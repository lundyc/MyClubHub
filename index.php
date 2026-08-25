<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Club overview',
    'title' => 'Club Hub',
    'subtitle' => 'Your starting point for club, sponsorship, fixture, and publishing work.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/player_birthdays.php';
require_once __DIR__ . '/lib/season_tickets.php';
require_once __DIR__ . '/lib/season_passes.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';
require_once __DIR__ . '/lib/announcements.php';
require_once __DIR__ . '/lib/publishing_history.php';

function hub_index_load_json_array(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $json = file_get_contents($path);
    if (!is_string($json) || trim($json) === '') {
        return [];
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function hub_index_format_date(?string $value, string $fallback = '—'): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return $fallback;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('d/m/Y', $timestamp);
}

function hub_index_fixture_title(array $fixture): string
{
    $prefix = !empty($fixture['is_home']) ? 'Home v ' : 'Away v ';
    return $prefix . trim((string) ($fixture['opponent'] ?? ''));
}

function hub_index_ordinal_position(mixed $value): string
{
    $position = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($position === false) {
        return '—';
    }

    $lastTwoDigits = $position % 100;
    $suffix = match (true) {
        $lastTwoDigits >= 11 && $lastTwoDigits <= 13 => 'th',
        $position % 10 === 1 => 'st',
        $position % 10 === 2 => 'nd',
        $position % 10 === 3 => 'rd',
        default => 'th',
    };

    return $position . $suffix;
}

function hub_index_result_outcome(array $fixture): ?array
{
    if (
        ($fixture['full_time_home_score'] ?? null) === null
        || ($fixture['full_time_away_score'] ?? null) === null
    ) {
        return null;
    }

    $homeScore = (int)$fixture['full_time_home_score'];
    $awayScore = (int)$fixture['full_time_away_score'];
    $saltcoatsScore = !empty($fixture['is_home']) ? $homeScore : $awayScore;
    $opponentScore = !empty($fixture['is_home']) ? $awayScore : $homeScore;

    if ($saltcoatsScore > $opponentScore) {
        return ['label' => 'Win', 'class' => 'win'];
    }
    if ($saltcoatsScore < $opponentScore) {
        return ['label' => 'Loss', 'class' => 'loss'];
    }

    return ['label' => 'Draw', 'class' => 'draw'];
}

$playerTotals = [
    'active' => 0,
    'former' => 0,
    'total' => 0,
];

$upcomingFixtures = [];
$recentResults = [];
$nextBirthdays = [];
$fixtureTotals = ['upcoming' => 0, 'played' => 0];
$attentionTotals = ['players_without_sponsors' => 0, 'unpaid_sponsorships' => 0, 'fixtures_missing_details' => 0, 'open_orders' => 0];
$openOrderSeasonId = 0;
$leagueSnapshot = null;
$seasonId = (int) ($seasonContext['season_id'] ?? 0);
$selectedSeason = $seasonContext['season'] ?? null;

try {
    if (isset($pdo)) {
        try {
            // "Played" is the Hub's completed/finished fixture status. Leave
            // postponed and cancelled fixtures unchanged for an admin to review.
            $pdo->exec("
                UPDATE match_fixtures
                SET status = 'played'
                WHERE status = 'scheduled'
                  AND match_date < CURDATE()
            ");
        } catch (Throwable $e) {
            // A status rollover failure should not prevent the dashboard loading.
        }

        $playerTotals['active'] = (int) $pdo->query("SELECT COUNT(*) FROM players WHERE status IN ('current', 'trialist') AND active = 1")->fetchColumn();
        $playerTotals['former'] = (int) $pdo->query("SELECT COUNT(*) FROM players WHERE status NOT IN ('current', 'trialist') OR active = 0")->fetchColumn();
        $playerTotals['total'] = $playerTotals['active'] + $playerTotals['former'];
        $nextBirthdays = players_next_birthdays($pdo, 5);

        $fixtureCountStmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN match_date >= CURDATE() AND status IN ('scheduled', 'postponed') THEN 1 ELSE 0 END) AS upcoming_count,
                SUM(CASE WHEN status = 'played' THEN 1 ELSE 0 END) AS played_count
            FROM match_fixtures
            WHERE season_id = :season_id
        ");
        $fixtureCountStmt->execute([':season_id' => $seasonId]);
        $fixtureCountRow = $fixtureCountStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $fixtureTotals['upcoming'] = (int) ($fixtureCountRow['upcoming_count'] ?? 0);
        $fixtureTotals['played'] = (int) ($fixtureCountRow['played_count'] ?? 0);

        $upcomingStmt = $pdo->prepare("
            SELECT id, match_date, kickoff_time, opponent, is_home, competition, venue, status,
                   half_time_home_score, half_time_away_score,
                   full_time_home_score, full_time_away_score
            FROM match_fixtures
            WHERE season_id = :season_id
              AND match_date >= CURDATE()
              AND status IN ('scheduled', 'postponed')
            ORDER BY match_date ASC, kickoff_time ASC, id ASC
            LIMIT 5
        ");
        $upcomingStmt->execute([':season_id' => $seasonId]);
        $upcomingFixtures = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

        $resultsStmt = $pdo->prepare("
            SELECT id, match_date, kickoff_time, opponent, is_home, competition, venue, status,
                   half_time_home_score, half_time_away_score,
                   full_time_home_score, full_time_away_score
            FROM match_fixtures
            WHERE season_id = :season_id
              AND status = 'played'
            ORDER BY match_date DESC, kickoff_time DESC, id DESC
            LIMIT 5
        ");
        $resultsStmt->execute([':season_id' => $seasonId]);
        $recentResults = $resultsStmt->fetchAll(PDO::FETCH_ASSOC);

        $playersWithoutSponsorsStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM players p
            WHERE p.status IN ('current', 'trialist') AND p.active = 1
              AND NOT EXISTS (
                  SELECT 1 FROM sponsorships s
                  WHERE s.player_id = p.id AND s.season_id = :season_id AND s.ended_at IS NULL
              )
        ");
        $playersWithoutSponsorsStmt->execute([':season_id' => $seasonId]);
        $attentionTotals['players_without_sponsors'] = (int) $playersWithoutSponsorsStmt->fetchColumn();

        $unpaidStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM sponsorships s
            WHERE s.season_id = :season_id AND s.ended_at IS NULL AND s.amount > 0
              AND COALESCE((SELECT SUM(p.amount) FROM sponsorship_payments p WHERE p.sponsorship_id = s.id), 0) < s.amount
        ");
        $unpaidStmt->execute([':season_id' => $seasonId]);
        $attentionTotals['unpaid_sponsorships'] = (int) $unpaidStmt->fetchColumn();

        $missingFixtureStmt = $pdo->prepare("
            SELECT COUNT(*) FROM match_fixtures
            WHERE season_id = :season_id AND match_date >= CURDATE() AND status = 'scheduled'
              AND (opponent IS NULL OR TRIM(opponent) = '' OR kickoff_time IS NULL OR venue IS NULL OR TRIM(venue) = '')
        ");
        $missingFixtureStmt->execute([':season_id' => $seasonId]);
        $attentionTotals['fixtures_missing_details'] = (int) $missingFixtureStmt->fetchColumn();

        // Orders (season tickets, match tickets, and any future product type sold
        // through the same checkout) that were started but never completed or paid.
        $attentionTotals['open_orders'] = (int) $pdo->query("
            SELECT COUNT(*) FROM orders WHERE status IN ('draft', 'pending_payment')
        ")->fetchColumn();

        // The season ticket ledger report is scoped to whichever season is currently
        // selected in the Hub's season switcher, so a link into it only shows these
        // open orders if it also jumps to the season they actually belong to.
        $openOrderSeasonStmt = $pdo->query("
            SELECT sp.season_id
            FROM orders o
            JOIN order_items oi ON oi.order_id = o.id
            JOIN entitlements e ON e.order_item_id = oi.id
            JOIN season_passes sp ON sp.entitlement_id = e.id
            WHERE o.status IN ('draft', 'pending_payment')
            ORDER BY o.created_at DESC
            LIMIT 1
        ");
        $openOrderSeasonId = (int) ($openOrderSeasonStmt->fetchColumn() ?: 0);
    }
} catch (Throwable $e) {
    // Keep dashboard resilient if one of the legacy tables is unavailable.
}

$seasonTicketTotals = ['holders' => 0, 'collected' => 0.0, 'outstanding' => 0.0];
$sponsorshipTotals = ['agreed' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0];
$announcementsPublished = 0;
$publishingCounts = ['draft' => 0, 'queued' => 0, 'published' => 0, 'failed' => 0, 'prepared' => 0];

try {
    if (isset($pdo)) {
        ensureSeasonPassSchema($pdo);
        $holdersStmt = $pdo->prepare("SELECT COUNT(DISTINCT e.person_id)
            FROM season_passes sp
            JOIN entitlements e ON e.id = sp.entitlement_id
            WHERE sp.season_id = :season_id AND e.status <> 'cancelled'");
        $holdersStmt->execute([':season_id' => $seasonId]);
        $seasonTicketTotals['holders'] = (int) $holdersStmt->fetchColumn();

        $ticketMoneyStmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN pay.status = 'paid' THEN o.total_amount ELSE 0 END), 0) AS collected,
                COALESCE(SUM(CASE WHEN pay.status <> 'paid' AND o.status <> 'cancelled' THEN o.total_amount ELSE 0 END), 0) AS outstanding
            FROM season_passes sp
            JOIN entitlements e ON e.id = sp.entitlement_id
            JOIN order_items oi ON oi.id = e.order_item_id
            JOIN orders o ON o.id = oi.order_id
            JOIN payments pay ON pay.order_id = o.id
            WHERE sp.season_id = :season_id
        ");
        $ticketMoneyStmt->execute([':season_id' => $seasonId]);
        $ticketMoneyRow = $ticketMoneyStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $seasonTicketTotals['collected'] = (float) ($ticketMoneyRow['collected'] ?? 0);
        $seasonTicketTotals['outstanding'] = (float) ($ticketMoneyRow['outstanding'] ?? 0);

        ensureSponsorshipCatalogSchema($pdo);
        foreach (getSponsorshipAgreements($pdo, ['season_id' => $seasonId]) as $agreement) {
            if (!empty($agreement['is_complimentary'])) {
                continue;
            }
            $sponsorshipTotals['agreed'] += (float) $agreement['agreed_amount'];
            $sponsorshipTotals['paid'] += (float) $agreement['total_paid'];
        }
        $sponsorshipTotals['outstanding'] = max(0.0, $sponsorshipTotals['agreed'] - $sponsorshipTotals['paid']);

        $announcementsPublished = count(getAnnouncements($pdo, true));
        $publishingCounts = array_merge($publishingCounts, hub_publishing_history_counts($pdo));
    }
} catch (Throwable $e) {
    // Keep dashboard resilient if one of these tables/features is unavailable.
}

$leagueRows = hub_index_load_json_array(__DIR__ . '/cache/wosfl_table.json');
foreach ($leagueRows as $row) {
    if (!is_array($row)) {
        continue;
    }

    if (strcasecmp(trim((string) ($row['club'] ?? '')), 'Saltcoats Victoria') === 0) {
        $leagueSnapshot = $row;
        break;
    }
}
$leaguePosition = hub_index_ordinal_position($leagueSnapshot['pos'] ?? null);
$nextFixture = $upcomingFixtures[0] ?? null;
$attentionCount = array_sum(array_map('intval', $attentionTotals));

// Revenue-by-source chart — only include a source once it has actually collected
// something, so the legend doesn't fill up with permanently-zero slices.
$revenueSources = [
    ['label' => 'Sponsorship', 'value' => round($sponsorshipTotals['paid'], 2), 'color' => '#6a2036'],
    ['label' => 'Season tickets', 'value' => round($seasonTicketTotals['collected'], 2), 'color' => '#b99b61'],
];
$revenueSources = array_values(array_filter($revenueSources, static fn(array $s): bool => $s['value'] > 0));

$formChartData = null;
if ($leagueSnapshot !== null) {
    $formChartData = [
        'labels' => ['Won', 'Drawn', 'Lost'],
        'values' => [(int) ($leagueSnapshot['w'] ?? 0), (int) ($leagueSnapshot['d'] ?? 0), (int) ($leagueSnapshot['l'] ?? 0)],
        'colors' => ['#198754', '#b58105', '#dc3545'],
    ];
}
?>

<div class="hub-index-page">

    <div class="hub-context-bar" role="status">
        <div><i class="fa-solid fa-calendar-days" aria-hidden="true"></i><span>Showing <strong><?= htmlspecialchars((string) ($selectedSeason['name'] ?? 'the selected season'), ENT_QUOTES, 'UTF-8') ?></strong></span><?php if (!empty($selectedSeason['is_locked'])): ?><span class="badge text-bg-secondary">Locked</span><?php endif; ?></div>
        <span>Change season from the navigation menu.</span>
    </div>

    <section class="dashboard-command-centre" aria-labelledby="dashboardCommandTitle">
        <div class="dashboard-command-centre__main">
            <p class="page-kicker mb-1">Today&apos;s focus</p>
            <h2 id="dashboardCommandTitle">Next fixture and open work</h2>
            <?php if ($nextFixture === null): ?>
                <p class="dashboard-command-centre__summary">No upcoming fixtures are recorded for <?= htmlspecialchars((string) ($selectedSeason['name'] ?? 'the selected season'), ENT_QUOTES, 'UTF-8') ?>.</p>
                <div class="dashboard-command-centre__actions">
                    <a href="/match.php?action=new&season_id=<?= (int) $seasonId ?>" class="btn btn-brand"><i class="fa-solid fa-plus" aria-hidden="true"></i>Add fixture</a>
                    <a href="/matches.php?season_id=<?= (int) $seasonId ?>" class="btn btn-neutral">View fixtures</a>
                </div>
            <?php else: ?>
                <a class="dashboard-next-fixture" href="/match.php?id=<?= (int) $nextFixture['id'] ?>&season_id=<?= (int) $seasonId ?>" aria-label="Open <?= htmlspecialchars(hub_index_fixture_title($nextFixture), ENT_QUOTES, 'UTF-8') ?>">
                    <span class="dashboard-next-fixture__date">
                        <strong><?= htmlspecialchars(hub_index_format_date((string) ($nextFixture['match_date'] ?? '')), ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php if (!empty($nextFixture['kickoff_time'])): ?><small><?= htmlspecialchars(substr((string) $nextFixture['kickoff_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?> kick-off</small><?php endif; ?>
                    </span>
                    <span class="dashboard-next-fixture__body">
                        <strong><?= htmlspecialchars(hub_index_fixture_title($nextFixture), ENT_QUOTES, 'UTF-8') ?></strong>
                        <small>
                            <?= htmlspecialchars((string) ($nextFixture['competition'] ?? 'Fixture'), ENT_QUOTES, 'UTF-8') ?>
                            <?php if (!empty($nextFixture['venue'])): ?>&middot; <?= htmlspecialchars((string) $nextFixture['venue'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                        </small>
                    </span>
                    <span class="badge <?= !empty($nextFixture['is_home']) ? 'text-bg-primary' : 'text-bg-secondary' ?>"><?= !empty($nextFixture['is_home']) ? 'Home' : 'Away' ?></span>
                </a>
                <div class="dashboard-command-centre__actions">
                    <a href="/match.php?id=<?= (int) $nextFixture['id'] ?>&season_id=<?= (int) $seasonId ?>" class="btn btn-brand"><i class="fa-solid fa-clipboard-list" aria-hidden="true"></i>Open match</a>
                    <a href="/match_graphics.php?fixture_id=<?= (int) $nextFixture['id'] ?>&season_id=<?= (int) $seasonId ?>" class="btn btn-neutral"><i class="fa-solid fa-image" aria-hidden="true"></i>Graphics</a>
                </div>
            <?php endif; ?>
        </div>

        <aside class="dashboard-command-centre__side" aria-label="Attention summary">
            <div class="dashboard-attention-score">
                <span>Needs attention</span>
                <strong><?= (int) $attentionCount ?></strong>
            </div>
            <div class="dashboard-attention__list dashboard-attention__list--compact">
                <a href="players.php?sponsor_status=none" class="dashboard-attention__item">
                    <span class="dashboard-attention__icon"><i class="fa-solid fa-user-tag" aria-hidden="true"></i></span>
                    <span><strong><?= (int) $attentionTotals['players_without_sponsors'] ?> players without sponsors</strong><small>Review available player packages</small></span>
                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                </a>
                <a href="reports.php?season_id=<?= (int) $seasonId ?>" class="dashboard-attention__item">
                    <span class="dashboard-attention__icon"><i class="fa-solid fa-sterling-sign" aria-hidden="true"></i></span>
                    <span><strong><?= (int) $attentionTotals['unpaid_sponsorships'] ?> unpaid sponsorships</strong><small>Check outstanding balances</small></span>
                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                </a>
                <a href="matches.php?season_id=<?= (int) $seasonId ?>" class="dashboard-attention__item">
                    <span class="dashboard-attention__icon"><i class="fa-solid fa-calendar-xmark" aria-hidden="true"></i></span>
                    <span><strong><?= (int) $attentionTotals['fixtures_missing_details'] ?> fixtures need details</strong><small>Add venue, opponent, or kick-off time</small></span>
                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                </a>
                <a href="reports.php?report_type=season_tickets&status=pending_payment<?= $openOrderSeasonId > 0 ? '&season_id=' . $openOrderSeasonId : '' ?>" class="dashboard-attention__item">
                    <span class="dashboard-attention__icon"><i class="fa-solid fa-cart-shopping" aria-hidden="true"></i></span>
                    <span><strong><?= (int) $attentionTotals['open_orders'] ?> open orders</strong><small>Season tickets, match tickets and other orders awaiting payment</small></span>
                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                </a>
            </div>
        </aside>
    </section>

    <section class="hub-index-section" aria-labelledby="snapshotTitle">
        <div class="hub-index-section__header">
            <p class="page-kicker mb-1">Snapshot</p>
            <h2 id="snapshotTitle" class="h4 mb-0">Where things stand this season</h2>
        </div>

        <?php hub_render_metric_grid([
            ['label' => 'Players', 'value' => (int) $playerTotals['active'], 'meta' => (int) $playerTotals['former'] . ' former / ' . (int) $playerTotals['total'] . ' total', 'icon' => 'fa-users', 'tone' => 'primary', 'href' => 'players.php'],
            ['label' => 'Upcoming fixtures', 'value' => (int) $fixtureTotals['upcoming'], 'meta' => 'Next ' . min(5, count($upcomingFixtures)) . ' shown below', 'icon' => 'fa-calendar-days', 'tone' => 'info', 'href' => 'matches.php?season_id=' . $seasonId],
            ['label' => 'Recent results', 'value' => (int) $fixtureTotals['played'], 'meta' => 'Latest ' . min(5, count($recentResults)) . ' shown below', 'icon' => 'fa-flag-checkered', 'tone' => 'success', 'href' => 'matches.php?season_id=' . $seasonId],
            ['label' => 'League position', 'value' => $leaguePosition, 'meta' => ($leagueSnapshot['pts'] ?? '0') . ' pts, GD ' . ($leagueSnapshot['gd'] ?? '0'), 'icon' => 'fa-ranking-star', 'tone' => 'warning', 'href' => 'league_table.php'],
            ['label' => 'Season tickets', 'value' => (int) $seasonTicketTotals['holders'], 'meta' => gbp($seasonTicketTotals['collected']) . ' collected' . ($seasonTicketTotals['outstanding'] > 0 ? ', ' . gbp($seasonTicketTotals['outstanding']) . ' outstanding' : ''), 'icon' => 'fa-id-card', 'tone' => 'primary', 'href' => 'season_ticket_orders.php'],
            ['label' => 'Sponsorship collected', 'value' => gbp($sponsorshipTotals['paid']), 'meta' => gbp($sponsorshipTotals['outstanding']) . ' outstanding of ' . gbp($sponsorshipTotals['agreed']) . ' agreed', 'icon' => 'fa-sterling-sign', 'tone' => $sponsorshipTotals['outstanding'] > 0 ? 'warning' : 'success', 'href' => 'sponsorship_agreements.php'],
            ['label' => 'Announcements', 'value' => (int) $announcementsPublished, 'meta' => 'Published to members', 'icon' => 'fa-bullhorn', 'tone' => 'info', 'href' => 'announcements.php'],
            ['label' => 'Social posts', 'value' => (int) $publishingCounts['published'], 'meta' => (int) $publishingCounts['failed'] > 0 ? ((int) $publishingCounts['failed'] . ' failed — needs attention') : ((int) $publishingCounts['draft'] . ' drafts waiting'), 'icon' => 'fa-share-nodes', 'tone' => (int) $publishingCounts['failed'] > 0 ? 'danger' : 'neutral', 'href' => 'generate_and_post.php'],
        ], 'Snapshot'); ?>
    </section>

    <section class="hub-section-commandbar" aria-labelledby="quickActionsTitle">
        <div><h2 id="quickActionsTitle">Common actions</h2><p>Create the records used most often across match-day and sponsorship workflows.</p></div>
        <div class="hub-local-actions">
            <a href="/match.php?action=new" class="btn btn-brand btn-sm"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add fixture</a>
            <a href="/player_add.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-user-plus me-1" aria-hidden="true"></i>Add player</a>
            <a href="/sponsor.php?action=new" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-handshake me-1" aria-hidden="true"></i>Add sponsor</a>
            <a href="/sponsorship_agreement.php?action=new" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-file-signature me-1" aria-hidden="true"></i>Add sponsorship agreement</a>
        </div>
    </section>

    <section class="hub-index-section" aria-labelledby="matchDayTitle">
        <div class="hub-index-section__header">
            <p class="page-kicker mb-1">Match day</p>
            <h2 id="matchDayTitle" class="h4 mb-0">Fixtures, results, and the wider picture</h2>
        </div>

        <div class="hub-index-matchday">
            <section class="card shadow-sm border-0 hub-panel hub-index-matchday__fixtures">
                <div class="card-body p-4">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
                        <div>
                            <p class="page-kicker mb-1">Upcoming Fixtures</p>
                            <h3 class="h5 mb-0">What is next on the schedule</h3>
                        </div>
                        <span class="badge text-bg-light">Next <?= (int) count($upcomingFixtures) ?> of <?= (int) $fixtureTotals['upcoming'] ?></span>
                    </div>

                    <?php if ($upcomingFixtures === []): ?>
                        <div class="alert alert-light border mb-0 hub-empty-state">No upcoming fixtures found for the selected season.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($upcomingFixtures as $fixture): ?>
                                <a
                                    class="list-group-item list-group-item-action px-0 py-2"
                                    href="/match.php?id=<?= (int) $fixture['id'] ?>"
                                    aria-label="Open <?= htmlspecialchars(hub_index_fixture_title($fixture), ENT_QUOTES, 'UTF-8') ?>"
                                >
                                        <div class="d-flex flex-column flex-md-row justify-content-between gap-2">
                                            <div>
                                                <div class="fw-semibold"><?= htmlspecialchars(hub_index_fixture_title($fixture), ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small">
                                                    <?= htmlspecialchars(hub_index_format_date((string) ($fixture['match_date'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                                                    <?php if (!empty($fixture['kickoff_time'])): ?>
                                                        &middot; <?= htmlspecialchars(substr((string) $fixture['kickoff_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>
                                                    <?php endif; ?>
                                                    &middot; <?= htmlspecialchars((string) ($fixture['competition'] ?? 'Fixture'), ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            </div>
                                            <div class="text-md-end">
                                                <span class="badge text-bg-light mb-1"><?= !empty($fixture['is_home']) ? 'Home' : 'Away' ?></span>
                                                <div class="small text-muted"><?= htmlspecialchars((string) ($fixture['venue'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                        </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card shadow-sm border-0 hub-panel hub-index-matchday__results">
                <div class="card-body p-4">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
                        <div>
                            <p class="page-kicker mb-1">Recent Results</p>
                            <h3 class="h5 mb-0">Latest played fixtures</h3>
                        </div>
                        <span class="badge text-bg-light">Latest <?= (int) count($recentResults) ?> of <?= (int) $fixtureTotals['played'] ?></span>
                    </div>

                    <?php if ($recentResults === []): ?>
                        <div class="alert alert-light border mb-0 hub-empty-state">No results have been recorded yet.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($recentResults as $fixture): ?>
                                <?php $resultOutcome = hub_index_result_outcome($fixture); ?>
                                <a
                                    class="list-group-item list-group-item-action px-0 py-2"
                                    href="/match.php?id=<?= (int) $fixture['id'] ?>"
                                    aria-label="Open <?= htmlspecialchars(hub_index_fixture_title($fixture), ENT_QUOTES, 'UTF-8') ?>"
                                >
                                        <div class="d-flex flex-column flex-md-row justify-content-between gap-2">
                                            <div>
                                                <div class="fw-semibold"><?= htmlspecialchars(hub_index_fixture_title($fixture), ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted small">
                                                    <?= htmlspecialchars(hub_index_format_date((string) ($fixture['match_date'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                                                    <?php if (!empty($fixture['kickoff_time'])): ?>
                                                        &middot; <?= htmlspecialchars(substr((string) $fixture['kickoff_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>
                                                    <?php endif; ?>
                                                    &middot; <?= htmlspecialchars((string) ($fixture['competition'] ?? 'Fixture'), ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            </div>
                                            <div class="text-md-end">
                                                <?php if ($fixture['full_time_home_score'] !== null && $fixture['full_time_away_score'] !== null): ?>
                                                    <div class="fw-bold fs-5"><?= (int) $fixture['full_time_home_score'] ?>–<?= (int) $fixture['full_time_away_score'] ?></div>
                                                    <?php if ($resultOutcome !== null): ?>
                                                        <div class="hub-index__result hub-index__result--<?= htmlspecialchars($resultOutcome['class'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <span class="hub-index__result-light" aria-hidden="true"></span>
                                                            <?= htmlspecialchars($resultOutcome['label'], ENT_QUOTES, 'UTF-8') ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge text-bg-success mb-1">Played</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card shadow-sm border-0 hub-panel hub-index-matchday__league">
                <div class="card-body p-4">
                    <p class="page-kicker mb-1">League Table</p>
                    <h3 class="h5 mb-3">Current position: <?= htmlspecialchars($leaguePosition, ENT_QUOTES, 'UTF-8') ?></h3>

                    <?php if ($leagueSnapshot === null): ?>
                        <div class="alert alert-light border mb-0 hub-empty-state">No league table cache is available yet.</div>
                    <?php else: ?>
                        <div class="hub-index__league-card mb-4">
                            <div class="hub-index__league-pos"><?= htmlspecialchars((string) ($leagueSnapshot['pos'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars((string) ($leagueSnapshot['club'] ?? 'Saltcoats Victoria'), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="text-muted small">
                                    <?= htmlspecialchars((string) ($leagueSnapshot['p'] ?? '0'), ENT_QUOTES, 'UTF-8') ?> played
                                    &middot; <?= htmlspecialchars((string) ($leagueSnapshot['pts'] ?? '0'), ENT_QUOTES, 'UTF-8') ?> points
                                    &middot; GD <?= htmlspecialchars((string) ($leagueSnapshot['gd'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>
                        </div>
                        <div class="hub-index__fact-list mb-4">
                            <div class="hub-index__fact">
                                <span class="hub-index__fact-label">Won</span>
                                <span class="hub-index__fact-value"><?= htmlspecialchars((string) ($leagueSnapshot['w'] ?? '0'), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="hub-index__fact">
                                <span class="hub-index__fact-label">Drawn</span>
                                <span class="hub-index__fact-value"><?= htmlspecialchars((string) ($leagueSnapshot['d'] ?? '0'), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="hub-index__fact">
                                <span class="hub-index__fact-label">Lost</span>
                                <span class="hub-index__fact-value"><?= htmlspecialchars((string) ($leagueSnapshot['l'] ?? '0'), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </div>
                        <p class="text-muted mb-0">
                            This card pulls from the latest WOSFL table cache and shows where the club sits right now.
                        </p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card shadow-sm border-0 hub-panel dashboard-birthdays hub-index-matchday__birthdays">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                        <div>
                            <p class="page-kicker mb-1">Squad birthdays</p>
                            <h3 class="h5 mb-0">Next birthdays</h3>
                        </div>
                        <a href="/players.php" class="btn btn-outline-secondary btn-sm">Open players</a>
                    </div>
                    <?php if ($nextBirthdays === []): ?>
                        <div class="alert alert-light border mb-0 hub-empty-state">No player dates of birth have been recorded yet.</div>
                    <?php else: ?>
                        <div class="dashboard-birthdays__list">
                            <?php foreach ($nextBirthdays as $birthday): ?>
                                <a class="dashboard-birthdays__item" href="/player_view.php?id=<?= (int) $birthday['id'] ?>">
                                    <span class="dashboard-birthdays__days<?= (int) $birthday['days_until'] === 0 ? ' dashboard-birthdays__days--today' : '' ?>">
                                        <strong><?= (int) $birthday['days_until'] === 0 ? '🎉' : (int) $birthday['days_until'] ?></strong>
                                        <small><?= (int) $birthday['days_until'] === 0 ? 'Today' : ((int) $birthday['days_until'] === 1 ? 'day' : 'days') ?></small>
                                    </span>
                                    <span class="dashboard-birthdays__info">
                                        <span class="dashboard-birthdays__name"><?= h($birthday['name']) ?></span>
                                        <small>Turns <?= (int) $birthday['age_turning'] ?> &middot; <?= h(date('d M', strtotime($birthday['next_birthday']))) ?></small>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($revenueSources !== [] || $formChartData !== null): ?>
            <section class="card shadow-sm border-0 hub-panel hub-index-matchday__charts">
                <div class="card-body p-4">
                    <p class="page-kicker mb-1">Money &amp; form</p>
                    <h3 class="h5 mb-3">Season charts</h3>
                    <div class="dashboard-season-charts dashboard-season-charts--row">
                        <?php if ($revenueSources !== []): ?>
                        <div class="dashboard-season-charts__block">
                            <p class="dashboard-season-charts__label">Revenue collected this season</p>
                            <div class="dashboard-chart-wrap dashboard-chart-wrap--rail"><canvas id="revenueChart" role="img" aria-label="Donut chart of revenue collected by source this season"></canvas></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($formChartData !== null): ?>
                        <div class="dashboard-season-charts__block">
                            <p class="dashboard-season-charts__label">Results so far this season</p>
                            <div class="dashboard-chart-wrap dashboard-chart-wrap--rail"><canvas id="formChart" role="img" aria-label="Donut chart of wins, draws and losses this season"></canvas></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>
        </div>
    </section>
</div>

<link rel="stylesheet" href="/assets/css/index.css">

<?php if ($revenueSources !== [] || $formChartData !== null): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
(() => {
    const brandFont = getComputedStyle(document.body).fontFamily;
    Chart.defaults.font.family = brandFont;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;

    const revenueCanvas = document.getElementById('revenueChart');
    if (revenueCanvas) {
        const data = <?= json_encode($revenueSources, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        new Chart(revenueCanvas, {
            type: 'doughnut',
            data: {
                labels: data.map(d => d.label),
                datasets: [{
                    data: data.map(d => d.value),
                    backgroundColor: data.map(d => d.color),
                    borderWidth: 2,
                    borderColor: '#fffdf9',
                }],
            },
            options: {
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ctx.label + ': £' + Number(ctx.parsed).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                        },
                    },
                },
            },
        });
    }

    const formCanvas = document.getElementById('formChart');
    if (formCanvas) {
        const data = <?= json_encode($formChartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        new Chart(formCanvas, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.values,
                    backgroundColor: data.colors,
                    borderWidth: 2,
                    borderColor: '#fffdf9',
                }],
            },
            options: {
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { position: 'bottom' },
                },
            },
        });
    }
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
