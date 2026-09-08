<?php
/** Route: /robots.txt (effective once the site is served from the domain root). */
declare(strict_types=1);

pub_raw();
header('Content-Type: text/plain; charset=utf-8');

$origin = current_url_origin();
echo "User-agent: *\n";
echo "Allow: /\n";
echo "Sitemap: " . $origin . url('sitemap.xml') . "\n";
