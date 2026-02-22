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

        $this->pageData = [];

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

    // ─── Docker / VM / VFIO Detection ────────────────────────────────────────

    const DOCKER_INSPECT = 'docker container inspect';
    const DOCKER_ICON_DEFAULT_PATH = '/plugins/dynamix.docker.manager/images/question.png';
    const DOCKER_ICON_PATH = '/var/local/emhttp/plugins/dynamix.docker.manager/docker.json';

    // Default host-level process icons (extensible via /boot/config/plugins/gpustat/hostapps.json)
    const HOST_APPS = [
        'xorg' => ['/plugins/gpustat/images/xorg.png'],
        'qemu-system-x86' => ['/plugins/gpustat/images/qemu.png'],
        'firefox-bin' => ['/plugins/gpustat/images/firefox-bin.png'],
    ];

    /**
     * Checks if a GPU is bound to the vfio-pci driver (passed through to a VM)
     * @param string $pciid Full PCI ID (e.g. "0000:0c:00.0")
     * @return bool
     */
    protected function checkVFIO(string $pciid): bool
    {
        $files = @scandir('/sys/bus/pci/drivers/vfio-pci/');
        return $files ? in_array($pciid, $files) : false;
    }

    /**
     * Detects which VM is using a passed-through GPU via virsh
     * @param string $pciid PCI bus ID (e.g. "0c:00.0")
     * @return string|false "VMName,/path/to/icon.png" or false
     */
    protected function getGpuVm(string $pciid)
    {
        // Check if libvirt is running
        if (!is_file('/var/run/libvirt/libvirtd.pid')) {
            return false;
        }

        // Build PCI device ID → bus mapping from lspci
        static $lspci = null;
        if ($lspci === null) {
            $lspci = [];
            $lines = explode("\n", trim((string)shell_exec('lspci -n')));
            foreach ($lines as $line) {
                $cleaned = preg_replace('/\s*\(.*?\)\s*/', '', $line);
                $parts = explode(' ', $cleaned, 2);
                if (count($parts) < 2)
                    continue;
                $infoParts = explode(' ', $parts[1]);
                if (isset($infoParts[1])) {
                    $lspci[$infoParts[1]] = ['type' => $infoParts[0], 'pciid' => $parts[0]];
                }
            }
        }

        // Query all running VMs for VGA devices
        $vmpcilist = [];
        $doms = explode("\n", (string)shell_exec('virsh list --name'));
        foreach ($doms as $name) {
            if (empty(trim($name)))
                continue;
            $output = explode("\n", (string)shell_exec('virsh qemu-monitor-command "' . $name . '" --hmp info pci | grep VGA'));
            foreach ($output as $string) {
                if (preg_match('/PCI device (\S+)/', $string, $matches)) {
                    $pciDeviceID = $matches[1];
                    if ($pciDeviceID === '1b36:0100')
                        continue; // Skip QEMU VGA
                    if (isset($lspci[$pciDeviceID]['pciid'])) {
                        $vmpcilist[$lspci[$pciDeviceID]['pciid']] = $name;
                    }
                }
            }
        }

        if (!array_key_exists($pciid, $vmpcilist)) {
            return false;
        }

        // Resolve VM icon
        global $docroot;
        $vmName = $vmpcilist[$pciid];
        $strIcon = '/plugins/dynamix.vm.manager/templates/images/default.png';
        $strIconGet = (string)shell_exec("virsh dumpxml '" . $vmName . "' --xpath \"//domain/metadata/*[local-name()='vmtemplate']/@icon\"");
        if (preg_match('/icon="([^"]+)"/', $strIconGet, $matches)) {
            $iconVal = $matches[1];
            if (is_file($iconVal)) {
                $strIcon = $iconVal;
            }
            elseif (is_file("$docroot/plugins/dynamix.vm.manager/templates/images/$iconVal")) {
                $strIcon = "/plugins/dynamix.vm.manager/templates/images/$iconVal";
            }
            elseif (is_file("$docroot/boot/config/plugins/dynamix.vm.manager/templates/images/$iconVal")) {
                $strIcon = "/boot/config/plugins/dynamix.vm.manager/templates/images/$iconVal";
            }
        }

        return $vmName . ',' . $strIcon;
    }

    /**
     * Retrieves the control group for a process (used to detect Docker containers)
     * @param int $pid
     * @return string
     */
    protected function getControlGroup(int $pid): string
    {
        $file = sprintf('/proc/%d/cgroup', $pid);
        if (file_exists($file) && is_readable($file)) {
            return trim((string)@file_get_contents($file), "\0");
        }
        return '';
    }

    /**
     * Retrieves Docker container info (name, title, icon) via docker inspect
     * @param string $id Container ID hash
     * @return array
     */
    protected function getDockerContainerInspect(string $id): array
    {
        $this->runCommand(self::DOCKER_INSPECT, $id);
        $json = json_decode($this->stdout);
        if (!$json || !isset($json[0]->Config->Labels)) {
            return [];
        }

        $docker_name = preg_replace('/^\//', '', $json[0]->Name);
        return [
            'name' => $docker_name,
            'title' => $json[0]->Config->Labels->{ "org.opencontainers.image.title"} ?? $docker_name,
            'icon' => $this->getDockerContainerIcon($docker_name),
        ];
    }

    /**
     * Looks up Docker container icon from Unraid's docker.json
     * @param string $name
     * @return string
     */
    protected function getDockerContainerIcon(string $name): string
    {
        if (!file_exists(self::DOCKER_ICON_PATH)) {
            return self::DOCKER_ICON_DEFAULT_PATH;
        }
        $json = json_decode(file_get_contents(self::DOCKER_ICON_PATH));
        return isset($json->$name->icon) ? $json->$name->icon : self::DOCKER_ICON_DEFAULT_PATH;
    }

    /**
     * Detects application from process info — checks Docker containers first,
     * then falls back to host app icon matching.
     * Used for dynamic session display.
     * @param array $process ['pid' => ..., 'name' => ..., 'memory' => ...]
     */
    protected function detectApplicationDynamic(array $process): void
    {
        $dockerInfo = null;
        $controlGroup = $this->getControlGroup((int)$process['pid']);
        $usedMemory = (int)self::stripText(' MiB', $process['memory'] ?? '');

        // Collect extra per-process stats for tooltip (nvtop fields)
        $extras = [];
        foreach (['gpu_usage', 'mem_usage', 'enc_dec', 'encode', 'decode', 'kind', 'user'] as $k) {
            if (isset($process[$k]) && $process[$k] !== null) {
                $extras[$k] = $process[$k];
            }
        }

        // Try Docker container identification first
        if ($controlGroup && preg_match('/docker\/([a-z0-9]+)$/', $controlGroup, $matches)) {
            $dockerInfo = $this->getDockerContainerInspect($matches[1]);
        }

        // Load host apps (built-in + user-defined)
        $hostapps = self::HOST_APPS;
        $hostappsfile = '/boot/config/plugins/gpustat/hostapps.json';
        if (file_exists($hostappsfile)) {
            $jsonData = json_decode(file_get_contents($hostappsfile), true);
            if (is_array($jsonData)) {
                $hostapps = array_merge_recursive($hostapps, $jsonData);
            }
        }

        if ($dockerInfo) {
            $active_app = [
                'name' => $dockerInfo['name'],
                'title' => $dockerInfo['title'],
                'icon' => $dockerInfo['icon'],
                'mem' => $usedMemory,
                'count' => 1,
            ] + $extras;
        }
        else {
            $processName = strtolower($process['name']);
            $icon = $hostapps[$processName] ?? self::DOCKER_ICON_DEFAULT_PATH;
            $active_app = [
                'name' => (string)$process['name'],
                'title' => (string)$process['name'],
                'icon' => $icon,
                'mem' => $usedMemory,
                'count' => 1,
            ] + $extras;
        }

        // Merge into active_apps (aggregate if same name already tracked)
        if (!isset($this->pageData['active_apps'])) {
            $this->pageData['active_apps'] = [];
        }
        $index = array_search($active_app['name'], array_column($this->pageData['active_apps'], 'name'));
        if ($index === false) {
            $this->pageData['active_apps'][] = $active_app;
        }
        else {
            $this->pageData['active_apps'][$index]['mem'] += $usedMemory;
            $this->pageData['active_apps'][$index]['count']++;
        }
    }

    // ─── sysfs / hwmon Helpers ───────────────────────────────────────────────

    /**
     * Reads a sysfs file or returns a default value
     * @param string $path
     * @param string $default
     * @return string
     */
    protected function getSysfsValue(string $path, string $default = 'N/A'): string
    {
        return file_exists($path) ? trim(file_get_contents($path)) : $default;
    }

    /**
     * Finds the hwmon sysfs path for a given PCI device
     * @param string $pciId Full PCI ID (e.g. "0000:0c:00.0")
     * @return string|null
     */
    protected function findHwmonPath(string $pciId): ?string
    {
        foreach (glob('/sys/class/hwmon/hwmon*') as $hwmon) {
            if (file_exists("$hwmon/device")) {
                $realPath = realpath("$hwmon/device");
                if ($realPath !== false && strpos($realPath, $pciId) !== false) {
                    return $hwmon;
                }
            }
        }
        return null;
    }

    /**
     * Gets the kernel driver for a PCI device via udevadm
     * @param string $pciid Full PCI ID (e.g. "0000:0c:00.0")
     * @return string Driver name (e.g. "amdgpu", "nvidia", "vfio-pci")
     */
    protected function getKernelDriver(string $pciid): string
    {
        $output = shell_exec("udevadm info --query=property --path=/sys/bus/pci/devices/$pciid | grep 'DRIVER='");
        return $output ? trim(str_replace('DRIVER=', '', $output)) : '';
    }

    /**
     * Gets the GPU product name via udevadm
     * @param string $pciid Full PCI ID
     * @return string
     */
    protected function getGpuNameFromUdev(string $pciid): string
    {
        $input = (string)shell_exec("udevadm info query -p /sys/bus/pci/devices/$pciid | grep ID_MODEL");
        if (preg_match('/^E:\s*([^=]+)=(.*?)\s*\[(.*?)\]\s*$/', $input, $matches)) {
            return trim($matches[3]);
        }
        elseif (preg_match('/^E:\s*([^=]+)=(.*?)$/', $input, $matches)) {
            return trim($matches[2]);
        }
        return 'Unknown';
    }

    /**
     * Reads PCIe gen/width from sysfs (simple alternative to lspci parsing)
     * @param string $pciid Full PCI ID
     */
    protected function getPCIeBandwidthFromSysfs(string $pciid): void
    {
        $sysfs = "/sys/bus/pci/devices/$pciid";
        if (!file_exists("$sysfs/max_link_speed") || !file_exists("$sysfs/max_link_width")) {
            return;
        }

        $maxSpeed = trim(file_get_contents("$sysfs/max_link_speed"));
        $this->pageData['pciegenmax'] = $this->parsePCIEgen($maxSpeed);
        $this->pageData['pciewidthmax'] = 'x' . trim(file_get_contents("$sysfs/max_link_width"));

        if (file_exists("$sysfs/current_link_speed")) {
            $curSpeed = trim(file_get_contents("$sysfs/current_link_speed"));
            $this->pageData['pciegen'] = $this->parsePCIEgen($curSpeed);
        }
        if (file_exists("$sysfs/current_link_width")) {
            $this->pageData['pciewidth'] = 'x' . trim(file_get_contents("$sysfs/current_link_width"));
        }

        // Detect integrated GPU (bus 00:xx.x)
        $this->pageData['igpu'] = (strpos($pciid, '0000:00:') === 0) ? '1' : '0';
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