<?php

declare(strict_types=1);

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/sponsorship_catalog.php';
require_once __DIR__ . '/match_sponsorship.php';

const STRIPE_API_BASE = 'https://api.stripe.com/v1';
const STRIPE_DEFAULT_LINK_EXPIRY_HOURS = 24; // Stripe Checkout Sessions cannot expire more than 24h after creation.
const STRIPE_WEBHOOK_TOLERANCE_SECONDS = 300;

/**
 * Merge parsed .env values with any non-empty runtime environment overrides,
 * mirroring match_google_calendar_env() in lib/google_calendar.php.
 *
 * @return array<string, string>
 */
function stripe_env(): array
{
    $fileEnv = app_parse_env_file(__DIR__ . '/../.env');
    $runtimeEnv = [
        'STRIPE_MODE' => getenv('STRIPE_MODE') !== false ? (string) getenv('STRIPE_MODE') : '',
        'STRIPE_SECRET_KEY' => getenv('STRIPE_SECRET_KEY') !== false ? (string) getenv('STRIPE_SECRET_KEY') : '',
        'STRIPE_WEBHOOK_SECRET' => getenv('STRIPE_WEBHOOK_SECRET') !== false ? (string) getenv('STRIPE_WEBHOOK_SECRET') : '',
        'STRIPE_DEFAULT_CURRENCY' => getenv('STRIPE_DEFAULT_CURRENCY') !== false ? (string) getenv('STRIPE_DEFAULT_CURRENCY') : '',
        'STRIPE_LINK_EXPIRY_HOURS' => getenv('STRIPE_LINK_EXPIRY_HOURS') !== false ? (string) getenv('STRIPE_LINK_EXPIRY_HOURS') : '',
    ];

    return array_merge($fileEnv, array_filter($runtimeEnv, static fn(string $value): bool => $value !== ''));
}

function stripe_mode(): string
{
    $env = stripe_env();
    $mode = strtolower(trim((string) ($env['STRIPE_MODE'] ?? 'test')));
    return $mode === 'live' ? 'live' : 'test';
}

function stripe_secret_key(): string
{
    return trim((string) (stripe_env()['STRIPE_SECRET_KEY'] ?? ''));
}

function stripe_webhook_secret(): string
{
    return trim((string) (stripe_env()['STRIPE_WEBHOOK_SECRET'] ?? ''));
}

function stripe_default_currency(): string
{
    $currency = strtolower(trim((string) (stripe_env()['STRIPE_DEFAULT_CURRENCY'] ?? 'gbp')));
    return $currency !== '' ? $currency : 'gbp';
}

function stripe_link_expiry_hours(): int
{
    $hours = (int) (stripe_env()['STRIPE_LINK_EXPIRY_HOURS'] ?? STRIPE_DEFAULT_LINK_EXPIRY_HOURS);
    if ($hours < 1) {
        return STRIPE_DEFAULT_LINK_EXPIRY_HOURS;
    }
    return min($hours, STRIPE_DEFAULT_LINK_EXPIRY_HOURS);
}

function stripe_is_configured(): bool
{
    return stripe_secret_key() !== '';
}

/**
 * Idempotent schema guard, following the same CREATE TABLE IF NOT EXISTS
 * pattern as ensureSponsorshipCatalogSchema() / ensureMatchSchema().
 */
function ensureStripeSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS stripe_payment_links (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        agreement_id INT UNSIGNED NOT NULL,
        stripe_checkout_session_id VARCHAR(190) NOT NULL,
        stripe_payment_intent_id VARCHAR(190) DEFAULT NULL,
        amount DECIMAL(10,2) NOT NULL,
        currency VARCHAR(10) NOT NULL DEFAULT 'gbp',
        url VARCHAR(500) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        sent_to_email VARCHAR(190) DEFAULT NULL,
        sent_at DATETIME DEFAULT NULL,
        created_by BIGINT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME DEFAULT NULL,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_stripe_payment_links_session (stripe_checkout_session_id),
        KEY idx_stripe_payment_links_agreement (agreement_id, status),
        CONSTRAINT fk_stripe_payment_links_agreement FOREIGN KEY (agreement_id) REFERENCES sponsorship_agreements (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS stripe_transactions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        agreement_id INT UNSIGNED NOT NULL,
        payment_link_id INT UNSIGNED DEFAULT NULL,
        stripe_payment_intent_id VARCHAR(190) NOT NULL,
        stripe_checkout_session_id VARCHAR(190) DEFAULT NULL,
        amount DECIMAL(10,2) NOT NULL,
        currency VARCHAR(10) NOT NULL DEFAULT 'gbp',
        status VARCHAR(30) NOT NULL DEFAULT 'succeeded',
        refunded_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        raw_payload MEDIUMTEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_stripe_transactions_intent (stripe_payment_intent_id),
        KEY idx_stripe_transactions_agreement (agreement_id, status),
        KEY idx_stripe_transactions_link (payment_link_id),
        CONSTRAINT fk_stripe_transactions_agreement FOREIGN KEY (agreement_id) REFERENCES sponsorship_agreements (id) ON DELETE CASCADE,
        CONSTRAINT fk_stripe_transactions_link FOREIGN KEY (payment_link_id) REFERENCES stripe_payment_links (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Sponsorship bundles let one Stripe Checkout Session cover several agreements at
    // once (one stripe_payment_links / stripe_transactions row per agreement, all
    // sharing one session/payment-intent — see stripe_create_checkout_session_for_bundle()
    // and stripe_handle_checkout_session_completed()), so the old 1:1 unique keys on
    // stripe_checkout_session_id / stripe_payment_intent_id have to go. payment_link_id
    // stays genuinely 1:1 with "one agreement's slice of one session", so that's where
    // the real uniqueness guarantee (and the webhook's per-item idempotency check) now
    // lives instead.
    $linkIndexes = [];
    foreach ($pdo->query('SHOW INDEX FROM stripe_payment_links') as $row) {
        $linkIndexes[(string) $row['Key_name']] = true;
    }
    if (isset($linkIndexes['uq_stripe_payment_links_session'])) {
        $pdo->exec('ALTER TABLE stripe_payment_links DROP INDEX uq_stripe_payment_links_session');
        unset($linkIndexes['uq_stripe_payment_links_session']);
    }
    if (!isset($linkIndexes['idx_stripe_payment_links_session'])) {
        $pdo->exec('ALTER TABLE stripe_payment_links ADD KEY idx_stripe_payment_links_session (stripe_checkout_session_id)');
    }

    $txnIndexes = [];
    foreach ($pdo->query('SHOW INDEX FROM stripe_transactions') as $row) {
        $txnIndexes[(string) $row['Key_name']] = true;
    }
    if (isset($txnIndexes['uq_stripe_transactions_intent'])) {
        $pdo->exec('ALTER TABLE stripe_transactions DROP INDEX uq_stripe_transactions_intent');
        unset($txnIndexes['uq_stripe_transactions_intent']);
    }
    if (!isset($txnIndexes['idx_stripe_transactions_intent'])) {
        $pdo->exec('ALTER TABLE stripe_transactions ADD KEY idx_stripe_transactions_intent (stripe_payment_intent_id)');
    }
    if (!isset($txnIndexes['uq_stripe_transactions_link'])) {
        $pdo->exec('ALTER TABLE stripe_transactions ADD UNIQUE KEY uq_stripe_transactions_link (payment_link_id)');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS stripe_webhook_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        stripe_event_id VARCHAR(190) NOT NULL,
        type VARCHAR(100) NOT NULL,
        processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_stripe_webhook_events_event (stripe_event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS stripe_refunds (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        transaction_id INT UNSIGNED NOT NULL,
        stripe_refund_id VARCHAR(190) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        reason VARCHAR(255) DEFAULT NULL,
        initiated_by BIGINT UNSIGNED DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_stripe_refunds_refund (stripe_refund_id),
        KEY idx_stripe_refunds_transaction (transaction_id),
        CONSTRAINT fk_stripe_refunds_transaction FOREIGN KEY (transaction_id) REFERENCES stripe_transactions (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

class StripeApiException extends RuntimeException
{
}

/**
 * Raw cURL client for the Stripe REST API, mirroring the cURL usage in
 * lib/google_calendar.php and lib/facebook_publisher.php rather than pulling
 * in the Composer-based Stripe SDK.
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function stripe_request(string $method, string $path, array $params = []): array
{
    $secretKey = stripe_secret_key();
    if ($secretKey === '') {
        throw new StripeApiException('Stripe is not configured. Add a secret key in Settings > Payments.');
    }

    $method = strtoupper($method);
    $url = STRIPE_API_BASE . '/' . ltrim($path, '/');
    $body = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    if ($method === 'GET' && $body !== '') {
        $url .= '?' . $body;
        $body = '';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $secretKey,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $errorNumber = curl_errno($ch);
    $errorMessage = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errorNumber !== 0 || $response === false) {
        throw new StripeApiException('Could not reach Stripe: ' . $errorMessage);
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        throw new StripeApiException('Stripe returned an unreadable response.');
    }

    if ($statusCode >= 400) {
        $message = (string) ($decoded['error']['message'] ?? 'Stripe request failed.');
        throw new StripeApiException($message);
    }

    return $decoded;
}

function stripe_amount_to_minor_units(float $amount, string $currency): int
{
    // GBP/USD/EUR-style two-decimal currencies only; the club only trades in GBP today.
    return (int) round($amount * 100);
}

function stripe_minor_units_to_amount(int $minorUnits, string $currency): float
{
    return round($minorUnits / 100, 2);
}

/**
 * The outstanding balance for an agreement, matching the epsilon tolerance
 * used by reportPaymentState() in reports.php.
 */
function stripe_agreement_outstanding_amount(array $agreement): float
{
    $due = (float) ($agreement['agreed_amount'] ?? 0);
    $paid = (float) ($agreement['total_paid'] ?? 0);
    $outstanding = round($due - $paid, 2);
    return $outstanding > 0.0001 ? $outstanding : 0.0;
}

function stripe_agreement_display_name(array $agreement): string
{
    $sponsor = trim((string) ($agreement['sponsor_name'] ?? 'Sponsor'));
    $package = trim((string) ($agreement['package_name'] ?? 'Sponsorship'));
    return $sponsor . ' — ' . $package;
}

/**
 * Create a Stripe Checkout Session for an agreement's outstanding balance
 * and persist it in stripe_payment_links. The amount is always computed
 * server-side from the agreement record — never trust a client-submitted
 * amount for this.
 *
 * @param array<string, mixed> $agreement
 * @return array<string, mixed> the stripe_payment_links row
 */
function stripe_create_checkout_session_for_agreement(PDO $pdo, array $agreement, string $successUrl, string $cancelUrl, ?int $createdByUserId = null): array
{
    ensureStripeSchema($pdo);

    if ((int) ($agreement['is_complimentary'] ?? 0) === 1) {
        throw new RuntimeException('Complimentary agreements cannot be charged.');
    }

    $amount = stripe_agreement_outstanding_amount($agreement);
    if ($amount <= 0) {
        throw new RuntimeException('This agreement has no outstanding balance.');
    }

    $currency = stripe_default_currency();
    $expiresAt = time() + (stripe_link_expiry_hours() * 3600);

    $params = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'client_reference_id' => (string) $agreement['id'],
        'expires_at' => $expiresAt,
        'line_items' => [
            [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => stripe_amount_to_minor_units($amount, $currency),
                    'product_data' => [
                        'name' => stripe_agreement_display_name($agreement),
                        'description' => 'Sponsorship payment — Saltcoats Victoria FC',
                    ],
                ],
            ],
        ],
        'metadata' => [
            'agreement_id' => (string) $agreement['id'],
            'sponsor_id' => (string) ($agreement['sponsor_id'] ?? ''),
        ],
        'payment_intent_data' => [
            'metadata' => [
                'agreement_id' => (string) $agreement['id'],
            ],
        ],
    ];

    $contactEmail = trim((string) ($agreement['sponsor_contact_email'] ?? ''));
    if ($contactEmail !== '' && filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $params['customer_email'] = $contactEmail;
    }

    $session = stripe_request('POST', '/checkout/sessions', $params);

    $stmt = $pdo->prepare('INSERT INTO stripe_payment_links
        (agreement_id, stripe_checkout_session_id, stripe_payment_intent_id, amount, currency, url, status, created_by, created_at, expires_at)
        VALUES (:agreement_id, :session_id, :payment_intent_id, :amount, :currency, :url, :status, :created_by, NOW(), :expires_at)');
    $stmt->execute([
        ':agreement_id' => (int) $agreement['id'],
        ':session_id' => (string) $session['id'],
        ':payment_intent_id' => is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null,
        ':amount' => $amount,
        ':currency' => $currency,
        ':url' => (string) $session['url'],
        ':status' => 'open',
        ':created_by' => $createdByUserId,
        ':expires_at' => date('Y-m-d H:i:s', $expiresAt),
    ]);

    $linkId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM stripe_payment_links WHERE id = :id');
    $stmt->execute([':id' => $linkId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Create ONE Stripe Checkout Session covering several agreements at once (a
 * "sponsorship bundle" — a board + several players + several MOTMs, etc., all for one
 * sponsor) and persist one stripe_payment_links row per chargeable agreement, all
 * sharing that session's id/url/status/expires_at but each keeping its own split
 * amount. Each member agreement's own detail page needs zero changes — it just reads
 * its own stripe_payment_links row exactly like it always has for a standalone
 * agreement. See stripe_handle_checkout_session_completed() for how one confirmed
 * payment gets split back across every member.
 *
 * Complimentary agreements and agreements with no outstanding balance are silently
 * skipped rather than erroring, so generating a bundle link after some members are
 * already paid just charges for what's left.
 *
 * @param list<array<string, mixed>> $agreements the bundle's member agreement rows,
 *   e.g. getSponsorshipAgreements(['bundle_id' => $bundleId])
 * @return array{session_id: string, session_url: string, total_amount: float, agreement_ids: list<int>}
 */
function stripe_create_checkout_session_for_bundle(PDO $pdo, array $agreements, string $successUrl, string $cancelUrl, ?int $createdByUserId = null): array
{
    ensureStripeSchema($pdo);

    $chargeable = [];
    foreach ($agreements as $agreement) {
        if ((int) ($agreement['is_complimentary'] ?? 0) === 1) {
            continue;
        }
        $amount = stripe_agreement_outstanding_amount($agreement);
        if ($amount <= 0) {
            continue;
        }
        $chargeable[] = ['agreement' => $agreement, 'amount' => $amount];
    }

    if (!$chargeable) {
        throw new RuntimeException('This bundle has no outstanding balance.');
    }

    $currency = stripe_default_currency();
    $expiresAt = time() + (stripe_link_expiry_hours() * 3600);

    $lineItems = [];
    $agreementIds = [];
    $totalAmount = 0.0;
    $contactEmail = '';
    foreach ($chargeable as $item) {
        $agreement = $item['agreement'];
        $amount = $item['amount'];
        $lineItems[] = [
            'quantity' => 1,
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => stripe_amount_to_minor_units($amount, $currency),
                'product_data' => [
                    'name' => stripe_agreement_display_name($agreement),
                    'description' => 'Sponsorship payment — Saltcoats Victoria FC',
                ],
            ],
        ];
        $agreementIds[] = (int) $agreement['id'];
        $totalAmount += $amount;
        if ($contactEmail === '') {
            $candidate = trim((string) ($agreement['sponsor_contact_email'] ?? ''));
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $contactEmail = $candidate;
            }
        }
    }

    $params = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'expires_at' => $expiresAt,
        'line_items' => $lineItems,
        'metadata' => [
            // Traceability only (visible in the Stripe dashboard) — the webhook looks
            // rows up by session id, not by parsing this.
            'agreement_ids' => implode(',', $agreementIds),
            'sponsor_id' => (string) ($chargeable[0]['agreement']['sponsor_id'] ?? ''),
        ],
    ];
    if ($contactEmail !== '') {
        $params['customer_email'] = $contactEmail;
    }

    $session = stripe_request('POST', '/checkout/sessions', $params);
    $sessionId = (string) $session['id'];
    $paymentIntentId = is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null;
    $url = (string) $session['url'];

    $insert = $pdo->prepare('INSERT INTO stripe_payment_links
        (agreement_id, stripe_checkout_session_id, stripe_payment_intent_id, amount, currency, url, status, created_by, created_at, expires_at)
        VALUES (:agreement_id, :session_id, :payment_intent_id, :amount, :currency, :url, :status, :created_by, NOW(), :expires_at)');
    foreach ($chargeable as $item) {
        $insert->execute([
            ':agreement_id' => (int) $item['agreement']['id'],
            ':session_id' => $sessionId,
            ':payment_intent_id' => $paymentIntentId,
            ':amount' => $item['amount'],
            ':currency' => $currency,
            ':url' => $url,
            ':status' => 'open',
            ':created_by' => $createdByUserId,
            ':expires_at' => date('Y-m-d H:i:s', $expiresAt),
        ]);
    }

    return [
        'session_id' => $sessionId,
        'session_url' => $url,
        'total_amount' => round($totalAmount, 2),
        'agreement_ids' => $agreementIds,
    ];
}

/**
 * Expires an open Checkout Session on Stripe's side (Stripe has no "delete" for these —
 * expiring is the correct way to invalidate a link so it can no longer be paid) and marks
 * the local row expired to match, so a fresh link can be generated in its place.
 *
 * @param array<string, mixed> $link a stripe_payment_links row
 */
function stripe_expire_payment_link(PDO $pdo, array $link): void
{
    if ((string) $link['status'] !== 'open') {
        return;
    }

    try {
        stripe_request('POST', '/checkout/sessions/' . rawurlencode((string) $link['stripe_checkout_session_id']) . '/expire');
    } catch (StripeApiException $e) {
        // Already expired/completed on Stripe's side (e.g. the sponsor paid or it lapsed
        // moments ago) — fall through and reconcile our local status regardless.
    }

    // Stripe expires the whole Checkout Session, not one line item — a bundle's combined
    // link is several local rows sharing that one session, so every sibling (not just the
    // row that was clicked) needs to flip to expired, or the others would be stuck showing
    // "open" locally for a link that no longer actually works.
    $pdo->prepare("UPDATE stripe_payment_links SET status = 'expired' WHERE stripe_checkout_session_id = :session_id AND status = 'open'")
        ->execute([':session_id' => (string) $link['stripe_checkout_session_id']]);
}

/**
 * Human-readable "what is this for" lines shared by the copyable message and the email —
 * e.g. which fixture, which player, which season — built from whatever the package scope
 * actually applies to.
 *
 * @param array<string, mixed> $agreement
 * @return list<array{label: string, value: string}>
 */
function stripe_agreement_context_lines(array $agreement): array
{
    $lines = [];
    $lines[] = ['label' => 'Sponsor', 'value' => (string) ($agreement['sponsor_name'] ?? '')];
    $lines[] = ['label' => 'Package', 'value' => (string) ($agreement['package_name'] ?? '')];

    $scope = (string) ($agreement['package_scope'] ?? '');
    if ($scope === 'match' && !empty($agreement['fixture_opponent'])) {
        $matchLabel = 'vs ' . (string) $agreement['fixture_opponent'];
        if (!empty($agreement['fixture_date'])) {
            $matchLabel .= ' (' . date('d/m/Y', strtotime((string) $agreement['fixture_date'])) . ')';
        }
        $lines[] = ['label' => 'Match', 'value' => $matchLabel];
    } elseif ($scope === 'player' && !empty($agreement['player_name'])) {
        $lines[] = ['label' => 'Player', 'value' => (string) $agreement['player_name']];
    } elseif ($scope === 'team' && !empty($agreement['team_name'])) {
        $lines[] = ['label' => 'Team', 'value' => (string) $agreement['team_name']];
    }

    if (!empty($agreement['season_name'])) {
        $lines[] = ['label' => 'Season', 'value' => (string) $agreement['season_name']];
    }

    return $lines;
}

/**
 * Plain-text payment message — used both as the "copy message" button's clipboard content
 * and as the plain-text part of the email, so the two always say exactly the same thing.
 *
 * @param array<string, mixed> $agreement
 * @param array<string, mixed> $link
 */
function stripe_build_payment_message(string $sponsorName, array $agreement, array $link): string
{
    $amount = gbp((float) $link['amount']);
    $expires = date('d/m/Y H:i', strtotime((string) $link['expires_at']));

    $out = [];
    $out[] = 'Saltcoats Victoria FC — Sponsorship Payment';
    $out[] = '';
    $out[] = 'Hi ' . ($sponsorName !== '' ? $sponsorName : 'there') . ',';
    $out[] = '';
    $out[] = 'Thanks for your support! Here are the details of your sponsorship:';
    $out[] = '';
    foreach (stripe_agreement_context_lines($agreement) as $line) {
        $out[] = $line['label'] . ': ' . $line['value'];
    }
    $out[] = 'Amount due: ' . $amount;
    $out[] = '';
    $out[] = 'Pay securely here: ' . (string) $link['url'];
    $out[] = '';
    $out[] = 'This link expires on ' . $expires . '.';
    $out[] = '';
    $out[] = 'Thanks again for your support,';
    $out[] = 'Saltcoats Victoria FC';

    return implode("\n", $out);
}

/**
 * HTML email body with a proper "Pay now" button instead of a bare link, plus the same
 * sponsorship context lines as the plain-text message. Inline-styled throughout, since email
 * clients don't reliably support external/embedded stylesheets.
 *
 * @param array<string, mixed> $agreement
 * @param array<string, mixed> $link
 */
function stripe_build_payment_email_html(string $sponsorName, array $agreement, array $link): string
{
    $amount = gbp((float) $link['amount']);
    $expires = date('d/m/Y H:i', strtotime((string) $link['expires_at']));
    $url = (string) $link['url'];
    $greetingName = $sponsorName !== '' ? h($sponsorName) : 'there';

    $rows = '';
    foreach (stripe_agreement_context_lines($agreement) as $line) {
        $rows .= '<tr>'
            . '<td style="padding:6px 16px 6px 0;color:#6a2036;font-weight:600;white-space:nowrap;font-size:14px;">' . h($line['label']) . '</td>'
            . '<td style="padding:6px 0;color:#1f1a1d;font-size:14px;">' . h($line['value']) . '</td>'
            . '</tr>';
    }

    return '<!doctype html><html><body style="margin:0;padding:0;background:#f6ecde;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="max-width:520px;margin:0 auto;padding:24px 16px;">'
        . '<div style="background:#ffffff;border-radius:12px;padding:32px;">'
        . '<h1 style="margin:0 0 16px;font-size:20px;color:#4b0818;">Saltcoats Victoria FC</h1>'
        . '<p style="margin:0 0 16px;color:#1f1a1d;font-size:15px;">Hi ' . $greetingName . ',</p>'
        . '<p style="margin:0 0 20px;color:#1f1a1d;font-size:15px;">Thanks for your support! Here are the details of your sponsorship:</p>'
        . '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">' . $rows . '</table>'
        . '<p style="margin:0 0 8px;color:#1f1a1d;font-weight:700;font-size:18px;">Amount due: ' . h($amount) . '</p>'
        . '<div style="text-align:center;margin:28px 0;">'
        . '<a href="' . h($url) . '" style="background:#6a2036;color:#ffffff;text-decoration:none;font-weight:700;padding:14px 32px;border-radius:8px;display:inline-block;font-size:16px;">Pay ' . h($amount) . ' now</a>'
        . '</div>'
        . '<p style="margin:0;color:#6b6b6b;font-size:13px;">This link expires on ' . h($expires) . '. If the button doesn\'t work, copy and paste this link into your browser:<br>'
        . '<a href="' . h($url) . '" style="color:#6a2036;word-break:break-all;">' . h($url) . '</a></p>'
        . '</div>'
        . '<p style="text-align:center;color:#9a8f93;font-size:12px;margin-top:16px;">Saltcoats Victoria FC</p>'
        . '</div>'
        . '</body></html>';
}

/**
 * Email a payment link to a sponsor, reusing the mail() approach from
 * hub_users_send_password_reset_email() in hub/users_lib.php (no
 * PHPMailer/SMTP library is used anywhere in this project). Sent as
 * multipart/alternative so both a plain-text fallback and the styled HTML
 * button version are included.
 *
 * @param array<string, mixed> $agreement
 * @param array<string, mixed> $link
 */
function stripe_send_payment_link_email(string $toEmail, string $sponsorName, array $agreement, array $link): bool
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $amount = gbp((float) $link['amount']);
    $subject = 'Sponsorship payment request — ' . $amount;
    $fromAddress = 'no-reply@' . preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'lundy.me.uk'))));

    $textBody = stripe_build_payment_message($sponsorName, $agreement, $link);
    $htmlBody = stripe_build_payment_email_html($sponsorName, $agreement, $link);

    $boundary = 'stripe_' . bin2hex(random_bytes(12));
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'From: Saltcoats Victoria FC <' . $fromAddress . '>',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ]);

    $body = "--{$boundary}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $textBody . "\r\n\r\n"
        . "--{$boundary}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $htmlBody . "\r\n\r\n"
        . "--{$boundary}--";

    $sent = @mail($toEmail, $subject, $body, $headers, '-f' . $fromAddress);
    return (bool) $sent;
}

