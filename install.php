<?php
/**
 * Installer and server check.
 *
 *   Browser: http://localhost/guestwifi/install.php
 *   CLI:     php install.php
 *
 * Creates the database and tables, then audits the server and the
 * configuration. Safe to run more than once.
 *
 * The PHP version test runs before anything else is loaded, so this page
 * still reports usefully on a server too old to run the portal.
 */

$GW_MIN_PHP = '5.6.0';

if (version_compare(PHP_VERSION, $GW_MIN_PHP, '<')) {
    $msg = "This server has PHP " . PHP_VERSION . "; the portal needs $GW_MIN_PHP or newer.";
    if (PHP_SAPI === 'cli') {
        echo "  [FAIL] $msg\n";
        exit(1);
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>PHP too old</h1><p>' . htmlspecialchars($msg) . '</p>';
    exit;
}

require_once dirname(__FILE__) . '/portal.php';

$isCli = PHP_SAPI === 'cli';

// Schema changes from a browser are limited to the machine running XAMPP.
if (!$isCli) {
    $ip = gw_client_ip();
    if (!in_array($ip, array('127.0.0.1', '::1'), true) && !APP_DEBUG) {
        http_response_code(403);
        exit('Run install.php from the server console, or set APP_DEBUG=true temporarily.');
    }
}

$steps  = array();
$errors = array();
$checks = array();

try {
    $steps = gw_install_schema();
} catch (Exception $e) {
    $errors[] = 'Schema: ' . $e->getMessage();
}

/* --- server ------------------------------------------------------ */

$checks[] = array('ok', 'PHP ' . PHP_VERSION . ' (needs ' . $GW_MIN_PHP . '+)');

foreach (array('pdo_mysql', 'curl', 'mbstring', 'openssl') as $ext) {
    if (extension_loaded($ext)) {
        $checks[] = array('ok', "PHP extension $ext loaded");
    } else {
        $note = $ext === 'openssl' && PHP_VERSION_ID < 70000
            ? ' - REQUIRED on PHP 5.6: it is the random source for OTP generation'
            : '';
        $checks[] = array('warn', "PHP extension $ext is MISSING" . $note);
    }
}

// On PHP 5.6 the OTP depends on the polyfill finding a real CSPRNG.
try {
    random_bytes(8);
    $checks[] = array('ok', 'Secure random source available (OTP generation)');
} catch (Exception $e) {
    $errors[] = 'Random: ' . $e->getMessage();
    $checks[] = array('warn', 'No secure random source - the portal will refuse to issue OTPs');
}

$storage = gw_storage_path();
$checks[] = (is_dir($storage) && is_writable($storage))
    ? array('ok', 'storage/ is writable')
    : array('warn', 'storage/ is not writable - SMS and error logs will be lost');

/* --- configuration ------------------------------------------------ */

if (APP_SECRET === 'PUT_A_64_CHARACTER_RANDOM_HEX_STRING_HERE' || strlen(APP_SECRET) < 32) {
    $checks[] = array('warn', 'APP_SECRET is weak or unchanged. Generate one with: php -r "echo bin2hex(random_bytes(32));"');
} else {
    $checks[] = array('ok', 'APP_SECRET is set');
}

if (SMS_DRIVER === 'log') {
    $checks[] = array('warn', 'SMS_DRIVER is "log" - OTPs go to storage/sms.log and no SMS is sent. Fine for testing.');
} else {
    $checks[] = array('ok', 'SMS driver: ' . SMS_DRIVER);
}

$checks[] = APP_DEBUG
    ? array('warn', 'APP_DEBUG is true - set it to false before going live')
    : array('ok', 'APP_DEBUG is false');

if (FORTIGATE_MODE === 'none') {
    $checks[] = array('warn', 'FORTIGATE_MODE is "none" - OTPs are verified but the firewall session is not opened');
} else {
    $checks[] = array('ok', 'FortiGate mode: ' . FORTIGATE_MODE);
    if (gw_config_json('FORTIGATE_ALLOWED_HOSTS') === array()) {
        $checks[] = array('warn', 'FORTIGATE_ALLOWED_HOSTS is empty - add your firewall so credentials cannot be posted elsewhere');
    }
}

if (password_verify('admin123', ADMIN_PASS_HASH)) {
    $checks[] = array('warn', 'The admin password is still the default "admin123" - change ADMIN_PASS_HASH');
}

/* --- output ------------------------------------------------------- */

if ($isCli) {
    foreach ($steps as $s) {
        echo "  [db]   $s\n";
    }
    foreach ($checks as $c) {
        echo '  [' . str_pad($c[0], 4) . '] ' . $c[1] . "\n";
    }
    foreach ($errors as $e) {
        echo "  [FAIL] $e\n";
    }
    echo $errors ? "\nInstall finished with errors.\n" : "\nInstall complete.\n";
    exit($errors ? 1 : 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup &middot; <?php echo gw_e(APP_NAME); ?></title>
<?php echo gw_page_css(); ?>
</head>
<body>
<div class="panel">
    <h1>Guest Wi-Fi portal &middot; setup</h1>
    <p class="muted"><?php echo gw_e(APP_ORG); ?></p>

    <?php if ($errors): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $e): ?><p><?php echo gw_e($e); ?></p><?php endforeach; ?>
            <p class="muted">Check that MySQL is running in the XAMPP control panel and that the
               credentials in <code>config.php</code> are right.</p>
        </div>
    <?php else: ?>
        <div class="alert alert-ok">Database is ready.</div>
    <?php endif; ?>

    <h2>Schema</h2>
    <ul class="list">
        <?php foreach ($steps as $s): ?>
            <li class="ok"><?php echo gw_e($s); ?></li>
        <?php endforeach; ?>
    </ul>

    <h2>Server and configuration</h2>
    <ul class="list">
        <?php foreach ($checks as $c): ?>
            <li class="<?php echo gw_e($c[0]); ?>"><?php echo gw_e($c[1]); ?></li>
        <?php endforeach; ?>
    </ul>

    <p class="actions">
        <a class="btn" href="portal-standalone.html">Open the portal</a>
        <a class="btn btn-ghost" href="admin.php">Admin dashboard</a>
    </p>
    <p class="muted">Delete <code>install.php</code> once the portal is live.</p>
</div>
</body>
</html>
