<?php
defined('GW_ADMIN') or exit;

$tab    = isset($_GET['tab']) && $_GET['tab'] === 'approved' ? 'approved' : 'blocked';
$manage = gw_can('guests.manage');
$filter = gw_admin_filter();

gw_ui_title('Access control', 'Decide which mobile numbers can use the guest Wi-Fi.');
gw_ui_tabs('access', array('blocked' => 'Blocked numbers', 'approved' => 'Approved numbers'), $tab);

if ($tab === 'blocked'):
    $where = array();
    $args  = array();
    gw_where_search(array('mobile', 'reason'), $filter['q'], $where, $args);
    $paged = gw_paged('*', 'FROM blocked_mobiles' . gw_where_sql($where), $args, 'active DESC, blocked_at DESC');
    ?>

    <?php if ($manage): ?>
        <form method="post" class="add-row">
            <?php echo gw_csrf_field(); ?>
            <input type="hidden" name="do" value="block_add">
            <input name="mobile" type="tel" inputmode="numeric" maxlength="10" placeholder="10-digit mobile"
                   value="<?php echo gw_e(gw_field($_GET, 'mobile', 20)); ?>" aria-label="Mobile number" required>
            <input class="grow" name="reason" type="text" maxlength="255" placeholder="Description (why it is blocked)" aria-label="Description" required>
            <button class="btn-primary" type="submit">Block number</button>
        </form>
    <?php endif; ?>

    <form class="filters" method="get" action="admin.php">
        <input type="hidden" name="page" value="access">
        <input type="hidden" name="tab" value="blocked">
        <input class="grow" type="search" name="q" value="<?php echo gw_e($filter['q']); ?>" placeholder="Search mobile or description" aria-label="Search">
        <button class="btn-ghost" type="submit">Search</button>
        <?php if (gw_can('reports.export')): ?>
            <a class="btn-ghost push" href="<?php echo gw_e(gw_url('reports', array('report' => 'blocked'))); ?>"><?php echo gw_icon('download'); ?>Download</a>
        <?php endif; ?>
    </form>

    <div class="card card-flush">
        <?php gw_ui_table_start(array('Mobile', 'Description', 'Status', 'Blocked', 'Unblocked', '')); ?>
        <?php if (!$paged['rows']) { gw_ui_empty(6, 'No numbers have been blocked.'); } ?>
        <?php foreach ($paged['rows'] as $row): $active = (int) $row['active'] === 1; ?>
            <tr>
                <td><?php echo gw_ui_mobile_link($row['mobile']); ?></td>
                <td class="wrap"><?php echo gw_e($row['reason']); ?></td>
                <td><?php echo $active ? gw_pill('blocked', 'error') : gw_pill('unblocked', 'off'); ?></td>
                <td><?php echo gw_e(gw_dt($row['blocked_at'])); ?></td>
                <td><?php echo gw_e(gw_dt($row['unblocked_at'])); ?></td>
                <td class="row-actions">
                    <?php if ($manage): ?>
                        <?php echo gw_action_button($active ? 'block_remove' : 'block_again', $row['id'], $active ? 'Unblock' : 'Block again'); ?>
                        <?php echo gw_action_button('block_delete', $row['id'], 'Delete', 'link',
                            'Delete this entry and its description? The number will be able to request an OTP.'); ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php gw_ui_table_end(); ?>
        <?php gw_ui_pager($paged); ?>
    </div>

<?php else:
    $where = array();
    $args  = array();
    gw_where_search(array('mobile', 'label'), $filter['q'], $where, $args);
    $paged = gw_paged('*', 'FROM allowed_mobiles' . gw_where_sql($where), $args, 'mobile');

    if (REQUIRE_ALLOWED_LIST) {
        gw_ui_alert('ok', 'Enforced: only numbers on this list can sign in.');
    } else {
        gw_ui_alert('warn', 'Not enforced: the portal is open to any number. Set REQUIRE_ALLOWED_LIST in config.php to use this list.');
    }
    ?>

    <?php if ($manage): ?>
        <form method="post" class="add-row">
            <?php echo gw_csrf_field(); ?>
            <input type="hidden" name="do" value="allow_add">
            <input name="mobile" type="tel" inputmode="numeric" maxlength="10" placeholder="10-digit mobile" aria-label="Mobile number" required>
            <input class="grow" name="label" type="text" maxlength="100" placeholder="Name / department (optional)" aria-label="Label">
            <button class="btn-primary" type="submit">Add number</button>
        </form>
    <?php endif; ?>

    <div class="card card-flush">
        <?php gw_ui_table_start(array('Mobile', 'Label', 'Status', 'Added', '')); ?>
        <?php if (!$paged['rows']) { gw_ui_empty(5, 'No numbers on the list.'); } ?>
        <?php foreach ($paged['rows'] as $row): $enabled = (int) $row['enabled'] === 1; ?>
            <tr>
                <td><?php echo gw_ui_mobile_link($row['mobile']); ?></td>
                <td><?php echo gw_e($row['label']); ?></td>
                <td><?php echo $enabled ? gw_pill('enabled', 'ok') : gw_pill('disabled', 'off'); ?></td>
                <td><?php echo gw_e(gw_dt($row['created_at'])); ?></td>
                <td class="row-actions">
                    <?php if ($manage): ?>
                        <?php echo gw_action_button('allow_toggle', $row['id'], $enabled ? 'Disable' : 'Enable'); ?>
                        <?php echo gw_action_button('allow_delete', $row['id'], 'Remove', 'link', 'Remove this number from the approved list?'); ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php gw_ui_table_end(); ?>
        <?php gw_ui_pager($paged); ?>
    </div>
<?php endif; ?>
