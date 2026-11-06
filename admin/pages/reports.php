<?php
defined('GW_ADMIN') or exit;

$reports = gw_reports();
$key     = isset($_GET['report']) && isset($reports[$_GET['report']]) ? $_GET['report'] : 'daily_connected';
$report  = $reports[$key];
$ranged  = !isset($report['ranged']) || $report['ranged'];

list($from, $to) = gw_report_range();

$today   = date('Y-m-d');
$presets = array(
    'Today'      => array($today, $today),
    'Yesterday'  => array(date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))),
    'Last 7 days'  => array(date('Y-m-d', strtotime('-6 days')), $today),
    'Last 30 days' => array(date('Y-m-d', strtotime('-29 days')), $today),
    'This month' => array(date('Y-m-01'), $today),
    'Last month' => array(date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))),
    'Last 12 months' => array(date('Y-m-01', strtotime('first day of -11 months')), $today),
);

// Preview the first rows; the count covers everything the download will hold.
$preview = array();
$total   = 0;
foreach (gw_report_rows($key, $from, $to) as $row) {
    if ($total < 50) {
        $preview[] = $row;
    }
    $total++;
}

$download = array('report' => $key, 'from' => $from, 'to' => $to);

gw_ui_title('Reports', gw_can('reports.export')
    ? 'Preview a report, then download it for Excel or print it to PDF.'
    : 'Preview a report. Downloads are for administrators.');
?>
<div class="reports">
    <nav class="report-list" aria-label="Reports">
        <?php foreach ($reports as $k => $r): ?>
            <?php // A monthly report over today's date range would show one partial month. ?>
            <?php $range = isset($r['monthly']) ? $presets['Last 12 months'] : array($from, $to); ?>
            <a class="<?php echo $k === $key ? 'on' : ''; ?>"
               href="<?php echo gw_e(gw_url('reports', array('report' => $k, 'from' => $range[0], 'to' => $range[1]))); ?>">
                <b><?php echo gw_e($r['title']); ?></b>
                <span><?php echo gw_e($r['desc']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="report-body">
        <div class="card">
            <div class="card-head">
                <h2><?php echo gw_e($report['title']); ?></h2>
                <?php if (gw_can('reports.export')): ?>
                    <div class="report-actions">
                        <a class="btn-primary" href="<?php echo gw_e(gw_url('export', $download + array('format' => 'xlsx'))); ?>"><?php echo gw_icon('download'); ?>Excel</a>
                        <a class="btn-ghost" href="<?php echo gw_e(gw_url('export', $download + array('format' => 'csv'))); ?>"><?php echo gw_icon('download'); ?>CSV</a>
                        <a class="btn-ghost" href="<?php echo gw_e(gw_url('export', $download + array('format' => 'print'))); ?>" target="_blank" rel="noopener"><?php echo gw_icon('print'); ?>Print / PDF</a>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($ranged): ?>
                <div class="presets">
                    <?php foreach ($presets as $label => $range): ?>
                        <a class="preset<?php echo $range[0] === $from && $range[1] === $to ? ' on' : ''; ?>"
                           href="<?php echo gw_e(gw_url('reports', array('report' => $key, 'from' => $range[0], 'to' => $range[1]))); ?>"><?php echo gw_e($label); ?></a>
                    <?php endforeach; ?>
                </div>
                <form class="filters flat" method="get" action="admin.php">
                    <input type="hidden" name="page" value="reports">
                    <input type="hidden" name="report" value="<?php echo gw_e($key); ?>">
                    <label>From <input type="date" name="from" value="<?php echo gw_e($from); ?>" max="<?php echo gw_e($today); ?>"></label>
                    <label>To <input type="date" name="to" value="<?php echo gw_e($to); ?>" max="<?php echo gw_e($today); ?>"></label>
                    <button class="btn-ghost" type="submit">Apply</button>
                    <span class="muted">Up to <?php echo GW_REPORT_MAX_DAYS; ?> days.</span>
                </form>
            <?php endif; ?>
        </div>

        <div class="card card-flush">
            <div class="card-head">
                <h2><?php echo number_format($total); ?> <?php echo $total === 1 ? 'row' : 'rows'; ?></h2>
                <?php if ($total > 50): ?><span class="muted">Showing the first 50. The download has them all.</span><?php endif; ?>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr>
                        <?php foreach ($report['cols'] as $col): ?>
                            <th class="<?php echo $col[1] === 'n' ? 'num' : ''; ?>"><?php echo gw_e($col[0]); ?></th>
                        <?php endforeach; ?>
                    </tr></thead>
                    <tbody>
                    <?php if (!$preview) { gw_ui_empty(count($report['cols']), 'Nothing in this period.'); } ?>
                    <?php foreach ($preview as $row): ?>
                        <tr>
                            <?php foreach ($row as $i => $cell): ?>
                                <td class="<?php echo $report['cols'][$i][1] === 'n' ? 'num' : ''; ?>"><?php echo gw_e($cell); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
