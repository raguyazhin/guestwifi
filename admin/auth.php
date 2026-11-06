<?php
/**
 * Dashboard accounts: roles, sign-in, sessions, passwords and the audit trail.
 * Loaded by admin.php (and by install.php for --reset-admin).
 */

defined('GW_ADMIN') or exit;

// Below PHP's default 24-minute session cleanup, which a longer idle limit
// would be cut short by.
define('GW_ADMIN_IDLE_SEC', 1200);
define('GW_ADMIN_MAX_SEC', 43200);     // signed out after 12 hours regardless
define('GW_ADMIN_MAX_FAILS', 5);       // wrong passwords before an account locks
define('GW_ADMIN_LOCK_MIN', 15);
define('GW_ADMIN_IP_FAILS', 20);       // failures from one address per lock window
define('GW_PASSWORD_MIN', 10);

/* --- roles ------------------------------------------------------- */

function gw_roles()
{
    return array(
        'admin'    => 'Administrator',
        'operator' => 'Operator',
        'viewer'   => 'Viewer',
    );
}

function gw_role_label($role)
{
    $roles = gw_roles();
    return isset($roles[$role]) ? $roles[$role] : $role;
}

/** Whether the signed-in user may do something. Everyone may view; only admins download. */
function gw_can($capability)
{
    $user = gw_admin_user();
    if (!$user) {
        return false;
    }

    $grants = array(
        'admin'    => array('guests.manage', 'users.manage', 'audit.view', 'system.view', 'reports.export'),
        'operator' => array('guests.manage'),
        'viewer'   => array(),
    );

    return isset($grants[$user['role']]) && in_array($capability, $grants[$user['role']], true);
}

/* --- session ----------------------------------------------------- */

function gw_admin_session_start()
{
    $path   = gw_base_path();
    $secure = !empty($_SERVER['HTTPS']);

    ini_set('session.use_strict_mode', '1');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array(
            'lifetime' => 0, 'path' => $path, 'secure' => $secure,
            'httponly' => true, 'samesite' => 'Lax',
        ));
    } else {
        session_set_cookie_params(0, $path . '; samesite=Lax', '', $secure, true);
    }
    session_name('gw_admin');
    session_start();
}

/** Holds the signed-in user for this request. */
function gw_admin_user($set = null)
{
    static $user = null;

    if ($set !== null) {
        $user = $set === false ? null : $set;
    }
    return $user;
}

function gw_admin_session_destroy()
{
    $_SESSION = array();
    session_destroy();
    gw_admin_user(false);
}

/**
 * Loads the signed-in user, or returns null. The row is re-read on every
 * request so disabling a user or resetting a password takes effect at once.
 * $touch is false for the dashboard's auto-refresh, so a screen left open
 * still times out.
 */
function gw_admin_session_user($touch = true)
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }

    $now = time();
    if ($now - (int) $_SESSION['seen_at'] > GW_ADMIN_IDLE_SEC
        || $now - (int) $_SESSION['login_at'] > GW_ADMIN_MAX_SEC) {
        gw_admin_session_destroy();
        return null;
    }

    $user = gw_user_by_id((int) $_SESSION['admin_id']);
    if (!$user || (int) $user['active'] !== 1
        || (int) $user['session_version'] !== (int) $_SESSION['session_version']) {
        gw_admin_session_destroy();
        return null;
    }

    if ($touch) {
        $_SESSION['seen_at'] = $now;
    }

    return gw_admin_user($user);
}

function gw_csrf_token()
{
    return isset($_SESSION['csrf']) ? (string) $_SESSION['csrf'] : '';
}

/* --- sign-in ----------------------------------------------------- */

function gw_user_by_id($id)
{
    $stmt = db()->prepare('SELECT * FROM admin_users WHERE id = ?');
    $stmt->execute(array((int) $id));
    $row = $stmt->fetch();

    return $row ? $row : null;
}

function gw_user_by_name($username)
{
    $stmt = db()->prepare('SELECT * FROM admin_users WHERE username = ?');
    $stmt->execute(array($username));
    $row = $stmt->fetch();

    return $row ? $row : null;
}

function gw_user_locked($user)
{
    return $user['locked_until'] !== null && !gw_expired($user['locked_until']);
}

