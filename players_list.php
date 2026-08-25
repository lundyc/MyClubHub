<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('football_ops')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once 'db_connection.php';
include 'header.php';

$players = $pdo->query("SELECT * FROM players ORDER BY is_active DESC, created_at ASC")->fetchAll();
?>
<div class="d-flex align-items-center mb-3">
          <h1 class="h4 mb-0">Players</h1>
          <div class="ms-auto">
                    <a class="btn btn-primary btn-sm" href="players_form.php">Add Player</a>
          </div>
</div>

<div class="card shadow-sm">
          <div class="card-body p-0">
                    <div class="table-responsive">
                              <table class="table table-striped align-middle mb-0">
                                        <thead class="table-dark">
                                                  <tr>
                                                            <th>Name</th>
                                                            <th>Status</th>
                                                            <th class="text-end">Actions</th>
                                                  </tr>
                                        </thead>
                                        <tbody>
                                                  <?php foreach ($players as $p): ?>
                                                            <tr>
                                                                      <td><?= htmlspecialchars($p['name']) ?></td>
                                                                      <td><?= $p['is_active'] ? 'Active' : 'Left' ?></td>
                                                                      <td class="text-end">
                                                                                <a class="btn btn-sm btn-outline-primary" href="players_form.php?id=<?= (int)$p['id'] ?>">Edit</a>
                                                                                <a class="btn btn-sm btn-outline-danger" href="players_delete.php?id=<?= (int)$p['id'] ?>"
                                                                                          onclick="return confirm('Delete player? This will also remove assignment history.');">Delete</a>
                                                                                <?php if ($p['is_active']): ?>
                                                                                          <a class="btn btn-sm btn-outline-warning" href="players_leave.php?id=<?= (int)$p['id'] ?>"
                                                                                                    onclick="return confirm('Open the leave and sponsorship transfer form for this player?');">Mark Left</a>
                                                                                <?php endif; ?>
                                                                      </td>
                                                            </tr>
                                                  <?php endforeach; ?>
                                        </tbody>
                              </table>
                    </div>
          </div>
</div>
<?php include 'footer.php'; ?>
