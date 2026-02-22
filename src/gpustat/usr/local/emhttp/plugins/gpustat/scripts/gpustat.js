/*
 * gpustat.js — Dashboard client for GPU Statistics plugin.
 * Communicates with gpustatus.php via AJAX.
 *
 * Flow: gpustatus.page loads config → passes _args to JS →
 *       gpustat_dash_build() builds UI once → gpustat_status() polls for updates.
 *
 * Response '_debug' flag (from UIDEBUG config) gates console.log output.
 */

// Metric key → display label mapping (space = hidden label)
const KEY_MAP = {
    // Common
    "clock": "Clock", "fan": "Fan Speed", "fanmax": "Fan Speed Max",
    "memclock": "Mem Clock", "memused": "Mem Usage", "power": "TDP",
    "voltage": "Voltage", 'pciegen': " ", 'pciegenmax': " ",
    'pciewidth': " ", 'pciewidthmax': " ",
    // AMD
    "event": "Event", "vertex": "Vertex", "texture": "Texture",
    "shaderexp": "Shader Exp", "sequencer": "Sequencer", "shaderinter": "Shader Inter",
    "scancon": "Scan Conv", "primassem": "Prim Assem", "depthblk": "Depth Blk",
    "colorblk": "Color Blk", "uvd": "UVD", "vce0": "VCE0", "gttused": "GTT Mem",
    // Nvidia
    "sm_clock": "Shader Clock", "video_clock": " Video Clock",
    'encutil': "Encoder Util", 'decutil': "Decoder Util",
    'perfstate': "Power State", 'throttled': "Throttling",
    'thrtlrsn': "Throttling Reason", 'sessions': " ", 'processes': " ",
    // Intel
    '3drender': "3D Render", 'blitter': "Blitter", 'interrupts': "Interrupts/Sec",
    'powerutil': "Power Draw", 'video': "Video", 'videnh': "Video Enhance",
    "rxutil": "Bus Rx Util", "txutil": "Bus TX Util",
};

// Display order for metric bars; extras appended at end
const KEY_ORDER = [
    "clock", "memclock", "3drender", "blitter", "video", "videnh", "interrupts",
    "sm_clock", "video_clock", "fan", "power", "gttused", "memused", "event",
    "vertex", "texture", "sequencer", "shaderexp", "shaderinter", "scancon",
    "primassem", "depthblk", "colorblk", "uvd", "vce0", "encutil", "decutil",
    "rxutil", "txutil", "perfstate", "throttled", "thrtlrsn", "sessions",
];

// Substring matches to exclude from bar/label rendering (metadata keys)
const EXCLUDED_KEYS = [
    "max", "unit", "pcie", "driver", "bridge_bus", "passedthrough",
    "vendor", "name", "temp", "util", "appssupp", "processes",
    "uuid", "sessions", "thrtlrsn", "panel", "stats"
];

// Exceptions to EXCLUDED_KEYS (contain excluded substrings but should display)
const ADDITIONAL_KEYS = ["rxutil", "txutil", "encutil", "decutil"];

