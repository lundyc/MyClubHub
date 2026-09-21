<?php
/** Route: /sitemap.xml */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/shop.php';

pub_raw();
header('Content-Type: application/xml; charset=utf-8');

$origin = current_url_origin();
$loc = static fn (string $path): string => $origin . url($path);

/** @var list<array{0:string,1:?string,2:string,3:string}> $urls [loc, lastmod, changefreq, priority] */
$urls = [];

// Primary page.
$urls[] = [$loc(''), null, 'daily', '1.0'];

// Section hubs — top-level listing pages, refreshed on their own cadence.
$hubs = [
    'news'     => 'daily',
    'fixtures' => 'weekly',
    'results'  => 'weekly',
    'table'    => 'weekly',
    'team'     => 'monthly',
    'staff'    => 'yearly',
    'gallery'  => 'monthly',
    'partners' => 'yearly',
    'contact'  => 'yearly',
];
foreach ($hubs as $p => $freq) {
    $urls[] = [$loc($p), null, $freq, '0.8'];
}

// Legal/utility page — present but not a content hub.
$urls[] = [$loc('privacy'), null, 'yearly', '0.6'];

// Static club-info pages — router.php matches these before the club/{slug} CMS
// catch-all, so any club_pages row sharing one of these slugs is unreachable
// and must be skipped below to avoid a duplicate <url>.
$staticClubSlugs = ['history', 'stadium', 'officials', 'honours', 'player-awards', 'policies', 'records'];
foreach ($staticClubSlugs as $slug) {
    $urls[] = [$loc('club/' . $slug), null, 'yearly', '0.6'];
}

// Shop — only when enabled; storefront hub + one entry per active product.
$shopSettings = shop_get_settings(db());
if (($shopSettings['shop_enabled'] ?? '1') === '1') {
    $urls[] = [$loc('shop'), null, 'weekly', '0.8'];
    foreach (shop_get_products(db(), ['active_only' => true]) as $product) {
        $urls[] = [$loc('shop/p/' . $product['slug']), null, 'weekly', '0.6'];
    }
}

// Match tickets — availability changes with the fixture list.
$urls[] = [$loc('tickets'), null, 'weekly', '0.8'];

foreach (news_categories() as $cat) {
    $urls[] = [$loc('news/category/' . news_slugify($cat)), null, 'weekly', '0.6'];
}
foreach (news_published(db(), ['limit' => 60]) as $a) {
    $urls[] = [$loc('news/' . $a['slug']), format_date($a['published_at'], 'Y-m-d'), 'monthly', '0.6'];
}
foreach (club_pages_menu(db()) as $cp) {
    if (in_array($cp['slug'], $staticClubSlugs, true)) {
        continue;
    }
    $urls[] = [$loc('club/' . $cp['slug']), null, 'monthly', '0.6'];
}
foreach (squad_public_players(db()) as $pl) {
    $urls[] = [$loc('team/' . $pl['slug']), null, 'monthly', '0.6'];
}
foreach (pub_galleries() as $g) {
    $lastmod = !empty($g['album_date']) ? format_date($g['album_date'], 'Y-m-d') : null;
    $urls[] = [$loc('gallery/' . $g['slug']), $lastmod, 'yearly', '0.6'];
}
foreach (pub_seasons() as $s) {
    $freq = $s['is_current'] ? 'weekly' : 'yearly';
    foreach (pub_fixtures(['season_id' => (int) $s['id'], 'type' => 'all']) as $fx) {
        $urls[] = [$loc('match/' . (int) $fx['id']), null, $freq, '0.6'];
    }
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$u, $lastmod, $freq, $priority]) {
    echo '  <url><loc>' . e($u) . '</loc>';
    if ($lastmod) {
        echo '<lastmod>' . e($lastmod) . '</lastmod>';
    }
    echo '<changefreq>' . $freq . '</changefreq><priority>' . $priority . '</priority></url>' . "\n";
}
echo '</urlset>' . "\n";
