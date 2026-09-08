<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/lib/directory.php';

const SOCIALS_PLAYERS_CACHE = __DIR__ . '/data/players.json';
const SOCIALS_SPONSORS_CACHE = __DIR__ . '/data/sponsors.json';

function write_json_file(string $path, array $rows): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $json = json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

/**
 * @return array{ok: bool, players: int, sponsors: int}
 */
function player_sponsors_sync_social_directory(): array
{
    $players = player_sponsors_directory_players();
    $sponsors = player_sponsors_directory_sponsors();

    $playersOk = write_json_file(SOCIALS_PLAYERS_CACHE, $players);
    $sponsorsOk = write_json_file(SOCIALS_SPONSORS_CACHE, $sponsors);

    return [
        'ok' => $playersOk && $sponsorsOk,
        'players' => count($players),
        'sponsors' => count($sponsors),
    ];
}

if (basename(__FILE__) === basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    header('Content-Type: text/plain; charset=UTF-8');

    $result = player_sponsors_sync_social_directory();
    if (!$result['ok']) {
        http_response_code(500);
        echo "Export failed: could not write one or more cache files.\n";
        exit;
    }

    echo "Export complete\n";
    echo "Players exported: " . $result['players'] . "\n";
    echo "Sponsors exported: " . $result['sponsors'] . "\n";
}
