<?php
defined('GW_ADMIN') or exit;

$tabs = array('otp' => 'OTP requests', 'attempts' => 'Login attempts', 'sms' => 'SMS gateway');
$tab  = isset($_GET['tab']) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : 'otp';

$filter = gw_admin_filter();
$where  = array();
$args   = array();

gw_ui_title('Activity logs', 'What happened at the portal, newest first.');
gw_ui_tabs('logs', $tabs, $tab);

if ($tab === 'otp'):
    gw_where_range('created_at', $filter, $where, $args);
    gw_where_search(array('mobile', 'mrd_no', 'mac_address', 'status'), $filter['q'], $where, $args);
    $paged = gw_paged('*', 'FROM otp_requests' . gw_where_sql($where), $args, 'id DESC');

    gw_ui_filters('logs', $filter, 'Search mobile, MRD no, MAC or status', array('tab' => 'otp'), 'otp_requests');
    ?>
    <div class="card card-flush">
        <?php gw_ui_table_start(array('Time', 'Mobile', 'MRD No', 'Device', 'Status', 'Wrong tries', 'Expires')); ?>
        <?php if (!$paged['rows']) { gw_ui_empty(7, 'No OTP requests match.'); } ?>
        <?php foreach ($paged['rows'] as $row): ?>
            <tr>
                <td><?php echo gw_e(gw_dt($row['created_at'])); ?></td>
                <td><?php echo gw_ui_mobile_link($row['mobile']); ?></td>
                <td><?php echo gw_e($row['mrd_no']); ?></td>
                <td><?php echo gw_ui_device($row); ?></td>
                <td><?php echo gw_otp_pill($row['status']); ?></td>
                <td><?php echo (int) $row['attempts']; ?></td>
                <td><?php echo gw_e(gw_dt($row['expires_at'])); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php gw_ui_table_end(); ?>
        <?php gw_ui_pager($paged); ?>
    </div>

<?php elseif ($tab === 'attempts'):
    gw_where_range('created_at', $filter, $where, $args);
    gw_where_search(array('mobile', 'reason', 'ip_address'), $filter['q'], $where, $args);
    $paged = gw_paged('*', 'FROM login_history' . gw_where_sql($where), $args, 'id DESC');

    gw_ui_filters('logs', $filter, 'Search mobile, reason or IP', array('tab' => 'attempts'), 'login_attempts');
    ?>
    <div class="card card-flush">
        <?php gw_ui_table_start(array('Time', 'Mobile', 'Result', 'Reason', 'IP address')); ?>
        <?php if (!$paged['rows']) { gw_ui_empty(5, 'No attempts match.'); } ?>
        <?php foreach ($paged['rows'] as $row): ?>
            <tr>
                <td><?php echo gw_e(gw_dt($row['created_at'])); ?></td>
                <td><?php echo gw_ui_mobile_link($row['mobile']); ?></td>
                <td><?php echo (int) $row['success'] === 1 ? gw_pill('allowed', 'ok') : gw_pill('refused', 'error'); ?></td>
                <td><?php echo gw_e(gw_reason_label($row['reason'])); ?></td>
                <td><?php echo gw_e($row['ip_address']); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php gw_ui_table_end(); ?>
        <?php gw_ui_pager($paged); ?>
    </div>

<?php else:
    gw_where_range('created_at', $filter, $where, $args);
    gw_where_search(array('mobile', 'response'), $filter['q'], $where, $args);
    $paged = gw_paged('*', 'FROM sms_log' . gw_where_sql($where), $args, 'id DESC');

    if (SMS_DRIVER === 'log') {
        gw_ui_alert('warn', 'Test mode: nothing is actually sent. OTPs are in storage/sms.log.');
    }
    gw_ui_filters('logs', $filter, 'Search mobile or gateway response', array('tab' => 'sms'), 'sms_delivery');
    ?>
    <div class="card card-flush">
        <?php gw_ui_table_start(array('Time', 'Mobile', 'Driver', 'Result', 'Message / response')); ?>
        <?php if (!$paged['rows']) { gw_ui_empty(5, 'No messages match.'); } ?>
        <?php foreach ($paged['rows'] as $row): ?>
            <tr>
                <td><?php echo gw_e(gw_dt($row['created_at'])); ?></td>
                <td><?php echo gw_ui_mobile_link($row['mobile']); ?></td>
                <td><?php echo gw_e($row['driver']); ?></td>
                <td><?php echo (int) $row['ok'] === 1 ? gw_pill('sent', 'ok') : gw_pill('failed', 'error'); ?></td>
                <td class="wrap">
                    <?php echo gw_e($row['message']); ?>
                    <?php if (trim((string) $row['response']) !== ''): ?>
                        <br><span class="muted"><?php echo gw_e(mb_substr((string) $row['response'], 0, 160)); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php gw_ui_table_end(); ?>
        <?php gw_ui_pager($paged); ?>
    </div>
<?php endif; ?>
