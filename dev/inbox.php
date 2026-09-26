<?php
/**
 * Test inbox - shows the OTP messages written by the 'log' SMS driver so
 * the whole flow can be exercised without a live gateway.
 *
 * Only works while APP_DEBUG is true AND SMS_DRIVER is 'log', and only for
 * a browser on the server itself.
 */

require_once __DIR__ . '/../lib/util.php';

if (!APP_DEBUG || SMS_DRIVER !== 'log') {
    http_response_code(404);
    exit('Not available.');
}

if (!in_array(gw_client_ip(), ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('The test inbox is only reachable from the server itself.');
}

$file  = gw_storage_path('sms.log');
$lines = is_file($file) ? array_slice(array_filter(array_map('trim', file($file))), -40) : [];
$lines = array_reverse($lines);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="10">
<title>Test inbox</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body class="page-plain">
<div class="panel">
    <h1>Test inbox</h1>
    <div class="alert alert-warn">
        <code>SMS_DRIVER</code> is <code>log</code>, so nothing is actually sent - the messages below
        are what the gateway would have delivered. This page refreshes every 10&nbsp;seconds.
    </div>

    <table>
        <thead><tr><th>Time</th><th>To</th><th>Message</th></tr></thead>
        <tbody>
        <?php if (!$lines): ?>
            <tr><td colspan="3" class="muted">Nothing yet. Request an OTP from the portal.</td></tr>
        <?php endif; ?>
        <?php foreach ($lines as $line): ?>
            <?php
            // Format: [YYYY-mm-dd HH:ii:ss] 919876543210 | Your ... OTP is 123456. ...
            preg_match('/^\[([^\]]+)\]\s*([0-9]+)\s*\|\s*(.*)$/', $line, $m);
            $time    = $m[1] ?? '';
            $to      = $m[2] ?? '';
            $message = $m[3] ?? $line;
            ?>
            <tr>
                <td><?= gw_e($time) ?></td>
                <td><?= gw_e($to) ?></td>
                <td style="white-space:normal">
                    <?= preg_replace('/\b(\d{4,8})\b/', '<strong style="font-size:1.15em;letter-spacing:2px">$1</strong>', gw_e($message)) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p class="actions">
        <a class="btn" href="../index.html">Open the portal</a>
        <a class="btn btn-ghost" href="../admin/">Admin</a>
    </p>
</div>
</body>
</html>
