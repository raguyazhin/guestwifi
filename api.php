<?php
/**
 * Portal API.
 *
 *   POST api.php?action=init        capture the firewall's redirect params
 *   POST api.php?action=send_otp    { mobile }
 *   POST api.php?action=verify_otp  { mobile, otp }
 *   GET  api.php?action=status
 *   POST api.php?action=logout
 *
 * Every response is JSON: { success, message, code, ... }
 */

require_once dirname(__FILE__) . '/portal.php';

gw_portal_start();

/* --- CORS: only for origins named in config (the firewall-hosted page) -- */
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if ($origin !== '' && in_array($origin, gw_config_json('PORTAL_ALLOWED_ORIGINS'), true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Vary: Origin');
}
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$input  = gw_input();
$action = isset($_GET['action']) ? $_GET['action'] : gw_field($input, 'action', 32);

// The firewall's parameters arrive on the landing URL and the page forwards
// them on every call, so a mid-flow reload does not lose the context.
gw_capture_portal_context($_GET + $input);

if (!gw_device_is_hardware()) {
    $claimed = gw_field($input, 'device_id', 64);
    if (preg_match('/^[a-f0-9]{32}$/', $claimed)) {
        gw_adopt_client_device_id($claimed);
    }
}

try {
    gw_expire_stale();
} catch (Exception $e) {
    gw_fail(
        APP_DEBUG
            ? 'Database not ready: ' . $e->getMessage()
            : 'The portal is not available right now. Please contact the helpdesk.',
        'setup_required',
        array('setup_url' => 'install.php'),
        503
    );
}

$deviceId = gw_device_id();
$mac      = gw_device_mac();

try {
    switch ($action) {

        /* ---------------------------------------------------------- */
        case 'init':
            $session = gw_current_session();

            gw_ok('ready', array(
                'app'          => APP_NAME,
                'org'          => APP_ORG,
                'otp_length'   => OTP_LENGTH,
                'otp_minutes'  => OTP_VALID_MINUTES,
                'resend_after' => RESEND_COOLDOWN_SEC,
                'session_mins' => SESSION_MINUTES,
                'device_bound' => gw_device_is_hardware(),
                'ssid'         => gw_portal_context()['ssid'],
                'connected'    => $session !== null,
                'session'      => $session ? gw_session_public($session) : null,
            ));
            break;

        /* ---------------------------------------------------------- */
        case 'send_otp':
            $mobile = gw_normalize_mobile(gw_field($input, 'mobile', 20));
            $result = gw_otp_issue($mobile, $deviceId, $mac);

            if (!$result['ok']) {
                gw_fail($result['message'], $result['code'], $result['data']);
            }
            gw_ok($result['message'], $result['data']);
            break;

        /* ---------------------------------------------------------- */
        case 'verify_otp':
            $mobile = gw_normalize_mobile(gw_field($input, 'mobile', 20));
            $otp    = preg_replace('/\D/', '', gw_field($input, 'otp', 16));
            $result = gw_otp_verify($mobile, $otp, $deviceId);

            if (!$result['ok']) {
                gw_fail($result['message'], $result['code'], $result['data']);
            }

            $token = $result['data']['token'];
            gw_set_cookie(GW_SESSION_COOKIE, gw_sign_pack($token), SESSION_MINUTES * 60);

            gw_ok($result['message'], array(
                'authenticated' => true,
                'token'         => $token,
                'session'       => $result['data']['session'],
                'handoff'       => gw_fortigate_handoff($mobile, $token),
            ));
            break;

        /* ---------------------------------------------------------- */
        case 'status':
            $session = gw_current_session();

            if (!$session) {
                gw_ok('Not connected.', array('connected' => false, 'session' => null));
            }
            gw_ok('Connected.', array('connected' => true, 'session' => gw_session_public($session)));
            break;

        /* ---------------------------------------------------------- */
        case 'logout':
            $session = gw_current_session();

            if ($session) {
                gw_session_end((int) $session['id'], 'user_logout');
            }
            gw_set_cookie(GW_SESSION_COOKIE, '', -3600);

            gw_ok('You have been disconnected.', array('connected' => false));
            break;

        /* ---------------------------------------------------------- */
        default:
            gw_fail('Unknown API action.', 'unknown_action', array(), 400);
    }
} catch (Exception $e) {
    gw_log('error.log', $action . ' failed: ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());

    gw_fail(
        APP_DEBUG ? $e->getMessage() : 'Something went wrong. Please try again.',
        'server_error',
        array(),
        500
    );
}
