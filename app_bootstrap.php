<?php

declare(strict_types=1);

require_once __DIR__ . '/social_auth.php';
require_once __DIR__ . '/lib/functions.php';

/**
 * Escape output for HTML context.
 *
 * @param mixed $value
 */
function safe($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function app_format_uk_date(?string $value, string $fallback = 'Not set'): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return $fallback;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if ($date === false) {
        return $value;
    }

    return $date->format('d/m/Y');
}

/**
 * @return array{isAuthenticated: bool, authUsername: string, styleVersion: int}
 */
function app_bootstrap_state(): array
{
    $isAuthenticated = auth_is_authenticated();
    $styleCandidates = [
        @filemtime(__DIR__ . '/assets/css/style.css') ?: 0,
        @filemtime(__DIR__ . '/assets/css/social-publishing.css') ?: 0,
        @filemtime(__DIR__ . '/assets/css/player-sponsors-base.css') ?: 0,
    ];

    return [
        'isAuthenticated' => $isAuthenticated,
        'authUsername' => $isAuthenticated ? auth_get_username() : '',
        'styleVersion' => (int) max($styleCandidates) ?: time(),
    ];
}

function app_nav_link_class(string $currentPage, string $page): string
{
    return $currentPage === $page ? 'aria-current="page"' : '';
}

function app_render_login_modal(string $title, string $message): void
{
    ?>
    <div id="loginModal" class="auth-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="loginModalTitle">
        <div class="auth-modal-card p-4">
            <h1 id="loginModalTitle" class="h4 mb-2"><?= safe($title) ?></h1>
            <p class="text-muted mb-4"><?= safe($message) ?></p>
            <form id="loginForm" novalidate>
                <input type="hidden" id="loginCsrfToken" name="csrf_token" value="<?= safe(auth_csrf_token()) ?>">
                <div class="mb-3">
                    <label for="loginIdentifier" class="form-label">Username or email</label>
                    <input id="loginIdentifier" name="identifier" class="form-control" autocomplete="username" required>
                </div>
                <div class="mb-3">
                    <label for="loginPassword" class="form-label">Password</label>
                    <input id="loginPassword" name="password" type="password" class="form-control" autocomplete="current-password" required>
                </div>
                <div id="loginStatus" class="alert alert-danger d-none mb-3" role="status" aria-live="polite"></div>
                <button id="loginSubmitBtn" type="submit" class="btn btn-maroon w-100">Log in</button>
                <div class="auth-modal__footer mt-3">
                    <a class="auth-modal__link" href="forgot_password.php">Forgot password?</a>
                </div>
            </form>
        </div>
    </div>
    <?php
}

function app_render_primary_nav(string $currentPage, string $brandLabel = 'Club Hub'): void
{
    $primaryLinks = [
        ['key' => 'home', 'label' => 'Club overview', 'href' => '/index.php'],
        ['key' => 'matches', 'label' => 'Match day', 'href' => '/matches.php'],
        ['key' => 'players', 'label' => 'Players', 'href' => '/players.php'],
        ['key' => 'sponsors', 'label' => 'Sponsors', 'href' => '/sponsors.php'],
        ['key' => 'league_table', 'label' => 'League table', 'href' => '/league_table.php'],
    ];
    $moreLinks = [
        ['key' => 'templates', 'label' => 'Template packs', 'href' => '/template_packs.php'],
        ['key' => 'stripe_dashboard', 'label' => 'Stripe Dashboard', 'href' => '/stripe_dashboard.php'],
        ['key' => 'settings', 'label' => 'Settings', 'href' => '/settings.php'],
    ];
    if (auth_is_admin()) {
        $moreLinks[] = ['key' => 'admin_users', 'label' => 'People & Users', 'href' => '/club_people.php'];
    }

    ?>
    <a class="hub-skip-link" href="#hubMainContent">Skip to main content</a>
    <nav class="legacy-toolbar" aria-label="Primary navigation">
        <a class="legacy-toolbar__brand" href="/index.php" aria-label="<?= safe($brandLabel) ?> home">
            <img src="/Saltcoats Victoria FC -White_Transparent.png" alt="" width="38" height="38">
            <span><?= safe($brandLabel) ?></span>
        </a>
        <div class="legacy-toolbar__links">
            <?php foreach ($primaryLinks as $link): ?>
                <a class="legacy-toolbar__link<?= $currentPage === $link['key'] ? ' is-active' : '' ?>" href="<?= safe($link['href']) ?>"<?= $currentPage === $link['key'] ? ' aria-current="page"' : '' ?>><?= safe($link['label']) ?></a>
            <?php endforeach; ?>
            <details class="legacy-toolbar__more">
                <summary>More</summary>
                <div class="legacy-toolbar__menu">
                    <?php foreach ($moreLinks as $link): ?>
                        <a class="legacy-toolbar__menu-link<?= $currentPage === $link['key'] ? ' is-active' : '' ?>" href="<?= safe($link['href']) ?>"<?= $currentPage === $link['key'] ? ' aria-current="page"' : '' ?>><?= safe($link['label']) ?></a>
                    <?php endforeach; ?>
                    <button id="logoutBtn" class="legacy-toolbar__menu-link legacy-toolbar__logout" type="button" data-csrf-token="<?= safe(auth_csrf_token()) ?>">Log out</button>
                </div>
            </details>
        </div>
    </nav>
    <?php
}

