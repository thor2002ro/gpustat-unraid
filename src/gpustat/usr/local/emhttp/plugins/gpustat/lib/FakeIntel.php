<?php

namespace gpustat\lib;

use JsonException;

/**
 * Class FakeIntel
 * @package gpustat\lib
 */
class FakeIntel extends Main
{
    const CMD_UTILITY = 'ps';
    const INVENTORY_UTILITY = 'lspci';
    const INVENTORY_PARAM = "| grep VGA";
    const INVENTORY_REGEX =
    '/VGA.+:\s+Intel\s+Corporation\s+(?P<model>.*)\s+(\[|Family|Integrated|Graphics|Controller|Series|\()/iU';
    const STATISTICS_PARAM = '';
    const STATISTICS_WRAPPER = 'timeout -k .500 .400';

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
        $result = $inventory = [];

        $inventory[] = [
            'vendor'        => 'Intel',
            'id'            => 99,
            'model'         => '640',
            'guid'          => '0000-00-FAKE-000000',
        ];
        $result = $inventory;
        return $result;
    }

    /**
     * Retrieves Intel iGPU statistics
     */
    public function getStatistics(string $gpu)
    {
        if ($this->cmdexists) {
            //Command invokes intel_gpu_top in JSON output mode with an update rate of 5 seconds
            $command = self::STATISTICS_WRAPPER . ES . self::CMD_UTILITY;
            $this->runCommand($command, self::STATISTICS_PARAM, false);
            if (!empty($this->stdout) && strlen($this->stdout) > 0) {
                $this->parseStatistics($gpu);
            } else {
                $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_NOT_RETURNED);
            }
        }
    }

    /**
     * Loads JSON into array then retrieves and returns specific definitions in an array
     */
    private function parseStatistics(string $gpu)
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
                'vendor'        => $this->praseGPU($gpu)[0],
                'name'          => $this->praseGPU($gpu)[1],
                '3drender'      => 'N/A',
                'blitter'       => 'N/A',
                'interrupts'    => 'N/A',
                'powerutil'     => 'N/A',
                'video'         => 'N/A',
                'videnh'        => 'N/A',
            ];

            if ($this->settings['DISP3DRENDER']) {
                if (isset($data['engines']['Render/3D/0']['busy'])) {
                    $this->pageData['util'] = $this->pageData['3drender'] = $this->roundFloat($data['engines']['Render/3D/0']['busy']) . '%';
                }
            }
            if ($this->settings['DISPBLITTER']) {
                if (isset($data['engines']['Blitter/0']['busy'])) {
                    $this->pageData['blitter'] = $this->roundFloat($data['engines']['Blitter/0']['busy']) . '%';
                }
            }
            if ($this->settings['DISPVIDEO']) {
                if (isset($data['engines']['Video/0']['busy'])) {
                    $this->pageData['video'] = $this->roundFloat($data['engines']['Video/0']['busy']) . '%';
                }
            }
            if ($this->settings['DISPVIDENH']) {
                if (isset($data['engines']['VideoEnhance/0']['busy'])) {
                    $this->pageData['videnh'] = $this->roundFloat($data['engines']['VideoEnhance/0']['busy']) . '%';
                }
            }
            if ($this->settings['DISPPCIUTIL']) {
                if (isset($data['imc-bandwidth']['reads'], $data['imc-bandwidth']['writes'])) {
                    $this->pageData['rxutil'] = $this->roundFloat($data['imc-bandwidth']['reads'], 2) . " MB/s";
                    $this->pageData['txutil'] = $this->roundFloat($data['imc-bandwidth']['writes'], 2) . " MB/s";
                }
            }
            if ($this->settings['DISPPWRDRAW']) {
                // Older versions of intel_gpu_top in case people haven't updated
                if (isset($data['power']['value'])) {
                    $this->pageData['power'] = $this->roundFloat($data['power']['value'], 2) . $data['power']['unit'];
                    // Newer version of intel_gpu_top includes GPU and package power readings, just scrape GPU for now
                } elseif (isset($data['power']['GPU'])) {
                    $this->pageData['power'] = $this->roundFloat($data['power']['GPU'], 2) . $data['power']['unit'];
                }
            }
            // According to the sparse documentation, rc6 is a percentage of how little the GPU is requesting power
            if ($this->settings['DISPPWRSTATE']) {
                if (isset($data['rc6']['value'])) {
                    $this->pageData['powerutil'] = $this->roundFloat(100 - $data['rc6']['value'], 2) . "%";
                }
            }
            if ($this->settings['DISPCLOCKS']) {
                if (isset($data['frequency']['actual'])) {
                    $this->pageData['clock'] = (int) $this->roundFloat($data['frequency']['actual']);
                }
            }
            if ($this->settings['DISPINTERRUPT']) {
                if (isset($data['interrupts']['count'])) {
                    $this->pageData['interrupts'] = (int) $this->roundFloat($data['interrupts']['count']);
                }
            }
        } else {
            $this->pageData['error'][] = Error::get(Error::VENDOR_DATA_BAD_PARSE);
        }

        $this->pageData = [
            'vendor'        => $this->praseGPU($gpu)[0],
            'name'          => $this->praseGPU($gpu)[1],
            '3drender'      => '50%',
            'blitter'       => '25%',
            'interrupts'    => '421',
            'powerutil'     => '50%',
            'video'         => '80%',
            'videnh'        => '50%',
            'util'          => '50%',
            'rxutil'        => '50.50 MB/s',
            'txutil'        => '25.20 MB/s',
            'power'         => '10W',
            'clock'         => '825',

        ];

        $this->echoJson();
    }
}
