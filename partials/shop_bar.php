<?php
/** Secondary bar for shop pages: shop home link + basket link with count.
 *  $basketCount (int), $categories (list, optional), $activeCategoryId (int). */
declare(strict_types=1);

$basketCount = (int) ($basketCount ?? 0);
$categories = $categories ?? [];
$activeCategoryId = (int) ($activeCategoryId ?? 0);
?>
<div class="shopbar">
  <div class="container shopbar__inner">
    <a class="shopbar__home" href="<?= e(url('shop')) ?>">Club shop</a>
    <?php
      $flatCats = [];
      foreach ($categories as $c) {
          $flatCats[] = $c;
          foreach (($c['children'] ?? []) as $sub) {
              $flatCats[] = $sub;
          }
      }
    ?>
    <?php if (count($flatCats) > 1): ?>
      <nav class="shopbar__cats" aria-label="Shop categories">
        <a class="<?= $activeCategoryId === 0 ? 'is-active' : '' ?>" href="<?= e(url('shop')) ?>">All</a>
        <?php foreach ($categories as $c): ?>
          <a class="<?= $activeCategoryId === (int) $c['id'] ? 'is-active' : '' ?>" href="<?= e(url('shop') . '?category=' . (int) $c['id']) ?>"><?= e($c['name']) ?></a>
          <?php foreach (($c['children'] ?? []) as $sub): ?>
            <a class="shopbar__subcat <?= $activeCategoryId === (int) $sub['id'] ? 'is-active' : '' ?>" href="<?= e(url('shop') . '?category=' . (int) $sub['id']) ?>"><?= e($sub['name']) ?></a>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
    <a class="shopbar__basket" href="<?= e(url('shop/basket')) ?>">
      <?= pub_icon('basket') ?: '🧺' ?>
      <span>Basket<?php if ($basketCount > 0): ?> <b><?= $basketCount ?></b><?php endif; ?></span>
    </a>
  </div>
</div>
