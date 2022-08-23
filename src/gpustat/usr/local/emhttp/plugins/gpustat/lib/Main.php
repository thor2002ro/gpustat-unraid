<?php

namespace gpustat\lib;

/** @noinspection PhpIncludeInspection */
require_once('/usr/local/emhttp/plugins/dynamix/include/Wrappers.php');

/**
 * Class Main
 * @package gpustat\lib
 */
class Main
{
    const PLUGIN_NAME = 'gpustat';
    const COMMAND_EXISTS_CHECKER = 'which';

    /**
     * @var array
     */
    public $settings;

    /**
     * @var string
     */
    protected $stdout;

    /**
     * @var array
     */
    protected $inventory;

    /**
     * @var array
     */
    protected $gpu_lspci;

    /**
     * @var array
     */
    protected $pageData;

    /**
     * @var bool
     */
    protected $cmdexists;

    /**
     * GPUStat constructor.
     *
     * @param array $settings
     */
    public function __construct(array $settings = [])
    {
        $this->settings = $settings;
        if (isset($this->settings['inventory'])) {
            $this->checkCommand($this->settings['cmd'], false);
        } else {
            $this->checkCommand($this->settings['cmd']);
        }

        $this->stdout = '';
        $this->inventory = [];

        $this->pageData = [
            'clock'         => 'N/A',
            'fan'           => 'N/A',
            'memclock'      => 'N/A',
            'memusedutil'   => 'N/A',
            'memused'       => 'N/A',
            'power'         => 'N/A',
            'powermax'      => 'N/A',
            'rxutil'        => 'N/A',
            'txutil'        => 'N/A',
            'temp'          => 'N/A',
            'tempmax'       => 'N/A',
            'util'          => 'N/A',
        ];
    }

    /**
     * Checks if vendor utility exists in the system and dies if it does not
     *
     * @param string $utility
     * @param bool $error
     */
    protected function checkCommand(string $utility, $error = true)
    {
        $this->cmdexists = false;
        // Check if vendor utility is available
        $this->runCommand(self::COMMAND_EXISTS_CHECKER, $utility, false);
        // When checking for existence of the command, we want the return to be NULL
        if (!empty($this->stdout)) {
            $this->cmdexists = true;
        } else {
            // Send the error but don't die because we need to continue for inventory
            if ($error) {
                $this->pageData['error'][] = Error::get(Error::VENDOR_UTILITY_NOT_FOUND);
            }
        }
    }

    /**
     * Runs a command in shell and stores STDOUT in class variable
     *
     * @param string $command
     * @param string $argument
     * @param bool $escape
     */
    protected function runCommand(string $command, string $argument = '', $escape = true)
    {
        if ($escape) {
            $this->stdout = shell_exec(sprintf("%s %s", $command, escapeshellarg($argument)));
        } else {
            $this->stdout = shell_exec(sprintf("%s %s", $command, $argument));
        }
    }

    /**
     * Retrieves the full command with arguments for a given process ID
     *
     * @param int $pid
     * @return string
     */
    protected function getFullCommand(int $pid): string
    {
        $command = '';
        $file = sprintf('/proc/%0d/cmdline', $pid);

        if (file_exists($file)) {
            $command = trim(file_get_contents($file), "\0");
        }

        return $command;
    }

    /**
     * Retrieves plugin settings and returns them or defaults if no file
     *
     * @return mixed
     */
    public static function getSettings()
    {
        /** @noinspection PhpUndefinedFunctionInspection */
        return parse_plugin_cfg(self::PLUGIN_NAME);
    }

    /**
     * prase gpu line
     * [0] name
     * [1] bus
     *
     * @return mixed
     */
    public static function praseGPU(string $gpu)
    {
        return explode('|||||', $gpu);
    }

    /**
     * prase lspci gpu speed to pcie gen
     * return pcie gen nr
     *
     * @return mixed
     */
    public static function prasePCIEgen(float $pciespeed_in)
    {
        if ($pciespeed_in == '2.5') {
            return 1;
        } else if ($pciespeed_in == '5') {
            return 2;
        } else if ($pciespeed_in == '8') {
            return 3;
        } else if ($pciespeed_in == '16') {
            return 4;
        } else if ($pciespeed_in == '32' || $pciespeed_in == '25') {
            return 5;
        } else {
            return 0;
        }
    }

    /**
     * Triggers regex match all against class variable stdout and places matches in class variable inventory
     *
     * @param string $regex
     */
    protected function parseInventory(string $regex = '')
    {
        $ret = [];
        preg_match_all($regex, $this->stdout, $ret, PREG_SET_ORDER);
        return $ret;
    }

    /**
     * Echoes JSON to web renderer -- used to populate page data
     */
    protected function echoJson()
    {
        // Page file JavaScript expects a JSON encoded string
        if (is_array($this->pageData)) {
            // If errors exist, do not encode anything else for send
            if (isset($this->pageData['errors'])) {
                $json = json_encode($this->pageData['errors']);
            } else {
                $json = json_encode($this->pageData);
            }
            header('Content-Type: application/json');
            header('Content-Length:' . ES . strlen($json));
            echo $json;
        } else {
            // Can't echo JSON for debug, so print_r for array data
            print_r(Error::get(Error::BAD_ARRAY_DATA));
        }
    }

    /**
     * Strips all spaces from a provided string
     *
     * @param string $text
     * @return string
     */
    protected static function stripSpaces(string $text = ''): string
    {
        return str_replace(' ', '', $text);
    }

    /**
     * Converts Celsius to Fahrenheit
     *
     * @param int $temp
     * @return float
     */
    protected static function convertCelsius(int $temp = 0): float
    {
        $fahrenheit = $temp * (9 / 5) + 32;

        return round($fahrenheit, -1, PHP_ROUND_HALF_UP);
    }

    /**
     * Rounds a float to a whole number
     *
     * @param float $number
     * @param int $precision
     * @return float
     */
    protected static function roundFloat(float $number, int $precision = 0): float
    {
        if ($precision > 0) {
            $result = number_format(round($number, $precision, PHP_ROUND_HALF_UP), $precision, '.', '');
        } else {
            $result = round($number, $precision, PHP_ROUND_HALF_UP);
        }

        return $result;
    }

    /**
     * Replaces a string within a string with an empty string
     *
     * @param string|string[] $strip
     * @param string $string
     * @return string|string[]
     */
    protected static function stripText($strip, string $string)
    {
        return str_replace($strip, '', $string);
    }
}
