<?php
/** Route: /staff — first-team management team. The club committee is a section
 *  of the "About the club" page (/club/history#committee). */
declare(strict_types=1);

$people = pub_staff_management();

set_meta([
    'title' => 'Management team',
    'description' => club('club_name') . ' first-team management and coaching staff.',
]);
?>
<?php partial('page_hero', ['eyebrow' => 'Team', 'title' => 'Management team']); ?>

<div class="page">
  <div class="container">
    <div class="teamtabs">
      <a class="teamtabs__tab" href="<?= e(url('team')) ?>">Squad</a>
      <a class="teamtabs__tab is-active" href="<?= e(url('staff')) ?>">Management team</a>
    </div>

    <?php if ($people === []): ?>
      <div class="emptystate"><p>Management team details will be published here soon.</p></div>
    <?php else: ?>
      <div class="staff-grid">
        <?php foreach ($people as $person): ?>
          <div class="staffcard">
            <div class="staffcard__photo"><span aria-hidden="true"><?= e(mb_strtoupper(mb_substr($person['name'], 0, 1))) ?></span></div>
            <span class="staffcard__role"><?= e($person['position']) ?></span>
            <span class="staffcard__name"><?= e($person['name']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <p class="fxnote"><a class="linkarrow" href="<?= e(url('club/history') . '#committee') ?>">Club committee &amp; officials</a></p>
  </div>
</div>
