<?php
/**
 * Admin dashboard: who is online, what was sent, and the approved-number
 * list. Credentials live in config.php (ADMIN_USER / ADMIN_PASS_HASH).
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/guest_session.php';

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => gw_base_path(),
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_name('gw_admin');
session_start();

$error = '';
$notice = '';

/* --- auth -------------------------------------------------------- */

if (($_POST['do'] ?? '') === 'login') {
    $user = (string) ($_POST['username'] ?? '');
    $pass = (string) ($_POST['password'] ?? '');

    // Constant-time on both fields so neither can be probed by timing.
    if (hash_equals(ADMIN_USER, $user) && password_verify($pass, ADMIN_PASS_HASH)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['csrf']  = bin2hex(random_bytes(16));
        header('Location: index.php');
        exit;
    }
    $error = 'Incorrect username or password.';
    usleep(400000);
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['admin'])) {
    render_login($error);
    exit;
}

/* --- actions ----------------------------------------------------- */

$action = $_POST['do'] ?? '';

if ($action !== '' && $action !== 'login') {
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Session expired, please retry.';
    } else {
        try {
            switch ($action) {
                case 'disconnect':
                    gw_session_end((int) ($_POST['id'] ?? 0), 'admin');
                    $notice = 'Device disconnected.';
                    break;

                case 'allow_add':
                    $mobile = gw_normalize_mobile((string) ($_POST['mobile'] ?? ''));
                    if (!gw_valid_mobile($mobile)) {
                        $error = 'Enter a valid 10-digit mobile number.';
                        break;
                    }
                    $stmt = db()->prepare(
                        'INSERT INTO allowed_mobiles (mobile, label, enabled, created_at)
                         VALUES (?, ?, 1, ?)
                         ON DUPLICATE KEY UPDATE enabled = 1, label = VALUES(label)'
                    );
                    $stmt->execute([$mobile, mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 100), gw_ts()]);
                    $notice = $mobile . ' added to the approved list.';
                    break;

                case 'allow_toggle':
                    $stmt = db()->prepare('UPDATE allowed_mobiles SET enabled = 1 - enabled WHERE id = ?');
                    $stmt->execute([(int) ($_POST['id'] ?? 0)]);
                    $notice = 'Approved-number status changed.';
                    break;

                case 'allow_delete':
                    $stmt = db()->prepare('DELETE FROM allowed_mobiles WHERE id = ?');
                    $stmt->execute([(int) ($_POST['id'] ?? 0)]);
                    $notice = 'Number removed.';
                    break;
            }
        } catch (Throwable $e) {
            $error = 'Action failed: ' . $e->getMessage();
        }
    }
}

/* --- data -------------------------------------------------------- */

try {
    gw_expire_stale();

    $today = gw_now()->format('Y-m-d') . ' 00:00:00';

    $active = db()->query(
        'SELECT * FROM guest_sessions WHERE active = 1 ORDER BY started_at DESC LIMIT 100'
    )->fetchAll();

    $stmt = db()->prepare('SELECT COUNT(*) FROM login_history WHERE success = 1 AND created_at >= ?');
    $stmt->execute([$today]);
    $loginsToday = (int) $stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) FROM otp_requests WHERE created_at >= ?');
    $stmt->execute([$today]);
    $otpToday = (int) $stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) FROM login_history WHERE success = 0 AND created_at >= ?');
    $stmt->execute([$today]);
    $failedToday = (int) $stmt->fetchColumn();

    $otps = db()->query(
        'SELECT * FROM otp_requests ORDER BY id DESC LIMIT 25'
    )->fetchAll();

    $history = db()->query(
        'SELECT * FROM login_history ORDER BY id DESC LIMIT 25'
    )->fetchAll();

    $sms = db()->query(
        'SELECT * FROM sms_log ORDER BY id DESC LIMIT 15'
    )->fetchAll();

    $allowed = db()->query(
        'SELECT * FROM allowed_mobiles ORDER BY mobile'
    )->fetchAll();

} catch (Throwable $e) {
    $error   = 'Database not ready. Run install.php first. (' . $e->getMessage() . ')';
    $active  = $otps = $history = $sms = $allowed = [];
    $loginsToday = $otpToday = $failedToday = 0;
}

