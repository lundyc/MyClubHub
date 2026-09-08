<?php
/** Route: /partners — club sponsors & partners. */
declare(strict_types=1);

$main = pub_main_sponsors();
$others = pub_other_sponsors();
$contactEmail = club('contact_email');

set_meta([
    'title' => 'Partners',
    'description' => 'The businesses and supporters backing ' . club('club_name') . '.',
]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'Commercial',
    'title'   => 'Our partners',
    'sub'     => 'The local businesses and supporters who back ' . club('club_name') . '.',
]); ?>

<?php if ($main): ?>
<section class="band">
  <div class="container">
    <div class="band__head"><div><span class="eyebrow">Principal partners</span><h2>Leading the way</h2></div></div>
    <div class="partner-lead">
      <?php foreach ($main as $s): ?>
        <?php
        $logo = trim((string) $s['logo_path']);
        $link = pub_sponsor_link($s);
        $addr = trim((string) ($s['address'] ?? ''));
        $phone = trim((string) ($s['contact_phone'] ?? ''));
        ?>
        <div class="partner-lead__card">
          <div class="partner-lead__logo">
            <?php if ($logo !== ''): ?>
              <img src="<?= e(uploads('sponsors/' . $logo)) ?>" alt="<?= e($s['name']) ?>" loading="lazy">
            <?php else: ?>
              <span><?= e($s['name']) ?></span>
            <?php endif; ?>
          </div>
          <div class="partner-lead__body">
            <h3><?= e($s['name']) ?></h3>
            <?php if ($addr !== ''): ?><p class="partner-lead__meta"><?= e($addr) ?></p><?php endif; ?>
            <?php if ($phone !== ''): ?><p class="partner-lead__meta"><?= e($phone) ?></p><?php endif; ?>
            <?php if ($link !== ''): ?>
              <a class="linkarrow" href="<?= e($link) ?>" target="_blank" rel="noopener">Visit</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($others): ?>
<section class="band band--surface">
  <div class="container">
    <div class="band__head"><div><span class="eyebrow">In partnership with</span><h2>Our partners &amp; supporters</h2></div></div>
    <div class="partner-grid">
      <?php foreach ($others as $s): ?>
        <?php
        $logo = trim((string) $s['logo_path']);
        $link = pub_sponsor_link($s);
        $inner = $logo !== ''
            ? '<img src="' . e(uploads('sponsors/' . $logo)) . '" alt="' . e($s['name']) . '" loading="lazy">'
            : '<span class="partner-grid__name">' . e($s['name']) . '</span>';
        ?>
        <?php if ($link !== ''): ?>
          <a class="partner-grid__item<?= $logo === '' ? ' is-text' : '' ?>" href="<?= e($link) ?>" target="_blank" rel="noopener" title="<?= e($s['name']) ?>"><?= $inner ?></a>
        <?php else: ?>
          <span class="partner-grid__item<?= $logo === '' ? ' is-text' : '' ?>" title="<?= e($s['name']) ?>"><?= $inner ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="band">
  <div class="container">
    <div class="partner-cta">
      <div>
        <h2>Partner with <?= e(club('club_short_name', club('club_name'))) ?></h2>
        <p>Match sponsorship, matchball, hospitality, advertising boards and player sponsorship packages are available. Get your business in front of the Campbell Park crowd and support your local club.</p>
      </div>
      <div class="partner-cta__actions">
        <?php if ($contactEmail !== ''): ?>
          <a class="btn" href="mailto:<?= e($contactEmail) ?>?subject=<?= rawurlencode('Sponsorship enquiry') ?>">Email the club</a>
        <?php endif; ?>
        <a class="btn btn--ghost" href="<?= e(url('contact')) ?>">Contact form</a>
      </div>
    </div>
  </div>
</section>
