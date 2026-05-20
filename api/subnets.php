<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

// INPUT
$network = $_GET['network'] ?? '2001:db8::/48';
$levels  = $_GET['levels'] ?? '56,64';
$offset  = intval($_GET['offset'] ?? 0);
$limit   = intval($_GET['limit'] ?? 20);

$levels = array_map('intval', explode(',', $levels));

// SPLIT
list($ip, $prefix) = explode('/', $network);
$prefix = intval($prefix);

// TYPE
$isIPv6 = strpos($ip, ':') !== false;

// ================= IPv6 =================
function compressIPv6($parts) {
    return inet_ntop(inet_pton(implode(':', $parts)));
}

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

    return $parts;
}

function lastIPv6($parts, $prefix) {

    $bits = $prefix;

    for ($i = 0; $i < 8; $i++) {
        if ($bits >= 16) {
            $bits -= 16;
            continue;
        }

        $mask = (1 << (16 - $bits)) - 1;
        $num = hexdec($parts[$i]);
        $num |= $mask;

        $parts[$i] = str_pad(dechex($num), 4, '0', STR_PAD_LEFT);
        $bits = 0;
    }

    return $parts;
}

function firstIPv6($parts, $prefix) {

    $result = $parts;
    $lastIndex = 7;

    $num = hexdec($result[$lastIndex]);
    $num += 1;

    $result[$lastIndex] = str_pad(dechex($num), 4, '0', STR_PAD_LEFT);

    return $result;
}

// ================= IPv4 =================
function ipToInt($ip) {
    return sprintf('%u', ip2long($ip));
}

function intToIP($int) {
    return long2ip($int);
}

// ================= MAIN =================
$data = [];
$lvl1 = $levels[0];

// ================= LEVEL 1 =================
if ($isIPv6) {

    $diff1 = $lvl1 - $prefix;
    $count1 = pow(2, $diff1);

    $start = $offset;
    $end = min($offset + $limit, $count1);

    $lvl1PartsList = [];

    for ($i = $start; $i < $end; $i++) {

        $parts = generateIPv6($ip, $prefix, $lvl1, $i);
        $lvl1PartsList[] = $parts;

        $data[] = [
            "level" => $lvl1,
            "network" => compressIPv6($parts) . "/$lvl1",
            "raw" => implode(':', $parts),
            "first" => compressIPv6(firstIPv6($parts, $lvl1)),
            "last" => compressIPv6(lastIPv6($parts, $lvl1))
        ];
    }

    // ================= LEVEL 2 =================
    if (isset($levels[1])) {

        $lvl2 = $levels[1];
        $diff2 = $lvl2 - $lvl1;
        $count2 = pow(2, $diff2);

        foreach ($lvl1PartsList as $parentParts) {

            $parentIP = implode(':', $parentParts);

            for ($j = 0; $j < min($limit, $count2); $j++) {

                $parts = generateIPv6($parentIP, $lvl1, $lvl2, $j);

                $data[] = [
                    "level" => $lvl2,
                    "network" => compressIPv6($parts) . "/$lvl2",
                    "raw" => implode(':', $parts),
                    "first" => compressIPv6(firstIPv6($parts, $lvl2)),
                    "last" => compressIPv6(lastIPv6($parts, $lvl2))
                ];
            }
        }
    }

} else {

// ================= IPv4 =================

    $base = ipToInt($ip);

    // Netzwerk korrekt maskieren
    $mask = ~((1 << (32 - $prefix)) - 1);
    $base = $base & $mask;

    $lvl1 = $levels[0];

    $diff1 = $lvl1 - $prefix;
    $count1 = pow(2, $diff1);

    $start = $offset;
    $end = min($offset + $limit, $count1);

    $lvl1List = [];

    // ===== LEVEL 1 =====
    for ($i = $start; $i < $end; $i++) {

        $size = pow(2, 32 - $lvl1);
        $net = $base + ($i * $size);

        $lvl1List[] = $net;

        $data[] = [
            "level" => $lvl1,
            "network" => intToIP($net) . "/$lvl1",
            "first" => intToIP($net + 1),
            "last" => intToIP($net + $size - 1)
        ];
    }

    // ===== LEVEL 2 =====
    if (isset($levels[1])) {

        $lvl2 = $levels[1];
        $diff2 = $lvl2 - $lvl1;
        $count2 = pow(2, $diff2);

        foreach ($lvl1List as $parentNet) {

            for ($j = 0; $j < min($limit, $count2); $j++) {

                $size = pow(2, 32 - $lvl2);
                $net = $parentNet + ($j * $size);

                $data[] = [
                    "level" => $lvl2,
                    "network" => intToIP($net) . "/$lvl2",
                    "first" => intToIP($net + 1),
                    "last" => intToIP($net + $size - 1)
                ];
            }
        }
    }
}

// OUTPUT
echo json_encode([
    "network" => $network,
    "levels" => $levels,
    "offset" => $offset,
    "limit" => $limit,
    "data" => $data
]);