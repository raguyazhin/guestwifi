<?php
defined('GW_ADMIN') or exit;

$filter = gw_admin_filter();
$where  = array();
$args   = array();
gw_where_range('s.started_at', $filter, $where, $args);
gw_where_search(array('s.mobile', 's.mrd_no', 's.mac_address'), $filter['q'], $where, $args);

$paged = gw_paged(
    "s.mobile,
     SUBSTRING_INDEX(GROUP_CONCAT(s.mrd_no ORDER BY s.started_at DESC), ',', 1) mrd_no,
     COUNT(*) sessions, COUNT(DISTINCT s.device_id) devices,
     SUM(" . gw_sql_session_minutes() . ") minutes,
     MAX(s.started_at) last_seen, SUM(s.active) online,
     (SELECT 1 FROM blocked_mobiles b WHERE b.mobile = s.mobile AND b.active = 1) is_blocked",
    'FROM guest_sessions s' . gw_where_sql($where), $args, 'last_seen DESC',
    'COUNT(DISTINCT s.mobile)', 'GROUP BY s.mobile', array(gw_ts())
);

gw_ui_title('Guests', 'Everyone who has connected, one line per mobile number.');
gw_ui_filters('guests', $filter, 'Search mobile, MRD no or MAC address', array(), 'guest_usage');
?>
<div class="card card-flush">
    <?php gw_ui_table_start(array('Mobile', 'MRD No', 'Status', 'Sessions', 'Devices', 'Total time', 'Last connected')); ?>
    <?php if (!$paged['rows']) { gw_ui_empty(7, 'No guests match.'); } ?>
    <?php foreach ($paged['rows'] as $row): ?>
        <tr>
            <td><?php echo gw_ui_mobile_link($row['mobile']); ?></td>
            <td><?php echo gw_e($row['mrd_no']); ?></td>
            <td><?php
                if ($row['is_blocked']) {
                    echo gw_pill('blocked', 'error');
                } elseif ((int) $row['online'] > 0) {
                    echo gw_pill('online', 'ok');
                } else {
                    echo gw_pill('offline', 'off');
                }
            ?></td>
            <td><?php echo (int) $row['sessions']; ?></td>
            <td><?php echo (int) $row['devices']; ?></td>
            <td><?php echo gw_e(gw_minutes_text($row['minutes'])); ?></td>
            <td><?php echo gw_e(gw_dt($row['last_seen'])); ?></td>
        </tr>
    <?php endforeach; ?>
    <?php gw_ui_table_end(); ?>
    <?php gw_ui_pager($paged); ?>
</div>
