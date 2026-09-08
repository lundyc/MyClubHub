<?php

declare(strict_types=1);

/**
 * Image upload endpoint for the news editor (hero image + inline EasyMDE
 * images). Returns JSON: {"url": "/uploads/news/YYYY/MM/xxxx.jpg"} or
 * {"error": "..."}.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/news.php';

header('Content-Type: application/json; charset=utf-8');

if (!hub_auth_is_authenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not signed in.']);
    exit;
}
if (!csrf_check()) {
    http_response_code(400);
    echo json_encode(['error' => 'Session expired — reload the page.']);
    exit;
}

$file = $_FILES['image'] ?? null;
if (!is_array($file)) {
    http_response_code(400);
    echo json_encode(['error' => 'No image received.']);
    exit;
}

$result = news_store_upload($file);
if (!$result['ok']) {
    http_response_code(422);
    echo json_encode(['error' => $result['error']]);
    exit;
}

echo json_encode(['url' => $result['url'], 'path' => $result['path']]);
