<?php
/** Route: /table — full league standings (read from the Hub's WOSFL cache). */
declare(strict_types=1);

$table = pub_league_table();
$config = pub_league_config();
$rowCount = count($table['rows']);

set_meta([
    'title' => 'League table',
    'description' => $config['title'] . ' standings.',
]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'First team',
    'title'   => $config['title'],
    'sub'     => $table['updated'] ? 'Updated ' . format_date(date('Y-m-d', $table['updated']), 'j M Y') : '',
]); ?>

<div class="page">
  <div class="container">
    <?php if (!$table['ok']): ?>
      <div class="emptystate">
        <p>The league table isn't available right now.</p>
        <p><a class="btn btn--sm" href="<?= e($table['url']) ?>" target="_blank" rel="noopener">View on WOSFL</a></p>
      </div>
    <?php else: ?>
      <div class="tablewrap">
        <table class="leaguetable">
          <thead>
            <tr>
              <th class="c">#</th>
              <th>Club</th>
              <th class="c">P</th><th class="c">W</th><th class="c">D</th><th class="c">L</th>
              <th class="c hide-sm">F</th><th class="c hide-sm">A</th><th class="c">GD</th><th class="c">Pts</th>
              <th class="c hide-sm">Form</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($table['rows'] as $i => $row): ?>
              <?php
              $pos = (int) ($row['pos'] ?: $i + 1);
              $isUs = pub_league_is_us($row);
              $zone = '';
              if ($config['promotion'] > 0 && $pos <= $config['promotion']) {
                  $zone = 'promo';
              } elseif ($config['relegation'] > 0 && $pos > $rowCount - $config['relegation']) {
                  $zone = 'releg';
              }
              $logo = trim((string) ($row['logo'] ?? ''));
              ?>
              <tr class="<?= $isUs ? 'is-us' : '' ?> <?= $zone ? 'zone-' . $zone : '' ?>">
                <td class="c"><?= $pos ?></td>
                <td class="club">
                  <?php if ($logo !== ''): ?><img src="/<?= e(ltrim($logo, '/')) ?>" alt="" loading="lazy"><?php endif; ?>
                  <span><?= e($row['club'] ?? '') ?></span>
                </td>
                <td class="c"><?= e($row['p'] ?? '') ?></td>
                <td class="c"><?= e($row['w'] ?? '') ?></td>
                <td class="c"><?= e($row['d'] ?? '') ?></td>
                <td class="c"><?= e($row['l'] ?? '') ?></td>
                <td class="c hide-sm"><?= e($row['f'] ?? '') ?></td>
                <td class="c hide-sm"><?= e($row['a'] ?? '') ?></td>
                <td class="c"><?= e($row['gd'] ?? '') ?></td>
                <td class="c"><b><?= e($row['pts'] ?? '') ?></b></td>
                <td class="c hide-sm form">
                  <?php foreach (array_slice((array) ($row['form'] ?? []), -5) as $f): ?>
                    <span class="chip chip--<?= e($f) ?>"><?= e($f) ?></span>
                  <?php endforeach; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="tablekey">
        <?php if ($config['promotion'] > 0): ?><span><i class="sw sw--promo"></i> Promotion</span><?php endif; ?>
        <?php if ($config['relegation'] > 0): ?><span><i class="sw sw--releg"></i> Relegation</span><?php endif; ?>
        <a href="<?= e($table['url']) ?>" target="_blank" rel="noopener">Full standings on WOSFL →</a>
      </div>
    <?php endif; ?>
  </div>
</div>
