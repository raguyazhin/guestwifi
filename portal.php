<?php
/**
 * Guest Wi-Fi OTP portal - all application logic.
 *
 * Included by api.php, install.php and admin.php. Defines functions only;
 * requesting this file directly produces nothing.
 *
 * Written for PHP 5.6 (no ??, no type declarations, no match) so it runs on
 * the deployment server, and unchanged on PHP 7 and 8.
 *
 * Sections:
 *   1. Compatibility   secure random polyfills, cookie helpers
 *   2. Helpers         time, JSON, input, mobile/MAC, signing, logging
 *   3. Database        connection, schema installer, stale-record cleanup
 *   4. Portal context  firewall redirect params, device identity
 *   5. FortiGate       credential handoff
 *   6. Sessions        guest internet sessions
 *   7. SMS             pluggable gateway drivers
 *   8. OTP             issue and verify - every policy rule lives here
 */

if (!defined('APP_SECRET')) {
    require_once dirname(__FILE__) . '/config.php';
}

date_default_timezone_set(TIMEZONE);

define('GW_DEVICE_COOKIE', 'gw_device');
define('GW_SESSION_COOKIE', 'gw_session');

/* ================================================================== */
/* 1. Compatibility                                                    */
/* ================================================================== */

/**
 * PHP 5.6 has no random_bytes(). mt_rand() is not an acceptable stand-in:
 * its state can be recovered from a few outputs, so an attacker who
 * collects a handful of OTPs could predict the next one. These polyfills
 * use the OS cryptographic source and refuse to return anything if none is
 * available, rather than quietly producing guessable codes.
 */
if (!function_exists('random_bytes')) {
    function random_bytes($length)
    {
        $length = (int) $length;
        if ($length < 1) {
            throw new Exception('random_bytes: length must be positive');
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $bytes  = openssl_random_pseudo_bytes($length, $strong);
            if ($strong === true && is_string($bytes) && strlen($bytes) === $length) {
                return $bytes;
            }
        }

        if (function_exists('mcrypt_create_iv')) {
            $bytes = @mcrypt_create_iv($length, MCRYPT_DEV_URANDOM);
            if (is_string($bytes) && strlen($bytes) === $length) {
                return $bytes;
            }
        }

        if (@is_readable('/dev/urandom')) {
            $fh = @fopen('/dev/urandom', 'rb');
            if ($fh !== false) {
                $bytes = @fread($fh, $length);
                fclose($fh);
                if (is_string($bytes) && strlen($bytes) === $length) {
                    return $bytes;
                }
            }
        }

        throw new Exception(
            'No cryptographically secure random source is available. Enable the '
            . 'OpenSSL extension in php.ini, or run the portal on PHP 7 or newer.'
        );
    }
}

if (!function_exists('random_int')) {
    function random_int($min, $max)
    {
        $min = (int) $min;
        $max = (int) $max;

        if ($min > $max) {
            throw new Exception('random_int: min must not exceed max');
        }
        if ($min === $max) {
            return $min;
        }

        $range = $max - $min;

        $bits = 0;
        $tmp  = $range;
        while ($tmp > 0) {
            $bits++;
            $tmp >>= 1;
        }
        $bytes = (int) ceil($bits / 8);
        $mask  = (1 << $bits) - 1;

        do {
            $raw   = random_bytes($bytes);
            $value = 0;
            for ($i = 0; $i < $bytes; $i++) {
                $value = ($value << 8) | ord($raw[$i]);
            }
            // Trim to the range's bit width and retry on overflow. Taking a
            // modulus instead would make low values slightly likelier.
            $value &= $mask;
        } while ($value > $range);

        return $min + $value;
    }
}

/** setcookie() with SameSite. The array form of setcookie() is PHP 7.3+. */
function gw_setcookie($name, $value, $expires, $path, $secure, $httpOnly, $sameSite = 'Lax')
{
    if (PHP_VERSION_ID >= 70300) {
        setcookie($name, $value, array(
            'expires'  => $expires,
            'path'     => $path,
            'secure'   => (bool) $secure,
            'httponly' => (bool) $httpOnly,
            'samesite' => $sameSite,
        ));
        return;
    }

    // Before 7.3 the attribute has to ride along on the path, which works
    // because the value is written into the header verbatim.
    setcookie($name, $value, $expires, $path . '; samesite=' . $sameSite, '', (bool) $secure, (bool) $httpOnly);
}

/* ================================================================== */
/* 2. Helpers                                                          */
/* ================================================================== */

function gw_now()
{
    return new DateTime('now');
}

/** Current time as a MySQL DATETIME, optionally shifted: gw_ts('+5 minutes') */
function gw_ts($modify = null)
{
    $t = gw_now();
    if ($modify !== null) {
        $t->modify($modify);
    }
    return $t->format('Y-m-d H:i:s');
}

function gw_today()
{
    return gw_now()->format('Y-m-d') . ' 00:00:00';
}

function gw_expired($datetime)
{
    return $datetime === null || strtotime($datetime) <= time();
}

function gw_seconds_left($datetime)
{
    if ($datetime === null) {
        return 0;
    }
    return max(0, strtotime($datetime) - time());
}

function gw_json($payload, $status = 200)
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload);
    exit;
}

/** $code is machine-readable so the UI can react without parsing prose. */
function gw_fail($message, $code = 'error', $extra = array(), $status = 200)
{
    gw_json(array_merge(array(
        'success' => false,
        'message' => $message,
        'code'    => $code,
    ), $extra), $status);
}

function gw_ok($message, $extra = array())
{
    gw_json(array_merge(array('success' => true, 'message' => $message), $extra));
}

/** Request body, whether it arrived as JSON or as a normal form post. */
function gw_input()
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

function gw_field($src, $key, $maxLen = 255)
{
    $v = (isset($src[$key]) && is_scalar($src[$key])) ? (string) $src[$key] : '';
    $v = str_replace(array("\r", "\n", "\0"), '', trim($v));
    return mb_substr($v, 0, $maxLen);
}

function gw_client_ip()
{
    // Deliberately NOT trusting X-Forwarded-For: on a captive portal the
    // client IP is the lease the firewall handed out, and a spoofable
    // header would let one guest evade the per-IP OTP rate limit.
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    return mb_substr($ip, 0, 45);
}

