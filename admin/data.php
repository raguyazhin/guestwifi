<?php
/**
 * Read side of the dashboard: filters, paging, dashboard figures and reports.
 * Nothing here changes data.
 */

defined('GW_ADMIN') or exit;

define('GW_REPORT_MAX_DAYS', 366);

/* --- labels ------------------------------------------------------ */

function gw_reason_label($code)
{
    $labels = array(
        'login_ok'          => 'Login successful',
        'invalid_otp'       => 'Wrong OTP',
        'otp_expired'       => 'OTP expired',
        'no_pending_otp'    => 'No valid OTP',
        'otp_already_used'  => 'OTP already used',
        'too_many_attempts' => 'Too many wrong OTPs',
        'device_mismatch'   => 'OTP used on another device',
        'device_limit'      => 'Already on another device',
        'daily_limit'       => 'Daily login limit reached',
        'otp_daily_limit'   => 'Daily OTP limit reached',
        'blocked'           => 'Number blocked',
        'not_allowed'       => 'Not on approved list',
        'sms_failed'        => 'SMS could not be sent',
    );

    return isset($labels[$code]) ? $labels[$code] : (string) $code;
}

function gw_end_label($reason)
{
    $labels = array(
        'expired'     => 'Time limit reached',
        'user_logout' => 'Guest disconnected',
        'admin'       => 'Disconnected by admin',
        'blocked'     => 'Number blocked',
        'replaced'    => 'Replaced by new device',
        'reconnect'   => 'Reconnected',
    );

    return isset($labels[$reason]) ? $labels[$reason] : (string) $reason;
}

function gw_audit_label($action)
{
    $labels = array(
        'auth.login'          => 'Signed in',
        'auth.logout'         => 'Signed out',
        'auth.login_failed'   => 'Sign-in failed',
        'auth.account_locked' => 'Account locked',
        'guest.disconnect'    => 'Disconnected device',
        'block.add'           => 'Blocked number',
        'block.remove'        => 'Unblocked number',
        'block.delete'        => 'Deleted block entry',
        'allow.add'           => 'Approved number',
        'allow.toggle'        => 'Changed approved number',
        'allow.delete'        => 'Removed approved number',
        'user.create'         => 'Created user',
        'user.update'         => 'Updated user',
        'user.enable'         => 'Enabled user',
        'user.disable'        => 'Disabled user',
        'user.unlock'         => 'Unlocked user',
        'user.password_reset' => 'Reset password',
        'account.password'    => 'Changed own password',
        'account.profile'     => 'Updated own profile',
        'report.export'       => 'Downloaded report',
    );

    return isset($labels[$action]) ? $labels[$action] : (string) $action;
}

/** Android phone / iPhone / Windows PC, from the browser's user agent. */
function gw_device_label($userAgent)
{
    $ua = (string) $userAgent;
    if ($ua === '') {
        return 'Unknown device';
    }

    $types = array(
        '/iPad/'             => 'iPad',
        '/iPhone/'           => 'iPhone',
        '/Android.*Mobile/'  => 'Android phone',
        '/Android/'          => 'Android tablet',
        '/Windows/'          => 'Windows PC',
        '/Macintosh/'        => 'Mac',
        '/CrOS/'             => 'Chromebook',
        '/Linux/'            => 'Linux PC',
    );
    foreach ($types as $pattern => $label) {
        if (preg_match($pattern, $ua)) {
            return $label;
        }
    }
    return 'Other device';
}

/** MAC where the firewall gave one, otherwise the short browser identifier. */
function gw_device_ref($mac, $deviceId)
{
    return $mac ? $mac : mb_substr((string) $deviceId, 0, 14);
}

/**
 * When a session really finished. gw_expire_stale() stamps ended_at on the
 * next request after the time ran out, which can be hours later, so it is
 * capped at expires_at. Still running: the current time.
 */
function gw_session_end_time($row)
{
    $end = $row['ended_at'] ? $row['ended_at'] : gw_ts();
    return strtotime($end) > strtotime($row['expires_at']) ? $row['expires_at'] : $end;
}

function gw_session_minutes($row)
{
    return (int) round(max(0, strtotime(gw_session_end_time($row)) - strtotime($row['started_at'])) / 60);
}

/** The same cap as gw_session_end_time(), for SQL totals. Binds the time once. */
function gw_sql_session_minutes()
{
    return 'TIMESTAMPDIFF(MINUTE, s.started_at, LEAST(COALESCE(s.ended_at, ?), s.expires_at))';
}

