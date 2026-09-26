<?php
/**
 * One-click installer / upgrader.
 *
 *   Browser: http://localhost/guestwifi/install.php
 *   CLI:     php install.php
 *
 * Creates the database and tables, and adds any column introduced after
 * the first release. Safe to run more than once.
 */

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/sms.php';

$isCli = PHP_SAPI === 'cli';

// Schema changes from a browser are limited to the machine running XAMPP.
if (!$isCli) {
    $ip = gw_client_ip();
    if (!in_array($ip, ['127.0.0.1', '::1'], true) && !APP_DEBUG) {
        http_response_code(403);
        exit('Run install.php from the server console, or set APP_DEBUG=true temporarily.');
    }
}

$steps  = [];
$errors = [];
$checks = [];

try {
    $steps = gw_install_schema();
} catch (Throwable $e) {
    $errors[] = 'Schema: ' . $e->getMessage();
}

/* --- configuration sanity checks --------------------------------- */

if (APP_SECRET === 'CHANGE_TO_A_LONG_RANDOM_SECRET' || strlen(APP_SECRET) < 32) {
    $checks[] = ['warn', 'APP_SECRET is weak or unchanged. Generate one with: php -r "echo bin2hex(random_bytes(32));"'];
} else {
    $checks[] = ['ok', 'APP_SECRET is set'];
}

if (SMS_DRIVER === 'log') {
    $checks[] = ['warn', 'SMS_DRIVER is "log" - OTPs are written to storage/sms.log and no SMS is sent. Fine for testing.'];
} else {
    $checks[] = ['ok', 'SMS driver: ' . SMS_DRIVER];
}

if (APP_DEBUG) {
    $checks[] = ['warn', 'APP_DEBUG is true - set it to false before going live.'];
} else {
    $checks[] = ['ok', 'APP_DEBUG is false'];
}

if (FORTIGATE_MODE === 'none') {
    $checks[] = ['warn', 'FORTIGATE_MODE is "none" - the portal verifies OTPs but does not open the firewall session yet.'];
} else {
    $checks[] = ['ok', 'FortiGate mode: ' . FORTIGATE_MODE];
    if (gw_config_json('FORTIGATE_ALLOWED_HOSTS') === []) {
        $checks[] = ['warn', 'FORTIGATE_ALLOWED_HOSTS is empty - add your firewall host so credentials cannot be posted elsewhere.'];
    }
}

if (password_verify('admin123', ADMIN_PASS_HASH)) {
    $checks[] = ['warn', 'The admin password is still the default "admin123". Change ADMIN_PASS_HASH.'];
}

$storage = gw_storage_path();
if (is_dir($storage) && is_writable($storage)) {
    $checks[] = ['ok', 'storage/ is writable'];
} else {
    $checks[] = ['warn', 'storage/ is not writable - SMS and error logs will be lost'];
}

foreach (['curl', 'pdo_mysql', 'mbstring'] as $ext) {
    $checks[] = extension_loaded($ext)
        ? ['ok', "PHP extension $ext loaded"]
        : ['warn', "PHP extension $ext is MISSING"];
}

/* --- output ------------------------------------------------------ */

if ($isCli) {
    foreach ($steps as $s) {
        echo "  [db]   $s\n";
    }
    foreach ($checks as [$level, $msg]) {
        echo '  [' . str_pad($level, 4) . "] $msg\n";
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
<title>Install &middot; <?= gw_e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="page-plain">
<main class="panel">
    <h1>Guest Wi-Fi portal setup</h1>

    <?php if ($errors): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $e): ?><p><?= gw_e($e) ?></p><?php endforeach; ?>
            <p class="muted">Check that MySQL is running in the XAMPP control panel and that the
               credentials in <code>config.php</code> are right.</p>
        </div>
    <?php else: ?>
        <div class="alert alert-ok">Database is ready.</div>
    <?php endif; ?>

    <h2>Schema</h2>
    <ul class="checklist">
        <?php foreach ($steps as $s): ?>
            <li class="ok"><?= gw_e($s) ?></li>
        <?php endforeach; ?>
    </ul>

    <h2>Configuration</h2>
    <ul class="checklist">
        <?php foreach ($checks as [$level, $msg]): ?>
            <li class="<?= gw_e($level) ?>"><?= gw_e($msg) ?></li>
        <?php endforeach; ?>
    </ul>

    <p class="actions">
        <a class="btn" href="index.html">Open the portal</a>
        <a class="btn btn-ghost" href="admin/">Admin dashboard</a>
        <?php if (APP_DEBUG && SMS_DRIVER === 'log'): ?>
            <a class="btn btn-ghost" href="dev/inbox.php">Test inbox</a>
        <?php endif; ?>
    </p>
    <p class="muted">Delete or block <code>install.php</code> once the portal is live.</p>
</main>
</body>
</html>
