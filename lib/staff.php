<?php

declare(strict_types=1);

/*
 * Public people data from person_positions × hub_positions × people.
 *   - pub_staff_management()  -> football department  (shown on /staff)
 *   - pub_staff_officials()   -> committee department  (shown on /club/officials)
 * The `other` department (volunteers etc.) is intentionally excluded.
 */

/** Rough seniority ordering within a department. */
function pub_staff_rank(string $position): int
{
    $order = [
        'chairman' => 0, 'vice chairman' => 1, 'secretary' => 2, 'treasurer' => 3,
        'manager' => 0, 'assistant manager' => 1, 'coach' => 2, 'goalkeeping coach' => 3,
    ];
    return $order[strtolower($position)] ?? 50;
}

/**
 * Current holders of positions in the given department.
 * @return list<array{name:string,position:string}>
 */
function pub_staff_in_department(string $department): array
{
    $stmt = db()->prepare(
        "SELECT p.display_name, hp.name AS position, hp.sort_order
         FROM person_positions pp
         JOIN hub_positions hp ON hp.id = pp.position_id
         JOIN people p ON p.id = pp.person_id
         WHERE hp.department = :dept
           AND (pp.end_date IS NULL OR pp.end_date >= CURDATE())
           AND (pp.start_date IS NULL OR pp.start_date <= CURDATE())
           AND p.is_active = 1
         ORDER BY hp.sort_order ASC"
    );
    $stmt->execute([':dept' => $department]);
    $people = [];
    foreach ($stmt->fetchAll() as $r) {
        $people[] = ['name' => (string) $r['display_name'], 'position' => (string) $r['position']];
    }
    usort($people, static fn ($a, $b) => pub_staff_rank($a['position']) <=> pub_staff_rank($b['position'])
        ?: strcmp($a['name'], $b['name']));
    return $people;
}

/** Management team — the football department (manager, coaches …). */
function pub_staff_management(): array
{
    return pub_staff_in_department('football');
}

/** Club officials — the committee (chairman, secretary, treasurer …). */
function pub_staff_officials(): array
{
    return pub_staff_in_department('committee');
}
