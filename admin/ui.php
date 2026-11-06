<?php
/**
 * Dashboard page furniture: layout, navigation, small components and charts.
 * Charts are SVG drawn here on the server, so the dashboard needs no CDN and
 * works on a network with no internet access.
 */

defined('GW_ADMIN') or exit;

/* --- small helpers ----------------------------------------------- */

function gw_url($page, $params = array())
{
    return 'admin.php?' . http_build_query(array('page' => $page) + $params);
}

/** Asset URL with its timestamp, so an update is not hidden by the browser cache. */
function gw_asset($file)
{
    return 'admin/assets/' . $file . '?v=' . filemtime(dirname(__FILE__) . '/assets/' . $file);
}

function gw_dt($datetime)
{
    return $datetime ? date('d M Y, H:i', strtotime($datetime)) : '';
}

function gw_pill($text, $kind)
{
    return '<span class="pill pill-' . gw_e($kind) . '">' . gw_e($text) . '</span>';
}

function gw_otp_pill($status)
{
    switch ($status) {
        case 'verified':    return gw_pill($status, 'ok');
        case 'pending':     return gw_pill($status, 'warn');
        case 'locked':
        case 'blocked':
        case 'send_failed': return gw_pill($status, 'error');
        default:            return gw_pill($status, 'off');
    }
}

function gw_minutes_text($minutes)
{
    $minutes = (int) $minutes;
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    return floor($minutes / 60) . ' h ' . ($minutes % 60) . ' min';
}

function gw_flash($kind, $message, $secret = '')
{
    $_SESSION['flash'] = array('kind' => $kind, 'message' => $message, 'secret' => $secret);
}

function gw_icon($name)
{
    $paths = array(
        'dashboard' => '<rect x="3" y="3" width="6" height="7" rx="1"/><rect x="11" y="3" width="6" height="4" rx="1"/><rect x="11" y="9" width="6" height="8" rx="1"/><rect x="3" y="12" width="6" height="5" rx="1"/>',
        'devices'   => '<path d="M2.5 7.5a11 11 0 0 1 15 0"/><path d="M5 10.5a7.5 7.5 0 0 1 10 0"/><path d="M7.7 13.3a3.8 3.8 0 0 1 4.6 0"/><circle cx="10" cy="16" r=".8"/>',
        'guests'    => '<circle cx="8" cy="7" r="3"/><path d="M2.5 17c.5-3.2 2.7-5 5.5-5s5 1.8 5.500 5"/><path d="M13 4.300a3 3 0 0 1 0 5.400"/><path d="M15 12.300c1.400.7 2.300 2.300 2.500 4.700"/>',
        'shield'    => '<path d="M10 2.500l6 2.200v4.800c0 3.700-2.400 6.500-6 8-3.600-1.500-6-4.300-6-8V4.700z"/><path d="M7.500 10l1.800 1.800 3.200-3.300"/>',
        'logs'      => '<path d="M5 2.500h7l3 3v12H5z"/><path d="M8 9h4M8 12h4M8 15h2"/>',
        'reports'   => '<path d="M3 17h14"/><rect x="4.500" y="10" width="3" height="5"/><rect x="8.500" y="6" width="3" height="9"/><rect x="12.500" y="3" width="3" height="12"/>',
        'users'     => '<circle cx="10" cy="6.500" r="3.500"/><path d="M3.500 17.500c.6-3.800 3.100-6 6.500-6s5.900 2.200 6.500 6"/>',
        'audit'     => '<circle cx="10" cy="10" r="7.500"/><path d="M10 5.500V10l3 2"/>',
        'system'    => '<rect x="3" y="3.500" width="14" height="5.500" rx="1"/><rect x="3" y="11" width="14" height="5.500" rx="1"/><path d="M6 6.200h.01M6 13.700h.01"/>',
        'search'    => '<circle cx="9" cy="9" r="5.500"/><path d="M13.200 13.200L17 17"/>',
        'menu'      => '<path d="M3 5.500h14M3 10h14M3 14.500h14"/>',
        'download'  => '<path d="M10 3v10M6 9.500l4 4 4-4M3.500 17h13"/>',
        'print'     => '<path d="M5.500 7V3h9v4"/><rect x="2.500" y="7" width="15" height="7" rx="1.500"/><path d="M5.500 12h9v5h-9z"/>',
        'plus'      => '<path d="M10 4v12M4 10h12"/>',
        'logout'    => '<path d="M8 3H4v14h4"/><path d="M12.500 6l4 4-4 4M16.500 10H8"/>',
        'external'  => '<path d="M8 4H4v12h12v-4"/><path d="M11 3h6v6M17 3l-7.500 7.500"/>',
        'check'     => '<path d="M4 10.500l4 4 8-9"/>',
        'alert'     => '<path d="M10 3l8 14H2z"/><path d="M10 8.500v4M10 14.700h.01"/>',
        'cross'     => '<path d="M5 5l10 10M15 5L5 15"/>',
    );

    return '<svg class="icon" viewBox="0 0 20 20" aria-hidden="true">'
        . (isset($paths[$name]) ? $paths[$name] : '') . '</svg>';
}

