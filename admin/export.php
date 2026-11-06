<?php
/**
 * Report downloads: Excel, CSV and a print view (the browser's Save as PDF).
 * All three read gw_report_rows(), so they always agree.
 */

defined('GW_ADMIN') or exit;

function gw_export_filename($key, $from, $to, $ext)
{
    $reports = gw_reports();
    $name    = 'guest-wifi-' . str_replace('_', '-', $key);
    if (!isset($reports[$key]['ranged']) || $reports[$key]['ranged']) {
        $name .= '-' . $from . ($to !== $from ? '-to-' . $to : '');
    }
    return $name . '.' . $ext;
}

function gw_export_period($report, $from, $to)
{
    if (isset($report['ranged']) && !$report['ranged']) {
        return 'As of ' . date('d M Y H:i');
    }
    $text = date('d M Y', strtotime($from));
    return $from === $to ? $text : $text . ' to ' . date('d M Y', strtotime($to));
}

/** Handles page=export. Sends the file, or returns if the request is invalid. */
function gw_export($key, $format)
{
    // Guest data: refuse here too, whatever the caller checked.
    if (!gw_can('reports.export')) {
        return;
    }

    $reports = gw_reports();
    if (!isset($reports[$key]) || !in_array($format, array('xlsx', 'csv', 'print'), true)) {
        return;
    }

    list($from, $to) = gw_report_range();
    gw_audit('report.export', $reports[$key]['title'], $format . ', ' . $from . ' to ' . $to);

    if ($format === 'csv') {
        gw_export_csv($key, $reports[$key], $from, $to);
    } elseif ($format === 'xlsx') {
        gw_export_xlsx($key, $reports[$key], $from, $to);
    } else {
        gw_export_print($key, $reports[$key], $from, $to);
    }
    exit;
}

/**
 * Guest data reaches a spreadsheet through these cells, so anything Excel
 * would run as a formula is forced to text.
 */
function gw_csv_cell($value)
{
    if (is_int($value)) {
        return $value;
    }
    $value = (string) $value;
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

function gw_export_csv($key, $report, $from, $to)
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . gw_export_filename($key, $from, $to, 'csv') . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // lets Excel read it as UTF-8

    $labels = array();
    foreach ($report['cols'] as $col) {
        $labels[] = $col[0];
    }
    fputcsv($out, $labels);

    foreach (gw_report_rows($key, $from, $to) as $row) {
        fputcsv($out, array_map('gw_csv_cell', $row));
    }
    fclose($out);
}

function gw_xml($value)
{
    // Control characters are invalid in XML and would make Excel refuse the file.
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value);
    return htmlspecialchars($clean, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function gw_xlsx_col($index)
{
    $name = '';
    for ($n = $index + 1; $n > 0; $n = (int) (($n - 1) / 26)) {
        $name = chr(65 + (($n - 1) % 26)) . $name;
    }
    return $name;
}

/** $style: 0 plain, 1 title, 2 subtitle, 3 header. */
function gw_xlsx_row($number, $cells, $types, $style = 0)
{
    $xml = '<row r="' . $number . '">';
    foreach ($cells as $i => $value) {
        $ref = gw_xlsx_col($i) . $number;
        if ($types !== null && $types[$i] === 'n' && is_int($value)) {
            $xml .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
        } else {
            $xml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t>' . gw_xml($value) . '</t></is></c>';
        }
    }
    return $xml . '</row>';
}

/**
 * Sends the workbook. Its temp files hold guest data, so they are removed
 * even when building it fails, or the browser goes away during the download.
 */
function gw_export_xlsx($key, $report, $from, $to)
{
    $sheet = tempnam(sys_get_temp_dir(), 'gwx');
    $file  = tempnam(sys_get_temp_dir(), 'gwz');

    try {
        gw_xlsx_build($key, $report, $from, $to, $sheet, $file);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . gw_export_filename($key, $from, $to, 'xlsx') . '"');
        header('Content-Length: ' . filesize($file));

        ignore_user_abort(true);   // an abort would end the script and skip the cleanup
        readfile($file);
    } finally {
        @unlink($sheet);
        @unlink($file);
    }
}

