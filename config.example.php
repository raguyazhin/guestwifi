<?php
/**
 * Guest Wi-Fi OTP Portal - configuration
 * ------------------------------------------------------------------
 * Everything the portal needs in order to be re-pointed at a different
 * SMS gateway, firewall or policy lives in this file. No other file
 * should need editing for a normal deployment.
 */

/* ---------------------------------------------------------------- */
/* Database (XAMPP defaults)                                         */
/* ---------------------------------------------------------------- */
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'guestwifi');
define('DB_USER', 'root');
define('DB_PASS', '');

/* ---------------------------------------------------------------- */
/* Application                                                       */
/* ---------------------------------------------------------------- */
// Signs device cookies and session tokens. Regenerate for production:
//   php -r "echo bin2hex(random_bytes(32));"
define('APP_SECRET', 'PUT_A_64_CHARACTER_RANDOM_HEX_STRING_HERE');

define('APP_NAME', 'Guest Wi-Fi');
define('APP_ORG',  'Sankara Nethralaya');

// true = detailed errors + the /dev/inbox.php OTP viewer. Set false in production.
define('APP_DEBUG', true);

/* ---------------------------------------------------------------- */
/* OTP policy                                                        */
/* ---------------------------------------------------------------- */
define('OTP_LENGTH',           6);
define('OTP_VALID_MINUTES',    5);   // OTP lifetime
define('MAX_OTP_ATTEMPTS',     5);   // wrong entries before the OTP is burned
define('RESEND_COOLDOWN_SEC', 60);   // minimum seconds between two OTP requests
define('MAX_OTP_PER_DAY',      5);   // OTP sends per mobile per day
define('MAX_OTP_PER_IP_HOUR', 20);   // OTP sends per source IP per hour

/* ---------------------------------------------------------------- */
/* Access policy                                                     */
/* ---------------------------------------------------------------- */
// One OTP unlocks exactly one device, and only the device that asked for
// it. Leave this true - it is the core rule of the portal.
define('BIND_OTP_TO_DEVICE', true);

define('MAX_ACTIVE_DEVICES', 1);     // concurrent devices per mobile number
// What happens when a NEW device hits that limit:
//   'reject'  -> refuse, tell the user to disconnect the other device
//   'replace' -> log the older device out and let the new one in
define('ON_DEVICE_LIMIT', 'reject');

define('MAX_DAILY_SUCCESS', 3);      // successful logins per mobile per day
define('SESSION_MINUTES', 120);      // how long internet access lasts

// true  = only numbers listed in `allowed_mobiles` may log in (staff portal)
// false = any valid mobile number may log in (open guest portal)
define('REQUIRE_ALLOWED_LIST', false);

// Accepted mobile format. Default: Indian 10-digit starting 6-9.
define('MOBILE_REGEX', '/^[6-9][0-9]{9}$/');
define('MOBILE_COUNTRY_CODE', '91');  // prefixed when handing the number to the gateway

/* ---------------------------------------------------------------- */
/* SMS gateway                                                       */
/* ---------------------------------------------------------------- */
// 'log'    -> writes to storage/sms.log, nothing is sent (TESTING)
// 'http'   -> generic HTTP gateway, see SMS_HTTP_* below
// 'msg91'
// 'twilio'
define('SMS_DRIVER', 'http');

define('SMS_SENDER_ID', 'SNALRT');
// India's DLT rules: this exact wording must be registered against the
// SNALRT header, or the gateway accepts the call and silently drops the
// message. The network-alert template already registered for SNALRT does
// not cover OTP text - register this one before go-live.
define('SMS_TEMPLATE', 'Your {app} OTP is {otp}. Valid for {minutes} minutes. Do not share it with anyone.');

// --- driver: http (timesapi, same call the network-alert app makes) ---
define('SMS_HTTP_URL',    'https://sms.timesapi.in/api/v1/send');
define('SMS_HTTP_METHOD', 'GET');     // GET or POST
define('SMS_USERNAME',    'YOUR_SMS_USERNAME');
define('SMS_PASSWORD',    'YOUR_SMS_PASSWORD');