function gw_csrf_field()
{
    return '<input type="hidden" name="csrf" value="' . gw_e(gw_csrf_token()) . '">';
}

/** A one-button form. $confirm opens the confirmation dialog first. */
function gw_action_button($do, $id, $label, $class = 'link', $confirm = '')
{
    $attr = $confirm !== '' ? ' data-confirm="' . gw_e($confirm) . '"' : '';

    return '<form method="post" class="inline"' . $attr . '>' . gw_csrf_field()
        . '<input type="hidden" name="do" value="' . gw_e($do) . '">'
        . '<input type="hidden" name="id" value="' . (int) $id . '">'
        . '<button class="btn-' . gw_e($class) . '" type="submit">' . gw_e($label) . '</button></form>';
}

/* --- layout ------------------------------------------------------ */

function gw_nav()
{
    $nav = array(
        'Monitor' => array(
            'dashboard' => array('Dashboard', 'dashboard'),
            'devices'   => array('Connected devices', 'devices'),
            'guests'    => array('Guests', 'guests'),
        ),
        'Manage' => array(
            'access'  => array('Access control', 'shield'),
            'logs'    => array('Activity logs', 'logs'),
            'reports' => array('Reports', 'reports'),
        ),
    );

    $admin = array();
    if (gw_can('users.manage')) {
        $admin['users'] = array('Users', 'users');
    }
    if (gw_can('audit.view')) {
        $admin['audit'] = array('Audit trail', 'audit');
    }
    if (gw_can('system.view')) {
        $admin['system'] = array('System', 'system');
    }
    if ($admin) {
        $nav['Administration'] = $admin;
    }

    return $nav;
}

function gw_ui_head_tags($title)
{
    ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo gw_e($title); ?> &middot; <?php echo gw_e(APP_NAME); ?></title>
    <link rel="icon" href="<?php echo gw_asset('logo.png'); ?>">
    <link rel="stylesheet" href="<?php echo gw_asset('admin.css'); ?>">
    <script src="<?php echo gw_asset('admin.js'); ?>" defer></script>
    <?php
}