/**
 * Dispatches a Stripe payment into whichever underlying payment table the
 * agreement actually rolls up through (see getSponsorshipAgreements() in
 * lib/sponsorship_catalog.php), so total_paid/status stay correct everywhere
 * without changing that rollup query.
 */
function stripe_record_agreement_payment(PDO $pdo, array $agreement, float $amount, string $note): void
{
    $legacySource = (string) ($agreement['legacy_source'] ?? '');
    $legacyId = (int) ($agreement['legacy_id'] ?? 0);

    if ($legacySource === 'match' && $legacyId > 0) {
        try {
            addMatchPayment($pdo, $legacyId, $amount, 'Stripe', $note);
        } catch (Throwable $e) {
            error_log('[stripe] Payment succeeded in Stripe but could not be recorded against match_sponsorship ' . $legacyId . ': ' . $e->getMessage());
        }
        return;
    }

    if ($legacySource === 'player' && $legacyId > 0) {
        $seasonStmt = $pdo->prepare('SELECT season_id FROM sponsorships WHERE id = :id');
        $seasonStmt->execute([':id' => $legacyId]);
        $seasonId = $seasonStmt->fetchColumn();

        $stmt = $pdo->prepare('INSERT INTO sponsorship_payments (sponsorship_id, amount, paid_at, method, note, season_id)
            VALUES (:sponsorship_id, :amount, NOW(), :method, :note, :season_id)');
        $stmt->execute([
            ':sponsorship_id' => $legacyId,
            ':amount' => $amount,
            ':method' => 'Stripe',
            ':note' => $note,
            ':season_id' => $seasonId !== false ? $seasonId : null,
        ]);
        recomputePaidFlag($pdo, $legacyId);
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO sponsorship_agreement_payments (agreement_id, amount, paid_at, method, note)
        VALUES (:agreement_id, :amount, CURDATE(), :method, :note)');
    $stmt->execute([
        ':agreement_id' => (int) $agreement['id'],
        ':amount' => $amount,
        ':method' => 'Stripe',
        ':note' => $note,
    ]);
}

/**
 * Mirror image of stripe_record_agreement_payment() for refunds: writes a
 * negative adjustment row into whichever table the original payment used,
 * preserving history instead of deleting the original payment.
 */
function stripe_reverse_agreement_payment(PDO $pdo, array $agreement, float $amount, string $note): void
{
    $legacySource = (string) ($agreement['legacy_source'] ?? '');
    $legacyId = (int) ($agreement['legacy_id'] ?? 0);

    if ($legacySource === 'match' && $legacyId > 0) {
        $row = getMatchSponsorshipById($pdo, $legacyId);
        $seasonId = $row['season_id'] ?? null;
        $stmt = $pdo->prepare('INSERT INTO match_sponsorship_payments (match_sponsorship_id, season_id, amount, paid_at, method, note)
            VALUES (:match_sponsorship_id, :season_id, :amount, NOW(), :method, :note)');
        $stmt->execute([
            ':match_sponsorship_id' => $legacyId,
            ':season_id' => $seasonId,
            ':amount' => -$amount,
            ':method' => 'Stripe refund',
            ':note' => $note,
        ]);
        recomputeMatchPaidFlag($pdo, $legacyId);
        return;
    }

    if ($legacySource === 'player' && $legacyId > 0) {
        $seasonStmt = $pdo->prepare('SELECT season_id FROM sponsorships WHERE id = :id');
        $seasonStmt->execute([':id' => $legacyId]);
        $seasonId = $seasonStmt->fetchColumn();

        $stmt = $pdo->prepare('INSERT INTO sponsorship_payments (sponsorship_id, amount, paid_at, method, note, season_id)
            VALUES (:sponsorship_id, :amount, NOW(), :method, :note, :season_id)');
        $stmt->execute([
            ':sponsorship_id' => $legacyId,
            ':amount' => -$amount,
            ':method' => 'Stripe refund',
            ':note' => $note,
            ':season_id' => $seasonId !== false ? $seasonId : null,
        ]);
        recomputePaidFlag($pdo, $legacyId);
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO sponsorship_agreement_payments (agreement_id, amount, paid_at, method, note)
        VALUES (:agreement_id, :amount, CURDATE(), :method, :note)');
    $stmt->execute([
        ':agreement_id' => (int) $agreement['id'],
        ':amount' => -$amount,
        ':method' => 'Stripe refund',
        ':note' => $note,
    ]);
}

/**
 * Verify Stripe's webhook signature by hand (documented HMAC-SHA256 scheme),
 * avoiding a dependency on the Stripe SDK's webhook helper.
 */
function stripe_verify_webhook_signature(string $payload, string $sigHeader, string $secret): bool
{
    if ($secret === '' || $sigHeader === '') {
        return false;
    }

    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $sigHeader) as $part) {
        $pair = explode('=', $part, 2);
        if (count($pair) !== 2) {
            continue;
        }
        [$key, $value] = $pair;
        if ($key === 't') {
            $timestamp = $value;
        } elseif ($key === 'v1') {
            $signatures[] = $value;
        }
    }

    if ($timestamp === null || $signatures === []) {
        return false;
    }

    if (abs(time() - (int) $timestamp) > STRIPE_WEBHOOK_TOLERANCE_SECONDS) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $event decoded Stripe event payload
 */
function stripe_handle_webhook_event(PDO $pdo, array $event): void
{
    ensureStripeSchema($pdo);

    $type = (string) ($event['type'] ?? '');
    $object = $event['data']['object'] ?? [];
    $kind = (string) ($object['metadata']['kind'] ?? '');

    if ($kind === 'season_ticket' && in_array($type, ['checkout.session.completed', 'checkout.session.expired'], true)) {
        // Season ticket self-serve checkouts are handled by lib/season_ticket_stripe.php
        // (separate order/holder model from sponsorship_agreements) rather than below.
        require_once __DIR__ . '/season_ticket_stripe.php';
        if ($type === 'checkout.session.completed') {
            season_ticket_stripe_handle_checkout_completed($pdo, $object);
        } else {
            season_ticket_stripe_handle_checkout_expired($pdo, $object);
        }
        return;
    }

    if ($kind === 'member_sponsorship') {
        // Member self-serve sponsorships (lib/member_sponsorship.php) never create
        // an agreement until Stripe confirms payment, so there's nothing to do here
        // for checkout.session.expired — no agreement was ever created to clean up.
        require_once __DIR__ . '/member_sponsorship.php';
        if ($type === 'checkout.session.completed') {
            member_sponsorship_stripe_handle_checkout_completed($pdo, $object);
        }
        return;
    }

    if ($kind === 'match_ticket' && in_array($type, ['checkout.session.completed', 'checkout.session.expired'], true)) {
        require_once __DIR__ . '/match_tickets.php';
        if ($type === 'checkout.session.completed') {
            match_ticket_stripe_handle_checkout_completed($pdo, $object);
        } else {
            match_ticket_stripe_handle_checkout_expired($pdo, $object);
        }
        return;
    }

    if ($kind === 'hidden_team' && in_array($type, ['checkout.session.completed', 'checkout.session.expired'], true)) {
        require_once __DIR__ . '/hidden_team_stripe.php';
        if ($type === 'checkout.session.completed') {
            hidden_team_stripe_handle_checkout_completed($pdo, $object);
        } else {
            hidden_team_stripe_handle_checkout_expired($pdo, $object);
        }
        return;
    }

    if ($kind === 'player_sponsorship_shop' && in_array($type, ['checkout.session.completed', 'checkout.session.expired'], true)) {
        // Public multi-item player kit sponsorship shop (hub/playersponsors*.php) —
        // pre-creates a pending order+items row at checkout, unlike member_sponsorship
        // above, so both completed and expired need handling here.
        require_once __DIR__ . '/player_sponsorship_shop.php';
        if ($type === 'checkout.session.completed') {
            player_sponsorship_shop_stripe_handle_checkout_completed($pdo, $object);
        } else {
            player_sponsorship_shop_stripe_handle_checkout_expired($pdo, $object);
        }
        return;
    }

    if ($type === 'checkout.session.completed') {
        stripe_handle_checkout_session_completed($pdo, $object);
    } elseif ($type === 'checkout.session.expired') {
        stripe_handle_checkout_session_expired($pdo, $object);
    } elseif ($type === 'charge.refunded') {
        stripe_handle_charge_refunded($pdo, $object);
    }
}

/**
 * @param array<string, mixed> $session
 */
function stripe_handle_checkout_session_completed(PDO $pdo, array $session): void
{
    $sessionId = (string) ($session['id'] ?? '');
    if ($sessionId === '' || (string) ($session['payment_status'] ?? '') !== 'paid') {
        return;
    }

    // A bundle's combined link produces several rows sharing this one session id (one
    // per member agreement, each with its own split amount) — a plain single-agreement
    // link just has one. Either way, loop every row so every agreement gets credited.
    $linkStmt = $pdo->prepare('SELECT * FROM stripe_payment_links WHERE stripe_checkout_session_id = :session_id');
    $linkStmt->execute([':session_id' => $sessionId]);
    $links = $linkStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$links) {
        error_log('[stripe] checkout.session.completed for unknown session ' . $sessionId);
        return;
    }

    $paymentIntentId = (string) ($session['payment_intent'] ?? '');
    $pdo->prepare('UPDATE stripe_payment_links SET status = :status, stripe_payment_intent_id = :payment_intent_id WHERE stripe_checkout_session_id = :session_id')
        ->execute([':status' => 'complete', ':payment_intent_id' => $paymentIntentId ?: null, ':session_id' => $sessionId]);

    if ($paymentIntentId === '') {
        return;
    }

    foreach ($links as $link) {
        // Idempotency is scoped per payment_link_id (one row = one agreement's slice of
        // this session), not per payment_intent_id — a session-wide check would find the
        // first sibling's just-inserted row and wrongly skip recording every other one.
        $existing = $pdo->prepare('SELECT id FROM stripe_transactions WHERE payment_link_id = :payment_link_id');
        $existing->execute([':payment_link_id' => (int) $link['id']]);
        if ($existing->fetchColumn()) {
            continue; // Already recorded (webhook retry).
        }

        $agreement = getSponsorshipAgreement($pdo, (int) $link['agreement_id']);
        if (!$agreement) {
            error_log('[stripe] Paid session ' . $sessionId . ' references missing agreement ' . $link['agreement_id']);
            continue;
        }

        $amount = (float) $link['amount'];
        $currency = (string) $link['currency'];

        $pdo->prepare('INSERT INTO stripe_transactions
            (agreement_id, payment_link_id, stripe_payment_intent_id, stripe_checkout_session_id, amount, currency, status, raw_payload)
            VALUES (:agreement_id, :payment_link_id, :payment_intent_id, :session_id, :amount, :currency, :status, :raw_payload)')
            ->execute([
                ':agreement_id' => (int) $agreement['id'],
                ':payment_link_id' => (int) $link['id'],
                ':payment_intent_id' => $paymentIntentId,
                ':session_id' => $sessionId,
                ':amount' => $amount,
                ':currency' => $currency,
                ':status' => 'succeeded',
                ':raw_payload' => json_encode($session),
            ]);

        stripe_record_agreement_payment($pdo, $agreement, $amount, 'Stripe payment (session ' . $sessionId . ')');
    }
}

/**
 * @param array<string, mixed> $session
 */
function stripe_handle_checkout_session_expired(PDO $pdo, array $session): void
{
    $sessionId = (string) ($session['id'] ?? '');
    if ($sessionId === '') {
        return;
    }

    $linkStmt = $pdo->prepare("SELECT * FROM stripe_payment_links WHERE stripe_checkout_session_id = :session_id AND status = 'open'");
    $linkStmt->execute([':session_id' => $sessionId]);
    $link = $linkStmt->fetch(PDO::FETCH_ASSOC);
    if (!$link) {
        return;
    }

    $pdo->prepare("UPDATE stripe_payment_links SET status = 'expired' WHERE id = :id")->execute([':id' => $link['id']]);
}

/**
 * @param array<string, mixed> $charge
 */
function stripe_handle_charge_refunded(PDO $pdo, array $charge): void
{
    $paymentIntentId = (string) ($charge['payment_intent'] ?? '');
    if ($paymentIntentId === '') {
        return;
    }

    $stmt = $pdo->prepare('SELECT * FROM stripe_transactions WHERE stripe_payment_intent_id = :id');
    $stmt->execute([':id' => $paymentIntentId]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$transactions) {
        return;
    }
    if (count($transactions) > 1) {
        // A bundle's combined payment can back several transactions off one payment
        // intent — but Stripe's refund/charge event carries no per-line-item breakdown,
        // so there's no way to know which agreement(s) this refund actually belongs to.
        // Refuse to guess; refund each bundle member individually from its own agreement
        // page (stripe_refund.php) instead, which already reconciles correctly per-row.
        error_log('[stripe] charge.refunded for payment_intent ' . $paymentIntentId . ' backs ' . count($transactions) . ' transactions (bundle) — skipping automatic reconciliation, refund each agreement individually.');
        return;
    }
    $transaction = $transactions[0];

    $newRefundedTotal = stripe_minor_units_to_amount((int) ($charge['amount_refunded'] ?? 0), (string) $transaction['currency']);
    $delta = round($newRefundedTotal - (float) $transaction['refunded_amount'], 2);
    if ($delta <= 0.0001) {
        return; // Already reconciled (webhook retry, or refunded via our own stripe_refund.php flow already updated this row).
    }

    $refundId = 'webhook_' . (string) ($charge['id'] ?? uniqid('ch_', true));
    $refunds = $charge['refunds']['data'] ?? [];
    if (is_array($refunds) && $refunds !== []) {
        $latest = end($refunds);
        if (is_array($latest) && isset($latest['id'])) {
            $refundId = (string) $latest['id'];
        }
    }

    $agreement = getSponsorshipAgreement($pdo, (int) $transaction['agreement_id']);
    if (!$agreement) {
        return;
    }

    try {
        $pdo->prepare('INSERT INTO stripe_refunds (transaction_id, stripe_refund_id, amount, reason, initiated_by)
            VALUES (:transaction_id, :refund_id, :amount, :reason, NULL)')
            ->execute([
                ':transaction_id' => (int) $transaction['id'],
                ':refund_id' => $refundId,
                ':amount' => $delta,
                ':reason' => 'Refunded directly in Stripe',
            ]);
    } catch (Throwable $e) {
        // Unique key collision means our own stripe_refund.php already recorded this refund; nothing further to do.
        return;
    }

    $status = $newRefundedTotal >= (float) $transaction['amount'] - 0.0001 ? 'refunded' : 'partially_refunded';
    $pdo->prepare('UPDATE stripe_transactions SET refunded_amount = :refunded_amount, status = :status WHERE id = :id')
        ->execute([':refunded_amount' => $newRefundedTotal, ':status' => $status, ':id' => $transaction['id']]);

    stripe_reverse_agreement_payment($pdo, $agreement, $delta, 'Stripe refund (' . $refundId . ')');
}

/**
 * Initiate a refund from within the Hub (full or partial).
 *
 * @param array<string, mixed> $transaction a stripe_transactions row
 */
function stripe_create_refund(PDO $pdo, array $transaction, float $amount, ?string $reason, ?int $initiatedByUserId): array
{
    ensureStripeSchema($pdo);

    $remaining = round((float) $transaction['amount'] - (float) $transaction['refunded_amount'], 2);
    if ($amount <= 0 || $amount > $remaining + 0.0001) {
        throw new RuntimeException('Refund amount must be between £0.01 and the remaining paid amount (' . gbp($remaining) . ').');
    }

    $currency = (string) $transaction['currency'];
    $response = stripe_request('POST', '/refunds', [
        'payment_intent' => (string) $transaction['stripe_payment_intent_id'],
        'amount' => stripe_amount_to_minor_units($amount, $currency),
    ]);

    $refundId = (string) ($response['id'] ?? '');
    if ($refundId === '') {
        throw new RuntimeException('Stripe did not return a refund id.');
    }

    $pdo->prepare('INSERT INTO stripe_refunds (transaction_id, stripe_refund_id, amount, reason, initiated_by)
        VALUES (:transaction_id, :refund_id, :amount, :reason, :initiated_by)')
        ->execute([
            ':transaction_id' => (int) $transaction['id'],
            ':refund_id' => $refundId,
            ':amount' => $amount,
            ':reason' => $reason !== null && $reason !== '' ? $reason : null,
            ':initiated_by' => $initiatedByUserId,
        ]);

    $newRefundedTotal = round((float) $transaction['refunded_amount'] + $amount, 2);
    $status = $newRefundedTotal >= (float) $transaction['amount'] - 0.0001 ? 'refunded' : 'partially_refunded';
    $pdo->prepare('UPDATE stripe_transactions SET refunded_amount = :refunded_amount, status = :status WHERE id = :id')
        ->execute([':refunded_amount' => $newRefundedTotal, ':status' => $status, ':id' => $transaction['id']]);

    $agreement = getSponsorshipAgreement($pdo, (int) $transaction['agreement_id']);
    if ($agreement) {
        stripe_reverse_agreement_payment($pdo, $agreement, $amount, 'Stripe refund (' . $refundId . ')' . ($reason ? ' — ' . $reason : ''));
    }

    return $response;
}

/**
 * @return list<array<string, mixed>>
 */
function stripe_get_transactions(PDO $pdo, array $filters = []): array
{
    ensureStripeSchema($pdo);

    $where = [];
    $params = [];
    if (!empty($filters['status'])) {
        $where[] = 't.status = :status';
        $params[':status'] = (string) $filters['status'];
    }
    if (!empty($filters['season_id'])) {
        $where[] = 'a.season_id = :season_id';
        $params[':season_id'] = (int) $filters['season_id'];
    }
    if (!empty($filters['from'])) {
        $where[] = 't.created_at >= :from';
        $params[':from'] = (string) $filters['from'] . ' 00:00:00';
    }
    if (!empty($filters['to'])) {
        $where[] = 't.created_at <= :to';
        $params[':to'] = (string) $filters['to'] . ' 23:59:59';
    }

    $sql = 'SELECT t.*, s.name AS sponsor_name, p.name AS package_name, a.season_id
        FROM stripe_transactions t
        JOIN sponsorship_agreements a ON a.id = t.agreement_id
        JOIN sponsors s ON s.id = a.sponsor_id
        JOIN packages p ON p.id = a.package_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY t.created_at DESC, t.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return array{collected: float, pending_links: int, expired_links: int, refunded: float}
 */
function stripe_dashboard_summary(PDO $pdo): array
{
    ensureStripeSchema($pdo);

    $collected = (float) ($pdo->query("SELECT COALESCE(SUM(amount - refunded_amount),0) FROM stripe_transactions WHERE status IN ('succeeded','partially_refunded')")->fetchColumn());
    $pendingLinks = (int) ($pdo->query("SELECT COUNT(*) FROM stripe_payment_links WHERE status = 'open' AND (expires_at IS NULL OR expires_at > NOW())")->fetchColumn());
    $expiredLinks = (int) ($pdo->query("SELECT COUNT(*) FROM stripe_payment_links WHERE status = 'expired'")->fetchColumn());
    $refunded = (float) ($pdo->query('SELECT COALESCE(SUM(refunded_amount),0) FROM stripe_transactions')->fetchColumn());

    return [
        'collected' => $collected,
        'pending_links' => $pendingLinks,
        'expired_links' => $expiredLinks,
        'refunded' => $refunded,
    ];
}

/**
 * Unified payment history for an agreement regardless of which underlying
 * table it rolls up through, so Stripe payments on legacy match/player
 * agreements show up on sponsorship_agreement.php too.
 *
 * recorded_at is when the row was actually written (full date+time) — distinct
 * from paid_at, which on sponsorship_agreement_payments is a DATE staff pick by
 * hand and carries no time. The match/player tables' paid_at is already a real
 * timestamp (Stripe writes it via NOW()), so recorded_at just mirrors it there.
 *
 * @param array<string, mixed> $agreement
 * @return list<array{paid_at: string, recorded_at: string, amount: float, method: ?string, note: ?string}>
 */
function getAgreementPayments(PDO $pdo, array $agreement): array
{
    $legacySource = (string) ($agreement['legacy_source'] ?? '');
    $legacyId = (int) ($agreement['legacy_id'] ?? 0);

    if ($legacySource === 'match' && $legacyId > 0) {
        $stmt = $pdo->prepare('SELECT paid_at, paid_at AS recorded_at, amount, method, note FROM match_sponsorship_payments WHERE match_sponsorship_id = :id ORDER BY paid_at DESC, id DESC');
        $stmt->execute([':id' => $legacyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($legacySource === 'player' && $legacyId > 0) {
        $stmt = $pdo->prepare('SELECT paid_at, paid_at AS recorded_at, amount, method, note FROM sponsorship_payments WHERE sponsorship_id = :id ORDER BY paid_at DESC, id DESC');
        $stmt->execute([':id' => $legacyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare('SELECT paid_at, created_at AS recorded_at, amount, method, note FROM sponsorship_agreement_payments WHERE agreement_id = :id ORDER BY paid_at DESC, id DESC');
    $stmt->execute([':id' => (int) $agreement['id']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
