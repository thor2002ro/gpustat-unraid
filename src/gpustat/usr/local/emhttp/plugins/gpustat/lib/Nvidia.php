<?php

namespace gpustat\lib;

use SimpleXMLElement;

/**
 * Class Nvidia
 * @package gpustat\lib
 */
class Nvidia extends Main
{
    const CMD_UTILITY = 'nvidia-smi';
    const INVENTORY_PARAM = '-L';
    const INVENTORY_PARM_PCI = "-q -x -g %s 2>&1 | grep 'gpu id'";
    const INVENTORY_REGEX = '/GPU\s(?P<id>\d):\s(?P<model>.*)\s\(UUID:\s(?P<guid>GPU-[0-9a-f-]+)\)/i';
    const STATISTICS_PARAM = '-q -x -g %s 2>&1';


    /**
     * Nvidia constructor.
     * @param array $settings
     */
    public function __construct(array $settings = [])
    {
        $settings += ['cmd' => self::CMD_UTILITY];
        parent::__construct($settings);
    }

    /**
     * Parses PCI Bus Utilization data
     *
     * @param SimpleXMLElement $pci
     */
    private function getBusUtilization(SimpleXMLElement $pci)
    {
        if (isset($pci->rx_util, $pci->tx_util)) {
            // Not all cards support PCI RX/TX Measurements
            if ((string)$pci->rx_util !== 'N/A') {
                $this->pageData['rxutil'] = $this->roundFloat(((float)($pci->rx_util) / 1000), 1);
            }
            if ((string)$pci->tx_util !== 'N/A') {
                $this->pageData['txutil'] = $this->roundFloat(((float)($pci->tx_util) / 1000), 1);
            }
        }
        if (
        isset(
        $pci->pci_gpu_link_info->pcie_gen->current_link_gen,
        $pci->pci_gpu_link_info->pcie_gen->max_link_gen,
        $pci->pci_gpu_link_info->link_widths->current_link_width,
        $pci->pci_gpu_link_info->link_widths->max_link_width
        )
        ) {
            $this->pageData['pciegen'] = (int)$pci->pci_gpu_link_info->pcie_gen->current_link_gen;
            $this->pageData['pciewidth'] = "x" . (int)$pci->pci_gpu_link_info->link_widths->current_link_width;
            $generation = (int)$pci->pci_gpu_link_info->pcie_gen->current_link_gen;
            $width = (int)$pci->pci_gpu_link_info->link_widths->current_link_width;
            // @ 16x Lanes: Gen 1 = 4000, 2 = 8000, 3 = 16000 MB/s -- Slider bars won't be that active with most workloads
            $this->pageData['rxutilmax'] = $this->pageData['txutilmax'] = pow(2, $generation - 1) * 250 * $width;
            $this->pageData['pciegenmax'] = (int)$pci->pci_gpu_link_info->pcie_gen->max_link_gen;
            $this->pageData['pciewidthmax'] = "x" . (int)$pci->pci_gpu_link_info->link_widths->max_link_width;
        }
    }

    /**
     * Retrieves NVIDIA card inventory and parses into an array
     *
     * @return array
     */
    public function getInventorym(): array
    {
        $result2 = $result = [];

        if ($this->cmdexists) {
            $this->runCommand(self::CMD_UTILITY, self::INVENTORY_PARAM, false);
            if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                $this->parseInventory(self::INVENTORY_REGEX);
                if (!empty($this->inventory)) {
                    $result = $this->inventory;
                }
            }
            foreach ($result as $gpu) {
                $cmd = self::CMD_UTILITY . ES . sprintf(self::INVENTORY_PARM_PCI, $gpu['guid']);
                $cmdres = $this->stdout = shell_exec($cmd);
                $pci = substr($cmdres, 14, 12);
                $gpu['id'] = substr($pci, 5);
                $gpu['vendor'] = 'nvidia';
                $gpu['bridge_chip'] = NULL;
                $result2[$pci] = $gpu;
            }
        }

        if ($this->settings['UIDEBUG']) {
            $inventory["FAKE_nvidia"] = [
                'vendor' => 'nvidia',
                'id' => "FAKE_nvidia",
                'model' => 'GeForce GTX 1060 6GB',
                'guid' => 'FAKE_nvidia',
                'bridge_chip' => NULL,
            ];
            $result2 = array_merge($result2, $inventory);
        }

