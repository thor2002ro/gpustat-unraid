const gpustat_status = (_args) => {
    $.getJSON('/plugins/gpustat/gpustatus.php?gpus=' + JSON.stringify(_args), (data2) => {
        if (data2) {
            $.each(data2, function (key2, data) {
                panel = data["panel"];
                if (data["appssupp"]) {
                    data["appssupp"].forEach(function (app) {
                        if (data["processes"][app + "using"]) {
                            $('.gpu' + panel + '-img-span-' + app).css('display', "table-cell");
                            $('#gpu' + panel + "-" + app).attr('title', "Count: " + data["processes"][app + "count"] + "\n" + "Memory: " + data["processes"][app + "mem"] + "MB");
                        } else {
                            $('.gpu' + panel + '-img-span-' + app).css('display', "none");
                            $('#gpu' + panel + "-" + app).attr('title', "");
                        }
                    });
                }
                $.each(data, function (key, value) {
                    var dataV = parseInt(data[key]);
                    if ((key === "clock") || (key === "memclock") || (key === 'power')) {
                        dataV = parseFloat(data[key]);
                        var dataVmax = parseFloat(data[key + "max"]) || NaN;
                    } else {
                        var dataVmax = parseInt(data[key + "max"]) || NaN;
                    }
                    if ((key === "clock") && !isNaN(data["voltage"])) {
                        var extraV = ' @ ' + parseFloat(data["voltage"]) + data["voltageunit"];
                    } else if ((key === "memused") && data["vendor"].toLowerCase() === 'amd') {
                        var extraV = "\n" + "GTT - " + parseFloat(data["gttusedutil"]) + "% - " + parseFloat(data["gttused"]) + " / " + parseInt(data["gttusedmax"]) + data["gttusedunit"];
                    } else {
                        var extraV = "";
                    }
                    var unitV = data[key + "unit"] || "";
                    if (!$('.gpu' + panel + '-' + key).parent().hasClass('gpu-stats-primary') && !isNaN(dataV) && !isNaN(dataVmax)) {
                        var _value = data[key + 'util'] || parseFloat(dataV / dataVmax * 100).toFixed(2) + "%";
                        $('.gpu' + panel + '-' + key + 'bar').removeAttr('style').css('width', _value);
                        $('.gpu' + panel + '-' + key).parent().attr('title', (_value + ' - ' + value + ' / ' + dataVmax + ' ' + unitV + extraV));
                    } else if (!($('.gpu' + panel + '-' + key).parent().hasClass('gpu-stats-primary'))) {
                        $('.gpu' + panel + '-' + key + 'bar').removeAttr('style').css('width', value);
                        $('.gpu' + panel + '-' + key).parent().attr('title', (value + ' ' + unitV + extraV));
                    } else {
                        $('.gpu' + panel + '-' + key + 'bar').removeAttr('style').css('width', value);
                    }
                    if ($('.gpu' + panel + '-' + key).parents().is('#gpu-labels')) {
                        if (key === "throttled") {
                            $('.gpu' + panel + '-' + key).html(value + data["thrtlrsn"]);
                            $('.gpu' + panel + '-' + key).parent().attr('title', (keyMap[key] + ": " + value + "\n" + keyMap["thrtlrsn"] + ": " + data["thrtlrsn"]));
                        } else {
                            $('.gpu' + panel + '-' + key).html(value + " " + unitV);
                        }
                    } else {
                        $('.gpu' + panel + '-' + key).html(value);
                    }
                });
                let pcieSpeedDownVisible = parseInt(data["pciegen"]) >= parseInt(data["pciegenmax"]) ? 0 : 1;
                let pcieWidthDownVisible = parseInt(data["pciewidth"]) >= parseInt(data["pciewidthmax"]) ? 0 : 1;
                change_visibility('#gpu' + panel + '-' + 'pciegen-arrow', pcieSpeedDownVisible);
                change_visibility('#gpu' + panel + '-' + 'pciewidth-arrow', pcieWidthDownVisible);

                change_color('.gpu' + panel + '-' + 'util', data["util"], 80, 'red');
                change_color('.gpu' + panel + '-' + 'temp', data["temp"], data["tempmax"] - 15, 'red');
                change_color('#gpu' + panel + '-' + 'pcie', data["bridge_bus"], 0, 'brown');
                change_tooltip($('#gpu' + panel + '-' + 'pcie').parent(), data["bridge_bus"], 0, 'PCIe Gen(Bridge Chip bus:' + data["bridge_bus"] + ')');
                change_color_string('.gpu' + panel + '-' + 'passedthrough', data["passedthrough"], "Passthrough");
            });
        }
    });
};

