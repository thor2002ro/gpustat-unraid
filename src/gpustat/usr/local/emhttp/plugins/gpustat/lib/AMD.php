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

namespace gpustat\lib;

/**
 * Class AMD
 * @package gpustat\lib
 */
class AMD extends Main
{
    const CMD_UTILITY = 'radeontop';
    const INVENTORY_UTILITY = 'lspci';
    const INVENTORY_PARAM = '| grep VGA';
    const INVENTORY_REGEX =
        '/^(?P<busid>[0-9a-f]{2}).*\[AMD(\/ATI)?\]\s+(?P<model>.+)\s+(\[(?P<product>.+)\]|\()/imU';

    const STATISTICS_PARAM = '-d - -l 1';
    const STATISTICS_KEYMAP = [
        'gpu'   => ['util'],
        'ee'    => ['event'],
        'vgt'   => ['vertex'],
        'ta'    => ['texture'],
        'sx'    => ['shaderexp'],
        'sh'    => ['sequencer'],
        'spi'   => ['shaderinter'],
        'sc'    => ['scancon'],
        'pa'    => ['primassem'],
        'db'    => ['depthblk'],
        'cb'    => ['colorblk'],
        'vram'  => ['memutil', 'memused'],
        'gtt'   => ['gfxtrans', 'transused'],
        'mclk'  => ['memclockutil', 'memclock', 'clocks'],
        'sclk'  => ['clockutil', 'clock', 'clocks'],
    ];

    const TEMP_UTILITY = 'sensors';
    const TEMP_PARAM = '-j 2>errors';

    const LSPCI_PARAM = ' -vv -s ';
    const LSPCI_PARAM2 = ': -s .0 | grep -P "LnkSta:|LnkCap:|Kernel driver in use"';
    const LSPCI_REGEX =
        '/^.+LnkCap:\s.*?,\s[Speed]*\s(?P<pcie_speedmax>.*),\s[Width]*\s(?P<pcie_widthmax>.*),.*+\n.+LnkSta:\s[Speed]*\s(?P<pcie_speed>.*)\s\((?P<pcie_downspeed>.*)\),\s[Width]*\s(?P<pcie_width>.*)\s\((?P<pcie_downwidth>.*)\).*+\n.+Kernel driver in use:\s(?P<driver>.*)\n/imU';

    /**
     * AMD constructor.
     * @param array $settings
     */
    public function __construct(array $settings = [])
    {
        $settings += ['cmd' => self::CMD_UTILITY];
        parent::__construct($settings);
    }

