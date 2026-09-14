<?php
/** Single news article. Route: /news/{slug}. */
declare(strict_types=1);

/** @var string $slug */
$article = news_find_by_slug(db(), (string) ($slug ?? ''));
if ($article === null) {
    http_response_code(404);
    set_meta(['title' => 'Article not found']);
    echo '<div class="page"><div class="container"><span class="eyebrow">Error 404</span>'
        . '<h1>Article not found</h1><p>This article may have been unpublished or moved.</p>'
        . '<p><a class="linkarrow" href="' . e(url('news')) . '">All news</a></p></div></div>';
    return;
}

$hero = trim((string) $article['hero_image_path']);
$heroUrl = $hero !== '' ? news_image_url($hero) : '';
$published = format_date($article['published_at'], 'l j F Y');
$bodyHtml = news_render($article);
$gallery = news_gallery(db(), (int) $article['id']);
$related = news_published(db(), [
    'category' => $article['category'],
    'limit' => 3,
    'exclude_id' => (int) $article['id'],
]);

set_meta([
    'title' => $article['meta_title'] !== '' ? $article['meta_title'] : $article['title'],
    'description' => $article['meta_description'] !== ''
        ? $article['meta_description']
        : ($article['excerpt'] !== '' ? $article['excerpt'] : excerpt(strip_tags($bodyHtml), 180)),
    'image' => $heroUrl !== '' ? (current_url_origin() . $heroUrl) : '',
]);

pub_jsonld([
    '@type' => 'NewsArticle',
    'headline' => $article['title'],
    'datePublished' => $article['published_at'] ? date('c', strtotime((string) $article['published_at'])) : null,
    'dateModified' => !empty($article['updated_at']) ? date('c', strtotime((string) $article['updated_at'])) : null,
    'author' => ['@type' => 'Organization', 'name' => trim((string) $article['author_name']) ?: club('club_name')],
    'publisher' => ['@type' => 'Organization', 'name' => club('club_name'), 'logo' => current_url_origin() . club_crest()],
    'image' => $heroUrl !== '' ? [current_url_origin() . $heroUrl] : null,
    'mainEntityOfPage' => current_url(),
]);
?>
<article class="article">
  <header class="article__header <?= $heroUrl !== '' ? 'article__header--hero' : '' ?>"
    <?= $heroUrl !== '' ? 'style="background-image:linear-gradient(180deg,rgba(18,22,27,.15),rgba(18,22,27,.85)),url(\'' . e($heroUrl) . '\')"' : '' ?>>
    <div class="container">
      <a class="article__cat" href="<?= e(url('news/category/' . news_slugify((string) $article['category']))) ?>"><?= e($article['category']) ?></a>
      <h1><?= e($article['title']) ?></h1>
      <p class="article__meta">
        <?php if ($published !== ''): ?><span><?= e($published) ?></span><?php endif; ?>
        <?php if (trim((string) $article['author_name']) !== ''): ?><span>By <?= e($article['author_name']) ?></span><?php endif; ?>
      </p>
    </div>
  </header>

  <div class="container article__body">
    <div class="article__layout<?= !empty($article['fixture_id']) ? ' article__layout--match' : '' ?>">
      <div class="article__main prose">
        <?php if ($heroUrl !== '' && trim((string) $article['hero_caption']) !== ''): ?>
          <p class="article__herocaption"><?= e($article['hero_caption']) ?></p>
        <?php endif; ?>

        <?= $bodyHtml /* sanitised by news_purify() */ ?>

        <?php if ($gallery): ?>
          <div class="article__gallery" data-lightbox>
            <?php foreach ($gallery as $img): ?>
              <?php $gu = news_image_url((string) $img['file_path']); $gc = trim((string) $img['caption']); ?>
              <figure>
                <a class="article__gallery__item" href="<?= e($gu) ?>" data-full="<?= e($gu) ?>" data-caption="<?= e($gc) ?>">
                  <img src="<?= e($gu) ?>" alt="<?= e($gc) ?>" loading="lazy">
                </a>
                <?php if ($gc !== ''): ?><figcaption><?= e($gc) ?></figcaption><?php endif; ?>
              </figure>
            <?php endforeach; ?>
          </div>
          <?php partial('lightbox'); ?>
        <?php endif; ?>

        <?php if (!empty($article['fixture_id'])): ?>
          <p><a class="linkarrow" href="<?= e(url('match/' . (int) $article['fixture_id'])) ?>">Match centre</a></p>
        <?php endif; ?>

        <p class="article__back"><a class="linkarrow" href="<?= e(url('news')) ?>">All news</a></p>
      </div>

      <?php if (!empty($article['fixture_id'])): ?>
        <?php partial('match_report_sidebar', ['fixture_id' => (int) $article['fixture_id']]); ?>
      <?php endif; ?>
    </div>
  </div>
</article>

<?php if ($related): ?>
<section class="band band--surface">
  <div class="container">
    <div class="band__head"><h2>More <?= e(strtolower((string) $article['category'])) ?></h2></div>
    <div class="news-grid">
      <?php foreach ($related as $rel): ?>
        <?php partial('news_card', ['article' => $rel]); ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
