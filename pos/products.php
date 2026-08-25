<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/pos.php';
require_once __DIR__ . '/../lib/audit.php';

pos_ensure_schema($pdo);
pos_require_actor($pdo);
if (!pos_actor_is_manager($pdo)) {
    http_response_code(403);
    exit('POS manager access required.');
}

$notice = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $error = 'Your session expired. Please reload and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_product') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            $price = max(0, (float) ($_POST['price'] ?? 0));
            $colour = preg_match('/^#[a-f0-9]{6}$/i', (string) ($_POST['button_colour'] ?? '')) ? (string) $_POST['button_colour'] : '#4b0818';
            if ($name === '' || $categoryId <= 0) {
                $error = 'Product name and category are required.';
            } else {
                $isNewProduct = $id <= 0;
                if ($id > 0) {
                    $pdo->prepare('UPDATE pos_products SET category_id=:category, name=:name, price=:price, button_colour=:colour, earns_points=:earns, discountable=:discountable, is_active=:active WHERE id=:id')
                        ->execute([
                            ':id' => $id,
                            ':category' => $categoryId,
                            ':name' => $name,
                            ':price' => $price,
                            ':colour' => $colour,
                            ':earns' => !empty($_POST['earns_points']) ? 1 : 0,
                            ':discountable' => !empty($_POST['discountable']) ? 1 : 0,
                            ':active' => !empty($_POST['is_active']) ? 1 : 0,
                        ]);
                } else {
                    $pdo->prepare('INSERT INTO pos_products (category_id, name, price, button_colour, earns_points, discountable, is_active) VALUES (:category, :name, :price, :colour, :earns, :discountable, 1)')
                        ->execute([
                            ':category' => $categoryId,
                            ':name' => $name,
                            ':price' => $price,
                            ':colour' => $colour,
                            ':earns' => !empty($_POST['earns_points']) ? 1 : 0,
                            ':discountable' => !empty($_POST['discountable']) ? 1 : 0,
                        ]);
                    $id = (int) $pdo->lastInsertId();
                }
                $pdo->prepare('DELETE FROM pos_location_products WHERE product_id = :id')->execute([':id' => $id]);
                $link = $pdo->prepare('INSERT INTO pos_location_products (location_id, product_id, price_override, is_available) VALUES (:location, :product, NULL, 1)');
                foreach (array_map('intval', (array) ($_POST['location_ids'] ?? [])) as $locationId) {
                    $link->execute([':location' => $locationId, ':product' => $id]);
                }
                auditLog($pdo, $isNewProduct ? 'pos_product_created' : 'pos_product_updated', ($isNewProduct ? "Created POS product '{$name}'" : "Updated POS product '{$name}' (#{$id})"));
                $notice = 'Product saved.';
            }
        }
        if ($action === 'save_discount') {
            $discountName = trim((string) ($_POST['discount_name'] ?? 'Season ticket discount'));
            $pdo->prepare('INSERT INTO pos_discount_rules (name, category_id, product_id, discount_type, discount_value, is_active) VALUES (:name, :category, :product, :type, :value, 1)')
                ->execute([
                    ':name' => $discountName,
                    ':category' => (int) ($_POST['discount_category_id'] ?? 0) ?: null,
                    ':product' => (int) ($_POST['discount_product_id'] ?? 0) ?: null,
                    ':type' => (string) ($_POST['discount_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent',
                    ':value' => max(0, (float) ($_POST['discount_value'] ?? 0)),
                ]);
            auditLog($pdo, 'pos_discount_rule_created', "Created POS discount rule '{$discountName}'");
            $notice = 'Discount rule added.';
        }
    }
}

