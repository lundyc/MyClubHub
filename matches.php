<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/season.php';

$seasonId = getSelectedSeasonId($pdo);
$season = getSeasonById($pdo, $seasonId);

$pageHero = [
          'eyebrow' => 'Fixture management',
          'title' => 'Match Fixtures',
          'subtitle' => 'Season: ' . ($season['name'] ?? 'Unknown'),
          'actions' => [],
];

require_once __DIR__ . '/header.php';

$fixtures = getMatchFixtures($pdo, $seasonId);

$fixtureCount = count($fixtures);
$activeMatchDay = 0;
$activeMatchBall = 0;
$activeMotm = 0;
$totalDue = 0.0;
$totalPaid = 0.0;
$fixtureCards = [];
foreach ($fixtures as $fixture) {
          $rows = getMatchSponsorshipRows($pdo, (int)$fixture['id']);
          $dayRow = null;
          $ballRow = null;
          $motmRow = null;
          foreach ($rows as $row) {
                    if ((int)($row['is_complimentary'] ?? 0) !== 1) {
                              $totalDue += (float)$row['amount'];
                              $totalPaid += (float)$row['paid_total'];
                    }
                    if ($row['sponsorship_role'] === 'match_day') {
                              $activeMatchDay++;
                              $dayRow = $row;
                    }
                    if ($row['sponsorship_role'] === 'match_ball') {
                              $activeMatchBall++;
                              $ballRow = $row;
                    }
                    if ($row['sponsorship_role'] === 'motm') {
                              $activeMotm++;
                              $motmRow = $row;
                    }
          }
          $fixtureCards[] = [
                    'fixture' => $fixture,
                    'dayRow' => $dayRow,
                    'ballRow' => $ballRow,
                    'motmRow' => $motmRow,
                    'hasSponsor' => $dayRow !== null || $ballRow !== null || $motmRow !== null,
          ];
}
$outstanding = max(0, $totalDue - $totalPaid);

$upcomingFixtureCards = [];
$playedFixtureCards = [];
foreach ($fixtureCards as $item) {
          $fixtureStatusValue = strtolower(trim((string)($item['fixture']['status'] ?? '')));
          if ($fixtureStatusValue === 'played') {
                    $playedFixtureCards[] = $item;
          } else {
                    $upcomingFixtureCards[] = $item;
          }
}
$playedFixtureCards = array_reverse($playedFixtureCards);

$matchSponsorshipStateClass = static function (?array $row): string {
          if ($row === null) {
                    return 'is-available';
          }
          if ((int)($row['is_complimentary'] ?? 0) === 1) {
                    return 'is-complimentary';
          }

          $amount = (float) ($row['amount'] ?? 0);
          $paidTotal = (float) ($row['paid_total'] ?? 0);
          if ($paidTotal <= 0.0001) {
                    return 'is-unpaid';
          }

          return ($paidTotal + 0.0001) >= $amount ? 'is-paid' : 'is-partial';
};

$matchSponsorshipStateIcon = static function (?array $row): string {
          if ($row === null) {
                    return '<i class="fa-solid fa-minus" aria-hidden="true"></i>';
          }
          if ((int)($row['is_complimentary'] ?? 0) === 1) {
                    return '<i class="fa-solid fa-gift" aria-hidden="true"></i>';
          }

          $amount = (float) ($row['amount'] ?? 0);
          $paidTotal = (float) ($row['paid_total'] ?? 0);

          if ($paidTotal <= 0.0001) {
                    return '<i class="fa-solid fa-sterling-sign" aria-hidden="true"></i>';
          }

          return ($paidTotal + 0.0001) >= $amount
                    ? '<i class="fa-solid fa-check" aria-hidden="true"></i>'
                    : '<i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i>';
};