function gw_ui_flash()
{
    if (empty($_SESSION['flash'])) {
        return;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    gw_ui_alert($flash['kind'], $flash['message'], $flash['secret']);
}

function gw_ui_alert($kind, $message, $secret = '')
{
    $icons = array('ok' => 'check', 'warn' => 'alert', 'error' => 'cross');
    ?>
    <div class="alert alert-<?php echo gw_e($kind); ?>" role="status">
        <?php echo gw_icon($icons[$kind]); ?>
        <div>
            <?php echo gw_e($message); ?>
            <?php if ($secret !== ''): ?>
                <div class="secret">
                    <code id="secret-value"><?php echo gw_e($secret); ?></code>
                    <button type="button" class="btn-link" data-copy="#secret-value">Copy</button>
                    <span class="muted">Shown once. Give it to the user directly.</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function gw_ui_start($title, $current)
{
    $user     = gw_admin_user();
    $initials = strtoupper(substr($user['full_name'], 0, 1));
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head><?php gw_ui_head_tags($title); ?></head>
    <body>
    <div class="app" id="app">
        <aside class="side" id="side">
            <a class="side-brand" href="admin.php">
                <img src="<?php echo gw_asset('logo.png'); ?>" alt="">
                <span><b><?php echo gw_e(APP_NAME); ?></b><small><?php echo gw_e(APP_ORG); ?></small></span>
            </a>
            <nav aria-label="Main">
                <?php foreach (gw_nav() as $group => $items): ?>
                    <div class="nav-group"><?php echo gw_e($group); ?></div>
                    <?php foreach ($items as $key => $item): ?>
                        <a class="nav-item<?php echo $key === $current ? ' on' : ''; ?>"
                           href="<?php echo gw_e(gw_url($key)); ?>"<?php echo $key === $current ? ' aria-current="page"' : ''; ?>>
                            <?php echo gw_icon($item[1]); ?><?php echo gw_e($item[0]); ?>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>
            <a class="nav-item side-foot" href="portal-standalone.html" target="_blank" rel="noopener">
                <?php echo gw_icon('external'); ?>Guest portal
            </a>
        </aside>
        <div class="scrim" data-toggle-side></div>

        <div class="main">
            <header class="bar">
                <button class="icon-btn menu-btn" type="button" data-toggle-side aria-label="Menu"><?php echo gw_icon('menu'); ?></button>
                <form class="bar-search" action="admin.php" method="get" role="search">
                    <input type="hidden" name="page" value="guests">
                    <?php echo gw_icon('search'); ?>
                    <input type="search" name="q" placeholder="Search mobile, MRD no or MAC address" aria-label="Search guests">
                </form>
                <div class="user-menu">
                    <button class="user-btn" type="button" data-toggle-menu aria-haspopup="true" aria-expanded="false">
                        <span class="avatar"><?php echo gw_e($initials); ?></span>
                        <span class="user-text"><b><?php echo gw_e($user['full_name']); ?></b>
                            <small><?php echo gw_e(gw_role_label($user['role'])); ?></small></span>
                    </button>
                    <div class="menu" id="user-menu" hidden>
                        <a href="<?php echo gw_e(gw_url('account')); ?>">My account</a>
                        <form method="post" action="<?php echo gw_e(gw_url('account')); ?>">
                            <?php echo gw_csrf_field(); ?>
                            <input type="hidden" name="do" value="logout">
                            <button type="submit">Sign out</button>
                        </form>
                    </div>
                </div>
            </header>
            <main class="content" id="content"<?php echo in_array($current, array('dashboard', 'devices'), true) ? ' data-refresh="60"' : ''; ?>>
    <?php
    gw_ui_flash();
}

function gw_ui_end()
{
    ?>
            </main>
        </div>
    </div>
    <div class="modal" id="confirm" hidden role="dialog" aria-modal="true" aria-labelledby="confirm-text">
        <div class="modal-box">
            <p id="confirm-text"></p>
            <div class="modal-actions">
                <button class="btn-ghost" type="button" data-confirm-no>Cancel</button>
                <button class="btn-primary" type="button" data-confirm-yes>Confirm</button>
            </div>
        </div>
    </div>
    <div class="tip" id="tip" hidden></div>
    </body>
    </html>
    <?php
}

/** Sign-in and forced password change share this split-screen frame. */
function gw_ui_auth_start($title)
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head><?php gw_ui_head_tags($title); ?></head>
    <body class="auth">
    <section class="auth-brand">
        <img src="<?php echo gw_asset('logo.png'); ?>" alt="<?php echo gw_e(APP_ORG); ?>">
        <h2><?php echo gw_e(APP_NAME); ?> Management</h2>
        <p><?php echo gw_e(APP_ORG); ?></p>
    </section>
    <main class="auth-form">
        <div class="auth-box">
    <?php
}

function gw_ui_auth_end()
{
    ?>
        </div>
    </main>
    </body>
    </html>
    <?php
}

function gw_ui_login($error, $notice)
{
    gw_ui_auth_start('Sign in');
    ?>
    <h1>Sign in</h1>
    <p class="muted">Use your Guest Wi-Fi management account.</p>
    <?php if ($error !== '') { gw_ui_alert('error', $error); } ?>
    <?php if ($notice !== '') { gw_ui_alert('ok', $notice); } ?>
    <form method="post" action="admin.php">
        <input type="hidden" name="do" value="login">
        <label class="field">Username
            <input name="username" type="text" autocomplete="username" autocapitalize="none" autofocus required>
        </label>
        <label class="field">Password
            <span class="reveal">
                <input name="password" type="password" autocomplete="current-password" required>
                <button type="button" class="btn-link" data-reveal>Show</button>
            </span>
        </label>
        <button class="btn-primary wide" type="submit">Sign in</button>
    </form>
    <p class="muted small">Forgot your password? Ask a system administrator to reset it.</p>
    <?php
    gw_ui_auth_end();
}

/** Title row. $actions is ready-made HTML (buttons, links). */
function gw_ui_title($title, $subtitle = '', $actions = '')
{
    ?>
    <div class="page-head">
        <div>
            <h1><?php echo gw_e($title); ?></h1>
            <?php if ($subtitle !== ''): ?><p><?php echo gw_e($subtitle); ?></p><?php endif; ?>
        </div>
        <?php if ($actions !== ''): ?><div class="page-actions"><?php echo $actions; ?></div><?php endif; ?>
    </div>
    <?php
}

function gw_ui_tabs($page, $tabs, $current)
{
    echo '<div class="tabs" role="tablist">';
    foreach ($tabs as $key => $label) {
        echo '<a role="tab" class="tab' . ($key === $current ? ' on' : '') . '" href="'
            . gw_e(gw_url($page, array('tab' => $key))) . '">' . gw_e($label) . '</a>';
    }
    echo '</div>';
}

/** Search and date row above a list, with its download links. */
function gw_ui_filters($page, $filter, $placeholder, $hidden = array(), $report = '')
{
    ?>
    <form class="filters" method="get" action="admin.php">
        <input type="hidden" name="page" value="<?php echo gw_e($page); ?>">
        <?php foreach ($hidden as $k => $v): ?>
            <input type="hidden" name="<?php echo gw_e($k); ?>" value="<?php echo gw_e($v); ?>">
        <?php endforeach; ?>
        <input type="date" name="from" value="<?php echo gw_e($filter['from']); ?>" aria-label="From date">
        <input type="date" name="to" value="<?php echo gw_e($filter['to']); ?>" aria-label="To date">
        <input class="grow" type="search" name="q" value="<?php echo gw_e($filter['q']); ?>" placeholder="<?php echo gw_e($placeholder); ?>" aria-label="Search">
        <button class="btn-ghost" type="submit">Apply</button>
        <?php if ($filter['q'] !== '' || $filter['from'] !== '' || $filter['to'] !== ''): ?>
            <a class="btn-link" href="<?php echo gw_e(gw_url($page, $hidden)); ?>">Clear</a>
        <?php endif; ?>
        <?php if ($report !== '' && gw_can('reports.export')): ?>
            <a class="btn-ghost push" href="<?php echo gw_e(gw_url('reports', array(
                'report' => $report,
                'from'   => $filter['from'] !== '' ? $filter['from'] : date('Y-m-d', strtotime('-29 days')),
                'to'     => $filter['to'] !== '' ? $filter['to'] : date('Y-m-d'),
            ))); ?>"><?php echo gw_icon('download'); ?>Download</a>
        <?php endif; ?>
    </form>
    <?php
}

