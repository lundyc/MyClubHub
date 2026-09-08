<?php
/** Route: /team — first-team squad by position. */
declare(strict_types=1);

$groups = squad_public_grouped(db());
$hasAny = array_sum(array_map('count', $groups)) > 0;
$labels = ['GK' => 'Goalkeepers', 'DEF' => 'Defenders', 'MID' => 'Midfielders', 'FWD' => 'Forwards'];

set_meta([
    'title' => 'First team',
    'description' => 'The ' . club('club_name') . ' first-team squad.',
]);
?>
<?php partial('page_hero', ['eyebrow' => 'Team', 'title' => 'First team']); ?>

<div class="page">
  <div class="container">
    <div class="teamtabs">
      <a class="teamtabs__tab is-active" href="<?= e(url('team')) ?>">Squad</a>
      <a class="teamtabs__tab" href="<?= e(url('staff')) ?>">Management &amp; staff</a>
    </div>

    <?php if (!$hasAny): ?>
      <div class="emptystate"><p>Squad profiles are being prepared and will appear here soon.</p></div>
    <?php else: ?>
      <?php foreach ($labels as $key => $label): ?>
        <?php if (empty($groups[$key])) { continue; } ?>
        <h2 class="squad-heading"><?= e($label) ?></h2>
        <div class="squad-grid">
          <?php foreach ($groups[$key] as $player): ?>
            <?php partial('player_card', ['player' => $player]); ?>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
