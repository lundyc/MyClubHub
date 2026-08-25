<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// ==========================================
// player_graphic_render.php
// ==========================================

require_once __DIR__ . '/db.php';

// ------------------------------------------------------------
// Get player ID
// ------------------------------------------------------------
$playerId = isset($_GET['player_id']) ? (int)$_GET['player_id'] : 0;
if (!$playerId) {
          http_response_code(400);
          exit('Missing player_id');
}

// ------------------------------------------------------------
// Fetch player + sponsor data
// ------------------------------------------------------------
$sql = "
SELECT 
    p.id,
    p.name,
    p.avatar,
    s1.name AS home_sponsor,
    s1.logo_path AS home_logo,
    s2.name AS away_sponsor,
    s2.logo_path AS away_logo
FROM players p
LEFT JOIN sponsorships sp1 ON sp1.player_id = p.id AND sp1.slot = 'home'
LEFT JOIN sponsors s1 ON s1.id = sp1.sponsor_id
LEFT JOIN sponsorships sp2 ON sp2.player_id = p.id AND sp2.slot = 'away'
LEFT JOIN sponsors s2 ON s2.id = sp2.sponsor_id
WHERE p.id = :id
LIMIT 1;
";

$stmt = $pdo->prepare($sql);
$stmt->execute(['id' => $playerId]);
$player = $stmt->fetch();

if (!$player) {
          http_response_code(404);
          exit('Player not found');
}

$playerName   = $player['name'];
$playerAvatar = $player['avatar'] ? __DIR__ . '/uploads/players/' . $player['avatar'] : null;

$homeSponsor  = $player['home_sponsor'];
$homeLogo     = $player['home_logo'] ? __DIR__ . '/uploads/sponsors/' . $player['home_logo'] : null;
$awaySponsor  = $player['away_sponsor'];
$awayLogo     = $player['away_logo'] ? __DIR__ . '/uploads/sponsors/' . $player['away_logo'] : null;

// ------------------------------------------------------------
// Image setup
// ------------------------------------------------------------
$width = 768;
$height = 768;
$im = imagecreatetruecolor($width, $height);

// Background
$bgPath = __DIR__ . "/assets/images/background.png";
if (file_exists($bgPath)) {
          $bg = imagecreatefrompng($bgPath);
          imagecopyresampled($im, $bg, 0, 0, 0, 0, $width, $height, imagesx($bg), imagesy($bg));
          imagedestroy($bg);
} else {
          $cream = imagecolorallocate($im, 242, 239, 231);
          imagefill($im, 0, 0, $cream);
}

// ------------------------------------------------------------
// Player image with scaling and offsets
// ------------------------------------------------------------
$panelX = 540;
$panelWidth = $width - $panelX;
$panelHeight = $height;
$inset = 6;

$playerScale   = isset($_GET['player_scale']) ? (float)$_GET['player_scale'] : 1.0;
$playerXOffset = isset($_GET['player_x_offset']) ? (int)$_GET['player_x_offset'] : 0;
$playerYOffset = isset($_GET['player_y_offset']) ? (int)$_GET['player_y_offset'] : 0;

if ($playerAvatar && file_exists($playerAvatar)) {
          $photo = imagecreatefromstring(file_get_contents($playerAvatar));
          $pw = imagesx($photo);
          $ph = imagesy($photo);

          $scale = max($panelWidth / $pw, $panelHeight / $ph) * $playerScale;
          $newW = $pw * $scale;
          $newH = $ph * $scale;

          $srcX = max(0, ($newW - $panelWidth) / (2 * $scale));
          $srcY = max(0, ($newH - $panelHeight) / (2 * $scale));
          $srcW = $panelWidth / $scale;
          $srcH = $panelHeight / $scale;

          imagecopyresampled(
                    $im,
                    $photo,
                    (int)($panelX + 42 + $playerXOffset),
                    (int)($inset + $playerYOffset),
                    (int)$srcX,
                    (int)$srcY,
                    (int)($panelWidth - $inset - 42),
                    (int)($panelHeight - ($inset * 2)),
                    (int)$srcW,
                    (int)$srcH
          );
          imagedestroy($photo);
} else {
          $basePanel = imagecolorallocate($im, 46, 46, 46);
          imagefilledrectangle(
                    $im,
                    (int)($panelX + 42),
                    (int)$inset,
                    (int)$width,
                    (int)$height,
                    $basePanel
          );
}

