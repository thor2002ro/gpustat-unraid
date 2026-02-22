<?php

namespace gpustat\lib;

require_once('/usr/local/emhttp/plugins/dynamix/include/Wrappers.php');

// Base class for all vendor GPU classes (AMD, Nvidia, Intel).
// Provides: command execution, inventory parsing, settings, temp conversion, PCIe gen lookup.
class Main
{
    const PLUGIN_NAME = 'gpustat';
    const COMMAND_EXISTS_CHECKER = 'which';

    /** @var array */
    public $settings;

    /** @var string */
    protected $stdout;

    /** @var array */
    protected $inventory;

    /** @var array */
    protected $pageData;

    /** @var bool */
    protected $cmdexists;

    /**
     * GPUStat constructor.
     * @param array $settings
     */
    public function __construct(array $settings = [])
    {
        $this->settings = $settings;
        $this->stdout = '';
        $this->inventory = [];

        // Default values — overridden by vendor getStatistics()
        $this->pageData = [
            'clock' => 'N/A',
            'fan' => 'N/A',
            'memclock' => 'N/A',
            'memutil' => 'N/A',
            'memused' => 'N/A',
            'power' => 'N/A',
            'powermax' => 'N/A',
            'rxutil' => 'N/A',
            'txutil' => 'N/A',
            'temp' => 'N/A',
            'tempmax' => 'N/A',
            'util' => 'N/A',
        ];

        // Check if vendor utility is available
        $cmd = isset($this->settings['cmd']) ? $this->settings['cmd'] : '';
        $errorOnFailure = !isset($this->settings['inventory']);
        $this->checkCommand($cmd, $errorOnFailure);
    }

    /**
     * Checks if vendor utility exists
     * @param string $utility
     * @param bool $error
     */
    protected function checkCommand(string $utility, bool $error = true)
    {
        $this->cmdexists = false;
        if (empty($utility))
            return;

        $this->runCommand(self::COMMAND_EXISTS_CHECKER, $utility, false);

        if (!empty(trim($this->stdout))) {
            $this->cmdexists = true;
        }
        elseif ($error) {
            $this->pageData['error'][] = Error::get(Error::VENDOR_UTILITY_NOT_FOUND);
        }
    }

    /**
     * Runs command and stores output
     * @param string $command
     * @param string $argument
     * @param bool $escape
     */
    protected function runCommand(string $command, string $argument = '', bool $escape = true)
    {
        $arg = $escape ? escapeshellarg($argument) : $argument;
        // Added 2>&1 to capture STDERR in case of permissions issues
        $this->stdout = (string)shell_exec(sprintf("%s %s 2>&1", $command, $arg));
    }

    /**
     * Retrieves full command from /proc
     * @param int $pid
     * @return string
     */
    protected function getFullCommand(int $pid): string
    {
        $file = sprintf('/proc/%d/cmdline', $pid);
        if (file_exists($file) && is_readable($file)) {
            $content = file_get_contents($file);
            // cmdline arguments are null-terminated, replace with spaces for readability
            return $content !== false ? trim(str_replace("\0", " ", $content)) : '';
        }
        return '';
    }

    /**
     * Retrieves parent command
     * @param int $pid
     * @return string
     */
    protected function getParentCommand(int $pid): string
    {
        // Modernized ps command to get PPID without complex awk/cut piping
        $ppid = (int)shell_exec(sprintf("ps -o ppid= -p %d", $pid));
        return ($ppid > 0) ? $this->getFullCommand($ppid) : '';
    }

    /**
     * @return array
     */
    public static function getSettings()
    {
        return parse_plugin_cfg(self::PLUGIN_NAME) ?: [];
    }

    /**
     * @param string $regex
     * @return array
     */
    protected function parseInventory(string $regex = '')
    {
        $ret = [];
        if (!empty($regex)) {
            preg_match_all($regex, $this->stdout, $ret, PREG_SET_ORDER);
        }
        return $ret;
    }

    /**
     * @param string $text
     * @return string
     */
    protected static function stripSpaces(string $text = ''): string
    {
        return str_replace(' ', '', $text);
    }

    /**
     * Converts Celsius to Fahrenheit
     * @param int $temp
     * @return float
     */
    protected static function convertCelsius(int $temp = 0): float
    {
        $fahrenheit = $temp * (9 / 5) + 32;
        // Return float, do NOT add strings here
        return round($fahrenheit, 1);
    }

    /**
     * @param float $number
     * @param int $precision
     * @return float|string
     */
    protected static function roundFloat(float $number, int $precision = 0)
    {
        // Strictly return a float/int so the UI generates bars
        return (float)round($number, $precision);
    }

    /**
     * @param string|array $strip
     * @param string $string
     * @return string
     */
    protected static function stripText($strip, string $string)
    {
        return str_replace($strip, '', $string);
    }

    // Convert PCIe link speed (GT/s) to generation number
    // e.g. "8GT/s" → Gen 3, "16GT/s" → Gen 4
    function parsePCIEgen(string $rawSpeed): int
    {
        $speed = (float)preg_replace('/[^0-9.]/', '', $rawSpeed);
        if ($speed >= 64.0)
            return 6; // PCIe 6.0
        if ($speed >= 32.0)
            return 5; // PCIe 5.0
        if ($speed >= 16.0)
            return 4; // PCIe 4.0
        if ($speed >= 8.0)
            return 3; // PCIe 3.0
        if ($speed >= 5.0)
            return 2; // PCIe 2.0
        if ($speed >= 2.5)
            return 1; // PCIe 1.0
        return 0;
    }
}