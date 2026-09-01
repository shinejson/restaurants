<?php
// admin/printing/get_windows_printers.php
// AJAX endpoint: returns a JSON list of printers installed on the Windows host.
// Uses fast detection methods first (wmic, COM) and caches results for 5 minutes.
require_once '../../includes/admin_check.php';
require_once '../../config/db.php';

header('Content-Type: application/json');

$cache_file = sys_get_temp_dir() . '/restaurant_windows_printers.json';
$cache_ttl  = 300; // 5 minutes

// Serve from cache if fresh
if (is_file($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
    $cached = json_decode(file_get_contents($cache_file), true);
    if (is_array($cached)) {
        $cached['cached'] = true;
        echo json_encode($cached);
        exit();
    }
}

$printers = [];

if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {

    // Method 1: wmic (fast, available on most Windows versions)
    @exec('wmic printer get name /format:list 2>NUL', $wmic_out, $wmic_code);
    if ($wmic_code === 0 && !empty($wmic_out)) {
        foreach ($wmic_out as $line) {
            $line = trim($line);
            if (stripos($line, 'Name=') === 0) {
                $name = trim(substr($line, 5));
                if ($name !== '') {
                    $printers[] = $name;
                }
            }
        }
    }

    // Method 2: COM WScript.Network (fast, lists mapped/shared printers)
    if (empty($printers) && class_exists('COM')) {
        try {
            $wsh = new COM('WScript.Network');
            $conns = $wsh->EnumPrinterConnections();
            $count = $conns->Count();
            // Pairs: even index = port, odd index = printer name
            for ($i = 0; $i < $count; $i += 2) {
                $printers[] = (string) $conns->Item($i + 1);
            }
            unset($wsh);
        } catch (Exception $e) {
            // COM unavailable — ignore
        }
    }

    // Method 3: PowerShell Get-Printer (slower startup, but most complete list)
    if (empty($printers)) {
        @exec(
            'powershell -NoProfile -NonInteractive -Command "Get-Printer | Select-Object -ExpandProperty Name" 2>NUL',
            $ps_out,
            $ps_code
        );
        if ($ps_code === 0 && !empty($ps_out)) {
            foreach ($ps_out as $line) {
                $line = trim($line);
                if ($line !== '' && stripos($line, 'error') === false && stripos($line, 'get-printer') === false) {
                    $printers[] = $line;
                }
            }
        }
    }
}

$printers = array_values(array_unique(array_filter($printers)));
sort($printers, SORT_NATURAL | SORT_FLAG_CASE);

$response = [
    'success'  => !empty($printers),
    'count'    => count($printers),
    'printers' => $printers,
    'os'       => PHP_OS,
];

// Cache result (even empty lists, to avoid repeated slow scans)
@file_put_contents($cache_file, json_encode($response));

echo json_encode($response);