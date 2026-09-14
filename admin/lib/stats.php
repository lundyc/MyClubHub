<?php
declare(strict_types=1);

/** Results are always from the club's perspective; incomplete scores stay unknown. */
function hub_stats_result(array $fixture): ?array
{
    if (($fixture['status'] ?? '') !== 'played'
        || !isset($fixture['full_time_home_score'], $fixture['full_time_away_score'])) {
        return null;
    }
    $home = (int)$fixture['is_home'] === 1;
    $for = (int)$fixture[$home ? 'full_time_home_score' : 'full_time_away_score'];
    $against = (int)$fixture[$home ? 'full_time_away_score' : 'full_time_home_score'];
    return ['for' => $for, 'against' => $against, 'result' => $for > $against ? 'W' : ($for < $against ? 'L' : 'D')];
}

function hub_stats_summary(array $fixtures): array
{
    $summary = ['played' => 0, 'scored' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'goals_for' => 0, 'goals_against' => 0, 'clean_sheets' => 0];
    foreach ($fixtures as $fixture) {
        if (($fixture['status'] ?? '') !== 'played') continue;
        $summary['played']++;
        $result = hub_stats_result($fixture);
        if ($result === null) continue;
        $summary['scored']++;
        $summary[['W' => 'wins', 'D' => 'draws', 'L' => 'losses'][$result['result']]]++;
        $summary['goals_for'] += $result['for'];
        $summary['goals_against'] += $result['against'];
        if ($result['against'] === 0) $summary['clean_sheets']++;
    }
    return $summary;
}

/* -------------------------------------------------------------------------
 * "Compare seasons" model — year-on-year team + player stats.
 * Shared by stats.php (screen) and stats_compare_pdf.php (A4 export).
 * ---------------------------------------------------------------------- */

/** @return array<string,string> metric key => label, in display order. */
function hub_stats_compare_metric_labels(): array
{
    return [
        'goals' => 'Goals',
        'appearances' => 'Appearances',
        'starts' => 'Starts',
        'substitute_appearances' => 'Sub appearances',
        'minutes_played' => 'Minutes',
        'yellow_cards' => 'Yellow cards',
        'red_cards' => 'Red cards',
        'clean_sheets' => 'Clean sheets',
    ];
}

/**
 * Team-comparison rows. Each entry: [label, fn(row):string, fn(row):?float|null, 'high'|'low'|null].
 * The raw getter (3rd item) drives the season-to-season delta; null = no delta.
 * @return list<array{0:string,1:callable,2:?callable,3:?string}>
 */
function hub_stats_compare_team_rows(): array
{
    $fmt2 = static fn(?float $v): string => $v === null ? '—' : number_format($v, 2);
    $pct  = static fn(?float $v): string => $v === null ? '—' : round($v) . '%';
    return [
        ['Played',            static fn($c) => (string)$c['summary']['played'],        static fn($c) => (float)$c['summary']['played'],        null],
        ['Won',               static fn($c) => (string)$c['summary']['wins'],          static fn($c) => (float)$c['summary']['wins'],          'high'],
        ['Drawn',             static fn($c) => (string)$c['summary']['draws'],         static fn($c) => (float)$c['summary']['draws'],         null],
        ['Lost',              static fn($c) => (string)$c['summary']['losses'],        static fn($c) => (float)$c['summary']['losses'],        'low'],
        ['Win rate',          static fn($c) => $pct($c['win_pct']),                    static fn($c) => $c['win_pct'] === null ? null : round($c['win_pct']), 'high'],
        ['Points',            static fn($c) => (string)$c['points'],                   static fn($c) => (float)$c['points'],                   'high'],
        ['Points per game',   static fn($c) => $fmt2($c['ppg']),                       static fn($c) => $c['ppg'],                             'high'],
        ['Goals for',         static fn($c) => (string)$c['summary']['goals_for'],     static fn($c) => (float)$c['summary']['goals_for'],     'high'],
        ['Goals against',     static fn($c) => (string)$c['summary']['goals_against'], static fn($c) => (float)$c['summary']['goals_against'], 'low'],
        ['Goal difference',   static fn($c) => sprintf('%+d', $c['summary']['goals_for'] - $c['summary']['goals_against']), static fn($c) => (float)($c['summary']['goals_for'] - $c['summary']['goals_against']), 'high'],
        ['Goals per game',    static fn($c) => $fmt2($c['gf_pg']),                     static fn($c) => $c['gf_pg'],                           'high'],
        ['Conceded per game', static fn($c) => $fmt2($c['ga_pg']),                     static fn($c) => $c['ga_pg'],                           'low'],
        ['Clean sheets',      static fn($c) => (string)$c['summary']['clean_sheets'],  static fn($c) => (float)$c['summary']['clean_sheets'],  'high'],
        ['Clean sheet rate',  static fn($c) => $pct($c['cs_pct']),                     static fn($c) => $c['cs_pct'] === null ? null : round($c['cs_pct']), 'high'],
        ['Failed to score',   static fn($c) => (string)$c['failed_to_score'],          static fn($c) => (float)$c['failed_to_score'],          'low'],
        ['Home (P·W·D·L)',    static fn($c) => sprintf('%d·%d·%d·%d', $c['home']['played'], $c['home']['wins'], $c['home']['draws'], $c['home']['losses']), null, null],
        ['Away (P·W·D·L)',    static fn($c) => sprintf('%d·%d·%d·%d', $c['away']['played'], $c['away']['wins'], $c['away']['draws'], $c['away']['losses']), null, null],
        ['Biggest win',       static fn($c) => $c['biggest_win'] ? $c['biggest_win']['score'] . ' v ' . $c['biggest_win']['opp'] : '—', null, null],
        ['Biggest defeat',    static fn($c) => $c['biggest_loss'] ? $c['biggest_loss']['score'] . ' v ' . $c['biggest_loss']['opp'] : '—', null, null],
    ];
}

