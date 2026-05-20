<?php
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);

ob_start();

// =====================
// AUTOLOAD
// =====================
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// =====================
// INPUT
// =====================
$network = $_GET['network'] ?? '';
$levels  = $_GET['levels'] ?? '';
$type    = $_GET['type'] ?? 'txt';

if (!$network || !$levels) {
    die("Fehlende Parameter");
}

$levels = array_map('intval', explode(',', $levels));

list($ip, $prefix) = explode('/', $network);
$prefix = intval($prefix);

$isIPv6 = strpos($ip, ':') !== false;

// =====================
// ESTIMATE
// =====================
$estimated = 1;
$prev = $prefix;

foreach ($levels as $lvl) {
    $estimated *= pow(2, $lvl - $prev);
    $prev = $lvl;
}

// =====================
// XLSX LIMIT (WICHTIG!)
// =====================
if ($type === "xlsx" && $estimated > 1000000) {
    die("Zu viele Daten für XLSX! Bitte CSV nutzen.");
}

// =====================
// XLSX INIT
// =====================
$spreadsheet = null;
$sheet = null;
$row = 2;

if ($type === "xlsx") {

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    $sheet->setCellValue('A1', 'Level');
    $sheet->setCellValue('B1', 'Network');
    $sheet->setCellValue('C1', 'Parent');

    $sheet->getStyle('A1:C1')->getFont()->setBold(true);
}

// =====================
// HEADER (nur für CSV/TXT)
// =====================
if ($type === "csv") {

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="subnets.csv"');

    echo "\xEF\xBB\xBF";
    echo "Level;Network;Parent\n";

} elseif ($type === "txt") {

    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="subnets.txt"');
}

// =====================
// IPv6
// =====================
function generateIPv6($baseIP, $startPrefix, $targetPrefix, $index) {

    $base = unpack("H*", inet_pton($baseIP))[1];
    $parts = str_split($base, 4);

    $bitPos = $startPrefix;
    $diff = $targetPrefix - $startPrefix;

    for ($b = $diff - 1; $b >= 0; $b--) {

        $bit = ($index >> $b) & 1;

        $i = floor($bitPos / 16);
        $offset = 15 - ($bitPos % 16);

        $num = hexdec($parts[$i]);
        $num |= ($bit << $offset);
        $parts[$i] = str_pad(dechex($num), 4, '0', STR_PAD_LEFT);

        $bitPos++;
    }

    return inet_ntop(inet_pton(implode(':', $parts)));
}

// =====================
// IPv4
// =====================
function ipToInt($ip) { return sprintf('%u', ip2long($ip)); }
function intToIP($int) { return long2ip($int); }

// =====================
// GENERATOR
// =====================
function generateAll($ip, $prefix, $levels, $levelIndex = 0) {

    global $isIPv6, $type, $sheet, $row;

    $target = $levels[$levelIndex];
    $diff   = $target - $prefix;
    $count  = pow(2, $diff);

    for ($i = 0; $i < $count; $i++) {

        // Netz berechnen
        if ($isIPv6) {
            $net = generateIPv6($ip, $prefix, $target, $i);
        } else {
            $base = ipToInt($ip);
            $size = pow(2, 32 - $target);
            $net  = intToIP($base + ($i * $size));
        }

        // =====================
        // OUTPUT
        // =====================
        if ($type === "csv") {

            echo $target . ";" . $net . "/$target" . ";" . $ip . "/$prefix\n";

        } elseif ($type === "xlsx") {

            $sheet->setCellValue("A$row", $target);
            $sheet->setCellValue("B$row", $net . "/$target");
            $sheet->setCellValue("C$row", $ip . "/$prefix");

            $row++;

        } else {

            echo "/" . $target . " - " . $net . "/$target\n";
        }

        // =====================
        // REKURSION
        // =====================
        if (isset($levels[$levelIndex + 1])) {
            generateAll($net, $target, $levels, $levelIndex + 1);
        }

        // =====================
        // STREAM (nur TXT/CSV)
        // =====================
        if ($type !== "xlsx" && $i % 1000 === 0) {
            ob_flush();
            flush();
        }
    }
}

// =====================
// START
// =====================
generateAll($ip, $prefix, $levels);

// =====================
// XLSX OUTPUT
// =====================
if ($type === "xlsx") {

    ob_end_clean();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="subnets.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');

    exit;
}