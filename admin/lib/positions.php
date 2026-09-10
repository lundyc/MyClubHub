<?php

declare(strict_types=1);

require_once __DIR__ . '/audit.php';

/**
 * Committee positions (Treasurer, Secretary, ...) that staff/volunteer
 * accounts can be assigned, each granting a set of capabilities. Positions
 * themselves are fully admin-manageable (add/rename/delete) via
 * hub/positions.php — only the fixed set of capabilities below is
 * developer-defined, since each one corresponds to a real code-level gate
 * somewhere in the app.
 */
const HUB_CAPABILITIES = [
    'finance' => 'Finance (payments, refunds, sponsorship money, financial reports)',
    'football_ops' => 'Football & team operations (fixtures, players, results, football reports)',
    'tickets_ops' => 'Ticketing & gate operations',
    'content_social' => 'Content & social media tools',
    'secretary_ops' => 'Secretary & club administration (discipline, registrations, correspondence, governance)',
];

/**
 * Which real page (by basename) requires which capability/capabilities, for
 * pages that have been moved off a hard admin-only check. A page passes if
 * the user holds ANY of its listed capabilities (see
 * hub_auth_has_any_capability()) — most pages list exactly one. Used by
 * header.php's volunteer/staff default-deny gate, and by the pages
 * themselves alongside hub_auth_is_admin().
 *
 * @var array<string, list<string>>
 */
