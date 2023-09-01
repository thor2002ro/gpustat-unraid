<?php

const ES = ' ';

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

// $gpustat_inventory should be set if called from settings page code
if (isset($gpustat_inventory) && $gpustat_inventory) {
    $gpustat_cfg['inventory'] = true;
    // Settings page looks for $gpustat_data specifically -- inventory all supported GPU types
    $inventory_nvidia = (new Nvidia($gpustat_cfg))->getInventorym();
    $inventory_intel = (new Intel($gpustat_cfg))->getInventory();
    $inventory_amd = (new AMD($gpustat_cfg))->getInventory();

    $gpustat_data = array_merge($inventory_nvidia, $inventory_intel, $inventory_amd);
} else {
    $array = json_decode($_GET['gpus'], true);

    $data = array();
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
        $decode["panel"] = $gpu['panel'];
        $decode["stats"] = $gpu['stats']; //passtrough which stats to display from config
        $data[$gpu["id"]] = $decode;
    }

    $json = json_encode($data);
    header('Content-Type: application/json');
    header('Content-Length: ' . ES . strlen($json));
    echo $json;
    file_put_contents("/tmp/gpujson", "Time = " . date(DATE_RFC2822) . "\n");
    file_put_contents("/tmp/gpujson", $json . "\n", FILE_APPEND);
}