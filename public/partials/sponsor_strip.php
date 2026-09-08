<?php
/** Partner logo band. $sponsors: rows from pub_sponsors(). */
declare(strict_types=1);

/** @var list<array<string,mixed>> $sponsors */
$sponsors = $sponsors ?? [];
if ($sponsors === []) {
    return;
}
?>
<section class="partners" aria-label="Club partners">
  <div class="container">
    <div class="partners__head">
      <span class="eyebrow">Proudly supported by</span>
      <h2 class="h2">Our partners</h2>
    </div>
    <div class="partners__grid">
      <?php $sawMain = false; $breakDone = false; ?>
      <?php foreach ($sponsors as $s): ?>
        <?php
        $isMain = (int) $s['is_main_sponsor'] === 1;
        if ($isMain) {
            $sawMain = true;
        } elseif ($sawMain && !$breakDone) {
            // Principal partners fill the first row; everyone else drops below.
            echo '<span class="partners__grid__break" aria-hidden="true"></span>';
            $breakDone = true;
        }
        $logo = uploads('sponsors/' . $s['logo_path']);
        $website = trim((string) ($s['website_url'] ?? ''));
        $img = '<img src="' . e($logo) . '" alt="' . e($s['name']) . '" loading="lazy">';
        ?>
        <?php if ($website !== ''): ?>
          <a class="<?= $isMain ? 'is-main' : '' ?>" href="<?= e($website) ?>" rel="noopener" target="_blank" title="<?= e($s['name']) ?>"><?= $img ?></a>
        <?php else: ?>
          <span class="<?= $isMain ? 'is-main' : '' ?>" title="<?= e($s['name']) ?>"><?= $img ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
