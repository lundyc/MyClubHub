<?php
/** Route: /match/{id} — match centre. */
declare(strict_types=1);

$fixture = pub_fixture((int) ($id ?? 0));
if ($fixture === null) {
    http_response_code(404);
    set_meta(['title' => 'Match not found']);
    echo '<div class="page"><div class="container"><span class="eyebrow">Error 404</span>'
        . '<h1>Match not found</h1><p><a class="linkarrow" href="' . e(url('fixtures')) . '">All fixtures</a></p></div></div>';
    return;
}

$teams = pub_fixture_teams($fixture);
$played = pub_fixture_is_played($fixture);
$outcome = pub_fixture_outcome($fixture);
$oppCrest = pub_opponent_crest($fixture['opponent_logo'] ?? '', (string) ($fixture['opponent_name'] ?? $fixture['opponent'] ?? ''));
$homeCrest = $fixture['is_home'] ? club_crest_reverse() : $oppCrest; // dark hero
$awayCrest = $fixture['is_home'] ? $oppCrest : club_crest_reverse();
$venue = trim((string) ($fixture['venue'] ?? '')) ?: ($fixture['is_home'] ? club('ground_name') : '');
$ko = format_time($fixture['kickoff_time'] ?? '');

$events = $played ? pub_match_events((int) $fixture['id']) : [];
$lineup = pub_match_lineup((int) $fixture['id']);
$photos = pub_match_photos((int) $fixture['id']);
$album  = pub_gallery_for_fixture((int) $fixture['id']);
$related = news_for_fixture(db(), (int) $fixture['id']);
$veo = trim((string) ($fixture['veo_url'] ?? ''));

set_meta([
    'title' => $teams['home'] . ' v ' . $teams['away'],
    'description' => trim(($fixture['competition'] ?: 'Fixture') . ' · ' . format_date($fixture['match_date'], 'j M Y')),
]);

$startIso = trim((string) $fixture['match_date'] . 'T' . ($fixture['kickoff_time'] ?: '15:00:00'));
pub_jsonld([
    '@type' => 'SportsEvent',
    'name' => $teams['home'] . ' v ' . $teams['away'],
    'sport' => 'Football',
    'startDate' => $startIso,
    'eventStatus' => $played ? 'https://schema.org/EventScheduled' : 'https://schema.org/EventScheduled',
    'location' => $venue !== '' ? ['@type' => 'Place', 'name' => $venue] : null,
    'competitor' => [
        ['@type' => 'SportsTeam', 'name' => $teams['home']],
        ['@type' => 'SportsTeam', 'name' => $teams['away']],
    ],
    'organizer' => ['@type' => 'Organization', 'name' => (string) ($fixture['competition'] ?: 'Fixture')],
]);
?>
<section class="mc-hero">
  <div class="container">
    <p class="mc-hero__comp"><?= e($fixture['competition'] ?: 'Fixture') ?><?php if ($fixture['competition_stage']): ?> · <?= e($fixture['competition_stage']) ?><?php endif; ?></p>
    <div class="mc-hero__grid">
      <div class="mc-hero__team">
        <?php if ($homeCrest): ?><img src="<?= e($homeCrest) ?>" alt=""><?php endif; ?>
        <span><?= e($teams['home']) ?></span>
      </div>
      <div class="mc-hero__mid">
        <?php if ($played): ?>
          <b class="mc-hero__score"><?= (int) $fixture['full_time_home_score'] ?> – <?= (int) $fixture['full_time_away_score'] ?></b>
          <?php if ($fixture['half_time_home_score'] !== null): ?>
            <span class="mc-hero__ht">HT <?= (int) $fixture['half_time_home_score'] ?>–<?= (int) $fixture['half_time_away_score'] ?></span>
          <?php endif; ?>
        <?php else: ?>
          <b class="mc-hero__ko"><?= e($ko ?: 'TBC') ?></b>
        <?php endif; ?>
        <span class="mc-hero__date"><?= e(format_date($fixture['match_date'], 'D j M Y')) ?></span>
      </div>
      <div class="mc-hero__team">
        <?php if ($awayCrest): ?><img src="<?= e($awayCrest) ?>" alt=""><?php endif; ?>
        <span><?= e($teams['away']) ?></span>
      </div>
    </div>
    <?php if ($venue): ?><p class="mc-hero__venue"><?= e($venue) ?></p><?php endif; ?>
  </div>
