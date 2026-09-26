<?php
/**
 * Pluggable SMS delivery.
 *
 * Every driver returns the same shape:
 *   ['ok' => bool, 'http' => int|null, 'response' => string, 'error' => string]
 *
 * Swapping gateway is a config change (SMS_DRIVER + the SMS_HTTP_* block);
 * nothing in the OTP logic knows which gateway is in use.
 */

require_once __DIR__ . '/db.php';

function gw_sms_message(string $otp): string
{
    return strtr(SMS_TEMPLATE, [
        '{otp}'     => $otp,
        '{app}'     => APP_NAME,
        '{org}'     => APP_ORG,
        '{minutes}' => (string) OTP_VALID_MINUTES,
    ]);
}

/** National number with the country code attached, as gateways expect. */
function gw_msisdn(string $mobile): string
{
    return MOBILE_COUNTRY_CODE . $mobile;
}

/**
 * @param string $secret value to blank out before the message is stored,
 *                       so a database dump never reveals a live OTP
 */
function gw_send_sms(string $mobile, string $message, string $secret = ''): array
{
    $driver = SMS_DRIVER;

    try {
        $result = match ($driver) {
            'log'    => gw_sms_driver_log($mobile, $message),
            'http'   => gw_sms_driver_http($mobile, $message),
            'msg91'  => gw_sms_driver_msg91($mobile, $message),
            'twilio' => gw_sms_driver_twilio($mobile, $message),
            default  => ['ok' => false, 'http' => null, 'response' => '', 'error' => "Unknown SMS driver '$driver'"],
        };
    } catch (Throwable $e) {
        $result = ['ok' => false, 'http' => null, 'response' => '', 'error' => $e->getMessage()];
    }

    // Keep an auditable record, but never a readable OTP outside debug mode.
    $stored = $message;
    if ($secret !== '' && !($driver === 'log' && APP_DEBUG)) {
        $stored = str_replace($secret, str_repeat('*', strlen($secret)), $stored);
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO sms_log (mobile, driver, message, ok, http_code, response, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $mobile,
            $driver,
            mb_substr($stored, 0, 500),
            $result['ok'] ? 1 : 0,
            $result['http'],
            mb_substr(trim($result['response'] . ' ' . $result['error']), 0, 2000),
            gw_ts(),
        ]);
    } catch (Throwable $e) {
        gw_log('error.log', 'sms_log insert failed: ' . $e->getMessage());
    }

    return $result;
}

/* ---------------------------------------------------------------- */
/* Drivers                                                           */
/* ---------------------------------------------------------------- */

/** Testing driver: nothing leaves the machine, the OTP lands in a file. */
function gw_sms_driver_log(string $mobile, string $message): array
{
    gw_log('sms.log', gw_msisdn($mobile) . ' | ' . $message);

    return ['ok' => true, 'http' => 200, 'response' => 'logged to storage/sms.log', 'error' => ''];
}

/** Generic HTTP gateway driven entirely by SMS_HTTP_PARAMS. */
function gw_sms_driver_http(string $mobile, string $message): array
{
    $params = gw_config_json('SMS_HTTP_PARAMS');
    if ($params === []) {
        return ['ok' => false, 'http' => null, 'response' => '', 'error' => 'SMS_HTTP_PARAMS is empty'];
    }

    $replacements = [
        '{mobile}'   => gw_msisdn($mobile),
        '{message}'  => $message,
        '{sender}'   => SMS_SENDER_ID,
        '{username}' => SMS_USERNAME,
        '{password}' => SMS_PASSWORD,
    ];

    foreach ($params as $key => $value) {
        $params[$key] = is_string($value) ? strtr($value, $replacements) : $value;
    }

    $method = strtoupper(SMS_HTTP_METHOD) === 'POST' ? 'POST' : 'GET';
    $url    = SMS_HTTP_URL;
    $opts   = [];

    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    } else {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    }

    $result = gw_http_request($url, $opts);

    // Some gateways answer HTTP 200 with a body that means "rejected".
    $markers = gw_config_json('SMS_SUCCESS_MARKERS');
    if ($result['ok'] && $markers !== []) {
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

function gw_sms_driver_msg91(string $mobile, string $message): array
{
    if (MSG91_AUTHKEY === '') {
        return ['ok' => false, 'http' => null, 'response' => '', 'error' => 'MSG91_AUTHKEY is not set'];
    }

    $payload = [
        'sender'  => SMS_SENDER_ID,
        'route'   => '4',
        'country' => MOBILE_COUNTRY_CODE,
        'sms'     => [['message' => $message, 'to' => [gw_msisdn($mobile)]]],
    ];
    if (MSG91_TEMPLATE_ID !== '') {
        $payload['DLT_TE_ID'] = MSG91_TEMPLATE_ID;
    }

    return gw_http_request('https://api.msg91.com/api/v2/sendsms', [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'authkey: ' . MSG91_AUTHKEY,
        ],
    ]);
}

function gw_sms_driver_twilio(string $mobile, string $message): array
{
    if (TWILIO_SID === '' || TWILIO_TOKEN === '') {
        return ['ok' => false, 'http' => null, 'response' => '', 'error' => 'Twilio credentials are not set'];
    }

    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode(TWILIO_SID) . '/Messages.json';

    return gw_http_request($url, [
        CURLOPT_POST       => true,
        CURLOPT_USERPWD    => TWILIO_SID . ':' . TWILIO_TOKEN,
        CURLOPT_POSTFIELDS => http_build_query([
            'From' => TWILIO_FROM,
            'To'   => '+' . gw_msisdn($mobile),
            'Body' => $message,
        ]),
    ]);
}

/* ---------------------------------------------------------------- */

function gw_http_request(string $url, array $options = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, $options + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'GuestWifiPortal/1.0',
    ]);

    $body  = curl_exec($ch);
    $error = curl_error($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok'       => $error === '' && $code >= 200 && $code < 300,
        'http'     => $code,
        'response' => is_string($body) ? mb_substr($body, 0, 2000) : '',
        'error'    => $error,
    ];
}
