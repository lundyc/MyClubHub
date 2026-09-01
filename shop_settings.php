<?php

declare(strict_types=1);

// Club Shop — settings, pre-order window and discount codes. Hub admin only.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/shop.php';
require_once __DIR__ . '/account_auth.php';
require_once __DIR__ . '/lib/audit.php';

shop_ensure_schema($pdo);
$isAdmin = hub_auth_is_admin();

const SHOP_SETTING_KEYS = [
    'shop_enabled', 'shop_name', 'shop_intro', 'contact_email',
    'collection_point', 'collection_details', 'delivery_note',
    'lead_time', 'preorder_close_at', 'preorder_intro', 'terms',
];

if ($isAdmin && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        header('Location: /shop_settings.php?e=' . rawurlencode('Session expired — try again.'));
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_settings') {
            foreach (SHOP_SETTING_KEYS as $key) {
                if ($key === 'shop_enabled') {
                    shop_save_setting($pdo, $key, empty($_POST['shop_enabled']) ? '0' : '1');
                    continue;
                }
                if (!array_key_exists($key, $_POST)) {
                    continue;
                }
                $value = (string) $_POST[$key];
                if ($key === 'preorder_close_at' && trim($value) !== '') {
                    $value = date('Y-m-d H:i:s', strtotime($value) ?: time());
                }
                shop_save_setting($pdo, $key, trim($value));
            }
            auditLog($pdo, 'shop_settings_updated', 'Club Shop settings updated');
            $m = 'Settings saved.';
        } elseif ($action === 'save_code') {
            shop_save_discount_code($pdo, (int) ($_POST['id'] ?? 0) ?: null, $_POST);
            auditLog($pdo, 'shop_discount_saved', 'Discount code "' . strtoupper((string) ($_POST['code'] ?? '')) . '"');
            $m = 'Discount code saved.';
        } elseif ($action === 'delete_code') {
            shop_delete_discount_code($pdo, (int) ($_POST['id'] ?? 0));
            $m = 'Discount code deleted.';
        } else {
            $m = '';
        }
        header('Location: /shop_settings.php' . ($m !== '' ? '?m=' . rawurlencode($m) : ''));
        exit;
    } catch (Throwable $e) {
        header('Location: /shop_settings.php?e=' . rawurlencode($e->getMessage()));
        exit;
    }
}

$pageHero = [
    'eyebrow' => 'Shop',
    'title' => 'Shop settings',
    'subtitle' => 'Storefront copy, collection details, the pre-order window and discount codes.',
    'actions' => [['label' => 'Back to shop', 'href' => '/shop_overview.php', 'class' => 'btn btn-outline-light btn-sm']],
];
require_once __DIR__ . '/header.php';

