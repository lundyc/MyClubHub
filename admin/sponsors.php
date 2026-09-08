<?php
// sponsors.php — Manage Sponsors
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';

$seasonId = getSelectedSeasonId($pdo);
// Remember each season's directory filters when returning from another page.
$sponsorFilterKeys = ['q', 'payment', 'status', 'scope', 'package', 'main', 'nologo', 'facebook'];
$hasSponsorFilters = count(array_intersect($sponsorFilterKeys, array_keys($_GET))) > 0;
if (isset($_GET['reset'])) {
  unset($_SESSION['sponsors_list_filters'][$seasonId]);
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && !$hasSponsorFilters) {
  $rememberedFilters = $_SESSION['sponsors_list_filters'][$seasonId] ?? [];
  if ($rememberedFilters) {
    $returnQuery = array_merge($rememberedFilters, ['season_id' => $seasonId]);
    if (isset($_GET['saved'])) {
      $returnQuery['saved'] = '1';
    }
    header('Location: sponsors.php?' . http_build_query($returnQuery));
    exit;
  }
}
$season = getSeasonById($pdo, $seasonId);
$previousSeasonId = $seasonId > 0 ? getPreviousSeasonId($pdo, $seasonId) : 0;
$seasonLocked = $season ? (int)$season['is_locked'] === 1 : false;
ensureSponsorSeasonSchema($pdo);

$pageHero = [
  'eyebrow' => 'Sponsor management',
  'title' => 'Sponsors',
  'subtitle' => 'Manage every sponsor, agreement type, and payment position from one portfolio view.',
  'actions' => [],
];
require_once __DIR__ . '/header.php';
$seasonError = '';

