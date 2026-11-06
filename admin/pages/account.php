<?php
defined('GW_ADMIN') or exit;

$me = gw_admin_user();

gw_ui_title('My account', $me['username'] . ' · ' . gw_role_label($me['role']));
?>
<section class="grid grid-1-1">
    <div class="card">
        <div class="card-head"><h2>Profile</h2></div>
        <form method="post">
            <?php echo gw_csrf_field(); ?>
            <input type="hidden" name="do" value="profile_update">
            <label class="field">Full name
                <input name="full_name" type="text" maxlength="100" value="<?php echo gw_e($me['full_name']); ?>" required>
            </label>
            <label class="field">Email <small>optional</small>
                <input name="email" type="email" maxlength="190" value="<?php echo gw_e($me['email']); ?>">
            </label>
            <div class="form-actions"><button class="btn-primary" type="submit">Save</button></div>
        </form>
    </div>

    <div class="card">
        <div class="card-head"><h2>Change password</h2></div>
        <form method="post">
            <?php echo gw_csrf_field(); ?>
            <input type="hidden" name="do" value="password_change">
            <label class="field">Current password
                <input name="current" type="password" autocomplete="current-password" required>
            </label>
            <label class="field">New password
                <input name="new" type="password" autocomplete="new-password" minlength="<?php echo GW_PASSWORD_MIN; ?>" maxlength="72" required>
            </label>
            <label class="field">Repeat new password
                <input name="confirm" type="password" autocomplete="new-password" required>
            </label>
            <p class="muted">At least <?php echo GW_PASSWORD_MIN; ?> characters, with letters and numbers.</p>
            <div class="form-actions"><button class="btn-primary" type="submit">Change password</button></div>
        </form>
    </div>
</section>