function gw_ui_pager($paged)
{
    if ($paged['total'] === 0) {
        return;
    }
    $first = ($paged['page'] - 1) * $paged['per'] + 1;
    $last  = min($paged['total'], $paged['page'] * $paged['per']);
    $query = $_GET;
    ?>
    <div class="pager">
        <span><?php echo $first; ?>&ndash;<?php echo $last; ?> of <?php echo number_format($paged['total']); ?></span>
        <?php if ($paged['pages'] > 1): ?>
            <span>
                <?php if ($paged['page'] > 1): $query['p'] = $paged['page'] - 1; ?>
                    <a class="btn-ghost small" href="admin.php?<?php echo gw_e(http_build_query($query)); ?>">Previous</a>
                <?php endif; ?>
                <?php if ($paged['page'] < $paged['pages']): $query['p'] = $paged['page'] + 1; ?>
                    <a class="btn-ghost small" href="admin.php?<?php echo gw_e(http_build_query($query)); ?>">Next</a>
                <?php endif; ?>
            </span>
        <?php endif; ?>
    </div>
    <?php
}

/** Opens a table with its header row. Pass '' for a blank header. */
function gw_ui_table_start($headers)
{
    echo '<div class="table-wrap"><table><thead><tr>';
    foreach ($headers as $header) {
        echo '<th>' . gw_e($header) . '</th>';
    }
    echo '</tr></thead><tbody>';
}

