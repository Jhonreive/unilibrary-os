<?php
/**
 * Reports -> Excel export.
 * Builds a real .xlsx workbook (Summary, Books, Members, Transactions) using
 * PHP's built-in ZipArchive - no extra libraries needed. If the zip extension
 * is switched off, it falls back to a CSV of all transactions.
 */
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require_login();
require_perm('reports.view');

refresh_overdue($pdo);
$settings = get_settings($pdo);
$rate     = (float) $settings['fine_rate'];

$books   = $pdo->query('SELECT * FROM books ORDER BY id ASC')->fetchAll();
$members = $pdo->query('SELECT * FROM members ORDER BY id ASC')->fetchAll();
$txs     = $pdo->query('SELECT t.*, b.title AS book_title FROM transactions t
                        LEFT JOIN books b ON b.id = t.book_id ORDER BY t.id ASC')->fetchAll();

/* ---------- numbers for the summary sheet ---------- */
$totalCopies = $availCopies = 0;
$byGenre = [];
foreach ($books as $b) {
    $totalCopies += (int) $b['copies'];
    $availCopies += (int) $b['available'];
    $out = (int) $b['copies'] - (int) $b['available'];
    $byGenre[$b['genre']] = ($byGenre[$b['genre']] ?? 0) + $out;
}
arsort($byGenre);
$overdue  = count(array_filter($txs, fn($t) => $t['status'] === 'overdue'));
$returned = count(array_filter($txs, fn($t) => $t['status'] === 'returned'));
$active   = count(array_filter($txs, fn($t) => $t['status'] === 'active'));
$totalFines = 0.0;
foreach ($txs as $t) $totalFines += tx_fine($t, $rate);
$onTime = count($txs) ? round(($returned / count($txs)) * 100) : 0;
$types = [];
foreach ($members as $m) $types[$m['type']] = ($types[$m['type']] ?? 0) + 1;

$stamp = date('Y-m-d');
$file  = 'UniLibrary_Report_' . $stamp . '.xlsx';

/* ---------- cell helpers: S = text, N = number, M = money ---------- */
function S($v, $style = 0) { return ['s', (string) $v, $style]; }
function N($v, $style = 0) { return ['n', $v, $style]; }
function M($v)             { return ['n', (float) $v, 2]; }

/* ---------- sheets ---------- */
$sheets = [];

$sum = [
    [S('UniLibrary OS - Library Report', 3)],
    [S('Library', 4), S($settings['library_name'])],
    [S('Generated', 4), S(date('F j, Y  g:i A'))],
    [],
    [S('OVERVIEW', 1), S('', 1)],
    [S('Book titles'), N(count($books))],
    [S('Total copies'), N($totalCopies)],
    [S('Copies available'), N($availCopies)],
    [S('Copies on loan'), N($totalCopies - $availCopies)],
    [S('Members'), N(count($members))],
    [S('Total transactions'), N(count($txs))],
    [S('Active loans'), N($active)],
    [S('Overdue loans'), N($overdue)],
    [S('Returned loans'), N($returned)],
    [S('On-time return rate'), S($onTime . '%')],
    [S('Outstanding fines'), M($totalFines)],
    [],
    [S('CHECKOUTS BY GENRE', 1), S('Copies out', 1)],
];
foreach ($byGenre as $g => $c) $sum[] = [S($g), N($c)];
if (!$byGenre) $sum[] = [S('No active loans yet.')];
$sum[] = [];
$sum[] = [S('MEMBERS BY TYPE', 1), S('Count', 1)];
foreach ($types as $tname => $c) $sum[] = [S($tname), N($c)];
$sheets[] = ['name' => 'Summary', 'rows' => $sum, 'widths' => [30, 26], 'header' => false];

$rows = [[S('Code', 1), S('ISBN', 1), S('Title', 1), S('Author', 1), S('Genre', 1), S('Copies', 1), S('Available', 1), S('On Loan', 1)]];
foreach ($books as $b) {
    $rows[] = [S(book_code($b['id'])), S($b['isbn'] ?? ''), S($b['title']), S($b['author']), S($b['genre']),
               N((int) $b['copies']), N((int) $b['available']), N((int) $b['copies'] - (int) $b['available'])];
}
$sheets[] = ['name' => 'Books', 'rows' => $rows, 'widths' => [10, 16, 40, 30, 22, 9, 11, 9], 'header' => true];

$rows = [[S('Code', 1), S('School ID', 1), S('Name', 1), S('Type', 1), S('Email', 1), S('Joined', 1), S('Status', 1)]];
foreach ($members as $m) {
    $rows[] = [S(member_code($m['id'])), S($m['school_id']), S($m['name']), S($m['type']), S($m['email']), S($m['joined']), S(ucfirst($m['status']))];
}
$sheets[] = ['name' => 'Members', 'rows' => $rows, 'widths' => [10, 14, 28, 11, 32, 12, 10], 'header' => true];

$rows = [[S('Loan ID', 1), S('Book', 1), S('Member', 1), S('Borrowed', 1), S('Due', 1), S('Returned', 1), S('Status', 1), S('Fine', 1)]];
foreach ($txs as $t) {
    $rows[] = [S(tx_code($t['id'])), S($t['book_title'] ?? ''), S($t['member_name']), S($t['borrow_date']), S($t['due_date']),
               S($t['return_date'] ?? ''), S(ucfirst($t['status'])), M(tx_fine($t, $rate))];
}
$sheets[] = ['name' => 'Transactions', 'rows' => $rows, 'widths' => [11, 38, 28, 12, 12, 12, 11, 12], 'header' => true];

/* ---------- fallback: no zip extension -> CSV of transactions ---------- */
if (!class_exists('ZipArchive')) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="UniLibrary_Transactions_' . $stamp . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($sheets[3]['rows'] as $r) {
        fputcsv($out, array_map(function ($c) {
            $v = (string) $c[1];
            return ($v !== '' && strpos('=+-@', $v[0]) !== false) ? "'" . $v : $v;
        }, $r));
    }
    exit;
}

