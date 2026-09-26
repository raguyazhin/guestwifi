<?php
/**
 * Captive-portal context and device identity.
 *
 * When a FortiGate intercepts an unauthenticated client it redirects the
 * browser here with the details of that client attached to the query
 * string. Those values decide *which device* the OTP will be locked to,
 * so they are captured once on landing, sanitised, and kept server-side
 * in the PHP session - never re-read from whatever the client posts later.
 */

require_once __DIR__ . '/util.php';

const GW_DEVICE_COOKIE = 'gw_device';
const GW_SESSION_COOKIE = 'gw_session';

function gw_portal_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => gw_base_path(),
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_name('gw_portal');
    session_start();
}

/**
 * Reads the firewall's redirect parameters and stores them for the rest of
 * the visit. Called on landing and again on each API hit, so a client that
 * reloads mid-flow does not lose its context.
 *
 * @param array $src usually $_GET on landing, or the JSON body from app.js
 */
function gw_capture_portal_context(array $src): array
{
    gw_portal_start();

    $context = $_SESSION['portal'] ?? [
        'magic'    => '',
        'mac'      => '',
        'user_ip'  => '',
        'post_url' => '',
        'redirect' => '',
        'ssid'     => '',
        'ap_mac'   => '',
    ];

    // FortiOS names first, then the aliases other vendors use.
    $map = [
        'magic'    => ['magic'],
        'mac'      => ['usermac', 'client_mac', 'mac', 'id'],
        'user_ip'  => ['userip', 'client_ip', 'uamip'],
        'post_url' => ['post', 'login_url', 'link-login-only', 'link-login'],
        'redirect' => ['4Tredir', 'redir', 'url', 'link-orig'],
        'ssid'     => ['ssid'],
        'ap_mac'   => ['apmac', 'ap_mac', 'bssid'],
    ];

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
            break;   // first alias that carried a usable value wins
        }
    }

    $_SESSION['portal'] = $context;

    return $context;
}

function gw_portal_context(): array
{
    gw_portal_start();

    return $_SESSION['portal'] ?? [
        'magic'    => '',
        'mac'      => '',
        'user_ip'  => '',
        'post_url' => '',
        'redirect' => '',
        'ssid'     => '',
        'ap_mac'   => '',
    ];
}

/**
 * Stable identity for the device in front of the portal.
 *
 * The firewall-supplied MAC is preferred: it survives a browser restart or
 * a switch to private browsing, so a guest cannot obtain a second OTP by
 * clearing cookies. Without a MAC (local testing, or a firewall that does
 * not pass one) we fall back to a signed cookie, which is an application
 * identifier - not proof of hardware identity.
 */
function gw_device_id(): string
{
    $context = gw_portal_context();

    if ($context['mac'] !== '') {
        return 'mac:' . $context['mac'];
    }

    return 'br:' . gw_browser_device_id();
}

function gw_device_mac(): ?string
{
    $mac = gw_portal_context()['mac'];

    return $mac === '' ? null : $mac;
}

/** True when the device id came from the firewall rather than a cookie. */
function gw_device_is_hardware(): bool
{
    return gw_portal_context()['mac'] !== '';
}

function gw_browser_device_id(): string
{
    gw_portal_start();

    if (!empty($_SESSION['device_id'])) {
        return $_SESSION['device_id'];
    }

    $cookie = isset($_COOKIE[GW_DEVICE_COOKIE]) ? gw_sign_unpack((string) $_COOKIE[GW_DEVICE_COOKIE]) : '';
    if (!preg_match('/^[a-f0-9]{32}$/', $cookie)) {
        $cookie = gw_random_token(16);
        gw_set_cookie(GW_DEVICE_COOKIE, gw_sign_pack($cookie), 86400 * 365);
    }

    $_SESSION['device_id'] = $cookie;

    return $cookie;
}

/**
 * Last-resort identity for a page served from another origin (the firewall
 * hosts index.html, so our cookie never reaches it). Only consulted when
 * the firewall supplied no MAC. It is no weaker than a cookie the guest
 * could clear anyway, but it is not proof of hardware identity - deploy
 * with the firewall passing `usermac` for the real guarantee.
 */