/* --- filters and paging ------------------------------------------ */

function gw_valid_date($value)
{
    $t = DateTime::createFromFormat('Y-m-d', (string) $value);
    return $t && $t->format('Y-m-d') === $value;
}

function gw_next_day($date)
{
    return date('Y-m-d', strtotime($date . ' +1 day'));
}

/** Search box and optional date range from the query string. */
function gw_admin_filter()
{
    $from = isset($_GET['from']) && gw_valid_date($_GET['from']) ? $_GET['from'] : '';
    $to   = isset($_GET['to']) && gw_valid_date($_GET['to']) ? $_GET['to'] : '';

    if ($from !== '' && $to !== '' && $from > $to) {
        list($from, $to) = array($to, $from);
    }

    return array('q' => gw_field($_GET, 'q', 64), 'from' => $from, 'to' => $to);
}

function gw_where_range($column, $filter, &$where, &$args)
{
    if ($filter['from'] !== '') {
        $where[] = "$column >= ?";
        $args[]  = $filter['from'] . ' 00:00:00';
    }
    if ($filter['to'] !== '') {
        $where[] = "$column < ?";
        $args[]  = gw_next_day($filter['to']) . ' 00:00:00';
    }
}

function gw_where_search($columns, $q, &$where, &$args)
{
    if ($q === '') {
        return;
    }
    $like  = '%' . addcslashes($q, '%_\\') . '%';
    $parts = array();
    foreach ($columns as $column) {
        $parts[] = "$column LIKE ?";
        $args[]  = $like;
    }
    $where[] = '(' . implode(' OR ', $parts) . ')';
}

function gw_where_sql($where)
{
    return $where ? ' WHERE ' . implode(' AND ', $where) : '';
}

/**
 * One page of a query. $fromWhere is everything from FROM up to GROUP BY.
 * $group makes each row a group, and $count then has to count groups.
 * $selectArgs are placeholders inside $select, which sit before $args.
 */
function gw_paged($select, $fromWhere, $args, $order, $count = 'COUNT(*)', $group = '', $selectArgs = array(), $perPage = 25)
{
    $stmt = db()->prepare("SELECT $count $fromWhere");
    $stmt->execute($args);
    $total = (int) $stmt->fetchColumn();

    $pages = max(1, (int) ceil($total / $perPage));
    $page  = isset($_GET['p']) ? max(1, min($pages, (int) $_GET['p'])) : 1;
    $skip  = ($page - 1) * $perPage;

    // Whole numbers from above, so safe to inline; LIMIT rejects a bound string.
    $stmt = db()->prepare("SELECT $select $fromWhere $group ORDER BY $order LIMIT $skip, $perPage");
    $stmt->execute(array_merge($selectArgs, $args));

    return array('rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page,
                 'pages' => $pages, 'per' => $perPage);
}

function gw_scalar($sql, $args = array())
{
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchColumn();
}

/* --- dashboard --------------------------------------------------- */

function gw_dashboard_stats()
{
    $today     = gw_today();
    $tomorrow  = gw_next_day(date('Y-m-d')) . ' 00:00:00';
    $yesterday = date('Y-m-d H:i:s', strtotime($today . ' -1 day'));
    $sameTime  = gw_ts('-1 day');

    $count = function ($sql, $from, $to) {
        return (int) gw_scalar($sql, array($from, $to));
    };

    $logins  = 'SELECT COUNT(*) FROM login_history WHERE success = 1 AND created_at >= ? AND created_at < ?';
    $guests  = 'SELECT COUNT(DISTINCT mobile) FROM guest_sessions WHERE started_at >= ? AND started_at < ?';
    $failed  = 'SELECT COUNT(*) FROM login_history WHERE success = 0 AND created_at >= ? AND created_at < ?';
    $otps    = 'SELECT COUNT(*) FROM otp_requests WHERE created_at >= ? AND created_at < ?';

    $sms = db()->prepare('SELECT COUNT(*) total, COALESCE(SUM(ok), 0) ok FROM sms_log WHERE created_at >= ?');
    $sms->execute(array($today));
    $smsToday = $sms->fetch();

    return array(
        'online'          => (int) gw_scalar('SELECT COUNT(*) FROM guest_sessions WHERE active = 1'),
        'guests'          => $count($guests, $today, $tomorrow),
        'guests_prev'     => $count($guests, $yesterday, $sameTime),
        'logins'          => $count($logins, $today, $tomorrow),
        'logins_prev'     => $count($logins, $yesterday, $sameTime),
        'failed'          => $count($failed, $today, $tomorrow),
        'failed_prev'     => $count($failed, $yesterday, $sameTime),
        'otps'            => $count($otps, $today, $tomorrow),
        'otps_prev'       => $count($otps, $yesterday, $sameTime),
        'sms_total'       => (int) $smsToday['total'],
        'sms_ok'          => (int) $smsToday['ok'],
    );
}

