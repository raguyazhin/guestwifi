<?php
/** Forced password change: shown instead of every page until it is done. */

defined('GW_ADMIN') or exit;

gw_ui_auth_start('Set a new password');
?>
<h1>Set a new password</h1>
<p class="muted">Your temporary password has to be replaced before you can continue.</p>
<?php gw_ui_flash(); ?>
<form method="post" action="admin.php">
    <?php echo gw_csrf_field(); ?>
    <input type="hidden" name="do" value="password_change">
    <label class="field">Current password
        <input name="current" type="password" autocomplete="current-password" autofocus required>
    </label>
    <label class="field">New password
        <input name="new" type="password" autocomplete="new-password" minlength="<?php echo GW_PASSWORD_MIN; ?>" maxlength="72" required>
    </label>
    <label class="field">Repeat new password
        <input name="confirm" type="password" autocomplete="new-password" required>
    </label>
    <p class="muted small">At least <?php echo GW_PASSWORD_MIN; ?> characters, with letters and numbers.</p>
    <button class="btn-primary wide" type="submit">Save and continue</button>
</form>
<form method="post" action="admin.php" class="auth-alt">
    <?php echo gw_csrf_field(); ?>
    <input type="hidden" name="do" value="logout">
    <button class="btn-link" type="submit">Sign out</button>
</form>
<?php
gw_ui_auth_end();
