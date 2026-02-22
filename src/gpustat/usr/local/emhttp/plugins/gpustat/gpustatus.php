<?php
/*
 * gpustatus.php — GPU Statistics data endpoint.
 * 3 paths: AJAX (browser), settings page include, CLI tool.
 * Flow: _args → fetchGPUStatistics() → Vendor::getStatistics() → JSON
 */
include 'lib/Main.php';
include 'lib/Nvidia.php';
include 'lib/Intel.php';
include 'lib/AMD.php';
include 'lib/Error.php';
use gpustat\lib\AMD;
use gpustat\lib\Main;
use gpustat\lib\Nvidia;
use gpustat\lib\Intel;
use gpustat\lib\Error;

if (!isset($gpustat_cfg)) {
    $gpustat_cfg = Main::getSettings();
}

// Fetch stats for each GPU via vendor class, preserve panel/stats from input
function fetchGPUStatistics($array)
{
    global $gpustat_cfg;
    $data = array();
    if (!is_array($array))
        return $data;
    foreach ($array as $gpu) {
        $gpustat_cfg["VENDOR"] = $gpu['vendor'];
        $gpustat_cfg["GPUID"] = $gpu['id'];

        switch (strtolower($gpu['vendor'])) {
            case 'amd':
                $return = (new AMD($gpustat_cfg))->getStatistics($gpu);
                break;
            case 'intel':
                $return = (new Intel($gpustat_cfg))->getStatistics($gpu);
                break;
            case 'nvidia':
                $return = (new Nvidia($gpustat_cfg))->getStatistics($gpu);
                break;
            default:
                print_r(Error::get(Error::CONFIG_SETTINGS_NOT_VALID));
        }

        $decode = json_decode($return, true);
        isset($gpu['panel']) ? $decode["panel"] = $gpu['panel'] : '';
        isset($gpu['stats']) ? $decode["stats"] = $gpu['stats'] : '';
        $data[$gpu["id"]] = $decode;
    }
    return $data;
}

// ─── Path 1: CLI ─────────────────────────────────────────────────────────────
// Usage: php gpustatus.php [--inventory] [--vendor=amd|nvidia|intel]
if (php_sapi_name() === 'cli') {
    $cliArgs = getopt('', ['inventory', 'vendor:']);
    $filterVendor = isset($cliArgs['vendor']) ? strtolower($cliArgs['vendor']) : null;

    $gpustat_cfg['inventory'] = true;
    $inventory_nvidia = (new Nvidia($gpustat_cfg))->getInventorym();
    $inventory_intel = (new Intel($gpustat_cfg))->getInventory();
    $inventory_amd = (new AMD($gpustat_cfg))->getInventory();
    $all = array_merge($inventory_nvidia, $inventory_intel, $inventory_amd);

    if (isset($cliArgs['inventory'])) {
        echo json_encode($all, JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }

    if ($filterVendor !== null) {
        $all = array_filter($all, function ($gpu) use ($filterVendor) {
            return strtolower($gpu['vendor']) === $filterVendor;
        });
    }

    $panel = 0;
    foreach ($all as $id => $gpu) {
        $all[$id]['panel'] = $panel++;
        $all[$id]['stats'] = [];
    }

    $data = fetchGPUStatistics($all);
    echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

// ─── Path 2: Settings page include ($gpustat_inventory = true) ───────────────
if (isset($gpustat_inventory) && $gpustat_inventory) {
    $gpustat_cfg['inventory'] = true;
    $inventory_nvidia = (new Nvidia($gpustat_cfg))->getInventorym();
    $inventory_intel = (new Intel($gpustat_cfg))->getInventory();
    $inventory_amd = (new AMD($gpustat_cfg))->getInventory();
    $gpustat_data = array_merge($inventory_nvidia, $inventory_intel, $inventory_amd);
    $gpustat_pool = fetchGPUStatistics($gpustat_data);
}
// ─── Path 3: AJAX web request from gpustat.js ────────────────────────────────
else {
    $array = json_decode($_GET['gpus'], true);
    $data = fetchGPUStatistics($array);
    $data['_debug'] = intval($gpustat_cfg['UIDEBUG'] ?? 0); // JS debug logging flag

    $json = json_encode($data);
    header('Content-Type: application/json');
    header('Content-Length: ' . strlen($json));
    echo $json;

    // Debug dump — only write to /tmp when UIDEBUG is on
    if (!empty($gpustat_cfg['UIDEBUG'])) {
        file_put_contents("/tmp/gpujson", "Time = " . date(DATE_RFC2822) . "\n");
        file_put_contents("/tmp/gpujson", $json . "\n", FILE_APPEND);
    }
}