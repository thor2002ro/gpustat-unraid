<?php

namespace gpustat\lib;

use JsonException;

/**
 * Class Intel
 * @package gpustat\lib
 */
class Intel extends Main
{
    const CMD_UTILITY = 'intel_gpu_top';
    const INVENTORY_UTILITY = 'lspci';
    const INVENTORY_PARAM = " -Dmm | grep -E 'Display|VGA' ";
    const INVENTORY_REGEX =
        '/^(?P<id>(?P<busid>[0-9a-f]{2}):[0-9a-f]{2}\.[0-9a-f])\s+\"(?P<device>.+)\"\s+\"(?P<vendor>.+)\"\s+\"(?P<model>.+)\"\s+(?:-\w+\s+){2}(?:\"(?P<manufacturer>.+)\"\s+\"(?P<product>.+)\"|())/imU';

    const STATISTICS_PARAM = '-J -s 250 -d pci:slot="';
    const STATISTICS_WRAPPER = 'timeout -k .500 .600';

    /**
     * Intel constructor.
     * @param array $settings
     */
    public function __construct(array $settings = [])
    {
        $settings += ['cmd' => self::CMD_UTILITY];
        parent::__construct($settings);
    }

    /**
     * Retrieves Intel inventory using lspci and returns an array
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
                    $this->parseInventory(self::INVENTORY_REGEX);
                }
                if (!empty($this->inventory)) {
                    foreach ($this->inventory as $gpu) {
                        if ($vendor != "Intel Corporation")
                            continue;
                        $result[$gpu['id']] = [
                            'vendor' => 'Intel',
                            'id' => $gpu['id'],
                            'model' => (string)$gpu['model'],
                            'guid' => $gpu['busid'],
                            'bridge_chip' => NULL,
                        ];
                    }
                }
            }
        }
        if ($this->settings['UIDEBUG']) {
            $inventory["FAKE_intel"] = [
                'vendor' => 'intel',
                'id' => "FAKE_intel",
                'model' => 'Xeon E3-1200 v2/3rd Gen Core processor',
                'guid' => 'FAKE_intel',
                'bridge_chip' => NULL,
            ];
            $result = array_merge($result, $inventory);
        }

        return $result;
    }

    /**
     * Retrieves Intel iGPU/dGPU statistics.
     * Supports: intel_gpu_top (i915), XE driver (sysfs fallback), VFIO passthrough.
     */
    public function getStatistics($gpu)
    {
        $fullPciId = $gpu['id'];
        $driver = $this->getKernelDriver($fullPciId);
        if ($driver === 'xe')
            $driver = 'XE';
        if ($driver !== 'XE' && $driver !== 'i915')
            $driver = 'i915';

        // VFIO passthrough — GPU is in a VM
        if ($this->checkVFIO($fullPciId)) {
            $this->pageData['vendor'] = 'Intel';
            $this->pageData['name'] = $gpu['model'] ?? 'Intel GPU';
            $this->pageData['driver'] = $driver;
            $this->pageData['vfio'] = true;
            $this->pageData['vfiovm'] = $this->getGpuVm($gpu['guid'] ?? $gpu['id'] ?? '');
            $this->getPCIeBandwidthFromSysfs($fullPciId);
            return json_encode($this->pageData);
        }

        if ($gpu['id'] === 'FAKE_intel') {
            $this->stdout = file_get_contents(__DIR__ . '/../sample/intel-gpu-top-stdout.txt');
        }
        elseif ($driver === 'XE') {
            // XE driver: build intel_gpu_top-compatible JSON from sysfs
            $this->stdout = $this->buildXEJSON($fullPciId);
        }
        elseif ($this->cmdexists) {
            $command = self::STATISTICS_WRAPPER . ES . self::CMD_UTILITY;
            $this->runCommand($command, self::STATISTICS_PARAM . $gpu['id'] . '"', false);
        }

        if (!empty($this->stdout)) {
            $this->parseStatistics($gpu);
        }
        else {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_NOT_RETURNED);
        }

