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
                            'model' => (string) $gpu['model'],
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
     * Retrieves Intel iGPU statistics
     */
    public function getStatistics($gpu)
    {
        if ($gpu['id'] === "FAKE_intel") {
            $this->stdout = file_get_contents(__DIR__ . '/../sample/intel-gpu-top-stdout.txt');
        } else if ($this->cmdexists) {
            //Command invokes intel_gpu_top in JSON output mode with an update rate of 5 seconds
            $command = self::STATISTICS_WRAPPER . ES . self::CMD_UTILITY;
            $this->runCommand($command, self::STATISTICS_PARAM . $gpu['id'] . '"', false);
        }
        if (!empty($this->stdout) && strlen($this->stdout) > 0) {
            $this->parseStatistics($gpu);
        } else {
            $this->pageData['error'][] += Error::get(Error::VENDOR_DATA_NOT_RETURNED);
        }
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
        } catch (JsonException $e) {
            $data = [];
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_BAD_PARSE, $e->getMessage());
        }

        // Need to make sure we have at least two array indexes to take the second one
        $count = count($data);
        if ($count < 2) {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_NOT_ENOUGH, "Count: $count");
        }

        // intel_gpu_top will never show utilization counters on the first sample so we need the second position
        $data = $data[1];
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
            ];

            $this->pageData += [
                'powerunit' => 'W',
                'fanunit' => 'RPM',
                'voltageunit' => 'V',
                'tempunit' => $this->settings['TEMPFORMAT'],
            ];

            if (isset($data['engines']['Render/3D/0']['busy'])) {
                $this->pageData['util'] /*= $this->pageData['3drender']*/= $this->roundFloat($data['engines']['Render/3D/0']['busy'], 1) . $data['engines']['Render/3D/0']['unit'];
            }

            if (isset($data['engines']['Blitter/0']['busy'])) {
                $this->pageData['blitter'] = $this->roundFloat($data['engines']['Blitter/0']['busy']) . $data['engines']['Blitter/0']['unit'];
            }

            if (isset($data['engines']['Video/0']['busy'])) {
                $this->pageData['video'] = $this->roundFloat($data['engines']['Video/0']['busy']) . $data['engines']['Video/0']['unit'];
            }

            if (isset($data['engines']['VideoEnhance/0']['busy'])) {
                $this->pageData['videnh'] = $this->roundFloat($data['engines']['VideoEnhance/0']['busy']) . $data['engines']['VideoEnhance/0']['unit'];
            }

            if (isset($data['imc-bandwidth']['reads'], $data['imc-bandwidth']['writes'])) {
                $this->pageData['rxutilunit'] = $data['imc-bandwidth']['unit'];
                $this->pageData['txutilunit'] = $data['imc-bandwidth']['unit'];
                $this->pageData['rxutil'] = $this->roundFloat($data['imc-bandwidth']['reads'], 2);
                $this->pageData['txutil'] = $this->roundFloat($data['imc-bandwidth']['writes'], 2);
            }

            // Older versions of intel_gpu_top in case people haven't updated
            if (isset($data['power']['value'])) {
                $this->pageData['powerunit'] = $data['power']['unit'];

                $this->pageData['power'] = $this->roundFloat($data['power']['value'], 2);
                // Newer version of intel_gpu_top includes GPU and package power readings, just scrape GPU for now
            } elseif (isset($data['power']['GPU'])) {
                $this->pageData['power'] = $this->roundFloat($data['power']['GPU'], 2);
            }

            // According to the sparse documentation, rc6 is a percentage of how little the GPU is requesting power
            if (isset($data['rc6']['value'])) {
                $this->pageData['powerutil'] = $this->roundFloat(100 - $data['rc6']['value'], 2) . $data['rc6']['unit'];
                $this->pageData['powermax'] = (int) ((100 * $this->pageData['power']) / $this->pageData['powerutil']);
            }

            if (isset($data['frequency']['actual'])) {
                $this->pageData['clockunit'] = $data['frequency']['unit'];
                $this->pageData['clock'] = $this->roundFloat($data['frequency']['actual']);
            }

            if (isset($data['interrupts']['count'])) {
                $this->pageData['interruptsunit'] = $data['interrupts']['unit'];
                $this->pageData['interrupts'] = (int) $this->roundFloat($data['interrupts']['count']);
            }

        } else {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_BAD_PARSE);
        }
    }
}