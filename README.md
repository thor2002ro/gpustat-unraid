# gpustat-unraid
An UnRAID plugin for displaying GPU status
![Screenshot](src/gpustat/usr/local/emhttp/plugins/gpustat/sample/Screenshot.png?raw=true)


## Prerequisites:
#### NVIDIA:
- UnRAID:
  * with nvidia drivers

#### INTEL:
- UnRAID (All Versions)
  * Intel GPU TOP

#### AMD:
- UnRAID (6.9+)
  * RadeonTop

Note: From an UnRAID console if `nvidia-smi` (NVIDIA), `intel_gpu_top` (Intel) or `radeontop` (AMD) cannot be found or run for any reason,
the plugin will fail for that vendor. If none of these commands exists, the plugin install will fail.

## Manual Installation
    - download https://raw.githubusercontent.com/thor2002ro/gpustat-unraid/main/gpustat.plg and install

## Current Support

#### NVIDIA:
    - GPU/Memory Utilization
    - GPU/Memory Clocks
    - Encoder/Decoder Utilization
    - PCI Bus Utilization
    - Temperature
    - Fan Utilization
    - Power Draw
    - Power State
    - Throttled (Y/N) and Reason for Throttle
    - Active Process Count

#### INTEL:
    - 3D Render Engine Utilization
    - Blitter Engine Utilization
    - Video Engine Utilization
    - VideoEnhance Engine Utilization
    - IMC Bandwidth Throughput
    - Power Draw and Power Demand (rc6 slider)
    - GPU Clock
    - Interrupts per Second

#### AMD:
    APU/GPU
    - GPU/Memory Utilization
    - Event Engine Utilization
    - Vertex Grouper and Tesselator Utilization
    - Texture Addresser Utilization
    - Shader Export/Interpolator Utilization
    - Sequencer Instruction Cache Utilization
    - Scan Converter Utilization
    - Primitive Assembly Utilization
    - Depth/Color Block Utilization
    - Graphics Translation Table Utilization
    - Memory/Shader Clocks
    - Temperature

    GPU Only
    - Power Draw
    - Fan Current/Max RPM