/** Returns '' on success, otherwise the message to show. */
function gw_admin_login($username, $password)
{
    $username = strtolower(trim($username));
    $ip       = gw_client_ip();

    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM admin_audit
          WHERE action = 'auth.login_failed' AND ip_address = ? AND created_at >= ?"
    );
    $stmt->execute(array($ip, gw_ts('-' . GW_ADMIN_LOCK_MIN . ' minutes')));
    if ((int) $stmt->fetchColumn() >= GW_ADMIN_IP_FAILS) {
        return 'Too many failed sign-ins from this address. Try again later.';
    }

    $user   = gw_user_by_name($username);
    $usable = $user && (int) $user['active'] === 1 && !gw_user_locked($user);

    // A locked or unknown account answers exactly like a wrong password and
    // takes as long, so neither state can be probed. A locked account never
    // has its password checked: that would keep guessing useful during a lock.
    if ($usable) {
        $valid = password_verify($password, $user['password_hash']);
    } else {
        password_hash($password, PASSWORD_DEFAULT);
        $valid = false;
    }

    if (!$valid) {
        if ($usable) {
            gw_user_login_failed($user);
        }
        gw_audit('auth.login_failed', mb_substr($username, 0, 100));
        usleep(300000);

        return 'Incorrect username or password. Accounts lock for ' . GW_ADMIN_LOCK_MIN
            . ' minutes after ' . GW_ADMIN_MAX_FAILS . ' failed attempts.';
    }

    session_regenerate_id(true);
    $now = time();
    $_SESSION = array(
        'admin_id'        => (int) $user['id'],
        'session_version' => (int) $user['session_version'],
        'csrf'            => gw_random_token(16),
        'login_at'        => $now,
        'seen_at'         => $now,
    );

    $stmt = db()->prepare(
        'UPDATE admin_users SET failed_logins = 0, locked_until = NULL,
                last_login_at = ?, last_login_ip = ? WHERE id = ?'
    );
    $stmt->execute(array(gw_ts(), $ip, (int) $user['id']));

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $stmt = db()->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?');
        $stmt->execute(array(password_hash($password, PASSWORD_DEFAULT), (int) $user['id']));
    }

    gw_admin_user($user);
    gw_audit('auth.login');

    return '';
}

function gw_user_login_failed($user)
{
    $fails = (int) $user['failed_logins'] + 1;
    $until = null;

    if ($fails >= GW_ADMIN_MAX_FAILS) {
        $fails = 0;
        $until = gw_ts('+' . GW_ADMIN_LOCK_MIN . ' minutes');
        gw_audit('auth.account_locked', $user['username']);
    }

    $stmt = db()->prepare('UPDATE admin_users SET failed_logins = ?, locked_until = ? WHERE id = ?');
    $stmt->execute(array($fails, $until, (int) $user['id']));
}

/* --- passwords --------------------------------------------------- */

/** Returns '' when acceptable, otherwise what is wrong with it. */
function gw_password_problem($password, $username)
{
    if (strlen($password) < GW_PASSWORD_MIN) {
        return 'Password must be at least ' . GW_PASSWORD_MIN . ' characters.';
    }
    if (strlen($password) > 72) {
        return 'Password must be 72 characters or fewer.';   // bcrypt ignores the rest
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'Password must contain both letters and numbers.';
    }
    if ($username !== '' && stripos($password, $username) !== false) {
        return 'Password must not contain the username.';
    }
    return '';
}

/** Readable temporary password, with the look-alike characters left out. */
function gw_temp_password()
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max      = strlen($alphabet) - 1;

    do {
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
    } while (gw_password_problem($out, '') !== '');

    return $out;
}

/** Stores a password. Every session the user holds stops working. */
function gw_user_set_password($id, $password, $mustChange)
{
    $stmt = db()->prepare(
        'UPDATE admin_users
            SET password_hash = ?, must_change_password = ?, password_changed_at = ?,
                session_version = session_version + 1, failed_logins = 0, locked_until = NULL
          WHERE id = ?'
    );
    $stmt->execute(array(password_hash($password, PASSWORD_DEFAULT), $mustChange ? 1 : 0, gw_ts(), (int) $id));
}

/** Issues a temporary password, enabling and unlocking the account. */
function gw_user_reset_password($id)
{
    $temp = gw_temp_password();
    gw_user_set_password($id, $temp, true);

    $stmt = db()->prepare('UPDATE admin_users SET active = 1 WHERE id = ?');
    $stmt->execute(array((int) $id));

    return $temp;
}

/** Whether taking this user's admin rights away would leave no active admin. */
function gw_user_is_last_admin($id)
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND active = 1 AND id <> ?");
    $stmt->execute(array((int) $id));

    return (int) $stmt->fetchColumn() === 0;
}

/* --- audit ------------------------------------------------------- */

/** Never lets a logging problem stop the action being recorded. */
function gw_audit($action, $target = '', $detail = '')
{
    $user = gw_admin_user();

    try {
        $stmt = db()->prepare(
            'INSERT INTO admin_audit (user_id, username, action, target, detail, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(array(
            $user ? (int) $user['id'] : null,
            $user ? $user['username'] : null,
            $action,
            mb_substr((string) $target, 0, 100),
            mb_substr((string) $detail, 0, 255),
            gw_client_ip(),
            gw_ts(),
        ));
    } catch (Exception $e) {
        gw_log('error.log', 'admin_audit insert failed: ' . $e->getMessage());
    }
}
