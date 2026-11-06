<?php
defined('GW_ADMIN') or exit;

$me    = gw_admin_user();
$users = db()->query('SELECT * FROM admin_users ORDER BY active DESC, username')->fetchAll();

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$edit   = $editId > 0 ? gw_user_by_id($editId) : null;
$new    = isset($_GET['new']);

$roleHelp = array(
    'admin'    => 'Everything, including report downloads, users, audit trail and system.',
    'operator' => 'Monitor, preview reports, disconnect devices, manage blocked and approved numbers.',
    'viewer'   => 'Monitor and preview reports only. Cannot download or change anything.',
);

gw_ui_title('Users', 'People who can sign in to this console.',
    $new || $edit ? '' : '<a class="btn-primary" href="' . gw_e(gw_url('users', array('new' => 1))) . '">' . gw_icon('plus') . 'New user</a>');
?>

<?php if ($new || $edit): ?>
    <div class="card form-card">
        <div class="card-head"><h2><?php echo $edit ? 'Edit ' . gw_e($edit['username']) : 'New user'; ?></h2></div>
        <form method="post" action="<?php echo gw_e(gw_url('users')); ?>">
            <?php echo gw_csrf_field(); ?>
            <input type="hidden" name="do" value="<?php echo $edit ? 'user_update' : 'user_create'; ?>">
            <?php if ($edit): ?><input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>"><?php endif; ?>
            <div class="form-grid">
                <?php if (!$edit): ?>
                    <label class="field">Username
                        <input name="username" type="text" maxlength="32" pattern="[A-Za-z0-9._\-]{3,32}" autocapitalize="none" autocomplete="off" required>
                    </label>
                <?php endif; ?>
                <label class="field">Full name
                    <input name="full_name" type="text" maxlength="100" value="<?php echo $edit ? gw_e($edit['full_name']) : ''; ?>" required>
                </label>
                <label class="field">Email <small>optional</small>
                    <input name="email" type="email" maxlength="190" value="<?php echo $edit ? gw_e($edit['email']) : ''; ?>">
                </label>
            </div>
            <fieldset class="roles">
                <legend>Role</legend>
                <?php foreach (gw_roles() as $value => $label):
                    $checked = $edit ? $edit['role'] === $value : $value === 'viewer'; ?>
                    <label class="role">
                        <input type="radio" name="role" value="<?php echo gw_e($value); ?>"<?php echo $checked ? ' checked' : ''; ?>>
                        <span><b><?php echo gw_e($label); ?></b><small><?php echo gw_e($roleHelp[$value]); ?></small></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <?php if (!$edit): ?>
                <p class="muted">A temporary password is generated and shown once. The user sets their own at first sign-in.</p>
            <?php endif; ?>
            <div class="form-actions">
                <button class="btn-primary" type="submit"><?php echo $edit ? 'Save changes' : 'Create user'; ?></button>
                <a class="btn-ghost" href="<?php echo gw_e(gw_url('users')); ?>">Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="card card-flush">
    <?php gw_ui_table_start(array('User', 'Role', 'Status', 'Last sign-in', 'Created', '')); ?>
    <?php foreach ($users as $row):
        $self   = (int) $row['id'] === (int) $me['id'];
        $active = (int) $row['active'] === 1;
        $locked = gw_user_locked($row);
        ?>
        <tr>
            <td>
                <b><?php echo gw_e($row['full_name']); ?></b>
                <?php if ($self): ?><span class="muted">(you)</span><?php endif; ?>
                <br><span class="muted"><?php echo gw_e($row['username']); ?><?php echo $row['email'] ? ' &middot; ' . gw_e($row['email']) : ''; ?></span>
            </td>
            <td><?php echo gw_e(gw_role_label($row['role'])); ?></td>
            <td><?php
                if (!$active) {
                    echo gw_pill('disabled', 'off');
                } elseif ($locked) {
                    echo gw_pill('locked', 'error');
                } elseif ((int) $row['must_change_password'] === 1) {
                    echo gw_pill('password pending', 'warn');
                } else {
                    echo gw_pill('active', 'ok');
                }
            ?></td>
            <td><?php echo $row['last_login_at']
                ? gw_e(gw_dt($row['last_login_at'])) . '<br><span class="muted">' . gw_e($row['last_login_ip']) . '</span>'
                : '<span class="muted">Never</span>'; ?></td>
            <td><?php echo gw_e(gw_dt($row['created_at'])); ?></td>
            <td class="row-actions">
                <a class="btn-link" href="<?php echo gw_e(gw_url('users', array('edit' => $row['id']))); ?>">Edit</a>
                <?php if ($locked) { echo gw_action_button('user_unlock', $row['id'], 'Unlock'); } ?>
                <?php if (!$self): ?>
                    <?php echo gw_action_button('user_reset', $row['id'], 'Reset password', 'link',
                        'Reset the password for ' . $row['username'] . '? They are signed out and get a temporary password.'); ?>
                    <?php echo gw_action_button('user_toggle', $row['id'], $active ? 'Disable' : 'Enable', 'link',
                        $active ? 'Disable ' . $row['username'] . '? They are signed out at once.' : ''); ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php gw_ui_table_end(); ?>
</div>