/** Logins and failures per day, oldest first, with empty days filled in. */
function gw_daily_series($days)
{
    $start = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $rows  = array();
    for ($i = 0; $i < $days; $i++) {
        $rows[date('Y-m-d', strtotime("$start +$i days"))] = array('ok' => 0, 'failed' => 0);
    }

    $stmt = db()->prepare(
        'SELECT DATE(created_at) d, SUM(success = 1) ok, SUM(success = 0) failed
           FROM login_history WHERE created_at >= ? GROUP BY DATE(created_at)'
    );
    $stmt->execute(array($start . ' 00:00:00'));
    foreach ($stmt->fetchAll() as $row) {
        if (isset($rows[$row['d']])) {
            $rows[$row['d']] = array('ok' => (int) $row['ok'], 'failed' => (int) $row['failed']);
        }
    }

    return $rows;
}

/** Sessions started in each hour of today. Hours still to come are null. */
function gw_hourly_series()
{
    $hours = array();
    $now   = (int) date('G');
    for ($h = 0; $h < 24; $h++) {
        $hours[$h] = $h <= $now ? 0 : null;
    }

    $stmt = db()->prepare(
        'SELECT HOUR(started_at) h, COUNT(*) c FROM guest_sessions WHERE started_at >= ? GROUP BY HOUR(started_at)'
    );
    $stmt->execute(array(gw_today()));
    foreach ($stmt->fetchAll() as $row) {
        $hours[(int) $row['h']] = (int) $row['c'];
    }

    return $hours;
}

/** Why logins failed over the last 7 days, most common first. */
function gw_failure_reasons($limit = 6)
{
    $stmt = db()->prepare(
        'SELECT reason, COUNT(*) c FROM login_history WHERE success = 0 AND created_at >= ?
          GROUP BY reason ORDER BY c DESC LIMIT ' . (int) $limit
    );
    $stmt->execute(array(gw_ts('-7 days')));

    return $stmt->fetchAll();
}

/**
 * What needs attention. Each entry: level (ok, warn, error), title, detail.
 * Facts this server can actually see; whether a device is really online at
 * the firewall is not one of them.
 */
function gw_health()
{
    $items = array();

    if (SMS_DRIVER === 'log') {
        $items[] = array('warn', 'SMS test mode', 'OTPs go to a log file; no SMS is sent.');
    } else {
        $stmt = db()->prepare('SELECT COUNT(*) total, COALESCE(SUM(ok), 0) ok, MAX(created_at) last FROM sms_log WHERE created_at >= ?');
        $stmt->execute(array(gw_ts('-1 day')));
        $sms    = $stmt->fetch();
        $failed = (int) $sms['total'] - (int) $sms['ok'];

        if ((int) $sms['total'] === 0) {
            $items[] = array('ok', 'SMS gateway', 'No messages in the last 24 hours.');
        } elseif ($failed * 5 > (int) $sms['total']) {
            $items[] = array('error', 'SMS gateway', $failed . ' of ' . $sms['total'] . ' sends failed in the last 24 hours.');
        } else {
            $items[] = array('ok', 'SMS gateway', $sms['ok'] . ' of ' . $sms['total'] . ' sent in the last 24 hours.');
        }
    }

    $items[] = FORTIGATE_MODE === 'none'
        ? array('warn', 'Firewall handoff off', 'OTPs are verified but the firewall is not told.')
        : array('ok', 'Firewall handoff', 'FortiGate ' . FORTIGATE_MODE . ' mode.');

    if (empty($_SERVER['HTTPS'])) {
        $items[] = array('warn', 'Plain HTTP', 'Sign-ins to this page are not encrypted.');
    }
    if (APP_DEBUG) {
        $items[] = array('warn', 'Debug mode on', 'Set APP_DEBUG to false in config.php.');
    }

    return $items;
}

/* --- reports ----------------------------------------------------- */

/**
 * Report catalogue. cols are label, type (t text, n number) and Excel width.
 * Mobile and MRD numbers are text so leading zeros and long digits survive.
 */
