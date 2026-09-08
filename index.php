<?php

declare(strict_types=1);

/*
 * Front controller for the public site. Every request that is not a real file
 * under public/ is rewritten here by .htaccess.
 */

require __DIR__ . '/bootstrap.php';

[$handler, $params] = public_router_match(public_request_path());

/*
 * Back-compat: the Hub used to live at the web root, so old bookmarks and
 * external links point at flat /whatever.php paths. Bounce those to
 * /admin/whatever.php. The root .htaccess already does this, but a stale
 * nginx `try_files … /index.php` directive can shadow the Apache rule, so
 * the front controller owns the fallback too.
 */
if (($handler['status'] ?? 200) === 404) {
    $requested = public_request_path();
    if (preg_match('#^[A-Za-z0-9_-]+\.php$#', $requested) && !is_file(__DIR__ . '/' . $requested)) {
        header('Location: /admin/' . $requested, true, 301);
        exit;
    }
}

public_render($handler, $params);
