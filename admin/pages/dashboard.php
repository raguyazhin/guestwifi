<?php
defined('GW_ADMIN') or exit;

$stats   = gw_dashboard_stats();
$daily   = gw_daily_series(14);
$hourly  = gw_hourly_series();
$reasons = gw_failure_reasons();
$health  = gw_health();

$today = date('Y-m-d');

$online = db()->query('SELECT * FROM guest_sessions WHERE active = 1 ORDER BY started_at DESC LIMIT 6')->fetchAll();
$recent = db()->query('SELECT * FROM login_history ORDER BY id DESC LIMIT 7')->fetchAll();

$smsNote = $stats['sms_total'] === 0 ? 'None sent today'
    : $stats['sms_ok'] . ' of ' . $stats['sms_total'] . ' sent';

$dayLabels = array();
$okValues  = array();
$badValues = array();
foreach ($daily as $date => $day) {
    $dayLabels[] = date('d M', strtotime($date));
    $okValues[]  = $day['ok'];
    $badValues[] = $day['failed'];
}

$hourLabels = array();
foreach (array_keys($hourly) as $h) {
    $hourLabels[] = str_pad((string) $h, 2, '0', STR_PAD_LEFT);
}

$reasonRows = array();
foreach ($reasons as $row) {
    $reasonRows[] = array(gw_reason_label($row['reason']), (int) $row['c']);
}

gw_ui_title('Dashboard', date('l, d F Y'), !gw_can('reports.export') ? '' :
    '<a class="btn-primary" href="' . gw_e(gw_url('export', array(
        'report' => 'daily_connected', 'from' => $today, 'to' => $today, 'format' => 'xlsx',
    ))) . '">' . gw_icon('download') . 'Today\'s connected report</a>');
?>

<section class="stats">
    <?php
    gw_ui_stat('Online now', number_format($stats['online']), null, true, 'Portal sessions');
    gw_ui_stat('Guests today', $stats['guests'], $stats['guests_prev']);
    gw_ui_stat('Logins today', $stats['logins'], $stats['logins_prev']);
    gw_ui_stat('OTPs requested', $stats['otps'], $stats['otps_prev']);
    gw_ui_stat('SMS delivered', $stats['sms_total'] === 0 ? '-' : round($stats['sms_ok'] / $stats['sms_total'] * 100) . '%', null, true, $smsNote);
    gw_ui_stat('Failed attempts', $stats['failed'], $stats['failed_prev'], false);
    ?>
</section>

<section class="grid grid-2-1">
    <div class="card">
        <div class="card-head">
            <h2>Logins, last 14 days</h2>
            <div class="legend">
                <span><i class="key s1"></i>Successful</span>
                <span><i class="key s2"></i>Failed</span>
            </div>
        </div>
        <?php echo gw_chart_lines($dayLabels, array(array('Successful', $okValues), array('Failed', $badValues)),
            'Successful and failed logins per day for the last 14 days'); ?>
    </div>

    <div class="card">
        <div class="card-head"><h2>System status</h2></div>
        <ul class="health">
            <?php foreach ($health as $item): ?>
                <li class="health-<?php echo gw_e($item[0]); ?>">
                    <?php echo gw_icon($item[0] === 'ok' ? 'check' : ($item[0] === 'warn' ? 'alert' : 'cross')); ?>
                    <div><b><?php echo gw_e($item[1]); ?></b><span><?php echo gw_e($item[2]); ?></span></div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>

<section class="grid grid-1-1">
    <div class="card">
        <div class="card-head"><h2>Connections by hour, today</h2></div>
        <?php echo gw_chart_columns($hourLabels, array_values($hourly), 'Connections', 'Guest connections per hour today'); ?>
    </div>

    <div class="card">
        <div class="card-head">
            <h2>Why attempts failed, last 7 days</h2>
            <a class="more" href="<?php echo gw_e(gw_url('logs', array('tab' => 'attempts'))); ?>">View log</a>
        </div>
        <?php if ($reasonRows): ?>
            <?php gw_chart_ranked($reasonRows); ?>
        <?php else: ?>
            <p class="empty">No failed attempts in the last 7 days.</p>
        <?php endif; ?>
    </div>
</section>

<section class="grid grid-2-1">
    <div class="card card-flush">
        <div class="card-head">
            <h2>Connected now</h2>
            <a class="more" href="<?php echo gw_e(gw_url('devices')); ?>">View all</a>
        </div>
        <?php gw_ui_table_start(array('Mobile', 'MRD No', 'Device', 'IP address', 'Connected', 'Expires')); ?>
        <?php if (!$online) { gw_ui_empty(6, 'Nobody is connected right now.'); } ?>
        <?php foreach ($online as $row): ?>
            <tr>
                <td><?php echo gw_ui_mobile_link($row['mobile']); ?></td>
                <td><?php echo gw_e($row['mrd_no']); ?></td>
                <td><?php echo gw_ui_device($row); ?></td>
                <td><?php echo gw_e($row['ip_address']); ?></td>
                <td><?php echo gw_e(date('H:i', strtotime($row['started_at']))); ?></td>
                <td><?php echo gw_e(date('H:i', strtotime($row['expires_at']))); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php gw_ui_table_end(); ?>
    </div>

    <div class="card">
        <div class="card-head"><h2>Recent activity</h2></div>
        <ul class="activity">
            <?php if (!$recent): ?><li class="empty">Nothing logged yet.</li><?php endif; ?>
            <?php foreach ($recent as $row): ?>
                <li>
                    <i class="status-dot <?php echo (int) $row['success'] === 1 ? 'good' : 'bad'; ?>"></i>
                    <div>
                        <b><?php echo gw_e(gw_reason_label($row['reason'])); ?></b>
                        <span><?php echo gw_e($row['mobile']); ?> &middot; <?php echo gw_e(gw_dt($row['created_at'])); ?></span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
