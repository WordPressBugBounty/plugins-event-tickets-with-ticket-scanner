jQuery(document).ready(()=>{
    const { __, _x, _n, sprintf } = wp.i18n;
    let system = {code:0, nonce:'', data:null, redeemed_successfully:false, img_pfad:'', last_scanned_ticket:{code:'', timestamp:0, auto_redeem:false}};
	let myAjax;
    if (typeof IS_PRETTY_PERMALINK_ACTIVATED === "undefined") {
        IS_PRETTY_PERMALINK_ACTIVATED = false;
    }
    let DIV;

    if (typeof Ajax_ticket_events_sasoEventtickets === "undefined") {
        myAjax = {
            url: '/admin-ajax.php'
        };
        system.nonce = NONCE;
    } else {
        myAjax = Ajax_ticket_events_sasoEventtickets;
        system.nonce = myAjax.nonce;
    }
    //console.log(myAjax);

	function intval(v) {
		let retv = parseInt(v,10);
		if (isNaN(retv)) retv = 0;
		return retv;
	}

    function toBool(v) {
        if (v == "1") return true;
        if (v == 1) return true;
        if (v.toLowerCase() == "yes") return true;
        return v == true;
    }

    function addStyleCode(content, media) {
		let c = document.createElement('style');
        if (media) c.setAttribute("media", media);
		c.innerHTML = content;
		document.getElementsByTagName("head")[0].appendChild(c);
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
    function _makeGet(action, myData, cbf, ecbf, pcbf) {
        let call_data = _getURLAndDateForAjax(action, myData, pcbf);
        //console.log(call_data);
        $.get( call_data.url, call_data.data, response=>{
            if (typeof response == "string") {
				response = JSON.parse(response);
			}
            if (response && response.data && response.data.nonce) system.nonce = response.data.nonce;
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
		let d = new Date(str.split(' ')[0].replace(/-/g,"/"));
        return d;
	}
	function parseDateAndText(str, format) {
		return Date2Text(parseDate(str).getTime(), format);
	}
	function DateTime2Text(millisek) {
		return Date2Text(millisek, myAjax.format_datetime ? myAjax.format_datetime : "d.m.Y H:i");
	}
	function Date2Text(millisek, format, timezone_id) {
		if (!millisek)
			millisek = time(timezone_id);
		var d = new Date(millisek);
		if (!format) {
			//format = system.format_date ? system.format_date : "%d.%m.%Y";
            format = myAjax.format_date ? myAjax.format_date : "d.m.Y";
			//format = "%d.%m.%Y %H:%i";
        }
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
                    var key = teile[a].substring(0,pos);
                    parawerte[key] = decodeURIComponent(teile[a].substring(pos+1));
                }
            }
        }
        return parawerte;
    }

    function renderEventsAsList() {
        let events = myAjax.events;
        let list_infos = myAjax.list_infos;
        let browserLanguage = navigator.language || navigator.userLanguage;
        let language = browserLanguage.split('-')[0];

        // iterate over the months
        let months_to_show = list_infos.months_to_show ? list_infos.months_to_show : 1;
        let html = '<div class="sasoEventTicketsValidator_list">';
        for (let i = 0; i < months_to_show; i++) {
            let monthHtml = renderEventsForMonth(i)
            html += monthHtml;
        }
        html += '</div>';

        let ret = $(html);
        // find all events and add click event
        ret.find('.sasoEventTicketsValidator_event-info-btn').click(event=>{
            event.preventDefault();
            //let div = $(event.target).closest('.sasoEventTicketsValidator_event');
            let elem = $(event.target).closest('.sasoEventTicketsValidator_list_event');
            let eventId = elem.data('event-id');
            // find url to the event
            let url = '';
            events.forEach(event=>{
                if (event.ID == eventId) {
                    url = event.product.url;
                    url += '?event_id=' + eventId;
                    url += '&event_date=' + elem.data('event-date');
                    url += '&cal_date=' + elem.data('cal-date');
                    if (event.dates.ticket_start_date && event.dates.ticket_start_date !== '') {
                        //url += '&date=' + event.dates.ticket_start_date;
                    }
                }
            });
            if (url !== '') {
               window.location.href = url;
            }
        });

        return ret;

        function renderEventsForMonth(monthOffset) {
            // calculate the month to show with the offset
            let d = new Date(); // actual date
            d.setMonth(d.getMonth() + monthOffset); // add the offset
            let month = d.getMonth();
            let year = d.getFullYear();
            let daysInMonth = new Date(year, month + 1, 0).getDate();
            //let firstDay = new Date(year, month, 1).getDay();
            let monthName = new Date(year, month, 1).toLocaleString(language, {month: 'long'});

            let html = '<div class="sasoEventTicketsValidator_list_events" data-month="' + month + '" data-year="' + year + '">';
            html += '<div class="sasoEventTicketsValidator_list_events-header">' + monthName + ' ' + year + '</div>';
            // render the actual month per day
            for (let day = 1; day <= daysInMonth; day++) {
                let eventHtml = '';
                // date object for the current day
                let date_today = new Date(year, month, day);
                // get the name of the day
                let dayName = date_today.toLocaleString(language, {weekday: 'long'});

                events.forEach(event => {
                    let eventStartDate = date_today;
                    let eventEndDate = date_today;
                    if (event.dates.is_date_set) {
                        eventStartDate = new Date(intval(event.dates.ticket_start_p_year), intval(event.dates.ticket_start_p_month)-1, intval(event.dates.ticket_start_p_date));
                    }
                    if (event.dates.is_end_date_set) {
                        eventEndDate = new Date(intval(event.dates.ticket_end_p_year), intval(event.dates.ticket_end_p_month)-1, intval(event.dates.ticket_end_p_date));
                    }

                    let show_event = false;
                    if (intval(event.dates.ticket_start_p_date) == date_today.getDate() && intval(event.dates.ticket_start_p_month)-1 == date_today.getMonth() && intval(event.dates.ticket_start_p_year) == date_today.getFullYear()) {
                        show_event = true;
                    }
                    if (show_event == false) {
                        if (event.dates.is_end_date_set == true) {
                            // if end date is in the future and the start date is in the past
                            if (eventStartDate.getTime() <= date_today.getTime() && eventEndDate.getTime() >= date_today.getTime()) {
                                show_event = true;
                            }
                        }
                    }
                    if (show_event == false && event.dates.is_date_set == false && event.dates.is_end_date_set == false) { // daily events
                        show_event = true;
                    }
                    if (show_event == true && event.dates.daychooser_exclude_wdays && event.dates.daychooser_exclude_wdays.length > 0 && event.dates.daychooser_exclude_wdays.includes(date_today.getDay().toString())) {
                        show_event = false;
                    }
                    if (show_event == true && event.dates.daychooser_exclude_dates && event.dates.daychooser_exclude_dates.length > 0) {
                        event.dates.daychooser_exclude_dates.forEach(exclude_date => {
                            let exclude_date_obj = new Date(exclude_date);
                            if (exclude_date_obj == date_today) {
                                show_event = false;
                            }
                        });
                    }

                    if (show_event) {
                        eventHtml += '<div class="sasoEventTicketsValidator_list_event" data-event-id="' + event.ID + '" data-cal-date="' + date_today.getTime() + '" data-event-date="' + eventStartDate.getTime() + '">';
                        eventHtml += '<div class="sasoEventTicketsValidator_event-title">' + event.product.title + '</div>';
                        if (event.event.location && event.event.location !== '') {
                            eventHtml += '<div class="sasoEventTicketsValidator_event-location">' + event.event.location_label + ' ' +event.event.location + '</div>';
                        }

                        let event_date = Date2Text(eventStartDate.getTime()) + (eventStartDate == eventEndDate ? '' : ' - ' + Date2Text(eventEndDate.getTime()));

                        eventHtml += '<div class="sasoEventTicketsValidator_event-date">' + event_date + '</div>';
                        if (event.dates.ticket_start_time && event.dates.ticket_start_time !== '') {
                            let dd = new Date(event.dates.ticket_start_date+' '+event.dates.ticket_start_time);
                            let start_time = Date2Text(dd.getTime(), myAjax.format_time ? myAjax.format_time : "H:i");
                            eventHtml += '<div class="sasoEventTicketsValidator_event-time">' + start_time;
                            if (event.dates.ticket_end_time && event.dates.ticket_end_time !== '') {
                                let de = new Date(event.dates.ticket_end_date+' '+event.dates.ticket_end_time);
                                let end_time = Date2Text(de.getTime(), myAjax.format_time ? myAjax.format_time : "H:i");
                                eventHtml += ' - ' + end_time;
                            }
                            eventHtml += '</div>';
                        }
                        if (event.event.description && event.event.description !== '') {
                            eventHtml += '<div class="sasoEventTicketsValidator_event-description">' + event.event.description + '</div>';
                        }
                        // add info button aligned to the right
                        eventHtml += '<div class="sasoEventTicketsValidator_event-info"><button class="sasoEventTicketsValidator_event-info-btn">' + __('Info', 'event-tickets-with-ticket-scanner') + '</button></div>';
                        eventHtml += '</div>';
                    }
                });
                let is_today = date_today.toDateString() === new Date().toDateString();
                html += '<div class="sasoEventTicketsValidator_list_calendar-row'+(is_today ? '-today' : '')+'">'
                    + '<div class="sasoEventTicketsValidator_list_calendar-header'+(is_today ? '-today' : '')+'">' + Date2Text(date_today.getTime()) + ' - ' + dayName + '</div>'
                    + (eventHtml == '' ? '-' : eventHtml)
                    + '</div>';
            }

            html += '</div>';
            return html;
        }
    }

    function renderEvents() { // todo: render events as calendar - for now it looks ugly and the event information is not displayed correctly
        let events = myAjax.events;
        let d = new Date();
        let month = d.getMonth();
        let year = d.getFullYear();
        let daysInMonth = new Date(year, month + 1, 0).getDate();
        let firstDay = new Date(year, month, 1).getDay();

        let html = '<div class="sasoEventTicketsValidator_calendar">';
        html += '<div class="sasoEventTicketsValidator_calendar-header">';
        html += '<div class="sasoEventTicketsValidator_calendar-header-month">' + (month + 1) + '/' + year + '</div>';
        html += '</div>';
        html += '<div class="sasoEventTicketsValidator_calendar-body">';
        html += '<div class="sasoEventTicketsValidator_calendar-row">';
        let daysOfWeek = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        for (let i = 0; i < 7; i++) {
            html += '<div class="sasoEventTicketsValidator_calendar-cell sasoEventTicketsValidator_calendar-day">' + daysOfWeek[i] + '</div>';
        }
        html += '</div>';
        html += '<div class="sasoEventTicketsValidator_calendar-row">';
        for (let i = 0; i < firstDay; i++) {
            html += '<div class="sasoEventTicketsValidator_calendar-cell"></div>';
        }
        for (let day = 1; day <= daysInMonth; day++) {
            let eventHtml = '';
            events.forEach(event => {
                let eventDate = new Date(parseFloat(event.dates.ticket_start_date_timestamp) * 1000);
                if (eventDate.getDate() === day && eventDate.getMonth() === month && eventDate.getFullYear() === year) {
                    eventHtml += '<div class="sasoEventTicketsValidator_event">' + event.product.title + '</div>';
                }
            });
            html += '<div class="sasoEventTicketsValidator_calendar-cell">' + day + eventHtml + '</div>';
            if ((day + firstDay) % 7 === 0 && day !== daysInMonth) {
                html += '</div><div class="sasoEventTicketsValidator_calendar-row">';
            }
        }
        html += '</div>';
        html += '</div>';
        html += '</div>';

        let calendar_view = $(html)
            .find('.sasoEventTicketsValidator_calendar-cell')
            .click(function () {
                let day = $(this).text();
                //console.log(day);
            });
        return calendar_view;
    }

    function starten() {
        $ = jQuery;

        addStyleCode('.lds-dual-ring {display:inline-block;width:64px;height:64px;}.lds-dual-ring:after {content:" ";display:block;width:46px;height:46px;margin:1px;border-radius:50%;border:5px solid #fff;border-color:#2e74b5 transparent #2e74b5 transparent;animation:lds-dual-ring 0.6s linear infinite;}@keyframes lds-dual-ring {0% {transform: rotate(0deg);}100% {transform: rotate(360deg);}}');
        addMetaTag("viewport", "width=device-width, initial-scale=1");

        DIV = $('#'+myAjax.divId);
        DIV.html(_getSpinnerHTML());
        //DIV.html(JSON.stringify(myAjax));
        window.setTimeout(()=>{DIV.html(renderEventsAsList());},250);
    }

    var $;
    //window.onload = starten;
    starten();
} );