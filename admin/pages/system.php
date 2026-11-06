<?php
defined('GW_ADMIN') or exit;

$groups = array(
    'Guest access' => array(
        'Session length'           => gw_minutes_text(SESSION_MINUTES),
        'Devices per number'       => MAX_ACTIVE_DEVICES,
        'When the limit is hit'    => ON_DEVICE_LIMIT === 'replace' ? 'Replace the older device' : 'Refuse the new device',
        'Logins per number, daily' => MAX_DAILY_SUCCESS,
        'Approved list'            => REQUIRE_ALLOWED_LIST ? 'Enforced' : 'Not enforced',
    ),
    'OTP' => array(
        'Length'             => OTP_LENGTH . ' digits',
        'Valid for'          => OTP_VALID_MINUTES . ' min',
        'Wrong tries allowed' => MAX_OTP_ATTEMPTS,
        'Resend wait'        => RESEND_COOLDOWN_SEC . ' s',
        'Per number, daily'  => MAX_OTP_PER_DAY,
        'Per address, hourly' => MAX_OTP_PER_IP_HOUR,
        'Tied to the device' => BIND_OTP_TO_DEVICE ? 'Yes' : 'No',
    ),
    'Gateways' => array(
        'SMS driver'     => SMS_DRIVER,
        'SMS sender ID'  => SMS_SENDER_ID,
        'Firewall mode'  => FORTIGATE_MODE,
        'Firewall group' => FORTIGATE_USER_GROUP,
    ),
    'Server' => array(
        'PHP'       => PHP_VERSION,
        'MySQL'     => (string) gw_scalar('SELECT VERSION()'),
        'Time zone' => TIMEZONE,
        'Time now'  => gw_dt(gw_ts()),
    ),
);

$errorLog = gw_storage_path('error.log');
$lines    = is_file($errorLog) ? array_slice(file($errorLog, FILE_IGNORE_NEW_LINES), -12) : array();

gw_ui_title('System', 'Current settings and recent errors. Settings are changed in config.php on the server.');
?>
<section class="grid grid-1-1">
    <?php foreach ($groups as $title => $rows): ?>
        <div class="card">
            <div class="card-head"><h2><?php echo gw_e($title); ?></h2></div>
            <dl class="facts">
                <?php foreach ($rows as $label => $value): ?>
                    <dt><?php echo gw_e($label); ?></dt><dd><?php echo gw_e($value); ?></dd>
                <?php endforeach; ?>
            </dl>
        </div>
    <?php endforeach; ?>
</section>

<div class="card">
    <div class="card-head"><h2>Status</h2></div>
    <ul class="health">
        <?php foreach (gw_health() as $item): ?>
            <li class="health-<?php echo gw_e($item[0]); ?>">
                <?php echo gw_icon($item[0] === 'ok' ? 'check' : ($item[0] === 'warn' ? 'alert' : 'cross')); ?>
                <div><b><?php echo gw_e($item[1]); ?></b><span><?php echo gw_e($item[2]); ?></span></div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="card">
    <div class="card-head"><h2>Recent errors</h2><span class="muted">storage/error.log</span></div>
    <?php if ($lines): ?>
        <pre class="log"><?php echo gw_e(implode("\n", array_reverse($lines))); ?></pre>
    <?php else: ?>
        <p class="empty">No errors logged.</p>
    <?php endif; ?>
</div>
