<?php
/** Route: /shop — storefront home. Logic mirrors the live shop/index.php;
 *  presentation is the public-site design. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/shop.php';
require_once HUB_ROOT . '/lib/shop_basket.php';

shop_ensure_schema(db());
$settings = shop_get_settings(db());
$shopEnabled = ($settings['shop_enabled'] ?? '1') === '1';

$activeCategoryId = (int) ($_GET['category'] ?? 0);
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$categories = shop_category_tree(db(), false);

$filters = ['active_only' => true];
if ($activeCategoryId > 0) {
    $filters['category_id'] = $activeCategoryId;
}
if ($searchQuery !== '') {
    $filters['search'] = $searchQuery;
}
$products = $shopEnabled ? shop_get_products(db(), $filters) : [];
$basketCount = shop_basket_count();

set_meta([
    'title' => 'Club shop',
    'description' => (string) ($settings['shop_intro'] ?? 'Official ' . club('club_name') . ' merchandise.'),
]);
?>
<?php partial('shop_bar', ['basketCount' => $basketCount, 'categories' => $categories, 'activeCategoryId' => $activeCategoryId]); ?>

<?php partial('page_hero', [
    'eyebrow' => club('club_name'),
    'title'   => (string) ($settings['shop_name'] ?? 'Club shop'),
    'sub'     => (string) ($settings['shop_intro'] ?? 'Official club merchandise. Every order is made to order by our kit manufacturer.'),
]); ?>

<div class="page">
  <div class="container">
    <?php if (function_exists('shop_render_preorder_notice')): ?>
      <div class="shopnote">
        <?php ob_start(); shop_render_preorder_notice($settings, 'full'); $raw = ob_get_clean();
          // strip the live shop's own markup wrappers, keep the sentences
          echo strip_tags($raw, '<p><strong>'); ?>
      </div>
    <?php endif; ?>

    <?php if ($shopEnabled): ?>
      <form class="shopsearch" method="get" action="<?= e(url('shop')) ?>" role="search">
        <?php if ($activeCategoryId > 0): ?><input type="hidden" name="category" value="<?= (int) $activeCategoryId ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= e($searchQuery) ?>" placeholder="Search products&hellip;" aria-label="Search products">
        <button type="submit" class="btn btn--sm">Search</button>
        <?php if ($searchQuery !== ''): ?><a class="shopsearch__clear" href="<?= e(url('shop') . ($activeCategoryId > 0 ? '?category=' . (int) $activeCategoryId : '')) ?>">Clear</a><?php endif; ?>
      </form>
    <?php endif; ?>

    <?php if (!$shopEnabled): ?>
      <div class="notice notice--warn"><p>The club shop is temporarily closed. Please check back soon.</p></div>
    <?php elseif (!$products): ?>
      <div class="emptystate"><p><?= $searchQuery !== '' ? 'No products match your search.' : 'There are no products on sale right now.' ?></p></div>
    <?php else: ?>
      <div class="prodgrid">
        <?php foreach ($products as $product): ?>
          <?php
          $orderable = shop_product_is_orderable(db(), $product);
          $close = shop_product_preorder_close(db(), $product);
          $closed = $close !== null && new DateTimeImmutable('now') > $close;
          $img = trim((string) ($product['image_path'] ?? ''));
          ?>
          <a class="prodcard" href="<?= e(url('shop/p/' . rawurlencode((string) $product['slug']))) ?>">
            <div class="prodcard__media<?= $img === '' ? ' is-placeholder' : '' ?>"
                 <?= $img !== '' ? 'style="background-image:url(\'' . e($img) . '\')"' : '' ?>>
              <div class="prodcard__badges">
                <?php if ((int) $product['is_preorder'] === 1 && !$closed): ?><span class="tag tag--gold">Pre-order</span><?php endif; ?>
                <?php if ($closed): ?><span class="tag tag--muted">Pre-order closed</span><?php endif; ?>
                <?php if (!$orderable && !$closed): ?><span class="tag tag--muted">Unavailable</span><?php endif; ?>
              </div>
            </div>
            <div class="prodcard__body">
              <span class="prodcard__cat"><?= e((string) $product['category_name']) ?></span>
              <span class="prodcard__name"><?= e((string) $product['name']) ?></span>
              <span class="prodcard__price"><?= e(gbp((float) $product['price'])) ?></span>
              <span class="prodcard__cta"><?= $closed ? 'View details' : 'Choose options →' ?></span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