function gw_reports()
{
    $reports = array(
        'daily_connected' => array(
            'title' => 'Daily connected guests',
            'desc'  => 'Every guest who connected, with device, times and session length.',
            'cols'  => array(
                array('Date', 't', 12), array('Mobile', 't', 13), array('MRD No', 't', 10),
                array('Device', 't', 15), array('MAC address', 't', 19), array('IP address', 't', 15),
                array('Connected', 't', 10), array('Ended', 't', 10), array('Minutes', 'n', 9),
                array('Status', 't', 23),
            ),
        ),
        'daily_summary' => array(
            'title' => 'Daily summary',
            'desc'  => 'One line per day: guests, devices, logins, OTPs and failures.',
            'cols'  => array(
                array('Date', 't', 12), array('Guests', 'n', 9), array('Devices', 'n', 9),
                array('Sessions', 'n', 10), array('Avg minutes', 'n', 12), array('OTPs requested', 'n', 15),
                array('OTPs verified', 'n', 14), array('SMS failed', 'n', 11), array('Failed attempts', 'n', 15),
            ),
        ),
        'monthly_summary' => array(
            'title'   => 'Monthly summary',
            'desc'    => 'One line per month: how many guests connected, for management.',
            'monthly' => true,
            'cols'    => array(
                array('Month', 't', 12), array('Guests', 'n', 9), array('Devices', 'n', 9),
                array('Sessions', 'n', 10), array('Avg minutes', 'n', 12), array('Total hours', 'n', 12),
            ),
        ),
        'guest_usage' => array(
            'title' => 'Guest usage',
            'desc'  => 'One line per mobile number, busiest first.',
            'cols'  => array(
                array('Mobile', 't', 13), array('MRD No', 't', 10), array('Sessions', 'n', 10),
                array('Devices', 'n', 9), array('Total minutes', 'n', 14),
                array('First connected', 't', 19), array('Last connected', 't', 19),
            ),
        ),
        'login_attempts' => array(
            'title' => 'Login attempts',
            'desc'  => 'Every allowed and refused attempt, with the reason.',
            'cols'  => array(
                array('Time', 't', 19), array('Mobile', 't', 13), array('Result', 't', 9),
                array('Reason', 't', 28), array('Device', 't', 22), array('IP address', 't', 15),
            ),
        ),
        'otp_requests' => array(
            'title' => 'OTP requests',
            'desc'  => 'OTPs issued and what became of each one.',
            'cols'  => array(
                array('Time', 't', 19), array('Mobile', 't', 13), array('MRD No', 't', 10),
                array('Status', 't', 12), array('Wrong tries', 'n', 11), array('Verified at', 't', 19),
                array('Device', 't', 22), array('IP address', 't', 15),
            ),
        ),
        'sms_delivery' => array(
            'title' => 'SMS delivery',
            'desc'  => 'What was handed to the SMS gateway and how it answered.',
            'cols'  => array(
                array('Time', 't', 19), array('Mobile', 't', 13), array('Driver', 't', 8),
                array('Result', 't', 8), array('HTTP', 'n', 6), array('Gateway response', 't', 60),
            ),
        ),
        'blocked' => array(
            'title'  => 'Blocked numbers',
            'desc'   => 'The current block list, with reasons. Not limited by date.',
            'ranged' => false,
            'cols'   => array(
                array('Mobile', 't', 13), array('Status', 't', 11), array('Reason', 't', 50),
                array('Blocked', 't', 19), array('Unblocked', 't', 19),
            ),
        ),
    );

    if (gw_can('audit.view')) {
        $reports['audit'] = array(
            'title' => 'Admin audit trail',
            'desc'  => 'Sign-ins and every change made in this console.',
            'cols'  => array(
                array('Time', 't', 19), array('User', 't', 14), array('Action', 't', 24),
                array('Target', 't', 22), array('Detail', 't', 44), array('IP address', 't', 15),
            ),
        );
    }

    return $reports;
}

/** Validated period for a report, defaulting to today and capped in length. */
function gw_report_range()
{
    $filter = gw_admin_filter();
    $to     = $filter['to'] !== '' ? $filter['to'] : date('Y-m-d');
    $from   = $filter['from'] !== '' ? $filter['from'] : $to;

    $floor = date('Y-m-d', strtotime($to . ' -' . (GW_REPORT_MAX_DAYS - 1) . ' days'));
    return array(max($from, $floor), $to);
}

