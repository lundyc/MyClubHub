<?php
/** Route: /tickets — match ticket shop (guest checkout). Reuses
 *  lib/match_tickets.php; presentation is the public-site design. The live
 *  /tickets keeps its guest/account/login options. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/season.php';
require_once HUB_ROOT . '/lib/match_tickets.php';

ensureMatchTicketSchema(db());

$currentSeason = getCurrentSeason(db());
$seasonId = (int) ($currentSeason['id'] ?? 0);
$fixtureId = (int) ($_GET['fixture_id'] ?? ($_POST['fixture_id'] ?? 0));
$fixtures = $seasonId > 0 ? getTicketedHomeFixtures(db(), $seasonId, true) : [];
$fixture = null;
foreach ($fixtures as $row) {
    if ((int) $row['id'] === $fixtureId) {
        $fixture = $row;
        break;
    }
}

$errors = [];
$cart = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$fixture) {
        $errors[] = 'Choose a home game.';
    } else {
        foreach ((array) ($_POST['qty'] ?? []) as $fixturePackageId => $qty) {
            $qty = max(0, min(20, (int) $qty));
            if ($qty > 0) {
                $cart[(int) $fixturePackageId] = $qty;
            }
        }
        if (!$cart) {
            $errors[] = 'Choose at least one ticket.';
        }
        $buyer = [
            'holder_id' => null,
            'name' => trim((string) ($_POST['buyer_name'] ?? '')),
            'email' => trim((string) ($_POST['buyer_email'] ?? '')),
            'phone' => trim((string) ($_POST['buyer_phone'] ?? '')),
        ];
        if ($buyer['name'] === '') {
            $errors[] = 'Enter your name.';
        }
        if (!filter_var($buyer['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }

        if (!$errors) {
            try {
                db()->beginTransaction();
                $orderId = createMatchTicketOrder(db(), $buyer, (int) $fixture['id'], $cart, 'online', 'guest');
                $order = getMatchTicketOrder(db(), $orderId);
                db()->commit();

                $viewUrl = current_url_origin() . url('tickets/order/' . rawurlencode((string) $order['access_token']));

                if ($order && (float) $order['total_amount'] > 0 && stripe_is_configured()) {
                    $checkout = match_ticket_stripe_create_checkout_session(
                        db(),
                        $orderId,
                        $viewUrl . '?paid=1',
                        current_url_origin() . url('tickets') . '?fixture_id=' . (int) $fixture['id'] . '&cancelled=1'
                    );
                    redirect($checkout['url']);
                }
                redirect($viewUrl . '?saved=1');
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                $errors[] = $e->getMessage();
            }
        }
    }
}

$packages = $fixture ? getFixtureTicketPackages(db(), (int) $fixture['id'], true) : [];
set_meta(['title' => 'Match tickets', 'description' => 'Buy tickets for ' . club('club_name') . ' home games.']);
?>
<?php partial('page_hero', [
    'eyebrow' => 'Match day',
    'title'   => 'Match tickets',
    'sub'     => 'Choose a home game, pick your tickets, and show your digital QR code at the gate.',
]); ?>

<div class="page">
  <div class="container">
    <?php if (isset($_GET['cancelled'])): ?><div class="notice notice--warn"><p>Payment was cancelled. No money was taken.</p></div><?php endif; ?>
    <?php foreach ($errors as $er): ?><div class="notice notice--err"><?= e($er) ?></div><?php endforeach; ?>

    <?php if (!$fixture): ?>
      <h2>Upcoming home games</h2>
      <?php if (!$fixtures): ?>
        <div class="emptystate"><p>There are no home games available for online tickets yet.</p></div>
      <?php else: ?>
        <div class="fxlist">
          <?php foreach ($fixtures as $game): ?>
            <a class="fxrow" href="<?= e(url('tickets') . '?fixture_id=' . (int) $game['id']) ?>" style="grid-template-columns:1fr auto">
              <span class="fxrow__team fxrow__team--home" style="justify-content:flex-start">
                <span><?= e(club('club_short_name')) ?> v <?= e((string) $game['opponent']) ?></span>
              </span>
              <span class="btn btn--sm">Buy tickets</span>
              <span class="fxrow__date" style="grid-column:1/-1">
                <span><?= e(format_date($game['match_date'], 'l j F Y')) ?><?= !empty($game['kickoff_time']) ? ' · ' . e(format_time($game['kickoff_time'])) : '' ?></span>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <p><a class="linkarrow" href="<?= e(url('tickets')) ?>">← All home games</a></p>
      <form method="post" class="basket-layout">
        <?= csrf_field() ?>
        <input type="hidden" name="fixture_id" value="<?= (int) $fixture['id'] ?>">

        <div>
          <h2><?= e(club('club_short_name')) ?> v <?= e((string) $fixture['opponent']) ?></h2>
          <p class="pdp__note" style="margin-top:0"><?= e(format_date($fixture['match_date'], 'l j F Y')) ?><?= !empty($fixture['kickoff_time']) ? ' · ' . e(format_time($fixture['kickoff_time'])) : '' ?></p>

          <h3>Tickets</h3>
          <?php if (!$packages): ?><p class="pdp__note">Tickets are not yet available online for this game.</p><?php endif; ?>
          <div class="blines">
            <?php foreach ($packages as $package): ?>
              <?php $remaining = (int) $package['allocation'] > 0 ? max(0, (int) $package['allocation'] - (int) $package['sold_qty']) : 999; ?>
              <div class="bline" style="grid-template-columns:1fr auto">
                <div class="bline__info">
                  <span class="bline__name"><?= e((string) $package['name']) ?></span>
                  <?php if (($package['description'] ?? '') !== ''): ?><div class="bline__opts"><?= e((string) $package['description']) ?></div><?php endif; ?>
                  <div class="bline__opts"><?= e(gbp((float) $package['price'])) ?></div>
                </div>
                <input class="ticket-qty" type="number" min="0" max="<?= min(20, $remaining) ?>"
                       name="qty[<?= (int) $package['id'] ?>]" value="<?= (int) ($cart[(int) $package['id']] ?? 0) ?>"
                       <?= $remaining <= 0 ? 'disabled' : '' ?> aria-label="Quantity for <?= e((string) $package['name']) ?>">
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <aside class="osummary">
          <h2>Your details</h2>
          <label class="field"><span>Name</span><input name="buyer_name" value="<?= e((string) ($_POST['buyer_name'] ?? '')) ?>" required></label>
          <label class="field"><span>Email</span><input type="email" name="buyer_email" value="<?= e((string) ($_POST['buyer_email'] ?? '')) ?>" required></label>
          <label class="field"><span>Phone</span><input name="buyer_phone" value="<?= e((string) ($_POST['buyer_phone'] ?? '')) ?>"></label>
          <p class="pdp__note">Your ticket link and QR codes are emailed to you. Paid securely by card via Stripe.</p>
          <button type="submit" class="btn btn--lg" style="width:100%">Checkout</button>
        </aside>
      </form>
    <?php endif; ?>
  </div>
</div>