// ------------------------------------------------------------
// Colors + Fonts
// ------------------------------------------------------------
$black = imagecolorallocate($im, 0, 0, 0);
$gold  = imagecolorallocate($im, 197, 173, 116);
$pink  = imagecolorallocate($im, 255, 105, 180);

$fontDir = realpath(__DIR__ . "/assets/fonts/");
function safeFont($fontDir, $fontName, $fallbacks = [])
{
          $candidates = array_merge([$fontDir . "/" . $fontName], $fallbacks);
          foreach ($candidates as $path) {
                    if (file_exists($path) && is_readable($path)) return $path;
          }
          return false;
}
$fontMontserrat = safeFont($fontDir, "Montserrat-SemiBold.ttf", [$fontDir . "/Roboto-Regular.ttf"]);
$fontCinzel = safeFont($fontDir, "Cinzel-SemiBold.ttf", [$fontDir . "/Cinzel-Regular.ttf"]);

// ------------------------------------------------------------
// Player name header
// ------------------------------------------------------------
$textY = 227;
$textSize = 18;
$textOffset = -80;
$textFont = $fontMontserrat;
$textColor = imagecolorallocate($im, 0x40, 0x09, 0x13);

$textString = "$playerName, sponsored by:";
$bbox = imagettfbbox($textSize, 0, $textFont, $textString);
$textWidth = $bbox[2] - $bbox[0];
$centerX = (int)(($width - $textWidth) / 2) + $textOffset;

imagettftext($im, $textSize, 0, $centerX, (int)$textY, $textColor, $textFont, $textString);
$y = $textY + 35;

// ------------------------------------------------------------
// Sponsor section with independent control
// ------------------------------------------------------------
$sponsorX = 42;
$debugBorders = isset($_GET['debug']) ? (bool)$_GET['debug'] : false;
$slotHeight = 150;

$homeYOffset = isset($_GET['home_y_offset']) ? (int)$_GET['home_y_offset'] : 0;
$awayYOffset = isset($_GET['away_y_offset']) ? (int)$_GET['away_y_offset'] : 0;
$sameYOffset = isset($_GET['same_y_offset']) ? (int)$_GET['same_y_offset'] : 0;

$homeWidth = isset($_GET['home_width']) ? (int)$_GET['home_width'] : 500;
$awayWidth = isset($_GET['away_width']) ? (int)$_GET['away_width'] : 500;
$sameWidth = isset($_GET['same_width']) ? (int)$_GET['same_width'] : 500;

$currentY = $y;

// Detect if same sponsor
$isSameSponsor = ($homeSponsor && $awaySponsor && $homeSponsor === $awaySponsor);