/** Rows of a report as plain arrays, in the order of its cols. */
function gw_report_rows($key, $from, $to)
{
    $start = $from . ' 00:00:00';
    $end   = gw_next_day($to) . ' 00:00:00';
    $pdo   = db();

    switch ($key) {
        case 'daily_connected':
            $stmt = $pdo->prepare(
                'SELECT * FROM guest_sessions WHERE started_at >= ? AND started_at < ? ORDER BY started_at'
            );
            $stmt->execute(array($start, $end));
            while ($row = $stmt->fetch()) {
                $online = (int) $row['active'] === 1;
                yield array(
                    substr($row['started_at'], 0, 10), $row['mobile'], $row['mrd_no'],
                    gw_device_label($row['user_agent']), gw_device_ref($row['mac_address'], $row['device_id']),
                    $row['ip_address'], substr($row['started_at'], 11),
                    $online ? '' : substr(gw_session_end_time($row), 11),
                    gw_session_minutes($row),
                    $online ? 'Online' : gw_end_label($row['end_reason']),
                );
            }
            return;

        case 'daily_summary':
            $days = array();
            for ($d = $from; $d <= $to; $d = gw_next_day($d)) {
                $days[$d] = array('guests' => 0, 'devices' => 0, 'sessions' => 0, 'minutes' => 0,
                                  'otps' => 0, 'verified' => 0, 'sms_failed' => 0, 'failed' => 0);
            }

            $now  = gw_ts();
            $stmt = $pdo->prepare(
                'SELECT DATE(s.started_at) d, COUNT(DISTINCT s.mobile) guests,
                        COUNT(DISTINCT s.device_id) devices, COUNT(*) sessions,
                        SUM(' . gw_sql_session_minutes() . ') minutes
                   FROM guest_sessions s WHERE s.started_at >= ? AND s.started_at < ?
                  GROUP BY DATE(s.started_at)'
            );
            $stmt->execute(array($now, $start, $end));
            foreach ($stmt->fetchAll() as $row) {
                $days[$row['d']] = array_merge($days[$row['d']], array(
                    'guests' => (int) $row['guests'], 'devices' => (int) $row['devices'],
                    'sessions' => (int) $row['sessions'], 'minutes' => (int) $row['minutes'],
                ));
            }

            $stmt = $pdo->prepare(
                "SELECT DATE(created_at) d, COUNT(*) otps, SUM(status = 'verified') verified
                   FROM otp_requests WHERE created_at >= ? AND created_at < ? GROUP BY DATE(created_at)"
            );
            $stmt->execute(array($start, $end));
            foreach ($stmt->fetchAll() as $row) {
                $days[$row['d']]['otps']     = (int) $row['otps'];
                $days[$row['d']]['verified'] = (int) $row['verified'];
            }

            $stmt = $pdo->prepare(
                'SELECT DATE(created_at) d, COUNT(*) c FROM sms_log
                  WHERE ok = 0 AND created_at >= ? AND created_at < ? GROUP BY DATE(created_at)'
            );
            $stmt->execute(array($start, $end));
            foreach ($stmt->fetchAll() as $row) {
                $days[$row['d']]['sms_failed'] = (int) $row['c'];
            }

            $stmt = $pdo->prepare(
                'SELECT DATE(created_at) d, COUNT(*) c FROM login_history
                  WHERE success = 0 AND created_at >= ? AND created_at < ? GROUP BY DATE(created_at)'
            );
            $stmt->execute(array($start, $end));
            foreach ($stmt->fetchAll() as $row) {
                $days[$row['d']]['failed'] = (int) $row['c'];
            }

            foreach ($days as $date => $day) {
                yield array(
                    $date, $day['guests'], $day['devices'], $day['sessions'],
                    $day['sessions'] > 0 ? (int) round($day['minutes'] / $day['sessions']) : 0,
                    $day['otps'], $day['verified'], $day['sms_failed'], $day['failed'],
                );
            }
            return;

        // Counted per month, not added up from days: a guest who came on two
        // days is one guest for the month.
        case 'monthly_summary':
            $months = array();
            $last   = substr($to, 0, 7);
            for ($m = substr($from, 0, 7); $m <= $last; $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
                $months[$m] = array('guests' => 0, 'devices' => 0, 'sessions' => 0, 'minutes' => 0);
            }

            $stmt = $pdo->prepare(
                'SELECT DATE_FORMAT(s.started_at, \'%Y-%m\') m, COUNT(DISTINCT s.mobile) guests,
                        COUNT(DISTINCT s.device_id) devices, COUNT(*) sessions,
                        SUM(' . gw_sql_session_minutes() . ') minutes
                   FROM guest_sessions s WHERE s.started_at >= ? AND s.started_at < ?
                  GROUP BY DATE_FORMAT(s.started_at, \'%Y-%m\')'
            );
            $stmt->execute(array(gw_ts(), $start, $end));
            foreach ($stmt->fetchAll() as $row) {
                $months[$row['m']] = array(
                    'guests' => (int) $row['guests'], 'devices' => (int) $row['devices'],
                    'sessions' => (int) $row['sessions'], 'minutes' => (int) $row['minutes'],
                );
            }

            foreach ($months as $month => $row) {
                yield array(
                    date('M Y', strtotime($month . '-01')), $row['guests'], $row['devices'], $row['sessions'],
                    $row['sessions'] > 0 ? (int) round($row['minutes'] / $row['sessions']) : 0,
                    (int) round($row['minutes'] / 60),
                );
            }
            return;

        case 'guest_usage':
            $stmt = $pdo->prepare(
                'SELECT s.mobile, SUBSTRING_INDEX(GROUP_CONCAT(s.mrd_no ORDER BY s.started_at DESC), \',\', 1) mrd_no,
                        COUNT(*) sessions, COUNT(DISTINCT s.device_id) devices,
                        SUM(' . gw_sql_session_minutes() . ') minutes,
                        MIN(s.started_at) first_seen, MAX(s.started_at) last_seen
                   FROM guest_sessions s WHERE s.started_at >= ? AND s.started_at < ?
                  GROUP BY s.mobile ORDER BY sessions DESC, last_seen DESC'
            );
            $stmt->execute(array(gw_ts(), $start, $end));
            while ($row = $stmt->fetch()) {
                yield array($row['mobile'], $row['mrd_no'], (int) $row['sessions'], (int) $row['devices'],
                            (int) $row['minutes'], $row['first_seen'], $row['last_seen']);
            }
            return;

        case 'login_attempts':
            $stmt = $pdo->prepare(
                'SELECT * FROM login_history WHERE created_at >= ? AND created_at < ? ORDER BY id'
            );
            $stmt->execute(array($start, $end));
            while ($row = $stmt->fetch()) {
                yield array($row['created_at'], $row['mobile'], (int) $row['success'] === 1 ? 'Allowed' : 'Refused',
                            gw_reason_label($row['reason']), $row['device_id'], $row['ip_address']);
            }
            return;

        case 'otp_requests':
            $stmt = $pdo->prepare(
                'SELECT * FROM otp_requests WHERE created_at >= ? AND created_at < ? ORDER BY id'
            );
            $stmt->execute(array($start, $end));
            while ($row = $stmt->fetch()) {
                yield array($row['created_at'], $row['mobile'], $row['mrd_no'], $row['status'],
                            (int) $row['attempts'], $row['verified_at'],
                            gw_device_ref($row['mac_address'], $row['device_id']), $row['ip_address']);
            }
            return;

        case 'sms_delivery':
            $stmt = $pdo->prepare(
                'SELECT created_at, mobile, driver, ok, http_code, response FROM sms_log
                  WHERE created_at >= ? AND created_at < ? ORDER BY id'
            );
            $stmt->execute(array($start, $end));
            while ($row = $stmt->fetch()) {
                yield array($row['created_at'], $row['mobile'], $row['driver'], (int) $row['ok'] === 1 ? 'Sent' : 'Failed',
                            $row['http_code'] === null ? '' : (int) $row['http_code'],
                            mb_substr((string) $row['response'], 0, 200));
            }
            return;

        case 'blocked':
            $stmt = $pdo->query('SELECT * FROM blocked_mobiles ORDER BY active DESC, blocked_at DESC');
            while ($row = $stmt->fetch()) {
                yield array($row['mobile'], (int) $row['active'] === 1 ? 'Blocked' : 'Unblocked', $row['reason'],
                            $row['blocked_at'], $row['unblocked_at']);
            }
            return;

        case 'audit':
            $stmt = $pdo->prepare('SELECT * FROM admin_audit WHERE created_at >= ? AND created_at < ? ORDER BY id');
            $stmt->execute(array($start, $end));
            while ($row = $stmt->fetch()) {
                yield array($row['created_at'], $row['username'], gw_audit_label($row['action']),
                            $row['target'], $row['detail'], $row['ip_address']);
            }
            return;
    }
}
