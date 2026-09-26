<?php
/**
 * OTP issue and verification, including every policy rule.
 *
 * The two guarantees the portal is built around:
 *   1. an OTP can be spent exactly once (atomic claim on `used`), and
 *   2. it can only be spent by the device that asked for it
 *      (BIND_OTP_TO_DEVICE), so a code shared over WhatsApp is useless
 *      on a second phone.
 *
 * Both functions return: ['ok'=>bool, 'code'=>string, 'message'=>string, 'data'=>array]
 * `code` is machine-readable so the UI can act on it (show a countdown,
 * jump back a step) without matching on English text.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/guest_session.php';

function gw_result(bool $ok, string $code, string $message, array $data = []): array
{
    return ['ok' => $ok, 'code' => $code, 'message' => $message, 'data' => $data];
}

function gw_mobile_allowed(string $mobile): bool
{
    if (!REQUIRE_ALLOWED_LIST) {
        return true;
    }

    $stmt = db()->prepare('SELECT 1 FROM allowed_mobiles WHERE mobile = ? AND enabled = 1');
    $stmt->execute([$mobile]);

    return (bool) $stmt->fetchColumn();
}

/* ---------------------------------------------------------------- */
/* Issue                                                             */
/* ---------------------------------------------------------------- */

function gw_otp_issue(string $mobile, string $deviceId, ?string $mac): array
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
        return gw_result(false, 'already_connected', 'This device is already connected.', [
            'session' => gw_session_public($existing),
        ]);
    }

    // The number is online elsewhere. One OTP = one device, so a second
    // device is refused before any SMS is sent.
    $elsewhere = gw_sessions_active_for_mobile($mobile, $deviceId);
    if (count($elsewhere) >= MAX_ACTIVE_DEVICES) {
        if (ON_DEVICE_LIMIT !== 'replace') {
            gw_history_add($mobile, $deviceId, false, 'device_limit');
            return gw_result(false, 'device_limit',
                'This mobile number is already connected on another device. Disconnect it first, or use a different number.');
        }
    }

    if (gw_daily_success_count($mobile) >= MAX_DAILY_SUCCESS) {
        gw_history_add($mobile, $deviceId, false, 'daily_limit');
        return gw_result(false, 'daily_limit',
            'Daily login limit reached for this number. Please try again tomorrow.');
    }

    // Resend cooldown, measured per number.
    $stmt = $pdo->prepare('SELECT created_at FROM otp_requests WHERE mobile = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$mobile]);
    $last = $stmt->fetchColumn();
    if ($last) {
        $wait = RESEND_COOLDOWN_SEC - (time() - strtotime((string) $last));
        if ($wait > 0) {
            return gw_result(false, 'cooldown',
                "Please wait {$wait} seconds before requesting another OTP.", ['retry_after' => $wait]);
        }
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM otp_requests WHERE mobile = ? AND created_at >= ?');
    $stmt->execute([$mobile, gw_now()->format('Y-m-d') . ' 00:00:00']);
    if ((int) $stmt->fetchColumn() >= MAX_OTP_PER_DAY) {
        gw_history_add($mobile, $deviceId, false, 'otp_daily_limit');
        return gw_result(false, 'otp_daily_limit',
            'Too many OTP requests for this number today. Please try again tomorrow.');
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM otp_requests WHERE ip_address = ? AND created_at >= ?');
    $stmt->execute([gw_client_ip(), gw_ts('-1 hour')]);
    if ((int) $stmt->fetchColumn() >= MAX_OTP_PER_IP_HOUR) {
        return gw_result(false, 'ip_limit', 'Too many requests from this connection. Please try again later.');
    }

    // Any earlier pending code for this number stops being valid now, so
    // only the newest SMS can ever be used.
    $stmt = $pdo->prepare(
        "UPDATE otp_requests SET used = 1, status = 'superseded' WHERE mobile = ? AND used = 0"
    );
    $stmt->execute([$mobile]);

    $max = (10 ** OTP_LENGTH) - 1;
    $otp = str_pad((string) random_int(0, $max), OTP_LENGTH, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare(
        'INSERT INTO otp_requests
            (mobile, otp_hash, device_id, mac_address, ip_address, user_agent,
             attempts, used, status, expires_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?)'
    );
    $stmt->execute([
        $mobile,
        password_hash($otp, PASSWORD_DEFAULT),
        mb_substr($deviceId, 0, 128),
        $mac,
        gw_client_ip(),
        gw_user_agent(),
        'pending',
        gw_ts('+' . OTP_VALID_MINUTES . ' minutes'),
        gw_ts(),
    ]);
    $otpId = (int) $pdo->lastInsertId();

    $sms = gw_send_sms($mobile, gw_sms_message($otp), $otp);

    if (!$sms['ok']) {
        // Do not leave a live code behind for a message that never left.
        $stmt = $pdo->prepare("UPDATE otp_requests SET used = 1, status = 'send_failed' WHERE id = ?");
        $stmt->execute([$otpId]);

        gw_log('error.log', 'SMS send failed for ' . gw_mask_mobile($mobile) . ': ' . $sms['error'] . ' ' . $sms['response']);
        gw_history_add($mobile, $deviceId, false, 'sms_failed');

        return gw_result(false, 'sms_failed',
            'We could not send the OTP right now. Please try again in a moment.',
            APP_DEBUG ? ['debug' => $sms] : []);
    }

    return gw_result(true, 'otp_sent', 'OTP sent to ' . gw_mask_mobile($mobile) . '.', [
        'otp_id'        => $otpId,
        'masked_mobile' => gw_mask_mobile($mobile),
        'expires_in'    => OTP_VALID_MINUTES * 60,
        'resend_after'  => RESEND_COOLDOWN_SEC,
        'otp_length'    => OTP_LENGTH,
    ]);
}

/* ---------------------------------------------------------------- */
/* Verify                                                            */
/* ---------------------------------------------------------------- */

function gw_otp_verify(string $mobile, string $otp, string $deviceId): array
{
    if (!gw_valid_mobile($mobile)) {
        return gw_result(false, 'invalid_mobile', 'Enter a valid 10-digit mobile number.');
    }
    if (!preg_match('/^[0-9]{' . OTP_LENGTH . '}$/', $otp)) {
        return gw_result(false, 'invalid_format', 'Enter the ' . OTP_LENGTH . '-digit OTP from your SMS.');
    }

    $pdo = db();

    $stmt = $pdo->prepare(
        "SELECT * FROM otp_requests
          WHERE mobile = ? AND used = 0 AND status = 'pending'
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$mobile]);
    $row = $stmt->fetch();

    if (!$row) {
        gw_history_add($mobile, $deviceId, false, 'no_pending_otp');
        return gw_result(false, 'no_pending_otp', 'This OTP is no longer valid. Please request a new one.');
    }

    if (gw_expired($row['expires_at'])) {
        $stmt = $pdo->prepare("UPDATE otp_requests SET used = 1, status = 'expired' WHERE id = ?");
        $stmt->execute([$row['id']]);
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
        $stmt->execute([$row['id']]);
        gw_history_add($mobile, $deviceId, false, 'too_many_attempts');

        return gw_result(false, 'too_many_attempts',
            'Too many incorrect attempts. Please request a new OTP.');
    }

    if (!password_verify($otp, (string) $row['otp_hash'])) {
        $stmt = $pdo->prepare('UPDATE otp_requests SET attempts = attempts + 1 WHERE id = ?');
        $stmt->execute([$row['id']]);

        $left = max(0, MAX_OTP_ATTEMPTS - ((int) $row['attempts'] + 1));
        gw_history_add($mobile, $deviceId, false, 'invalid_otp');

        if ($left === 0) {
            $stmt = $pdo->prepare("UPDATE otp_requests SET used = 1, status = 'locked' WHERE id = ?");
            $stmt->execute([$row['id']]);

            return gw_result(false, 'too_many_attempts',
                'Too many incorrect attempts. Please request a new OTP.');
        }

        return gw_result(false, 'invalid_otp',
            'Incorrect OTP. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' remaining.',
            ['attempts_left' => $left]);
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
            foreach (array_slice($elsewhere, 0, count($elsewhere) - MAX_ACTIVE_DEVICES + 1) as $old) {
                gw_session_end((int) $old['id'], 'replaced');
            }
        } else {
            gw_history_add($mobile, $deviceId, false, 'device_limit');
            return gw_result(false, 'device_limit',
                'This mobile number is already connected on another device.');
        }
    }

    // Single-use claim. The WHERE used = 0 is what makes two simultaneous
    // verifications impossible - only one of them can affect a row.
    $stmt = $pdo->prepare(
        "UPDATE otp_requests
            SET used = 1, status = 'verified', verified_at = ?
          WHERE id = ? AND used = 0"
    );
    $stmt->execute([gw_ts(), $row['id']]);

    if ($stmt->rowCount() !== 1) {
        gw_history_add($mobile, $deviceId, false, 'otp_already_used');
        return gw_result(false, 'otp_already_used', 'This OTP has already been used.');
    }

    $created = gw_session_create($mobile, $deviceId, $row['mac_address'] ?: null, (int) $row['id']);
    gw_history_add($mobile, $deviceId, true, 'login_ok');

    return gw_result(true, 'verified', 'Verified. You are now connected.', [
        'token'   => $created['token'],
        'session' => gw_session_public($created['session']),
    ]);
}
