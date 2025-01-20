jQuery(document).ready(()=>{
    const { __, _x, _n, sprintf } = wp.i18n;
    let system = {code:0, nonce:'', data:null, redeemed_successfully:false, img_pfad:'', last_scanned_ticket:{code:'', timestamp:0, auto_redeem:false}};
	let myAjax;
    if (typeof IS_PRETTY_PERMALINK_ACTIVATED === "undefined") {
        IS_PRETTY_PERMALINK_ACTIVATED = false;
    }

    let rest_route = '/event-tickets-with-ticket-scanner/ticket/scanner/';
    let pre_route = '../../../../..';
    if (typeof Ajax_sasoEventtickets != "undefined" && Ajax_sasoEventtickets.wcTicketCompatibilityModeRestURL != '') {
        pre_route = Ajax_sasoEventtickets.wcTicketCompatibilityModeRestURL.trim();
    }

    if (typeof Ajax_sasoEventtickets === "undefined") {
        myAjax = {
            url: pre_route + '/wp-json'+rest_route
        };
        system.nonce = NONCE;
    } else {
        myAjax = Ajax_sasoEventtickets;
        system.nonce = myAjax.nonce;
        if (Ajax_sasoEventtickets.wcTicketCompatibilityModeRestURL != "") {
            myAjax.url = Ajax_sasoEventtickets.wcTicketCompatibilityModeRestURL.trim()+'/wp-json'+rest_route;
        } else {
            myAjax.url = myAjax._siteUrl+'/wp-json'+rest_route;
        }
        IS_PRETTY_PERMALINK_ACTIVATED = myAjax.IS_PRETTY_PERMALINK_ACTIVATED;
    }
    myAjax.rest_route = rest_route;
    myAjax.non_pretty_permalink_url = pre_route+'/?rest_route='+myAjax.rest_route;

    system.INPUTFIELD;
    system.AUTHTOKENREMOVEBUTTON;
    system.ADDITIONBUTTONS;
    system.TIMEAREA;

    function toBool(v) {
        if (v == "1") return true;
        if (v == 1) return true;
        if (v.toLowerCase() == "yes") return true;
        return v == true;
    }

    var ticket_scanner_operating_option = {
        redeem_auto: false,
        distract_free: false,
        auth:"",
        ticketScannerDontRememberCamChoice:toBool(myAjax.ticketScannerDontRememberCamChoice),
        ticketScannerStartCamWithoutButtonClicked:false,
        ticketScannerDontShowOptionControls:toBool(myAjax.ticketScannerDontShowOptionControls),
        ticketScannerDontShowBtnPDF:toBool(myAjax.ticketScannerDontShowBtnPDF),
        ticketScannerDontShowBtnBadge:toBool(myAjax.ticketScannerDontShowBtnBadge)
    };

    var loadingticket = false;
    var div_ticket_info_area = null;
    var div_order_info_area = null;

    function addStyleCode(content, media) {
		let c = document.createElement('style');
        if (media) c.setAttribute("media", media);
		c.innerHTML = content;
		document.getElementsByTagName("head")[0].appendChild(c);
	}

    function onScanFailure(error) {
        // handle scan failure, usually better to ignore and keep scanning.
        // for example:
        //console.warn(`Code scan error = ${error}`);
    }
    var html5QrcodeScanner = null;
    var qrScanner = null;

    function setStartCamWithoutButtonClicked(value) {
        if (typeof value != "undefined") {
            ticket_scanner_operating_option.ticketScannerStartCamWithoutButtonClicked = value;
        } else {
            ticket_scanner_operating_option.ticket_scanner_operating_option.ticketScannerStartCamWithoutButtonClicked = !ticket_scanner_operating_option.ticketScannerStartCamWithoutButtonClicked;
        }
        _storeValue("ticket_scanner_operating_option.ticketScannerStartCamWithoutButtonClicked", ticket_scanner_operating_option.ticketScannerStartCamWithoutButtonClicked ? 1 : 0);
    }
    function setRedeemImmediately(value) {
        if (typeof value != "undefined") {
            ticket_scanner_operating_option.redeem_auto = value;
        } else {
            ticket_scanner_operating_option.redeem_auto = !ticket_scanner_operating_option.redeem_auto;
        }
        _storeValue("ticket_scanner_operating_option.redeem_auto", ticket_scanner_operating_option.redeem_auto ? 1 : 0);
    }
    function setDistractFree(value) {
        if (typeof value != "undefined") {
            ticket_scanner_operating_option.distract_free = value;
        } else {
            ticket_scanner_operating_option.distract_free = !ticket_scanner_operating_option.distract_free;
        }
        _storeValue("ticket_scanner_operating_option.distract_free", ticket_scanner_operating_option.distract_free ? 1 : 0);
    }
    function initAuthToken() {
        let text = _loadValue("ticket_scanner_operating_option.auth");
        if (system.PARA.auth) {
            text = system.PARA.auth.trim();
        }
        if (text != "") {
            try {
                let json = JSON.parse(text);
                setAuthToken(json, true);
            } catch (e) {
                alert(e);
            }
        }
    }
    function setAuthToken(token, doNotUpdateScanOption) {
        // {"type":"auth","time":"2023-07-10 20:07:24","name":"saso","code":"AHR0CHM6LY92ZXJ3AWNRBHVUZY5KZS93B3JKCHJLC3M=_0C3C7AF3DCCD805F56EF02BEB9E39FFC","areacode":"ticketscanner","url":"https://verwicklung.de/wordpress/wp-content/plugins/event-tickets-with-ticket-scanner/ticket/"}
        if (typeof token != "undefined" && typeof token.type != "undefined" && token.type == "auth") {
            //ticket_scanner_operating_option.auth = token;
        } else {
            token = "";
        }
        ticket_scanner_operating_option.auth = token;
        _storeValue("ticket_scanner_operating_option.auth", JSON.stringify(token));
        if (!doNotUpdateScanOption) showScanOptions();
    }
    function onScanSuccess(decodedText, decodedResult) {
        if (system.last_scanned_ticket.code == decodedText && system.last_scanned_ticket.timestamp + 10 > time()) {
            return;
        }
        if (loadingticket) return;
        loadingticket = true;
        system.last_scanned_ticket = {code: decodedText, timestamp: time()};

        if (qrScanner != null) {
            //qrScanner.stop(); // faster if not executed
        }

        // store setting to cookies / or browser storage
        if (!ticket_scanner_operating_option.ticketScannerDontRememberCamChoice && html5QrcodeScanner != null) {
            _storeValue("ticketScannerCameraId", html5QrcodeScanner.persistedDataManager.data.lastUsedCameraId, 365);
        }

        updateTicketScannerInfoArea("<center>"+sprintf(/* translators: %s: ticket number */__("found %s", 'event-tickets-with-ticket-scanner'), decodedText)+'</center>');
        // handle the scanned code as you like, for example:
        //console.log(`Code matched = ${decodedText}`, decodedResult);
        $("#reader_output").html(__("...loading...", 'event-tickets-with-ticket-scanner'));
        //window.location.href = "?code="+encodeURIComponent(decodedText) + (ticket_scanner_operating_option.redeem_auto ? "&redeemauto=1" : "");

        let token = null;
        try {
            token = JSON.parse(decodedText);
        } catch(e) {}
        if (token != null && typeof token == "object") {
            if (token.type && token.type == "auth") {
                setAuthToken(token);
                clearAreas();
                $("#reader_output").html('');
                updateTicketScannerInfoArea('<h1 style="color:green !important;">Auth Token Set</h3>');
                window.setTimeout(()=>{
                    showScanNextTicketButton();
                }, 350);
            } else {
                renderInfoBox("QR code content unknown. Can not extract data correctly. Please try a QR code of a ticket.");
            }
        } else {
            retrieveTicket(decodedText);
        }

        window.setTimeout(()=>{
            if (html5QrcodeScanner != null) {
                html5QrcodeScanner.clear().then((ignore) => {
                    // QR Code scanning is stopped.
                    // reload the page with the ticket info and redeem button
                    //console.log("stop success");
                }).catch((err) => {
                    // Stop failed, handle it.
                    //console.log("stop failed");
                });
            }
        }, 250);
      }

    function startScanner() {
        if (!ticket_scanner_operating_option.redeem_auto) updateTicketScannerInfoArea("");
        $("#reader_output").html("");
        loadingticket = false;

        if (system.PARA.useoldticketscanner) {
            startScanner_html5QrcodeSCanner();
        } else {
            startScanner_QRScanner();
        }
    }
    function startScanner_QRScanner() {
        let deviceId = _loadValue("ticketScannerCameraId");
        let v_id = 'saso_eventtickets_qr-video';
        let camlist_id = 'saso_eventtickets_camList';
        let start_cam = false;
        if (document.getElementById(v_id) == null) {
            $("#reader").html("");
            start_cam = true;
            $('#reader').append('<video id="'+v_id+'" style="width:100%" disablepictureinpicture playsinline></video>');
            $('<select id="'+camlist_id+'" style="width: 100%;"></select>').appendTo($('#reader')).on("change", event=>{
                _storeValue("ticketScannerCameraId", event.target.value, 365);
                qrScanner.setCamera(event.target.value);//.then(updateFlashAvailability);
            });
            let btn = $('<button>').text("Stop Camera").appendTo($('#reader')).on("click", event=>{
                qrScanner.stop();
                qrScanner.destroy();
                qrScanner = null;
                btn.css("display", "none");
                btn_start.css("display", "block");
            });

// flashlight button
/*
https://github.com/nimiq/qr-scanner
Flashlight support
On supported browsers, you can check whether the currently used camera has a flash and turn it on or off. Note that hasFlash should be called after the scanner was successfully started to avoid the need to open a temporary camera stream just to query whether it has flash support, potentially asking the user for camera access.

qrScanner.hasFlash(); // check whether the browser and used camera support turning the flash on; async.
qrScanner.isFlashOn(); // check whether the flash is on
qrScanner.turnFlashOn(); // turn the flash on if supported; async
qrScanner.turnFlashOff(); // turn the flash off if supported; async
qrScanner.toggleFlash(); // toggle the flash if supported; async.
*/

            let btn_start = $('<button class="button-ticket-options button-primary" style="display:none;">').text("Start Camera").appendTo($('#reader')).on("click", event=>{
                btn_start.css("display", "none");
                btn.css("display", "block");
                startScanner();
            });
        }

        if (qrScanner != null) {
            qrScanner.stop();
            qrScanner.destroy();
        }
        qrScanner = new QrScanner(
            document.getElementById(v_id),
            result => {
                onScanSuccess(result.data, result);
            },
            { highlightScanRegion: true,
            highlightCodeOutline: true,
            willReadFrequently:true,
            /* your options or returnDetailedScanResult: true if you're not specifying any other options */ }
        );

        if (deviceId != null && deviceId != "" && !ticket_scanner_operating_option.ticketScannerDontRememberCamChoice) {
            qrScanner.setCamera(deviceId);
        }

        if (start_cam) {
            qrScanner.start().then(() => {
                //updateFlashAvailability();
                // List cameras after the scanner started to avoid listCamera's stream and the scanner's stream being requested
                // at the same time which can result in listCamera's unconstrained stream also being offered to the scanner.
                // Note that we can also start the scanner after listCameras, we just have it this way around in the demo to
                // start the scanner earlier.
                QrScanner.listCameras(true).then(cameras => cameras.forEach(camera => {
                    const option = document.createElement('option');
                    option.value = camera.id;
                    option.text = camera.label;
                    if (camera.id == deviceId) {
                        option.selected = true;
                    }
                    $('#'+camlist_id).append(option);
                }));
            });
        } else {
            qrScanner.start();
        }
    }
    function startScanner_html5QrcodeSCanner() {
        if (html5QrcodeScanner == null) {
            let options = { fps: 25, qrbox: {width: 250, height: 250} };
            let deviceId = _loadValue("ticketScannerCameraId");
            if (deviceId != null && deviceId != "" && !ticket_scanner_operating_option.ticketScannerDontRememberCamChoice) {
                options.deviceId = {exact: deviceId}; // deviceId: { exact: cameraId}
            }
            html5QrcodeScanner = new Html5QrcodeScanner("reader",
                        options,
                        /* verbose= */ false);
        }
        //html5QrcodeScanner.render(onScanSuccess, onScanFailure);
        html5QrcodeScanner.render(onScanSuccess);
        window.qrs = html5QrcodeScanner;
    }

    function showScanNextTicketButton() {
        let skip = ticket_scanner_operating_option.ticketScannerStartCamWithoutButtonClicked;
        let div = $('<div>');
        $('#reader').css("border", "none").html(div);
        if (skip) {
            startScanner();
        } else {
            let btngrp = $('<div>').css("text-align", 'center').appendTo(div);
            $('<button class="button-ticket-options button-primary">').html(__("Scan next Ticket", 'event-tickets-with-ticket-scanner')).on("click", e=>{
                clearAreas();
                clearOrderInfos();
                startScanner();
            }).appendTo(btngrp);
            if (qrScanner != null) {
                $('<button class="button-ticket-options">').html(__("Stop camera", 'event-tickets-with-ticket-scanner')).on("click", e=>{
                    qrScanner.stop();
                    qrScanner.destroy();
                    qrScanner = null;
                    $(e.target).css("display", "none");
                }).appendTo(btngrp);
            }
        }
    }
    function showScanOptions() {
        let div = $('<div>');
        if (!ticket_scanner_operating_option.ticketScannerDontShowOptionControls) {
            let chkbox_redeem_imediately = $('<input type="checkbox">').on("click", e=>{
                setRedeemImmediately();
            }).appendTo(div);
            if (ticket_scanner_operating_option.redeem_auto) chkbox_redeem_imediately.prop("checked", true);
            div.append(' Scan and Redeem immediately');
            div.append("<br>");

            let chkbox_distractfree = $('<input type="checkbox">').on("click", e=>{
                setDistractFree();
                if (ticket_scanner_operating_option.distract_free) {
                    $('#ticket_info').css("display", "none");
                } else {
                    $('#ticket_info').css("display", "block");
                }
            }).appendTo(div);
            if (ticket_scanner_operating_option.distract_free) chkbox_distractfree.prop("checked", true);
            div.append(' Hide ticket information');
            div.append("<br>");

            let chkbox_ticketScannerStartCamWithoutButtonClicked = $('<input type="checkbox">').on("click", e=>{
                setStartCamWithoutButtonClicked(!ticket_scanner_operating_option.ticketScannerStartCamWithoutButtonClicked);
            }).appendTo(div);
            chkbox_ticketScannerStartCamWithoutButtonClicked.prop("checked", ticket_scanner_operating_option.ticketScannerStartCamWithoutButtonClicked);
            div.append(' Start cam to scan next ticket immediately');
        }

        $('<div style="margin-top:40px;">').append(system.INPUTFIELD).appendTo(div);
        if (typeof ticket_scanner_operating_option.auth == "object") div.append(system.AUTHTOKENREMOVEBUTTON);
        div.append(system.ADDITIONBUTTONS);
        system.TIMEAREA = $('<div>');
        div.append(system.TIMEAREA);
        $('#reader_options').html(div);
    }

    function addMetaTag(name, content) {
        let head = document.getElementsByTagName("head")[0];
        let metaTags = head.getElementsByTagName("meta");
        let contains = false;
        for (let i=0;i<metaTags.length;i++) {
            let tag = metaTags[i];
            if (tag.name == name) {
                tag.content = content;
                contains = true;
                break;
            }
        }
        if (!contains) {
            let metaTag = document.createElement("meta");
            metaTag.name = name;
            metaTag.content = content;
            head.appendChild(metaTag);
        }
    }

    function _storeValue(name, wert, days) {
        if (window.JAVAJSBridge && window.JAVAJSBridge.setItem) window.JAVAJSBridge.setItem(name, wert);
        else setCookie(name, wert, days);
    }
    function _loadValue(name) {
        if (window.JAVAJSBridge && window.JAVAJSBridge.getItem) return window.JAVAJSBridge.getItem(name);
        return getCookie(name);
    }
    function setCookie(cname, cvalue, exdays) {
      var d = new Date();
      if (!exdays) exdays = 30;
      d.setTime(d.getTime() + (exdays * 24 * 60 * 60 * 1000));
      var expires = "expires="+d.toUTCString();
      document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
    }
    function getCookie(cname) {
      var name = cname + "=";
      var ca = document.cookie.split(';');
      for(var i = 0; i < ca.length; i++) {
        var c = ca[i];
        while (c.charAt(0) === ' ') {
          c = c.substring(1);
        }
        if (c.indexOf(name) === 0) {
          return c.substring(name.length, c.length);
        }
      }
      return "";
    }
    function _getURLAndDateForAjax(action, myData, pcbf) {
        let _data = {};
        _data.action = action;
        _data.t = new Date().getTime();
        //if (system.nonce != '') _data._wpnonce = system.nonce;
        if (system.nonce != '') _data.nonce = system.nonce;
        pcbf && pcbf();
        //if (myData) for(var key in myData) _data['data['+key+']'] = myData[key];
        if (myData) for(var key in myData) _data[key] = myData[key];
        if (ticket_scanner_operating_option && ticket_scanner_operating_option.auth && ticket_scanner_operating_option.auth.code && ticket_scanner_operating_option.auth.code != "") {
            let key = "auth";
            if (Ajax_sasoEventtickets && Ajax_sasoEventtickets._params && Ajax_sasoEventtickets._params.auth) key = Ajax_sasoEventtickets._params.auth;
            _data[key] = ticket_scanner_operating_option.auth.code;
        }
        if (system.nonce != '') {
            $.ajaxSetup({
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', system.nonce);
                },
            });
        }

        let url = myAjax.url;
        if (IS_PRETTY_PERMALINK_ACTIVATED == false) {
            url = myAjax.non_pretty_permalink_url;
        }
        url += action;
        return {url:url, data:_data};
    }
	function _downloadFile(action, myData, filenameToStore, cbf, ecbf, pcbf) {
        let call_data = _getURLAndDateForAjax(action, myData, pcbf);
        let params = "";
        for(let key in call_data.data) {
            params += key+"="+encodeURIComponent(call_data.data[key])+"&";
        }
		let url = call_data.url+'?'+params;
		//window.location.href = url;
		ajax_downloadFile(url, filenameToStore, cbf);
	}
    function ajax_downloadFile(urlToSend, fileName, cbf) {
		var req = new XMLHttpRequest();
		req.open("GET", urlToSend, true);
		req.responseType = "blob";
		req.onload = function (event) {
			var blob = req.response;
			//var fileName = req.getResponseHeader("X-fileName") //if you have the fileName header available
			var link=document.createElement('a');
			link.href=window.URL.createObjectURL(blob);
			link.download=fileName;
			link.click();
			cbf && cbf();
		};

		req.send();
	}
    function _makeGet(action, myData, cbf, ecbf, pcbf) {
        let call_data = _getURLAndDateForAjax(action, myData, pcbf);
        console.log(call_data);
        $.get( call_data.url, call_data.data, response=>{
            if (response && response.data && response.data.nonce) system.nonce = response.data.nonce;
            if (!response.success) {
                if (ecbf) ecbf(response);
                else {
                    let msg = (response.data.status ? response.data.status : '') + " " + (response.data.message ? response.data.message : '');
                    renderFatalError(msg.trim());
                }
            } else {
                cbf && cbf(response.data);
            }
        }).always(jqXHR=>{
            if(jqXHR.status == 401 || jqXHR.status == 403) {
                renderFatalError(__("Access rights missing. Please login first.", 'event-tickets-with-ticket-scanner') + " "+(jqXHR.responseJSON && jqXHR.responseJSON.message ? jqXHR.responseJSON.message : '') );
            }
            if(jqXHR.status == 400) {
                renderFatalError(jqXHR.responseJSON.message);
            }
        });
    }
    function _makePost(action, myData, cbf, ecbf, pcbf) {
        let call_data = _getURLAndDateForAjax(action, myData, pcbf);
        $.post( call_data.url, call_data.data, response=>{
            if (response && response.data && response.data.nonce) system.nonce = response.data.nonce;
            if (!response.success) {
                if (ecbf) ecbf(response);
                else {
                    let msg = (response.data.status ? response.data.status : '') + " " + (response.data.message ? response.data.message : '');
                    renderFatalError(msg.trim());
                }
            } else {
                cbf && cbf(response.data);
            }
        }).always(jqXHR=>{
            if(jqXHR.status == 401 || jqXHR.status == 403) {
                renderFatalError(__("Access rights missing. Please login first.", 'event-tickets-with-ticket-scanner') + " " + (jqXHR.responseJSON && jqXHR.responseJSON.message ? jqXHR.responseJSON.message : '') );
            }
            if(jqXHR.status == 400) {
                renderFatalError(jqXHR.responseJSON.message);
            }
        });
    }
    function _getSpinnerHTML() {
        return '<span class="lds-dual-ring"></span>';
    }
    function makeDateFromString(timestring, timezone_id) {
        let d = new Date(timestring);
        return new Date(d.toLocaleString('en', {timeZone: timezone_id}));
    }
    function makeDate(timestamp, timezone_id) {
        let d = new Date();
        d.setTime(timestamp);
        return new Date(d.toLocaleString('en', {timeZone: timezone_id}));
    }
    function time(timezone_id, timestamp) {
        let d = new Date();
        if (timestamp) {
            d.setTime(timestamp);
        }
        if (timezone_id && timezone_id.indexOf("/") > 0) {
            d = new Date(d.toLocaleString('en', {timeZone: timezone_id}));
        }
        return parseInt(d.getTime() / 1000);
    }
	function parseDate(str){
		if (!str) return null;
		return new Date(str.split(' ')[0].replace(/-/g,"/"));
	}
	function parseDateAndText(str, format) {
		return Date2Text(parseDate(str).getTime(), format);
	}
	function DateTime2Text(millisek) {
		return Date2Text(millisek, system.format_datetime ? system.format_datetime : "d.m.Y H:i");
	}
	function Date2Text(millisek, format, timezone_id) {
		if (!millisek)
			millisek = time(timezone_id);
		var d = new Date(millisek);
		if (!format)
			//format = system.format_date ? system.format_date : "%d.%m.%Y";
            format = system.format_date ? system.format_date : "d.m.Y";
			//format = "%d.%m.%Y %H:%i";
		var tage = [
            _x('Sun', 'cal', 'event-tickets-with-ticket-scanner'),
            _x('Mon', 'cal', 'event-tickets-with-ticket-scanner'),
            _x('Tue', 'cal', 'event-tickets-with-ticket-scanner'),
            _x('Wed', 'cal', 'event-tickets-with-ticket-scanner'),
            _x('Thu', 'cal', 'event-tickets-with-ticket-scanner'),
            _x('Fri', 'cal', 'event-tickets-with-ticket-scanner'),
            _x('Sat', 'cal', 'event-tickets-with-ticket-scanner')
        ];
		var monate = [
            _x('Jan', 'cal', 'event-tickets-with-ticket-scanner'),
            _x('Feb', 'cal', 'event-tickets-with-ticket-scanner'),
            _x('Mar', 'cal', 'event-tickets-with-ticket-scanner'),
            _x('Apr', 'cal', 'event-tickets-with-ticket-scanner'),
            _x('May', 'cal', 'event-tickets-with-ticket-scanner'),
            _x('Jun', 'cal', 'event-tickets-with-ticket-scanner'),
            _x('Jul', 'cal', 'event-tickets-with-ticket-scanner'),
            _x('Aug', 'cal', 'event-tickets-with-ticket-scanner'),
            _x('Sep', 'cal', 'event-tickets-with-ticket-scanner'),
            _x('Oct', 'cal', 'event-tickets-with-ticket-scanner'),
            _x('Nov', 'cal', 'event-tickets-with-ticket-scanner'),
            _x('Dec', 'cal', 'event-tickets-with-ticket-scanner')
        ];
		var formate = {'d':d.getDate()<10?'0'+d.getDate():d.getDate(),
				'j':d.getDate(),'D':tage[d.getDay()],'w':d.getDate(),'m':d.getMonth()+1<10?'0'+(d.getMonth()+1):d.getMonth()+1,'M':monate[d.getMonth()],
				'n':d.getMonth()+1,'Y':d.getFullYear(),'y':d.getYear()>100?d.getYear().toString().substr(d.getYear().toString().length-2):d.getYear(),
				'H':d.getHours()<10?'0'+d.getHours():d.getHours(),'h':d.getHours()>12?d.getHours()-12:d.getHours(),
				'i':d.getMinutes()<10?'0'+d.getMinutes():d.getMinutes(),'s':d.getSeconds()<10?'0'+d.getSeconds():d.getSeconds()
				};
        for (var akey in formate) {
            //var rg = new RegExp('%'+akey, "g");
            var rg = new RegExp(akey, "g");
            format = format.replace(rg, formate[akey]);
        }
		return format;
	}
    function renderInfoBox(title, content) {
        let _options = {
            title: title,
            modal: true,
            minWidth: 400,
            minHeight: 200,
            buttons: [{text:_x('Ok', 'label', 'event-tickets-with-ticket-scanner'), click:function(){
                $(this).dialog("close");
                $(this).html("");
                clearAreas();
                $('#ticket_info').html(content);
                $('#reader').html("");
            }}]
        };
        if (typeof content !== "string") content = JSON.stringify(content);
        let dlg = $('<div/>').html(content);
        dlg.dialog(_options);
        return dlg;
    }
    function renderFatalError(content) {
        return renderInfoBox('Error', content);
    }
    function basics_ermittelURLParameter() {
        var parawerte = {};
        var teile;
        if (window.location.search !== "") {
            teile = window.location.search.substring(1).split("&");
            for (var a=0;a<teile.length;a++)
            {
                var pos = teile[a].indexOf("=");
                if (pos < 0) {
                    parawerte[teile[a]] = true;
                } else {
                    var key = teile[a].substr(0,pos);
                    parawerte[key] = decodeURIComponent(teile[a].substr(pos+1));
                }
            }
        }
        return parawerte;
    }
    function clearOrderInfos() {
        $('#order_info').html("");
    }
    function clearAreas() {
        $('#ticket_info_btns').html('');
        $('#ticket_add_info').html('');
        $('#ticket_info').html('');
        updateTicketScannerInfoArea('');
        $('#ticket_info_retrieved').html('');
    }
    function retrieveTicket(code, redeemed, cbf) {
        clearAreas();
        window.scrollBy(0,0);
        let div = $('#ticket_info').html(_getSpinnerHTML());
        div.css("display", "block");

        // check if the code is URL
        if (code.length > 12) {
            if (code.substr(0,5).toLowerCase() == "https") {
                if (code.substr(0,8).toLowerCase() == "https://" || (code.length > 14 && code.substr(0,14).toLowerCase() == "https%3A%2F%2F")) {
                    // extract code from URL
                    let url = code;
                    let pos = url.lastIndexOf("code=");
                    if (pos > 0) {
                        code = url.substr(pos + 5);
                    } else {
                        pos = url.toLowerCase().lastIndexOf("code%3d");
                        if (pos > 0) {
                            code = url.substr(pos + 7);
                        }
                    }
                }
            }
        }
        if (code == "") {
            alert("no code found");
            showScanNextTicketButton();
            return;
        }

        let redeem = ticket_scanner_operating_option.redeem_auto;
        if (redeemed == true) {
            redeem = false; // is already redeemed
        }
        system.last_scanned_ticket.auto_redeem = redeem;
        _makeGet('retrieve_ticket', {'code':code, 'redeem':redeem ? 1 : 0}, data=>{
            if (ticket_scanner_operating_option.distract_free) {
                div.css("display", "none");
            }
            system.data = data;
            system.code = code; // falls per code überschrieben wurde

            if (typeof data.order_infos !== "undefined" && data.order_infos.is_order_ticket) {
                system.format_datetime = data.option_displayDateTimeFormat;
                displayOrderTicketInfo(data);
                showScanNextTicketButton();
            } else {
                system.format_datetime = data._ret.option_displayDateTimeFormat;
                displayTicketInfo(data);
                displayTicketRetrievedInfo(data);
                displayTicketAdditionalInfos(data);

                $("#reader_output").html("");
                if(!redeemed && ticket_scanner_operating_option.redeem_auto && typeof data._ret.redeem_operation !== "undefined") {
                    // display redeem operation
                    //redeemTicket(code);
                    displayRedeemedInfo(code, data._ret.redeem_operation);
                } else {
                    showScanNextTicketButton();
                }
            }

            cbf && cbf();
        }, response=>{
            clearAreas();
            $("#reader_output").html('');
            updateTicketScannerInfoArea('<h1 style="color:red !important;">'+response.data+'</h3>');
            showScanNextTicketButton();
            cbf && cbf();
        });
    }
    function isTicketExpired(ticketRetObject) {
        if (ticketRetObject.is_expired) return true;
        return false;
    }
    function isRedeemTooEarly(data) {
        if (data._ret._options.wcTicketDontAllowRedeemTicketBeforeStart) {
            return data._ret._options.isRedeemOperationTooEarly;
        }
        return false;
    }
    function isRedeemTooLate(data) {
        if (data._ret._options.wsticketDenyRedeemAfterstart) {
           return data._ret._options.isRedeemOperationTooLate;
        }
        return false;
    }
    function isRedeemTooLateEndEvent(data) {
        if (data._ret._options.wcTicketAllowRedeemTicketAfterEnd == false) {
           return data._ret._options.isRedeemOperationTooLateEventEnded;
        }
        return false;
    }

    function canTicketBeRedeemedNow(data) {
        if (isRedeemTooEarly(data)) return false;
        if (isRedeemTooLateEndEvent(data)) return false;
        if (isRedeemTooLate(data)) return false;
        if (isTicketExpired(data._ret)) return false;
        return true;
    }
    function displayTicketRetrievedInfo(data) {
        let div = $('<div>').css("text-align", "center");
        let metaObj = data.metaObj
        let is_expired = isTicketExpired(data._ret);
        if (!data._ret.is_paid) {
            $('<h4 style="color:red !important;">').html(sprintf(/* translators: %s: order status */__('Ticket is NOT paid (%s).', 'label', 'event-tickets-with-ticket-scanner'),data._ret.order_status)).appendTo(div);
        } else {
            if (is_expired == false && metaObj['wc_ticket']['redeemed_date'] != "") {
                let color = "red";
                if (data._ret.max_redeem_amount > 1 && data.metaObj.wc_ticket.stats_redeemed.length < data._ret.max_redeem_amount) {
                    color = "green";
                }
                //if (system.last_scanned_ticket.auto_redeem == false) {
                if (system.redeemed_successfully) {
                    $('<h4 style="color:'+color+' !important;">').html(data._ret.msg_redeemed).appendTo(div);
                }
                if (metaObj.wc_ticket.redeemed_date != '') {
                    div.append(data._ret.redeemed_date_label+' '+metaObj['wc_ticket']['redeemed_date']);
                }
            } else {
                if (is_expired) {
                    div.append('<div style="color:red;">'+data._ret.msg_ticket_expired+'</div>');
                    div.append(data._ret.ticket_date_as_string);
                } else {
                    if (data._ret.ticket_end_date == "" || data._ret.ticket_end_date_timestamp > time()) {
                        div.append('<div style="color:green;">'+data._ret.msg_ticket_valid+'</div>');
                    }
                }
            }

            if (is_expired == false) {
                let _isRedeemTooLate = isRedeemTooLate(data);
                let _isRedeemTooLateEndEvent = isRedeemTooLateEndEvent(data);
                if (!canTicketBeRedeemedNow(data)) {
                    let error_msg = data._ret.msg_ticket_not_valid_yet;
                    if(_isRedeemTooLateEndEvent) {
                        error_msg = data._ret.msg_ticket_event_ended;
                    } else if(_isRedeemTooLate) {
                        error_msg = data._ret.msg_ticket_not_valid_anymore;
                    }
                    div.append('<div style="color:red;">'+error_msg+'</div>');
                }
                if (_isRedeemTooLate == false && _isRedeemTooLateEndEvent == false && data._ret._options.wcTicketDontAllowRedeemTicketBeforeStart) {
                    if (typeof data._ret.redeem_allowed_from != "undefined" && typeof data._ret.is_date_set != "undefined" && data._ret.is_date_set) {
                        div.append("<div>Redeem allowed from: <b>"+data._ret.redeem_allowed_from+"</b></div>");
                    }
                }
            }
        }
        $('#ticket_info_retrieved').html(div);
    }
    function displayTicketAdditionalInfos(data) {
        let div = $('<div style="width:50%;display:inline-block;">');
        if (data._ret.is_paid) {
            $('<div>').html('<b>'+__('Ticket paid', 'event-tickets-with-ticket-scanner')+'</b>').css("color", "green").appendTo(div);
        } else {
            $('<div>').html(__('Ticket NOT paid', 'event-tickets-with-ticket-scanner')).css("color", "red").appendTo(div);
        }
        if (data.metaObj.wc_ticket.redeemed_date != "") {
            $('<div>').html(__('Ticket is already redeemed', 'event-tickets-with-ticket-scanner')).appendTo(div);
        } else {
            $('<div>').html(__('Ticket not redeemed', 'event-tickets-with-ticket-scanner')).appendTo(div);
        }
        if (data._ret._options.displayConfirmedCounter) {
            $('<div>').html(sprintf(/* translators: %s: confirmed check counter */__('Confirmed status validation check counter: <b>%s</b>', 'event-tickets-with-ticket-scanner'), data.metaObj.confirmedCount)).appendTo(div);
        }
        $('<div>').html(sprintf(/* translators: %s: max redeem amount */__('Max Redeem Amount for this ticket: <b>%s</b>', 'event-tickets-with-ticket-scanner'), data._ret.max_redeem_amount)).appendTo(div);
        if(data._ret.max_redeem_amount > 1) {
            $('<div>').html(sprintf(/* translators: 1: redeemd tickets 2: max redeem */__('Redeem usage: <b>%1$d</b> of <b>%2$d</b>', 'event-tickets-with-ticket-scanner'), data.metaObj.wc_ticket.stats_redeemed.length, data._ret.max_redeem_amount)).appendTo(div);
        }

        let div2 = $('<div style="width:50%;display:inline-block;">');
        if (data._ret._options.wcTicketDontAllowRedeemTicketBeforeStart && typeof data._ret.is_date_set != "undefined" && data._ret.is_date_set) {
            //if (data._ret._options.isRedeemOperationTooEarly) {
                div2.append($('<div>').html(sprintf(/* translators: %s: date */__('Redeemable from %s', 'event-tickets-with-ticket-scanner'), data._ret.redeem_allowed_from)));
            //}
        }
        if (typeof data._ret.redeem_allowed_until != "undefined" && typeof data._ret.is_date_set != "undefined" && data._ret.is_date_set) {
            div2.append($('<div>').html(sprintf(/* translators: %s: date */__('Redeemable until %s', 'event-tickets-with-ticket-scanner'), data._ret.redeem_allowed_until)));
        }

        if (data.metaObj.woocommerce.creation_date != "") {
           div2.append('<div>'+sprintf(/* translators: %s: date */__('Bought at %s', 'event-tickets-with-ticket-scanner'), DateTime2Text(new Date(data.metaObj.woocommerce.creation_date).getTime()))+'</div>');
        }

        let is_expired = isTicketExpired(data._ret);
        if (typeof data.metaObj.expiration != "undefined") {
            if (data.metaObj.expiration.date != "") {
                div2.append('<div'+(is_expired ? ' style="font-weight:bold;"' : '')+'>'+sprintf(/* translators: %s: date */__('Expiration at %s', 'event-tickets-with-ticket-scanner'), DateTime2Text(new Date(data.metaObj.expiration.date).getTime()))+'</div>');
            } else {
                let date_expiration_ms = new Date(data.metaObj.woocommerce.creation_date).getTime();
                date_expiration_ms += data.metaObj.expiration.days * 24 * 3600 * 1000;
                let exp_text = data.metaObj.expiration.days > 0 ? sprintf(/* translators: 1: days 2: date */__('Expires after %1$d days (%2$s)', 'event-tickets-with-ticket-scanner'), data.metaObj.expiration.days, DateTime2Text( date_expiration_ms )) : '';
                if (exp_text != "") {
                    div2.append('<div>'+exp_text+'</div>');
                }
            }
        }

        let div3 = $('<div>');
        if (typeof data._ret.product !== "undefined") {
            div3.css("margin-top", "10px").html(__('<b>Product information</b>', 'event-tickets-with-ticket-scanner'))
                .append('<div>'+sprintf(__('#%s - %s', 'event-tickets-with-ticket-scanner'), data._ret.product.id, data._ret.product.name + ' '+ data._ret.product.name_variant)+'</div>');
            if (data._ret.product.sku != "") {
                div3.append('<div>'+sprintf(__('SKU: %s', 'event-tickets-with-ticket-scanner'), data._ret.product.sku)+'</div>');
            }
        }
        let content = "";
        if (ticket_scanner_operating_option.distract_free) {
            content = '<center>'+system.code+'</center>';
        }
        $('#ticket_add_info').html(content)
            .append( $('<div style="padding-top:10px;width:100%;">').append(div).append(div2) )
            .append(div3);
    }
    function displayRedeemedInfo(code, data) {
        system.redeemed_successfully = data.redeem_successfully;
        displayTicketRedeemedInfo(data);
        if(ticket_scanner_operating_option.redeem_auto) {
            showScanNextTicketButton();
        } else {
            //retrieveTicket(code, true);
        }
        system.INPUTFIELD.focus();
        system.INPUTFIELD.select();
    }
    function displayTicketRedeemedInfo(data) {
        showScanNextTicketButton();
        // zeige retrieved info an
        let content = $('<div>').html('<center>'+system.code+'</center>');
        if (system.redeemed_successfully) {
            content.append('<h3 style="color:green !important;text-align:center;">'+__('Redeemed', 'event-tickets-with-ticket-scanner')+'</h3>');
            content.append('<p style="text-align:center;color:green"><img src="'+system.img_pfad+'button_ok.png"><br><b>'+__('Successfully redeemed', 'event-tickets-with-ticket-scanner')+'</b></p>');
        } else {
            content.append('<h3 style="color:red !important;text-align:center;">'+__('NOT REDEEMED - see reason below', 'event-tickets-with-ticket-scanner')+'</h3>');
            content.append('<p style="text-align:center;color:red;"><img src="'+system.img_pfad+'button_cancel.png"><br><b>'+__('Failed to redeem', 'event-tickets-with-ticket-scanner')+'</b></p>');
        }
        updateTicketScannerInfoArea(content);
    }
    function displayRedeemedOrderInfo(code, data) {
        let content = $('<div>');
        content.html('<center>'+code+'</center>');
        if (data.errors.length > 0) {
            content.append('<h3 style="color:red !important;text-align:center;">'+__('ERRORS - see reason below', 'event-tickets-with-ticket-scanner')+'</h3>');
        } else if (data.not_redeemed.length) { // is not implemented yet
            content.append('<h3 style="color:orange !important;text-align:center;">'+__('NOT REDEEMED - see reason below', 'event-tickets-with-ticket-scanner')+'</h3>');
        } else {
            content.append('<h3 style="color:green !important;text-align:center;">'+__('Order Redeemed', 'event-tickets-with-ticket-scanner')+'</h3>');
        }
        updateTicketScannerInfoArea(content);

        system.INPUTFIELD.focus();
        system.INPUTFIELD.select();
    }
    function redeemTicket(code) {
        clearAreas();
        system.redeemed_successfully = false;
        $("#reader_output").html(__("start redeem ticket...loading..."));
        updateTicketScannerInfoArea(_getSpinnerHTML());
        _makeGet('redeem_ticket', {'code':code}, data=>{
            system.data = data;
            $("#reader_output").html('');

            if (typeof data.is_order_ticket !== "undefined" && data.is_order_ticket) {
                // update li
                data.errors.forEach(item=>{
                    let elems = $('#order_info').find('li[data-id="'+encodeURIComponent(item.code)+'"]');
                    elems.css("padding", "5px");
                    elems.css("margin-bottom", "5px");
                    elems.css("background-color", "red");
                    //elems.css("color", "white");
                    elems.append("<br>"+item.error);
                });
                data.not_redeemed.forEach(item=>{
                    let elems = $('#order_info').find('li[data-id="'+encodeURIComponent(item.code)+'"]');
                    elems.css("padding", "5px");
                    elems.css("margin-bottom", "5px");
                    elems.css("background-color", "orange");
                    elems.css("color", "black");
                    elems.append("<br>Not redeemed");
                });
                data.redeemed.forEach(item=>{
                    let info = item._ret.tickets_redeemed_show ? "<br>Redeemed: "+item._ret.tickets_redeemed:'';
                    let elems = $('#order_info').find('li[data-id="'+encodeURIComponent(item.code)+'"]');
                    elems.css("padding", "5px");
                    elems.css("margin-bottom", "5px");
                    elems.css("background-color", "green");
                    //elems.css("color", "white");
                    elems.append(info);
                });
                displayRedeemedOrderInfo(code, data);
            } else {
                displayRedeemedInfo(code, data);
                $('#ticket_info_btns').append(displayRedeemedTicketsInfo(data));
            }

        }, response=>{
            clearAreas();
            $("#reader_output").html('');
            updateTicketScannerInfoArea('<h1 style="color:red !important;">'+response.data+'</h3>');

            showScanNextTicketButton();

            system.INPUTFIELD.focus();
            system.INPUTFIELD.select();
        });
    }
    function displayOrderTicketInfo(data) {
        let div = $('<div>').css('padding', '10px');

        div.html('<h3 style="text-align:center;color:black !important;">Order Ticket</h3>');
        div.append($('<div style="text-align:center;">').html(data.order_infos.code));
        div.append("<b>Includes: </b>"+data.order_infos.products.length+' Products, ');
        div.append(data.ticket_infos.length+' Tickets<br>');
        div.append('<b>Order ID: </b>#'+data.order_infos.id+'<br>');
        div.append('<b>Created: </b>'+data.order_infos.date_created+'<br>');
        div.append('<b>Completed: </b>'+data.order_infos.date_completed+'<br>');
        div.append('<b>Paid: </b>'+data.order_infos.date_paid+'<br>');
        div.append($('<button class="button-ticket-options button-primary">').html("Redeem Complete Order").on("click", e=>{
            redeemTicket(data.order_infos.code);
        }));
        let div_tickets = $('<div style="padding-top:15px;text-align:left;">');
        for (let pidx=0;pidx<data.order_infos.products.length;pidx++) {
            let product = data.order_infos.products[pidx];
            div_tickets.append("<b>"+product.product_name
                +(product.product_name_variant != "" ? " - "
                +product.product_name_variant : "")
                +'</b>');
            let ol = $('<ol style="padding-top:5px;">');
            for (let idx=0;idx<data.ticket_infos.length;idx++) {
                let item = data.ticket_infos[idx];
                if (item.product_id == product.product_id && item.product_parent_id == product.product_parent_id) {
                    let li = $('<li data-id="'+encodeURIComponent(item.code_display)+'" style="padding-bottom:10px;">');
                    let extra_content = item.code_display+'<br>';
                    if (item.name_per_ticket != "" || item.value_per_ticket != "") {
                        extra_content += item.name_per_ticket+" "+item.value_per_ticket;
                    } else {
                        extra_content += "No name or value on ticket set";
                    }
                    if (item.location) {
                        extra_content += "<br>"+item.location;
                    }
                    if (item.ticket_date) {
                        extra_content += "<br>"+item.ticket_date;
                    }
                    li.append(extra_content+'<br>')
                        .append($('<button style="color:white;border-color:#008CBA;background-color:#008CBA;">').html("Retrieve ticket").on("click", e=>{
                            retrieveTicket(item.code_public, true); // do not redeem automatically
                        }))
                        .append($('<button style="color:white;border-color:red;background-color:red;">').html("Redeem ticket").on("click", e=>{
                            redeemTicket(item.code_public);
                        }))
                        .appendTo(ol);
                }
            }
            ol.appendTo(div_tickets);
        }
        div_tickets.appendTo(div);

        div_order_info_area = $('#order_info').html(div);
    }
    function displayTicketInfo(data) {
        let codeObj = data;
        let metaObj = data.metaObj;
        let ret = data._ret;
        let div = $('<div>').css('padding', '10px');
        let border_color = 'green';
        if (isTicketExpired(data._ret)) {
            border_color = 'orange';
        }
        if (metaObj['wc_ticket']['redeemed_date'] != "") {
            border_color = 'red';
        }
        div.css("border", "1px solid "+border_color);

        $('<h3 style="color:black !important;text-align:center;">').html(ret.ticket_heading).appendTo(div);
        $('<h4 style="color:black !important;">').html(ret.ticket_title).appendTo(div);
        if (ret.ticket_sub_title != "") {
            $('<p>').html(ret.ticket_sub_title).appendTo(div);
        }
        $('<p>').html(ret.ticket_date_as_string).appendTo(div);
        if (ret.ticket_location != "") {
            $('<p>').html(ret.ticket_location_label+' '+ret.ticket_location).appendTo(div);
        }
        if (ret.short_desc != "") {
            div.append(ret.short_desc).append('<br>');
        }
        if (ret.ticket_info != "") {
            $('<p>').html(ret.ticket_info).appendTo(div);
        }
        if (ret.cst_label != "") {
            $('<p>').html('<b>'+ret.cst_label+'</b><br>'+ret.cst_billing_address+'<br>').appendTo(div);
        }
        if (ret.payment_label != "") {
            let date_order_paid = ret.payment_paid_at;
            let date_order_complete = null;
            if (ret.payment_completed_at !== "undefined") {
                date_order_complete = ret.payment_completed_at;
            }
            let p = $('<p>').appendTo(div);
            p.append('<b>'+ret.payment_label+'</b><br>');
            p.append("Order status: "+ret.order_status+"<br>");
            p.append(ret.payment_paid_at_label+' ');
            p.append('<b>'+date_order_paid+'</b><br>');
            if (date_order_complete != null) {
                p.append(ret.payment_completed_at_label+' ');
                p.append('<b>'+date_order_complete+'</b><br>');
            }
            p.append(ret.payment_method_label);
            if (ret.payment_method != "") {
                p.append(' '+ret.payment_method+' '+ret.payment_trx_id);
            }
            p.append('<br>');
            if (ret.coupon != "") {
                p.append(ret.coupon_label+' <b>'+ret.coupon+'</b><br>');
            }
        }
        if (metaObj.wc_ticket.name_per_ticket != "") {
            $('<p>').html(ret.name_per_ticket_label + " " +metaObj.wc_ticket.name_per_ticket).appendTo(div);
        }
        if (metaObj.wc_ticket.value_per_ticket != "") {
            $('<p>').html(ret.value_per_ticket_label + " " +metaObj.wc_ticket.value_per_ticket).appendTo(div);
        }
        if (ret.ticket_amount_label != "") {
            $('<p>').html(ret.ticket_amount_label).appendTo(div);
        }
        let p = $('<p>').html(ret.ticket_label+' <b>'+codeObj['code_display']+'</b><br>').appendTo(div);
        p.append(ret.paid_price_label+' <b>'+ret.paid_price_as_string+'</b>');
        if (ret.product_price != ret.paid_price) {
            p.append(' <b>('+ret.product_price_label+' '+ret.product_price_as_string+')</b>');
        }
        $('<p style="text-align:center;">').html(system.code).appendTo(div);

        div_ticket_info_area = $('#ticket_info').html(div);
        displayTicketInfoButtons(data);
    }
    function displayTicketInfoButtons(data) {
        let div = $('<div>').css('text-align', 'center');
        if (!data._ret.is_paid) {
            $('<h4 style="color:red !important;">').html(sprintf(/* translators: %s: order status */__('Ticket is NOT paid (%s).', 'event-tickets-with-ticket-scanner'), data._ret.order_status)).appendTo(div);
        }
        $('<button class="button-ticket-options">').html(_x('Reload', 'label', 'event-tickets-with-ticket-scanner')).appendTo(div).on('click', e=>{
            retrieveTicket(system.code, true);
        });
        let btn_redeem = $('<button class="button-ticket-options">').html(_x('Redeem Ticket', 'label', 'event-tickets-with-ticket-scanner')).css("background-color", 'gray').css('color', 'white').prop("disabled", true).appendTo(div).on('click', e=>{
            redeemTicket(system.code);
        });
        if (ticket_scanner_operating_option.ticketScannerDontShowBtnPDF == false) {
            $('<button class="button-ticket-options">').html(_x('PDF', 'label', 'event-tickets-with-ticket-scanner')).appendTo(div).on('click', e=>{
                window.open(data.metaObj['wc_ticket']['_url']+'?pdf', '_blank');
            });
        }
        if (ticket_scanner_operating_option.ticketScannerDontShowBtnBadge == false) {
            $('<button class="button-ticket-options">').html(_x('Badge', 'label', 'event-tickets-with-ticket-scanner')).appendTo(div).on('click', e=>{
                _downloadFile('downloadPDFTicketBadge', {'code':data.code}, "eventticket_badge_"+data.code+".pdf");
                return false;
            });
        }
        let allow_redeem = false;
        if (data._ret.allow_redeem_only_paid) {
            if (data._ret.is_paid) {
                allow_redeem = true;
            }
        } else {
            allow_redeem = true;
        }
        if (allow_redeem) {
            if (data.metaObj['wc_ticket']['redeemed_date'] != "") {
                allow_redeem = false;
            }
            if (data._ret.max_redeem_amount > 1 && data.metaObj.wc_ticket.stats_redeemed.length < data._ret.max_redeem_amount) {
                allow_redeem = true;
            }
            if (allow_redeem) {
                allow_redeem = canTicketBeRedeemedNow(data);
            }
        }
        if (allow_redeem) {
            btn_redeem.prop("disabled", false).css('background-color','green');
        }

        div.append(displayRedeemedTicketsInfo(data));
        div.append(displayTimezoneInformation(data));
        $('#ticket_info_btns').html(div);
    }
    function displayRedeemedTicketsInfo(data) {
        if (!data._ret.tickets_redeemed_show) return "";
        let div = $('<div style="padding:10px;">').css('text-align', 'center');
        $('<h5 style="color:black !important">').html(sprintf(/* translators: %d: amount redeemed tickets */__('%d tickets of this event (product) redeemed already', 'event-tickets-with-ticket-scanner'), data._ret.tickets_redeemed)).appendTo(div);
        return div;
    }
    function updateTicketScannerInfoArea(content) {
        $('#ticket_scanner_info_area').html(content);
        if (toBool(myAjax.ticketScannerDisplayTimes)) {
            let data = system.data;
            if (data != null && typeof data != "undefined" && typeof data._ret != "undefined" && typeof data._ret._server != "undefined") {
                let div = $('<div style="padding-top:30px;">');
                div.append("Server: "+data._ret._server.time+" "+data._ret._server.timezone.timezone+" Offset: "+data._ret._server.timezone.timezone+"<br>");
                let date = new Date();
                div.append("Local: "+date+"<br>");
                system.TIMEAREA.html(div);
            }
        }
    }
    function displayTimezoneInformation(data) {
        let div = $('<div>').css('text-align', 'center');
        if (typeof system.PARA.displaytime !== "undefined") {
            div.css("padding", "10px;");
            //console.log(data);
            div.append("Ticket start timestamp: "+(data._ret.ticket_start_date_timestamp*1000)+"<br>");
            let d_t_s = new Date(data._ret.ticket_start_date_timestamp*1000);
            div.append("Ticket start timestamp date: "+d_t_s+"<br>");

            div.append("Ticket end timestamp: "+(data._ret.ticket_end_date_timestamp*1000)+"<br>");
            let d_t_e = new Date(data._ret.ticket_end_date_timestamp*1000);
            div.append("Ticket end timestamp date: "+d_t_e+"<br>");

            if (typeof data._ret._server !== "undefined") {
                try {
                    div.append("Server timezone: "+data._ret._server.timezone.timezone+" Offset: "+data._ret._server.timezone.timezone+"<br>");
                    div.append("Server time: "+data._ret._server.time+"<br>");
                    div.append("UTC time: "+data._ret._server.UTC_time+"<br>");
                    if (typeof data._ret.is_date_set != "undefined" && data._ret.is_date_set) {
                        let date = new Date(data._ret.redeem_allowed_from);
                        div.append("Redeem allowed from: "+date+"<br>");
                        date = new Date(data._ret.redeem_allowed_until);
                        div.append("Redeem allowed until: "+date+"<br>");
                    }
                } catch(e) {
                    //console.log(e);
                }
            }

            let d_ts_n = new Date();
            div.append("Ticket scanner browser now-date: "+d_ts_n+"<br>");
        }
        return div;
    }
    function addInputField() {
        let div = $('<div>').css('text-align', 'center');
        $('<label for="barcode_scanner_input" class="form-label" style="color:#837878">').html(__('For QR code barcode scanner', 'event-tickets-with-ticket-scanner')).appendTo(div);
        $('<br>').appendTo(div);
        let inputField = $('<input style="width:70%;" name="barcode_scanner_input" placeholder="'+_x('Type in the ticket number and hit ENTER (optional to scanning)', 'attr', 'event-tickets-with-ticket-scanner')+'" type="text">')
            .appendTo(div)
            .on("change", ()=>{
                clearOrderInfos();
                retrieveTicket(inputField.val().trim(), false, ()=>{
                    inputField.focus();
                    inputField.select();
                });
            })
            .on("keypress", event=>{
                if (event.key === "Enter") {
                    event.preventDefault();
                    clearOrderInfos();
                    retrieveTicket(inputField.val().trim(), false, ()=>{
                        inputField.focus();
                        inputField.select();
                    });
                }
            });
        system.INPUTFIELD = div;
    }
    function addRemoveAuthTokenButton() {
        let div = $('<div style="padding-top:10px;">').css('text-align', 'center');
        $('<button style="background-color:red;color:white;">')
            .html("Remove Auth Token")
            .appendTo(div)
            .on("click", e=>{
                if (confirm("Do you want to delete the auth token?")) {
                    setAuthToken();
                    showScanOptions();
                }
            });
        system.AUTHTOKENREMOVEBUTTON = div;
    }
    function addClearCamDeviceButton() {
        let btn = $('<button>').html("Clear the stored cam device").on("click", event=>{
            _storeValue("ticketScannerCameraId", "", 1);
            window.location.reload(true);
        });
        if (!system.ADDITIONBUTTONS) system.ADDITIONBUTTONS = $('<div style="text-align:center;margin-top:20px;">');
        system.ADDITIONBUTTONS.append(btn);
    }
    function initStyle() {
        document.getElementsByClassName('ticket_content')[0].style.borderRadius="12px";
        let content = '';
        content += 'button.button-ticket-options {width:90%;margin-left:auto;margin-right:auto;margin-bottom:15px;display:block;border-radius:12px;padding:10px 15px;text-align:center;}';
        content += 'button.button-primary {background-color:#008CBA;color:white;border-color:#008CBA;}';
        content += '@media screen and (min-width: 720px) { button.button-ticket-options{width:50%;} }';
        addStyleCode(content);
    }
    function starten() {
        $ = jQuery;
        initStyle();
        addMetaTag("viewport", "width=device-width, initial-scale=1");
        $('#reader').html(_getSpinnerHTML());
        _makeGet('ping', [], data=>{
            system.data = data; // initialer daten empfang mit options
            system.img_pfad = data.img_pfad;
            system.PARA = basics_ermittelURLParameter();

            if (toBool(myAjax.ticketScannerDontShowOptionControls)) {
                setRedeemImmediately(toBool(myAjax.ticketScannerScanAndRedeemImmediately));
                setDistractFree(toBool(myAjax.ticketScannerHideTicketInformation));
                setStartCamWithoutButtonClicked(toBool(myAjax.ticketScannerStartCamWithoutButtonClicked));
            } else {
                if (system.PARA.redeemauto || _loadValue("ticket_scanner_operating_option.redeem_auto") == "1" || setRedeemImmediately(toBool(myAjax.ticketScannerScanAndRedeemImmediately))) {
                    setRedeemImmediately(true);
                }
                if (system.PARA.distractfree || _loadValue("ticket_scanner_operating_option.distract_free") == "1" || toBool(myAjax.ticketScannerHideTicketInformation)) {
                    setDistractFree(true);
                }
                if (system.PARA.startcam || _loadValue("ticket_scanner_operating_option.ticketScannerStartCamWithoutButtonClicked") == "1" || toBool(myAjax.ticketScannerStartCamWithoutButtonClicked)) {
                    setStartCamWithoutButtonClicked(true);
                }
            }

            initAuthToken();
            addInputField();
            addClearCamDeviceButton();
            addRemoveAuthTokenButton();
            showScanOptions();
            if (system.PARA.code) {
                system.code = system.PARA.code;
                retrieveTicket(system.code);
            } else {
                startScanner();
                //showScanNextTicketButton();
            }
        });
    }
    var $;
    //window.onload = starten;
    starten();
} );