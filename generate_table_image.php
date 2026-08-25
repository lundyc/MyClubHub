<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/render_lib.php';

$output = __DIR__ . '/export/latest_wosfl.png';
$url = 'https://lundy.me.uk/league_table_graphic.php?render=1&table_style=compact';

$result = render_capture_image($url, $output, '.table-card--render', 1080, 1350, '.table-card--render');

if (!$result['ok']) {
    echo $result['error'] . PHP_EOL;
    foreach ($result['output'] as $line) {
        echo $line . PHP_EOL;
    }
    exit(1);
}

echo 'Done: ' . $output . PHP_EOL;
