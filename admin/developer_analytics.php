<?php
declare(strict_types=1);

$pageStyles = ['developer_analytics.css'];
$pageHero = [
    'eyebrow' => 'Developer',
    'title' => 'Product Analytics',
    'subtitle' => 'Private usage intelligence for pages, features, journeys, users, devices, and click maps.',
    'actions' => [
        ['label' => 'Developer dashboard', 'href' => '/developer.php', 'icon' => 'fa-arrow-left'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/analytics.php';

if (!hub_auth_is_developer()) {
    echo '<div class="alert alert-danger m-3">Access denied.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

hub_analytics_ensure_schema($pdo);

$days = hub_analytics_interval_days($_GET['days'] ?? 30);
$dateSql = 'created_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)';

function analytics_number(int|float|null $value): string
{
    return number_format((float) ($value ?? 0));
}

function analytics_seconds(int|float|null $milliseconds): string
{
    $seconds = (int) round(((float) ($milliseconds ?? 0)) / 1000);
    if ($seconds < 60) {
        return $seconds . 's';
    }
    return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
}

function analytics_percent(int|float|null $value): string
{
    return number_format((float) ($value ?? 0), 0) . '%';
}

function analytics_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function analytics_fetch_one(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function analytics_page_param(string $key): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $value === false || $value === null ? 1 : (int) $value;
}

function analytics_paginate_rows(array $rows, int $page, int $perPage = 10): array
{
    $total = count($rows);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));

    return [
        'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
        'page' => $page,
        'total' => $total,
        'total_pages' => $totalPages,
    ];
}

function analytics_render_pagination(string $pageKey, array $pagination, int $days): void
{
    $totalPages = (int) ($pagination['total_pages'] ?? 1);
    if ($totalPages <= 1) {
        return;
    }

    $currentPage = (int) ($pagination['page'] ?? 1);
    $baseParams = $_GET;
    $baseParams['days'] = $days;
    echo '<nav class="mt-3" aria-label="' . h(str_replace('_', ' ', $pageKey)) . ' pages"><ul class="pagination pagination-sm mb-0">';
    for ($page = 1; $page <= $totalPages; $page++) {
        $baseParams[$pageKey] = $page;
        $href = '?' . http_build_query($baseParams);
        echo '<li class="page-item' . ($page === $currentPage ? ' active' : '') . '"><a class="page-link" href="' . h($href) . '">' . $page . '</a></li>';
    }
    echo '</ul></nav>';
}

$summary = analytics_fetch_one($pdo, "
    SELECT
        COUNT(*) AS total_events,
        SUM(event_type = 'page_view') AS page_views,
        SUM(event_type = 'click') AS clicks,
        SUM(event_type = 'form_submit') AS form_submits,
        COUNT(DISTINCT NULLIF(session_key, '')) AS sessions,
        COUNT(DISTINCT user_id) AS users,
        AVG(CASE WHEN event_type = 'page_summary' THEN duration_ms END) AS avg_duration_ms,
        AVG(CASE WHEN event_type = 'page_summary' THEN scroll_depth END) AS avg_scroll_depth
    FROM hub_analytics_events
    WHERE {$dateSql}
");

$topPages = analytics_fetch_all($pdo, "
    SELECT
        page_path,
        MAX(page_title) AS page_title,
        SUM(event_type = 'page_view') AS page_views,
        SUM(event_type = 'click') AS clicks,
        SUM(event_type = 'form_submit') AS form_submits,
        COUNT(DISTINCT user_id) AS users,
        COUNT(DISTINCT session_key) AS sessions,
        AVG(CASE WHEN event_type = 'page_summary' THEN duration_ms END) AS avg_duration_ms,
        AVG(CASE WHEN event_type = 'page_summary' THEN scroll_depth END) AS avg_scroll_depth,
        MAX(created_at) AS last_seen
    FROM hub_analytics_events
    WHERE {$dateSql}
    GROUP BY page_path
    ORDER BY page_views DESC, clicks DESC, last_seen DESC
");

$topFeatures = analytics_fetch_all($pdo, "
    SELECT
        COALESCE(NULLIF(feature_label, ''), NULLIF(element_text, ''), element_selector, 'Unnamed action') AS label,
        page_path,
        event_type,
        COUNT(*) AS events,
        COUNT(DISTINCT user_id) AS users,
        MAX(created_at) AS last_used
    FROM hub_analytics_events
    WHERE {$dateSql}
      AND event_type IN ('click', 'form_submit')
    GROUP BY label, page_path, event_type
    ORDER BY events DESC, users DESC, last_used DESC
");

$users = analytics_fetch_all($pdo, "
    SELECT
        COALESCE(NULLIF(user_display_name, ''), CONCAT('User #', user_id), 'Unknown user') AS name,
        user_role,
        COUNT(DISTINCT session_key) AS sessions,
        SUM(event_type = 'page_view') AS page_views,
        SUM(event_type = 'click') AS clicks,
        SUM(event_type = 'form_submit') AS form_submits,
        AVG(CASE WHEN event_type = 'page_summary' THEN duration_ms END) AS avg_duration_ms,
        MAX(created_at) AS last_seen
    FROM hub_analytics_events
    WHERE {$dateSql}
    GROUP BY user_id, name, user_role
    ORDER BY last_seen DESC
");

$journeys = analytics_fetch_all($pdo, "
    SELECT
        session_key,
        COALESCE(NULLIF(MAX(user_display_name), ''), 'Unknown user') AS name,
        COUNT(*) AS events,
        SUM(event_type = 'page_view') AS page_views,
        MIN(created_at) AS started_at,
        MAX(created_at) AS ended_at,
        GROUP_CONCAT(DISTINCT page_path ORDER BY created_at SEPARATOR ' > ') AS pages
    FROM hub_analytics_events
    WHERE {$dateSql}
    GROUP BY session_key
    ORDER BY ended_at DESC
");

$deviceCase = hub_analytics_device_case_sql();
$devices = analytics_fetch_all($pdo, "
    SELECT {$deviceCase} AS device, COUNT(*) AS events, COUNT(DISTINCT session_key) AS sessions
    FROM hub_analytics_events
    WHERE {$dateSql}
    GROUP BY device
    ORDER BY sessions DESC, events DESC
");

$daily = analytics_fetch_all($pdo, "
    SELECT
        DATE(created_at) AS event_day,
        SUM(event_type = 'page_view') AS page_views,
        SUM(event_type = 'click') AS clicks,
        COUNT(DISTINCT user_id) AS users
    FROM hub_analytics_events
    WHERE {$dateSql}
    GROUP BY DATE(created_at)
    ORDER BY event_day DESC
");

$topPagesPagination = analytics_paginate_rows($topPages, analytics_page_param('pages_page'));
$topFeaturesPagination = analytics_paginate_rows($topFeatures, analytics_page_param('features_page'));
$usersPagination = analytics_paginate_rows($users, analytics_page_param('users_page'));
$journeysPagination = analytics_paginate_rows($journeys, analytics_page_param('journeys_page'));
$devicesPagination = analytics_paginate_rows($devices, analytics_page_param('devices_page'));
$dailyPagination = analytics_paginate_rows($daily, analytics_page_param('daily_page'));

$topPagesVisible = $topPagesPagination['rows'];
$topFeaturesVisible = $topFeaturesPagination['rows'];
$usersVisible = $usersPagination['rows'];
$journeysVisible = $journeysPagination['rows'];
$devicesVisible = $devicesPagination['rows'];
$dailyVisible = $dailyPagination['rows'];

$maxPageViews = max(1, ...array_map(static fn(array $row): int => (int) $row['page_views'], $topPagesVisible ?: [['page_views' => 1]]));
$maxFeatureEvents = max(1, ...array_map(static fn(array $row): int => (int) $row['events'], $topFeaturesVisible ?: [['events' => 1]]));
$maxDailyViews = max(1, ...array_map(static fn(array $row): int => (int) $row['page_views'], $dailyVisible ?: [['page_views' => 1]]));
?>

<div class="analytics-dashboard">
    <div class="analytics-toolbar">
        <div>
            <strong>Analytics window</strong>
            <div class="text-muted small">First-party tracking from authenticated hub pages. Form values are not recorded.</div>
        </div>
        <div class="analytics-range" aria-label="Date range">
            <?php foreach ([7, 30, 90, 365] as $range): ?>
                <a class="btn <?= $days === $range ? 'btn-brand' : 'btn-outline-secondary' ?>" href="?days=<?= $range ?>"><?= $range === 365 ? 'Year' : $range . ' days' ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
    hub_render_metric_grid([
        ['label' => 'Page views', 'value' => analytics_number((int) ($summary['page_views'] ?? 0)), 'meta' => $days . ' day window', 'icon' => 'fa-eye', 'tone' => 'primary'],
        ['label' => 'Active users', 'value' => analytics_number((int) ($summary['users'] ?? 0)), 'meta' => analytics_number((int) ($summary['sessions'] ?? 0)) . ' sessions', 'icon' => 'fa-users', 'tone' => 'success'],
        ['label' => 'Feature clicks', 'value' => analytics_number((int) ($summary['clicks'] ?? 0)), 'meta' => analytics_number((int) ($summary['form_submits'] ?? 0)) . ' form submits', 'icon' => 'fa-computer-mouse', 'tone' => 'info'],
        ['label' => 'Avg engagement', 'value' => analytics_seconds((float) ($summary['avg_duration_ms'] ?? 0)), 'meta' => analytics_percent((float) ($summary['avg_scroll_depth'] ?? 0)) . ' avg scroll', 'icon' => 'fa-stopwatch', 'tone' => 'warning'],
    ], 'Analytics summary');
    ?>

    <div class="analytics-grid">
        <section class="analytics-panel analytics-panel--wide">
            <div class="analytics-panel__header">
                <div>
                    <h2 class="analytics-panel__title">Top Pages</h2>
                    <p class="analytics-panel__meta">Most visited areas with engagement and action volume.</p>
                </div>
                <span class="analytics-pill"><i class="fa-solid fa-ranking-star" aria-hidden="true"></i><?= count($topPagesVisible) ?> / <?= count($topPages) ?></span>
            </div>
            <?php if ($topPages === []): ?>
                <p class="analytics-empty">No analytics have been recorded for this date range yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table analytics-table align-middle">
                        <thead><tr><th>Page</th><th>Views</th><th>Users</th><th>Clicks</th><th>Avg time</th><th>Scroll</th></tr></thead>
                        <tbody>
                        <?php foreach ($topPagesVisible as $row): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?= h((string) $row['page_path']) ?></div>
                                    <div class="analytics-bar mt-1"><span class="analytics-bar__track"><span class="analytics-bar__fill" style="width: <?= min(100, ((int) $row['page_views'] / $maxPageViews) * 100) ?>%"></span></span></div>
                                </td>
                                <td><?= analytics_number((int) $row['page_views']) ?></td>
                                <td><?= analytics_number((int) $row['users']) ?></td>
                                <td><?= analytics_number((int) $row['clicks']) ?></td>
                                <td><?= analytics_seconds((float) ($row['avg_duration_ms'] ?? 0)) ?></td>
                                <td><?= analytics_percent((float) ($row['avg_scroll_depth'] ?? 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php analytics_render_pagination('pages_page', $topPagesPagination, $days); ?>
            <?php endif; ?>
        </section>

        <section class="analytics-panel analytics-panel--side">
            <div class="analytics-panel__header">
                <div>
                    <h2 class="analytics-panel__title">Devices</h2>
                    <p class="analytics-panel__meta">Viewport-based usage split.</p>
                </div>
            </div>
            <?php if ($devices === []): ?>
                <p class="analytics-empty">No device data yet.</p>
            <?php else: ?>
                <table class="table analytics-table align-middle">
                    <thead><tr><th>Device</th><th>Sessions</th><th>Events</th></tr></thead>
                    <tbody>
                    <?php foreach ($devicesVisible as $row): ?>
                        <tr><td><?= h((string) $row['device']) ?></td><td><?= analytics_number((int) $row['sessions']) ?></td><td><?= analytics_number((int) $row['events']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php analytics_render_pagination('devices_page', $devicesPagination, $days); ?>
            <?php endif; ?>
        </section>

        <section class="analytics-panel analytics-panel--wide">
            <div class="analytics-panel__header">
                <div>
                    <h2 class="analytics-panel__title">Feature Usage</h2>
                    <p class="analytics-panel__meta">Buttons, links, and form submissions people use most.</p>
                </div>
            </div>
            <?php if ($topFeatures === []): ?>
                <p class="analytics-empty">No feature clicks have been recorded yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table analytics-table align-middle">
                        <thead><tr><th>Feature</th><th>Page</th><th>Type</th><th>Events</th><th>Users</th></tr></thead>
                        <tbody>
                        <?php foreach ($topFeaturesVisible as $row): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?= h((string) $row['label']) ?></div>
                                    <div class="analytics-bar mt-1"><span class="analytics-bar__track"><span class="analytics-bar__fill" style="width: <?= min(100, ((int) $row['events'] / $maxFeatureEvents) * 100) ?>%"></span></span></div>
                                </td>
                                <td><?= h((string) $row['page_path']) ?></td>
                                <td><?= h(str_replace('_', ' ', (string) $row['event_type'])) ?></td>
                                <td><?= analytics_number((int) $row['events']) ?></td>
                                <td><?= analytics_number((int) $row['users']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php analytics_render_pagination('features_page', $topFeaturesPagination, $days); ?>
            <?php endif; ?>
        </section>

        <section class="analytics-panel analytics-panel--side">
            <div class="analytics-panel__header">
                <div>
                    <h2 class="analytics-panel__title">Daily Trend</h2>
                    <p class="analytics-panel__meta">Recent page views by day.</p>
                </div>
            </div>
            <?php if ($daily === []): ?>
                <p class="analytics-empty">No trend data yet.</p>
            <?php else: ?>
                <table class="table analytics-table align-middle">
                    <thead><tr><th>Day</th><th>Views</th><th>Users</th></tr></thead>
                    <tbody>
                    <?php foreach ($dailyVisible as $row): ?>
                        <tr>
                            <td><?= h(date('d M', strtotime((string) $row['event_day']))) ?></td>
                            <td>
                                <div class="analytics-bar">
                                    <span><?= analytics_number((int) $row['page_views']) ?></span>
                                    <span class="analytics-bar__track"><span class="analytics-bar__fill" style="width: <?= min(100, ((int) $row['page_views'] / $maxDailyViews) * 100) ?>%"></span></span>
                                </div>
                            </td>
                            <td><?= analytics_number((int) $row['users']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php analytics_render_pagination('daily_page', $dailyPagination, $days); ?>
            <?php endif; ?>
        </section>

        <section class="analytics-panel analytics-panel--wide">
            <div class="analytics-panel__header">
                <div>
                    <h2 class="analytics-panel__title">User Activity</h2>
                    <p class="analytics-panel__meta">Who is using the Hub and how actively.</p>
                </div>
            </div>
            <?php if ($users === []): ?>
                <p class="analytics-empty">No user activity has been recorded yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table analytics-table align-middle">
                        <thead><tr><th>User</th><th>Role</th><th>Sessions</th><th>Views</th><th>Clicks</th><th>Avg time</th><th>Last seen</th></tr></thead>
                        <tbody>
                        <?php foreach ($usersVisible as $row): ?>
                            <tr>
                                <td class="fw-bold"><?= h((string) $row['name']) ?></td>
                                <td><?= h((string) $row['user_role']) ?></td>
                                <td><?= analytics_number((int) $row['sessions']) ?></td>
                                <td><?= analytics_number((int) $row['page_views']) ?></td>
                                <td><?= analytics_number((int) $row['clicks']) ?></td>
                                <td><?= analytics_seconds((float) ($row['avg_duration_ms'] ?? 0)) ?></td>
                                <td><?= h(date('d M H:i', strtotime((string) $row['last_seen']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php analytics_render_pagination('users_page', $usersPagination, $days); ?>
            <?php endif; ?>
        </section>

        <section class="analytics-panel analytics-panel--side">
            <div class="analytics-panel__header">
                <div>
                    <h2 class="analytics-panel__title">Recent Journeys</h2>
                    <p class="analytics-panel__meta">Latest sessions and their page sequence.</p>
                </div>
            </div>
            <?php if ($journeys === []): ?>
                <p class="analytics-empty">No journeys have been recorded yet.</p>
            <?php else: ?>
                <div class="vstack gap-3">
                    <?php foreach ($journeysVisible as $row): ?>
                        <article>
                            <div class="d-flex justify-content-between gap-2">
                                <strong><?= h((string) $row['name']) ?></strong>
                                <span class="analytics-pill"><?= analytics_number((int) $row['page_views']) ?> views</span>
                            </div>
                            <div class="small text-muted mt-1"><?= h((string) $row['pages']) ?></div>
                            <div class="small text-muted mt-1"><?= h(date('d M H:i', strtotime((string) $row['started_at']))) ?> to <?= h(date('H:i', strtotime((string) $row['ended_at']))) ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php analytics_render_pagination('journeys_page', $journeysPagination, $days); ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
