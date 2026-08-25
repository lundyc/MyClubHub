<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

$hubFileEnv = app_parse_env_file(__DIR__ . '/.env');

if (!function_exists('hub_config_value')) {
    function hub_config_value(array $fileEnv, string $key, string $default): string
    {
        $runtime = getenv($key);
        if ($runtime !== false && trim((string) $runtime) !== '') {
            return (string) $runtime;
        }

        if (isset($fileEnv[$key]) && trim((string) $fileEnv[$key]) !== '') {
            return (string) $fileEnv[$key];
        }

        return $default;
    }
}

if (!defined('APP_NAME')) {
    define('APP_NAME', hub_config_value($hubFileEnv, 'APP_NAME', 'Hub'));
}
if (!defined('BASE_URL')) {
    define('BASE_URL', '/');
}
if (!defined('ERROR_LOG_PATH')) {
    define('ERROR_LOG_PATH', '/var/www/vhosts/lundy.me.uk/logs/error_log');
}
if (!defined('DB_HOST')) {
    define('DB_HOST', hub_config_value($hubFileEnv, 'HUB_DB_HOST', 'localhost:3306'));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', hub_config_value($hubFileEnv, 'HUB_DB_NAME', 'Hub'));
}
if (!defined('DB_FALLBACK_NAME')) {
    define('DB_FALLBACK_NAME', hub_config_value($hubFileEnv, 'HUB_DB_FALLBACK_NAME', 'PlayerSponsors'));
}
if (!defined('DB_USER')) {
    define('DB_USER', hub_config_value($hubFileEnv, 'HUB_DB_USER', 'PlayerSponsors'));
}
if (!defined('DB_PASS')) {
    define('DB_PASS', hub_config_value($hubFileEnv, 'HUB_DB_PASS', ''));
}
if (!defined('INSTALL_SECRET')) {
    define('INSTALL_SECRET', hub_config_value($hubFileEnv, 'HUB_INSTALL_SECRET', ''));
}
if (!defined('DEVELOPER_EMAIL')) {
    // Identifies the one account allowed to use developer_roles.php's user
    // switcher. Previously hardcoded as users.id === 1; that broke once
    // staff accounts moved into season_ticket_holders (a different id
    // space), so this is now an explicit, reassignable identity instead of
    // an assumption about row order. Empty = feature disabled.
    define('DEVELOPER_EMAIL', hub_config_value($hubFileEnv, 'HUB_DEVELOPER_EMAIL', ''));
}
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', filter_var(hub_config_value($hubFileEnv, 'HUB_APP_DEBUG', '1'), FILTER_VALIDATE_BOOL));
}

error_reporting(E_ALL);
ini_set('log_errors', '1');
if (APP_DEBUG) {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Player/sponsor modules historically used user_id while the Hub uses
// hub_user_id. Keep both keys synchronized during the flattened transition.
if (isset($_SESSION['hub_user_id']) && !isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = (int) $_SESSION['hub_user_id'];
}
if (isset($_SESSION['user_id']) && !isset($_SESSION['hub_user_id'])) {
    $_SESSION['hub_user_id'] = (string) $_SESSION['user_id'];
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