const HUB_PAGE_CAPABILITIES = [
    // Cross-club overview
    'club_reminders.php' => ['finance', 'football_ops', 'tickets_ops', 'secretary_ops'],

    // Finance / Stripe (already gated)
    'stripe_dashboard.php' => ['finance'],
    'stripe_refund.php' => ['finance'],

    // Content & social media tools (already gated)
    'facebook_diagnostics.php' => ['content_social'],
    'facebook_photo_import.php' => ['content_social'],
    'google-callback.php' => ['content_social'],
    'template_packs.php' => ['content_social'],

    // Match Day / Club setup / squad — football & team operations
    'announcement.php' => ['football_ops'],
    'announcements.php' => ['football_ops'],
    'competition.php' => ['football_ops'],
    'competition_delete.php' => ['football_ops'],
    'competitions.php' => ['football_ops'],
    'feedback.php' => ['football_ops'],
    'feedback_item.php' => ['football_ops'],
    'facilities.php' => ['football_ops'],
    'fixture_starting_11.php' => ['football_ops'],
    'match.php' => ['football_ops'],
    'match_lineups.php' => ['football_ops'],
    'match_lineup_save.php' => ['football_ops'],
    'match_sub_save.php' => ['football_ops'],
    'match_formation_save.php' => ['football_ops'],
    'match_record_events.php' => ['football_ops'],
    'match_record_event_save.php' => ['football_ops'],
    'match_record_event_delete.php' => ['football_ops'],
    'matchday_stats.php' => ['football_ops'],
    'match_next_match.php' => ['football_ops'],
    'match_player_of_match.php' => ['football_ops'],
    // Matchday balance sheet: recorded by the treasurer or the match
    // secretary, so either capability grants access.
    'matchday_finance.php' => ['finance', 'football_ops'],
    'matchday_finance_edit.php' => ['finance', 'football_ops'],
    'matchday_finance_export.php' => ['finance', 'football_ops'],
    'matches.php' => ['football_ops'],
    'stats.php' => ['football_ops'],
    'monthly_fixtures.php' => ['football_ops'],
    'motm.php' => ['football_ops'],
    'opponent.php' => ['football_ops'],
    'opponent_bulk_delete.php' => ['football_ops'],
    'opponent_delete.php' => ['football_ops'],
    'opponents.php' => ['football_ops'],
    'player.php' => ['football_ops'],
    'player_add.php' => ['football_ops'],
    'player_edit.php' => ['football_ops'],
    'player_reference.php' => ['football_ops'],
    'player_view.php' => ['football_ops'],
    'players.php' => ['football_ops'],
    'players_leave.php' => ['football_ops'],
    'season.php' => ['football_ops'],
    'season_delete.php' => ['football_ops'],
    'seasons.php' => ['football_ops'],
    'venue.php' => ['football_ops'],
    'venue_delete.php' => ['football_ops'],
    'venue_reviews.php' => ['football_ops'],
    'venues.php' => ['football_ops'],

    // Sponsorship, fundraising, payments — finance
    'assign_sponsors.php' => ['finance'],
    'hidden_team_game.php' => ['finance'],
    'hidden_team_games.php' => ['finance'],
    'match_payment_delete.php' => ['finance'],
    'match_sponsorship_delete.php' => ['finance'],
    'match_sponsorship_move.php' => ['finance'],
    'payment_add.php' => ['finance'],
    'payment_edit.php' => ['finance'],
    'playersponsors_orders.php' => ['finance'],
    'sponsor.php' => ['finance'],
    'sponsor_followups.php' => ['finance'],
    'sponsors.php' => ['finance'],
    'sponsorship_agreement.php' => ['finance'],
    'sponsorship_agreement_bulk.php' => ['finance'],
    'sponsorship_agreements.php' => ['finance'],
    'sponsorship_bundle.php' => ['finance'],
    'sponsorship_bundles.php' => ['finance'],
    'sponsorship_delete.php' => ['finance'],
    'sponsorship_edit.php' => ['finance'],
    'sponsorship_package.php' => ['finance'],
    'sponsorship_packages.php' => ['finance'],
    'sponsorship_type.php' => ['finance'],
    'sponsorship_type_delete.php' => ['finance'],
    'sponsorship_types.php' => ['finance'],

    // Ticketing & gate operations (pages not already gated by the separate
    // role-based tickets.* permission layer — that layer is left as-is)
    'fixture_tickets.php' => ['tickets_ops'],
    'pos_overview.php' => ['tickets_ops'],
    'season_ticket_free_codes.php' => ['tickets_ops'],
    'season_ticket_order.php' => ['tickets_ops'],
    'season_ticket_renewals.php' => ['tickets_ops'],
    'season_ticket_type.php' => ['tickets_ops'],
    'season_ticket_types.php' => ['tickets_ops'],
    'ticket_packages.php' => ['tickets_ops'],

    // Secretary & club administration
    'secretary_dashboard.php' => ['secretary_ops'],
    'discipline_register.php' => ['secretary_ops'],
    'discipline_incident.php' => ['secretary_ops'],
    'discipline_incident_delete.php' => ['secretary_ops'],
    'player_registrations.php' => ['secretary_ops'],
    'player_registration_edit.php' => ['secretary_ops'],
    'secretary_tasks.php' => ['secretary_ops'],
    'secretary_task_edit.php' => ['secretary_ops'],
    'secretary_correspondence.php' => ['secretary_ops'],
    'secretary_correspondence_edit.php' => ['secretary_ops'],
    'fixture_change_requests.php' => ['secretary_ops'],
    'fixture_change_request_edit.php' => ['secretary_ops'],
    'committee_meetings.php' => ['secretary_ops'],
    'committee_meeting_edit.php' => ['secretary_ops'],
    'committee_meeting_delete.php' => ['secretary_ops'],
    'secretary_documents.php' => ['secretary_ops'],
    'secretary_document_download.php' => ['secretary_ops'],
    'secretary_document_delete.php' => ['secretary_ops'],
    'secretary_guide.php' => ['secretary_ops'],

    // Graphics, posters, media library, publishing — content & social
    'league_table.php' => ['content_social'],
    'match_fixtures_poster.php' => ['content_social'],
    'match_graphics.php' => ['content_social'],
    'match_media.php' => ['content_social'],
    'match_photos.php' => ['content_social'],
    'match_poster.php' => ['content_social'],
    'match_starting_11_graphic.php' => ['content_social'],
    'media.php' => ['content_social'],
    'people.php' => ['content_social'],
    'player_graphics.php' => ['content_social'],
    'player_photos_bulk.php' => ['content_social'],
    'sponsor_graphics.php' => ['content_social'],
    'sponsor_wall.php' => ['content_social'],

    // reports.php is gated per report-type (see reports.php's own
    // $reportTypeCapability map) rather than one capability for the whole
    // page — deliberately not listed here, and covered separately by
    // HUB_PAGE_GATE_EXEMPT below.
    //
    // Not listed (deliberately): club_people.php, club_person.php,
    // positions.php, settings.php, user_delete.php, user_edit.php stay
    // hub_auth_is_admin()-only, not a delegable capability — header.php's
    // blanket gate below still correctly denies volunteer/staff on these
    // (just via the generic message rather than each page's own admin-only
    // message), so no exemption is needed.
];

