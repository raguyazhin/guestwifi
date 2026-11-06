<?php
/**
 * Guest Wi-Fi management console. Every page goes through here: sign-in,
 * session and role checks, form actions, report downloads, page dispatch.
 *
 *   admin/auth.php     accounts, roles, sign-in, audit trail
 *   admin/data.php     queries, dashboard figures, report definitions
 *   admin/actions.php  everything a form can change
 *   admin/export.php   Excel, CSV and print output
 *   admin/ui.php       layout, components, charts
 *   admin/pages/       one file per screen
 */

define('GW_ADMIN', true);

$base = dirname(__FILE__);
require_once $base . '/portal.php';
require_once $base . '/admin/auth.php';
require_once $base . '/admin/data.php';
require_once $base . '/admin/ui.php';
require_once $base . '/admin/actions.php';
require_once $base . '/admin/export.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self'; "
    . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

gw_admin_session_start();

// page => file, capability needed, title
$pages = array(
    'dashboard' => array('dashboard.php', '', 'Dashboard'),
    'devices'   => array('devices.php', '', 'Connected devices'),
    'guests'    => array('guests.php', '', 'Guests'),
    'guest'     => array('guest.php', '', 'Guest'),
    'access'    => array('access.php', '', 'Access control'),
    'logs'      => array('logs.php', '', 'Activity logs'),
    'reports'   => array('reports.php', '', 'Reports'),
    'users'     => array('users.php', 'users.manage', 'Users'),
    'audit'     => array('audit.php', 'audit.view', 'Audit trail'),
    'system'    => array('system.php', 'system.view', 'System'),
    'account'   => array('account.php', '', 'My account'),
);



function gw_admin_redirect()
{
    $query = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
    header('Location: admin.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function gw_admin_fatal($e)
{
    gw_log('error.log', 'Admin: ' . $e->getMessage());
    http_response_code(500);
    gw_ui_auth_start('Unavailable');
    echo '<h1>Unavailable</h1><p class="muted">The console could not reach its database or finish the '
        . 'request. Check that MySQL is running and that install.php has been run. '
        . 'The detail is in storage/error.log.</p>';
    gw_ui_auth_end();
    exit;
}

try {
    $posted = isset($_POST['do']) ? (string) $_POST['do'] : '';

    /* --- sign-in --------------------------------------------------- */

    if ($posted === 'login') {
        $problem = gw_admin_login(
            isset($_POST['username']) ? (string) $_POST['username'] : '',
            isset($_POST['password']) ? (string) $_POST['password'] : ''
        );
        if ($problem === '') {
            header('Location: admin.php');
            exit;
        }
        gw_ui_login($problem, '');
        exit;
    }

    $polling = isset($_SERVER['HTTP_X_GW_POLL']);
    $me      = gw_admin_session_user(!$polling);

    if (!$me) {
        if ($polling) {
            http_response_code(401);
            exit;
        }
        if ($posted !== '' || isset($_GET['page'])) {
            header('Location: admin.php?expired=1');
            exit;
        }
        gw_ui_login('', isset($_GET['bye']) ? 'You have signed out.'
            : (isset($_GET['expired']) ? 'Your session ended. Sign in to continue.' : ''));
        exit;
    }

    /* --- form actions ---------------------------------------------- */

    if ($posted !== '') {
        if (!hash_equals(gw_csrf_token(), isset($_POST['csrf']) ? (string) $_POST['csrf'] : '')) {
            gw_flash('error', 'Your session changed. Please try that again.');
            gw_admin_redirect();
        }

        if ($posted === 'logout') {
            gw_audit('auth.logout');
            gw_admin_session_destroy();
            header('Location: admin.php?bye=1');
            exit;
        }

        // Until a temporary password is replaced, nothing else is allowed.
        if ((int) $me['must_change_password'] === 1 && $posted !== 'password_change') {
            gw_flash('error', 'Set a new password first.');
            gw_admin_redirect();
        }

        try {
            gw_admin_action($posted);
        } catch (GwActionError $e) {
            gw_flash('error', $e->getMessage());
        }
        gw_admin_redirect();
    }

    /* --- pages ----------------------------------------------------- */

    gw_expire_stale();

    if ((int) $me['must_change_password'] === 1) {
        require $base . '/admin/pages/password.php';
        exit;
    }

    $page = isset($_GET['page']) ? (string) $_GET['page'] : 'dashboard';

    if ($page === 'export') {
        gw_export(
            isset($_GET['report']) ? (string) $_GET['report'] : '',
            isset($_GET['format']) ? (string) $_GET['format'] : ''
        );
        $page = 'reports';
    }

    if (!isset($pages[$page])) {
        $page = 'dashboard';
    }

    $partial = isset($_GET['partial']) && $polling;
    $allowed = $pages[$page][1] === '' || gw_can($pages[$page][1]);

    if (!$partial) {
        gw_ui_start($pages[$page][2], $page === 'guest' ? 'guests' : $page);
    }
    if ($allowed) {
        require $base . '/admin/pages/' . $pages[$page][0];
    } else {
        gw_ui_title('Not available', 'Your role does not include this page.');
    }
    if (!$partial) {
        gw_ui_end();
    }
} catch (Exception $e) {
    gw_admin_fatal($e);
}
