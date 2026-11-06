<?php
defined('GW_ADMIN') or exit;

$filter = gw_admin_filter();
$where  = array();
$args   = array();
gw_where_range('created_at', $filter, $where, $args);
gw_where_search(array('username', 'action', 'target', 'detail', 'ip_address'), $filter['q'], $where, $args);

$paged = gw_paged('*', 'FROM admin_audit' . gw_where_sql($where), $args, 'id DESC');

gw_ui_title('Audit trail', 'Sign-ins, report downloads and every change made in this console.');
gw_ui_filters('audit', $filter, 'Search user, target, detail or IP', array(), 'audit');
?>
<div class="card card-flush">
    <?php gw_ui_table_start(array('Time', 'User', 'Action', 'Target', 'Detail', 'IP address')); ?>
    <?php if (!$paged['rows']) { gw_ui_empty(6, 'Nothing recorded.'); } ?>
    <?php foreach ($paged['rows'] as $row): ?>
        <tr>
            <td><?php echo gw_e(gw_dt($row['created_at'])); ?></td>
            <td><?php echo $row['username'] !== null ? gw_e($row['username']) : '<span class="muted">Not signed in</span>'; ?></td>
            <td><?php echo gw_e(gw_audit_label($row['action'])); ?></td>
            <td><?php echo gw_e($row['target']); ?></td>
            <td class="wrap"><?php echo gw_e($row['detail']); ?></td>
            <td><?php echo gw_e($row['ip_address']); ?></td>
        </tr>
    <?php endforeach; ?>
    <?php gw_ui_table_end(); ?>
    <?php gw_ui_pager($paged); ?>
</div>