$categories = $pdo->query('SELECT * FROM pos_categories WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC);
$locations = $pdo->query('SELECT * FROM pos_locations WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC);
$products = $pdo->query('SELECT p.*, c.name AS category_name FROM pos_products p JOIN pos_categories c ON c.id = p.category_id ORDER BY c.sort_order, p.sort_order, p.name')->fetchAll(PDO::FETCH_ASSOC);
$available = [];
foreach ($pdo->query('SELECT product_id, location_id FROM pos_location_products WHERE is_available = 1') as $row) {
    $available[(int) $row['product_id']][] = (int) $row['location_id'];
}
$rules = $pdo->query('SELECT r.*, c.name AS category_name, p.name AS product_name FROM pos_discount_rules r LEFT JOIN pos_categories c ON c.id = r.category_id LEFT JOIN pos_products p ON p.id = r.product_id ORDER BY r.id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>POS Products - Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{background:#f7efe4;font-family:Inter,system-ui,sans-serif}.wrap{max-width:1180px;margin:0 auto;padding:1rem}.hero{background:linear-gradient(135deg,#4b0818,#8a1538);color:#fff}.cardx{background:#fff;border:1px solid rgba(75,8,24,.12);border-radius:1rem;box-shadow:0 14px 36px rgba(36,21,26,.08)}.swatch{display:inline-block;width:18px;height:18px;border-radius:50%;vertical-align:middle}</style>
</head>
<body>
<header class="hero"><div class="wrap d-flex justify-content-between align-items-center"><h1 class="h3 fw-bold mb-0">POS Products</h1><nav class="d-flex gap-3"><a class="text-white fw-bold" href="/pos/">Till</a><a class="text-white fw-bold" href="/pos/reports.php">Reports</a><a class="text-white fw-bold" href="/pos/operators.php">Operators</a></nav></div></header>
<main class="wrap">
    <?php if ($notice): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
    <section class="cardx p-3 mb-3">
        <h2 class="h5 fw-bold">Add product</h2>
        <form method="post" class="row g-3">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_product">
            <div class="col-md-3"><label class="form-label">Name</label><input class="form-control" name="name" required></div>
            <div class="col-md-2"><label class="form-label">Category</label><select class="form-select" name="category_id"><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= h((string) $category['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Price</label><input class="form-control" name="price" type="number" min="0" step="0.01" required></div>
            <div class="col-md-2"><label class="form-label">Button colour</label><input class="form-control form-control-color" name="button_colour" value="#4b0818"></div>
            <div class="col-md-12"><label class="form-label">Locations</label><div class="d-flex gap-3 flex-wrap"><?php foreach ($locations as $location): ?><label><input type="checkbox" name="location_ids[]" value="<?= (int) $location['id'] ?>"> <?= h((string) $location['name']) ?></label><?php endforeach; ?></div></div>
            <div class="col-md-12 d-flex gap-3"><label><input type="checkbox" name="earns_points" checked> Earns points</label><label><input type="checkbox" name="discountable" checked> Discountable</label></div>
            <div class="col-12"><button class="btn btn-dark" type="submit">Save product</button></div>
        </form>
    </section>

    <section class="cardx p-3 mb-3">
        <h2 class="h5 fw-bold">Season ticket discount rule</h2>
        <form method="post" class="row g-3">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_discount">
            <div class="col-md-3"><label class="form-label">Rule name</label><input class="form-control" name="discount_name" value="Season ticket discount"></div>
            <div class="col-md-2"><label class="form-label">Category</label><select class="form-select" name="discount_category_id"><option value="">Any</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= h((string) $category['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Product</label><select class="form-select" name="discount_product_id"><option value="">Any</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>"><?= h((string) $product['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Type</label><select class="form-select" name="discount_type"><option value="percent">Percent</option><option value="fixed">Fixed</option></select></div>
            <div class="col-md-2"><label class="form-label">Value</label><input class="form-control" name="discount_value" type="number" min="0" step="0.01" value="10"></div>
            <div class="col-12"><button class="btn btn-dark" type="submit">Add rule</button></div>
        </form>
    </section>

    <section class="cardx table-responsive mb-3">
        <table class="table align-middle mb-0"><thead><tr><th>Product</th><th>Category</th><th>Price</th><th>Locations</th><th>Colour</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($products as $product): ?>
                <?php $ids = $available[(int) $product['id']] ?? []; ?>
                <tr><td class="fw-bold"><?= h((string) $product['name']) ?></td><td><?= h((string) $product['category_name']) ?></td><td><?= gbp((float) $product['price']) ?></td><td><?= h(implode(', ', array_map(static fn($l) => (string) $l['name'], array_filter($locations, static fn($l) => in_array((int) $l['id'], $ids, true))))) ?></td><td><span class="swatch" style="background:<?= h((string) $product['button_colour']) ?>"></span></td><td><?= (int) $product['is_active'] === 1 ? 'Active' : 'Inactive' ?></td></tr>
            <?php endforeach; ?>
        </tbody></table>
    </section>

    <section class="cardx table-responsive">
        <table class="table align-middle mb-0"><thead><tr><th>Rule</th><th>Scope</th><th>Discount</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($rules as $rule): ?><tr><td class="fw-bold"><?= h((string) $rule['name']) ?></td><td><?= h((string) ($rule['product_name'] ?: $rule['category_name'] ?: 'Any discountable product')) ?></td><td><?= h((string) $rule['discount_value']) ?><?= (string) $rule['discount_type'] === 'percent' ? '%' : '' ?></td><td><?= (int) $rule['is_active'] === 1 ? 'Active' : 'Inactive' ?></td></tr><?php endforeach; ?>
        </tbody></table>
    </section>
</main>
</body>
</html>
