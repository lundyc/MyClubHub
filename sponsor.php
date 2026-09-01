<?php
// sponsor.php — Sponsor profile & editor
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/sync_social_directory.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';
require_once __DIR__ . '/lib/facebook_page_resolver.php';

ensureSponsorshipCatalogSchema($pdo);

$seasonId = getSelectedSeasonId($pdo);
$season = getSeasonById($pdo, $seasonId);

function ensureSponsorProfileColumns(PDO $pdo, ?string &$error = null): bool
{
  static $checked = false;
  static $result = true;
  static $message = null;

  if ($checked) {
    $error = $message;
    return $result;
  }

  $checked = true;

  try {
    $stmt = $pdo->query("SHOW COLUMNS FROM sponsors LIKE 'logo_path'");
    $exists = (bool)$stmt->fetch();
    if (!$exists) {
      $pdo->exec("ALTER TABLE sponsors ADD COLUMN logo_path VARCHAR(255) NULL AFTER name");
    }
    $mainSponsorStmt = $pdo->query("SHOW COLUMNS FROM sponsors LIKE 'is_main_sponsor'");
    if (!(bool)$mainSponsorStmt->fetch()) {
      $pdo->exec("ALTER TABLE sponsors ADD COLUMN is_main_sponsor TINYINT(1) NOT NULL DEFAULT 0 AFTER logo_path");
    }
    $whiteLogoStmt = $pdo->query("SHOW COLUMNS FROM sponsors LIKE 'white_logo_path'");
    if (!(bool)$whiteLogoStmt->fetch()) {
      $pdo->exec("ALTER TABLE sponsors ADD COLUMN white_logo_path VARCHAR(255) NULL AFTER logo_path");
    }
    $sortOrderStmt = $pdo->query("SHOW COLUMNS FROM sponsors LIKE 'sort_order'");
    if (!(bool)$sortOrderStmt->fetch()) {
      $pdo->exec("ALTER TABLE sponsors ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_main_sponsor");
    }
    $socialColumns = [
      'facebook_page_url' => "VARCHAR(500) NULL AFTER white_logo_path",
      'facebook_page_id' => "VARCHAR(100) NULL AFTER facebook_page_url",
      'facebook_page_name' => "VARCHAR(255) NULL AFTER facebook_page_id",
      'instagram_url' => "VARCHAR(500) NULL AFTER facebook_page_name",
      'twitter_url' => "VARCHAR(500) NULL AFTER instagram_url",
    ];
    foreach ($socialColumns as $column => $definition) {
      $columnStmt = $pdo->query("SHOW COLUMNS FROM sponsors LIKE " . $pdo->quote($column));
      if (!(bool)$columnStmt->fetch()) {
        $pdo->exec("ALTER TABLE sponsors ADD COLUMN {$column} {$definition}");
      }
    }
    $businessColumns = [
      'is_business' => "TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER twitter_url",
      'address' => "VARCHAR(255) NULL AFTER is_business",
      'website_url' => "VARCHAR(255) NULL AFTER address",
      'contact_phone' => "VARCHAR(50) NULL AFTER website_url",
      'contact_email' => "VARCHAR(190) NULL AFTER contact_phone",
    ];
    foreach ($businessColumns as $column => $definition) {
      $columnStmt = $pdo->query("SHOW COLUMNS FROM sponsors LIKE " . $pdo->quote($column));
      if (!(bool)$columnStmt->fetch()) {
        $pdo->exec("ALTER TABLE sponsors ADD COLUMN {$column} {$definition}");
      }
    }
    $result = true;
    $message = null;
  } catch (PDOException $e) {
    $result = false;
    $message = 'Unable to prepare sponsor profile fields: ' . $e->getMessage();
  }

  $error = $message;
  return $result;
}

$schemaError = null;
$schemaReady = ensureSponsorProfileColumns($pdo, $schemaError);

function sponsorProfileDate(?string $date, string $fallback): string
{
  $date = trim((string)$date);
  if ($date === '') {
    return $fallback;
  }
  $timestamp = strtotime($date);
  return $timestamp ? date('d/m/Y', $timestamp) : $date;
}

function sponsorProfileDateTime(?string $date, string $fallback = 'Not recorded'): string
{
  $date = trim((string)$date);
  if ($date === '') {
    return $fallback;
  }
  $timestamp = strtotime($date);
  return $timestamp ? date('d/m/Y H:i', $timestamp) : $date;
}

function sponsorProfileStatusBadge(string $status): string
{
  $classes = [
    'active' => 'text-bg-success',
    'scheduled' => 'text-bg-info',
    'expired' => 'text-bg-secondary',
    'cancelled' => 'text-bg-dark',
  ];
  $class = $classes[$status] ?? 'text-bg-light';
  return '<span class="badge hub-status ' . h($class) . '">' . h(ucfirst($status)) . '</span>';
}

function sponsorProfileSlotLabel(?string $slot): string
{
  $slot = strtolower(trim((string)$slot));
  return $slot !== '' ? ucfirst($slot) . ' kit' : 'Player sponsorship';
}

$action = $_GET['action'] ?? 'view';
if (!in_array($action, ['new', 'edit'], true)) {
  $action = 'view';
}

$id = (int)($_GET['id'] ?? 0);
$errors = [];
$formData = [
  'name' => '',
  'is_active' => 1,
  'is_main_sponsor' => 0,
  'sort_order' => 0,
  'facebook_page_url' => '',
  'facebook_page_id' => '',
  'facebook_page_name' => '',
  'instagram_url' => '',
  'twitter_url' => '',
];
$currentLogo = null;
$currentWhiteLogo = null;
$removeLogoChecked = false;
$removeWhiteLogoChecked = false;
$pendingUpload = null;
$pendingWhiteUpload = null;
$deleteAfterCommit = null;
$deleteWhiteAfterCommit = null;
$seasonAssignment = [
  'is_active' => 1,
  'joined_at' => null,
  'left_at' => null,
];

