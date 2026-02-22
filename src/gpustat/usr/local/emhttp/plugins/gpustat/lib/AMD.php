<?php

namespace gpustat\lib;

// AMD GPU statistics — uses radeontop or nvtop (utilization), sensors (temp/fan/power), lspci (PCIe/inventory)
class AMD extends Main
{
    const RADEONTOP_UTILITY = 'radeontop'; // Default utilization tool
    const NVTOP_UTILITY = 'nvtop'; // Alternative: comprehensive JSON output
    const NVTOP_PARAM = '-s'; // nvtop snapshot mode

    // Supported apps for process detection (nvtop path only, same list as Nvidia)
    const SUPPORTED_APPS = [
        'plex' => ['Plex Transcoder'],
        'jellyfin' => ['jellyfin-ffmpeg'],
        'handbrake' => ['/usr/bin/HandBrakeCLI'],
        'emby' => ['emby'],
        'tdarr' => ['ffmpeg', 'HandbrakeCLI'],
        'unmanic' => ['ffmpeg'],
        'dizquetv' => ['ffmpeg'],
        'ersatztv' => ['ffmpeg'],
        'fileflows' => ['ffmpeg'],
        'frigate' => ['ffmpeg'],
        'deepstack' => ['python3'],
        'nsfminer' => ['nsfminer'],
        'shinobipro' => ['shinobi'],
        'foldinghome' => ['FahCore'],
    ];
    const INVENTORY_UTILITY = 'lspci'; // GPU detection
    const INVENTORY_PARAM = ' -mm | grep VGA';
    const INVENTORY_REGEX =
        '/^(?P<id>(?P<busid>[0-9a-f]{2}):[0-9a-f]{2}\.[0-9a-f])\s+\"(?P<device>.+)\"\s+\"(?P<vendor>.+)\"\s+\"(?P<model>.+)\"\s+(?:-\w+\s+){2}(?:\"(?P<manufacturer>.+)\"\s+\"(?P<product>.+)\"|())/imU';

    const STATISTICS_PARAM = '-d - -l 1'; // radeontop: dump once to stdout
    // Map radeontop keys → pageData keys (1st=util%, 2nd=absolute value, 3rd=category)
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

    const TEMP_UTILITY = 'sensors'; // lm-sensors for temp/fan/power/voltage
    const TEMP_PARAM = '-j 2>errors';

    const LSPCI = 'lspci'; // PCIe link info
    // Regex: captures LnkCap (max speed/width) and LnkSta (current speed/width) + driver
    const LSPCI_REGEX =
        '/^.+LnkCap:\s.*?,\s[Speed]*\s(?P<pcie_speedmax>.*),\s[Width]*\s(?P<pcie_widthmax>.*),.*+\n.+LnkSta:\s[Speed]*\s(?P<pcie_speed>.*)(\s(?P<pcie_downspeed>.*))?,\s[Width]*\s(?P<pcie_width>.*)(\s(?P<pcie_downwidth>.*))?\n.+Kernel driver in use:\s(?P<driver>.*)$/imU';
    // Regex: parses lspci -vvt bus range to find bridge chip (e.g. "-[0a-0c]-")
    const LSPCI_REGEX2 = '/^.*-\[(?P<pcie1>.*)(?:-(?P<pcie2>.*))?\]-/imU';

    /**
     * AMD constructor.
     * @param array $settings
     */
    public function __construct(array $settings = [])
    {
        // Pick utility based on AMDUTILITY config (radeontop or nvtop)
        $utility = strtolower($settings['AMDUTILITY'] ?? 'radeontop');
        $settings += ['cmd' => ($utility === 'nvtop') ?self::NVTOP_UTILITY : self::RADEONTOP_UTILITY];
        parent::__construct($settings);
    }

    // Discover AMD GPUs via lspci, including bridge chip detection
    public function getInventory(): array
    {
        $result = [];

        if ($this->cmdexists) {
            $this->checkCommand(self::INVENTORY_UTILITY, false);
            if ($this->cmdexists) {
                $this->runCommand(self::INVENTORY_UTILITY, self::INVENTORY_PARAM, false);
                if (!empty($this->stdout)) {
                    $this->inventory = $this->parseInventory(self::INVENTORY_REGEX);
                }
                foreach ($this->inventory as $gpu) {
                    if ($gpu['vendor'] !== 'Advanced Micro Devices, Inc. [AMD/ATI]') {
                        continue;
                    }
                    $result[$gpu['id']] = [
                        'id' => $gpu['id'],
                        'vendor' => 'amd',
                        'model' => (string)($gpu['product'] ?? $gpu['model']),
                        'guid' => $gpu['busid'],
                        'bridge_chip' => $this->getpciebridge($gpu['busid'])['bridge_chip'] ?? null,
                    ];
                }
            }
        }

        if (!empty($this->settings['UIDEBUG'])) {
            $result['FAKE_amd'] = [
                'vendor' => 'amd',
                'id' => 'FAKE_amd',
                'model' => 'Radeon RX 6800/6800 XT / 6900 XT',
                'guid' => 'FAKE_amd',
                'bridge_chip' => null,
            ];
        }

        return $result;
    }

