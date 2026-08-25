<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

$seasonId = getSelectedSeasonId($pdo);
if (isset($_GET['season_id'])) {
          $requestedSeasonId = (int)$_GET['season_id'];
          if ($requestedSeasonId > 0) {
                    $seasonId = $requestedSeasonId;
          }
}

$season = getSeasonById($pdo, $seasonId);

$defaultBackgroundImage = '../assets/images/background.png';
$backgroundDir = __DIR__ . '/uploads/match_fixtures';
$backgroundImage = trim((string)($_GET['background_image'] ?? (matchPosterSeasonBackgroundImage($seasonId) ?? $defaultBackgroundImage)));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['background_image_file'])) {
          if (!csrf_check()) {
                    http_response_code(400);
                    exit('Invalid CSRF token.');
          }

          $file = $_FILES['background_image_file'];
          if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
                              exit('Background upload failed.');
                    }
                    if (!is_uploaded_file($file['tmp_name'])) {
                              exit('Invalid background upload.');
                    }

                    $mimeInfo = @getimagesize($file['tmp_name']);
                    if (!$mimeInfo || !in_array($mimeInfo[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
                              exit('Please upload a PNG, JPG, or WEBP image.');
                    }

                    if (!is_dir($backgroundDir) && !mkdir($backgroundDir, 0775, true) && !is_dir($backgroundDir)) {
                              exit('Failed to prepare background upload directory.');
                    }

                    $extension = match ($mimeInfo[2]) {
                              IMAGETYPE_PNG => 'png',
                              IMAGETYPE_JPEG => 'jpg',
                              IMAGETYPE_WEBP => 'webp',
                    };
                    $filename = sprintf('match_poster_bg_season_%d.%s', $seasonId, $extension);
                    $destination = $backgroundDir . DIRECTORY_SEPARATOR . $filename;

                    if (!move_uploaded_file($file['tmp_name'], $destination)) {
                              exit('Failed to store background image.');
                    }

                    $backgroundImage = 'uploads/match_fixtures/' . $filename;
                    header('Location: match_fixtures_poster.php?season_id=' . (int)$seasonId . '&background_image=' . urlencode($backgroundImage) . '&uploaded=1');
                    exit;
          }
}

$previewParams = array_merge([
          'season_id' => $seasonId,
          'background_image' => $backgroundImage,
]);
$previewUrl = 'match_fixtures_poster_render.php?' . http_build_query($previewParams);

$fixtures = getMatchFixtures($pdo, $seasonId);
?>

<div class="page-hero mb-4">
                    <div class="page-hero-body d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
                    <div>
                              <div class="page-hero-eyebrow">Creative studio</div>
                              <h1 class="page-hero-title">Fixture Poster</h1>
                              <p class="page-hero-subtitle"><?= h($season['name'] ?? 'Unknown season') ?></p>
                    </div>
          </div>
</div>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="matches.php?season_id=<?= (int)$seasonId ?>">Fixtures</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Fixture poster</span></nav>
<div class="hub-section-commandbar"><div><h2>Poster workspace</h2><p>Configure the season artwork and export the current fixture list.</p></div><div class="hub-local-actions"><a href="<?= h($previewUrl) ?>" class="btn btn-brand btn-sm" target="_blank"><i class="fa-solid fa-download me-1" aria-hidden="true"></i>Download PNG</a></div></div>

<?php if (isset($_GET['uploaded'])): ?>
          <div class="alert alert-success">Background image uploaded.</div>
<?php endif; ?>

<div class="row g-3">
          <div class="col-12 col-xl-4">
                    <div class="card shadow-sm">
                              <div class="card-body">
                                        <form method="get" class="vstack gap-3 mb-3">
                                                  <?= csrf_field() ?>
                                                  <div>
                                                            <label class="form-label">Season</label>
                                                            <select name="season_id" class="form-select">
                                                                      <?php foreach (getSeasons($pdo) as $option): ?>
                                                                                <option value="<?= (int)$option['id'] ?>" <?= (int)$option['id'] === (int)$seasonId ? 'selected' : '' ?>>
                                                                                          <?= h((string)$option['name']) ?>
                                                                               </option>
                                                                      <?php endforeach; ?>
                                                            </select>
                                                  </div>
                                                  <div class="d-grid">
                                                            <button type="submit" class="btn btn-outline-secondary">Change Season</button>
                                                  </div>
                                        </form>

                                        <form method="post" enctype="multipart/form-data" class="vstack gap-3">
                                                  <?= csrf_field() ?>
                                                  <input type="hidden" name="season_id" value="<?= (int)$seasonId ?>">
                                                  <div>
                                                            <label class="form-label">Upload background image</label>
                                                            <input type="file" name="background_image_file" class="form-control" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp">
                                                            <div class="form-text">Upload the full poster background. PNG, JPG, or WEBP only.</div>
                                                  </div>
                                                  <div class="d-grid gap-2">
                                                            <button type="submit" class="btn btn-brand">Upload Background</button>
                                                            <a href="<?= h($previewUrl) ?>" class="btn btn-outline-secondary" target="_blank">Open Render</a>
                                                  </div>
                                        </form>
                              </div>
                    </div>

          </div>

          <div class="col-12 col-xl-8">
                    <div class="card shadow-sm rounded-0 overflow-hidden">
                              <div class="card-body p-0">
                                        <div class="poster-stage">
                                                  <img src="<?= h($previewUrl) ?>" alt="Fixture poster preview" class="poster-stage__image">
                                        </div>
                              </div>
                    </div>
          </div>
</div>

<link rel="stylesheet" href="/assets/css/player-sponsors-match-fixtures-poster.css">

<?php require_once __DIR__ . '/footer.php'; ?>
