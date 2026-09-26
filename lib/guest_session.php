<?php
/**
 * Guest internet sessions - the record of "this device, for this mobile
 * number, is allowed online until this timestamp".
 *
 * A session is created only by a successful, single-use OTP verification,
 * and is always bound to one device id.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/portal.php';

function gw_session_active_for_device(string $deviceId): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM guest_sessions
          WHERE device_id = ? AND active = 1 AND expires_at > ?
          ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$deviceId, gw_ts()]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/** @return array<int,array> active sessions for a mobile, oldest first */
function gw_sessions_active_for_mobile(string $mobile, string $excludeDeviceId = ''): array
{
    $sql = 'SELECT * FROM guest_sessions
             WHERE mobile = ? AND active = 1 AND expires_at > ?';
    $args = [$mobile, gw_ts()];

    if ($excludeDeviceId !== '') {
        $sql .= ' AND device_id <> ?';
        $args[] = $excludeDeviceId;
    }
    $sql .= ' ORDER BY started_at ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($args);

    return $stmt->fetchAll();
}

function gw_session_by_token(string $token): ?array
{
    if ($token === '') {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT * FROM guest_sessions WHERE token_hash = ? LIMIT 1'
    );
    $stmt->execute([gw_hash_token($token)]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function gw_session_end(int $id, string $reason): void
{
    $stmt = db()->prepare(
        'UPDATE guest_sessions
            SET active = 0, ended_at = ?, end_reason = ?
          WHERE id = ? AND active = 1'
    );
    $stmt->execute([gw_ts(), mb_substr($reason, 0, 32), $id]);
}

/** Successful logins recorded for a mobile number since midnight. */
function gw_daily_success_count(string $mobile): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_history
          WHERE mobile = ? AND success = 1 AND created_at >= ?'
    );
    $stmt->execute([$mobile, gw_now()->format('Y-m-d') . ' 00:00:00']);

    return (int) $stmt->fetchColumn();
}

function gw_history_add(string $mobile, string $deviceId, bool $success, string $reason): void
{
    $stmt = db()->prepare(
        'INSERT INTO login_history (mobile, device_id, ip_address, success, reason, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $mobile,
        mb_substr($deviceId, 0, 128),
        gw_client_ip(),
        $success ? 1 : 0,
        mb_substr($reason, 0, 48),
        gw_ts(),
    ]);
}

/**
 * Opens a session for a device, replacing any earlier session that same
 * device still holds (a reconnect should not count as a second device).
 *
 * @return array{token:string,session:array}
 */
function gw_session_create(string $mobile, string $deviceId, ?string $mac, ?int $otpId): array
{
    $pdo   = db();
    $token = gw_random_token(32);
    $now   = gw_ts();
    $until = gw_ts('+' . SESSION_MINUTES . ' minutes');

    $stmt = $pdo->prepare(
        "UPDATE guest_sessions
            SET active = 0, ended_at = ?, end_reason = 'reconnect'
          WHERE device_id = ? AND active = 1"
    );
    $stmt->execute([$now, $deviceId]);

    $stmt = $pdo->prepare(
        'INSERT INTO guest_sessions
            (mobile, device_id, mac_address, ip_address, user_agent, otp_id,
             token_hash, active, started_at, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
    );
    $stmt->execute([
        $mobile,
        mb_substr($deviceId, 0, 128),
        $mac,
        gw_client_ip(),
        gw_user_agent(),
        $otpId,
        gw_hash_token($token),
        $now,
        $until,
    ]);

    $id   = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM guest_sessions WHERE id = ?');
    $stmt->execute([$id]);

    return ['token' => $token, 'session' => $stmt->fetch()];
}

/**
 * The session this browser is holding, if any: cookie first, then a
 * device-id lookup so a cleared cookie does not hand out a second slot.
 */
function gw_current_session(): ?array
{
    $token = isset($_COOKIE[GW_SESSION_COOKIE]) ? gw_sign_unpack((string) $_COOKIE[GW_SESSION_COOKIE]) : '';

    if ($token !== '') {
        $row = gw_session_by_token($token);
        if ($row && (int) $row['active'] === 1 && !gw_expired($row['expires_at'])) {
            return $row;
        }
    }

    return gw_session_active_for_device(gw_device_id());
}

function gw_session_public(array $row): array
{
    return [
        'mobile'        => gw_mask_mobile((string) $row['mobile']),
        'started_at'    => $row['started_at'],
        'expires_at'    => $row['expires_at'],
        'seconds_left'  => gw_seconds_left($row['expires_at']),
        'minutes_left'  => (int) ceil(gw_seconds_left($row['expires_at']) / 60),
    ];
}
