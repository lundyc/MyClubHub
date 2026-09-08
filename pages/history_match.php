<?php
/** Route: /club/results/{slug} — a single archived match with its report. */
declare(strict_types=1);

$match = pub_history_match((string) ($slug ?? ''));
if ($match === null) {
    http_response_code(404);
    set_meta(['title' => 'Match not found']);
    echo '<div class="page"><div class="container"><span class="eyebrow">Error 404</span>'
        . '<h1>Match not found</h1><p><a class="linkarrow" href="' . e(url('club/results')) . '">Results archive</a></p></div></div>';
    return;
}

// home_score / away_score are stored as OUR score / THEIR score.
$us   = $match['home_score'];
$them = $match['away_score'];
$played = $us !== null && $them !== null;
$res = (string) ($match['result'] ?? '');
$resWord = ['W' => 'Win', 'D' => 'Draw', 'L' => 'Defeat'][$res] ?? '';
$vics = club('club_short_name', 'Saltcoats Vics');
$scorers = trim((string) ($match['scorers_text'] ?? ''));
$backHref = url('club/results') . '?season=' . rawurlencode((string) $match['season']);

set_meta([
    'title' => $played
        ? $vics . ' ' . (int) $us . '–' . (int) $them . ' ' . $match['opponent']
        : $vics . ' v ' . $match['opponent'],
    'description' => trim((string) $match['competition']) . ' · ' . format_date($match['match_date'], 'j F Y'),
]);
?>
<section class="band band--ink archero">
  <div class="container">
    <a class="archero__crumb" href="<?= e($backHref) ?>">Results archive</a>
    <p class="archero__meta">
      <?= e(format_date($match['match_date'], 'l j F Y')) ?>
      <?php if ($match['competition']): ?> &middot; <?= e($match['competition']) ?><?php endif; ?>
      &middot; <?= $match['is_home'] ? 'Home' : 'Away' ?>
    </p>
    <div class="archero__score">
      <span class="archero__team"><?= e($vics) ?></span>
      <span class="archero__nums">
        <?php if ($played): ?><?= (int) $us ?><i>–</i><?= (int) $them ?><?php else: ?>v<?php endif; ?>
      </span>
      <span class="archero__team"><?= e($match['opponent']) ?></span>
    </div>
    <?php if ($resWord !== ''): ?><p class="archero__result chip chip--<?= e($res) ?>"><?= e($resWord) ?></p><?php endif; ?>
  </div>
</section>

<div class="container arcbody">
  <?php if ($match['venue']): ?>
    <p class="arcbody__venue"><?= e($match['venue']) ?></p>
  <?php endif; ?>

  <?php if ($scorers !== ''): ?>
    <div class="arcbody__scorers">
      <h2><?= e($vics) ?> scorers</h2>
      <p><?= nl2br(e($scorers)) ?></p>
    </div>
  <?php endif; ?>

  <?php if (!empty($match['report_html'])): ?>
    <div class="prose">
      <?php if ($match['report_by']): ?><p class="arcbody__by">Report by <?= e($match['report_by']) ?></p><?php endif; ?>
      <?= $match['report_html'] /* already sanitised at import */ ?>
    </div>
  <?php elseif ($scorers === ''): ?>
    <p class="prose">No match report was recorded for this game.</p>
  <?php endif; ?>

  <p class="pp-back"><a class="linkarrow" href="<?= e($backHref) ?>">Back to <?= e(pub_history_season_label((string) $match['season'])) ?></a></p>
</div>
