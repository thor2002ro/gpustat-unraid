
const gpustat_status_gpu1 = () => {
    gpustat_status(1);
};

const gpustat_status_gpu2 = () => {
    gpustat_status(2);
};

const gpustat_status_gpu3 = () => {
    gpustat_status(3);
};

const gpustat_status = function (_args) {
    $.getJSON("/plugins/gpustat/gpustatus.php?argv=" + _args, (data) => {
        if (data) {
            switch (data["vendor"].toLowerCase()) {
                case "nvidia":
                    // Nvidia Slider Bars
                    /*$('.gpu'+_args+'-memclockbar').removeAttr('style').css('width', data["memclock"] / data["memclockmax"] * 100 + "%");
                    $('.gpu'+_args+'-gpuclockbar').removeAttr('style').css('width', data["clock"] / data["clockmax"] * 100 + "%");
                    $('.gpu'+_args+'-powerbar').removeAttr('style').css('width', parseInt(data["power"].replace("W","") / data["powermax"] * 100) + "%");
                    $('.gpu'+_args+'-rxutilbar').removeAttr('style').css('width', parseInt(data["rxutil"] / data["pciemax"] * 100) + "%");
                    $('.gpu'+_args+'-txutilbar').removeAttr('style').css('width', parseInt(data["txutil"] / data["pciemax"] * 100) + "%");

                     let nvidiabars = ['util', 'memutil', 'encutil', 'decutil', 'fan'];
                    nvidiabars.forEach(function (metric) {
                        $('.gpu'+_args+'-'+metric+'bar').removeAttr('style').css('width', data[metric]);
                    }); */

                    if (data["appssupp"]) {
                        data["appssupp"].forEach(function (app) {
                            if (data["processes"][app + "using"]) {
                                $('.gpu' + _args + '-img-span-' + app).css('display', "inline");
                                $('#gpu' + _args + "-" + app).attr('title', "Count: " + data["processes"][app + "count"] + "\n" +"Memory: " + data["processes"][app + "mem"] + "MB");
                            } else {
                                $('.gpu' + _args + '-img-span-' + app).css('display', "none");
                                $('#gpu' + _args + "-" + app).attr('title', "");
                            }
                        });
                    }
                    break;
                case "intel":
                    // Intel Slider Bars
                    /*  let intelbars = ['3drender', 'blitter', 'video', 'videnh', 'powerutil'];
                     intelbars.forEach(function (metric) {
                         $('.gpu'+_args+'-'+metric+'bar').removeAttr('style').css('width', data[metric]);
                     }); */
                    break;
                case "amd":
                    /*  $('.gpu'+_args+'-powerbar').removeAttr('style').css('width', parseInt(data["power"] / data["powermax"] * 100) + "%");
                        $('.gpu'+_args+'-fanbar').removeAttr('style').css('width', parseInt(data["fan"] / data["fanmax"] * 100) + "%");
                         let amdbars = [
                            'util', 'event', 'vertex',
                            'texture', 'shaderexp', 'sequencer',
                            'shaderinter', 'scancon', 'primassem',
                            'depthblk', 'colorblk', 'memutil',
                            'gfxtrans', 'memclockutil', 'clockutil'
                        ];
                        amdbars.forEach(function (metric) {
                            $('.gpu'+_args+'-'+metric+'bar').removeAttr('style').css('width', data[metric]);
                        });      */
                    break;
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

                /* console.log("key " + key);
                console.log("dataV " + dataV);
                console.log("$(dataV).length " + isNaN(dataV));
                console.log("dataVmax " + dataVmax);
                console.log("$(dataVmax).length " + isNaN(dataVmax));
                console.log("extraV " + extraV);
                console.log("unitV " + unitV);
                console.log("\n"); */

                if (!$('.gpu' + _args + '-' + key).parent().hasClass('gpu-stats-primary') && !isNaN(dataV) && !isNaN(dataVmax)) {
                    var _value = data[key + 'util'] || parseFloat(dataV / dataVmax * 100).toFixed(2) + "%";
                    $('.gpu' + _args + '-' + key + 'bar').removeAttr('style').css('width', _value);
                    $('.gpu' + _args + '-' + key).parent().attr('title', (_value + ' - ' + value + ' / ' + dataVmax + ' ' + unitV + extraV));
                } else if (!($('.gpu' + _args + '-' + key).parent().hasClass('gpu-stats-primary'))) {
                    $('.gpu' + _args + '-' + key + 'bar').removeAttr('style').css('width', value);
                    $('.gpu' + _args + '-' + key).parent().attr('title', (value + ' ' + unitV + extraV));
                } else {
                    $('.gpu' + _args + '-' + key + 'bar').removeAttr('style').css('width', value);
                }

                if ($('.gpu' + _args + '-' + key).parents().is('#gpu-labels')) {
                    if (key === "throttled") {
                        $('.gpu' + _args + '-' + key).html(value + data["thrtlrsn"]); //add throttled reason to throttled
                        $('.gpu' + _args + '-' + key).parent().attr('title', (keyMap[key] + ": " +value + "\n" + keyMap["thrtlrsn"] + ": " + data["thrtlrsn"])); //special tooltip for throttled
                    } else {
                        $('.gpu' + _args + '-' + key).html(value + " " + unitV); //add unit to simple labels
                    }
                } else {
                    $('.gpu' + _args + '-' + key).html(value);
                }
            });

            change_visibility('#gpu' + _args + '-' + 'pciegen-arrow', data["pcie_downspeed"]);
            change_visibility('#gpu' + _args + '-' + 'pciewidth-arrow', data["pcie_downwidth"]);
            change_color('.gpu' + _args + '-' + 'util', data["util"], 80, 'red');
            change_color('.gpu' + _args + '-' + 'temp', data["temp"], data["tempmax"] - 15, 'red');
            change_color('#gpu' + _args + '-' + 'pcie', data["bridge_bus"], 0, 'brown');
            change_tooltip($('#gpu' + _args + '-' + 'pcie').parent(), data["bridge_bus"], 0, 'PCIe Gen(Bridge Chip bus:' + data["bridge_bus"] + ')');
            change_color_string('.gpu' + _args + '-' + 'passedthrough', data["passedthrough"], "Passthrough");

        }
    });
};