if ($action === 'view') {
  if ($id <= 0) {
    echo '<div><div class="alert alert-danger">Invalid sponsor ID.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
  }

  $stmt = $pdo->prepare("SELECT * FROM sponsors WHERE id = :id");
  $stmt->execute([':id' => $id]);
  $sponsor = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$sponsor) {
    echo '<div><div class="alert alert-danger">Sponsor not found.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
  }

  $clubAgreements = getSponsorshipAgreements($pdo, ['sponsor_id' => $id]);
  $hasMainSponsorAgreement = false;
  foreach ($clubAgreements as $clubAgreement) {
    if ((string)$clubAgreement['package_code'] === 'main_sponsor' && (string)$clubAgreement['effective_status'] === 'active') {
      $hasMainSponsorAgreement = true;
      break;
    }
  }
  $agreementTotalDue = 0.0;
  $agreementTotalPaid = 0.0;
  foreach ($clubAgreements as $clubAgreement) {
    if (!in_array((string)$clubAgreement['effective_status'], ['active', 'scheduled'], true)) continue;
    if (!empty($clubAgreement['season_id']) && (int)$clubAgreement['season_id'] !== $seasonId) continue;
    $agreementTotalDue += (float)$clubAgreement['agreed_amount'];
    $agreementTotalPaid += (float)$clubAgreement['total_paid'];
  }
  $agreementTotalOutstanding = max(0, $agreementTotalDue - $agreementTotalPaid);

  $totalsStmt = $pdo->prepare("
        SELECT SUM(sp.amount) AS total_due, COALESCE(SUM(pay.amount), 0) AS total_paid
        FROM sponsorships sp
        LEFT JOIN sponsorship_payments pay ON pay.sponsorship_id = sp.id
        WHERE sp.sponsor_id = :id
          AND sp.season_id = :season_id
          AND sp.ended_at IS NULL
    ");
  $totalsStmt->execute([':id' => $id, ':season_id' => $seasonId]);
  $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
  $total_due = (float)($totals['total_due'] ?? 0);
  $total_paid = (float)($totals['total_paid'] ?? 0);
  $total_outstanding = $total_due - $total_paid;

  $sponsorshipStmt = $pdo->prepare("
        SELECT sp.id,
               sp.player_id,
               sp.season_id,
               sp.slot,
               sp.amount,
               sp.started_at,
               sp.ended_at,
               sp.ended_reason,
               se.name AS season_name,
               p.name AS player_name,
               p.active AS player_active,
               a.id AS agreement_id,
               COALESCE(SUM(pay.amount), 0) AS paid_total
        FROM sponsorships sp
        JOIN players p ON sp.player_id = p.id
        LEFT JOIN seasons se ON se.id = sp.season_id
        LEFT JOIN sponsorship_payments pay ON pay.sponsorship_id = sp.id
        LEFT JOIN sponsorship_agreements a ON a.legacy_source = 'player' AND a.legacy_id = sp.id
        WHERE sp.sponsor_id = :id
        GROUP BY sp.id, sp.player_id, sp.season_id, sp.slot, sp.amount, sp.started_at, sp.ended_at, sp.ended_reason, se.name, p.name, p.active, a.id
        ORDER BY sp.ended_at IS NULL DESC, sp.season_id DESC, p.name ASC, FIELD(sp.slot, 'home', 'away', 'third')
    ");
  $sponsorshipStmt->execute([':id' => $id]);
  $sponsorships = $sponsorshipStmt->fetchAll(PDO::FETCH_ASSOC);
  $payableSponsorships = array_values(array_filter($sponsorships, static function (array $sp) use ($seasonId): bool {
    return (int)$sp['season_id'] === $seasonId && empty($sp['ended_at']);
  }));

  $paymentsStmt = $pdo->prepare("
        SELECT pay.*, p.name AS player_name, sp.slot, sp.season_id, sp.ended_at, se.name AS season_name
        FROM sponsorship_payments pay
        JOIN sponsorships sp ON pay.sponsorship_id = sp.id
        JOIN players p ON sp.player_id = p.id
        LEFT JOIN seasons se ON se.id = COALESCE(pay.season_id, sp.season_id)
        WHERE sp.sponsor_id = :id
        ORDER BY pay.paid_at DESC
    ");
  $paymentsStmt->execute([':id' => $id]);
  $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
  $activeSponsorshipCount = count(array_filter($sponsorships, static fn(array $sp): bool => empty($sp['ended_at'])));
  $historicSponsorshipCount = count(array_filter($sponsorships, static fn(array $sp): bool => !empty($sp['ended_at'])));
  $playerSponsorshipDue = array_sum(array_map(static fn(array $sp): float => empty($sp['ended_at']) ? (float)$sp['amount'] : 0.0, $sponsorships));
  $playerSponsorshipPaid = array_sum(array_map(static fn(array $sp): float => empty($sp['ended_at']) ? (float)$sp['paid_total'] : 0.0, $sponsorships));
  $playerSponsorshipOutstanding = max(0, $playerSponsorshipDue - $playerSponsorshipPaid);
  $allPlayerPaymentTotal = array_sum(array_map(static fn(array $payment): float => (float)$payment['amount'], $payments));

  $notesStmt = $pdo->prepare("
        SELECT n.*
        FROM sponsor_notes n
        WHERE n.sponsor_id = :id
        ORDER BY n.created_at DESC
    ");
  $notesStmt->execute([':id' => $id]);
  $notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);

  $slotAmounts = getSponsorshipSlotAmounts($pdo, $seasonId);
  $availableSlots = getAllowedSponsorshipSlots($pdo, $seasonId);
  $slotAmountMap = [];
  foreach ($availableSlots as $index => $slotName) {
    $slotAmountMap[$slotName] = (float)($slotAmounts[$index + 1] ?? 0);
  }
  $activePlayersStmt = $pdo->prepare("
        SELECT
          p.id,
          p.name,
          COALESCE(GROUP_CONCAT(DISTINCT LOWER(s.slot) ORDER BY FIELD(UPPER(s.slot), 'HOME', 'AWAY', 'THIRD') SEPARATOR ','), '') AS occupied_slots,
          COUNT(DISTINCT s.slot) AS occupied_count
        FROM players p
        LEFT JOIN sponsorships s
          ON s.player_id = p.id
         AND s.season_id = :season_id
         AND s.ended_at IS NULL
        WHERE p.active = 1
        GROUP BY p.id, p.name
        HAVING COUNT(DISTINCT s.slot) < :allowed_slot_count
        ORDER BY p.name ASC
    ");
  $activePlayersStmt->execute([
    ':season_id' => $seasonId,
    ':allowed_slot_count' => count($availableSlots),
  ]);
  $activePlayers = $activePlayersStmt->fetchAll(PDO::FETCH_ASSOC);

  $logoUrl = $sponsor['logo_path'] ?? null;
  $saved = isset($_GET['saved']);
  $assigned = isset($_GET['assigned']);
?>
  <div>
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="sponsors.php">Sponsors</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= h((string)$sponsor['name']) ?></span></nav>
    <?php if ($saved): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        Sponsor details saved successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>
    <?php if ($assigned): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        Sponsorship added successfully.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>
    <?php if (isset($_GET['payment_removed'])): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        Payment removed. The outstanding balance has been updated.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>
    <?php if ($schemaError): ?>
      <div class="alert alert-warning"><?= h($schemaError) ?></div>
    <?php endif; ?>

    <div class="page-hero mb-4">
      <div class="page-hero-body">
        <div class="d-flex flex-column flex-lg-row align-items-start gap-3">
          <div>
            <div class="page-hero-eyebrow">Sponsor profile</div>
            <h1 class="page-hero-title"><i class="fa-solid fa-user-tie me-2"></i><?= h($sponsor['name']) ?></h1>
            <p class="page-hero-subtitle">Review sponsorships, payment history, and notes in one place.</p>
          </div>
          <div class="ms-lg-auto d-flex flex-column align-items-start align-items-lg-end gap-3">
            <div class="d-flex flex-wrap gap-2">
              <span class="badge <?= $sponsor['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                <?= $sponsor['is_active'] ? 'Active' : 'Inactive' ?>
              </span>
              <?php if ($hasMainSponsorAgreement): ?>
                <span class="badge bg-warning text-dark">Main Sponsor</span>
              <?php endif; ?>
              <span class="badge bg-primary">Total: <?= gbp($agreementTotalDue) ?></span>
              <span class="badge bg-success">Paid: <?= gbp($agreementTotalPaid) ?></span>
              <span class="badge bg-warning text-dark">Outstanding: <?= gbp($agreementTotalOutstanding) ?></span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="hub-section-commandbar">
      <div><h2>Sponsor record</h2><p>Company identity, links and portfolio status.</p></div>
      <div class="hub-local-actions"><a href="sponsor.php?action=edit&amp;id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-pen me-1" aria-hidden="true"></i>Edit sponsor</a></div>
    </div>

    <?php if (
      trim((string)($sponsor['facebook_page_url'] ?? '')) !== ''
      || trim((string)($sponsor['instagram_url'] ?? '')) !== ''
      || trim((string)($sponsor['twitter_url'] ?? '')) !== ''
    ): ?>
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
          <div>
            <div class="fw-semibold">Social channels</div>
            <div class="small text-muted">
              <?php if (trim((string)($sponsor['facebook_page_id'] ?? '')) !== ''): ?>
                Facebook Page ID: <?= h((string)$sponsor['facebook_page_id']) ?>
              <?php else: ?>
                Saved profiles for this sponsor.
              <?php endif; ?>
            </div>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <?php if (trim((string)($sponsor['facebook_page_url'] ?? '')) !== ''): ?>
              <a class="btn btn-sm btn-outline-primary" href="<?= h((string)$sponsor['facebook_page_url']) ?>" target="_blank" rel="noopener">
                <i class="fa-brands fa-facebook me-1"></i>Facebook
              </a>
            <?php endif; ?>
            <?php if (trim((string)($sponsor['instagram_url'] ?? '')) !== ''): ?>
              <a class="btn btn-sm btn-outline-danger" href="<?= h((string)$sponsor['instagram_url']) ?>" target="_blank" rel="noopener">
                <i class="fa-brands fa-instagram me-1"></i>Instagram
              </a>
            <?php endif; ?>
            <?php if (trim((string)($sponsor['twitter_url'] ?? '')) !== ''): ?>
              <a class="btn btn-sm btn-outline-dark" href="<?= h((string)$sponsor['twitter_url']) ?>" target="_blank" rel="noopener">
                <i class="fa-brands fa-x-twitter me-1"></i>X / Twitter
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <style>
      .sponsor-tab-panel {
        background: #fff;
        border: 1px solid rgba(33, 37, 41, .08);
        border-radius: 8px;
        box-shadow: 0 8px 22px rgba(15, 23, 42, .04);
        margin-bottom: 1rem;
        padding: 1rem;
      }
      .sponsor-tab-head {
        align-items: flex-start;
        display: flex;
        gap: 1rem;
        justify-content: space-between;
        margin-bottom: .9rem;
      }
      .sponsor-tab-head h3,
      .sponsor-action-panel h4 {
        font-size: 1rem;
        line-height: 1.25;
        margin: 0;
      }
      .sponsor-tab-head p,
      .sponsor-action-panel p {
        color: #6c757d;
        font-size: .86rem;
        margin: .25rem 0 0;
      }
      .sponsor-tab-summary {
        display: grid;
        gap: .75rem;
        grid-template-columns: repeat(4, minmax(0, 1fr));
      }
      .sponsor-tab-summary > div {
        background: #f8f9fa;
        border: 1px solid rgba(33, 37, 41, .06);
        border-radius: 8px;
        min-width: 0;
        padding: .8rem;
      }
      .sponsor-tab-summary span {
        color: #6c757d;
        display: block;
        font-size: .74rem;
        font-weight: 700;
        text-transform: uppercase;
      }
      .sponsor-tab-summary strong {
        display: block;
        font-size: 1.05rem;
        margin-top: .2rem;
      }
      .sponsor-profile-table-wrap {
        background: #fff;
        border: 1px solid rgba(33, 37, 41, .08);
        border-radius: 8px;
      }
      .sponsor-profile-table-wrap .table > :not(caption) > * > * {
        padding: .85rem;
      }
      .sponsor-action-panel {
        background: #fff;
        border: 1px solid rgba(33, 37, 41, .08);
        border-radius: 8px;
        padding: 1rem;
      }
      .sponsor-notes-list .list-group-item {
        border-color: rgba(33, 37, 41, .08);
        padding: 1rem;
      }
      @media (max-width: 991.98px) {
        .sponsor-tab-head {
          flex-direction: column;
        }
        .sponsor-tab-summary {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }
      @media (max-width: 575.98px) {
        .sponsor-tab-summary {
          grid-template-columns: 1fr;
        }
      }
    </style>

    <ul class="nav nav-tabs reports-tabs flex-nowrap" role="tablist">
      <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#agreements" type="button"><i class="fa-solid fa-file-signature me-1" aria-hidden="true"></i>Agreements <span class="badge text-bg-light ms-1"><?= count($clubAgreements) ?></span></button>
      </li>
      <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#sponsorships" type="button"><i class="fa-solid fa-shirt me-1" aria-hidden="true"></i>Players <span class="badge text-bg-light ms-1"><?= count($sponsorships) ?></span></button>
      </li>
      <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#payments" type="button"><i class="fa-solid fa-wallet me-1" aria-hidden="true"></i>Payments <span class="badge text-bg-light ms-1"><?= count($payments) ?></span></button>
      </li>
      <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#notes" type="button"><i class="fa-regular fa-note-sticky me-1" aria-hidden="true"></i>Notes <span class="badge text-bg-light ms-1"><?= count($notes) ?></span></button>
      </li>
    </ul>

    <div class="tab-content mt-3">
      <div class="tab-pane fade show active" id="agreements">
        <div class="sponsor-tab-panel">
          <div class="sponsor-tab-head">
            <div>
              <h3>Agreement Portfolio</h3>
              <p>Club-wide, match, player, team and digital agreements linked to this sponsor.</p>
            </div>
            <a class="btn btn-brand btn-sm" href="/sponsorship_agreement.php?action=new&amp;sponsor_id=<?= $id ?>"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add agreement</a>
          </div>
          <div class="sponsor-tab-summary">
            <div><span>Total value</span><strong><?= gbp($agreementTotalDue) ?></strong></div>
            <div><span>Paid</span><strong><?= gbp($agreementTotalPaid) ?></strong></div>
            <div><span>Outstanding</span><strong><?= gbp($agreementTotalOutstanding) ?></strong></div>
            <div><span>Agreements</span><strong><?= count($clubAgreements) ?></strong></div>
          </div>
        </div>
        <div class="table-responsive sponsor-profile-table-wrap">
          <table class="table table-modern table-hover align-middle mb-0">
            <thead><tr><th>Package</th><th>Applies to</th><th>Dates</th><th class="text-end">Value</th><th>Status</th><th class="text-end">Action</th></tr></thead>
            <tbody>
              <?php foreach ($clubAgreements as $agreement): ?>
                <?php $target = (string)($agreement['season_name'] ?: 'Club-wide'); if (!empty($agreement['fixture_id'])) $target = 'Fixture #' . (int)$agreement['fixture_id'] . ' · ' . (string)$agreement['fixture_opponent']; elseif (!empty($agreement['player_id'])) $target = (string)$agreement['player_name']; ?>
                <tr>
                  <td><div class="fw-semibold"><?= h((string)$agreement['package_name']) ?></div><span class="badge text-bg-light"><?= h((string)$agreement['package_category']) ?></span></td>
                  <td><div><?= h($target) ?></div><div class="small text-muted"><?= h((string)($agreement['season_name'] ?: 'No season')) ?></div></td>
                  <td class="text-nowrap"><?= h(sponsorProfileDate($agreement['start_date'] ?? null, 'Open')) ?> &rarr; <?= h(sponsorProfileDate($agreement['end_date'] ?? null, 'Ongoing')) ?></td>
                  <td class="text-end fw-semibold"><?= gbp((float)$agreement['agreed_amount']) ?></td>
                  <td><?= sponsorProfileStatusBadge((string)$agreement['effective_status']) ?></td>
                  <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/sponsorship_agreement.php?id=<?= (int)$agreement['id'] ?>">Manage</a></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$clubAgreements): ?><tr><td colspan="6" class="text-center text-muted py-4">No agreements recorded.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="tab-pane fade" id="sponsorships">
        <div class="sponsor-tab-panel">
          <div class="sponsor-tab-head">
            <div>
              <h3>Player Sponsorships</h3>
              <p>Current and historic player kit sponsorships held by this sponsor.</p>
            </div>
            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addSponsorshipModal">
              <i class="fa-solid fa-user-plus me-1"></i>Add sponsorship
            </button>
          </div>
          <div class="sponsor-tab-summary">
            <div><span>Active</span><strong><?= (int)$activeSponsorshipCount ?></strong></div>
            <div><span>Historic</span><strong><?= (int)$historicSponsorshipCount ?></strong></div>
            <div><span>Active value</span><strong><?= gbp($playerSponsorshipDue) ?></strong></div>
            <div><span>Outstanding</span><strong><?= gbp($playerSponsorshipOutstanding) ?></strong></div>
          </div>
        </div>
        <div class="table-responsive sponsor-profile-table-wrap">
        <table class="table table-modern table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Player</th>
              <th>Package</th>
              <th>Season</th>
              <th>Status</th>
              <th class="text-end">Value</th>
              <th class="text-end">Paid</th>
              <th class="text-end">Outstanding</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sponsorships as $sp): ?>
              <?php
                $isEnded = !empty($sp['ended_at']);
                $outstanding = max(0, (float)$sp['amount'] - (float)$sp['paid_total']);
              ?>
              <tr>
                <td>
                  <a class="fw-semibold" href="player_view.php?id=<?= (int)$sp['player_id'] ?>"><?= h($sp['player_name']) ?></a>
                  <?php if ((int)($sp['player_active'] ?? 1) !== 1): ?><div class="small text-muted">Former player</div><?php endif; ?>
                </td>
                <td><?= h(sponsorProfileSlotLabel($sp['slot'] ?? '')) ?></td>
                <td><?= h((string)($sp['season_name'] ?: ('Season #' . (int)$sp['season_id']))) ?></td>
                <td>
                  <?php if ($isEnded): ?>
                    <span class="badge hub-status text-bg-secondary">Ended</span>
                    <div class="small text-muted"><?= h(sponsorProfileDateTime($sp['ended_at'] ?? null)) ?></div>
                  <?php else: ?>
                    <span class="badge hub-status text-bg-success">Active</span>
                  <?php endif; ?>
                </td>
                <td class="text-end fw-semibold"><?= gbp($sp['amount']) ?></td>
                <td class="text-end"><?= gbp($sp['paid_total']) ?></td>
                <td class="text-end"><?= gbp($outstanding) ?></td>
                <td class="text-end">
                  <?php if (!$isEnded && (int)$sp['season_id'] === $seasonId && $outstanding > 0): ?>
                    <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-success"
                        data-bs-toggle="modal"
                        data-bs-target="#markPaidModal"
                        data-sponsorship-id="<?= (int)$sp['id'] ?>"
                        data-player-name="<?= h($sp['player_name']) ?>"
                        data-slot="<?= h(ucfirst((string)$sp['slot'])) ?>"
                        data-outstanding="<?= h(number_format($outstanding, 2, '.', '')) ?>"
                      >
                        Mark Paid
                      </button>
                      <?php if (!empty($sp['agreement_id'])): ?>
                        <a class="btn btn-sm btn-outline-primary" href="/sponsorship_agreement.php?id=<?= (int)$sp['agreement_id'] ?>#stripePaymentCard" title="Generate / send a Stripe payment link for this slot">
                          <i class="fa-brands fa-stripe-s me-1" aria-hidden="true"></i>Stripe link
                        </a>
                      <?php endif; ?>
                    </div>
                  <?php elseif (!$isEnded && $outstanding <= 0): ?>
                    <span class="badge text-bg-success">Paid</span>
                  <?php elseif (!$isEnded): ?>
                    <span class="text-muted small">Different season selected</span>
                  <?php else: ?>
                    <span class="text-muted small">No action</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$sponsorships): ?>
              <tr data-placeholder="sponsorships-empty">
                <td colspan="8" class="text-muted text-center py-4">
                  No sponsorships found for this sponsor.
                  <button type="button" class="btn btn-link btn-sm align-baseline p-0 ms-1" data-bs-toggle="modal" data-bs-target="#addSponsorshipModal">
                    Add one now
                  </button>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>

      <div class="tab-pane fade" id="payments">
        <div class="sponsor-tab-panel">
          <div class="sponsor-tab-head">
            <div>
              <h3>Payments</h3>
              <p>Recorded payments across every player sponsorship for this sponsor.</p>
            </div>
          </div>
          <div class="sponsor-tab-summary">
            <div><span>Current due</span><strong><?= gbp($total_due) ?></strong></div>
            <div><span>Current paid</span><strong><?= gbp($total_paid) ?></strong></div>
            <div><span>Current outstanding</span><strong><?= gbp($total_outstanding) ?></strong></div>
            <div><span>All payments</span><strong><?= gbp($allPlayerPaymentTotal) ?></strong></div>
          </div>
        </div>
        <div class="table-responsive sponsor-profile-table-wrap mb-3">
        <table class="table table-modern table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Date</th>
              <th>Player</th>
              <th>Slot</th>
              <th>Season</th>
              <th>Amount</th>
              <th>Method</th>
              <th>Note</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($payments as $pay): ?>
              <tr>
                <td class="text-nowrap"><?= h(sponsorProfileDateTime($pay['paid_at'] ?? null)) ?></td>
                <td><?= h($pay['player_name']) ?><?php if (!empty($pay['ended_at'])): ?><div class="small text-muted">Ended sponsorship</div><?php endif; ?></td>
                <td><?= h(sponsorProfileSlotLabel($pay['slot'] ?? '')) ?></td>
                <td><?= h((string)($pay['season_name'] ?: ('Season #' . (int)$pay['season_id']))) ?></td>
                <td class="fw-semibold"><?= gbp($pay['amount']) ?></td>
                <td><?= h((string)($pay['method'] ?: 'Not recorded')) ?></td>
                <td><?= h((string)($pay['note'] ?: '')) ?></td>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-outline-danger sponsor-payment-delete"
                    data-payment-id="<?= (int)$pay['id'] ?>"
                    data-amount="<?= h(number_format((float)$pay['amount'], 2)) ?>">Remove</button>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$payments): ?>
              <tr data-placeholder="payments-empty">
                <td colspan="8" class="text-muted text-center py-4">No payments recorded yet.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
        </div>

        <div class="sponsor-action-panel">
          <div>
            <h4>Add payment</h4>
            <p>Payments can be recorded against active player sponsorships in the selected season.</p>
          </div>
          <form method="post" action="sponsor_save.php" class="row g-2 align-items-end" id="paymentForm">
            <input type="hidden" name="action" value="add_payment">
            <input type="hidden" name="sponsor_id" value="<?= $id ?>">
            <input type="hidden" name="season_id" value="<?= $seasonId ?>">

            <div class="col-12 col-lg-4">
              <label class="form-label small text-muted mb-1" for="sponsorshipSelect">Player / slot</label>
              <select name="sponsorship_id" id="sponsorshipSelect" class="form-select form-select-sm" required>
                <option value="">Select Player / Slot</option>
                <?php foreach ($payableSponsorships as $sp): ?>
                  <?php
                  $outstanding = max(0, (float)$sp['amount'] - (float)$sp['paid_total']);
                  $label = $sp['player_name'] . ' - ' . sponsorProfileSlotLabel($sp['slot']) . ' - ' . gbp($sp['amount']);
                  $label .= $outstanding > 0 ? ' - Outstanding: ' . gbp($outstanding) : ' - Fully Paid';
                  ?>
                  <option value="<?= (int)$sp['id'] ?>" data-outstanding="<?= h(number_format($outstanding, 2, '.', '')) ?>">
                    <?= h($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-lg-3">
              <label class="form-label small text-muted mb-1" for="amountInput">Amount</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">&pound;</span>
                <input type="number" step="0.01" min="0" name="amount" id="amountInput" class="form-control" placeholder="Amount" required>
                <button type="button" id="fillOutstanding" class="btn btn-outline-secondary" disabled>Due</button>
              </div>
            </div>

            <div class="col-6 col-lg-2">
              <label class="form-label small text-muted mb-1" for="paymentMethod">Method</label>
              <select name="method" id="paymentMethod" class="form-select form-select-sm">
                <option value="">Not recorded</option>
                <option value="Cash">Cash</option>
                <option value="Bank Transfer">Bank Transfer</option>
                <option value="Cheque">Cheque</option>
                <option value="Card">Card</option>
                <option value="Stripe">Stripe</option>
                <option value="Other">Other</option>
              </select>
            </div>

            <div class="col-6 col-lg-2">
              <label class="form-label small text-muted mb-1">Note</label>
              <input type="text" name="note" class="form-control form-control-sm" placeholder="Optional">
            </div>

            <div class="col-12 col-lg-1 d-grid">
              <button class="btn btn-sm btn-primary" type="submit" <?= !$payableSponsorships ? 'disabled' : '' ?>>Add</button>
            </div>
            <?php if (!$payableSponsorships): ?><div class="col-12"><div class="small text-muted">No active player sponsorships are available for payment in the selected season.</div></div><?php endif; ?>
          </form>
        </div>

        <?php
        $stripeLinkRows = array_values(array_filter($sponsorships, static function (array $sp) use ($seasonId): bool {
          return empty($sp['ended_at'])
            && !empty($sp['agreement_id'])
            && (int)$sp['season_id'] === $seasonId
            && ((float)$sp['amount'] - (float)$sp['paid_total']) > 0.0001;
        }));
        ?>
        <?php if ($stripeLinkRows): ?>
          <div class="sponsor-action-panel">
            <div>
              <h4>Send a Stripe payment link</h4>
              <p>Generate a short pay-by-card link for an outstanding player sponsorship, then copy it or email it to the sponsor.</p>
            </div>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Player / slot</th><th class="text-end">Outstanding</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                  <?php foreach ($stripeLinkRows as $sp): ?>
                    <tr>
                      <td><?= h($sp['player_name']) ?> &middot; <?= h(sponsorProfileSlotLabel($sp['slot'] ?? '')) ?></td>
                      <td class="text-end fw-semibold"><?= gbp(max(0, (float)$sp['amount'] - (float)$sp['paid_total'])) ?></td>
                      <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="/sponsorship_agreement.php?id=<?= (int)$sp['agreement_id'] ?>#stripePaymentCard">
                          <i class="fa-brands fa-stripe-s me-1" aria-hidden="true"></i>Stripe link
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <script>
          (function() {
            const form = document.getElementById('paymentForm');
            if (!form) return;

            const select = document.getElementById('sponsorshipSelect');
            const amountInput = document.getElementById('amountInput');
            const outstandingBtn = document.getElementById('fillOutstanding');
            const totals = document.getElementById('paymentTotals');
            const currentSeasonName = <?= json_encode((string)($season['name'] ?? ('Season #' . $seasonId)), JSON_UNESCAPED_SLASHES) ?>;

            if (select && outstandingBtn) {
              select.addEventListener('change', function() {
                const selected = select.selectedOptions[0];
                if (!selected) {
                  outstandingBtn.disabled = true;
                  outstandingBtn.dataset.value = '';
                  return;
                }
                const outstanding = parseFloat(selected.dataset.outstanding || '0');
                outstandingBtn.disabled = outstanding <= 0;
                outstandingBtn.dataset.value = outstanding.toFixed(2);
              });
            }

            if (outstandingBtn && amountInput) {
              outstandingBtn.addEventListener('click', function() {
                if (!outstandingBtn.dataset.value) return;
                amountInput.value = outstandingBtn.dataset.value;
                amountInput.focus();
              });
            }

            form.addEventListener('submit', function(e) {
              e.preventDefault();
              window.hubClearFormError(form);
              const submitButton = e.submitter || form.querySelector('button[type="submit"]');
              window.hubSetBusy(submitButton, true);

              const formData = new FormData(form);

              fetch('sponsor_save.php', {
                  method: 'POST',
                  body: formData
                })
                .then(res => res.json())
                .then(data => {
                  if (!data.success) {
                    window.hubShowFieldError(form, data.field, data.error);
                    window.hubSetBusy(submitButton, false);
                    return;
                  }

                  const tableBody = form.closest('.tab-pane').querySelector('table tbody');
                  if (tableBody) {
                    const row = document.createElement('tr');
                    row.innerHTML = `
      <td>${data.payment.paid_at}</td>
      <td>${data.payment.player}</td>
      <td>${data.payment.slot}</td>
      <td>${currentSeasonName}</td>
      <td>&pound;${data.payment.amount}</td>
      <td>${data.payment.method}</td>
      <td>${data.payment.note}</td>
      <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger sponsor-payment-delete" data-payment-id="${data.payment.id}" data-amount="${data.payment.amount}">Remove</button></td>
    `;
                    tableBody.prepend(row);
                    const placeholder = tableBody.querySelector('[data-placeholder="payments-empty"]');
                    if (placeholder) {
                      placeholder.remove();
                    }
                  }

                  if (totals) {
                    totals.innerHTML = `
      <div><strong>Total Due:</strong> &pound;${data.totals.due}</div>
      <div><strong>Total Paid:</strong> &pound;${data.totals.paid}</div>
      <div><strong>Outstanding:</strong> &pound;${data.totals.outstanding}</div>
    `;
                  }

                  form.reset();
                  if (outstandingBtn) {
                    outstandingBtn.disabled = true;
                    outstandingBtn.dataset.value = '';
                  }
                  window.hubSetBusy(submitButton, false);
                })
                .catch(() => {
                  window.hubShowFormError(form, 'Request failed. Please try again.');
                  window.hubSetBusy(submitButton, false);
                });
            });
          })();
        </script>

      </div>

      <div class="tab-pane fade" id="notes">
        <div class="sponsor-tab-panel">
          <div class="sponsor-tab-head">
            <div>
              <h3>Notes</h3>
              <p>Internal context, follow-up reminders and sponsor relationship notes.</p>
            </div>
          </div>
        </div>
        <div class="row g-3">
          <div class="col-12 col-lg-7">
            <ul class="list-group sponsor-notes-list" id="notesList">
              <?php foreach ($notes as $note): ?>
                <li class="list-group-item">
                  <div><?= nl2br(h($note['note'])) ?></div>
                  <div class="text-muted small mt-2"><?= h(sponsorProfileDateTime($note['created_at'] ?? null)) ?></div>
                </li>
              <?php endforeach; ?>
              <?php if (!$notes): ?>
                <li class="list-group-item text-muted" data-placeholder="notes-empty">No notes yet.</li>
              <?php endif; ?>
            </ul>
          </div>
          <div class="col-12 col-lg-5">
            <div class="sponsor-action-panel h-100">
              <div>
                <h4>Add note</h4>
                <p>Keep this factual and useful for the next person reviewing the sponsor.</p>
              </div>
              <form method="post" action="sponsor_save.php" class="d-grid gap-2" id="noteForm">
                <input type="hidden" name="action" value="add_note">
                <input type="hidden" name="sponsor_id" value="<?= $id ?>">
                <textarea name="note" class="form-control form-control-sm" rows="6" placeholder="Write a note..." required></textarea>
                <button class="btn btn-sm btn-primary justify-self-start" type="submit">Add note</button>
              </form>
            </div>
          </div>
        </div>
      </div>

      <script>
        (function() {
          const form = document.getElementById('noteForm');
          if (!form) return;

          form.addEventListener('submit', function(e) {
            e.preventDefault();
            window.hubClearFormError(form);
            const submitButton = e.submitter || form.querySelector('button[type="submit"]');
            window.hubSetBusy(submitButton, true);
            const formData = new FormData(form);

            fetch('sponsor_save.php', {
                method: 'POST',
                body: formData
              })
              .then(res => res.json())
              .then(data => {
                if (!data.success) {
                  window.hubShowFieldError(form, data.field, data.error);
                  window.hubSetBusy(submitButton, false);
                  return;
                }

                const notesList = document.getElementById('notesList');
                if (!notesList) return;

                const li = document.createElement('li');
                li.className = 'list-group-item';
                li.innerHTML = `
      <div>${data.note.note}</div>
      <div class="text-muted small mt-2">${data.note.created_at}</div>
    `;
                notesList.prepend(li);
                const placeholder = notesList.querySelector('[data-placeholder="notes-empty"]');
                if (placeholder) {
                  placeholder.remove();
                }

                form.reset();
                window.hubSetBusy(submitButton, false);
              })
              .catch(() => {
                window.hubShowFormError(form, 'Request failed. Please try again.');
                window.hubSetBusy(submitButton, false);
            });
          });
        })();
      </script>

    </div>
  </div>

  <div class="modal fade" id="addSponsorshipModal" tabindex="-1" aria-labelledby="addSponsorshipModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form id="addSponsorshipForm">
          <div class="modal-header">
            <h5 class="modal-title" id="addSponsorshipModalLabel">Add Sponsorship</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_sponsorship">
            <input type="hidden" name="sponsor_id" value="<?= $id ?>">
            <input type="hidden" name="season_id" value="<?= $seasonId ?>">

            <div class="mb-3">
              <label for="sponsorshipPlayer" class="form-label">Player</label>
              <select name="player_id" id="sponsorshipPlayer" class="form-select" required>
                <option value="">Select a player</option>
                <?php foreach ($activePlayers as $player): ?>
                  <option value="<?= (int)$player['id'] ?>" data-occupied-slots="<?= h((string)($player['occupied_slots'] ?? '')) ?>">
                    <?= h($player['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-3">
              <label for="sponsorshipSlot" class="form-label">Slot</label>
              <select name="slot" id="sponsorshipSlot" class="form-select" required>
                <?php foreach ($availableSlots as $slot): ?>
                  <option value="<?= h($slot) ?>" data-amount="<?= h(number_format($slotAmountMap[$slot] ?? 0, 2, '.', '')) ?>">
                    <?= strtoupper(h($slot)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text" id="sponsorshipSlotHelp">Choose an available slot for the selected player.</div>
            </div>

            <div class="mb-3">
              <label for="sponsorshipAmount" class="form-label">Amount</label>
              <input type="number" step="0.01" min="0" name="amount" id="sponsorshipAmount" class="form-control" required>
              <div class="form-text">Defaults to the selected slot pricing for this season.</div>
            </div>

            <div class="mb-0">
              <label for="sponsorshipNotes" class="form-label">Notes</label>
              <textarea name="notes" id="sponsorshipNotes" class="form-control" rows="3" placeholder="Optional notes"></textarea>
            </div>

            <div class="form-check mt-3">
              <input class="form-check-input" type="checkbox" id="sponsorshipMarkPaid" name="mark_paid" value="1">
              <label class="form-check-label" for="sponsorshipMarkPaid">
                Mark as paid now
              </label>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-success">Save Sponsorship</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="markPaidModal" tabindex="-1" aria-labelledby="markPaidModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form id="markPaidForm">
          <div class="modal-header">
            <h5 class="modal-title" id="markPaidModalLabel">Mark Sponsorship Paid</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_payment">
            <input type="hidden" name="sponsor_id" value="<?= $id ?>">
            <input type="hidden" name="season_id" value="<?= $seasonId ?>">
            <input type="hidden" name="sponsorship_id" id="markPaidSponsorshipId" value="">

            <div class="mb-3">
              <div class="small text-muted">Player</div>
              <div class="fw-semibold" id="markPaidPlayerName">-</div>
            </div>

            <div class="mb-3">
              <div class="small text-muted">Slot</div>
              <div class="fw-semibold" id="markPaidSlotName">-</div>
            </div>

            <div class="mb-3">
              <label for="markPaidAmount" class="form-label">Payment Amount</label>
              <input type="number" step="0.01" min="0" name="amount" id="markPaidAmount" class="form-control" required>
              <div class="form-text">Defaults to the remaining outstanding amount.</div>
            </div>

            <div class="mb-3">
              <label for="markPaidMethod" class="form-label">Method</label>
              <input type="text" name="method" id="markPaidMethod" class="form-control" placeholder="Optional">
            </div>

            <div class="mb-0">
              <label for="markPaidNote" class="form-label">Note</label>
              <input type="text" name="note" id="markPaidNote" class="form-control" placeholder="Optional">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-success">Mark Paid</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    (function() {
      const modalEl = document.getElementById('addSponsorshipModal');
      const form = document.getElementById('addSponsorshipForm');
      const playerSelect = document.getElementById('sponsorshipPlayer');
      const slotSelect = document.getElementById('sponsorshipSlot');
      const amountInput = document.getElementById('sponsorshipAmount');
      const slotHelp = document.getElementById('sponsorshipSlotHelp');
      const markPaidCheckbox = document.getElementById('sponsorshipMarkPaid');
      const markPaidModalEl = document.getElementById('markPaidModal');
      const markPaidForm = document.getElementById('markPaidForm');
      const markPaidSponsorshipId = document.getElementById('markPaidSponsorshipId');
      const markPaidPlayerName = document.getElementById('markPaidPlayerName');
      const markPaidSlotName = document.getElementById('markPaidSlotName');
      const markPaidAmount = document.getElementById('markPaidAmount');

      if (!modalEl || !form || !playerSelect || !slotSelect || !amountInput) {
        return;
      }

      const syncAmount = () => {
        const selected = slotSelect.selectedOptions[0];
        const amount = selected ? selected.getAttribute('data-amount') : '';
        amountInput.value = amount || '0.00';
        if (markPaidCheckbox?.checked) {
          amountInput.value = amount || '0.00';
        }
      };

      const syncSlotsForPlayer = () => {
        const selectedPlayer = playerSelect.selectedOptions[0];
        const occupied = new Set(
          (selectedPlayer?.getAttribute('data-occupied-slots') || '')
            .split(',')
            .map((slot) => slot.trim().toLowerCase())
            .filter(Boolean)
        );

        let firstEnabledOption = null;
        Array.from(slotSelect.options).forEach((option, index) => {
          if (index === 0) {
            option.disabled = false;
            return;
          }

          const slot = option.value.toLowerCase();
          const isTaken = occupied.has(slot);
          option.disabled = isTaken;
          if (!isTaken && !firstEnabledOption) {
            firstEnabledOption = option;
          }
        });

        if (!selectedPlayer || !selectedPlayer.value) {
          slotSelect.disabled = true;
          amountInput.value = '0.00';
          if (slotHelp) {
            slotHelp.textContent = 'Select a player to see available slots.';
          }
          return;
        }

        slotSelect.disabled = false;
        if (slotHelp) {
          slotHelp.textContent = occupied.size
            ? 'Taken slots are disabled for the selected player.'
            : 'Choose an available slot for the selected player.';
        }

        if (slotSelect.selectedOptions[0] && slotSelect.selectedOptions[0].disabled) {
          if (firstEnabledOption) {
            firstEnabledOption.selected = true;
          }
        }

        if (!slotSelect.selectedOptions[0] || slotSelect.selectedOptions[0].disabled) {
          if (firstEnabledOption) {
            firstEnabledOption.selected = true;
          }
        }

        syncAmount();
      };

      modalEl.addEventListener('show.bs.modal', () => {
        form.reset();
        window.hubClearFormError(form);
        syncSlotsForPlayer();
        if (markPaidCheckbox) {
          markPaidCheckbox.checked = false;
        }
      });

      playerSelect.addEventListener('change', syncSlotsForPlayer);
      slotSelect.addEventListener('change', syncAmount);
      markPaidCheckbox?.addEventListener('change', syncAmount);

      form.addEventListener('submit', function(e) {
        e.preventDefault();
        window.hubClearFormError(form);

        if (slotSelect.disabled) {
          window.hubShowFieldError(form, 'slot', 'Select a player with an available sponsorship slot.');
          return;
        }

        const submitButton = e.submitter || form.querySelector('button[type="submit"]');
        window.hubSetBusy(submitButton, true);

        const formData = new FormData(form);
        fetch('sponsor_save.php', {
          method: 'POST',
          body: formData
        })
          .then(response => response.json())
          .then(data => {
            if (!data.success) {
              window.hubShowFieldError(form, data.field, data.error || 'Unable to add sponsorship.');
              window.hubSetBusy(submitButton, false);
              return;
            }

            window.location = 'sponsor.php?id=<?= $id ?>&assigned=1';
          })
          .catch(() => {
            window.hubShowFormError(form, 'Request failed. Please try again.');
            window.hubSetBusy(submitButton, false);
        });
      });

      markPaidModalEl?.addEventListener('show.bs.modal', (event) => {
        const button = event.relatedTarget;
        const sponsorshipId = button?.getAttribute('data-sponsorship-id') || '';
        const playerName = button?.getAttribute('data-player-name') || '';
        const slotName = button?.getAttribute('data-slot') || '';
        const outstanding = button?.getAttribute('data-outstanding') || '0.00';

        if (markPaidSponsorshipId) markPaidSponsorshipId.value = sponsorshipId;
        if (markPaidPlayerName) markPaidPlayerName.textContent = playerName;
        if (markPaidSlotName) markPaidSlotName.textContent = slotName;
        if (markPaidAmount) markPaidAmount.value = outstanding;
        if (markPaidForm) {
          markPaidForm.reset();
          window.hubClearFormError(markPaidForm);
          if (markPaidSponsorshipId) markPaidSponsorshipId.value = sponsorshipId;
          if (markPaidPlayerName) markPaidPlayerName.textContent = playerName;
          if (markPaidSlotName) markPaidSlotName.textContent = slotName;
          if (markPaidAmount) markPaidAmount.value = outstanding;
        }
      });

      markPaidForm?.addEventListener('submit', function(e) {
        e.preventDefault();
        window.hubClearFormError(markPaidForm);
        const submitButton = e.submitter || markPaidForm.querySelector('button[type="submit"]');
        window.hubSetBusy(submitButton, true);
        const formData = new FormData(markPaidForm);

        fetch('sponsor_save.php', {
          method: 'POST',
          body: formData
        })
          .then(response => response.json())
          .then(data => {
            if (!data.success) {
              window.hubShowFieldError(markPaidForm, data.field, data.error || 'Unable to mark paid.');
              window.hubSetBusy(submitButton, false);
              return;
            }

            window.location = 'sponsor.php?id=<?= $id ?>&saved=1';
          })
          .catch(() => {
            window.hubShowFormError(markPaidForm, 'Request failed. Please try again.');
            window.hubSetBusy(submitButton, false);
          });
      });

      syncSlotsForPlayer();
    })();
  </script>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      // Reopen a specific tab after a redirect, e.g. ?tab=payments
      var wantedTab = new URLSearchParams(window.location.search).get('tab');
      if (wantedTab) {
        var tabBtn = document.querySelector('.nav-tabs [data-bs-target="#' + wantedTab.replace(/[^a-z0-9_-]/gi, '') + '"]');
        if (tabBtn && typeof bootstrap !== 'undefined') {
          try { new bootstrap.Tab(tabBtn).show(); } catch (e) {}
        }
      }

      var sponsorId = <?= (int) $id ?>;
      document.addEventListener('click', function (event) {
        var btn = event.target.closest('.sponsor-payment-delete');
        if (!btn) return;

        var paymentId = btn.getAttribute('data-payment-id');
        var amount = btn.getAttribute('data-amount') || '';
        if (!paymentId) return;
        if (!window.confirm('Remove this £' + amount + ' payment? The outstanding balance will go back up.')) return;

        btn.disabled = true;
        fetch('sponsor_ajax.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'action=delete_payment&id=' + encodeURIComponent(paymentId)
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data && data.success) {
              window.location = 'sponsor.php?id=' + sponsorId + '&tab=payments&payment_removed=1';
            } else {
              btn.disabled = false;
              window.alert((data && data.message) ? data.message : 'Could not remove the payment.');
            }
          })
          .catch(function () {
            btn.disabled = false;
            window.alert('Request failed. Please try again.');
          });
      });
    });
  </script>