$matchSponsorshipStateLabel = static function (?array $row): string {
          if ($row === null) {
                    return 'Not assigned';
          }
          if ((int)($row['is_complimentary'] ?? 0) === 1) {
                    return 'Complimentary / Free promotion';
          }
          $amount = (float)($row['amount'] ?? 0);
          $paidTotal = (float)($row['paid_total'] ?? 0);
          if ($paidTotal <= 0.0001) {
                    return 'Assigned, not paid';
          }
          return ($paidTotal + 0.0001) >= $amount ? 'Paid' : 'Part paid';
};

$fixtureStatusClass = static function (string $status): string {
          return match (strtolower(trim($status))) {
                    'played', 'finished', 'complete', 'completed' => 'text-bg-success',
                    'live', 'in_progress', 'in progress' => 'text-bg-danger',
                    'postponed', 'delayed' => 'text-bg-warning',
                    'cancelled', 'canceled', 'abandoned' => 'text-bg-dark',
                    'scheduled', 'upcoming' => 'text-bg-primary',
                    default => 'text-bg-secondary',
          };
};
?>

<?php if (isset($_GET['imported'])): ?>
          <div class="alert alert-success">
                    Fixtures imported successfully.
                    <?php if (isset($_GET['created']) || isset($_GET['updated'])): ?>
                              Created <?= (int)($_GET['created'] ?? 0) ?>, updated <?= (int)($_GET['updated'] ?? 0) ?>.
                    <?php endif; ?>
          </div>
<?php endif; ?>

<?php hub_render_metric_grid([
          ['label' => 'Fixtures', 'value' => (int)$fixtureCount, 'meta' => (string)($selectedSeason['name'] ?? 'Selected season'), 'icon' => 'fa-calendar-days', 'tone' => 'primary'],
          ['label' => 'Match day', 'value' => (int)$activeMatchDay, 'meta' => 'Sponsor slots assigned', 'icon' => 'fa-handshake', 'tone' => 'info'],
          ['label' => 'Match ball', 'value' => (int)$activeMatchBall, 'meta' => 'Sponsor slots assigned', 'icon' => 'fa-futbol', 'tone' => 'info'],
          ['label' => 'Player of the match', 'value' => (int)$activeMotm, 'meta' => 'Sponsor slots assigned', 'icon' => 'fa-star', 'tone' => 'warning'],
          ['label' => 'Total due', 'value' => gbp($totalDue), 'meta' => 'Booked sponsorship value', 'icon' => 'fa-sterling-sign', 'tone' => 'primary'],
          ['label' => 'Outstanding', 'value' => gbp($outstanding), 'meta' => 'Still to collect', 'icon' => 'fa-clock', 'tone' => 'danger'],
], 'Fixture summary'); ?>

