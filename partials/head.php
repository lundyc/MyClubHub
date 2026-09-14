<?php
/** Document head + open <body> + site header. Rendered after the page body so
 *  pages can set_meta() first. */
declare(strict_types=1);

$clubName = club('club_name', 'Saltcoats Victoria FC');
$clubShort = club('club_short_name', $clubName);
$title = meta('title');
$fullTitle = $title === '' ? $clubName : $title . ' — ' . $clubShort;
$description = meta('description', $clubName . ' — official website. Fixtures, results, news, squad and more.');
$ogImage = meta('image');

/* Brand tokens: only emit overrides that differ from the CSS defaults so the
   stylesheet stays the single source of the base palette. */
$tokenMap = [
    '--club-primary'     => club('brand_primary'),
    '--club-primary-ink' => club('brand_primary_ink'),
    '--club-secondary'   => club('brand_secondary'),
    '--club-on-primary'  => club('brand_on_primary'),
    '--club-accent'      => club('brand_accent'),
    '--club-accent-ink'  => club('brand_accent_ink'),
];
$tokenCss = '';
foreach ($tokenMap as $name => $value) {
    if ($value !== '') {
        $tokenCss .= $name . ':' . $value . ';';
    }
}
?>
<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($fullTitle) ?></title>
<meta name="description" content="<?= e($description) ?>">
<link rel="canonical" href="<?= e(current_url()) ?>">
<meta property="og:site_name" content="<?= e($clubName) ?>">
<meta property="og:title" content="<?= e($title === '' ? $clubName : $title) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= e(current_url()) ?>">
<?php if ($ogImage !== ''): ?>
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php endif; ?>
<link rel="icon" href="<?= e(club_crest()) ?>">
<link rel="stylesheet" href="<?= e(asset('css/public.css')) ?>">
<link rel="alternate" type="application/rss+xml" title="<?= e($clubName) ?> news" href="<?= e(url('news/feed.xml')) ?>">
<?php if ($tokenCss !== ''): ?><style>:root{<?= $tokenCss /* colour literals, validated on save */ ?>}</style><?php endif; ?>
<?php if (meta('jsonld') !== ''): ?><script type="application/ld+json"><?= meta('jsonld') /* json_encode output, slashes escaped */ ?></script><?php endif; ?>
<script src="<?= e(asset('js/public.js')) ?>" defer></script>
<?php if (club('ga_measurement_id') !== ''): ?>
<script>
window.dataLayer=window.dataLayer||[];
function gtag(){dataLayer.push(arguments);}
/* Analytics storage stays denied until the visitor accepts the cookie banner (see site_footer.php). */
gtag('consent','default',{'analytics_storage':'denied'});
</script>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= e(club('ga_measurement_id')) ?>"></script>
<script>gtag('js',new Date());gtag('config','<?= e(club('ga_measurement_id')) ?>');</script>
<?php endif; ?>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<header class="site-header">
<?php
$social = pub_social_links();
$utilityLinks = [
    'Club shop' => url('shop'),
    'Tickets'   => url('tickets'),
    'Contact'   => url('contact'),
];
?>
  <div class="utility-bar">
    <div class="container">
      <nav class="utility-bar__links" aria-label="Utility">
        <?php foreach ($utilityLinks as $label => $href): ?>
          <a href="<?= e($href) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </nav>
      <?php if ($social): ?>
        <div class="utility-bar__social">
          <?php foreach ($social as $network => $href): ?>
            <a href="<?= e($href) ?>" aria-label="<?= e(ucfirst($network)) ?>" rel="noopener" target="_blank"><?= pub_icon($network) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="masthead">
    <div class="container">
      <a class="brand" href="<?= e(url()) ?>">
        <img src="<?= e(club_crest_reverse()) ?>" alt="" width="42" height="42">
        <span class="brand__name"><?= e($clubShort) ?><small><?= e(club('club_tagline')) ?></small></span>
      </a>

      <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="primary-nav" aria-label="Menu">
        <span class="nav-toggle__open"><?= pub_icon('menu') ?></span>
        <span class="nav-toggle__close"><?= pub_icon('close') ?></span>
      </button>

      <?php partial('site_nav'); ?>
    </div>
  </div>
</header>

<main id="main">
