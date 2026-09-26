<?php
/**
 * Small helpers shared by the API, the admin pages and the CLI installer.
 * All timestamps are produced in PHP (TIMEZONE) rather than by MySQL NOW(),
 * so expiry maths never drifts when the two servers disagree on time.
 */

if (!defined('APP_SECRET')) {
    require_once __DIR__ . '/../config.php';
}

date_default_timezone_set(TIMEZONE);

/* ---------------------------------------------------------------- */
/* Time                                                              */
/* ---------------------------------------------------------------- */

function gw_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now');
}

/** Current time as a MySQL DATETIME, optionally shifted: gw_ts('+5 minutes') */
function gw_ts(?string $modify = null): string
{
    $t = gw_now();
    if ($modify !== null) {
        $t = $t->modify($modify);
    }
    return $t->format('Y-m-d H:i:s');
}

function gw_expired(?string $datetime): bool
{
    return $datetime === null || strtotime($datetime) <= time();
}

/** Whole seconds remaining until $datetime, never negative. */
function gw_seconds_left(?string $datetime): int
{
    if ($datetime === null) {
        return 0;
    }
    return max(0, strtotime($datetime) - time());
}

/* ---------------------------------------------------------------- */
/* JSON responses                                                    */
/* ---------------------------------------------------------------- */

function gw_json(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @param string $code machine-readable reason, so the UI can react
 *                     (e.g. show a cooldown timer) without parsing prose
 */
function gw_fail(string $message, string $code = 'error', array $extra = [], int $status = 200): void
{
    gw_json(array_merge([
        'success' => false,
        'message' => $message,
        'code'    => $code,
    ], $extra), $status);
}

function gw_ok(string $message, array $extra = []): void
{
    gw_json(array_merge([
        'success' => true,
        'message' => $message,
    ], $extra));
}

/** Request body, whether it arrived as JSON or as a normal form post. */
function gw_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

function gw_field(array $src, string $key, int $maxLen = 255): string
{
    $v = isset($src[$key]) && is_scalar($src[$key]) ? (string) $src[$key] : '';
    $v = str_replace(["\r", "\n", "\0"], '', trim($v));
    return mb_substr($v, 0, $maxLen);
}

/* ---------------------------------------------------------------- */
/* Client identity                                                   */
/* ---------------------------------------------------------------- */

function gw_client_ip(): string
{
    // Deliberately NOT trusting X-Forwarded-For: on a captive portal the
    // client IP is the lease the firewall handed out, and a spoofable
    // header would let one guest evade the per-IP OTP rate limit.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return mb_substr($ip, 0, 45);
}

function gw_user_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

/** Strip everything but digits, then keep the national 10-digit part. */
function gw_normalize_mobile(string $value): string
{
    $digits = preg_replace('/\D/', '', $value);
    return strlen($digits) > 10 ? substr($digits, -10) : $digits;
}

function gw_valid_mobile(string $mobile): bool
{
    return (bool) preg_match(MOBILE_REGEX, $mobile);
}

/** 9876543210 -> 98XXXXXX10, for echoing a number back on screen. */
function gw_mask_mobile(string $mobile): string
{
    $len = strlen($mobile);
    if ($len < 6) {
        return str_repeat('X', $len);
    }
    return substr($mobile, 0, 2) . str_repeat('X', $len - 4) . substr($mobile, -2);
}

/** Normalise a MAC to AA:BB:CC:DD:EE:FF, or '' if it is not one. */
function gw_normalize_mac(string $value): string
{
    $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $value));
    if (strlen($hex) !== 12) {
        return '';
    }
    return implode(':', str_split($hex, 2));
}

/* ---------------------------------------------------------------- */
/* Tokens and signing                                                */
/* ---------------------------------------------------------------- */

function gw_random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/** Stored form of a session token - the raw token never touches the DB. */
function gw_hash_token(string $token): string
{
    return hash_hmac('sha256', $token, APP_SECRET);
}

function gw_sign(string $value): string
{
    return substr(hash_hmac('sha256', $value, APP_SECRET), 0, 32);
}

function gw_sign_pack(string $value): string
{
    return $value . '.' . gw_sign($value);
}

/** Unpacks a signed cookie value, or returns '' if it was tampered with. */
function gw_sign_unpack(string $packed): string
{
    $parts = explode('.', $packed);
    if (count($parts) !== 2) {
        return '';
    }
    [$value, $sig] = $parts;
    return hash_equals(gw_sign($value), $sig) ? $value : '';
}

function gw_set_cookie(string $name, string $value, int $lifetimeSeconds): void
{
    if (headers_sent()) {
        return;
    }
    setcookie($name, $value, [
        'expires'  => $lifetimeSeconds > 0 ? time() + $lifetimeSeconds : 0,
        'path'     => gw_base_path(),
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
}

/** Directory the portal is served from, e.g. /guestwifi/ */
function gw_base_path(): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if (basename($dir) === 'api' || basename($dir) === 'admin' || basename($dir) === 'dev') {
        $dir = dirname($dir);
    }
    $dir = rtrim($dir, '/');
    return $dir === '' ? '/' : $dir . '/';
}

/* ---------------------------------------------------------------- */
/* Logging                                                           */
/* ---------------------------------------------------------------- */

function gw_storage_path(string $file = ''): string
{
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $file === '' ? $dir : $dir . '/' . $file;
}

function gw_log(string $file, string $line): void
{
    @file_put_contents(
        gw_storage_path($file),
        '[' . gw_ts() . '] ' . $line . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function gw_config_json(string $constant): array
{
    if (!defined($constant)) {
        return [];
    }
    $decoded = json_decode((string) constant($constant), true);
    return is_array($decoded) ? $decoded : [];
}

function gw_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
