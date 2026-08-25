<?php
// ==========================================
// player_graphics.php — Player sponsorship graphic generator
// ==========================================

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/functions.php';

$players = $pdo->query("
    SELECT id, name
    FROM players
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$selectedId = (int)($_GET['player_id'] ?? 0);
if (!$selectedId && $players) {
  $selectedId = (int)$players[0]['id'];
}

// Default GET parameters for testing / preview
$params = [
  'debug' => 1,
  'player_scale' => $_GET['player_scale'] ?? 1.0,
  'player_x_offset' => $_GET['player_x_offset'] ?? 0,
  'player_y_offset' => $_GET['player_y_offset'] ?? 0,
  'home_y_offset' => $_GET['home_y_offset'] ?? 0,
  'home_width' => $_GET['home_width'] ?? 500,
  'away_y_offset' => $_GET['away_y_offset'] ?? 0,
  'away_width' => $_GET['away_width'] ?? 500,
  'same_y_offset' => $_GET['same_y_offset'] ?? 0,
  'same_width' => $_GET['same_width'] ?? 500
];

function buildQuery($arr)
{
  return http_build_query($arr);
}
?>

<div>
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <div>
      <h1 class="h3 mb-0">Player Graphic Generator</h1>
      <p class="text-muted mb-0">Create social-ready graphics for sponsored players.</p>
    </div>
    <div>
      <a href="players.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>Back to Players
      </a>
    </div>
  </div>

  <?php if (!$players): ?>
    <div class="alert alert-warning">No players found. Add players first to generate graphics.</div>
  <?php else: ?>
    <div class="card shadow-sm border-0 mb-4">
      <div class="card-body">
        <form class="row gy-2 gx-3 align-items-center" id="controlsForm">
          <div class="col-md-3">
            <label for="playerSelect" class="form-label mb-1">Select Player</label>
            <select id="playerSelect" name="player_id" class="form-select">
              <?php foreach ($players as $player): ?>
                <option value="<?= (int)$player['id'] ?>" <?= $player['id'] == $selectedId ? 'selected' : '' ?>>
                  <?= h($player['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-9">
            <div class="row gy-1 gx-2">
              <!-- Player Image Controls -->
              <div class="col-md-4">
                <label class="form-label mb-0 fw-bold">Player Image</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text">Scale</span>
                  <input type="number" step="0.1" name="player_scale" class="form-control" value="<?= h($params['player_scale']) ?>">
                  <span class="input-group-text">X</span>
                  <input type="number" name="player_x_offset" class="form-control" value="<?= h($params['player_x_offset']) ?>">
                  <span class="input-group-text">Y</span>
                  <input type="number" name="player_y_offset" class="form-control" value="<?= h($params['player_y_offset']) ?>">
                </div>
              </div>

              <!-- Home Sponsor Controls -->
              <div class="col-md-4">
                <label class="form-label mb-0 fw-bold">Home Sponsor</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text">Y</span>
                  <input type="number" name="home_y_offset" class="form-control" value="<?= h($params['home_y_offset']) ?>">
                  <span class="input-group-text">W</span>
                  <input type="number" name="home_width" class="form-control" value="<?= h($params['home_width']) ?>">
                </div>
              </div>

              <!-- Away Sponsor Controls -->
              <div class="col-md-4">
                <label class="form-label mb-0 fw-bold">Away Sponsor</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text">Y</span>
                  <input type="number" name="away_y_offset" class="form-control" value="<?= h($params['away_y_offset']) ?>">
                  <span class="input-group-text">W</span>
                  <input type="number" name="away_width" class="form-control" value="<?= h($params['away_width']) ?>">
                </div>
              </div>

              <!-- Same Sponsor Controls -->
              <div class="col-md-4">
                <label class="form-label mb-0 fw-bold">Same Sponsor</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text">Y</span>
                  <input type="number" name="same_y_offset" class="form-control" value="<?= h($params['same_y_offset']) ?>">
                  <span class="input-group-text">W</span>
                  <input type="number" name="same_width" class="form-control" value="<?= h($params['same_width']) ?>">
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 mt-3 text-end">
            <button type="submit" class="btn btn-brand btn-sm">
              <i class="fa-solid fa-rotate me-1"></i>Update Preview
            </button>
          </div>
        </form>
      </div>
    </div>

    <div class="card shadow-sm border-0">
      <div class="card-body bg-light">
        <div class="text-center mb-3">
          <small class="text-muted">Preview updates automatically when you change the controls.</small>
        </div>
        <div class="d-flex justify-content-center">
          <div class="bg-white border rounded shadow-sm p-2">
            <img id="graphicPreview"
              src="player_graphic_render.php?player_id=<?= $selectedId ?>&<?= buildQuery($params) ?>&t=<?= time() ?>"
              alt="Player graphic preview"
              class="img-fluid"
              style="max-width: 540px;">
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
  (function() {
    const form = document.getElementById('controlsForm');
    const preview = document.getElementById('graphicPreview');
    const playerSelect = document.getElementById('playerSelect');

    function updatePreview() {
      const formData = new FormData(form);
      const params = new URLSearchParams(formData);
      const url = 'player_graphic_render.php?' + params.toString() + '&t=' + Date.now();
      preview.src = url;
    }

    form.addEventListener('submit', function(e) {
      e.preventDefault();
      updatePreview();
    });

    playerSelect.addEventListener('change', updatePreview);
  })();
</script>

<?php require __DIR__ . '/footer.php'; ?>