// ─── Polling update — refreshes existing DOM elements with live data ─────────
const gpustat_status = (_args) => {
    $.getJSON('/plugins/gpustat/gpustatus.php?gpus=' + JSON.stringify(_args), (data2) => {
        if (!data2) return;
        const _dbg = data2._debug || 0;
        if (_dbg) console.log('[gpustat_status] Input args:', JSON.stringify(_args, null, 2));
        if (_dbg) console.log('[gpustat_status] Output data:', JSON.stringify(data2, null, 2));

        $.each(data2, (key2, data) => {
            if (key2 === '_debug') return;
            const panel = data["panel"]; // 1-based panel number for DOM targeting

            // 1. App icons (Nvidia: show/hide based on active GPU processes)
            if (data["appssupp"] && Array.isArray(data["appssupp"])) {
                data["appssupp"].forEach((app) => {
                    const $imgSpan = $(`.gpu${panel}-img-span-${app}`);
                    const $statusIcon = $(`#gpu${panel}-${app}`);

                    if (data["processes"] && data["processes"][app + "using"]) {
                        $imgSpan.css('display', "table-cell");
                        $statusIcon.attr('title', `Count: ${data["processes"][app + "count"]}\nMemory: ${data["processes"][app + "mem"]}MB`);
                    } else {
                        $imgSpan.css('display', "none");
                        $statusIcon.attr('title', "");
                    }
                });
            }

            // 2. Update metric values, progress bars, and tooltips
            $.each(data, (key, value) => {
                const { val, max, extra } = parseGpuValue(key, value, data);
                const unit = data[`${key}unit`] || "";
                const $el = $(`.gpu${panel}-${key}`);
                const $bar = $(`.gpu${panel}-${key}bar`);
                const $parent = $el.parent();

                if ($el.length === 0) return;

                const isPrimary = $parent.hasClass('gpu-stats-primary');

                if (!isPrimary && !isNaN(val) && !isNaN(max) && max !== 0) {
                    // Has value + max → percentage bar with tooltip
                    const utilPercent = data[`${key}util`] || (val / max * 100).toFixed(2) + "%";
                    $bar.css('width', utilPercent);
                    $parent.attr('title', `${utilPercent} - ${value} / ${max} ${unit}${extra}`);
                } else if (!isPrimary) {
                    $bar.css('width', value);
                    $parent.attr('title', `${value} ${unit}${extra}`);
                } else {
                    $bar.css('width', value);
                }

                // Text labels (inside #gpu-labels rows)
                if ($el.parents('#gpu-labels').length) {
                    if (key === "throttled") {
                        $el.html(`${value}${data["thrtlrsn"]}`);
                        $parent.attr('title', `${KEY_MAP[key]}: ${value}\n${KEY_MAP["thrtlrsn"]}: ${data["thrtlrsn"]}`);
                    } else {
                        $el.html(`${value} ${unit}`);
                    }
                } else {
                    $el.html(value);
                }
            });

            // 3. PCIe arrows — red down-arrow when gen/width below max
            const pcieGen = parseInt(data["pciegen"], 10);
            const pcieGenMax = parseInt(data["pciegenmax"], 10);
            const pcieWidth = parseInt((data["pciewidth"] || "").toString().replace(/^x/i, ''), 10);
            const pcieWidthMax = parseInt((data["pciewidthmax"] || "").toString().replace(/^x/i, ''), 10);

            const pcieSpeedDown = (!isNaN(pcieGen) && !isNaN(pcieGenMax) && pcieGen < pcieGenMax) ? 1 : 0;
            const pcieWidthDown = (!isNaN(pcieWidth) && !isNaN(pcieWidthMax) && pcieWidth < pcieWidthMax) ? 1 : 0;

            toggleVisibility(`#gpu${panel}-pciegen-arrow`, pcieSpeedDown);
            toggleVisibility(`#gpu${panel}-pciewidth-arrow`, pcieWidthDown);

            // 4. Color warnings & tooltips
            updateColor(`.gpu${panel}-util`, data["util"], 80, 'red');                  // red >= 80%
            updateColor(`.gpu${panel}-temp`, data["temp"], (data["tempmax"] - 15), 'red'); // red near max
            $(`.gpu${panel}-util-wrap`).attr('title', `GPU Utilization: ${data["util"]}`);
            $(`.gpu${panel}-temp-wrap`).attr('title', `Max: ${data["tempmax"]} °${data["tempunit"]}`);

            // 5. PCIe bridge chip — brown color + tooltip when behind a bridge
            const bridgeBus = data["bridge_bus"];
            const hasBridge = bridgeBus !== null && bridgeBus !== undefined && bridgeBus !== '';
            const $pcieEl = $(`.gpu${panel}-pciegen`).parent();
            $pcieEl.css('color', hasBridge ? 'brown' : '');
            let pcieTitle = `PCIe Gen ${data["pciegen"]}/${data["pciegenmax"]} - Width ${data["pciewidth"]}/${data["pciewidthmax"]}`;
            if (hasBridge) pcieTitle += `\nBridge Chip bus: ${bridgeBus}`;
            $pcieEl.attr('title', pcieTitle);

            // 6. Passthrough status — magenta if VM passthrough, green if normal
            updateColorString(`.gpu${panel}-passedthrough`, data["passedthrough"], "Passthrough");
        });
    }).fail((jqXHR, status, err) => {
        console.error('[gpustat_status] AJAX error:', status, err);
    });
};

