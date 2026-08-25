<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';

$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
$isPublicPage = in_array($currentScript, ['login.php', 'forgot_password.php', 'reset_password.php'], true);

if (!hub_auth_is_authenticated() && !$isPublicPage) {
    header('Location: login.php');
    exit;
}

$currentUser = hub_auth_current_user();
$currentRole = $currentUser['role'] ?? 'guest';

// Volunteers and staff default-deny: only admin sees everything
// unconditionally. Everyone else only sees a page if it's in
// HUB_PAGE_CAPABILITIES and one of their committee positions grants that
// capability. Starts near-empty until an admin assigns a position with
// capabilities via /positions.php — expanding what someone can reach is
// just adding another page to HUB_PAGE_CAPABILITIES, same pattern.
// HUB_PAGE_GATE_EXEMPT lists pages this blanket check must not touch,
// because something else already grants or enforces access correctly
// (the shared dashboard, reports.php's own per-report-type gate, and the
// handful of pages already gated by the separate role-based
// tickets.*/pos.* permission layer, which grants volunteer/staff a
// ticket-ops baseline independent of committee position).
if (in_array($currentRole, ['volunteer', 'staff'], true) && !in_array($currentScript, HUB_PAGE_GATE_EXEMPT, true)) {
    $roleCapabilities = HUB_PAGE_CAPABILITIES[$currentScript] ?? null;
    if ($roleCapabilities === null || !hub_auth_has_any_capability($roleCapabilities)) {
        http_response_code(403);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>No access yet</title></head>'
            . '<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1.5rem;color:#2d2b2c;">'
            . '<h1 style="font-size:1.3rem;">No areas assigned yet</h1>'
            . '<p>Your account doesn\'t have access to any Hub pages yet. Ask an admin to assign you a committee position under Positions.</p>'
            . '<p><a href="/logout.php">Log out</a></p>'
            . '</body></html>';
        exit;
    }
}

$seasonContext = ['seasons' => [], 'season_id' => 0, 'season' => null];
if (hub_auth_is_authenticated() && isset($pdo)) {
    try {
        $seasonContext = getSeasonContext($pdo);
    } catch (Throwable $exception) {
        // The shell must remain usable while season data is unavailable.
    }
}

function activePage(string $page): string
{
    return basename($_SERVER['PHP_SELF'] ?? '') === $page ? 'active' : '';
}

function activeGroup(array $pages): string
{
    $current = basename($_SERVER['PHP_SELF'] ?? '');
    return in_array($current, $pages, true) ? 'active' : '';
}

function hub_nav_current(array $pages): string
{
    return in_array(basename($_SERVER['PHP_SELF'] ?? ''), $pages, true) ? ' aria-current="page"' : '';
}