    /**
     * Retrieves AMD inventory using lspci and returns an array
     *
     * @return array
     */
    public function getInventory(): array
    {
        $result = [];

        if ($this->cmdexists) {
            $this->checkCommand(self::INVENTORY_UTILITY, false);
            if ($this->cmdexists) {
                $this->runCommand(self::INVENTORY_UTILITY, self::INVENTORY_PARAM, false);
                if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                    $this->inventory = $this->parseInventory(self::INVENTORY_REGEX);
                }
                if (!empty($this->inventory)) {
                    foreach ($this->inventory AS $gpu) {
                        $result[] = [
                            'id'    => "Bus ID " . $gpu['busid'],
                            'model' => (string) ($gpu['product'] ?? $gpu['model']),
                            'guid'  => $gpu['busid'],
                        ];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Retrieves AMD APU/GPU statistics
     * 
     * 
     * $this->settings['GPUID']
     */
    public function getStatistics(string $gpu)
    {
        if ($this->cmdexists) {
            //Command invokes radeontop in STDOUT mode with an update limit of half a second @ 120 samples per second
            $command = sprintf("%0s -b %1s", self::CMD_UTILITY, $this->praseGPU($gpu)[1]);
            $this->runCommand($command, self::STATISTICS_PARAM, false);
            if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                $this->parseStatistics($gpu);
            } else {
                $this->pageData['error'][] += Error::get(Error::VENDOR_DATA_NOT_RETURNED);
            }
        }
    }

    /**
     * Retrieves AMD APU/GPU Temperature/Fan/Power/Voltage readings from lm-sensors
     * @returns array
     */
    private function getSensorData(string $gpubus): array
    {
        $sensors = [];

        $this->checkCommand(self::TEMP_UTILITY, false);
        if ($this->cmdexists) {
            $tempFormat = '';
            if ($this->settings['TEMPFORMAT'] == 'F') {
                $tempFormat = '-f';
            }
            $chip = sprintf('amdgpu-pci-%1s00', $gpubus);
            $command = sprintf('%0s %1s %2s', self::TEMP_UTILITY, $chip, $tempFormat);
            $this->runCommand($command, self::TEMP_PARAM, false);
            if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                $data = json_decode($this->stdout, true);
                if(isset($data[$chip])) {
                    $data = $data[$chip];
                    if ($this->settings['DISPTEMP']) {
                        if (isset($data['edge']['temp1_input'])) {
                            $sensors['tempunit'] = $this->settings['TEMPFORMAT'];
                            $sensors['temp'] = $this->roundFloat($data['edge']['temp1_input']) . ' °' . $sensors['tempunit'];
                            if (isset($data['edge']['temp1_crit'])) {
                                $sensors['tempmax'] = $this->roundFloat($data['edge']['temp1_crit']);
                            }
                        }
                    }
                    if ($this->settings['DISPFAN']) {
                        if (isset($data['fan1']['fan1_input'])) {
                            $sensors['fan_raw'] = $this->roundFloat($data['fan1']['fan1_input']);
                            if (isset($data['fan1']['fan1_max'])) {
                                $sensors['fanmax_raw'] = $this->roundFloat($data['fan1']['fan1_max']);
                            }
                            $sensors['fanunit'] = 'rpm';
                            $sensors['fan'] = ($this->roundFloat($sensors['fan_raw'] / $sensors['fanmax_raw'] * 100)) . "%";
                        }
                    }
                    if ($this->settings['DISPPWRDRAW']) {
                        if (isset($data['power1']['power1_average'])) {
                            $sensors['power_raw'] = $this->roundFloat($data['power1']['power1_average'], 1);
                            if (isset($data['power1']['power1_cap'])) {
                                $sensors['powermax_raw'] = $this->roundFloat($data['power1']['power1_cap'], 1);
                            }
                        } else if (isset($data['PPT']['power1_average'])) {
                            $sensors['power_raw'] = $this->roundFloat($data['PPT']['power1_average'], 1);
                            if (isset($data['PPT']['power1_cap'])) {
                                $sensors['powermax_raw'] = $this->roundFloat($data['PPT']['power1_cap'], 1);
                            }                            
                        }
                        $sensors['powerunit'] = 'w';
                        $sensors['power'] = ($this->roundFloat($sensors['power_raw'] / $sensors['powermax_raw'] * 100)) . "%";

                        if (isset($data['vddgfx']['in0_input'])) {
                            $sensors['voltageunit'] = 'v';
                            $sensors['voltage'] = $this->roundFloat($data['vddgfx']['in0_input'], 2);
                        }
                    }
                }
            }
        }

        return $sensors;
    }

    /**
     * Retrieves pcie and driver from lspci and returns an array
     *
     * @return array
     */
    public function getpciedata(string $gpubus): array
    {
        $result = [];
        if ($this->cmdexists) {
            $this->checkCommand(self::INVENTORY_UTILITY, false);
            if ($this->cmdexists) {
                $this->runCommand(self::INVENTORY_UTILITY, sprintf("%s%s%s", self::LSPCI_PARAM, $gpubus, self::LSPCI_PARAM2), false);
                if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                    $this->gpu_lspci = $this->parseInventory(self::LSPCI_REGEX);
                }
                if (!empty($this->gpu_lspci)) {
                    foreach ($this->gpu_lspci AS $gpu) {
                        $result = [
                            'pciegenmax'        => (int) $this->prasePCIEgen(floatval($gpu['pcie_speedmax'])),
                            'pciewidthmax'      => $gpu['pcie_widthmax'],
                            'pciegen'           => (int) $this->prasePCIEgen(floatval($gpu['pcie_speed'])),
                            'pcie_downspeed'    => (int) ($gpu['pcie_downspeed'] == 'ok' ? 0 : 1),
                            'pciewidth'         => $gpu['pcie_width'],
                            'pcie_downwidth'    => (int) ($gpu['pcie_downwidth'] == 'ok' ? 0 : 1),
                            'driver'            => $gpu['driver'],
                            'passedthrough'     => ($gpu['driver'] == 'vfio-pci' ? "Passthrough" : "Normal"),
                        ];
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Loads radeontop STDOUT and parses into an associative array for mapping to plugin variables
     */
    private function parseStatistics(string $gpu)
    {
        $this->pageData += [
            'vendor'        => 'AMD',
            'name'          => $this->praseGPU($gpu)[0],
            'event'         => 'N/A',
            'vertex'        => 'N/A',
            'texture'       => 'N/A',
            'shaderexp'     => 'N/A',
            'sequencer'     => 'N/A',
            'shaderinter'   => 'N/A',
            'scancon'       => 'N/A',
            'primassem'     => 'N/A',
            'depthblk'      => 'N/A',
            'colorblk'      => 'N/A',
            'perfstate'     => 'N/A',
            'throttled'     => 'N/A',
            'thrtlrsn'      => '',
        ];

        // radeontop data doesn't follow a standard object format -- need to parse CSV and then explode by spaces
        $data = explode(", ", substr($this->stdout, strpos($this->stdout, 'gpu')));
        $count = count($data);
        if ($count > 0) {
            foreach ($data AS $metric) {
                // metric util% value
                $fields = explode(" ", $metric);
                if (isset(self::STATISTICS_KEYMAP[$fields[0]])) {
                    $values = self::STATISTICS_KEYMAP[$fields[0]];
                    if ($this->settings['DISP' . strtoupper($values[0])] || $this->settings['DISP' . strtoupper($values[2])]) {
                        $this->pageData[$values[0]] = $this->roundFloat($this->stripText('%', $fields[1]), 1) . '%';
                        if (isset($fields[2])) {
                            $this->pageData[$values[1]] = $this->roundFloat(
                                trim(
                                    $this->stripText(
                                        ['mb','ghz'],
                                        $fields[2]
                                    )
                                ), 2
                            );
                        }
                    } elseif ($fields[0] == 'gpu') {
                        // GPU Load doesn't have a setting, for now just pass the check
                        $this->pageData[$values[0]] = $this->roundFloat($this->stripText('%', $fields[1]), 1) . '%';
                    }
                }
            }
            unset($data, $this->stdout);
        } else {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_NOT_ENOUGH, "Count: $count");
        }
        $this->pageData = array_merge($this->pageData, $this->getSensorData($this->praseGPU($gpu)[1]));

        $this->pageData = array_merge($this->pageData, $this->getpciedata($this->praseGPU($gpu)[1]));

        $this->echoJson();
    }
}
