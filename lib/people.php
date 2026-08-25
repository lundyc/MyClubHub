<?php
declare(strict_types=1);

require_once __DIR__ . '/season_tickets.php';
require_once __DIR__ . '/season.php';
require_once __DIR__ . '/audit.php';

function ensurePersonPositionsDateSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $tableExists = (bool) $pdo->query("SHOW TABLES LIKE 'person_positions'")->fetchColumn();
    if (!$tableExists) {
        return;
    }

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM person_positions') as $row) {
        $columns[(string) $row['Field']] = true;
    }

    if (!isset($columns['start_date'])) {
        $pdo->exec('ALTER TABLE person_positions ADD COLUMN start_date DATE NULL AFTER season_id');
        $pdo->exec('UPDATE person_positions pp LEFT JOIN seasons s ON s.id = pp.season_id SET pp.start_date = s.start_date WHERE pp.start_date IS NULL');
    }
    if (!isset($columns['end_date'])) {
        $pdo->exec('ALTER TABLE person_positions ADD COLUMN end_date DATE NULL AFTER start_date');
        $pdo->exec('UPDATE person_positions pp LEFT JOIN seasons s ON s.id = pp.season_id SET pp.end_date = s.end_date WHERE pp.end_date IS NULL');
    }

    $indexes = [];
    foreach ($pdo->query('SHOW INDEX FROM person_positions') as $row) {
        $indexes[(string) $row['Key_name']] = true;
    }
    if (!isset($indexes['idx_person_positions_dates'])) {
        $pdo->exec('ALTER TABLE person_positions ADD KEY idx_person_positions_dates (person_id, start_date, end_date)');
    }
}

function people_normalize_email(?string $email): ?string
{
    return seasonTicketNormalizeEmail($email);
}

