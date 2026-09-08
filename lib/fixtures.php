<?php

declare(strict_types=1);

/*
 * Public, read-only fixture/result queries against the Hub `match_fixtures`
 * table. Everything is scoped to the current season unless asked otherwise.
 * (Build step 5 extends this with season/competition filtering + match centre.)
 */

/** id of the season flagged is_current, or the most recent one. */
function pub_current_season_id(): int
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }
    $row = db()->query(
        'SELECT id FROM seasons ORDER BY is_current DESC, start_date DESC, id DESC LIMIT 1'
    )->fetch();
    return $id = (int) ($row['id'] ?? 0);
}

/** Common SELECT with the opponent crest joined in. */
function pub_fixture_select(): string
{
    return 'SELECT f.id, f.match_date, f.kickoff_time, f.opponent, f.competition,
                   f.competition_stage, f.is_home, f.venue, f.status,
                   f.full_time_home_score, f.full_time_away_score,
                   f.half_time_home_score, f.half_time_away_score, f.veo_url,
                   o.clubname      AS opponent_name,
                   o.logo_path     AS opponent_logo,
                   o.ground_location AS opponent_ground
            FROM match_fixtures f
            LEFT JOIN match_opponents o ON o.id = f.opponent_id';
}

/** A fixture counts as "played" once it has a full-time score or that status. */
function pub_fixture_is_played(array $f): bool
{
    return $f['status'] === 'played'
        || ($f['full_time_home_score'] !== null && $f['full_time_away_score'] !== null);
}

/**
 * Result from the club's point of view.
 * @return array{outcome:'W'|'D'|'L'|null,label:string,us:int,them:int}
 */
function pub_fixture_outcome(array $f): array
{
    if (!pub_fixture_is_played($f)
        || $f['full_time_home_score'] === null
        || $f['full_time_away_score'] === null
    ) {
        return ['outcome' => null, 'label' => '', 'us' => 0, 'them' => 0];
    }
    $home = (int) $f['full_time_home_score'];
    $away = (int) $f['full_time_away_score'];
    $us = $f['is_home'] ? $home : $away;
    $them = $f['is_home'] ? $away : $home;
    $outcome = $us > $them ? 'W' : ($us < $them ? 'L' : 'D');
    $label = ['W' => 'Win', 'L' => 'Defeat', 'D' => 'Draw'][$outcome];
    return ['outcome' => $outcome, 'label' => $label, 'us' => $us, 'them' => $them];
}

/** The next unplayed fixture (this season), or null. */
function pub_next_fixture(): ?array
{
    $sql = pub_fixture_select()
        . ' WHERE f.season_id = :s
              AND (f.status IS NULL OR f.status <> "played")
              AND f.full_time_home_score IS NULL
              AND f.match_date >= CURDATE()
            ORDER BY f.match_date ASC, f.kickoff_time ASC
            LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute([':s' => pub_current_season_id()]);
    return $stmt->fetch() ?: null;
}

/** Most recent played fixtures (this season), newest first. */
function pub_recent_results(int $limit = 5): array
{
    $limit = max(1, min(20, $limit));
    $sql = pub_fixture_select()
        . ' WHERE f.season_id = :s
              AND (f.status = "played" OR f.full_time_home_score IS NOT NULL)
            ORDER BY f.match_date DESC, f.kickoff_time DESC
            LIMIT ' . $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute([':s' => pub_current_season_id()]);
    return $stmt->fetchAll();
}

/** Upcoming fixtures (this season), soonest first. */
function pub_upcoming_fixtures(int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    $sql = pub_fixture_select()
        . ' WHERE f.season_id = :s
              AND (f.status IS NULL OR f.status <> "played")
              AND f.full_time_home_score IS NULL
              AND f.match_date >= CURDATE()
            ORDER BY f.match_date ASC, f.kickoff_time ASC
            LIMIT ' . $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute([':s' => pub_current_season_id()]);
    return $stmt->fetchAll();
}

/**
 * Best available crest URL for an opponent, or '' if none resolves.
 *
 * match_opponents.logo_path is often set to a slug ("vale-of-leven.png") that
 * was never uploaded to uploads/opponents/ but DOES exist in the Hub's WOSFL
 * badge cache (/badges/). Try both, then a slug of the club name.
 */
function pub_opponent_crest(?string $logoPath, string $name = ''): string
{
    static $cache = [];
    $logoPath = trim((string) $logoPath);
    $key = $logoPath . '|' . $name;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $candidates = [];
    if ($logoPath !== '') {
        $candidates[] = ['uploads/opponents/' . $logoPath, uploads('opponents/' . $logoPath)];
        $candidates[] = ['badges/' . $logoPath, '/badges/' . rawurlencode($logoPath)];
    }
    if ($name !== '') {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        if ($slug !== '') {
            $candidates[] = ['badges/' . $slug . '.png', '/badges/' . $slug . '.png'];
        }
    }

    foreach ($candidates as [$rel, $urlPath]) {
        if (is_file(HUB_ROOT . '/' . $rel)) {
            return $cache[$key] = $urlPath;
        }
    }
    return $cache[$key] = '';
}

/** "Saltcoats Victoria" home/away label pair for a fixture. */
function pub_fixture_teams(array $f): array
{
    $opp = $f['opponent_name'] ?: $f['opponent'] ?: 'TBC';
    $short = club('club_short_name', 'Saltcoats Vics');
    return $f['is_home']
        ? ['home' => $short, 'away' => $opp]
        : ['home' => $opp, 'away' => $short];
}

