<?php

/*
  MIT License

  Copyright (c) 2020-2022 b3rs3rk

  Permission is hereby granted, free of charge, to any person obtaining a copy
  of this software and associated documentation files (the "Software"), to deal
  in the Software without restriction, including without limitation the rights
  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
  copies of the Software, and to permit persons to whom the Software is
  furnished to do so, subject to the following conditions:

  The above copyright notice and this permission notice shall be included in all
  copies or substantial portions of the Software.

  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
  SOFTWARE.
*/

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
    $gpustat_data1 = (new Nvidia($gpustat_cfg))->getInventory();
    $gpustat_data1 += (new Intel($gpustat_cfg))->getInventory();
    $gpustat_data1 += (new AMD($gpustat_cfg))->getInventory();

    $gpustat_data2 = (new Nvidia($gpustat_cfg))->getInventory();
    $gpustat_data2 += (new Intel($gpustat_cfg))->getInventory();
    $gpustat_data2 += (new AMD($gpustat_cfg))->getInventory();

    $gpustat_data3 = (new Nvidia($gpustat_cfg))->getInventory();
    $gpustat_data3 += (new Intel($gpustat_cfg))->getInventory();
    $gpustat_data3 += (new AMD($gpustat_cfg))->getInventory();
} else {
    if (PHP_SAPI === 'cli') {
        $argument1 = $argv[1];
    }
    else {
        $argument1 = $_GET['argv'];
    }

    $GPUNR='';
    if (isset($argument1)) {
        $GPUNR=$argument1;
    } else {
        $GPUNR='1';
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
            (new Intel($gpustat_cfg))->getStatistics($gpustat_cfg["GPU{$GPUNR}"]);
            break;
        case 'nvidia':
            (new Nvidia($gpustat_cfg))->getStatistics($gpustat_cfg["GPU{$GPUNR}"]);
            break;
        default:
            print_r(Error::get(Error::CONFIG_SETTINGS_NOT_VALID));
    }
}

