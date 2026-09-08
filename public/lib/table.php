<?php

declare(strict_types=1);

/*
 * League table for the public site.
 *
 * The Hub scrapes the WOSFL standings (wosfl-table.php, refreshed whenever
 * staff view /league_table.php) and writes them to cache/wosfl_table.json. The
 * public site only ever READS that cache — it never runs the scraper, which has
 * die()s and other side effects unsuitable for a public request. If the cache
 * is missing we degrade to a "view on WOSFL" link.
 */

function pub_league_cache_path(): string
{
    return HUB_ROOT . '/cache/wosfl_table.json';
}

/**
 * @return array{ok:bool, rows:list<array<string,mixed>>, updated:?int, url:string, title:string}
 */
function pub_league_table(): array
{
    static $result = null;
    if ($result !== null) {
        return $result;
    }

    $config = pub_league_config();
    $path = pub_league_cache_path();
    $rows = [];
    $updated = null;

    if (is_file($path)) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded)) {
            $rows = array_values(array_filter($decoded, 'is_array'));
            $updated = @filemtime($path) ?: null;
        }
    }

    return $result = [
        'ok' => $rows !== [],
        'rows' => $rows,
        'updated' => $updated,
        'url' => $config['url'],
        'title' => $config['title'],
    ];
}

/**
 * @return array{title:string, url:string, promotion:int, relegation:int}
 */
function pub_league_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $data = [];
    $file = HUB_ROOT . '/league-config.json';
    if (is_file($file)) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    // A club-configured override always wins.
    $override = club('league_table_url');

    return $cfg = [
        'title'      => (string) ($data['league_title'] ?? 'League table'),
        'url'        => $override !== '' ? $override : (string) ($data['wosfl_table_url'] ?? 'https://www.wosfl.co.uk/'),
        'promotion'  => max(0, (int) ($data['promotion_spots'] ?? 0)),
        'relegation' => max(0, (int) ($data['relegation_spots'] ?? 0)),
    ];
}

/** Is this row our club? */
function pub_league_is_us(array $row): bool
{
    return stripos((string) ($row['club'] ?? ''), 'saltcoats') !== false;
}

/** Our row in the table, or null. */
function pub_league_our_row(): ?array
{
    foreach (pub_league_table()['rows'] as $row) {
        if (pub_league_is_us($row)) {
            return $row;
        }
    }
    return null;
}

/** Ordinal like 1st, 2nd, 13th. */
function pub_ordinal(int $n): string
{
    if ($n <= 0) {
        return '—';
    }
    $mod100 = $n % 100;
    $suffix = ($mod100 >= 11 && $mod100 <= 13) ? 'th'
        : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th');
    return $n . $suffix;
}
