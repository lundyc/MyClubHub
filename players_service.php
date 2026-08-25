<?php

declare(strict_types=1);

require_once __DIR__ . '/players_lib.php';

/**
 * @return array{current: list<array<string, mixed>>, trialist: list<array<string, mixed>>, left: list<array<string, mixed>>, retired: list<array<string, mixed>>, loan: list<array<string, mixed>>, injured: list<array<string, mixed>>}
 */
function players_split_sections(array $players): array
{
    return [
        'current' => array_values(array_filter($players, static fn(array $player): bool => ($player['status'] ?? '') === 'current')),
        'trialist' => array_values(array_filter($players, static fn(array $player): bool => ($player['status'] ?? '') === 'trialist')),
        'left' => array_values(array_filter($players, static fn(array $player): bool => ($player['status'] ?? '') === 'left')),
        'retired' => array_values(array_filter($players, static fn(array $player): bool => ($player['status'] ?? '') === 'retired')),
        'loan' => array_values(array_filter($players, static fn(array $player): bool => ($player['status'] ?? '') === 'loan')),
        'injured' => array_values(array_filter($players, static fn(array $player): bool => ($player['status'] ?? '') === 'injured')),
    ];
}

/**
 * @return array{ok: bool, message: string, errors?: list<string>, players?: list<array<string, mixed>>}
 */