function sponsorPaymentState(array $row): array
{
  $agreements = (int)$row['agreement_count'];
  $total = (float)$row['total_amount'];
  $paid = (float)$row['total_paid'];
  $complimentary = (int)$row['complimentary_count'];

  if ($agreements === 0) {
    return ['key' => 'noagreements', 'label' => 'No agreements', 'badge' => 'text-bg-secondary'];
  }

  if ($total <= 0 && $complimentary > 0) {
    return ['key' => 'complimentary', 'label' => 'Complimentary', 'badge' => 'text-bg-info'];
  }

  if ($total <= 0) {
    return ['key' => 'nocharge', 'label' => 'No charge', 'badge' => 'text-bg-light'];
  }

  if ($paid >= $total) {
    return ['key' => 'paid', 'label' => 'Paid', 'badge' => 'bg-brand-paid'];
  }

  if ($paid > 0) {
    return ['key' => 'partial', 'label' => 'Partial', 'badge' => 'bg-brand-partial'];
  }

  return ['key' => 'unpaid', 'label' => 'Unpaid', 'badge' => 'bg-brand-unpaid'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check()) {
    $seasonError = 'Invalid CSRF token.';
  } else {
    $seasonAction = $_POST['season_action'] ?? '';

    try {
      if (!$season) {
        throw new RuntimeException('Invalid season selected.');
      }
      if ($seasonLocked) {
        throw new RuntimeException('This season is locked.');
      }

      if ($seasonAction === 'copy_previous') {
        if ($previousSeasonId <= 0) {
          throw new RuntimeException('No previous season available to copy from.');
        }

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM sponsor_seasons WHERE season_id = :season_id")
          ->execute([':season_id' => $seasonId]);
        seedSeasonSponsors($pdo, $seasonId, $previousSeasonId);
        $pdo->commit();

        header('Location: sponsors.php?saved=1');
        exit;
      }

      if ($seasonAction === 'toggle_sponsor_status') {
        $sponsorId = (int)($_POST['sponsor_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

        if ($sponsorId <= 0) {
          throw new RuntimeException('Invalid sponsor selected.');
        }

        $stmt = $pdo->prepare("
          UPDATE sponsor_seasons
          SET is_active = :is_active,
              left_at = CASE WHEN :is_active = 1 THEN NULL ELSE CURRENT_DATE() END
          WHERE season_id = :season_id
            AND sponsor_id = :sponsor_id
        ");
        $stmt->execute([
          ':is_active' => $isActive,
          ':season_id' => $seasonId,
          ':sponsor_id' => $sponsorId,
        ]);

        header('Location: sponsors.php?saved=1');
        exit;
      }

      if ($seasonAction === 'save_season_sponsors') {
        $activeIds = array_map('intval', $_POST['active_sponsors'] ?? []);
        $allSponsorIds = $pdo->query("SELECT id FROM sponsors ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM sponsor_seasons WHERE season_id = :season_id")
          ->execute([':season_id' => $seasonId]);

        $ins = $pdo->prepare("
          INSERT INTO sponsor_seasons (season_id, sponsor_id, is_active, joined_at, left_at, notes)
          VALUES (:season_id, :sponsor_id, :is_active, NULL, NULL, NULL)
        ");

        foreach ($allSponsorIds as $sponsorId) {
          $ins->execute([
            ':season_id' => $seasonId,
            ':sponsor_id' => (int)$sponsorId,
            ':is_active' => in_array((int)$sponsorId, $activeIds, true) ? 1 : 0,
          ]);
        }

        $pdo->commit();
        header('Location: sponsors.php?saved=1');
        exit;
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $seasonError = $e->getMessage();
    }
  }
}

// --- Filters ---
$paymentFilter = $_GET['payment'] ?? 'all';
if ($paymentFilter === 'noslots') {
  $paymentFilter = 'noagreements';
}
if (!in_array($paymentFilter, ['all', 'paid', 'partial', 'unpaid', 'complimentary', 'nocharge', 'noagreements'], true)) {
  $paymentFilter = 'all';
}

$statusFilter = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, ['all', 'active', 'inactive'], true)) {
  $statusFilter = 'all';
}

$scopeLabels = ['club' => 'Club-wide', 'team' => 'Team', 'match' => 'Match', 'player' => 'Player', 'digital' => 'Digital'];
$scopeFilter = $_GET['scope'] ?? 'all';
if (!in_array($scopeFilter, array_merge(['all'], array_keys($scopeLabels)), true)) {
  $scopeFilter = 'all';
}

$packageFilterRaw = $_GET['package'] ?? [];
if (!is_array($packageFilterRaw)) {
  $packageFilterRaw = $packageFilterRaw !== '' ? [$packageFilterRaw] : [];
}
$packageFilters = array_values(array_unique(array_filter(array_map('intval', $packageFilterRaw), static fn(int $id): bool => $id > 0)));
$mainOnly = ($_GET['main'] ?? '') === '1';
$noLogoOnly = ($_GET['nologo'] ?? '') === '1';
$facebookFilter = $_GET['facebook'] ?? 'all';
if (!in_array($facebookFilter, ['all', 'posted', 'not_posted'], true)) {
  $facebookFilter = 'all';
}

$search = trim($_GET['q'] ?? '');

$_SESSION['sponsors_list_filters'][$seasonId] = [
  'q' => $search,
  'payment' => $paymentFilter,
  'status' => $statusFilter,
  'scope' => $scopeFilter,
  'package' => $packageFilters,
  'main' => $mainOnly ? '1' : '0',
  'nologo' => $noLogoOnly ? '1' : '0',
  'facebook' => $facebookFilter,
];

$whereParts = [];
$params = [];

if ($search !== '') {
  $whereParts[] = "(s.name LIKE :q OR COALESCE(ss.notes, '') LIKE :q)";
  $params[':q'] = '%' . $search . '%';
}

if ($statusFilter !== 'all') {
  $whereParts[] = 's.is_active = :is_active';
  $params[':is_active'] = $statusFilter === 'active' ? 1 : 0;
}

if ($mainOnly) {
  $whereParts[] = 's.is_main_sponsor = 1';
}

if ($noLogoOnly) {
  $whereParts[] = "(s.logo_path IS NULL OR s.logo_path = '')";
}

if ($facebookFilter !== 'all') {
  $whereParts[] = 'COALESCE(ss.facebook_spotlight_posted, 0) = :facebook_spotlight_posted';
  $params[':facebook_spotlight_posted'] = $facebookFilter === 'posted' ? 1 : 0;
}

$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

// --- Fetch the sponsor directory, then attach every agreement type. ---
$sql = "
  SELECT
    s.id,
    s.name,
    s.is_active,
    s.is_main_sponsor,
    s.logo_path,
    ss.notes,
    COALESCE(ss.facebook_spotlight_posted, 0) AS facebook_spotlight_posted,
    ss.facebook_spotlight_posted_at
  FROM sponsors s
  LEFT JOIN sponsor_seasons ss
    ON ss.sponsor_id = s.id
   AND ss.season_id = :season_id
  $where
  ORDER BY
    s.is_active DESC,
    s.name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([':season_id' => $seasonId], $params));
$sponsors = $stmt->fetchAll(PDO::FETCH_ASSOC);

$agreements = getSponsorshipAgreements($pdo, ['season_id' => $seasonId]);
$agreementIds = array_map(static fn(array $agreement): int => (int)$agreement['id'], $agreements);
$lastPayments = [];
if ($agreementIds !== []) {
  $placeholders = implode(',', array_fill(0, count($agreementIds), '?'));
  $paymentStmt = $pdo->prepare("SELECT a.sponsor_id, MAX(p.paid_at) AS last_payment
    FROM sponsorship_agreements a
    JOIN sponsorship_agreement_payments p ON p.agreement_id = a.id
    WHERE a.id IN ($placeholders)
    GROUP BY a.sponsor_id");
  $paymentStmt->execute($agreementIds);
  foreach ($paymentStmt->fetchAll(PDO::FETCH_ASSOC) as $paymentRow) {
    $lastPayments[(int)$paymentRow['sponsor_id']] = (string)$paymentRow['last_payment'];
  }
  $legacyPaymentQueries = [
    "SELECT a.sponsor_id, MAX(p.paid_at) AS last_payment FROM sponsorship_agreements a JOIN sponsorship_payments p ON a.legacy_source = 'player' AND p.sponsorship_id = a.legacy_id WHERE a.id IN ($placeholders) GROUP BY a.sponsor_id",
    "SELECT a.sponsor_id, MAX(p.paid_at) AS last_payment FROM sponsorship_agreements a JOIN match_sponsorship_payments p ON a.legacy_source = 'match' AND p.match_sponsorship_id = a.legacy_id WHERE a.id IN ($placeholders) GROUP BY a.sponsor_id",
  ];
  foreach ($legacyPaymentQueries as $legacyPaymentSql) {
    $legacyPaymentStmt = $pdo->prepare($legacyPaymentSql);
    $legacyPaymentStmt->execute($agreementIds);
    foreach ($legacyPaymentStmt->fetchAll(PDO::FETCH_ASSOC) as $paymentRow) {
      $sponsorId = (int)$paymentRow['sponsor_id'];
      $paymentDate = (string)$paymentRow['last_payment'];
      if (!isset($lastPayments[$sponsorId]) || $paymentDate > $lastPayments[$sponsorId]) {
        $lastPayments[$sponsorId] = $paymentDate;
      }
    }
  }
}

$agreementRowsBySponsor = [];
$packageOptions = [];
foreach ($agreements as $agreement) {
  if ((string)($agreement['effective_status'] ?? '') === 'cancelled') {
    continue;
  }
  $agreementRowsBySponsor[(int)$agreement['sponsor_id']][] = $agreement;

  $pid = (int)$agreement['package_id'];
  if (!isset($packageOptions[$pid])) {
    $packageOptions[$pid] = [
      'id' => $pid,
      'name' => (string)$agreement['package_name'],
      'category' => trim((string)($agreement['package_category'] ?? '')) !== '' ? (string)$agreement['package_category'] : 'Other',
    ];
  }
}
uasort($packageOptions, static fn(array $a, array $b): int => [$a['category'], $a['name']] <=> [$b['category'], $b['name']]);

$packageFilters = array_values(array_intersect($packageFilters, array_keys($packageOptions)));

// An explicit "every package checked" selection is functionally identical to no
// filter at all (and must include sponsors with zero package agreements too), so
// collapse it back to the empty/unfiltered state — the checkboxes still render
// all-checked further down since $packageFilterIsAllSelected covers both cases.
$packageFilterIsAllSelected = $packageFilters === [] || count($packageFilters) === count($packageOptions);
if ($packageFilterIsAllSelected) {
  $packageFilters = [];
}

$packageFilterSummary = 'All packages';
if (!$packageFilterIsAllSelected) {
  $packageFilterSummary = count($packageFilters) <= 2
    ? implode(', ', array_map(static fn(int $id): string => $packageOptions[$id]['name'] ?? '', $packageFilters))
    : count($packageFilters) . ' packages selected';
}

foreach ($sponsors as &$sponsor) {
  $sponsorAgreements = $agreementRowsBySponsor[(int)$sponsor['id']] ?? [];
  $sponsor['agreement_count'] = count($sponsorAgreements);
  $sponsor['active_agreement_count'] = 0;
  $sponsor['complimentary_count'] = 0;
  $sponsor['total_amount'] = 0.0;
  $sponsor['total_paid'] = 0.0;
  $sponsor['package_names'] = [];
  $sponsor['package_ids'] = [];
  $sponsor['scope_counts'] = [];
  $sponsor['last_payment'] = $lastPayments[(int)$sponsor['id']] ?? null;
  foreach ($sponsorAgreements as $agreement) {
    if ((string)$agreement['effective_status'] === 'active') {
      $sponsor['active_agreement_count']++;
    }
    $packageName = trim((string)($agreement['package_name'] ?? ''));
    if ($packageName !== '') {
      $sponsor['package_names'][$packageName] = true;
    }
    $sponsor['package_ids'][(int)$agreement['package_id']] = true;
    $scope = (string)($agreement['package_scope'] ?? 'club');
    $sponsor['scope_counts'][$scope] = ($sponsor['scope_counts'][$scope] ?? 0) + 1;
    if (!empty($agreement['is_complimentary'])) {
      $sponsor['complimentary_count']++;
      continue;
    }
    $sponsor['total_amount'] += (float)$agreement['agreed_amount'];
    $sponsor['total_paid'] += (float)$agreement['total_paid'];
  }
  $sponsor['package_names'] = array_keys($sponsor['package_names']);
}
unset($sponsor);

usort($sponsors, static function (array $left, array $right): int {
  $leftState = sponsorPaymentState($left)['key'];
  $rightState = sponsorPaymentState($right)['key'];
  $order = ['unpaid' => 0, 'partial' => 1, 'paid' => 2, 'complimentary' => 3, 'nocharge' => 4, 'noagreements' => 5];
  return ($order[$leftState] <=> $order[$rightState]) ?: strcasecmp((string)$left['name'], (string)$right['name']);
});

function sponsorMatchesPaymentFilter(array $row, string $paymentFilter): bool
{
  $state = sponsorPaymentState($row)['key'];
  return $paymentFilter === 'all' || $state === $paymentFilter;
}

$visibleSponsors = array_values(array_filter($sponsors, function (array $row) use ($paymentFilter, $scopeFilter, $packageFilters): bool {
  if (!sponsorMatchesPaymentFilter($row, $paymentFilter)) {
    return false;
  }
  if ($scopeFilter !== 'all' && empty($row['scope_counts'][$scopeFilter])) {
    return false;
  }
  if ($packageFilters !== [] && !array_intersect_key($row['package_ids'], array_flip($packageFilters))) {
    return false;
  }
  return true;
}));

// --- Summary counts ---
$activeSponsorsCount = count(array_filter($sponsors, fn($row) => (int)$row['active_agreement_count'] > 0));
$leftSponsorsCount = count(array_filter($sponsors, fn($row) => (int)$row['active_agreement_count'] === 0 && (int)$row['agreement_count'] > 0));
$paidSponsorsCount = count(array_filter($sponsors, fn($row) => sponsorPaymentState($row)['key'] === 'paid'));
$partialSponsorsCount = count(array_filter($sponsors, fn($row) => sponsorPaymentState($row)['key'] === 'partial'));
$unpaidSponsorsCount = count(array_filter($sponsors, fn($row) => sponsorPaymentState($row)['key'] === 'unpaid'));
$complimentarySponsorsCount = count(array_filter($sponsors, fn($row) => sponsorPaymentState($row)['key'] === 'complimentary'));
$noChargeSponsorsCount = count(array_filter($sponsors, fn($row) => sponsorPaymentState($row)['key'] === 'nocharge'));
$noAgreementsSponsorsCount = count(array_filter($sponsors, fn($row) => sponsorPaymentState($row)['key'] === 'noagreements'));
$visibleOutstanding = array_sum(array_map(
  fn($row) => max(0, (float)$row['total_amount'] - (float)$row['total_paid']),
  $visibleSponsors
));
?>

<div class="sponsors-page">
  <?php if ($previousSeasonId > 0 && !$seasonLocked): ?>
    <form id="copyPreviousSeasonForm" method="post" class="d-none">
      <?= csrf_field() ?>
      <input type="hidden" name="season_id" value="<?= (int)$seasonId ?>">
      <input type="hidden" name="season_action" value="copy_previous">
    </form>
  <?php endif; ?>

  <?php hub_render_metric_grid([
    ['label' => 'Visible', 'value' => (int)count($visibleSponsors), 'meta' => 'In current filters', 'icon' => 'fa-filter', 'tone' => 'primary'],
    ['label' => 'Active', 'value' => (int)$activeSponsorsCount, 'meta' => 'With active agreements', 'icon' => 'fa-circle-check', 'tone' => 'success'],
    ['label' => 'Paid', 'value' => (int)$paidSponsorsCount, 'meta' => 'Fully settled', 'icon' => 'fa-wallet', 'tone' => 'success'],
    ['label' => 'Outstanding', 'value' => gbp($visibleOutstanding), 'meta' => 'Visible rows only', 'icon' => 'fa-clock', 'tone' => 'danger'],
    ['label' => 'Inactive portfolio', 'value' => (int)$leftSponsorsCount, 'meta' => 'No active agreements', 'icon' => 'fa-pause', 'tone' => 'neutral'],
    ['label' => 'No agreements', 'value' => (int)$noAgreementsSponsorsCount, 'meta' => 'Sponsor records only', 'icon' => 'fa-link-slash', 'tone' => 'warning'],
  ], 'Sponsor summary'); ?>

  <div class="hub-section-commandbar">
    <div><h2>Sponsor directory</h2><p>Search, review and manage the current portfolio.</p></div>
    <div class="hub-local-actions">
      <a href="/admin/sponsor.php?action=new" class="btn btn-brand btn-sm"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add sponsor</a>
      <a href="/admin/sponsor_wall.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-border-all me-1" aria-hidden="true"></i>Sponsor wall</a>
      <div class="dropdown">
        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">More</button>
        <div class="dropdown-menu dropdown-menu-end">
          <button type="button" class="dropdown-item" id="exportCsv"><i class="fa-solid fa-file-csv" aria-hidden="true"></i>Export CSV</button>
          <button type="button" class="dropdown-item" id="exportExcel"><i class="fa-solid fa-file-excel" aria-hidden="true"></i>Export Excel</button>
          <?php if ($previousSeasonId > 0 && !$seasonLocked): ?><button type="submit" form="copyPreviousSeasonForm" class="dropdown-item"><i class="fa-solid fa-copy" aria-hidden="true"></i>Copy last season</button><?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">Season sponsor settings saved.</div>
  <?php endif; ?>

  <?php if (!empty($seasonError)): ?>
    <div class="alert alert-danger"><?= h($seasonError) ?></div>
  <?php endif; ?>

  <?php if ($seasonLocked): ?>
    <div class="alert alert-warning">
      This season is locked. Season sponsor membership can be viewed here, but changes are disabled.
    </div>
  <?php endif; ?>

  <div class="card sponsors-section-card border-0 shadow-sm mb-3 hub-form-card">
    <div class="card-body">
      <form method="get" class="row g-2 g-lg-3 align-items-end">
        <div class="col-12 col-lg-3">
          <label for="sponsorSearch" class="form-label small text-muted mb-1">Search</label>
          <input
            id="sponsorSearch"
            class="form-control"
            type="search"
            name="q"
            placeholder="Search sponsors or notes..."
            value="<?= h($search) ?>">
        </div>
        <div class="col-6 col-lg-2">
          <label for="paymentFilter" class="form-label small text-muted mb-1">Payment</label>
          <select id="paymentFilter" name="payment" class="form-select">
            <option value="all" <?= $paymentFilter === 'all' ? 'selected' : '' ?>>All payments</option>
            <option value="paid" <?= $paymentFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
            <option value="partial" <?= $paymentFilter === 'partial' ? 'selected' : '' ?>>Partial</option>
            <option value="unpaid" <?= $paymentFilter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
            <option value="complimentary" <?= $paymentFilter === 'complimentary' ? 'selected' : '' ?>>Complimentary</option>
            <option value="nocharge" <?= $paymentFilter === 'nocharge' ? 'selected' : '' ?>>No charge</option>
            <option value="noagreements" <?= $paymentFilter === 'noagreements' ? 'selected' : '' ?>>No agreements</option>
          </select>
        </div>
        <div class="col-6 col-lg-2">
          <label for="statusFilter" class="form-label small text-muted mb-1">Status</label>
          <select id="statusFilter" name="status" class="form-select">
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All records</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>
        <div class="col-6 col-lg-2">
          <label for="scopeFilter" class="form-label small text-muted mb-1">Coverage</label>
          <select id="scopeFilter" name="scope" class="form-select">
            <option value="all" <?= $scopeFilter === 'all' ? 'selected' : '' ?>>All coverage</option>
            <?php foreach ($scopeLabels as $scopeValue => $scopeLabel): ?>
              <option value="<?= h($scopeValue) ?>" <?= $scopeFilter === $scopeValue ? 'selected' : '' ?>><?= h($scopeLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-lg-2">
          <label for="facebookFilter" class="form-label small text-muted mb-1">Facebook</label>
          <select id="facebookFilter" name="facebook" class="form-select">
            <option value="all" <?= $facebookFilter === 'all' ? 'selected' : '' ?>>All Facebook</option>
            <option value="posted" <?= $facebookFilter === 'posted' ? 'selected' : '' ?>>Advertised</option>
            <option value="not_posted" <?= $facebookFilter === 'not_posted' ? 'selected' : '' ?>>Not advertised</option>
          </select>
        </div>
        <div class="col-6 col-lg-3 hub-checkbox-dropdown-field">
          <label class="form-label small text-muted mb-1" id="packageFilterLabel">Package</label>
          <div class="dropdown hub-checkbox-dropdown">
            <button type="button" class="form-select hub-checkbox-dropdown__toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-labelledby="packageFilterLabel">
              <span class="hub-checkbox-dropdown__summary" data-package-filter-summary><?= h($packageFilterSummary) ?></span>
            </button>
            <div class="dropdown-menu hub-checkbox-dropdown__menu" aria-label="Filter by package">
              <?php
                $packageOptionsByCategory = [];
                foreach ($packageOptions as $opt) {
                  $packageOptionsByCategory[$opt['category']][] = $opt;
                }
              ?>
              <?php if ($packageOptionsByCategory === []): ?>
                <span class="hub-checkbox-dropdown__empty">No packages available</span>
              <?php else: ?>
                <label class="hub-checkbox-dropdown__item hub-checkbox-dropdown__select-all">
                  <input type="checkbox" data-package-select-all <?= $packageFilterIsAllSelected ? 'checked' : '' ?>>
                  <span>Select all</span>
                </label>
              <?php endif; ?>
              <?php foreach ($packageOptionsByCategory as $category => $opts): ?>
                <span class="hub-checkbox-dropdown__group-label"><?= h($category) ?></span>
                <?php foreach ($opts as $opt): ?>
                  <label class="hub-checkbox-dropdown__item">
                    <input type="checkbox" name="package[]" value="<?= (int)$opt['id'] ?>" <?= ($packageFilterIsAllSelected || in_array((int)$opt['id'], $packageFilters, true)) ? 'checked' : '' ?>>
                    <span><?= h($opt['name']) ?></span>
                  </label>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="col-6 col-lg-2 d-flex align-items-center">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="mainOnlyFilter" name="main" value="1" <?= $mainOnly ? 'checked' : '' ?>>
            <label class="form-check-label small" for="mainOnlyFilter">Main sponsors only</label>
          </div>
        </div>
        <div class="col-6 col-lg-2 d-flex align-items-center">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="noLogoFilter" name="nologo" value="1" <?= $noLogoOnly ? 'checked' : '' ?>>
            <label class="form-check-label small" for="noLogoFilter">Missing logo only</label>
          </div>
        </div>
        <div class="col-12 col-lg-8 d-flex gap-2 justify-content-lg-end">
          <input type="hidden" name="season_id" value="<?= (int)$seasonId ?>">
          <button class="btn btn-brand flex-grow-1 flex-lg-grow-0" type="submit">
            Apply Filters
          </button>
          <a class="btn btn-outline-secondary flex-grow-1 flex-lg-grow-0" href="sponsors.php?reset=1&amp;season_id=<?= (int)$seasonId ?>">
            Reset
          </a>
        </div>
      </form>
    </div>
  </div>

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <div class="text-muted small">
      Showing <?= (int)count($visibleSponsors) ?> sponsors
      in the current view with <?= gbp($visibleOutstanding) ?> outstanding.
    </div>
    <div class="d-flex flex-wrap gap-1">
      <span class="badge hub-status bg-brand-paid">Paid: <?= (int)$paidSponsorsCount ?></span>
      <span class="badge hub-status bg-brand-partial">Partial: <?= (int)$partialSponsorsCount ?></span>
      <span class="badge hub-status bg-brand-unpaid">Unpaid: <?= (int)$unpaidSponsorsCount ?></span>
      <span class="badge hub-status text-bg-info">Complimentary: <?= (int)$complimentarySponsorsCount ?></span>
      <span class="badge hub-status text-bg-light">No charge: <?= (int)$noChargeSponsorsCount ?></span>
      <span class="badge hub-status bg-secondary">No agreements: <?= (int)$noAgreementsSponsorsCount ?></span>
    </div>
  </div>

  <?php if (!$visibleSponsors): ?>
    <div class="alert alert-info mb-0 hub-empty-state">
      No sponsors match the current filters.
    </div>
  <?php else: ?>
    <div class="d-xl-none d-flex flex-column gap-2 mb-3">
      <?php foreach ($visibleSponsors as $s): ?>
        <?php
          $state = sponsorPaymentState($s);
          $outstanding = max(0, (float)$s['total_amount'] - (float)$s['total_paid']);
          $paymentPercent = (float)$s['total_amount'] > 0 ? min(100, round(((float)$s['total_paid'] / (float)$s['total_amount']) * 100)) : 0;
          $packageNames = (array)$s['package_names'];
        ?>
        <article class="sponsors-mobile-card hub-record-card">
          <div class="sponsors-mobile-head">
            <div class="d-flex align-items-start gap-2">
              <div class="sponsors-mobile-avatar">
                <?php if (!empty($s['logo_path'])): ?>
                  <img src="/uploads/sponsors/<?= h($s['logo_path']) ?>" alt="<?= h($s['name']) ?> logo">
                <?php else: ?>
                  <span><?= strtoupper(substr($s['name'], 0, 1)) ?></span>
                <?php endif; ?>
              </div>
              <div>
                <div class="fw-semibold"><?= h($s['name']) ?></div>
                <div class="d-flex flex-wrap gap-1 mt-1">
                  <span class="badge hub-status <?= h($state['badge']) ?>"><?= h($state['label']) ?></span>
                  <?php if (!(int)$s['is_active']): ?><span class="badge text-bg-secondary">Inactive record</span><?php endif; ?>
                  <?php if ((int)$s['is_main_sponsor']): ?><span class="badge text-bg-warning">Main sponsor</span><?php endif; ?>
                  <?php if ((int)$s['facebook_spotlight_posted'] === 1): ?><span class="badge text-bg-primary">Facebook posted</span><?php endif; ?>
                </div>
              </div>
            </div>
            <div class="btn-group sponsors-mobile-actions hub-actions" role="group" aria-label="Sponsor actions">
              <a href="/admin/sponsor.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View sponsor" aria-label="View <?= h($s['name']) ?>">
                <i class="fa-regular fa-eye" aria-hidden="true"></i>
              </a>
              <a href="/admin/sponsor.php?action=edit&id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit sponsor" aria-label="Edit <?= h($s['name']) ?>">
                <i class="fa-solid fa-pen" aria-hidden="true"></i>
              </a>
            </div>
          </div>

          <div class="sponsors-mobile-portfolio">
            <div><strong><?= (int)$s['agreement_count'] ?> agreement<?= (int)$s['agreement_count'] === 1 ? '' : 's' ?></strong><span><?= (int)$s['active_agreement_count'] ?> active<?php if ((int)$s['complimentary_count'] > 0): ?> · <?= (int)$s['complimentary_count'] ?> complimentary<?php endif; ?></span></div>
            <?php if ($packageNames !== []): ?>
              <div class="sponsor-package-list"><?php foreach (array_slice($packageNames, 0, 2) as $packageName): ?><span><?= h($packageName) ?></span><?php endforeach; ?><?php if (count($packageNames) > 2): ?><span>+<?= count($packageNames) - 2 ?> more</span><?php endif; ?></div>
            <?php else: ?><span class="text-muted small">No packages assigned</span><?php endif; ?>
          </div>

          <?php if ((array)$s['scope_counts'] !== []): ?>
            <div class="sponsor-coverage-list" aria-label="Sponsorship coverage">
              <?php foreach ((array)$s['scope_counts'] as $scope => $count): ?><span><i class="fa-solid <?= $scope === 'player' ? 'fa-shirt' : ($scope === 'match' ? 'fa-futbol' : ($scope === 'team' ? 'fa-people-group' : 'fa-shield-halved')) ?>" aria-hidden="true"></i><?= h(ucfirst((string)$scope)) ?> <?= (int)$count ?></span><?php endforeach; ?>
            </div>
          <?php endif; ?>

          <label class="form-check form-switch sponsor-facebook-toggle sponsor-facebook-toggle--mobile">
            <input
              class="form-check-input"
              type="checkbox"
              role="switch"
              data-facebook-spotlight-toggle
              data-sponsor-id="<?= (int)$s['id'] ?>"
              aria-label="Advertised on Facebook for <?= h($s['name']) ?>"
              <?= (int)$s['facebook_spotlight_posted'] === 1 ? 'checked' : '' ?>
              <?= $seasonLocked ? 'disabled' : '' ?>>
            <span>Advertised on Facebook</span>
          </label>

          <div class="sponsors-mobile-metrics">
            <div class="sponsors-mobile-metric">
              <span class="label">Value</span>
              <span class="value"><?= gbp((float)$s['total_amount']) ?></span>
            </div>
            <div class="sponsors-mobile-metric">
              <span class="label">Paid</span>
              <span class="value"><?= gbp((float)$s['total_paid']) ?></span>
            </div>
            <div class="sponsors-mobile-metric">
              <span class="label">Outstanding</span>
              <span class="value"><?= gbp($outstanding) ?></span>
            </div>
            <div class="sponsors-mobile-metric">
              <span class="label">Last payment</span>
              <span class="value"><?= !empty($s['last_payment']) ? h(date('d M Y', strtotime((string)$s['last_payment']))) : 'No payment recorded' ?></span>
            </div>
          </div>
          <?php if ((float)$s['total_amount'] > 0): ?><div class="sponsor-payment-progress" role="progressbar" aria-label="<?= h($s['name']) ?> payment progress" aria-valuenow="<?= (int)$paymentPercent ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= (int)$paymentPercent ?>%"></span></div><?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="card sponsors-section-card border-0 shadow-sm d-none d-xl-block hub-table-card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-modern table-hover hub-data-table align-middle mb-0" id="sponsorsLedgerTable">
            <caption class="visually-hidden">Sponsor portfolios, coverage, values and payment progress</caption>
            <thead>
              <tr>
                <th data-sort-key="sponsor" data-sort-type="text"><button type="button" class="sponsor-sort-button">Sponsor <span class="sponsor-sort-icon" aria-hidden="true"></span></button></th>
                <th data-sort-key="portfolio" data-sort-type="number"><button type="button" class="sponsor-sort-button">Portfolio <span class="sponsor-sort-icon" aria-hidden="true"></span></button></th>
                <th data-sort-key="coverage" data-sort-type="text"><button type="button" class="sponsor-sort-button">Coverage <span class="sponsor-sort-icon" aria-hidden="true"></span></button></th>
                <th class="text-end" data-sort-key="value" data-sort-type="number"><button type="button" class="sponsor-sort-button sponsor-sort-button--end">Value <span class="sponsor-sort-icon" aria-hidden="true"></span></button></th>
                <th data-sort-key="payment" data-sort-type="number"><button type="button" class="sponsor-sort-button">Payment <span class="sponsor-sort-icon" aria-hidden="true"></span></button></th>
                <th data-sort-key="last-payment" data-sort-type="number"><button type="button" class="sponsor-sort-button">Last payment <span class="sponsor-sort-icon" aria-hidden="true"></span></button></th>
                <th class="text-center" data-sort-key="facebook" data-sort-type="number"><button type="button" class="sponsor-sort-button sponsor-sort-button--center">Facebook <span class="sponsor-sort-icon" aria-hidden="true"></span></button></th>
                <th class="text-center" data-export-ignore="1">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($visibleSponsors as $s): ?>
                <?php
                  $state = sponsorPaymentState($s);
                  $outstanding = max(0, (float)$s['total_amount'] - (float)$s['total_paid']);
                  $paymentPercent = (float)$s['total_amount'] > 0 ? min(100, round(((float)$s['total_paid'] / (float)$s['total_amount']) * 100)) : 0;
                  $packageNames = (array)$s['package_names'];
                  $scopeSummary = implode(' ', array_map(
                    static fn($scope, $count): string => ucfirst((string)$scope) . ' ' . (int)$count,
                    array_keys((array)$s['scope_counts']),
                    array_values((array)$s['scope_counts'])
                  ));
                  $paymentSortOrder = ['unpaid' => 0, 'partial' => 1, 'paid' => 2, 'complimentary' => 3, 'nocharge' => 4, 'noagreements' => 5];
                  $lastPaymentSort = !empty($s['last_payment']) ? strtotime((string)$s['last_payment']) : 0;
                ?>
                <tr
                  class="<?= in_array($state['key'], ['unpaid', 'partial'], true) ? 'sponsor-ledger-row--attention' : '' ?>"
                  data-sort-sponsor="<?= h(strtolower((string)$s['name'])) ?>"
                  data-sort-portfolio="<?= (int)$s['agreement_count'] ?>"
                  data-sort-coverage="<?= h(strtolower($scopeSummary)) ?>"
                  data-sort-value="<?= h(number_format((float)$s['total_amount'], 2, '.', '')) ?>"
                  data-sort-payment="<?= (int)($paymentSortOrder[$state['key']] ?? 99) ?>"
                  data-sort-last-payment="<?= (int)$lastPaymentSort ?>"
                  data-sort-facebook="<?= (int)$s['facebook_spotlight_posted'] ?>">
                  <td>
                    <div class="sponsor-ledger-identity">
                      <div class="sponsor-ledger-logo"><?php if (!empty($s['logo_path'])): ?><img src="/uploads/sponsors/<?= h($s['logo_path']) ?>" alt=""><?php else: ?><span><?= h(strtoupper(substr((string)$s['name'], 0, 1))) ?></span><?php endif; ?></div>
                      <div><a class="sponsor-ledger-name" href="/admin/sponsor.php?id=<?= (int)$s['id'] ?>"><?= h($s['name']) ?></a><div class="sponsor-ledger-flags"><?php if ((int)$s['is_main_sponsor']): ?><span>Main sponsor</span><?php endif; ?><?php if (!(int)$s['is_active']): ?><span>Inactive record</span><?php endif; ?><?php if (empty($s['logo_path'])): ?><span>No logo</span><?php endif; ?></div></div>
                    </div>
                  </td>
                  <td>
                    <div class="sponsor-portfolio-count"><strong><?= (int)$s['agreement_count'] ?></strong><span>agreement<?= (int)$s['agreement_count'] === 1 ? '' : 's' ?> · <?= (int)$s['active_agreement_count'] ?> active</span></div>
                    <?php if ($packageNames !== []): ?><div class="sponsor-package-list"><?php foreach (array_slice($packageNames, 0, 2) as $packageName): ?><span><?= h($packageName) ?></span><?php endforeach; ?><?php if (count($packageNames) > 2): ?><span>+<?= count($packageNames) - 2 ?> more</span><?php endif; ?></div><?php else: ?><span class="text-muted small">No packages assigned</span><?php endif; ?>
                  </td>
                  <td><div class="sponsor-coverage-list"><?php foreach ((array)$s['scope_counts'] as $scope => $count): ?><span><i class="fa-solid <?= $scope === 'player' ? 'fa-shirt' : ($scope === 'match' ? 'fa-futbol' : ($scope === 'team' ? 'fa-people-group' : 'fa-shield-halved')) ?>" aria-hidden="true"></i><?= h(ucfirst((string)$scope)) ?> <?= (int)$count ?></span><?php endforeach; ?><?php if ((array)$s['scope_counts'] === []): ?><span class="is-empty">—</span><?php endif; ?></div></td>
                  <td class="text-end sponsor-ledger-value"><strong><?= gbp((float)$s['total_amount']) ?></strong><?php if ((int)$s['complimentary_count'] > 0): ?><span><?= (int)$s['complimentary_count'] ?> complimentary</span><?php endif; ?></td>
                  <td class="sponsor-ledger-payment">
                    <div class="d-flex justify-content-between align-items-center gap-2"><span class="badge hub-status <?= h($state['badge']) ?>"><?= h($state['label']) ?></span><?php if ((float)$s['total_amount'] > 0): ?><span class="small text-muted"><?= (int)$paymentPercent ?>%</span><?php endif; ?></div>
                    <?php if ((float)$s['total_amount'] > 0): ?><div class="sponsor-payment-progress" role="progressbar" aria-label="<?= h($s['name']) ?> payment progress" aria-valuenow="<?= (int)$paymentPercent ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= (int)$paymentPercent ?>%"></span></div><div class="sponsor-payment-detail"><span><?= gbp((float)$s['total_paid']) ?> paid</span><span><?= gbp($outstanding) ?> due</span></div><?php endif; ?>
                  </td>
                  <td class="sponsor-ledger-date">
                    <?= !empty($s['last_payment']) ? h(date('d M Y', strtotime((string)$s['last_payment']))) : '<span class="text-muted">None recorded</span>' ?>
                  </td>
                  <td class="text-center sponsor-facebook-cell">
                    <label class="form-check form-switch sponsor-facebook-toggle" title="Mark sponsor spotlight advertised on Facebook">
                      <input
                        class="form-check-input"
                        type="checkbox"
                        role="switch"
                        data-facebook-spotlight-toggle
                        data-sponsor-id="<?= (int)$s['id'] ?>"
                        aria-label="Advertised on Facebook for <?= h($s['name']) ?>"
                        <?= (int)$s['facebook_spotlight_posted'] === 1 ? 'checked' : '' ?>
                        <?= $seasonLocked ? 'disabled' : '' ?>>
                    </label>
                  </td>
                  <td class="text-center" data-export-ignore="1">
                    <div class="hub-row-actions hub-actions" role="group" aria-label="Sponsor actions">
                      <a href="/admin/sponsor.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-secondary" title="View sponsor" aria-label="View <?= h($s['name']) ?>">
                        <i class="fa-regular fa-eye" aria-hidden="true"></i>
                      </a>
                      <a href="/admin/sponsor.php?action=edit&id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit sponsor" aria-label="Edit <?= h($s['name']) ?>">
                        <i class="fa-solid fa-pen" aria-hidden="true"></i>
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<style>
  .sponsor-facebook-toggle {
    align-items: center;
    display: inline-flex;
    gap: .45rem;
    justify-content: center;
    margin: 0;
    min-width: 6rem;
    padding-left: 0;
    white-space: nowrap;
  }
  .sponsor-facebook-toggle .form-check-input {
    float: none;
    margin-left: 0;
  }
  .sponsor-facebook-toggle--mobile {
    border-top: 1px solid rgba(0, 0, 0, .08);
    justify-content: flex-start;
    padding-top: .75rem;
    width: 100%;
  }
  .sponsor-facebook-toggle--saving {
    opacity: .65;
  }
  #sponsorsLedgerTable th[data-sort-key] {
    cursor: pointer;
    user-select: none;
  }
  .sponsor-sort-button {
    align-items: center;
    background: transparent;
    border: 0;
    color: inherit;
    display: inline-flex;
    font: inherit;
    gap: .35rem;
    justify-content: flex-start;
    letter-spacing: inherit;
    padding: 0;
    text-align: inherit;
    text-transform: inherit;
    width: 100%;
  }
  .sponsor-sort-button--end {
    justify-content: flex-end;
  }
  .sponsor-sort-button--center {
    justify-content: center;
  }
  .sponsor-sort-button:focus-visible {
    outline: 2px solid var(--brand-primary, #124e66);
    outline-offset: 3px;
  }
  .sponsor-sort-icon::before {
    color: currentColor;
    content: "\f0dc";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    opacity: .35;
  }
  #sponsorsLedgerTable th[aria-sort="ascending"] .sponsor-sort-icon::before {
    content: "\f0de";
    opacity: .9;
  }
  #sponsorsLedgerTable th[aria-sort="descending"] .sponsor-sort-icon::before {
    content: "\f0dd";
    opacity: .9;
  }
</style>

<script>
  $(function () {
    $('[data-bs-toggle="tooltip"]').tooltip();
    var sponsorsCsrfToken = <?= json_encode((string)($_SESSION['csrf_token'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
    var selectedSeasonId = <?= (int)$seasonId ?>;
    var sponsorSortStorageKey = 'sponsors.sort.' + selectedSeasonId;
    var rememberedSponsorSort = null;
    try {
      if (<?= isset($_GET['reset']) ? 'true' : 'false' ?>) {
        sessionStorage.removeItem(sponsorSortStorageKey);
      }
      rememberedSponsorSort = JSON.parse(sessionStorage.getItem(sponsorSortStorageKey));
    } catch (error) { /* Sorting still works when browser storage is unavailable. */ }

    var packageCheckboxes = Array.prototype.slice.call(document.querySelectorAll('input[name="package[]"]'));
    var packageSelectAll = document.querySelector('[data-package-select-all]');
    var packageFilterSummary = document.querySelector('[data-package-filter-summary]');
    function updatePackageFilterSummary() {
      if (!packageFilterSummary) return;
      var checked = packageCheckboxes.filter(function (box) { return box.checked; });
      if (checked.length === 0 || checked.length === packageCheckboxes.length) {
        packageFilterSummary.textContent = 'All packages';
      } else if (checked.length <= 2) {
        packageFilterSummary.textContent = checked.map(function (box) {
          var label = box.closest('.hub-checkbox-dropdown__item').querySelector('span');
          return label ? label.textContent : box.value;
        }).join(', ');
      } else {
        packageFilterSummary.textContent = checked.length + ' packages selected';
      }
    }
    function syncPackageSelectAll() {
      if (!packageSelectAll || !packageCheckboxes.length) return;
      var checkedCount = packageCheckboxes.filter(function (box) { return box.checked; }).length;
      packageSelectAll.checked = checkedCount === packageCheckboxes.length;
      packageSelectAll.indeterminate = checkedCount > 0 && checkedCount < packageCheckboxes.length;
    }
    packageCheckboxes.forEach(function (box) {
      box.addEventListener('change', function () {
        updatePackageFilterSummary();
        syncPackageSelectAll();
      });
    });
    if (packageSelectAll) {
      packageSelectAll.addEventListener('change', function () {
        packageCheckboxes.forEach(function (box) { box.checked = packageSelectAll.checked; });
        packageSelectAll.indeterminate = false;
        updatePackageFilterSummary();
      });
      syncPackageSelectAll();
    }

    var sponsorsLedgerTable = document.getElementById('sponsorsLedgerTable');
    if (sponsorsLedgerTable) {
      var sortableHeaders = Array.prototype.slice.call(sponsorsLedgerTable.querySelectorAll('thead th[data-sort-key]'));
      var tableBody = sponsorsLedgerTable.querySelector('tbody');

      function getSponsorSortValue(row, key, type) {
        var value = row.dataset['sort' + key.replace(/(^|-)([a-z])/g, function (_, __, letter) {
          return letter.toUpperCase();
        })] || '';

        if (type === 'number') {
          var numericValue = parseFloat(value);
          return isNaN(numericValue) ? 0 : numericValue;
        }

        return String(value).toLowerCase();
      }

      function updateSponsorSortHeaders(activeHeader, direction) {
        sortableHeaders.forEach(function (header) {
          var button = header.querySelector('button');
          var isActive = header === activeHeader;
          header.setAttribute('aria-sort', isActive ? (direction === 'asc' ? 'ascending' : 'descending') : 'none');
          if (button) {
            button.setAttribute('aria-label', header.textContent.trim() + (isActive ? ', sorted ' + (direction === 'asc' ? 'ascending' : 'descending') : ', sort column'));
          }
        });
      }

      sortableHeaders.forEach(function (header) {
        header.setAttribute('aria-sort', 'none');
        var button = header.querySelector('button');
        if (!button || !tableBody) {
          return;
        }

        button.addEventListener('click', function () {
          var key = header.dataset.sortKey;
          var type = header.dataset.sortType || 'text';
          var direction = header.dataset.sortDirection === 'asc' ? 'desc' : 'asc';
          var rows = Array.prototype.slice.call(tableBody.querySelectorAll('tr'));

          rows.sort(function (left, right) {
            var leftValue = getSponsorSortValue(left, key, type);
            var rightValue = getSponsorSortValue(right, key, type);
            var result = 0;

            if (type === 'number') {
              result = leftValue - rightValue;
            } else {
              result = leftValue.localeCompare(rightValue, undefined, { numeric: true, sensitivity: 'base' });
            }

            if (result === 0) {
              result = getSponsorSortValue(left, 'sponsor', 'text').localeCompare(getSponsorSortValue(right, 'sponsor', 'text'), undefined, { numeric: true, sensitivity: 'base' });
            }

            return direction === 'asc' ? result : -result;
          });

          sortableHeaders.forEach(function (otherHeader) {
            if (otherHeader !== header) {
              delete otherHeader.dataset.sortDirection;
            }
          });
          header.dataset.sortDirection = direction;
          try {
            sessionStorage.setItem(sponsorSortStorageKey, JSON.stringify({ key: key, direction: direction }));
          } catch (error) { /* Browser storage is optional. */ }
          updateSponsorSortHeaders(header, direction);
          rows.forEach(function (row) { tableBody.appendChild(row); });
        });
      });
      updateSponsorSortHeaders(null, 'asc');
      if (rememberedSponsorSort) {
        var rememberedHeader = sortableHeaders.find(function (header) {
          return header.dataset.sortKey === rememberedSponsorSort.key;
        });
        if (rememberedHeader && rememberedHeader.querySelector('button')) {
          rememberedHeader.dataset.sortDirection = rememberedSponsorSort.direction === 'desc' ? 'asc' : 'desc';
          rememberedHeader.querySelector('button').click();
        }
      }
    }

    document.querySelectorAll('[data-facebook-spotlight-toggle]').forEach(function (toggle) {
      toggle.addEventListener('change', function () {
        var checked = toggle.checked;
        var sponsorId = toggle.dataset.sponsorId || '';
        var matchingSelector = '[data-facebook-spotlight-toggle][data-sponsor-id="' + sponsorId.replace(/"/g, '\\"') + '"]';
        var label = toggle.closest('.sponsor-facebook-toggle');
        var body = new URLSearchParams({
          csrf_token: sponsorsCsrfToken,
          season_id: String(selectedSeasonId),
          sponsor_id: sponsorId,
          posted: checked ? '1' : '0'
        });

        toggle.disabled = true;
        if (label) label.classList.add('sponsor-facebook-toggle--saving');

        fetch('/admin/sponsor_facebook_spotlight_save.php', {
          method: 'POST',
          body: body,
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json' }
        })
          .then(function (response) {
            return response.json().then(function (data) {
              if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Unable to save Facebook status.');
              }
              return data;
            });
          })
          .then(function (data) {
            document.querySelectorAll(matchingSelector).forEach(function (matchingToggle) {
              matchingToggle.checked = !!data.posted;
            });
          })
          .catch(function (error) {
            toggle.checked = !checked;
            alert(error.message || 'Unable to save Facebook status.');
          })
          .finally(function () {
            document.querySelectorAll(matchingSelector).forEach(function (matchingToggle) {
              matchingToggle.disabled = <?= $seasonLocked ? 'true' : 'false' ?>;
              var matchingLabel = matchingToggle.closest('.sponsor-facebook-toggle');
              if (matchingLabel) matchingLabel.classList.remove('sponsor-facebook-toggle--saving');
            });
          });
      });
    });

    function escapeCsv(value) {
      return '"' + String(value).replace(/"/g, '""').replace(/\s+/g, ' ').trim() + '"';
    }

    function buildTableData() {
      const headers = [];
      $('#sponsorsLedgerTable thead th').each(function () {
        if ($(this).data('export-ignore')) {
          return;
        }
        headers.push($(this).text().trim());
      });

      const rows = [];
      $('#sponsorsLedgerTable tbody tr').each(function () {
        const row = [];
        $(this).find('td').each(function () {
          if ($(this).data('export-ignore')) {
            return;
          }
          row.push($(this).text().replace(/\s+/g, ' ').trim());
        });
        rows.push(row);
      });

      return { headers, rows };
    }

    $('#exportCsv').on('click', function () {
      if (!$('#sponsorsLedgerTable').length) {
        return;
      }

      const data = buildTableData();
      const csvLines = [data.headers.map(escapeCsv).join(',')];

      data.rows.forEach(function (row) {
        csvLines.push(row.map(escapeCsv).join(','));
      });

      const blob = new Blob([csvLines.join('\n')], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = 'sponsors.csv';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);
    });

    $('#exportExcel').on('click', function () {
      if (!$('#sponsorsLedgerTable').length) {
        return;
      }

      const tableHtml = $('#sponsorsLedgerTable').prop('outerHTML');
      const blob = new Blob([
        '<html><head><meta charset="utf-8"></head><body>' + tableHtml + '</body></html>'
      ], { type: 'application/vnd.ms-excel' });

      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = 'sponsors.xls';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);
    });
  });
</script>

<?php
require_once __DIR__ . '/footer.php';
?>
