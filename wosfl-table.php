<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/lib/league_table_form.php';

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

/**
 * @return array<string, string>
 */
function load_wosfl_badge_overrides(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $json = file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $overrides = [];
    foreach ($decoded as $clubName => $badgePath) {
        if (!is_string($clubName) || !is_string($badgePath)) {
            continue;
        }

        $normalizedClub = normalize_wosfl_club_name($clubName);
        if ($normalizedClub === '') {
            continue;
        }

        $overrides[$normalizedClub] = trim($badgePath);
    }

    return $overrides;
}

function normalize_wosfl_club_name(string $clubName): string
{
    $clubName = strtolower(trim($clubName));
    $clubName = preg_replace('/\s+/', ' ', $clubName) ?? $clubName;

    return trim($clubName);
}

/**
 * Resolve a badge path for a club, preferring an explicit override file when available.
 */
function resolve_wosfl_badge(string $clubName, string $logoUrl, string $badgeDir, array $badgeOverrides): array
{
    $slugSource = strtolower($clubName);
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slugSource), '-');
    $defaultRelative = 'badges/' . $slug . '.png';
    $defaultPath = __DIR__ . '/' . $defaultRelative;

    $overrideKey = normalize_wosfl_club_name($clubName);
    $overrideRelative = null;

    if ($overrideKey !== '' && isset($badgeOverrides[$overrideKey])) {
        $overrideCandidate = trim((string) $badgeOverrides[$overrideKey]);
        if ($overrideCandidate !== '') {
            $overrideCandidate = str_replace('\\', '/', $overrideCandidate);
            $overrideCandidate = ltrim($overrideCandidate, '/');
            $overrideCandidate = preg_replace('#^\.\/#', '', $overrideCandidate) ?? $overrideCandidate;

            if (str_starts_with($overrideCandidate, 'badges/')) {
                $overrideRelative = $overrideCandidate;
            } else {
                $overrideRelative = 'badges/' . basename($overrideCandidate);
            }
        }
    }

    $candidates = [];
    if ($overrideRelative !== null) {
        $candidates[] = $overrideRelative;
    }
    $candidates[] = $defaultRelative;

    foreach ($candidates as $relativePath) {
        $absolutePath = __DIR__ . '/' . $relativePath;
        if (is_file($absolutePath)) {
            return [$relativePath, $absolutePath];
        }
    }

    if ($logoUrl !== '' && !file_exists($defaultPath)) {
        $img = @file_get_contents($logoUrl);
        if ($img) {
            file_put_contents($defaultPath, $img);
        }
    }

    if (is_file($defaultPath)) {
        return [$defaultRelative, $defaultPath];
    }

    if ($overrideRelative !== null) {
        return [$overrideRelative, __DIR__ . '/' . $overrideRelative];
    }

    return [$defaultRelative, $defaultPath];
}

$badgeOverrides = load_wosfl_badge_overrides($badgeOverridesFile);

if (!is_dir(dirname($cacheFile))) {
    mkdir(dirname($cacheFile), 0777, true);
}

if (!is_dir($badgeDir)) {
    mkdir($badgeDir, 0777, true);
}

foreach (glob($badgeDir . '/*.png') as $file) {
    if (time() - filemtime($file) > 60 * 60 * 24 * 30) {
        unlink($file);
    }
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
                [$localLogo, $localPath] = resolve_wosfl_badge($clubName, $logoUrl, $badgeDir, $badgeOverrides);

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
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $rows = $xpath->query('//table//tr');
    $teams = [];

    foreach ($rows as $row) {
        $cols = $row->getElementsByTagName('td');
        if ($cols->length < 10) {
            continue;
        }

        $clubLink = $cols->item(1)->getElementsByTagName('a')->item(0);
        $clubName = trim($clubLink->textContent ?? '');
        $logoTag = $clubLink ? $clubLink->getElementsByTagName('img')->item(0) : null;
        $logoUrl = $logoTag ? trim($logoTag->getAttribute('src')) : '';
        [$localLogo, $localPath] = resolve_wosfl_badge($clubName, $logoUrl, $badgeDir, $badgeOverrides);

        $teams[] = [
            'pos' => trim($cols->item(0)->textContent),
            'club' => $clubName,
            'p' => trim($cols->item(2)->textContent),
            'w' => trim($cols->item(3)->textContent),
            'd' => trim($cols->item(4)->textContent),
            'l' => trim($cols->item(5)->textContent),
            'f' => trim($cols->item(6)->textContent),
            'a' => trim($cols->item(7)->textContent),
            'gd' => trim($cols->item(8)->textContent),
            'pts' => trim($cols->item(9)->textContent),
            'logo' => $localLogo,
        ];
    }
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