$csrf = (string) ($_SESSION['csrf'] ?? '');

function pill(string $text, string $kind): string
{
    return '<span class="pill pill-' . gw_e($kind) . '">' . gw_e($text) . '</span>';
}

function render_login(string $error): void
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Admin sign in</title>
        <link rel="stylesheet" href="../assets/style.css">
    </head>
    <body class="page-plain">
    <div class="panel login-panel">
        <h1>Guest Wi-Fi admin</h1>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= gw_e($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="do" value="login">
            <div class="field">
                <label for="u">Username</label>
                <div class="input-group"><input id="u" name="username" type="text" autocomplete="username" required></div>
            </div>
            <div class="field">
                <label for="p">Password</label>
                <div class="input-group"><input id="p" name="password" type="password" autocomplete="current-password" required></div>
            </div>
            <button class="btn" type="submit">Sign in</button>
        </form>
    </div>
    </body>
    </html>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Guest Wi-Fi admin</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="admin-shell">

    <div class="admin-top">
        <h1>Guest Wi-Fi &middot; <?= gw_e(APP_ORG) ?></h1>
        <div class="actions" style="margin:0">
            <a class="btn btn-ghost" href="../index.html">Portal</a>
            <a class="btn btn-ghost" href="index.php">Refresh</a>
            <a class="btn btn-ghost" href="index.php?logout=1">Sign out</a>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= gw_e($error) ?></div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <div class="alert alert-ok"><?= gw_e($notice) ?></div>
    <?php endif; ?>

    <div class="stat-grid">
        <div class="stat"><div class="value"><?= count($active) ?></div><div class="label">Devices online</div></div>
        <div class="stat"><div class="value"><?= $loginsToday ?></div><div class="label">Logins today</div></div>
        <div class="stat"><div class="value"><?= $otpToday ?></div><div class="label">OTPs sent today</div></div>
        <div class="stat"><div class="value"><?= $failedToday ?></div><div class="label">Rejected today</div></div>
    </div>

    <div class="table-wrap">
        <h2>Connected devices</h2>
        <table>
            <thead>
            <tr><th>Mobile</th><th>Device</th><th>IP</th><th>Started</th><th>Expires</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (!$active): ?>
                <tr><td colspan="6" class="muted">Nobody is connected right now.</td></tr>
            <?php endif; ?>
            <?php foreach ($active as $row): ?>
                <tr>
                    <td><?= gw_e($row['mobile']) ?></td>
                    <td><code><?= gw_e($row['mac_address'] ?: $row['device_id']) ?></code></td>
                    <td><?= gw_e($row['ip_address']) ?></td>
                    <td><?= gw_e($row['started_at']) ?></td>
                    <td><?= gw_e($row['expires_at']) ?></td>
                    <td>
                        <form method="post" style="margin:0">
                            <input type="hidden" name="csrf" value="<?= gw_e($csrf) ?>">
                            <input type="hidden" name="do" value="disconnect">
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button class="btn-link" type="submit">Disconnect</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="table-wrap">
        <h2>Recent OTP requests</h2>
        <table>
            <thead>
            <tr><th>Time</th><th>Mobile</th><th>Device</th><th>Status</th><th>Tries</th><th>Expires</th></tr>
            </thead>
            <tbody>
            <?php foreach ($otps as $row): ?>
                <tr>
                    <td><?= gw_e($row['created_at']) ?></td>
                    <td><?= gw_e($row['mobile']) ?></td>
                    <td><code><?= gw_e(mb_substr((string) $row['device_id'], 0, 24)) ?></code></td>
                    <td><?php
                        $status = (string) $row['status'];
                        $kind = match ($status) {
                            'verified' => 'ok',
                            'pending'  => 'warn',
                            'locked', 'send_failed' => 'error',
                            default    => 'off',
                        };
                        echo pill($status, $kind);
                    ?></td>
                    <td><?= (int) $row['attempts'] ?></td>
                    <td><?= gw_e($row['expires_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$otps): ?>
                <tr><td colspan="6" class="muted">No OTP requests yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="table-wrap">
        <h2>Login attempts</h2>
        <table>
            <thead>
            <tr><th>Time</th><th>Mobile</th><th>Result</th><th>Reason</th><th>IP</th></tr>
            </thead>
            <tbody>
            <?php foreach ($history as $row): ?>
                <tr>
                    <td><?= gw_e($row['created_at']) ?></td>
                    <td><?= gw_e($row['mobile']) ?></td>
                    <td><?= (int) $row['success'] === 1 ? pill('allowed', 'ok') : pill('rejected', 'error') ?></td>
                    <td><?= gw_e($row['reason']) ?></td>
                    <td><?= gw_e($row['ip_address']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$history): ?>
                <tr><td colspan="5" class="muted">Nothing logged yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="table-wrap">
        <h2>SMS gateway log</h2>
        <table>
            <thead>
            <tr><th>Time</th><th>Mobile</th><th>Driver</th><th>Result</th><th>Response</th></tr>
            </thead>
            <tbody>
            <?php foreach ($sms as $row): ?>
                <tr>
                    <td><?= gw_e($row['created_at']) ?></td>
                    <td><?= gw_e($row['mobile']) ?></td>
                    <td><?= gw_e($row['driver']) ?></td>
                    <td><?= (int) $row['ok'] === 1 ? pill('sent', 'ok') : pill('failed', 'error') ?></td>
                    <td style="white-space:normal;max-width:420px"><?= gw_e(mb_substr((string) $row['response'], 0, 200)) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$sms): ?>
                <tr><td colspan="5" class="muted">No messages sent yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="table-wrap">
        <h2>Approved numbers
            <?= REQUIRE_ALLOWED_LIST
                ? pill('enforced', 'ok')
                : pill('not enforced - portal is open to any number', 'off') ?>
        </h2>

        <form method="post" class="inline-form">
            <input type="hidden" name="csrf" value="<?= gw_e($csrf) ?>">
            <input type="hidden" name="do" value="allow_add">
            <input name="mobile" type="tel" inputmode="numeric" maxlength="10" placeholder="10-digit mobile" required>
            <input name="label" type="text" maxlength="100" placeholder="Name / department (optional)">
            <button class="btn" type="submit">Add</button>
        </form>

        <table>
            <thead>
            <tr><th>Mobile</th><th>Label</th><th>Status</th><th>Added</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($allowed as $row): ?>
                <tr>
                    <td><?= gw_e($row['mobile']) ?></td>
                    <td><?= gw_e($row['label']) ?></td>
                    <td><?= (int) $row['enabled'] === 1 ? pill('enabled', 'ok') : pill('disabled', 'off') ?></td>
                    <td><?= gw_e($row['created_at']) ?></td>
                    <td>
                        <form method="post" style="margin:0;display:inline">
                            <input type="hidden" name="csrf" value="<?= gw_e($csrf) ?>">
                            <input type="hidden" name="do" value="allow_toggle">
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button class="btn-link" type="submit"><?= (int) $row['enabled'] === 1 ? 'Disable' : 'Enable' ?></button>
                        </form>
                        <form method="post" style="margin:0;display:inline">
                            <input type="hidden" name="csrf" value="<?= gw_e($csrf) ?>">
                            <input type="hidden" name="do" value="allow_delete">
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button class="btn-link" type="submit">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$allowed): ?>
                <tr><td colspan="5" class="muted">No numbers on the list.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
</body>
</html>
