<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('football_ops')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once 'db_connection.php';
include 'header.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$player = ['name' => '', 'is_active' => 1];
if ($id) {
          $st = $pdo->prepare("SELECT * FROM players WHERE id = :id");
          $st->execute([':id' => $id]);
          $player = $st->fetch() ?: $player;
}
?>
<h1 class="h4 mb-3"><?= $id ? 'Edit Player' : 'Add Player' ?></h1>

<form method="post" action="players_save.php" class="card shadow-sm p-3">
          <input type="hidden" name="id" value="<?= $id ?>">
          <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input required name="name" class="form-control" value="<?= htmlspecialchars($player['name']) ?>">
          </div>
          <?php if ($id): ?>
                    <div class="form-check mb-3">
                              <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= $player['is_active'] ? 'checked' : '' ?>>
                              <label class="form-check-label" for="is_active">Active</label>
                    </div>
          <?php endif; ?>
          <div class="d-flex gap-2">
                    <button class="btn btn-primary">Save</button>
                    <a class="btn btn-secondary" href="players_list.php">Cancel</a>
          </div>
</form>
<?php include 'footer.php'; ?>