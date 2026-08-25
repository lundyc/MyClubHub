<?php
// logo-proxy.php
// Fetches a remote club badge, caches it locally, and serves it from same-origin
// so html2canvas can include it in exports.

error_reporting(0);

$url = isset($_GET['u']) ? $_GET['u'] : '';
if (!$url) {
          http_response_code(400);
          exit('Missing u');
}

// Basic allowlist (tighten to the domains you actually need)
$allowed = [
          'images.leaguerepublic.com',
          'leaguerepublic.com'
];

$parts = parse_url($url);
if (!isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['host']), $allowed)) {
          http_response_code(403);
          exit('Blocked host');
}

$cacheDir  = __DIR__ . '/cache/logos';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);

$ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
$ext = $ext ? strtolower($ext) : 'png';
$hash = md5($url);
$cacheFile = "$cacheDir/$hash.$ext";

// Serve from cache if present (7 days)
if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 60 * 60 * 24 * 7)) {
          $mime = mime_content_type($cacheFile);
          header("Content-Type: $mime");
          header("Cache-Control: public, max-age=604800"); // 7 days
          readfile($cacheFile);
          exit;
}

// Fetch via cURL
$ch = curl_init($url);
curl_setopt_array($ch, [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_TIMEOUT => 10,
          CURLOPT_USERAGENT => 'Mozilla/5.0',
]);
$img = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($img === false || $code !== 200) {
          http_response_code(502);
          exit('Fetch failed');
}

// Save cache
file_put_contents($cacheFile, $img);

// Serve
$mime = $contentType ?: mime_content_type($cacheFile) ?: 'image/png';
header("Content-Type: $mime");
header("Cache-Control: public, max-age=604800");
readfile($cacheFile);
