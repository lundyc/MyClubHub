<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/pos.php';

pos_ensure_schema($pdo);
$actor = pos_require_actor($pdo);

header('Content-Type: application/json');

function pos_api_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    pos_api_response(['ok' => false, 'error' => 'POST required.'], 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw !== false ? $raw : '', true);
if (!is_array($data)) {
    $data = $_POST;
}

$token = (string) ($data['csrf_token'] ?? '');
if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
    pos_api_response(['ok' => false, 'error' => 'Your session expired. Please reload and try again.'], 400);
}

$action = (string) ($data['action'] ?? '');

try {
    if ($action === 'member_lookup') {
        $member = pos_member_lookup($pdo, (string) ($data['input'] ?? ''));
        if (!$member) {
            pos_api_response(['ok' => false, 'error' => 'No season ticket holder or member was found.'], 404);
        }
        pos_api_response(['ok' => true, 'member' => $member]);
    }

    if ($action === 'create_sale') {
        $locationId = (int) ($data['location_id'] ?? 0);
        $allowed = array_column(pos_locations_for_actor($pdo, $actor), 'id');
        if (!in_array($locationId, array_map('intval', $allowed), true)) {
            pos_api_response(['ok' => false, 'error' => 'You do not have access to this POS location.'], 403);
        }
        $result = pos_create_sale(
            $pdo,
            $locationId,
            $actor,
            is_array($data['items'] ?? null) ? $data['items'] : [],
            (string) ($data['payment_method'] ?? ''),
            is_array($data['member'] ?? null) ? $data['member'] : null,
            (int) ($data['points_redeemed'] ?? 0)
        );
        pos_api_response(['ok' => true, 'sale' => $result]);
    }

    if ($action === 'create_card_pending') {
        $locationId = (int) ($data['location_id'] ?? 0);
        $allowed = array_column(pos_locations_for_actor($pdo, $actor), 'id');
        if (!in_array($locationId, array_map('intval', $allowed), true)) {
            pos_api_response(['ok' => false, 'error' => 'You do not have access to this POS location.'], 403);
        }
        $result = pos_create_sale(
            $pdo,
            $locationId,
            $actor,
            is_array($data['items'] ?? null) ? $data['items'] : [],
            'card_stripe_app',
            is_array($data['member'] ?? null) ? $data['member'] : null,
            (int) ($data['points_redeemed'] ?? 0),
            'pending_card'
        );
        pos_api_response(['ok' => true, 'sale' => $result]);
    }

    if ($action === 'create_cash_pending') {
        $locationId = (int) ($data['location_id'] ?? 0);
        $allowed = array_column(pos_locations_for_actor($pdo, $actor), 'id');
        if (!in_array($locationId, array_map('intval', $allowed), true)) {
            pos_api_response(['ok' => false, 'error' => 'You do not have access to this POS location.'], 403);
        }
        $result = pos_create_sale(
            $pdo,
            $locationId,
            $actor,
            is_array($data['items'] ?? null) ? $data['items'] : [],
            'cash',
            is_array($data['member'] ?? null) ? $data['member'] : null,
            (int) ($data['points_redeemed'] ?? 0),
            'pending_cash'
        );
        pos_api_response(['ok' => true, 'sale' => $result]);
    }

    if ($action === 'complete_card_pending') {
        $result = pos_complete_pending_card_sale($pdo, (int) ($data['sale_id'] ?? 0), $actor);
        pos_api_response(['ok' => true, 'sale' => $result]);
    }

    if ($action === 'complete_cash_pending') {
        $result = pos_complete_pending_cash_sale($pdo, (int) ($data['sale_id'] ?? 0), $actor);
        pos_api_response(['ok' => true, 'sale' => $result]);
    }

    if ($action === 'cancel_card_pending') {
        pos_cancel_pending_card_sale($pdo, (int) ($data['sale_id'] ?? 0), $actor);
        pos_api_response(['ok' => true]);
    }

    if ($action === 'cancel_cash_pending') {
        pos_cancel_pending_cash_sale($pdo, (int) ($data['sale_id'] ?? 0), $actor);
        pos_api_response(['ok' => true]);
    }

    if ($action === 'pending_card_status') {
        $sale = pos_pending_card_sale($pdo, (int) ($data['sale_id'] ?? 0), $actor);
        pos_api_response(['ok' => true, 'sale' => $sale]);
    }

    if ($action === 'pending_cash_status') {
        $sale = pos_pending_cash_sale($pdo, (int) ($data['sale_id'] ?? 0), $actor);
        pos_api_response(['ok' => true, 'sale' => $sale]);
    }

    pos_api_response(['ok' => false, 'error' => 'Unknown POS action.'], 400);
} catch (Throwable $exception) {
    pos_api_response(['ok' => false, 'error' => $exception->getMessage()], 400);
}