/* -------------------------------------------------------------------------
 * Season-aware listing (fixtures / results pages)
 * ---------------------------------------------------------------------- */

/** @return list<array{id:int,name:string,is_current:int}> newest first */
function pub_seasons(): array
{
    return db()->query(
        'SELECT id, name, is_current FROM seasons ORDER BY start_date DESC, id DESC'
    )->fetchAll();
}

function pub_season(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM seasons WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

/** Distinct competition names used by a season's fixtures. @return list<string> */
function pub_competitions_in_season(int $seasonId): array
{
    $stmt = db()->prepare(
        "SELECT DISTINCT competition FROM match_fixtures
         WHERE season_id = :s AND competition <> '' ORDER BY competition"
    );
    $stmt->execute([':s' => $seasonId]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * @param array{season_id?:int,competition?:string,type?:'upcoming'|'results'|'all'} $opts
 * @return list<array<string,mixed>>
 */
function pub_fixtures(array $opts = []): array
{
    $seasonId = (int) ($opts['season_id'] ?? pub_current_season_id());
    $type = $opts['type'] ?? 'all';

    $sql = pub_fixture_select() . ' WHERE f.season_id = :s';
    $params = [':s' => $seasonId];

    if (!empty($opts['competition'])) {
        $sql .= ' AND f.competition = :c';
        $params[':c'] = (string) $opts['competition'];
    }
    if ($type === 'results') {
        $sql .= ' AND (f.status = "played" OR f.full_time_home_score IS NOT NULL)';
        $sql .= ' ORDER BY f.match_date DESC, f.kickoff_time DESC';
    } elseif ($type === 'upcoming') {
        $sql .= ' AND (f.status IS NULL OR f.status <> "played") AND f.full_time_home_score IS NULL';
        $sql .= ' ORDER BY f.match_date ASC, f.kickoff_time ASC';
    } else {
        $sql .= ' ORDER BY f.match_date ASC, f.kickoff_time ASC';
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Group fixture rows by "F Y" month key, preserving row order within a month.
 * @param list<array<string,mixed>> $rows
 * @return array<string, list<array<string,mixed>>>
 */
function pub_fixtures_by_month(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $ts = strtotime((string) $row['match_date']) ?: time();
        $out[date('F Y', $ts)][] = $row;
    }
    return $out;
}

/* -------------------------------------------------------------------------
 * Single fixture + match centre (events / line-ups from data/matches.json)
 * ---------------------------------------------------------------------- */

function pub_fixture(int $id): ?array
{
    $stmt = db()->prepare(pub_fixture_select() . ' WHERE f.id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

/** Raw entry for a fixture from the Hub's data/matches.json, or null. */
function pub_match_detail(int $fixtureId): ?array
{
    static $all = null;
    if ($all === null) {
        $file = HUB_ROOT . '/data/matches.json';
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $all = is_array($decoded) ? $decoded : [];
    }
    foreach ($all as $m) {
        if ((string) ($m['id'] ?? '') === (string) $fixtureId) {
            return $m;
        }
    }
    return null;
}

/**
 * Normalised, minute-sorted events for a fixture.
 * @return list<array{minute:string,type:string,side:'us'|'opp',player:string,detail:string}>
 */
function pub_match_events(int $fixtureId): array
{
    $detail = pub_match_detail($fixtureId);
    $events = is_array($detail['events'] ?? null) ? $detail['events'] : [];
    $out = [];
    foreach ($events as $e) {
        $type = (string) ($e['type'] ?? '');
        $side = (string) ($e['team'] ?? '') === 'svfc' ? 'us' : 'opp';
        $label = match ($type) {
            'goal' => !empty($e['own_goal']) ? 'Own goal' : 'Goal',
            'card' => ((string) ($e['card_type'] ?? '') === 'red' ? 'Red card' : 'Yellow card'),
            'substitution', 'sub' => 'Substitution',
            'penalty_miss' => 'Penalty missed',
            default => ucfirst($type ?: 'Event'),
        };
        $out[] = [
            'minute' => (string) ($e['minute'] ?? ''),
            'type' => $type,
            'label' => $label,
            'side' => $side,
            'player' => (string) ($e['player'] ?? ''),
            'detail' => (string) ($e['secondary_player'] ?? ($e['note'] ?? '')),
            'sequence' => (int) ($e['sequence'] ?? 0),
        ];
    }
    usort($out, static function (array $a, array $b): int {
        $ma = (int) preg_replace('/\D+/', '', $a['minute'] ?: '0');
        $mb = (int) preg_replace('/\D+/', '', $b['minute'] ?: '0');
        return $ma <=> $mb ?: $a['sequence'] <=> $b['sequence'];
    });
    return $out;
}

/** @return array{starters:list<string>,substitutes:list<string>,captain:string} */
function pub_match_lineup(int $fixtureId): array
{
    $detail = pub_match_detail($fixtureId) ?? [];
    return [
        'starters' => array_values(array_filter((array) ($detail['starters'] ?? []), 'strlen')),
        'substitutes' => array_values(array_filter((array) ($detail['substitutes'] ?? []), 'strlen')),
        'captain' => (string) ($detail['captain'] ?? ''),
    ];
}

/** Match photos for a fixture (from the Hub match_photos table). */
function pub_match_photos(int $fixtureId, int $limit = 24): array
{
    $limit = max(1, min(60, $limit));
    $stmt = db()->prepare(
        "SELECT filename, kit FROM match_photos WHERE match_fixture_id = :f
         ORDER BY uploaded_at DESC, id DESC LIMIT $limit"
    );
    $stmt->execute([':f' => $fixtureId]);
    return $stmt->fetchAll();
}
