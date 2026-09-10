<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/stats.php';
require_once __DIR__ . '/lib/player_match_stats.php';
$pageHero = ['eyebrow' => 'Matchday', 'title' => 'Stats', 'subtitle' => 'Team performance, player contributions and match breakdowns.', 'actions' => []];
require __DIR__ . '/header.php';
$seasonId = (int)$seasonContext['season_id'];
$tab = is_string($_GET['tab'] ?? null) && in_array($_GET['tab'], ['team', 'players', 'matches'], true) ? $_GET['tab'] : 'team';
$competition = is_string($_GET['competition'] ?? null) ? $_GET['competition'] : '';
$venue = is_string($_GET['venue'] ?? null) && in_array($_GET['venue'], ['home', 'away'], true) ? $_GET['venue'] : '';
$stmt = $pdo->prepare('SELECT * FROM match_fixtures WHERE season_id = ? AND status = ? ORDER BY match_date ASC, kickoff_time ASC, id ASC');
$stmt->execute([$seasonId, 'played']);
$allFixtures = $stmt->fetchAll(PDO::FETCH_ASSOC);
$competitions = array_values(array_unique(array_filter(array_column($allFixtures, 'competition'))));
sort($competitions);
if (!in_array($competition, $competitions, true)) $competition = '';
$fixtures = array_values(array_filter($allFixtures, static fn(array $f): bool => ($competition === '' || $f['competition'] === $competition) && ($venue === '' || (int)$f['is_home'] === ($venue === 'home' ? 1 : 0))));
$eventsByFixture = [];
$dataFile = __DIR__ . '/data/matches.json';
$eventsAvailable = false;
if (is_readable($dataFile)) {
    $records = json_decode((string)file_get_contents($dataFile), true);
    $eventsAvailable = is_array($records);
    foreach (is_array($records) ? $records : [] as $record) {
        if (is_array($record) && isset($record['id'])) $eventsByFixture[(int)$record['id']] = array_values(array_filter(is_array($record['events'] ?? null) ? $record['events'] : [], 'is_array'));
    }
}
$summary = hub_stats_summary($fixtures);
$url = static fn(array $changes): string => '/admin/stats.php?' . http_build_query(array_merge(['season_id' => $seasonId, 'tab' => $tab, 'competition' => $competition, 'venue' => $venue], $changes));
$esc = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$badge = static fn(string $result): string => ['W' => 'success', 'D' => 'secondary', 'L' => 'danger'][$result] ?? 'secondary';
?>
<div class="container-fluid py-3">
    <nav class="nav nav-tabs mb-4" aria-label="Statistics sections">
        <?php foreach (['team' => 'Team stats', 'players' => 'Player Stats', 'matches' => 'Match Stats'] as $key => $label): ?>
        <a class="nav-link <?= $tab === $key ? 'active' : '' ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?> href="<?= $esc($url(['tab' => $key])) ?>"><?= $esc($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <form method="get" class="card card-body shadow-sm mb-4">
        <input type="hidden" name="tab" value="<?= $esc($tab) ?>">
        <div class="row g-3 align-items-end">
            <div class="col-md-4"><label for="stats-season" class="form-label">Season</label><select class="form-select" id="stats-season" name="season_id"><?php foreach ($seasonContext['seasons'] as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $seasonId ? 'selected' : '' ?>><?= $esc($s['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label for="stats-competition" class="form-label">Competition</label><select class="form-select" id="stats-competition" name="competition"><option value="">All competitions</option><?php foreach ($competitions as $c): ?><option <?= $competition === $c ? 'selected' : '' ?> value="<?= $esc($c) ?>"><?= $esc($c) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label for="stats-venue" class="form-label">Venue</label><select class="form-select" id="stats-venue" name="venue"><option value="">Home &amp; away</option><option value="home" <?= $venue === 'home' ? 'selected' : '' ?>>Home</option><option value="away" <?= $venue === 'away' ? 'selected' : '' ?>>Away</option></select></div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Apply filters</button></div>
        </div>
    </form>
    <?php if (!$eventsAvailable): ?><div class="alert alert-warning">Match events are currently unavailable. Player goals and cards may be incomplete.</div><?php endif; ?>
    <?php if (!$fixtures): ?>
        <div class="card card-body text-center py-5"><h2 class="h5">No played matches found</h2><p class="text-muted mb-0">Choose another season or filter, or record a result in Match day to start building your stats.</p></div>
    <?php elseif ($tab === 'team'): ?>
        <div class="row g-3 mb-4">
        <?php
        $metricCards = [
            ['Matches played' => $summary['played']],
            ['Wins' => $summary['wins'], 'Draws' => $summary['draws'], 'Losses' => $summary['losses']],
            ['Win rate' => $summary['scored'] ? round(100 * $summary['wins'] / $summary['scored']) . '%' : '—'],
            ['Goals for' => $summary['goals_for'], 'Goals against' => $summary['goals_against'], 'Goal difference' => $summary['goals_for'] - $summary['goals_against']],
            ['Clean sheets' => $summary['clean_sheets']],
            ['Goals per game' => $summary['scored'] ? number_format($summary['goals_for'] / $summary['scored'], 2) : '—'],
        ];
        ?>
        <?php foreach ($metricCards as $metrics): ?>
            <div class="<?= count($metrics) > 1 ? 'col-12 col-lg-6' : 'col-6 col-lg-3' ?>">
                <div class="card card-body h-100 shadow-sm">
                    <div class="row g-0">
                        <?php foreach ($metrics as $label => $value): ?>
                            <div class="<?= count($metrics) > 1 ? 'col-4 text-center px-2' : 'col-12' ?>">
                                <span class="text-muted small d-block"><?= $esc($label) ?></span>
                                <strong class="fs-2"><?= $esc($value) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php if ($summary['played'] > $summary['scored']): ?><div class="alert alert-info"><?= $summary['played'] - $summary['scored'] ?> played match(es) have no full-time score. Result metrics exclude them.</div><?php endif; ?>
        <section class="card card-body shadow-sm mb-4"><h2 class="h5">Recent form</h2><p class="text-muted small">Last five played matches, oldest to newest. Select a result for the match breakdown.</p><div class="d-flex flex-wrap gap-2"><?php foreach (array_slice($fixtures, -5) as $f): $r = hub_stats_result($f); ?><a class="btn btn-outline-<?= $badge($r['result'] ?? '') ?>" href="<?= $esc($url(['tab' => 'matches', 'match_id' => $f['id']])) ?>" title="<?= $esc($f['opponent']) ?>"><?= $esc($r['result'] ?? '—') ?><span class="ms-2 small"><?= $esc($f['opponent']) ?></span></a><?php endforeach; ?></div></section>
        <section class="card shadow-sm"><div class="card-body"><h2 class="h5">Home &amp; away performance</h2></div><div class="table-responsive"><table class="table mb-0"><thead><tr><?php foreach (['Venue', 'Played', 'Won', 'Drawn', 'Lost', 'GF', 'GA', 'Clean sheets'] as $label): ?><th scope="col"><?= $label ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ([1 => 'Home', 0 => 'Away'] as $home => $label): $s = hub_stats_summary(array_filter($fixtures, static fn(array $f): bool => (int)$f['is_home'] === $home)); ?><tr><th scope="row"><?= $label ?></th><?php foreach (['played', 'wins', 'draws', 'losses', 'goals_for', 'goals_against', 'clean_sheets'] as $k): ?><td><?= $s[$k] ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div></section>
    <?php elseif ($tab === 'players'): ?>
        <?php
        // Include names in historic lineups/events even when their player record has since left the club.
        $names = [];
        foreach ($fixtures as $f) {
            foreach (['starting11_starters_json', 'starting11_substitutes_json'] as $field) {
                $lineup = json_decode((string)($f[$field] ?? '[]'), true);
                foreach (is_array($lineup) ? $lineup : [] as $name) if (is_string($name) && trim($name) !== '') $names[hub_player_stats_normalize_name($name)] = $name;
            }
            foreach ($eventsByFixture[(int)$f['id']] ?? [] as $event) {
                if (hub_player_stats_is_club_player_event($event) && trim((string)($event['player'] ?? '')) !== '') $names[hub_player_stats_normalize_name((string)$event['player'])] = (string)$event['player'];
            }
        }
        $players = [];
        foreach ($names as $name) $players[] = ['name' => $name] + hub_player_match_stats($pdo, $seasonId, $name, $dataFile, $fixtures, $eventsByFixture);
        $sorts = ['name' => 'Player', 'appearances' => 'Apps', 'starts' => 'Starts', 'substitute_appearances' => 'Sub apps', 'minutes_played' => 'Minutes', 'goals' => 'Goals', 'yellow_cards' => 'Yellow cards', 'red_cards' => 'Red cards', 'clean_sheets' => 'Clean sheets'];
        $sort = is_string($_GET['sort'] ?? null) && isset($sorts[$_GET['sort']]) ? $_GET['sort'] : 'goals';
        $direction = is_string($_GET['direction'] ?? null) && in_array($_GET['direction'], ['asc', 'desc'], true)
            ? $_GET['direction'] : ($sort === 'name' ? 'asc' : 'desc');
        usort($players, static function (array $a, array $b) use ($sort, $direction): int {
            // Keep the displayed em dashes below goalkeeper clean-sheet values.
            if ($sort === 'clean_sheets' && $a['is_goalkeeper'] !== $b['is_goalkeeper']) {
                return $a['is_goalkeeper'] ? -1 : 1;
            }
            $comparison = $sort === 'name' ? strcasecmp($a['name'], $b['name']) : ($a[$sort] <=> $b[$sort]);
            return ($direction === 'asc' ? $comparison : -$comparison) ?: strcasecmp($a['name'], $b['name']);
        });
        ?>
        <section class="card shadow-sm"><div class="card-body"><h2 class="h5">Player contributions</h2><p class="text-muted small">From recorded lineups and match events. Minutes are estimated over 90 minutes; unused substitutes do not count as appearances. Clean sheets apply to the starting goalkeeper.</p><p class="text-muted small mb-0">Click a column heading to sort; click again to reverse the order.</p></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><?php foreach ($sorts as $key => $label):
            $activeSort = $sort === $key;
            $nextDirection = $activeSort ? ($direction === 'asc' ? 'desc' : 'asc') : ($key === 'name' ? 'asc' : 'desc');
        ?><th scope="col" class="text-nowrap"<?= $activeSort ? ' aria-sort="' . ($direction === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>><a class="d-block text-reset text-decoration-none" href="<?= $esc($url(['sort' => $key, 'direction' => $nextDirection])) ?>" aria-label="<?= $esc('Sort by ' . $label . ', ' . ($nextDirection === 'asc' ? 'ascending' : 'descending')) ?>"><?= $esc($label) ?> <span aria-hidden="true" class="<?= $activeSort ? '' : 'text-muted' ?>"><?= $activeSort ? ($direction === 'asc' ? '↑' : '↓') : '↕' ?></span></a></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($players as $player): ?><tr><th scope="row"><?= $esc($player['name']) ?></th><?php foreach (['appearances', 'starts', 'substitute_appearances', 'minutes_played', 'goals', 'yellow_cards', 'red_cards', 'clean_sheets'] as $key): ?><td><?= $key === 'clean_sheets' && !$player['is_goalkeeper'] ? '—' : (int)$player[$key] ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        <?php if (!$players): ?><tr><td colspan="9" class="text-muted text-center py-4">No player lineups or events recorded for these matches yet.</td></tr><?php endif; ?>
        </tbody></table></div></section>
    <?php else: ?>
        <section class="card shadow-sm mb-4"><div class="card-body"><h2 class="h5">Match results</h2><p class="text-muted small mb-0">Scores are shown as home – away. Results are from your club's perspective.</p></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><?php foreach (['Date', 'Opponent', 'Venue', 'Competition', 'Half time', 'Full time', 'Result', 'Details'] as $label): ?><th scope="col" class="text-nowrap"><?= $label ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach (array_reverse($fixtures) as $f): $r = hub_stats_result($f); ?><tr><td class="text-nowrap"><?= $esc(date('j M Y', strtotime($f['match_date']))) ?></td><th scope="row"><?= $esc($f['opponent']) ?></th><td><?= (int)$f['is_home'] === 1 ? 'Home' : 'Away' ?></td><td><?= $esc($f['competition'] ?: '—') ?></td><?php foreach (['half_time', 'full_time'] as $period): ?><td class="text-nowrap"><?= isset($f[$period . '_home_score'], $f[$period . '_away_score']) ? (int)$f[$period . '_home_score'] . ' – ' . (int)$f[$period . '_away_score'] : '—' ?></td><?php endforeach; ?><td><span class="badge text-bg-<?= $badge($r['result'] ?? '') ?>"><?= $esc($r['result'] ?? 'No score') ?></span></td><td><a href="<?= $esc($url(['match_id' => $f['id']])) ?>#match-breakdown" class="btn btn-outline-primary btn-sm">Breakdown</a></td></tr><?php endforeach; ?>
        </tbody></table></div></section>
        <?php
        $selected = null;
        $matchId = (int)($_GET['match_id'] ?? 0);
        foreach ($fixtures as $f) if ((int)$f['id'] === $matchId) $selected = $f;
        if (!$selected) $selected = $fixtures[count($fixtures) - 1];
        $events = $eventsByFixture[(int)$selected['id']] ?? [];
        usort($events, static fn(array $a, array $b): int => (int)($a['sequence'] ?? 0) <=> (int)($b['sequence'] ?? 0));
        ?>
        <section class="card shadow-sm" id="match-breakdown"><div class="card-body"><div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h2 class="h5">Match breakdown: <?= $esc($selected['opponent']) ?></h2><span class="text-muted small"><?= $esc(date('j M Y', strtotime($selected['match_date']))) ?></span></div><a class="btn btn-outline-primary align-self-start" href="/admin/match.php?id=<?= (int)$selected['id'] ?>&amp;season_id=<?= $seasonId ?>">Open match</a></div>
        <h3 class="h6">Match timeline</h3>
        <?php if (!$events): ?><p class="text-muted">No match events recorded yet.</p><?php else: ?><ol class="list-group list-group-numbered mb-4"><?php foreach ($events as $event): ?><li class="list-group-item"><strong><?= $esc(($event['minute'] ?? '') !== '' ? $event['minute'] . "′ · " : '') ?><?= $esc(ucwords(str_replace('_', ' ', (string)($event['type'] ?? 'Event')))) ?></strong> <span class="text-muted"><?= $esc(($event['team'] ?? '') === 'opponent' ? $selected['opponent'] : (($event['team'] ?? '') === 'svfc' ? 'Our team' : '')) ?></span> <?= $esc($event['player'] ?? '') ?><?php if (!empty($event['secondary_player'])): ?> → <?= $esc($event['secondary_player']) ?><?php endif; ?><?php foreach (is_array($event['substitutions'] ?? null) ? $event['substitutions'] : [] as $change): if (!is_array($change)) continue; ?> <span class="d-block small">Off: <?= $esc($change['off'] ?? '') ?> · On: <?= $esc($change['on'] ?? '') ?></span><?php endforeach; ?><?php if (!empty($event['outcome'])): ?> · <?= $esc(ucfirst((string)$event['outcome'])) ?><?php endif; ?></li><?php endforeach; ?></ol><?php endif; ?>
        <div class="row g-3"><?php foreach (['starting11_starters_json' => 'Starting XI', 'starting11_substitutes_json' => 'Substitutes'] as $field => $label): $names = json_decode((string)($selected[$field] ?? '[]'), true); $names = is_array($names) ? array_filter($names, 'is_string') : []; ?><div class="col-md-6"><h3 class="h6"><?= $label ?></h3><?php if (!$names): ?><p class="text-muted">No lineup recorded.</p><?php else: ?><ul class="mb-0"><?php foreach ($names as $name): if (trim($name) === '') continue; ?><li><?= $esc($name) ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endforeach; ?></div>
        </div></section>
    <?php endif; ?>
    <p class="text-muted small mt-4">Stats use the Hub's recorded results, lineups and match events. Video-analysis measures such as possession, shots and xG are not currently connected.</p>
</div>
<?php require __DIR__ . '/footer.php'; ?>
