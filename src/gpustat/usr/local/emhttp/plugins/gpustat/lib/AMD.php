<?php

namespace gpustat\lib;

/**
 * Class AMD
 * @package gpustat\lib
 */
class AMD extends Main
{
    const CMD_UTILITY = 'radeontop';
    const INVENTORY_UTILITY = 'lspci';
    const INVENTORY_PARAM = ' -mm | grep VGA';
    const INVENTORY_REGEX =
        '/^(?P<id>(?P<busid>[0-9a-f]{2}):[0-9a-f]{2}\.[0-9a-f])\s+\"(?P<device>.+)\"\s+\"(?P<vendor>.+)\"\s+\"(?P<model>.+)\"\s+(?:-\w+\s+){2}(?:\"(?P<manufacturer>.+)\"\s+\"(?P<product>.+)\"|())/imU';

    const STATISTICS_PARAM = '-d - -l 1';
    const STATISTICS_KEYMAP = [
        'gpu' => ['util'],
        'ee' => ['event'],
        'vgt' => ['vertex'],
        'ta' => ['texture'],
        'sx' => ['shaderexp'],
        'sh' => ['sequencer'],
        'spi' => ['shaderinter'],
        'sc' => ['scancon'],
        'pa' => ['primassem'],
        'db' => ['depthblk'],
        'cb' => ['colorblk'],
        'uvd' => ['uvd'],
        'vce0' => ['vce0'],
        'vram' => ['memusedutil', 'memused'],
        'gtt' => ['gttusedutil', 'gttused'],
        'mclk' => ['memclockutil', 'memclock', 'clocks'],
        'sclk' => ['clockutil', 'clock', 'clocks'],
    ];

    const TEMP_UTILITY = 'sensors';
    const TEMP_PARAM = '-j 2>errors';

    const LSPCI = 'lspci';
    const LSPCI_REGEX =
        '/^.+LnkCap:\s.*?,\s[Speed]*\s(?P<pcie_speedmax>.*),\s[Width]*\s(?P<pcie_widthmax>.*),.*+\n.+LnkSta:\s[Speed]*\s(?P<pcie_speed>.*)(\s(?P<pcie_downspeed>.*))?,\s[Width]*\s(?P<pcie_width>.*)(\s(?P<pcie_downwidth>.*))?\n.+Kernel driver in use:\s(?P<driver>.*)$/imU';

