<?php
/**
 * Admin dashboard: who is online, what was sent, and the approved-number
 * list. Credentials live in config.php (ADMIN_USER / ADMIN_PASS_HASH).
 *
 * While SMS_DRIVER is 'log' the SMS table shows the full message including
 * the OTP, so the whole flow can be tested without a live gateway.
 */

require_once dirname(__FILE__) . '/portal.php';

$path   = gw_base_path();
$secure = !empty($_SERVER['HTTPS']);

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

$error  = '';
$notice = '';

function gw_pill($text, $kind)
{
    return '<span class="pill pill-' . gw_e($kind) . '">' . gw_e($text) . '</span>';
}

function gw_login_page($error)
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin sign in</title>
    <?php echo gw_page_css(); ?>
    </head>
    <body>
    <div class="panel login">
        <h1>Guest Wi-Fi admin</h1>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?php echo gw_e($error); ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="do" value="login">
            <label for="u">Username</label>
            <input id="u" name="username" type="text" autocomplete="username" required>
            <label for="p">Password</label>
            <input id="p" name="password" type="password" autocomplete="current-password" required>
            <button class="btn" type="submit">Sign in</button>
        </form>
    </div>
    </body>
    </html>
    <?php
}

/* --- auth -------------------------------------------------------- */

$posted = isset($_POST['do']) ? $_POST['do'] : '';

if ($posted === 'login') {
    $user = isset($_POST['username']) ? (string) $_POST['username'] : '';
    $pass = isset($_POST['password']) ? (string) $_POST['password'] : '';

    // Constant-time on both fields so neither can be probed by timing.
    if (hash_equals(ADMIN_USER, $user) && password_verify($pass, ADMIN_PASS_HASH)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['csrf']  = bin2hex(random_bytes(16));
        header('Location: admin.php');
        exit;
    }
    $error = 'Incorrect username or password.';
    usleep(400000);
}

if (isset($_GET['logout'])) {
    $_SESSION = array();
    session_destroy();
    header('Location: admin.php');
    exit;
}

if (empty($_SESSION['admin'])) {
    gw_login_page($error);
    exit;
}

$csrf = isset($_SESSION['csrf']) ? (string) $_SESSION['csrf'] : '';

/* --- actions ----------------------------------------------------- */

if ($posted !== '' && $posted !== 'login') {
    $token = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';

    if (!hash_equals($csrf, $token)) {
        $error = 'Session expired, please retry.';
    } else {
        try {
            switch ($posted) {
                case 'disconnect':
                    gw_session_end((int) (isset($_POST['id']) ? $_POST['id'] : 0), 'admin');
                    $notice = 'Device disconnected.';
                    break;

                case 'allow_add':
                    $mobile = gw_normalize_mobile(isset($_POST['mobile']) ? $_POST['mobile'] : '');
                    if (!gw_valid_mobile($mobile)) {
                        $error = 'Enter a valid 10-digit mobile number.';
                        break;
                    }
                    $label = mb_substr(trim(isset($_POST['label']) ? $_POST['label'] : ''), 0, 100);
                    $stmt  = db()->prepare(
                        'INSERT INTO allowed_mobiles (mobile, label, enabled, created_at)
                         VALUES (?, ?, 1, ?)
                         ON DUPLICATE KEY UPDATE enabled = 1, label = VALUES(label)'
                    );
                    $stmt->execute(array($mobile, $label, gw_ts()));
                    $notice = $mobile . ' added to the approved list.';
                    break;

                case 'allow_toggle':
                    $stmt = db()->prepare('UPDATE allowed_mobiles SET enabled = 1 - enabled WHERE id = ?');
                    $stmt->execute(array((int) (isset($_POST['id']) ? $_POST['id'] : 0)));
                    $notice = 'Approved-number status changed.';
                    break;

                case 'allow_delete':
                    $stmt = db()->prepare('DELETE FROM allowed_mobiles WHERE id = ?');
                    $stmt->execute(array((int) (isset($_POST['id']) ? $_POST['id'] : 0)));
                    $notice = 'Number removed.';
                    break;
            }
        } catch (Exception $e) {
            $error = 'Action failed: ' . $e->getMessage();
        }
    }
}

/* --- data -------------------------------------------------------- */

$active = array();
$otps = array();
$history = array();
$sms = array();
$allowed = array();
$loginsToday = 0;
$otpToday = 0;
$failedToday = 0;