function getPerson(PDO $pdo, int $personId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM people WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $personId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getPersonByLegacyHolderId(PDO $pdo, int $holderId): ?array
{
    $stmt = $pdo->prepare('SELECT p.*, m.old_holder_id, m.account_id
        FROM identity_migration_map m
        JOIN people p ON p.id = m.person_id
        WHERE m.old_holder_id = :holder_id
        LIMIT 1');
    $stmt->execute([':holder_id' => $holderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getLegacyHolderIdForPerson(PDO $pdo, int $personId): ?int
{
    $stmt = $pdo->prepare('SELECT old_holder_id FROM identity_migration_map WHERE person_id = :person_id LIMIT 1');
    $stmt->execute([':person_id' => $personId]);
    $value = $stmt->fetchColumn();
    return $value !== false ? (int) $value : null;
}

function personIdFromLegacyHolderId(PDO $pdo, int $holderId): ?int
{
    $stmt = $pdo->prepare('SELECT person_id FROM identity_migration_map WHERE old_holder_id = :holder_id LIMIT 1');
    $stmt->execute([':holder_id' => $holderId]);
    $value = $stmt->fetchColumn();
    return $value !== false ? (int) $value : null;
}

function legacyHolderIdFromPersonId(PDO $pdo, int $personId): ?int
{
    return getLegacyHolderIdForPerson($pdo, $personId);
}

/**
 * Transitional bridge for legacy ticketing tables that still require a
 * season_ticket_holders.id. New identity must begin with people/accounts; this
 * adapter is the only normal place that should create a holder row now.
 *
 * @param array<string,mixed> $overrides
 */
function ensureLegacyHolderForPerson(PDO $pdo, int $personId, array $overrides = []): int
{
    $person = getPerson($pdo, $personId);
    if (!$person) {
        throw new RuntimeException('Person not found.');
    }

    $account = function_exists('getAccountByPersonId') ? getAccountByPersonId($pdo, $personId) : null;
    $mappedHolderId = getLegacyHolderIdForPerson($pdo, $personId);
    if ($mappedHolderId !== null) {
        $data = array_merge($person, $overrides);
        saveSeasonTicketHolder($pdo, $mappedHolderId, [
            'name' => (string) ($data['display_name'] ?? ''),
            'date_of_birth' => (string) ($data['date_of_birth'] ?? ''),
            'email' => (string) ($data['email'] ?? ''),
            'phone' => (string) ($data['phone'] ?? ''),
            'address_line1' => (string) ($data['address_line1'] ?? ''),
            'address_line2' => (string) ($data['address_line2'] ?? ''),
            'town' => (string) ($data['town'] ?? ''),
            'postcode' => (string) ($data['postcode'] ?? ''),
            'country' => (string) ($data['country'] ?? ''),
            'profile_image_path' => (string) ($data['profile_image_path'] ?? ''),
            'managed_by_holder_id' => $data['managed_by_holder_id'] ?? null,
            'marketing_opt_in' => !empty($data['marketing_opt_in']) ? 1 : 0,
        ]);
        if ($account && empty($account['old_holder_id'])) {
            $pdo->prepare('UPDATE identity_migration_map SET account_id = :account_id WHERE old_holder_id = :holder_id')
                ->execute([':account_id' => (int) $account['id'], ':holder_id' => $mappedHolderId]);
        }
        return $mappedHolderId;
    }

    $email = trim((string) ($overrides['email'] ?? $person['email'] ?? ''));
    $holderId = null;
    if ($email !== '') {
        $existingHolder = findSeasonTicketHolderByEmail($pdo, $email);
        if ($existingHolder) {
            $existingPersonId = personIdFromLegacyHolderId($pdo, (int) $existingHolder['id']);
            if ($existingPersonId === null || $existingPersonId === $personId) {
                $holderId = (int) $existingHolder['id'];
            } else {
                $email = '';
            }
        }
    }

    $data = array_merge($person, $overrides);
    $holderId = saveSeasonTicketHolder($pdo, $holderId, [
        'name' => (string) ($data['display_name'] ?? ''),
        'date_of_birth' => (string) ($data['date_of_birth'] ?? ''),
        'email' => $email,
        'phone' => (string) ($data['phone'] ?? ''),
        'address_line1' => (string) ($data['address_line1'] ?? ''),
        'address_line2' => (string) ($data['address_line2'] ?? ''),
        'town' => (string) ($data['town'] ?? ''),
        'postcode' => (string) ($data['postcode'] ?? ''),
        'country' => (string) ($data['country'] ?? ''),
        'profile_image_path' => (string) ($data['profile_image_path'] ?? ''),
        'managed_by_holder_id' => $data['managed_by_holder_id'] ?? null,
        'marketing_opt_in' => !empty($data['marketing_opt_in']) ? 1 : 0,
        'notes' => 'Transitional ticketing adapter for person #' . $personId,
    ]);

    $pdo->prepare('INSERT INTO identity_migration_map (old_holder_id, person_id, account_id)
        VALUES (:holder_id, :person_id, :account_id)
        ON DUPLICATE KEY UPDATE person_id = VALUES(person_id), account_id = VALUES(account_id)')
        ->execute([
            ':holder_id' => $holderId,
            ':person_id' => $personId,
            ':account_id' => $account ? (int) $account['id'] : null,
        ]);
    identityAuditLog($pdo, 'legacy_holder_adapter_created', 'Created holder adapter #' . $holderId . ' for person #' . $personId);
    return $holderId;
}

/**
 * Compatibility shim -- identity actions used to log through here directly;
 * the real implementation (and the session-key/FK fix) now lives in
 * lib/audit.php's auditLog(), shared by the whole Hub.
 */
function identityAuditLog(PDO $pdo, string $action, string $details = ''): void
{
    auditLog($pdo, $action, $details);
}

function createPerson(PDO $pdo, array $data): int
{
    $displayName = trim((string) ($data['display_name'] ?? $data['name'] ?? ''));
    if ($displayName === '') {
        throw new InvalidArgumentException('Display name is required.');
    }
    $email = trim((string) ($data['email'] ?? ''));
    $stmt = $pdo->prepare('INSERT INTO people
        (display_name, date_of_birth, email, email_normalized, phone, address_line1, address_line2, town, postcode, country, marketing_opt_in, is_active)
        VALUES (:display_name, :date_of_birth, :email, :email_normalized, :phone, :address_line1, :address_line2, :town, :postcode, :country, :marketing_opt_in, :is_active)');
    $stmt->execute([
        ':display_name' => $displayName,
        ':date_of_birth' => trim((string) ($data['date_of_birth'] ?? '')) ?: null,
        ':email' => $email !== '' ? $email : null,
        ':email_normalized' => people_normalize_email($email),
        ':phone' => trim((string) ($data['phone'] ?? '')) ?: null,
        ':address_line1' => trim((string) ($data['address_line1'] ?? '')) ?: null,
        ':address_line2' => trim((string) ($data['address_line2'] ?? '')) ?: null,
        ':town' => trim((string) ($data['town'] ?? '')) ?: null,
        ':postcode' => trim((string) ($data['postcode'] ?? '')) ?: null,
        ':country' => trim((string) ($data['country'] ?? '')) ?: null,
        ':marketing_opt_in' => !empty($data['marketing_opt_in']) ? 1 : 0,
        ':is_active' => array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1,
    ]);
    $personId = (int) $pdo->lastInsertId();
    identityAuditLog($pdo, 'person_created', 'Created person #' . $personId . ' (' . $displayName . ')');
    return $personId;
}

function updatePersonProfileImage(PDO $pdo, int $personId, string $profileImagePath): void
{
    $pdo->prepare('UPDATE people SET profile_image_path = :path WHERE id = :id')
        ->execute([':path' => trim($profileImagePath) ?: null, ':id' => $personId]);
    $holderId = getLegacyHolderIdForPerson($pdo, $personId);
    if ($holderId !== null) {
        $pdo->prepare('UPDATE season_ticket_holders SET profile_image_path = :path WHERE id = :id')
            ->execute([':path' => trim($profileImagePath) ?: null, ':id' => $holderId]);
    }
    identityAuditLog($pdo, 'person_profile_image_changed', 'Updated profile image for person #' . $personId);
}

/**
 * @param array<string,mixed> $filters
 * @return list<array<string,mixed>>
 */
function getPeopleDirectory(PDO $pdo, array $filters = []): array
{
    $where = [];
    $params = [];
    if (!empty($filters['search'])) {
        $where[] = '(p.display_name LIKE :search OR p.email LIKE :search OR p.phone LIKE :search)';
        $params[':search'] = '%' . (string) $filters['search'] . '%';
    }
    if (($filters['status'] ?? '') === 'active') {
        $where[] = 'p.is_active = 1';
    } elseif (($filters['status'] ?? '') === 'archived') {
        $where[] = 'p.is_active = 0';
    }
    if (($filters['account'] ?? '') === 'with') {
        $where[] = 'a.id IS NOT NULL';
    } elseif (($filters['account'] ?? '') === 'without') {
        $where[] = 'a.id IS NULL';
    }
    if (!empty($filters['position_id'])) {
        $where[] = 'pp.position_id = :position_id';
        $params[':position_id'] = (int) $filters['position_id'];
    }
    if (!empty($filters['role'])) {
        $where[] = 'r.code = :role';
        $params[':role'] = (string) $filters['role'];
    }
    if (($filters['login'] ?? '') === 'never') {
        $where[] = 'a.last_login_at IS NULL';
    } elseif (($filters['login'] ?? '') === 'active') {
        $where[] = 'a.last_login_at IS NOT NULL';
    }

    // position_names/the position_id filter reflect positions that are
    // CURRENT BY DATE (start_date <= today <= end_date, or no end_date) --
    // this is a roster list, not a history view (that's club_person.php's
    // Positions tab). A past Chairman shouldn't still show up here as one.
    // Matches getCurrentPersonPositions()'s own definition of "current";
    // season_id is bookkeeping for reporting, not the source of truth for
    // whether an assignment is active today (an ongoing position started in
    // a prior season, with no end date, is still current).
    $sql = "SELECT p.*, m.old_holder_id, a.id AS account_id, a.email AS account_email, a.is_active AS account_is_active,
               a.last_login_at,
               GROUP_CONCAT(DISTINCT hp.name ORDER BY hp.name SEPARATOR ', ') AS position_names,
               GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ',') AS role_codes,
               COUNT(DISTINCT pr.dependent_person_id) AS dependents_count
        FROM people p
        LEFT JOIN identity_migration_map m ON m.person_id = p.id
        LEFT JOIN accounts a ON a.person_id = p.id
        LEFT JOIN account_roles ar ON ar.account_id = a.id
        LEFT JOIN roles r ON r.id = ar.role_id
        LEFT JOIN person_positions pp ON pp.person_id = p.id
            AND pp.start_date <= CURDATE()
            AND (pp.end_date IS NULL OR pp.end_date >= CURDATE())
        LEFT JOIN hub_positions hp ON hp.id = pp.position_id
        LEFT JOIN person_relationships pr ON pr.manager_person_id = p.id
        " . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "
        GROUP BY p.id, m.old_holder_id, a.id
        ORDER BY p.display_name, p.id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function updatePerson(PDO $pdo, int $personId, array $data, bool $syncLegacyHolder = true): void
{
    $email = trim((string) ($data['email'] ?? ''));
    $params = [
        ':id' => $personId,
        ':display_name' => trim((string) ($data['display_name'] ?? '')),
        ':date_of_birth' => trim((string) ($data['date_of_birth'] ?? '')) ?: null,
        ':email' => $email !== '' ? $email : null,
        ':email_normalized' => people_normalize_email($email),
        ':phone' => trim((string) ($data['phone'] ?? '')) ?: null,
        ':address_line1' => trim((string) ($data['address_line1'] ?? '')) ?: null,
        ':address_line2' => trim((string) ($data['address_line2'] ?? '')) ?: null,
        ':town' => trim((string) ($data['town'] ?? '')) ?: null,
        ':postcode' => trim((string) ($data['postcode'] ?? '')) ?: null,
        ':country' => trim((string) ($data['country'] ?? '')) ?: null,
        ':marketing_opt_in' => !empty($data['marketing_opt_in']) ? 1 : 0,
        ':is_active' => !empty($data['is_active']) ? 1 : 0,
    ];
    if ($params[':display_name'] === '') {
        throw new InvalidArgumentException('Display name is required.');
    }

    $pdo->prepare('UPDATE people
        SET display_name = :display_name, date_of_birth = :date_of_birth, email = :email,
            email_normalized = :email_normalized, phone = :phone, address_line1 = :address_line1,
            address_line2 = :address_line2, town = :town, postcode = :postcode, country = :country,
            marketing_opt_in = :marketing_opt_in, is_active = :is_active
        WHERE id = :id')->execute($params);
    identityAuditLog($pdo, 'person_updated', 'Updated person #' . $personId);

    if ($syncLegacyHolder) {
        $holderId = getLegacyHolderIdForPerson($pdo, $personId);
        if ($holderId !== null) {
            $holder = getSeasonTicketHolder($pdo, $holderId) ?: [];
            // Temporary dual-write while season tickets and member pages still
            // use season_ticket_holders as their compatibility record.
            saveSeasonTicketHolder($pdo, $holderId, [
                'name' => $params[':display_name'],
                'date_of_birth' => $params[':date_of_birth'],
                'email' => $params[':email'],
                'phone' => $params[':phone'],
                'address_line1' => $params[':address_line1'],
                'address_line2' => $params[':address_line2'],
                'town' => $params[':town'],
                'postcode' => $params[':postcode'],
                'country' => $params[':country'],
                'profile_image_path' => $holder['profile_image_path'] ?? '',
                'sponsor_id' => $holder['sponsor_id'] ?? null,
                'managed_by_holder_id' => $holder['managed_by_holder_id'] ?? null,
                'marketing_opt_in' => $params[':marketing_opt_in'],
                'notes' => $holder['notes'] ?? '',
            ]);
            if ((int) $params[':is_active'] !== 1) {
                deleteSeasonTicketHolder($pdo, $holderId);
            }
        }
    }
}

function archivePerson(PDO $pdo, int $personId): void
{
    $pdo->prepare('UPDATE people SET is_active = 0 WHERE id = :id')->execute([':id' => $personId]);
    $pdo->prepare('UPDATE accounts SET is_active = 0 WHERE person_id = :id')->execute([':id' => $personId]);
    $holderId = getLegacyHolderIdForPerson($pdo, $personId);
    if ($holderId !== null) {
        deleteSeasonTicketHolder($pdo, $holderId);
    }
    identityAuditLog($pdo, 'person_archived', 'Archived person #' . $personId);
}

/**
 * @return list<array<string,mixed>>
 */
function getPersonDependents(PDO $pdo, int $personId): array
{
    $stmt = $pdo->prepare("SELECT pr.id AS relationship_id, pr.manager_person_id, pr.dependent_person_id, pr.relationship_type, pr.created_at AS relationship_created_at,
            p.*, m.old_holder_id
        FROM person_relationships pr
        JOIN people p ON p.id = pr.dependent_person_id
        LEFT JOIN identity_migration_map m ON m.person_id = p.id
        WHERE pr.manager_person_id = :person_id
        ORDER BY p.display_name");
    $stmt->execute([':person_id' => $personId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return list<array<string,mixed>>
 */
function getPersonManagers(PDO $pdo, int $personId): array
{
    $stmt = $pdo->prepare("SELECT pr.*, p.display_name, p.email
        FROM person_relationships pr
        JOIN people p ON p.id = pr.manager_person_id
        WHERE pr.dependent_person_id = :person_id
        ORDER BY p.display_name");
    $stmt->execute([':person_id' => $personId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Full position history for this person, across every season, newest season
 * first — for the Positions tab table. Capability resolution does NOT use
 * this (a past Chairman shouldn't keep finance access forever); it uses
 * getCurrentPersonPositions() instead.
 *
 * @return list<array<string,mixed>>
 */
function getPersonPositions(PDO $pdo, int $personId): array
{
    ensurePersonPositionsDateSchema($pdo);
    $stmt = $pdo->prepare('SELECT pp.id, pp.person_id, pp.position_id, pp.season_id, pp.start_date, pp.end_date, pp.notes, pp.assigned_at,
            hp.name AS position_name, hp.capabilities, hp.sort_order,
            s.name AS season_name, s.is_current AS season_is_current
        FROM person_positions pp
        JOIN hub_positions hp ON hp.id = pp.position_id
        LEFT JOIN seasons s ON s.id = pp.season_id
        WHERE pp.person_id = :person_id
        ORDER BY COALESCE(pp.start_date, s.start_date, DATE(pp.assigned_at)) DESC, pp.id DESC, hp.sort_order, hp.name');
    $stmt->execute([':person_id' => $personId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Positions held in one specific season only — what capability resolution
 * (hub_auth_current_positions()) actually reads. Returns the same shape
 * getHubPositions()/hub_position_capabilities() already expect.
 *
 * @return list<array<string,mixed>>
 */
function getCurrentPersonPositions(PDO $pdo, int $personId, int $seasonId = 0): array
{
    ensurePersonPositionsDateSchema($pdo);
    $stmt = $pdo->prepare('SELECT hp.*
        FROM person_positions pp
        JOIN hub_positions hp ON hp.id = pp.position_id
        LEFT JOIN seasons s ON s.id = pp.season_id
        WHERE pp.person_id = :person_id
          AND COALESCE(pp.start_date, s.start_date, DATE(pp.assigned_at)) <= CURDATE()
          AND (COALESCE(pp.end_date, s.end_date) IS NULL OR COALESCE(pp.end_date, s.end_date) >= CURDATE())
        ORDER BY hp.sort_order, hp.name');
    $stmt->execute([':person_id' => $personId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * person_positions.season_id is NOT NULL with a FK to seasons, but the Add/Edit
 * position form only collects start/end dates (no season picker) -- so a
 * caller passing seasonId=0 gets the season whose date range contains the
 * given start date, falling back to the current season if none matches
 * (e.g. an open-ended start date past the last configured season's end).
 */
function resolvePersonPositionSeasonId(PDO $pdo, string $startDate): int
{
    $stmt = $pdo->prepare('SELECT id FROM seasons WHERE :start_date BETWEEN start_date AND end_date ORDER BY start_date DESC LIMIT 1');
    $stmt->execute([':start_date' => $startDate]);
    $seasonId = (int) $stmt->fetchColumn();
    if ($seasonId > 0) {
        return $seasonId;
    }
    $current = getCurrentSeason($pdo);
    if ($current) {
        return (int) $current['id'];
    }
    throw new RuntimeException('No season is configured to attach this position to.');
}

function addPersonPosition(PDO $pdo, int $personId, int $positionId, int $seasonId = 0, ?string $notes = null, ?string $startDate = null, ?string $endDate = null): int
{
    ensurePersonPositionsDateSchema($pdo);
    $startDate = trim((string) $startDate);
    $endDate = trim((string) $endDate);
    if ($startDate === '') {
        throw new InvalidArgumentException('A start date is required.');
    }
    if ($endDate !== '' && $endDate < $startDate) {
        throw new InvalidArgumentException('End date must be after the start date.');
    }
    $seasonId = $seasonId > 0 ? $seasonId : resolvePersonPositionSeasonId($pdo, $startDate);

    $existing = $pdo->prepare('SELECT id FROM person_positions WHERE person_id = :person_id AND position_id = :position_id AND season_id = :season_id');
    $existing->execute([':person_id' => $personId, ':position_id' => $positionId, ':season_id' => $seasonId]);
    if ($existing->fetchColumn()) {
        throw new RuntimeException('This person already holds this position for that season. Edit the existing entry instead of adding a new one.');
    }

    $stmt = $pdo->prepare('INSERT INTO person_positions (person_id, position_id, season_id, start_date, end_date, notes) VALUES (:person_id, :position_id, :season_id, :start_date, :end_date, :notes)');
    $stmt->execute([
        ':person_id' => $personId,
        ':position_id' => $positionId,
        ':season_id' => $seasonId,
        ':start_date' => $startDate,
        ':end_date' => $endDate !== '' ? $endDate : null,
        ':notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
    ]);
    $rowId = (int) $pdo->lastInsertId();
    identityAuditLog($pdo, 'person_position_added', "Added position #{$positionId} for person #{$personId}, season #{$seasonId}");
    syncAccountRoleForPerson($pdo, $personId);
    return $rowId;
}

function updatePersonPosition(PDO $pdo, int $rowId, int $positionId, int $seasonId = 0, ?string $notes = null, ?string $startDate = null, ?string $endDate = null): void
{
    ensurePersonPositionsDateSchema($pdo);
    $stmt = $pdo->prepare('SELECT person_id FROM person_positions WHERE id = :id');
    $stmt->execute([':id' => $rowId]);
    $personId = (int) $stmt->fetchColumn();
    if ($personId <= 0) {
        throw new RuntimeException('Position assignment not found.');
    }

    $startDate = trim((string) $startDate);
    $endDate = trim((string) $endDate);
    if ($startDate === '') {
        throw new InvalidArgumentException('A start date is required.');
    }
    if ($endDate !== '' && $endDate < $startDate) {
        throw new InvalidArgumentException('End date must be after the start date.');
    }
    $seasonId = $seasonId > 0 ? $seasonId : resolvePersonPositionSeasonId($pdo, $startDate);

    $pdo->prepare('UPDATE person_positions SET position_id = :position_id, season_id = :season_id, start_date = :start_date, end_date = :end_date, notes = :notes WHERE id = :id')
        ->execute([
            ':position_id' => $positionId,
            ':season_id' => $seasonId,
            ':start_date' => $startDate,
            ':end_date' => $endDate !== '' ? $endDate : null,
            ':notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            ':id' => $rowId,
        ]);
    identityAuditLog($pdo, 'person_position_updated', "Updated position assignment #{$rowId} for person #{$personId}");
    syncAccountRoleForPerson($pdo, $personId);
}

function deletePersonPosition(PDO $pdo, int $rowId): void
{
    ensurePersonPositionsDateSchema($pdo);
    $stmt = $pdo->prepare('SELECT person_id FROM person_positions WHERE id = :id');
    $stmt->execute([':id' => $rowId]);
    $personId = (int) $stmt->fetchColumn();
    if ($personId <= 0) {
        throw new RuntimeException('Position assignment not found.');
    }

    $pdo->prepare('DELETE FROM person_positions WHERE id = :id')->execute([':id' => $rowId]);
    identityAuditLog($pdo, 'person_position_removed', "Removed position assignment #{$rowId} for person #{$personId}");
    syncAccountRoleForPerson($pdo, $personId);
}

function personRelationshipExists(PDO $pdo, int $managerPersonId, int $dependentPersonId): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM person_relationships WHERE manager_person_id = :manager AND dependent_person_id = :dependent AND relationship_type = 'dependent' LIMIT 1");
    $stmt->execute([':manager' => $managerPersonId, ':dependent' => $dependentPersonId]);
    return (bool) $stmt->fetchColumn();
}

function addPersonRelationship(PDO $pdo, int $managerPersonId, int $dependentPersonId): int
{
    if ($managerPersonId === $dependentPersonId) {
        throw new InvalidArgumentException('A person cannot manage themselves.');
    }
    if (personRelationshipExists($pdo, $managerPersonId, $dependentPersonId)) {
        throw new RuntimeException('That relationship already exists.');
    }
    if (personRelationshipExists($pdo, $dependentPersonId, $managerPersonId)) {
        throw new RuntimeException('That would create a circular management relationship.');
    }
    $pdo->prepare("INSERT INTO person_relationships (manager_person_id, dependent_person_id, relationship_type)
        VALUES (:manager, :dependent, 'dependent')")
        ->execute([':manager' => $managerPersonId, ':dependent' => $dependentPersonId]);
    $relationshipId = (int) $pdo->lastInsertId();

    $managerHolderId = getLegacyHolderIdForPerson($pdo, $managerPersonId);
    $dependentHolderId = getLegacyHolderIdForPerson($pdo, $dependentPersonId);
    if ($managerHolderId !== null && $dependentHolderId !== null) {
        $pdo->prepare('UPDATE season_ticket_holders SET managed_by_holder_id = :manager WHERE id = :dependent')
            ->execute([':manager' => $managerHolderId, ':dependent' => $dependentHolderId]);
    }
    identityAuditLog($pdo, 'person_relationship_added', 'Person #' . $managerPersonId . ' manages person #' . $dependentPersonId);
    return $relationshipId;
}

function removePersonRelationship(PDO $pdo, int $relationshipId): void
{
    $stmt = $pdo->prepare('SELECT * FROM person_relationships WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $relationshipId]);
    $relationship = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$relationship) {
        return;
    }
    $pdo->prepare('DELETE FROM person_relationships WHERE id = :id')->execute([':id' => $relationshipId]);
    $dependentHolderId = getLegacyHolderIdForPerson($pdo, (int) $relationship['dependent_person_id']);
    if ($dependentHolderId !== null) {
        $pdo->prepare('UPDATE season_ticket_holders SET managed_by_holder_id = NULL WHERE id = :id')
            ->execute([':id' => $dependentHolderId]);
    }
    identityAuditLog($pdo, 'person_relationship_removed', 'Removed relationship #' . $relationshipId);
}