var keyMap = {
    //common
    //"util": "Load",
    //"temp": "Temperature",
    "clock": "Clock",                  // gpu clock
    "fan": "Fan Speed",                // current fan speed
    "fanmax": "Fan Speed Max",         // max fan speed
    "memclock": "Mem Clock",           // memory clock
    "memused": "Mem Usage",            // used vram
    "power": "TDP",                    // current tdp/power
    "voltage": "Voltage",              // current gpu voltage
    'pciegen': " ",                     // current pcie gen
    'pciegenmax': " ",                  // max pcie gen
    'pciewidth': " ",                   // current pcie width
    'pciewidthmax': " ",                // max pcie width
    //amd
    "event": "Event",                  // Event Engine
    "vertex": "Vertex",                // Vertex Grouper + Tesselator
    "texture": "Texture",              // Texture Addresser
    "shaderexp": "Shader Exp",         // Shader Export
    "sequencer": "Sequencer",          // Sequencer Instruction Cache
    "shaderinter": "Shader Inter",     // Shader Interpolator
    "scancon": "Scan Conv",            // Scan Converter
    "primassem": "Prim Assem",         // Primitive Assembl
    "depthblk": "Depth Blk",           // Depth Block
    "colorblk": "Color Blk",           // Color Block
    "gttused": "GTT Mem",              // used GTT
    //nvidia
    "sm_clock": "Shader Clock",         //?????
    "video_clock": " Video Clock",
    'encutil': "Encoder Util",
    'decutil': "Decoder Util",
    'perfstate': "Power State",
    'throttled': "Throttling",
    'thrtlrsn': "Throttling Reason",                    // reason for throttling
    'sessions': " ",                    // GPU Sessions
    'processes': " ",                    // GPU Sessions
    //intel
    '3drender': "3D Render",
    'blitter': "Blitter",
    'interrupts': "Interrupts/Sec",
    'powerutil': "Power Draw",
    'video': "Video",
    'videnh': "Video Enhance",
    "rxutil": "Bus Rx Util",           // used by nvidia also
    "txutil": "Bus TX Util",           // used by nvidia also
};

var keyOrder = [
    //common
    "clock", "memclock",
    //intel
    "3drender", "blitter",
    "video", "videnh",
    "interrupts",
    //nvidia
    "sm_clock", "video_clock",
    //
    "fan", "power",
    //amd
    "gttused", "memused",
    "event", "vertex",
    "texture", "sequencer",
    "shaderexp", "shaderinter",
    "scancon", "primassem",
    "depthblk", "colorblk",
    //nvidia
    "encutil", "decutil",
    "rxutil", "txutil",
    "perfstate", "throttled",
    "thrtlrsn", "sessions",
];

var excludedKeys = [
    "max", "unit", "pcie", "driver", "bridge_bus",
    "passedthrough", "vendor", "name", "temp", "util",
    "appssupp", "processes", "uuid", "sessions", "thrtlrsn", "panel"
];

var additionalKeys = ["rxutil", "txutil", "encutil", "decutil"];