try {
    gw_expire_stale();
    $today = gw_today();

    $active = db()->query('SELECT * FROM guest_sessions WHERE active = 1 ORDER BY started_at DESC LIMIT 100')->fetchAll();

    $stmt = db()->prepare('SELECT COUNT(*) FROM login_history WHERE success = 1 AND created_at >= ?');
    $stmt->execute(array($today));
    $loginsToday = (int) $stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) FROM otp_requests WHERE created_at >= ?');
    $stmt->execute(array($today));
    $otpToday = (int) $stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) FROM login_history WHERE success = 0 AND created_at >= ?');
    $stmt->execute(array($today));
    $failedToday = (int) $stmt->fetchColumn();

    $otps    = db()->query('SELECT * FROM otp_requests ORDER BY id DESC LIMIT 25')->fetchAll();
    $history = db()->query('SELECT * FROM login_history ORDER BY id DESC LIMIT 25')->fetchAll();
    $sms     = db()->query('SELECT * FROM sms_log ORDER BY id DESC LIMIT 20')->fetchAll();
    $allowed = db()->query('SELECT * FROM allowed_mobiles ORDER BY mobile')->fetchAll();

} catch (Exception $e) {
    $error = 'Database not ready. Run install.php first. (' . $e->getMessage() . ')';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Guest Wi-Fi admin</title>
<?php echo gw_page_css(); ?>
</head>
<body>
<div class="shell">

    <div class="top">
        <h1>Guest Wi-Fi &middot; <?php echo gw_e(APP_ORG); ?></h1>
        <div class="actions" style="margin:0">
            <a class="btn btn-ghost" href="portal-standalone.html">Portal</a>
            <a class="btn btn-ghost" href="admin.php">Refresh</a>
            <a class="btn btn-ghost" href="admin.php?logout=1">Sign out</a>
        </div>
    </div>

    <?php if ($error !== ''): ?><div class="alert alert-error"><?php echo gw_e($error); ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="alert alert-ok"><?php echo gw_e($notice); ?></div><?php endif; ?>

    <div class="stats">
        <div class="stat"><b><?php echo count($active); ?></b><span>Devices online</span></div>
        <div class="stat"><b><?php echo $loginsToday; ?></b><span>Logins today</span></div>
        <div class="stat"><b><?php echo $otpToday; ?></b><span>OTPs sent today</span></div>
        <div class="stat"><b><?php echo $failedToday; ?></b><span>Rejected today</span></div>
    </div>

    <div class="tbl">
        <h2>Connected devices</h2>
        <table>
            <thead><tr><th>Mobile</th><th>Device</th><th>IP</th><th>Started</th><th>Expires</th><th></th></tr></thead>
            <tbody>
            <?php if (!$active): ?><tr><td colspan="6" class="muted">Nobody is connected right now.</td></tr><?php endif; ?>
            <?php foreach ($active as $row): ?>
                <tr>
                    <td><?php echo gw_e($row['mobile']); ?></td>
                    <td><code><?php echo gw_e($row['mac_address'] ? $row['mac_address'] : $row['device_id']); ?></code></td>
                    <td><?php echo gw_e($row['ip_address']); ?></td>
                    <td><?php echo gw_e($row['started_at']); ?></td>
                    <td><?php echo gw_e($row['expires_at']); ?></td>
                    <td>
                        <form method="post" style="margin:0">
                            <input type="hidden" name="csrf" value="<?php echo gw_e($csrf); ?>">
                            <input type="hidden" name="do" value="disconnect">
                            <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                            <button class="btn-link" type="submit">Disconnect</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="tbl">
        <h2>Recent OTP requests</h2>
        <table>
            <thead><tr><th>Time</th><th>Mobile</th><th>Device</th><th>Status</th><th>Tries</th><th>Expires</th></tr></thead>
            <tbody>
            <?php if (!$otps): ?><tr><td colspan="6" class="muted">No OTP requests yet.</td></tr><?php endif; ?>
            <?php foreach ($otps as $row): ?>
                <tr>
                    <td><?php echo gw_e($row['created_at']); ?></td>
                    <td><?php echo gw_e($row['mobile']); ?></td>
                    <td><code><?php echo gw_e(mb_substr((string) $row['device_id'], 0, 24)); ?></code></td>
                    <td><?php
                        $status = (string) $row['status'];
                        switch ($status) {
                            case 'verified':    $kind = 'ok'; break;
                            case 'pending':     $kind = 'warn'; break;
                            case 'locked':
                            case 'send_failed': $kind = 'error'; break;
                            default:            $kind = 'off';
                        }
                        echo gw_pill($status, $kind);
                    ?></td>
                    <td><?php echo (int) $row['attempts']; ?></td>
                    <td><?php echo gw_e($row['expires_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="tbl">
        <h2>Login attempts</h2>
        <table>
            <thead><tr><th>Time</th><th>Mobile</th><th>Result</th><th>Reason</th><th>IP</th></tr></thead>
            <tbody>
            <?php if (!$history): ?><tr><td colspan="5" class="muted">Nothing logged yet.</td></tr><?php endif; ?>
            <?php foreach ($history as $row): ?>
                <tr>
                    <td><?php echo gw_e($row['created_at']); ?></td>
                    <td><?php echo gw_e($row['mobile']); ?></td>
                    <td><?php echo ((int) $row['success'] === 1) ? gw_pill('allowed', 'ok') : gw_pill('rejected', 'error'); ?></td>
                    <td><?php echo gw_e($row['reason']); ?></td>
                    <td><?php echo gw_e($row['ip_address']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="tbl">
        <h2>SMS gateway log
            <?php if (SMS_DRIVER === 'log'): ?>
                <?php echo gw_pill('test mode - nothing is actually sent', 'warn'); ?>
            <?php endif; ?>
        </h2>
        <table>
            <thead><tr><th>Time</th><th>Mobile</th><th>Driver</th><th>Result</th><th>Message / response</th></tr></thead>
            <tbody>
            <?php if (!$sms): ?><tr><td colspan="5" class="muted">No messages sent yet.</td></tr><?php endif; ?>
            <?php foreach ($sms as $row): ?>
                <tr>
                    <td><?php echo gw_e($row['created_at']); ?></td>
                    <td><?php echo gw_e($row['mobile']); ?></td>
                    <td><?php echo gw_e($row['driver']); ?></td>
                    <td><?php echo ((int) $row['ok'] === 1) ? gw_pill('sent', 'ok') : gw_pill('failed', 'error'); ?></td>
                    <td style="white-space:normal;max-width:430px">
                        <?php echo gw_e($row['message']); ?>
                        <?php if (trim((string) $row['response']) !== ''): ?>
                            <br><span class="muted"><?php echo gw_e(mb_substr((string) $row['response'], 0, 160)); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="tbl">
        <h2>Approved numbers
            <?php echo REQUIRE_ALLOWED_LIST
                ? gw_pill('enforced', 'ok')
                : gw_pill('not enforced - portal is open to any number', 'off'); ?>
        </h2>

        <form method="post" class="form">
            <input type="hidden" name="csrf" value="<?php echo gw_e($csrf); ?>">
            <input type="hidden" name="do" value="allow_add">
            <input name="mobile" type="tel" inputmode="numeric" maxlength="10" placeholder="10-digit mobile" required>
            <input name="label" type="text" maxlength="100" placeholder="Name / department (optional)">
            <button class="btn" type="submit">Add</button>
        </form>

        <table>
            <thead><tr><th>Mobile</th><th>Label</th><th>Status</th><th>Added</th><th></th></tr></thead>
            <tbody>
            <?php if (!$allowed): ?><tr><td colspan="5" class="muted">No numbers on the list.</td></tr><?php endif; ?>
            <?php foreach ($allowed as $row): ?>
                <tr>
                    <td><?php echo gw_e($row['mobile']); ?></td>
                    <td><?php echo gw_e($row['label']); ?></td>
                    <td><?php echo ((int) $row['enabled'] === 1) ? gw_pill('enabled', 'ok') : gw_pill('disabled', 'off'); ?></td>
                    <td><?php echo gw_e($row['created_at']); ?></td>
                    <td>
                        <form method="post" style="margin:0;display:inline">
                            <input type="hidden" name="csrf" value="<?php echo gw_e($csrf); ?>">
                            <input type="hidden" name="do" value="allow_toggle">
                            <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                            <button class="btn-link" type="submit"><?php echo ((int) $row['enabled'] === 1) ? 'Disable' : 'Enable'; ?></button>
                        </form>
                        <form method="post" style="margin:0;display:inline">
                            <input type="hidden" name="csrf" value="<?php echo gw_e($csrf); ?>">
                            <input type="hidden" name="do" value="allow_delete">
                            <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                            <button class="btn-link" type="submit">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>
</body>
</html>
