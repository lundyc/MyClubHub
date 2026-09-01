<?php

declare(strict_types=1);

// Club Shop — orders list, filtering and CSV export. Hub admin only.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/shop.php';
require_once __DIR__ . '/account_auth.php';

shop_ensure_schema($pdo);

$status = (string) ($_GET['status'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));
$preorderOnly = !empty($_GET['preorder']);
$filters = array_filter([
    'status' => $status,
    'search' => $search,
    'preorder_only' => $preorderOnly,
]);

// CSV export bypasses the HTML shell entirely.
if (($_GET['export'] ?? '') === 'csv') {
    if (!hub_auth_is_admin()) {
        http_response_code(403);
        exit('Forbidden');
    }
    $rows = shop_order_list($pdo, $filters);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="club-shop-orders-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['Ref', 'Placed', 'Status', 'Customer', 'Email', 'Phone', 'Items', 'Subtotal', 'Discount', 'Total', 'Refunded', 'Pre-order', 'Paid at', 'Collected at', 'Item detail'], ',', '"', '');
    foreach ($rows as $o) {
        $detail = [];
        foreach (shop_order_items($pdo, (int) $o['id']) as $it) {
            $detail[] = $it['quantity'] . '× ' . $it['product_name'] . ($it['options_label'] ? ' (' . $it['options_label'] . ')' : '');
        }
        fputcsv($out, [
            $o['order_ref'],
            $o['created_at'],
            $o['status'],
            $o['customer_name'],
            $o['customer_email'],
            $o['customer_phone'],
            $o['item_count'],
            number_format((float) $o['subtotal'], 2, '.', ''),
            number_format((float) $o['discount_total'], 2, '.', ''),
            number_format((float) $o['total'], 2, '.', ''),
            number_format((float) $o['amount_refunded'], 2, '.', ''),
            (int) $o['is_preorder'] === 1 ? 'yes' : 'no',
            (string) ($o['paid_at'] ?? ''),
            (string) ($o['collected_at'] ?? ''),
            implode(' | ', $detail),
        ], ',', '"', '');
    }
    fclose($out);
    exit;
}

$pageHero = [
    'eyebrow' => 'Shop',
    'title' => 'Orders',
    'subtitle' => 'Every order placed through the online shop.',
    'actions' => [['label' => 'Back to shop', 'href' => '/shop_overview.php', 'class' => 'btn btn-outline-light btn-sm']],
];
require_once __DIR__ . '/header.php';

if ((string) ($currentRole ?? 'guest') !== 'admin') {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to manage the shop.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$orders = shop_order_list($pdo, $filters);
$statuses = ['' => 'All statuses', 'pending_payment' => 'Pending payment', 'paid' => 'Paid', 'collected' => 'Collected', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded'];

$exportQuery = http_build_query(array_merge($_GET, ['export' => 'csv']));

function shop_status_badge(string $status): string
{
    $map = ['pending_payment' => 'secondary', 'paid' => 'success', 'collected' => 'primary', 'cancelled' => 'dark', 'refunded' => 'warning'];
    return '<span class="badge text-bg-' . ($map[$status] ?? 'secondary') . '">' . h(ucwords(str_replace('_', ' ', $status))) . '</span>';
}
?>
<div class="shop-admin-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/shop_overview.php">Shop</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page">Orders</span></nav>

    <form class="card hub-panel p-3 mb-3" method="get">
        <div class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label">Status</label>
                <select class="form-select" name="status" onchange="this.form.submit()">
                    <?php foreach ($statuses as $key => $label): ?>
                        <option value="<?= h($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="col-md-4"><label class="form-label">Search</label>
                <input class="form-control" name="q" value="<?= h($search) ?>" placeholder="Ref, name or email"></div>
            <div class="col-md-3 d-flex align-items-end"><div class="form-check">
                <input class="form-check-input" type="checkbox" id="f_preorder" name="preorder" value="1" <?= $preorderOnly ? 'checked' : '' ?> onchange="this.form.submit()">
                <label class="form-check-label" for="f_preorder">Pre-orders only</label></div></div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-dark" type="submit">Filter</button>
            </div>
        </div>
        <div class="mt-2">
            <a class="btn btn-sm btn-outline-secondary" href="/shop_orders.php?<?= h($exportQuery) ?>"><i class="fa-solid fa-download me-1"></i>Export CSV</a>
            <span class="text-muted small ms-2"><?= count($orders) ?> order<?= count($orders) === 1 ? '' : 's' ?></span>
        </div>
    </form>

    <section class="card hub-panel table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Ref</th><th>Placed</th><th>Customer</th><th class="text-end">Items</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$orders): ?><tr><td colspan="7" class="text-muted text-center py-4">No orders match.</td></tr><?php endif; ?>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td class="fw-bold"><a class="text-decoration-none" href="/shop_order.php?id=<?= (int) $o['id'] ?>"><?= h((string) $o['order_ref']) ?></a>
                        <?php if ((int) $o['is_preorder'] === 1): ?><span class="badge text-bg-warning ms-1">Pre-order</span><?php endif; ?></td>
                    <td class="small text-muted"><?= h((new DateTimeImmutable((string) $o['created_at']))->format('d/m/Y H:i')) ?></td>
                    <td><?= h((string) $o['customer_name']) ?><div class="small text-muted"><?= h((string) $o['customer_email']) ?></div></td>
                    <td class="text-end"><?= (int) $o['item_count'] ?></td>
                    <td class="text-end fw-bold"><?= gbp((float) $o['total']) ?><?php if ((float) $o['amount_refunded'] > 0): ?><div class="small text-warning">−<?= gbp((float) $o['amount_refunded']) ?></div><?php endif; ?></td>
                    <td><?= shop_status_badge((string) $o['status']) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/shop_order.php?id=<?= (int) $o['id'] ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>
<?php require __DIR__ . '/footer.php'; ?>
