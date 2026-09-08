<?php
/** Route: /sitemap.xml */
declare(strict_types=1);

pub_raw();
header('Content-Type: application/xml; charset=utf-8');

$origin = current_url_origin();
$loc = static fn (string $path): string => $origin . url($path);

$urls = [];
$urls[] = [$loc(''), null, 'daily'];
foreach (['news', 'fixtures', 'results', 'table', 'team', 'staff', 'contact', 'privacy'] as $p) {
    $urls[] = [$loc($p), null, 'weekly'];
}

foreach (news_published(db(), ['limit' => 60]) as $a) {
    $urls[] = [$loc('news/' . $a['slug']), format_date($a['published_at'], 'Y-m-d'), 'monthly'];
}
foreach (club_pages_menu(db()) as $cp) {
    $urls[] = [$loc('club/' . $cp['slug']), null, 'monthly'];
}
foreach (squad_public_players(db()) as $pl) {
    $urls[] = [$loc('team/' . $pl['slug']), null, 'monthly'];
}
foreach (pub_fixtures(['type' => 'all']) as $fx) {
    $urls[] = [$loc('match/' . (int) $fx['id']), null, 'weekly'];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$u, $lastmod, $freq]) {
    echo '  <url><loc>' . e($u) . '</loc>';
    if ($lastmod) {
        echo '<lastmod>' . e($lastmod) . '</lastmod>';
    }
    echo '<changefreq>' . $freq . '</changefreq></url>' . "\n";
}
echo '</urlset>' . "\n";