        return $result2;
    }

    /**
     * Parses product name and stores in page data
     *
     * @param string $name
     */
    private function getProductName(string $name)
    {
        // Some product names include NVIDIA and we already set it to be Vendor + Product Name
        if (stripos($name, 'NVIDIA') !== false) {
            $name = trim($this->stripText('NVIDIA', $name));
        }
        // Some product names are too long, like TITAN Xp COLLECTORS EDITION and need to be shortened for fitment
        if (strlen($name) > 20 && str_word_count($name) > 2) {
            $words = explode(" ", $name);
            $this->pageData['name'] = sprintf("%0s %1s", $words[0], $words[1]);
        }
        else {
            $this->pageData['name'] = $name;
        }
    }

    /**
     * Parses sensor data for environmental metrics
     *
     * @param SimpleXMLElement $data
     */
    private function getSensorData(SimpleXMLElement $data)
    {
        if (isset($data->temperature)) {
            if (isset($data->temperature->gpu_temp)) {
                $this->pageData['temp'] = (string)str_replace('C', '°C', $data->temperature->gpu_temp);
            }
            if (isset($data->temperature->gpu_temp_max_threshold)) {
                $this->pageData['tempmax'] = (string)str_replace('C', '°C', $data->temperature->gpu_temp_max_threshold);
            }
            if ($this->settings['TEMPFORMAT'] == 'F') {
                foreach (['temp', 'tempmax'] as $key) {
                    $this->pageData[$key] = $this->convertCelsius((int)$this->stripText('C', $this->pageData[$key])) . 'F';
                }
            }
        }

        if (isset($data->fan_speed)) {
            $this->pageData['fan'] = $this->stripSpaces($data->fan_speed);
        }

        if (isset($data->performance_state)) {
            $this->pageData['perfstate'] = $this->stripSpaces($data->performance_state);
        }

        if (isset($data->clocks_throttle_reasons)) {
            $this->pageData['throttled'] = 'No';
            foreach ($data->clocks_throttle_reasons->children() as $reason => $throttle) {
                if ($throttle == 'Active') {
                    $this->pageData['throttled'] = 'Yes';
                    $this->pageData['thrtlrsn'] = ' (' . $this->stripText(['clocks_throttle_reason_', '_setting'], $reason) . ')';
                    break;
                }
            }
        }
        if (isset($data->clocks_event_reasons)) {
            $this->pageData['throttled'] = 'No';
            foreach ($data->clocks_event_reasons->children() as $reason => $throttle) {
                if ($throttle == 'Active') {
                    $this->pageData['throttled'] = 'Yes';
                    $this->pageData['thrtlrsn'] = ' (' . $this->stripText(['clocks_event_reason_', '_setting'], $reason) . ')';
                    break;
                }
            }
        }

        if (isset($data->power_readings)) {
            if (isset($data->power_readings->power_draw)) {
                $this->pageData['power'] = $this->roundFloat((float)$data->power_readings->power_draw, 1);
            }
            if (isset($data->power_readings->power_limit)) {
                $this->pageData['powermax'] = $this->roundFloat((float)$data->power_readings->power_limit, 1);
            }
        }

    }

    /**
     * Retrieves NVIDIA card statistics.
     * Supports: nvidia-smi (proprietary), Nouveau (open-source), VFIO passthrough.
     */
    public function getStatistics($gpu)
    {
        $fullPciId = '0000:' . ($gpu['guid'] ?? $gpu['id'] ?? '');
        $driver = strtoupper($this->getKernelDriver($fullPciId));

        // Normalize driver name
        if ($driver !== 'NVIDIA' && $driver !== 'NOUVEAU' && $driver !== 'VFIO-PCI') {
            $driver = $this->cmdexists ? 'NVIDIA' : '';
        }

        // VFIO passthrough — GPU is in a VM
        if ($this->checkVFIO($fullPciId)) {
            $this->pageData['vendor'] = 'NVIDIA';
            $this->pageData['name'] = $gpu['model'] ?? 'NVIDIA GPU';
            $this->pageData['driver'] = $driver;
            $this->pageData['vfio'] = true;
            $this->pageData['vfiovm'] = $this->getGpuVm($gpu['guid'] ?? $gpu['id'] ?? '');
            $this->getPCIeBandwidthFromSysfs($fullPciId);
            return json_encode($this->pageData);
        }

        if ($gpu['id'] === 'FAKE_nvidia') {
            $this->stdout = file_get_contents(__DIR__ . '/../sample/nvidia-smi-stdout.txt');
        }
        elseif ($driver === 'NOUVEAU') {
            // Nouveau: build synthetic nvidia-smi XML from sysfs
            $this->stdout = $this->buildNouveauXML($fullPciId);
        }
        elseif ($this->cmdexists) {
            // Proprietary nvidia driver: use nvidia-smi
            $this->stdout = shell_exec(self::CMD_UTILITY . ES . sprintf(self::STATISTICS_PARAM, $gpu['id']));
        }
        else {
            $this->pageData['error'][] = Error::get(Error::VENDOR_UTILITY_NOT_FOUND);
        }

        if (!empty($this->stdout)) {
            $this->parseStatistics($gpu);
        }
        else {
            if (!isset($this->pageData['error'])) {
                $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_NOT_RETURNED);
            }
        }

        $this->pageData['driver'] = $driver;
        $this->pageData['vfio'] = false;
        return json_encode($this->pageData);
    }

    /**
     * Parses hardware utilization data
     *
     * @param SimpleXMLElement $data
     */
    private function getUtilization(SimpleXMLElement $data)
    {
        if (isset($data->utilization)) {
            if (isset($data->utilization->gpu_util)) {
                $this->pageData['util'] = $this->stripSpaces($data->utilization->gpu_util);
            }
            if (isset($data->utilization->encoder_util)) {
                $this->pageData['encutil'] = $this->stripSpaces($data->utilization->encoder_util);
            }
            if (isset($data->utilization->decoder_util)) {
                $this->pageData['decutil'] = $this->stripSpaces($data->utilization->decoder_util);
            }

        }
        if (isset($data->fb_memory_usage->used, $data->fb_memory_usage->total)) {
            $this->pageData['memusedmax'] = (int)$data->fb_memory_usage->total;
            $this->pageData['memused'] = (int)$data->fb_memory_usage->used;
            $this->pageData['memusedunit'] = preg_replace('/[^a-zA-Z]/', '', $data->fb_memory_usage->used);
            $this->pageData['memusedutil'] = round($this->pageData['memused'] / $this->pageData['memusedmax'] * 100) . "%";
        }
    }

    /**
     * Loads stdout into SimpleXMLObject then retrieves and returns specific definitions in an array
     */
    private function parseStatistics($gpu)
    {
        $data = @simplexml_load_string($this->stdout);
        $this->stdout = '';

        if ($data instanceof SimpleXMLElement && !empty($data->gpu)) {

            $data = $data->gpu;
            $this->pageData += [
                'vendor' => 'NVIDIA',
                'name' => $gpu['model'],
                'clockmax' => 'N/A',
                'memclockmax' => 'N/A',
                'memtotal' => 'N/A',
                'encutil' => 'N/A',
                'decutil' => 'N/A',
                'pciemax' => 'N/A',
                'perfstate' => 'N/A',
                'throttled' => 'N/A',
                'thrtlrsn' => '',
                'pciegen' => 'N/A',
                'pciegenmax' => 'N/A',
                'pciewidth' => 'N/A',
                'pciewidthmax' => 'N/A',
                'sessions' => 0,
                'uuid' => 'N/A',
            ];

            $this->pageData += [
                'powerunit' => 'W',
                //'fanunit'       => 'RPM',   // card returns % not fan speed
                'voltageunit' => 'V',
                'tempunit' => $this->settings['TEMPFORMAT'],
                'rxutilunit' => "MB/s",
                'txutilunit' => "MB/s",
            ];

            // App HW Usage Defaults are no longer needed for static apps

            if (isset($data->product_name)) {
                $this->getProductName($data->product_name);
            }
            if (isset($data->uuid)) {
                $this->pageData['uuid'] = (string)$data->uuid;
            }
            else {
                $this->pageData['uuid'] = $gpu['id'];
            }
            $this->getUtilization($data);
            $this->getSensorData($data);
            if (isset($data->clocks, $data->max_clocks)) {
                if (isset($data->clocks->graphics_clock, $data->max_clocks->graphics_clock)) {
                    $this->pageData['clock'] = (int)$data->clocks->graphics_clock;
                    $this->pageData['clockunit'] = preg_replace('/[^a-zA-Z]/', '', $data->clocks->graphics_clock);
                    $this->pageData['clockmax'] = (int)$data->max_clocks->graphics_clock;
                }
                if (isset($data->clocks->sm_clock, $data->max_clocks->sm_clock)) {
                    $this->pageData['sm_clock'] = (int)$data->clocks->sm_clock;
                    $this->pageData['sm_clockunit'] = preg_replace('/[^a-zA-Z]/', '', $data->clocks->sm_clock);
                    $this->pageData['sm_clockmax'] = (int)$data->max_clocks->sm_clock;
                }
                if (isset($data->clocks->video_clock, $data->max_clocks->video_clock)) {
                    $this->pageData['video_clock'] = (int)$data->clocks->video_clock;
                    $this->pageData['video_clockunit'] = preg_replace('/[^a-zA-Z]/', '', $data->clocks->video_clock);
                    $this->pageData['video_clockmax'] = (int)$data->max_clocks->video_clock;
                }
                if (isset($data->clocks->mem_clock, $data->max_clocks->mem_clock)) {
                    $this->pageData['memclock'] = (int)$data->clocks->mem_clock;
                    $this->pageData['memclockunit'] = preg_replace('/[^a-zA-Z]/', '', $data->clocks->mem_clock);
                    $this->pageData['memclockmax'] = (int)$data->max_clocks->mem_clock;
                }
            }
            // For some reason, encoder_sessions->session_count is not reliable on my install, better to count processes
            $this->pageData['active_apps'] = [];
            if (isset($data->processes->process_info)) {
                $this->pageData['sessions'] = count($data->processes->process_info);
                if ($this->pageData['sessions'] > 0) {
                    foreach ($data->processes->children() as $process) {
                        if ($gpu['id'] === "FAKE_nvidia") {
                            $this->detectApplicationDynamic(['pid' => 111, 'name' => 'plex', 'memory' => '25 MiB']);
                            $this->detectApplicationDynamic(['pid' => 222, 'name' => 'jellyfin', 'memory' => '30 MiB']);
                        }
                        else if (isset($process->process_name)) {
                            // Populate active_apps for dynamic Docker/host display
                            $this->detectApplicationDynamic([
                                'pid'    => (int)($process->pid ?? 0),
                                'name'   => (string)$process->process_name,
                                'memory' => (string)($process->used_memory ?? '0 MiB'),
                            ]);
                        }
                    }
                }
            }

            $this->getBusUtilization($data->pci);

        }
        else {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_BAD_PARSE);
        }
    }

    /**
     * Builds a synthetic nvidia-smi XML document from sysfs/hwmon data.
     * Used when the Nouveau open-source driver is active (no nvidia-smi).
     * The XML structure mirrors nvidia-smi output so parseStatistics() works unchanged.
     *
     * @param string $pciId Full PCI ID (e.g. "0000:0c:00.0")
     * @return string XML string
     */
    private function buildNouveauXML(string $pciId): string
    {
        $gpuPath = "/sys/bus/pci/devices/$pciId/";
        $clientsPath = "/sys/kernel/debug/dri/$pciId/clients";
        $hwmonPath = $this->findHwmonPath($pciId);

        $xml = new \SimpleXMLElement("<?xml version='1.0' encoding='UTF-8'?><nvidia_smi_log></nvidia_smi_log>");
        $xml->addChild('timestamp', date('r'));
        $xml->addChild('driver_version', $this->getSysfsValue('/sys/module/nouveau/version', 'Nouveau'));
        $xml->addChild('cuda_version', 'N/A');
        $xml->addChild('attached_gpus', '1');

        $gpu = $xml->addChild('gpu');
        $gpu->addAttribute('id', $pciId);
        $gpu->addChild('product_name', $this->getGpuNameFromUdev($pciId));
        $gpu->addChild('uuid', 'GPU-' . md5($pciId));

        // PCIe link info
        $pci = $gpu->addChild('pci');
        $gpuLinkInfo = $pci->addChild('pci_gpu_link_info');
        $pcieGen = $gpuLinkInfo->addChild('pcie_gen');
        $maxSpeed = $this->getSysfsValue("$gpuPath/max_link_speed");
        $curSpeed = $this->getSysfsValue("$gpuPath/current_link_speed", $maxSpeed);
        $pcieGen->addChild('max_link_gen', (string)$this->parsePCIEgen($maxSpeed));
        $pcieGen->addChild('current_link_gen', (string)$this->parsePCIEgen($curSpeed));
        $linkWidths = $gpuLinkInfo->addChild('link_widths');
        $linkWidths->addChild('max_link_width', $this->getSysfsValue("$gpuPath/max_link_width") . 'x');
        $linkWidths->addChild('current_link_width', $this->getSysfsValue("$gpuPath/current_link_width",
            $this->getSysfsValue("$gpuPath/max_link_width")) . 'x');
        $pci->addChild('tx_util', 'N/A');
        $pci->addChild('rx_util', 'N/A');

        // Temperature from hwmon
        $temperature = $gpu->addChild('temperature');
        if ($hwmonPath && file_exists("$hwmonPath/temp1_input")) {
            $tempRaw = (int)$this->getSysfsValue("$hwmonPath/temp1_input", '0');
            $temperature->addChild('gpu_temp', round($tempRaw / 1000) . ' C');
        }
        else {
            $temperature->addChild('gpu_temp', 'N/A');
        }
        if ($hwmonPath && file_exists("$hwmonPath/temp1_crit")) {
            $critRaw = (int)$this->getSysfsValue("$hwmonPath/temp1_crit", '0');
            $temperature->addChild('gpu_temp_max_threshold', round($critRaw / 1000) . ' C');
        }
        else {
            $temperature->addChild('gpu_temp_max_threshold', '105 C');
        }

        // Fan speed from hwmon (PWM 0-255 → percentage)
        if ($hwmonPath && file_exists("$hwmonPath/pwm1")) {
            $pwm = (int)$this->getSysfsValue("$hwmonPath/pwm1", '0');
            $gpu->addChild('fan_speed', round($pwm / 255 * 100) . ' %');
        }
        else {
            $gpu->addChild('fan_speed', 'N/A');
        }

        $gpu->addChild('performance_state', 'P0');

        // Clocks event reasons (all inactive for Nouveau)
        $clocksEvent = $gpu->addChild('clocks_event_reasons');
        $events = ['gpu_idle', 'applications_clocks_setting', 'sw_power_cap', 'hw_slowdown',
            'hw_thermal_slowdown', 'hw_power_brake_slowdown', 'sync_boost',
            'sw_thermal_slowdown', 'display_clocks_setting'];
        foreach ($events as $event) {
            $clocksEvent->addChild("clocks_event_reason_$event", 'Not Active');
        }

        // Memory (Nouveau doesn't easily expose this, use placeholder)
        $fbMemory = $gpu->addChild('fb_memory_usage');
        $fbMemory->addChild('total', 'N/A');
        $fbMemory->addChild('used', 'N/A');
        $fbMemory->addChild('free', 'N/A');

        // Utilization (not available on Nouveau)
        $utilization = $gpu->addChild('utilization');
        $utilization->addChild('gpu_util', 'N/A');
        $utilization->addChild('memory_util', 'N/A');
        $utilization->addChild('encoder_util', 'N/A');
        $utilization->addChild('decoder_util', 'N/A');

        // Power (not directly available)
        $power = $gpu->addChild('power_readings');
        $power->addChild('power_draw', 'N/A');
        $power->addChild('power_limit', 'N/A');

        // Clocks (not available on Nouveau)
        $clocks = $gpu->addChild('clocks');
        $clocks->addChild('graphics_clock', 'N/A');
        $clocks->addChild('mem_clock', 'N/A');
        $maxClocks = $gpu->addChild('max_clocks');
        $maxClocks->addChild('graphics_clock', 'N/A');
        $maxClocks->addChild('mem_clock', 'N/A');

        // Process clients from DRI debugfs
        $processes = $gpu->addChild('processes');
        if (file_exists($clientsPath)) {
            $lines = file($clientsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            array_shift($lines); // Remove header
            $seenTgids = [];
            foreach ($lines as $line) {
                $columns = preg_split('/\s+/', trim($line));
                if (count($columns) >= 6) {
                    [$command, $tgid] = $columns;
                    if (isset($seenTgids[$tgid]))
                        continue; // Deduplicate by TGID
                    $seenTgids[$tgid] = true;
                    $processInfo = $processes->addChild('process_info');
                    $processInfo->addChild('gpu_instance_id', 'N/A');
                    $processInfo->addChild('compute_instance_id', 'N/A');
                    $processInfo->addChild('pid', $tgid);
                    $processInfo->addChild('type', 'C');
                    $processInfo->addChild('process_name', $command);
                    $processInfo->addChild('used_memory', 'N/A');
                }
            }
        }

        $dom = new \DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());
        return $dom->saveXML();
    }
}