const gpustat_dash = function (_args) {
    // append data from the table into the correct one
    $("#db-box1").append($(".dash_gpustat" + _args).html());

    // reload toggle to get the correct state
    toggleView("dash_gpustat_toggle" + _args, true);

    // reload sorting to get the stored data (cookie)
    sortTable($("#db-box1"), $.cookie("db-box1"));
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

const gpustat_dash_build = function (_args) {
    let gpu_data = [];
    let gpu_data_nobars = [];
    let gpu_data_bars = [];
    let disabled_array = [];
    let missing_array = [];

    $.getJSON("/plugins/gpustat/gpustatus.php?argv=" + _args, (data) => {
        if (data) {
            $.each(data, function (key, value) {
                if (
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
                        key.includes("thrtlrsn")
                    ) ||
                    ((key === "rxutil" ||
                        key === "txutil" ||
                        key === "encutil" ||
                        key === "decutil") &&
                        !value.toString().includes("N/A"))
                ) {

                    /* console.log(key);
                    console.log(data[key]);
                    console.log(data[key + 'max']);
                    console.log($(data[key + 'max']).length); */

                    gpu_data.push(key);
                    if (!(($(data[key + 'max']).length > 0) || (value.toString().includes('%')))) {
                        gpu_data_nobars.push(key);
                    }
                }
            });

            // returns missing keys present in keyOrder but not in gpu_data
            disabled_array = keyOrder.filter(function (obj) { return gpu_data.indexOf(obj) == -1; });
            // returns missing keys preset in gpu_data but not in keyOrder
            missing_array = gpu_data.filter(function (obj) { return keyOrder.indexOf(obj) == -1; });
            // merge keyOrded and missing_array and remove keys from disabled_array
            gpu_data = keyOrder.concat(missing_array).filter(function (obj) { return disabled_array.indexOf(obj) < 0; });
            gpu_data = gpu_data.filter(function (obj) { return gpu_data_nobars.indexOf(obj) < 0; });


            /* console.log(data["name"]);
            console.log(data);
            console.log(gpu_data);
            console.log(disabled_array);
            console.log(missing_array);
            console.log(gpu_data_nobars);
            console.log(gpu_data_bars); */

            for (var i = 0; i < gpu_data.length; i += 2) {
                var $clone = $('#message-template-bars').html();
                $clone = $clone
                    .replaceAll("{{gpuNR}}", _args)
                    .replaceAll("{{label1}}", keyMap[gpu_data[i]] || gpu_data[i])
                    .replaceAll("{{label2}}", keyMap[gpu_data[i + 1]] || gpu_data[i + 1])
                    .replaceAll("{{stat1}}", gpu_data[i])
                    .replaceAll("{{stat2}}", gpu_data[i + 1]);

                if (gpu_data[i + 1]) {
                    $clone = $clone.replaceAll("'hidden'", "");
                }
                // etc
                $("#target-dash-gpustat" + _args).append($clone);
            }
            for (var i = 0; i < gpu_data_nobars.length; i += 3) {
                var $clone = $('#message-template-simple').html();
                $clone = $clone
                    .replaceAll("{{gpuNR}}", _args)
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
                // etc
                $("#target-dash-gpustat" + _args).append($clone);
            }

            if (data["vendor"].toLowerCase() === "nvidia") {
                var $clone = $('#message-template-sessions').html();
                $clone = $clone.replaceAll("{{gpuNR}}", _args)

                // etc
                $("#target-dash-gpustat" + _args).append($clone);
            }
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
        $(key).css({ 'color': (color) });
    } else {
        $(key).css({ 'color': '' });
    }
}

function change_tooltip(key, value, redvalue, title) {
    if (parseInt(value) >= parseInt(redvalue)) {
        $(key).attr('title', (title));
    }
}

function change_color_string(key, value, redvalue) {
    if (value === redvalue) {
        $(key).css({ 'color': 'magenta' });
    } else {
        $(key).css({ 'color': 'green' });
    }
}

/*
TODO: Not currently used due to issue with default reset actually working
function resetDATA(form) {
    form.VENDOR.value = "nvidia";
    form.TEMPFORMAT.value = "C";
    form.GPUBUS.value = "0";
    form.DISPCLOCKS.value = "1";
    form.DISPENCDEC.value = "1";
    form.DISPTEMP.value = "1";
    form.DISPFAN.value = "1";
    form.DISPPCIUTIL.value = "1";
    form.DISPPWRDRAW.value = "1";
    form.DISPPWRSTATE.value = "1";
    form.DISPTHROTTLE.value = "1";
    form.DISPSESSIONS.value = "1";
    form.UIREFRESH.value = "1";
    form.UIREFRESHINT.value = "1000";
    form.DISPMEMUTIL.value = "1";
    form.DISP3DRENDER.value = "1";
    form.DISPBLITTER.value = "1";
    form.DISPVIDEO.value = "1";
    form.DISPVIDENH.value = "1";
    form.DISPINTERRUPT.value = "1";
}
*/
