<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Season tickets',
    'title' => 'Season Tickets',
    'subtitle' => 'Season-ticket packages attached to people, with shared order, payment, ticket and credential records.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/season_tickets.php';
require_once __DIR__ . '/lib/season_passes.php';
require_once __DIR__ . '/lib/audit.php';
hub_auth_require_permission('tickets.view');
ensureSeasonPassSchema($pdo);

$seasons = $pdo->query('SELECT id, name FROM seasons ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC);
$currentSeason = getCurrentSeason($pdo);
$seasonId = isset($_GET['season_id']) ? (int) $_GET['season_id'] : (int) ($currentSeason['id'] ?? 0);
$status = trim((string) ($_GET['status'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));
$ticketTypeId = max(0, (int) ($_GET['ticket_type_id'] ?? 0));
$paymentStatus = trim((string) ($_GET['payment_status'] ?? ''));
$paymentMethod = trim((string) ($_GET['payment_method'] ?? ''));
$ticketStatus = trim((string) ($_GET['ticket_status'] ?? ''));
$credentialActive = trim((string) ($_GET['credential_active'] ?? ''));
$dateFrom = trim((string) ($_GET['from'] ?? ''));
$dateTo = trim((string) ($_GET['to'] ?? ''));
$orderId = max(0, (int) ($_GET['id'] ?? ($_POST['order_id'] ?? 0)));
$paymentOptions = seasonTicketPaymentMethodOptions();
$ticketTypes = getSeasonTicketTypes($pdo, null, false);
$errors = [];

function season_ticket_order_badge(array $order): string
{
    $status = (string) ($order['status'] ?? '');
    $class = in_array($status, ['paid', 'complete'], true) ? 'text-bg-success' : ($status === 'cancelled' ? 'text-bg-secondary' : 'text-bg-warning');
    return '<span class="badge hub-status ' . $class . '">' . h($status) . '</span>';
}

function season_ticket_order_date(?string $value, string $format = 'd/m/Y'): string
{
    $timestamp = strtotime((string) $value);
    return $timestamp ? date($format, $timestamp) : 'Not set';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please reload and try again.';
    } elseif ($orderId <= 0) {
        $errors[] = 'Order not found.';
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'cancel_order') {
                cancelSeasonPassOrder($pdo, $orderId, (string) ($_POST['reason'] ?? ''), isset($currentUser['account_id']) ? (int) $currentUser['account_id'] : null);
                $cancelledOrder = getSeasonPassOrderBundle($pdo, $orderId);
                auditLog($pdo, 'season_ticket_order_cancelled', "Cancelled season ticket order #{$orderId} for '" . (string) ($cancelledOrder['holder_name'] ?? '') . "'");
                header('Location: /season_ticket_orders.php?id=' . $orderId . '&cancelled=1');
                exit;
            }
            if ($action === 'refund_order') {
                if (!hub_auth_has_permission('tickets.refund') && !hub_auth_has_permission('finance.refund')) {
                    throw new RuntimeException('You do not have permission to refund ticket orders.');
                }
                refundSeasonPassOrder($pdo, $orderId, (float) ($_POST['amount'] ?? 0), (string) ($_POST['reason'] ?? ''), isset($currentUser['account_id']) ? (int) $currentUser['account_id'] : null);
                $refundedOrder = getSeasonPassOrderBundle($pdo, $orderId);
                auditLog($pdo, 'season_ticket_order_refunded', "Refunded season ticket order #{$orderId} for '" . (string) ($refundedOrder['holder_name'] ?? '') . "'");
                header('Location: /season_ticket_orders.php?id=' . $orderId . '&refunded=1');
                exit;
            }
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

if ($orderId > 0) {
    $order = getSeasonPassOrderBundle($pdo, $orderId);
    if (!$order) {
        echo '<div class="alert alert-danger">Season ticket order not found.</div>';
        require_once __DIR__ . '/footer.php';
        exit;
    }
    $holderUrl = '/club_person.php?id=' . (int) $order['owner_person_id'];
    $verifyUrl = !empty($order['token']) ? '/verify_ticket.php?token=' . rawurlencode((string) $order['token']) : '';
    ?>

    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <a class="btn btn-outline-secondary" href="/season_ticket_orders.php?season_id=<?= (int) $seasonId ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Orders</a>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="<?= h($holderUrl) ?>"><i class="fa-solid fa-user" aria-hidden="true"></i> Person</a>
            <a class="btn btn-brand" href="/season_ticket_order.php?action=new"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add ticket</a>
        </div>
    </div>

    <?php if (isset($_GET['cancelled'])): ?><div class="alert alert-success">Season ticket cancelled and credential revoked.</div><?php endif; ?>
    <?php if (isset($_GET['refunded'])): ?><div class="alert alert-success">Refund recorded. Full refunds revoke the ticket credential.</div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-danger"><?= h(implode(' ', $errors)) ?></div><?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-8">
            <section class="card shadow-sm border-0 mb-3">
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start">
                        <div>
                            <div class="small text-uppercase text-muted fw-bold">Order #<?= (int) $order['id'] ?><?php if (!empty($order['legacy_season_ticket_order_id'])): ?> · legacy #<?= (int) $order['legacy_season_ticket_order_id'] ?><?php endif; ?></div>
                            <h2 class="h3 mb-1"><?= h((string) $order['type_name']) ?> Season Ticket</h2>
                            <div class="text-muted"><?= h((string) $order['season_name']) ?> · <?= h(season_ticket_order_date((string) $order['created_at'])) ?></div>
                        </div>
                        <div class="fs-5"><?= season_ticket_order_badge($order) ?></div>
                    </div>
                    <hr>
                    <div class="row g-3">
                        <div class="col-md-6"><div class="text-muted small">Person / ticket holder</div><div class="fw-semibold"><?= h((string) $order['holder_name']) ?></div><div><?= h((string) ($order['holder_email'] ?? '')) ?></div></div>
                        <div class="col-md-6"><div class="text-muted small">Payment</div><div class="fw-semibold"><?= gbp((float) $order['total_amount']) ?></div><div><?= h((string) $order['payment_status']) ?><?= !empty($order['paid_at']) ? ' - ' . h(season_ticket_order_date((string) $order['paid_at'])) : '' ?></div><div class="small text-muted"><?= h((string) ($paymentOptions[$order['payment_method']] ?? $order['payment_method'] ?? 'No method')) ?></div></div>
                        <div class="col-md-6"><div class="text-muted small">Ticket</div><div class="fw-semibold"><?= h((string) $order['pass_status']) ?></div><div class="small text-muted">Entitlement #<?= (int) $order['entitlement_id'] ?> · Ticket #<?= (int) $order['season_pass_id'] ?></div></div>
                        <div class="col-md-6"><div class="text-muted small">Credential</div><div class="fw-semibold font-monospace"><?= h((string) $order['manual_code']) ?></div><div class="small text-muted"><?= (int) $order['credential_is_active'] === 1 ? 'Active' : 'Inactive' ?></div><?php if ($verifyUrl !== ''): ?><a class="small" href="<?= h($verifyUrl) ?>" target="_blank" rel="noopener">Open verification</a><?php endif; ?></div>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-lg-4">
            <section class="card shadow-sm border-0 mb-3">
                <div class="card-body">
                    <h3 class="h5 mb-3">Order Summary</h3>
                    <div class="d-flex justify-content-between border-bottom py-2"><span>Ticket</span><strong><?= h((string) $order['type_name']) ?></strong></div>
                    <div class="d-flex justify-content-between border-bottom py-2"><span>Season</span><strong><?= h((string) $order['season_name']) ?></strong></div>
                    <div class="d-flex justify-content-between border-bottom py-2"><span>Order</span><strong><?= h((string) $order['status']) ?></strong></div>
                    <div class="d-flex justify-content-between border-bottom py-2"><span>Payment</span><strong><?= h((string) $order['payment_status']) ?></strong></div>
                    <div class="d-flex justify-content-between border-bottom py-2"><span>Ticket</span><strong><?= h((string) $order['pass_status']) ?></strong></div>
                    <div class="d-flex justify-content-between fw-bold pt-2"><span>Total</span><span><?= gbp((float) $order['total_amount']) ?></span></div>
                </div>
            </section>

            <section class="card shadow-sm border-0">
                <div class="card-body">
                    <h3 class="h5 mb-3">Actions</h3>
                    <?php if (!in_array((string) $order['status'], ['cancelled', 'refunded'], true)): ?>
                        <form method="post" class="mb-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <input type="hidden" name="action" value="cancel_order">
                            <label class="form-label fw-semibold" for="cancel_reason">Cancel reason</label>
                            <textarea class="form-control mb-2" id="cancel_reason" name="reason" rows="2" required></textarea>
                            <button class="btn btn-outline-danger w-100" type="submit">Cancel Ticket</button>
                        </form>
                        <?php if ((string) $order['payment_status'] === 'paid'): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                <input type="hidden" name="action" value="refund_order">
                                <label class="form-label fw-semibold" for="refund_amount">Refund amount</label>
                                <input class="form-control mb-2" id="refund_amount" name="amount" type="number" min="0" step="0.01" value="<?= h((string) $order['total_amount']) ?>" required>
                                <label class="form-label fw-semibold" for="refund_reason">Refund reason</label>
                                <textarea class="form-control mb-2" id="refund_reason" name="reason" rows="2"></textarea>
                                <button class="btn btn-outline-warning w-100" type="submit">Record Refund</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-secondary mb-0">This ticket is not active.</div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/footer.php';
    exit;
}

$filters = [];
if ($seasonId > 0) {
    $filters['season_id'] = $seasonId;
}
if ($status !== '') {
    $filters['status'] = $status;
}
if ($q !== '') {
    $filters['q'] = $q;
}
if ($ticketTypeId > 0) {
    $filters['ticket_type_id'] = $ticketTypeId;
}
if ($paymentStatus !== '') {
    $filters['payment_status'] = $paymentStatus;
}
if ($paymentMethod !== '') {
    $filters['payment_method'] = $paymentMethod;
}
if ($ticketStatus !== '') {
    $filters['ticket_status'] = $ticketStatus;
}
if ($credentialActive !== '') {
    $filters['credential_active'] = $credentialActive === '1' ? 1 : 0;
}
if ($dateFrom !== '') {
    $filters['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $filters['date_to'] = $dateTo;
}
$orders = getSeasonPassOrderList($pdo, $filters);

$totalPaid = 0.0;
$totalOutstanding = 0.0;
foreach ($orders as $order) {
    if ((string) $order['payment_status'] === 'paid') {
        $totalPaid += (float) $order['total_amount'];
    } else {
        $totalOutstanding += (float) $order['total_amount'];
    }
}
?>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Order saved.</div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?= h(implode(' ', $errors)) ?></div><?php endif; ?>

<div class="hub-section-commandbar">
  <div><h2>Season Tickets</h2><p><?= count($orders) ?> ticket order<?= count($orders) === 1 ? '' : 's' ?> · <?= gbp($totalPaid) ?> paid · <?= gbp($totalOutstanding) ?> outstanding</p></div>
  <div class="hub-local-actions">
    <a class="btn btn-outline-secondary btn-sm" href="club_people.php">People &amp; Users</a>
    <a class="btn btn-outline-secondary btn-sm" href="season_ticket_free_codes.php">Free links</a>
    <a class="btn btn-brand btn-sm" href="season_ticket_order.php?action=new"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add ticket</a>
  </div>
</div>

<form method="get" class="card shadow-sm border-0 mb-3">
  <div class="card-body">
    <div class="row g-2 align-items-end">
    <div class="col-md-4 col-lg-3">
        <label class="form-label small text-muted" for="stSearch">Search</label>
        <input class="form-control form-control-sm" id="stSearch" name="q" value="<?= h($q) ?>" placeholder="Name, email, code, order #">
    </div>
    <div class="col-md-4 col-lg-2">
        <label class="form-label small text-muted" for="stSeason">Season</label>
        <select class="form-select form-select-sm" id="stSeason" name="season_id" onchange="this.form.submit()">
            <option value="0">All seasons</option>
            <?php foreach ($seasons as $season): ?>
                <option value="<?= (int) $season['id'] ?>" <?= $seasonId === (int) $season['id'] ? 'selected' : '' ?>><?= h((string) $season['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4 col-lg-2">
        <label class="form-label small text-muted" for="stType">Ticket type</label>
        <select class="form-select form-select-sm" id="stType" name="ticket_type_id">
            <option value="0">All types</option>
            <?php foreach ($ticketTypes as $type): ?>
                <option value="<?= (int) $type['id'] ?>" <?= $ticketTypeId === (int) $type['id'] ? 'selected' : '' ?>><?= h((string) $type['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4 col-lg-2">
        <label class="form-label small text-muted" for="stStatus">Order status</label>
        <select class="form-select form-select-sm" id="stStatus" name="status" onchange="this.form.submit()">
            <option value="">Any status</option>
            <?php foreach (['draft', 'pending_payment', 'paid', 'cancelled', 'partially_refunded', 'refunded'] as $value): ?>
                <option value="<?= h($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= h($value) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4 col-lg-2">
        <label class="form-label small text-muted" for="stPaymentStatus">Payment status</label>
        <select class="form-select form-select-sm" id="stPaymentStatus" name="payment_status">
            <option value="">Any payment</option>
            <?php foreach (['pending', 'paid', 'failed', 'refunded', 'partially_refunded'] as $value): ?>
                <option value="<?= h($value) ?>" <?= $paymentStatus === $value ? 'selected' : '' ?>><?= h($value) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4 col-lg-2">
        <label class="form-label small text-muted" for="stPaymentMethod">Method</label>
        <select class="form-select form-select-sm" id="stPaymentMethod" name="payment_method">
            <option value="">Any method</option>
            <?php foreach ($paymentOptions as $value => $label): ?>
                <option value="<?= h((string) $value) ?>" <?= $paymentMethod === (string) $value ? 'selected' : '' ?>><?= h((string) $label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4 col-lg-2">
        <label class="form-label small text-muted" for="stTicketStatus">Ticket status</label>
        <select class="form-select form-select-sm" id="stTicketStatus" name="ticket_status">
            <option value="">Any ticket</option>
            <?php foreach (['pending', 'active', 'cancelled', 'refunded'] as $value): ?>
                <option value="<?= h($value) ?>" <?= $ticketStatus === $value ? 'selected' : '' ?>><?= h($value) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4 col-lg-2">
        <label class="form-label small text-muted" for="stCredential">Credential</label>
        <select class="form-select form-select-sm" id="stCredential" name="credential_active">
            <option value="">Any credential</option>
            <option value="1" <?= $credentialActive === '1' ? 'selected' : '' ?>>Active</option>
            <option value="0" <?= $credentialActive === '0' ? 'selected' : '' ?>>Inactive</option>
        </select>
    </div>
    <div class="col-md-4 col-lg-2">
        <label class="form-label small text-muted" for="stFrom">From</label>
        <input class="form-control form-control-sm" id="stFrom" type="date" name="from" value="<?= h($dateFrom) ?>">
    </div>
    <div class="col-md-4 col-lg-2">
        <label class="form-label small text-muted" for="stTo">To</label>
        <input class="form-control form-control-sm" id="stTo" type="date" name="to" value="<?= h($dateTo) ?>">
    </div>
    <div class="col-md-4 col-lg-3 d-flex gap-2">
        <button class="btn btn-brand btn-sm flex-fill" type="submit"><i class="fa-solid fa-filter me-1" aria-hidden="true"></i>Apply</button>
        <a class="btn btn-outline-secondary btn-sm flex-fill" href="season_ticket_orders.php">Reset</a>
    </div>
    </div>
  </div>
</form>

<div class="card hub-list-card hub-section hub-table-card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table align-middle hub-data-table hub-data-table--responsive">
        <thead><tr><th>Person</th><th>Type</th><th>Price</th><th>Payment</th><th>Ticket</th><th>Credential</th><th>Order date</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($orders as $order): ?>
            <tr>
              <td data-label="Person"><a href="club_person.php?id=<?= (int) $order['person_id'] ?>"><?= h((string) $order['holder_name']) ?></a></td>
              <td data-label="Type"><?= h((string) $order['type_name']) ?></td>
              <td data-label="Price"><?= gbp((float) $order['total_amount']) ?></td>
              <td data-label="Payment"><span class="badge <?= (string) $order['payment_status'] === 'paid' ? 'text-bg-success' : 'text-bg-warning' ?>"><?= h((string) $order['payment_status']) ?></span><div class="small text-muted"><?= h($paymentOptions[$order['payment_method']] ?? (string) $order['payment_method']) ?></div></td>
              <td data-label="Ticket"><span class="badge <?= (string) $order['entitlement_status'] === 'active' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= h((string) $order['entitlement_status']) ?></span></td>
              <td data-label="Credential"><span class="font-monospace"><?= h((string) $order['manual_code']) ?></span></td>
              <td data-label="Order date"><?= h(date('d/m/Y', strtotime((string) $order['created_at']))) ?></td>
              <td data-label="Actions" class="text-end"><a class="btn btn-sm btn-brand" href="season_ticket_orders.php?id=<?= (int) $order['id'] ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$orders): ?><tr><td colspan="8" class="text-center text-muted py-4">No orders found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