    // Get live stats: radeontop or nvtop → sensors → lspci PCIe data
    public function getStatistics(array $gpu): string
    {
        $useNvtop = strtolower($this->settings['AMDUTILITY'] ?? 'radeontop') === 'nvtop';

        if ($useNvtop) {
            // nvtop path: single JSON call for all metrics
            if ($gpu['id'] === 'FAKE_amd') {
                $this->stdout = (string)file_get_contents(__DIR__ . '/../sample/amd-nvtop-stdout.txt');
            }
            elseif ($this->cmdexists) {
                $this->runCommand(self::NVTOP_UTILITY, self::NVTOP_PARAM, false);
            }

            if (!empty($this->stdout)) {
                $this->parseStatisticsNvtop($gpu);
            }
            else {
                $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_NOT_RETURNED);
            }
        }
        else {
            // radeontop path: original behavior
            if ($gpu['id'] === 'FAKE_amd') {
                $this->stdout = (string)file_get_contents(__DIR__ . '/../sample/amd-radeontop-stdout.txt');
            }
            elseif ($this->cmdexists) {
                $command = sprintf('%s -b %s', self::RADEONTOP_UTILITY, $gpu['id']);
                $this->runCommand($command, self::STATISTICS_PARAM, false);
            }

            if (!empty($this->stdout)) {
                $this->parseStatistics($gpu);
            }
            else {
                $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_NOT_RETURNED);
            }
        }

        return json_encode($this->pageData);
    }

    // Read temp/fan/power/voltage from lm-sensors JSON output
    private function getSensorData(string $gpubus): array
    {
        $sensors = [];

        $chip = sprintf('amdgpu-pci-%s00', $gpubus);

        if ($gpubus === 'FAKE_amd') {
            $this->stdout = (string)file_get_contents(__DIR__ . '/../sample/amd-sensors-stdout.txt');
            // Override chip name to match the sample file's key.
            $chip = array_key_first(json_decode($this->stdout, true) ?? []) ?? $chip;
        }
        else {
            $this->checkCommand(self::TEMP_UTILITY, false);
            if ($this->cmdexists) {
                $tempFlag = ($this->settings['TEMPFORMAT'] === 'F') ? '-f' : '';
                $command = sprintf('%s %s %s', self::TEMP_UTILITY, $chip, $tempFlag);
                $this->runCommand($command, self::TEMP_PARAM, false);
            }
        }

        if (!empty($this->stdout)) {
            $data = json_decode($this->stdout, true);

            if (isset($data[$chip])) {
                $chipData = $data[$chip];

                if (isset($chipData['edge']['temp1_input'])) {
                    $sensors['temp'] = $this->roundFloat($chipData['edge']['temp1_input']) . ' °' . $this->settings['TEMPFORMAT'];
                    if (isset($chipData['edge']['temp1_crit'])) {
                        $sensors['tempmax'] = $this->roundFloat($chipData['edge']['temp1_crit']);
                    }
                }

                if (isset($chipData['fan1']['fan1_input'])) {
                    $sensors['fan'] = $this->roundFloat($chipData['fan1']['fan1_input']);
                    if (isset($chipData['fan1']['fan1_max'])) {
                        $sensors['fanmax'] = $this->roundFloat($chipData['fan1']['fan1_max']);
                    }
                }

                // Power fallback: power1 (pre-RDNA3) → PPT average (RDNA3+) → PPT input
                $powerValue = $chipData['power1']['power1_average']
                    ?? $chipData['PPT']['power1_average']
                    ?? $chipData['PPT']['power1_input']
                    ?? null;

                if ($powerValue !== null) {
                    $sensors['power'] = $this->roundFloat((float)$powerValue, 1);
                    if (isset($chipData['PPT']['power1_cap'])) {
                        $sensors['powermax'] = $this->roundFloat((float)$chipData['PPT']['power1_cap'], 1);
                    }
                }

                if (isset($chipData['vddgfx']['in0_input'])) {
                    $sensors['voltage'] = $this->roundFloat((float)$chipData['vddgfx']['in0_input'], 2);
                }
            }
        }

        return $sensors;
    }

    // Get PCIe link info (gen/width, current vs max) and driver name from lspci
    public function getpciedata(array $gpu): array
    {
        $result = [];
        $bridge = [];
        $gpubus = $gpu['guid'];
        $bridgebus = $gpu['bridge_chip'] ?? null;

        if ($gpubus === 'FAKE_amd') {
            $this->stdout = (string)file_get_contents(__DIR__ . '/../sample/amd-lspci-stdout.txt');
            $this->lspci_gpu = $this->parseInventory(self::LSPCI_REGEX);
        }
        elseif ($this->cmdexists) {
            $this->checkCommand(self::INVENTORY_UTILITY, false);
            if ($this->cmdexists) {
                $param = sprintf(' -vv -s %s: -s .0 | grep -P "LnkSta:|LnkCap:|Kernel driver in use"', $gpubus);
                $this->runCommand(self::INVENTORY_UTILITY, $param, false);
                if (!empty($this->stdout)) {
                    $this->lspci_gpu = $this->parseInventory(self::LSPCI_REGEX);
                }

                if (!empty($bridgebus)) {
                    $param = sprintf(' -vv -s %s: -s .0 | grep -P "LnkSta:|LnkCap:|Kernel driver in use"', $bridgebus);
                    $this->runCommand(self::INVENTORY_UTILITY, $param, false);
                    if (!empty($this->stdout)) {
                        $this->lspci_bridge = $this->parseInventory(self::LSPCI_REGEX);
                    }
                    foreach ($this->lspci_bridge ?? [] as $br) {
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

        foreach ($this->lspci_gpu ?? [] as $gpuEntry) {
            $result = [
                'pciegenmax' => (int)$this->parsePCIEgen((float)($bridge['pcie_speedmax'] ?? $gpuEntry['pcie_speedmax'])),
                'pciewidthmax' => $bridge['pcie_widthmax'] ?? $gpuEntry['pcie_widthmax'],
                'pciegen' => (int)$this->parsePCIEgen((float)($bridge['pcie_speed'] ?? $gpuEntry['pcie_speed'])),
                'pciewidth' => $bridge['pcie_width'] ?? $gpuEntry['pcie_width'],
                'driver' => $gpuEntry['driver'],
                'passedthrough' => ($gpuEntry['driver'] === 'vfio-pci') ? 'Passthrough' : 'Normal',
                'bridge_bus' => $bridgebus,
            ];
        }

        return $result;
    }

    // Find bridge chip bus ID by parsing lspci -vvt (bus range shows bridge)
    public function getpciebridge(string $gpubus): array
    {
        $result = [];

        if ($this->cmdexists) {
            $this->checkCommand(self::LSPCI, false);
            if ($this->cmdexists) {
                $param = sprintf(' -vvt | grep -m 1 -E "\[%s\]"', $gpubus);
                $this->runCommand(self::LSPCI, $param, false);
                if (!empty($this->stdout)) {
                    $this->lspci_bridge = $this->parseInventory(self::LSPCI_REGEX2);
                }
                foreach ($this->lspci_bridge ?? [] as $bridge) {
                    $hasPcie2 = isset($bridge['pcie2']);
                    $result = [
                        'pcie1' => $bridge['pcie1'],
                        'pcie2' => $hasPcie2 ? $bridge['pcie2'] : $bridge['pcie1'],
                        'bridge_chip' => $hasPcie2 ? $bridge['pcie1'] : null,
                    ];
                }
            }
        }

        return $result;
    }

    // Parse nvtop JSON output into pageData, then merge sensors + lspci
    private function parseStatisticsNvtop(array $gpu): void
    {
        $allGpus = json_decode($this->stdout, true);
        if (!is_array($allGpus)) {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_NOT_RETURNED);
            return;
        }

        // Match by PCI bus ID: nvtop uses "0000:0c:00.0", inventory has "0c:00.0"
        $gpuData = null;
        foreach ($allGpus as $entry) {
            $shortPci = preg_replace('/^[0-9a-f]{4}:/i', '', $entry['pci'] ?? '');
            if (strcasecmp($shortPci, $gpu['id']) === 0) {
                $gpuData = $entry;
                break;
            }
        }
        // Fallback: use first GPU if only one in output
        if ($gpuData === null) {
            $gpuData = count($allGpus) === 1 ? $allGpus[0] : null;
        }
        if ($gpuData === null) {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_NOT_RETURNED);
            return;
        }

        // Helper: strip unit suffix from value strings like "500MHz", "42C", "8W", "0%"
        $stripUnit = function ($val) {
            if ($val === null)
                return null;
            return preg_replace('/[^0-9.\-]/', '', (string)$val);
        };

        // Temperature: nvtop always reports Celsius — convert if needed
        $tempUnit = $this->settings['TEMPFORMAT'] ?? 'C';
        $tempRaw = (float)$stripUnit($gpuData['temp'] ?? null);
        if ($tempUnit === 'F') {
            $tempRaw = self::convertCelsius((int)$tempRaw);
        }
        $tempMax = (float)$stripUnit($gpuData['temp_slowdown_threshold'] ?? null);
        if ($tempUnit === 'F' && $tempMax > 0) {
            $tempMax = self::convertCelsius((int)$tempMax);
        }

        // Parse memory values ("256.00 MiB" → 256.0)
        $memUsed = (float)$stripUnit(explode(' ', $gpuData['mem_used'] ?? '0')[0]);
        $memTotal = (float)$stripUnit(explode(' ', $gpuData['mem_total'] ?? '0')[0]);

        // array_merge (not +=) so we overwrite the N/A defaults from Main::__construct()
        $this->pageData = array_merge($this->pageData, [
            'vendor' => 'AMD',
            'name' => $gpuData['device_name'] ?? $gpu['model'] ?? 'Unknown',
            'util' => $gpuData['gpu_util'] ?? 'N/A',
            'clock' => (float)$stripUnit($gpuData['gpu_clock'] ?? null),
            'clockmax' => (float)$stripUnit($gpuData['gpu_clock_max'] ?? null),
            'clockunit' => 'MHz',
            'memclock' => (float)$stripUnit($gpuData['mem_clock'] ?? null),
            'memclockmax' => (float)$stripUnit($gpuData['mem_clock_max'] ?? null),
            'memclockunit' => 'MHz',
            'temp' => $this->roundFloat($tempRaw) . ' °' . $tempUnit,
            'tempmax' => $this->roundFloat($tempMax),
            'tempunit' => $tempUnit,
            'fan' => $gpuData['fan_speed'] ?? 'N/A',
            'fanunit' => '%',
            'power' => (float)$stripUnit($gpuData['power_draw'] ?? null),
            'powermax' => (float)$stripUnit($gpuData['power_draw_max'] ?? null),
            'powerunit' => 'W',
            'memused' => $this->roundFloat($memUsed, 1),
            'memusedmax' => $this->roundFloat($memTotal, 1),
            'memusedunit' => 'MiB',
            'memusedutil' => $gpuData['mem_util'] ?? 'N/A',
            'pciegen' => (int)($gpuData['pcie_link_gen'] ?? 0),
            'pciegenmax' => (int)($gpuData['max_pcie_gen'] ?? 0),
            'pciewidth' => 'x' . (int)($gpuData['pcie_link_width'] ?? 0),
            'pciewidthmax' => 'x' . (int)($gpuData['max_pcie_link_width'] ?? 0),
            'voltageunit' => 'V',
        ]);

        // Encode/decode — nvtop uses either shared "encode_decode" or separate fields
        if (isset($gpuData['encode_decode'])) {
            $this->pageData['encutil'] = $gpuData['encode_decode'];
            $this->pageData['decutil'] = $gpuData['encode_decode'];
        }
        else {
            if (isset($gpuData['encode']))
                $this->pageData['encutil'] = $gpuData['encode'];
            if (isset($gpuData['decode']))
                $this->pageData['decutil'] = $gpuData['decode'];
        }

        // PCIe rx/tx (bytes/sec) — only present on some GPUs
        if (isset($gpuData['pcie_rx']))
            $this->pageData['rxutil'] = $gpuData['pcie_rx'];
        if (isset($gpuData['pcie_tx']))
            $this->pageData['txutil'] = $gpuData['pcie_tx'];

        // Process detection
        foreach (self::SUPPORTED_APPS as $app => $cmds) {
            $this->pageData['processes'][($app . 'using')] = false;
            $this->pageData['processes'][($app . 'mem')] = 0;
            $this->pageData['processes'][($app . 'count')] = 0;
        }
        $this->pageData['appssupp'] = array_keys(self::SUPPORTED_APPS);
        $this->pageData['sessions'] = 0;

        if (isset($gpuData['processes']) && is_array($gpuData['processes'])) {
            $this->pageData['sessions'] = count($gpuData['processes']);
            if ($gpu['id'] === 'FAKE_amd') {
                foreach (self::SUPPORTED_APPS as $app => $cmds) {
                    $this->pageData['processes'][($app . 'using')] = true;
                    $this->pageData['processes'][($app . 'mem')] = 25;
                    $this->pageData['processes'][($app . 'count')] = 2;
                }
            }
            else {
                foreach ($gpuData['processes'] as $proc) {
                    if (isset($proc['cmdline'])) {
                        $pid = (int)($proc['pid'] ?? 0);
                        $memMB = (int)round((float)$stripUnit($proc['gpu_mem_bytes_alloc'] ?? '0'));
                        $this->detectApplication($pid, (string)$proc['cmdline'], $memMB);
                    }
                }
            }
        }

        unset($this->stdout);

        // Merge sensors (voltage) and lspci (driver, bridge chip, passthrough)
        $this->pageData = array_merge($this->pageData, $this->getSensorData($gpu['guid']));
        $this->pageData = array_merge($this->pageData, $this->getpciedata($gpu));
    }

    /**
     * Match a process against SUPPORTED_APPS by its command line.
     * Uses /proc to resolve ambiguous binaries (ffmpeg, python3, etc.)
     */
    private function detectApplication(int $pid, string $processName, int $usedMemory): void
    {
        foreach (self::SUPPORTED_APPS as $app => $commands) {
            foreach ($commands as $command) {
                if (strpos($processName, $command) !== false) {
                    if (in_array($command, ['ffmpeg', 'HandbrakeCLI', 'python3'])) {
                        if ($pid > 0) {
                            $pid_info = $this->getFullCommand($pid);
                            if (!empty($pid_info)) {
                                if ($command === 'python3') {
                                    if (strpos($pid_info, '/app/intelligencelayer/shared') === false) {
                                        continue 2;
                                    }
                                }
                                elseif (stripos($pid_info, $app) === false) {
                                    $ppid_info = $this->getParentCommand($pid);
                                    if (stripos($ppid_info, $app) === false) {
                                        continue 2;
                                    }
                                }
                            }
                        }
                    }
                    $this->pageData['processes'][($app . 'using')] = true;
                    $this->pageData['processes'][($app . 'mem')] += $usedMemory;
                    $this->pageData['processes'][($app . 'count')]++;
                    break 2;
                }
            }
        }
    }

    // Parse radeontop output into pageData, then merge sensor + PCIe data
    private function parseStatistics(array $gpu): void
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
            'powerunit' => 'W',
            'fanunit' => 'RPM',
            'voltageunit' => 'V',
            'tempunit' => $this->settings['TEMPFORMAT'],
        ];

        // radeontop emits comma-separated key/value pairs; strip everything before 'gpu'.
        $raw = substr($this->stdout, strpos($this->stdout, 'gpu'));
        $data = explode(', ', $raw);
        $count = count($data);

        if ($count > 0) {
            foreach ($data as $metric) {
                $fields = explode(' ', trim($metric));
                $key = $fields[0] ?? '';

                if (!isset(self::STATISTICS_KEYMAP[$key])) {
                    continue;
                }

                $values = self::STATISTICS_KEYMAP[$key];

                // Parse first field (utilisation percentage or bare value).
                $field1Parts = preg_split('/(?<=[0-9])(?=[a-z]+)/i', str_replace(PHP_EOL, '', $fields[1] ?? ''));
                $this->pageData[$values[0]] = $field1Parts[0] ?? 'N/A';
                if (isset($field1Parts[1])) {
                    $this->pageData[$values[0] . 'unit'] = $field1Parts[1];
                }

                if (isset($values[1], $fields[2])) {
                    $field2Parts = preg_split('/(?<=[0-9])(?=[a-z]+)/i', str_replace(PHP_EOL, '', $fields[2]));
                    $this->pageData[$values[1]] = $field2Parts[0] ?? 'N/A';
                    if (isset($field2Parts[1])) {
                        $this->pageData[$values[1] . 'unit'] = $field2Parts[1];
                    }

                    // Derive the absolute maximum from utilisation % and current value.
                    if (in_array($key, ['vram', 'gtt', 'mclk', 'sclk'], true)) {
                        $util = (float)($field1Parts[0] ?? 0);
                        $value = (float)($field2Parts[0] ?? 0);
                        if ($util > 0) {
                            $this->pageData[$values[1] . 'max'] = $this->roundFloat($value * 100 / $util, 2);
                        }
                    }
                }

                // GPU overall load is a bare percentage — store as-is.
                if ($key === 'gpu') {
                    $this->pageData[$values[0]] = $this->roundFloat((float)($fields[1] ?? 0), 1) . '%';
                }
            }

            unset($data, $this->stdout);
        }
        else {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_NOT_ENOUGH, "Count: $count");
        }

        $this->pageData = array_merge($this->pageData, $this->getSensorData($gpu['guid']));
        $this->pageData = array_merge($this->pageData, $this->getpciedata($gpu));
    }
}