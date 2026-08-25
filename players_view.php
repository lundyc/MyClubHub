<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('football_ops')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/players_service.php';

function players_encode_json_attr(array $player): string
{
    if (isset($player['image'])) {
        $player['image_url'] = players_public_image_url((string) $player['image']);
    }

    return htmlspecialchars((string) json_encode($player, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
}

function players_render_summary_panel(array $players): string
{
    $sections = players_split_sections($players);
    ob_start();
    ?>
    <aside class="utility-panel utility-panel--admin">
        <p class="utility-panel__eyebrow">Squad Summary</p>
        <h2 class="utility-panel__title"><?= count($players) ?> player<?= count($players) === 1 ? '' : 's' ?> stored</h2>
        <ul class="status-list">
            <li><strong>Current players:</strong> <?= count($sections['current']) ?></li>
            <li><strong>Left players:</strong> <?= count($sections['left']) ?></li>
            <li><strong>Retired players:</strong> <?= count($sections['retired']) ?></li>
            <li><strong>Loan players:</strong> <?= count($sections['loan']) ?></li>
            <li><strong>Injured players:</strong> <?= count($sections['injured']) ?></li>
            <li><strong>Captain flagged:</strong> <?= count(array_filter($sections['current'], static fn(array $player): bool => !empty($player['is_captain']))) ?></li>
            <li><strong>Pictures uploaded:</strong> <?= count(array_filter($players, static fn(array $player): bool => trim((string) ($player['image'] ?? '')) !== '')) ?></li>
        </ul>
    </aside>
    <?php
    return (string) ob_get_clean();
}

function players_render_table(array $players, string $heading, string $title, string $type): string
{
    if ($players === []) {
        return '';
    }

    ob_start();
    ?>
    <div class="players-group">
        <div class="players-group__heading">
            <p class="page-kicker"><?= safe($heading) ?></p>
            <h3 class="player-section-title"><?= safe($title) ?></h3>
        </div>
        <div class="players-table-wrap">
            <table class="players-table">
                <thead>
                    <?php if ($type === 'left'): ?>
                        <tr>
                            <th scope="col">Player</th>
                            <th scope="col">Position</th>
                            <th scope="col">DOB</th>
                            <th scope="col">Joined</th>
                            <th scope="col">Left</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <th scope="col">Player</th>
                            <th scope="col">Position</th>
                            <th scope="col">DOB</th>
                            <th scope="col">Joined</th>
                            <th scope="col">Trial Ended</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                    <?php foreach ($players as $player): ?>
                        <tr>
                            <th scope="row" class="players-table__player" data-label="Player">
                                <div class="players-table__identity">
                                    <?php if (!empty($player['image'])): ?>
                                        <img class="players-table__image" src="<?= safe(players_public_image_url((string) $player['image'])) ?>" alt="<?= safe((string) $player['name']) ?>" loading="lazy">
                                    <?php else: ?>
                                        <div class="players-table__image players-table__image--placeholder"><?= safe(strtoupper(substr((string) $player['name'], 0, 1))) ?></div>
                                    <?php endif; ?>
                                    <span><?= safe((string) $player['name']) ?></span>
                                </div>
                            </th>
                            <td data-label="Position"><?= safe((string) $player['position']) ?></td>
                            <td data-label="DOB"><?= safe(app_format_uk_date((string) ($player['dob'] ?? ''))) ?></td>
                            <td data-label="Joined"><?= safe(app_format_uk_date((string) ($player['joined_date'] ?? ''))) ?></td>

                            <?php if ($type === 'left'): ?>
                                <td data-label="Left"><?= safe(app_format_uk_date((string) ($player['released_date'] ?? ''))) ?></td>
                                <td data-label="Status">
                                    <span class="refresh-history__badge refresh-history__badge--error">Left</span>
                                </td>
                            <?php else: ?>
                                <td data-label="Status">
                                    <span class="players-table__muted"><?= safe(ucfirst((string) $player['status'])) ?></span>
                                </td>
                            <?php endif; ?>

                            <td data-label="Captain">
                                <?php if (!empty($player['is_captain'])): ?>
                                    <span class="refresh-history__badge refresh-history__badge--warning">Captain</span>
                                <?php else: ?>
                                    <span class="players-table__muted">No</span>
                                <?php endif; ?>
                            </td>

                            <td data-label="Actions">
                                <div class="players-table__actions">
                                    <button
                                        type="button"
                                        class="btn btn-neutral js-edit-player"
                                        data-player="<?= players_encode_json_attr($player) ?>"
                                    >Edit</button>
                                    <form method="post" class="player-card__delete-form js-async-action" data-action-label="Deleting player" onsubmit="return confirm('Delete this player record?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="player_id" value="<?= safe((string) $player['id']) ?>">
                                        <button class="btn btn-maroon" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

function players_render_sections(array $players): string
{
    $sections = players_split_sections($players);
    ob_start();

    if ($players === []) {
        echo '<p class="feature-card__copy">No players saved yet. Add the first squad member using the form.</p>';
    } else {
        echo players_render_table($sections['current'], 'Current Players', 'Available squad', 'current');
        echo players_render_table($sections['left'], 'Left Players', 'Left the club', 'left');
        echo players_render_table($sections['retired'], 'Retired Players', 'Retired squad members', 'retired');
        echo players_render_table($sections['loan'], 'Loan Players', 'Players out on loan', 'loan');
        echo players_render_table($sections['injured'], 'Injured Players', 'Currently injured players', 'injured');
    }

    return (string) ob_get_clean();
}
