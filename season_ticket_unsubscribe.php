<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season_tickets.php';
ensureSeasonTicketSchema($pdo);

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$holder = null;
if ($token !== '') {
    $stmt = $pdo->prepare('SELECT * FROM season_ticket_holders WHERE unsubscribe_token = :token LIMIT 1');
    $stmt->execute([':token' => $token]);
    $holder = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$done = false;
if ($holder && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $pdo->prepare('UPDATE season_ticket_holders SET marketing_opt_in = 0, unsubscribed_at = NOW() WHERE id = :id')
        ->execute([':id' => $holder['id']]);
    $done = true;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unsubscribe - <?= h(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/login.css">
</head>
<body>
    <div class="login-card">
        <img src="/Saltcoats Victoria FC -White_Transparent.png" alt="Saltcoats Victoria FC" class="login-card__logo" loading="eager">
        <h1 class="h3">Mailing list</h1>
        <?php if (!$holder): ?>
            <p class="login-card__intro">That unsubscribe link isn't valid. If you'd like to be removed from our mailing list, please contact the club directly.</p>
        <?php elseif ($done): ?>
            <div class="alert alert-success" role="status">You've been unsubscribed, <?= h((string) $holder['name']) ?>. You won't receive any more club offers or deals by email.</div>
            <p class="login-card__intro">Changed your mind? Contact the club to be added back.</p>
        <?php elseif (!empty($holder['unsubscribed_at']) && (int) $holder['marketing_opt_in'] === 0): ?>
            <div class="alert alert-info" role="status">You're already unsubscribed, <?= h((string) $holder['name']) ?>.</div>
        <?php else: ?>
            <p class="login-card__intro">Hi <?= h((string) $holder['name']) ?>, click below to stop receiving club offers and deals by email. You'll still hear from us about important season ticket matters.</p>
            <form method="post">
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <button type="submit" class="btn btn-brand w-100">Unsubscribe me</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
