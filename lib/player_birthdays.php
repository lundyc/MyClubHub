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

/**
 * @return list<array{name: string, id: int, date_of_birth: string, next_birthday: string, days_until: int, age_turning: int}>
 */
function players_next_birthdays(PDO $pdo, int $limit = 5): array
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

    $today = new DateTimeImmutable('today');
    $birthdays = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        try {
            $dob = new DateTimeImmutable((string) $row['date_of_birth']);
        } catch (Throwable) {
            continue;
        }

        $nextBirthday = $dob->setDate((int) $today->format('Y'), (int) $dob->format('m'), (int) $dob->format('d'));
        if ($nextBirthday < $today) {
            $nextBirthday = $nextBirthday->modify('+1 year');
        }

        $birthdays[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'date_of_birth' => $dob->format('Y-m-d'),
            'next_birthday' => $nextBirthday->format('Y-m-d'),
            'days_until' => (int) $today->diff($nextBirthday)->format('%a'),
            'age_turning' => (int) $nextBirthday->format('Y') - (int) $dob->format('Y'),
        ];
    }

    usort($birthdays, static function (array $a, array $b): int {
        return $a['days_until'] <=> $b['days_until']
            ?: strcasecmp($a['name'], $b['name']);
    });

    return array_slice($birthdays, 0, max(0, $limit));
}
