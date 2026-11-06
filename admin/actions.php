<?php
/**
 * Everything a dashboard form can change. Each action states the capability it
 * needs and is written to the audit trail. gw_admin_action() throws to report
 * a problem; the message reaches the user, so it must be safe to show.
 */

defined('GW_ADMIN') or exit;

class GwActionError extends Exception
{
}

function gw_post($key, $max = 255)
{
    return gw_field($_POST, $key, $max);
}

function gw_post_mobile()
{
    $mobile = gw_normalize_mobile(gw_post('mobile', 20));
    if (!gw_valid_mobile($mobile)) {
        throw new GwActionError('Enter a valid 10-digit mobile number.');
    }
    return $mobile;
}

function gw_require_can($capability)
{
    if (!gw_can($capability)) {
        throw new GwActionError('Your role does not allow this action.');
    }
}

function gw_block_notice($mobile, $closed)
{
    $notice = $mobile . ' blocked.';
    if ($closed > 0) {
        $notice .= ' It was online: the portal session is closed, but the firewall '
            . 'keeps it online until its own authentication timeout.';
    }
    return $notice;
}

function gw_post_user_fields()
{
    $name  = gw_post('full_name', 100);
    $email = gw_post('email', 190);
    $role  = gw_post('role', 16);
    $roles = gw_roles();

    if ($name === '') {
        throw new GwActionError('Enter the full name.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new GwActionError('Enter a valid email address, or leave it blank.');
    }
    if (!isset($roles[$role])) {
        throw new GwActionError('Choose a role.');
    }

    return array($name, $email === '' ? null : $email, $role);
}

function gw_target_user()
{
    $user = gw_user_by_id((int) gw_post('id', 12));
    if (!$user) {
        throw new GwActionError('That user no longer exists.');
    }
    return $user;
}

/** Runs one posted action and records the outcome as a flash message. */
function gw_admin_action($do)
{
    $me = gw_admin_user();

    switch ($do) {
        /* guests */

        case 'disconnect':
            gw_require_can('guests.manage');
            $stmt = db()->prepare('SELECT * FROM guest_sessions WHERE id = ?');
            $stmt->execute(array((int) gw_post('id', 20)));
            $row = $stmt->fetch();
            if ($row) {
                gw_session_end((int) $row['id'], 'admin');
                gw_audit('guest.disconnect', $row['mobile'], (string) $row['mac_address']);
            }
            gw_flash('ok', 'Portal session closed. The firewall keeps the device online until its own authentication timeout.');
            return;

        case 'block_add':
            gw_require_can('guests.manage');
            $mobile = gw_post_mobile();
            $reason = gw_post('reason');
            if ($reason === '') {
                throw new GwActionError('Enter a description for the block.');
            }
            $closed = gw_mobile_block($mobile, $reason);
            gw_audit('block.add', $mobile, $reason);
            gw_flash('ok', gw_block_notice($mobile, $closed));
            return;

        case 'block_remove':
            gw_require_can('guests.manage');
            $row = gw_block_entry(gw_post('id', 12));
            gw_mobile_unblock(gw_post('id', 12));
            if ($row) {
                gw_audit('block.remove', $row['mobile']);
            }
            gw_flash('ok', 'Number unblocked.');
            return;

        case 'block_again':
            gw_require_can('guests.manage');
            $row = gw_block_entry(gw_post('id', 12));
            if (!$row) {
                throw new GwActionError('That entry no longer exists.');
            }
            $closed = gw_mobile_block($row['mobile'], $row['reason']);
            gw_audit('block.add', $row['mobile'], $row['reason']);
            gw_flash('ok', gw_block_notice($row['mobile'], $closed));
            return;

        case 'block_delete':
            gw_require_can('guests.manage');
            $row = gw_block_entry(gw_post('id', 12));
            gw_block_delete(gw_post('id', 12));
            if ($row) {
                gw_audit('block.delete', $row['mobile'], $row['reason']);
            }
            gw_flash('ok', 'Entry deleted. The number can request an OTP again.');
            return;

        case 'allow_add':
            gw_require_can('guests.manage');
            $mobile = gw_post_mobile();
            $label  = gw_post('label', 100);
            $stmt   = db()->prepare(
                'INSERT INTO allowed_mobiles (mobile, label, enabled, created_at)
                 VALUES (?, ?, 1, ?)
                 ON DUPLICATE KEY UPDATE enabled = 1, label = VALUES(label)'
            );
            $stmt->execute(array($mobile, $label, gw_ts()));
            gw_audit('allow.add', $mobile, $label);
            gw_flash('ok', $mobile . ' added to the approved list.');
            return;

        case 'allow_toggle':
            gw_require_can('guests.manage');
            $stmt = db()->prepare('UPDATE allowed_mobiles SET enabled = 1 - enabled WHERE id = ?');
            $stmt->execute(array((int) gw_post('id', 12)));
            gw_audit('allow.toggle', gw_post('id', 12));
            gw_flash('ok', 'Approved-number status changed.');
            return;

        case 'allow_delete':
            gw_require_can('guests.manage');
            $stmt = db()->prepare('DELETE FROM allowed_mobiles WHERE id = ?');
            $stmt->execute(array((int) gw_post('id', 12)));
            gw_audit('allow.delete', gw_post('id', 12));
            gw_flash('ok', 'Number removed.');
            return;

        /* users */

        case 'user_create':
            gw_require_can('users.manage');
            $username = strtolower(gw_post('username', 32));
            if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
                throw new GwActionError('Username must be 3-32 letters, numbers, dots, dashes or underscores.');
            }
            if (gw_user_by_name($username)) {
                throw new GwActionError('That username is already taken.');
            }
            list($name, $email, $role) = gw_post_user_fields();

            $temp = gw_temp_password();
            $stmt = db()->prepare(
                'INSERT INTO admin_users (username, full_name, email, role, password_hash,
                                          must_change_password, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
            );
            $stmt->execute(array($username, $name, $email, $role, password_hash($temp, PASSWORD_DEFAULT),
                $me['username'], gw_ts()));
            gw_audit('user.create', $username, gw_role_label($role));
            gw_flash('ok', 'User ' . $username . ' created. Temporary password, to be changed at first sign-in:', $temp);
            return;

        case 'user_update':
            gw_require_can('users.manage');
            $user = gw_target_user();
            list($name, $email, $role) = gw_post_user_fields();

            if ($role !== $user['role'] && (int) $user['id'] === (int) $me['id']) {
                throw new GwActionError('You cannot change your own role.');
            }
            if ($role !== 'admin' && $user['role'] === 'admin' && gw_user_is_last_admin($user['id'])) {
                throw new GwActionError('This is the only active administrator.');
            }

            $stmt = db()->prepare('UPDATE admin_users SET full_name = ?, email = ?, role = ? WHERE id = ?');
            $stmt->execute(array($name, $email, $role, (int) $user['id']));
            gw_audit('user.update', $user['username'],
                $role !== $user['role'] ? gw_role_label($user['role']) . ' to ' . gw_role_label($role) : '');
            gw_flash('ok', 'User ' . $user['username'] . ' updated.');
            return;

        case 'user_toggle':
            gw_require_can('users.manage');
            $user   = gw_target_user();
            $enable = (int) $user['active'] !== 1;

            if (!$enable && (int) $user['id'] === (int) $me['id']) {
                throw new GwActionError('You cannot disable your own account.');
            }
            if (!$enable && $user['role'] === 'admin' && gw_user_is_last_admin($user['id'])) {
                throw new GwActionError('This is the only active administrator.');
            }

            $stmt = db()->prepare('UPDATE admin_users SET active = ? WHERE id = ?');
            $stmt->execute(array($enable ? 1 : 0, (int) $user['id']));
            gw_audit($enable ? 'user.enable' : 'user.disable', $user['username']);
            gw_flash('ok', 'User ' . $user['username'] . ($enable ? ' enabled.' : ' disabled and signed out.'));
            return;

        case 'user_unlock':
            gw_require_can('users.manage');
            $user = gw_target_user();
            $stmt = db()->prepare('UPDATE admin_users SET locked_until = NULL, failed_logins = 0 WHERE id = ?');
            $stmt->execute(array((int) $user['id']));
            gw_audit('user.unlock', $user['username']);
            gw_flash('ok', 'User ' . $user['username'] . ' unlocked.');
            return;

        case 'user_reset':
            gw_require_can('users.manage');
            $user = gw_target_user();
            if ((int) $user['id'] === (int) $me['id']) {
                throw new GwActionError('Change your own password from My account.');
            }
            $temp = gw_user_reset_password((int) $user['id']);
            gw_audit('user.password_reset', $user['username']);
            gw_flash('ok', 'Password reset for ' . $user['username'] . '. Temporary password, to be changed at next sign-in:', $temp);
            return;

        /* own account */

        case 'profile_update':
            $name  = gw_post('full_name', 100);
            $email = gw_post('email', 190);
            if ($name === '') {
                throw new GwActionError('Enter your full name.');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new GwActionError('Enter a valid email address, or leave it blank.');
            }
            $stmt = db()->prepare('UPDATE admin_users SET full_name = ?, email = ? WHERE id = ?');
            $stmt->execute(array($name, $email === '' ? null : $email, (int) $me['id']));
            gw_audit('account.profile');
            gw_flash('ok', 'Profile updated.');
            return;

        case 'password_change':
            // Passwords are taken raw: gw_field() would alter them.
            $current = isset($_POST['current']) ? (string) $_POST['current'] : '';
            $new     = isset($_POST['new']) ? (string) $_POST['new'] : '';
            $confirm = isset($_POST['confirm']) ? (string) $_POST['confirm'] : '';

            if (!password_verify($current, $me['password_hash'])) {
                throw new GwActionError('Your current password is not correct.');
            }
            if ($new !== $confirm) {
                throw new GwActionError('The new passwords do not match.');
            }
            $problem = gw_password_problem($new, $me['username']);
            if ($problem !== '') {
                throw new GwActionError($problem);
            }
            if (password_verify($new, $me['password_hash'])) {
                throw new GwActionError('Choose a password different from the current one.');
            }

            gw_user_set_password($me['id'], $new, false);
            $_SESSION['session_version'] = (int) $me['session_version'] + 1;   // keep this session
            gw_audit('account.password');
            gw_flash('ok', 'Password changed. Any other sessions have been signed out.');
            return;
    }

    throw new GwActionError('Unknown action.');
}
