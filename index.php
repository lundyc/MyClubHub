<?php

declare(strict_types=1);

/*
 * Front controller for the public site. Every request that is not a real file
 * under public/ is rewritten here by .htaccess.
 */

require __DIR__ . '/bootstrap.php';

[$handler, $params] = public_router_match(public_request_path());
public_render($handler, $params);