<div class="fixtures-toolbar hub-toolbar" aria-label="Search and filter fixtures">
          <div class="fixtures-search">
                    <label class="visually-hidden" for="fixtureSearch">Search fixtures</label>
                    <i class="fa-solid fa-magnifying-glass fixtures-search__icon" aria-hidden="true"></i>
                    <input id="fixtureSearch" type="search" class="form-control" placeholder="Search opponent, date or status" autocomplete="off">
                    <button type="button" class="fixtures-search__clear" id="clearFixtureSearch" aria-label="Clear fixture search" title="Clear search" hidden>
                              <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
          </div>

          <div class="fixtures-filter-field">
                    <label for="fixtureVenueFilter">Match type</label>
                    <select id="fixtureVenueFilter" class="form-select form-select-sm">
                              <option value="all">All matches</option>
                              <option value="home">Home</option>
                              <option value="away">Away</option>
                    </select>
          </div>

          <div class="fixtures-filter-field">
                    <label for="fixtureSponsorFilter">Sponsorship</label>
                    <select id="fixtureSponsorFilter" class="form-select form-select-sm">
                              <option value="all">All fixtures</option>
                              <option value="sponsored">Sponsored</option>
                              <option value="unsponsored">Not sponsored</option>
                    </select>
          </div>

          <button type="button" class="btn btn-outline-secondary btn-sm fixtures-reset" id="resetFixtureFilters" hidden>
                    <i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Reset
          </button>

          <div class="hub-toolbar__count fixtures-toolbar__count" aria-live="polite">
                    <strong id="visibleFixtureCount"><?= (int)$fixtureCount ?></strong>
                    <span id="fixtureCountLabel"><?= $fixtureCount === 1 ? 'fixture' : 'fixtures' ?></span>
          </div>
          <div class="hub-local-actions">
                    <a href="match.php?action=new&amp;season_id=<?= (int)$seasonId ?>" class="btn btn-brand btn-sm"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add fixture</a>
                    <div class="dropdown">
                              <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">More</button>
                              <div class="dropdown-menu dropdown-menu-end">
                                        <button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#importFixturesModal"><i class="fa-solid fa-upload" aria-hidden="true"></i>Import fixtures</button>
                                        <a class="dropdown-item" href="monthly_fixtures.php?season_id=<?= (int)$seasonId ?>"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i>Monthly fixtures</a>
                                        <a class="dropdown-item" href="match_fixtures_poster.php?season_id=<?= (int)$seasonId ?>"><i class="fa-solid fa-image" aria-hidden="true"></i>Fixture poster</a>
                                        <a class="dropdown-item" href="seasons.php"><i class="fa-solid fa-coins" aria-hidden="true"></i>Season pricing</a>
                              </div>
                    </div>
          </div>
</div>

<div class="modal fade" id="importFixturesModal" tabindex="-1" aria-labelledby="importFixturesModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                              <div class="modal-header">
                                        <div>
                                                  <h5 class="modal-title mb-1" id="importFixturesModalLabel">Import Fixtures</h5>
                                                  <div class="text-muted small">Upload a CSV or Excel file to create or update fixtures in this season.</div>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                              </div>
                              <div class="modal-body">
                                        <div class="d-flex justify-content-end mb-3">
                                                  <a href="templates/match_fixtures_import_template.csv" class="btn btn-outline-secondary btn-sm" download>
                                                            <i class="fa-solid fa-file-csv me-1"></i>Download Template
                                                  </a>
                                        </div>

                                        <form method="post" action="matches_import.php" enctype="multipart/form-data">
                                                  <?= csrf_field() ?>
                                                  <input type="hidden" name="season_id" value="<?= (int)$seasonId ?>">
                                                  <div class="row g-3 align-items-end">
                                                            <div class="col-lg-8">
                                                                      <label class="form-label">Import file</label>
                                                                      <input type="file" name="import_file" class="form-control" accept=".csv,.xlsx,.xlsm,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                                                                      <div class="form-text">Headers must match the template. Existing fixtures are matched by `fixture_id` or `match_date + opponent`.</div>
                                                            </div>
                                                            <div class="col-lg-4 text-lg-end">
                                                                      <button type="submit" class="btn btn-brand w-100">Import Fixtures</button>
                                                            </div>
                                                            <?php if ((int)($season['is_locked'] ?? 0) === 1): ?>
                                                                      <div class="col-12">
                                                                                <div class="alert alert-warning mb-0">
                                                                                          <div class="form-check">
                                                                                                    <input class="form-check-input" type="checkbox" value="1" name="historical_import" id="historicalImportConfirm" required>
                                                                                                    <label class="form-check-label fw-semibold" for="historicalImportConfirm">I understand this import will add or update historical fixtures in a locked season.</label>
                                                                                          </div>
                                                                                          <div class="small mt-1">The season remains locked for normal editing. Review the spreadsheet carefully before importing.</div>
                                                                                </div>
                                                                      </div>
                                                            <?php endif; ?>
                                                  </div>
                                        </form>
                              </div>
                    </div>
          </div>
</div>

<?php if (!$fixtureCards): ?>
          <div class="card shadow-sm hub-section hub-table-card">
                    <div class="card-body">
                              <div class="alert alert-info mb-0 hub-empty-state">No fixtures recorded for this season.</div>
                    </div>
          </div>
