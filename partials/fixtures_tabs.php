<?php
/**
 * Shared first-team match tab strip: Fixtures | Results | Results archive | Records.
 *
 * $active : 'fixtures' | 'results' | 'archive' | 'records'  (which tab is current)
 * $suffix : query string (e.g. "?season=3") appended to the Fixtures/Results
 *           links only, so switching between those two keeps the active filter.
 */
declare(strict_types=1);

$active = $active ?? 'fixtures';
$suffix = $suffix ?? '';

$tabs = [
    'fixtures' => ['Fixtures',        url('fixtures') . $suffix],
    'results'  => ['Results',         url('results') . $suffix],
    'archive'  => ['Results archive', url('club/results')],
    'records'  => ['Records',         url('club/records')],
];
?>
<div class="fxtabs">
  <?php foreach ($tabs as $key => [$label, $href]): ?>
    <a class="fxtabs__tab <?= $key === $active ? 'is-active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