    const LSPCI_REGEX2 = '/^.*-\[(?P<pcie1>.*)(?:-(?P<pcie2>.*))?\]-/imU';

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
                    foreach ($this->inventory as $gpu) {
                        if ($gpu['vendor'] != "Advanced Micro Devices, Inc. [AMD/ATI]")
                            continue;
                        $result[$gpu['id']] = [
                            'id' => $gpu['id'],
                            'vendor' => 'amd',
                            'model' => (string) ($gpu['product'] ?? $gpu['model']),
                            'guid' => $gpu['busid'],
                            'bridge_chip' => ($this->getpciebridge($gpu['busid']))['bridge_chip'],
                        ];
                    }
                }
            }
        }
        if ($this->settings['UIDEBUG']) {
            $inventory["FAKE_amd"] = [
                'vendor' => 'amd',
                'id' => "FAKE_amd",
                'model' => 'Radeon RX 6800/6800 XT / 6900 XT',
                'guid' => 'FAKE_amd',
                'bridge_chip' => NULL,
            ];
            $result = array_merge($result, $inventory);
        }

        return $result;
    }

    /**
     * Retrieves AMD APU/GPU statistics
     */
    public function getStatistics(array $gpu)
    {

        if ($gpu['id'] === "FAKE_amd") {
            $this->stdout = file_get_contents(__DIR__ . '/../sample/amd-radeontop-stdout.txt');
        } else if ($this->cmdexists) {
            //Command invokes radeontop in STDOUT mode with an update limit of half a second @ 120 samples per second
            $command = sprintf("%0s -b %1s", self::CMD_UTILITY, $gpu['id']);
            $this->runCommand($command, self::STATISTICS_PARAM, false);
        }
        if (!empty($this->stdout) && strlen($this->stdout) > 0) {
            $this->parseStatistics($gpu);
        } else {
            $this->pageData['error'][] += Error::get(Error::VENDOR_DATA_NOT_RETURNED);
        }
        return json_encode($this->pageData);

    }

    /**
     * Retrieves AMD APU/GPU Temperature/Fan/Power/Voltage readings from lm-sensors
     * @returns array
     */
    private function getSensorData(string $gpubus): array
    {
        $sensors = [];

        if ($gpubus === "FAKE_amd") {
            $this->stdout = file_get_contents(__DIR__ . '/../sample/amd-sensors-stdout.txt');
        } else {
            $chip = sprintf('amdgpu-pci-%1s00', $gpubus);

            $this->checkCommand(self::TEMP_UTILITY, false);
            if ($this->cmdexists) {
                $tempFormat = '';
                if ($this->settings['TEMPFORMAT'] == 'F') {
                    $tempFormat = '-f';
                }
                $command = sprintf('%0s %1s %2s', self::TEMP_UTILITY, $chip, $tempFormat);
                $this->runCommand($command, self::TEMP_PARAM, false);
            }
        }
        if (!empty($this->stdout) && strlen($this->stdout) > 0) {
            $data = json_decode($this->stdout, true);

            if (isset($data[$chip])) {
                $data = $data[$chip];
                if (isset($data['edge']['temp1_input'])) {
                    $sensors['temp'] = $this->roundFloat($data['edge']['temp1_input']) . ' °' . $this->settings['TEMPFORMAT'];
                    if (isset($data['edge']['temp1_crit'])) {
                        $sensors['tempmax'] = $this->roundFloat($data['edge']['temp1_crit']);
                    }
                }

                if (isset($data['fan1']['fan1_input'])) {
                    $sensors['fan'] = $this->roundFloat($data['fan1']['fan1_input']);
                    if (isset($data['fan1']['fan1_max'])) {
                        $sensors['fanmax'] = $this->roundFloat($data['fan1']['fan1_max']);
                    }
                }

                if (isset($data['power1']['power1_average'])) {
                    $sensors['power'] = $this->roundFloat($data['power1']['power1_average'], 1);
                    if (isset($data['power1']['power1_cap'])) {
                        $sensors['powermax'] = $this->roundFloat($data['power1']['power1_cap'], 1);
                    }
                } else if (isset($data['PPT']['power1_average'])) {
                    $sensors['power'] = $this->roundFloat($data['PPT']['power1_average'], 1);
                    if (isset($data['PPT']['power1_cap'])) {
                        $sensors['powermax'] = $this->roundFloat($data['PPT']['power1_cap'], 1);
                    }
                }

                if (isset($data['vddgfx']['in0_input'])) {
                    $sensors['voltage'] = $this->roundFloat($data['vddgfx']['in0_input'], 2);
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
    public function getpciedata($gpu): array
    {
        $result = [];
        $bridge = [];
        $gpubus = $gpu['guid'];
        $bridgebus = $gpu['bridge_chip']; //$this->getpciebridge($this->praseGPU($gpu)[3])['bridge_chip'];

        if ($gpubus === "FAKE_amd") {
            $this->stdout = file_get_contents(__DIR__ . '/../sample/amd-lspci-stdout.txt');
            $this->lspci_gpu = $this->parseInventory(self::LSPCI_REGEX);
        } else if ($this->cmdexists) {
            $this->checkCommand(self::INVENTORY_UTILITY, false);
            $param = sprintf(' -vv -s %s: -s .0 | grep -P "LnkSta:|LnkCap:|Kernel driver in use"', $gpubus);
            $this->runCommand(self::INVENTORY_UTILITY, $param, false);
            if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                $this->lspci_gpu = $this->parseInventory(self::LSPCI_REGEX);
            }
            if (($bridgebus != NULL) || ($bridgebus != '')) {
                $param = sprintf(' -vv -s %s: -s .0 | grep -P "LnkSta:|LnkCap:|Kernel driver in use"', $bridgebus);
                $this->runCommand(self::INVENTORY_UTILITY, $param, false);
                if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                    $this->lspci_bridge = $this->parseInventory(self::LSPCI_REGEX);
                }
                if (!empty($this->lspci_bridge)) {
                    foreach ($this->lspci_bridge as $br) {
                        $bridge = [
                            'pcie_speedmax' => $br['pcie_speedmax'],
                            'pcie_widthmax' => $br['pcie_widthmax'],
                            'pcie_speed' => $br['pcie_speed'],
                            'pcie_width' => $br['pcie_width'],
                        ];
                    }
                }
            }
        }

        if (!empty($this->lspci_gpu)) {
            foreach ($this->lspci_gpu as $gpu) {
                $result = [
                    'pciegenmax' => (int) $this->prasePCIEgen(floatval(isset($bridge['pcie_speedmax']) ? $bridge['pcie_speedmax'] : $gpu['pcie_speedmax'])),
                    'pciewidthmax' => (isset($bridge['pcie_widthmax']) ? $bridge['pcie_widthmax'] : $gpu['pcie_widthmax']),
                    'pciegen' => (int) $this->prasePCIEgen(floatval(isset($bridge['pcie_speed']) ? $bridge['pcie_speed'] : $gpu['pcie_speed'])),
                    'pciewidth' => (isset($bridge['pcie_width']) ? $bridge['pcie_width'] : $gpu['pcie_width']),
                    'driver' => $gpu['driver'],
                    'passedthrough' => ($gpu['driver'] == 'vfio-pci' ? "Passthrough" : "Normal"),
                    'bridge_bus' => $bridgebus,
                ];
            }
        }
        return $result;
    }
    public function getpciebridge(string $gpubus): array
    {
        $result = [];
        if ($this->cmdexists) {
            $this->checkCommand(self::LSPCI, false);
            if ($this->cmdexists) {
                $param = sprintf(' -vvt | grep -m 1 -E "\[%s\]"', $gpubus);
                $this->runCommand(self::LSPCI, $param, false);
                if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                    $this->lspci_bridge = $this->parseInventory(self::LSPCI_REGEX2);
                }
                if (!empty($this->lspci_bridge)) {
                    foreach ($this->lspci_bridge as $bridge) {
                        $result = [
                            'pcie1' => $bridge['pcie1'],
                            'pcie2' => (isset($bridge['pcie2']) ? $bridge['pcie2'] : $bridge['pcie1']),
                            'bridge_chip' => (isset($bridge['pcie2']) ? $bridge['pcie1'] : NULL),
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
    private function parseStatistics($gpu)
    {
        $this->pageData += [
            'vendor' => 'AMD',
            'name' => $gpu['model'],
            'event' => 'N/A',
            'vertex' => 'N/A',
            'texture' => 'N/A',
            'shaderexp' => 'N/A',
            'sequencer' => 'N/A',
            'shaderinter' => 'N/A',
            'scancon' => 'N/A',
            'primassem' => 'N/A',
            'depthblk' => 'N/A',
            'colorblk' => 'N/A',
            'perfstate' => 'N/A',
            'throttled' => 'N/A',
            'thrtlrsn' => 'N/A',
            'util' => 'N/A',
        ];

        $this->pageData += [
            'powerunit' => 'W',
            'fanunit' => 'RPM',
            'voltageunit' => 'V',
            'tempunit' => $this->settings['TEMPFORMAT'],
        ];

        // radeontop data doesn't follow a standard object format -- need to parse CSV and then explode by spaces
        $data = explode(", ", substr($this->stdout, strpos($this->stdout, 'gpu')));
        $count = count($data);
        if ($count > 0) {
            foreach ($data as $metric) {
                // metric util% value
                $fields = explode(" ", $metric);
                if (isset(self::STATISTICS_KEYMAP[$fields[0]])) {
                    $values = self::STATISTICS_KEYMAP[$fields[0]];

                    $fields[1] = str_replace(PHP_EOL, '', $fields[1]);
                    $fields1_clean = preg_split('/(?<=[0-9])(?=[a-z]+)/i', $fields[1]);
                    $this->pageData[$values[0]] = $fields1_clean[0];
                    if (isset($fields1_clean[1])) {
                        $this->pageData[$values[0] . 'unit'] = $fields1_clean[1];
                    }
                    if (isset($fields[2])) {
                        $fields[2] = str_replace(PHP_EOL, '', $fields[2]);
                        $fields2_clean = preg_split('/(?<=[0-9])(?=[a-z]+)/i', $fields[2]);
                        $this->pageData[$values[1]] = $fields2_clean[0];
                        if (isset($fields2_clean[1])) {
                            $this->pageData[$values[1] . 'unit'] = $fields2_clean[1];
                        }
                    }

                    if (
                        ($fields[0] == 'vram') || ($fields[0] == 'gtt') ||
                        ($fields[0] == 'mclk') || ($fields[0] == 'sclk')
                    ) {
                        $this->pageData[$values[1] . 'max'] = $this->roundFloat(floatval((($fields2_clean[0]) * 100 / ($fields1_clean[0]))), 2);
                    }
                    if ($fields[0] == 'gpu') {
                        // GPU Load doesn't have a setting, for now just pass the check
                        $this->pageData[$values[0]] = $this->roundFloat(floatval($fields[1]), 1) . '%';
                    }
                }
            }
            unset($data, $this->stdout);
        } else {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_NOT_ENOUGH, "Count: $count");
        }

        $this->pageData = array_merge($this->pageData, $this->getSensorData($gpu['guid']));
        $this->pageData = array_merge($this->pageData, $this->getpciedata($gpu));

    }
}