function hub_default_page_hero(string $script): array
{
    $map = [
        'index.php' => [
            'eyebrow' => 'Club overview',
            'title' => 'Club Hub',
            'subtitle' => 'The latest club, sponsorship, fixture, and publishing activity.',
            'actions' => [
                ['label' => 'Add fixture', 'href' => '/match.php?action=new', 'class' => 'btn btn-light btn-sm'],
                ['label' => 'Add player', 'href' => '/player_add.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'players.php' => [
            'eyebrow' => 'Club',
            'title' => 'Players',
            'subtitle' => 'Manage squad records, player statuses, and sponsorship links.',
            'actions' => [
                ['label' => 'Add player', 'href' => '/player_add.php', 'class' => 'btn btn-light btn-sm'],
                ['label' => 'View sponsors', 'href' => '/sponsors.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'sponsors.php' => [
            'eyebrow' => 'Club',
            'title' => 'Sponsors',
            'subtitle' => 'Manage sponsor records, season status, and payment progress.',
            'actions' => [
                ['label' => 'Add sponsor', 'href' => '/sponsor.php?action=new', 'class' => 'btn btn-light btn-sm'],
                ['label' => 'View agreements', 'href' => '/sponsorship_agreements.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'matches.php' => [
            'eyebrow' => 'Match Day',
            'title' => 'Matches',
            'subtitle' => 'Fixture management, sponsorships, and match-day workflow.',
            'actions' => [
                ['label' => 'Add fixture', 'href' => '/match.php?action=new', 'class' => 'btn btn-light btn-sm'],
                ['label' => 'League table', 'href' => '/league_table.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'league_table.php' => [
            'eyebrow' => 'Publishing',
            'title' => 'League Table',
            'subtitle' => 'Generate and publish league table graphics.',
            'actions' => [
                ['label' => 'Matches', 'href' => '/matches.php', 'class' => 'btn btn-outline-light btn-sm'],
                ['label' => 'Template packs', 'href' => '/template_packs.php', 'class' => 'btn btn-outline-light btn-sm'],
                ['label' => 'Facebook diagnostics', 'href' => '/facebook_diagnostics.php', 'class' => 'btn btn-outline-light btn-sm'],
                ['label' => 'Settings', 'href' => '/settings.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'templates.php' => [
            'eyebrow' => 'Publishing',
            'title' => 'Template Packs',
            'subtitle' => 'Manage complete, reusable graphic design systems.',
            'actions' => [
                ['label' => 'Matches', 'href' => '/matches.php', 'class' => 'btn btn-outline-light btn-sm'],
                ['label' => 'League table', 'href' => '/league_table.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'template_packs.php' => [
            'eyebrow' => 'Publishing',
            'title' => 'Template Packs',
            'subtitle' => 'Create and manage consistent designs for every match graphic.',
            'actions' => [
                ['label' => 'Matches', 'href' => '/matches.php', 'class' => 'btn btn-outline-light btn-sm'],
                ['label' => 'Publishing settings', 'href' => '/settings.php?tab=publishing', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'template_pack.php' => [
            'eyebrow' => 'Publishing',
            'title' => 'Edit Template Pack',
            'subtitle' => 'Configure branding and layouts across the match-day graphic set.',
            'actions' => [
                ['label' => 'All packs', 'href' => '/template_packs.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'settings.php' => [
            'eyebrow' => 'Publishing',
            'title' => 'Settings',
            'subtitle' => 'Configure hub content, imagery, and publishing preferences.',
            'actions' => [
                ['label' => 'Template packs', 'href' => '/template_packs.php', 'class' => 'btn btn-outline-light btn-sm'],
                ['label' => 'Publishing settings', 'href' => '/settings.php?tab=publishing', 'class' => 'btn btn-outline-light btn-sm'],
                ['label' => 'Facebook diagnostics', 'href' => '/facebook_diagnostics.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'facebook_diagnostics.php' => [
            'eyebrow' => 'Publishing',
            'title' => 'Facebook Diagnostics',
            'subtitle' => 'Recent Facebook publish attempts, duplicates blocked, and redacted API responses.',
            'actions' => [
                ['label' => 'Publishing settings', 'href' => '/settings.php?tab=publishing', 'class' => 'btn btn-outline-light btn-sm'],
                ['label' => 'League table', 'href' => '/league_table.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'admin_users.php' => [
            'eyebrow' => 'Administration',
            'title' => 'People & Users',
            'subtitle' => 'Legacy users URL. Manage account access from People & Users.',
            'actions' => [
                ['label' => 'People & Users', 'href' => '/club_people.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'club_people.php' => [
            'eyebrow' => 'Administration',
            'title' => 'People & Users',
            'subtitle' => 'One record per person: supporters, staff, volunteers and committee. Login access, positions and season tickets attach to the person.',
            'actions' => [
                ['label' => 'Roles & Positions', 'href' => '/positions.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'club_person.php' => [
            'eyebrow' => 'Administration',
            'title' => 'Person & User',
            'subtitle' => 'Manage one person, their login account, club positions, dependants and ticket history.',
            'actions' => [
                ['label' => 'People & Users', 'href' => '/club_people.php', 'class' => 'btn btn-outline-light btn-sm'],
            ],
        ],
        'media.php' => [
            'eyebrow' => 'Administration',
            'title' => 'Media Library',
            'subtitle' => 'Browse, upload and edit the images used across the Hub.',
            'actions' => [],
        ],
        'match_photos.php' => [
            'eyebrow' => 'Administration',
            'title' => 'Media Library',
            'subtitle' => 'Browse, upload and edit hub images, including every tagged match photo.',
            'actions' => [],
        ],
    ];

    $hero = $map[$script] ?? [
        'eyebrow' => 'Club management',
        'title' => 'Club Hub',
        'subtitle' => 'One site for club management, graphics, posting, and match tools.',
        'actions' => [],
    ];

    // Page operations live beside the content they affect. Default heroes are
    // deliberately informational so fallback pages do not reintroduce a
    // detached action strip.
    $hero['actions'] = [];
    return $hero;
}

/**
 * @param array{
 *   eyebrow?: string,
 *   title?: string,
 *   subtitle?: string,
 *   class?: string,
 *   actions?: list<array{label?: string, icon?: string, href?: string, class?: string, variant?: 'primary'|'secondary', tag?: 'a'|'button', attrs?: array<string, scalar>}>
 * } $hero
 */
function hub_render_page_hero(array $hero): void
{
    $eyebrow = (string) ($hero['eyebrow'] ?? '');
    $title = (string) ($hero['title'] ?? '');
    $subtitle = (string) ($hero['subtitle'] ?? '');
    $class = trim('page-hero ' . (string) ($hero['class'] ?? ''));
    $actions = isset($hero['actions']) && is_array($hero['actions']) ? $hero['actions'] : [];

    $actionIcon = static function (array $action): string {
        if (!empty($action['icon'])) {
            return (string) $action['icon'];
        }
        $label = strtolower(trim((string) ($action['label'] ?? '')));
        $iconClass = match (true) {
            preg_match('/^(add|create|new)\b/', $label) === 1 || str_contains($label, '+ add') => 'fa-plus',
            str_starts_with($label, 'back') || str_starts_with($label, 'all ') => 'fa-arrow-left',
            str_contains($label, 'export') => 'fa-download',
            str_contains($label, 'import') => 'fa-upload',
            str_contains($label, 'copy') => 'fa-copy',
            str_contains($label, 'preview') || str_contains($label, 'graphic') || str_contains($label, 'poster') => 'fa-image',
            str_contains($label, 'setting') || str_contains($label, 'pricing') => 'fa-gear',
            str_contains($label, 'fixture') || str_contains($label, 'match') => 'fa-futbol',
            str_contains($label, 'league') => 'fa-table',
            str_contains($label, 'sponsor') => 'fa-handshake',
            str_contains($label, 'agreement') => 'fa-file-signature',
            str_contains($label, 'package') || str_contains($label, 'pack') => 'fa-box-open',
            str_contains($label, 'report') => 'fa-chart-column',
            str_contains($label, 'wall') => 'fa-border-all',
            default => 'fa-arrow-right',
        };
        return '<i class="fa-solid ' . $iconClass . '" aria-hidden="true"></i>';
    };

    $primaryIndex = null;
    foreach ($actions as $index => $action) {
        $declaredVariant = (string) ($action['variant'] ?? '');
        $legacyClass = (string) ($action['class'] ?? '');
        if ($declaredVariant === 'primary' || str_contains($legacyClass, 'btn-light') || str_contains($legacyClass, 'btn-brand')) {
            $primaryIndex = $index;
            break;
        }
    }
    if ($primaryIndex === null) {
        foreach ($actions as $index => $action) {
            $label = strtolower(trim((string) ($action['label'] ?? '')));
            if (preg_match('/^(\+\s*)?(add|create|new|save)\b/', $label) === 1) {
                $primaryIndex = $index;
                break;
            }
        }
    }

    foreach ($actions as $index => &$action) {
        $action['_variant'] = $index === $primaryIndex ? 'primary' : 'secondary';
        $action['_index'] = $index;
        $action['_icon'] = $actionIcon($action);
    }
    unset($action);
    usort($actions, static function (array $left, array $right): int {
        $variantOrder = ['primary' => 0, 'secondary' => 1];
        $comparison = ($variantOrder[$left['_variant']] ?? 1) <=> ($variantOrder[$right['_variant']] ?? 1);
        return $comparison !== 0 ? $comparison : (($left['_index'] ?? 0) <=> ($right['_index'] ?? 0));
    });

    $visibleActions = count($actions) > 3 ? array_slice($actions, 0, 2) : $actions;
    $overflowActions = count($actions) > 3 ? array_slice($actions, 2) : [];

    $renderAction = static function (array $action, bool $inMenu = false): void {
        $tag = (string) ($action['tag'] ?? 'a');
        $icon = (string) ($action['_icon'] ?? '');
        $labelText = trim((string) ($action['label'] ?? ''));
        if (str_contains($icon, 'fa-plus')) {
            $labelText = preg_replace('/^\+\s*/', '', $labelText) ?? $labelText;
        }
        $label = htmlspecialchars($labelText, ENT_QUOTES, 'UTF-8');
        $attrs = isset($action['attrs']) && is_array($action['attrs']) ? $action['attrs'] : [];
        $attrHtml = '';
        foreach ($attrs as $attrName => $attrValue) {
            $attrHtml .= ' ' . htmlspecialchars((string) $attrName, ENT_QUOTES, 'UTF-8') . '="' . htmlspecialchars((string) $attrValue, ENT_QUOTES, 'UTF-8') . '"';
        }
        $class = $inMenu
            ? 'dropdown-item page-hero-action-menu__item'
            : 'btn page-hero-action page-hero-action--' . (($action['_variant'] ?? 'secondary') === 'primary' ? 'primary' : 'secondary');
        $content = $icon . '<span>' . $label . '</span>';
        if ($tag === 'button') {
            $buttonType = array_key_exists('type', $attrs) ? '' : ' type="button"';
            echo '<button class="' . $class . '"' . $buttonType . $attrHtml . '>' . $content . '</button>';
            return;
        }
        $href = htmlspecialchars((string) ($action['href'] ?? '#'), ENT_QUOTES, 'UTF-8');
        echo '<a href="' . $href . '" class="' . $class . '"' . $attrHtml . '>' . $content . '</a>';
    };
    ?>
    <header class="<?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>" aria-labelledby="hubPageTitle">
        <div class="page-hero-body d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
            <div>
                <div class="page-hero-eyebrow"><?= htmlspecialchars($eyebrow, ENT_QUOTES, 'UTF-8') ?></div>
                <h1 class="page-hero-title" id="hubPageTitle"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="page-hero-subtitle"><?= htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <?php if ($actions !== []): ?>
                <div class="page-hero-actions" aria-label="Page actions">
                    <?php foreach ($visibleActions as $action): $renderAction($action); endforeach; ?>
                    <?php if ($overflowActions !== []): ?>
                        <div class="dropdown page-hero-action-menu">
                            <button class="btn page-hero-action page-hero-action--more dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fa-solid fa-ellipsis" aria-hidden="true"></i><span>More actions</span>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end">
                                <?php foreach ($overflowActions as $action): $renderAction($action, true); endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </header>
    <?php
}

/**
 * Render the single shared summary-card pattern used throughout the Hub.
 *
 * @param list<array{label:string,value:scalar,meta?:string,icon?:string,tone?:string,href?:string,value_attrs?:array<string,scalar>}> $metrics
 */
function hub_render_metric_grid(array $metrics, string $ariaLabel = 'Page summary'): void
{
    if ($metrics === []) {
        return;
    }
    $allowedTones = ['primary', 'success', 'warning', 'danger', 'info', 'neutral'];
    ?>
    <section class="hub-metric-grid" aria-label="<?= htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8') ?>">
        <?php foreach ($metrics as $metric): ?>
            <?php
            $tone = (string) ($metric['tone'] ?? 'primary');
            $tone = in_array($tone, $allowedTones, true) ? $tone : 'primary';
            $icon = trim((string) ($metric['icon'] ?? 'fa-chart-simple'));
            $icon = str_starts_with($icon, 'fa-') && !str_contains($icon, ' ') ? 'fa-solid ' . $icon : $icon;
            $href = trim((string) ($metric['href'] ?? ''));
            $tag = $href !== '' ? 'a' : 'article';
            $valueAttrs = '';
            foreach (($metric['value_attrs'] ?? []) as $attrName => $attrValue) {
                $valueAttrs .= ' ' . htmlspecialchars((string) $attrName, ENT_QUOTES, 'UTF-8') . '="' . htmlspecialchars((string) $attrValue, ENT_QUOTES, 'UTF-8') . '"';
            }
            ?>
            <<?= $tag ?> class="hub-metric-card<?= $href !== '' ? ' hub-metric-card--link' : '' ?>"<?= $href !== '' ? ' href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                <span class="hub-metric-card__icon hub-metric-card__icon--<?= $tone ?>"><i class="<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i></span>
                <span class="hub-metric-card__body">
                    <span class="hub-metric-card__label"><?= htmlspecialchars((string) ($metric['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    <strong class="hub-metric-card__value"<?= $valueAttrs ?>><?= htmlspecialchars((string) ($metric['value'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php if (trim((string) ($metric['meta'] ?? '')) !== ''): ?>
                        <span class="hub-metric-card__meta"><?= htmlspecialchars((string) $metric['meta'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php else: ?>
                        <span class="hub-metric-card__meta" aria-hidden="true">&nbsp;</span>
                    <?php endif; ?>
                </span>
                <?php if ($href !== ''): ?><i class="fa-solid fa-chevron-right hub-metric-card__arrow" aria-hidden="true"></i><?php endif; ?>
            </<?= $tag ?>>
        <?php endforeach; ?>
    </section>
    <?php
}

if ($currentScript === 'sponsor_wall.php' && !isset($pageHero)) {
    // This creative workspace supplies a specialised, in-page workflow hero.
    $pageHero = [];
} elseif (!isset($pageHero) || !is_array($pageHero)) {
    $pageHero = hub_default_page_hero($currentScript);
}
$documentTitle = trim((string) ($pageHero['title'] ?? ''));
$documentTitle = $documentTitle !== '' ? $documentTitle . ' – ' . APP_NAME : APP_NAME;

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#4b0818">
    <title><?= htmlspecialchars($documentTitle, ENT_QUOTES, 'UTF-8') ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" rel="stylesheet">
    <link href="/assets/css/style.css?v=<?= (int)(@filemtime(__DIR__ . '/assets/css/style.css') ?: time()) ?>" rel="stylesheet">
    <?php foreach (($pageStyles ?? []) as $pageStyle): ?>
        <?php
        $pageStyle = basename((string) $pageStyle);
        $pageStylePath = __DIR__ . '/assets/css/' . $pageStyle;
        if ($pageStyle === '' || !is_file($pageStylePath)) {
            continue;
        }
        ?>
        <link href="/assets/css/<?= htmlspecialchars($pageStyle, ENT_QUOTES, 'UTF-8') ?>?v=<?= (int)(@filemtime($pageStylePath) ?: time()) ?>" rel="stylesheet">
    <?php endforeach; ?>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="/assets/js/app.js?v=<?= (int)(@filemtime(__DIR__ . '/assets/js/app.js') ?: time()) ?>" defer></script>
</head>

<body class="hub-shell">
    <a class="hub-skip-link" href="#hubMainContent">Skip to main content</a>
    <nav class="navbar navbar-expand-lg border-bottom shadow-sm navbar-dark" id="hubSideNavigation">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold text-brand" href="index.php">
                <img
                    src="Saltcoats Victoria FC -White_Transparent.png"
                    alt="Saltcoats Victoria FC"
                    class="navbar-brand__logo"
                    loading="eager"
                >
                <span class="navbar-brand__text"><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></span>
            </a>

            <button class="navbar-toggler collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain"
                    aria-controls="navbarMain" aria-expanded="false" aria-label="Toggle navigation">
                <span class="custom-toggler-icon">
                    <span></span>
                    <span></span>
                    <span></span>
                </span>
            </button>

            <div class="collapse navbar-collapse justify-content-center" id="navbarMain">
                <?php
                $isAdmin = (string) $currentRole === 'admin';
                ?>
                <div class="nav-sections d-flex flex-column w-100">
                    <?php if (($seasonContext['seasons'] ?? []) !== []): ?>
                        <form class="hub-season-switcher" method="get" action="<?= htmlspecialchars($currentScript, ENT_QUOTES, 'UTF-8') ?>" id="hubSeasonSwitcherForm">
                            <label for="hubSeasonSelect">Working season</label>
                            <div class="hub-season-switcher__control">
                                <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                                <select id="hubSeasonSelect" name="season_id" aria-describedby="hubSeasonHelp">
                                    <?php foreach ($seasonContext['seasons'] as $navSeason): ?>
                                        <option value="<?= (int) $navSeason['id'] ?>" <?= (int) $seasonContext['season_id'] === (int) $navSeason['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $navSeason['name'], ENT_QUOTES, 'UTF-8') ?><?= !empty($navSeason['is_current']) ? ' · Current' : '' ?><?= !empty($navSeason['is_locked']) ? ' · Locked' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <span id="hubSeasonHelp" class="visually-hidden">Changing this updates season-based information across the Hub.</span>
                        </form>
                        <script>
                            // Keep whatever report/filters/tab the user is on when they just switch season,
                            // instead of the plain season_id-only submit resetting the page to its defaults.
                            (function () {
                                var form = document.getElementById('hubSeasonSwitcherForm');
                                if (!form) return;
                                form.addEventListener('submit', function () {
                                    var params = new URLSearchParams(window.location.search);
                                    params.delete('season_id');
                                    params.forEach(function (value, key) {
                                        var input = document.createElement('input');
                                        input.type = 'hidden';
                                        input.name = key;
                                        input.value = value;
                                        form.appendChild(input);
                                    });
                                });
                            })();
                        </script>
                    <?php endif; ?>
                    <section class="nav-section">
                        <div class="nav-section-label">Overview</div>
                        <ul class="navbar-nav nav-main mb-0">
                            <li class="nav-item"><a class="nav-link <?= activePage('index.php') ?>"<?= hub_nav_current(['index.php']) ?> href="/index.php"><i class="fa-solid fa-house me-1" aria-hidden="true"></i>Club overview</a></li>
                        </ul>
                    </section>

                    <section class="nav-section">
                        <div class="nav-section-label">Match Day</div>
                        <ul class="navbar-nav nav-main mb-0">
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['matches.php', 'match.php', 'match_starting_11.php', 'match_starting_11_graphic.php', 'match_next_match.php', 'match_graphics.php', 'match_poster.php', 'match_events.php', 'monthly_fixtures.php', 'match_fixtures_poster.php', 'match_media.php']) ?>"<?= hub_nav_current(['matches.php', 'match.php', 'match_starting_11.php', 'match_starting_11_graphic.php', 'match_next_match.php', 'match_graphics.php', 'match_poster.php', 'match_events.php', 'monthly_fixtures.php', 'match_fixtures_poster.php']) ?> href="/matches.php"><i class="fa-solid fa-futbol me-1" aria-hidden="true"></i>Match day</a></li>
                            <li class="nav-item"><a class="nav-link <?= str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/scan') ? 'active' : '' ?>"<?= str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/scan') ? ' aria-current="page"' : '' ?> href="/scan_overview.php"><i class="fa-solid fa-qrcode me-1" aria-hidden="true"></i>Scan</a></li>
                            <li class="nav-item"><a class="nav-link <?= str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/pos') ? 'active' : '' ?>"<?= str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/pos') ? ' aria-current="page"' : '' ?> href="/pos_overview.php"><i class="fa-solid fa-cash-register me-1" aria-hidden="true"></i>POS</a></li>
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['players.php', 'player_add.php', 'player_edit.php', 'player_view.php']) ?>"<?= hub_nav_current(['players.php', 'player_add.php', 'player_edit.php', 'player_view.php']) ?> href="/players.php"><i class="fa-solid fa-users me-1" aria-hidden="true"></i>Players</a></li>
                        </ul>
                    </section>

                    <section class="nav-section">
                        <div class="nav-section-label">Sponsorship</div>
                        <ul class="navbar-nav nav-main mb-0">
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['sponsors.php','sponsor.php']) ?>"<?= hub_nav_current(['sponsors.php', 'sponsor.php']) ?> href="/sponsors.php"><i class="fa-solid fa-handshake me-1" aria-hidden="true"></i>Sponsors</a></li>
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['sponsorship_agreements.php','sponsorship_agreement.php']) ?>"<?= hub_nav_current(['sponsorship_agreements.php', 'sponsorship_agreement.php']) ?> href="/sponsorship_agreements.php"><i class="fa-solid fa-file-signature me-1" aria-hidden="true"></i>Agreements</a></li>
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['sponsorship_bundles.php','sponsorship_bundle.php']) ?>"<?= hub_nav_current(['sponsorship_bundles.php', 'sponsorship_bundle.php']) ?> href="/sponsorship_bundles.php"><i class="fa-solid fa-boxes-stacked me-1" aria-hidden="true"></i>Bundles</a></li>
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['sponsorship_packages.php','sponsorship_package.php', 'sponsorship_types.php', 'sponsorship_type.php']) ?>"<?= hub_nav_current(['sponsorship_packages.php', 'sponsorship_package.php']) ?> href="/sponsorship_packages.php"><i class="fa-solid fa-box-open me-1" aria-hidden="true"></i>Packages</a></li>
                        </ul>
                    </section>

                    <section class="nav-section">
                        <div class="nav-section-label">Fundraising</div>
                        <ul class="navbar-nav nav-main mb-0">
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['hidden_team_games.php', 'hidden_team_game.php']) ?>"<?= hub_nav_current(['hidden_team_games.php', 'hidden_team_game.php']) ?> href="/hidden_team_games.php"><i class="fa-solid fa-futbol me-1" aria-hidden="true"></i>Hidden Team</a></li>
                        </ul>
                    </section>

                    <section class="nav-section">
                        <?php $ticketingActive = activeGroup(['season_ticket_orders.php', 'season_ticket_order.php', 'fixture_tickets.php', 'ticket_orders.php', 'ticket_packages.php', 'season_ticket_renewals.php', 'season_ticket_free_codes.php', 'season_ticket_types.php', 'season_ticket_type.php']); ?>
                        <button class="nav-section-toggle <?= $ticketingActive ?>" type="button" data-bs-toggle="collapse" data-bs-target="#hubTicketingNavigation" aria-expanded="<?= $ticketingActive === 'active' ? 'true' : 'false' ?>" aria-controls="hubTicketingNavigation">
                            <span>Ticketing</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </button>
                        <ul class="navbar-nav nav-main nav-submenu collapse <?= $ticketingActive === 'active' ? 'show' : '' ?> mb-0" id="hubTicketingNavigation">
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['season_ticket_orders.php', 'season_ticket_order.php']) ?>"<?= hub_nav_current(['season_ticket_orders.php', 'season_ticket_order.php']) ?> href="/season_ticket_orders.php"><i class="fa-solid fa-id-card me-1" aria-hidden="true"></i>Season Tickets</a></li>
                            <li class="nav-item"><a class="nav-link <?= activePage('fixture_tickets.php') ?>"<?= hub_nav_current(['fixture_tickets.php']) ?> href="/fixture_tickets.php"><i class="fa-solid fa-cash-register me-1" aria-hidden="true"></i>Match Tickets</a></li>
                            <li class="nav-item"><a class="nav-link <?= activePage('ticket_orders.php') ?>"<?= hub_nav_current(['ticket_orders.php']) ?> href="/ticket_orders.php"><i class="fa-solid fa-list-check me-1" aria-hidden="true"></i>Ticket Orders</a></li>
                            <li class="nav-item"><a class="nav-link <?= activePage('ticket_packages.php') ?>"<?= hub_nav_current(['ticket_packages.php']) ?> href="/ticket_packages.php"><i class="fa-solid fa-box-open me-1" aria-hidden="true"></i>Ticket Packages</a></li>
                            <li class="nav-item"><a class="nav-link <?= activePage('season_ticket_renewals.php') ?>"<?= hub_nav_current(['season_ticket_renewals.php']) ?> href="/season_ticket_renewals.php"><i class="fa-solid fa-arrows-rotate me-1" aria-hidden="true"></i>Renewals</a></li>
                            <li class="nav-item"><a class="nav-link <?= activePage('season_ticket_free_codes.php') ?>"<?= hub_nav_current(['season_ticket_free_codes.php']) ?> href="/season_ticket_free_codes.php"><i class="fa-solid fa-key me-1" aria-hidden="true"></i>Free Signup Codes</a></li>
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['season_ticket_types.php', 'season_ticket_type.php']) ?>"<?= hub_nav_current(['season_ticket_types.php', 'season_ticket_type.php']) ?> href="/season_ticket_types.php"><i class="fa-solid fa-tags me-1" aria-hidden="true"></i>Types &amp; Pricing</a></li>
                        </ul>
                    </section>

                    <section class="nav-section">
                        <?php $membersActive = activeGroup(['announcements.php', 'announcement.php', 'feedback.php', 'feedback_item.php', 'venue_reviews.php', 'motm.php']); ?>
                        <button class="nav-section-toggle <?= $membersActive ?>" type="button" data-bs-toggle="collapse" data-bs-target="#hubMembersNavigation" aria-expanded="<?= $membersActive === 'active' ? 'true' : 'false' ?>" aria-controls="hubMembersNavigation">
                            <span>Members Area</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </button>
                        <ul class="navbar-nav nav-main nav-submenu collapse <?= $membersActive === 'active' ? 'show' : '' ?> mb-0" id="hubMembersNavigation">
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['announcements.php', 'announcement.php']) ?>"<?= hub_nav_current(['announcements.php', 'announcement.php']) ?> href="/announcements.php"><i class="fa-solid fa-bullhorn me-1" aria-hidden="true"></i>Announcements</a></li>
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['feedback.php', 'feedback_item.php']) ?>"<?= hub_nav_current(['feedback.php', 'feedback_item.php']) ?> href="/feedback.php"><i class="fa-solid fa-comment-dots me-1" aria-hidden="true"></i>Feedback</a></li>
                            <li class="nav-item"><a class="nav-link <?= activePage('venue_reviews.php') ?>"<?= hub_nav_current(['venue_reviews.php']) ?> href="/venue_reviews.php"><i class="fa-solid fa-star me-1" aria-hidden="true"></i>Venue Reviews</a></li>
                            <li class="nav-item"><a class="nav-link <?= activePage('motm.php') ?>"<?= hub_nav_current(['motm.php']) ?> href="/motm.php"><i class="fa-solid fa-trophy me-1" aria-hidden="true"></i>Man of the Match</a></li>
                        </ul>
                    </section>

                    <section class="nav-section">
                        <div class="nav-section-label">Finance</div>
                        <ul class="navbar-nav nav-main mb-0">
                            <li class="nav-item"><a class="nav-link <?= activePage('reports.php') ?>"<?= hub_nav_current(['reports.php']) ?> href="/reports.php"><i class="fa-solid fa-chart-column me-1" aria-hidden="true"></i>Reports</a></li>
                            <li class="nav-item"><a class="nav-link <?= activePage('stripe_dashboard.php') ?>"<?= hub_nav_current(['stripe_dashboard.php']) ?> href="/stripe_dashboard.php"><i class="fa-brands fa-stripe-s me-1" aria-hidden="true"></i>Stripe Dashboard</a></li>
                        </ul>
                    </section>

                    <section class="nav-section">
                        <div class="nav-section-label">Secretary</div>
                        <ul class="navbar-nav nav-main mb-0">
                            <li class="nav-item"><a class="nav-link <?= activePage('secretary_dashboard.php') ?>"<?= hub_nav_current(['secretary_dashboard.php']) ?> href="/secretary_dashboard.php"><i class="fa-solid fa-user-tie me-1" aria-hidden="true"></i>Dashboard</a></li>
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['discipline_register.php', 'discipline_incident.php', 'discipline_incident_delete.php']) ?>"<?= hub_nav_current(['discipline_register.php', 'discipline_incident.php', 'discipline_incident_delete.php']) ?> href="/discipline_register.php"><i class="fa-solid fa-square-exclamation me-1" aria-hidden="true"></i>Discipline register</a></li>
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['player_registrations.php', 'player_registration_edit.php']) ?>"<?= hub_nav_current(['player_registrations.php', 'player_registration_edit.php']) ?> href="/player_registrations.php"><i class="fa-solid fa-id-card-clip me-1" aria-hidden="true"></i>Player registrations</a></li>
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['secretary_tasks.php', 'secretary_task_edit.php']) ?>"<?= hub_nav_current(['secretary_tasks.php', 'secretary_task_edit.php']) ?> href="/secretary_tasks.php"><i class="fa-solid fa-list-check me-1" aria-hidden="true"></i>Tasks &amp; planner</a></li>
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['secretary_correspondence.php', 'secretary_correspondence_edit.php']) ?>"<?= hub_nav_current(['secretary_correspondence.php', 'secretary_correspondence_edit.php']) ?> href="/secretary_correspondence.php"><i class="fa-solid fa-envelope me-1" aria-hidden="true"></i>Correspondence</a></li>
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['fixture_change_requests.php', 'fixture_change_request_edit.php']) ?>"<?= hub_nav_current(['fixture_change_requests.php', 'fixture_change_request_edit.php']) ?> href="/fixture_change_requests.php"><i class="fa-solid fa-calendar-days me-1" aria-hidden="true"></i>Fixture changes</a></li>
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['committee_meetings.php', 'committee_meeting_edit.php', 'committee_meeting_delete.php']) ?>"<?= hub_nav_current(['committee_meetings.php', 'committee_meeting_edit.php', 'committee_meeting_delete.php']) ?> href="/committee_meetings.php"><i class="fa-solid fa-people-group me-1" aria-hidden="true"></i>Committee &amp; AGM</a></li>
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['secretary_documents.php', 'secretary_document_delete.php']) ?>"<?= hub_nav_current(['secretary_documents.php', 'secretary_document_delete.php']) ?> href="/secretary_documents.php"><i class="fa-solid fa-folder-tree me-1" aria-hidden="true"></i>Documents</a></li>
                            <li class="nav-item"><a class="nav-link <?= activePage('secretary_guide.php') ?>"<?= hub_nav_current(['secretary_guide.php']) ?> href="/secretary_guide.php"><i class="fa-solid fa-circle-question me-1" aria-hidden="true"></i>Emergency guide</a></li>
                        </ul>
                    </section>

                    <section class="nav-section">
                        <?php $setupActive = activeGroup(['seasons.php', 'season.php', 'opponents.php', 'opponent.php', 'competitions.php', 'competition.php', 'venues.php', 'venue.php']); ?>
                        <button class="nav-section-toggle <?= $setupActive ?>" type="button" data-bs-toggle="collapse" data-bs-target="#hubSetupNavigation" aria-expanded="<?= $setupActive === 'active' ? 'true' : 'false' ?>" aria-controls="hubSetupNavigation">
                            <span>Club setup</span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </button>
                        <ul class="navbar-nav nav-main nav-submenu collapse <?= $setupActive === 'active' ? 'show' : '' ?> mb-0" id="hubSetupNavigation">
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['seasons.php', 'season.php']) ?>" href="/seasons.php"><i class="fa-solid fa-calendar-days me-1" aria-hidden="true"></i>Seasons</a></li>
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['opponents.php', 'opponent.php']) ?>" href="/opponents.php"><i class="fa-solid fa-people-arrows me-1" aria-hidden="true"></i>Opponents</a></li>
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['competitions.php', 'competition.php']) ?>" href="/competitions.php"><i class="fa-solid fa-trophy me-1" aria-hidden="true"></i>Competitions</a></li>
                            <li class="nav-item"><a class="nav-link <?= activeGroup(['venues.php', 'venue.php']) ?>" href="/venues.php"><i class="fa-solid fa-map-location-dot me-1" aria-hidden="true"></i>Venues</a></li>
                        </ul>
                    </section>

                    <section class="nav-section">
                        <div class="nav-section-label">Publishing</div>
                        <ul class="navbar-nav nav-main mb-0">
                            <li class="nav-item"><a class="nav-link <?= activePage('league_table.php') ?>"<?= hub_nav_current(['league_table.php']) ?> href="/league_table.php"><i class="fa-solid fa-table me-1" aria-hidden="true"></i>League table</a></li>
                            <?php if ($isAdmin): ?><li class="nav-item"><a class="nav-link <?= activeGroup(['template_packs.php', 'template_pack.php', 'templates.php']) ?>" href="/template_packs.php"><i class="fa-solid fa-layer-group me-1" aria-hidden="true"></i>Template packs</a></li><?php endif; ?>
                        </ul>
                    </section>

                    <?php if ($isAdmin): ?>
                        <section class="nav-section">
                            <div class="nav-section-label">Admin</div>
                            <ul class="navbar-nav nav-main mb-0">
                                <li class="nav-item"><a class="nav-link <?= activeGroup(['match_photos.php', 'media.php', 'facebook_photo_import.php']) ?>"<?= hub_nav_current(['match_photos.php', 'media.php', 'facebook_photo_import.php']) ?> href="/match_photos.php"><i class="fa-solid fa-photo-film me-1" aria-hidden="true"></i>Media Library</a></li>
                                <li class="nav-item"><a class="nav-link <?= activePage('people.php') ?>"<?= hub_nav_current(['people.php']) ?> href="/people.php"><i class="fa-solid fa-user-tag me-1" aria-hidden="true"></i>Tagged people</a></li>
                                <li class="nav-item"><a class="nav-link <?= activeGroup(['settings.php', 'social_post_settings.php']) ?>"<?= hub_nav_current(['settings.php', 'social_post_settings.php']) ?> href="/settings.php"><i class="fa-solid fa-gear me-1" aria-hidden="true"></i>Settings</a></li>
                                <li class="nav-item"><a class="nav-link <?= activeGroup(['club_people.php', 'club_person.php']) ?>"<?= hub_nav_current(['club_people.php', 'club_person.php']) ?> href="/club_people.php"><i class="fa-solid fa-address-book me-1" aria-hidden="true"></i>People &amp; Users</a></li>
                                <li class="nav-item"><a class="nav-link <?= activePage('positions.php') ?>"<?= hub_nav_current(['positions.php']) ?> href="/positions.php"><i class="fa-solid fa-sitemap me-1" aria-hidden="true"></i>Roles &amp; Positions</a></li>
                            </ul>
                        </section>
                    <?php endif; ?>

                    <section class="nav-section nav-account">
                        <div class="nav-account__identity"><i class="fa-solid fa-circle-user" aria-hidden="true"></i><span><strong><?= htmlspecialchars((string) ($currentUser['display_name'] ?? $currentUser['username'] ?? $currentUser['email'] ?? 'Hub user'), ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars(ucfirst((string) $currentRole), ENT_QUOTES, 'UTF-8') ?></small></span></div>
                        <ul class="navbar-nav nav-main mb-0">
                            <li class="nav-item">
                                <button id="logoutBtn" class="nav-link text-start" aria-label="Log out" type="button" data-csrf-token="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="fa-solid fa-right-from-bracket me-1"></i>Log out
                                </button>
                            </li>
                        </ul>
                    </section>
                </div>
            </div>
        </div>
    </nav>

    <button class="hub-desktop-nav-toggle d-none d-xl-inline-flex" id="hubDesktopNavToggle" type="button" aria-controls="hubSideNavigation" aria-expanded="true" aria-label="Hide side navigation" title="Hide side navigation">
        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
    </button>

    <main class="hub-main" id="hubMainContent" tabindex="-1">
        <div class="container-fluid">
        <?php if (!empty($pageHero) && is_array($pageHero)): ?>
            <?php hub_render_page_hero($pageHero); ?>
        <?php endif; ?>
    <script>
        (function() {
            var logoutButton = document.getElementById('logoutBtn');
            if (!logoutButton) {
                return;
            }

            logoutButton.addEventListener('click', function() {
                var button = this;
                var originalText = button.textContent;

                button.disabled = true;
                button.textContent = 'Logging Out...';

                fetch('auth_endpoint.php?action=logout', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: 'csrf_token=' + encodeURIComponent(button.getAttribute('data-csrf-token') || '')
                }).then(function(response) {
                    if (!response.ok) {
                        throw new Error('Logout failed.');
                    }
                }).then(function() {
                    window.location.href = 'login.php';
                }).catch(function() {
                    button.disabled = false;
                    button.textContent = originalText;
                });
            });
        })();
    </script>