const gpustat_dash_build = (_args) => {   
    const target = "tblGPUDash";

    $.getJSON('/plugins/gpustat/gpustatus.php?gpus=' + JSON.stringify(_args), (data2) => {
        if (data2) {
            $.each(data2, function (key2, data) {
                let panel = data["panel"];
                let fragment = document.createDocumentFragment();

                let gpu_data = new Set();
                let gpu_data_nobars = new Set();

                $.each(data, function (key, value) {
                    let isExcludedKey = excludedKeys.some(item => key.includes(item));
                    let isAdditionalKey = additionalKeys.some(item => key === item);
                    let isValidValue = value !== null && !value.toString().includes("N/A");

                    if (isValidValue && (!isExcludedKey || isAdditionalKey)) {
                        gpu_data.add(key);
                        if (!(($(data[key + 'max']).length > 0) || (value.toString().includes('%')))) {
                            gpu_data_nobars.add(key);
                        }
                    }
                });
                
                // Convert sets to arrays for further processing if needed
                let gpu_data_array = Array.from(gpu_data);
                let gpu_data_nobars_array = Array.from(gpu_data_nobars);
 
                // Find missing and disabled keys                 
                let disabled_array = keyOrder.filter(item => !gpu_data_array.includes(item));
                let missing_array = gpu_data_array.filter(item => !keyOrder.includes(item));
                let gpu_data_filtered = keyOrder.concat(missing_array).filter(item => !disabled_array.includes(item))
                                                                      .filter(item => !gpu_data_nobars_array.includes(item));

                let templateContent_bars = $('#message-template-bars').html();
                for (let i = 0; i < gpu_data_filtered.length; i += 2) {
                    let [key1, key2] = gpu_data_filtered.slice(i, i + 2);

                    let $clone = $(templateContent_bars).removeAttr('id');
                    $clone.html(
                        $clone.html()
                            .replaceAll("{{gpuNR}}", panel)
                            .replaceAll("{{label1}}", keyMap[key1] || key1)
                            .replaceAll("{{label2}}", keyMap[key2] || key2)
                            .replaceAll("{{stat1}}", key1)
                            .replaceAll("{{stat2}}", key2)
                    );

                    if (key2) {
                        $clone.find(".hidden").removeClass("hidden");
                    }

                    fragment.appendChild($clone[0]);
                }

                let templateContent_simple = $('#message-template-simple').html();
                for (let i = 0; i < gpu_data_nobars_array.length; i += 3) {
                    let [key1, key2, key3] = gpu_data_nobars_array.slice(i, i + 3);

                    let $clone = $(templateContent_simple).removeAttr('id');
                    $clone.html(
                        $clone.html()
                            .replaceAll("{{gpuNR}}", panel)
                            .replaceAll("{{label1}}", keyMap[key1] || key1)
                            .replaceAll("{{label2}}", keyMap[key2] || key2)
                            .replaceAll("{{label3}}", keyMap[key3] || key3)
                            .replaceAll("{{stat1}}", key1)
                            .replaceAll("{{stat2}}", key2)
                            .replaceAll("{{stat3}}", key3)
                    );

                    if (key2) {
                        $clone.find(".hidden").eq(0).removeClass("hidden");
                    }

                    if (key3) {
                        $clone.find(".hidden").eq(0).removeClass("hidden");
                    }

                    fragment.appendChild($clone[0]);
                }

                let templateContent_sessions = $('#message-template-sessions').html();

                if (data["vendor"].toLowerCase() === "nvidia") {
                    let $clone = $(templateContent_sessions).removeAttr('id');
                    $clone.html(
                        $clone.html()
                            .replaceAll("{{gpuNR}}", panel)
                    );
                    fragment.appendChild($clone[0]);
                }

                $("#" + target + panel).append(fragment);
            });
        }
    });
};


function change_visibility(key, value) {
    $(key).toggleClass('hidden', parseInt(value) === 0);
}

function change_color(key, value, redvalue, color) {
    $(key).css({ 'color': parseInt(value) >= parseInt(redvalue) ? color : '' });
}

function change_tooltip(key, value, redvalue, title) {
    $(key).attr('title', parseInt(value) >= parseInt(redvalue) ? title : null);
}

function change_color_string(key, value, redvalue) {
    $(key).css({ 'color': value === redvalue ? 'magenta' : 'green' });
}