// The network-alert app sets CURLOPT_SSL_VERIFYPEER=false, which usually
// means that server has no CA bundle configured (common on XAMPP/PHP 5.6).
// Leave this true where it works - turning it off lets anyone on the path
// impersonate the gateway and read every OTP in transit. install.php tests
// it and tells you which way to set it.
define('SMS_VERIFY_TLS', true);
// Request parameters. {mobile} {message} {sender} {username} {password}
// are substituted at send time. Rename keys to match your gateway's docs.
define('SMS_HTTP_PARAMS', json_encode([
    'username' => '{username}',
    'password' => '{password}',
    'unicode'  => 'false',
    'from'     => '{sender}',
    'to'       => '{mobile}',
    'text'     => '{message}',
]));
// Substrings in the gateway reply that mean "accepted".
// Empty list = trust the HTTP 2xx status alone.
define('SMS_SUCCESS_MARKERS', json_encode([]));

// --- driver: msg91 ---
define('MSG91_AUTHKEY',     '');
define('MSG91_TEMPLATE_ID', '');

// --- driver: twilio ---
define('TWILIO_SID',   '');
define('TWILIO_TOKEN', '');
define('TWILIO_FROM',  '');

/* ---------------------------------------------------------------- */
/* Captive portal handoff (FortiGate)                                */
/* ---------------------------------------------------------------- */
// 'none'      -> verify only, show a success screen (local testing)
// 'form_post' -> browser posts credentials back to the FortiGate URL that
//                redirected it here (standard FortiOS external-portal flow)
// 'api'       -> provision a one-time local user over the FortiOS REST API
//                first, then do the same form post
define('FORTIGATE_MODE', 'none');

// Credentials posted to FortiGate in 'form_post' mode.
// 'shared' -> every guest authenticates with the single firewall account
//             below; this portal, not FortiOS, is the real gatekeeper.
// 'mobile' -> username = mobile number, password = generated token.
//             Needs FORTIGATE_MODE='api' or a RADIUS backend that knows them.
define('FORTIGATE_CRED_MODE',   'shared');
define('FORTIGATE_SHARED_USER', 'guestwifi');
define('FORTIGATE_SHARED_PASS', 'CHANGE_ME');

// Fallback POST endpoint, used only when the firewall did not send one.
// Typically https://<fortigate-ip>:1003/
define('FORTIGATE_POST_URL', '');
// Hosts this portal is willing to post credentials to. Stops an attacker
// crafting ?post=https://evil.tld and harvesting the firewall password.
define('FORTIGATE_ALLOWED_HOSTS', json_encode([]));  // e.g. ['192.168.1.99','fw.example.com']

// Where to send the browser after login when the firewall did not supply
// the originally requested URL.
define('DEFAULT_LANDING_URL', 'https://www.google.com');

// Origins allowed to call api.php with cookies. Needed only once index.html
// is served from somewhere other than this server (e.g. the FortiGate's own
// replacement-message page). Same-origin hosting needs no entries.
define('PORTAL_ALLOWED_ORIGINS', json_encode([]));  // e.g. ['https://192.168.1.99:1003']

// --- FORTIGATE_MODE='api' only ---
define('FORTIGATE_API_URL',        '');   // https://192.168.1.99
define('FORTIGATE_API_TOKEN',      '');
define('FORTIGATE_API_VDOM',       'root');
define('FORTIGATE_USER_GROUP',     'Guest-Users');
define('FORTIGATE_API_VERIFY_TLS', false);  // true once the firewall has a trusted cert

/* ---------------------------------------------------------------- */
/* Admin dashboard (/admin)                                          */
/* ---------------------------------------------------------------- */
define('ADMIN_USER', 'admin');
// Default password is "admin123" - change it before going live:
//   php -r "echo password_hash('new-password', PASSWORD_DEFAULT);"
define('ADMIN_PASS_HASH', 'PUT_A_PASSWORD_HASH_HERE');

/* ---------------------------------------------------------------- */
define('TIMEZONE', 'Asia/Kolkata');
