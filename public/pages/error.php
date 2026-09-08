<?php
/** 404 page. */
declare(strict_types=1);

set_meta(['title' => $title ?? 'Page not found']);
?>
<?php partial('page_hero', [
    'eyebrow' => 'Error 404',
    'title'   => $title ?? 'Page not found',
    'sub'     => "We couldn't find that page. It may have moved, or the link may be out of date.",
]); ?>

<div class="page">
  <div class="container">
    <p><a class="linkarrow" href="<?= e(url()) ?>">Back to home</a></p>
  </div>
</div>
