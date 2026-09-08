<?php
/** Route: /club/results — season-by-season results archive (history_matches). */
declare(strict_types=1);

$seasons = pub_history_seasons();
$totals  = pub_history_totals();

$current = trim((string) ($_GET['season'] ?? ''));
$valid = array_column($seasons, 'season');
if ($current === '' || !in_array($current, $valid, true)) {
    $current = $valid[0] ?? '';
}
$rows = $current !== '' ? pub_history_results($current) : [];

set_meta([
    'title' => 'Results archive',
    'description' => 'Every ' . club('club_name') . ' result on record — ' .
        ($totals['y0'] ?? '') . ' to ' . ($totals['y1'] ?? '') . '.',
]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'First team',
    'title'   => 'Results archive',
    'sub'     => $totals
        ? sprintf(
            '%d matches on record from %d to %d — %d won, %d drawn, %d lost.',
            (int) $totals['played'], (int) $totals['y0'], (int) $totals['y1'],
            (int) $totals['w'], (int) $totals['d'], (int) $totals['l']
        )
        : '',
]); ?>

<div class="page">
  <div class="container">
    <?php partial('fixtures_tabs', ['active' => 'archive']); ?>

    <?php if (!$seasons): ?>
      <div class="emptystate"><p>The results archive is being prepared.</p></div>
    <?php else: ?>
      <form class="fxfilters" method="get" action="<?= e(url('club/results')) ?>">
        <label>
          <span>Season</span>
          <select name="season" onchange="this.form.submit()">
            <?php foreach ($seasons as $s): ?>
              <option value="<?= e($s['season']) ?>" <?= $s['season'] === $current ? 'selected' : '' ?>>
                <?= e(pub_history_season_label($s['season'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <noscript><button type="submit">Go</button></noscript>
      </form>

      <?php
      $sel = null;
      foreach ($seasons as $s) {
          if ($s['season'] === $current) { $sel = $s; break; }
      }
      ?>
      <?php if ($sel): ?>
        <h2 class="arcseason"><?= e(pub_history_season_label($current)) ?> season</h2>
        <ul class="pp-stats arcstats">
          <li><span class="pp-stats__k">Played</span><span class="pp-stats__v"><?= (int) $sel['played'] ?></span></li>
          <li class="arcstats--w"><span class="pp-stats__k">Won</span><span class="pp-stats__v"><?= (int) $sel['w'] ?></span></li>
          <li class="arcstats--d"><span class="pp-stats__k">Drawn</span><span class="pp-stats__v"><?= (int) $sel['d'] ?></span></li>
          <li class="arcstats--l"><span class="pp-stats__k">Lost</span><span class="pp-stats__v"><?= (int) $sel['l'] ?></span></li>
          <li><span class="pp-stats__k">Goals for</span><span class="pp-stats__v"><?= (int) $sel['gf'] ?></span></li>
          <li><span class="pp-stats__k">Goals against</span><span class="pp-stats__v"><?= (int) $sel['ga'] ?></span></li>
        </ul>
      <?php endif; ?>

      <div class="tablewrap">
        <table class="arctable">
          <thead>
            <tr>
              <th>Date</th><th>Competition</th><th class="c">H/A</th>
              <th>Opponent</th><th class="c">Score</th><th class="c">Result</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <?php
              $vs = (int) $r['home_score']; // stored as our score / their score
              $os = (int) $r['away_score'];
              $res = (string) ($r['result'] ?? '');
              ?>
              <tr>
                <td class="nowrap"><?= e(format_date($r['match_date'], 'j M Y')) ?></td>
                <td><?= e($r['competition'] ?: '—') ?></td>
                <td class="c"><?= $r['is_home'] ? 'H' : 'A' ?></td>
                <td>
                  <?php if ($r['has_report']): ?>
                    <a href="<?= e(url('club/results/' . $r['slug'])) ?>"><?= e($r['opponent']) ?></a>
                  <?php else: ?>
                    <?= e($r['opponent']) ?>
                  <?php endif; ?>
                </td>
                <td class="c nowrap"><b><?= $vs ?>–<?= $os ?></b></td>
                <td class="c">
                  <?php if ($res !== ''): ?><span class="chip chip--<?= e($res) ?>"><?= e($res) ?></span><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
