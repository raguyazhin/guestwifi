<?php
defined('GW_ADMIN') or exit;

$mobile = gw_normalize_mobile(gw_field($_GET, 'mobile', 20));

$stmt = db()->prepare('SELECT * FROM guest_sessions WHERE mobile = ? ORDER BY started_at DESC LIMIT 50');
$stmt->execute(array($mobile));
$sessions = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM login_history WHERE mobile = ? ORDER BY id DESC LIMIT 25');
$stmt->execute(array($mobile));
$attempts = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM blocked_mobiles WHERE mobile = ?');
$stmt->execute(array($mobile));
$block = $stmt->fetch();

$blocked = $block && (int) $block['active'] === 1;
$online  = 0;
$minutes = 0;
$devices = array();
foreach ($sessions as $row) {
    $online += (int) $row['active'];
    $minutes += gw_session_minutes($row);
    $devices[$row['device_id']] = true;
}

$actions = '';
if (gw_can('guests.manage') && gw_valid_mobile($mobile)) {
    $actions = $blocked
        ? gw_action_button('block_remove', $block['id'], 'Unblock', 'ghost')
        : '<a class="btn-ghost" href="' . gw_e(gw_url('access', array('tab' => 'blocked', 'mobile' => $mobile))) . '">Block number</a>';
}

gw_ui_title($mobile !== '' ? $mobile : 'Guest', $sessions ? 'MRD No ' . $sessions[0]['mrd_no'] : 'No sessions on record.', $actions);
?>

<?php if ($blocked): ?>
    <?php gw_ui_alert('error', 'Blocked since ' . gw_dt($block['blocked_at']) . ': ' . $block['reason']); ?>
<?php endif; ?>

<section class="stats stats-4">
    <?php
    gw_ui_stat('Status', $blocked ? 'Blocked' : ($online > 0 ? 'Online' : 'Offline'));
    gw_ui_stat('Sessions', count($sessions), null, true, count($sessions) === 50 ? 'Latest 50' : '');
    gw_ui_stat('Devices', count($devices));
    gw_ui_stat('Total time', gw_minutes_text($minutes));
    ?>
</section>

<div class="card card-flush">
    <div class="card-head"><h2>Sessions</h2></div>
    <?php gw_ui_table_start(array('Connected', 'Ended', 'Length', 'MRD No', 'Device', 'IP address', 'Status')); ?>
    <?php if (!$sessions) { gw_ui_empty(7, 'No sessions.'); } ?>
    <?php foreach ($sessions as $row): $live = (int) $row['active'] === 1; ?>
        <tr>
            <td><?php echo gw_e(gw_dt($row['started_at'])); ?></td>
            <td><?php echo $live ? '' : gw_e(gw_dt(gw_session_end_time($row))); ?></td>
            <td><?php echo gw_e(gw_minutes_text(gw_session_minutes($row))); ?></td>
            <td><?php echo gw_e($row['mrd_no']); ?></td>
            <td><?php echo gw_ui_device($row); ?></td>
            <td><?php echo gw_e($row['ip_address']); ?></td>
            <td><?php echo $live ? gw_pill('online', 'ok') : gw_e(gw_end_label($row['end_reason'])); ?></td>
        </tr>
    <?php endforeach; ?>
    <?php gw_ui_table_end(); ?>
</div>

<div class="card card-flush">
    <div class="card-head"><h2>Login attempts</h2></div>
    <?php gw_ui_table_start(array('Time', 'Result', 'Reason', 'IP address')); ?>
    <?php if (!$attempts) { gw_ui_empty(4, 'No attempts.'); } ?>
    <?php foreach ($attempts as $row): ?>
        <tr>
            <td><?php echo gw_e(gw_dt($row['created_at'])); ?></td>
            <td><?php echo (int) $row['success'] === 1 ? gw_pill('allowed', 'ok') : gw_pill('refused', 'error'); ?></td>
            <td><?php echo gw_e(gw_reason_label($row['reason'])); ?></td>
            <td><?php echo gw_e($row['ip_address']); ?></td>
        </tr>
    <?php endforeach; ?>
    <?php gw_ui_table_end(); ?>
</div>