        $this->pageData['driver'] = $driver;
        $this->pageData['vfio'] = false;
        return json_encode($this->pageData);
    }

    /**
     * Loads JSON into array then retrieves and returns specific definitions in an array
     */
    private function parseStatistics($gpu)
    {
        // JSON output from intel_gpu_top with multiple array indexes isn't properly formatted
        $stdout = "[" . str_replace('}{', '},{', str_replace(["\n", "\t"], '', $this->stdout)) . "]";

        try {
            $data = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        }
        catch (JsonException $e) {
            $data = [];
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_BAD_PARSE, $e->getMessage());
        }

        // Need to make sure we have at least two array indexes to take the second one
        $count = count($data);
        if ($count < 2) {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_NOT_ENOUGH, "Count: $count");
        }

        // intel_gpu_top will never show utilization counters on the first sample so we need the second position
        $data = $data[1] ?? $data[0] ?? [];
        unset($stdout, $this->stdout);

        if (!empty($data)) {

            $this->pageData += [
                'vendor' => 'Intel',
                'name' => $gpu['model'],
                'blitter' => 'N/A',
                'interrupts' => 'N/A',
                'powerutil' => 'N/A',
                'video' => 'N/A',
                'videnh' => 'N/A',
                'compute' => 0,
                'sessions' => 0,
            ];

            $this->pageData += [
                'powerunit' => 'W',
                'fanunit' => 'RPM',
                'voltageunit' => 'V',
                'tempunit' => $this->settings['TEMPFORMAT'],
            ];

            // Engine utilization — support both "Render/3D/0" and "Render/3D" naming
            $engines = $data['engines'] ?? [];
            $render3d = $engines['Render/3D/0']['busy'] ?? $engines['Render/3D']['busy'] ?? null;
            if ($render3d !== null) {
                $this->pageData['util'] = $this->roundFloat($render3d, 1) . '%';
            }

            $blitter = $engines['Blitter/0']['busy'] ?? $engines['Blitter']['busy'] ?? null;
            if ($blitter !== null) {
                $this->pageData['blitter'] = $this->roundFloat($blitter) . '%';
            }

            $video = $engines['Video/0']['busy'] ?? $engines['Video']['busy'] ?? null;
            if ($video !== null) {
                $this->pageData['video'] = $this->roundFloat($video) . '%';
            }

            $videnh = $engines['VideoEnhance/0']['busy'] ?? $engines['VideoEnhance']['busy'] ?? null;
            if ($videnh !== null) {
                $this->pageData['videnh'] = $this->roundFloat($videnh) . '%';
            }

            if (isset($data['imc-bandwidth']['reads'], $data['imc-bandwidth']['writes'])) {
                $this->pageData['rxutilunit'] = $data['imc-bandwidth']['unit'] ?? 'MiB/s';
                $this->pageData['txutilunit'] = $data['imc-bandwidth']['unit'] ?? 'MiB/s';
                $this->pageData['rxutil'] = $this->roundFloat($data['imc-bandwidth']['reads'], 2);
                $this->pageData['txutil'] = $this->roundFloat($data['imc-bandwidth']['writes'], 2);
            }

            // Power: old format (single value) → new format (GPU + Package selectable)
            $pwrSel = $this->settings['DISPPWRDRWSEL'] ?? 'GPU';
            if (isset($data['power']['value'])) {
                $this->pageData['powerunit'] = $data['power']['unit'] ?? 'W';
                $this->pageData['power'] = $this->roundFloat($data['power']['value'], 2);
            }
            else {
                $powerGPU = isset($data['power']['GPU']) && ($pwrSel === 'MAX' || $pwrSel === 'GPU')
                    ? $this->roundFloat($data['power']['GPU'], 1) : 0;
                $powerPkg = isset($data['power']['Package']) && ($pwrSel === 'MAX' || $pwrSel === 'PACKAGE')
                    ? $this->roundFloat($data['power']['Package'], 1) : 0;
                $powerUnit = $data['power']['unit'] ?? 'W';
                $this->pageData['power'] = max($powerGPU, $powerPkg);
                $this->pageData['powerunit'] = $powerUnit;
            }

            // rc6 = percentage of idle → invert for power utilization
            if (isset($data['rc6']['value'])) {
                $this->pageData['powerutil'] = $this->roundFloat(100 - $data['rc6']['value'], 2) . '%';
            }

            if (isset($data['frequency']['actual'])) {
                $this->pageData['clockunit'] = $data['frequency']['unit'] ?? 'MHz';
                $this->pageData['clock'] = $this->roundFloat($data['frequency']['actual']);
            }

            if (isset($data['interrupts']['count'])) {
                $this->pageData['interruptsunit'] = $data['interrupts']['unit'] ?? 'irq/s';
                $this->pageData['interrupts'] = (int)$this->roundFloat($data['interrupts']['count']);
            }

            // Temperature from sysfs (hwmon)
            $tempPath = glob("/sys/bus/pci/devices/{$gpu['id']}/hwmon/*/temp1_input");
            if (isset($tempPath[0]) && is_file($tempPath[0])) {
                $tempRaw = (float)trim(file_get_contents($tempPath[0])) / 1000;
                if ($this->settings['TEMPFORMAT'] === 'F') {
                    $this->pageData['temp'] = self::convertCelsius((int)$tempRaw) . ' °F';
                }
                else {
                    $this->pageData['temp'] = round($tempRaw) . ' °C';
                }
            }

            // Fan speed from sysfs (discrete Intel GPUs like Arc)
            $fanPath = glob("/sys/bus/pci/devices/{$gpu['id']}/hwmon/*/fan1_input");
            if (isset($fanPath[0]) && is_file($fanPath[0])) {
                $this->pageData['fan'] = (float)trim(file_get_contents($fanPath[0]));
                $this->pageData['fanmax'] = 4000;
            }

            // Per-client engine utilization aggregation (when global engines show 0)
            if (isset($data['clients']) && count($data['clients']) > 0) {
                $this->pageData['sessions'] = count($data['clients']);
                $this->pageData['active_apps'] = [];
                $clientRender = $clientBlitter = $clientVideo = $clientVideoEnh = $clientCompute = 0;

                foreach ($data['clients'] as $process) {
                    if (!isset($process['name']))
                        continue;

                    $memTotal = isset($process['memory']['system']['total'])
                        ? $process['memory']['system']['total'] / (1024 * 1024) : 0;
                    $processArray = [
                        'pid' => $process['pid'] ?? 0,
                        'name' => $process['name'],
                        'memory' => round($memTotal) . ' MiB',
                    ];
                    $this->detectApplicationDynamic($processArray);

                    // Per-client engine busy aggregation
                    $ec = $process['engine-classes'] ?? [];
                    if (isset($ec['Render/3D']['busy']))
                        $clientRender += $ec['Render/3D']['busy'];
                    if (isset($ec['Blitter']['busy']))
                        $clientBlitter += $ec['Blitter']['busy'];
                    if (isset($ec['Video']['busy']))
                        $clientVideo += $ec['Video']['busy'];
                    if (isset($ec['VideoEnhance']['busy']))
                        $clientVideoEnh += $ec['VideoEnhance']['busy'];
                    if (isset($ec['Compute']['busy']))
                        $clientCompute += $ec['Compute']['busy'];
                }

                // Fall back to aggregated client data if global engines are 0
                $render3dVal = (float)rtrim($this->pageData['util'] ?? '0', '%');
                $blitterVal = (float)rtrim($this->pageData['blitter'] ?? '0', '%');
                $videoVal = (float)rtrim($this->pageData['video'] ?? '0', '%');
                $videnhVal = (float)rtrim($this->pageData['videnh'] ?? '0', '%');

                if ($render3dVal == 0 && $clientRender > 0)
                    $this->pageData['util'] = $this->roundFloat($clientRender) . '%';
                if ($blitterVal == 0 && $clientBlitter > 0)
                    $this->pageData['blitter'] = $this->roundFloat($clientBlitter) . '%';
                if ($videoVal == 0 && $clientVideo > 0)
                    $this->pageData['video'] = $this->roundFloat($clientVideo) . '%';
                if ($videnhVal == 0 && $clientVideoEnh > 0)
                    $this->pageData['videnh'] = $this->roundFloat($clientVideoEnh) . '%';
                if ($clientCompute > 0)
                    $this->pageData['compute'] = $this->roundFloat($clientCompute) . '%';
            }

            // Overall util = max of all engine metrics
            $maxVals = [
                (float)rtrim($this->pageData['util'] ?? '0', '%'),
                (float)rtrim($this->pageData['blitter'] ?? '0', '%'),
                (float)rtrim($this->pageData['video'] ?? '0', '%'),
                (float)rtrim($this->pageData['videnh'] ?? '0', '%'),
                (float)rtrim($this->pageData['compute'] ?? '0', '%'),
            ];
            $maxLoad = max($maxVals);
            if ($maxLoad > 0) {
                $this->pageData['util'] = $maxLoad . '%';
            }

            // PCIe bandwidth from sysfs
            $this->getPCIeBandwidthFromSysfs($gpu['id']);
        }
        else {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_BAD_PARSE);
        }
    }

    /**
     * Builds intel_gpu_top-compatible JSON from sysfs data for the XE driver.
     * XE doesn't work with intel_gpu_top, so we construct minimal JSON
     * that parseStatistics() can consume.
     *
     * @param string $pciId Full PCI ID (e.g. "0000:00:02.0")
     * @return string JSON string (array of 2 identical entries)
     */
    private function buildXEJSON(string $pciId): string
    {
        $basePath = "/sys/bus/pci/devices/$pciId";
        $clientsPath = "/sys/kernel/debug/dri/$pciId/clients";

        if (!file_exists($basePath)) {
            return json_encode([['error' => 'Invalid PCI ID']]);
        }

        // Frequency (XE exposes via gt/gt0/)
        $freqActual = null;
        $freqPath = "$basePath/gt/gt0/rps_act_freq_mhz";
        if (file_exists($freqPath)) {
            $freqActual = (float)trim(file_get_contents($freqPath));
        }

        // Clients from DRI debugfs
        $clients = null;
        if (file_exists($clientsPath)) {
            $lines = file($clientsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            array_shift($lines); // Remove header
            if ($lines) {
                foreach ($lines as $line) {
                    $columns = preg_split('/\s+/', trim($line));
                    if (count($columns) >= 6) {
                        [$command, $tgid] = $columns;
                        $clients[$tgid] = [
                            'name' => $command,
                            'pid' => (int)$tgid,
                            'memory' => ['system' => ['total' => 0]],
                        ];
                    }
                }
            }
        }

        $entry = [
            'period' => ['duration' => 1000.0, 'unit' => 'ms'],
            'frequency' => ['requested' => null, 'actual' => $freqActual, 'unit' => 'MHz'],
            'interrupts' => ['count' => null, 'unit' => 'irq/s'],
            'rc6' => ['value' => 100, 'unit' => '%'],
            'power' => ['GPU' => null, 'Package' => null, 'unit' => 'W'],
            'engines' => [
                'Render/3D' => ['busy' => 0.0, 'sema' => 0.0, 'wait' => 0.0, 'unit' => '%'],
                'Blitter' => ['busy' => 0.0, 'sema' => 0.0, 'wait' => 0.0, 'unit' => '%'],
                'Video' => ['busy' => 0.0, 'sema' => 0.0, 'wait' => 0.0, 'unit' => '%'],
                'VideoEnhance' => ['busy' => 0.0, 'sema' => 0.0, 'wait' => 0.0, 'unit' => '%'],
            ],
            'clients' => $clients,
        ];

        // Return 2 entries (parseStatistics uses index [1])
        return json_encode([$entry, $entry]);
    }
}