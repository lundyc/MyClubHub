<?php

declare(strict_types=1);

// Club Shop — self-contained catalogue + basket + Stripe checkout module.
//
// Mirrors the structure of the other commerce modules in this app
// (lib/pos.php, lib/season_passes.php, lib/player_sponsorship_shop.php):
// its own shop_* tables, an idempotent shop_ensure_schema(), a small set
// of domain helpers, and Stripe Checkout Session handlers dispatched from
// stripe_handle_webhook_event() on metadata.kind === 'shop'.
//
// Customer storefront:  /shop/*          (public, guest checkout)
// Admin:                /shop_*.php      (hub admin, "Shop" nav section)

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/stripe.php';

const SHOP_CURRENCY = 'gbp';
const SHOP_COLLECTION_POINT_DEFAULT = 'Campbell Park';
const SHOP_LEAD_TIME_DEFAULT = '6–8 weeks';
const SHOP_PREORDER_CLOSE_DEFAULT = '2026-09-14 23:59:59';

/* -------------------------------------------------------------------------
 * Schema
 * ---------------------------------------------------------------------- */

function shop_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_settings (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        setting_key VARCHAR(80) NOT NULL,
        setting_value MEDIUMTEXT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shop_settings_key (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_categories (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(140) NOT NULL,
        slug VARCHAR(160) NOT NULL,
        description TEXT NULL,
        image_path VARCHAR(255) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shop_categories_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_products (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        category_id INT UNSIGNED NOT NULL,
        name VARCHAR(180) NOT NULL,
        slug VARCHAR(200) NOT NULL,
        summary VARCHAR(255) NOT NULL DEFAULT '',
        description MEDIUMTEXT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        image_path VARCHAR(255) NULL,
        stock_qty INT NULL,
        max_per_order INT UNSIGNED NOT NULL DEFAULT 10,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        is_featured TINYINT(1) NOT NULL DEFAULT 0,
        is_preorder TINYINT(1) NOT NULL DEFAULT 0,
        preorder_close_at DATETIME NULL,
        preorder_message VARCHAR(500) NOT NULL DEFAULT '',
        lead_time VARCHAR(120) NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shop_products_slug (slug),
        KEY idx_shop_products_category (category_id, is_active),
        CONSTRAINT fk_shop_products_category FOREIGN KEY (category_id) REFERENCES shop_categories (id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_product_images (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id INT UNSIGNED NOT NULL,
        image_path VARCHAR(255) NOT NULL,
        alt_text VARCHAR(180) NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_shop_product_images_product (product_id, sort_order),
        CONSTRAINT fk_shop_product_images_product FOREIGN KEY (product_id) REFERENCES shop_products (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_modifier_groups (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(140) NOT NULL,
        slug VARCHAR(160) NOT NULL,
        help_text VARCHAR(255) NOT NULL DEFAULT '',
        selection_type VARCHAR(10) NOT NULL DEFAULT 'single',
        is_required TINYINT(1) NOT NULL DEFAULT 1,
        min_select INT UNSIGNED NOT NULL DEFAULT 1,
        max_select INT UNSIGNED NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shop_modifier_groups_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_modifier_options (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        group_id INT UNSIGNED NOT NULL,
        option_group VARCHAR(60) NOT NULL DEFAULT '',
        label VARCHAR(140) NOT NULL,
        price_delta DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        sku_suffix VARCHAR(40) NOT NULL DEFAULT '',
        stock_qty INT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_shop_modifier_options_group (group_id, sort_order),
        CONSTRAINT fk_shop_modifier_options_group FOREIGN KEY (group_id) REFERENCES shop_modifier_groups (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // option_group is a heading *within* a modifier group (e.g. Adult / Kids
    // under "Kit Size") that the storefront turns into a two-step
    // choose-fit-then-size control. Added after the table shipped, so backfill
    // it from any legacy "Adult …" / "Youth …" labels the first time through.
    $modifierOptionColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM shop_modifier_options') as $row) {
        $modifierOptionColumns[(string) $row['Field']] = true;
    }
    if (!isset($modifierOptionColumns['option_group'])) {
        $pdo->exec("ALTER TABLE shop_modifier_options ADD COLUMN option_group VARCHAR(60) NOT NULL DEFAULT '' AFTER group_id");
        $pdo->exec("UPDATE shop_modifier_options SET option_group = 'Adult', label = TRIM(SUBSTRING(label, 7))
            WHERE option_group = '' AND label LIKE 'Adult %'");
        $pdo->exec("UPDATE shop_modifier_options SET option_group = 'Kids', label = TRIM(SUBSTRING(label, 7)),
            sort_order = sort_order + 1000
            WHERE option_group = '' AND (label LIKE 'Youth %' OR label LIKE 'Kids %' OR label LIKE 'Junior %')");
        $pdo->exec("UPDATE shop_modifier_groups SET help_text = 'Choose Adult or Kids, then pick your size. Kit is a slim, athletic fit — size up for a looser fit.'
            WHERE slug = 'kit-size'");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_product_modifier_groups (
        product_id INT UNSIGNED NOT NULL,
        group_id INT UNSIGNED NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_required TINYINT(1) NULL,
        PRIMARY KEY (product_id, group_id),
        KEY idx_shop_pmg_group (group_id),
        CONSTRAINT fk_shop_pmg_product FOREIGN KEY (product_id) REFERENCES shop_products (id) ON DELETE CASCADE,
        CONSTRAINT fk_shop_pmg_group FOREIGN KEY (group_id) REFERENCES shop_modifier_groups (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_discount_codes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(40) NOT NULL,
        discount_type VARCHAR(10) NOT NULL DEFAULT 'percent',
        discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        min_spend DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        max_uses INT UNSIGNED NULL,
        used_count INT UNSIGNED NOT NULL DEFAULT 0,
        starts_at DATETIME NULL,
        ends_at DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shop_discount_codes_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_orders (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_ref VARCHAR(32) NOT NULL,
        access_token VARCHAR(64) NOT NULL,
        customer_name VARCHAR(190) NOT NULL,
        customer_email VARCHAR(190) NOT NULL,
        customer_phone VARCHAR(50) NOT NULL DEFAULT '',
        status VARCHAR(30) NOT NULL DEFAULT 'pending_payment',
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        discount_code VARCHAR(40) NOT NULL DEFAULT '',
        discount_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        currency CHAR(3) NOT NULL DEFAULT 'GBP',
        amount_refunded DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        is_preorder TINYINT(1) NOT NULL DEFAULT 0,
        fulfilment_method VARCHAR(20) NOT NULL DEFAULT 'collection',
        collection_point VARCHAR(190) NOT NULL DEFAULT '',
        batch_note VARCHAR(255) NOT NULL DEFAULT '',
        customer_note TEXT NULL,
        marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,
        terms_accepted_at DATETIME NULL,
        stripe_checkout_session_id VARCHAR(190) NULL,
        stripe_payment_intent_id VARCHAR(190) NULL,
        stripe_refund_id VARCHAR(190) NULL,
        confirmation_email_sent_at DATETIME NULL,
        paid_at DATETIME NULL,
        cancelled_at DATETIME NULL,
        collected_at DATETIME NULL,
        vsn_ordered_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shop_orders_ref (order_ref),
        UNIQUE KEY uq_shop_orders_token (access_token),
        KEY idx_shop_orders_status (status, created_at),
        KEY idx_shop_orders_session (stripe_checkout_session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_order_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NULL,
        product_name VARCHAR(190) NOT NULL,
        options_label VARCHAR(255) NOT NULL DEFAULT '',
        options_json MEDIUMTEXT NULL,
        base_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        quantity INT UNSIGNED NOT NULL DEFAULT 1,
        line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        is_preorder TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_shop_order_items_order (order_id),
        KEY idx_shop_order_items_product (product_id),
        CONSTRAINT fk_shop_order_items_order FOREIGN KEY (order_id) REFERENCES shop_orders (id) ON DELETE CASCADE,
        CONSTRAINT fk_shop_order_items_product FOREIGN KEY (product_id) REFERENCES shop_products (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    shop_seed_defaults($pdo);
}

function shop_seed_defaults(PDO $pdo): void
{
    $defaults = [
        'shop_enabled'        => '1',
        'shop_name'           => 'Club Shop',
        'shop_intro'          => 'Official Saltcoats Victoria FC merchandise. Every order is made to order by our kit manufacturer VSN.',
        'contact_email'       => '',
        'collection_point'    => SHOP_COLLECTION_POINT_DEFAULT,
        'collection_details'  => 'You will be emailed as soon as your order is ready to collect from Campbell Park. Please bring your order confirmation.',
        'delivery_note'       => 'Collection only — there is no delivery option for this shop. All orders are collected from ' . SHOP_COLLECTION_POINT_DEFAULT . '.',
        'lead_time'           => SHOP_LEAD_TIME_DEFAULT,
        'preorder_close_at'   => SHOP_PREORDER_CLOSE_DEFAULT,
        'preorder_intro'      => 'Pre-order now and pay today. Orders close on 14 September 2026, after which the full order is placed with VSN. '
            . 'VSN manufacturing typically takes ' . SHOP_LEAD_TIME_DEFAULT . ' from that date. All orders are collected from ' . SHOP_COLLECTION_POINT_DEFAULT . ' — no delivery is available.',
        'terms'               => "By placing this order you agree that:\n"
            . "• Payment is taken in full today.\n"
            . "• Pre-order kit orders close on 14 September 2026. After that date the combined order is placed with VSN and items can no longer be added or changed.\n"
            . "• VSN manufacturing typically takes " . SHOP_LEAD_TIME_DEFAULT . " from the order closing date.\n"
            . "• All orders are collected from " . SHOP_COLLECTION_POINT_DEFAULT . ". There is no delivery option.\n"
            . "• You will be contacted by email when your order is ready to collect.",
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO shop_settings (setting_key, setting_value) VALUES (:k, :v)');
    foreach ($defaults as $key => $value) {
        $stmt->execute([':k' => $key, ':v' => $value]);
    }

    // Only seed the catalogue on a truly empty shop.
    if ((int) $pdo->query('SELECT COUNT(*) FROM shop_products')->fetchColumn() > 0) {
        return;
    }

    $pdo->prepare('INSERT IGNORE INTO shop_categories (name, slug, description, sort_order, is_active) VALUES (:n, :s, :d, :o, 1)')
        ->execute([
            ':n' => 'Matchday Kit 2026/27',
            ':s' => 'matchday-kit-2026-27',
            ':d' => 'This season\'s official home and away playing kit, made to order by VSN.',
            ':o' => 10,
        ]);
    $categoryId = (int) $pdo->query("SELECT id FROM shop_categories WHERE slug = 'matchday-kit-2026-27'")->fetchColumn();
    if ($categoryId <= 0) {
        return;
    }

    // Shared "Kit Size" modifier group. option_group ("Adult" / "Kids") drives
    // the storefront's choose-fit-then-size control.
    $pdo->prepare("INSERT IGNORE INTO shop_modifier_groups (name, slug, help_text, selection_type, is_required, min_select, max_select, sort_order)
        VALUES ('Kit Size', 'kit-size', 'Choose Adult or Kids, then pick your size. Kit is a slim, athletic fit — size up for a looser fit.', 'single', 1, 1, 1, 10)")
        ->execute();
    $sizeGroupId = (int) $pdo->query("SELECT id FROM shop_modifier_groups WHERE slug = 'kit-size'")->fetchColumn();
    if ($sizeGroupId > 0 && (int) $pdo->query('SELECT COUNT(*) FROM shop_modifier_options WHERE group_id = ' . $sizeGroupId)->fetchColumn() === 0) {
        $sizes = [
            ['Adult', 'XS'], ['Adult', 'S'], ['Adult', 'M'], ['Adult', 'L'],
            ['Adult', 'XL'], ['Adult', '2XL'], ['Adult', '3XL'], ['Adult', '4XL'],
            ['Kids', '5-6 yrs'], ['Kids', '7-8 yrs'], ['Kids', '9-10 yrs'], ['Kids', '11-12 yrs'], ['Kids', '13-14 yrs'],
        ];
        $optStmt = $pdo->prepare('INSERT INTO shop_modifier_options (group_id, option_group, label, sku_suffix, sort_order) VALUES (:g, :grp, :l, :sku, :o)');
        foreach ($sizes as $i => [$grp, $label]) {
            $optStmt->execute([
                ':g' => $sizeGroupId,
                ':grp' => $grp,
                ':l' => $label,
                ':sku' => strtoupper($grp[0] . (preg_replace('/[^A-Za-z0-9]+/', '', $label) ?? '')),
                ':o' => ($i + 1) * 10,
            ]);
        }
    }

    $close = (string) shop_setting($pdo, 'preorder_close_at', SHOP_PREORDER_CLOSE_DEFAULT);
    $lead = (string) shop_setting($pdo, 'lead_time', SHOP_LEAD_TIME_DEFAULT);
    $preMsg = 'Pre-order — orders close 14 September 2026, then placed with VSN (allow ' . $lead . '). Collection only from '
        . SHOP_COLLECTION_POINT_DEFAULT . '.';

    $productStmt = $pdo->prepare('INSERT INTO shop_products
        (category_id, name, slug, summary, description, price, is_active, is_featured, is_preorder, preorder_close_at, preorder_message, lead_time, sort_order)
        VALUES (:cat, :name, :slug, :summary, :description, :price, 1, 1, 1, :close, :premsg, :lead, :sort)');
    $kits = [
        [
            'name' => 'Home Shirt 2026/27',
            'slug' => 'home-shirt-2026-27',
            'summary' => 'Official home playing shirt for the 2026/27 season.',
            'description' => "The official Saltcoats Victoria FC home shirt for season 2026/27, manufactured by VSN.\n\n"
                . "This is a pre-order. Pay today; your order goes into the club's bulk order to VSN when pre-orders close on 14 September 2026. "
                . "VSN manufacturing then typically takes " . $lead . ".\n\n"
                . "All orders are collected from " . SHOP_COLLECTION_POINT_DEFAULT . " — there is no delivery option. You'll be emailed when your kit is ready.",
            'price' => 45.00,
            'sort' => 10,
        ],
        [
            'name' => 'Away Shirt 2026/27',
            'slug' => 'away-shirt-2026-27',
            'summary' => 'Official away playing shirt for the 2026/27 season.',
            'description' => "The official Saltcoats Victoria FC away shirt for season 2026/27, manufactured by VSN.\n\n"
                . "This is a pre-order. Pay today; your order goes into the club's bulk order to VSN when pre-orders close on 14 September 2026. "
                . "VSN manufacturing then typically takes " . $lead . ".\n\n"
                . "All orders are collected from " . SHOP_COLLECTION_POINT_DEFAULT . " — there is no delivery option. You'll be emailed when your kit is ready.",
            'price' => 45.00,
            'sort' => 20,
        ],
    ];
    foreach ($kits as $kit) {
        $productStmt->execute([
            ':cat' => $categoryId,
            ':name' => $kit['name'],
            ':slug' => $kit['slug'],
            ':summary' => $kit['summary'],
            ':description' => $kit['description'],
            ':price' => $kit['price'],
            ':close' => $close,
            ':premsg' => $preMsg,
            ':lead' => $lead,
            ':sort' => $kit['sort'],
        ]);
        $productId = (int) $pdo->lastInsertId();
        if ($productId > 0 && $sizeGroupId > 0) {
            $pdo->prepare('INSERT IGNORE INTO shop_product_modifier_groups (product_id, group_id, sort_order, is_required) VALUES (:p, :g, 10, 1)')
                ->execute([':p' => $productId, ':g' => $sizeGroupId]);
        }
    }
}

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

/** @return array<string,string> */
function shop_get_settings(PDO $pdo): array
{
    shop_ensure_schema($pdo);
    $out = [];
    foreach ($pdo->query('SELECT setting_key, setting_value FROM shop_settings') as $row) {
        $out[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
    }
    return $out;
}

function shop_setting(PDO $pdo, string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = shop_get_settings($pdo);
    }
    $value = $cache[$key] ?? null;
    return ($value === null || $value === '') ? $default : $value;
}

function shop_save_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('INSERT INTO shop_settings (setting_key, setting_value) VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([':k' => $key, ':v' => $value]);
}

function shop_is_enabled(PDO $pdo): bool
{
    return shop_setting($pdo, 'shop_enabled', '1') === '1';
}

/* -------------------------------------------------------------------------
 * Small helpers
 * ---------------------------------------------------------------------- */

function shop_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function shop_unique_slug(PDO $pdo, string $table, string $base, ?int $ignoreId = null): string
{
    $base = shop_slugify($base);
    if ($base === '') {
        $base = 'item';
    }
    $slug = $base;
    $n = 1;
    $sql = "SELECT COUNT(*) FROM {$table} WHERE slug = :slug" . ($ignoreId !== null ? ' AND id <> :id' : '');
    $stmt = $pdo->prepare($sql);
    while (true) {
        $params = [':slug' => $slug];
        if ($ignoreId !== null) {
            $params[':id'] = $ignoreId;
        }
        $stmt->execute($params);
        if ((int) $stmt->fetchColumn() === 0) {
            return $slug;
        }
        $slug = $base . '-' . (++$n);
    }
}

function shop_money(float $amount): string
{
    return gbp($amount);
}

function shop_generate_token(): string
{
    return bin2hex(random_bytes(16));
}

function shop_generate_order_ref(PDO $pdo): string
{
    $next = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM shop_orders')->fetchColumn();
    for ($i = 0; $i < 20; $i++) {
        $ref = 'SVFC-' . str_pad((string) ($next + $i), 4, '0', STR_PAD_LEFT);
        $check = $pdo->prepare('SELECT 1 FROM shop_orders WHERE order_ref = :r');
        $check->execute([':r' => $ref]);
        if (!$check->fetchColumn()) {
            return $ref;
        }
    }
    return 'SVFC-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

/**
 * Validate an uploaded image and move it into uploads/shop/<subdir>/.
 * Returns the public web path, or null on any problem.
 *
 * @param array<string,mixed> $file a single $_FILES entry
 */
function shop_handle_image_upload(array $file, string $subdir): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        return null;
    }
    if ((int) ($file['size'] ?? 0) > 6 * 1024 * 1024) {
        return null;
    }
    $info = @getimagesize((string) $file['tmp_name']);
    $allowed = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
    ];
    if ($info === false || !isset($allowed[$info[2]])) {
        return null;
    }
    $subdir = preg_replace('/[^a-z0-9_-]/', '', strtolower($subdir)) ?: 'misc';
    $dir = dirname(__DIR__) . '/uploads/shop/' . $subdir;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    $name = bin2hex(random_bytes(10)) . '.' . $allowed[$info[2]];
    $dest = $dir . '/' . $name;
    if (!@move_uploaded_file((string) $file['tmp_name'], $dest)) {
        return null;
    }
    @chmod($dest, 0644);
    return '/uploads/shop/' . $subdir . '/' . $name;
}

/* -------------------------------------------------------------------------
 * Categories
 * ---------------------------------------------------------------------- */

/** @return list<array<string,mixed>> */
function shop_get_categories(PDO $pdo, bool $includeInactive = false): array
{
    shop_ensure_schema($pdo);
    $sql = 'SELECT c.*, (SELECT COUNT(*) FROM shop_products p WHERE p.category_id = c.id) AS product_count
            FROM shop_categories c';
    if (!$includeInactive) {
        $sql .= ' WHERE c.is_active = 1';
    }
    $sql .= ' ORDER BY c.sort_order, c.name';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function shop_get_category(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM shop_categories WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function shop_get_category_by_slug(PDO $pdo, string $slug): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM shop_categories WHERE slug = :s');
    $stmt->execute([':s' => $slug]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** @param array<string,mixed> $data */
function shop_save_category(PDO $pdo, ?int $id, array $data): int
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new HubFieldValidationException('name', 'Category name is required.');
    }
    $slugInput = trim((string) ($data['slug'] ?? ''));
    $slug = shop_unique_slug($pdo, 'shop_categories', $slugInput !== '' ? $slugInput : $name, $id);
    $params = [
        ':name' => $name,
        ':slug' => $slug,
        ':description' => trim((string) ($data['description'] ?? '')) ?: null,
        ':image_path' => trim((string) ($data['image_path'] ?? '')) ?: null,
        ':sort_order' => (int) ($data['sort_order'] ?? 0),
        ':is_active' => !empty($data['is_active']) ? 1 : 0,
    ];
    if ($id) {
        $params[':id'] = $id;
        $sql = 'UPDATE shop_categories SET name=:name, slug=:slug, description=:description, image_path=:image_path,
                sort_order=:sort_order, is_active=:is_active WHERE id=:id';
        // Keep the existing image if none supplied.
        if ($params[':image_path'] === null) {
            unset($params[':image_path']);
            $sql = str_replace(', image_path=:image_path', '', $sql);
        }
        $pdo->prepare($sql)->execute($params);
        return $id;
    }
    $pdo->prepare('INSERT INTO shop_categories (name, slug, description, image_path, sort_order, is_active)
        VALUES (:name, :slug, :description, :image_path, :sort_order, :is_active)')->execute($params);
    return (int) $pdo->lastInsertId();
}

function shop_delete_category(PDO $pdo, int $id): void
{
    $count = $pdo->prepare('SELECT COUNT(*) FROM shop_products WHERE category_id = :id');
    $count->execute([':id' => $id]);
    if ((int) $count->fetchColumn() > 0) {
        throw new RuntimeException('Move or delete this category\'s products first.');
    }
    $pdo->prepare('DELETE FROM shop_categories WHERE id = :id')->execute([':id' => $id]);
}

/* -------------------------------------------------------------------------
 * Products
 * ---------------------------------------------------------------------- */

/**
 * @param array<string,mixed> $filters  category_id, active_only, featured_only, search
 * @return list<array<string,mixed>>
 */
function shop_get_products(PDO $pdo, array $filters = []): array
{
    shop_ensure_schema($pdo);
    $where = [];
    $params = [];
    if (!empty($filters['active_only'])) {
        $where[] = 'p.is_active = 1 AND c.is_active = 1';
    }
    if (!empty($filters['featured_only'])) {
        $where[] = 'p.is_featured = 1';
    }
    if (!empty($filters['category_id'])) {
        $where[] = 'p.category_id = :cat';
        $params[':cat'] = (int) $filters['category_id'];
    }
    if (!empty($filters['search'])) {
        $where[] = '(p.name LIKE :q OR p.summary LIKE :q)';
        $params[':q'] = '%' . $filters['search'] . '%';
    }
    $sql = 'SELECT p.*, c.name AS category_name, c.slug AS category_slug
            FROM shop_products p JOIN shop_categories c ON c.id = p.category_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY c.sort_order, p.sort_order, p.name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function shop_get_product(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT p.*, c.name AS category_name, c.slug AS category_slug
        FROM shop_products p JOIN shop_categories c ON c.id = p.category_id WHERE p.id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function shop_get_product_by_slug(PDO $pdo, string $slug): ?array
{
    $stmt = $pdo->prepare('SELECT p.*, c.name AS category_name, c.slug AS category_slug
        FROM shop_products p JOIN shop_categories c ON c.id = p.category_id WHERE p.slug = :s');
    $stmt->execute([':s' => $slug]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** @return list<array<string,mixed>> */
function shop_product_images(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare('SELECT * FROM shop_product_images WHERE product_id = :id ORDER BY sort_order, id');
    $stmt->execute([':id' => $productId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Every image for a product's gallery, main image first.
 * @return list<array{path:string,alt:string}>
 */
function shop_product_gallery(PDO $pdo, array $product): array
{
    $gallery = [];
    $main = trim((string) ($product['image_path'] ?? ''));
    if ($main !== '') {
        $gallery[] = ['path' => $main, 'alt' => (string) $product['name']];
    }
    foreach (shop_product_images($pdo, (int) $product['id']) as $img) {
        $gallery[] = ['path' => (string) $img['image_path'], 'alt' => (string) ($img['alt_text'] ?: $product['name'])];
    }
    return $gallery;
}

/** @param array<string,mixed> $data */
function shop_save_product(PDO $pdo, ?int $id, array $data): int
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new HubFieldValidationException('name', 'Product name is required.');
    }
    $categoryId = (int) ($data['category_id'] ?? 0);
    if ($categoryId <= 0 || !shop_get_category($pdo, $categoryId)) {
        throw new HubFieldValidationException('category_id', 'Choose a valid category.');
    }
    $slugInput = trim((string) ($data['slug'] ?? ''));
    $slug = shop_unique_slug($pdo, 'shop_products', $slugInput !== '' ? $slugInput : $name, $id);

    $stockRaw = trim((string) ($data['stock_qty'] ?? ''));
    $preorderClose = trim((string) ($data['preorder_close_at'] ?? ''));

    $params = [
        ':category_id' => $categoryId,
        ':name' => $name,
        ':slug' => $slug,
        ':summary' => trim((string) ($data['summary'] ?? '')),
        ':description' => trim((string) ($data['description'] ?? '')) ?: null,
        ':price' => max(0, round((float) ($data['price'] ?? 0), 2)),
        ':stock_qty' => $stockRaw === '' ? null : max(0, (int) $stockRaw),
        ':max_per_order' => max(1, (int) ($data['max_per_order'] ?? 10)),
        ':is_active' => !empty($data['is_active']) ? 1 : 0,
        ':is_featured' => !empty($data['is_featured']) ? 1 : 0,
        ':is_preorder' => !empty($data['is_preorder']) ? 1 : 0,
        ':preorder_close_at' => $preorderClose !== '' ? date('Y-m-d H:i:s', strtotime($preorderClose)) : null,
        ':preorder_message' => trim((string) ($data['preorder_message'] ?? '')),
        ':lead_time' => trim((string) ($data['lead_time'] ?? '')),
        ':sort_order' => (int) ($data['sort_order'] ?? 0),
    ];
    $hasImage = array_key_exists('image_path', $data) && trim((string) $data['image_path']) !== '';
    if ($hasImage) {
        $params[':image_path'] = trim((string) $data['image_path']);
    }

    if ($id) {
        $params[':id'] = $id;
        $set = 'category_id=:category_id, name=:name, slug=:slug, summary=:summary, description=:description,
            price=:price, stock_qty=:stock_qty, max_per_order=:max_per_order, is_active=:is_active,
            is_featured=:is_featured, is_preorder=:is_preorder, preorder_close_at=:preorder_close_at,
            preorder_message=:preorder_message, lead_time=:lead_time, sort_order=:sort_order';
        if ($hasImage) {
            $set .= ', image_path=:image_path';
        }
        $pdo->prepare("UPDATE shop_products SET {$set} WHERE id=:id")->execute($params);
        $productId = $id;
    } else {
        $cols = array_keys($params);
        $fields = implode(', ', array_map(static fn($c) => ltrim($c, ':'), $cols));
        $placeholders = implode(', ', $cols);
        $pdo->prepare("INSERT INTO shop_products ({$fields}) VALUES ({$placeholders})")->execute($params);
        $productId = (int) $pdo->lastInsertId();
    }

    if (array_key_exists('modifier_group_ids', $data) && is_array($data['modifier_group_ids'])) {
        shop_set_product_groups(
            $pdo,
            $productId,
            array_map('intval', $data['modifier_group_ids']),
            is_array($data['modifier_group_required'] ?? null) ? $data['modifier_group_required'] : []
        );
    }

    return $productId;
}

function shop_delete_product(PDO $pdo, int $id): void
{
    $paid = $pdo->prepare("SELECT COUNT(*) FROM shop_order_items oi JOIN shop_orders o ON o.id = oi.order_id
        WHERE oi.product_id = :id AND o.status IN ('paid','collected','refunded')");
    $paid->execute([':id' => $id]);
    if ((int) $paid->fetchColumn() > 0) {
        // Preserve order history: retire instead of hard delete.
        $pdo->prepare('UPDATE shop_products SET is_active = 0, is_featured = 0 WHERE id = :id')->execute([':id' => $id]);
        throw new RuntimeException('This product has paid orders, so it has been hidden from the shop rather than deleted.');
    }
    $pdo->prepare('DELETE FROM shop_products WHERE id = :id')->execute([':id' => $id]);
}

/* -------------------------------------------------------------------------
 * Modifier groups & options
 * ---------------------------------------------------------------------- */

/** @return list<array<string,mixed>> */
function shop_get_modifier_groups(PDO $pdo): array
{
    shop_ensure_schema($pdo);
    return $pdo->query('SELECT g.*, (SELECT COUNT(*) FROM shop_modifier_options o WHERE o.group_id = g.id) AS option_count
        FROM shop_modifier_groups g ORDER BY g.sort_order, g.name')->fetchAll(PDO::FETCH_ASSOC);
}

function shop_get_modifier_group(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM shop_modifier_groups WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** @return list<array<string,mixed>> */
function shop_group_options(PDO $pdo, int $groupId, bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM shop_modifier_options WHERE group_id = :g';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY sort_order, id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':g' => $groupId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @param array<string,mixed> $data */
function shop_save_modifier_group(PDO $pdo, ?int $id, array $data): int
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new HubFieldValidationException('name', 'Modifier name is required.');
    }
    $type = ($data['selection_type'] ?? 'single') === 'multi' ? 'multi' : 'single';
    $isRequired = !empty($data['is_required']) ? 1 : 0;
    $min = max(0, (int) ($data['min_select'] ?? ($isRequired ? 1 : 0)));
    $maxRaw = trim((string) ($data['max_select'] ?? ''));
    if ($type === 'single') {
        $min = $isRequired ? 1 : 0;
        $max = 1;
    } else {
        $max = $maxRaw === '' ? null : max(1, (int) $maxRaw);
    }
    $slug = shop_unique_slug($pdo, 'shop_modifier_groups', trim((string) ($data['slug'] ?? '')) ?: $name, $id);
    $params = [
        ':name' => $name,
        ':slug' => $slug,
        ':help_text' => trim((string) ($data['help_text'] ?? '')),
        ':selection_type' => $type,
        ':is_required' => $isRequired,
        ':min_select' => $min,
        ':max_select' => $max,
        ':sort_order' => (int) ($data['sort_order'] ?? 0),
    ];
    if ($id) {
        $params[':id'] = $id;
        $pdo->prepare('UPDATE shop_modifier_groups SET name=:name, slug=:slug, help_text=:help_text,
            selection_type=:selection_type, is_required=:is_required, min_select=:min_select,
            max_select=:max_select, sort_order=:sort_order WHERE id=:id')->execute($params);
        return $id;
    }
    $pdo->prepare('INSERT INTO shop_modifier_groups (name, slug, help_text, selection_type, is_required, min_select, max_select, sort_order)
        VALUES (:name, :slug, :help_text, :selection_type, :is_required, :min_select, :max_select, :sort_order)')->execute($params);
    return (int) $pdo->lastInsertId();
}

function shop_delete_modifier_group(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM shop_modifier_groups WHERE id = :id')->execute([':id' => $id]);
}

/** @param array<string,mixed> $data */
function shop_save_modifier_option(PDO $pdo, ?int $id, int $groupId, array $data): int
{
    if (!shop_get_modifier_group($pdo, $groupId)) {
        throw new RuntimeException('Unknown modifier group.');
    }
    $label = trim((string) ($data['label'] ?? ''));
    if ($label === '') {
        throw new HubFieldValidationException('label', 'Option label is required.');
    }
    $stockRaw = trim((string) ($data['stock_qty'] ?? ''));
    $params = [
        ':group_id' => $groupId,
        ':option_group' => trim((string) ($data['option_group'] ?? '')),
        ':label' => $label,
        ':price_delta' => round((float) ($data['price_delta'] ?? 0), 2),
        ':sku_suffix' => trim((string) ($data['sku_suffix'] ?? '')),
        ':stock_qty' => $stockRaw === '' ? null : max(0, (int) $stockRaw),
        ':is_active' => !empty($data['is_active']) ? 1 : 0,
        ':sort_order' => (int) ($data['sort_order'] ?? 0),
    ];
    if ($id) {
        $params[':id'] = $id;
        $pdo->prepare('UPDATE shop_modifier_options SET option_group=:option_group, label=:label, price_delta=:price_delta, sku_suffix=:sku_suffix,
            stock_qty=:stock_qty, is_active=:is_active, sort_order=:sort_order WHERE id=:id AND group_id=:group_id')->execute($params);
        return $id;
    }
    $pdo->prepare('INSERT INTO shop_modifier_options (group_id, option_group, label, price_delta, sku_suffix, stock_qty, is_active, sort_order)
        VALUES (:group_id, :option_group, :label, :price_delta, :sku_suffix, :stock_qty, :is_active, :sort_order)')->execute($params);
    return (int) $pdo->lastInsertId();
}

function shop_delete_modifier_option(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM shop_modifier_options WHERE id = :id')->execute([':id' => $id]);
}

/**
 * Modifier groups attached to a product, each with its resolved required flag
 * and its (active) options. Used by the storefront product page and the
 * server-side basket validation.
 *
 * @return list<array<string,mixed>>
 */
function shop_product_groups(PDO $pdo, int $productId, bool $activeOptionsOnly = true): array
{
    $stmt = $pdo->prepare('SELECT g.*, pmg.sort_order AS link_sort, pmg.is_required AS link_required
        FROM shop_product_modifier_groups pmg
        JOIN shop_modifier_groups g ON g.id = pmg.group_id
        WHERE pmg.product_id = :p
        ORDER BY pmg.sort_order, g.sort_order, g.name');
    $stmt->execute([':p' => $productId]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($groups as &$group) {
        $group['is_required'] = $group['link_required'] === null
            ? (int) $group['is_required']
            : (int) $group['link_required'];
        $group['options'] = shop_group_options($pdo, (int) $group['id'], $activeOptionsOnly);
        if ((string) $group['selection_type'] === 'single') {
            $group['min_select'] = $group['is_required'] ? 1 : 0;
            $group['max_select'] = 1;
        }
    }
    unset($group);
    return $groups;
}

/**
 * If every active option in a single-select group carries an option_group
 * heading and there are 2+ distinct headings, the storefront renders a
 * two-step "choose fit, then size" control. Otherwise it falls back to a flat
 * row of chips. Returns the ordered list of headings, or [] when not sectioned.
 *
 * @param array<string,mixed> $group a row from shop_product_groups()
 * @return list<string>
 */
function shop_group_sections(array $group): array
{
    if ((string) ($group['selection_type'] ?? 'single') !== 'single') {
        return [];
    }
    $sections = [];
    foreach (($group['options'] ?? []) as $opt) {
        $heading = trim((string) ($opt['option_group'] ?? ''));
        if ($heading === '') {
            return []; // partly-tagged group — not safe to render as sections
        }
        if (!in_array($heading, $sections, true)) {
            $sections[] = $heading;
        }
    }
    return count($sections) >= 2 ? $sections : [];
}

/**
 * @param list<int> $groupIds
 * @param array<int,mixed> $requiredMap  group_id => truthy when required for this product
 */
function shop_set_product_groups(PDO $pdo, int $productId, array $groupIds, array $requiredMap = []): void
{
    $pdo->prepare('DELETE FROM shop_product_modifier_groups WHERE product_id = :p')->execute([':p' => $productId]);
    if (!$groupIds) {
        return;
    }
    $ins = $pdo->prepare('INSERT INTO shop_product_modifier_groups (product_id, group_id, sort_order, is_required)
        VALUES (:p, :g, :sort, :req)');
    $sort = 0;
    foreach (array_values(array_unique($groupIds)) as $groupId) {
        if ($groupId <= 0 || !shop_get_modifier_group($pdo, $groupId)) {
            continue;
        }
        $ins->execute([
            ':p' => $productId,
            ':g' => $groupId,
            ':sort' => ($sort += 10),
            ':req' => array_key_exists($groupId, $requiredMap) ? (!empty($requiredMap[$groupId]) ? 1 : 0) : null,
        ]);
    }
}

/* -------------------------------------------------------------------------
 * Pre-order window
 * ---------------------------------------------------------------------- */

function shop_product_preorder_close(PDO $pdo, array $product): ?DateTimeImmutable
{
    if ((int) ($product['is_preorder'] ?? 0) !== 1) {
        return null;
    }
    $raw = trim((string) ($product['preorder_close_at'] ?? ''));
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
        $raw = shop_setting($pdo, 'preorder_close_at', SHOP_PREORDER_CLOSE_DEFAULT);
    }
    try {
        return new DateTimeImmutable($raw);
    } catch (Throwable) {
        return null;
    }
}

function shop_product_is_orderable(PDO $pdo, array $product): bool
{
    if ((int) ($product['is_active'] ?? 0) !== 1) {
        return false;
    }
    $close = shop_product_preorder_close($pdo, $product);
    if ($close !== null && new DateTimeImmutable('now') > $close) {
        return false;
    }
    if (($product['stock_qty'] ?? null) !== null && (int) $product['stock_qty'] <= 0) {
        return false;
    }
    return true;
}

/* -------------------------------------------------------------------------
 * Selection resolution / pricing  (server-authoritative)
 * ---------------------------------------------------------------------- */

/**
 * Validate a customer's modifier choices for a product and compute the unit
 * price. $selected is  group_id => (int option_id | list<int> option_ids).
 *
 * @return array{
 *   base_price: float, unit_price: float, options_label: string,
 *   options: list<array{group_id:int,group_name:string,option_id:int,option_label:string,price_delta:float}>
 * }
 * @throws RuntimeException on any invalid / incomplete selection
 */
function shop_resolve_selection(PDO $pdo, array $product, array $selected): array
{
    $base = round((float) $product['price'], 2);
    $unit = $base;
    $chosen = [];
    $labels = [];

    foreach (shop_product_groups($pdo, (int) $product['id'], true) as $group) {
        $groupId = (int) $group['id'];
        $raw = $selected[$groupId] ?? [];
        $ids = array_values(array_filter(array_map('intval', is_array($raw) ? $raw : [$raw])));
        $ids = array_values(array_unique($ids));

        $optionById = [];
        foreach ($group['options'] as $opt) {
            $optionById[(int) $opt['id']] = $opt;
        }
        foreach ($ids as $optId) {
            if (!isset($optionById[$optId])) {
                throw new RuntimeException('Please choose a valid ' . $group['name'] . '.');
            }
        }

        $min = (int) $group['min_select'];
        $max = $group['max_select'] === null ? PHP_INT_MAX : (int) $group['max_select'];
        if (count($ids) < $min) {
            throw new RuntimeException($min === 1
                ? 'Please choose a ' . strtolower((string) $group['name']) . '.'
                : 'Please choose at least ' . $min . ' ' . $group['name'] . ' options.');
        }
        if (count($ids) > $max) {
            throw new RuntimeException('Please choose no more than ' . $max . ' ' . $group['name'] . ' options.');
        }

        foreach ($ids as $optId) {
            $opt = $optionById[$optId];
            if (($opt['stock_qty'] ?? null) !== null && (int) $opt['stock_qty'] <= 0) {
                throw new RuntimeException($opt['label'] . ' is out of stock.');
            }
            $delta = round((float) $opt['price_delta'], 2);
            $unit += $delta;
            $heading = trim((string) ($opt['option_group'] ?? ''));
            $displayLabel = $heading !== '' ? $heading . ' ' . (string) $opt['label'] : (string) $opt['label'];
            $chosen[] = [
                'group_id' => $groupId,
                'group_name' => (string) $group['name'],
                'option_id' => $optId,
                'option_group' => $heading,
                'option_label' => (string) $opt['label'],
                'price_delta' => $delta,
            ];
            $labels[] = $group['name'] . ': ' . $displayLabel;
        }
    }

    return [
        'base_price' => $base,
        'unit_price' => round($unit, 2),
        'options_label' => implode(' · ', $labels),
        'options' => $chosen,
    ];
}

/* -------------------------------------------------------------------------
 * Discount codes
 * ---------------------------------------------------------------------- */

/** @return list<array<string,mixed>> */
function shop_discount_codes(PDO $pdo): array
{
    shop_ensure_schema($pdo);
    return $pdo->query('SELECT * FROM shop_discount_codes ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
}

/** @param array<string,mixed> $data */
function shop_save_discount_code(PDO $pdo, ?int $id, array $data): int
{
    $code = strtoupper(trim((string) ($data['code'] ?? '')));
    $code = preg_replace('/[^A-Z0-9_-]/', '', $code) ?? '';
    if ($code === '') {
        throw new HubFieldValidationException('code', 'Enter a code.');
    }
    $maxUsesRaw = trim((string) ($data['max_uses'] ?? ''));
    $params = [
        ':code' => $code,
        ':discount_type' => ($data['discount_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent',
        ':discount_value' => max(0, round((float) ($data['discount_value'] ?? 0), 2)),
        ':min_spend' => max(0, round((float) ($data['min_spend'] ?? 0), 2)),
        ':max_uses' => $maxUsesRaw === '' ? null : max(1, (int) $maxUsesRaw),
        ':starts_at' => trim((string) ($data['starts_at'] ?? '')) ?: null,
        ':ends_at' => trim((string) ($data['ends_at'] ?? '')) ?: null,
        ':is_active' => !empty($data['is_active']) ? 1 : 0,
    ];
    if ($id) {
        $params[':id'] = $id;
        $pdo->prepare('UPDATE shop_discount_codes SET code=:code, discount_type=:discount_type, discount_value=:discount_value,
            min_spend=:min_spend, max_uses=:max_uses, starts_at=:starts_at, ends_at=:ends_at, is_active=:is_active WHERE id=:id')
            ->execute($params);
        return $id;
    }
    $pdo->prepare('INSERT INTO shop_discount_codes (code, discount_type, discount_value, min_spend, max_uses, starts_at, ends_at, is_active)
        VALUES (:code, :discount_type, :discount_value, :min_spend, :max_uses, :starts_at, :ends_at, :is_active)')->execute($params);
    return (int) $pdo->lastInsertId();
}

function shop_delete_discount_code(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM shop_discount_codes WHERE id = :id')->execute([':id' => $id]);
}

/**
 * @return array{ok:bool, error:string, code:string, amount:float}
 */
function shop_validate_discount(PDO $pdo, string $code, float $subtotal): array
{
    $code = strtoupper(trim($code));
    $fail = static fn(string $m): array => ['ok' => false, 'error' => $m, 'code' => '', 'amount' => 0.0];
    if ($code === '') {
        return $fail('');
    }
    $stmt = $pdo->prepare('SELECT * FROM shop_discount_codes WHERE code = :c');
    $stmt->execute([':c' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) $row['is_active'] !== 1) {
        return $fail('That code is not valid.');
    }
    $now = new DateTimeImmutable('now');
    if (!empty($row['starts_at']) && $now < new DateTimeImmutable((string) $row['starts_at'])) {
        return $fail('That code is not active yet.');
    }
    if (!empty($row['ends_at']) && $now > new DateTimeImmutable((string) $row['ends_at'])) {
        return $fail('That code has expired.');
    }
    if ($row['max_uses'] !== null && (int) $row['used_count'] >= (int) $row['max_uses']) {
        return $fail('That code has already been fully redeemed.');
    }
    if ($subtotal < (float) $row['min_spend']) {
        return $fail('Spend at least ' . gbp((float) $row['min_spend']) . ' to use that code.');
    }
    $amount = (string) $row['discount_type'] === 'fixed'
        ? min($subtotal, (float) $row['discount_value'])
        : round($subtotal * ((float) $row['discount_value'] / 100), 2);
    $amount = min($subtotal, max(0.0, $amount));
    return ['ok' => true, 'error' => '', 'code' => $code, 'amount' => $amount];
}

/* -------------------------------------------------------------------------
 * Orders
 * ---------------------------------------------------------------------- */

/**
 * @param array{name:string,email:string,phone:string,note:string,marketing_opt_in:bool} $customer
 * @param list<array{
 *   product_id:int, product_name:string, options_label:string, options:array,
 *   base_price:float, unit_price:float, quantity:int, is_preorder:bool
 * }> $lines  already validated & priced server-side
 * @param array{discount_code?:string, terms_accepted?:bool} $meta
 */
function shop_create_order(PDO $pdo, array $customer, array $lines, array $meta = []): array
{
    if (!$lines) {
        throw new RuntimeException('Your basket is empty.');
    }
    $subtotal = 0.0;
    $isPreorder = false;
    foreach ($lines as $line) {
        $subtotal += round((float) $line['unit_price'] * (int) $line['quantity'], 2);
        $isPreorder = $isPreorder || !empty($line['is_preorder']);
    }
    $subtotal = round($subtotal, 2);

    $discount = shop_validate_discount($pdo, (string) ($meta['discount_code'] ?? ''), $subtotal);
    $discountAmount = $discount['ok'] ? $discount['amount'] : 0.0;
    $total = round(max(0.0, $subtotal - $discountAmount), 2);

    $collectionPoint = shop_setting($pdo, 'collection_point', SHOP_COLLECTION_POINT_DEFAULT);
    $lead = shop_setting($pdo, 'lead_time', SHOP_LEAD_TIME_DEFAULT);
    $batchNote = $isPreorder
        ? 'Pre-order — placed with VSN after orders close 14 September 2026, then allow ' . $lead . '. Collect from ' . $collectionPoint . '.'
        : 'Collect from ' . $collectionPoint . '.';

    $pdo->beginTransaction();
    try {
        $ref = shop_generate_order_ref($pdo);
        $token = shop_generate_token();
        $pdo->prepare('INSERT INTO shop_orders
            (order_ref, access_token, customer_name, customer_email, customer_phone, status, subtotal,
             discount_code, discount_total, total, currency, is_preorder, fulfilment_method, collection_point,
             batch_note, customer_note, marketing_opt_in, terms_accepted_at)
            VALUES
            (:ref, :token, :name, :email, :phone, \'pending_payment\', :subtotal,
             :discount_code, :discount_total, :total, \'GBP\', :is_preorder, \'collection\', :collection_point,
             :batch_note, :note, :marketing, :terms_at)')
            ->execute([
                ':ref' => $ref,
                ':token' => $token,
                ':name' => trim((string) $customer['name']),
                ':email' => trim((string) $customer['email']),
                ':phone' => trim((string) ($customer['phone'] ?? '')),
                ':subtotal' => $subtotal,
                ':discount_code' => $discount['ok'] ? $discount['code'] : '',
                ':discount_total' => $discountAmount,
                ':total' => $total,
                ':is_preorder' => $isPreorder ? 1 : 0,
                ':collection_point' => $collectionPoint,
                ':batch_note' => $batchNote,
                ':note' => trim((string) ($customer['note'] ?? '')) ?: null,
                ':marketing' => !empty($customer['marketing_opt_in']) ? 1 : 0,
                ':terms_at' => !empty($meta['terms_accepted']) ? date('Y-m-d H:i:s') : null,
            ]);
        $orderId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare('INSERT INTO shop_order_items
            (order_id, product_id, product_name, options_label, options_json, base_price, unit_price, quantity, line_total, is_preorder)
            VALUES (:order_id, :product_id, :product_name, :options_label, :options_json, :base_price, :unit_price, :quantity, :line_total, :is_preorder)');
        foreach ($lines as $line) {
            $qty = max(1, (int) $line['quantity']);
            $itemStmt->execute([
                ':order_id' => $orderId,
                ':product_id' => (int) $line['product_id'] ?: null,
                ':product_name' => (string) $line['product_name'],
                ':options_label' => (string) ($line['options_label'] ?? ''),
                ':options_json' => json_encode($line['options'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':base_price' => round((float) $line['base_price'], 2),
                ':unit_price' => round((float) $line['unit_price'], 2),
                ':quantity' => $qty,
                ':line_total' => round((float) $line['unit_price'] * $qty, 2),
                ':is_preorder' => !empty($line['is_preorder']) ? 1 : 0,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return shop_get_order($pdo, $orderId);
}

function shop_get_order(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM shop_orders WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function shop_get_order_by_token(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM shop_orders WHERE access_token = :t');
    $stmt->execute([':t' => $token]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function shop_get_order_by_session(PDO $pdo, string $sessionId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM shop_orders WHERE stripe_checkout_session_id = :s');
    $stmt->execute([':s' => $sessionId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** @return list<array<string,mixed>> */
function shop_order_items(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT * FROM shop_order_items WHERE order_id = :id ORDER BY id');
    $stmt->execute([':id' => $orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @param array<string,mixed> $filters  status, search, preorder_only
 * @return list<array<string,mixed>>
 */
function shop_order_list(PDO $pdo, array $filters = []): array
{
    shop_ensure_schema($pdo);
    $where = [];
    $params = [];
    if (!empty($filters['status'])) {
        $where[] = 'o.status = :status';
        $params[':status'] = (string) $filters['status'];
    }
    if (!empty($filters['preorder_only'])) {
        $where[] = 'o.is_preorder = 1';
    }
    if (!empty($filters['search'])) {
        $where[] = '(o.order_ref LIKE :q OR o.customer_name LIKE :q OR o.customer_email LIKE :q)';
        $params[':q'] = '%' . $filters['search'] . '%';
    }
    $sql = 'SELECT o.*,
            (SELECT COALESCE(SUM(quantity),0) FROM shop_order_items i WHERE i.order_id = o.id) AS item_count
            FROM shop_orders o';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY o.created_at DESC, o.id DESC';
    if (!empty($filters['limit'])) {
        $sql .= ' LIMIT ' . (int) $filters['limit'];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function shop_attach_stripe_session(PDO $pdo, int $orderId, string $sessionId): void
{
    $pdo->prepare('UPDATE shop_orders SET stripe_checkout_session_id = :s WHERE id = :id')
        ->execute([':s' => $sessionId, ':id' => $orderId]);
}

/**
 * Idempotent — safe to call from the webhook, the confirmation page fallback,
 * or an admin "mark paid". Decrements stock, bumps discount usage and sends
 * the confirmation email exactly once.
 */
function shop_mark_order_paid(PDO $pdo, int $orderId, ?string $paymentIntentId = null): void
{
    $order = shop_get_order($pdo, $orderId);
    if (!$order) {
        return;
    }
    if (in_array((string) $order['status'], ['paid', 'collected', 'refunded'], true)) {
        if ($paymentIntentId && empty($order['stripe_payment_intent_id'])) {
            $pdo->prepare('UPDATE shop_orders SET stripe_payment_intent_id = :pi WHERE id = :id')
                ->execute([':pi' => $paymentIntentId, ':id' => $orderId]);
        }
        return;
    }

    $pdo->prepare("UPDATE shop_orders SET status = 'paid', paid_at = COALESCE(paid_at, NOW()),
        stripe_payment_intent_id = COALESCE(:pi, stripe_payment_intent_id) WHERE id = :id AND status <> 'paid'")
        ->execute([':pi' => $paymentIntentId, ':id' => $orderId]);

    // Stock + discount usage.
    foreach (shop_order_items($pdo, $orderId) as $item) {
        if ($item['product_id'] !== null) {
            $pdo->prepare('UPDATE shop_products SET stock_qty = GREATEST(0, stock_qty - :q)
                WHERE id = :id AND stock_qty IS NOT NULL')
                ->execute([':q' => (int) $item['quantity'], ':id' => (int) $item['product_id']]);
        }
        foreach ((array) json_decode((string) ($item['options_json'] ?? '[]'), true) as $opt) {
            if (!empty($opt['option_id'])) {
                $pdo->prepare('UPDATE shop_modifier_options SET stock_qty = GREATEST(0, stock_qty - :q)
                    WHERE id = :id AND stock_qty IS NOT NULL')
                    ->execute([':q' => (int) $item['quantity'], ':id' => (int) $opt['option_id']]);
            }
        }
    }
    if (!empty($order['discount_code'])) {
        $pdo->prepare('UPDATE shop_discount_codes SET used_count = used_count + 1 WHERE code = :c')
            ->execute([':c' => (string) $order['discount_code']]);
    }

    try {
        shop_send_confirmation_email($pdo, $orderId);
        shop_send_admin_notification($pdo, $orderId);
    } catch (Throwable $e) {
        error_log('[shop] confirmation email failed for order ' . $orderId . ': ' . $e->getMessage());
    }
}

function shop_cancel_order(PDO $pdo, int $orderId, string $reason = ''): void
{
    $order = shop_get_order($pdo, $orderId);
    if (!$order || in_array((string) $order['status'], ['paid', 'collected', 'refunded'], true)) {
        return;
    }
    $pdo->prepare("UPDATE shop_orders SET status = 'cancelled', cancelled_at = COALESCE(cancelled_at, NOW()),
        customer_note = TRIM(CONCAT(COALESCE(customer_note,''), :note)) WHERE id = :id")
        ->execute([':note' => $reason !== '' ? "\n[cancelled] " . $reason : '', ':id' => $orderId]);
}

function shop_mark_collected(PDO $pdo, int $orderId): void
{
    $pdo->prepare("UPDATE shop_orders SET status = 'collected', collected_at = COALESCE(collected_at, NOW())
        WHERE id = :id AND status = 'paid'")->execute([':id' => $orderId]);
}

function shop_mark_vsn_ordered(PDO $pdo, int $orderId): void
{
    $pdo->prepare('UPDATE shop_orders SET vsn_ordered_at = COALESCE(vsn_ordered_at, NOW()) WHERE id = :id')
        ->execute([':id' => $orderId]);
}

/**
 * Refund via Stripe (full or partial) and record it on the order.
 */
function shop_refund_order(PDO $pdo, int $orderId, float $amount, string $reason = ''): void
{
    $order = shop_get_order($pdo, $orderId);
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }
    $paymentIntent = (string) ($order['stripe_payment_intent_id'] ?? '');
    if ($paymentIntent === '') {
        throw new RuntimeException('This order has no Stripe payment to refund.');
    }
    $already = (float) $order['amount_refunded'];
    $remaining = round((float) $order['total'] - $already, 2);
    $amount = round(min($amount, $remaining), 2);
    if ($amount <= 0) {
        throw new RuntimeException('Nothing left to refund on this order.');
    }

    $params = [
        'payment_intent' => $paymentIntent,
        'amount' => stripe_amount_to_minor_units($amount, SHOP_CURRENCY),
        'metadata' => ['kind' => 'shop', 'order_id' => (string) $orderId, 'order_ref' => (string) $order['order_ref']],
    ];
    if (in_array($reason, ['duplicate', 'fraudulent', 'requested_by_customer'], true)) {
        $params['reason'] = $reason;
    }
    $refund = stripe_request('POST', '/refunds', $params);

    $newRefunded = round($already + $amount, 2);
    $fullyRefunded = $newRefunded >= (float) $order['total'] - 0.001;
    $pdo->prepare('UPDATE shop_orders SET amount_refunded = :amt, stripe_refund_id = :rid,
        status = IF(:full = 1, \'refunded\', status) WHERE id = :id')
        ->execute([
            ':amt' => $newRefunded,
            ':rid' => (string) ($refund['id'] ?? ''),
            ':full' => $fullyRefunded ? 1 : 0,
            ':id' => $orderId,
        ]);
}

/* -------------------------------------------------------------------------
 * Stripe Checkout
 * ---------------------------------------------------------------------- */

/**
 * @return array{url:string, session_id:string}
 */
function shop_create_checkout_session(PDO $pdo, array $order, string $successUrl, string $cancelUrl): array
{
    $items = shop_order_items($pdo, (int) $order['id']);
    if (!$items) {
        throw new RuntimeException('This order has no items.');
    }

    $lineItems = [];
    foreach ($items as $item) {
        $lineItems[] = [
            'quantity' => (int) $item['quantity'],
            'price_data' => [
                'currency' => SHOP_CURRENCY,
                'unit_amount' => stripe_amount_to_minor_units((float) $item['unit_price'], SHOP_CURRENCY),
                'product_data' => array_filter([
                    'name' => (string) $item['product_name'],
                    'description' => (string) ($item['options_label'] ?: null),
                ]),
            ],
        ];
    }

    // A basket-level discount becomes a Stripe coupon so Stripe's total matches ours.
    $discounts = [];
    if ((float) $order['discount_total'] > 0) {
        $coupon = stripe_request('POST', '/coupons', [
            'amount_off' => stripe_amount_to_minor_units((float) $order['discount_total'], SHOP_CURRENCY),
            'currency' => SHOP_CURRENCY,
            'duration' => 'once',
            'name' => 'Discount ' . (string) $order['discount_code'],
        ]);
        $discounts[] = ['coupon' => (string) $coupon['id']];
    }

    $metadata = [
        'kind' => 'shop',
        'order_id' => (string) $order['id'],
        'order_ref' => (string) $order['order_ref'],
    ];
    $params = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'line_items' => $lineItems,
        'client_reference_id' => (string) $order['order_ref'],
        'metadata' => $metadata,
        'payment_intent_data' => ['metadata' => $metadata],
    ];
    if ($discounts) {
        $params['discounts'] = $discounts;
    }
    $email = (string) $order['customer_email'];
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $params['customer_email'] = $email;
    }

    $session = stripe_request('POST', '/checkout/sessions', $params);
    $sessionId = (string) $session['id'];
    shop_attach_stripe_session($pdo, (int) $order['id'], $sessionId);

    return ['url' => (string) $session['url'], 'session_id' => $sessionId];
}

/** @param array<string,mixed> $session decoded Stripe Checkout Session */
function shop_stripe_handle_checkout_completed(PDO $pdo, array $session): void
{
    shop_ensure_schema($pdo);
    $sessionId = (string) ($session['id'] ?? '');
    if ($sessionId === '' || (string) ($session['payment_status'] ?? '') !== 'paid') {
        return;
    }
    $order = shop_get_order_by_session($pdo, $sessionId);
    if (!$order) {
        $orderId = (int) ($session['metadata']['order_id'] ?? 0);
        $order = $orderId > 0 ? shop_get_order($pdo, $orderId) : null;
    }
    if (!$order) {
        error_log('[shop] checkout.session.completed for unknown session ' . $sessionId);
        return;
    }
    $paymentIntent = is_string($session['payment_intent'] ?? null) ? (string) $session['payment_intent'] : null;
    shop_mark_order_paid($pdo, (int) $order['id'], $paymentIntent);
}

/** @param array<string,mixed> $session decoded Stripe Checkout Session */
function shop_stripe_handle_checkout_expired(PDO $pdo, array $session): void
{
    shop_ensure_schema($pdo);
    $sessionId = (string) ($session['id'] ?? '');
    if ($sessionId === '') {
        return;
    }
    $order = shop_get_order_by_session($pdo, $sessionId);
    if ($order && (string) $order['status'] === 'pending_payment') {
        shop_cancel_order($pdo, (int) $order['id'], 'Stripe Checkout session expired');
    }
}

/**
 * Confirmation-page fallback for when the webhook hasn't landed yet: pull the
 * session straight from Stripe and finalise if it's paid.
 */
function shop_finalize_pending_order_from_stripe(PDO $pdo, array $order): array
{
    if ((string) $order['status'] !== 'pending_payment') {
        return $order;
    }
    $sessionId = (string) ($order['stripe_checkout_session_id'] ?? '');
    if ($sessionId === '' || !stripe_is_configured()) {
        return $order;
    }
    try {
        $session = stripe_request('GET', '/checkout/sessions/' . rawurlencode($sessionId));
        if ((string) ($session['payment_status'] ?? '') === 'paid') {
            $pi = is_string($session['payment_intent'] ?? null) ? (string) $session['payment_intent'] : null;
            shop_mark_order_paid($pdo, (int) $order['id'], $pi);
        } elseif ((string) ($session['status'] ?? '') === 'expired') {
            shop_stripe_handle_checkout_expired($pdo, $session);
        }
    } catch (Throwable $e) {
        error_log('[shop] finalize fallback failed for order ' . $order['id'] . ': ' . $e->getMessage());
    }
    return shop_get_order($pdo, (int) $order['id']) ?? $order;
}

/* -------------------------------------------------------------------------
 * Email
 * ---------------------------------------------------------------------- */

function shop_email_wrapper(string $subject, string $preheader, string $heroTitle, string $heroSubtitle, string $bodyHtml): string
{
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($subject) . '</title></head>
    <body style="margin:0;padding:0;background:#f6ecde;font-family:Inter,Arial,sans-serif;color:#21141a;">
        <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . h($preheader) . '</div>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6ecde;padding:28px 12px;">
            <tr><td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 18px 45px rgba(75,8,24,.14);">
                    <tr>
                        <td style="background:linear-gradient(135deg,#4b0818,#7a1730 62%,#a6791d);padding:28px 26px;color:#ffffff;">
                            <div style="font-size:12px;text-transform:uppercase;letter-spacing:.14em;font-weight:900;color:rgba(255,255,255,.74);">Saltcoats Victoria FC</div>
                            <h1 style="margin:8px 0 8px;font-size:26px;line-height:1.15;color:#ffffff;">' . h($heroTitle) . '</h1>
                            <p style="margin:0;color:rgba(255,255,255,.84);font-size:15px;">' . h($heroSubtitle) . '</p>
                        </td>
                    </tr>
                    <tr><td style="padding:26px;">' . $bodyHtml . '</td></tr>
                    <tr>
                        <td style="padding:18px 26px;background:#21141a;color:rgba(255,255,255,.72);font-size:13px;text-align:center;">
                            Saltcoats Victoria FC Club Shop &middot; Thank you for your support
                        </td>
                    </tr>
                </table>
            </td></tr>
        </table>
    </body></html>';
}

function shop_send_mail(string $toEmail, string $subject, string $html): bool
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $host = preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'myclubhub.co.uk'))));
    $fromAddress = 'no-reply@' . ($host !== '' ? $host : 'myclubhub.co.uk');
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: Saltcoats Victoria FC Club Shop <' . $fromAddress . '>',
    ]);
    return (bool) @mail($toEmail, $subject, $html, $headers, '-f' . $fromAddress);
}

function shop_order_items_email_rows(array $items): string
{
    $rows = '';
    foreach ($items as $item) {
        $name = h((string) $item['product_name']);
        if (($item['options_label'] ?? '') !== '') {
            $name .= '<br><span style="color:#7a6f74;font-size:13px;">' . h((string) $item['options_label']) . '</span>';
        }
        $rows .= '<tr>
            <td style="padding:10px 0;border-bottom:1px solid #eadfdf;color:#21141a;font-weight:700;">' . $name . '</td>
            <td style="padding:10px 0;border-bottom:1px solid #eadfdf;color:#21141a;text-align:center;">&times;' . (int) $item['quantity'] . '</td>
            <td style="padding:10px 0;border-bottom:1px solid #eadfdf;color:#21141a;text-align:right;font-weight:700;">' . h(gbp((float) $item['line_total'])) . '</td>
        </tr>';
    }
    return $rows;
}

function shop_send_confirmation_email(PDO $pdo, int $orderId, bool $force = false): bool
{
    $order = shop_get_order($pdo, $orderId);
    if (!$order || !in_array((string) $order['status'], ['paid', 'collected'], true)) {
        return false;
    }
    if (!$force && !empty($order['confirmation_email_sent_at'])) {
        return true;
    }
    $email = trim((string) $order['customer_email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $items = shop_order_items($pdo, $orderId);
    $collectionPoint = (string) ($order['collection_point'] ?: shop_setting($pdo, 'collection_point', SHOP_COLLECTION_POINT_DEFAULT));
    $collectionDetails = shop_setting($pdo, 'collection_details', '');
    $lead = shop_setting($pdo, 'lead_time', SHOP_LEAD_TIME_DEFAULT);

    $summaryRows = shop_order_items_email_rows($items);
    if ((float) $order['discount_total'] > 0) {
        $summaryRows .= '<tr><td colspan="2" style="padding:8px 0;color:#4a4046;">Discount (' . h((string) $order['discount_code']) . ')</td>
            <td style="padding:8px 0;text-align:right;color:#4a4046;">&minus;' . h(gbp((float) $order['discount_total'])) . '</td></tr>';
    }

    $timeline = '';
    if ((int) $order['is_preorder'] === 1) {
        $timeline = '<div style="margin:18px 0;padding:16px 18px;border-radius:12px;background:#fff4e5;color:#7a4a06;font-size:14px;line-height:1.6;">
            <strong>This is a pre-order.</strong><br>
            Kit pre-orders close on <strong>14 September 2026</strong>. After that date the full club order is placed with VSN.
            VSN manufacturing then typically takes <strong>' . h($lead) . '</strong>.<br>
            All orders are collected from <strong>' . h($collectionPoint) . '</strong> — there is no delivery option.
            We will email you as soon as your order is ready to collect.
        </div>';
    }

    $body = '<p style="margin:0 0 16px;font-size:16px;line-height:1.55;">Hi ' . h((string) $order['customer_name']) . ',</p>'
        . '<p style="margin:0 0 20px;font-size:16px;line-height:1.55;color:#4a4046;">Thanks for your order. We\'ve received your payment in full. Here are the details for <strong>' . h((string) $order['order_ref']) . '</strong>.</p>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 12px;">' . $summaryRows . '</table>'
        . '<p style="margin:0 0 4px;color:#4b0818;font-size:18px;font-weight:900;">Total paid: ' . h(gbp((float) $order['total'])) . '</p>'
        . $timeline
        . '<div style="margin:18px 0 0;padding:16px 18px;border-radius:12px;background:#f6ecde;color:#3c2f34;font-size:14px;line-height:1.6;">
            <strong>Collection</strong><br>' . h($collectionPoint)
        . ($collectionDetails !== '' ? '<br>' . h($collectionDetails) : '') . '</div>';

    $subject = 'Your Saltcoats Victoria FC Club Shop order ' . (string) $order['order_ref'];
    $html = shop_email_wrapper($subject, 'Your Club Shop order confirmation.', 'Order confirmed', (string) $order['order_ref'], $body);
    $sent = shop_send_mail($email, $subject, $html);
    if ($sent) {
        $pdo->prepare('UPDATE shop_orders SET confirmation_email_sent_at = COALESCE(confirmation_email_sent_at, NOW()) WHERE id = :id')
            ->execute([':id' => $orderId]);
    }
    return $sent;
}

function shop_send_admin_notification(PDO $pdo, int $orderId): void
{
    $to = trim(shop_setting($pdo, 'contact_email', ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $order = shop_get_order($pdo, $orderId);
    if (!$order) {
        return;
    }
    $items = shop_order_items($pdo, $orderId);
    $body = '<p style="margin:0 0 12px;font-size:15px;">New paid Club Shop order <strong>' . h((string) $order['order_ref']) . '</strong>.</p>'
        . '<p style="margin:0 0 12px;font-size:14px;color:#4a4046;">'
        . h((string) $order['customer_name']) . ' &middot; ' . h((string) $order['customer_email'])
        . ($order['customer_phone'] ? ' &middot; ' . h((string) $order['customer_phone']) : '') . '</p>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">'
        . shop_order_items_email_rows($items) . '</table>'
        . '<p style="margin:12px 0 0;font-weight:900;color:#4b0818;">Total paid: ' . h(gbp((float) $order['total'])) . '</p>';
    $subject = '[Club Shop] New order ' . (string) $order['order_ref'] . ' — ' . gbp((float) $order['total']);
    shop_send_mail($to, $subject, shop_email_wrapper($subject, 'New Club Shop order.', 'New order', (string) $order['order_ref'], $body));
}
