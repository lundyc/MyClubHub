<?php
declare(strict_types=1);
?>
    </div>
</main>

<?php
$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
$showMobileNav = !in_array($currentScript, ['login.php', 'forgot_password.php', 'reset_password.php'], true);
$mobilePrimaryPages = [
    'index.php', 'matches.php', 'match.php', 'match_starting_11.php', 'match_events.php',
    'players.php', 'player_view.php', 'player_edit.php', 'player_add.php',
    'sponsors.php', 'sponsor.php', 'sponsorship_agreements.php', 'sponsorship_agreement.php',
];
$mobileMoreActive = !in_array($currentScript, $mobilePrimaryPages, true);
if ($showMobileNav):
?>
<nav class="hub-mobile-nav d-lg-none" aria-label="Mobile hub navigation">
    <a class="hub-mobile-nav__item <?= $currentScript === 'index.php' ? 'active' : '' ?>" href="/index.php" <?= $currentScript === 'index.php' ? 'aria-current="page"' : '' ?>>
        <i class="fa-solid fa-house" aria-hidden="true"></i>
        <span>Overview</span>
    </a>
    <a class="hub-mobile-nav__item <?= in_array($currentScript, ['matches.php', 'match.php', 'match_starting_11.php', 'match_events.php'], true) ? 'active' : '' ?>" href="/matches.php" <?= in_array($currentScript, ['matches.php', 'match.php', 'match_starting_11.php', 'match_events.php'], true) ? 'aria-current="page"' : '' ?>>
        <i class="fa-solid fa-futbol" aria-hidden="true"></i>
        <span>Match day</span>
    </a>
    <a class="hub-mobile-nav__item <?= in_array($currentScript, ['players.php', 'player_view.php', 'player_edit.php', 'player_add.php'], true) ? 'active' : '' ?>" href="/players.php" <?= in_array($currentScript, ['players.php', 'player_view.php', 'player_edit.php', 'player_add.php'], true) ? 'aria-current="page"' : '' ?>>
        <i class="fa-solid fa-users" aria-hidden="true"></i>
        <span>Players</span>
    </a>
    <a class="hub-mobile-nav__item <?= in_array($currentScript, ['sponsors.php', 'sponsor.php', 'sponsorship_agreements.php', 'sponsorship_agreement.php'], true) ? 'active' : '' ?>" href="/sponsors.php" <?= in_array($currentScript, ['sponsors.php', 'sponsor.php', 'sponsorship_agreements.php', 'sponsorship_agreement.php'], true) ? 'aria-current="page"' : '' ?>>
        <i class="fa-solid fa-handshake" aria-hidden="true"></i>
        <span>Sponsors</span>
    </a>
    <button class="hub-mobile-nav__item <?= $mobileMoreActive ? 'active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain" aria-controls="navbarMain" aria-expanded="false" aria-label="Open all Hub navigation">
        <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
        <span>More</span>
    </button>
</nav>

<div class="modal fade" id="hubConfirmDialog" tabindex="-1" aria-labelledby="hubConfirmDialogTitle" aria-describedby="hubConfirmDialogMessage" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content hub-confirm-dialog">
            <div class="modal-header">
                <div class="hub-confirm-dialog__heading">
                    <span class="hub-confirm-dialog__icon"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span>
                    <div><div class="small text-uppercase fw-bold text-muted">Please confirm</div><h2 class="modal-title h5 mb-0" id="hubConfirmDialogTitle">Confirm this action</h2></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel and close"></button>
            </div>
            <div class="modal-body"><p class="mb-0" id="hubConfirmDialogMessage">This action cannot be undone.</p></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="hubConfirmDialogAction">Continue</button>
            </div>
        </div>
    </div>
</div>

<div class="hub-toast-region" aria-live="polite" aria-atomic="true">
    <span class="visually-hidden" id="hubLiveStatus"></span>
</div>
<?php endif; ?>

<?php
$hubAnalyticsScript = __DIR__ . '/assets/js/hub_analytics.js';
$hubAnalyticsDisabledPages = ['login.php', 'forgot_password.php', 'reset_password.php', 'analytics_track.php'];
if (
    $showMobileNav
    && function_exists('hub_auth_is_authenticated')
    && hub_auth_is_authenticated()
    && !in_array($currentScript, $hubAnalyticsDisabledPages, true)
    && is_file($hubAnalyticsScript)
):
?>
<script>
    window.hubAnalyticsConfig = {
        endpoint: "/analytics_track.php"
    };
</script>
<script src="/assets/js/hub_analytics.js?v=<?= (int)(@filemtime($hubAnalyticsScript) ?: time()) ?>" defer></script>
<?php endif; ?>

<?php ob_end_flush(); ?>
</body>

</html>