function gw_ui_table_end()
{
    echo '</tbody></table></div>';
}

function gw_ui_empty($columns, $message)
{
    echo '<tr><td colspan="' . (int) $columns . '" class="empty">' . gw_e($message) . '</td></tr>';
}

/** Device name over its MAC, used wherever a device is listed. */
function gw_ui_device($row)
{
    return gw_e(gw_device_label(isset($row['user_agent']) ? $row['user_agent'] : ''))
        . '<br><code>' . gw_e(gw_device_ref($row['mac_address'], $row['device_id'])) . '</code>';
}

function gw_ui_mobile_link($mobile)
{
    return '<a href="' . gw_e(gw_url('guest', array('mobile' => $mobile))) . '">' . gw_e($mobile) . '</a>';
}

/* --- figures and charts ------------------------------------------ */

/** Stat tile. $prev compares with the same time yesterday; $upIsGood sets its tone. */
function gw_ui_stat($label, $value, $prev = null, $upIsGood = true, $note = '')
{
    ?>
    <div class="stat">
        <span class="stat-label"><?php echo gw_e($label); ?></span>
        <b class="stat-value"><?php echo gw_e($value); ?></b>
        <?php if ($prev !== null):
            $delta = (int) $value - (int) $prev;
            $tone  = $delta === 0 ? 'flat' : (($delta > 0) === $upIsGood ? 'good' : 'bad');
            $arrow = $delta === 0 ? '' : ($delta > 0 ? '&#9650; ' : '&#9660; ');
            ?>
            <span class="stat-delta <?php echo $tone; ?>" title="Compared with the same time yesterday"><?php echo $arrow . abs($delta); ?> <em>vs yesterday</em></span>
        <?php elseif ($note !== ''): ?>
            <span class="stat-delta flat"><em><?php echo gw_e($note); ?></em></span>
        <?php endif; ?>
    </div>
    <?php
}

/** Upper end of a y-axis that divides into 4 whole-number steps. */
function gw_axis_step($max)
{
    if ($max <= 4) {
        return 1;
    }
    $magnitude = pow(10, floor(log10($max / 4)));
    foreach (array(1, 2, 5, 10) as $m) {
        if ($m * $magnitude * 4 >= $max) {
            return (int) ($m * $magnitude);
        }
    }
    return (int) (10 * $magnitude);
}

function gw_tip_attr($title, $rows)
{
    return " data-tip='" . htmlspecialchars(json_encode(array('t' => $title, 'r' => $rows)), ENT_QUOTES, 'UTF-8') . "'";
}

function gw_chart_grid($left, $right, $top, $height, $step, $scale)
{
    $svg = '';
    for ($i = 0; $i <= 4; $i++) {
        $value = $i * $step;
        $y     = round($top + $height - $value * $scale, 1);
        $svg  .= '<line class="grid" x1="' . $left . '" x2="' . $right . '" y1="' . $y . '" y2="' . $y . '"/>'
            . '<text class="axis" x="' . ($left - 8) . '" y="' . ($y + 4) . '" text-anchor="end">' . number_format($value) . '</text>';
    }
    return $svg;
}

/**
 * Line chart. $series: list of array(name, values). One axis; each band across
 * the plot is a hover target that shows every series at that date.
 */