function app_render_auth_scripts(bool $isAuthenticated): void
{
    ?>
    <script>
        <?php if (!$isAuthenticated): ?>
        (function() {
            var form = document.getElementById('loginForm');
            var usernameInput = document.getElementById('loginIdentifier');
            var passwordInput = document.getElementById('loginPassword');
            var csrfInput = document.getElementById('loginCsrfToken');
            var statusEl = document.getElementById('loginStatus');
            var submitBtn = document.getElementById('loginSubmitBtn');

            function setLoginStatus(message) {
                statusEl.textContent = message;
                statusEl.classList.remove('d-none');
            }

            if (usernameInput) {
                usernameInput.focus();
            }

            if (!form || !usernameInput || !passwordInput || !csrfInput || !statusEl || !submitBtn) {
                return;
            }

            form.addEventListener('submit', function(event) {
                event.preventDefault();
                statusEl.classList.add('d-none');
                submitBtn.disabled = true;
                submitBtn.textContent = 'Logging in…';

                fetch('auth_endpoint.php?action=login', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: 'identifier=' + encodeURIComponent(usernameInput.value)
                        + '&password=' + encodeURIComponent(passwordInput.value)
                        + '&csrf_token=' + encodeURIComponent(csrfInput.value)
                }).then(function(response) {
                    return response.json().then(function(json) {
                        return {
                            ok: response.ok,
                            json: json
                        };
                    }).catch(function() {
                        return {
                            ok: response.ok,
                            json: null
                        };
                    });
                }).then(function(result) {
                    if (!result.ok || !result.json || !result.json.ok) {
                        throw new Error((result.json && result.json.error) ? result.json.error : 'Login failed.');
                    }

                    window.location.reload();
                }).catch(function(error) {
                    if (error.message === 'Session validation failed. Please reload and try again.') {
                        window.location.reload();
                        return;
                    }

                    setLoginStatus(error.message);
                    passwordInput.value = '';
                    passwordInput.focus();
                }).finally(function() {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Log in';
                });
            });
        })();
        <?php endif; ?>

        <?php if ($isAuthenticated): ?>
        (function() {
            var moreMenu = document.querySelector('.legacy-toolbar__more');
            if (moreMenu) {
                moreMenu.addEventListener('click', function(event) {
                    if (event.target.closest('a, button')) {
                        moreMenu.removeAttribute('open');
                    }
                });
                document.addEventListener('keydown', function(event) {
                    if (event.key === 'Escape' && moreMenu.open) {
                        moreMenu.removeAttribute('open');
                        moreMenu.querySelector('summary').focus();
                    }
                });
                document.addEventListener('click', function(event) {
                    if (moreMenu.open && !moreMenu.contains(event.target)) {
                        moreMenu.removeAttribute('open');
                    }
                });
            }

            var logoutButton = document.getElementById('logoutBtn');
            if (!logoutButton) {
                return;
            }

            logoutButton.addEventListener('click', function() {
                var button = this;
                var originalText = button.textContent;

                button.disabled = true;
                button.textContent = 'Logging Out...';

                fetch('auth_endpoint.php?action=logout', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: 'csrf_token=' + encodeURIComponent(button.getAttribute('data-csrf-token') || '')
                }).then(function(response) {
                    if (!response.ok) {
                        throw new Error('Logout failed.');
                    }
                }).then(function() {
                    window.location.href = 'index.php';
                }).catch(function() {
                    button.disabled = false;
                    button.textContent = originalText;
                });
            });
        })();
        <?php endif; ?>
    </script>
    <?php
}
