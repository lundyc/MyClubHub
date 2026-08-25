<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/db.php';

// Fetch all active players
$players = $pdo->query("
  SELECT id, name, avatar
  FROM players
  WHERE active = 1
  ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Helper for escaping
function e($s)
{
          return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link rel="stylesheet" href="/assets/css/player-sponsors-starting11.css">

<div>
          <div class="page-hero mb-4">
                    <div class="page-hero-body">
                              <div class="page-hero-eyebrow">Creative studio</div>
                              <h1 class="page-hero-title">Starting 11 Lineup Builder</h1>
                              <p class="page-hero-subtitle">Arrange players into a starting lineup graphic with drag and scale controls.</p>
                    </div>
          </div>
          <div id="starting11-grid">
                    <?php for ($i = 1; $i <= 11; $i++): ?>
                              <div class="starting11-slot" data-slot="<?= $i ?>">
                                        <span class="slot-label"><?= $i ?></span>
                                        <div class="slot-photo-wrap">
                                                  <img class="slot-photo" id="photo-<?= $i ?>" src="" style="display:none;" draggable="false">
                                        </div>
                                        <div class="slot-player-name" id="name-<?= $i ?>"></div>
                                        <select class="slot-select" data-slot="<?= $i ?>">
                                                  <option value="">Select player...</option>
                                                  <?php foreach ($players as $p): ?>
                                                            <option value="<?= (int)$p['id'] ?>"
                                                                      data-avatar="<?= e($p['avatar']) ?>"
                                                                      data-name="<?= e($p['name']) ?>">
                                                                      <?= e($p['name']) ?>
                                                            </option>
                                                  <?php endforeach; ?>
                                        </select>
                              </div>
                    <?php endfor; ?>
          </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/interactjs/dist/interact.min.js"></script>
<script>
          const playerData = {};
          <?php foreach ($players as $p): ?>
                    playerData[<?= (int)$p['id'] ?>] = {
                              name: <?= json_encode($p['name']) ?>,
                              avatar: <?= json_encode($p['avatar'] ? 'uploads/players/' . $p['avatar'] : '') ?>
                    };
          <?php endforeach; ?>

          // Handle player selection for each slot
          document.querySelectorAll('.slot-select').forEach(sel => {
                    sel.addEventListener('change', function() {
                              const slot = this.getAttribute('data-slot');
                              const playerId = this.value;
                              const img = document.getElementById('photo-' + slot);
                              const nameDiv = document.getElementById('name-' + slot);

                              if (playerId && playerData[playerId]) {
                                        img.src = playerData[playerId].avatar;
                                        img.style.display = '';
                                        nameDiv.textContent = playerData[playerId].name;
                                        // Center image initially
                                        img.style.transform = 'translate(0px,0px) scale(1)';
                                        img.dataset.x = 0;
                                        img.dataset.y = 0;
                                        img.dataset.scale = 1;
                              } else {
                                        img.src = '';
                                        img.style.display = 'none';
                                        nameDiv.textContent = '';
                              }
                    });
          });

          // Interact.js for drag/scale inside each slot
          for (let i = 1; i <= 11; i++) {
                    const img = document.getElementById('photo-' + i);
                    interact(img).draggable({
                              listeners: {
                                        move(event) {
                                                  let target = event.target;
                                                  let x = (parseFloat(target.dataset.x) || 0) + event.dx;
                                                  let y = (parseFloat(target.dataset.y) || 0) + event.dy;
                                                  target.style.transform = `translate(${x}px,${y}px) scale(${target.dataset.scale || 1})`;
                                                  target.dataset.x = x;
                                                  target.dataset.y = y;
                                        }
                              }
                    }).gesturable({
                              listeners: {
                                        move(event) {
                                                  let target = event.target;
                                                  let scale = (parseFloat(target.dataset.scale) || 1) * (1 + event.ds);
                                                  // Clamp scale between 0.5 and 2.5
                                                  scale = Math.max(0.5, Math.min(2.5, scale));
                                                  target.style.transform = `translate(${target.dataset.x || 0}px,${target.dataset.y || 0}px) scale(${scale})`;
                                                  target.dataset.scale = scale;
                                        }
                              }
                    });
          }
</script>
<?php require __DIR__ . '/footer.php'; ?>