/** A real workbook, so mobile and MRD numbers stay text and keep their zeros. */
function gw_xlsx_build($key, $report, $from, $to, $sheet, $file)
{
    $user   = gw_admin_user();
    $count  = count($report['cols']);
    $last   = gw_xlsx_col($count - 1);
    $types  = array();
    $labels = array();
    $widths = '';

    foreach ($report['cols'] as $i => $col) {
        $labels[] = $col[0];
        $types[]  = $col[1];
        $widths  .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $col[2] . '" customWidth="1"/>';
    }

    $out = fopen($sheet, 'w');

    // Closed on failure too: Windows will not delete a file that is still open.
    try {
        fwrite($out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0" showGridLines="0">'
            . '<pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $widths . '</cols><sheetData>');

        fwrite($out, gw_xlsx_row(1, array(APP_ORG . ' - ' . $report['title']), null, 1));
        fwrite($out, gw_xlsx_row(2, array(gw_export_period($report, $from, $to) . '. Generated '
            . date('d M Y H:i') . ' by ' . $user['full_name'] . '. Confidential.'), null, 2));
        fwrite($out, gw_xlsx_row(4, $labels, null, 3));

        $number = 4;
        foreach (gw_report_rows($key, $from, $to) as $row) {
            $number++;
            fwrite($out, gw_xlsx_row($number, $row, $types));
        }

        fwrite($out, '</sheetData><autoFilter ref="A4:' . $last . $number . '"/>'
            . '<pageSetup orientation="landscape" fitToHeight="0"/></worksheet>');
    } finally {
        fclose($out);
    }

    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Could not build the workbook.');
    }

    $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $rel  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml';

    $zip->addFromString('[Content_Types].xml', $head
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="' . $mime . '.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="' . $mime . '.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="' . $mime . '.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', $head
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', $head . '<workbook xmlns="' . $main . '" xmlns:r="' . $rel . '">'
        . '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets>'
        . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">'
        . 'Report!$A$4:$' . $last . '$' . $number . '</definedName></definedNames></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', $head
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="' . $rel . '/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', $head . '<styleSheet xmlns="' . $main . '">'
        . '<fonts count="4"><font><sz val="10"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="14"/><name val="Calibri"/></font>'
        . '<font><sz val="9"/><color rgb="FF5D7182"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0B2A3C"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="3" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
        . '</styleSheet>');
    $zip->addFile($sheet, 'xl/worksheets/sheet1.xml');
    $zip->close();
}

function gw_export_print($key, $report, $from, $to)
{
    $user = gw_admin_user();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="utf-8">
    <title><?php echo gw_e($report['title']); ?> &middot; <?php echo gw_e(APP_ORG); ?></title>
    <link rel="stylesheet" href="<?php echo gw_asset('admin.css'); ?>">
    <script src="<?php echo gw_asset('admin.js'); ?>" defer></script>
    </head>
    <body class="print-page" data-autoprint="1">
    <header class="print-head">
        <img src="<?php echo gw_asset('logo.png'); ?>" alt="">
        <div>
            <h1><?php echo gw_e($report['title']); ?></h1>
            <p><?php echo gw_e(APP_ORG); ?> &middot; <?php echo gw_e(APP_NAME); ?></p>
            <p><?php echo gw_e(gw_export_period($report, $from, $to)); ?> &middot;
               Generated <?php echo gw_e(date('d M Y H:i')); ?> by <?php echo gw_e($user['full_name']); ?></p>
        </div>
    </header>
    <table class="print-table">
        <thead><tr>
            <?php foreach ($report['cols'] as $col): ?>
                <th class="<?php echo $col[1] === 'n' ? 'num' : ''; ?>"><?php echo gw_e($col[0]); ?></th>
            <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php $rows = 0; foreach (gw_report_rows($key, $from, $to) as $row): $rows++; ?>
            <tr>
                <?php foreach ($row as $i => $cell): ?>
                    <td class="<?php echo $report['cols'][$i][1] === 'n' ? 'num' : ''; ?>"><?php echo gw_e($cell); ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="print-foot"><?php echo $rows; ?> <?php echo $rows === 1 ? 'row' : 'rows'; ?>. Confidential: contains guest contact details.</p>
    </body>
    </html>
    <?php
}
