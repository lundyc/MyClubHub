<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/pos.php';

pos_ensure_schema($pdo);
$actor = pos_require_actor($pdo);
if (!pos_actor_is_manager($pdo)) {
    http_response_code(403);
    exit('POS manager access required.');
}
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) ? (string) $_GET['date'] : date('Y-m-d');
$summary = pos_sales_summary($pdo, $date);
$stmt = $pdo->prepare('SELECT s.*, l.name AS location_name, o.name AS operator_name, u.display_name AS hub_user_name
    FROM pos_sales s
    JOIN pos_locations l ON l.id = s.location_id
    LEFT JOIN pos_operators o ON o.id = s.operator_id
    LEFT JOIN users u ON u.id = s.hub_user_id
    WHERE DATE(s.created_at) = :date
    ORDER BY s.created_at DESC, s.id DESC');
$stmt->execute([':date' => $date]);
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>POS Reports - Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" rel="stylesheet">
    <style>
        body { margin:0; font-family:Inter,system-ui,sans-serif; background:#f7efe4; color:#24151a; }
        .pos-header { background:linear-gradient(135deg,#4b0818,#8a1538); color:#fff; padding:1rem; }
        .pos-header a { color:#fff; text-decoration:none; font-weight:800; }
        .wrap { max-width:1180px; margin:0 auto; padding:1rem; }
        .cardx { background:#fff; border:1px solid rgba(75,8,24,.12); border-radius:1rem; box-shadow:0 14px 36px rgba(36,21,26,.08); }
        .metric { padding:1rem; }
        .metric span { color:#6f6470; font-weight:700; }
        .metric strong { display:block; color:#4b0818; font-size:1.6rem; }
    </style>
</head>
<body>
<header class="pos-header">
    <div class="wrap d-flex justify-content-between align-items-center flex-wrap gap-2 py-0">
        <div><h1 class="h3 fw-bold mb-0">POS Reports</h1><div class="small opacity-75"><?= h((string) $actor['name']) ?></div></div>
        <nav class="d-flex gap-3"><a href="/pos/">Till</a><?php if (pos_actor_is_manager($pdo)): ?><a href="/pos/products.php">Products</a><a href="/pos/operators.php">Operators</a><?php endif; ?><a href="/">Hub</a></nav>
    </div>
</header>
<main class="wrap">
    <form class="cardx p-3 mb-3 d-flex gap-2 align-items-end flex-wrap" method="get">
        <div><label class="form-label fw-bold" for="date">Report date</label><input class="form-control" id="date" name="date" type="date" value="<?= h($date) ?>"></div>
        <button class="btn btn-dark" type="submit">View</button>
    </form>

    <section class="row g-3 mb-3">
        <div class="col-md-3"><div class="cardx metric"><span>Sales</span><strong><?= (int) $summary['sales_count'] ?></strong></div></div>
        <div class="col-md-3"><div class="cardx metric"><span>Total</span><strong><?= gbp((float) $summary['total']) ?></strong></div></div>
        <div class="col-md-3"><div class="cardx metric"><span>Cash sales</span><strong><?= (int) $summary['cash_count'] ?></strong></div></div>
        <div class="col-md-3"><div class="cardx metric"><span>Card sales</span><strong><?= (int) $summary['card_count'] ?></strong></div></div>
    </section>

    <section class="cardx table-responsive">
        <table class="table table-striped align-middle mb-0">
            <thead><tr><th>Time</th><th>Ref</th><th>Location</th><th>Operator</th><th>Member</th><th>Payment</th><th class="text-end">Total</th></tr></thead>
            <tbody>
            <?php foreach ($sales as $sale): ?>
                <tr>
                    <td><?= h(date('H:i', strtotime((string) $sale['created_at']))) ?></td>
                    <td class="fw-bold"><?= h((string) $sale['sale_ref']) ?></td>
                    <td><?= h((string) $sale['location_name']) ?></td>
                    <td><?= h((string) ($sale['operator_name'] ?: $sale['hub_user_name'] ?: 'Hub user')) ?></td>
                    <td><?= h((string) ($sale['holder_name'] ?: '-')) ?></td>
                    <td><?= h(ucfirst((string) $sale['payment_method'])) ?></td>
                    <td class="text-end fw-bold"><?= gbp((float) $sale['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$sales): ?><tr><td colspan="7" class="text-center text-muted py-4">No POS sales for this date.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