/**
 * Pages header.php's volunteer/staff default-deny gate must NOT block on
 * "not in HUB_PAGE_CAPABILITIES", because something else already grants or
 * enforces access correctly:
 *
 * - index.php is the shared dashboard landing page every authenticated
 *   account should reach, capability-gated or not.
 * - reports.php gates itself per report-type internally (see its own
 *   $reportTypeCapability map) rather than as one page-level capability.
 * - complimentary_admission.php, fixture_ticketing_dashboard.php,
 *   scan_overview.php, season_pass_rules.php, season_ticket_orders.php,
 *   ticket_orders.php already have their own working
 *   hub_auth_require_permission() gate from the separate role-based
 *   permission layer (lib/permissions.php), which grants volunteer/staff a
 *   ticket-ops baseline (tickets.scan, pos.use, ...) independent of
 *   committee position. Without this exemption the blanket gate would deny
 *   them before that check ever runs.
 * - developer*.php pages gate themselves with hub_auth_is_developer(), which
 *   is stricter than admin and resolves to the configured Colin/developer
 *   email only. The blanket capability gate must not run first.
 *
 * @var list<string>
 */
const HUB_PAGE_GATE_EXEMPT = [
    'index.php',
    'reports.php',
    'complimentary_admission.php',
    'fixture_ticketing_dashboard.php',
    'scan_overview.php',
    'season_pass_rules.php',
    'season_ticket_orders.php',
    'ticket_orders.php',
    'developer.php',
    'developer_analytics.php',
    'developer_audit.php',
    'developer_db.php',
    'developer_errors.php',
    'developer_roles.php',
    'developer_sponsorships.php',
];

/**
 * Which section of People & Users' "Club Roles & Committee" a position's
 * holders group under. Distinct from Site Admin, which is the account-level
 * "Site Administrator" flag (independent of any position).
 *
 * @var array<string, string>
 */
const HUB_POSITION_DEPARTMENTS = [
    'committee' => 'Committee',
    'football' => 'Football Department',
    'other' => 'Other Roles',
];

function ensureHubPositionsSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS hub_positions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        department VARCHAR(20) NOT NULL DEFAULT 'other',
        capabilities TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_hub_positions_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM hub_positions') as $row) {
        $columns[(string) $row['Field']] = true;
    }
    if (!isset($columns['department'])) {
        $pdo->exec("ALTER TABLE hub_positions ADD COLUMN department VARCHAR(20) NOT NULL DEFAULT 'other' AFTER name");
        // Best-effort classification of positions that predate this column.
        $pdo->exec("UPDATE hub_positions SET department = 'committee' WHERE name IN ('Chairman', 'Vice Chairman', 'Secretary', 'Treasurer', 'Committee Member')");
        $pdo->exec("UPDATE hub_positions SET department = 'football' WHERE name IN ('Manager', 'Assistant Manager', 'Coach', 'Goalkeeping Coach')");
    }

    $count = (int) $pdo->query('SELECT COUNT(*) FROM hub_positions')->fetchColumn();
    if ($count === 0) {
        $seed = $pdo->prepare('INSERT INTO hub_positions (name, department, capabilities, sort_order) VALUES (:name, :department, :capabilities, :sort_order)');
        $positions = [
            ['Chairman', 'committee', []],
            ['Vice Chairman', 'committee', []],
            ['Secretary', 'committee', []],
            ['Treasurer', 'committee', ['finance']],
            ['Committee Member', 'committee', []],
        ];
        foreach ($positions as $i => [$name, $department, $capabilities]) {
            $seed->execute([
                ':name' => $name,
                ':department' => $department,
                ':capabilities' => json_encode($capabilities),
                ':sort_order' => $i,
            ]);
        }
    }

    $done = true;
}

