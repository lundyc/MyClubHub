<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/pos.php';

pos_ensure_schema($pdo);
$actor = pos_require_actor($pdo);
if (!pos_actor_is_manager($pdo)) {
    http_response_code(403);
    exit('POS manager access required.');
}

$pageHero = [
    'eyebrow' => 'Point of sale',
    'title' => 'POS Overview',
    'subtitle' => 'Review till activity, transactions, locations, operators, and product setup.',
    'actions' => [
        ['label' => 'Open till', 'href' => '/pos/', 'variant' => 'primary', 'icon' => 'fa-cash-register'],
        ['label' => 'Products', 'href' => '/pos/products.php', 'icon' => 'fa-box-open'],
        ['label' => 'Operators', 'href' => '/pos/operators.php', 'icon' => 'fa-users-gear'],
    ],
];

$today = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('-6 days'));
$monthStart = date('Y-m-01');

$salesSummary = static function (PDO $pdo, string $from, ?string $to = null): array {
    $to = $to ?: $from;
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS sales_count,
            COALESCE(SUM(total), 0) AS total,
            COALESCE(SUM(discount_total + points_discount), 0) AS discounts,
            COALESCE(SUM(payment_method = 'cash'), 0) AS cash_count,
            COALESCE(SUM(payment_method IN ('card', 'card_stripe_app')), 0) AS card_count
        FROM pos_sales
        WHERE DATE(created_at) BETWEEN :from_date AND :to_date
          AND status = 'complete'
    ");
    $stmt->execute([':from_date' => $from, ':to_date' => $to]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['sales_count' => 0, 'total' => 0, 'discounts' => 0, 'cash_count' => 0, 'card_count' => 0];
};

$todaySummary = $salesSummary($pdo, $today);
$weekSummary = $salesSummary($pdo, $weekStart, $today);
$monthSummary = $salesSummary($pdo, $monthStart, $today);

$pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM pos_sales WHERE status IN ('pending_card', 'pending_cash')")->fetchColumn();
$productCount = (int) $pdo->query('SELECT COUNT(*) FROM pos_products WHERE is_active = 1')->fetchColumn();
$locationCount = (int) $pdo->query('SELECT COUNT(*) FROM pos_locations WHERE is_active = 1')->fetchColumn();
$operatorCount = (int) $pdo->query('SELECT COUNT(*) FROM pos_operators WHERE is_active = 1')->fetchColumn();

$recentStmt = $pdo->query("
    SELECT s.*, l.name AS location_name, o.name AS operator_name, u.display_name AS hub_user_name
    FROM pos_sales s
    JOIN pos_locations l ON l.id = s.location_id
    LEFT JOIN pos_operators o ON o.id = s.operator_id
    LEFT JOIN users u ON u.id = s.hub_user_id
    ORDER BY s.created_at DESC, s.id DESC
    LIMIT 100
");
$recentSales = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

$locationStmt = $pdo->prepare("
    SELECT l.id, l.name, l.description,
           COUNT(s.id) AS sales_count,
           COALESCE(SUM(CASE WHEN s.status = 'complete' THEN s.total ELSE 0 END), 0) AS total
    FROM pos_locations l
    LEFT JOIN pos_sales s ON s.location_id = l.id AND DATE(s.created_at) = :today
    WHERE l.is_active = 1
    GROUP BY l.id, l.name, l.description
    ORDER BY l.sort_order, l.name
");
$locationStmt->execute([':today' => $today]);
$locations = $locationStmt->fetchAll(PDO::FETCH_ASSOC);

$topProductsStmt = $pdo->prepare("
    SELECT i.product_name, i.category_name, SUM(i.qty) AS qty, COALESCE(SUM(i.line_total), 0) AS total
    FROM pos_sale_items i
    JOIN pos_sales s ON s.id = i.sale_id
    WHERE s.status = 'complete'
      AND DATE(s.created_at) BETWEEN :from_date AND :to_date
    GROUP BY i.product_name, i.category_name
    ORDER BY qty DESC, total DESC
    LIMIT 8
");
$topProductsStmt->execute([':from_date' => $weekStart, ':to_date' => $today]);
$topProducts = $topProductsStmt->fetchAll(PDO::FETCH_ASSOC);

$paymentLabel = static function (string $method): string {
    return match ($method) {
        'cash' => 'Cash',
        'card', 'card_stripe_app' => 'Card',
        default => ucfirst(str_replace('_', ' ', $method)),
    };
};

$statusBadge = static function (string $status): string {
    return match ($status) {
        'complete' => 'text-bg-success',
        'pending_card', 'pending_cash' => 'text-bg-warning',
        'cancelled_card', 'cancelled_cash' => 'text-bg-secondary',
        default => 'text-bg-light',
    };
};

require_once __DIR__ . '/header.php';
?>

<div class="pos-overview-page">
    <?php hub_render_metric_grid([
        ['label' => 'Today', 'value' => gbp((float) $todaySummary['total']), 'meta' => (int) $todaySummary['sales_count'] . ' completed sales', 'icon' => 'fa-sterling-sign', 'tone' => 'success'],
        ['label' => 'Last 7 days', 'value' => gbp((float) $weekSummary['total']), 'meta' => (int) $weekSummary['sales_count'] . ' completed sales', 'icon' => 'fa-calendar-week', 'tone' => 'primary'],
        ['label' => 'This month', 'value' => gbp((float) $monthSummary['total']), 'meta' => (int) $monthSummary['sales_count'] . ' completed sales', 'icon' => 'fa-chart-line', 'tone' => 'info'],
        ['label' => 'Pending', 'value' => $pendingCount, 'meta' => 'Card or cash sales awaiting completion', 'icon' => 'fa-clock', 'tone' => $pendingCount > 0 ? 'warning' : 'neutral'],
        ['label' => 'Products', 'value' => $productCount, 'meta' => 'Active products', 'icon' => 'fa-box-open', 'tone' => 'primary', 'href' => '/pos/products.php'],
        ['label' => 'Operators', 'value' => $operatorCount, 'meta' => $locationCount . ' active locations', 'icon' => 'fa-users-gear', 'tone' => 'neutral', 'href' => '/pos/operators.php'],
    ], 'POS summary'); ?>

    <section class="hub-section-commandbar" aria-labelledby="posActionsTitle">
        <div>
            <h2 id="posActionsTitle">POS actions</h2>
            <p>Open the till for live sales, manage products, or review day-level reports.</p>
        </div>
        <div class="hub-local-actions">
            <a class="btn btn-brand btn-sm" href="/pos/"><i class="fa-solid fa-cash-register" aria-hidden="true"></i>Open till</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/reports.php"><i class="fa-solid fa-table-list" aria-hidden="true"></i>Daily report</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/products.php"><i class="fa-solid fa-box-open" aria-hidden="true"></i>Products</a>
            <a class="btn btn-outline-secondary btn-sm" href="/pos/operators.php"><i class="fa-solid fa-users-gear" aria-hidden="true"></i>Operators</a>
        </div>
    </section>

    <div class="row g-3 mb-3">
        <div class="col-xl-8">
            <section class="card hub-panel h-100">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center gap-3">
                    <div>
                        <p class="page-kicker mb-1">Transactions</p>
                        <h2 class="h5 mb-0">Latest POS sales</h2>
                    </div>
                    <span class="badge text-bg-light">Latest <?= count($recentSales) ?></span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle hub-data-table hub-data-table--responsive mb-0">
                            <thead><tr><th>Time</th><th>Ref</th><th>Location</th><th>Operator</th><th>Member</th><th>Payment</th><th>Status</th><th class="text-end">Total</th></tr></thead>
                            <tbody>
                            <?php foreach ($recentSales as $sale): ?>
                                <tr>
                                    <td data-label="Time"><?= h(date('d/m/Y H:i', strtotime((string) $sale['created_at']))) ?></td>
                                    <td data-label="Ref" class="fw-semibold"><?= h((string) $sale['sale_ref']) ?></td>
                                    <td data-label="Location"><?= h((string) $sale['location_name']) ?></td>
                                    <td data-label="Operator"><?= h((string) ($sale['operator_name'] ?: $sale['hub_user_name'] ?: 'Hub user')) ?></td>
                                    <td data-label="Member"><?= h((string) ($sale['holder_name'] ?: '-')) ?></td>
                                    <td data-label="Payment"><?= h($paymentLabel((string) $sale['payment_method'])) ?></td>
                                    <td data-label="Status"><span class="badge hub-status <?= h($statusBadge((string) $sale['status'])) ?>"><?= h(ucfirst(str_replace('_', ' ', (string) $sale['status']))) ?></span></td>
                                    <td data-label="Total" class="text-end fw-bold"><?= gbp((float) $sale['total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$recentSales): ?><tr><td colspan="8" class="hub-empty-state">No POS transactions have been recorded yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-xl-4">
            <section class="card hub-panel h-100">
                <div class="card-body">
                    <p class="page-kicker mb-1">Locations</p>
                    <h2 class="h5 mb-3">Today by till</h2>
                    <div class="pos-overview-list">
                        <?php foreach ($locations as $location): ?>
                            <article class="pos-overview-list__item">
                                <span class="pos-overview-list__icon"><i class="fa-solid fa-cash-register" aria-hidden="true"></i></span>
                                <span>
                                    <strong><?= h((string) $location['name']) ?></strong>
                                    <small><?= (int) $location['sales_count'] ?> sales today</small>
                                </span>
                                <b><?= gbp((float) $location['total']) ?></b>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <section class="card hub-panel h-100">
                <div class="card-body">
                    <p class="page-kicker mb-1">Products</p>
                    <h2 class="h5 mb-3">Top sellers this week</h2>
                    <div class="pos-overview-list">
                        <?php foreach ($topProducts as $product): ?>
                            <article class="pos-overview-list__item">
                                <span class="pos-overview-list__icon"><i class="fa-solid fa-box" aria-hidden="true"></i></span>
                                <span>
                                    <strong><?= h((string) $product['product_name']) ?></strong>
                                    <small><?= h((string) $product['category_name']) ?> · <?= (int) $product['qty'] ?> sold</small>
                                </span>
                                <b><?= gbp((float) $product['total']) ?></b>
                            </article>
                        <?php endforeach; ?>
                        <?php if (!$topProducts): ?><div class="hub-empty-state">No product sales in the last 7 days.</div><?php endif; ?>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-lg-6">
            <section class="card hub-panel h-100">
                <div class="card-body">
                    <p class="page-kicker mb-1">Payments</p>
                    <h2 class="h5 mb-3">Payment mix today</h2>
                    <div class="pos-payment-split">
                        <article>
                            <span><i class="fa-solid fa-money-bill-wave" aria-hidden="true"></i>Cash</span>
                            <strong><?= (int) $todaySummary['cash_count'] ?></strong>
                        </article>
                        <article>
                            <span><i class="fa-solid fa-credit-card" aria-hidden="true"></i>Card</span>
                            <strong><?= (int) $todaySummary['card_count'] ?></strong>
                        </article>
                        <article>
                            <span><i class="fa-solid fa-tags" aria-hidden="true"></i>Discounts</span>
                            <strong><?= gbp((float) $todaySummary['discounts']) ?></strong>
                        </article>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