/**
 * Full data model for the "Compare seasons" view.
 *
 * @param list<array<string,mixed>> $allSeasons  every season row (id, name, start_date, …)
 * @param array{
 *   season_ids?: array<int|string>,
 *   competitions?: array<string>,
 *   venue?: string,
 *   metrics?: array<string>,
 *   events_by_fixture?: array<int, array>,
 *   data_file?: string
 * } $opts
 * @return array<string,mixed>
 */
function hub_stats_compare_build(PDO $pdo, array $allSeasons, array $opts): array
{
    require_once __DIR__ . '/player_match_stats.php';

    $metricLabels = hub_stats_compare_metric_labels();

    $seasonById = [];
    foreach ($allSeasons as $s) {
        $seasonById[(int)$s['id']] = $s;
    }

    // Seasons ---------------------------------------------------------------
    $seasonIds = [];
    foreach ((array)($opts['season_ids'] ?? []) as $rid) {
        $rid = (int)$rid;
        if (isset($seasonById[$rid]) && !in_array($rid, $seasonIds, true)) {
            $seasonIds[] = $rid;
        }
    }
    if ($seasonIds === []) {
        $played = $pdo->query("SELECT season_id, COUNT(*) FROM match_fixtures WHERE status = 'played' GROUP BY season_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        $withPlayed = array_values(array_filter($allSeasons, static fn(array $s): bool => (int)($played[(int)$s['id']] ?? 0) > 0));
        $seasonIds = array_map(static fn(array $s): int => (int)$s['id'], array_slice($withPlayed, -2));
    }
    usort($seasonIds, static fn(int $a, int $b): int =>
        strcmp((string)($seasonById[$a]['start_date'] ?? ''), (string)($seasonById[$b]['start_date'] ?? '')) ?: ($a <=> $b));

    // Venue ---------------------------------------------------------------
    $venue = in_array($opts['venue'] ?? '', ['home', 'away'], true) ? (string)$opts['venue'] : '';

    // Competitions ------------------------------------------------------
    $competitionsAvailable = [];
    if ($seasonIds) {
        $ph = implode(',', array_fill(0, count($seasonIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT DISTINCT competition FROM match_fixtures
             WHERE season_id IN ($ph) AND status = 'played'
               AND competition IS NOT NULL AND competition <> '' ORDER BY competition"
        );
        $stmt->execute($seasonIds);
        $competitionsAvailable = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $competitions = [];
    foreach ((array)($opts['competitions'] ?? []) as $c) {
        $c = (string)$c;
        if (in_array($c, $competitionsAvailable, true) && !in_array($c, $competitions, true)) {
            $competitions[] = $c;
        }
    }

    // Metrics ---------------------------------------------------------
    $metrics = [];
    foreach ((array)($opts['metrics'] ?? []) as $m) {
        if (is_string($m) && isset($metricLabels[$m]) && !in_array($m, $metrics, true)) {
            $metrics[] = $m;
        }
    }
    if ($metrics === []) {
        $metrics = ['goals'];
    }
    $metrics = array_values(array_intersect(array_keys($metricLabels), $metrics));

    // Player status (name -> left the club) --------------------------
    $playerStatusByNorm = [];
    foreach ($pdo->query(
        "SELECT name, status, active FROM players
         ORDER BY (status = 'current' AND active = 1) DESC, active DESC, id ASC"
    ) as $pr) {
        $norm = hub_player_stats_normalize_name((string)$pr['name']);
        if ($norm === '' || isset($playerStatusByNorm[$norm])) {
            continue;
        }
        $status = (string)$pr['status'];
        $playerStatusByNorm[$norm] = [
            'status' => $status,
            'left' => (int)$pr['active'] === 0 || in_array($status, ['left', 'retired'], true),
        ];
    }

    // Match events --------------------------------------------------
    $eventsByFixture = $opts['events_by_fixture'] ?? null;
    $dataFile = (string)($opts['data_file'] ?? '');
    if (!is_array($eventsByFixture)) {
        $eventsByFixture = [];
        if ($dataFile !== '' && is_readable($dataFile)) {
            $records = json_decode((string)file_get_contents($dataFile), true);
            foreach (is_array($records) ? $records : [] as $record) {
                if (is_array($record) && isset($record['id'])) {
                    $eventsByFixture[(int)$record['id']] = array_values(array_filter(is_array($record['events'] ?? null) ? $record['events'] : [], 'is_array'));
                }
            }
        }
    }

    // Per-season aggregation --------------------------------------
    $team = [];
    $playerCompare = [];
    $playerNames = [];
    $stmt = $pdo->prepare('SELECT * FROM match_fixtures WHERE season_id = ? AND status = ? ORDER BY match_date ASC, kickoff_time ASC, id ASC');
    foreach ($seasonIds as $sid) {
        $stmt->execute([$sid, 'played']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($venue !== '') {
            $want = $venue === 'home' ? 1 : 0;
            $rows = array_values(array_filter($rows, static fn(array $f): bool => (int)$f['is_home'] === $want));
        }
        if ($competitions !== []) {
            $rows = array_values(array_filter($rows, static fn(array $f): bool => in_array((string)($f['competition'] ?? ''), $competitions, true)));
        }

        $sum = hub_stats_summary($rows);
        $home = hub_stats_summary(array_filter($rows, static fn(array $f): bool => (int)$f['is_home'] === 1));
        $away = hub_stats_summary(array_filter($rows, static fn(array $f): bool => (int)$f['is_home'] === 0));
        $points = $sum['wins'] * 3 + $sum['draws'];
        $failedToScore = 0;
        $biggestWin = null;
        $biggestLoss = null;
        foreach ($rows as $f) {
            $r = hub_stats_result($f);
            if ($r === null) {
                continue;
            }
            if ($r['for'] === 0) {
                $failedToScore++;
            }
            $margin = $r['for'] - $r['against'];
            if ($r['result'] === 'W' && ($biggestWin === null || $margin > $biggestWin['margin'])) {
                $biggestWin = ['margin' => $margin, 'score' => $r['for'] . '–' . $r['against'], 'opp' => (string)$f['opponent']];
            }
            if ($r['result'] === 'L' && ($biggestLoss === null || $margin < $biggestLoss['margin'])) {
                $biggestLoss = ['margin' => $margin, 'score' => $r['for'] . '–' . $r['against'], 'opp' => (string)$f['opponent']];
            }
        }

        $names = [];
        foreach ($rows as $f) {
            foreach (['starting11_starters_json', 'starting11_substitutes_json'] as $field) {
                $lineup = json_decode((string)($f[$field] ?? '[]'), true);
                foreach (is_array($lineup) ? $lineup : [] as $name) {
                    if (is_string($name) && trim($name) !== '') {
                        $names[hub_player_stats_normalize_name($name)] = $name;
                    }
                }
            }
            foreach ($eventsByFixture[(int)$f['id']] ?? [] as $event) {
                if (hub_player_stats_is_club_player_event($event) && trim((string)($event['player'] ?? '')) !== '') {
                    $names[hub_player_stats_normalize_name((string)$event['player'])] = (string)$event['player'];
                }
            }
        }

        $team[$sid] = [
            'summary' => $sum,
            'points' => $points,
            'ppg' => $sum['scored'] ? $points / $sum['scored'] : null,
            'win_pct' => $sum['scored'] ? 100 * $sum['wins'] / $sum['scored'] : null,
            'gf_pg' => $sum['scored'] ? $sum['goals_for'] / $sum['scored'] : null,
            'ga_pg' => $sum['scored'] ? $sum['goals_against'] / $sum['scored'] : null,
            'cs_pct' => $sum['scored'] ? 100 * $sum['clean_sheets'] / $sum['scored'] : null,
            'failed_to_score' => $failedToScore,
            'home' => $home,
            'away' => $away,
            'biggest_win' => $biggestWin,
            'biggest_loss' => $biggestLoss,
            'has_players' => $names !== [],
        ];

        foreach ($names as $norm => $displayName) {
            $playerCompare[$sid][$norm] = hub_player_match_stats($pdo, $sid, $displayName, $dataFile, $rows, $eventsByFixture);
            if (!isset($playerNames[$norm]) || mb_strlen($displayName) > mb_strlen($playerNames[$norm])) {
                $playerNames[$norm] = $displayName;
            }
        }
    }

    $playerSeasons = array_values(array_filter($seasonIds, static fn(int $sid): bool => !empty($team[$sid]['has_players'])));
    $noPlayerSeasons = array_values(array_filter($seasonIds, static fn(int $sid): bool => empty($team[$sid]['has_players'])));
    $latestPlayerSeason = $playerSeasons ? (int)end($playerSeasons) : 0;

    $players = [];
    foreach ($metrics as $metric) {
        $grid = [];
        foreach ($playerSeasons as $sid) {
            foreach ($playerCompare[$sid] ?? [] as $norm => $p) {
                $grid[$norm][$sid] = (int)($p[$metric] ?? 0);
            }
        }
        $prows = [];
        $hiddenZero = 0;
        foreach ($grid as $norm => $vals) {
            $total = 0;
            foreach ($playerSeasons as $sid) {
                $total += $vals[$sid] ?? 0;
            }
            if ($total === 0) {
                $hiddenZero++;
                continue;
            }
            $st = $playerStatusByNorm[$norm] ?? null;
            $prows[] = [
                'name' => $playerNames[$norm] ?? $norm,
                'vals' => $vals,
                'total' => $total,
                'left' => (bool)($st['left'] ?? false),
                'status' => (string)($st['status'] ?? ''),
            ];
        }
        usort($prows, static fn(array $a, array $b): int =>
            (($a['left'] ? 1 : 0) <=> ($b['left'] ? 1 : 0))
            ?: ($b['total'] <=> $a['total'])
            ?: (($b['vals'][$latestPlayerSeason] ?? 0) <=> ($a['vals'][$latestPlayerSeason] ?? 0))
            ?: strcasecmp($a['name'], $b['name']));
        $players[$metric] = [
            'rows' => $prows,
            'hidden_zero' => $hiddenZero,
            'any_left' => (bool)array_filter($prows, static fn(array $r): bool => $r['left']),
            'better' => in_array($metric, ['yellow_cards', 'red_cards'], true) ? 'low' : 'high',
        ];
    }

    return [
        'season_ids' => $seasonIds,
        'season_by_id' => $seasonById,
        'venue' => $venue,
        'competitions_available' => $competitionsAvailable,
        'competitions' => $competitions,
        'metrics' => $metrics,
        'metric_labels' => $metricLabels,
        'team' => $team,
        'player_seasons' => $playerSeasons,
        'no_player_seasons' => $noPlayerSeasons,
        'latest_player_season' => $latestPlayerSeason,
        'players' => $players,
    ];
}

/**
 * Match-by-match breakdown behind one cell of the "Compare seasons" player
 * tables — e.g. which games a player's goals came in, and at what minute.
 * Walks the same lineup/event data as hub_player_match_stats(), but keeps a
 * row per fixture instead of collapsing to a season total.
 *
 * @param list<int> $seasonIds
 * @param array<int,array<string,mixed>> $seasonById season_id => season row (needs 'name')
 * @param list<string> $competitions empty = all competitions
 * @return array{player:string,metric:string,total:int,rows:list<array<string,mixed>>}
 */
function hub_player_stat_match_rows(
    PDO $pdo,
    array $seasonIds,
    array $seasonById,
    string $playerName,
    string $metric,
    array $competitions,
    string $venue,
    string $dataFile
): array {
    require_once __DIR__ . '/player_match_stats.php';

    $normalizedPlayerName = hub_player_stats_normalize_name($playerName);
    $rows = [];
    if ($normalizedPlayerName === '' || $seasonIds === []) {
        return ['player' => $playerName, 'metric' => $metric, 'total' => 0, 'rows' => []];
    }

    $eventsByFixture = [];
    if ($dataFile !== '' && is_readable($dataFile)) {
        $records = json_decode((string)file_get_contents($dataFile), true);
        foreach (is_array($records) ? $records : [] as $record) {
            if (is_array($record) && isset($record['id'])) {
                $eventsByFixture[(int)$record['id']] = array_values(array_filter(is_array($record['events'] ?? null) ? $record['events'] : [], 'is_array'));
            }
        }
    }

    $stmt = $pdo->prepare('SELECT * FROM match_fixtures WHERE season_id = ? AND status = ? ORDER BY match_date ASC, kickoff_time ASC, id ASC');
    foreach ($seasonIds as $sid) {
        $stmt->execute([$sid, 'played']);
        $fixtures = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($venue !== '') {
            $want = $venue === 'home' ? 1 : 0;
            $fixtures = array_values(array_filter($fixtures, static fn(array $f): bool => (int)$f['is_home'] === $want));
        }
        if ($competitions !== []) {
            $fixtures = array_values(array_filter($fixtures, static fn(array $f): bool => in_array((string)($f['competition'] ?? ''), $competitions, true)));
        }

        foreach ($fixtures as $fixture) {
            $starters = json_decode((string)($fixture['starting11_starters_json'] ?? '[]'), true);
            $substitutes = json_decode((string)($fixture['starting11_substitutes_json'] ?? '[]'), true);
            $starters = is_array($starters) ? array_values($starters) : [];
            $substitutes = is_array($substitutes) ? array_values($substitutes) : [];

            $starterIndex = null;
            foreach ($starters as $index => $starter) {
                if (hub_player_stats_normalize_name((string)$starter) === $normalizedPlayerName) {
                    $starterIndex = (int)$index;
                    break;
                }
            }
            $isStarter = $starterIndex !== null;
            $isNamedSubstitute = false;
            foreach ($substitutes as $substitute) {
                if (hub_player_stats_normalize_name((string)$substitute) === $normalizedPlayerName) {
                    $isNamedSubstitute = true;
                    break;
                }
            }

            $events = array_values(array_filter($eventsByFixture[(int)$fixture['id']] ?? [], 'is_array'));
            usort($events, static fn(array $a, array $b): int => (int)($a['sequence'] ?? 0) <=> (int)($b['sequence'] ?? 0));

            $onMinute = null;
            $offMinute = null;
            $opponentGoalsFromEvents = 0;
            $hasFullTimeEvent = false;
            $goalMinutes = [];
            $yellowMinutes = [];
            $redMinutes = [];

            foreach ($events as $event) {
                $type = (string)($event['type'] ?? '');
                $team = (string)($event['team'] ?? '');
                $eventPlayer = hub_player_stats_normalize_name((string)($event['player'] ?? ''));
                $isScoringEvent = $type === 'goal' || ($type === 'penalty' && (string)($event['outcome'] ?? '') === 'scored');
                $minute = hub_player_stats_event_minute($event['minute'] ?? '');

                if ($isScoringEvent && $team === 'opponent') {
                    $opponentGoalsFromEvents++;
                }
                if ($type === 'full_time') {
                    $hasFullTimeEvent = true;
                }
                if ($team === 'svfc' && $eventPlayer === $normalizedPlayerName) {
                    if ($isScoringEvent && empty($event['own_goal'])) {
                        $goalMinutes[] = $minute;
                    }
                    if ($type === 'yellow_card' || ($type === 'card' && (string)($event['card_type'] ?? '') === 'yellow')) {
                        $yellowMinutes[] = $minute;
                    }
                    if ($type === 'red_card' || ($type === 'card' && (string)($event['card_type'] ?? '') === 'red')) {
                        $redMinutes[] = $minute;
                    }
                }

                if ($type !== 'substitution' || $team !== 'svfc') {
                    continue;
                }
                $changes = is_array($event['substitutions'] ?? null) ? $event['substitutions'] : [];
                if ($changes === []) {
                    $changes[] = ['off' => (string)($event['player'] ?? ''), 'on' => (string)($event['secondary_player'] ?? '')];
                }
                foreach ($changes as $change) {
                    if (!is_array($change)) {
                        continue;
                    }
                    if ($offMinute === null && hub_player_stats_normalize_name((string)($change['off'] ?? '')) === $normalizedPlayerName) {
                        $offMinute = $minute;
                    }
                    if ($onMinute === null && hub_player_stats_normalize_name((string)($change['on'] ?? '')) === $normalizedPlayerName) {
                        $onMinute = $minute;
                    }
                }
            }

            $played = false;
            $minutesPlayed = 0;
            $apparanceDetail = '';
            if ($isStarter) {
                $played = true;
                $minutesPlayed = $offMinute ?? 90;
                $apparanceDetail = $offMinute !== null ? "Started, off {$offMinute}\u{2032}" : 'Started, played 90 minutes';
            } elseif ($isNamedSubstitute && $onMinute !== null) {
                $played = true;
                $minutesPlayed = max(0, 90 - $onMinute);
                $apparanceDetail = "Came on {$onMinute}\u{2032}" . ($offMinute !== null && $offMinute > $onMinute ? ", off {$offMinute}\u{2032}" : '');
            }

            $cleanSheet = false;
            if ($isStarter && $starterIndex === 0) {
                $opponentScore = null;
                if ($fixture['full_time_home_score'] !== null && $fixture['full_time_away_score'] !== null) {
                    $opponentScore = (int)$fixture[(int)$fixture['is_home'] === 1 ? 'full_time_away_score' : 'full_time_home_score'];
                } elseif ($hasFullTimeEvent) {
                    $opponentScore = $opponentGoalsFromEvents;
                }
                $cleanSheet = $opponentScore === 0;
            }

            $value = 0;
            $detail = '';
            switch ($metric) {
                case 'goals':
                    $value = count($goalMinutes);
                    $detail = $value === 1 ? "Scored \u{2013} {$goalMinutes[0]}\u{2032}" : implode(', ', array_map(static fn($m) => $m . "\u{2032}", $goalMinutes));
                    break;
                case 'yellow_cards':
                    $value = count($yellowMinutes);
                    $detail = 'Yellow card ' . implode(', ', array_map(static fn($m) => $m . "\u{2032}", $yellowMinutes));
                    break;
                case 'red_cards':
                    $value = count($redMinutes);
                    $detail = 'Red card ' . implode(', ', array_map(static fn($m) => $m . "\u{2032}", $redMinutes));
                    break;
                case 'clean_sheets':
                    $value = $cleanSheet ? 1 : 0;
                    $detail = 'Clean sheet';
                    break;
                case 'appearances':
                    $value = $played ? 1 : 0;
                    $detail = $apparanceDetail;
                    break;
                case 'starts':
                    $value = $isStarter ? 1 : 0;
                    $detail = $isStarter ? $apparanceDetail : '';
                    break;
                case 'substitute_appearances':
                    $value = ($isNamedSubstitute && $onMinute !== null) ? 1 : 0;
                    $detail = $value ? $apparanceDetail : '';
                    break;
                case 'minutes_played':
                    $value = $minutesPlayed;
                    $detail = $played ? "{$minutesPlayed} minutes" : '';
                    break;
            }

            if ($value <= 0) {
                continue;
            }

            $result = hub_stats_result($fixture);
            $rows[] = [
                'season_name' => (string)($seasonById[$sid]['name'] ?? $sid),
                'date' => (string)$fixture['match_date'],
                'opponent' => (string)$fixture['opponent'],
                'venue' => (int)$fixture['is_home'] === 1 ? 'Home' : 'Away',
                'competition' => (string)($fixture['competition'] ?? ''),
                'score' => $result ? $result['for'] . "\u{2013}" . $result['against'] : null,
                'result' => $result['result'] ?? null,
                'value' => $value,
                'detail' => $detail,
            ];
        }
    }

    return [
        'player' => $playerName,
        'metric' => $metric,
        'total' => array_sum(array_column($rows, 'value')),
        'rows' => $rows,
    ];
}
