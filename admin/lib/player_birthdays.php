<?php
declare(strict_types=1);

function players_ensure_date_of_birth_column(PDO $pdo): void
{
    $column = $pdo->query("SHOW COLUMNS FROM players LIKE 'date_of_birth'")->fetch(PDO::FETCH_ASSOC);
    if ($column) {
        return;
    }

    $positionColumn = $pdo->query("SHOW COLUMNS FROM players LIKE 'position'")->fetch(PDO::FETCH_ASSOC);
    $afterColumn = $positionColumn ? ' AFTER position' : ' AFTER name';
    $pdo->exec('ALTER TABLE players ADD COLUMN date_of_birth DATE NULL' . $afterColumn);
}

function players_format_date_of_birth(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return 'Not recorded';
    }

    $timestamp = strtotime($value);
    return $timestamp !== false ? date('d M Y', $timestamp) : $value;
}

function players_calculate_age(?string $dateOfBirth): ?int
{
    $dateOfBirth = trim((string) $dateOfBirth);
    if ($dateOfBirth === '') {
        return null;
    }

    try {
        $dob = new DateTimeImmutable($dateOfBirth);
    } catch (Exception) {
        return null;
    }

    return (new DateTimeImmutable('today'))->diff($dob)->y;
}

/**
 * @param array<string,mixed> $row
 * @return array{name: string, id: int, date_of_birth: string, next_birthday: string, days_until: int, age_turning: int, role_label: string, href: string}|null
 */
function players_build_birthday_row(array $row, string $nameKey, string $dobKey, string $roleLabel, string $href): ?array
{
    $today = new DateTimeImmutable('today');

    try {
        $dob = new DateTimeImmutable((string) ($row[$dobKey] ?? ''));
    } catch (Throwable) {
        return null;
    }

    $nextBirthday = $dob->setDate((int) $today->format('Y'), (int) $dob->format('m'), (int) $dob->format('d'));
    if ($nextBirthday < $today) {
        $nextBirthday = $nextBirthday->modify('+1 year');
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row[$nameKey] ?? ''),
        'date_of_birth' => $dob->format('Y-m-d'),
        'next_birthday' => $nextBirthday->format('Y-m-d'),
        'days_until' => (int) $today->diff($nextBirthday)->format('%a'),
        'age_turning' => (int) $nextBirthday->format('Y') - (int) $dob->format('Y'),
        'role_label' => ucfirst($roleLabel),
        'href' => $href,
    ];
}

function players_birthday_role_label(?string $roleCodes, ?string $positionNames): string
{
    $roles = array_filter(array_map('trim', explode(',', (string) $roleCodes)));
    if (in_array('admin', $roles, true)) {
        return 'Admin';
    }

    $positions = array_values(array_filter(array_map('trim', explode(',', (string) $positionNames))));
    foreach ($positions as $position) {
        if (stripos($position, 'manager') !== false) {
            return 'manager';
        }
    }
    if ($positions !== []) {
        return implode(', ', $positions);
    }

    foreach (['staff' => 'staff', 'volunteer' => 'volunteer', 'public' => 'member'] as $code => $label) {
        if (in_array($code, $roles, true)) {
            return $label;
        }
    }

    return 'supporter';
}

function players_birthdays_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
    $stmt->execute([':table_name' => $table]);
    return (bool) $stmt->fetchColumn();
}

/**
 * @return list<array{name: string, id: int, date_of_birth: string, next_birthday: string, days_until: int, age_turning: int, role_label: string, href: string}>
 */
function players_all_birthdays(PDO $pdo, int $limit = 0): array
{
    players_ensure_date_of_birth_column($pdo);

    $stmt = $pdo->query("
        SELECT id, name, date_of_birth
        FROM players
        WHERE active = 1
          AND status IN ('current', 'trialist', 'injured', 'loan')
          AND date_of_birth IS NOT NULL
          AND date_of_birth <> '0000-00-00'
        ORDER BY name ASC
    ");

    $birthdays = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $birthday = players_build_birthday_row($row, 'name', 'date_of_birth', 'player', '/player_view.php?id=' . (int) $row['id']);
        if ($birthday !== null) {
            $birthdays[] = $birthday;
        }
    }

    if (
        players_birthdays_table_exists($pdo, 'people')
        && players_birthdays_table_exists($pdo, 'accounts')
        && players_birthdays_table_exists($pdo, 'roles')
        && players_birthdays_table_exists($pdo, 'account_roles')
        && players_birthdays_table_exists($pdo, 'person_positions')
        && players_birthdays_table_exists($pdo, 'hub_positions')
    ) {
        $accessRoleSelect = "''";
        $accessRoleJoins = '';
        if (players_birthdays_table_exists($pdo, 'person_access_roles') && players_birthdays_table_exists($pdo, 'access_roles')) {
            $accessRoleSelect = "GROUP_CONCAT(DISTINCT access_role.slug ORDER BY access_role.slug SEPARATOR ',')";
            $accessRoleJoins = 'LEFT JOIN person_access_roles par ON par.person_id = p.id
                LEFT JOIN access_roles access_role ON access_role.id = par.role_id';
        }
        $peopleStmt = $pdo->query("
            SELECT p.id, p.display_name, p.date_of_birth,
                   {$accessRoleSelect} AS access_role_codes,
                   GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ',') AS role_codes,
                   GROUP_CONCAT(DISTINCT hp.name ORDER BY hp.sort_order, hp.name SEPARATOR ', ') AS position_names
            FROM people p
            {$accessRoleJoins}
            LEFT JOIN accounts a ON a.person_id = p.id AND a.is_active = 1
            LEFT JOIN account_roles ar ON ar.account_id = a.id
            LEFT JOIN roles r ON r.id = ar.role_id
            LEFT JOIN person_positions pp ON pp.person_id = p.id
                AND COALESCE(pp.start_date, DATE(pp.assigned_at)) <= CURDATE()
                AND (pp.end_date IS NULL OR pp.end_date >= CURDATE())
            LEFT JOIN hub_positions hp ON hp.id = pp.position_id
            WHERE p.is_active = 1
              AND p.date_of_birth IS NOT NULL
              AND p.date_of_birth <> '0000-00-00'
            GROUP BY p.id, p.display_name, p.date_of_birth
            ORDER BY p.display_name ASC
        ");

        foreach ($peopleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $birthday = players_build_birthday_row(
                $row,
                'display_name',
                'date_of_birth',
                players_birthday_role_label(($row['role_codes'] ?? '') . ',' . ($row['access_role_codes'] ?? ''), (string) ($row['position_names'] ?? '')),
                '/club_person.php?id=' . (int) $row['id']
            );
            if ($birthday !== null) {
                $birthdays[] = $birthday;
            }
        }
    }

    usort($birthdays, static function (array $a, array $b): int {
        return $a['days_until'] <=> $b['days_until']
            ?: strcasecmp($a['name'], $b['name']);
    });

    if ($limit > 0) {
        return array_slice($birthdays, 0, $limit);
    }

    return $birthdays;
}

/**
 * @return list<array{name: string, id: int, date_of_birth: string, next_birthday: string, days_until: int, age_turning: int, role_label: string, href: string}>
 */
function players_next_birthdays(PDO $pdo, int $limit = 5): array
{
    return players_all_birthdays($pdo, $limit);
}