<?php else: ?>
          <?php
          $renderFixtureItems = function (array $cards, string $groupKey, string $mode) use (
                    $seasonId,
                    $matchSponsorshipStateClass,
                    $matchSponsorshipStateIcon,
                    $matchSponsorshipStateLabel,
                    $fixtureStatusClass
          ): void {
                    foreach ($cards as $item) {
                              $fixture = $item['fixture'];
                              $dayRow = $item['dayRow'];
                              $ballRow = $item['ballRow'];
                              $motmRow = $item['motmRow'];
                              $hasSponsor = $item['hasSponsor'];
                              $opponentIsDeleted = isDeletedOpponentLabel((string)($fixture['opponent'] ?? ''));
                              $fixtureSearchText = implode(' ', [
                                        (string)($fixture['opponent'] ?? ''),
                                        (string)($fixture['status'] ?? ''),
                                        (string)($fixture['match_date'] ?? ''),
                                        date('d/m/Y', strtotime((string)$fixture['match_date'])),
                                        !empty($fixture['is_home']) ? 'home' : 'away',
                              ]);
                              if ($mode === 'mobile'):
                              ?>
                                        <a class="fixtures-mobile-card fixtures-mobile-card--link hub-record-card js-fixture-item"
                                                  href="match.php?id=<?= (int)$fixture['id'] ?>&season_id=<?= (int)$seasonId ?>"
                                                  aria-label="Open fixture against <?= h((string)$fixture['opponent']) ?>"
                                                  data-fixture-id="<?= (int)$fixture['id'] ?>"
                                                  data-group="<?= h($groupKey) ?>"
                                                  data-search="<?= h($fixtureSearchText) ?>"
                                                  data-venue="<?= !empty($fixture['is_home']) ? 'home' : 'away' ?>"
                                                  data-sponsored="<?= $hasSponsor ? '1' : '0' ?>">
                                                  <div class="fixtures-mobile-head">
                                                            <div>
                                                                      <div class="fixtures-mobile-venue">
                                                                                <span class="fixtures-venue-icon <?= $fixture['is_home'] ? 'fixtures-venue-icon--home' : 'fixtures-venue-icon--away' ?>">
                                                                                          <i class="fa-solid <?= $fixture['is_home'] ? 'fa-house' : 'fa-bus' ?>"></i>
                                                                                </span>
                                                                                <span class="fixtures-venue-text"><?= h($fixture['is_home'] ? 'Home fixture' : 'Away fixture') ?></span>
                                                                      </div>
                                                                      <div class="fixtures-mobile-opponent<?= $opponentIsDeleted ? ' is-deleted' : '' ?>">
                                                                                <?php if ($opponentIsDeleted): ?>
                                                                                          <span class="fixtures-opponent-deleted">Team Deleted</span>
                                                                                <?php else: ?>
                                                                                          <?= h($fixture['opponent']) ?>
                                                                                <?php endif; ?>
                                                                      </div>
                                                                      <div class="fixtures-mobile-meta">
                                                                                <span class="badge <?= h($fixtureStatusClass((string)$fixture['status'])) ?>"><?= h(ucfirst((string)$fixture['status'])) ?></span>
                                                                                <span class="badge bg-light text-dark"><?= h(date('d/m/Y', strtotime((string)$fixture['match_date']))) ?></span>
                                                                                <?php if (!empty($fixture['kickoff_time'])): ?>
                                                                                          <span class="badge bg-light text-dark"><?= h(substr((string)$fixture['kickoff_time'], 0, 5)) ?></span>
                                                                                <?php endif; ?>
                                                                      </div>
                                                            </div>
                                                            <div class="badge bg-brand-primary align-self-start"><?= h($fixture['is_home'] ? 'Home' : 'Away') ?></div>
                                                  </div>

                                                  <div class="fixtures-mobile-summary">
                                                            <div class="fixtures-mobile-panel">
                                                                      <span class="label">Match Day</span>
                                                                      <span class="value">
                                                                                <span class="fixtures-sponsorship-status is-compact <?= h($matchSponsorshipStateClass($dayRow)) ?>" title="<?= h($matchSponsorshipStateLabel($dayRow)) ?>" aria-label="<?= h($matchSponsorshipStateLabel($dayRow)) ?>">
                                                                                          <?= $matchSponsorshipStateIcon($dayRow) ?>
                                                                                </span>
                                                                      </span>
                                                            </div>
                                                            <div class="fixtures-mobile-panel">
                                                                      <span class="label">Match Ball</span>
                                                                      <span class="value">
                                                                                <span class="fixtures-sponsorship-status is-compact <?= h($matchSponsorshipStateClass($ballRow)) ?>" title="<?= h($matchSponsorshipStateLabel($ballRow)) ?>" aria-label="<?= h($matchSponsorshipStateLabel($ballRow)) ?>">
                                                                                          <?= $matchSponsorshipStateIcon($ballRow) ?>
                                                                                </span>
                                                                      </span>
                                                            </div>
                                                            <div class="fixtures-mobile-panel">
                                                                      <span class="label">MOTM</span>
                                                                      <span class="value">
                                                                                <span class="fixtures-sponsorship-status is-compact <?= h($matchSponsorshipStateClass($motmRow)) ?>" title="<?= h($matchSponsorshipStateLabel($motmRow)) ?>" aria-label="<?= h($matchSponsorshipStateLabel($motmRow)) ?>">
                                                                                          <?= $matchSponsorshipStateIcon($motmRow) ?>
                                                                                </span>
                                                                      </span>
                                                            </div>
                                                  </div>
                                        </a>
                              <?php else: ?>
                                        <tr class="fixture-row js-fixture-item"
                                                  role="link"
                                                  tabindex="0"
                                                  aria-label="Open fixture against <?= h((string)$fixture['opponent']) ?>"
                                                  data-href="match.php?id=<?= (int)$fixture['id'] ?>&amp;season_id=<?= (int)$seasonId ?>"
                                                  data-fixture-id="<?= (int)$fixture['id'] ?>"
                                                  data-group="<?= h($groupKey) ?>"
                                                  data-search="<?= h($fixtureSearchText) ?>"
                                                  data-venue="<?= !empty($fixture['is_home']) ? 'home' : 'away' ?>"
                                                  data-sponsored="<?= $hasSponsor ? '1' : '0' ?>">
                                                  <td class="text-center">
                                                            <span class="fixtures-venue-icon <?= $fixture['is_home'] ? 'fixtures-venue-icon--home' : 'fixtures-venue-icon--away' ?>" title="<?= h($fixture['is_home'] ? 'Home fixture' : 'Away fixture') ?>" aria-label="<?= h($fixture['is_home'] ? 'Home fixture' : 'Away fixture') ?>">
                                                                      <i class="fa-solid <?= $fixture['is_home'] ? 'fa-house' : 'fa-bus' ?>"></i>
                                                            </span>
                                                  </td>
                                                  <td><?= h(date('d/m/Y', strtotime((string)$fixture['match_date']))) ?><?php if (!empty($fixture['kickoff_time'])): ?><div class="small text-muted"><?= h(substr((string)$fixture['kickoff_time'], 0, 5)) ?></div><?php endif; ?></td>
                                                  <td>
                                                            <?php if ($opponentIsDeleted): ?>
                                                                      <span class="fixtures-opponent-deleted">Team Deleted</span>
                                                            <?php else: ?>
                                                                      <span class="fw-semibold"><?= h($fixture['opponent']) ?></span>
                                                            <?php endif; ?>
                                                  </td>
                                                  <td><span class="badge <?= h($fixtureStatusClass((string)$fixture['status'])) ?>"><?= h(ucfirst((string)$fixture['status'])) ?></span></td>
                                                  <td class="text-center fixtures-sponsor-col">
                                                            <span class="fixtures-sponsorship-status is-compact <?= h($matchSponsorshipStateClass($dayRow)) ?>" title="<?= h($matchSponsorshipStateLabel($dayRow)) ?>" aria-label="<?= h($matchSponsorshipStateLabel($dayRow)) ?>">
                                                                      <?= $matchSponsorshipStateIcon($dayRow) ?>
                                                            </span>
                                                  </td>
                                                  <td class="text-center fixtures-sponsor-col">
                                                            <span class="fixtures-sponsorship-status is-compact <?= h($matchSponsorshipStateClass($ballRow)) ?>" title="<?= h($matchSponsorshipStateLabel($ballRow)) ?>" aria-label="<?= h($matchSponsorshipStateLabel($ballRow)) ?>">
                                                                      <?= $matchSponsorshipStateIcon($ballRow) ?>
                                                            </span>
                                                  </td>
                                                  <td class="text-center fixtures-sponsor-col">
                                                            <span class="fixtures-sponsorship-status is-compact <?= h($matchSponsorshipStateClass($motmRow)) ?>" title="<?= h($matchSponsorshipStateLabel($motmRow)) ?>" aria-label="<?= h($matchSponsorshipStateLabel($motmRow)) ?>">
                                                                      <?= $matchSponsorshipStateIcon($motmRow) ?>
                                                            </span>
                                                  </td>
                                        </tr>
                              <?php endif;
                    }
          };

          $fixtureGroups = [
                    ['key' => 'upcoming', 'label' => 'Upcoming Fixtures', 'cards' => $upcomingFixtureCards, 'emptyAll' => 'No upcoming fixtures scheduled for this season.', 'badgeTone' => 'primary'],
                    ['key' => 'played', 'label' => 'Played Fixtures', 'cards' => $playedFixtureCards, 'emptyAll' => 'No fixtures have been played yet this season.', 'badgeTone' => 'secondary'],
          ];
          ?>

          <div class="fixtures-status-legend" aria-label="Sponsorship payment status key">
                    <span><span class="fixtures-sponsorship-status is-available is-compact"><i class="fa-solid fa-minus" aria-hidden="true"></i></span>Available</span>
                    <span><span class="fixtures-sponsorship-status is-unpaid is-compact"><i class="fa-solid fa-sterling-sign" aria-hidden="true"></i></span>Unpaid</span>
                    <span><span class="fixtures-sponsorship-status is-partial is-compact"><i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i></span>Part paid</span>
                    <span><span class="fixtures-sponsorship-status is-paid is-compact"><i class="fa-solid fa-check" aria-hidden="true"></i></span>Paid</span>
                    <span><span class="fixtures-sponsorship-status is-complimentary is-compact"><i class="fa-solid fa-gift" aria-hidden="true"></i></span>Complimentary</span>
          </div>

          <?php foreach ($fixtureGroups as $group): ?>
                    <div class="card shadow-sm hub-section hub-table-card mb-4">
                              <div class="card-header d-flex align-items-center justify-content-between gap-2">
                                        <h2 class="h5 mb-0"><?= h($group['label']) ?></h2>
                                        <span class="badge text-bg-<?= h($group['badgeTone']) ?>"><?= count($group['cards']) ?></span>
                              </div>
                              <div class="card-body">
                                        <?php if (!$group['cards']): ?>
                                                  <div class="alert alert-info mb-0 hub-empty-state"><?= h($group['emptyAll']) ?></div>
                                        <?php else: ?>
                                                  <div class="d-xl-none d-flex flex-column gap-3 mb-3" id="fixturesMobileList-<?= h($group['key']) ?>">
                                                            <?php $renderFixtureItems($group['cards'], $group['key'], 'mobile'); ?>
                                                  </div>

                                                  <div class="d-none d-xl-block">
                                                  <div class="table-responsive">
                                                            <table class="table table-striped hub-data-table align-middle">
                                                                      <thead>
                                                                                <tr>
                                                                                          <th>Venue</th>
                                                                                          <th>Date</th>
                                                                                          <th>Opponent</th>
                                                                                          <th>Status</th>
                                                                                          <th class="fixtures-sponsor-col">Match Day</th>
                                                                                          <th class="fixtures-sponsor-col">Match Ball</th>
                                                                                          <th class="fixtures-sponsor-col">MOTM</th>
                                                                                </tr>
                                                                      </thead>
                                                                      <tbody id="fixturesDesktopBody-<?= h($group['key']) ?>">
                                                                                <?php $renderFixtureItems($group['cards'], $group['key'], 'desktop'); ?>
                                                                      </tbody>
                                                            </table>
                                                  </div>
                                                  </div>

                                                  <div class="fixtures-filter-empty hub-empty-state" id="fixtureFilterEmpty-<?= h($group['key']) ?>" hidden>
                                                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                                                            <strong>No fixtures match your filters</strong>
                                                            <span>Try changing your search or filter selection.</span>
                                                  </div>

                                                  <nav class="fixtures-pagination mt-3" aria-label="<?= h($group['label']) ?> pages" id="fixturesPagination-<?= h($group['key']) ?>" hidden>
                                                            <ul class="pagination pagination-sm justify-content-center mb-0"></ul>
                                                  </nav>
                                        <?php endif; ?>
                              </div>
                    </div>
          <?php endforeach; ?>
