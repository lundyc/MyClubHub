<?php
/**
 * Shared fixtures/results listing UI.
 * $mode: 'upcoming' | 'results'
 */
declare(strict_types=1);

$mode = ($mode ?? 'upcoming') === 'results' ? 'results' : 'upcoming';

$seasons = pub_seasons();
$seasonId = (int) ($_GET['season'] ?? 0) ?: pub_current_season_id();
$validSeason = false;
foreach ($seasons as $s) {
    if ((int) $s['id'] === $seasonId) {
        $validSeason = true;
        break;
    }
}
if (!$validSeason) {
    $seasonId = pub_current_season_id();
}

$competitions = pub_competitions_in_season($seasonId);
$competition = (string) ($_GET['competition'] ?? '');
if ($competition !== '' && !in_array($competition, $competitions, true)) {
    $competition = '';
}

$rows = pub_fixtures([
    'season_id' => $seasonId,
    'competition' => $competition ?: null,
    'type' => $mode,
]);
$byMonth = pub_fixtures_by_month($rows);

$base = url($mode === 'results' ? 'results' : 'fixtures');
$qs = static function (array $overrides) use ($seasonId, $competition): string {
    $params = array_filter([
        'season' => $seasonId,
        'competition' => $competition,
    ] + $overrides, static fn ($v) => $v !== '' && $v !== null);
    return $params ? '?' . http_build_query($params) : '';
};
?>
<?php partial('page_hero', ['eyebrow' => 'First team', 'title' => $mode === 'results' ? 'Results' : 'Fixtures']); ?>

<div class="page">
  <div class="container">
    <?php partial('fixtures_tabs', ['active' => $mode === 'results' ? 'results' : 'fixtures', 'suffix' => $qs([])]); ?>

    <form class="fxfilters" method="get" action="<?= e($base) ?>">
      <label>
        <span>Season</span>
        <select name="season" onchange="this.form.submit()">
          <?php foreach ($seasons as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $seasonId ? 'selected' : '' ?>><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($competitions): ?>
      <label>
        <span>Competition</span>
        <select name="competition" onchange="this.form.submit()">
          <option value="">All competitions</option>
          <?php foreach ($competitions as $c): ?>
            <option value="<?= e($c) ?>" <?= $c === $competition ? 'selected' : '' ?>><?= e($c) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
      <noscript><button type="submit">Go</button></noscript>
    </form>

    <?php if ($rows === []): ?>
      <div class="emptystate"><p>No <?= $mode === 'results' ? 'results' : 'fixtures' ?> to show for this selection.</p></div>
    <?php else: ?>
      <?php foreach ($byMonth as $month => $monthRows): ?>
        <h2 class="fxmonth"><?= e($month) ?></h2>
        <div class="fxlist">
          <?php foreach ($monthRows as $fixture): ?>
            <?php partial('fixture_row', ['fixture' => $fixture]); ?>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <p class="fxnote"><a class="linkarrow" href="<?= e(url('table')) ?>">League table</a></p>
  </div>
</div>
