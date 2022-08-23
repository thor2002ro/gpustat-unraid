<?php

namespace gpustat\lib;

use SimpleXMLElement;

/**
 * Class FakeNvidia
 * @package gpustat\lib
 */
class FakeNvidia extends Main
{
    const CMD_UTILITY = 'ps';
    const INVENTORY_PARAM = '-L';
    const INVENTORY_REGEX = '/GPU\s(?P<id>\d):\s(?P<model>.*)\s\(UUID:\s(?P<guid>GPU-[0-9a-f-]+)\)/i';
    const STATISTICS_PARAM = '-q -x -g %s 2>&1';
    const SUPPORTED_APPS = [ // Order here is important because some apps use the same binaries -- order should be more specific to less
        'plex'        => ['Plex Transcoder'],
        'jellyfin'    => ['jellyfin-ffmpeg'],
        'handbrake'   => ['/usr/bin/HandBrakeCLI'],
        'emby'        => ['emby'],
        'tdarr'       => ['ffmpeg', 'HandbrakeCLI'],
        'unmanic'     => ['ffmpeg'],
        'dizquetv'    => ['ffmpeg'],
        'deepstack'   => ['python3'],
        'nsfminer'    => ['nsfminer'],
        'shinobipro'  => ['shinobi'],
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
                                } elseif (strpos($pid_info, $app) === false) {
                                    // We didn't match the application name in the arguments, no match
                                    continue 2;
                                }
                            }
                        }
                    }
                    $this->pageData['processes'][($app . "using")]      = true;
                    $this->pageData['processes'][($app . "mem")]        += (int) $process->used_memory;
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
    public function getInventory(): array
    {
        $result = [];

        if ($this->cmdexists) {
            $this->runCommand(self::CMD_UTILITY, self::INVENTORY_PARAM, false);
            if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                $this->parseInventory(self::INVENTORY_REGEX);
                if (!empty($this->inventory)) {
                    $result = $this->inventory;
                }
            }
        }
        $inventory[] = [
            'vendor'        => 'Nvidia',
            'id'            => 91,
            'model'         => '970Gtx',
            'guid'          => 'GPU-0000-00-FAKE-000000',
        ];
        $result = $inventory;
        return $result;
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
        if ($this->settings['DISPTEMP']) {
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
        }
        if ($this->settings['DISPFAN']) {
            if (isset($data->fan_speed)) {
                $this->pageData['fan'] = $this->stripSpaces($data->fan_speed);
            }
        }
        if ($this->settings['DISPPWRSTATE']) {
            if (isset($data->performance_state)) {
                $this->pageData['perfstate'] = $this->stripSpaces($data->performance_state);
            }
        }
        if ($this->settings['DISPTHROTTLE']) {
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
        }
        if ($this->settings['DISPPWRDRAW']) {
            if (isset($data->power_readings)) {
                if (isset($data->power_readings->power_draw)) {
                    $this->pageData['power'] = $this->roundFloat((float) $data->power_readings->power_draw, 1);
                }
                if (isset($data->power_readings->power_limit)) {
                    $this->pageData['powermax'] = $this->roundFloat((float) $data->power_readings->power_limit, 1);
                }
            }
        }
    }

    /**
     * Retrieves NVIDIA card statistics
     */
    public function getStatistics(string $gpu)
    {
        if ($this->cmdexists) {
            //Command invokes nvidia-smi in query all mode with XML return
            $this->stdout = '<?xml version="1.0" ?>
            <!DOCTYPE nvidia_smi_log SYSTEM "nvsmi_device_v9.dtd">
            <nvidia_smi_log>
                    <timestamp>Tue Oct 24 23:04:13 2017</timestamp>
                    <driver_version>387.12</driver_version>
                    <attached_gpus>1</attached_gpus>
                    <gpu id="00000000:01:00.0">
                            <product_name>GeForce GTX 1060 6GB</product_name>
                            <product_brand>GeForce</product_brand>
                            <display_mode>Enabled</display_mode>
                            <display_active>Enabled</display_active>
                            <persistence_mode>Disabled</persistence_mode>
                            <accounting_mode>Disabled</accounting_mode>
                            <accounting_mode_buffer_size>1920</accounting_mode_buffer_size>
                            <driver_model>
                                    <current_dm>N/A</current_dm>
                                    <pending_dm>N/A</pending_dm>
                            </driver_model>
                            <serial>N/A</serial>
                            <uuid>GPU-028229ed-8dea-b674-09a8-13a841cb8626</uuid>
                            <minor_number>0</minor_number>
                            <vbios_version>86.06.0E.00.99</vbios_version>
                            <multigpu_board>No</multigpu_board>
                            <board_id>0x100</board_id>
                            <gpu_part_number>N/A</gpu_part_number>
                            <inforom_version>
                                    <img_version>G001.0000.01.03</img_version>
                                    <oem_object>1.1</oem_object>
                                    <ecc_object>N/A</ecc_object>
                                    <pwr_object>N/A</pwr_object>
                            </inforom_version>
                            <gpu_operation_mode>
                                    <current_gom>N/A</current_gom>
                                    <pending_gom>N/A</pending_gom>
                            </gpu_operation_mode>
                            <gpu_virtualization_mode>
                                    <virtualization_mode>None</virtualization_mode>
                            </gpu_virtualization_mode>
                            <pci>
                                    <pci_bus>01</pci_bus>                                                                                                                                                                           
                                    <pci_device>00</pci_device>                                                                                                                                                                     
                                    <pci_domain>0000</pci_domain>                                                                                                                                                                   
                                    <pci_device_id>1C0310DE</pci_device_id>                                                                                                                                                         
                                    <pci_bus_id>00000000:01:00.0</pci_bus_id>                                                                                                                                                       
                                    <pci_sub_system_id>371A1458</pci_sub_system_id>
                                    <pci_gpu_link_info>
                                            <pcie_gen>
                                                    <max_link_gen>3</max_link_gen>
                                                    <current_link_gen>2</current_link_gen>
                                            </pcie_gen>
                                            <link_widths>
                                                    <max_link_width>16x</max_link_width>
                                                    <current_link_width>16x</current_link_width>
                                            </link_widths>
                                    </pci_gpu_link_info>
                                    <pci_bridge_chip>
                                            <bridge_chip_type>N/A</bridge_chip_type>
                                            <bridge_chip_fw>N/A</bridge_chip_fw>
                                    </pci_bridge_chip>
                                    <replay_counter>0</replay_counter>
                                    <tx_util>0 KB/s</tx_util>
                                    <rx_util>185000 KB/s</rx_util>
                            </pci>
                            <fan_speed>0 %</fan_speed>
                            <performance_state>P5</performance_state>
                            <clocks_throttle_reasons>
                                    <clocks_throttle_reason_gpu_idle>Active</clocks_throttle_reason_gpu_idle>
                                    <clocks_throttle_reason_applications_clocks_setting>Not Active</clocks_throttle_reason_applications_clocks_setting>
                                    <clocks_throttle_reason_sw_power_cap>Not Active</clocks_throttle_reason_sw_power_cap>
                                    <clocks_throttle_reason_hw_slowdown>Not Active</clocks_throttle_reason_hw_slowdown>
                                    <clocks_throttle_reason_sync_boost>Not Active</clocks_throttle_reason_sync_boost>
                                    <clocks_throttle_reason_sw_thermal_slowdown>Not Active</clocks_throttle_reason_sw_thermal_slowdown>
                            </clocks_throttle_reasons>
                            <fb_memory_usage>
                                    <total>6064 MiB</total>
                                    <used>912 MiB</used>
                                    <free>5152 MiB</free>
                            </fb_memory_usage>
                            <bar1_memory_usage>
                                    <total>256 MiB</total>
                                    <used>17 MiB</used>
                                    <free>239 MiB</free>
                            </bar1_memory_usage>
                            <compute_mode>Default</compute_mode>
                            <utilization>
                                    <gpu_util>29 %</gpu_util>
                                    <memory_util>16 %</memory_util>
                                    <encoder_util>0 %</encoder_util>
                                    <decoder_util>0 %</decoder_util>
                            </utilization>
                            <encoder_stats>
                                    <session_count>0</session_count>
                                    <average_fps>0</average_fps>
                                    <average_latency>0</average_latency>
                            </encoder_stats>
                            <ecc_mode>
                                    <current_ecc>N/A</current_ecc>
                                    <pending_ecc>N/A</pending_ecc>
                            </ecc_mode>
                            <ecc_errors>
                                    <volatile>
                                            <single_bit>
                                                    <device_memory>N/A</device_memory>
                                                    <register_file>N/A</register_file>
                                                    <l1_cache>N/A</l1_cache>
                                                    <l2_cache>N/A</l2_cache>
                                                    <texture_memory>N/A</texture_memory>
                                                    <texture_shm>N/A</texture_shm>
                                                    <cbu>N/A</cbu>
                                                    <total>0</total>
                                            </single_bit>
                                            <double_bit>
                                                    <device_memory>N/A</device_memory>
                                                    <register_file>N/A</register_file>
                                                    <l1_cache>N/A</l1_cache>
                                                    <l2_cache>N/A</l2_cache>
                                                    <texture_memory>N/A</texture_memory>
                                                    <texture_shm>N/A</texture_shm>
                                                    <cbu>N/A</cbu>
                                                    <total>0</total>
                                            </double_bit>
                                    </volatile>
                                    <aggregate>
                                            <single_bit>
                                                    <device_memory>N/A</device_memory>
                                                    <register_file>N/A</register_file>
                                                    <l1_cache>N/A</l1_cache>
                                                    <l2_cache>N/A</l2_cache>
                                                    <texture_memory>N/A</texture_memory>
                                                    <texture_shm>N/A</texture_shm>
                                                    <cbu>N/A</cbu>
                                                    <total>0</total>
                                            </single_bit>
                                            <double_bit>
                                                    <device_memory>N/A</device_memory>
                                                    <register_file>N/A</register_file>
                                                    <l1_cache>N/A</l1_cache>
                                                    <l2_cache>N/A</l2_cache>
                                                    <texture_memory>N/A</texture_memory>
                                                    <texture_shm>N/A</texture_shm>
                                                    <cbu>N/A</cbu>
                                                    <total>0</total>
                                            </double_bit>
                                    </aggregate>
                            </ecc_errors>
                            <retired_pages>
                                    <multiple_single_bit_retirement>
                                            <retired_count>N/A</retired_count>
                                            <retired_page_addresses>N/A</retired_page_addresses>
                                    </multiple_single_bit_retirement>
                                    <double_bit_retirement>
                                            <retired_count>N/A</retired_count>
                                            <retired_page_addresses>N/A</retired_page_addresses>
                                    </double_bit_retirement>
                                    <pending_retirement>N/A</pending_retirement>
                            </retired_pages>
                            <temperature>
                                    <gpu_temp>56 C</gpu_temp>
                                    <gpu_temp_max_threshold>102 C</gpu_temp_max_threshold>
                                    <gpu_temp_slow_threshold>99 C</gpu_temp_slow_threshold>
                                    <gpu_temp_max_gpu_threshold>N/A</gpu_temp_max_gpu_threshold>
                                    <memory_temp>N/A</memory_temp>
                                    <gpu_temp_max_mem_threshold>N/A</gpu_temp_max_mem_threshold>
                            </temperature>
                            <power_readings>
                                    <power_state>P5</power_state>
                                    <power_management>Supported</power_management>
                                    <power_draw>15.42 W</power_draw>
                                    <power_limit>120.00 W</power_limit>
                                    <default_power_limit>120.00 W</default_power_limit>
                                    <enforced_power_limit>120.00 W</enforced_power_limit>
                                    <min_power_limit>60.00 W</min_power_limit>
                                    <max_power_limit>140.00 W</max_power_limit>
                            </power_readings>
                            <clocks>
                                    <graphics_clock>759 MHz</graphics_clock>
                                    <sm_clock>759 MHz</sm_clock>
                                    <mem_clock>810 MHz</mem_clock>
                                    <video_clock>683 MHz</video_clock>
                            </clocks>
                            <applications_clocks>
                                    <graphics_clock>N/A</graphics_clock>
                                    <mem_clock>N/A</mem_clock>
                            </applications_clocks>
                            <default_applications_clocks>
                                    <graphics_clock>N/A</graphics_clock>
                                    <mem_clock>N/A</mem_clock>
                            </default_applications_clocks>
                            <max_clocks>
                                    <graphics_clock>1961 MHz</graphics_clock>
                                    <sm_clock>1961 MHz</sm_clock>
                                    <mem_clock>4004 MHz</mem_clock>
                                    <video_clock>1708 MHz</video_clock>
                            </max_clocks>
                            <max_customer_boost_clocks>
                                    <graphics_clock>N/A</graphics_clock>
                            </max_customer_boost_clocks>
                            <clock_policy>
                                    <auto_boost>N/A</auto_boost>
                                    <auto_boost_default>N/A</auto_boost_default>
                            </clock_policy>
                            <supported_clocks>N/A</supported_clocks>
                            <processes>
                                    <process_info>
                                            <pid>1813</pid>
                                            <type>G</type>
                                            <process_name>/usr/lib/xorg/Xorg</process_name>
                                            <used_memory>329 MiB</used_memory>
                                    </process_info>
                                    <process_info>
                                            <pid>5935</pid>
                                            <type>G</type>
                                            <process_name>kwin_x11</process_name>
                                            <used_memory>212 MiB</used_memory>
                                    </process_info>
                                    <process_info>
                                            <pid>5939</pid>
                                            <type>G</type>
                                            <process_name>/usr/bin/krunner</process_name>
                                            <used_memory>1 MiB</used_memory>
                                    </process_info>
                                    <process_info>
                                            <pid>5940</pid>
                                            <type>G</type>
                                            <process_name>/usr/bin/plasmashell</process_name>
                                            <used_memory>103 MiB</used_memory>
                                    </process_info>
                                    <process_info>
                                            <pid>6064</pid>
                                            <type>G</type>
                                            <process_name>share/jetbrains-toolbox/jetbrains-toolbox</process_name>
                                            <used_memory>2 MiB</used_memory>
                                    </process_info>
                                    <process_info>
                                            <pid>6635</pid>
                                            <type>G</type>
                                            <process_name>/opt/google/chrome/chrome --type=gpu-process --field-trial-handle=3267655270993955714,12768845709541001258,131072 --ignore-gpu-blacklist --disable-breakpad --gpu-vendor-id=0x10de --gpu-device-id=0x1c03 --gpu-driver-vendor=Nvidia --gpu-driver-version=387.12 --gpu-driver-date --service-request-channel-token=EF338332EF829C1B7DBC803AB9C93B96</process_name>
                                            <used_memory>120 MiB</used_memory>
                                    </process_info>
                                    <process_info>
                                            <pid>12129</pid>
                                            <type>G</type>
                                            <process_name>/usr/share/discord/Discord --type=gpu-process --no-sandbox --supports-dual-gpus=false --gpu-driver-bug-workarounds=7,23,71 --gpu-vendor-id=0x10de --gpu-device-id=0x1c03 --gpu-driver-vendor=NVIDIA --gpu-driver-version=387.12 --gpu-driver-date --service-request-channel-token=9B94E971FF24921D6557DC1AA4475391 --v8-natives-passed-by-fd --v8-snapshot-passed-by-fd</process_name>
                                            <used_memory>110 MiB</used_memory>
                                    </process_info>
                                    <process_info>
                                            <pid>21567</pid>
                                            <type>G</type>
                                            <process_name>./ts3client_linux_amd64</process_name>
                                            <used_memory>2 MiB</used_memory>
                                    </process_info>
                                    <process_info>
                                            <pid>30984</pid>
                                            <type>G</type>
                                            <process_name>/usr/local/bin/latte-dock</process_name>
                                            <used_memory>25 MiB</used_memory>
                                    </process_info>
                            </processes>
                            <accounted_processes>
                            </accounted_processes>
                    </gpu>
            
            </nvidia_smi_log>';
            if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                $this->parseStatistics($gpu);
            } else {
                $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_NOT_RETURNED);
            }
        } else {
            $this->pageData['error'][] = Error::get(Error::VENDOR_UTILITY_NOT_FOUND);
        }
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
            if ($this->settings['DISPENCDEC']) {
                if (isset($data->utilization->encoder_util)) {
                    $this->pageData['encutil'] = $this->stripSpaces($data->utilization->encoder_util);
                }
                if (isset($data->utilization->decoder_util)) {
                    $this->pageData['decutil'] = $this->stripSpaces($data->utilization->decoder_util);
                }
            }
        }
        if ($this->settings['DISPMEMUSEDUTIL']) {
            if (isset($data->fb_memory_usage->used, $data->fb_memory_usage->total)) {
                $this->pageData['memusedmax'] = (int) $data->fb_memory_usage->total;
                $this->pageData['memused'] = (int) $data->fb_memory_usage->used;
                $this->pageData['memusedunit'] = preg_replace('/[^a-zA-Z]/', '', $data->fb_memory_usage->used);
                $this->pageData['memusedutil'] = round($this->pageData['memused'] / $this->pageData['memusedmax'] * 100) . "%";
            }
        }
    }

    /**
     * Loads stdout into SimpleXMLObject then retrieves and returns specific definitions in an array
     */
    private function parseStatistics(string $gpu)
    {
        $data = @simplexml_load_string($this->stdout);
        $this->stdout = '';

        if ($data instanceof SimpleXMLElement && !empty($data->gpu)) {

            $data = $data->gpu;
            $this->pageData += [
                'vendor'        => $this->praseGPU($gpu)[0],
                'name'          => $this->praseGPU($gpu)[1],
                'clockmax'      => 'N/A',
                'memclockmax'   => 'N/A',
                'memtotal'      => 'N/A',
                'encutil'       => 'N/A',
                'decutil'       => 'N/A',
                'pciemax'       => 'N/A',
                'perfstate'     => 'N/A',
                'throttled'     => 'N/A',
                'thrtlrsn'      => '',
                'pciegen'       => 'N/A',
                'pciegenmax'    => 'N/A',
                'pciewidth'     => 'N/A',
                'pciewidthmax'  => 'N/A',
                'sessions'      => 0,
                'uuid'          => 'N/A',
            ];

            $this->pageData += [
                'powerunit'     => 'W',
                //'fanunit'       => 'RPM',   // card returns % not fan speed
                'voltageunit'   => 'V',
                'tempunit'      => $this->settings['TEMPFORMAT'],
                'rxutilunit'    => "MB/s",
                'txutilunit'    => "MB/s",
            ];

            // Set App HW Usage Defaults
            foreach (self::SUPPORTED_APPS as $app => $process) {
                $this->pageData['processes'][($app . "using")]      = false;
                $this->pageData['processes'][($app . "mem")]        = 0;
                $this->pageData['processes'][($app . "count")]      = 0;
            }

            //$this->pageData['vendor'] = 'NVIDIA';

            if (isset($data->product_name)) {
                $this->getProductName($data->product_name);
            }
            if (isset($data->uuid)) {
                $this->pageData['uuid'] = (string) $data->uuid;
            } else {
                $this->pageData['uuid'] = $this->praseGPU($gpu)[2];
            }
            $this->getUtilization($data);
            $this->getSensorData($data);
            if ($this->settings['DISPCLOCKS']) {
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
            }
            // For some reason, encoder_sessions->session_count is not reliable on my install, better to count processes
            if ($this->settings['DISPSESSIONS']) {
                $this->pageData['appssupp'] = array_keys(self::SUPPORTED_APPS);
                if (isset($data->processes->process_info)) {
                    $this->pageData['sessions'] = count($data->processes->process_info);
                    if ($this->pageData['sessions'] > 0) {
                        foreach ($data->processes->children() as $process) {
                            if (isset($process->process_name)) {
                                $this->detectApplication($process);
                            }
                        }
                    }
                }
            }
            if ($this->settings['DISPPCIUTIL']) {
                $this->getBusUtilization($data->pci);
            }
        } else {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_BAD_PARSE);
        }

        $this->echoJson();
    }
}