<?php endif; ?>

<script>
(function () {
          var PAGE_SIZE = 5;
          var GROUPS = ['upcoming', 'played'];
          var pageState = { upcoming: 1, played: 1 };

          var searchInput = document.getElementById('fixtureSearch');
          var clearSearch = document.getElementById('clearFixtureSearch');
          var venueFilter = document.getElementById('fixtureVenueFilter');
          var sponsorFilter = document.getElementById('fixtureSponsorFilter');
          var resetButton = document.getElementById('resetFixtureFilters');
          var visibleCount = document.getElementById('visibleFixtureCount');
          var countLabel = document.getElementById('fixtureCountLabel');

          if (!searchInput || !venueFilter || !sponsorFilter) {
                    return;
          }

          function normalize(value) {
                    return String(value || '').toLocaleLowerCase().trim();
          }

          var fixtureMeta = {};
          document.querySelectorAll('.js-fixture-item').forEach(function (item) {
                    var id = item.dataset.fixtureId;
                    if (!fixtureMeta[id]) {
                              fixtureMeta[id] = {
                                        search: normalize(item.dataset.search),
                                        venue: item.dataset.venue,
                                        sponsored: item.dataset.sponsored
                              };
                    }
          });

          var groupOrder = {};
          GROUPS.forEach(function (group) {
                    var body = document.getElementById('fixturesDesktopBody-' + group);
                    groupOrder[group] = body
                              ? Array.prototype.map.call(body.querySelectorAll('.js-fixture-item'), function (el) { return el.dataset.fixtureId; })
                              : [];
          });

          function matchingIds(group) {
                    var query = normalize(searchInput.value);
                    var venue = venueFilter.value;
                    var sponsorship = sponsorFilter.value;

                    return groupOrder[group].filter(function (id) {
                              var meta = fixtureMeta[id];
                              if (!meta) return false;
                              var matchesSearch = !query || meta.search.includes(query);
                              var matchesVenue = venue === 'all' || meta.venue === venue;
                              var matchesSponsorship = sponsorship === 'all'
                                        || (sponsorship === 'sponsored' && meta.sponsored === '1')
                                        || (sponsorship === 'unsponsored' && meta.sponsored === '0');
                              return matchesSearch && matchesVenue && matchesSponsorship;
                    });
          }

          function renderPagination(group, totalPages) {
                    var nav = document.getElementById('fixturesPagination-' + group);
                    if (!nav) return;
                    var list = nav.querySelector('ul');
                    list.innerHTML = '';

                    if (totalPages <= 1) {
                              nav.hidden = true;
                              return;
                    }
                    nav.hidden = false;

                    var current = pageState[group];

                    function addItem(label, page, disabled, active) {
                              var li = document.createElement('li');
                              li.className = 'page-item' + (active ? ' active' : '') + (disabled ? ' disabled' : '');
                              var btn = document.createElement('button');
                              btn.type = 'button';
                              btn.className = 'page-link';
                              btn.textContent = label;
                              if (active) btn.setAttribute('aria-current', 'page');
                              if (!disabled && !active) {
                                        btn.addEventListener('click', function () {
                                                  pageState[group] = page;
                                                  renderGroup(group);
                                        });
                              } else if (disabled) {
                                        btn.disabled = true;
                              }
                              li.appendChild(btn);
                              list.appendChild(li);
                    }

                    addItem('Prev', current - 1, current === 1, false);
                    for (var i = 1; i <= totalPages; i++) {
                              addItem(String(i), i, false, i === current);
                    }
                    addItem('Next', current + 1, current === totalPages, false);
          }

          function renderGroup(group) {
                    var ids = matchingIds(group);
                    var totalPages = Math.max(1, Math.ceil(ids.length / PAGE_SIZE));
                    if (pageState[group] > totalPages) pageState[group] = totalPages;
                    if (pageState[group] < 1) pageState[group] = 1;

                    var start = (pageState[group] - 1) * PAGE_SIZE;
                    var pageIds = {};
                    ids.slice(start, start + PAGE_SIZE).forEach(function (id) { pageIds[id] = true; });

                    document.querySelectorAll('.js-fixture-item[data-group="' + group + '"]').forEach(function (item) {
                              item.hidden = !pageIds[item.dataset.fixtureId];
                    });

                    var emptyState = document.getElementById('fixtureFilterEmpty-' + group);
                    if (emptyState) emptyState.hidden = ids.length > 0 || groupOrder[group].length === 0;

                    renderPagination(group, totalPages);

                    return ids.length;
          }

          function applyFixtureFilters() {
                    var total = 0;
                    GROUPS.forEach(function (group) { total += renderGroup(group); });

                    var query = searchInput.value;
                    var filtersActive = query !== '' || venueFilter.value !== 'all' || sponsorFilter.value !== 'all';

                    if (visibleCount) visibleCount.textContent = String(total);
                    if (countLabel) countLabel.textContent = total === 1 ? 'fixture' : 'fixtures';
                    if (clearSearch) clearSearch.hidden = query === '';
                    if (resetButton) resetButton.hidden = !filtersActive;
          }

          function onFilterChange() {
                    pageState.upcoming = 1;
                    pageState.played = 1;
                    applyFixtureFilters();
          }

          searchInput.addEventListener('input', onFilterChange);
          venueFilter.addEventListener('change', onFilterChange);
          sponsorFilter.addEventListener('change', onFilterChange);
          clearSearch.addEventListener('click', function () {
                    searchInput.value = '';
                    searchInput.focus();
                    onFilterChange();
          });
          resetButton.addEventListener('click', function () {
                    searchInput.value = '';
                    venueFilter.value = 'all';
                    sponsorFilter.value = 'all';
                    onFilterChange();
                    searchInput.focus();
          });

          document.querySelectorAll('.fixture-row[data-href]').forEach(function (row) {
                    function openFixture() {
                              window.location.assign(row.dataset.href);
                    }

                    row.addEventListener('click', function (event) {
                              if (event.target.closest('a, button, input, select, textarea, label')) return;
                              openFixture();
                    });
                    row.addEventListener('keydown', function (event) {
                              if (event.key !== 'Enter' && event.key !== ' ') return;
                              event.preventDefault();
                              openFixture();
                    });
          });

          applyFixtureFilters();
}());
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