function players_handle_save(array $post, array $files): array
{
    $players = players_load_all();
    $playerId = isset($post['player_id']) && is_string($post['player_id']) ? trim($post['player_id']) : '';
    $name = isset($post['name']) && is_string($post['name']) ? trim($post['name']) : '';
    $position = isset($post['position']) && is_string($post['position']) ? trim($post['position']) : '';
    $dob = isset($post['dob']) && is_string($post['dob']) ? trim($post['dob']) : '';
    $joinedDate = isset($post['joined_date']) && is_string($post['joined_date']) ? trim($post['joined_date']) : '';
    $releasedDate = isset($post['released_date']) && is_string($post['released_date']) ? trim($post['released_date']) : '';
    $status = players_normalize_status(isset($post['status']) && is_string($post['status']) ? $post['status'] : null);
    $isCaptain = isset($post['is_captain']);
    $existingImage = isset($post['existing_image']) && is_string($post['existing_image']) ? trim($post['existing_image']) : '';

    if ($status === 'current' && $joinedDate === '') {
        $joinedDate = date('Y-m-d');
    }

    if ($status === 'left' && $releasedDate === '') {
        $releasedDate = date('Y-m-d');
    }

    if ($status !== 'left') {
        $releasedDate = '';
    }

    $errors = [];
    if ($name === '') {
        $errors[] = 'Player name is required.';
    }
    if ($position === '') {
        $errors[] = 'Player position is required.';
    }
    if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
        $errors[] = 'Date of birth must use the YYYY-MM-DD format.';
    }
    if ($joinedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $joinedDate)) {
        $errors[] = 'Join date must use the YYYY-MM-DD format.';
    }
    if ($releasedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $releasedDate)) {
        $errors[] = 'End date must use the YYYY-MM-DD format.';
    }
    if ($joinedDate !== '' && $releasedDate !== '' && strcmp($releasedDate, $joinedDate) < 0) {
        $errors[] = 'End date cannot be earlier than the join date.';
    }
    if ($status === 'left' && $releasedDate === '') {
        $errors[] = 'Left players must have an end date.';
    }
    if ($status !== 'left' && $releasedDate !== '') {
        $errors[] = 'Only left players should have an end date.';
    }

    $upload = players_handle_upload($files['picture'] ?? []);
    if ($upload['error'] !== '') {
        $errors[] = $upload['error'];
    }

    if ($errors !== []) {
        return [
            'ok' => false,
            'message' => 'Please fix the player form and try again.',
            'errors' => $errors,
        ];
    }

    $timestamp = date(DATE_ATOM);
    $imagePath = $upload['path'] !== '' ? $upload['path'] : $existingImage;
    $updated = false;
    $recordId = $playerId !== '' ? $playerId : players_generate_id();
    $syncResult = players_sync_master_player([
        'id' => $recordId,
        'name' => $name,
        'position' => $position,
        'dob' => $dob,
        'joined_date' => $joinedDate,
        'released_date' => $releasedDate,
        'status' => $status,
        'image' => $imagePath,
        'is_captain' => $isCaptain,
    ]);

    if (!$syncResult['ok']) {
        return [
            'ok' => false,
            'message' => $syncResult['message'],
        ];
    }

    if (isset($syncResult['master_id']) && $syncResult['master_id'] !== '') {
        $recordId = $syncResult['master_id'];
    }

    foreach ($players as $index => $player) {
        if ((string) ($player['id'] ?? '') !== $playerId || $playerId === '') {
            continue;
        }

        if ($upload['path'] !== '' && $existingImage !== '' && $existingImage !== $upload['path']) {
            players_delete_image($existingImage);
        }

        $players[$index] = players_normalize([
            'id' => $recordId,
            'name' => $name,
            'position' => $position,
            'dob' => $dob,
            'joined_date' => $joinedDate,
            'released_date' => $releasedDate,
            'status' => $status,
            'image' => $imagePath,
            'is_captain' => $isCaptain,
            'sponsor_name' => $player['sponsor_name'] ?? '',
            'sponsor_url' => $player['sponsor_url'] ?? '',
            'created_at' => $player['created_at'] ?? $timestamp,
            'updated_at' => $timestamp,
        ]);
        $updated = true;
        break;
    }

    if (!$updated) {
        $players[] = players_normalize([
            'id' => $recordId,
            'name' => $name,
            'position' => $position,
            'dob' => $dob,
            'joined_date' => $joinedDate,
            'released_date' => $releasedDate,
            'status' => $status,
            'image' => $imagePath,
            'is_captain' => $isCaptain,
            'sponsor_name' => '',
            'sponsor_url' => '',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    if (!players_save_all($players)) {
        return [
            'ok' => false,
            'message' => 'Player record could not be saved.',
        ];
    }

    return [
        'ok' => true,
        'message' => $updated ? 'Player updated successfully.' : 'Player added successfully.',
        'players' => players_load_all(),
    ];
}

/**
 * @return array{ok: bool, message: string, players?: list<array<string, mixed>>}
 */
function players_handle_delete(array $post): array
{
    $players = players_load_all();
    $playerId = isset($post['player_id']) && is_string($post['player_id']) ? trim($post['player_id']) : '';
    $remaining = [];
    $deleted = false;

    foreach ($players as $player) {
        if ((string) ($player['id'] ?? '') === $playerId) {
            $syncResult = players_sync_master_player([
                'id' => $playerId,
                'name' => (string) ($player['name'] ?? ''),
                'position' => (string) ($player['position'] ?? ''),
                'dob' => (string) ($player['dob'] ?? ''),
                'joined_date' => (string) ($player['joined_date'] ?? ''),
                'released_date' => date('Y-m-d'),
                'status' => 'left',
                'image' => (string) ($player['image'] ?? ''),
                'is_captain' => !empty($player['is_captain']),
            ]);
            if (!$syncResult['ok']) {
                return [
                    'ok' => false,
                    'message' => $syncResult['message'],
                ];
            }

            $player['status'] = 'left';
            $player['released_date'] = date('Y-m-d');
            $player['updated_at'] = date(DATE_ATOM);
            $remaining[] = players_normalize($player);
            $deleted = true;
            continue;
        }
        $remaining[] = $player;
    }

    if ($deleted && players_save_all($remaining)) {
        return [
            'ok' => true,
            'message' => 'Player marked as left successfully.',
            'players' => players_load_all(),
        ];
    }

    return [
        'ok' => false,
        'message' => 'Player record could not be deleted.',
    ];
}

/**
 * @return array{ok: bool, message: string, players?: list<array<string, mixed>>}
 */
function players_handle_promote(array $post): array
{
    $players = players_load_all();
    $playerId = isset($post['player_id']) && is_string($post['player_id']) ? trim($post['player_id']) : '';
    $promoted = false;

    foreach ($players as $index => $player) {
        if ((string) ($player['id'] ?? '') !== $playerId) {
            continue;
        }

        $players[$index] = players_normalize([
            'id' => $player['id'] ?? '',
            'name' => $player['name'] ?? '',
            'position' => $player['position'] ?? '',
            'dob' => $player['dob'] ?? '',
            'joined_date' => $player['joined_date'] ?? '',
            'released_date' => '',
            'status' => 'current',
            'image' => $player['image'] ?? '',
            'is_captain' => !empty($player['is_captain']),
            'sponsor_name' => $player['sponsor_name'] ?? '',
            'sponsor_url' => $player['sponsor_url'] ?? '',
            'created_at' => $player['created_at'] ?? date(DATE_ATOM),
            'updated_at' => date(DATE_ATOM),
        ]);
        $syncResult = players_sync_master_player($players[$index]);
        if (!$syncResult['ok']) {
            return [
                'ok' => false,
                'message' => $syncResult['message'],
            ];
        }
        $promoted = true;
        break;
    }

    if ($promoted && players_save_all($players)) {
        return [
            'ok' => true,
            'message' => 'Player promoted to current successfully.',
            'players' => players_load_all(),
        ];
    }

    return [
        'ok' => false,
        'message' => 'Player could not be promoted.',
    ];
}