/**
 * @return list<string>
 */
function hub_position_capabilities(array $position): array
{
    $decoded = json_decode((string) ($position['capabilities'] ?? ''), true);
    if (!is_array($decoded)) {
        return [];
    }
    return array_values(array_filter($decoded, 'is_string'));
}

/**
 * @return list<array<string, mixed>>
 */
function getHubPositions(PDO $pdo): array
{
    ensureHubPositionsSchema($pdo);
    $stmt = $pdo->query('SELECT * FROM hub_positions ORDER BY sort_order, name');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getHubPosition(PDO $pdo, int $id): ?array
{
    ensureHubPositionsSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM hub_positions WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $position = $stmt->fetch(PDO::FETCH_ASSOC);
    return $position ?: null;
}

/**
 * @param array{name: string, department?: string, capabilities?: list<string>, sort_order?: int} $data
 */
function saveHubPosition(PDO $pdo, ?int $id, array $data): int
{
    ensureHubPositionsSchema($pdo);

    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('A position name is required.');
    }

    $department = (string) ($data['department'] ?? 'other');
    if (!array_key_exists($department, HUB_POSITION_DEPARTMENTS)) {
        $department = 'other';
    }
    $capabilities = array_values(array_intersect((array) ($data['capabilities'] ?? []), array_keys(HUB_CAPABILITIES)));
    $sortOrder = (int) ($data['sort_order'] ?? 0);

    if ($id === null) {
        $stmt = $pdo->prepare('INSERT INTO hub_positions (name, department, capabilities, sort_order) VALUES (:name, :department, :capabilities, :sort_order)');
        $stmt->execute([':name' => $name, ':department' => $department, ':capabilities' => json_encode($capabilities), ':sort_order' => $sortOrder]);
        $newId = (int) $pdo->lastInsertId();
        auditLog($pdo, 'position_created', 'Created position #' . $newId . ' (' . $name . ')');
        return $newId;
    }

    $stmt = $pdo->prepare('UPDATE hub_positions SET name = :name, department = :department, capabilities = :capabilities, sort_order = :sort_order WHERE id = :id');
    $stmt->execute([':name' => $name, ':department' => $department, ':capabilities' => json_encode($capabilities), ':sort_order' => $sortOrder, ':id' => $id]);
    auditLog($pdo, 'position_updated', 'Updated position #' . $id . ' (' . $name . ')');
    return $id;
}

/**
 * @return array{ok: bool, error?: string}
 */
function deleteHubPosition(PDO $pdo, int $id): array
{
    ensureHubPositionsSchema($pdo);

    $inUse = $pdo->prepare('SELECT COUNT(*) FROM person_positions WHERE position_id = :id');
    $inUse->execute([':id' => $id]);
    if ((int) $inUse->fetchColumn() > 0) {
        return ['ok' => false, 'error' => 'This position is still assigned to one or more accounts. Reassign them first.'];
    }

    $position = getHubPosition($pdo, $id);
    $pdo->prepare('DELETE FROM hub_positions WHERE id = :id')->execute([':id' => $id]);
    auditLog($pdo, 'position_deleted', 'Deleted position #' . $id . ' (' . (string) ($position['name'] ?? '') . ')');
    return ['ok' => true];
}