if ((string) ($currentRole ?? 'guest') !== 'admin') {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to manage the shop.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$s = shop_get_settings($pdo);
$closeValue = '';
if (!empty($s['preorder_close_at'])) {
    $closeValue = (new DateTimeImmutable((string) $s['preorder_close_at']))->format('Y-m-d\TH:i');
}
$codes = shop_discount_codes($pdo);
$editCode = null;
foreach ($codes as $c) {
    if ((int) $c['id'] === (int) ($_GET['code'] ?? 0)) {
        $editCode = $c;
    }
}
?>
<div class="shop-admin-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/shop_overview.php">Shop</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page">Settings</span></nav>

    <?php if (isset($_GET['m'])): ?><div class="alert alert-success"><?= h((string) $_GET['m']) ?></div><?php endif; ?>
    <?php if (isset($_GET['e'])): ?><div class="alert alert-danger"><?= h((string) $_GET['e']) ?></div><?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_settings">
        <div class="row g-3">
            <div class="col-lg-6">
                <section class="card hub-panel p-3 mb-3">
                    <h2 class="h6 fw-bold text-uppercase text-muted">Storefront</h2>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="shop_enabled" name="shop_enabled" value="1" <?= ($s['shop_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="shop_enabled">Storefront open to customers</label>
                    </div>
                    <div class="mb-2"><label class="form-label">Shop name</label>
                        <input class="form-control" name="shop_name" value="<?= h((string) ($s['shop_name'] ?? 'Club Shop')) ?>"></div>
                    <div class="mb-2"><label class="form-label">Intro paragraph</label>
                        <textarea class="form-control" name="shop_intro" rows="2"><?= h((string) ($s['shop_intro'] ?? '')) ?></textarea></div>
                    <div class="mb-0"><label class="form-label">Order notification email <span class="text-muted small">(optional)</span></label>
                        <input class="form-control" type="email" name="contact_email" value="<?= h((string) ($s['contact_email'] ?? '')) ?>" placeholder="shop@saltcoatsvictoria.co.uk">
                        <div class="form-text">A copy of every paid order is emailed here.</div></div>
                </section>

                <section class="card hub-panel p-3">
                    <h2 class="h6 fw-bold text-uppercase text-muted">Collection</h2>
                    <div class="mb-2"><label class="form-label">Collection point</label>
                        <input class="form-control" name="collection_point" value="<?= h((string) ($s['collection_point'] ?? 'Campbell Park')) ?>"></div>
                    <div class="mb-2"><label class="form-label">Collection details</label>
                        <textarea class="form-control" name="collection_details" rows="2"><?= h((string) ($s['collection_details'] ?? '')) ?></textarea></div>
                    <div class="mb-0"><label class="form-label">Delivery note</label>
                        <input class="form-control" name="delivery_note" value="<?= h((string) ($s['delivery_note'] ?? '')) ?>"></div>
                </section>
            </div>

            <div class="col-lg-6">
                <section class="card hub-panel p-3 mb-3">
                    <h2 class="h6 fw-bold text-uppercase text-muted">Pre-order window</h2>
                    <div class="mb-2"><label class="form-label">Default close date &amp; time</label>
                        <input class="form-control" type="datetime-local" name="preorder_close_at" value="<?= h($closeValue) ?>">
                        <div class="form-text">Used for any pre-order product without its own close date. Ordering is blocked after this.</div></div>
                    <div class="mb-2"><label class="form-label">Manufacturing lead time</label>
                        <input class="form-control" name="lead_time" value="<?= h((string) ($s['lead_time'] ?? '6–8 weeks')) ?>"></div>
                    <div class="mb-0"><label class="form-label">Pre-order explainer (storefront &amp; emails)</label>
                        <textarea class="form-control" name="preorder_intro" rows="3"><?= h((string) ($s['preorder_intro'] ?? '')) ?></textarea></div>
                </section>

                <section class="card hub-panel p-3">
                    <h2 class="h6 fw-bold text-uppercase text-muted">Checkout terms</h2>
                    <textarea class="form-control" name="terms" rows="8"><?= h((string) ($s['terms'] ?? '')) ?></textarea>
                    <div class="form-text">Shown on the checkout page; the customer must tick to accept.</div>
                </section>
            </div>
        </div>
        <div class="mt-3"><button class="btn btn-dark" type="submit">Save settings</button></div>
    </form>

    <hr class="my-4">

    <div class="row g-3">
        <div class="col-lg-5">
            <section class="card hub-panel p-3">
                <h2 class="h6 fw-bold text-uppercase text-muted"><?= $editCode ? 'Edit discount code' : 'Add discount code' ?></h2>
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_code">
                    <?php if ($editCode): ?><input type="hidden" name="id" value="<?= (int) $editCode['id'] ?>"><?php endif; ?>
                    <div class="col-7"><label class="form-label">Code</label>
                        <input class="form-control text-uppercase" name="code" required value="<?= h((string) ($editCode['code'] ?? '')) ?>" placeholder="SUPPORTER10"></div>
                    <div class="col-5"><label class="form-label">Type</label>
                        <select class="form-select" name="discount_type">
                            <option value="percent" <?= (string) ($editCode['discount_type'] ?? 'percent') === 'percent' ? 'selected' : '' ?>>Percent %</option>
                            <option value="fixed" <?= (string) ($editCode['discount_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed £</option>
                        </select></div>
                    <div class="col-6"><label class="form-label">Value</label>
                        <input class="form-control" type="number" step="0.01" min="0" name="discount_value" required value="<?= h((string) ($editCode['discount_value'] ?? '10')) ?>"></div>
                    <div class="col-6"><label class="form-label">Min spend (£)</label>
                        <input class="form-control" type="number" step="0.01" min="0" name="min_spend" value="<?= h((string) ($editCode['min_spend'] ?? '0')) ?>"></div>
                    <div class="col-6"><label class="form-label">Max uses</label>
                        <input class="form-control" type="number" min="1" name="max_uses" value="<?= $editCode && $editCode['max_uses'] !== null ? (int) $editCode['max_uses'] : '' ?>" placeholder="∞"></div>
                    <div class="col-6 d-flex align-items-end"><div class="form-check">
                        <input class="form-check-input" type="checkbox" id="dc_active" name="is_active" value="1" <?= (int) ($editCode['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="dc_active">Active</label></div></div>
                    <div class="col-6"><label class="form-label">Starts</label>
                        <input class="form-control" type="datetime-local" name="starts_at" value="<?= $editCode && $editCode['starts_at'] ? h((new DateTimeImmutable((string) $editCode['starts_at']))->format('Y-m-d\TH:i')) : '' ?>"></div>
                    <div class="col-6"><label class="form-label">Ends</label>
                        <input class="form-control" type="datetime-local" name="ends_at" value="<?= $editCode && $editCode['ends_at'] ? h((new DateTimeImmutable((string) $editCode['ends_at']))->format('Y-m-d\TH:i')) : '' ?>"></div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-dark" type="submit"><?= $editCode ? 'Save code' : 'Add code' ?></button>
                        <?php if ($editCode): ?><a class="btn btn-outline-secondary" href="/shop_settings.php">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </section>
        </div>
        <div class="col-lg-7">
            <section class="card hub-panel table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Code</th><th>Discount</th><th>Min spend</th><th>Uses</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$codes): ?><tr><td colspan="6" class="text-muted text-center py-4">No discount codes.</td></tr><?php endif; ?>
                    <?php foreach ($codes as $c): ?>
                        <tr>
                            <td class="fw-bold"><?= h((string) $c['code']) ?></td>
                            <td><?= (string) $c['discount_type'] === 'percent' ? h((string) (float) $c['discount_value']) . '%' : gbp((float) $c['discount_value']) ?></td>
                            <td><?= (float) $c['min_spend'] > 0 ? gbp((float) $c['min_spend']) : '—' ?></td>
                            <td><?= (int) $c['used_count'] ?><?= $c['max_uses'] !== null ? ' / ' . (int) $c['max_uses'] : '' ?></td>
                            <td><?= (int) $c['is_active'] === 1 ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Off</span>' ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="/shop_settings.php?code=<?= (int) $c['id'] ?>">Edit</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this code?');">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_code"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">×</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
