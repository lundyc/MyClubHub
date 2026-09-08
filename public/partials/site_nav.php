<?php
/** Primary navigation. Dropdowns open on hover/focus (desktop CSS) or via the
 *  .navbtn toggle (mobile drawer, public.js). */
declare(strict_types=1);

/**
 * key      => route/href used for the active-section check + top-level link
 * label    => visible text
 * children => optional [label => href]
 */
$nav = [
    ['route' => 'news',  'label' => 'News',    'href' => url('news')],
    ['route' => 'team',  'label' => 'Team',    'href' => url('team'), 'sections' => ['team'], 'children' => [
        'First team'  => url('team'),
        'Management'  => url('staff'),
    ]],
    ['route' => null,    'label' => 'Matches', 'href' => url('fixtures'), 'sections' => ['fixtures', 'results', 'table', 'match', 'club/results', 'club/records'], 'children' => [
        'Fixtures'        => url('fixtures'),
        'Results'         => url('results'),
        'League table'    => url('table'),
        'Results archive' => url('club/results'),
        'Club records'    => url('club/records'),
    ]],
    ['route' => null,    'label' => 'Club',    'href' => url('club/history'), 'sections' => ['club', 'contact', 'privacy', 'gallery'], 'except' => ['club/results', 'club/records'], 'children' => [
        'About the club'    => url('club/history'),
        'Honours & awards'  => url('club/honours'),
        'Photo gallery'     => url('gallery'),
        'Policies'          => url('club/policies'),
        'Contact & find us' => url('contact'),
    ]],
    ['route' => 'partners', 'label' => 'Partners', 'href' => url('partners')],
    ['route' => 'tickets', 'label' => 'Tickets', 'href' => url('tickets')],
    ['route' => 'shop',    'label' => 'Shop',    'href' => url('shop')],
];

$isSection = static function (array $item): bool {
    // Routes claimed by another top-level item (e.g. club/results now lives
    // under Team) must not also light up this section.
    foreach ($item['except'] ?? [] as $prefix) {
        if (nav_active($prefix)) {
            return false;
        }
    }
    foreach ($item['sections'] ?? ($item['route'] !== null ? [$item['route']] : []) as $prefix) {
        if (nav_active($prefix)) {
            return true;
        }
    }
    return false;
};
?>
<nav class="primary-nav" id="primary-nav" aria-label="Primary">
  <ul>
    <?php foreach ($nav as $item): ?>
      <?php $active = $isSection($item); $hasChildren = !empty($item['children']); ?>
      <li class="<?= $active ? 'is-section' : '' ?>">
        <a href="<?= e($item['href']) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
        <?php if ($hasChildren): ?>
          <button class="navbtn" type="button" aria-expanded="false" aria-label="<?= e($item['label']) ?> submenu">▾</button>
          <div class="subnav">
            <?php foreach ($item['children'] as $label => $href): ?>
              <a href="<?= e($href) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</nav>
