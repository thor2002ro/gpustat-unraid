/*
  MIT License

  Copyright (c) 2020-2022 b3rs3rk

  Permission is hereby granted, free of charge, to any person obtaining a copy
  of this software and associated documentation files (the "Software"), to deal
  in the Software without restriction, including without limitation the rights
  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
  copies of the Software, and to permit persons to whom the Software is
  furnished to do so, subject to the following conditions:

  The above copyright notice and this permission notice shall be included in all
  copies or substantial portions of the Software.

  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
  SOFTWARE.
*/

const gpustat_status_gpu1 = () => {
    gpustat_status(1);
}

const gpustat_status_gpu2 = () => {
    gpustat_status(2);
}

const gpustat_status_gpu3 = () => {
    gpustat_status(3);
}


const gpustat_status = function(_args) {
    $.getJSON("/plugins/gpustat/gpustatus.php?argv="+_args, (data) => {
        if (data) {
            switch (data["vendor"]) {
                case 'NVIDIA':
                    // Nvidia Slider Bars
                    $('.gpu'+_args+'-memclockbar').removeAttr('style').css('width', data["memclock"] / data["memclockmax"] * 100 + "%");
                    $('.gpu'+_args+'-gpuclockbar').removeAttr('style').css('width', data["clock"] / data["clockmax"] * 100 + "%");
                    $('.gpu'+_args+'-powerbar').removeAttr('style').css('width', parseInt(data["power"].replace("W","") / data["powermax"] * 100) + "%");
                    $('.gpu'+_args+'-rxutilbar').removeAttr('style').css('width', parseInt(data["rxutil"] / data["pciemax"] * 100) + "%");
                    $('.gpu'+_args+'-txutilbar').removeAttr('style').css('width', parseInt(data["txutil"] / data["pciemax"] * 100) + "%");

                    /* let nvidiabars = ['util', 'memutil', 'encutil', 'decutil', 'fan'];
                    nvidiabars.forEach(function (metric) {
                        $('.gpu'+_args+'-'+metric+'bar').removeAttr('style').css('width', data[metric]);
                    }); */

                    if (data["appssupp"]) {
                        data["appssupp"].forEach(function (app) {
                            if (data[app + "using"]) {
                                $('.gpu'+_args+'-img-span-'+app).css('display', "inline");
                                $('#gpu-'+app).attr('title', "Count: " + data[app+"count"] + " Memory: " + data[app+"mem"] + "MB");
                            } else {
                                $('.gpu'+_args+'-img-span-'+app).css('display', "none");
                                $('#gpu-'+app).attr('title', "");
                            }
                        });
                    }
                    break;
                case 'Intel':
                    // Intel Slider Bars
                   /*  let intelbars = ['3drender', 'blitter', 'video', 'videnh', 'powerutil'];
                    intelbars.forEach(function (metric) {
                        $('.gpu'+_args+'-'+metric+'bar').removeAttr('style').css('width', data[metric]);
                    }); */
                    break;
                case 'AMD':
                    //$('.gpu'+_args+'-powerbar').removeAttr('style').css('width', parseInt(data["power"] / data["powermax"] * 100) + "%");
                    //$('.gpu'+_args+'-fanbar').removeAttr('style').css('width', parseInt(data["fan"] / data["fanmax"] * 100) + "%");
                /*     let amdbars = [
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
                $('.gpu'+_args+'-'+key+'bar').removeAttr('style').css('width', value);
            
                if (!$('.gpu'+_args+'-'+key).parent().hasClass('gpu-stats-primary') && $(data[key+'_raw']).length > 0 && $(data[key+'max'+'_raw']).length > 0) {
                    $('.gpu'+_args+'-'+key).parent().attr('title', (data[key+'_raw']+' / '+data[key+'max'+'_raw']+' '+data[key+'unit']));
                    $('.gpu'+_args+'-'+key).html(data[key+'_raw']);
                } else if (!$('.gpu'+_args+'-'+key).parent().hasClass('gpu-stats-primary')) {
                    $('.gpu'+_args+'-'+key).parent().attr('title', (value));
                    $('.gpu'+_args+'-'+key).html(value);
                } else {
                    $('.gpu'+_args+'-'+key).html(value);
                }
            })
            
            change_visibility('#gpu'+_args+'-'+'pciegen-arrow', data["pcie_downspeed"]);
            change_visibility('#gpu'+_args+'-'+'pciewidth-arrow', data["pcie_downwidth"]);
            change_color('.gpu'+_args+'-'+'util', data["util"], 80);
            change_color('.gpu'+_args+'-'+'temp', data["temp"], data["tempmax"]-15);
            change_color_string('.gpu'+_args+'-'+'passedthrough', data["passedthrough"], "Passthrough");
        }
    });
};

const gpustat_dash = function(_args) {
    // append data from the table into the correct one
    $("#db-box1").append($(".dash_gpustat"+_args).html());

    // reload toggle to get the correct state
    toggleView('dash_gpustat_toggle'+_args, true);

    // reload sorting to get the stored data (cookie)
    sortTable($("#db-box1"), $.cookie("db-box1"));
}

function change_visibility(key, value) {
    $(key).removeClass('hidden');
    if (parseInt(value) == 0){ 
        $(key).addClass('hidden');
    } 
}

function change_color(key, value, redvalue) {
    if (parseInt(value) >= parseInt(redvalue)){ 
        $(key).css({'color':'red'});
    } else {
        $(key).css({'color':''});
    }
}

function change_color_string(key, value, redvalue) {
    if (value === redvalue){ 
        $(key).css({'color':'magenta'});
    } else {
        $(key).css({'color':'green'});
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