function gw_chart_lines($labels, $series, $summary)
{
    $w = 720; $h = 250;
    $left = 40; $right = $w - 34; $top = 12; $height = $h - 42;
    $count = count($labels);
    $band  = ($right - $left) / max(1, $count - 1);

    $max = 1;
    foreach ($series as $s) {
        $max = max($max, max($s[1]));
    }
    $step  = gw_axis_step($max);
    $scale = $height / ($step * 4);

    $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . gw_e($summary) . '">';
    $svg .= gw_chart_grid($left, $right, $top, $height, $step, $scale);

    $ends = array();
    foreach ($series as $n => $s) {
        $points = array();
        foreach ($s[1] as $i => $value) {
            $points[] = round($left + $i * $band, 1) . ',' . round($top + $height - $value * $scale, 1);
        }
        if ($n === 0) {
            $svg .= '<polygon class="wash" points="' . $left . ',' . ($top + $height) . ' ' . implode(' ', $points)
                . ' ' . $right . ',' . ($top + $height) . '"/>';
        }
        $svg .= '<polyline class="line s' . ($n + 1) . '" points="' . implode(' ', $points) . '"/>';
        $ends[$n] = array(round($top + $height - end($s[1]) * $scale, 1), end($s[1]));
    }

    // Value at the end of each line, unless two would land on each other.
    $collide = count($ends) === 2 && abs($ends[0][0] - $ends[1][0]) < 14;
    foreach ($ends as $n => $end) {
        $svg .= '<circle class="dot s' . ($n + 1) . '" cx="' . $right . '" cy="' . $end[0] . '" r="4"/>';
        if (!$collide || $n === 0) {
            $svg .= '<text class="end" x="' . ($right + 9) . '" y="' . ($end[0] + 4) . '">' . number_format($end[1]) . '</text>';
        }
    }

    $every = $count > 10 ? 3 : 1;
    foreach ($labels as $i => $label) {
        $x = round($left + $i * $band, 1);
        if (($count - 1 - $i) % $every === 0) {
            $svg .= '<text class="axis" x="' . $x . '" y="' . ($h - 8) . '" text-anchor="middle">' . gw_e($label) . '</text>';
        }
        $rows = array();
        foreach ($series as $n => $s) {
            $rows[] = array('s' . ($n + 1), $s[0], $s[1][$i]);
        }
        $svg .= '<rect class="hit" x="' . round($x - $band / 2, 1) . '" y="' . $top . '" width="' . round($band, 1)
            . '" height="' . $height . '" data-x="' . $x . '"' . gw_tip_attr($label, $rows) . '/>';
    }

    $svg .= '<line class="cross" x1="0" x2="0" y1="' . $top . '" y2="' . ($top + $height) . '" hidden/>';

    return $svg . '</svg>';
}

/** Column chart for one series. A null value is a period that has not come yet. */
function gw_chart_columns($labels, $values, $name, $summary)
{
    $w = 540; $h = 220;
    $left = 32; $right = $w - 4; $top = 12; $height = $h - 42;
    $count = count($labels);
    $band  = ($right - $left) / $count;
    $bar   = min(24, $band - 2);

    $max   = max(1, max($values));
    $step  = gw_axis_step($max);
    $scale = $height / ($step * 4);
    $base  = $top + $height;

    $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . gw_e($summary) . '">';
    $svg .= gw_chart_grid($left, $right, $top, $height, $step, $scale);

    foreach ($labels as $i => $label) {
        $value = $values[$i];
        $x     = $left + $i * $band + ($band - $bar) / 2;

        if ($value > 0) {
            $bh = max(3, $value * $scale);
            $r  = min(4, $bh);
            $svg .= '<path class="bar s1" d="M' . round($x, 1) . ' ' . $base
                . 'v' . round(-$bh + $r, 1) . 'q0 -' . $r . ' ' . $r . ' -' . $r
                . 'h' . round($bar - 2 * $r, 1) . 'q' . $r . ' 0 ' . $r . ' ' . $r
                . 'v' . round($bh - $r, 1) . 'z"/>';
        }
        if ($i % 3 === 0) {
            $svg .= '<text class="axis" x="' . round($x + $bar / 2, 1) . '" y="' . ($h - 8) . '" text-anchor="middle">' . gw_e($label) . '</text>';
        }
        if ($value !== null) {
            $svg .= '<rect class="hit" x="' . round($left + $i * $band, 1) . '" y="' . $top . '" width="' . round($band, 1)
                . '" height="' . $height . '"' . gw_tip_attr($label . ':00', array(array('s1', $name, $value))) . '/>';
        }
    }

    return $svg . '</svg>';
}

/** Ranked horizontal bars with the value at the tip. $rows: list of array(label, value). */
function gw_chart_ranked($rows)
{
    $max = 1;
    foreach ($rows as $row) {
        $max = max($max, $row[1]);
    }

    echo '<div class="ranked">';
    foreach ($rows as $row) {
        echo '<div class="ranked-row"><span class="ranked-label">' . gw_e($row[0]) . '</span>'
            . '<span class="ranked-track"><i style="width:' . round($row[1] / $max * 100, 1) . '%"></i></span>'
            . '<span class="ranked-value">' . number_format($row[1]) . '</span></div>';
    }
    echo '</div>';
}