function gw_user_agent()
{
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    return mb_substr($ua, 0, 255);
}

/** Strip everything but digits, then keep the national 10-digit part. */
function gw_normalize_mobile($value)
{
    $digits = preg_replace('/\D/', '', $value);
    return strlen($digits) > 10 ? substr($digits, -10) : $digits;
}

function gw_valid_mobile($mobile)
{
    return (bool) preg_match(MOBILE_REGEX, $mobile);
}

/** 9876543210 -> 98XXXXXX10, for echoing a number back on screen. */
function gw_mask_mobile($mobile)
{
    $len = strlen($mobile);
    if ($len < 6) {
        return str_repeat('X', $len);
    }
    return substr($mobile, 0, 2) . str_repeat('X', $len - 4) . substr($mobile, -2);
}

/** Normalise a MAC to AA:BB:CC:DD:EE:FF, or '' if it is not one. */
function gw_normalize_mac($value)
{
    $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $value));
    if (strlen($hex) !== 12) {
        return '';
    }
    return implode(':', str_split($hex, 2));
}

function gw_random_token($bytes = 32)
{
    return bin2hex(random_bytes($bytes));
}

/** Stored form of a session token - the raw token never touches the DB. */
function gw_hash_token($token)
{
    return hash_hmac('sha256', $token, APP_SECRET);
}

function gw_sign($value)
{
    return substr(hash_hmac('sha256', $value, APP_SECRET), 0, 32);
}

function gw_sign_pack($value)
{
    return $value . '.' . gw_sign($value);
}

/** Unpacks a signed cookie value, or '' if it was tampered with. */
function gw_sign_unpack($packed)
{
    $parts = explode('.', $packed);
    if (count($parts) !== 2) {
        return '';
    }
    return hash_equals(gw_sign($parts[0]), $parts[1]) ? $parts[0] : '';
}

/** Directory the portal is served from, e.g. /guestwifi/ */
function gw_base_path()
{
    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/';
    $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $dir === '' ? '/' : $dir . '/';
}

function gw_set_cookie($name, $value, $lifetimeSeconds)
{
    if (headers_sent()) {
        return;
    }
    $expires = $lifetimeSeconds > 0 ? time() + $lifetimeSeconds : 0;
    $secure  = !empty($_SERVER['HTTPS']);
    gw_setcookie($name, $value, $expires, gw_base_path(), $secure, true, gw_samesite());
}

/**
 * The firewall-hosted page calls this server cross-site, and browsers only
 * send SameSite=None cookies on such calls - which in turn requires HTTPS.
 * Over plain HTTP stay on Lax (None without Secure is rejected outright).
 */
function gw_samesite()
{
    return !empty($_SERVER['HTTPS']) ? 'None' : 'Lax';
}

function gw_storage_path($file = '')
{
    $dir = dirname(__FILE__) . '/storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $file === '' ? $dir : $dir . '/' . $file;
}

function gw_log($file, $line)
{
    @file_put_contents(gw_storage_path($file), '[' . gw_ts() . '] ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function gw_config_json($constant)
{
    if (!defined($constant)) {
        return array();
    }
    $decoded = json_decode((string) constant($constant), true);
    return is_array($decoded) ? $decoded : array();
}

function gw_e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function gw_result($ok, $code, $message, $data = array())
{
    return array('ok' => $ok, 'code' => $code, 'message' => $message, 'data' => $data);
}

/* ================================================================== */
/* 3. Database                                                         */
/* ================================================================== */

function db()
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ));
    }

    return $pdo;
}

function gw_table_exists(PDO $pdo, $table)
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $stmt->execute(array(DB_NAME, $table));
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Creates the database and tables if absent. Safe to run repeatedly.
 * Returns a list of what happened, for install.php to display.
 */
