<?php
defined('GW_ADMIN') or exit;

$filter = gw_admin_filter();
$where  = array('active = 1');
$args   = array();
gw_where_search(array('mobile', 'mrd_no', 'mac_address', 'ip_address'), $filter['q'], $where, $args);

$paged  = gw_paged('*', 'FROM guest_sessions' . gw_where_sql($where), $args, 'started_at DESC');
$manage = gw_can('guests.manage');

gw_ui_title('Connected devices', 'Guests verified on the portal with time remaining. Firewall traffic is not tracked here.');
?>
<form class="filters" method="get" action="admin.php">
    <input type="hidden" name="page" value="devices">
    <input class="grow" type="search" name="q" value="<?php echo gw_e($filter['q']); ?>" placeholder="Search mobile, MRD no, MAC or IP" aria-label="Search">
    <button class="btn-ghost" type="submit">Search</button>
</form>

<div class="card card-flush">
    <?php gw_ui_table_start(array('Mobile', 'MRD No', 'Device', 'IP address', 'Connected', 'Time left', $manage ? '' : ' ')); ?>
    <?php if (!$paged['rows']) { gw_ui_empty(7, $filter['q'] !== '' ? 'No connected device matches.' : 'Nobody is connected right now.'); } ?>
    <?php foreach ($paged['rows'] as $row): ?>
        <tr>
            <td><?php echo gw_ui_mobile_link($row['mobile']); ?></td>
            <td><?php echo gw_e($row['mrd_no']); ?></td>
            <td><?php echo gw_ui_device($row); ?></td>
            <td><?php echo gw_e($row['ip_address']); ?></td>
            <td><?php echo gw_e(gw_dt($row['started_at'])); ?></td>
            <td><?php echo gw_e(gw_minutes_text(ceil(gw_seconds_left($row['expires_at']) / 60))); ?></td>
            <td class="row-actions">
                <?php if ($manage): ?>
                    <?php echo gw_action_button('disconnect', $row['id'], 'Disconnect', 'link',
                        'Close the portal session for ' . $row['mobile'] . '? The firewall keeps the device online until its own authentication timeout.'); ?>
                    <a class="btn-link" href="<?php echo gw_e(gw_url('access', array('tab' => 'blocked', 'mobile' => $row['mobile']))); ?>">Block</a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php gw_ui_table_end(); ?>
    <?php gw_ui_pager($paged); ?>
</div>