// ─── Initial build — creates dashboard rows from HTML templates (once) ───────
const gpustat_dash_build = (_args) => {
    const target = "tblGPUDash";
    $.getJSON('/plugins/gpustat/gpustatus.php?gpus=' + JSON.stringify(_args), (data2) => {
        if (!data2) return;
        const _dbg = data2._debug || 0;
        if (_dbg) console.log('[gpustat_dash_build] Input args:', JSON.stringify(_args, null, 2));
        if (_dbg) console.log('[gpustat_dash_build] Output data:', JSON.stringify(data2, null, 2));

        $.each(data2, (key2, data) => {
            if (key2 === '_debug') return;
            const panel = data["panel"];
            const stats = data["stats"]; // Display toggles (DISPCLOCKS, DISPFAN, etc.)
            const fragment = document.createDocumentFragment();

            // Classify keys: bar-capable vs text-only
            const gpuData = new Set();
            const gpuDataNoBars = new Set();

            $.each(data, (key, value) => {
                const isExcluded = EXCLUDED_KEYS.some(item => key.includes(item));
                const isAdditional = ADDITIONAL_KEYS.includes(key);
                const isValid = value !== null && !value.toString().includes("N/A");

                if (isValid && (!isExcluded || isAdditional)) {
                    gpuData.add(key);
                    // No max value and not a percentage → text-only (no bar)
                    if (data[`${key}max`] == null && !value.toString().includes('%')) {
                        gpuDataNoBars.add(key);
                    }
                }
            });

            const validKeys = Array.from(gpuData);
            const noBarKeys = Array.from(gpuDataNoBars);

            // Disabled = not valid + explicitly turned off in stats config
            const disabledKeys = [
                ...KEY_ORDER.filter(item => !validKeys.includes(item)),
                ...Object.keys(stats).filter(k => stats[k] == 0).map(k => k.replace(/^DISP/, '').toLowerCase())
            ];

            // Final render list: KEY_ORDER + extras, minus disabled/no-bar
            const missingKeys = validKeys.filter(item => !KEY_ORDER.includes(item));
            const renderList = KEY_ORDER.concat(missingKeys)
                .filter(item => !disabledKeys.includes(item))
                .filter(item => !noBarKeys.includes(item));

            // Bar rows (paired, 2 per row)
            const tmplBars = $('#message-template-bars').html();
            for (let i = 0; i < renderList.length; i += 2) {
                const [k1, k2] = [renderList[i], renderList[i + 1]];
                let html = tmplBars
                    .replaceAll("{{gpuNR}}", panel)
                    .replaceAll("{{label1}}", KEY_MAP[k1] || k1)
                    .replaceAll("{{stat1}}", k1);

                if (k2) {
                    html = html.replaceAll("{{label2}}", KEY_MAP[k2] || k2).replaceAll("{{stat2}}", k2);
                } else {
                    html = html.replaceAll("{{label2}}", "").replaceAll("{{stat2}}", "");
                }

                const $clone = $(html).removeAttr('id');
                if (k2) $clone.find(".hidden").removeClass("hidden");
                fragment.appendChild($clone[0]);
            }

            // Text-only rows (triplets, 3 per row)
            const tmplSimple = $('#message-template-simple').html();
            for (let i = 0; i < noBarKeys.length; i += 3) {
                const keys = noBarKeys.slice(i, i + 3);
                let html = tmplSimple.replaceAll("{{gpuNR}}", panel);

                keys.forEach((k, idx) => {
                    html = html.replaceAll(`{{label${idx + 1}}}`, KEY_MAP[k] || k)
                        .replaceAll(`{{stat${idx + 1}}}`, k);
                });

                const $clone = $(html).removeAttr('id');
                if (keys[1]) $clone.find(".hidden").eq(0).removeClass("hidden");
                if (keys[2]) $clone.find(".hidden").eq(0).removeClass("hidden");
                fragment.appendChild($clone[0]);
            }

            // Sessions row (Nvidia only)
            if (data["vendor"] && data["vendor"].toLowerCase() === "nvidia") {
                const tmplSessions = $('#message-template-sessions').html();
                const $clone = $(tmplSessions.replaceAll("{{gpuNR}}", panel)).removeAttr('id');
                fragment.appendChild($clone[0]);
            }

            $(`#${target}${panel}`).append(fragment);
        });
    }).fail((jqXHR, status, err) => {
        console.error('[gpustat_dash_build] AJAX error:', status, err);
    });
};

/* ─── Helpers ─────────────────────────────────────────────────────────────── */

// Parse metric value, max, and extra tooltip text (voltage, GTT info)
function parseGpuValue(key, value, data) {
    let val = parseInt(value);
    let max = parseInt(data[`${key}max`]) || NaN;
    let extra = "";

    if (['clock', 'memclock', 'power'].includes(key)) {
        val = parseFloat(value);
        max = parseFloat(data[`${key}max`]) || NaN;
    }

    if (key === "clock" && !isNaN(data["voltage"])) {
        extra = ` @ ${parseFloat(data["voltage"])}${data["voltageunit"]}`;
    } else if (key === "memused" && data["vendor"].toLowerCase() === 'amd') {
        extra = `\nGTT - ${parseFloat(data["gttusedutil"])}% - ${parseFloat(data["gttused"])} / ${parseInt(data["gttusedmax"])}${data["gttusedunit"]}`;
    }

    return { val, max, extra };
}

// Toggle element visibility via 'hidden' class
function toggleVisibility(selector, isVisible) {
    $(selector).toggleClass('hidden', !isVisible);
}

// Set text color when value >= threshold
function updateColor(selector, value, threshold, color) {
    $(selector).css({ 'color': parseInt(value) >= parseInt(threshold) ? color : '' });
}

// Set tooltip when value >= threshold
function updateTooltip(selector, value, threshold, title) {
    $(selector).attr('title', parseInt(value) >= parseInt(threshold) ? title : null);
}

// Set text color on exact string match (passthrough status)
function updateColorString(selector, value, matchValue) {
    $(selector).css({ 'color': value === matchValue ? 'magenta' : 'green' });
}