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
    const SUPPORTED_APPS = [
        // Order here is important because some apps use the same binaries -- order should be more specific to less
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
     * Iterates supported applications and their respective commands to match against processes using GPU hardware
     *
     * @param SimpleXMLElement $process
     */
    private function detectApplication(SimpleXMLElement $process)
    {
        foreach (self::SUPPORTED_APPS as $app => $commands) {
            foreach ($commands as $command) {
                if (strpos($process->process_name, $command) !== false) {
                    // For Handbrake/ffmpeg: arguments tell us which application called it
                    if (in_array($command, ['ffmpeg', 'HandbrakeCLI', 'python3'])) {
                        if (isset($process->pid)) {
                            $pid_info = $this->getFullCommand((int) $process->pid);
                            if (!empty($pid_info) && strlen($pid_info) > 0) {
                                if ($command === 'python3') {
                                    // Deepstack doesn't have any signifier in the full command output
                                    if (strpos($pid_info, '/app/intelligencelayer/shared') === false) {
                                        continue 2;
                                    }
                                } elseif (stripos($pid_info, $app) === false) {
                                    // Try to match the app name in the parent process
                                    $ppid_info = $this->getParentCommand((int) $process->pid);
                                    if (stripos($ppid_info, $app) === false) {
                                        // We didn't match the application name in the arguments, no match
                                        continue 2;
                                    }
                                }
                            }
                        }
                    }
                    $this->pageData['processes'][($app . "using")] = true;
                    $this->pageData['processes'][($app . "mem")] += (int) $process->used_memory;
                    $this->pageData['processes'][($app . "count")]++;
                    // If we match a more specific command/app to a process, continue on to the next process
                    break 2;
                }
            }
        }
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
            if ((string) $pci->rx_util !== 'N/A') {
                $this->pageData['rxutil'] = $this->roundFloat(((float) ($pci->rx_util) / 1000), 1);
            }
            if ((string) $pci->tx_util !== 'N/A') {
                $this->pageData['txutil'] = $this->roundFloat(((float) ($pci->tx_util) / 1000), 1);
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
            $this->pageData['pciegen'] = (int) $pci->pci_gpu_link_info->pcie_gen->current_link_gen;
            $this->pageData['pciewidth'] = "x" . (int) $pci->pci_gpu_link_info->link_widths->current_link_width;
            $generation = (int) $pci->pci_gpu_link_info->pcie_gen->current_link_gen;
            $width = (int) $pci->pci_gpu_link_info->link_widths->current_link_width;
            // @ 16x Lanes: Gen 1 = 4000, 2 = 8000, 3 = 16000 MB/s -- Slider bars won't be that active with most workloads
            $this->pageData['rxutilmax'] = $this->pageData['txutilmax'] = pow(2, $generation - 1) * 250 * $width;
            $this->pageData['pciegenmax'] = (int) $pci->pci_gpu_link_info->pcie_gen->max_link_gen;
            $this->pageData['pciewidthmax'] = "x" . (int) $pci->pci_gpu_link_info->link_widths->max_link_width;
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
        } else {
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
                $this->pageData['temp'] = (string) str_replace('C', '°C', $data->temperature->gpu_temp);
            }
            if (isset($data->temperature->gpu_temp_max_threshold)) {
                $this->pageData['tempmax'] = (string) str_replace('C', '°C', $data->temperature->gpu_temp_max_threshold);
            }
            if ($this->settings['TEMPFORMAT'] == 'F') {
                foreach (['temp', 'tempmax'] as $key) {
                    $this->pageData[$key] = $this->convertCelsius((int) $this->stripText('C', $this->pageData[$key])) . 'F';
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
                $this->pageData['power'] = $this->roundFloat((float) $data->power_readings->power_draw, 1);
            }
            if (isset($data->power_readings->power_limit)) {
                $this->pageData['powermax'] = $this->roundFloat((float) $data->power_readings->power_limit, 1);
            }
        }

    }

    /**
     * Retrieves NVIDIA card statistics
     */
    public function getStatistics($gpu)
    {
        if ($gpu['id'] === "FAKE_nvidia") {
            $this->stdout = file_get_contents(__DIR__ . '/../sample/nvidia-smi-stdout.txt');
        } else if ($this->cmdexists) {
            //Command invokes nvidia-smi in query all mode with XML return
            $this->stdout = shell_exec(self::CMD_UTILITY . ES . sprintf(self::STATISTICS_PARAM, $gpu['id']));
        } else {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_NOT_RETURNED);
        }

        if (!empty($this->stdout) && strlen($this->stdout) > 0) {
            $this->parseStatistics($gpu);
        } else {
            $this->pageData['error'][] = Error::get(Error::VENDOR_UTILITY_NOT_FOUND);
        }
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
            $this->pageData['memusedmax'] = (int) $data->fb_memory_usage->total;
            $this->pageData['memused'] = (int) $data->fb_memory_usage->used;
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

            // Set App HW Usage Defaults
            foreach (self::SUPPORTED_APPS as $app => $process) {
                $this->pageData['processes'][($app . "using")] = false;
                $this->pageData['processes'][($app . "mem")] = 0;
                $this->pageData['processes'][($app . "count")] = 0;
            }
            if (isset($data->product_name)) {
                $this->getProductName($data->product_name);
            }
            if (isset($data->uuid)) {
                $this->pageData['uuid'] = (string) $data->uuid;
            } else {
                $this->pageData['uuid'] = $gpu['id'];
            }
            $this->getUtilization($data);
            $this->getSensorData($data);
            if (isset($data->clocks, $data->max_clocks)) {
                if (isset($data->clocks->graphics_clock, $data->max_clocks->graphics_clock)) {
                    $this->pageData['clock'] = (int) $data->clocks->graphics_clock;
                    $this->pageData['clockunit'] = preg_replace('/[^a-zA-Z]/', '', $data->clocks->graphics_clock);
                    $this->pageData['clockmax'] = (int) $data->max_clocks->graphics_clock;
                }
                if (isset($data->clocks->sm_clock, $data->max_clocks->sm_clock)) {
                    $this->pageData['sm_clock'] = (int) $data->clocks->sm_clock;
                    $this->pageData['sm_clockunit'] = preg_replace('/[^a-zA-Z]/', '', $data->clocks->sm_clock);
                    $this->pageData['sm_clockmax'] = (int) $data->max_clocks->sm_clock;
                }
                if (isset($data->clocks->video_clock, $data->max_clocks->video_clock)) {
                    $this->pageData['video_clock'] = (int) $data->clocks->video_clock;
                    $this->pageData['video_clockunit'] = preg_replace('/[^a-zA-Z]/', '', $data->clocks->video_clock);
                    $this->pageData['video_clockmax'] = (int) $data->max_clocks->video_clock;
                }
                if (isset($data->clocks->mem_clock, $data->max_clocks->mem_clock)) {
                    $this->pageData['memclock'] = (int) $data->clocks->mem_clock;
                    $this->pageData['memclockunit'] = preg_replace('/[^a-zA-Z]/', '', $data->clocks->mem_clock);
                    $this->pageData['memclockmax'] = (int) $data->max_clocks->mem_clock;
                }
            }
            // For some reason, encoder_sessions->session_count is not reliable on my install, better to count processes
            $this->pageData['appssupp'] = array_keys(self::SUPPORTED_APPS);
            if (isset($data->processes->process_info)) {
                $this->pageData['sessions'] = count($data->processes->process_info);
                if ($this->pageData['sessions'] > 0) {
                    foreach ($data->processes->children() as $process) {
                        if ($gpu['id'] === "FAKE_nvidia") {
                            foreach (self::SUPPORTED_APPS as $app => $process) {
                                $this->pageData['processes'][($app . "using")] = true;
                                $this->pageData['processes'][($app . "mem")] = 25;
                                $this->pageData['processes'][($app . "count")] = 2;
                            }
                        } else if (isset($process->process_name)) {
                            $this->detectApplication($process);
                        }
                    }
                }
            }

            $this->getBusUtilization($data->pci);

        } else {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_BAD_PARSE);
        }
    }
}