function gw_install_schema()
{
    $done = array();

    $server = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $server->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` '
        . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $done[] = 'database `' . DB_NAME . '` ready';

    $pdo = db();

    $tables = array(

        // Optional whitelist, consulted only when REQUIRE_ALLOWED_LIST is true.
        'allowed_mobiles' => "
            CREATE TABLE IF NOT EXISTS allowed_mobiles (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                mobile     VARCHAR(20) NOT NULL UNIQUE,
                label      VARCHAR(100) DEFAULT NULL,
                enabled    TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // device_id is the device the code was issued to - what makes the
        // OTP unusable anywhere else. `used` is the single-use latch.
        'otp_requests' => "
            CREATE TABLE IF NOT EXISTS otp_requests (
                id          BIGINT AUTO_INCREMENT PRIMARY KEY,
                mobile      VARCHAR(20) NOT NULL,
                otp_hash    VARCHAR(255) NOT NULL,
                device_id   VARCHAR(128) NOT NULL,
                mac_address VARCHAR(32) DEFAULT NULL,
                ip_address  VARCHAR(45) DEFAULT NULL,
                user_agent  VARCHAR(255) DEFAULT NULL,
                attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
                used        TINYINT(1) NOT NULL DEFAULT 0,
                status      VARCHAR(16) NOT NULL DEFAULT 'pending',
                expires_at  DATETIME NOT NULL,
                verified_at DATETIME DEFAULT NULL,
                created_at  DATETIME NOT NULL,
                KEY idx_mobile_used (mobile, used),
                KEY idx_device (device_id),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'guest_sessions' => "
            CREATE TABLE IF NOT EXISTS guest_sessions (
                id          BIGINT AUTO_INCREMENT PRIMARY KEY,
                mobile      VARCHAR(20) NOT NULL,
                device_id   VARCHAR(128) NOT NULL,
                mac_address VARCHAR(32) DEFAULT NULL,
                ip_address  VARCHAR(45) DEFAULT NULL,
                user_agent  VARCHAR(255) DEFAULT NULL,
                otp_id      BIGINT DEFAULT NULL,
                token_hash  CHAR(64) NOT NULL,
                active      TINYINT(1) NOT NULL DEFAULT 1,
                started_at  DATETIME NOT NULL,
                expires_at  DATETIME NOT NULL,
                ended_at    DATETIME DEFAULT NULL,
                end_reason  VARCHAR(32) DEFAULT NULL,
                UNIQUE KEY uq_token (token_hash),
                KEY idx_mobile_active (mobile, active),
                KEY idx_device_active (device_id, active),
                KEY idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'login_history' => "
            CREATE TABLE IF NOT EXISTS login_history (
                id         BIGINT AUTO_INCREMENT PRIMARY KEY,
                mobile     VARCHAR(20) DEFAULT NULL,
                device_id  VARCHAR(128) DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                success    TINYINT(1) NOT NULL DEFAULT 0,
                reason     VARCHAR(48) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_mobile_success (mobile, success, created_at),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // The OTP is masked before the message is stored.
        'sms_log' => "
            CREATE TABLE IF NOT EXISTS sms_log (
                id         BIGINT AUTO_INCREMENT PRIMARY KEY,
                mobile     VARCHAR(20) NOT NULL,
                driver     VARCHAR(20) NOT NULL,
                message    VARCHAR(500) DEFAULT NULL,
                ok         TINYINT(1) NOT NULL DEFAULT 0,
                http_code  INT DEFAULT NULL,
                response   TEXT,
                created_at DATETIME NOT NULL,
                KEY idx_mobile (mobile, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    );

    foreach ($tables as $name => $sql) {
        $existed = gw_table_exists($pdo, $name);
        $pdo->exec($sql);
        $done[] = $existed ? "table `$name` present" : "table `$name` created";
    }

    return $done;
}

/**
 * Closes sessions and OTPs whose time has run out. Cheap enough to call on
 * every request, which keeps the portal correct without a cron job.
 */
function gw_expire_stale()
{
    $now = gw_ts();

    $stmt = db()->prepare(
        "UPDATE guest_sessions SET active = 0, ended_at = ?, end_reason = 'expired'
          WHERE active = 1 AND expires_at <= ?"
    );
    $stmt->execute(array($now, $now));

    $stmt = db()->prepare(
        "UPDATE otp_requests SET used = 1, status = 'expired' WHERE used = 0 AND expires_at <= ?"
    );
    $stmt->execute(array($now));
}

/* ================================================================== */
/* 4. Portal context and device identity                               */
/* ================================================================== */

function gw_portal_start()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $path   = gw_base_path();
    $secure = !empty($_SERVER['HTTPS']);
    $same   = gw_samesite();

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array(
            'lifetime' => 0, 'path' => $path, 'secure' => $secure,
            'httponly' => true, 'samesite' => $same,
        ));
    } else {
        session_set_cookie_params(0, $path . '; samesite=' . $same, '', $secure, true);
    }

    session_name('gw_portal');
    session_start();
}

function gw_empty_context()
{
    return array('magic' => '', 'mac' => '', 'user_ip' => '', 'post_url' => '',
                 'redirect' => '', 'ssid' => '', 'ap_mac' => '');
}

/**
 * Reads the firewall's redirect parameters and keeps them server-side for
 * the rest of the visit, so a reload mid-flow does not lose the context.
 */
function gw_capture_portal_context($src)
{
    gw_portal_start();

    $context = isset($_SESSION['portal']) ? $_SESSION['portal'] : gw_empty_context();

    // FortiOS names first, then the aliases other vendors use.
    $map = array(
        'magic'    => array('magic'),
        'mac'      => array('usermac', 'client_mac', 'mac', 'id'),
        'user_ip'  => array('userip', 'client_ip', 'uamip'),
        'post_url' => array('post', 'login_url', 'link-login-only', 'link-login'),
        'redirect' => array('4Tredir', 'redir', 'url', 'link-orig'),
        'ssid'     => array('ssid'),
        'ap_mac'   => array('apmac', 'ap_mac', 'bssid'),
    );

    foreach ($map as $key => $aliases) {
        foreach ($aliases as $alias) {
            if (!isset($src[$alias]) || !is_scalar($src[$alias])) {
                continue;
            }
            $value = gw_field($src, $alias, 512);
            if ($value === '') {
                continue;
            }
            if ($key === 'mac' || $key === 'ap_mac') {
                $value = gw_normalize_mac($value);
                if ($value === '') {
                    continue;
                }
            }
            if ($key === 'magic' && !preg_match('/^[A-Za-z0-9]{1,64}$/', $value)) {
                continue;
            }
            $context[$key] = $value;
            break;   // first alias carrying a usable value wins
        }
    }

    $_SESSION['portal'] = $context;

    return $context;
}

function gw_portal_context()
{
    gw_portal_start();
    return isset($_SESSION['portal']) ? $_SESSION['portal'] : gw_empty_context();
}

/**
 * Stable identity for the device in front of the portal.
 *
 * The firewall-supplied MAC is preferred: it survives a browser restart,
 * private browsing and cleared cookies, so a guest cannot obtain a second
 * OTP by wiping site data. Without a MAC we fall back to a signed cookie,
 * which is an application identifier - not proof of hardware identity.
 */
function gw_device_id()
{
    $context = gw_portal_context();

    if ($context['mac'] !== '') {
        return 'mac:' . $context['mac'];
    }
    return 'br:' . gw_browser_device_id();
}

function gw_device_mac()
{
    $mac = gw_portal_context()['mac'];
    return $mac === '' ? null : $mac;
}

function gw_device_is_hardware()
{
    $context = gw_portal_context();
    return $context['mac'] !== '';
}

function gw_browser_device_id()
{
    gw_portal_start();

    if (!empty($_SESSION['device_id'])) {
        return $_SESSION['device_id'];
    }

    $raw    = isset($_COOKIE[GW_DEVICE_COOKIE]) ? (string) $_COOKIE[GW_DEVICE_COOKIE] : '';
    $cookie = $raw === '' ? '' : gw_sign_unpack($raw);

    if (!preg_match('/^[a-f0-9]{32}$/', $cookie)) {
        $cookie = gw_random_token(16);
        gw_set_cookie(GW_DEVICE_COOKIE, gw_sign_pack($cookie), 86400 * 365);
    }

    $_SESSION['device_id'] = $cookie;

    return $cookie;
}

/**
 * Last-resort identity for a page served from another origin (the firewall
 * hosts the page, so our cookie never reaches it). Only consulted when the
 * firewall supplied no MAC. No weaker than a cookie the guest could clear,
 * but not proof of hardware identity.
 */
function gw_adopt_client_device_id($id)
{
    gw_portal_start();

    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        return;
    }
    if (!empty($_SESSION['device_id'])) {
        return;   // this browser already has an identity; do not let it swap
    }

    $_SESSION['device_id'] = $id;
    gw_set_cookie(GW_DEVICE_COOKIE, gw_sign_pack($id), 86400 * 365);
}

/* ================================================================== */
/* 5. FortiGate handoff                                                */
/* ================================================================== */

/** Where the browser should POST. '' when missing or not on the allow-list. */
function gw_fortigate_post_url($context)
{
    $url = $context['post_url'] !== '' ? $context['post_url'] : FORTIGATE_POST_URL;
    if ($url === '') {
        return '';
    }

    $parts  = parse_url($url);
    $scheme = isset($parts['scheme']) ? $parts['scheme'] : '';
    if (!$parts || empty($parts['host']) || !in_array($scheme, array('http', 'https'), true)) {
        return '';
    }

    // Without this check a crafted ?post=https://evil.tld would make the
    // portal hand the firewall password to an attacker's server.
    $allowed = gw_config_json('FORTIGATE_ALLOWED_HOSTS');
    if (!empty($allowed) && !in_array($parts['host'], $allowed, true)) {
        gw_log('error.log', 'Blocked FortiGate post to unlisted host: ' . $parts['host']);
        return '';
    }

    return $url;
}

/** Builds the instruction the browser follows after a verified OTP. */
function gw_fortigate_handoff($mobile, $sessionToken)
{
    $context = gw_portal_context();
    $landing = $context['redirect'] !== '' ? $context['redirect'] : DEFAULT_LANDING_URL;
    $none    = array('action' => 'none', 'url' => $landing, 'fields' => new stdClass());

    if (FORTIGATE_MODE === 'none') {
        return $none;
    }

    $postUrl = gw_fortigate_post_url($context);
    if ($postUrl === '' || $context['magic'] === '') {
        // Most likely the portal was opened directly rather than through a
        // firewall redirect.
        gw_log('error.log', 'FortiGate handoff skipped: post_url or magic missing');
        return $none;
    }

    if (FORTIGATE_CRED_MODE === 'mobile') {
        $username = $mobile;
        // Deterministic from the session token, so the same value can be
        // provisioned on the firewall and posted by the browser.
        $password = substr(hash_hmac('sha256', 'fgt:' . $sessionToken, APP_SECRET), 0, 24);
    } else {
        $username = FORTIGATE_SHARED_USER;
        $password = FORTIGATE_SHARED_PASS;
    }

    if (FORTIGATE_MODE === 'api') {
        $provisioned = gw_fortigate_provision_user($username, $password);
        if (!$provisioned['ok']) {
            gw_log('error.log', 'FortiGate provisioning failed: ' . $provisioned['error']);
        }
    }

    return array(
        'action' => 'form_post',
        'url'    => $postUrl,
        'fields' => array(
            'magic'    => $context['magic'],
            'username' => $username,
            'password' => $password,
            '4Tredir'  => $landing,
        ),
    );
}

/**
 * Creates or refreshes a local user on the FortiGate. Endpoint paths vary
 * between FortiOS versions - check yours in the firewall's API browser
 * before enabling FORTIGATE_MODE='api'.
 */
function gw_fortigate_provision_user($username, $password)
{
    if (FORTIGATE_API_URL === '' || FORTIGATE_API_TOKEN === '') {
        return array('ok' => false, 'error' => 'FortiGate API URL or token not configured');
    }

    $base = rtrim(FORTIGATE_API_URL, '/') . '/api/v2/cmdb/user/local';
    $auth = array(
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . FORTIGATE_API_TOKEN,
        ),
        CURLOPT_SSL_VERIFYPEER => (bool) FORTIGATE_API_VERIFY_TLS,
        CURLOPT_SSL_VERIFYHOST => FORTIGATE_API_VERIFY_TLS ? 2 : 0,
    );
    $query = '?vdom=' . rawurlencode(FORTIGATE_API_VDOM);
    $body  = json_encode(array(
        'name' => $username, 'type' => 'password',
        'passwd' => $password, 'status' => 'enable',
    ));

    $create = gw_http_request($base . $query,
        $auth + array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body));

    if (!$create['ok']) {
        // Already there: update the password instead of creating a duplicate.
        $update = gw_http_request($base . '/' . rawurlencode($username) . $query,
            $auth + array(CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $body));
        if (!$update['ok']) {
            return array('ok' => false,
                'error' => 'create: ' . $create['response'] . ' | update: ' . $update['response']);
        }
    }

    return array('ok' => true, 'error' => '');
}

/* ================================================================== */
/* 6. Guest sessions                                                   */
/* ================================================================== */

function gw_session_active_for_device($deviceId)
{
    $stmt = db()->prepare(
        'SELECT * FROM guest_sessions WHERE device_id = ? AND active = 1 AND expires_at > ?
          ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(array($deviceId, gw_ts()));
    $row = $stmt->fetch();

    return $row ? $row : null;
}

/** Active sessions for a mobile, oldest first. */
function gw_sessions_active_for_mobile($mobile, $excludeDeviceId = '')
{
    $sql  = 'SELECT * FROM guest_sessions WHERE mobile = ? AND active = 1 AND expires_at > ?';
    $args = array($mobile, gw_ts());

    if ($excludeDeviceId !== '') {
        $sql .= ' AND device_id <> ?';
        $args[] = $excludeDeviceId;
    }
    $sql .= ' ORDER BY started_at ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($args);

    return $stmt->fetchAll();
}

function gw_session_by_token($token)
{
    if ($token === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM guest_sessions WHERE token_hash = ? LIMIT 1');
    $stmt->execute(array(gw_hash_token($token)));
    $row = $stmt->fetch();

    return $row ? $row : null;
}

function gw_session_end($id, $reason)
{
    $stmt = db()->prepare(
        'UPDATE guest_sessions SET active = 0, ended_at = ?, end_reason = ? WHERE id = ? AND active = 1'
    );
    $stmt->execute(array(gw_ts(), mb_substr($reason, 0, 32), (int) $id));
}

function gw_daily_success_count($mobile)
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_history WHERE mobile = ? AND success = 1 AND created_at >= ?'
    );
    $stmt->execute(array($mobile, gw_today()));

    return (int) $stmt->fetchColumn();
}

function gw_history_add($mobile, $deviceId, $success, $reason)
{
    $stmt = db()->prepare(
        'INSERT INTO login_history (mobile, device_id, ip_address, success, reason, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute(array($mobile, mb_substr($deviceId, 0, 128), gw_client_ip(),
        $success ? 1 : 0, mb_substr($reason, 0, 48), gw_ts()));
}

/**
 * Opens a session for a device, replacing any earlier session that device
 * still holds (a reconnect is not a second device).
 */
function gw_session_create($mobile, $deviceId, $mac, $otpId)
{
    $pdo   = db();
    $token = gw_random_token(32);
    $now   = gw_ts();
    $until = gw_ts('+' . SESSION_MINUTES . ' minutes');

    $stmt = $pdo->prepare(
        "UPDATE guest_sessions SET active = 0, ended_at = ?, end_reason = 'reconnect'
          WHERE device_id = ? AND active = 1"
    );
    $stmt->execute(array($now, $deviceId));

    $stmt = $pdo->prepare(
        'INSERT INTO guest_sessions
            (mobile, device_id, mac_address, ip_address, user_agent, otp_id,
             token_hash, active, started_at, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
    );
    $stmt->execute(array($mobile, mb_substr($deviceId, 0, 128), $mac, gw_client_ip(),
        gw_user_agent(), $otpId, gw_hash_token($token), $now, $until));

    $stmt = $pdo->prepare('SELECT * FROM guest_sessions WHERE id = ?');
    $stmt->execute(array((int) $pdo->lastInsertId()));

    return array('token' => $token, 'session' => $stmt->fetch());
}

/**
 * The session this browser holds: cookie first, then a device-id lookup so
 * a cleared cookie does not hand out a second slot.
 */
function gw_current_session()
{
    $raw   = isset($_COOKIE[GW_SESSION_COOKIE]) ? (string) $_COOKIE[GW_SESSION_COOKIE] : '';
    $token = $raw === '' ? '' : gw_sign_unpack($raw);

    if ($token !== '') {
        $row = gw_session_by_token($token);
        if ($row && (int) $row['active'] === 1 && !gw_expired($row['expires_at'])) {
            return $row;
        }
    }

    return gw_session_active_for_device(gw_device_id());
}

function gw_session_public($row)
{
    $left = gw_seconds_left($row['expires_at']);

    return array(
        'mobile'       => gw_mask_mobile((string) $row['mobile']),
        'started_at'   => $row['started_at'],
        'expires_at'   => $row['expires_at'],
        'seconds_left' => $left,
        'minutes_left' => (int) ceil($left / 60),
    );
}

/* ================================================================== */
/* 7. SMS                                                              */
/* ================================================================== */

function gw_sms_message($otp)
{
    return strtr(SMS_TEMPLATE, array(
        '{otp}'     => $otp,
        '{app}'     => APP_NAME,
        '{org}'     => APP_ORG,
        '{minutes}' => (string) OTP_VALID_MINUTES,
    ));
}

/** National number with the country code attached, as gateways expect. */
function gw_msisdn($mobile)
{
    return MOBILE_COUNTRY_CODE . $mobile;
}

/**
 * @param string $secret value blanked out before the message is stored, so
 *                       a database dump never reveals a live OTP
 */
function gw_send_sms($mobile, $message, $secret = '')
{
    $driver = SMS_DRIVER;

    try {
        switch ($driver) {
            case 'log':
                // The template may not carry {otp} yet (awaiting DLT approval),
                // so always record the code - this driver is for testing only.
                $logged = ($secret !== '' && strpos($message, $secret) === false)
                    ? $message . ' [OTP: ' . $secret . ']' : $message;
                $result = gw_sms_driver_log($mobile, $logged);
                break;
            case 'http':
                $result = gw_sms_driver_http($mobile, $message);
                break;
            case 'msg91':
                $result = gw_sms_driver_msg91($mobile, $message);
                break;
            case 'twilio':
                $result = gw_sms_driver_twilio($mobile, $message);
                break;
            default:
                $result = array('ok' => false, 'http' => null, 'response' => '',
                                'error' => "Unknown SMS driver '$driver'");
        }
    } catch (Exception $e) {
        $result = array('ok' => false, 'http' => null, 'response' => '', 'error' => $e->getMessage());
    }

    $stored = $message;
    if ($secret !== '' && !($driver === 'log' && APP_DEBUG)) {
        $stored = str_replace($secret, str_repeat('*', strlen($secret)), $stored);
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO sms_log (mobile, driver, message, ok, http_code, response, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(array($mobile, $driver, mb_substr($stored, 0, 500),
            $result['ok'] ? 1 : 0, $result['http'],
            mb_substr(trim($result['response'] . ' ' . $result['error']), 0, 2000), gw_ts()));
    } catch (Exception $e) {
        gw_log('error.log', 'sms_log insert failed: ' . $e->getMessage());
    }

    return $result;
}

/** Testing driver: nothing leaves the machine, the OTP lands in a file. */
function gw_sms_driver_log($mobile, $message)
{
    gw_log('sms.log', gw_msisdn($mobile) . ' | ' . $message);

    return array('ok' => true, 'http' => 200, 'response' => 'logged to storage/sms.log', 'error' => '');
}

/** Generic HTTP gateway, driven entirely by SMS_HTTP_PARAMS. */
function gw_sms_driver_http($mobile, $message)
{
    $params = gw_config_json('SMS_HTTP_PARAMS');
    if (empty($params)) {
        return array('ok' => false, 'http' => null, 'response' => '', 'error' => 'SMS_HTTP_PARAMS is empty');
    }

    $replacements = array(
        '{mobile}'   => gw_msisdn($mobile),
        '{local}'    => $mobile,              // 10 digits, no country code
        '{message}'  => $message,
        '{sender}'   => SMS_SENDER_ID,
        '{username}' => SMS_USERNAME,
        '{password}' => SMS_PASSWORD,
    );

    foreach ($params as $key => $value) {
        $params[$key] = is_string($value) ? strtr($value, $replacements) : $value;
    }

    $method = strtoupper(SMS_HTTP_METHOD) === 'POST' ? 'POST' : 'GET';
    $url    = SMS_HTTP_URL;
    $verify = !defined('SMS_VERIFY_TLS') || SMS_VERIFY_TLS;
    $opts   = array(
        CURLOPT_SSL_VERIFYPEER => (bool) $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        // The site DNS is sometimes slow to resolve the gateway; give it room.
        CURLOPT_CONNECTTIMEOUT => 25,
        CURLOPT_TIMEOUT        => 40,
    );

    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    } else {
        $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($params);
    }

    $result = gw_http_request($url, $opts);

    // A lookup/connect failure means the request never reached the gateway,
    // so one retry cannot produce a duplicate SMS.
    if (!$result['ok'] && preg_match('/^(Resolving timed out|Could not resolve|Connection timed out|Failed to connect)/i', $result['error'])) {
        $result = gw_http_request($url, $opts);
    }

    // Some gateways answer HTTP 200 with a body that means "rejected".
    $markers = gw_config_json('SMS_SUCCESS_MARKERS');
    if ($result['ok'] && !empty($markers)) {
        $matched = false;
        foreach ($markers as $marker) {
            if (stripos($result['response'], (string) $marker) !== false) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $result['ok']    = false;
            $result['error'] = 'Gateway reply did not contain a success marker';
        }
    }

    return $result;
}

function gw_sms_driver_msg91($mobile, $message)
{
    if (MSG91_AUTHKEY === '') {
        return array('ok' => false, 'http' => null, 'response' => '', 'error' => 'MSG91_AUTHKEY is not set');
    }

    $payload = array(
        'sender'  => SMS_SENDER_ID,
        'route'   => '4',
        'country' => MOBILE_COUNTRY_CODE,
        'sms'     => array(array('message' => $message, 'to' => array(gw_msisdn($mobile)))),
    );
    if (MSG91_TEMPLATE_ID !== '') {
        $payload['DLT_TE_ID'] = MSG91_TEMPLATE_ID;
    }

    return gw_http_request('https://api.msg91.com/api/v2/sendsms', array(
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'authkey: ' . MSG91_AUTHKEY),
    ));
}

function gw_sms_driver_twilio($mobile, $message)
{
    if (TWILIO_SID === '' || TWILIO_TOKEN === '') {
        return array('ok' => false, 'http' => null, 'response' => '', 'error' => 'Twilio credentials are not set');
    }

    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode(TWILIO_SID) . '/Messages.json';

    return gw_http_request($url, array(
        CURLOPT_POST       => true,
        CURLOPT_USERPWD    => TWILIO_SID . ':' . TWILIO_TOKEN,
        CURLOPT_POSTFIELDS => http_build_query(array(
            'From' => TWILIO_FROM, 'To' => '+' . gw_msisdn($mobile), 'Body' => $message,
        )),
    ));
}

function gw_http_request($url, $options = array())
{
    // Old XAMPP PHP builds ship without a CA bundle, so verification fails
    // with "unable to get local issuer certificate" unless one is supplied.
    if (defined('CA_BUNDLE_FILE') && CA_BUNDLE_FILE !== '' && is_file(CA_BUNDLE_FILE)) {
        $options += array(CURLOPT_CAINFO => CA_BUNDLE_FILE);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $options + array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'GuestWifiPortal/1.0',
    ));

    $body  = curl_exec($ch);
    $error = curl_error($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return array(
        'ok'       => $error === '' && $code >= 200 && $code < 300,
        'http'     => $code,
        'response' => is_string($body) ? mb_substr($body, 0, 2000) : '',
        'error'    => $error,
    );
}

/* ================================================================== */
/* 8. OTP                                                              */
/* ================================================================== */

function gw_mobile_allowed($mobile)
{
    if (!REQUIRE_ALLOWED_LIST) {
        return true;
    }
    $stmt = db()->prepare('SELECT 1 FROM allowed_mobiles WHERE mobile = ? AND enabled = 1');
    $stmt->execute(array($mobile));

    return (bool) $stmt->fetchColumn();
}

function gw_otp_issue($mobile, $deviceId, $mac)
{
    if (!gw_valid_mobile($mobile)) {
        return gw_result(false, 'invalid_mobile', 'Enter a valid 10-digit mobile number.');
    }

    if (!gw_mobile_allowed($mobile)) {
        gw_history_add($mobile, $deviceId, false, 'not_allowed');
        return gw_result(false, 'not_allowed', 'This mobile number is not approved for Wi-Fi access.');
    }

    $pdo = db();

    // Already online on this very device - no need to spend another SMS.
    $existing = gw_session_active_for_device($deviceId);
    if ($existing) {
        return gw_result(false, 'already_connected', 'This device is already connected.',
            array('session' => gw_session_public($existing)));
    }

    // The number is online elsewhere. One OTP = one device, so a second
    // device is refused before any SMS is sent.
    $elsewhere = gw_sessions_active_for_mobile($mobile, $deviceId);
    if (count($elsewhere) >= MAX_ACTIVE_DEVICES && ON_DEVICE_LIMIT !== 'replace') {
        gw_history_add($mobile, $deviceId, false, 'device_limit');
        return gw_result(false, 'device_limit',
            'This mobile number is already connected on another device. '
            . 'Disconnect it first, or use a different number.');
    }

    if (gw_daily_success_count($mobile) >= MAX_DAILY_SUCCESS) {
        gw_history_add($mobile, $deviceId, false, 'daily_limit');
        return gw_result(false, 'daily_limit',
            'Daily login limit reached for this number. Please try again tomorrow.');
    }

    $stmt = $pdo->prepare('SELECT created_at FROM otp_requests WHERE mobile = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute(array($mobile));
    $last = $stmt->fetchColumn();
    if ($last) {
        $wait = RESEND_COOLDOWN_SEC - (time() - strtotime((string) $last));
        if ($wait > 0) {
            return gw_result(false, 'cooldown',
                "Please wait {$wait} seconds before requesting another OTP.",
                array('retry_after' => $wait));
        }
    }

    // Sends that never left (gateway down) do not use up the guest's quota.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM otp_requests WHERE mobile = ? AND created_at >= ? AND status <> 'send_failed'");
    $stmt->execute(array($mobile, gw_today()));
    if ((int) $stmt->fetchColumn() >= MAX_OTP_PER_DAY) {
        gw_history_add($mobile, $deviceId, false, 'otp_daily_limit');
        return gw_result(false, 'otp_daily_limit',
            'Too many OTP requests for this number today. Please try again tomorrow.');
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM otp_requests WHERE ip_address = ? AND created_at >= ?');
    $stmt->execute(array(gw_client_ip(), gw_ts('-1 hour')));
    if ((int) $stmt->fetchColumn() >= MAX_OTP_PER_IP_HOUR) {
        return gw_result(false, 'ip_limit', 'Too many requests from this connection. Please try again later.');
    }

    // Any earlier pending code stops being valid now, so only the newest
    // SMS can ever be used.
    $stmt = $pdo->prepare("UPDATE otp_requests SET used = 1, status = 'superseded' WHERE mobile = ? AND used = 0");
    $stmt->execute(array($mobile));

    $max = pow(10, OTP_LENGTH) - 1;
    $otp = str_pad((string) random_int(0, $max), OTP_LENGTH, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare(
        'INSERT INTO otp_requests
            (mobile, otp_hash, device_id, mac_address, ip_address, user_agent,
             attempts, used, status, expires_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?)'
    );
    $stmt->execute(array($mobile, password_hash($otp, PASSWORD_DEFAULT),
        mb_substr($deviceId, 0, 128), $mac, gw_client_ip(), gw_user_agent(),
        'pending', gw_ts('+' . OTP_VALID_MINUTES . ' minutes'), gw_ts()));

    $otpId = (int) $pdo->lastInsertId();
    $sms   = gw_send_sms($mobile, gw_sms_message($otp), $otp);

    if (!$sms['ok']) {
        // Do not leave a live code behind for a message that never left.
        $stmt = $pdo->prepare("UPDATE otp_requests SET used = 1, status = 'send_failed' WHERE id = ?");
        $stmt->execute(array($otpId));

        gw_log('error.log', 'SMS send failed for ' . gw_mask_mobile($mobile) . ': '
            . $sms['error'] . ' ' . $sms['response']);
        gw_history_add($mobile, $deviceId, false, 'sms_failed');

        return gw_result(false, 'sms_failed',
            'We could not send the OTP right now. Please try again in a moment.',
            APP_DEBUG ? array('debug' => $sms) : array());
    }

    return gw_result(true, 'otp_sent', 'OTP sent to ' . gw_mask_mobile($mobile) . '.', array(
        'otp_id'        => $otpId,
        'masked_mobile' => gw_mask_mobile($mobile),
        'expires_in'    => OTP_VALID_MINUTES * 60,
        'resend_after'  => RESEND_COOLDOWN_SEC,
        'otp_length'    => OTP_LENGTH,
    ));
}

function gw_otp_verify($mobile, $otp, $deviceId)
{
    if (!gw_valid_mobile($mobile)) {
        return gw_result(false, 'invalid_mobile', 'Enter a valid 10-digit mobile number.');
    }
    if (!preg_match('/^[0-9]{' . OTP_LENGTH . '}$/', $otp)) {
        return gw_result(false, 'invalid_format', 'Enter the ' . OTP_LENGTH . '-digit OTP from your SMS.');
    }

    $pdo  = db();
    $stmt = $pdo->prepare(
        "SELECT * FROM otp_requests WHERE mobile = ? AND used = 0 AND status = 'pending'
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(array($mobile));
    $row = $stmt->fetch();

    if (!$row) {
        gw_history_add($mobile, $deviceId, false, 'no_pending_otp');
        return gw_result(false, 'no_pending_otp', 'This OTP is no longer valid. Please request a new one.');
    }

    if (gw_expired($row['expires_at'])) {
        $stmt = $pdo->prepare("UPDATE otp_requests SET used = 1, status = 'expired' WHERE id = ?");
        $stmt->execute(array($row['id']));
        gw_history_add($mobile, $deviceId, false, 'otp_expired');

        return gw_result(false, 'otp_expired', 'Your OTP has expired. Please request a new one.');
    }

    // The device gate runs before the code is compared, so a wrong device
    // learns nothing about whether the digits were right - and cannot burn
    // the attempt budget of the phone that legitimately asked for the code.
    if (BIND_OTP_TO_DEVICE && !hash_equals((string) $row['device_id'], $deviceId)) {
        gw_history_add($mobile, $deviceId, false, 'device_mismatch');
        gw_log('security.log', 'OTP replay attempt: otp_id=' . $row['id']
            . ' issued_to=' . $row['device_id'] . ' tried_by=' . $deviceId . ' ip=' . gw_client_ip());

        return gw_result(false, 'device_mismatch',
            'This OTP belongs to the device that requested it. Please request an OTP on this device.');
    }

    if ((int) $row['attempts'] >= MAX_OTP_ATTEMPTS) {
        $stmt = $pdo->prepare("UPDATE otp_requests SET used = 1, status = 'locked' WHERE id = ?");
        $stmt->execute(array($row['id']));
        gw_history_add($mobile, $deviceId, false, 'too_many_attempts');

        return gw_result(false, 'too_many_attempts', 'Too many incorrect attempts. Please request a new OTP.');
    }

    if (!password_verify($otp, (string) $row['otp_hash'])) {
        $stmt = $pdo->prepare('UPDATE otp_requests SET attempts = attempts + 1 WHERE id = ?');
        $stmt->execute(array($row['id']));

        $left = max(0, MAX_OTP_ATTEMPTS - ((int) $row['attempts'] + 1));
        gw_history_add($mobile, $deviceId, false, 'invalid_otp');

        if ($left === 0) {
            $stmt = $pdo->prepare("UPDATE otp_requests SET used = 1, status = 'locked' WHERE id = ?");
            $stmt->execute(array($row['id']));

            return gw_result(false, 'too_many_attempts', 'Too many incorrect attempts. Please request a new OTP.');
        }

        return gw_result(false, 'invalid_otp',
            'Incorrect OTP. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' remaining.',
            array('attempts_left' => $left));
    }

    // Re-check the limits that may have been reached while this OTP was in
    // flight (e.g. the same number logged in elsewhere 30 seconds ago).
    if (gw_daily_success_count($mobile) >= MAX_DAILY_SUCCESS) {
        gw_history_add($mobile, $deviceId, false, 'daily_limit');
        return gw_result(false, 'daily_limit', 'Daily login limit reached for this number.');
    }

    $elsewhere = gw_sessions_active_for_mobile($mobile, $deviceId);
    if (count($elsewhere) >= MAX_ACTIVE_DEVICES) {
        if (ON_DEVICE_LIMIT === 'replace') {
            $drop = array_slice($elsewhere, 0, count($elsewhere) - MAX_ACTIVE_DEVICES + 1);
            foreach ($drop as $old) {
                gw_session_end((int) $old['id'], 'replaced');
            }
        } else {
            gw_history_add($mobile, $deviceId, false, 'device_limit');
            return gw_result(false, 'device_limit', 'This mobile number is already connected on another device.');
        }
    }

    // Single-use claim. The WHERE used = 0 is what makes two simultaneous
    // verifications impossible - only one of them can affect a row.
    $stmt = $pdo->prepare(
        "UPDATE otp_requests SET used = 1, status = 'verified', verified_at = ? WHERE id = ? AND used = 0"
    );
    $stmt->execute(array(gw_ts(), $row['id']));

    if ($stmt->rowCount() !== 1) {
        gw_history_add($mobile, $deviceId, false, 'otp_already_used');
        return gw_result(false, 'otp_already_used', 'This OTP has already been used.');
    }

    $mac     = $row['mac_address'] ? $row['mac_address'] : null;
    $created = gw_session_create($mobile, $deviceId, $mac, (int) $row['id']);
    gw_history_add($mobile, $deviceId, true, 'login_ok');

    return gw_result(true, 'verified', 'Verified. You are now connected.', array(
        'token'   => $created['token'],
        'session' => gw_session_public($created['session']),
    ));
}

/* ================================================================== */
/* 9. Shared styling for install.php and admin.php                     */
/* ================================================================== */

/**
 * The guest portal carries its own inlined CSS (it has to be a single
 * file for the firewall), so this only dresses the two admin pages.
 * Kept here rather than in a stylesheet to keep the deployment to a
 * handful of files.
 */
function gw_page_css()
{
    return '<style>
:root{--brand:#0095d9;--dark:#0077b0;--ink:#05496b;--bg:#eef4f8;--surface:#fff;--text:#16242e;
--muted:#5d7182;--border:#d8e3ec;--ok:#0f8a4d;--okbg:#e6f6ed;--warn:#9a6400;--warnbg:#fdf3e0;
--err:#c0392b;--errbg:#fdecea;--shadow:0 10px 30px rgba(11,61,88,.12)}
*{box-sizing:border-box}
body{margin:0;padding:26px 16px;font-family:"Segoe UI",Roboto,Arial,sans-serif;background:var(--bg);
color:var(--text);line-height:1.5}
.panel{max-width:820px;margin:0 auto;background:var(--surface);border-radius:14px;
box-shadow:var(--shadow);padding:24px 26px 28px}
.shell{max-width:1120px;margin:0 auto}
h1{margin:0 0 4px;font-size:1.3rem}h2{font-size:1rem;margin:22px 0 10px}
.top{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}
.top h1{margin:0}
.alert{padding:11px 14px;border-radius:10px;font-size:.88rem;margin-bottom:16px;border:1px solid transparent}
.alert-ok{background:var(--okbg);color:var(--ok);border-color:#bfe6cf}
.alert-warn{background:var(--warnbg);color:var(--warn);border-color:#f0dcb4}
.alert-error{background:var(--errbg);color:var(--err);border-color:#f5c6c0}
.list{list-style:none;padding:0;margin:0}
.list li{padding:7px 0 7px 26px;position:relative;font-size:.87rem;border-bottom:1px solid var(--border)}
.list li:last-child{border-bottom:0}
.list li:before{position:absolute;left:2px;font-weight:700}
.list li.ok:before{content:"\2713";color:var(--ok)}
.list li.warn:before{content:"!";color:var(--warn)}
.btn{display:inline-block;padding:10px 18px;border:0;border-radius:9px;background:var(--brand);
color:#fff;font-size:.95rem;font-weight:600;font-family:inherit;cursor:pointer;text-decoration:none}
.btn:hover{background:var(--dark)}
.btn-ghost{background:transparent;color:var(--dark);border:1.5px solid var(--border)}
.btn-ghost:hover{background:#f2f9fd}
.btn-link{background:0 0;border:0;color:var(--dark);font:inherit;font-size:.84rem;
cursor:pointer;padding:3px;text-decoration:underline}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:22px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px}
.stat{background:var(--surface);border-radius:12px;padding:15px 18px;box-shadow:var(--shadow)}
.stat b{display:block;font-size:1.7rem;color:var(--ink)}
.stat span{font-size:.75rem;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.tbl{background:var(--surface);border-radius:12px;box-shadow:var(--shadow);overflow-x:auto;margin-bottom:24px}
.tbl h2{margin:0;padding:15px 18px 11px;font-size:.97rem;border-bottom:1px solid var(--border)}
table{width:100%;border-collapse:collapse;font-size:.83rem}
th,td{text-align:left;padding:10px 14px;border-bottom:1px solid var(--border);white-space:nowrap}
th{color:var(--muted);font-weight:600;font-size:.74rem;text-transform:uppercase;letter-spacing:.4px}
tbody tr:last-child td{border-bottom:0}
tbody tr:hover{background:#f7fbfe}
.pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:.72rem;font-weight:600}
.pill-ok{background:var(--okbg);color:var(--ok)}
.pill-off{background:#eef1f4;color:var(--muted)}
.pill-error{background:var(--errbg);color:var(--err)}
.pill-warn{background:var(--warnbg);color:var(--warn)}
.form{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:14px 18px}
input[type=text],input[type=tel],input[type=password]{border:1.5px solid var(--border);
border-radius:8px;padding:10px 12px;font-size:.92rem;font-family:inherit}
input:focus{outline:0;border-color:var(--brand);box-shadow:0 0 0 3px rgba(0,149,217,.16)}
label{display:block;font-size:.8rem;font-weight:600;color:var(--muted);margin:12px 0 6px}
.login{max-width:350px;margin:9vh auto 0}
.login input{width:100%}.login .btn{width:100%;margin-top:16px}
.muted{color:var(--muted);font-size:.83rem}
code{background:#eef4f8;padding:1px 6px;border-radius:5px;font-size:.86em}
</style>';
}
