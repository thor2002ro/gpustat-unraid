<?php

const ES = ' ';

include 'lib/Main.php';
include 'lib/Nvidia.php';
include 'lib/Intel.php';
include 'lib/AMD.php';
include 'lib/Error.php';
include 'lib/FakeNvidia.php';
include 'lib/FakeIntel.php';

use gpustat\lib\AMD;
use gpustat\lib\Main;
use gpustat\lib\Nvidia;
use gpustat\lib\Intel;
use gpustat\lib\Error;

use gpustat\lib\FakeIntel;
use gpustat\lib\FakeNvidia;

if (!isset($gpustat_cfg)) {
    $gpustat_cfg = Main::getSettings();
}

// $gpustat_inventory should be set if called from settings page code
if (isset($gpustat_inventory) && $gpustat_inventory) {
    $gpustat_cfg['inventory'] = true;
    // Settings page looks for $gpustat_data specifically -- inventory all supported GPU types
    $inventory_nvidia = (new Nvidia($gpustat_cfg))->getInventory();
    $inventory_intel = (new Intel($gpustat_cfg))->getInventory();
    $inventory_amd = (new AMD($gpustat_cfg))->getInventory();

    if ($gpustat_cfg["UIDEBUG"] === '1') {
        $inventory_FAKEintel = (new FakeIntel($gpustat_cfg))->getInventory();
        $inventory_FAKEnvidia = (new FakeNvidia($gpustat_cfg))->getInventory();
        $inventory_nvidia = array_merge($inventory_nvidia, $inventory_FAKEnvidia);
        $inventory_intel = array_merge($inventory_intel, $inventory_FAKEintel);
    }

    $gpustat_data = array_merge($inventory_nvidia, $inventory_intel, $inventory_amd);
} else {
    if (PHP_SAPI === 'cli') {
        $argument1 = $argv[1];
    } else {
        $argument1 = $_GET['argv'];
    }

    $GPUNR = '';
    if (isset($argument1)) {
        $GPUNR = $argument1;
    } else {
        $GPUNR = '1';
    }

    if (!isset($gpustat_cfg["GPU{$GPUNR}"])) {
        echo "not found GPU{$GPUNR}";
    }

    $gpu_vendor = strtolower(Main::praseGPU($gpustat_cfg["GPU{$GPUNR}"])[0]);
    switch ($gpu_vendor) {
        case 'amd':
            (new AMD($gpustat_cfg))->getStatistics($gpustat_cfg["GPU{$GPUNR}"]);
            break;
        case 'intel':
            if ($gpustat_cfg["UIDEBUG"] === '1') {
                (new FakeIntel($gpustat_cfg))->getStatistics($gpustat_cfg["GPU{$GPUNR}"]);
            } else {
                (new Intel($gpustat_cfg))->getStatistics($gpustat_cfg["GPU{$GPUNR}"]);
            }
            break;
        case 'nvidia':
            if ($gpustat_cfg["UIDEBUG"] === '1') {
                (new FakeNvidia($gpustat_cfg))->getStatistics($gpustat_cfg["GPU{$GPUNR}"]);
            } else {
                (new Nvidia($gpustat_cfg))->getStatistics($gpustat_cfg["GPU{$GPUNR}"]);
            }
            break;
        default:
            print_r(Error::get(Error::CONFIG_SETTINGS_NOT_VALID));
    }
}
