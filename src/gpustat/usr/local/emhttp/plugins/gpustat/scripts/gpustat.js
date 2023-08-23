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
                change_visibility('#gpu' + panel + '-' + 'pciegen-arrow', data["pcie_downspeed"]);
                change_visibility('#gpu' + panel + '-' + 'pciewidth-arrow', data["pcie_downwidth"]);
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

const gpustat_dash_build = (_args) => {
   
    let target = "tblGPUDash";

    $.getJSON('/plugins/gpustat/gpustatus.php?gpus=' + JSON.stringify(_args), (data2) => {
        if (data2) {
            $.each(data2, function (key2, data) {
                panel = data["panel"];
                 let gpu_data = [];
                 let gpu_data_nobars = [];
                 let gpu_data_bars = [];
                 let disabled_array = [];
                 let missing_array = [];
                $.each(data, function (key, value) {
                    if (value !== null && (
                        !(
                            value.toString().includes("N/A") ||
                            key.includes("max") ||
                            key.includes("unit") ||
                            key.includes("pcie") ||
                            key.includes("driver") ||
                            key.includes("bridge_bus") ||
                            key.includes("passedthrough") ||
                            key.includes("vendor") ||
                            key.includes("name") ||
                            key.includes("temp") ||
                            key.includes("util") ||
                            key.includes("appssupp") ||
                            key.includes("processes") ||
                            key.includes("uuid") ||
                            key.includes("sessions") ||
                            key.includes("thrtlrsn") ||
                            key.includes("panel")
                        ) ||
                        ((key === "rxutil" ||
                            key === "txutil" ||
                            key === "encutil" ||
                            key === "decutil") &&
                            !value.toString().includes("N/A"))
                    )) {
                        if (gpu_data.indexOf(key) === -1) gpu_data.push(key);
                        if (!(($(data[key + 'max']).length > 0) || (value.toString().includes('%')))) {
                            if (gpu_data_nobars.indexOf(key) === -1) gpu_data_nobars.push(key);
                        }
                    }
                });
                disabled_array = keyOrder.filter(function (obj) { return gpu_data.indexOf(obj) == -1; });
                missing_array = gpu_data.filter(function (obj) { return keyOrder.indexOf(obj) == -1; });
                gpu_data = keyOrder.concat(missing_array).filter(function (obj) { return disabled_array.indexOf(obj) < 0; });
                gpu_data = gpu_data.filter(function (obj) { return gpu_data_nobars.indexOf(obj) < 0; });

                for (var i = 0; i < gpu_data.length; i += 2) {
                    var $clone = $('#message-template-bars').html();
                    $clone = $clone
                        .replaceAll("{{gpuNR}}", panel)
                        .replaceAll("{{label1}}", keyMap[gpu_data[i]] || gpu_data[i])
                        .replaceAll("{{label2}}", keyMap[gpu_data[i + 1]] || gpu_data[i + 1])
                        .replaceAll("{{stat1}}", gpu_data[i])
                        .replaceAll("{{stat2}}", gpu_data[i + 1]);

                    if (gpu_data[i + 1]) {
                        $clone = $clone.replaceAll("'hidden'", "");
                    }
                    $("#" + target + panel).append($clone);
                }
                for (var i = 0; i < gpu_data_nobars.length; i += 3) {
                    var $clone = $('#message-template-simple').html();
                    $clone = $clone
                        .replaceAll("{{gpuNR}}", panel)
                        .replaceAll("{{label1}}", keyMap[gpu_data_nobars[i]] || gpu_data_nobars[i])
                        .replaceAll("{{label2}}", keyMap[gpu_data_nobars[i + 1]] || gpu_data_nobars[i + 1])
                        .replaceAll("{{label3}}", keyMap[gpu_data_nobars[i + 2]] || gpu_data_nobars[i + 2])
                        .replaceAll("{{stat1}}", gpu_data_nobars[i])
                        .replaceAll("{{stat2}}", gpu_data_nobars[i + 1])
                        .replaceAll("{{stat3}}", gpu_data_nobars[i + 2]);

                    if (gpu_data_nobars[i + 1]) {
                        $clone = $clone.replace("'hidden'", "");
                    }
                    if (gpu_data_nobars[i + 2]) {
                        $clone = $clone.replace("'hidden'", "");
                    }
                    $("#" + target + panel).append($clone);
                }

                if (data["vendor"].toLowerCase() === "nvidia") {
                    var $clone = $('#message-template-sessions').html();
                    $clone = $clone.replaceAll("{{gpuNR}}", panel)
                    $("#" + target + panel).append($clone);
                }
            });
        }
    });
};

function change_visibility(key, value) {
    $(key).removeClass('hidden');
    if (parseInt(value) == 0) {
        $(key).addClass('hidden');
    }
}

function change_color(key, value, redvalue, color) {
    if (parseInt(value) >= parseInt(redvalue)) {
        $(key).css({ 'color': color });
    } else {
        $(key).css({ 'color': '' });
    }
}

function change_tooltip(key, value, redvalue, title) {
    if (parseInt(value) >= parseInt(redvalue)) {
        $(key).attr('title', title);
    }
}

function change_color_string(key, value, redvalue) {
    if (value === redvalue) {
        $(key).css({ 'color': 'magenta' });
    } else {
        $(key).css({ 'color': 'green' });
    }
}

// TODO: Not currently used due to issue with default reset actually working
// function resetDATA(form) {
//     form.VENDOR.value = "nvidia";
//     form.TEMPFORMAT.value = "C";
//     form.GPUBUS.value = "0";
//     form.DISPCLOCKS.value = "1";
//     form.DISPENCDEC.value = "1";
//     form.DISPTEMP.value = "1";
//     form.DISPFAN.value = "1";
//     form.DISPPCIUTIL.value = "1";
//     form.DISPPWRDRAW.value = "1";
//     form.DISPPWRSTATE.value = "1";
//     form.DISPTHROTTLE.value = "1";
//     form.DISPSESSIONS.value = "1";
//     form.UIREFRESH.value = "1";
//     form.UIREFRESHINT.value = "1000";
//     form.DISPMEMUTIL.value = "1";
//     form.DISP3DRENDER.value = "1";
//     form.DISPBLITTER.value = "1";
//     form.DISPVIDEO.value = "1";
//     form.DISPVIDENH.value = "1";
//     form.DISPINTERRUPT.value = "1";
// }