/* ---------- xlsx writer ---------- */
function x($v) {
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $v);
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
function col($i) { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; }

function sheet_xml(array $sh) {
    $rowsXml = '';
    $maxCol = 0;
    foreach ($sh['rows'] as $ri => $row) {
        if (!$row) { $rowsXml .= '<row r="' . ($ri + 1) . '"/>'; continue; }
        $cells = '';
        foreach ($row as $ci => $c) {
            $maxCol = max($maxCol, $ci);
            $ref = col($ci) . ($ri + 1);
            [$type, $val, $style] = $c;
            if ($type === 'n') {
                $cells .= '<c r="' . $ref . '" s="' . $style . '"><v>' . (float) $val . '</v></c>';
            } else {
                $cells .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . x($val) . '</t></is></c>';
            }
        }
        $ht = ($ri === 0 && $sh['header']) ? ' ht="24" customHeight="1"' : '';
        $rowsXml .= '<row r="' . ($ri + 1) . '"' . $ht . '>' . $cells . '</row>';
    }
    $cols = '';
    foreach ($sh['widths'] as $i => $w) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    $view = $sh['header']
        ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        : '<sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews>';
    $filter = ($sh['header'] && count($sh['rows']) > 1)
        ? '<autoFilter ref="A1:' . col($maxCol) . count($sh['rows']) . '"/>' : '';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . $view . '<cols>' . $cols . '</cols><sheetData>' . $rowsXml . '</sheetData>' . $filter . '</worksheet>';
}

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
 . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
 . '<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;&#8369;&quot;#,##0.00"/></numFmts>'
 . '<fonts count="4">'
 .   '<font><sz val="11"/><name val="Calibri"/></font>'
 .   '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
 .   '<font><b/><sz val="18"/><color rgb="FF6B4F38"/><name val="Calibri"/></font>'
 .   '<font><b/><sz val="11"/><color rgb="FF6B4F38"/><name val="Calibri"/></font>'
 . '</fonts>'
 . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
 .   '<fill><patternFill patternType="solid"><fgColor rgb="FF8A6F55"/><bgColor indexed="64"/></patternFill></fill></fills>'
 . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
 . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
 . '<cellXfs count="5">'
 .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
 .   '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf>'
 .   '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
 .   '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
 .   '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
 . '</cellXfs>'
 . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
 . '</styleSheet>';

$ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
$wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
$wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
$names = '';
foreach ($sheets as $i => $sh) {
    $n = $i + 1;
    $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $wb .= '<sheet name="' . x($sh['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
    $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    if ($sh['header'] && count($sh['rows']) > 1) {
        $names .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\'' . x($sh['name']) . '\'!$A$1:$' . col(count($sh['widths']) - 1) . '$' . count($sh['rows']) . '</definedName>';
    }
}
$sn = count($sheets) + 1;
$wbRels .= '<Relationship Id="rId' . $sn . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
$wb .= '</sheets>' . ($names ? '<definedNames>' . $names . '</definedNames>' : '') . '</workbook>';
$ct .= '</Types>';
$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';

$tmp = tempnam(sys_get_temp_dir(), 'ulx');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    flash_set('Could not build the Excel file. Please try again.');
    header('Location: reports.php');
    exit;
}
$zip->addFromString('[Content_Types].xml', $ct);
$zip->addFromString('_rels/.rels', $rootRels);
$zip->addFromString('xl/workbook.xml', $wb);
$zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
$zip->addFromString('xl/styles.xml', $styles);
foreach ($sheets as $i => $sh) $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', sheet_xml($sh));
$zip->close();

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
unlink($tmp);
exit;
