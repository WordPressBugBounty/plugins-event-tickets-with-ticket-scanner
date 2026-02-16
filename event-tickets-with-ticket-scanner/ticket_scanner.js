jQuery(document).ready(()=>{
    const { __, _x, _n, sprintf } = wp.i18n;
    let system = {code:0 /* public ticket number */,
                nonce:'', data:null /* retrieved data */, redeemed_successfully:false,
                img_pfad:'',
                last_scanned_ticket:{code:'', timestamp:0, auto_redeem:false, data:null},
                last_nonce_check:0,
                status:'ready' /* ready, retrieved, redeemed */
            };
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
        if (!v) return false;
        if (v == "1") return true;
        if (v == 1) return true;
        if (v.toLowerCase() == "yes") return true;
        return v == true;
    }

    var ticket_scanner_operating_option = {
        redeem_auto: false,
        distract_free: false,
        distract_free_show_short_desc: false,
        speak: false,
        vibrate: false,
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
    function setSpeakCheckbox(value) {
        if (typeof value != "undefined") {
            ticket_scanner_operating_option.speak = value;
        } else {
            ticket_scanner_operating_option.speak = !ticket_scanner_operating_option.speak;
        }
        _storeValue("ticket_scanner_operating_option.speak", ticket_scanner_operating_option.speak ? 1 : 0);
    }
    function setVibrate(value) {
        if (typeof value != "undefined") {
            ticket_scanner_operating_option.vibrate = value;
        } else {
            ticket_scanner_operating_option.vibrate = !ticket_scanner_operating_option.vibrate;
        }
        _storeValue("ticket_scanner_operating_option.vibrate", ticket_scanner_operating_option.vibrate ? 1 : 0);
    }
    function setDistractFreeShowShortDesc(value) {
        if (typeof value != "undefined") {
            ticket_scanner_operating_option.distract_free_show_short_desc = value;
        } else {
            ticket_scanner_operating_option.distract_free_show_short_desc = !ticket_scanner_operating_option.distract_free_show_short_desc;
        }
        _storeValue("ticket_scanner_operating_option.distract_free_show_short_desc", ticket_scanner_operating_option.distract_free_show_short_desc ? 1 : 0);
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
        //if (decodedText) decodedText = decodedText.trim();
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
                updateTicketScannerInfoArea('<h1 style="color:green !important;">'+__("Auth Token Set", 'event-tickets-with-ticket-scanner')+'</h3>');
                window.setTimeout(()=>{
                    showScanNextTicketButton();
                }, 350);
            } else {
                renderInfoBox(__("Scan error", 'event-tickets-with-ticket-scanner'), __("QR code content unknown. Can not extract data correctly. Please try a QR code of a ticket.", 'event-tickets-with-ticket-scanner'), showScanNextTicketButton);
            }
        } else {
            /*
            // not working with QRScanner? or the other scanner. Somehow content is not recognized correctly or not send. maybe a config value to be set. Because with text in it, the scanner is returning an empty string
            // extract the public ticket number from the token. format is CRC32(TIMESTAMP)-ORDERID-TICKETNUMBER.
            // the public ticket number can be part of text in the qr code, so we need to extract it.
            if (decodedText.length > 12) {
                debugger;
                // format: NUMBER-NUMBER-TICKETNUMBER , TICKETNUMBER can be text and numbers
                // example: 2523448324-671-ticket_2025052808_dc_XYJBSSAZGZBHENY
                reg = /\b\d+-\d+-[A-Za-z0-9_]+\b/g;
                console.log("decodedText: "+decodedText);
                let matches = decodedText.match(reg);
                if (matches && matches.length > 3) {
                    decodedText = matches[0]; // the ticket number is the third match
                }
                console.log("extracted ticket number from QR code: "+decodedText);
                retrieveTicket(decodedText);
            } else {
                if (decodedText != "") {
                    renderInfoBox(__("Scan error", 'event-tickets-with-ticket-scanner'), "Cannot find the public ticket number in the QR code. Please try a QR code of a ticket.", showScanNextTicketButton);
                }
            }
            */
            if (decodedText != "") {
                retrieveTicket(decodedText);
            } else {
                renderInfoBox(__("Scan error", 'event-tickets-with-ticket-scanner'), __("Cannot find the public ticket number in the QR code. Please try a QR code of a ticket.", 'event-tickets-with-ticket-scanner'), showScanNextTicketButton);
            }
        }

        if (html5QrcodeScanner != null) {
            window.setTimeout(()=>{
                    html5QrcodeScanner.clear().then((ignore) => {
                        // QR Code scanning is stopped.
                        // reload the page with the ticket info and redeem button
                        //console.log("stop success");
                    }).catch((err) => {
                        // Stop failed, handle it.
                        //console.log("stop failed");
                    });
            }, 250);
        }
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

            let btn_start = $('<button class="button-ticket-options button-primary" style="display:none;">').text(__("Start Camera", 'event-tickets-with-ticket-scanner')).appendTo($('#reader')).on("click", event=>{
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
            if (system.status == "retrieved") {
                let btn_redeem = $('<button class="button-ticket-options">').html(_x('Redeem Ticket', 'label', 'event-tickets-with-ticket-scanner')).css("background-color", 'gray').css('color', 'white').prop("disabled", true).on('click', e=>{
                    redeemTicket(system.code);
                }).appendTo(btngrp);
                if (canTicketBeRedeemed(system.last_scanned_ticket.data)) {
                    btn_redeem.prop("disabled", false).css('background-color','green');
                }
            }
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
            let chkbox_speak = $('<input type="checkbox">').on("click", e=> {
                setSpeakCheckbox();
            }).appendTo(div);
            if (ticket_scanner_operating_option.speak) chkbox_speak.prop("checked", true);
            div.append(' '+__("Speak out loud redeem operation (BETA)", 'event-tickets-with-ticket-scanner'));
            div.append("<br>");

            let chkbox_vibrate = $('<input type="checkbox">').on("click", e=> {
                setVibrate();
            }).appendTo(div);
            if (ticket_scanner_operating_option.vibrate) chkbox_vibrate.prop("checked", true);
            div.append(' '+__("Vibrate on scan result", 'event-tickets-with-ticket-scanner'));
            div.append("<br>");

            let chkbox_redeem_imediately = $('<input type="checkbox">').on("click", e=>{
                setRedeemImmediately();
            }).appendTo(div);
            if (ticket_scanner_operating_option.redeem_auto) chkbox_redeem_imediately.prop("checked", true);
            div.append(' '+__("Scan and Redeem immediately", 'event-tickets-with-ticket-scanner'));
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
            div.append(' '+__("Hide ticket information", 'event-tickets-with-ticket-scanner'));
            div.append("<br>");

            let chkbox_distractfree_showshortdesc = $('<input type="checkbox">').on("click", e=>{
                setDistractFreeShowShortDesc();
                if (system.status == "retrieved") {
                    displayTicketRetrievedInfo(system.last_scanned_ticket.data);
                } else if (system.status == "redeemed") {
                    displayTicketRedeemedInfo(system.data);
                }
            }).appendTo(div);
            if (ticket_scanner_operating_option.distract_free_show_short_desc) chkbox_distractfree_showshortdesc.prop("checked", true);
            div.append(' '+__("Display short description if ticket information is hidden", 'event-tickets-with-ticket-scanner'));
            div.append("<br>");

            let chkbox_ticketScannerStartCamWithoutButtonClicked = $('<input type="checkbox">').on("click", e=>{
                setStartCamWithoutButtonClicked(!ticket_scanner_operating_option.ticketScannerStartCamWithoutButtonClicked);
            }).appendTo(div);
            chkbox_ticketScannerStartCamWithoutButtonClicked.prop("checked", ticket_scanner_operating_option.ticketScannerStartCamWithoutButtonClicked);
            div.append(' '+__("Start cam to scan next ticket immediately", 'event-tickets-with-ticket-scanner'));
            div.append("<br>");

            $('<input type="checkbox">').on("click", e=>{
                window.location.href = "?code="+encodeURIComponent(system.code)
                    + (ticket_scanner_operating_option.redeem_auto ? "&redeemauto=1" : "")
                    + (system.PARA.useoldticketscanner ? "" : "&useoldticketscanner=1");
            }).prop("checked", system.PARA.useoldticketscanner).appendTo(div);
            div.append(' '+__("Use old ticket scanner library - compatibility mode", 'event-tickets-with-ticket-scanner'));
        }

        $('<div style="margin-top:40px;">').append(system.INPUTFIELD).appendTo(div);
        if (typeof ticket_scanner_operating_option.auth == "object") div.append(system.AUTHTOKENREMOVEBUTTON);
        div.append(system.ADDITIONBUTTONS);
        addFullscreenButton(div);
        system.TIMEAREA = $('<div>');
        div.append(system.TIMEAREA);
        $('#reader_options').html(div);
    }

    function addFullscreenButton(container) {
        var el = document.documentElement;
        if (!el.requestFullscreen && !el.webkitRequestFullscreen) return;
        var btn = $('<button class="button-ticket-options">').html(__("Fullscreen", 'event-tickets-with-ticket-scanner'));
        btn.on("click", function() {
            if (!document.fullscreenElement && !document.webkitFullscreenElement) {
                if (el.requestFullscreen) el.requestFullscreen();
                else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
            } else {
                if (document.exitFullscreen) document.exitFullscreen();
                else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
            }
        });
        var onChange = function() {
            var isFs = !!(document.fullscreenElement || document.webkitFullscreenElement);
            btn.html(isFs ? __("Exit Fullscreen", 'event-tickets-with-ticket-scanner') : __("Fullscreen", 'event-tickets-with-ticket-scanner'));
        };
        document.addEventListener('fullscreenchange', onChange);
        document.addEventListener('webkitfullscreenchange', onChange);
        container.append(btn);
    }

    function vibrateOnResult(success) {
        if (navigator.vibrate && ticket_scanner_operating_option.vibrate) {
            navigator.vibrate(success ? [200] : [100, 50, 100, 50, 100]);
        }
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

        // Pass through debug parameter if set in URL
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('VollstartValidatorDebug')) {
            _data['VollstartValidatorDebug'] = urlParams.get('VollstartValidatorDebug') || '1';
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
        //console.log(call_data);
        $.get( call_data.url, call_data.data, response=>{
            if (typeof response == "string") {
				response = JSON.parse(response);
			}
            if (response && response.data && response.data.nonce) {
                system.last_nonce_check = new Date().getTime();
                system.nonce = response.data.nonce;
            }
            if (!response.success) {
                if (ecbf) ecbf(response);
                else {
                    let msg = (typeof response.data !== "undefined" && response.data.status ? response.data.status : '') + " " + (response.data.message ? response.data.message : '');
                    renderFatalError(msg.trim());
                }
            } else {
                cbf && cbf(response.data);
            }
        }, "json").always(jqXHR=>{
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
            if (typeof response == "string") {
				response = JSON.parse(response);
			}
            if (response && response.data && response.data.nonce) {
                system.last_nonce_check = new Date().getTime();
                system.nonce = response.data.nonce;
            }
            if (!response.success) {
                if (ecbf) ecbf(response);
                else {
                    let msg = (response.data.status ? response.data.status : '') + " " + (response.data.message ? response.data.message : '');
                    renderFatalError(msg.trim());
                }
            } else {
                cbf && cbf(response.data);
            }
        }, "json").always(jqXHR=>{
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
    function _getSeatInfoHtml(obj) {
        if (!obj.seat_label || obj.seat_label == "") {
            return '';
        }
        let seatText = '';
        if (obj.seat_label_text && obj.seat_label_text != '') {
            seatText = obj.seat_label_text + ": ";
        }
        seatText += "<b>" + obj.seat_label;
        if (obj.seat_category && obj.seat_category != "") {
            seatText += " (" + obj.seat_category + ")";
        }
        seatText += "</b>";
        if (obj.seating_plan_name && obj.seating_plan_name != "") {
            seatText += " - " + obj.seating_plan_name;
        }
        if (obj.seat_desc && obj.seat_desc != "") {
            seatText += "<br><small>" + obj.seat_desc + "</small>";
        }
        return seatText;
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
			millisek = time(timezone_id) * 1000;
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
				'n':d.getMonth()+1,'Y':d.getFullYear(),'y':d.getYear()>100?d.getYear().toString().substring(d.getYear().toString().length-2):d.getYear(),
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
    function renderInfoBox(title, content, cbf) {
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
                if (cbf) cbf();
            }}]
        };
        if (typeof content !== "string") content = JSON.stringify(content);
        let dlg = $('<div/>').html(content);
        dlg.dialog(_options);
        return dlg;
    }
    function renderFatalError(content, cbf) {
        return renderInfoBox('Error', content, cbf);
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
                    var key = teile[a].substring(0,pos);
                    parawerte[key] = decodeURIComponent(teile[a].substring(pos+1));
                }
            }
        }
        return parawerte;
    }
    function speakOutLoud(v, display) {
		if ('speechSynthesis' in window) {
			var t = typeof v === 'object' ? 'Value is an object.' : v;
			if (t.trim() == "") t = 'Value is empty';
			var msg = new SpeechSynthesisUtterance(t);
			msg.lang = "en-US";
			window.speechSynthesis.speak(msg);
			if (display) console.log("Speak:", v);
		} else {
			console.log(v);
		}
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
            if (code.substring(0,5).toLowerCase() == "https") {
                if (code.substring(0,8).toLowerCase() == "https://" || (code.length > 14 && code.substring(0,14).toLowerCase() == "https%3A%2F%2F")) {
                    // extract code from URL
                    let url = code;
                    let pos = url.lastIndexOf("code=");
                    if (pos > 0) {
                        code = url.substring(pos + 5);
                    } else {
                        pos = url.toLowerCase().lastIndexOf("code%3d");
                        if (pos > 0) {
                            code = url.substring(pos + 7);
                        }
                    }
                }
            }
        }
        if (code == "") {
            alert(__("no code found", 'event-tickets-with-ticket-scanner'));
            showScanNextTicketButton();
            return;
        }

        // check if the code is an order, then transform it to ordertickets
        if (code.startsWith("order-")) {
            code = "ordertickets-" + code.substring(6);
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
            system.status = "retrieved";
            system.data = data;
            system.last_scanned_ticket.data = data;
            system.code = code; // falls per code überschrieben wurde

            if (typeof data.order_infos !== "undefined" && data.order_infos.is_order_ticket) {
                system.format_datetime = data.option_displayDateTimeFormat;
                system.format_date = data.option_displayDateFormat;
                system.format_time = data.option_displayTimeFormat;
                displayOrderTicketInfo(data);
                showScanNextTicketButton();
            } else {
                system.format_datetime = data._ret.option_displayDateTimeFormat;
                system.format_date = data._ret.option_displayDateFormat;
                system.format_time = data._ret.option_displayTimeFormat;
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

            if (ticket_scanner_operating_option.distract_free) {
                // display ticket title and subtitle
                if (typeof data._ret.ticket_title != "undefined" && data._ret.ticket_title != "") {
                    div.append('<h4>'+data._ret.ticket_title+'</h4>');
                }
                if (typeof data._ret.ticket_subtitle != "undefined" && data._ret.ticket_subtitle != "") {
                    div.append('<h5>'+data._ret.ticket_subtitle+'</h5>');
                }

                // display short description and ticket_info
                if (ticket_scanner_operating_option.distract_free_show_short_desc && typeof data._ret.short_desc != "undefined" && data._ret.short_desc != "") {
                    div.append('<div>'+data._ret.short_desc+'</div>');
                }
                if (typeof data._ret.ticket_info != "undefined" && data._ret.ticket_info != "") {
                    //div.append('<div>'+data._ret.ticket_info+'</div>');
                }
                //console.log(data._ret);
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
                        //div.append("<div>Redeem allowed from: <b>"+data._ret.redeem_allowed_from+"</b></div>");
                        div.append('<div style="display: flex;flex-wrap: wrap;flex-direction: column;"><div>Redeem allowed from: </div><div style="font-weight:bold;">'+data._ret.redeem_allowed_from+'</div></div>');
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
        // Seat information
        let seatHtml = _getSeatInfoHtml(data._ret);
        if (seatHtml != '') {
            $('<div>').html(seatHtml).appendTo(div);
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
            let product_name = data._ret.product.name + (data._ret.product.name_variant != "" ? " - "+data._ret.product.name_variant : "");
            div3.css("margin-top", "10px").html(__('<b>Product information</b>', 'event-tickets-with-ticket-scanner'))
                .append('<div>'+sprintf(__('#%s - %s', 'event-tickets-with-ticket-scanner'), data._ret.product.id, product_name)+'</div>');
            if (data._ret.product.sku != "") {
                div3.append('<div>'+sprintf(__('SKU: %s', 'event-tickets-with-ticket-scanner'), data._ret.product.sku)+'</div>');
            }
        }
        let content = "";
        if (ticket_scanner_operating_option.distract_free) {
            content = '<div style="display:flex;text-align:center;flex-wrap: nowrap;flex-direction: row;justify-content: center;flex-basis: auto;">'+system.code+'</div>';
        }
        $('#ticket_add_info').html(content)
            .append( $('<div style="padding-top:10px;width:100%;">').append(div).append(div2) )
            .append(div3);
    }
    function displayRedeemedInfo(code, data) {
        system.status = "redeemed";
        system.redeemed_successfully = data.redeem_successfully;
        vibrateOnResult(data.redeem_successfully);
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
        let t = '';
        // zeige retrieved info an
        let content = $('<div>').html('<div style="display:flex;text-align:center;flex-wrap: nowrap;flex-direction: row;justify-content: center;flex-basis: auto;">'+system.code+'</div>');
        if (system.redeemed_successfully) {
            content.append('<h3 style="color:green !important;text-align:center;">'+__('Redeemed', 'event-tickets-with-ticket-scanner')+'</h3>');
            //content.append('<p style="text-align:center;color:green"><img src="'+system.img_pfad+'button_ok.png"><br><b>'+__('Successfully redeemed', 'event-tickets-with-ticket-scanner')+'</b></p>');
            content.append('<p style="text-align:center;color:green"><img src="'+system.img_pfad+'button_ok.png"></p>');
            t = 'Redeemed';
        } else {
            content.append('<h3 style="color:red !important;text-align:center;">'+__('NOT REDEEMED - see reason below', 'event-tickets-with-ticket-scanner')+'</h3>');
            //content.append('<p style="text-align:center;color:red;"><img src="'+system.img_pfad+'button_cancel.png"><br><b>'+__('Failed to redeem', 'event-tickets-with-ticket-scanner')+'</b></p>');
            content.append('<p style="text-align:center;color:red;"><img src="'+system.img_pfad+'button_cancel.png"></p>');
            t = 'Not redeemed';
        }
        if (typeof system.last_scanned_ticket.data != null && system.last_scanned_ticket.data._ret && system.last_scanned_ticket.data._ret.ticket_title && system.last_scanned_ticket.data._ret.ticket_title != "") {
            content.append('<h4 style="text-align:center;">'+system.last_scanned_ticket.data._ret.ticket_title+'</h4>');
        }
        if (typeof system.last_scanned_ticket.data._ret.ticket_subtitle != "undefined" && system.last_scanned_ticket.data._ret && system.last_scanned_ticket.data._ret.ticket_subtitle && system.last_scanned_ticket.data._ret.ticket_subtitle != "") {
            content.append('<h5 style="text-align:center;">'+system.last_scanned_ticket.data._ret.ticket_subtitle+'</h5>');
        }
        if (ticket_scanner_operating_option.distract_free_show_short_desc && typeof system.last_scanned_ticket.data._ret.short_desc != "undefined" && system.last_scanned_ticket.data._ret.short_desc != "") {
            content.append('<div>'+system.last_scanned_ticket.data._ret.short_desc+'</div>');
        }

        if (typeof system.last_scanned_ticket.data != null && system.last_scanned_ticket.data.metaObj && system.last_scanned_ticket.data.metaObj.wc_ticket && system.last_scanned_ticket.data.metaObj.wc_ticket.redeemed_date && system.last_scanned_ticket.data.metaObj.wc_ticket.redeemed_date != "") {
            content.append('<div style="text-align:center;">'+system.last_scanned_ticket.data._ret.redeemed_date_label+' '+system.last_scanned_ticket.data.metaObj.wc_ticket.redeemed_date+'</div>');
        }
        speakText(t, 'en-EN');
        showScanNextTicketButton();
        updateTicketScannerInfoArea(content);
    }
    function displayRedeemedOrderInfo(code, data) {
        vibrateOnResult(data.errors.length === 0);
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

        div.html('<h3 style="text-align:center;color:black !important;">'+_x("Order Ticket", 'label', 'event-tickets-with-ticket-scanner')+'</h3>');
        div.append($('<div style="text-align:center;">').html(data.order_infos.code));
        div.append("<b>"+_x("Includes", 'label', 'event-tickets-with-ticket-scanner')+": </b>"+sprintf(__('%s Products, %s Tickets', 'event-tickets-with-ticket-scanner'), data.order_infos.products.length, data.ticket_infos.length)+'<br>');
        div.append('<b>'+_x("Order ID", 'label', 'event-tickets-with-ticket-scanner')+': </b>#'+data.order_infos.id+'<br>');
        div.append('<b>'+_x("Created", 'label', 'event-tickets-with-ticket-scanner')+': </b>'+data.order_infos.date_created+'<br>');
        div.append('<b>'+_x("Completed", 'label', 'event-tickets-with-ticket-scanner')+': </b>'+data.order_infos.date_completed+'<br>');
        div.append('<b>'+_x("Paid", 'label', 'event-tickets-with-ticket-scanner')+': </b>'+data.order_infos.date_paid+'<br>');
        div.append($('<button class="button-ticket-options button-primary">').html(_x("Redeem Complete Order", 'label', 'event-tickets-with-ticket-scanner')).on("click", e=>{
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
                    // Seat information
                    let itemSeatHtml = _getSeatInfoHtml(item);
                    if (itemSeatHtml != '') {
                        extra_content += "<br>" + itemSeatHtml;
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
        $('<h4 style="color:black !important;margin-bottom:0;">').html(ret.ticket_title).appendTo(div);
        /* // ?? is the same like ret.ticiet_sub_title
        if (data._ret.product.name_variant != "") {
            $('<h5 style="color:black !important;margin-top:0;padding-top:0;">').html(data._ret.product.name_variant).appendTo(div);
        }
        */
        if (ret.ticket_sub_title != "") {
            $('<h5 style="color:black !important;margin-top:0;padding-top:0;">').html(ret.ticket_sub_title).appendTo(div);
        }
        $('<p>').html(ret.ticket_date_as_string).appendTo(div);
        if (ret.ticket_location != "") {
            $('<p>').html(ret.ticket_location_label+' '+ret.ticket_location).appendTo(div);
        }
        // Seat information - display prominently after location
        let seatHtml = _getSeatInfoHtml(ret);
        if (seatHtml != '') {
            $('<p>').html(seatHtml).appendTo(div);
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
    function canTicketBeRedeemed(data) {
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
        return allow_redeem;
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
        // Venue Image button - show if venue image exists (for all plan types)
        if (data._ret.seating_plan_show_venue_button && data._ret.seat_id > 0) {
            $('<button class="button-ticket-options btn-venue-image">').html(_x('Venue Image', 'label', 'event-tickets-with-ticket-scanner')).appendTo(div).on('click', e=>{
                showVenueImageModal(data);
                return false;
            });
        }
        // Visual Seating Plan button - show only for visual plans (lazy loaded)
        if (data._ret.seating_plan_show_visual_button && data._ret.seat_id > 0) {
            $('<button class="button-ticket-options btn-seating-plan">').html(_x('Seating Plan', 'label', 'event-tickets-with-ticket-scanner')).appendTo(div).on('click', e=>{
                loadAndShowSeatingPlan(data._ret.seating_plan_id, data._ret.seat_id, data._ret);
                return false;
            });
        }

        if (canTicketBeRedeemed(data)) {
            btn_redeem.prop("disabled", false).css('background-color','green');
        }

        div.append(displayRedeemedTicketsInfo(data));
        div.append(displayTimezoneInformation(data));
        $('#ticket_info_btns').html(div);
    }
    // Helper: escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Build SVG seating map (same approach as seating_frontend.js)
    function buildSeatingPlanSvg(planData, currentSeatId) {
        let meta = planData.meta || {};
        let width = meta.canvas_width || 800;
        let height = meta.canvas_height || 600;
        let bgColor = meta.background_color || '#ffffff';
        let bgImage = meta.background_image || planData.planImage || '';

        let svg = '<svg class="saso-seat-map-readonly" viewBox="0 0 ' + width + ' ' + height + '" style="background-color: ' + bgColor + ';">';

        // Background image
        if (bgImage) {
            svg += '<image href="' + escapeHtml(bgImage) + '" x="0" y="0" width="' + width + '" height="' + height + '" preserveAspectRatio="xMidYMid meet" />';
        }

        // Decorations layer
        (meta.decorations || []).forEach(function(el) {
            svg += buildSvgElement(el);
        });

        // Lines layer
        (meta.lines || []).forEach(function(el) {
            svg += buildSvgElement(el);
        });

        // Labels layer
        (meta.labels || []).forEach(function(el) {
            svg += buildSvgElement(el);
        });

        // Seats layer
        (planData.seats || []).forEach(function(seat) {
            svg += buildSeatSvgElement(seat, currentSeatId);
        });

        svg += '</svg>';
        return svg;
    }

    // Build SVG element (decoration, line, label)
    function buildSvgElement(el) {
        let type = el.type || 'rect';
        let x = parseFloat(el.x) || 0;
        let y = parseFloat(el.y) || 0;
        let fill = el.fill || 'transparent';
        let stroke = el.stroke || 'none';
        let strokeWidth = el.strokeWidth || 1;
        let fillOpacity = el.fillOpacity !== undefined ? (parseFloat(el.fillOpacity) / 100) : 1;
        let strokeOpacity = el.strokeOpacity !== undefined ? (parseFloat(el.strokeOpacity) / 100) : 0;

        let svgEl = '';
        switch (type) {
            case 'rect':
                let rw = parseFloat(el.width) || 50;
                let rh = parseFloat(el.height) || 50;
                let rx = el.rx || 0;
                svgEl = '<rect x="' + x + '" y="' + y + '" width="' + rw + '" height="' + rh + '" rx="' + rx + '" fill="' + fill + '" fill-opacity="' + fillOpacity + '" stroke="' + stroke + '" stroke-opacity="' + strokeOpacity + '" stroke-width="' + (strokeOpacity > 0 ? strokeWidth : 0) + '" />';
                break;
            case 'circle':
                let r = parseFloat(el.r) || 25;
                let cx = x + r;
                let cy = y + r;
                svgEl = '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="' + fill + '" fill-opacity="' + fillOpacity + '" stroke="' + stroke + '" stroke-opacity="' + strokeOpacity + '" stroke-width="' + (strokeOpacity > 0 ? strokeWidth : 0) + '" />';
                break;
            case 'line':
                let x1 = parseFloat(el.x1) || 0;
                let y1 = parseFloat(el.y1) || 0;
                let x2 = parseFloat(el.x2) || 100;
                let y2 = parseFloat(el.y2) || 100;
                let lineOpacity = el.strokeOpacity !== undefined ? (parseFloat(el.strokeOpacity) / 100) : 1;
                svgEl = '<line x1="' + x1 + '" y1="' + y1 + '" x2="' + x2 + '" y2="' + y2 + '" stroke="' + stroke + '" stroke-opacity="' + lineOpacity + '" stroke-width="' + strokeWidth + '" />';
                break;
            case 'text':
                let fontSize = el.fontSize || 14;
                svgEl = '<text x="' + x + '" y="' + y + '" fill="' + fill + '" fill-opacity="' + fillOpacity + '" font-size="' + fontSize + '" font-family="sans-serif">' + escapeHtml(el.text || '') + '</text>';
                break;
            case 'image':
                let iw = el.width || 100;
                let ih = el.height || 100;
                svgEl = '<image href="' + escapeHtml(el.href || '') + '" x="' + x + '" y="' + y + '" width="' + iw + '" height="' + ih + '" opacity="' + fillOpacity + '" />';
                break;
        }
        return svgEl;
    }

    // Build seat SVG element
    function buildSeatSvgElement(seat, currentSeatId) {
        let meta = seat.meta || {};
        let posX = parseFloat(meta.pos_x) || parseFloat(meta.x) || 0;
        let posY = parseFloat(meta.pos_y) || parseFloat(meta.y) || 0;
        let shapeConfig = meta.shape_config || {width: 30, height: 30};
        let shapeType = meta.shape_type || meta.shape || 'rect';
        let seatWidth = parseFloat(shapeConfig.width) || parseFloat(meta.width) || 30;
        let seatHeight = parseFloat(shapeConfig.height) || parseFloat(meta.height) || 30;
        let seatLabel = meta.seat_label || seat.seat_identifier || '';
        let seatColor = meta.color || '#4CAF50';

        let isCurrent = seat.is_current || (String(seat.id) === String(currentSeatId));
        let fillColor = isCurrent ? '#4CAF50' : (seatColor || '#cccccc');
        let opacity = isCurrent ? '1' : '0.4';
        let strokeColor = isCurrent ? '#ff0000' : 'transparent';
        let strokeWidth = isCurrent ? '4' : '0';

        let svgEl = '';
        let textX, textY;

        if (shapeType === 'circle') {
            let r = seatWidth / 2;
            let cx = posX + r;
            let cy = posY + r;
            textX = cx;
            textY = cy;
            svgEl = '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="' + fillColor + '" opacity="' + opacity + '" stroke="' + strokeColor + '" stroke-width="' + strokeWidth + '"' + (isCurrent ? ' class="current-seat"' : '') + ' />';
        } else {
            textX = posX + seatWidth / 2;
            textY = posY + seatHeight / 2;
            svgEl = '<rect x="' + posX + '" y="' + posY + '" width="' + seatWidth + '" height="' + seatHeight + '" rx="3" fill="' + fillColor + '" opacity="' + opacity + '" stroke="' + strokeColor + '" stroke-width="' + strokeWidth + '"' + (isCurrent ? ' class="current-seat"' : '') + ' />';
        }

        // Seat label
        let labelSize = Math.min(seatWidth, seatHeight) * 0.35;
        svgEl += '<text x="' + textX + '" y="' + textY + '" text-anchor="middle" dominant-baseline="middle" fill="' + (isCurrent ? '#fff' : '#333') + '" font-size="' + labelSize + '" font-weight="bold" pointer-events="none">' + escapeHtml(seatLabel) + '</text>';

        return svgEl;
    }

    function showVenueImageModal(data) {
        let ret = data._ret;
        // Create modal overlay
        let overlay = $('<div class="seating-plan-modal-overlay">').on('click', function(e) {
            if (e.target === this) {
                $(this).remove();
            }
        });
        let modal = $('<div class="seating-plan-modal">');

        // Header with close button
        let header = $('<div class="seating-plan-modal-header">');
        $('<h3>').text(ret.seating_plan_name || _x('Venue', 'label', 'event-tickets-with-ticket-scanner')).appendTo(header);
        $('<button class="seating-plan-modal-close">&times;</button>').on('click', function() {
            overlay.remove();
        }).appendTo(header);
        modal.append(header);

        // Content area
        let content = $('<div class="seating-plan-modal-content">');

        // Seat info banner
        let seatBanner = $('<div class="seating-plan-seat-banner">');
        seatBanner.html('<strong>' + (ret.seat_label_text || _x('Seat', 'label', 'event-tickets-with-ticket-scanner')) + ':</strong> ' +
            ret.seat_label + (ret.seat_category ? ' (' + ret.seat_category + ')' : ''));
        content.append(seatBanner);

        // Plan description if available
        if (ret.seating_plan_description) {
            let descDiv = $('<div class="seating-plan-description">').text(ret.seating_plan_description);
            content.append(descDiv);
        }

        // Venue image
        let imgContainer = $('<div class="seating-plan-image-container">');
        let img = $('<img>').attr('src', ret.seating_plan_image_url).attr('alt', ret.seating_plan_name || 'Venue');
        imgContainer.append(img);
        content.append(imgContainer);

        modal.append(content);

        // Footer with close button
        let footer = $('<div class="seating-plan-modal-footer">');
        $('<button class="button-ticket-options">').text(_x('Close', 'label', 'event-tickets-with-ticket-scanner')).on('click', function() {
            overlay.remove();
        }).appendTo(footer);
        modal.append(footer);

        overlay.append(modal);
        $('body').append(overlay);
    }

    // Load seating plan data via REST endpoint and show modal (lazy loading)
    function loadAndShowSeatingPlan(planId, seatId, ticketRet) {
        // Show loading overlay
        let loadingOverlay = $('<div class="seating-plan-modal-overlay">');
        let loadingModal = $('<div class="seating-plan-modal" style="text-align:center;padding:40px;">');
        loadingModal.html('<p>' + __('Loading seating plan...', 'event-tickets-with-ticket-scanner') + '</p>');
        loadingOverlay.append(loadingModal);
        $('body').append(loadingOverlay);

        // Fetch seating plan data via REST endpoint
        _makeGet('seating_plan', {plan_id: planId, seat_id: seatId}, function(data) {
            loadingOverlay.remove();
            if (data) {
                showSeatingPlanModal(data, ticketRet);
            } else {
                alert(_x('Failed to load seating plan', 'label', 'event-tickets-with-ticket-scanner'));
            }
        }, function(response) {
            loadingOverlay.remove();
            alert(_x('Error loading seating plan', 'label', 'event-tickets-with-ticket-scanner'));
        });
    }

    function showSeatingPlanModal(planData, ticketRet) {
        if (!planData) {
            alert(_x('Seating plan data not available', 'label', 'event-tickets-with-ticket-scanner'));
            return;
        }

        // Create modal overlay
        let overlay = $('<div class="seating-plan-modal-overlay">').on('click', function(e) {
            if (e.target === this) {
                $(this).remove();
            }
        });
        let modal = $('<div class="seating-plan-modal seating-plan-modal-large">');

        // Header with close button
        let header = $('<div class="seating-plan-modal-header">');
        $('<h3>').text(planData.planName || _x('Seating Plan', 'label', 'event-tickets-with-ticket-scanner')).appendTo(header);
        $('<button class="seating-plan-modal-close">&times;</button>').on('click', function() {
            overlay.remove();
        }).appendTo(header);
        modal.append(header);

        // Content area with seating plan
        let content = $('<div class="seating-plan-modal-content">');

        // Seat info banner
        let seatBanner = $('<div class="seating-plan-seat-banner">');
        seatBanner.html('<strong>' + (ticketRet.seat_label_text || _x('Seat', 'label', 'event-tickets-with-ticket-scanner')) + ':</strong> ' +
            ticketRet.seat_label + (ticketRet.seat_category ? ' (' + ticketRet.seat_category + ')' : ''));
        content.append(seatBanner);

        // Plan description if available
        if (ticketRet.seating_plan_description) {
            let descDiv = $('<div class="seating-plan-description">').text(ticketRet.seating_plan_description);
            content.append(descDiv);
        }

        // Build SVG seating plan using the same approach as seating_frontend.js
        let canvasContainer = $('<div class="seating-plan-canvas-container">');
        if (planData.seats && planData.seats.length > 0) {
            // Build full SVG with decorations, lines, labels, and seats
            let svgHtml = buildSeatingPlanSvg(planData, planData.currentSeatId);
            canvasContainer.html(svgHtml);
        } else if (planData.planImage) {
            // Fallback: show venue image only
            let imgContainer = $('<div class="seating-plan-image-container">');
            let img = $('<img>').attr('src', planData.planImage).attr('alt', planData.planName || 'Venue');
            imgContainer.append(img);
            canvasContainer.append(imgContainer);
        } else {
            // No visual data available
            canvasContainer.html('<p style="text-align:center;padding:40px;">' +
                _x('No seating plan visualization available.', 'label', 'event-tickets-with-ticket-scanner') + '</p>');
        }

        content.append(canvasContainer);
        modal.append(content);

        // Footer with close button
        let footer = $('<div class="seating-plan-modal-footer">');
        $('<button class="button-ticket-options">').text(_x('Close', 'label', 'event-tickets-with-ticket-scanner')).on('click', function() {
            overlay.remove();
        }).appendTo(footer);
        modal.append(footer);

        overlay.append(modal);
        $('body').append(overlay);
    }
    function displayRedeemedTicketsInfo(data) {
        let div = $('<div>');
        let show = false;
        if (data._ret.tickets_redeemed_show) {
            show = true;
            $('<div style="color:black !important">').html(sprintf(/* translators: %d: amount redeemed tickets */__('%d tickets of this event (product) redeemed already by stats', 'event-tickets-with-ticket-scanner'), data._ret.tickets_redeemed)).appendTo(div);
        }
        if (data._ret.tickets_redeemed_show_c) {
            show = true;
            $('<div style="color:black !important">').html(sprintf(/* translators: %d: amount redeemed tickets */__('%d tickets of this event (product) redeemed already', 'event-tickets-with-ticket-scanner'), data._ret.tickets_redeemed_by_codes)).appendTo(div);
        }
        if (data._ret.tickets_redeemed_show_cn) {
            show = true;
            $('<div style="color:black !important">').html(sprintf(/* translators: %d: amount redeemed tickets */__('%d tickets of this event (product) not redeemed yet', 'event-tickets-with-ticket-scanner'), data._ret.tickets_redeemed_not_by_codes)).appendTo(div);
        }
        if (show) {
            div.css('text-align', 'center');
            div.css("padding-top", "10px");
            return div;
        }
        return '';
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
                        div.append('Redeem allowed from: '+date+'<br>');
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
    function cleanPublicTicketNumber(code) {
        if (code) {
            return code.replace(/'/g, "-").trim();
        }
        return '';
    }
    function addInputField() {
        let div = $('<div>').css('text-align', 'center');
        $('<label for="barcode_scanner_input" class="form-label" style="color:#837878">').html(__('For QR code barcode scanner', 'event-tickets-with-ticket-scanner')).appendTo(div);
        $('<br>').appendTo(div);
        let inputField = $('<input style="width:70%;" name="barcode_scanner_input" placeholder="'+_x('Type in the ticket number and hit ENTER (optional to scanning)', 'attr', 'event-tickets-with-ticket-scanner')+'" type="text">')
            .appendTo(div)
            .on("change", ()=>{
                let code = cleanPublicTicketNumber(inputField.val());
                if (code != "") {
                    clearOrderInfos();
                    retrieveTicket(code, false, ()=>{
                        inputField.focus();
                        inputField.select();
                    });
                }
            })
            .on("keypress", event=>{
                if (event.key === "Enter") {
                    let code = cleanPublicTicketNumber(inputField.val());
                    if (code != "") {
                        event.preventDefault();
                        clearOrderInfos();
                        retrieveTicket(code, false, ()=>{
                            inputField.focus();
                            inputField.select();
                        });
                    }
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
        var tc = myAjax.ticketScannerThemeColor || '#008CBA';
        content += 'button.button-primary {background-color:'+tc+';color:white;border-color:'+tc+';}';
        content += '@media screen and (min-width: 720px) { button.button-ticket-options{width:50%;} }';
        // Seating Plan Modal Styles
        content += '.seating-plan-modal-overlay {position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:10000;display:flex;align-items:center;justify-content:center;padding:10px;box-sizing:border-box;}';
        content += '.seating-plan-modal {background:#fff;border-radius:12px;max-width:95vw;max-height:95vh;width:800px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.3);}';
        content += '.seating-plan-modal-large {width:90vw;max-width:1200px;}';
        content += '.seating-plan-modal-header {display:flex;justify-content:space-between;align-items:center;padding:15px 20px;border-bottom:1px solid #eee;background:#f5f5f5;}';
        content += '.seating-plan-modal-header h3 {margin:0;font-size:1.2em;color:#333;}';
        content += '.seating-plan-modal-close {background:none;border:none;font-size:28px;cursor:pointer;color:#666;padding:0 5px;line-height:1;}';
        content += '.seating-plan-modal-close:hover {color:#000;}';
        content += '.seating-plan-modal-content {flex:1;overflow:auto;padding:15px;}';
        content += '.seating-plan-seat-banner {background:#4CAF50;color:#fff;padding:12px 15px;border-radius:8px;margin-bottom:15px;text-align:center;font-size:1.1em;}';
        content += '.seating-plan-canvas-container {position:relative;background:#f9f9f9;border-radius:8px;overflow:hidden;min-height:200px;}';
        content += '.saso-seat-map-readonly {width:100%;height:auto;display:block;}';
        content += '.saso-seat-map-readonly .current-seat {animation:pulse-seat 1.5s ease-in-out infinite;}';
        content += '@keyframes pulse-seat {0%,100%{stroke-width:4px;} 50%{stroke-width:8px;}}';
        content += '.seating-plan-image-container {position:relative;width:100%;}';
        content += '.seating-plan-image-container img {width:100%;height:auto;display:block;}';
        content += '.seating-plan-seat-marker {position:absolute;transform:translate(-50%,-50%);width:40px;height:40px;background:#4CAF50;border:3px solid #ff0000;border-radius:50%;display:flex;align-items:center;justify-content:center;animation:pulse-marker 1.5s ease-in-out infinite;box-shadow:0 2px 10px rgba(0,0,0,0.3);}';
        content += '.seating-plan-seat-marker span {color:#fff;font-weight:bold;font-size:10px;text-align:center;}';
        content += '@keyframes pulse-marker {0%,100%{transform:translate(-50%,-50%) scale(1);} 50%{transform:translate(-50%,-50%) scale(1.2);}}';
        content += '.seating-plan-modal-footer {padding:15px 20px;border-top:1px solid #eee;text-align:center;}';
        content += '.seating-plan-modal-footer button {width:auto;display:inline-block;padding:10px 30px;}';
        content += '.btn-seating-plan {background:#2196F3 !important;color:#fff !important;}';
        content += '.btn-venue-image {background:#FF9800 !important;color:#fff !important;}';
        content += '.seating-plan-description {background:#f0f0f0;padding:10px 15px;border-radius:6px;margin-bottom:15px;color:#555;font-size:0.95em;}';
        addStyleCode(content);
    }
    function refreshNoncePeriodically() {
        // check if the last check of nonce is older than 4 minutes
        // do a ping to get the new nonce
        setInterval(()=>{
            let last_check = system.last_nonce_check;
            if (last_check == null || last_check == "") {
                last_check = 0;
            }
            let now = new Date().getTime();
            if (now - last_check > 240000) {
                _makeGet('ping', [], data=>{
                });
            }
        }, 60000);
    }
    	
    function speak(text) {
		if (TTS != null) {
			TTS.speak(text);
		}
	}
	/**
	 * tts.js — Robust Web TTS for Ticket Scanner
	 * - No UI. No static strings.
	 * - Optional language override per call; null/undefined → browser language with sensible fallbacks.
	 * - Safe across Chrome/Edge/Safari/Firefox (Web Speech API).
	 * - Handles multiple calls, cancels overlaps, async voice loading, and user-activation policy.
	 */
	function initTTS() {
		const RESULT = Object.freeze({
			OK: "ok",
			UNSUPPORTED: "unsupported",
			NEEDS_ACTIVATION: "needs_activation",
			BUSY: "busy",
			ERROR: "error",
		});

		let voices = [];
		let voicesReady = false;
		let speaking = false;
		let activated = false;

		// ---------- feature & policy helpers ----------
		const hasTTS = () =>
			typeof window !== "undefined" &&
			"speechSynthesis" in window &&
			"SpeechSynthesisUtterance" in window;

		const isUserActivated = () => {
			const ua = navigator.userActivation;
			// Some browsers expose hasBeenActive, others just isActive; either is fine to proceed after a user gesture.
			return !!(ua && (ua.isActive || ua.hasBeenActive));
		};

		const pageOK = () =>
			(typeof document === "undefined" || document.visibilityState === "visible") &&
			(typeof document === "undefined" || !document.hasFocus || document.hasFocus());

		// ---------- language & voices ----------
		function detectLang() {
			const prefs = Array.isArray(navigator.languages) && navigator.languages.length
			? navigator.languages
			: [navigator.language || "en-US"];

			const normalized = prefs
			.filter(Boolean)
			.map(l => l.replace("_", "-"))
			.map(l => (l.length === 2
				? (l === "de" ? "de-DE" : l === "en" ? "en-US" : l)
				: l));

			const fallbacks = ["en-US", "en-GB", "de-DE", "fr-FR", "es-ES", "it-IT"];
			return [...normalized, ...fallbacks].find(Boolean);
		}

		function loadVoices() {
			try {
			voices = window.speechSynthesis.getVoices() || [];
			if (!voices.length) {
				window.speechSynthesis.onvoiceschanged = () => {
				voices = window.speechSynthesis.getVoices() || [];
				voicesReady = true;
				};
			} else {
				voicesReady = true;
			}
			} catch { /* noop */ }
		}

		function pickVoice(langCode) {
			if (!voices || !voices.length) return null;
			// exact match > language prefix match
			return (
			voices.find(v => v.lang === langCode) ||
			voices.find(v => v.lang && v.lang.toLowerCase().startsWith((langCode || "").toLowerCase().slice(0, 2))) ||
			null
			);
		}

		// ---------- activation ----------
		/**
		 * Prime TTS after a user gesture (click/tap). Silent, fast, idempotent.
		 * Call this once from your own UI handler (e.g., "Start scanning").
		 */
		function prime(lang) {
			if (!hasTTS()) return RESULT.UNSUPPORTED;
			loadVoices();
			const chosen = lang ?? detectLang();
			try {
			const u = new SpeechSynthesisUtterance(".");
			u.lang = chosen;
			u.volume = 0; // silent
			u.rate = 1; u.pitch = 1;
			const v = pickVoice(chosen);
			if (v) u.voice = v;
			window.speechSynthesis.cancel();
			window.speechSynthesis.speak(u);
			activated = true;
			return RESULT.OK;
			} catch {
			return RESULT.ERROR;
			}
		}

		// ---------- speak ----------
		/**
		 * Speak a text. Returns a Promise<RESULT>.
		 * @param {string} text
		 * @param {{ lang?: string|null, rate?: number, pitch?: number }} [opts]
		 */
		function speak(text, opts = {}) {
			return new Promise((resolve) => {
			if (!text || typeof text !== "string") return resolve(RESULT.ERROR);
			if (!hasTTS()) return resolve(RESULT.UNSUPPORTED);
			if (!pageOK()) return resolve(RESULT.NEEDS_ACTIVATION);

			// If site hasn’t called prime() under a user gesture, some browsers will block.
			// We surface that cleanly so the host app can call TTS.prime() from a click/tap.
			if (!activated && !isUserActivated()) return resolve(RESULT.NEEDS_ACTIVATION);

			try {
				if (speaking) {
				// cancel current queue/utterance to avoid overlaps
				window.speechSynthesis.cancel();
				speaking = false;
				}

				if (!voicesReady) loadVoices();

				const lang = (opts.lang === null || typeof opts.lang === "undefined")
				? detectLang()
				: (opts.lang || detectLang());

				const u = new SpeechSynthesisUtterance(text);
				u.lang  = lang;
				u.rate  = (typeof opts.rate === "number" && opts.rate > 0) ? opts.rate : 1;
				u.pitch = (typeof opts.pitch === "number" && opts.pitch > 0) ? opts.pitch : 1;

				const v = pickVoice(lang);
				if (v) u.voice = v;

				u.onstart = () => { speaking = true; };
				u.onend   = () => { speaking = false; resolve(RESULT.OK); };
				u.onerror = () => { speaking = false; resolve(RESULT.ERROR); };

				window.speechSynthesis.cancel(); // clear queue
				window.speechSynthesis.speak(u);
			} catch {
				resolve(RESULT.ERROR);
			}
			});
		}

		return { prime, speak, RESULT };
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
                setVibrate(toBool(myAjax.ticketScannerVibrate));
            } else {
                if (system.PARA.redeemauto || _loadValue("ticket_scanner_operating_option.redeem_auto") == "1" || setRedeemImmediately(toBool(myAjax.ticketScannerScanAndRedeemImmediately))) {
                    setRedeemImmediately(true);
                }
                if (system.PARA.distractfree || _loadValue("ticket_scanner_operating_option.distract_free") == "1" || toBool(myAjax.ticketScannerHideTicketInformation)) {
                    setDistractFree(true);
                }
                if (system.PARA.distractfreeshowshortdesc || _loadValue("ticket_scanner_operating_option.distract_free_show_short_desc") == "1" || toBool(myAjax.ticketScannerHideTicketInformationShowShortDesc)) {
                    setDistractFreeShowShortDesc(true);
                }
                if (system.PARA.startcam || _loadValue("ticket_scanner_operating_option.ticketScannerStartCamWithoutButtonClicked") == "1" || toBool(myAjax.ticketScannerStartCamWithoutButtonClicked)) {
                    setStartCamWithoutButtonClicked(true);
                }
                if (system.PARA.speak || _loadValue("ticket_scanner_operating_option.speak") == "1" || toBool(myAjax.ticketScannerSpeakText)) {
                    setSpeakCheckbox(true);
                }
                if (_loadValue("ticket_scanner_operating_option.vibrate") == "1" || toBool(myAjax.ticketScannerVibrate)) {
                    setVibrate(true);
                }
            }

            initAuthToken();
            addInputField();
            addClearCamDeviceButton();
            addRemoveAuthTokenButton();
            showScanOptions();
            refreshNoncePeriodically();

            if (system.PARA.code) {
                system.code = system.PARA.code;
                if (system.code != "") {
                    system.code = cleanPublicTicketNumber(system.code);
                    system.INPUTFIELD.val(system.code);
                    retrieveTicket(system.code);
                }
            } else {
                startScanner();
                //showScanNextTicketButton();
            }

            registerServiceWorker();
        });

    }

    function speakText(text, lang) {
        //console.log(text);
        if (ticket_scanner_operating_option.speak) {
            try {
                if (!('speechSynthesis' in window)) return; // kein TTS support

                // Browser-Sprache als Fallback
                const language = lang || navigator.language || "en-US";

                // Cancel laufende Ausgabe
                window.speechSynthesis.cancel();

                // Neues Utterance erzeugen
                const utter = new SpeechSynthesisUtterance(text);
                utter.lang = language;
                utter.rate = 1;
                utter.pitch = 1;

                // Fehlerbehandlung
                utter.onerror = (e) => console.warn("TTS error:", e);

                // Aussprechen
                window.speechSynthesis.speak(utter);
            } catch (e) {
                console.error("TTS failed:", e);
            }
        }
    }

    function registerServiceWorker() {
        if ('serviceWorker' in navigator && myAjax._pwaSWUrl) {
            var scannerScope = window.location.pathname;
            if (scannerScope.charAt(scannerScope.length - 1) !== '/') scannerScope += '/';

            navigator.serviceWorker.register(myAjax._pwaSWUrl, { scope: scannerScope })
                .then(function(registration) {
                    setInterval(function() { registration.update(); }, 3600000);
                })
                .catch(function() {
                    // Not critical - scanner works without SW
                });
        }
    }

    var $;
    //window.onload = starten;
    starten();
} );