<?php
  require __DIR__ . '/footer.php';
  exit;
}

if ($action === 'edit') {
  if ($id <= 0) {
    echo '<div><div class="alert alert-danger">Invalid sponsor ID.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
  }

  $stmt = $pdo->prepare("SELECT * FROM sponsors WHERE id = :id");
  $stmt->execute([':id' => $id]);
  $sponsor = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$sponsor) {
    echo '<div><div class="alert alert-danger">Sponsor not found.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
  }
} else {
  $nextSponsorSortOrder = max(10, ((int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM sponsors WHERE is_main_sponsor = 1")->fetchColumn()) + 10);
  $sponsor = [
    'name' => '',
    'is_active' => 1,
    'is_main_sponsor' => 0,
    'sort_order' => $nextSponsorSortOrder,
    'logo_path' => null,
    'white_logo_path' => null,
    'facebook_page_url' => null,
    'facebook_page_id' => null,
    'facebook_page_name' => null,
    'instagram_url' => null,
    'twitter_url' => null,
    'is_business' => 0,
    'address' => null,
    'website_url' => null,
    'contact_phone' => null,
    'contact_email' => null,
  ];
}

$formData['name'] = $sponsor['name'] ?? '';
$formData['is_active'] = (int)($sponsor['is_active'] ?? 1);
$formData['is_main_sponsor'] = (int)($sponsor['is_main_sponsor'] ?? 0);
$formData['sort_order'] = max(0, (int)($sponsor['sort_order'] ?? 0));
$formData['facebook_page_url'] = trim((string)($sponsor['facebook_page_url'] ?? ''));
$formData['facebook_page_id'] = trim((string)($sponsor['facebook_page_id'] ?? ''));
$formData['facebook_page_name'] = trim((string)($sponsor['facebook_page_name'] ?? ''));
$formData['instagram_url'] = trim((string)($sponsor['instagram_url'] ?? ''));
$formData['twitter_url'] = trim((string)($sponsor['twitter_url'] ?? ''));
$formData['is_business'] = (int)($sponsor['is_business'] ?? 0);
$formData['address'] = trim((string)($sponsor['address'] ?? ''));
$formData['website_url'] = trim((string)($sponsor['website_url'] ?? ''));
$formData['contact_phone'] = trim((string)($sponsor['contact_phone'] ?? ''));
$formData['contact_email'] = trim((string)($sponsor['contact_email'] ?? ''));
$currentLogo = $sponsor['logo_path'] ?? null;
$currentWhiteLogo = $sponsor['white_logo_path'] ?? null;

if ($action === 'edit' && $id > 0) {
  $seasonStmt = $pdo->prepare("
    SELECT is_active, joined_at, left_at
    FROM sponsor_seasons
    WHERE season_id = :season_id
      AND sponsor_id = :sponsor_id
    LIMIT 1
  ");
  $seasonStmt->execute([
    ':season_id' => $seasonId,
    ':sponsor_id' => $id,
  ]);
  $existingSeasonAssignment = $seasonStmt->fetch(PDO::FETCH_ASSOC);
  if ($existingSeasonAssignment) {
    $seasonAssignment['is_active'] = (int)$existingSeasonAssignment['is_active'];
    $seasonAssignment['joined_at'] = $existingSeasonAssignment['joined_at'] ?: null;
    $seasonAssignment['left_at'] = $existingSeasonAssignment['left_at'] ?: null;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check()) {
    $errors[] = 'Invalid security token. Please try again.';
  }

  $formData['name'] = trim((string)($_POST['name'] ?? ''));
  $formData['is_active'] = isset($_POST['is_active']) ? 1 : 0;
  $formData['is_main_sponsor'] = (int)($sponsor['is_main_sponsor'] ?? 0);
  $formData['sort_order'] = max(0, (int)($sponsor['sort_order'] ?? 0));
  $formData['facebook_page_url'] = trim((string)($_POST['facebook_page_url'] ?? ''));
  $formData['facebook_page_id'] = trim((string)($_POST['facebook_page_id'] ?? ''));
  $formData['facebook_page_name'] = trim((string)($_POST['facebook_page_name'] ?? ''));
  $formData['instagram_url'] = trim((string)($_POST['instagram_url'] ?? ''));
  $formData['twitter_url'] = trim((string)($_POST['twitter_url'] ?? ''));
  $formData['is_business'] = isset($_POST['is_business']) ? 1 : 0;
  $formData['address'] = trim((string)($_POST['address'] ?? ''));
  $formData['website_url'] = trim((string)($_POST['website_url'] ?? ''));
  $formData['contact_phone'] = trim((string)($_POST['contact_phone'] ?? ''));
  $formData['contact_email'] = trim((string)($_POST['contact_email'] ?? ''));
  if ($formData['contact_email'] !== '' && !filter_var($formData['contact_email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Enter a valid contact email address.';
  }
  $seasonStatus = $_POST['season_status'] ?? 'active';
  if (!in_array($seasonStatus, ['active', 'left'], true)) {
    $errors[] = 'Invalid sponsor season status.';
  }
  $removeLogoChecked = isset($_POST['remove_logo']) && $schemaReady;
  $removeWhiteLogoChecked = isset($_POST['remove_white_logo']) && $schemaReady;
  $removeLogo = $removeLogoChecked;
  $removeWhiteLogo = $removeWhiteLogoChecked;
  $newLogoPath = $currentLogo;
  $newWhiteLogoPath = $currentWhiteLogo;

  if ($formData['name'] === '') {
    $errors[] = 'Sponsor name is required.';
  }

  if ($formData['facebook_page_url'] !== '') {
    $normalisedFacebookUrl = sponsor_normalise_facebook_url($formData['facebook_page_url']);
    if ($normalisedFacebookUrl === null) {
      $errors[] = 'Facebook Page URL must be a valid facebook.com Page URL.';
    } else {
      $formData['facebook_page_url'] = $normalisedFacebookUrl;
    }
  }

  foreach ([
    'instagram_url' => 'Instagram URL',
    'twitter_url' => 'X / Twitter URL',
    'website_url' => 'Website URL',
  ] as $field => $label) {
    $value = $formData[$field];
    if ($value !== '' && (
      filter_var($value, FILTER_VALIDATE_URL) === false
      || !in_array(strtolower((string)parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
    )) {
      $errors[] = $label . ' must be a complete http:// or https:// URL.';
    }
  }
  if (($formData['facebook_page_id'] === '' || $formData['facebook_page_name'] === '') && $formData['facebook_page_url'] !== '') {
    $resolvedFacebookPage = sponsor_resolve_facebook_page($formData['facebook_page_url']);
    if ($resolvedFacebookPage !== null) {
      $formData['facebook_page_url'] = $resolvedFacebookPage['url'];
      $formData['facebook_page_id'] = $resolvedFacebookPage['id'];
      if ($resolvedFacebookPage['name'] !== '') {
        $formData['facebook_page_name'] = $resolvedFacebookPage['name'];
      }
    }
  }
  if ($formData['facebook_page_id'] !== '' && preg_match('/^[0-9]{5,100}$/', $formData['facebook_page_id']) !== 1) {
    $errors[] = 'Facebook Page ID must contain numbers only.';
  }

  if (!empty($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
    if (!$schemaReady) {
      $errors[] = 'Unable to upload images at this time.';
    } else {
      $file = $_FILES['logo'];
      if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Image upload failed. Please try again.';
      } elseif (!is_uploaded_file($file['tmp_name'])) {
        $errors[] = 'Invalid image upload.';
      } elseif ($file['size'] > 5 * 1024 * 1024) {
        $errors[] = 'Image must be 5MB or smaller.';
      } else {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : null;
        if ($finfo) {
          finfo_close($finfo);
        }
        $allowed = [
          'image/jpeg' => 'jpg',
          'image/png'  => 'png',
          'image/gif'  => 'gif',
          'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime ?? ''])) {
          $errors[] = 'Unsupported image format. Please use PNG, JPG, GIF or WebP.';
        } else {
          $uploadDir = __DIR__ . '/uploads/sponsors';
          if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
              $errors[] = 'Failed to prepare upload directory.';
            }
          }
          if (!$errors) {
            try {
              $random = bin2hex(random_bytes(8));
            } catch (Exception $e) {
              $errors[] = 'Failed to prepare filename for image upload.';
              $random = null;
            }
            if (!$errors && $random !== null) {
              $filename = 'sponsor_' . $random . '.' . $allowed[$mime];
              $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;
              $pendingUpload = [
                'tmp_name' => $file['tmp_name'],
                'destination' => $destination,
              ];
              $newLogoPath = $filename; // Store only the filename in DB
              $removeLogo = false;
              $removeLogoChecked = false;
            }
          }
        }
      }
    }
  }

  if (!empty($_FILES['white_logo']) && $_FILES['white_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
    if (!$schemaReady) {
      $errors[] = 'Unable to upload images at this time.';
    } else {
      $file = $_FILES['white_logo'];
      if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'White image upload failed. Please try again.';
      } elseif (!is_uploaded_file($file['tmp_name'])) {
        $errors[] = 'Invalid white image upload.';
      } elseif ($file['size'] > 5 * 1024 * 1024) {
        $errors[] = 'White image must be 5MB or smaller.';
      } else {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : null;
        if ($finfo) {
          finfo_close($finfo);
        }
        $allowed = [
          'image/jpeg' => 'jpg',
          'image/png'  => 'png',
          'image/gif'  => 'gif',
          'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime ?? ''])) {
          $errors[] = 'Unsupported white image format. Please use PNG, JPG, GIF or WebP.';
        } else {
          $uploadDir = __DIR__ . '/uploads/sponsors';
          if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            $errors[] = 'Failed to prepare upload directory.';
          }
          if (!$errors) {
            try {
              $random = bin2hex(random_bytes(8));
            } catch (Exception $e) {
              $errors[] = 'Failed to prepare filename for white image upload.';
              $random = null;
            }
            if (!$errors && $random !== null) {
              $filename = 'sponsor_white_' . $random . '.' . $allowed[$mime];
              $pendingWhiteUpload = [
                'tmp_name' => $file['tmp_name'],
                'destination' => $uploadDir . DIRECTORY_SEPARATOR . $filename,
              ];
              $newWhiteLogoPath = $filename;
              $removeWhiteLogo = false;
              $removeWhiteLogoChecked = false;
            }
          }
        }
      }
    }
  }

  if ($removeLogo && !$pendingUpload) {
    $newLogoPath = null;
  }
  if ($removeWhiteLogo && !$pendingWhiteUpload) {
    $newWhiteLogoPath = null;
  }

  if ($formData['is_main_sponsor'] && empty($newLogoPath)) {
    $errors[] = 'Upload a Sponsor Image before marking this as a Main Sponsor.';
  }

  if (!$schemaReady && $newLogoPath !== $currentLogo) {
    $errors[] = 'Sponsor images cannot be modified because the database update failed.';
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      if ($action === 'new') {
        $stmt = $pdo->prepare("
          INSERT INTO sponsors (
            name, is_active, is_main_sponsor, sort_order, logo_path, white_logo_path,
            facebook_page_url, facebook_page_id, facebook_page_name, instagram_url, twitter_url,
            is_business, address, website_url, contact_phone, contact_email
          ) VALUES (
            :name, :is_active, :is_main_sponsor, :sort_order, :logo_path, :white_logo_path,
            :facebook_page_url, :facebook_page_id, :facebook_page_name, :instagram_url, :twitter_url,
            :is_business, :address, :website_url, :contact_phone, :contact_email
          )
        ");
        $stmt->execute([
          ':name' => $formData['name'],
          ':is_active' => $formData['is_active'],
          ':is_main_sponsor' => $formData['is_main_sponsor'],
          ':sort_order' => $formData['sort_order'],
          ':logo_path' => $newLogoPath,
          ':white_logo_path' => $newWhiteLogoPath,
          ':facebook_page_url' => $formData['facebook_page_url'] ?: null,
          ':facebook_page_id' => $formData['facebook_page_id'] ?: null,
          ':facebook_page_name' => $formData['facebook_page_name'] ?: null,
          ':instagram_url' => $formData['instagram_url'] ?: null,
          ':twitter_url' => $formData['twitter_url'] ?: null,
          ':is_business' => $formData['is_business'],
          ':address' => $formData['address'] ?: null,
          ':website_url' => $formData['website_url'] ?: null,
          ':contact_phone' => $formData['contact_phone'] ?: null,
          ':contact_email' => $formData['contact_email'] ?: null,
        ]);
        $id = (int)$pdo->lastInsertId();
        if ($seasonId > 0) {
          $seasonJoinedAt = $seasonStatus === 'active'
            ? ($seasonAssignment['joined_at'] ?: date('Y-m-d'))
            : ($seasonAssignment['joined_at'] ?: date('Y-m-d'));
          $seasonLeftAt = $seasonStatus === 'left' ? ($seasonAssignment['left_at'] ?: date('Y-m-d')) : null;
          upsertSponsorSeason(
            $pdo,
            $seasonId,
            $id,
            $seasonStatus === 'active' ? 1 : 0,
            $seasonJoinedAt,
            $seasonLeftAt,
            null
          );
        }
      } else {
        $stmt = $pdo->prepare("
          UPDATE sponsors
          SET name = :name,
              is_active = :is_active,
              is_main_sponsor = :is_main_sponsor,
              sort_order = :sort_order,
              logo_path = :logo_path,
              white_logo_path = :white_logo_path,
              facebook_page_url = :facebook_page_url,
              facebook_page_id = :facebook_page_id,
              facebook_page_name = :facebook_page_name,
              instagram_url = :instagram_url,
              twitter_url = :twitter_url,
              is_business = :is_business,
              address = :address,
              website_url = :website_url,
              contact_phone = :contact_phone,
              contact_email = :contact_email
          WHERE id = :id
        ");
        $stmt->execute([
          ':name' => $formData['name'],
          ':is_active' => $formData['is_active'],
          ':is_main_sponsor' => $formData['is_main_sponsor'],
          ':sort_order' => $formData['sort_order'],
          ':logo_path' => $newLogoPath,
          ':white_logo_path' => $newWhiteLogoPath,
          ':facebook_page_url' => $formData['facebook_page_url'] ?: null,
          ':facebook_page_id' => $formData['facebook_page_id'] ?: null,
          ':facebook_page_name' => $formData['facebook_page_name'] ?: null,
          ':instagram_url' => $formData['instagram_url'] ?: null,
          ':twitter_url' => $formData['twitter_url'] ?: null,
          ':is_business' => $formData['is_business'],
          ':address' => $formData['address'] ?: null,
          ':website_url' => $formData['website_url'] ?: null,
          ':contact_phone' => $formData['contact_phone'] ?: null,
          ':contact_email' => $formData['contact_email'] ?: null,
          ':id' => $id,
        ]);

        if ($seasonId > 0) {
          $seasonJoinedAt = $seasonAssignment['joined_at'] ?: date('Y-m-d');
          $seasonLeftAt = $seasonStatus === 'left' ? ($seasonAssignment['left_at'] ?: date('Y-m-d')) : null;
          upsertSponsorSeason(
            $pdo,
            $seasonId,
            $id,
            $seasonStatus === 'active' ? 1 : 0,
            $seasonJoinedAt,
            $seasonLeftAt,
            null
          );
        }
      }

      if ($pendingUpload) {
        if (!move_uploaded_file($pendingUpload['tmp_name'], $pendingUpload['destination'])) {
          throw new RuntimeException('Failed to store uploaded image.');
        }
        if ($currentLogo && $currentLogo !== $newLogoPath) {
          $deleteAfterCommit = $currentLogo;
        }
      } elseif ($removeLogo && $currentLogo) {
        $deleteAfterCommit = $currentLogo;
      } else {
        $deleteAfterCommit = null;
      }

      if ($pendingWhiteUpload) {
        if (!move_uploaded_file($pendingWhiteUpload['tmp_name'], $pendingWhiteUpload['destination'])) {
          throw new RuntimeException('Failed to store uploaded white image.');
        }
        if ($currentWhiteLogo && $currentWhiteLogo !== $newWhiteLogoPath) {
          $deleteWhiteAfterCommit = $currentWhiteLogo;
        }
      } elseif ($removeWhiteLogo && $currentWhiteLogo) {
        $deleteWhiteAfterCommit = $currentWhiteLogo;
      } else {
        $deleteWhiteAfterCommit = null;
      }

      $pdo->commit();
      player_sponsors_sync_social_directory();
      auditLog($pdo, $action === 'new' ? 'sponsor_created' : 'sponsor_updated', ($action === 'new' ? 'Created' : 'Updated') . " sponsor #{$id} ({$formData['name']})");

      if (!empty($deleteAfterCommit)) {
        $deletePath = __DIR__ . '/uploads/sponsors/' . $deleteAfterCommit;
        if (is_file($deletePath)) {
          @unlink($deletePath);
        }
      }
      if (!empty($deleteWhiteAfterCommit)) {
        $deletePath = __DIR__ . '/uploads/sponsors/' . $deleteWhiteAfterCommit;
        if (is_file($deletePath)) {
          @unlink($deletePath);
        }
      }

      header('Location: sponsor.php?id=' . $id . '&saved=1');
      exit;
    } catch (Throwable $e) {
      $pdo->rollBack();
      if ($pendingUpload && isset($pendingUpload['destination']) && is_file($pendingUpload['destination'])) {
        @unlink($pendingUpload['destination']);
      }
      if ($pendingWhiteUpload && isset($pendingWhiteUpload['destination']) && is_file($pendingWhiteUpload['destination'])) {
        @unlink($pendingWhiteUpload['destination']);
      }
      $errors[] = $e->getMessage();
    }
  }

  if ($removeLogoChecked && !$pendingUpload && $errors) {
    $currentLogo = null;
  }
  if ($removeWhiteLogoChecked && !$pendingWhiteUpload && $errors) {
    $currentWhiteLogo = null;
  }
}
?>

<div>
  <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="sponsors.php">Sponsors</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $action === 'new' ? 'Add sponsor' : 'Edit sponsor' ?></span></nav>
  <h1 class="h3 mb-3"><?= $action === 'new' ? 'Add Sponsor' : 'Edit Sponsor' ?></h1>

  <?php if ($schemaError): ?>
    <div class="alert alert-warning"><?= h($schemaError) ?></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
          <li><?= h($error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card shadow-sm border-0">
    <div class="card-body">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label for="sponsorName" class="form-label">Sponsor Name</label>
        <input type="text" class="form-control" id="sponsorName" name="name" value="<?= h($formData['name']) ?>" required>
      </div>

      <div class="card border-0 bg-light mb-3">
        <div class="card-body">
          <div class="d-flex align-items-start gap-2 mb-3">
            <span class="text-brand fs-4"><i class="fa-solid fa-share-nodes"></i></span>
            <div>
              <h2 class="h5 mb-1">Social channels</h2>
              <p class="small text-muted mb-0">These profiles can be used for sponsor credits and platform mentions in social captions.</p>
            </div>
          </div>
          <div class="row g-3">
            <div class="col-lg-8">
              <label for="sponsorFacebookUrl" class="form-label fw-semibold">
                <i class="fa-brands fa-facebook text-primary me-1"></i>Facebook Page URL
              </label>
              <input type="text" inputmode="url" class="form-control" id="sponsorFacebookUrl" name="facebook_page_url" value="<?= h($formData['facebook_page_url']) ?>" placeholder="www.facebook.com/your-page" autocomplete="url">
              <div class="form-text" id="sponsorFacebookLookupStatus">Paste the Page URL and its numeric ID will be found automatically.</div>
            </div>
            <div class="col-lg-4">
              <label for="sponsorFacebookId" class="form-label fw-semibold">Facebook Page ID</label>
              <input type="text" inputmode="numeric" pattern="[0-9]*" class="form-control" id="sponsorFacebookId" name="facebook_page_id" value="<?= h($formData['facebook_page_id']) ?>" placeholder="Numeric Page ID">
              <div class="form-text">Used when an automated Facebook Page mention is supported.</div>
            </div>
            <div class="col-12">
              <label for="sponsorFacebookName" class="form-label fw-semibold">Facebook Page name</label>
              <input type="text" class="form-control" id="sponsorFacebookName" name="facebook_page_name" value="<?= h($formData['facebook_page_name']) ?>" placeholder="Found automatically from the Facebook Page">
              <div class="form-text">This is the official Page name displayed in Facebook sponsor mentions.</div>
            </div>
            <div class="col-md-6">
              <label for="sponsorInstagramUrl" class="form-label fw-semibold">
                <i class="fa-brands fa-instagram text-danger me-1"></i>Instagram profile URL
              </label>
              <input type="url" class="form-control" id="sponsorInstagramUrl" name="instagram_url" value="<?= h($formData['instagram_url']) ?>" placeholder="https://www.instagram.com/your-account">
            </div>
            <div class="col-md-6">
              <label for="sponsorTwitterUrl" class="form-label fw-semibold">
                <i class="fa-brands fa-x-twitter me-1"></i>X / Twitter profile URL
              </label>
              <input type="url" class="form-control" id="sponsorTwitterUrl" name="twitter_url" value="<?= h($formData['twitter_url']) ?>" placeholder="https://x.com/your-account">
            </div>
          </div>
        </div>
      </div>

      <div class="card border-0 bg-light mb-3">
        <div class="card-body">
          <div class="d-flex align-items-start gap-2 mb-3">
            <span class="text-brand fs-4"><i class="fa-solid fa-envelope"></i></span>
            <div>
              <h2 class="h5 mb-1">Payments</h2>
              <p class="small text-muted mb-0">Used to email Stripe payment links from a sponsorship agreement.</p>
            </div>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label for="sponsorContactEmail" class="form-label fw-semibold">Contact email</label>
              <input type="email" class="form-control" id="sponsorContactEmail" name="contact_email" value="<?= h($formData['contact_email']) ?>" placeholder="sponsor@example.com" autocomplete="email">
            </div>
          </div>
        </div>
      </div>

      <div class="card border-0 bg-light mb-3">
        <div class="card-body">
          <div class="d-flex align-items-start gap-2 mb-3">
            <span class="text-brand fs-4"><i class="fa-solid fa-building"></i></span>
            <div>
              <h2 class="h5 mb-1">Business details</h2>
              <p class="small text-muted mb-0">For sponsors who are a business rather than an individual. Shown on the player graphic and available to insert into social post captions.</p>
            </div>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="sponsorIsBusiness" name="is_business" value="1" <?= $formData['is_business'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="sponsorIsBusiness">This sponsor is a business</label>
          </div>
          <div id="sponsorBusinessFields" class="row g-3" <?= $formData['is_business'] ? '' : 'style="display:none"' ?>>
            <div class="col-12">
              <label for="sponsorAddress" class="form-label fw-semibold">Address</label>
              <input type="text" class="form-control" id="sponsorAddress" name="address" value="<?= h($formData['address']) ?>" placeholder="e.g. 12 High Street, Saltcoats, KA21 5AB" autocomplete="street-address">
            </div>
            <div class="col-md-6">
              <label for="sponsorWebsiteUrl" class="form-label fw-semibold">Website</label>
              <input type="url" class="form-control" id="sponsorWebsiteUrl" name="website_url" value="<?= h($formData['website_url']) ?>" placeholder="https://www.example.com" autocomplete="url">
            </div>
            <div class="col-md-6">
              <label for="sponsorContactPhone" class="form-label fw-semibold">Contact number</label>
              <input type="tel" class="form-control" id="sponsorContactPhone" name="contact_phone" value="<?= h($formData['contact_phone']) ?>" placeholder="01294 123456" autocomplete="tel">
            </div>
          </div>
        </div>
      </div>

      <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" role="switch" id="sponsorActive" name="is_active" value="1" <?= $formData['is_active'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="sponsorActive">Sponsor is active</label>
      </div>

      <div class="alert alert-info">
        Sponsorship roles such as Main Sponsor are now managed as agreements.
        <?php if ($action === 'edit'): ?><a href="/sponsorship_agreement.php?action=new&amp;sponsor_id=<?= $id ?>" class="alert-link">Add an agreement for this sponsor</a>.<?php endif; ?>
      </div>

      <div class="card border-0 bg-light mb-3">
        <div class="card-body">
          <label class="form-label mb-2 fw-semibold d-block">Current season status</label>
          <div class="btn-group sponsor-season-toggle w-100" role="group" aria-label="Current season status">
            <input class="btn-check" type="radio" name="season_status" id="seasonStatusActive" value="active" <?= (int)$seasonAssignment['is_active'] === 1 ? 'checked' : '' ?>>
            <label class="btn btn-outline-secondary sponsor-season-btn sponsor-season-active" for="seasonStatusActive">
              Active this season
            </label>

            <input class="btn-check" type="radio" name="season_status" id="seasonStatusLeft" value="left" <?= (int)$seasonAssignment['is_active'] === 0 ? 'checked' : '' ?>>
            <label class="btn btn-outline-secondary sponsor-season-btn sponsor-season-left" for="seasonStatusLeft">
              Left this season
            </label>
          </div>
          <div class="form-text mt-2">
            This controls the sponsor's status for the currently selected season.
          </div>
        </div>
      </div>

      <div class="mb-3">
        <h2 class="h5 mb-1">Sponsor Images</h2>
        <p class="small text-muted">Upload both versions so the logo remains readable on different graphic backgrounds.</p>
        <div class="row g-3">
          <div class="col-md-6">
            <div class="border rounded-3 p-3 h-100">
              <label for="sponsorLogo" class="form-label fw-semibold">Coloured Image</label>
              <?php if ($currentLogo): ?>
                <div class="mb-3">
                  <img src="/uploads/sponsors/<?= h(rawurlencode(basename((string)$currentLogo))) ?>" alt="<?= h($formData['name'] ?: 'Current sponsor') ?> coloured logo" class="rounded border bg-white p-2" style="width:220px; height:140px; object-fit:contain;">
                </div>
              <?php endif; ?>
              <input type="file" class="form-control" id="sponsorLogo" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" <?= !$schemaReady ? 'disabled' : '' ?>>
              <div class="form-text">PNG, JPG, GIF or WebP up to 5MB.</div>
              <?php if ($currentLogo): ?>
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" id="removeLogo" name="remove_logo" value="1" <?= !$schemaReady ? 'disabled' : '' ?> <?= $removeLogoChecked ? 'checked' : '' ?>>
                  <label class="form-check-label" for="removeLogo">Remove coloured image</label>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="col-md-6">
            <div class="border rounded-3 p-3 h-100">
              <label for="sponsorWhiteLogo" class="form-label fw-semibold">White Image</label>
              <?php if ($currentWhiteLogo): ?>
                <div class="mb-3 rounded border bg-dark p-2 d-inline-flex">
                  <img src="/uploads/sponsors/<?= h(rawurlencode(basename((string)$currentWhiteLogo))) ?>" alt="<?= h($formData['name'] ?: 'Current sponsor') ?> white logo" style="width:220px; height:140px; object-fit:contain;">
                </div>
              <?php endif; ?>
              <input type="file" class="form-control" id="sponsorWhiteLogo" name="white_logo" accept="image/png,image/jpeg,image/gif,image/webp" <?= !$schemaReady ? 'disabled' : '' ?>>
              <div class="form-text">Transparent PNG or WebP recommended.</div>
              <?php if ($currentWhiteLogo): ?>
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" id="removeWhiteLogo" name="remove_white_logo" value="1" <?= !$schemaReady ? 'disabled' : '' ?> <?= $removeWhiteLogoChecked ? 'checked' : '' ?>>
                  <label class="form-check-label" for="removeWhiteLogo">Remove white image</label>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php if (!$schemaReady): ?>
          <div class="alert alert-warning mt-2 mb-0 py-2 px-3">Image uploads are currently unavailable.</div>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-footer d-flex flex-column-reverse flex-md-row justify-content-between gap-2">
      <a href="<?= $action === 'new' ? 'sponsors.php' : 'sponsor.php?id=' . $id ?>" class="btn btn-outline-secondary">Cancel</a>
      <button type="submit" class="btn btn-brand"><?= $action === 'new' ? 'Create Sponsor' : 'Save Changes' ?></button>
    </div>
  </form>
</div>

<script>
(() => {
  const urlInput = document.getElementById('sponsorFacebookUrl');
  const idInput = document.getElementById('sponsorFacebookId');
  const nameInput = document.getElementById('sponsorFacebookName');
  const status = document.getElementById('sponsorFacebookLookupStatus');
  const csrfInput = document.querySelector('input[name="csrf_token"]');
  if (!urlInput || !idInput || !nameInput || !status || !csrfInput) return;

  let requestNumber = 0;

  const showStatus = (message, tone = 'muted') => {
    status.textContent = message;
    status.className = `form-text text-${tone}`;
  };

  const resolvePage = async () => {
    let url = urlInput.value.trim();
    if (!url) {
      showStatus('Paste the Page URL and its numeric ID will be found automatically.');
      return;
    }
    if (!/^https?:\/\//i.test(url)) {
      url = `https://${url.replace(/^\/+/, '')}`;
      urlInput.value = url;
    }

    const currentRequest = ++requestNumber;
    showStatus('Finding Facebook Page ID…', 'primary');

    const data = new FormData();
    data.set('csrf_token', csrfInput.value);
    data.set('facebook_page_url', url);

    try {
      const response = await fetch('resolve_facebook_page.php', {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
        headers: {'Accept': 'application/json'}
      });
      const result = await response.json();
      if (currentRequest !== requestNumber) return;

      if (!response.ok || !result.ok) {
        showStatus(result.message || 'The Facebook Page ID could not be found automatically.', 'warning');
        return;
      }

      urlInput.value = result.url;
      idInput.value = result.id;
      if (result.name) nameInput.value = result.name;
      showStatus(`${result.name || 'Facebook Page'} found and linked.`, 'success');
    } catch (error) {
      if (currentRequest === requestNumber) {
        showStatus('The Facebook Page ID could not be checked just now. You can still save it manually.', 'warning');
      }
    }
  };

  urlInput.addEventListener('change', resolvePage);
  urlInput.addEventListener('paste', () => window.setTimeout(resolvePage, 0));
})();

(() => {
  const toggle = document.getElementById('sponsorIsBusiness');
  const fields = document.getElementById('sponsorBusinessFields');
  if (!toggle || !fields) return;

  toggle.addEventListener('change', () => {
    fields.style.display = toggle.checked ? '' : 'none';
  });
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
