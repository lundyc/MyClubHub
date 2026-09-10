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
