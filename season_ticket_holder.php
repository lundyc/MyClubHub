<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/people.php';

$holderId = (int) ($_GET['id'] ?? 0);
if ($holderId > 0) {
    $person = getPersonByLegacyHolderId($pdo, $holderId);
    if ($person) {
        header('Location: /club_person.php?id=' . (int) $person['id'], true, 302);
        exit;
    }
}

header('Location: /club_people.php', true, 302);
exit;