function gw_adopt_client_device_id(string $id): void
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

/* ---------------------------------------------------------------- */
/* FortiGate handoff                                                 */
/* ---------------------------------------------------------------- */

/**
 * Where the browser should POST the firewall credentials. Returns '' when
 * the target is missing or not on the allow-list.
 */
function gw_fortigate_post_url(array $context): string
{
    $url = $context['post_url'] !== '' ? $context['post_url'] : FORTIGATE_POST_URL;
    if ($url === '') {
        return '';
    }

    $parts = parse_url($url);
    if (!$parts || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
        return '';
    }

    // Without this check a crafted ?post=https://evil.tld would make the
    // portal hand the firewall password to an attacker's server.
    $allowed = gw_config_json('FORTIGATE_ALLOWED_HOSTS');
    if ($allowed !== [] && !in_array($parts['host'], $allowed, true)) {
        gw_log('error.log', 'Blocked FortiGate post to unlisted host: ' . $parts['host']);
        return '';
    }

    return $url;
}

/**
 * Builds the instruction the browser follows after a verified OTP.
 *
 * @return array{action:string,url:string,fields:array<string,string>}
 */
function gw_fortigate_handoff(string $mobile, string $sessionToken): array
{
    $context = gw_portal_context();
    $landing = $context['redirect'] !== '' ? $context['redirect'] : DEFAULT_LANDING_URL;

    if (FORTIGATE_MODE === 'none') {
        return ['action' => 'none', 'url' => $landing, 'fields' => []];
    }

    $postUrl = gw_fortigate_post_url($context);
    if ($postUrl === '' || $context['magic'] === '') {
        // Nothing to post to - most likely the portal was opened directly
        // instead of through a firewall redirect.
        gw_log('error.log', 'FortiGate handoff skipped: post_url or magic missing');
        return ['action' => 'none', 'url' => $landing, 'fields' => []];
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

    return [
        'action' => 'form_post',
        'url'    => $postUrl,
        'fields' => [
            'magic'    => $context['magic'],
            'username' => $username,
            'password' => $password,
            '4Tredir'  => $landing,
        ],
    ];
}

/**
 * Creates (or refreshes) a local user on the FortiGate and puts it in the
 * guest group. Endpoint paths vary between FortiOS versions - verify
 * against your firewall's API browser before enabling FORTIGATE_MODE='api'.
 */
function gw_fortigate_provision_user(string $username, string $password): array
{
    require_once __DIR__ . '/sms.php';   // gw_http_request()

    if (FORTIGATE_API_URL === '' || FORTIGATE_API_TOKEN === '') {
        return ['ok' => false, 'error' => 'FortiGate API URL or token not configured'];
    }

    $base = rtrim(FORTIGATE_API_URL, '/') . '/api/v2/cmdb/user/local';
    $auth = [
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . FORTIGATE_API_TOKEN,
        ],
        CURLOPT_SSL_VERIFYPEER => (bool) FORTIGATE_API_VERIFY_TLS,
        CURLOPT_SSL_VERIFYHOST => FORTIGATE_API_VERIFY_TLS ? 2 : 0,
    ];
    $query = '?vdom=' . rawurlencode(FORTIGATE_API_VDOM);

    $body = json_encode([
        'name'   => $username,
        'type'   => 'password',
        'passwd' => $password,
        'status' => 'enable',
    ]);

    $create = gw_http_request($base . $query, $auth + [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => $body,
    ]);

    if (!$create['ok']) {
        // Already there: update the password instead of creating a duplicate.
        $update = gw_http_request($base . '/' . rawurlencode($username) . $query, $auth + [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS    => $body,
        ]);
        if (!$update['ok']) {
            return ['ok' => false, 'error' => 'create: ' . $create['response'] . ' | update: ' . $update['response']];
        }
    }

    return ['ok' => true, 'error' => ''];
}