</section>

<div class="container mc-body">
  <div class="mc-main">
    <?php if (!$played): ?>
      <div class="mc-panel">
        <h2>Preview</h2>
        <p><?= e($teams['home']) ?> host <?= e($teams['away']) ?> in the <?= e($fixture['competition'] ?: 'league') ?>
           on <?= e(format_date($fixture['match_date'], 'l j F')) ?><?= $ko ? ' (' . e($ko) . ' kick-off)' : '' ?>.</p>
        <p><a class="btn btn--sm" href="/tickets">Match tickets</a></p>
      </div>
    <?php endif; ?>

    <?php if ($events): ?>
      <div class="mc-panel">
        <h2>Match events</h2>
        <ol class="mc-timeline">
          <?php foreach ($events as $ev): ?>
            <li class="mc-ev mc-ev--<?= e($ev['side']) ?>">
              <span class="mc-ev__min"><?= e($ev['minute'] !== '' ? $ev['minute'] . "'" : '') ?></span>
              <span class="mc-ev__icon mc-ev__icon--<?= e($ev['type']) ?>" aria-hidden="true"></span>
              <span class="mc-ev__text">
                <b><?= e($ev['label']) ?></b>
                <?php if ($ev['player'] !== ''): ?><span><?= e($ev['player']) ?></span><?php endif; ?>
                <?php if ($ev['detail'] !== ''): ?><small><?= e($ev['detail']) ?></small><?php endif; ?>
              </span>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>
    <?php endif; ?>

    <?php if ($lineup['starters']): ?>
      <div class="mc-panel">
        <h2><?= e(club('club_short_name')) ?> line-up</h2>
        <ul class="mc-xi">
          <?php foreach ($lineup['starters'] as $name): ?>
            <li><?= e($name) ?><?php if ($name === $lineup['captain']): ?> <span class="mc-cap">(C)</span><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
        <?php if ($lineup['substitutes']): ?>
          <h3>Substitutes</h3>
          <p class="mc-subs"><?= e(implode(', ', $lineup['substitutes'])) ?></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($photos || $album): ?>
      <div class="mc-panel">
        <h2>Gallery</h2>
        <?php if ($photos): ?>
          <div class="mc-gallery">
            <?php foreach ($photos as $ph): ?>
              <img src="<?= e(uploads('matches/' . $ph['filename'])) ?>" alt="" loading="lazy">
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($album): ?>
          <p<?= $photos ? ' style="margin-top:1rem"' : '' ?>>
            <a class="linkarrow" href="<?= e(url('gallery/' . $album['slug'])) ?>">
              <?= $photos ? 'Full photo album' : $album['title'] ?> · <?= (int) $album['photo_count'] ?> photo<?= (int) $album['photo_count'] === 1 ? '' : 's' ?>
            </a>
          </p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($veo !== ''): ?>
      <p><a class="btn btn--ghost btn--sm" href="<?= e($veo) ?>" target="_blank" rel="noopener">Watch on Veo</a></p>
    <?php endif; ?>
  </div>

  <aside class="mc-side">
    <?php if ($related): ?>
      <div class="mc-panel">
        <h2>Related news</h2>
        <ul class="mc-related">
          <?php foreach ($related as $r): ?>
            <li><a href="<?= e(url('news/' . $r['slug'])) ?>"><?= e($r['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <div class="mc-panel">
      <h2>More</h2>
      <ul class="mc-related">
        <li><a href="<?= e(url('fixtures')) ?>">All fixtures</a></li>
        <li><a href="<?= e(url('results')) ?>">All results</a></li>
        <li><a href="<?= e(url('table')) ?>">League table</a></li>
      </ul>
    </div>
  </aside>
</div>
