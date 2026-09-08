<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/lib/league_table_form.php';
require_once __DIR__ . '/lib/wosfl_table.php';

$env = app_parse_env_file(__DIR__ . '/.env');
$url = trim((string) ($env['WOSFL_TABLE_URL'] ?? ''));
if ($url === '') {
    $url = 'https://www.wosfl.co.uk/standingsForDate/847802708/2/-1/-1.html';
}

$competitionUrl = isset($wosflTableUrlOverride) ? trim((string) $wosflTableUrlOverride) : '';
if ($competitionUrl !== '' && preg_match('#^https?://#i', $competitionUrl) === 1) {
    $url = $competitionUrl;
} elseif (is_file(__DIR__ . '/league-config.json')) {
    $leagueConfigFile = __DIR__ . '/league-config.json';
    $json = file_get_contents($leagueConfigFile);
    $decoded = is_string($json) ? json_decode($json, true) : null;
    if (is_array($decoded)) {
        $configuredUrl = trim((string) ($decoded['wosfl_table_url'] ?? ''));
        if ($configuredUrl !== '' && preg_match('#^https?://#i', $configuredUrl) === 1) {
            $url = $configuredUrl;
        }
    }
}

$cacheFile = __DIR__ . '/cache/wosfl_table.json';
$cachedTeams = is_file($cacheFile)
    ? (json_decode((string) file_get_contents($cacheFile), true) ?: [])
    : [];
$badgeDir = __DIR__ . '/badges';
$badgeOverridesFile = __DIR__ . '/data/wosfl_badge_overrides.json';
$helperScript = dirname(__DIR__) . '/saltcoats/scripts/wosfl-table-cli.mjs';
$tableDataSource = 'live';
$tableStatusLabel = 'Live data';
$tableStatusMessage = '';

function run_wosfl_helper_script(string $script, string $url): array
{
    $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($nodeBinary === '' || !is_file($script)) {
        return ['', 127];
    }

    $command = escapeshellcmd($nodeBinary) . ' ' . escapeshellarg($script)
        . ' --json --pretty --url=' . escapeshellarg($url) . ' 2>&1';
    $outputLines = [];
    $exitCode = 0;
    exec($command, $outputLines, $exitCode);

    return [implode("\n", $outputLines), $exitCode];
}

/**
 * @return array<int, array<string, mixed>>
 */
function decode_wosfl_helper_rows(string $output): array
{
    $decoded = json_decode(trim($output), true);
    if (!is_array($decoded)) {
        return [];
    }

    $rows = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rows[] = $row;
    }

    return $rows;
}

$badgeOverrides = wosfl_load_badge_overrides($badgeOverridesFile);

if (!is_dir(dirname($cacheFile))) {
    mkdir(dirname($cacheFile), 0777, true);
}

if (!is_dir($badgeDir)) {
    mkdir($badgeDir, 0777, true);
}

$cookieFile = tempnam(sys_get_temp_dir(), 'wosfl-session-');
if ($cookieFile === false) {
    $cookieFile = '';
} else {
    register_shutdown_function(static function () use ($cookieFile): void {
        @unlink($cookieFile);
    });
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    CURLOPT_REFERER => 'https://www.wosfl.co.uk/',
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_COOKIEJAR => $cookieFile,
]);
$html = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$html || $code !== 200) {
    [$helperOutput, $helperExitCode] = run_wosfl_helper_script($helperScript, $url);
    if ($helperExitCode === 0) {
        $helperTeams = decode_wosfl_helper_rows($helperOutput);
        if ($helperTeams !== []) {
            $teams = [];

            foreach ($helperTeams as $team) {
                $clubName = trim((string) ($team['club'] ?? ''));
                $logoUrl = trim((string) ($team['logo'] ?? ''));
                [$localLogo] = wosfl_resolve_badge($clubName, $logoUrl, $badgeDir, $badgeOverrides);

                $teams[] = [
                    'pos' => (string) ($team['pos'] ?? ''),
                    'club' => $clubName,
                    'p' => (string) ($team['p'] ?? ''),
                    'w' => (string) ($team['w'] ?? ''),
                    'd' => (string) ($team['d'] ?? ''),
                    'l' => (string) ($team['l'] ?? ''),
                    'f' => (string) ($team['f'] ?? ''),
                    'a' => (string) ($team['a'] ?? ''),
                    'gd' => (string) ($team['gd'] ?? ''),
                    'pts' => (string) ($team['pts'] ?? ''),
                    'logo' => $localLogo,
                ];
            }

            $tableDataSource = 'live';
            $tableStatusLabel = 'Live data';
            $tableStatusMessage = 'Live WOSFL data was fetched using the browser fallback helper.';
        }
    }

    if (!isset($teams)) {
        if (file_exists($cacheFile)) {
            $teams = json_decode((string) file_get_contents($cacheFile), true) ?: [];
            $tableDataSource = 'cache';
            $tableStatusLabel = 'Cached data';
            $tableStatusMessage = 'Live WOSFL data was unavailable, so the most recent cached standings are being shown.';
            return;
        }

        die('Unable to fetch WOSFL table page (' . $code . ')');
    }
} else {
    $teams = wosfl_parse_standings_html($html, $badgeDir, $badgeOverrides);
}

if ($teams === [] && file_exists($cacheFile)) {
    $teams = json_decode((string) file_get_contents($cacheFile), true) ?: [];
    $tableDataSource = 'cache';
    $tableStatusLabel = 'Cached data';
    $tableStatusMessage = 'The live response did not include standings, so the cached table is being shown instead.';
    return;
}

if ($teams === []) {
    die('Unable to parse WOSFL standings.');
}

$liveForm = is_string($html) && $html !== '' && $cookieFile !== ''
    ? league_table_form_fetch($html, $cookieFile)
    : null;
$teams = league_table_form_attach($teams, $liveForm, $cachedTeams);

file_put_contents($cacheFile, json_encode($teams, JSON_PRETTY_PRINT));