// ---------------- SAME SPONSOR (centered) ----------------
if ($isSameSponsor) {
          $singleSponsor = $homeSponsor;
          $singleLogo = ($homeLogo && file_exists($homeLogo)) ? $homeLogo : (($awayLogo && file_exists($awayLogo)) ? $awayLogo : null);

          $middleY = $currentY + ($slotHeight / 2) + $sameYOffset;

          if ($debugBorders) {
                    imagerectangle(
                              $im,
                              (int)($sponsorX - 5),
                              (int)($middleY - ($slotHeight / 2)),
                              (int)($sponsorX + $sameWidth + 5),
                              (int)($middleY + ($slotHeight / 2)),
                              $pink
                    );
          }

          if ($singleLogo && file_exists($singleLogo)) {
                    $logo = imagecreatefromstring(file_get_contents($singleLogo));
                    $lw = imagesx($logo);
                    $lh = imagesy($logo);
                    $scale = $sameWidth / $lw;
                    $newW = $sameWidth;
                    $newH = $lh * $scale;
                    $centerX = (int)($sponsorX + ($sameWidth - $newW) / 2);
                    $topY = (int)($middleY - ($newH / 2));
                    imagecopyresampled($im, $logo, $centerX, $topY, 0, 0, $newW, $newH, $lw, $lh);
                    imagedestroy($logo);
          } else {
                    $bbox = imagettfbbox(26, 0, $fontMontserrat, $singleSponsor);
                    $textW = $bbox[2] - $bbox[0];
                    $textX = (int)($sponsorX + ($sameWidth - $textW) / 2);
                    imagettftext($im, 26, 0, $textX, (int)($middleY + 10), $black, $fontMontserrat, $singleSponsor);
          }
} else {
          // ---------------- HOME SPONSOR ----------------
          if ($homeSponsor) {
                    $homeY = $currentY + $homeYOffset;
                    if ($debugBorders) {
                              imagerectangle($im, $sponsorX - 5, $homeY - 5, $sponsorX + $homeWidth + 5, $homeY + $slotHeight, $pink);
                    }

                    if ($homeLogo && file_exists($homeLogo)) {
                              $logo = imagecreatefromstring(file_get_contents($homeLogo));
                              $lw = imagesx($logo);
                              $lh = imagesy($logo);
                              $scale = $homeWidth / $lw;
                              $newW = $homeWidth;
                              $newH = $lh * $scale;
                              $centerX = (int)($sponsorX + ($homeWidth - $newW) / 2);
                              imagecopyresampled($im, $logo, $centerX, (int)$homeY, 0, 0, (int)$newW, (int)$newH, (int)$lw, (int)$lh);
                              imagedestroy($logo);
                    } else {
                              $bbox = imagettfbbox(26, 0, $fontMontserrat, $homeSponsor);
                              $textW = $bbox[2] - $bbox[0];
                              $textX = (int)($sponsorX + ($homeWidth - $textW) / 2);
                              imagettftext($im, 26, 0, $textX, (int)($homeY + 60), $black, $fontMontserrat, $homeSponsor);
                    }
                    $currentY = $homeY + $slotHeight + 10;
          }

          // ---------------- AWAY SPONSOR ----------------
          if ($awaySponsor) {
                    $awayY = $currentY + $awayYOffset;
                    if ($debugBorders) {
                              imagerectangle($im, $sponsorX - 5, $awayY - 5, $sponsorX + $awayWidth + 5, $awayY + $slotHeight, $pink);
                    }

                    if ($awayLogo && file_exists($awayLogo)) {
                              $logo = imagecreatefromstring(file_get_contents($awayLogo));
                              $lw = imagesx($logo);
                              $lh = imagesy($logo);
                              $scale = $awayWidth / $lw;
                              $newW = $awayWidth;
                              $newH = $lh * $scale;
                              $centerX = (int)($sponsorX + ($awayWidth - $newW) / 2);
                              imagecopyresampled($im, $logo, $centerX, (int)$awayY, 0, 0, (int)$newW, (int)$newH, (int)$lw, (int)$lh);
                              imagedestroy($logo);
                    } else {
                              $bbox = imagettfbbox(26, 0, $fontMontserrat, $awaySponsor);
                              $textW = $bbox[2] - $bbox[0];
                              $textX = (int)($sponsorX + ($awayWidth - $textW) / 2);
                              imagettftext($im, 26, 0, $textX, (int)($awayY + 60), $black, $fontMontserrat, $awaySponsor);
                    }
          }

          // ---------------- NO SPONSORS ----------------
          if (!$homeSponsor && !$awaySponsor) {
                    $fallback = __DIR__ . "/assets/images/Available for Sponsor.png";
                    $fallback2 = __DIR__ . "/assets/images/Available for Sponsor 2.png";

                    if (file_exists($fallback)) {
                              $img = imagecreatefrompng($fallback);
                              imagecopyresampled($im, $img, 60, (int)$y, 0, 0, 500, 150, imagesx($img), imagesy($img));
                              imagedestroy($img);
                    }

                    if (file_exists($fallback2)) {
                              $img = imagecreatefrompng($fallback2);
                              imagecopyresampled($im, $img, 60, (int)($y + 170), 0, 0, 500, 150, imagesx($img), imagesy($img));
                              imagedestroy($img);
                    }
          }
}

// ------------------------------------------------------------
// Output
// ------------------------------------------------------------
header("Content-Type: image/png");
imagepng($im);
imagedestroy($im);
exit;
