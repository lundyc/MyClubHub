<?php

declare(strict_types=1);

/**
 * Matchday balance sheet — one financial return per home fixture, mirroring
 * the club's paper "Matchday Balance Sheet": floats / cash reconciliation,
 * income lines, outgoings lines, and a sign-off. Keyed 1:1 to a
 * match_fixtures row (UNIQUE fixture_id) so the date / opponent /
 * competition / kick-off never need re-keying and the season roll-up shown
 * under Finance comes for free.
 *
 * The field lists below are the single source of truth: the edit form, the
 * totals maths, the list page and the CSV export all read from them, so
 * adding or renaming a line is a one-line change here.
 */

function matchday_finance_ensure_schema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS matchday_finance (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fixture_id INT UNSIGNED NOT NULL,
            season_id INT UNSIGNED NOT NULL,

            cash_float_gate DECIMAL(10,2) NOT NULL DEFAULT 0,
            cash_close_gate DECIMAL(10,2) NOT NULL DEFAULT 0,
            cash_float_bar DECIMAL(10,2) NOT NULL DEFAULT 0,
            cash_close_bar DECIMAL(10,2) NOT NULL DEFAULT 0,
            cash_float_catering DECIMAL(10,2) NOT NULL DEFAULT 0,
            cash_close_catering DECIMAL(10,2) NOT NULL DEFAULT 0,
            cash_float_merch DECIMAL(10,2) NOT NULL DEFAULT 0,
            cash_close_merch DECIMAL(10,2) NOT NULL DEFAULT 0,

            income_gate DECIMAL(10,2) NOT NULL DEFAULT 0,
            income_matchday_sponsorship DECIMAL(10,2) NOT NULL DEFAULT 0,
            income_matchball_sponsorship DECIMAL(10,2) NOT NULL DEFAULT 0,
            income_merchandise DECIMAL(10,2) NOT NULL DEFAULT 0,
            income_raffle DECIMAL(10,2) NOT NULL DEFAULT 0,
            income_bar DECIMAL(10,2) NOT NULL DEFAULT 0,
            income_catering DECIMAL(10,2) NOT NULL DEFAULT 0,
            income_other DECIMAL(10,2) NOT NULL DEFAULT 0,

            expense_officials DECIMAL(10,2) NOT NULL DEFAULT 0,
            expense_league_fees DECIMAL(10,2) NOT NULL DEFAULT 0,
            expense_hospitality DECIMAL(10,2) NOT NULL DEFAULT 0,
            expense_supplies DECIMAL(10,2) NOT NULL DEFAULT 0,
            expense_other DECIMAL(10,2) NOT NULL DEFAULT 0,

            attendance INT UNSIGNED NULL,
            notes TEXT NULL,
            completed_by VARCHAR(120) NOT NULL DEFAULT '',
            checked_by VARCHAR(120) NOT NULL DEFAULT '',
            status ENUM('draft','final') NOT NULL DEFAULT 'draft',

            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            UNIQUE KEY uq_matchday_finance_fixture (fixture_id),
            KEY idx_matchday_finance_season (season_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ensured = true;
}

/**
 * Income lines in sheet order.
 *
 * @return array<string, string> column => label
 */
function matchday_finance_income_fields(): array
{
    return [
        'income_gate' => 'Gate / admissions',
        'income_matchday_sponsorship' => 'Matchday sponsorship',
        'income_matchball_sponsorship' => 'Match ball sponsorship',
        'income_merchandise' => 'Merchandise',
        'income_raffle' => 'Raffle / fundraising',
        'income_bar' => 'Bar',
        'income_catering' => 'Catering',
        'income_other' => 'Other income',
    ];
}

/**
 * Outgoing lines in sheet order.
 *
 * @return array<string, string> column => label
 */
function matchday_finance_expense_fields(): array
{
    return [
        'expense_officials' => 'Officials / referee fees',
        'expense_league_fees' => 'League / match fees',
        'expense_hospitality' => 'Hospitality / catering costs',
        'expense_supplies' => 'Matchday supplies',
        'expense_other' => 'Other expenses',
    ];
}

/**
 * Cash-handling areas for the floats / reconciliation block. "Cash taken" is
 * always close count minus starting float; it is cross-checked against the
 * matching declared income line, but only as a soft flag — card takings and
 * change bags mean an exact match is the exception, not the rule.
 *
 * @return array<string, array{label:string, float:string, close:string, income:string}>
 */
function matchday_finance_cash_areas(): array
{
    return [
        'gate' => ['label' => 'Gate', 'float' => 'cash_float_gate', 'close' => 'cash_close_gate', 'income' => 'income_gate'],
        'bar' => ['label' => 'Bar', 'float' => 'cash_float_bar', 'close' => 'cash_close_bar', 'income' => 'income_bar'],
        'catering' => ['label' => 'Catering', 'float' => 'cash_float_catering', 'close' => 'cash_close_catering', 'income' => 'income_catering'],
        'merch' => ['label' => 'Merchandise', 'float' => 'cash_float_merch', 'close' => 'cash_close_merch', 'income' => 'income_merchandise'],
    ];
}

/**
 * Every DECIMAL money column on the table (income + outgoings + the float /
 * close pair for each cash area), in a stable order.
 *
 * @return list<string>
 */
function matchday_finance_money_columns(): array
{
    $columns = array_merge(
        array_keys(matchday_finance_income_fields()),
        array_keys(matchday_finance_expense_fields())
    );
    foreach (matchday_finance_cash_areas() as $area) {
        $columns[] = $area['float'];
        $columns[] = $area['close'];
    }
    return $columns;
}

/** Parse a currency-ish form value into a non-negative amount rounded to 2dp. */
function matchday_finance_money(mixed $value): float
{
    if (is_string($value)) {
        $value = str_replace([',', '£', ' '], '', $value);
    }
    $number = (float) $value;
    if (!is_finite($number) || $number < 0) {
        return 0.0;
    }
    return round($number, 2);
}

/** SQL fragment: every money column COALESCEd to 0 under its real name. */
function matchday_finance_money_select(string $alias): string
{
    $parts = [];
    foreach (matchday_finance_money_columns() as $column) {
        $parts[] = "COALESCE({$alias}.{$column}, 0) AS {$column}";
    }
    return implode(', ', $parts);
}

/** A blank record with every money column zeroed — the "new sheet" default. */
function matchday_finance_blank(): array
{
    $row = [];
    foreach (matchday_finance_money_columns() as $column) {
        $row[$column] = 0.0;
    }
    $row['attendance'] = null;
    $row['notes'] = '';
    $row['completed_by'] = '';
    $row['checked_by'] = '';
    $row['status'] = 'draft';

    return $row;
}

function matchday_finance_get(PDO $pdo, int $fixtureId): ?array
{
    matchday_finance_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM matchday_finance WHERE fixture_id = :fixture_id');
    $stmt->execute([':fixture_id' => $fixtureId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Derived totals for one record (a stored row, a blank row, or POST data).
 *
 * @return array{
 *     income: float, expenses: float, net: float, cash_taken_total: float,
 *     cash_by_area: array<string, array{label:string, float:float, close:float, taken:float, declared:float, variance:float}>
 * }
 */
function matchday_finance_totals(array $row): array
{
    $income = 0.0;
    foreach (array_keys(matchday_finance_income_fields()) as $column) {
        $income += (float) ($row[$column] ?? 0);
    }

    $expenses = 0.0;
    foreach (array_keys(matchday_finance_expense_fields()) as $column) {
        $expenses += (float) ($row[$column] ?? 0);
    }

    $cashByArea = [];
    $cashTakenTotal = 0.0;
    foreach (matchday_finance_cash_areas() as $key => $area) {
        $float = (float) ($row[$area['float']] ?? 0);
        $close = (float) ($row[$area['close']] ?? 0);
        $taken = $close - $float;
        $declared = (float) ($row[$area['income']] ?? 0);
        $cashByArea[$key] = [
            'label' => $area['label'],
            'float' => $float,
            'close' => $close,
            'taken' => $taken,
            'declared' => $declared,
            'variance' => $taken - $declared,
        ];
        $cashTakenTotal += $taken;
    }

    return [
        'income' => $income,
        'expenses' => $expenses,
        'net' => $income - $expenses,
        'cash_taken_total' => $cashTakenTotal,
        'cash_by_area' => $cashByArea,
    ];
}

/**
 * Home fixtures for a season, each with its finance row merged in (money
 * columns COALESCEd to 0 when no sheet has been started yet).
 *
 * @return list<array<string, mixed>>
 */
function matchday_finance_list_for_season(PDO $pdo, int $seasonId): array
{
    matchday_finance_ensure_schema($pdo);

    $stmt = $pdo->prepare(
        "SELECT f.id AS fixture_id, f.match_date, f.kickoff_time, f.competition, f.venue,
                f.status AS fixture_status,
                COALESCE(o.clubname, f.opponent) AS opponent,
                mf.id AS finance_id, mf.status AS finance_status, mf.attendance,
                mf.completed_by, mf.checked_by, mf.updated_at AS finance_updated_at,
                " . matchday_finance_money_select('mf') . "
         FROM match_fixtures f
         LEFT JOIN match_opponents o ON o.id = f.opponent_id
         LEFT JOIN matchday_finance mf ON mf.fixture_id = f.id
         WHERE f.season_id = :season_id AND f.is_home = 1
         ORDER BY f.match_date ASC, f.id ASC"
    );
    $stmt->execute([':season_id' => $seasonId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Season roll-up across the home fixtures returned by
 * matchday_finance_list_for_season().
 *
 * @param list<array<string, mixed>> $rows
 * @return array{home_games:int, recorded:int, final:int, income:float, expenses:float, net:float}
 */
function matchday_finance_season_summary(array $rows): array
{
    $summary = ['home_games' => count($rows), 'recorded' => 0, 'final' => 0, 'income' => 0.0, 'expenses' => 0.0, 'net' => 0.0];

    foreach ($rows as $row) {
        if (empty($row['finance_id'])) {
            continue;
        }
        $summary['recorded']++;
        if ((string) ($row['finance_status'] ?? '') === 'final') {
            $summary['final']++;
        }
        $totals = matchday_finance_totals($row);
        $summary['income'] += $totals['income'];
        $summary['expenses'] += $totals['expenses'];
        $summary['net'] += $totals['net'];
    }

    return $summary;
}

/**
 * Insert or update the balance sheet for a fixture (UNIQUE fixture_id makes
 * this a genuine upsert — one sheet per home game).
 *
 * @param array<string, mixed> $data
 */
function matchday_finance_save(PDO $pdo, int $fixtureId, int $seasonId, array $data, ?int $userId): void
{
    matchday_finance_ensure_schema($pdo);

    $fields = ['fixture_id' => $fixtureId, 'season_id' => $seasonId];
    foreach (matchday_finance_money_columns() as $column) {
        $fields[$column] = matchday_finance_money($data[$column] ?? 0);
    }

    $attendance = trim((string) ($data['attendance'] ?? ''));
    $fields['attendance'] = $attendance === '' ? null : max(0, (int) $attendance);
    $notes = trim((string) ($data['notes'] ?? ''));
    $fields['notes'] = $notes !== '' ? $notes : null;
    $fields['completed_by'] = mb_substr(trim((string) ($data['completed_by'] ?? '')), 0, 120);
    $fields['checked_by'] = mb_substr(trim((string) ($data['checked_by'] ?? '')), 0, 120);
    $status = (string) ($data['status'] ?? 'draft');
    $fields['status'] = in_array($status, ['draft', 'final'], true) ? $status : 'draft';

    $columns = array_keys($fields);
    $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);

    // Everything except the fixture_id identity is refreshed on a re-save.
    $assignments = [];
    foreach ($columns as $column) {
        if ($column === 'fixture_id') {
            continue;
        }
        $assignments[] = "{$column} = VALUES({$column})";
    }
    $assignments[] = 'updated_by = VALUES(updated_by)';

    $sql = 'INSERT INTO matchday_finance (' . implode(', ', $columns) . ', created_by, updated_by) '
        . 'VALUES (' . implode(', ', $placeholders) . ', :created_by, :updated_by) '
        . 'ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);

    $params = [];
    foreach ($fields as $column => $value) {
        $params[':' . $column] = $value;
    }
    $params[':created_by'] = $userId;
    $params[':updated_by'] = $userId;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}
