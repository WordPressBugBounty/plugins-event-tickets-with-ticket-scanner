function sasoEventtickets(_myAjaxVar, doNotInit) {
	const { __, _x, _n, sprintf } = wp.i18n;
	let myAjax = _myAjaxVar;
	let self = this;
	let PREMIUM = null;
	var $ = jQuery;
	var PARAS = basics_ermittelURLParameter();
	var DATA = {
        /*action: '',*/
        nonce: myAjax.nonce,
		last_nonce_check: 0
    };

	var system = {is_debug:false, DYNJS:{}, DYNJS_CACHE:{}};
	var FATAL_ERROR = false;
    var DIV = null;
    var LAYOUT = null;
    var DATA_LISTS = null;
	var DATA_AUTHTOKENS = null;
    var OPTIONS = {
		list:[], mapKeys:{},
		versions:{mapKeys:{}},
		meta_tags_keys:{list:[], mapKeys:{}},
		infos:{},
		tickets_for_testing:[],
		options_special:{}
	};

	var STATE = null;

    if (_myAjaxVar._doNotInit) doNotInit = true;

	function time() {
		return new Date().getTime();
	}

	function destroy_tags(t) {
		if (t != null) {
			t = t.replace("<", "").replace(">","");
		}
		return t;
	}

	function _requestURL(action, myData) {
		let paras = '?action='+myAjax._action+'&a_sngmbh='+action+'&nonce='+ DATA.nonce+'&t='+time();
		if (myData) {
			for(let key in myData) paras += '&data['+key+']='+encodeURIComponent(myData[key]);
		}
		for(let key in DATA) paras += '&'+key+'='+encodeURIComponent(DATA[key]);
		return myAjax.url + paras;
	}

	function _makePost(action, myData, cbf, ecbf, pcbf) {
		if (FATAL_ERROR) return;
		let _data = Object.assign({}, DATA);
		_data.action = myAjax._action;
		_data.a_sngmbh = action;
		_data.t = new Date().getTime();
		_data.nonce = DATA.nonce;
		pcbf && pcbf();
		for(var key in myData) _data['data['+key+']'] = myData[key];
		// Pass through debug parameter if set in URL
		var urlParams = new URLSearchParams(window.location.search);
		if (urlParams.has('VollstartValidatorDebug')) {
			_data['VollstartValidatorDebug'] = urlParams.get('VollstartValidatorDebug') || '1';
		}
        $.post( myAjax.url, _data, function( response ) {
			if (response && response.data && response.data.nonce) {
                DATA.last_nonce_check = new Date().getTime();
                DATA.nonce = response.data.nonce;
            }
            if (!response.success) {
            	if (ecbf) ecbf(response);
            	else LAYOUT.renderFatalError(response.data);
            } else {
            	cbf && cbf(response.data);
            }
        });
	}

	function _makeGet(action, myData, cbf, ecbf, pcbf) {
		if (FATAL_ERROR) return;
		let _data = Object.assign({}, DATA);
		_data.action = myAjax._action;
		_data.a_sngmbh = action;
		_data.t = new Date().getTime();
		_data.nonce = DATA.nonce;
		pcbf && pcbf();
		for(var key in myData) _data['data['+key+']'] = myData[key];
		// Pass through debug parameter if set in URL
		var urlParams = new URLSearchParams(window.location.search);
		if (urlParams.has('VollstartValidatorDebug')) {
			_data['VollstartValidatorDebug'] = urlParams.get('VollstartValidatorDebug') || '1';
		}
        $.get( myAjax.url, _data, function( response ) {
			if (response && response.data && response.data.nonce) {
                DATA.last_nonce_check = new Date().getTime();
                DATA.nonce = response.data.nonce;
            }
            if (!response.success) {
				if (ecbf) ecbf(response);
            	else LAYOUT.renderFatalError(response.data);
            } else {
            	cbf && cbf(response.data);
            }
        });
	}

	function getOptionsFromServer(cbf, ecbf, pcbf) {
		_makeGet('getOptions', {}, options=>{
			_setOptions(options);
			cbf && cbf(options);
		}, ecbf, pcbf);
	}

	function _downloadFile(action, myData, filenameToStore, cbf, ecbf, pcbf) {
		let _data = Object.assign({}, DATA);
		_data.action = myAjax._action;
		_data.a_sngmbh = action;
		_data.t = new Date().getTime();
		_data.nonce = DATA.nonce;
		pcbf && pcbf();
		for(var key in myData) _data['data['+key+']'] = myData[key];
		let params = "";
		for(var key in _data) params += key+"="+_data[key]+"&";
		let url = myAjax.url+'?'+params;
		let window_name = myData.code ? myData.code : '_blank';
		let new_window = window.open(url, window_name);
		//window.location.href = url;
		//ajax_downloadFile(url, filenameToStore, cbf);
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
	function _saveOptionValue(key, value, cbf, pcbf) {
		_makePost('changeOption', {'key':key, 'value':value},
		()=>{
			cbf && cbf();
			if (key == "wcTicketDesignerTemplateTest") {
				$("#wcTicketDesignerTemplateTest_button_PDF").prop("disabled", false).text(__('Preview Test Template Code as PDF', 'event-tickets-with-ticket-scanner'));
			}
		}, null,
		()=>{
			pcbf && pcbf();
			if (key == "wcTicketDesignerTemplateTest") {
				$("#wcTicketDesignerTemplateTest_button_PDF").prop("disabled", true).text(__('saving...', 'event-tickets-with-ticket-scanner'));
			}
		});

	}

	function _setOptions(optionData) {
		OPTIONS.list = optionData.options;
		for (let a=0;a<OPTIONS.list.length;a++) {
			let item = OPTIONS.list[a];
			OPTIONS.mapKeys[item.key] = item;
			OPTIONS.mapKeys[item.key].getValue = function(key) {
				return function() {return _getOptions_getValByKey(key);};
			}(item.key);
		}
		if (optionData.versions) {
			if (!optionData.versions.IS_PRETTY_PERMALINK_ACTIVATED) {
				LAYOUT.renderInfoBox(__("Warning", 'event-tickets-with-ticket-scanner'), __("In order to make the ticket detail view and the ticket scanner work, you need to set a permalink structure within the settings.<br>Please go to the settings->permalinks and choose a permalink structure, that is not 'plain'.", 'event-tickets-with-ticket-scanner'));
			}
			OPTIONS.versions.mapKeys = optionData.versions;
		}
		system.is_debug = typeof optionData.versions.is_debug != "undefined" && optionData.versions.is_debug == 1 ? true : false;
		if (optionData.meta_tags_keys) {
			OPTIONS.meta_tags_keys.list = optionData.meta_tags_keys;
			OPTIONS.meta_tags_keys.mapKeys = {};
			for (let a=0;a<OPTIONS.meta_tags_keys.list.length;a++) {
				let item = OPTIONS.meta_tags_keys.list[a];
				OPTIONS.meta_tags_keys.mapKeys[item.key] = item;
				OPTIONS.meta_tags_keys.mapKeys[item.key].getValue = function(key) {
					return function() {return _getOptions_Meta_getValByKey(key);};
				}(item.key);
			}
		}
		if (optionData.infos) {
			OPTIONS.infos = optionData.infos;
		}
		if (optionData.tickets_for_testing) {
			OPTIONS.tickets_for_testing = optionData.tickets_for_testing;
		}
		if (optionData.options_special) {
			OPTIONS.options_special = optionData.options_special;
		}

		if (isPremium()) {
			let serial = _getOptions_getValByKey('serial');
			if (serial == '') {
				if (STATE != "options") {
					let errortext = __("You are using the premium version. Many thanks, please enter your serial key within the options", 'event-tickets-with-ticket-scanner');
					let i = confirm(errortext);
					if (i) {
						_displayOptionsArea();
					}
				}
			}
			if (serial != "" && typeof OPTIONS.infos.premium_expiration !== "undefined") {
				let expiration = OPTIONS.infos.premium_expiration;
				if (expiration.last_run != 0 && expiration.timestamp > 0) {
					let expirationDate = new Date(expiration.timestamp * 1000);
					let toCheck = new Date();
					toCheck.setDate(toCheck.getDate() + 21);
					let today = new Date();
					if (expirationDate <= today || toCheck >= expirationDate) {
						let msg = typeof expiration.message !== "undefined" && expiration.message != "" ? '<br>'+expiration.message : '';
						let info_box = $('<div style="background-color:red;color:white;padding:10px;">').html("Your premium license expires soon, at the "+expiration.expiration_date+ ' '+expiration.timezone+'<br>It will work, but no updates are possible for the premium plugin after the expiration date.<br>'+msg+'You can <a target="_blank" style="color:white;font-weight:bold;" href="https://vollstart.com/event-tickets-with-ticket-scanner/">renew your premium license here</a>.');
						$('body').find('div[data-id="plugin_info_area"').html(info_box);
					}
				}
			}
		}
	}

	function _getOptions_getByKey(key) {
		if (OPTIONS.mapKeys[key]) return OPTIONS.mapKeys[key];
		return null;
	}
	function _getOptions_Meta_getByKey(key) {
		if (OPTIONS.meta_tags_keys.mapKeys[key]) return OPTIONS.meta_tags_keys.mapKeys[key];
		return null;
	}
	function _getOptions_Versions_getByKey(key) {
		if (OPTIONS.versions.mapKeys[key]) return OPTIONS.versions.mapKeys[key];
		return null;
	}
	function _getOptions_Infos_getByKey(key) {
		if (OPTIONS.infos[key]) return OPTIONS.infos[key];
		return null;
	}
	function _getOptions_isActivatedByKey(key) {
		let po = _getOptions_getByKey(key);
		if (po == null) return false;
		return po.value == 1;
	}
	function _getOptions_Versions_isActivatedByKey(key) {
		let po = _getOptions_Versions_getByKey(key);
		if (po == null) return false;
		return po == 1;
	}
	function _getOptions_getLabelByKey(key) {
		let po = _getOptions_getByKey(key);
		if (po == null) return "";
		return po.label;
	}
	function _getOptions_Meta_getLabelByKey(key) {
		let po = _getOptions_Meta_getByKey(key);
		if (po == null) return "";
		return po.label;
	}
	function _getOptions_getValByKey(key) {
		let po = _getOptions_getByKey(key);
		if (po == null) return "";
		return po.value == "" ? po['default'] : po.value;
	}
	function _getOptions_Versions_getValByKey(key) {
		let po = _getOptions_Versions_getByKey(key);
		if (po == null) return "";
		return po;
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

	function intval(v) {
		let retv = parseInt(v,10);
		if (isNaN(retv)) retv = 0;
		return retv;
	}

	function getDefaultDateFormat() {
		return (OPTIONS?.options_special?.format_date) ? OPTIONS.options_special.format_date : "d.m.Y";
	}
	function getDefaultDateTimeFormat() {
		return OPTIONS.options_special.format_datetime ? OPTIONS.options_special.format_datetime : "d.m.Y H:i";
	}
	function DateTime2Text(millisek) {
		return Date2Text(millisek, getDefaultDateTimeFormat());
	}
	/*
	function Date2Text(millisek, format, timezone_id) {
		if (!timezone_id) timezone_id =  _getOptions_Versions_getByKey("date_WP_timezone");
		if (!millisek)
			millisek = time(timezone_id);
		var d = new Date(millisek);
		if (!format)
			//format = system.format_date ? system.format_date : "%d.%m.%Y";
            format = getDefaultDateFormat();
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
	*/

	function DateFormatStringToDateTimeText(datestring, format, timezone_id) {
		if (!format) format = getDefaultDateTimeFormat();
		let millisek = parseToMillis(datestring, timezone_id);
		return Date2Text(millisek, format, timezone_id);
	}
	function DateFormatStringToDateText(datestring, format, timezone_id) {
		let millisek = parseToMillis(datestring, timezone_id);
		return Date2Text(millisek, format, timezone_id);
	}

	function Date2Text(millisek, format, timezone_id) {
		// 1) Timezone bestimmen (Fallback: UTC)
		if (!timezone_id) {
			timezone_id = _getOptions_Versions_getByKey("date_WP_timezone") || "UTC";
		}

		// 2) Timestamp normalisieren (PHP liefert oft Sekunden; JS braucht Millisekunden)
		if (typeof millisek === "string") millisek = Number(millisek);
		if (!millisek) {
			// Deine bestehende Logik – falls du hier einen Unix-TS in Sekunden bekommst, bitte ggf. *1000 ergänzen
			millisek = time(timezone_id);
		}
		if (String(Math.trunc(millisek)).length === 10) {
			millisek = millisek * 1000;
		}
		const date = new Date(Number(millisek));

		// 3) Defaults für Format
		if (!format) {
			format = getDefaultDateFormat();
		}

		// 4) Lokalisierte Kurzformen (nutzt deine _x-Übersetzungen)
		const tage = [
			_x('Sun', 'cal', 'event-tickets-with-ticket-scanner'),
			_x('Mon', 'cal', 'event-tickets-with-ticket-scanner'),
			_x('Tue', 'cal', 'event-tickets-with-ticket-scanner'),
			_x('Wed', 'cal', 'event-tickets-with-ticket-scanner'),
			_x('Thu', 'cal', 'event-tickets-with-ticket-scanner'),
			_x('Fri', 'cal', 'event-tickets-with-ticket-scanner'),
			_x('Sat', 'cal', 'event-tickets-with-ticket-scanner')
		];
		const monate = [
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

		// 5) Teile in gewünschter Timezone extrahieren
		const dtf = new Intl.DateTimeFormat("de-CH", {
			timeZone: timezone_id,
			year: "numeric",
			month: "2-digit",
			day: "2-digit",
			hour: "2-digit",
			minute: "2-digit",
			second: "2-digit",
			weekday: "short",
			hour12: false
		});
		const parts = Object.fromEntries(dtf.formatToParts(date).map(p => [p.type, p.value]));

		const monthNum = Number(parts.month);     // 1..12 (als "01".."12")
		const dayNum   = Number(parts.day);       // 1..31
		const hourNum  = Number(parts.hour);      // 0..23

		// Wochentag-Index (0=Sun..6=Sat) in der angegebenen Timezone
		const weekdayEn = new Intl.DateTimeFormat("en-US", { timeZone: timezone_id, weekday: "short" }).format(date);
		const weekdayIndex = ["Sun","Mon","Tue","Wed","Thu","Fri","Sat"].indexOf(weekdayEn);

		// 6) Token-Mapping (PHP-ähnlich)
		const formate = {
			'd': parts.day,                                   // 01..31
			'j': String(dayNum),                              // 1..31
			'D': tage[weekdayIndex],                          // So/Mo/... (aus _x oben, hier auf 'Sun'.. gemappt)
			'w': String(weekdayIndex),                        // 0..6 (So=0)
			'm': parts.month,                                 // 01..12
			'M': monate[monthNum - 1],                        // Jan..Dec (aus _x oben)
			'n': String(monthNum),                            // 1..12
			'Y': parts.year,                                  // 2025
			'y': parts.year.slice(-2),                        // 25
			'H': parts.hour,                                  // 00..23
			'h': String(((hourNum % 12) || 12)).padStart(2,'0'), // 01..12
			'i': parts.minute,                                // 00..59
			's': parts.second                                 // 00..59
		};

		// 7) Token ersetzen (ohne %; entspricht deiner aktuellen Logik)
		for (const akey in formate) {
			const rg = new RegExp(akey, "g");
			format = format.replace(rg, formate[akey]);
		}
		return format;
	}

	// Hilfsfunktion: Offset-Minuten einer IANA-Zeitzone für einen UTC-Instant ermitteln.
	// Nutzt Intl.DateTimeFormat mit timeZoneName:'shortOffset' (z.B. "GMT+2").
	function _getTzOffsetMinutes(utcDate, timezone_id) {
		const fmt = new Intl.DateTimeFormat('en-US', {
			timeZone: timezone_id,
			timeZoneName: 'shortOffset',
			year: 'numeric', month: '2-digit', day: '2-digit',
			hour: '2-digit', minute: '2-digit', second: '2-digit',
			hour12: false
		});
		const parts = fmt.formatToParts(utcDate);
		const z = parts.find(p => p.type === 'timeZoneName')?.value || 'GMT+0';
		// Erwartete Form: "GMT+2" oder "GMT+02:00"
		const m = z.match(/GMT([+-])(\d{1,2})(?::?(\d{2}))?/i);
		if (!m) return 0;
		const sign = m[1] === '-' ? -1 : 1;
		const hours = parseInt(m[2], 10);
		const mins  = m[3] ? parseInt(m[3], 10) : 0;
		return sign * (hours * 60 + mins);
	}

	// Wandelt verschiedenste Eingaben in einen UTC-Millis-Timestamp.
	// - Zahlen (Sekunden/Millis) -> normalisiert
	// - ISO-Strings mit Z/±hh:mm -> nativ geparst
	// - Naive Strings (z.B. "YYYY-MM-DD HH:mm:ss") -> als timezone_id-Wandzeit interpretiert
	function parseToMillis(input, timezone_id) {
		timezone_id = timezone_id || (typeof _getOptions_Versions_getByKey === 'function'
			? _getOptions_Versions_getByKey("date_WP_timezone") : "UTC");

		// 1) Direkt Number?
		if (typeof input === 'number') {
			// 10-stellige Sekunden -> *1000
			if (String(Math.trunc(input)).length === 10) return input * 1000;
			return input; // bereits Millisekunden
		}

		// 2) String -> trim
		if (typeof input === 'string') {
			const s = input.trim();

			// 2a) Reine Ziffern -> Sekunden/Millis
			if (/^\d+$/.test(s)) {
				const n = Number(s);
				return (s.length === 10) ? n * 1000 : n;
			}

			// 2b) ISO mit Z / Offset -> nativ (sicher)
			if (/T.*(Z|[+-]\d{2}:\d{2})$/.test(s)) {
				const d = new Date(s);
				if (!isNaN(d)) return d.getTime();
			}

			// 2c) Naive Formate: "YYYY-MM-DD HH:mm:ss" | "YYYY/MM/DD HH:mm" | "YYYY-MM-DD"
			// Wir parsen Komponenten und interpretieren sie als Wandzeit in timezone_id.
			const m = s.match(
				/^(\d{4})[-\/](\d{2})[-\/](\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/
			);
			if (m) {
				const Y = parseInt(m[1], 10);
				const Mo = parseInt(m[2], 10);
				const D = parseInt(m[3], 10);
				const H = m[4] ? parseInt(m[4], 10) : 0;
				const I = m[5] ? parseInt(m[5], 10) : 0;
				const S = m[6] ? parseInt(m[6], 10) : 0;

				// Instant-Kandidat in UTC aus den "lokalen" Komponenten
				// Idee: Komponenten als UTC annehmen -> Offset der Ziel-Zeitzone abziehen.
				const utcGuess = new Date(Date.UTC(Y, Mo - 1, D, H, I, S));

				// Offset der Ziel-Zone zum angegebenen Zeitpunkt holen (inkl. DST)
				const offMin = _getTzOffsetMinutes(utcGuess, timezone_id);

				// Echte UTC-Millis, wenn Y-M-D H:I:S die Wandzeit in timezone_id ist:
				return utcGuess.getTime() - offMin * 60 * 1000;
			}

			// 2d) Fallback: Versuch natives Date (Browser-lokal) – nicht ideal, aber besser als NaN
			const d = new Date(s.replace(' ', 'T'));
			if (!isNaN(d)) return d.getTime();
		}

		// 3) Wenn alles fehlschlägt -> NaN (oder wirf Fehler je nach Policy)
		return NaN;
	}
	function _getMediaData(mediaid, cbf) {
		_makeGet('getMediaData', {'mediaid':mediaid}, (ret)=>{
			cbf && cbf(ret);
		});
	}

	function getDataLists(cbf) {
		if (DATA_LISTS !== null) cbf && cbf();
		_makeGet('getLists', {}, data=>{
			DATA_LISTS = data;
			cbf && cbf(DATA_LISTS);
		});
	}

	function getCodeObjectMeta(codeObj) {
		if (codeObj.metaObj) return codeObj.metaObj;
		try {
			if (typeof codeObj.meta == "undefined" || codeObj.meta == "") {
				codeObj.metaObj = null;
			} else {
				codeObj.metaObj = JSON.parse(codeObj.meta);
			}
		} catch(e) {
			// new empty tickets have no meta
			//console.log("Error should not happen. Meta is broken. ", codeObj);
			codeObj.metaObj = null;
		}
		return codeObj.metaObj;
	}

	function updateCodeObject(codeObj, newCodeObj) {
		for(var prop in newCodeObj) {
			codeObj[prop] = newCodeObj[prop];
		}
		codeObj.metaObj = null;
	}

	function closeDialog(dlg) {
		$(dlg).dialog( "close" );
		$(dlg).html('');
		$(dlg).dialog("destroy").remove();
		$(dlg).empty();
		$(dlg).remove();
		$('.ui-dialog-content').dialog('destroy');
	}

	function getUseFulVideosHTML() {
		return '<h3>Useful videos</h3><ul><li><span class="dashicons dashicons-external"></span><a href="https://youtu.be/yJcHMV7oAFc" target="_blank">Setup for use case Event Organizer (Youtube)</a></li><li><span class="dashicons dashicons-external"></span><a href="https://www.youtube.com/watch?v=TDMWI0R_HXQ" target="_blank">Setup for use case Club, Spa and Fitness clubs (Youtube)</a></li></ul>';
	}

	function getAuthtokens(cbf) {
		if (DATA_AUTHTOKENS !== null) cbf && cbf();
		_makeGet('getAuthtokens', {}, data=>{
			DATA_AUTHTOKENS = data;
			cbf && cbf(DATA_AUTHTOKENS);
		});
	}

	function _displayAuthTokensArea() {
		STATE = 'authtokens';
		DIV.html('');
		DIV.append(getBackButtonDiv());

		DIV.append('<h3>'+_x('Auth Token', 'label', 'event-tickets-with-ticket-scanner')+'</h3>');
		$('<p>').html(__('You can add auth tokens, that can be used to access your ticket scanner. Create an auth token and pass the QR code to the user or let the user scan it from your admin area. The used auth token will bypass any access restricton settings for the ticket scanner that are set in the options.', 'event-tickets-with-ticket-scanner')).appendTo(DIV);
		$('<p>').html(__('The user scan the QR code for the auth token with the ticket scanner. Just like a normal ticket. The system will store the auth token to the browser.', 'event-tickets-with-ticket-scanner')).appendTo(DIV);
		let loading = $('<div/>').html(_getSpinnerHTML()).appendTo(DIV);
		let div2 = $('<div style="background:white;padding:15px;border-radius:15px;">').appendTo(DIV);
		let tplace = $('<div style="background:white;padding:15px;border-radius:15px;"/>');

		getOptionsFromServer(reply=>{
			let tabelle_authtokens_datatable;
			let btn_new = $('<button/>').addClass("button-primary").html(_x('Add', 'label', 'event-tickets-with-ticket-scanner')).on("click", ()=>{
				__showMaskAuthtoken(null);
			});
			$('<div/>').css('text-align', 'right').css('margin-bottom','10px').append(btn_new).appendTo(div2);
			let div_tabelle = $('<div>');
			loading.html("");
			tplace.html("").append(div_tabelle).appendTo(div2);

			function __showMaskAuthtoken(editValues) {
				let _options = {
					title: editValues !== null ? _x('Edit Auth Token', 'title', 'event-tickets-with-ticket-scanner') : _x('Add Auth Token', 'title', 'event-tickets-with-ticket-scanner'),
					modal: true,
					minWidth: 600,
					minHeight: 400,
					buttons: [
						  {
							  text: _x('Ok', 'label', 'event-tickets-with-ticket-scanner'),
							  click: function() {
								___submitForm();
							  }
						  },
						  {
							  text: _x('Cancel', 'label', 'event-tickets-with-ticket-scanner'),
							  click: function() {
								  closeDialog(this);
							  }
						  }
					  ]
				};
				let dlg = $('<div/>').html('<form>'+_x('Name', 'label', 'event-tickets-with-ticket-scanner')+'<br><input name="inputName" type="text" style="width:100%;" required></form>');
				dlg.dialog(_options);

				dlg.find("form").append('<p>'+_x('Bound to product(s)', 'label', 'event-tickets-with-ticket-scanner')+'<br><input name="inputBoundToProducts" type="text" placeholder="'+_x('all products allowed to be redeemed', 'label', 'event-tickets-with-ticket-scanner')+'" style="width:100%;"><br>'+__('You can add comma seperated "," product ids. This will limit the user to redeem tickets only of products listed here. If left empty, all are allowed.', 'event-tickets-with-ticket-scanner')+'</p>');
				dlg.dialog(_options);

				dlg.find("form").append($('<p>'+_x('Description', 'label', 'event-tickets-with-ticket-scanner')+'<br><textarea name="desc" style="width:100%;"></textarea></p>'));
				if (isPremium() && typeof PREMIUM.addAuthtokenMaskEditFields != "undefined") PREMIUM.addAuthtokenMaskEditFields(dlg, editValues);
				dlg.find("form").append($('<p><input type="checkbox" name="aktiv">'+_x('is active', 'label', 'event-tickets-with-ticket-scanner')+'</p>'));

				let form = dlg.find("form").on("submit", event=>{
					event.preventDefault();
					___submitForm();
				});

				let metaObj = [];
				if (editValues && typeof editValues.meta !== "undefined" && editValues.meta != "") {
					try {
						metaObj = JSON.parse(editValues.meta);
					} catch(e) {}
				}

				if (editValues) {
					form[0].elements['inputName'].value = editValues.name;
					form[0].elements['inputName'].select();
					form[0].elements['inputBoundToProducts'].value = editValues.metaObj.ticketscanner.bound_to_products;
					form[0].elements['aktiv'].checked = editValues.aktiv == 1 ? true : false;
					if (typeof metaObj.desc !== "undefined") {
						form[0].elements['desc'].value = metaObj.desc;
					}
				}

				function ___submitForm() {
					let inputName = form[0].elements['inputName'].value.trim();
					if (inputName === "") return;

					dlg.html(_getSpinnerHTML());
					let _data = {"name":inputName};
					_data['aktiv'] = form[0].elements['aktiv'].checked ? 1 : 0;
					_data['meta'] = {"desc":"", "ticketscanner":{"bound_to_products":""}};
					_data['meta']['desc'] = form[0].elements['desc'].value.trim();
					_data['meta']['ticketscanner']['bound_to_products'] = form[0].elements['inputBoundToProducts'].value.trim();
					if (isPremium() && typeof PREMIUM.addAuthtokenMaskEditFieldsData != "undefined") PREMIUM.addAuthtokenMaskEditFieldsData(_data, form[0], editValues);

					form[0].reset();
					if (editValues) {
						_data.id = editValues.id;
						_makePost('editAuthtoken', _data, result=>{
							DATA_AUTHTOKENS = null;
							__renderTabelleAuthtokens();
							//tabelle_authtokens_datatable.ajax.reload();
							setTimeout(function(){closeDialog(dlg);},250);
						}, ()=>{
							closeDialog(dlg);
						});
					} else {
						_makePost('addAuthtoken', _data, result=>{
							DATA_AUTHTOKENS = null;
							__renderTabelleAuthtokens();
							closeDialog(dlg);
						}, response=>{
							closeDialog(dlg);
							if (response.data.slice(0,1) === "#") {
								FATAL_ERROR === false && LAYOUT.renderFatalError(response.data);
								//FATAL_ERROR = true;
							}
						});
					}
				}
			} // end __showMaskAuthtoken

			function __renderTabelleAuthtokens() {
				div_tabelle.html(_getSpinnerHTML());
				getAuthtokens(()=>{
					let table_id = myAjax.divPrefix+'_tabelle_authtokens';
					let tabelle = $('<table/>').attr("id", table_id);
					tabelle.html('<thead><tr><th></th><th align="left">'+_x('Name', 'label', 'event-tickets-with-ticket-scanner')+'</th><th align="left">'+_x('Created', 'label', 'event-tickets-with-ticket-scanner')+'</th><th>'+_x('Area', 'label', 'event-tickets-with-ticket-scanner')+'</th><th>'+_x('Status', 'label', 'event-tickets-with-ticket-scanner')+'</th><th></th></tr></thead>');
					div_tabelle.html(tabelle);

					let table = $('#'+table_id);
					$(table).DataTable().clear().destroy();
					tabelle_authtokens_datatable = $(table).DataTable({
						"responsive": true,
						"visible": true,
						"searching": true,
						"ordering": true,
						"processing": true,
						"serverSide": false,
						"stateSave": true,
						"data": DATA_AUTHTOKENS,
						"order": [[ 1, "asc" ]],
						"columns":[
							{"data":null,"className":'details-control',"orderable":false,"defaultContent":'', "width":10},
							{"data":"name", "orderable":true,
								"render": ( data, type, row )=>{
									return encodeURIComponent(data);
								}
							},
							{"data":"time", "orderable":true, "width":80,
								"render":function (data, type, row) {
									return '<span style="display:none;">'+data+'</span>'+DateFormatStringToDateTimeText(data);
								}
							},
							{"data":"areacode", "orderable":true, "className":"dt-center", "width":80},
							{"data":"aktiv", "orderable":true, "width":50, "className":"dt-center", "render":(data, type, row)=>{
								return data == 1 ? 'active' : 'inactive';
							}},
							{"data":null,"orderable":false,"defaultContent":'',"className":"buttons dt-right","width":100,
								"render": ( data, type, row )=>{
									return '<button class="button-secondary" data-type="edit">'+_x('Edit', 'label', 'event-tickets-with-ticket-scanner')+'</button> <button class="button-secondary" data-type="delete">'+_x('Delete', 'label', 'event-tickets-with-ticket-scanner')+'</button>';
								}
							}
						]
					});
					tabelle.css("width", "100%");
					table.on('click', 'button[data-type="edit"]', e=>{
						let data = tabelle_authtokens_datatable.row( $(e.target).parents('tr') ).data();
						__showMaskAuthtoken(data);
					});
					table.on('click', 'button[data-type="delete"]', e=>{
						let data = tabelle_authtokens_datatable.row( $(e.target).parents('tr') ).data();
						LAYOUT.renderYesNo(_x('Do you want to delete?', 'title', 'event-tickets-with-ticket-scanner'), __('Are you sure, you want to delete this auth token?', 'event-tickets-with-ticket-scanner')+'<br><p><b>'+data.name+'</b></p>'+__('The user with this auth token will not be able to use the server anymore. The user will need to add a new auth token from you.<p>The effect will be immediately.</p>', 'event-tickets-with-ticket-scanner'), ()=>{
							let _data = {'id':data.id};
							div_tabelle.html(_getSpinnerHTML());
							_makePost('removeAuthtoken', _data, result=>{
								DATA_AUTHTOKENS = null;
								__renderTabelleAuthtokens();
								//tabelle_authtokens_datatable.ajax.reload();
							});
						});
					});
					$('#'+table_id+' tbody').on('click', 'td.details-control', e=>{
						function ___format(d) {
							let metaObj = {};
							if (d.metaObj) metaObj = d.metaObj;
							if (d.meta && !d.metaObj) {
								metaObj = JSON.parse(d.meta);
							}
							let id = 'qrcode_'+d.id+'_'+time();
							let content = JSON.stringify({"type":"auth", "time":d.time, "name":d.name, "code":d.code, "areacode":d.areacode, "url":OPTIONS.infos.site.site_url});
							let content2 = _getTicketScannerURL()+'&auth='+encodeURIComponent(content);

							let div = $('<div/>');
							$('<div>').html("<b>Authcode: </b>"+d.code).appendTo(div);
							let div_wrapper = $('<div style="padding-top:10px;">').appendTo(div);

							$('<div style="width:256px;float:left;text-align:center">').html('<b>Only Auth Token</b><div id="'+id+'" style="text-align:center;"></div><script>jQuery("#'+id+'").qrcode(\''+content+'\');</script>').appendTo(div_wrapper);
							$('<div style="margin-left:20px;width:256px;float:left;text-align:center">').html('<b>With Ticket Scanner URL</b><div id="'+id+'2" style="text-align:center;"></div><script>jQuery("#'+id+'2").qrcode(\''+content2+'\');</script>').appendTo(div_wrapper);

							let div_inner = $('<div style="float:left;padding-left:10px;">').appendTo(div_wrapper);
							let _desc = metaObj.desc == "" ? "-" : metaObj.desc;
							$('<div>').html('<b><a href="'+content2+'" target="_blank">Open Ticket Scanner with Auth Token</a></b>').appendTo(div_inner);
							$('<div>').html('<b>Desc:</b> ').append($('<span>').text(_desc)).appendTo(div_inner);

							let bound_to_products = metaObj.ticketscanner.bound_to_products == "" ? [] : metaObj.ticketscanner.bound_to_products.toString().split(",");
							$("<div>").html("<b>Bound to product:</b> "+(bound_to_products.length == 0 ? "all products": bound_to_products.join(", "))).appendTo(div_inner);

							return div;
						}

						var tr = $(e.target).parents('tr');
						var row = tabelle_authtokens_datatable.row( tr );
						if ( row.child.isShown() ) {
							// This row is already open - close it
							row.child.hide();
							tr.removeClass('shown');
						} else {
							// Open this row
							row.child( ___format(row.data()) ).show();
							tr.addClass('shown');
						}

					});
				});
			}
			__renderTabelleAuthtokens();
		});
	}

	function _displayFAQArea() {
		STATE = 'faq';
		DIV.html(_getSpinnerHTML());

		let questions = [
			{
				"q":'PDF is not rendering - critical error',
				"t":'<p>The used PDF library cannot handle all the fancy HTML and CSS. Using these in the product description can lead to an error. If the ticket detail page is working, but the PDF not then you can try to remove the HTML tags or use the option to not print the product description to the ticket.<br>Please set the option <b>wcTicketPDFStripHTML</b> to remove the HTML and retry the PDF by reloading the browser or click again.</p><p>If your system is not live yet, you can use the debug mode first to see which HTML tags are used. The basics HTML tags are working well.</p><p>Try the option to remove the not supported HTML tags - this is not always great, because it removes the HTML tags that Wordpress is not supporting and could still lead to PDF issues, but a great start.<br>If this was not helping, then remove please the HTML tags in your product description for a test. You can also just deactivate the option <b>wcTicketDisplayShortDesc</b> to not use the short description of the product for a test.</p>'
			},
			{
				"q":'Receiving 404 error page if calling the ticket view and/or PDF',
				"t":'<p>Some installations have issues to open the ticket details view and/or the ticket scanner.<br>This could be because of your theme, other plugins or more stricter security settings.</p><p>If you experience to see the "file not found" page (404), then it could help if your activate the compatibility mode in the options.</p><p>For this configure the option <b>wcTicketCompatibilityModeURLPath</b> and/or <b>wcTicketCompatibilityMode</b>.</p><p>If this do not help, then the plugin will not work with your installation for now.</p>'
			},
			{
				"q":'How to ask for a value of your ticket?',
				"t":'<p>You can setup your product to ask your customer for up to 2 values. Free text and a value chosen from a dropdown.<br>You can checkout how it is done with <a href="https://youtu.be/2vTV39wgWNE" target="_blank">this video</a>.</p>'
			},
			{
				"q":'(Pre)Create order with tickets in the backend',
				"t":'<p>You can also checkout <a href="https://youtu.be/VxUV-s-SIpA" target="_blank">this video here</a>.<br>This video shows how to create an order from the backend and generate the tickets.<br>This approach is also good for free tickets. So you can create the order and have valid tickets. Do not forget to set the order to a redeemable status. The default is "completed".</p>'
			},
			{
				"q":'How to display meta information of the purchased item?',
				"t":'You can display the meta information of the item with TWIG.<br>Try TWIG code in the ticket template test designer, to see if this helps. First it is a good idea to check the whole meta values. You can achieve this, by displaying the values as JSON with this code.<p><b>{% for item_id, item in ORDER.get_items %}<br>{{ item.get_meta_data|json_encode() }}<br>{% endfor %}</b></p><p>You will see the key value pairs. Then grab your values. E.g.</p><p><b>{%- for item_id, item in ORDER.get_items -%}<br>{%- if item_id == METAOBJ.woocommerce.item_id -%}<br>&lt;br&gt;Date: {{ item.get_meta("Booked From", true) }} - {{ item.get_meta("Booked To", true) }}<br>{%- endif -%}<br>{%- endfor -%}</b></p>'
			},
			{
				"q":"How to set the order immediately to 'completed' if the order is paid?",
				"t":"You can activate the option wcTicketSetOrderToCompleteIfAllOrderItemsAreTickets to change the order status to completed if all purchased items in the order are tickets and the order status is processing. With this the order is fine and not paid orders are not automatically set to completed. This prevents frauds."
			},
			{
				"q":"How to use own page with ticket scanner and have the QR code redirect to it?",
				"t":"<p>You set up a page with the ticket scanner shortcode 'sasoEventTicketsValidator_ticket_scanner'.<br>Then adjust the URL for your tickets (scanner is included). The only option for now is the wcTicketCompatibilityModeURLPath. But this also changes the detail page of the ticket. Basically the system is adding to this URL just the '/scanner/?code='.</p><p>If you do not want this, you can adjust the QR content with the option qrOwnQRContent.</p><p>Set it to have the content:<br>https://domain-and-path/scanner/?code={WC_TICKET__PUBLIC_TICKET_ID}</p>"
			}
		];

		let div = $('<div>');
		div.append("<h2>FAQ</h2>");
		let div2 = $('<div style="background:white;padding:15px;border-radius:15px;">').appendTo(div);
		div2.append(getUseFulVideosHTML()+'<br><br>');

		questions.forEach(v=>{
			let clicked = false;
			div2.append($('<h3 style="cursor:pointer;">').html("+ "+v.q).on("click",e=>{
				f1.css("display", clicked ? "none" : "block");
				clicked = !clicked;
			}));
			let f1 = $('<div style="display:none;padding-bottom:15px;">').html(v.t).appendTo(div2);
		});

		DIV.html(getBackButtonDiv());
		DIV.append(div);
	}

	function _displaySeatingplanArea() {
		STATE = 'seatingplan';
		let div = $('<div>').html(_getSpinnerHTML());
		const version = system.is_debug ? new Date().getTime() : myAjax._plugin_version;
		const jsFile = 'js/seating_admin.js?v=' + version;
		const cssFile = 'css/seating_admin';

		addStyleTag(myAjax._plugin_home_url + '/' + cssFile + '.css?v=' + version, 'saso_seating_admin_css');

		// Load JS if not already loaded (or always in debug mode)
		if (!system.is_debug && system.DYNJS[jsFile]) {
			sasoEventtickets_js_seating_admin(myAjax, getHelperFunktions()).initAdmin(div);
		} else {
			console.log('Loading seating admin JS: ' + jsFile);
			$.getScript(myAjax._plugin_home_url + '/' + jsFile, (data) => {
				system.DYNJS[jsFile] = data;
				eval(data);
				sasoEventtickets_js_seating_admin(myAjax, getHelperFunktions()).initAdmin(div);
			});
		}

		return div;
	}
	function _displaySupportInfoArea() {
		STATE = 'support';
		DIV.html(_getSpinnerHTML());
		getOptionsFromServer(reply=>{
			let newline = '<br>';
			let div_stats = $('<div/>').html(_getSpinnerHTML());

			_makeGet('getSupportInfos', {}, infos=>{
				div_stats.html("");
				div_stats.append('<b>Codes:</b>: '+infos.amount.codes+newline);
				div_stats.append('<b>Lists:</b>: '+infos.amount.lists+newline);
				div_stats.append('<b>IPs:</b>: '+infos.amount.ips+newline);
			});

			let data = reply.options; // options values
			let versions = reply.versions;

			DIV.html(getBackButtonDiv());

			// zeige support email
			DIV.append(getUseFulVideosHTML);
			DIV.append('<h3>BETA Chat bot for fast answers</h3><p>Use our new chat bot to get fast answers. The bot is trained with the FAQ and the documentation. It can help you to find answers faster. The bot is in BETA and we are using ChatGPT for now - you need to login to use it (sorry).</p><p><a class="button button-secondary" href="https://chatgpt.com/g/g-6819d8f68338819193a4be7e7973cce0-event-tickets-support-gpt" target="_blank">Open Chat Bot</a></p>');
			DIV.append('<h3>Release notes</h3><p>You can find the release notes here: <span class="dashicons dashicons-external"></span><a href="https://vollstart.com/posts/category/eventticketupdates/" target="_blank">Release Notes</a></p>');
			DIV.append('<h3>Support Email</h3><b>support@vollstart.com</b>');
			DIV.append('<h3>Support Context Information</h3><p>'+__('Please copy the following information, so that we can support you better and faster. Remove any critical information if needed.', 'event-tickets-with-ticket-scanner')+'</p>');
			DIV.append('<b>Ticket Counter: </b> '+reply.infos.ticket.counter+newline);
			DIV.append('<b>Wordpress Version:</b> '+versions.wp+newline);
			DIV.append('<b>MySQL/Mariadb Version:</b> '+versions.mysql+newline);
			DIV.append('<b>PHP Version:</b> '+versions.php+newline);
			DIV.append('<b>Product:</b> Event Tickets with WooCommerce'+newline);
			DIV.append('<b>Basic Plugin Version:</b> '+versions.basic+newline);
			DIV.append('<b>Basic DB Version:</b> '+versions.db+newline);
			if (versions.premium != "") {
				DIV.append('<b>Premium Serial:</b> '+versions.premium_serial+newline);
				DIV.append('<b>Premium Plugin Version:</b> '+versions.premium+newline);
				DIV.append('<b>Premium DB Version:</b> '+versions.premium_db+newline);
			}
			DIV.append('<h4 style="margin-bottom:0;">Date</h4>');
			DIV.append('<b>Your default timezone: </b> '+versions.date_default_timezone+newline);
			DIV.append('<b>Your WP timezone: </b> '+versions.date_WP_timezone+newline);
			DIV.append('<b>Your WP timezone full: </b> '+versions.date_WP_timezone_time+newline);
			DIV.append('<b>Your date: </b> '+versions.date_default_timezone_time+newline);
			DIV.append('<b>UTC date: </b> '+versions.date_UTC_timezone_time+newline);

			DIV.append('<h4 style="margin-bottom:0;">Stats</h4>');
			DIV.append(div_stats);
			DIV.append('<h4 style="margin-bottom:0;">URLs</h4>');
			DIV.append('<b>Mulitsite: </b> '+reply.infos.site.is_multisite+newline);
			DIV.append('<b>Home: </b> '+reply.infos.site.home+newline);
			DIV.append('<b>Network home: </b> '+reply.infos.site.network_home+newline);
			DIV.append('<b>Site URL: </b> '+reply.infos.site.site_url+newline);

			DIV.append('<h4 style="margin-bottom:0;">Ticket URLs</h4>');
			//$wcTicketCompatibilityModeURLPath = trim(trim($wcTicketCompatibilityModeURLPath, "/"));
			DIV.append('<b>Ticket Detail Own URL Path: </b> '+reply.infos.site.home+"/"+_getOptions_getValByKey("wcTicketCompatibilityModeURLPath")+newline);
			DIV.append('<b>Ticket Scanner Own URL Path: </b> '+reply.infos.site.home+"/"+_getOptions_getValByKey("wcTicketCompatibilityModeURLPath")+'/scanner/'+newline);
			DIV.append('<b>Ticket Default Plugin Detail URL: </b> '+reply.infos.ticket.ticket_base_url+newline);
			DIV.append('<b>Ticket Default Plugin Scanner Path: </b> '+reply.infos.ticket.ticket_scanner_path+newline);
			DIV.append('<b>Ticket Detail Default Plugin Path: </b> '+reply.infos.ticket.ticket_detail_path+newline);
			DIV.append('<b>Ticket Scanner Default Plugin Path: </b> '+reply.infos.ticket.ticket_detail_path+'scanner/'+newline);

			let tabelle_errorlogs_datatable;
			DIV.append('<h3 style="margin-bottom:10px;">Error Logs</h3>');
			$('<div style="text-align:right;margin-bottom:10px;">')
				.append($('<button>').html(__('Refresh table', 'event-tickets-with-ticket-scanner')).addClass("button-secondary").on("click", ()=>{
					tabelle_errorlogs_datatable.ajax.reload();
				}))
				.append($('<button>').html(__('Empty table', 'event-tickets-with-ticket-scanner')).addClass("sngmbh_btn-delete").on("click", ()=>{
					LAYOUT.renderYesNo(__('Empty table', 'event-tickets-with-ticket-scanner'), sprintf(/* translators: %s: name of ticket table */__('Do you want to empty the "%s" table? All data will be lost.', 'event-tickets-with-ticket-scanner'), _x("Error Logs", 'title', 'event-tickets-with-ticket-scanner')), ()=>{
						LAYOUT.renderYesNo(__('Empty table - last chance', 'event-tickets-with-ticket-scanner'), sprintf(/* translators: %s: name of ticket table */__('Are you sure? You will not be able to restore the data, except you have a backup of your database. All data will be lost.', 'event-tickets-with-ticket-scanner'), _x("Error Logs", 'title', 'event-tickets-with-ticket-scanner')), ()=>{
							_makeGet('emptyTableErrorLogs', null, ()=>{
							tabelle_errorlogs_datatable.ajax.reload();
							});
						});
					});
				}))
				.appendTo(DIV);

			let div_tabelle = $('<div style="margin-bottom:20px;">').appendTo(DIV);

			let label_version = _x('Version', 'label', 'event-tickets-with-ticket-scanner');
			DIV.append('<h3 style="margin-bottom:10px;">Used Libraries</h3>');
			DIV.append('<p>'+__('The following libraries are used in the plugin.', 'event-tickets-with-ticket-scanner')+'</p>');
			DIV.append('<p>'+__('The libraries are used in the frontend and backend.', 'event-tickets-with-ticket-scanner')+'</p>');
			DIV.append('<ul>');
			DIV.append('<li><b>jQuery</b> - '+label_version+': '+jQuery.fn.jquery+'</li>');
			DIV.append('<li><b>jQuery UI</b> - '+label_version+': '+jQuery.ui.version+'</li>');
			DIV.append('<li><b>jQuery UI CSS</b> - '+label_version+': '+jQuery.ui.version+'</li>');
			DIV.append('<li><b>PHP TWIG template engine</b> https://twig.symfony.com/ - '+label_version+': 3.22.0</li>');
			DIV.append('<li><b>PHP QR Code</b> http://sourceforge.net/projects/phpqrcode/ - '+label_version+': 1.1.4</li>');
			DIV.append('<li><b>FPDI</b> '+label_version+': 2.3.7</li>');
			DIV.append('<li><b>FPDF</b> '+label_version+': 1.8.5</li>');
			DIV.append('<li><b>TCPDF</b> http://www.tcpdf.org - '+label_version+': 6.4.4</li>');
			DIV.append('<li><b>Javascript QR code scanner:</b> https://github.com/nimiq/qr-scanner - '+label_version+': 1.4.2</li>');
			DIV.append('<li><b>Javascript Datatable:</b> https://datatables.net/ - '+label_version+': 1.10.21</li>');
			DIV.append('<li><b>Javascript Raphael:</b> http://raphaeljs.com/ - '+label_version+': 2.3.0</li>');
			DIV.append('<li><b>Javascript Ace Editor</b></li>');
			DIV.append('<li><b>html5-qrcode:</b> https://github.com/mebjas/html5-qrcode/ - '+label_version+': 2.3.8</li>');

			DIV.append('<h3 style="margin-bottom:10px;">Options</h3>');
			// liste alle optionen mit wert auf
			data.forEach(v=>{
				if (v.type != 'heading' && v.key != "serial") {
					if (v.additional && v.additional.doNotRender && v.additional.doNotRender === 1) {}
					else {
						let value = v.value;
						let def = '';
						if (value == '') {
							def = ' (DEFAULT used)';
							value = v.default;
						}
						text = document.createTextNode(value);
						DIV.append(`<b>${v.key}${def}:</b> `).append(text).append(`${newline}`);
					}
				}
			});

			/*
			DIV.append('<h3 style="margin-bottom:0;">All available Options</h3>');
			let list_elem = $('<div>').appendTo(DIV);
			data.forEach(v=>{
				if (v.type != 'heading' && v.key != "serial" && v.type != "desc") {
					if (v.additional && v.additional.doNotRender && v.additional.doNotRender === 1) {}
					else {
						list_elem.append(v.key);
						list_elem.append(' - ');
						list_elem.append(v.label);
						if (v.desc != "") {
							list_elem.append(`${newline}`).append(v.desc);
						}
						list_elem.append(`${newline}`);
						list_elem.append(`${newline}`);
					}
				} else {
					if (v.type == 'heading') {
						list_elem.append(`${newline}`);
						list_elem.append('== '+v.label+' ==');
						if (v.desc != "") {
							//list_elem.append(`${newline}`).append(v.desc);
						}
						list_elem.append(`${newline}`);
					}
				}
			});
			*/

			// helper buttons
			$('<button/>').css("margin-top", "30px").addClass("sngmbh_btn-delete").html(_x("Repair tables", 'label', 'event-tickets-with-ticket-scanner')).appendTo(DIV).on("click", ()=>{
	    		LAYOUT.renderYesNo(__('Repair database tables?', 'event-tickets-with-ticket-scanner'), __('Do you realy want to try to repair your database table definitions for the plugin? It should be safe, but only needed in very rare cases. You might see errors messages during the page reload - that is normal. Why not asking support, if you should do it? ;)', 'event-tickets-with-ticket-scanner'), dlg=>{
					dlg.html(_getSpinnerHTML());
					dlg.dialog({
						title:_x('Repaired', 'title', 'event-tickets-with-ticket-scanner'), modal:true, dialogClass: "no-close",
						close: function(event, ui){ abort=true; },
						buttons: [
							{
								text: _x('Ok', 'label', 'event-tickets-with-ticket-scanner'),
								click: function() {
									$( this ).dialog( _x('Close', 'label', 'event-tickets-with-ticket-scanner') );
									$( this ).html('');
								}
							}
						]
					});
					_makePost('repairTables', {}, result=>{
						speakOutLoud(result, true);
						dlg.html(result);
					});
	    		});
			});

			function __renderTabelleErrorLogs() {
				div_tabelle.html(_getSpinnerHTML());
				let table_id = myAjax.divPrefix+'_tabelle_errorlogs';
				let tabelle = $('<table/>').attr("id", table_id);
				tabelle.html('<thead><tr><th></th><th align="left">'+_x('Created', 'label', 'event-tickets-with-ticket-scanner')+'</th><th align="left">'+_x('Exception', 'label', 'event-tickets-with-ticket-scanner')+'</th><th>'+_x('Function', 'label', 'event-tickets-with-ticket-scanner')+'</th></tr></thead>');
				div_tabelle.html(tabelle);

				let table = $('#'+table_id);
				$(table).DataTable().clear().destroy();
				tabelle_errorlogs_datatable = $(table).DataTable({
					"responsive": true,
					"searching": true,
					"ordering": true,
					"processing": true,
					"serverSide": true,
					"stateSave": false,
					"pageLength":50,
					"ajax": {
						url: _requestURL('getErrorLogs'),
						type: 'POST',
					},
					"order": [[ 1, "desc" ]],
					"columns":[
						{"data":null,"className":'details-control',"orderable":false,"defaultContent":'', "width":10},
						{"data":"time", "orderable":true, "width":80},
						{"data":"exception_msg", "orderable":true},
						{"data":"caller_name", "orderable":true},
					]
				});
				tabelle.css("width", "100%");
				$('#'+table_id+' tbody').on('click', 'td.details-control', e=>{
					var tr = $(e.target).parents('tr');
					var row = tabelle_errorlogs_datatable.row( tr );
					if ( row.child.isShown() ) {
						// This row is already open - close it
						row.child.hide();
						tr.removeClass('shown');
					} else {
						// Open this row
						let d = row.data();
						row.child( "#"+d.id+'<br><pre>'+destroy_tags(d.msg)+'</pre>' ).show();
						tr.addClass('shown');
					}

				});
			}
			__renderTabelleErrorLogs();

		});
	}

	/**
	 * returns 0 if the versions are the same, 1 if version1 is greater, -1 if version2 is greater
	 */
	function compareVersions(version1, version2) {
		const v1 = version1.split('.').map(Number);
		const v2 = version2.split('.').map(Number);

		for (let i = 0; i < Math.max(v1.length, v2.length); i++) {
			const num1 = v1[i] || 0;
			const num2 = v2[i] || 0;

			if (num1 > num2) return 1;
			if (num1 < num2) return -1;
		}

		/*
		// Example usage:
		const result = compareVersions('5.8.1', '5.8.2');
		if (result > 0) {
			console.log('Version 5.8.1 is greater than 5.8.2');
		} else if (result < 0) {
			console.log('Version 5.8.1 is less than 5.8.2');
		} else {
			console.log('Both versions are equal');
		}
		*/

		return 0;
	}

	function _displayOptionsArea() {
		STATE = 'options';
		DIV.html(_getSpinnerHTML());
		getOptionsFromServer(reply=>{
			let data = reply.options; // options values
			let meta_tags_keys = reply.meta_tags_keys;

			DIV.html(getBackButtonDiv());

			// Create tabs
			let tabs = $('<div class="tabs"/>');
			let tabOptions = $('<div id="tab-options" class="tab-content"/>');

			// Create tab navigation
			let tabNav = $('<ul class="tab-nav"/>');
			tabNav.append('<li><a href="#tab-options">Options</a></li>');
			if (isPremium() && typeof PREMIUM.displayOptionsArea_Templates !== "undefined") {
				tabNav.append(PREMIUM.displayOptionsArea_Tab);
			}

			tabs.append(tabNav);
			tabs.append(tabOptions);
			if (isPremium() && typeof PREMIUM.displayOptionsArea_Templates !== "undefined") {
				tabs.append(PREMIUM.displayOptionsArea_Templates(_getOptions_Versions_getByKey('premium')));
			}

			// seating plan tab
			let tabNavSeatingplan = $('<li><a href="#tab-seatingplan">Seating Plans</a></li>');
			tabNavSeatingplan.on("click", ()=>{
				tabSeatingplan.html(_displaySeatingplanArea());
			});
			tabNav.append(tabNavSeatingplan);
			let tabSeatingplan = $('<div id="tab-seatingplan" class="tab-content"/>');
			tabs.append(tabSeatingplan);

			DIV.append(tabs);

			// Populate Options tab
			let div_options = $('<div/>');
			let div_infos = $('<div style="padding-top: 50px;"/>');
			let resetOption_div = $('<div class="reset_option_wrap" style="padding-top: 20px;"/>');
			tabOptions.append(div_options);
			tabOptions.append('<hr>');
			tabOptions.append(resetOption_div);
			$('<button class="button reset_btn_actn">').html(_x('Reset All Options', 'label', 'event-tickets-with-ticket-scanner'))
				.on('click', ()=>{
					LAYOUT.renderYesNo(_x('Reset All Options', 'title', 'event-tickets-with-ticket-scanner'), __('Do you really want to reset all the option?', 'event-tickets-with-ticket-scanner'), ()=>{
						_makePost('resetOptions','', function(result) {
							if(result){
								_displayOptionsArea();
							}
						});
					});
				}).appendTo(resetOption_div);
			tabOptions.append(div_infos);
			div_infos.append('<a name="replacementtags"></a><h3>'+_x('Replacement Tags', 'title', 'event-tickets-with-ticket-scanner')+'</h3>').append('<p>'+__('You can use these replacement tags in your text messages and URLs for the meta ticket values', 'event-tickets-with-ticket-scanner')+'</p>');
			meta_tags_keys.forEach(v=>{
				let t = '<p><b>{'+v.key+'}</b>: '+v.label+'</p>';
				div_infos.append(t);
			});

			//div_options.append('<h3>'+_x('Options', 'title', 'event-tickets-with-ticket-scanner')+'</h3>');
			div_options.append('<p><span class="dashicons dashicons-external"></span><a href="https://vollstart.com/event-tickets-with-ticket-scanner/docs/" target="_blank">Click here, to visit the documentation.</a></p>');
			div_options.append(getUseFulVideosHTML());

			let menu_band = $('<div style="padding-top:10px;padding-bottom:15px;">').appendTo(div_options);
			let menu_values = [];
			data.forEach(v=>{
				if (v.type === "heading") {
					menu_values.push(v);
				}
			});
			menu_values.sort((a,b)=>{
				if(a.label < b.label) { return -1; }
    			if(a.label > b.label) { return 1; }
				return 0;
			});
			menu_values.forEach(v=>{
				$('<a href="#'+v.key+'" style="padding:5px;padding-left:0;margin-right:10px;">').html(v.label).appendTo(menu_band);
			});
			$('<a href="#topMenu" style="text-decoration:none;position:fixed;bottom:50px;right:10px;background-color:#b225cb;color:white;border-radius:15px;border:1 px solid blue;display:inline-block;padding:10px;">').html('<i class="dashicons dashicons-arrow-up"></i> Top').appendTo(div_options);

			// Add jQuery for tab functionality
			$('.tab-nav a').on('click', function(e) {
				e.preventDefault();
				$('.tab-content').hide();
				$($(this).attr('href')).show();
				$('.tab-nav a').removeClass('active');
				$(this).addClass('active');
			});

			// Show the first tab by default
			if (typeof PARAS.subdisplay !== "undefined" && PARAS.subdisplay == 'templates') {
				$('.tab-nav a').eq(1).click();
			} else {
				$('.tab-nav a:first').click();
			}

			function __createTicketTemplateChooserBox(ticket_template, editor) {
				return $('<div style="width:250px;display:inline-block;margin-right:5px;text-align:center;">')
						.append('<img style="width:250px;" src="'+myAjax._plugin_home_url+'/img/ticket_templates/'+ticket_template.image_url+'">')
						.append("<br>Zero-Padding: "+(ticket_template.wcTicketPDFZeroMarginTest ? "Yes" : "No"))
						.append(", Size: ("+ticket_template.wcTicketSizeWidthTest+'x'+ticket_template.wcTicketSizeHeightTest+")")
						.append("<br>")
						.append($('<button class="button button-primary">').text(__('Load template','event-tickets-with-ticket-scanner')).on("click", ()=>{
							LAYOUT.renderYesNo(_x('Load Template Ticket Code', 'title', 'event-tickets-with-ticket-scanner'),
								__('Do you want to replace the test ticket template code with this template?', 'event-tickets-with-ticket-scanner')+'<br><p><img style="width:250px;" src="'+myAjax._plugin_home_url+'/img/ticket_templates/'+ticket_template.image_url+'"></p><p>Following values will be changed:'
								+'<br><b>wcTicketPDFZeroMarginTest</b>: '+(ticket_template.wcTicketPDFZeroMarginTest ? "Yes" : "No")
								+'<br><b>wcTicketPDFisRTLTest</b>: '+(ticket_template.wcTicketPDFisRTLTest ? "Yes" : "No")
								+'<br><b>wcTicketSizeWidthTest</b>: '+ticket_template.wcTicketSizeWidthTest
								+'<br><b>wcTicketSizeHeightTest</b>: '+ticket_template.wcTicketSizeHeightTest
								+'<br><b>wcTicketQRSizeTest</b>: '+ticket_template.wcTicketQRSizeTest
								+'</p>'
								, ()=>{
									editor.wcTicketDesignerTemplateTest_editor.setValue(ticket_template.wcTicketDesignerTemplateTest);
									$('input[data-key="wcTicketPDFZeroMarginTest"').prop("checked",ticket_template.wcTicketPDFZeroMarginTest).trigger("change");
									$('input[data-key="wcTicketPDFisRTLTest"').prop("checked",ticket_template.wcTicketPDFisRTLTest).trigger("change");
									$('input[data-key="wcTicketSizeWidthTest"').val(ticket_template.wcTicketSizeWidthTest).trigger("change");
									$('input[data-key="wcTicketSizeHeightTest"').val(ticket_template.wcTicketSizeHeightTest).trigger("change");
									$('input[data-key="wcTicketQRSizeTest"').val(ticket_template.wcTicketQRSizeTest).trigger("change");
									let value = editor.wcTicketDesignerTemplateTest_editor.getValue().trim();
									_saveOptionValue("wcTicketDesignerTemplateTest", value);
									editor.wcTicketDesignerTemplateTest_btn.prop("disabled", true);

								});
						}));
			}

			// render die input felder
			function __getOptionByKey(key) {
				for(let a=0;a<data.length;a++) {
					if (key == data[a].key) return data[a];
				}
				return null;
			}

			let editor = {}; // for ace editor
			data.forEach(v=>{
				if (typeof v.additional !== "undefined" && v.additional.doNotRender) return;
				if (v.type == "heading") {
					let desc = v.desc;
					if (typeof v._doc_video !== "undefined" && v._doc_video != "") {
						desc += ' <span class="dashicons dashicons-external"></span> <a href="'+v._doc_video+'" target="_blank">Video Help</a>';
					}
					div_options.append('<hr>').append('<h3 id="'+v.key+'" '+(desc !== "" ? ' style="margin-bottom:0;"' : '')+'>'+v.label+'</h3>').append(desc !== "" ? '<div style="margin-bottom:15px;"><i>'+desc+'</i></div>':'');
				} else if (v.type =="desc") {
					let desc = v.desc+" ";
					if (typeof v._do_not_trim !== "undefined" && v._do_not_trim) {
						desc += 'To leave this value blank, enter a space. ';
					}
					if (typeof v._doc_video !== "undefined" && v._doc_video != "") {
						desc += '<span class="dashicons dashicons-external"></span> <a href="'+v._doc_video+'" target="_blank">Video Help</a>';
					}
					div_options.append('<div/>').css({"margin-bottom": "15px","margin-right": "15px"}).append('<b>'+v.label+'</b><br>'+desc+"<br>");
				} else {
					let elem_div = $('<div/>').css({"margin-bottom": "15px","margin-right": "15px"});
					let elem_input = $('<input type="'+v.type+'">');
					elem_input.attr("placeholder", v.default);
					if (typeof v.additional !== "undefined" && typeof v.additional.disabled !== "undefined") {
						elem_input.attr("disabled", true);
					}

					let cbf = null;
					let pcbf = null;
					let value = v.value;
					if (typeof v._do_not_trim !== "undefined" && v._do_not_trim) {
					} else {
						value = (""+v.value) !== "" ? (""+v.value).trim() : ""+v.default;
					}

					v.label = v.label + ' <span style="color:grey;">{'+v.key+'}</span>';
					if (typeof v._doc_video !== "undefined" && v._doc_video != "") {
						v.label += ' <span class="dashicons dashicons-external"></span> <a href="'+v._doc_video+'" target="_blank">Video Help</a>';
					}

					switch (v.type) {
						case "editor":
							elem_input = $('<div id="'+v.key+'_editor" style="height:'+(typeof v.additional !== "undefined" && typeof v.additional.height !== "undefined" ? v.additional.height : '500px')+';">').text(value.trim());
							break;
						case "textarea":
							elem_input = $('<textarea>');
							elem_input.attr("placeholder", v.default);
							//elem_input.val(value);
							elem_input.val(value);
							if (typeof v.additional !== "undefined" && typeof v.additional.rows !== "undefined") {
								elem_input.attr("rows", v.additional.rows);
							}
							break;
						case "checkbox":
							v.value = intval(v.value);
							elem_input.prop("checked",v.value === 1 ? true : false);
							elem_input.on("change", function(){
								_makePost('changeOption', {'key':v.key, 'value':elem_input[0].checked ? 1:0});
							});
							elem_div.html(elem_input).append(v.label).append(v.desc !== "" ? '<br><i>'+v.desc+'</i>':'');
							break;
						case "number":
							if (typeof v.additional.min !== "undefined") elem_input.attr("min", v.additional.min);
							break;
						case "dropdown":
							elem_input = $('<select>');
							if (v.additional.multiple) {
								elem_input.prop("multiple", true);
							}
							v.additional.values.forEach(_v=>{
								$('<option>').attr("value", _v.value).html(_v.label).appendTo(elem_input);
							});
							if (v.additional.multiple) {
								if (v.value.length == 0) {
									value = v.default;
								} else {
									value = v.value;
								}
							} else {
								if (value == "") value = 1;
							}
							elem_input.val(value);
							break;
						case "media":
							let image_info = $('<div>');
							let image = $('<image style="display:none;">');
							let image_btn_del = $('<button class="sngmbh_btn sngmbh_btn-delete" style="display:none;">').html(_x('Remove file', 'label', 'event-tickets-with-ticket-scanner'));
							image_btn_del.on('click', ()=>{
								LAYOUT.renderYesNo(_x('Remove file', 'title', 'event-tickets-with-ticket-scanner'), __('Do you really want to remove the file information from this option?', 'event-tickets-with-ticket-scanner'), ()=>{
									elem_input.val("");
									elem_input.trigger("change");
									_renderMedia(0, v, image_info, image, image_btn_del);
								});
							});
							if (typeof v.additional == "undefined") v.additional = {};
							if (v.additional.max) {
								if (v.additional.max.width) {
									image.css("max-width", v.additional.max.width+'px');
								}
								if (v.additional.max.height) {
									image.css("max-height", v.additional.max.height+'px');
								}
							}
							elem_input.attr("type", "hidden");
							let image_btn_add = $('<button style="display:block;" />').addClass("button-primary")
										.html(v.additional.button)
										.on("click", ()=>{
											let is_multiple = typeof v.additional.is_multiple != "undefined" ? v.additional.is_multiple : false;
											let imgContainer = null;
											let type_filter = typeof v.additional.type_filter != "undefined" ? v.additional.type_filter : null;
											_openMediaChooser(elem_input, is_multiple, imgContainer, type_filter);
										});
							$('<div/>').css({"margin-bottom": "15px","margin-right": "15px"})
								.html(v.label+'<br>')
								.append(image_btn_add)
								.append(v.desc !== "" ? '<i>'+v.desc+'</i>':'')
								.append(elem_input)
								.append(image_info)
								.append(image)
								.append(image_btn_del)
								.appendTo(elem_div);
							_renderMedia(value, v, image_info, image, image_btn_del);
							pcbf = function() {
								image_info.html(_getSpinnerHTML());
								image.css('display', 'none');
							}
							cbf = function () {
								let value = elem_input.val();
								_renderMedia(value, v, image_info, image, image_btn_del);
							}
							break;
					}

					if (v.type != "checkbox") {
						if (v.type != "media") {
							elem_div.html(v.label+'<br>').append(elem_input);
							let desc = v.desc+" ";
							if (typeof v._do_not_trim !== "undefined" && v._do_not_trim) {
								desc += 'To leave this value blank, enter a space. ';
							}
							desc = desc.trim();
							elem_div.append(desc !== "" ? '<br><i>'+desc+'</i>':'');
						}
						if (v.type != "number") {
							elem_input.css({"width":"90%"});
						}
						if (v.type != "dropdown" && v.type != "editor") {
							elem_input.attr("value",value);
						}
						if (v.type != "editor") {
							elem_input.on("change", ()=>{
								let value = elem_input.val();
								_saveOptionValue(v.key, value, cbf, pcbf);
							});
						}
					}

					elem_input.attr("data-key", v.key);

					if (v.key == "wcassignmentUseGlobalSerialFormatter") {
						let option = __getOptionByKey('wcassignmentUseGlobalSerialFormatter_values');
						let formatterValues = null;
						if (option.value != "") {
							try {
								formatterValues = JSON.parse(option.value);
							} catch (e) {
								//console.log(e);
							}
						}
						let extra_div = $('<div>').appendTo(elem_div).css("margin-top", "10px").css("margin-left", "50px").css("padding", "10px").css("border", "1px solid black");
						// render here den formatter
						let serialCodeFormatter = _form_fields_serial_format(extra_div);
						serialCodeFormatter.setNoNumberOptions();
						serialCodeFormatter.setFormatterValues(formatterValues);
						serialCodeFormatter.setCallbackHandle(_formatterValues=>{
							// speicher formatterValues
							_makePost('changeOption', {'key':'wcassignmentUseGlobalSerialFormatter_values', 'value':JSON.stringify(_formatterValues)});
						});
						serialCodeFormatter.render();
					}

					if (v.key == "wcTicketDesignerTemplate") {
						$('<button class="button button-primary">').html("Show Default Template").on("click", e=>{
							LAYOUT.renderInfoBox(_x('Ticket Default Template', 'title', 'event-tickets-with-ticket-scanner'), $('<textarea style="width:100%;height:400px">').val(v.default));
						}).appendTo(div_options);
					}

					if (v.type == "editor") {
						//https://ace.c9.io/#nav=howto
						let btn_group = $('<div>').prependTo(elem_div);
						editor[v.key+"_editor"] = null; // will be filled later
						editor[v.key+"_btn"] = $('<button class="button button-primary">').prop("disabled", true).html(_x('Save Template Code', 'title', 'event-tickets-with-ticket-scanner')).on("click", evt=>{
							let value = editor[v.key+"_editor"].getValue().trim();
							_saveOptionValue(v.key, value, cbf, pcbf);
							editor[v.key+"_btn"].prop("disabled", true);
						}).appendTo(btn_group);
						$('<button class="button button-danger">').html(_x('Copy Template Code To Live Code', 'title', 'event-tickets-with-ticket-scanner')).on("click", evt=>{
							LAYOUT.renderYesNo(_x('Replace Live Template Code', 'title', 'event-tickets-with-ticket-scanner'), __('Do you want to replace the live template code with the template code from the test?', 'event-tickets-with-ticket-scanner'), ()=>{
								let value = editor[v.key+"_editor"].getValue().trim();
								$('input[data-key="'+v.key.replace("Test", "")+'"').val(value).trigger("change");
								if (v.key == "wcTicketDesignerTemplateTest") {
									$('input[data-key="wcTicketPDFZeroMargin"').prop("checked",$('input[data-key="wcTicketPDFZeroMarginTest"').is(':checked')).trigger("change");
									$('input[data-key="wcTicketPDFisRTL"').prop("checked",$('input[data-key="wcTicketPDFisRTLTest"').is(':checked')).trigger("change");
									$('input[data-key="wcTicketSizeWidth"').val($('input[data-key="wcTicketSizeWidthTest"').val()).trigger("change");
									$('input[data-key="wcTicketSizeHeight"').val($('input[data-key="wcTicketSizeHeightTest"').val()).trigger("change");
									$('input[data-key="wcTicketQRSize"').val($('input[data-key="wcTicketQRSizeTest"').val()).trigger("change");
								}
							});
						}).appendTo(btn_group);

						if (v.key == "wcTicketDesignerTemplateTest") {
							let ticket_test_chooser = $('<div>');
							let ticket_template_chooser = $('<div style="padding-top:5px;padding-bottom:20px;">').html('<b>Templates</b><br>You can choose from the templates below to have a starting point.<br>').appendTo(ticket_test_chooser);
							let ticket_test_select = $('<select>').appendTo(ticket_test_chooser);
							let ticket_test_direct_input = $('<input type="text" style="width:180px;" placeholder="or enter a public ticket number">');
							// display the template thumbnails
							for(let a=0;a<reply.ticket_templates.length;a++) {
								let ticket_template = reply.ticket_templates[a];
								__createTicketTemplateChooserBox(ticket_template, editor).appendTo(ticket_template_chooser);
							}

							if (OPTIONS.tickets_for_testing.length > 0) {
								let option_values = [];
								for(let a=0;a<OPTIONS.tickets_for_testing.length;a++) {
									let ticket = OPTIONS.tickets_for_testing[a];
									let metaObj = null;
									try {
										metaObj = JSON.parse(ticket.meta);
									} catch(e) {}
									if (metaObj != null) {
										option_values.push({t:ticket, m:metaObj});
									}
								}
								if (option_values.length > 0) {
									for(let a=0;a<option_values.length;a++) {
										let item = option_values[a];
										$('<option value="'+item.m.wc_ticket._public_ticket_id+'">')
											.text("Order Id: "+item.t.order_id+" - "+item.m.wc_ticket._public_ticket_id+" - "+item.t._PRODUCT_NAME+" (#"+item.m.woocommerce.product_id+")")
											.attr("data-url-pdf", item.m.wc_ticket._url)
											.appendTo(ticket_test_select);
									}
									ticket_test_direct_input.appendTo(ticket_test_chooser);
									$('<button class="button button-primary" id="wcTicketDesignerTemplateTest_button_PDF">')
										.html(__('Preview Test Template Code as PDF', 'event-tickets-with-ticket-scanner')).
										appendTo(ticket_test_chooser).on("click", ()=>{
											let ticket_url = ticket_test_select.find(":selected").attr("data-url-pdf");
											let v = ticket_test_direct_input.val().trim();
											if (v != "") {
												ticket_url = reply.infos.ticket.ticket_base_url + v; // myAjax.ticket_base_url
											}
											iframe.attr("src", ticket_url+'?pdf&testDesigner=1&t='+time()+'&nonce='+DATA.nonce);
											iframe
												.css("width", "80%")
												.css("height", "500px")
												.css("margin-top", "10px")
												.css("display", "block");
										});
									let iframe = $('<iframe style="display:none;">').appendTo(ticket_test_chooser);
								} else {
									$('<option value="">').text(__("ticket cannot be used. Public Ticket Id missing.",'event-tickets-with-ticket-scanner')).appendTo(ticket_test_select);
								}
							} else {
								$('<option value="">').text(__("no ticket for preview available", 'event-tickets-with-ticket-scanner')).appendTo(ticket_test_select);
							}
							ticket_test_chooser.appendTo(elem_div);
						}
					}

					elem_div.appendTo(div_options);
				}
			});
			if (window.location.hash != "") {
				window.setTimeout(()=>{
					let h = window.location.hash;
					window.location.hash = "";
					window.location.hash = h;
				}, 250);
			}
			window.setTimeout(()=>{
				for(var k in editor) {
					if (k.substring(k.length -7) == "_editor") {
						editor[k] = ace.edit(k);
						//editor.wcTicketDesignerTemplateTest_editor.setTheme("ace/theme/monokai");
						editor[k].session.setMode("ace/mode/twig");
						editor[k].setShowPrintMargin(false);
						editor[k].commands.addCommand({name:'save', bindKey:{win:'Ctrl-S', mac:'Command-S'}, readOnly:false, exec:myEditor=>{
							myEditor.trigger("change");
						}});
						editor[k].session.on("change", delta=>{
							editor[k.replace("_editor", "_btn")].prop("disabled", false);
						});
					}
				}
			}, 250)

		});
	}

	function getSuffixFromFilename(filename) {
		let extension = filename.slice(filename.lastIndexOf('.') + 1);
		return extension;
	}
	function _renderMedia(mediaId, v, image_info, image, image_btn_del) {
		if (mediaId != "" && parseInt(mediaId) != 0) {
			_getMediaData(mediaId, data=>{
				let suffix = getSuffixFromFilename(data.url.replace(/^.*[\\\/]/,'')).toLowerCase();
				let info = suffix != "pdf" ? '('+data.meta.width+'x'+data.meta.height+')' : '';
				image_info.html('<b>'+_x('Title', 'title', 'event-tickets-with-ticket-scanner')+':</b> '+data.title+' '+info);
				if (v.additional.max && v.additional.msg_error_max) {
					if (v.additional.max.width && v.additional.msg_error_max.width && data.meta.width > v.additional.max.width) image_info.append('<div style="color:red;">'+v.additional.msg_error_max.width+'</div>');
					if (v.additional.max.height && v.additional.msg_error_max.height && data.meta.height > v.additional.max.height) image_info.append('<div style="color:red;">'+v.additional.msg_error_max.height+'</div>');
				}
				if (suffix != "pdf") {
					image.attr("src", data.url).css("display","block");
				}
				image_btn_del.css("display", "block");
			});
		} else {
			image_info.html("");
			image.css("display", "none");
			image_btn_del.css("display", "none");
		}
	}
	function _openMediaChooser(input_elem, multiple, imgContainer, typeFilter) {
		var image_frame;
 		if(image_frame){
     		image_frame.open();
		}
		if (!typeFilter) typeFilter = 'image';
		multiple ? multiple = true : multiple = false;
        // Define image_frame as wp.media object
		image_frame = wp.media({
			title: _x('Select Media', 'title', 'event-tickets-with-ticket-scanner'),
            multiple : multiple,
			library : {
            	type : typeFilter,
			}
		});

		image_frame.on('close',function() {
			// On close, get selections and save to the hidden input
			// plus other AJAX stuff to refresh the image preview
			var selection =  image_frame.state().get('selection');

			if (imgContainer) { // zeige erstes bild an
				var attachment = selection.first().toJSON();
				imgContainer.html( '<img src="'+attachment.url+'" style="max-width:100%;"/>' );
			}

			var gallery_ids = new Array();
			var my_index = 0;
			selection.each(function(attachment) {
				gallery_ids[my_index] = attachment['id'];
				my_index++;
			});
			var ids = gallery_ids.join(",");
			input_elem.val(ids);
			input_elem.trigger("change");
   		});

		image_frame.on('open',function() {
			// On open, get the id from the hidden input
			// and select the appropiate images in the media manager
			var selection =  image_frame.state().get('selection');
			var ids = input_elem.val().split(',');
			ids.forEach(function(id) {
				var attachment = wp.media.attachment(id);
				attachment.fetch();
				selection.add( attachment ? [ attachment ] : [] );
			});
		});
		image_frame.open();
	} // ende openmediachooser

	function getBackButtonDiv() {
		let div_buttons = $('<div class="event-tickets-with-ticket-scanner-topbar">');
		let div = $('<div/>').addClass("event-tickets-with-ticket-scanner-topbar-left").append(
			$('<button />')
				.addClass("event-tickets-with-ticket-scanner-back-btn")
				.html('<span class="event-tickets-with-ticket-scanner-back-icon">&lt;</span> ' + _x('Back', 'label', 'event-tickets-with-ticket-scanner'))
				.on("click", ()=>{ LAYOUT.renderAdminPageLayout(); }
			)
		);
		div_buttons.append(div);
		div_buttons.append(_displaySettingAreaButton());
		return div_buttons;
	}

	function _getTicketScannerURL() {
		let url = _getOptions_Infos_getByKey('ticket').ticket_scanner_path;
		let _urlpath = _getOptions_getValByKey("wcTicketCompatibilityModeURLPath");
		if (_urlpath != "") {
			url = OPTIONS.infos.site.home+"/"+_urlpath+'/scanner/?code=';
		} else {
			url = OPTIONS.infos.ticket.ticket_scanner_url;
		}
		return url;
	}
	function _displaySettingAreaButton() {
		let btn_grp = $('<nav id="topMenu"/>')
			.addClass("event-tickets-with-ticket-scanner-topmenu")
			.attr("aria-label", "Event Tickets navigation");
		$('<button/>')
			.addClass('event-tickets-with-ticket-scanner-topmenu-item')
			.toggleClass('event-tickets-with-ticket-scanner-topmenu-item-active', STATE === 'support')
			.html(_x("Support Info", 'label', 'event-tickets-with-ticket-scanner'))
			.on("click", () => {
				_displaySupportInfoArea();
			})
			.appendTo(btn_grp);
		$('<button/>')
			.addClass("event-tickets-with-ticket-scanner-topmenu-item")
			.toggleClass('event-tickets-with-ticket-scanner-topmenu-item-active', STATE === 'faq')
			.html(_x("FAQ", 'label', 'event-tickets-with-ticket-scanner'))
			.on("click", ()=>{
				_displayFAQArea();
			}).appendTo(btn_grp);
		//if (_getOptions_Versions_isActivatedByKey('is_wc_available')) {
			$('<button/>').addClass("event-tickets-with-ticket-scanner-topmenu-item").html(_x("Ticket Scanner", 'label', 'event-tickets-with-ticket-scanner'))
			.on("click", ()=>{
				let url = _getTicketScannerURL();
				window.open(url, 'ticketscanner');
			})
			.appendTo(btn_grp);
		//}
		$('<button/>')
			.addClass("event-tickets-with-ticket-scanner-topmenu-item")
			.toggleClass('event-tickets-with-ticket-scanner-topmenu-item-active', STATE === 'authtokens')
			.html(_x('Auth Token', 'label', 'event-tickets-with-ticket-scanner'))
			.on("click", ()=>{
				_displayAuthTokensArea();
			}).appendTo(btn_grp);
		$('<button/>')
			.addClass("event-tickets-with-ticket-scanner-topmenu-item")
			.toggleClass('event-tickets-with-ticket-scanner-topmenu-item-active', STATE === 'options')
			.html(_x('Options', 'label', 'event-tickets-with-ticket-scanner'))
			.on("click", ()=>{
				_displayOptionsArea();
			}).appendTo(btn_grp);

		if (isPremium()) {
			btn_grp = PREMIUM.displaySettingAreaButton(btn_grp);
		}
		return btn_grp;
	}

	function _form_fields_serial_format(appendToDiv) {
		let input_prefix_codes;
		let input_type_codes;
		let input_amount_letters;
		let input_letter_excl;
		let input_letter_style;
		let input_include_numbers;
		let input_serial_delimiter;
		let input_serial_delimiter_space;
		let input_number_start;
		let input_number_offset;

		let noNumbersOptions = false;
		let cbk = null;
		let formatterValues;

		function _setNoNumberOptions() {
			noNumbersOptions = true;
		}
		function _setCallbackHandle(_cbk) {
			cbk = _cbk;
		}
		function _callCallbackHandle() {
			cbk && cbk(_getFormatterValues());
		}
		function _setFormatterValues(values) {
			formatterValues = values;
		}

		function __render() {
			$('<br>').appendTo(appendToDiv);
			// prefix
			let div_prefix_codes = _createDivInput(_x("Enter a prefix (optional)", 'label', 'event-tickets-with-ticket-scanner')).appendTo(appendToDiv);
				input_prefix_codes = $('<input type="text">').appendTo(div_prefix_codes);
				$('<div>').html(__('You can use date placeholder to have the prefix filled with the date of the confirmed purchase.', 'event-tickets-with-ticket-scanner')+'<br>'+__('You can use: {Y} = year, {m} = month, {d} = day, {H} = hour, {i} = minutes, {s} = seconds, {TIMESTAMP} = unix timestamp.', 'event-tickets-with-ticket-scanner')).appendTo(div_prefix_codes);
				if (formatterValues && formatterValues['input_prefix_codes'] != null) input_prefix_codes.val(formatterValues['input_prefix_codes']);
				input_prefix_codes.on("change", ()=>{
					_callCallbackHandle();
				});
			// type numbers/serials
			let div_type_codes = _createDivInput(_x("Choose type of ticket numbers", 'label', 'event-tickets-with-ticket-scanner')).appendTo(appendToDiv);
			input_type_codes = $('<select><option value="1" selected>'+_x('Serials', 'option value', 'event-tickets-with-ticket-scanner')+'</option><option value="2">'+_x('Numbers', 'option value', 'event-tickets-with-ticket-scanner')+'</option></select>').appendTo(div_type_codes);
			if (formatterValues && formatterValues['input_type_codes'] != null) input_type_codes.val(formatterValues['input_type_codes']);

			if (noNumbersOptions) {
				input_type_codes.prop("disabled", true);
			}
			input_type_codes.on("change", function() {
				if (input_type_codes.val() === "2") {
					div_serials && div_serials.find("input").prop("disabled", true);
					div_serials && div_serials.find("select").prop("disabled", true);
					div_numbers && div_numbers.find("input").prop("disabled", false);
					div_numbers && div_numbers.find("select").prop("disabled", false);
				} else {
					div_serials && div_serials.find("input").prop("disabled", false);
					div_serials && div_serials.find("select").prop("disabled", false);
					div_numbers && div_numbers.find("input").prop("disabled", true);
					div_numbers && div_numbers.find("select").prop("disabled", true);
				}
				_callCallbackHandle();
			});
			// serials options
			let div_serials = $('<div>').html('<h4>'+_x('Serials options', 'title', 'event-tickets-with-ticket-scanner')+'</h4>').appendTo(appendToDiv);
				// anzahl letters
				let div_amount_letters = _createDivInput(_x('Amount of letter needed', 'label', 'event-tickets-with-ticket-scanner')).appendTo(div_serials);
				input_amount_letters = $('<input type="number" required value="21" min="1" max="30">').appendTo(div_amount_letters);
				if (formatterValues && formatterValues['input_amount_letters'] != null) input_amount_letters.val(formatterValues['input_amount_letters']);
				input_amount_letters.on("change", function(){
					input_serial_delimiter.trigger("change");
					_callCallbackHandle();
				});
				// select letter exclusion
				let div_letter_excl = _createDivInput(_x('Letter exclusion', 'label', 'event-tickets-with-ticket-scanner')).appendTo(div_serials);
				input_letter_excl = $('<select><option value="1">'+_x('None', 'option value', 'event-tickets-with-ticket-scanner')+'</option><option value="2" selected>i,l,o,p,q</option></select>').appendTo(div_letter_excl);
				if (formatterValues && formatterValues['input_letter_excl'] != null) input_letter_excl.val(formatterValues['input_letter_excl']);
				input_letter_excl.on("change", ()=>{
					_callCallbackHandle();
				});
				// radio button text gross/klein/both/none
				let div_letter_style = _createDivInput(_x('Letter style', 'label', 'event-tickets-with-ticket-scanner')).appendTo(div_serials);
				input_letter_style = $('<select><option value="1" selected>'+_x('Uppercase', 'option value', 'event-tickets-with-ticket-scanner')+'</option><option value="2">'+_x('Lowercase', 'option value', 'event-tickets-with-ticket-scanner')+'</option><option value="3">'+_x('Both', 'option value', 'event-tickets-with-ticket-scanner')+'</option></select>').appendTo(div_letter_style);
				if (formatterValues && formatterValues['input_letter_style'] != null) input_letter_style.val(formatterValues['input_letter_style']);
				input_letter_style.on("change", ()=>{
					_callCallbackHandle();
				});
				// radio button numbers/none
				let div_include_numbers = _createDivInput(_x('Numbers needed?', 'label', 'event-tickets-with-ticket-scanner')).appendTo(div_serials);
				input_include_numbers = $('<select><option value="1">'+_x('No', 'label', 'event-tickets-with-ticket-scanner')+'</option><option value="2" selected>'+_x('Yes', 'label', 'event-tickets-with-ticket-scanner')+'</option><option value="3">'+_x('Only numbers', 'option value', 'event-tickets-with-ticket-scanner')+'</option></select>').appendTo(div_include_numbers);
				if (formatterValues && formatterValues['input_include_numbers'] != null) input_include_numbers.val(formatterValues['input_include_numbers']);
				input_include_numbers.on("change", ()=>{
					_callCallbackHandle();
				});
				// select delimiter none/-/./space
				let div_serial_delimiter = _createDivInput(_x('Delimiter?', 'label', 'event-tickets-with-ticket-scanner')).appendTo(div_serials);
				input_serial_delimiter = $('<select><option value="1">'+_x('None', 'option value', 'event-tickets-with-ticket-scanner')+'</option><option value="2" selected>-</option><option value="4">:</option><option value="3">'+_x('Space', 'option value', 'event-tickets-with-ticket-scanner')+'</option></select>').appendTo(div_serial_delimiter);
				if (formatterValues && formatterValues['input_serial_delimiter'] != null) input_serial_delimiter.val(formatterValues['input_serial_delimiter']);
				function __refreshDelimiterSpace() {
					input_serial_delimiter_space.html("");
					if (input_serial_delimiter.val() !== "1") {
						let anzahl = parseInt(input_amount_letters.val(),10);
						if (anzahl > 0) {
							for(let a=1;a<anzahl;a++) input_serial_delimiter_space.append($('<option'+(anzahl > 2 && a === 7 ? " selected": "")+'>').attr("value",a).html(a));
						}
					}
				}
				input_serial_delimiter.on("change", function(){
					__refreshDelimiterSpace();
					_callCallbackHandle();
				});
				// choose delimiter space
				let div_serial_delimiter_space = _createDivInput(_x('After how many letters?', 'label', 'event-tickets-with-ticket-scanner')).appendTo(div_serials);
				input_serial_delimiter_space = $('<select></select>').appendTo(div_serial_delimiter_space);
				if (formatterValues && formatterValues['input_serial_delimiter'] != null) {
					// setze Werte erstmal ein
					__refreshDelimiterSpace();
				}
				if (formatterValues && formatterValues['input_serial_delimiter_space'] != null) input_serial_delimiter_space.val(formatterValues['input_serial_delimiter_space']);
				input_serial_delimiter_space.on("change", ()=>{
					_callCallbackHandle();
				});
			// numbers options
			let div_numbers = $('<div>').html('<h4>'+_x('Numbers options', 'title', 'event-tickets-with-ticket-scanner')+'</h4>').appendTo(appendToDiv);
				if (noNumbersOptions) div_numbers.css("display","none");
				// number start
				let div_number_start = _createDivInput(_x('Start number', 'label', 'event-tickets-with-ticket-scanner')).appendTo(div_numbers);
				input_number_start = $('<input type="number" disabled required value="10000" min="1">').appendTo(div_number_start);
				if (formatterValues && formatterValues['input_number_start'] != null) input_number_start.val(formatterValues['input_number_start']);
				input_number_start.on("change", ()=>{
					_callCallbackHandle();
				});
				// number offset
				let div_number_offset = _createDivInput(_x('Offset for each number', 'label', 'event-tickets-with-ticket-scanner')).appendTo(div_numbers);
				input_number_offset = $('<input type="number" disabled required value="1" min="1">').appendTo(div_number_offset);
				if (formatterValues && formatterValues['input_number_offset'] != null) input_number_offset.val(formatterValues['input_number_offset']);
				input_number_offset.on("change", ()=>{
					_callCallbackHandle();
				});
		}

		function __generateCode(length, cases, withnumbers, exclusion) {
			let charset = 'abcdefghijklmnopqrstuvwxyz';
			if (cases === 1) charset = charset.toUpperCase();
			if (cases === 3) charset += charset.toUpperCase();
		    if (withnumbers === 2) charset += '0123456789';
		    if (withnumbers === 3) charset = '0123456789';
		    if (typeof exclusion !== "undefined") {
		    	exclusion.forEach(function(v){
		    		let regex = new RegExp(v, 'gi');
		    		charset = charset.replace(regex, "");
		    	});
		    }
		    let retVal = "";
		    for (var i = 0, n = charset.length; i < length; ++i) {
		        retVal += charset.charAt(Math.floor(Math.random() * n));
		    }
		    return retVal;
		}
		function __insertSeperator(str, serial_delimiter, serial_delimiter_space) {
			if (str !== "" && serial_delimiter !== "" && serial_delimiter_space > 0) {
				let result = [str[0]];
				for(let x=1; x<str.length; x++) {
	    			if (x%serial_delimiter_space === 0) {
	      				result.push(serial_delimiter, str[x]);
	     			} else {
	      				result.push(str[x]);
	     			}
	  			}
				return result.join('');
			}
			return str;
		}

		function _isTypeNumbers() {
			return input_type_codes.val()  === "2";
		}
		function _getPrefix() {
			return input_prefix_codes.val().trim();
		}
		function _getAmountLetters() {
			let amount_letters = parseInt(input_amount_letters.val().trim(),10);
			if (isNaN(amount_letters) || amount_letters < 1) {
				input_amount_letters.select();
				return alert(__("Amount of letters has to be higher", 'event-tickets-with-ticket-scanner'));
			}
			return amount_letters;
		}
		function _getLetterExclusion() {
			return input_letter_excl.val() === "2" ? ['i','l','o','p','q'] : [];
		}
		function _getLetterStyle() {
			return parseInt(input_letter_style.val(),10);
		}
		function _getIncludeNumbers() {
			return parseInt(input_include_numbers.val(),10);
		}
		function _getSerialDelimiter() {
			return ['','-',' ',':'][parseInt(input_serial_delimiter.val(),10)-1];
		}
		function _getSerialDelimiterSpace() {
			let serial_delimiter_space = 0;
			try {
				serial_delimiter_space = _getSerialDelimiter() !== "" ? parseInt(input_serial_delimiter_space.val(),10) : 0;
			} catch (e) {}
			return serial_delimiter_space;
		}
		function _getNumberStart() {
			let start_number = parseInt(input_number_start.val().trim(),10);
			if (isNaN(start_number) || start_number < 1) {
				input_number_start.select();
				return alert(__("Your start number is not correct. It has to be an integer bigger than 0", 'event-tickets-with-ticket-scanner'));
			}
			return start_number;
		}
		function _getNumberOffset() {
			let number_offset = parseInt(input_number_offset.val().trim(),10);
			if (isNaN(number_offset) || number_offset < 1) number_offset = 1;
			return number_offset;
		}
		function _generateSerialCode(offsetCounter) {
			let code;
			let prefix = _getPrefix();
			if (_isTypeNumbers()) { // numbers
				if (!offsetCounter) offsetCounter = 0;
				let number_offset = offsetCounter * _getNumberOffset();
				code = _getNumberStart() + number_offset;
				if (prefix !== '') code = prefix + code;
			} else {
				code = __generateCode(_getAmountLetters(), _getLetterStyle(), _getIncludeNumbers(), _getLetterExclusion());
				code = __insertSeperator(code, _getSerialDelimiter(), _getSerialDelimiterSpace());
				if (prefix !== '') code = prefix + code;
			}
			return code;
		}
		function _getFormatterValues() {
			return {
				input_prefix_codes:_getPrefix().replace('/', '-'),
				input_type_codes:input_type_codes.val(),
				input_amount_letters:_getAmountLetters(),
				input_letter_excl:input_letter_excl.val(),
				input_letter_style:_getLetterStyle(),
				input_include_numbers:input_include_numbers.val(),
				input_serial_delimiter:input_serial_delimiter.val(),
				input_serial_delimiter_space:input_serial_delimiter_space.val(),
				input_number_start:_getNumberStart(),
				input_number_offset:_getNumberOffset()
			};
		}

		return {
			render:__render,
			getAmountLetters:_getAmountLetters,
			getLetterExclusion:_getLetterExclusion,
			getLetterStyle:_getLetterStyle,
			getIncludeNumbers:_getIncludeNumbers,
			getSerialDelimiter:_getSerialDelimiter,
			getSerialDelimiterSpace:_getSerialDelimiterSpace,
			getNumberStart:_getNumberStart,
			getNumberOffset:_getNumberOffset,
			isTypeNumbers:_isTypeNumbers,
			getPrefix:_getPrefix,
			generateSerialCode:_generateSerialCode,
			setNoNumberOptions:_setNoNumberOptions,
			getFormatterValues:_getFormatterValues,
			setCallbackHandle:_setCallbackHandle,
			setFormatterValues:_setFormatterValues
		};
	}

	function _createDivInput(label) {
		return $('<div/>').css({
			"display": "inline-block",
		    "margin-bottom": "15px",
		    "margin-right": "15px"
		}).html(label+"<br>");
	}

	function __showFirstSteps() {
		let infoBox = null;
		// check if option displayFirstStepsHelp is set and active
		if (_getOptions_isActivatedByKey("displayFirstStepsHelp")) {
			// render info box with the first steps instructions.
			infoBox = $('<div style="background:#f9f9f9;border:1px solid #ddd;border:2px solid blue;padding:15px;margin-top:20px;margin-bottom:20px;box-shadow: 0 2px 5px rgba(0,0,0,0.1);border-radius:10px;"/>');
			infoBox.append($('<h3/>').html(_x('First Steps', 'title', 'event-tickets-with-ticket-scanner')));
			$('<p/>').html(__('The basic steps to sell tickets, no matter which use case you have.', 'event-tickets-with-ticket-scanner')).appendTo(infoBox);
			let ul = $('<ol/>').appendTo(infoBox);
			$('<li/>').html(__('Create a list of tickets if none exists.<br>You can create different lists for different events or purposes. The ticket list need to be assigned to the product.', 'event-tickets-with-ticket-scanner')).appendTo(ul);
			$('<li/>').html(__('Go to the WooCommerce products and add/change a product.', 'event-tickets-with-ticket-scanner')).appendTo(ul);
			$('<li/>').html(__('Open the Event Ticket tab and activate the product to be a ticket and assign a ticket list.', 'event-tickets-with-ticket-scanner')).appendTo(ul);
			$('<li/>').html(__('Adjust the ticket informations if needed.', 'event-tickets-with-ticket-scanner')).appendTo(ul);
			$('<p/>').html(__('If you want to specialize your tickets, checkout our use case videos and also take a look at the options area with more than 200 options.', 'event-tickets-with-ticket-scanner')).appendTo(infoBox);
			$('<p/>').html(__('If you need help, please contact us via email. The information is in "Support Info" area - button above.', 'event-tickets-with-ticket-scanner')).appendTo(infoBox);
			infoBox.append(getUseFulVideosHTML());
			let btn_dont_show = $('<button class="button button-secondary" style="margin-top:10px;"/>').html(_x("Don't show this again", 'label', 'event-tickets-with-ticket-scanner')).appendTo(infoBox);
			btn_dont_show.on("click", function(){
				_saveOptionValue("displayFirstStepsHelp", "0", ()=>{
					infoBox.slideUp(300, function(){
						infoBox.remove();
					});
				});
			});
		}
		return infoBox; // jquery object
	}

	class Layout {
		constructor(){
			DIV.addClass("sngmbh_container");
			this.div_liste = $('<div style="background:white;padding:15px;border-radius:15px;"/>').html(_getSpinnerHTML());
			this.div_codes = $('<div style="background:white;padding:15px;border-radius:15px;"/>').html(_getSpinnerHTML());
			this.div_spinner = $('<div style="display: none;position: fixed;z-index: 1031;top: 50%;right: 50%;margin-top: 0.5vh;background-color: white;margin-left: 0.5vw;border: 4px solid #2e74b5;padding: 10px;border-radius:10%;"/>').html(_getSpinnerHTML("loading"));
			$("body").append(this.div_spinner);
		}
		renderMainBody() {
			let infoBoxFirstSteps = __showFirstSteps();

			// display upgrade to premium link
			if (!isPremium()) {
				let btn_upgrade = $('<a/>')
					.html('<img src="'+myAjax._plugin_home_url+'/img/button_premium_icon.gif" alt="" class="event-tickets-with-ticket-scanner-upgrade-icon">' + _x('Upgrade', 'label', 'event-tickets-with-ticket-scanner'))
					.addClass("event-tickets-with-ticket-scanner-upgrade-btn")
					.attr("href", getPremiumProductURL())
					.attr("target", "_blank");
				$('body').find('#event-tickets-with-ticket-scanner-header-actions').html(btn_upgrade);
			}

			let div_body = $('<div/>');
			div_body.append(
				$('<div class="event-tickets-with-ticket-scanner-topbar">')
					.html($('<div/>').addClass("event-tickets-with-ticket-scanner-topbar-left"))
					.append(_displaySettingAreaButton())
			);
			if (infoBoxFirstSteps) {
				div_body.append(infoBoxFirstSteps);
			}
			div_body.append($('<h3/>').html(_x('List of tickets', 'title', 'event-tickets-with-ticket-scanner')));
			div_body.append($('<p/>').html(__("Organize your tickets in lists. You can assign tickets to a list.", 'event-tickets-with-ticket-scanner')));
			div_body.append(this.div_liste);
			div_body.append($('<hr/>'));
			div_body.append($('<h3/>').html(_x("Event Tickets", 'title', 'event-tickets-with-ticket-scanner')));
			div_body.append(this.div_codes);
			return div_body;
		}
		renderAddCodes() {
			DIV.html(_getSpinnerHTML());
			getDataLists(()=>{
				function __generateCodes() {
					// generate codes and
					let amount_codes = parseInt(input_amount_codes.val().trim(),10);
					if (isNaN(amount_codes) || amount_codes < 1) {
						input_amount_codes.select();
						return alert(_x("Enter an amount of how many ticket numbers you need", 'title', 'event-tickets-with-ticket-scanner'));
					}
					if (amount_codes > _maxCodes) {
						input_amount_codes.val(_maxCodes);
						amount_codes = _maxCodes;

					}
					let uniq = {};
					let versuche = 0;
					if (serialCodeFormatterForm.isTypeNumbers()) { // numbers
						for(let a=0; a < amount_codes; a++) {
							let code = serialCodeFormatterForm.generateSerialCode( a );
							if (typeof uniq[code] !== "undefined") {
								continue;
							}
							uniq[code] = true;
						}
						versuche = amount_codes;
					} else {
						// erstmal kein check ob mit dem alphabet und die geforderte Menge an letters, unique codes erstellt werden können
						let counter = 0;
						let versuche_max = amount_codes * 1.5;
						while(counter < amount_codes && versuche < versuche_max) {
							versuche++;
							let code = serialCodeFormatterForm.generateSerialCode();
							if (typeof uniq[code] !== "undefined") {
								continue;
							}
							uniq[code] = true;
							counter++;
						}
					}
					return [Object.keys(uniq), versuche];
				} // __generateCodes

				let div = $('<div>').append(getBackButtonDiv());
				// eingabe generator options
				let div_generator = $('<div/>').css("padding", "10px").css("border","1px solid black").html('<h3>'+_x('1. Ticket number generator (optional step)', 'title', 'event-tickets-with-ticket-scanner')+'</h3>').appendTo(div);
				div_generator.append($('<p>').html(__("You can generate ticket numbers.", 'event-tickets-with-ticket-scanner')));
				if (isPremium()) div_generator.append('<p>'+__('Up 100.000 tickets generation per run. The limit is to prevent performance issues.', 'event-tickets-with-ticket-scanner')+'<br>'+__('You can repeat the "store tickets" operations as often as needed.', 'event-tickets-with-ticket-scanner')+'</p>');
				// anzahl codes
				let div_amount_codes = _createDivInput(_x('Enter amount of needed ticket numbers', 'label', 'event-tickets-with-ticket-scanner')).appendTo(div_generator);
				let _maxCodes = myAjax._max.codes;
				if (!isPremium()) div_amount_codes.append(sprintf(/* translators: 1: amount of possible codes 2: premium info */__('%1$d max. %2$s up to 100.000 for each run', 'event-tickets-with-ticket-scanner'), _maxCodes, getLabelPremiumOnly())+'<br>');
				let input_amount_codes = $('<input type="number" required value="100" min="1" max="'+_maxCodes+'">').appendTo(div_amount_codes);

				// predefine elements
				let serialCodeFormatterForm = _form_fields_serial_format(div_generator);
				serialCodeFormatterForm.render();

				let elem_clean_codebox = $('<input checked type="checkbox" />');
				$('<div/>').css({"margin-bottom": "15px","margin-right": "15px"})
					.html(elem_clean_codebox)
					.append(_x('Clear the ticket numbers list textarea field below to add fill in the new generated ticket numbers', 'label', 'event-tickets-with-ticket-scanner'))
					.appendTo(div_generator);

				let elem_create_cvv = $('<input type="checkbox" />');
				$('<div/>').css({"margin-bottom": "15px","margin-right": "15px"})
					.html(elem_create_cvv)
					.append(_x('Generate Code Verification Value (CVV) for each ticket number', 'label', 'event-tickets-with-ticket-scanner'))
					.appendTo(div_generator);

				// button generate
				div_generator.append($('<button/>').addClass("button-secondary").html(_x('Generate ticket numbers', 'label', 'event-tickets-with-ticket-scanner')).on("click", function(){
					let time_start = performance.now();
					btn_store_codes.prop("disabled", false);
					input_textarea.prop("disabled", false);
					if (elem_clean_codebox[0].checked) {
						input_textarea.html("");
					}
					input_textarea.prop("disabled", true);
					div_textarea_info.css("padding-bottom", "50px").html(_getSpinnerHTML());
					setTimeout(function(){
						let r = __generateCodes();
						let codes = r[0];
						let secs = ((performance.now() - time_start) / 1000)+"";
						if (elem_create_cvv[0].checked) {
							codes = codes.map(v=>{
								return v += ';'+(Math.floor(Math.random() * 10000) + 10000).toString().substring(1);
							});
						}
						input_textarea.append(codes.join("\n")).append("\n");
						input_textarea.prop("disabled", false);
						div_textarea_info.html(sprintf(/* translators: 1: amount of created tickets 2: seconds 3: amount of runs */__('Created %1$d tickets. In %2$s seconds, with %3$d runs to find unique ticket numbers.', 'event-tickets-with-ticket-scanner'), codes.length, secs.slice(0,5), r[1]));
						_calcLinesOfCodeTextArea();
					},250);
				}));

				// eingabe maske textarea
				function _calcLinesOfCodeTextArea() {
					let codesAmount = 0;
					input_textarea.val().trim().split('\n').forEach(v=>{
						if (v.trim() !== "") codesAmount++;
					});
					input_textarea_info.html(sprintf(/* translators: %d: amout of ticket numbers */__('contains %d tickets', 'event-tickets-with-ticket-scanner'), codesAmount));
				}
				let div_textarea = $('<div/>').html('<h3>'+_x('2. Ticket numbers to store on the server', 'title', 'event-tickets-with-ticket-scanner')+'</h3><p>'+__('One number per line and/or comma-separated (,). <br>If you want to add the CVV number then separate your ticket number with (;) and append your CVV number.<br>While storing the numbers to the server, it will check if the ticket number is unique and mark the ones, that are not.', 'event-tickets-with-ticket-scanner')+'</p>').appendTo(div);
				let div_textarea_info = $('<div/>').appendTo(div_textarea);
				let input_textarea = $('<textarea>').change(_calcLinesOfCodeTextArea).css("height","135px").css("width","100%").appendTo(div_textarea);
				let input_textarea_info = $('<div/>').appendTo(div_textarea);
				div_textarea.append("<br>");
				_calcLinesOfCodeTextArea();
				// list auswahl
				let div_code_list = _createDivInput(_x('Assign to this ticket list', 'label', 'event-tickets-with-ticket-scanner')).appendTo(div_textarea);
				let input_code_list = $('<select><option value="0">'+_x('None', 'option value', 'event-tickets-with-ticket-scanner')+'</select></select>').appendTo(div_code_list);
				DATA_LISTS.forEach(v=>{
					input_code_list.append('<option value="'+v.id+'">'+v.name+'</option>');
				});
				div_textarea.append("<br>");

				// additional prem fields
				if (isPremium() && PREMIUM.addAddCodeFields) {
					div_textarea.append(PREMIUM.addAddCodeFields());
				}

				// button store codes
				if (!isPremium()) div_textarea.append('<b>'+sprintf(/* translators: 1: max amout of ticket numbers 2: premium info */__('You can store up to %1$d. %2$s unlimited', 'event-tickets-with-ticket-scanner'), myAjax._max.codes_total, getLabelPremiumOnly())+'<br>');
				let btn_store_codes = $('<button/>');
				btn_store_codes.addClass("button-primary").html(_x('Store ticket numbers', 'label', 'event-tickets-with-ticket-scanner')).on("click", function(){
					// extract codes and
					let codes = [];
					let codesLines = input_textarea.val().split("\n").map(x=>x.trim());
					codesLines.forEach(x=>{
						x.split(",").forEach(y=>{
							y = y.trim();
							y = destroy_tags(y);
							if (y != "") codes.push(y);
						});
					});
					if (codes.length === 0) return;

					// sperre btn store codes
					btn_store_codes.prop("disabled", true);
					input_textarea.prop("disabled", true);

					div_textarea_info.append($('<div/>').addClass("notice notice-info").html(__("Each entry will turn green (successfull stored) or red (NOT OK - duplicat entry on the server).<br>Scroll down and wait for all to finish.<br>In the textarea below you will find all the successful stored tickets.", 'event-tickets-with-ticket-scanner')));
					let _output = $('<ol/>').appendTo(div_textarea_info);
					div_textarea_info.append('<h3>'+_x('Successfull stored ticket numbers', 'title', 'event-tickets-with-ticket-scanner')+'</h3>');
					let output_textarea_codes_done = $('<textarea disabled style="4px solid green;width:100%;height:150px;"></textarea>').appendTo(div_textarea_info);

					let list_id = parseInt(input_code_list.val(),10);

					function __addCodesInChunks(chunk_size) {
					    let dlg = $('<div/>').html(_getSpinnerHTML());
						dlg.dialog({title:_x('Importing', 'title', 'event-tickets-with-ticket-scanner'),closeOnEscape: true,modal: true, dialogClass: "no-close", close: function(event, ui){ abort=true; } });

						let abort = false;
						let counter_ok = 0;
						let counter_notok = 0;
						let counter_all = codes.length;
						const array_chunks = (array, chunk_size) => Array(Math.ceil(array.length / chunk_size)).fill().map((_, index) => index * chunk_size).map(begin => array.slice(begin, begin + chunk_size));
						let chunks = array_chunks(codes, chunk_size);
						function _addCodeChunk(idx) {
							if (abort) return;
							if (idx >= chunks.length) {
								dlg.append('<p>'+__('Import process finished', 'event-tickets-with-ticket-scanner')+'</p>');
								$('<center/>').append($('<button class="button-primary" />').html(_x('Ok', 'label', 'event-tickets-with-ticket-scanner')).on("click", ()=>{ closeDialog(dlg); })).appendTo(dlg);
								return;
							}
							let arr = chunks[idx];
							arr.forEach(v=>{
								let div_info_entry = $('<li data-id="code_'+v+'"/>').html(v);
								_output.append(div_info_entry);
							});
							let attr = {"codes":arr, "list_id":list_id};
							if (isPremium() && PREMIUM.addAddCodeFieldsData) {
								attr = PREMIUM.addAddCodeFieldsData(div_textarea, attr);
							}

							_makePost("addCodes", attr, function(data){
								counter_ok += data.ok.length;
								counter_notok += data.notok.length;
								if (myAjax._max.codes_total > 0 && myAjax._max.codes_total <= parseInt(data.total_size)) {
									div_textarea_info.prepend('<h3 style="color:red;">'+sprintf(/* translators: %d: total ticket count */_x('Your Limit of %d tickets is reached. Use the premium version to have unlimited tickets', 'title', 'event-tickets-with-ticket-scanner'),myAjax._max.codes_total)+'</h3>');
								}
								let per = Math.ceil(((counter_ok+counter_notok)/counter_all)*100);
								let info_content = '<div style="width:100%;border:1px solid #efefef;background-color:white;"><div style="text-align:center;height:20px;background-color:#428bca;color:white;width:'+per+'%;">'+per+'%</div></div>';
								info_content += '<p style="margin-top:20px;">'+_x('Amount', 'title', 'event-tickets-with-ticket-scanner')+': '+(counter_ok+counter_notok)+'/'+counter_all+'<br>'+_x('Ok', 'label', 'event-tickets-with-ticket-scanner')+': '+counter_ok+'<br>'+_x('Not Ok', 'label', 'event-tickets-with-ticket-scanner')+': '+counter_notok+'</p>';
								dlg.html(info_content);
								data.ok.forEach(_v=> {
									_output.find('li[data-id="code_'+_v+'"]').css("color","green").append(' ('+_x('Ok', 'label', 'event-tickets-with-ticket-scanner')+')');
									output_textarea_codes_done.append(_v+"\n");
								});
								data.notok.forEach(_v=> {
									_output.find('li[data-id="code_'+_v+'"]').css("color","red").append(' ('+_x('Not Ok', 'label', 'event-tickets-with-ticket-scanner')+')');
								});
								setTimeout(()=>{
									_addCodeChunk(idx+1);
								}, 100);
							}, function(response){
								if (response.data.slice(0,4) === "#208") {
									FATAL_ERROR === false && LAYOUT.renderFatalError(response.data);
									FATAL_ERROR = true;
								}
							});
						}

						if (chunks.length === 0) {
							closeDialog(dlg);
						} else {
							_addCodeChunk(0);
						}
					} // __addCodesInChunks
					__addCodesInChunks(100);

					// zeige ok button, der info area leer macht und den btn store codes wieder aktiviert
					div_textarea_info.append($('<button/>').addClass("button-primary").css("margin-bottom", "20px").html(_x('Ok', 'label', 'event-tickets-with-ticket-scanner')).on("click", function(){
						div_textarea_info.html("");
						btn_store_codes.prop("disabled", false);
						input_textarea.prop("disabled", false);
						window.scrollTo(0,0);
					}));

				}).appendTo(div_textarea);
				DIV.html(div);
			});
		}
		renderAdminPageLayout(cbf) {
			function __showMaskExport(totalRecordCount) {
				if (!totalRecordCount) totalRecordCount = 0;
				let maxRange = totalRecordCount > 40000 ? 40000 : totalRecordCount;
				let _options = {
					title: _x('Export tickets', 'title', 'event-tickets-with-ticket-scanner'),
			      	modal: true,
			      	minWidth: 400,
					minHeight: 200,
			      	buttons: [
			      		{
			      			text: _x('Export', 'label', 'event-tickets-with-ticket-scanner'),
			      			click: function() {
								___submitForm();
			      			}
			      		},
			      		{
			      			text: _x('Cancel', 'label', 'event-tickets-with-ticket-scanner'),
			      			click: function() {
			      				closeDialog(this);
			      			}
			      		}
		      		]
			    };
			    let formdlg = $('<form/>').html('<b>'+_x('Choose your export settings', 'title', 'event-tickets-with-ticket-scanner')+'</b><p>');
			    formdlg.append(_x('Choose the delimiter for the column values', 'label', 'event-tickets-with-ticket-scanner')+'<br><select name="delimiter"><option value="1">, ('+_x('Comma', 'option value', 'event-tickets-with-ticket-scanner')+')</option><option value="2">; ('+_x('Semicolon', 'option value', 'event-tickets-with-ticket-scanner')+')</option><option value="3">| ('+_x('Pipe', 'option value', 'event-tickets-with-ticket-scanner')+')</option></select><p>');
			    formdlg.append(_x('Choose a file suffix', 'label', 'event-tickets-with-ticket-scanner')+'<br><select name="suffix"><option value="1">.csv</option><option value="2">.txt</option></select><p>');

			    let _listChooser = $('<select name="listchooser"><option value="0">'+_x('All', 'option value', 'event-tickets-with-ticket-scanner')+'</option></select>');
			    for(let a=0;a<DATA_LISTS.length;a++) {
			    	_listChooser.append('<option value="'+DATA_LISTS[a].id+'">'+DATA_LISTS[a].name+'</option>');
			    }
			    formdlg.append(_x('Limit export to ticket list', 'label', 'event-tickets-with-ticket-scanner')+'<br>').append(_listChooser).append('<p>');

			    formdlg.append(_x('Choose a sorting field', 'label', 'event-tickets-with-ticket-scanner')+'<br><select name="orderby"><option value="1" selected>'+_x('Creation date', 'option value', 'event-tickets-with-ticket-scanner')+'</option><option value="2">'+__('Ticket number', 'event-tickets-with-ticket-scanner')+'</option><option value="3">'+__('Ticket display number', 'event-tickets-with-ticket-scanner')+'</option><option value="4">'+_x('List name', 'option value', 'event-tickets-with-ticket-scanner')+'</option></select><p>');
			    formdlg.append(_x('Choose a sorting direction', 'label', 'event-tickets-with-ticket-scanner')+'<br><select name="orderbydirection"><option value="1" selected>'+_x('Ascending', 'option value', 'event-tickets-with-ticket-scanner')+'</option><option value="2">'+_x('Descending', 'option value', 'event-tickets-with-ticket-scanner')+'</option></select><p>');
			    formdlg.append(_x('Set a range', 'label', 'event-tickets-with-ticket-scanner')+'<br><i>'+sprintf(/* translators: %d: total record count */__('You have %d tickets stored.', 'event-tickets-with-ticket-scanner'), totalRecordCount)+'<br>'+__('Some systems are slow and the connection timeout interupts the export, if you have too many tickets. In that case, you can export your tickets in several steps. e.g. 0 and 20000 amount and then 20001 and 20000 amount.', 'event-tickets-with-ticket-scanner')+'</i><br>'+__('Enter your row start (0 = from the first)', 'event-tickets-with-ticket-scanner')+'<br><input type="number" name="rangestart" value="0"><br>'+_x('Enter amount of tickets', 'label', 'event-tickets-with-ticket-scanner')+'<br><input type="number" name="rangeamount" value="'+maxRange+'"><p>');
				if (isPremium() && PREMIUM && PREMIUM.addExportTicketsInputFields) {
					formdlg.append(PREMIUM.addExportTicketsInputFields());
				}
			    let dlg = $('<div/>').append(formdlg);

				dlg.dialog(_options);

				let form = dlg.find("form").on("submit", function(event) {
					event.preventDefault();
					___submitForm();
				});

				function ___submitForm() {
					let delimiter = dlg.find('select[name="delimiter"]').val();
					let filesuffix = dlg.find('select[name="suffix"]').val();
					let orderby = dlg.find('select[name="orderby"]').val();
					let orderbydirection = dlg.find('select[name="orderbydirection"]').val();
					let rangestart = dlg.find('input[name="rangestart"]').val();
					let rangeamount = dlg.find('input[name="rangeamount"]').val();
					let listchooser = dlg.find('select[name="listchooser"]').val();

					let data = {'delimiter':delimiter, 'filesuffix':filesuffix, 'orderby':orderby, 'orderbydirection':orderbydirection, 'rangestart':rangestart, 'rangeamount':rangeamount, 'listchooser':listchooser};
					if (isPremium() && PREMIUM && PREMIUM.addExportTicketsInputFieldsData) {
						data = PREMIUM.addExportTicketsInputFieldsData(data, dlg);
					}

					let url = _requestURL('exportTableCodes', data);
					closeDialog(dlg);
					window.open(url, "_blank");
				}
			}
			function __showMaskList(editValues){
				let _options = {
					title: editValues !== null ? _x('Edit List', 'title', 'event-tickets-with-ticket-scanner') : _x('Add List', 'title', 'event-tickets-with-ticket-scanner'),
			      	modal: true,
			      	minWidth: 600,
					minHeight: 400,
					open: function(e) {
        				//$(e.target).parent().css('background-color','orangered');
    				},
    				buttons: [
			      		{
			      			text: _x('Ok', 'label', 'event-tickets-with-ticket-scanner'),
			      			click: function() {
								___submitForm();
			      			}
			      		},
			      		{
			      			text: _x('Cancel', 'label', 'event-tickets-with-ticket-scanner'),
			      			click: function() {
			      				closeDialog(this);
			      			}
			      		}
		      		]
			    };
			    let dlg = $('<div/>').html('<form>'+_x('Name', 'label', 'event-tickets-with-ticket-scanner')+'<br><input name="inputName" type="text" style="width:100%;" required></form>');
				dlg.dialog(_options);

				dlg.find("form").append($('<p>'+_x('Description', 'label', 'event-tickets-with-ticket-scanner')+'<br><textarea name="desc" style="width:100%;"></textarea></p>'));

				if (isPremium()) PREMIUM.addListMaskEditFields(dlg, editValues);
				else {
					if (_getOptions_isActivatedByKey("oneTimeUseOfRegisterCode")) {
						dlg.append($('<p><b>'+sprintf(/* translators: %s: h4 option name */__('Overrule %s per Ticket list', 'event-tickets-with-ticket-scanner'), _getOptions_getLabelByKey("h4"))+'</b> '+getLabelPremiumOnly()+'</p>'));
					}
				}

				let metaObj = [];
				if (editValues && typeof editValues.meta !== "undefined" && editValues.meta != "") {
					try {
						metaObj = JSON.parse(editValues.meta);
					} catch(e) {}
				}

				if (_getOptions_isActivatedByKey("userJSRedirectActiv")) {
					dlg.find("form").append($('<p>'+_getOptions_getLabelByKey("userJSRedirectURL")+'<br><input type="text" name="redirecturl" style="width:100%;"></p>'));
				}

				dlg.find("form").append($('<p><input name="serialformatter" type="checkbox"> '+_x('Overrule the ticket format settings', 'label', 'event-tickets-with-ticket-scanner')+'</p>'));
				let extra_div = $('<div>').appendTo(dlg).css("margin-top", "10px").css("margin-left", "24px").css("padding", "10px").css("border", "1px solid black")
						.html('<p><b>'+_x('Note', 'label', 'event-tickets-with-ticket-scanner')+':</b> '+__('Will be overriden if you set the ticket number format settings on the product!', 'event-tickets-with-ticket-scanner')+'</p>');
				let serialCodeFormatter = _form_fields_serial_format(extra_div);
				serialCodeFormatter.setNoNumberOptions();
				if (typeof metaObj.formatter !== "undefined" && metaObj.formatter.format != "") {
					let formatterValues;
					try {
						let o = metaObj.formatter.format.replace(new RegExp("\\\\", "g"), "").trim();
						formatterValues = JSON.parse(o);
						serialCodeFormatter.setFormatterValues(formatterValues);
					} catch (e) {}
				}
				serialCodeFormatter.render();

				$('<hr>').appendTo(dlg);
				$('<h4>').html(_x('Webhook', 'heading', 'event-tickets-with-ticket-scanner')).appendTo(dlg);
				if (!_getOptions_isActivatedByKey("webhooksActiv")) {
					$('<div style="color:red">').html(_x('The webhook need to be activated first in the options to be executed, even if the URL is set here.', 'label', 'event-tickets-with-ticket-scanner')).appendTo(dlg);
				}
				$('<div>').html(_x('URL to your service if the WooCommerce ticket is sold', 'label', 'event-tickets-with-ticket-scanner')).appendTo(dlg);
				let meta_webhooks_webhookURLaddwcticketsold = $('<input name="meta_webhooks_webhookURLaddwcticketsold" type="text" style="width:100%;">').appendTo(dlg);

				let form = dlg.find("form").on("submit", function(event) {
					event.preventDefault();
					___submitForm();
				});

				if (editValues) {
					form[0].elements['inputName'].value = editValues.name;
					form[0].elements['inputName'].select();
					if (typeof metaObj.desc !== "undefined") {
						form[0].elements['desc'].value = metaObj.desc.replace(new RegExp("\\\\", "g"), "").trim();
					}
					if (typeof metaObj.formatter !== "undefined" && metaObj.formatter.active) {
						form[0].elements['serialformatter'].checked = true;
					}
					if (_getOptions_isActivatedByKey("userJSRedirectActiv") && typeof metaObj.redirect !== "undefined" && metaObj.redirect.url) {
						form[0].elements['redirecturl'].value = metaObj.redirect.url.trim();
					}
					if (typeof metaObj.webhooks != "undefined") {
						if (typeof metaObj.webhooks.webhookURLaddwcticketsold != "undefined") {
							meta_webhooks_webhookURLaddwcticketsold.val(metaObj.webhooks.webhookURLaddwcticketsold);
						}
					}
				}

				function ___submitForm() {
					let inputName = form[0].elements['inputName'].value.trim();
					if (inputName === "") return;

					dlg.html(_getSpinnerHTML());
					let _data = {"name":inputName};
					_data['meta'] = {"desc":"", "formatter":{}, "webhooks":{}};
					_data['meta']['desc'] = form[0].elements['desc'].value.trim();
					_data['meta']['formatter']['active'] = form[0].elements['serialformatter'].checked ? 1 : 0;
					_data['meta']['formatter']['format'] = JSON.stringify(serialCodeFormatter.getFormatterValues());
					if (_getOptions_isActivatedByKey("userJSRedirectActiv")) {
						_data['meta']['redirect'] = {"url":form[0].elements['redirecturl'].value.trim()};
					}
					_data['meta']['webhooks']['webhookURLaddwcticketsold'] = meta_webhooks_webhookURLaddwcticketsold.val().trim();
					if (isPremium()) PREMIUM.addListMaskEditFieldsData(_data, form[0], editValues);

					form[0].reset();
					if (editValues) {
						_data.id = editValues.id;
						_makePost('editList', _data, result=>{
							DATA_LISTS = null;
							__renderTabelleListen();
							tabelle_codes_datatable.ajax.reload();
							setTimeout(function(){closeDialog(dlg);},250);
						}, function() {
							closeDialog(dlg);
						});
					} else {
						_makePost('addList', _data, result=>{
							DATA_LISTS = null;
							__renderTabelleListen();
							closeDialog(dlg);
						}, function(response) {
							closeDialog(dlg);
							if (response.data.slice(0,1) === "#") {
								FATAL_ERROR === false && LAYOUT.renderFatalError(response.data);
								FATAL_ERROR = true;
							}
						});
					}
				}

			} // ende showmaskliste

			function __showMaskCode(editValues){
				let _options = {
					title: editValues !== null ? _x('Edit Ticket', 'title', 'event-tickets-with-ticket-scanner') : _x('Add Ticket', 'title', 'event-tickets-with-ticket-scanner'),
			      	modal: true,
			      	minWidth: 400,
					minHeight: 200,
			      	buttons: [
			      		{
			      			text: _x('Ok', 'label', 'event-tickets-with-ticket-scanner'),
			      			click: function() {
								___submitForm();
			      			}
			      		},
			      		{
			      			text: _x('Cancel', 'label', 'event-tickets-with-ticket-scanner'),
			      			click: function() {
				        		$( this ).dialog( "close" );
				        		$( this ).html('');
			      			}
			      		}
		      		]
			    };
			    let dlg = $('<div />').html('<form>'+_x('List', 'label', 'event-tickets-with-ticket-scanner')+'<br><select name="inputListId"><option value="0">'+_x('None', 'option value', 'event-tickets-with-ticket-scanner')+'</option></select></form>');
				DATA_LISTS.forEach(v=>{
					$(dlg).find('select[name="inputListId"]').append('<option '+(editValues && parseInt(editValues.list_id,10) === parseInt(v.id,10) ? 'selected ':'')+'value="'+v.id+'">'+v.name+'</option>');
				});

				let elem_cvv = $('<input type="text" size="6" minlength="5" maxlength="4" />');
				$('<div/>').css({"margin-top":"10px","margin-bottom": "15px","margin-right": "15px"})
					.html(_x('CVV - use 4 digits for best results', 'label', 'event-tickets-with-ticket-scanner')+'<br>')
					.append(elem_cvv)
					.append('<br><i>'+__('If CVV is set, then your user will be asked to enter also the CVV to check the ticket number.', 'event-tickets-with-ticket-scanner')+'</i>')
					.appendTo(dlg.find("form"));

				let div_status = $('<div/>');
				div_status.append(
					$('<select name="inputStatus"/>')
						.append('<option '+(editValues.aktiv === "1"?'selected':'')+' value="1">'+_x('is activ', 'option value', 'event-tickets-with-ticket-scanner')+'</option>')
						.append('<option '+(editValues.aktiv === "0"?'selected':'')+' '+(!isPremium()?'disabled':'')+' value="0">'+_x('is inactiv', 'option value', 'event-tickets-with-ticket-scanner')+' '+(!isPremium()?getLabelPremiumOnly():'')+'</option>')
						.append('<option '+(editValues.aktiv === "2"?'selected':'')+' value="2">'+_x('is stolen', 'label', 'event-tickets-with-ticket-scanner')+'</option>')
					)
				.appendTo(dlg);

				dlg.dialog(_options);

				if (editValues) {
					if (editValues.cvv) elem_cvv.val(editValues.cvv);
				}

				if (isPremium()) PREMIUM.addCodeMaskEditFields(dlg, editValues);

				let form = dlg.find("form").on("submit", function(event) {
					event.preventDefault();
					___submitForm();
				});
				function ___submitForm() {
					let inputListId = parseInt($(dlg).find('select[name="inputListId"]').val(),10);
					let inputStatusValue = $(dlg).find('select[name="inputStatus"]').val();
					dlg.html(_getSpinnerHTML());
					let _data = {"list_id":inputListId, "aktiv":inputStatusValue, "cvv":elem_cvv.val().trim()};
					if (isPremium()) PREMIUM.addCodeMaskEditFieldsData(_data, form[0], editValues);
					form[0].reset();
					if (editValues) {
						_data.code = editValues.code;
						_makeGet('editCode', _data, ()=>{
							tabelle_codes_datatable.ajax.reload();
							closeDialog(dlg);
						}, function() {
							closeDialog(dlg);
						});
					} else {
						alert(__("Use the add option", 'event-tickets-with-ticket-scanner'));
					}
				}
			} // ende __showMaskCode

			let id_codes = myAjax.divPrefix+'_tabelle_codes';
			let tabelle_liste_datatable;
			let tabelle_codes_datatable;
			let tabelle_codes = $('<table/>').attr("id", id_codes);
			let tplace = $('<div/>');

			function __renderTabelleListen() {
				getDataLists(()=>{
					let id_liste = myAjax.divPrefix+'_tabelle_liste';
					let tabelle_liste = $('<table/>').attr("id", id_liste);
					tabelle_liste.html('<thead><tr><th align="left">'+_x('Name', 'label', 'event-tickets-with-ticket-scanner')+'</th><th align="left">'+_x('Created', 'label', 'event-tickets-with-ticket-scanner')+'</th><th></th></tr></thead>');
					tplace.html(tabelle_liste);

					let table = $('#'+id_liste);
					$(table).DataTable().clear().destroy();
					tabelle_liste_datatable = $(table).DataTable({
						language: {
        					emptyTable: '<b>You need a ticket list to assign it to the products in order to sell tickets.</b>'
    					},
						"responsive": true,
						"visible": true,
						"searching": true,
		    			"ordering": true,
		    			"processing": true,
		    			"serverSide": false,
		    			"stateSave": true,
		    			"data": DATA_LISTS,
		    			"order": [[ 0, "asc" ]],
		    			"columns":[
		    				{"data":"name", "orderable":true},
		    				{"data":"time", "orderable":true, "width":80,
								"render":function (data, type, row) {
									return '<span style="display:none;">'+data+'</span>'+DateFormatStringToDateTimeText(data);
								}
							},
		    				{"data":null,"orderable":false,"defaultContent":'',"className":"buttons dt-right","width":180,
		    					"render": function ( data, type, row ) {
		    						return '<button class="button-secondary" data-type="showCodes">'+_x('Tickets', 'label', 'event-tickets-with-ticket-scanner')+'</button> <button class="button-secondary" data-type="edit">'+_x('Edit', 'label', 'event-tickets-with-ticket-scanner')+'</button> <button class="button-secondary" data-type="deleteAllTickets" style="color:#b32d2e;">'+_x('Delete All Tickets', 'label', 'event-tickets-with-ticket-scanner')+'</button> <button class="button-secondary" data-type="delete">'+_x('Delete', 'label', 'event-tickets-with-ticket-scanner')+'</button>';
		                		}
		                	}
		    			]
					});
					tabelle_liste.css("width", "100%");
					table.on('click', 'button[data-type="showCodes"]', e=>{
						let data = tabelle_liste_datatable.row( $(e.target).parents('tr') ).data();
		        		tabelle_codes_datatable.search("LIST:"+data.id).draw();
					});
					table.on('click', 'button[data-type="edit"]', e=>{
		        		let data = tabelle_liste_datatable.row( $(e.target).parents('tr') ).data();
		        		__showMaskList(data);
					});
					table.on('click', 'button[data-type="delete"]', e=>{
		        		let data = tabelle_liste_datatable.row( $(e.target).parents('tr') ).data();
						let content = $('<div>');
						content.append('<p>' + __('Are you sure, you want to delete this list?', 'event-tickets-with-ticket-scanner') + '</p>');
						content.append('<p><b>' + data.name + '</b></p>');
						content.append('<p>' + __('No ticket will be deleted. Just the list.', 'event-tickets-with-ticket-scanner') + '</p>');
						content.append('<hr style="margin:15px 0;">');
						let checkboxId = 'delete-list-check-products-' + data.id;
						let checkboxWrapper = $('<label for="' + checkboxId + '" style="display:flex;align-items:center;gap:8px;cursor:pointer;">');
						let checkbox = $('<input type="checkbox" id="' + checkboxId + '" checked>');
						checkboxWrapper.append(checkbox);
						checkboxWrapper.append(__('Check if list is used by products', 'event-tickets-with-ticket-scanner'));
						content.append(checkboxWrapper);

		        		LAYOUT.renderYesNo(_x('Do you want to delete?', 'title', 'event-tickets-with-ticket-scanner'), content, ()=>{
		        			let _data = {
								'id': data.id,
								'skip_product_check': !checkbox.is(':checked')
							};
		        			_makePost('removeList', _data, result=>{
								if (result && result.error === 'list_in_use' && result.products) {
									let errorContent = $('<div>');
									errorContent.append('<p style="color:#b32d2e;font-weight:bold;">' + __('This list is still assigned to products:', 'event-tickets-with-ticket-scanner') + '</p>');
									let productList = $('<ul style="margin:10px 0;padding-left:20px;">');
									result.products.forEach(function(product) {
										let li = $('<li style="margin:5px 0;">');
										if (product.edit_url) {
											li.append('<a href="' + product.edit_url + '" target="_blank">' + product.name + '</a> (ID: ' + product.id + ')');
										} else {
											li.append(product.name + ' (ID: ' + product.id + ')');
										}
										productList.append(li);
									});
									errorContent.append(productList);
									errorContent.append('<p>' + __('Please reassign these products first, or uncheck the product check option.', 'event-tickets-with-ticket-scanner') + '</p>');
									LAYOUT.renderInfoBox(__('Cannot delete list', 'event-tickets-with-ticket-scanner'), errorContent);
								} else {
									__renderTabelleListen();
									tabelle_codes_datatable.ajax.reload();
								}
							});
		        		});
					});
				table.on('click', 'button[data-type="deleteAllTickets"]', e=>{
					let data = tabelle_liste_datatable.row( $(e.target).parents('tr') ).data();
					LAYOUT.renderYesNo(
						_x('Delete all tickets?', 'title', 'event-tickets-with-ticket-scanner'),
						sprintf(__('Are you sure you want to delete ALL tickets from the list "%s"?', 'event-tickets-with-ticket-scanner'), '<b>'+data.name+'</b>') + '<br><br><span style="color:#b32d2e;">' + __('This action cannot be undone!', 'event-tickets-with-ticket-scanner') + '</span>',
						()=>{
							let content = $('<div>');
							content.append('<p>' + __('To confirm deletion, type DELETE in the field below:', 'event-tickets-with-ticket-scanner') + '</p>');
							let confirmInput = $('<input type="text" style="width:100%;" placeholder="DELETE">');
							content.append(confirmInput);
							LAYOUT.renderYesNo(
								_x('Final confirmation', 'title', 'event-tickets-with-ticket-scanner'),
								content,
								()=>{
									if (confirmInput.val().trim().toUpperCase() !== 'DELETE') {
										alert(__('You must type DELETE to confirm.', 'event-tickets-with-ticket-scanner'));
										return;
									}
									let btn = $(e.target);
									btn.prop('disabled', true).text(__('Deleting...', 'event-tickets-with-ticket-scanner'));
									_makePost('removeAllCodesFromList', {'list_id': data.id}, result=>{
										btn.prop('disabled', false).text(_x('Delete All Tickets', 'label', 'event-tickets-with-ticket-scanner'));
										tabelle_codes_datatable.ajax.reload();
										if (result && result.deleted !== undefined) {
											alert(sprintf(__('%d tickets have been deleted.', 'event-tickets-with-ticket-scanner'), result.deleted));
										}
									});
								}
							);
						}
					);
				});
			}); // end of loading lists
		} // __renderTabelleListen
			tabelle_codes.css("width", "100%");

			STATE = 'admin';
			DIV.html(_getSpinnerHTML());
			getOptionsFromServer(optionData=>{
				DIV.html('');
				DIV.append(this.renderMainBody());

				let btn_liste_empty = $('<button/>').addClass("button-secondary").html(__('Empty table', 'event-tickets-with-ticket-scanner')).on("click", ()=>{
					LAYOUT.renderYesNo(__('Empty table', 'event-tickets-with-ticket-scanner'), sprintf(/* translators: %s: title list of tickets */__('Do you want to empty the "%s" table? All data will be lost. No ticket will be deleted. Just the lists.', 'event-tickets-with-ticket-scanner'), _x('List of tickets', 'title', 'event-tickets-with-ticket-scanner')), ()=>{
						LAYOUT.renderYesNo(__('Empty table - last chance', 'event-tickets-with-ticket-scanner'), sprintf(/* translators: %s: title list of tickets */__('Are you sure? You will not be able to restore the data, except you have a backup of your database. All data will be lost. No ticket will be deleted. Just the lists.', 'event-tickets-with-ticket-scanner'), _x('List of tickets', 'title', 'event-tickets-with-ticket-scanner')), ()=>{
							_makeGet('emptyTableLists', null, ()=>{
								tabelle_codes_datatable.ajax.reload();
								__renderTabelleListen();
							});
						});
					});
				});
				let btn_liste_new = $('<button/>').addClass("button-primary").html(_x('Add', 'label', 'event-tickets-with-ticket-scanner')).on("click", ()=>{
					__showMaskList(null);
				});
				this.div_liste.html($('<div/>').css('text-align', 'right').css('margin-bottom','10px').append(btn_liste_empty).append(isPremium()?'':' '+sprintf(/* translators: 1: max possible lists amount 2: link to premium */__('Max. %1$d list. Unlimited with %2$s', 'event-tickets-with-ticket-scanner'), myAjax._max.lists, getLabelPremiumOnly())+' ').append(btn_liste_new));
				this.div_liste.append(tplace);

				__renderTabelleListen();

				let additionalColumn_counter_before_created_field = 0;
				let additionalColumn = {customerName:'',customerCompany:'',redeemAmount:'',confirmedCount:''};
				if (_getOptions_isActivatedByKey('displayAdminAreaColumnConfirmedCount')) {
					additionalColumn.confirmedCount = '<th>'+_x('Confirmed Count', 'label', 'event-tickets-with-ticket-scanner')+'</th>';
				}
				if (_getOptions_isActivatedByKey('displayAdminAreaColumnBillingName')) {
					additionalColumn.customerName = '<th>'+_x('Customer', 'label', 'event-tickets-with-ticket-scanner')+'</th>';
					additionalColumn_counter_before_created_field++;
				}
				if (_getOptions_isActivatedByKey('displayAdminAreaColumnBillingCompany')) {
					additionalColumn.customerCompany = '<th>'+_x('Company', 'label', 'event-tickets-with-ticket-scanner')+'</th>';
					additionalColumn_counter_before_created_field++;
				}
				if (_getOptions_isActivatedByKey('displayAdminAreaColumnRedeemedInfo')) {
					additionalColumn.redeemAmount = '<th>'+_x('Redeem Amount', 'label', 'event-tickets-with-ticket-scanner')+'</th>';
				}

				tabelle_codes.html('<thead><tr><th style="text-align:left;padding-left:10px;"><input type="checkbox" data-id="checkAll"></th><th>&nbsp;</th><th align="left">'
					+_x('Ticket', 'label', 'event-tickets-with-ticket-scanner')+'</th>'+additionalColumn.customerName+additionalColumn.customerCompany+'<th align="left">'
					+_x('List', 'label', 'event-tickets-with-ticket-scanner')+'</th><th align="left">'
					+_x('Created', 'label', 'event-tickets-with-ticket-scanner')+'</th>'+additionalColumn.confirmedCount+'<th align="left">'
					+_x('Redeemed', 'label', 'event-tickets-with-ticket-scanner')+'</th>'+additionalColumn.redeemAmount+'<th>'
					+_x('OrderId', 'label', 'event-tickets-with-ticket-scanner')+'</th><th>CVV</th><th>'
					+_x('Status', 'label', 'event-tickets-with-ticket-scanner')+'</th><th></th></tr></thead><tfoot><th colspan="10" style="text-align:left;font-weight:normal;padding-left:0;padding-bottom:0;"></th></tfoot>');
				tabelle_codes.find('input[data-id="checkAll"]').on('click', (e)=> {
					let isChecked = $(e.currentTarget).prop('checked');
					let found = false;
					tabelle_codes.find('input[data-type="select-checkbox"]').each((i,v)=>{
						$(v).prop('checked', isChecked);
						found = true;
					});
					if (isChecked && found) {
						//drop_codes_bulk.prop("disabled", false);
					} else {
						//drop_codes_bulk.prop("disabled", true);
					}
				});
				let btn_codes_new = $('<button/>').addClass("button-primary").html(_x('Add', 'label', 'event-tickets-with-ticket-scanner')).on("click", ()=>{
					if (tabelle_liste_datatable.page.info().recordsTotal === 0) {
						alert(__("You need to create a ticket list first before you can add tickets.", 'event-tickets-with-ticket-scanner'));
					} else {
						if (!isPremium() && tabelle_codes_datatable.page.info().recordsTotal > myAjax._max.codes_total) {
							alert(__("You reached maximum amount of tickets. You need to delete tickets before you can add more new tickets or buy the premium version to have unlimited tickets.", 'event-tickets-with-ticket-scanner'));
						} else {
							LAYOUT.renderAddCodes();
						}
					}
				});
				let btn_codes_empty = $('<button/>').addClass("button-secondary").html(__('Empty table', 'event-tickets-with-ticket-scanner')).on("click", ()=>{
					LAYOUT.renderYesNo(__('Empty table', 'event-tickets-with-ticket-scanner'), sprintf(/* translators: %s: name of ticket table */__('Do you want to empty the "%s" table? All data will be lost.', 'event-tickets-with-ticket-scanner'), _x("Event Tickets", 'title', 'event-tickets-with-ticket-scanner')), ()=>{
						LAYOUT.renderYesNo(__('Empty table - last chance', 'event-tickets-with-ticket-scanner'), sprintf(/* translators: %s: name of ticket table */__('Are you sure? You will not be able to restore the data, except you have a backup of your database. All data will be lost.', 'event-tickets-with-ticket-scanner'), _x("Event Tickets", 'title', 'event-tickets-with-ticket-scanner')), ()=>{
							_makeGet('emptyTableCodes', null, ()=>{
								tabelle_codes_datatable.ajax.reload();
							});
						});
					});
				});
				let btn_codes_reload = $('<button/>').addClass("button-secondary").html(__('Refresh table', 'event-tickets-with-ticket-scanner')).on("click", ()=>{
					LAYOUT.renderSpinnerShow();
					tabelle_codes_datatable.ajax.reload();
					window.setTimeout(()=>{LAYOUT.renderSpinnerHide();}, 1500);
				});
				let btn_codes_export = $('<button/>').addClass("button-secondary").html(_x('Export tickets', 'label', 'event-tickets-with-ticket-scanner')).on("click", ()=>{
					//let url = _requestURL('exportTableCodes', null);
					//window.open(url, "_blank");
					//console.log(tabelle_codes_datatable.page.info());
					__showMaskExport(tabelle_codes_datatable.page.info().recordsTotal);
				});
				let drop_codes_bulk = $('<select data-id="bulk-code-action" />')
					.html('<option value="">'+_x('Bulk Action', 'option value', 'event-tickets-with-ticket-scanner')+'</option>');
					//.append('<option value="delete">'+_x('Delete', 'label', 'event-tickets-with-ticket-scanner')+'</option>');
				for (var key in BulkActions.codes) {
					let entry = BulkActions.codes[key];
					drop_codes_bulk.append('<option value="'+key+'">'+entry.label+'</option>');
				}
				drop_codes_bulk.on('change', ()=>{
					let val = drop_codes_bulk.val();
					if (val !== "") {
						let selectedElems = [];
						tabelle_codes.find('input[data-type="select-checkbox"]').each((i,v)=>{
							if ($(v).prop("checked")) selectedElems.push(v);
						});
						if (selectedElems.length) {
							let fkt = null;
							if (typeof BulkActions.codes[val] == "function") {
								fkt = BulkActions.codes[val];
							} else {
								fkt = BulkActions.codes[val].fkt;
							}
							fkt && fkt(selectedElems, tabelle_codes_datatable);
						}
					}
					drop_codes_bulk.val('');
				});
				let drop_search = $('<select data-id="filter_type" />');
				drop_search.append('<option value="">'+_x('Default search filter', 'option value', 'event-tickets-with-ticket-scanner')+'</option>');
				drop_search.append('<option value="LIST:">'+_x('Filter for list id', 'option value', 'event-tickets-with-ticket-scanner')+'</option>');
				drop_search.append('<option value="ORDERID:">'+_x('Filter for order id', 'option value', 'event-tickets-with-ticket-scanner')+'</option>');
				drop_search.append('<option value="CVV:">'+_x('Filter for cvv value', 'option value', 'event-tickets-with-ticket-scanner')+'</option>');
				drop_search.append('<option value="STATUS:">'+_x('Filter for status (1:active, 0:inactive, 2:stolen)', 'option value', 'event-tickets-with-ticket-scanner')+'</option>');
				drop_search.append('<option value="REDEEMED:">'+_x('Filter for redeemed status (0:not redeemed yet, 1:redeemed)', 'option value', 'event-tickets-with-ticket-scanner')+'</option>');
				drop_search.append('<option value="USERID:">'+_x('Filter for registered user id', 'option value', 'event-tickets-with-ticket-scanner')+'</option>');
				drop_search.append('<option value="CUSTOMER:">'+_x('Filter for customer name in billing first and last name', 'option value', 'event-tickets-with-ticket-scanner')+'</option>');
				drop_search.append('<option value="PRODUCTID:">'+_x('Filter for product id', 'option value', 'event-tickets-with-ticket-scanner')+'</option>');
				drop_search.append('<option value="DAYPERTICKET:">'+_x('Filter for choosen date (enter YYYY-MM-DD)', 'option value', 'event-tickets-with-ticket-scanner')+'</option>');
				drop_search.on("change", e=>{
					let old_search = tabelle_codes_datatable.search().trim();
					let search = drop_search.val();
					if (old_search && old_search.length > 0) {
						search = old_search + " & " + search;
					}
					tabelle_codes_datatable.search(search);
				});
				this.div_codes
					.html($('<div/>').css('text-align', 'right').css('margin-bottom','10px')
					.append(drop_codes_bulk)
					.append(drop_search)
					.append(btn_codes_export)
					.append(btn_codes_empty)
					.append(btn_codes_reload)
					.append(isPremium()?'':' '+sprintf(/* translators: 1: max amount tickets 2: premium link */__('Max. %1$d tickets. Unlimited with %2$s', 'event-tickets-with-ticket-scanner'), myAjax._max.codes_total, getLabelPremiumOnly())+' ').append(btn_codes_new));
				this.div_codes.append(tabelle_codes);

				let table_columns = [
					{"data":null,"orderable":false,"defaultContent":'', "render":function (data, type, row) {
						return '<input type="checkbox" data-type="select-checkbox" data-key="'+data.id+'" data-code="'+data.code+'">';
					}},
					{"data":null,"className":'details-control',"orderable":false,"defaultContent":''},
					{"data":"code_display", "orderable":true, "render":(data,type,row)=>{
						return destroy_tags(data);
					}},
					{"data":"list_name", "orderable":true, "render":(data,type,row)=>{
						return destroy_tags(data);
					}},
					{"data":"time", "className":"dt-center", "orderable":true,
						"render":function (data, type, row) {
							return '<span style="display:none;">'+data+'</span>'+DateFormatStringToDateTimeText(data);
						}
					},
					{"data":"redeemed", "orderable":true, "className":"dt-center", "render":function(data, type, row) {
						if (data == 1) {
							return 'yes';
						} else {
							return '';
						}
					}},
					{"data":"order_id", "className":"dt-right", "orderable":true},
					{"data":null, "orderable":false, "className":"dt-center", "render":function(data, type, row){
						return data.cvv === "" ? "" : '****';
					}},
					{"data":null, "orderable":true, "className":"dt-center", "render":function(data, type, row){
						let _stat = '';
						if (data.meta != "") {
							let metaObj = JSON.parse(data.meta);
							if (typeof metaObj['used'] !== "undefined") {
								if (metaObj.used.reg_request !== "") _stat = '/used';
							}
						}
						if (data.aktiv === "2") return '<span style="color:red;">'+_x('stolen', 'label', 'event-tickets-with-ticket-scanner')+'</span>'+_stat;
						return data.aktiv === "1" ? '<span style="color:green;">'+__('active', 'event-tickets-with-ticket-scanner')+'</span>'+_stat : '<span style="color:grey;">'+_x('is inactiv', 'label', 'event-tickets-with-ticket-scanner')+'</span>'+_stat;
					}},
					{"data":null,"orderable":false,"defaultContent":'',"className":"buttons dt-right",
						"render": function ( data, type, row ) {
							return '<button class="button-secondary" data-type="edit">'+_x('Edit', 'label', 'event-tickets-with-ticket-scanner')+'</button> <button class="button-secondary" data-type="delete">'+_x('Delete', 'label', 'event-tickets-with-ticket-scanner')+'</button>';
						}
					}
				];
				let addition_column_offset = 0;
				if (_getOptions_isActivatedByKey('displayAdminAreaColumnBillingName')) {
					addition_column_offset++;
					table_columns.splice(3, 0, {
						"data":"_customer_name","orderable":false
					});
				}
				if (_getOptions_isActivatedByKey('displayAdminAreaColumnBillingCompany')) {
					addition_column_offset++;
					table_columns.splice(3, 0, {
						"data":"_customer_company","orderable":false
					});
				}
				if (_getOptions_isActivatedByKey('displayAdminAreaColumnConfirmedCount')) {
					addition_column_offset++;
					table_columns.splice(4+addition_column_offset, 0, {
						"data":null,"orderable":false,"defaultContent":'',"className":"dt-center",
						"render":function(data,type,row) {
							let ret = 0;
							let metaObj = getCodeObjectMeta(data);
							if(!metaObj) return ret;
							if (typeof metaObj.confirmedCount != "undefined") {
								ret = metaObj.confirmedCount;
							}
							return ret;
						}
					});
				}
				if (_getOptions_isActivatedByKey('displayAdminAreaColumnRedeemedInfo')) {
					addition_column_offset++;
					table_columns.splice(5+addition_column_offset, 0, {
						"data":null,"orderable":false,"defaultContent":'',"className":"dt-center",
						"render":function(data,type,row) {
							let ret = '';
							if (row._max_redeem_amount > 0) {
								ret = row._redeemed_counter+'/'+row._max_redeem_amount;
							} else {
								ret = row._redeemed_counter+'/unlimited';
							}
							return ret;
						}
					});
				}

				tabelle_codes_datatable = $(this.div_codes).find('#'+id_codes).DataTable({
					"language": {
						emptyTable: '<div style="text-align:left;"><b>'+__('You have no tickets yet.', 'event-tickets-with-ticket-scanner')+'</b>'
							+ '<p>Tickets (number) can be added by two ways.</p>'
							+ '<ol>'
							+ '<li>Automatically with each sale of a ticket product.<br>Please configure a woocommerce product to be a ticket product - recommended<br><a href="https://vollstart.com/event-tickets-quick-start-video" target="_blank">Check out the quick start video</a></li>'
							+ '<li>Or add ticket numbers upfront to a ticket list<br>Click on the add button to import ticket numbers.<br>For this activate the option <b>wcassignmentReuseNotusedCodes</b></li></ol>'
							+ '</div>'
					},
					"responsive": true,
					"search": {
						"search": typeof PARAS.code !== "undefined" ? encodeURIComponent(PARAS.code.trim()) : ''
					},
					footerCallback: function(row, data, start, end, display) {
						let data_anser = tabelle_codes_datatable.ajax.json();
						let text = sprintf(/* translators: 1: amount tickets 2: total amount tickets */__('Redeemed tickets: %1$d (filtered) of %2$d (total redeemed tickets)', 'event-tickets-with-ticket-scanner'), data_anser.redeemedRecordsFiltered, data_anser.redeemedRecordsTotal);
						var api = this.api();
						$(api.column(1).footer()).html(text);
						//$(api.tables().footer()).html(text);
					},
					"processing": true,
	    			"serverSide": true,
	    			"stateSave": false,
					"ajax": {
						"url": _requestURL('getCodes'),
						"type": 'GET'
					},
	    			"order": [[ 4 + additionalColumn_counter_before_created_field, "desc" ]],
	    			"columns": table_columns,
					"initComplete": function () {
						LAYOUT.renderSpinnerHide();
					},
					"autowidth":true
				});
				tabelle_codes.on('click', 'button[data-type="edit"]', function (e) {
	        		let data = tabelle_codes_datatable.row( $(this).parents('tr') ).data();
	        		__showMaskCode(data);
				});
				tabelle_codes.on('click', 'button[data-type="delete"]', function (e) {
	        		let data = tabelle_codes_datatable.row( $(this).parents('tr') ).data();
	        		LAYOUT.renderYesNo(_x('Do you want to delete?', 'title', 'event-tickets-with-ticket-scanner'), __('Are you sure, you want to delete this ticket?', 'event-tickets-with-ticket-scanner')+'<br><br><b>'+data.code+'</b>', ()=>{
	        			let _data = {'id':data.id};
	        			_makePost('removeCode', _data, result=>{
							tabelle_codes_datatable.ajax.reload();
						});
	        		});
				});
	    		$('#'+id_codes+' tbody').on('click', 'td.details-control', function () {
	    			function ___format(d) {
	    				let metaObj = [];
	    				if (d.meta) {
	    					metaObj = JSON.parse(d.meta);
    					}
	    				let div = $('<div/>');

						// hole das aktuelle Metaobj
						function __getData(_codeObj) {
							div.html(_getSpinnerHTML());
							_makeGet('getMetaOfCode',{'code':d.code}, dataMeta=>{
								if (_codeObj) { // um eine Aktualisierung in das codeObj aufzunehmen
									_codeObj.meta = JSON.stringify(dataMeta);
									updateCodeObject(d, _codeObj);
									metaObj = getCodeObjectMeta(d);
								}

								div.html("");
								d.meta = JSON.stringify(dataMeta);
								d.metaObj = dataMeta;

								let btn_grp = $('<div/>').addClass("btn-group").appendTo(div);
								$('<button>').html(_x('Display QR with ticket number', 'label', 'event-tickets-with-ticket-scanner')).appendTo(btn_grp).on("click", e=>{
									let id = 'qrcode_'+d.code+'_'+time();
									let content = _x('This QR image contains', 'label', 'event-tickets-with-ticket-scanner')+':<br><b>'+d.code+'</b><br><br><div id="'+id+'" style="text-align:center;"></div><script>jQuery("#'+id+'").qrcode("'+d.code+'");</script>';
									LAYOUT.renderInfoBox(_x('QR with ticket number', 'title', 'event-tickets-with-ticket-scanner'), content);
								});
								if (d.metaObj.wc_ticket.is_ticket && typeof d.metaObj.wc_ticket._public_ticket_id !== "undefined" && d.metaObj.wc_ticket._public_ticket_id != "") {
									$('<button>').html(_x('Display QR with PUBLIC ticket number', 'label', 'event-tickets-with-ticket-scanner')).appendTo(btn_grp).on("click", e=>{
										let id = 'qrcode_'+d.code+'_'+time();
										let content = _x('This QR image contains', 'label', 'event-tickets-with-ticket-scanner')+':<br><b>'+d.metaObj.wc_ticket._public_ticket_id+'</b><br>'+_x('Can be used with the ticket scanner', 'label', 'event-tickets-with-ticket-scanner')+'<br><br><div id="'+id+'" style="text-align:center;"></div><script>jQuery("#'+id+'").qrcode("'+d.metaObj.wc_ticket._public_ticket_id+'");</script>';
										LAYOUT.renderInfoBox(_x('QR with ticket number', 'title', 'event-tickets-with-ticket-scanner'), content);
									});
								}
								if (d.metaObj.wc_ticket.is_ticket && typeof d.metaObj.wc_ticket._qr_content !== "undefined" && d.metaObj.wc_ticket._qr_content != "") {
									$('<button>').html(_x('Display QR with your own QR content', 'label', 'event-tickets-with-ticket-scanner')).appendTo(btn_grp).on("click", e=>{
										let id = 'qrcode_own_'+d.code+'_'+time();
										let content = _x('This QR image contains', 'label', 'event-tickets-with-ticket-scanner')+':<br><b>'+d.metaObj.wc_ticket._qr_content+'</b><br>'+_x('Can be used with the ticket scanner', 'label', 'event-tickets-with-ticket-scanner')+'<br><br><div id="'+id+'" style="text-align:center;"></div><script>jQuery("#'+id+'").qrcode("'+d.metaObj.wc_ticket._qr_content+'");</script>';
										LAYOUT.renderInfoBox(_x('QR with ticket number', 'title', 'event-tickets-with-ticket-scanner'), content);
									});
								}
								if (typeof d.metaObj._QR != "undefined" && typeof d.metaObj._QR.directURL != "undefined" && d.metaObj._QR.directURL != "") {
									$('<button>').html(_x('Display QR with URL', 'label', 'event-tickets-with-ticket-scanner')).appendTo(btn_grp).on("click", e=>{
										let id = 'qrcode_url_'+d.code+'_'+time();
										let qr_content = d.metaObj._QR.directURL;
										let content = _x('This QR image contains', 'label', 'event-tickets-with-ticket-scanner')+':<br><b>'+qr_content+'</b><br><br><div id="'+id+'" style="text-align:center;"></div><script>jQuery("#'+id+'").qrcode("'+qr_content+'");</script>';
										LAYOUT.renderInfoBox(_x('QR with URL and code', 'title', 'event-tickets-with-ticket-scanner'), content);
									});
								}
								div.append('<div/>');

								// male die Inhalte
								div.append('#'+d.id+'<br><b>'+_x('Created', 'label', 'event-tickets-with-ticket-scanner')+':</b> '+DateFormatStringToDateTimeText(d.time)+' ('+d.time+')<br><b>'+__('Ticket number', 'event-tickets-with-ticket-scanner')+':</b> '+d.code+'<br><b>'+__('Ticket display number', 'event-tickets-with-ticket-scanner')+':</b> '+d.code_display+'<br><b>'+_x('Code Verification Value (CVV)', 'label', 'event-tickets-with-ticket-scanner')+':</b> '+(d.cvv == "" ? '-' : d.cvv)+'<br><b>'+_x('is active', 'event-tickets-with-ticket-scanner')+':</b> '+(parseInt(d.aktiv,10) === 1?'True':'False'));
								div.append(_displayCodeDetails(d, metaObj, tabelle_codes_datatable));

								div.append('<h3>'+_x('WooCommerce Order', 'title', 'event-tickets-with-ticket-scanner')+'</h3>');
								if (!_getOptions_Versions_isActivatedByKey("is_wc_available")) {
									div.append($("<div>").css("color", "red").html(__("WooCommerce not found", 'event-tickets-with-ticket-scanner')));
								}
								div.append('<b>'+_x('OrderId', 'label', 'event-tickets-with-ticket-scanner')+':</b> ' + (parseInt(d.order_id) === 0 ? '-' : '#'+d.order_id+' <a target="_blank" href="post.php?post='+d.order_id+'&action=edit">'+_x('Show in WooCommerce Orders', 'label', 'event-tickets-with-ticket-scanner')+'</a>'));
								if (typeof metaObj['woocommerce'] !== "undefined") {
									if (metaObj.woocommerce.order_id !== 0) {
										div.append($("<div>").html('<b>'+_x('Order from', 'label', 'event-tickets-with-ticket-scanner')+':</b> ').append($('<span>').text(DateFormatStringToDateTimeText(metaObj.woocommerce.creation_date)+' ('+metaObj.woocommerce.creation_date+')')));
										div.append($("<div>").html('<b>'+_x('Product Id', 'label', 'event-tickets-with-ticket-scanner')+':</b> ').append($('<span>').html(metaObj.woocommerce.product_id+' <a target="_blank" href="post.php?post='+encodeURIComponent(metaObj.woocommerce.product_id)+'&action=edit">'+_x('Show Product', 'label', 'event-tickets-with-ticket-scanner')+'</a>')));
									}
								}
								if (typeof metaObj.wc_ticket.subs !== "undefined" && metaObj.wc_ticket.subs.length > 0) {
									div.append('<h4>'+__('Related Subscriptions', 'event-tickets-with-ticket-scanner')+'</h4>');
									metaObj.wc_ticket.subs.forEach(sub=>{
										div.append($("<div>").html('<b>'+_x('Subscription Id', 'label', 'event-tickets-with-ticket-scanner')+':</b> ').append($('<span>').html(sub.order_id+' <a target="_blank" href="post.php?post='+encodeURIComponent(sub.order_id)+'&action=edit">'+_x('Show Subscription', 'label', 'event-tickets-with-ticket-scanner')+'</a> ['+DateTime2Text(sub.date)+']')));
									});
								}
								if (parseInt(d.order_id) > 0) {
									div.append($('<div style="margin-top:10px;">').html($('<button>').addClass("button-delete").html(_x('Delete WooCommerce order info for this ticket', 'label', 'event-tickets-with-ticket-scanner')).on("click", ()=>{
										LAYOUT.renderYesNo(_x('Remove order', 'title', 'event-tickets-with-ticket-scanner'), sprintf(/* translators: %s: ticket number */__('Do you really want to remove your order information of this ticket "%s"? This will also remove the ticket number from the order! For the PREMIUM PLUGIN: It will only remove it from the position of the order. If you have in one order more than one item with ticket number, then it will only remove the ticket number(s) from this item on the order. For the BASIC PLUGIN, it will remove all tickets from all items on this order. Click OK to proceed the removal.', 'event-tickets-with-ticket-scanner'), d.code_display), ()=>{
											_makeGet('removeWoocommerceOrderInfoFromCode', {'code':d.code}, _codeObj=>{
												//tabelle_codes_datatable.ajax.reload();
												__getData(_codeObj);
											});
										});
									})));
								}

								div.append('<h4>'+__('WooCommerce ticket sale', 'event-tickets-with-ticket-scanner')+'</h4>');
								div.append(_displayWCETicket(d, tabelle_codes_datatable));

								div.append('<h3>'+__('WooCommerce Purchase Restriction', 'event-tickets-with-ticket-scanner')+'</h3>');
								if (typeof metaObj['wc_rp'] !== "undefined") {
									if (metaObj.wc_rp.order_id !== 0) {
										div.append($("<div>").html('<b>'+_x('Used for Order ID', 'label', 'event-tickets-with-ticket-scanner')+':</b> ').append($('<span>').html('#'+metaObj.wc_rp.order_id+' <a target="_blank" href="post.php?post='+encodeURIComponent(metaObj.wc_rp.order_id)+'&action=edit">'+_x('Open WooCommerce Order', 'label', 'event-tickets-with-ticket-scanner')+'</a>')));
										div.append($("<div>").html('<b>'+_x('Order from', 'label', 'event-tickets-with-ticket-scanner')+':</b> ').append($('<span>').text(metaObj.wc_rp.creation_date)));
										div.append($("<div>").html('<b>'+_x('Product Id', 'label', 'event-tickets-with-ticket-scanner')+'s:</b> ').append($('<span>').html(metaObj.wc_rp.product_id+' <a target="_blank" href="post.php?post='+encodeURIComponent(metaObj.wc_rp.product_id)+'&action=edit">'+_x('Show Product', 'label', 'event-tickets-with-ticket-scanner')+'</a>')));
										div.append($('<div style="margin-top:10px;">').html($('<button>').addClass("button-delete").html(__('Remove purchase ticket information', 'event-tickets-with-ticket-scanner')).on("click", ()=>{
											LAYOUT.renderYesNo(__('Remove purchase ticket information', 'event-tickets-with-ticket-scanner'), sprintf(/* translators: %s: ticket nummer */__('Do you really want to remove the purchase ticket information from the order of this ticket "%s"? This will remove the also the ticket(s) from the order items! This ticket can then be reused for purchases. Click OK to proceed the removal.', 'event-tickets-with-ticket-scanner'), d.code_display), ()=>{
												_makeGet('removeWoocommerceRstrPurchaseInfoFromCode', {'code':d.code}, _codeObj=>{
													//tabelle_codes_datatable.ajax.reload();
													__getData(_codeObj);
												});
											});
										})));
									} else {
										div.append($("<div>").html('<b>'+_x('Used for Order ID', 'label', 'event-tickets-with-ticket-scanner')+':</b> -'));
									}
								}

								div.append('<h3>'+_x('Registered user', 'title', 'event-tickets-with-ticket-scanner')+'</h3>');
								div.append(_displayRegisteredUserForCode(d, metaObj, tabelle_codes_datatable));

								div.append('<h3>Redeem operations</h3>');
								div.append(_displayRedeemOperationsForCode(d, metaObj));

								div.append('<h3>'+_x('IP list checked for this ticket', 'title', 'event-tickets-with-ticket-scanner')+'</h3>');
								if (isPremium()) {
									div.append(PREMIUM.displayTrackedIPsForCode(d.code));
								} else {
									div.append(getLabelPremiumOnly());
								}

								if (isPremium() && PREMIUM.displayCodeDetailsAtEnd) div.append(PREMIUM.displayCodeDetailsAtEnd(d, tabelle_codes_datatable, metaObj));

								div.append("<hr>");
							});
						}
						__getData();
	    				return div;
	            	}

	        		var tr = $(this).closest('tr');
	        		var row = tabelle_codes_datatable.row( tr );
	        		if ( row.child.isShown() ) {
	            		// This row is already open - close it
	            		row.child.hide();
	            		tr.removeClass('shown');
	        		} else {
	            		// Open this row
	            		row.child( ___format(row.data()) ).show();
	            		tr.addClass('shown');
	        		}
				});
				cbf && cbf();
			}); // end getOptions
		} // render layout

		renderInfoBox(title, content, displayPlain) {
			let _options = {
				title: title,
		      	modal: true,
		      	minWidth: 400,
				minHeight: 200,
		      	buttons: [{text:_x('Ok', 'label', 'event-tickets-with-ticket-scanner'),
				click: function() {
		      		$(this).dialog("close");
		      		$(this).html("");
		      	}}]
		    };
		    let dlg = $('<div/>');
			if (displayPlain) {
				dlg.text(content);
			} else {
				dlg.html(content);
			}
			dlg.dialog(_options);
			return dlg;
		}
		renderSpinnerShow() {
			this.div_spinner.css("display", "block");
		}
		renderSpinnerHide() {
			this.div_spinner.css("display", "none");
		}
		renderFatalError(content) {
			return LAYOUT.renderInfoBox(_x('Error', 'title', 'event-tickets-with-ticket-scanner'), content);
		}
		renderYesNo(title, content, cbfYes, cbfNo) {
			let _options = {
				title: title,
		      	modal: true,
		      	minWidth: 400,
				minHeight: 200,
		      	buttons: [{text:_x('Yes', 'label', 'event-tickets-with-ticket-scanner'), click:function(){
		      		$(this).dialog("close");
		      		$(this).html("");
		      		cbfYes && cbfYes(dlg);
		      	}},{text:_x('No', 'label', 'event-tickets-with-ticket-scanner'), click:function(){
		      		$(this).dialog("close");
		      		$(this).html("");
		      		cbfNo && cbfNo();
		      	}}]
		    };
		    let dlg = $('<div/>').html(content);
			dlg.dialog(_options);
			return dlg;
		}
	}

	function _displayCodeDetails(codeObj, metaObj, tabelle) {
		let div = $('<div/>');
		function __getData(_codeObj) {
			if (_codeObj) { // um eine Aktualisierung in das codeObj aufzunehmen
				updateCodeObject(codeObj, _codeObj);
			}

			div.html("");
			if (codeObj.meta !== "") {
				let metaObj = getCodeObjectMeta(codeObj);
				if (typeof metaObj.confirmedCount !== "undefined") {
					div.append($('<div/>').html('<b>Confirmed count:</b> '+metaObj.confirmedCount));
					if (metaObj.confirmedCount > 0 && metaObj.validation) {
						if (metaObj.validation.first_success != "") {
						div.append($('<div/>').html('<b>First successful validation at:</b> '+metaObj.validation.first_success));
						div.append($('<div/>').html('<b>First successful validation IP:</b> '+metaObj.validation.first_ip));
						}
						if (metaObj.validation.last_success != "" && metaObj.validation.last_success != metaObj.validation.first_success) {
							div.append($('<div/>').html('<b>Last successful validation at:</b> '+metaObj.validation.last_success));
							div.append($('<div/>').html('<b>Last successful validation IP:</b> '+metaObj.validation.last_ip));
						}
					}
				}
				let btngrp = $('<div style="margin-top:10px;">');
				if (typeof metaObj.used !== "undefined") {
					div.append("<h3>Code marked as used</h3>");
					if (metaObj.used.reg_request !== "") {
						div.append($("<div>").html("<b>Request from:</b> ").append($('<span>').text(DateFormatStringToDateTimeText(metaObj.used.reg_request)+' ('+metaObj.used.reg_request+')')));
						div.append($("<div>").html("<b>Request by wordpress user:</b> ").append($('<span>').text(metaObj.used.reg_userid)));
						if (metaObj.used._reg_username) div.append($("<div>").html("<b>Request by wordpress user:</b> ").append($('<span>').text(metaObj.used._reg_username)));
						div.append($("<div>").html("<b>Request from IP:</b> ").append($('<span>').text(metaObj.used.reg_ip)));

						btngrp.append($('<button/>').addClass("button-delete").html('Delete ticket used information').on("click", function(){
							LAYOUT.renderYesNo('Remove usage information', 'Do you really want to remove the usage information of this ticket "'+codeObj.code_display+'"? This will also reset the "Confirmed count" to 0.', ()=>{
								_makeGet('removeUsedInformationFromCode', {'code':codeObj.code}, _codeObj=>{
									//tabelle.ajax.reload();
									__getData(_codeObj);
								});
							});
						}));
					} else {
						div.append("Not used - still available");
					}

					btngrp.append($('<button/>').addClass("button-edit").html('Edit wordpress user information').on("click", function(){
						// display eingabe maske für userid
						function __showMask(){
							let _options = {
								title: 'Edit requested wordpress user',
								modal: true,
								minWidth: 400,
								minHeight: 200,
								buttons: [
									{
										id: 'okBtn',
										text: "Ok",
										click: function() {
											___submitForm();
										}
									},
									{
										text: "Cancel",
										click: function() {
											$( this ).dialog( "close" );
											$( this ).html('');
										}
									}
								]
							};
							let dlg = $('<div />');
							let form = $('<form />').appendTo(dlg);

							let elem_userid = $('<input type="number" min="0" value="'+metaObj.used.reg_userid+'" />');
							$('<div/>').css({"margin-top":"10px","margin-bottom": "15px","margin-right": "15px"})
								.html('Requested wordpress userid<br>')
								.append(elem_userid)
								.appendTo(form);

							dlg.append('<p>Changes will trigger the webhook, if activated.<br>The IP will be updated too. The requested date will only be changed, if it was not set already.</p>');
							dlg.dialog(_options);

							form.on("submit", function(event) {
								event.preventDefault();
								___submitForm();
							});
							function ___submitForm() {
								let reg_userid = intval(elem_userid.val().trim());
								dlg.html(_getSpinnerHTML());
								let _data = {"reg_userid":reg_userid};
								form[0].reset();
								_data.code = codeObj.code;
								$('#okBtn').remove();
								_makeGet('editUseridForUsedInformationFromCode', _data, _codeObj=>{
									//tabelle.ajax.reload();
									__getData(_codeObj);
									closeDialog(dlg);
								}, function() {
									closeDialog(dlg);
								});
							}
						} // ende __showMask
						__showMask();
					})); // end button-edit
				}
				div.append(btngrp);

				if (isPremium()) div.append(PREMIUM.displayCodeDetails(codeObj, tabelle, metaObj));
			} // endif codeObj.meta !== ""
		}
		__getData();
		return div;
	}

	function _displayWCETicket(codeObj, tabelle) {
		let div = $('<div/>');
		function __getData(_codeObj) {
			if (_codeObj) { // um eine Aktualisierung in das codeObj aufzunehmen
				updateCodeObject(codeObj, _codeObj);
			}

			div.html("");
			let metaObj = getCodeObjectMeta(codeObj);
			if(metaObj) {
				if (typeof metaObj.wc_ticket != "undefined" && typeof metaObj.wc_ticket.day_per_ticket != "undefined") {
					div.append($('<div>').html('<b>Date per Ticket (choosen by customer):</b> '+metaObj.wc_ticket.day_per_ticket +" ").append(
						$("<button>").html("Edit").on("click", ()=>{

							let _options = {
								title: 'Edit Ticket Date',
								modal: true,
								minWidth: 400,
								minHeight: 200,
								buttons: [
									{
										id: 'okBtn',
										text: "Ok",
										click: function() {
											___submitForm();
										}
									},
									{
										text: "Cancel",
										click: function() {
											$( this ).dialog( "close" );
											$( this ).html('');
										}
									}
								]
							};
							let dlg = $('<div />');
							let form = $('<form />').appendTo(dlg);

							let elem_input = $('<input type="date" value="'+metaObj.wc_ticket.day_per_ticket+'" />');
							$('<div/>').css({"margin-top":"10px","margin-bottom": "15px","margin-right": "15px"})
								.html('Date per Ticket (yyyy-mm-dd).<br><b>Very important to not break the date format and syntax, if you want to change the date!</b><br>')
								.append(elem_input)
								.appendTo(form);

							dlg.dialog(_options);

							form.on("submit", function(event) {
								event.preventDefault();
								___submitForm();
							});
							function ___submitForm() {
								let v = elem_input.val().trim();
								dlg.html(_getSpinnerHTML());
								let _data = {"value":v, "key":'wc_ticket.day_per_ticket'};
								form[0].reset();
								_data.code = codeObj.code;
								$('#okBtn').remove();
								_makeGet('editTicketMetaEntry', _data, _codeObj=>{
									//tabelle.ajax.reload();
									__getData(_codeObj);
									closeDialog(dlg);
								}, function() {
									closeDialog(dlg);
								});
							}
						})
					));
				}
				if (typeof metaObj.wc_ticket != "undefined" && typeof metaObj.wc_ticket.name_per_ticket != "undefined") {
					div.append($('<div>').html('<b>Name per Ticket (product detail setting):</b> '+metaObj.wc_ticket.name_per_ticket +" ").append(
						$("<button>").html("Edit").on("click", ()=>{

							let _options = {
								title: 'Edit Ticket Name',
								modal: true,
								minWidth: 400,
								minHeight: 200,
								buttons: [
									{
										id: 'okBtn',
										text: "Ok",
										click: function() {
											___submitForm();
										}
									},
									{
										text: "Cancel",
										click: function() {
											$( this ).dialog( "close" );
											$( this ).html('');
										}
									}
								]
							};
							let dlg = $('<div />');
							let form = $('<form />').appendTo(dlg);

							let elem_input = $('<input type="text" value="'+metaObj.wc_ticket.name_per_ticket+'" />');
							$('<div/>').css({"margin-top":"10px","margin-bottom": "15px","margin-right": "15px"})
								.html('Name per Ticket<br>')
								.append(elem_input)
								.appendTo(form);

							dlg.dialog(_options);

							form.on("submit", function(event) {
								event.preventDefault();
								___submitForm();
							});
							function ___submitForm() {
								let v = elem_input.val().trim();
								dlg.html(_getSpinnerHTML());
								let _data = {"value":v, "key":'wc_ticket.name_per_ticket'};
								form[0].reset();
								_data.code = codeObj.code;
								$('#okBtn').remove();
								_makeGet('editTicketMetaEntry', _data, _codeObj=>{
									//tabelle.ajax.reload();
									__getData(_codeObj);
									closeDialog(dlg);
								}, function() {
									closeDialog(dlg);
								});
							}
						})
					));
				}
				if (typeof metaObj.wc_ticket != "undefined" && typeof metaObj.wc_ticket.value_per_ticket != "undefined") {
					div.append($('<div>').html('<b>Value per Ticket (product detail setting):</b> '+metaObj.wc_ticket.value_per_ticket +" ").append(
						$("<button>").html("Edit").on("click", ()=>{

							let _options = {
								title: 'Edit Ticket Value',
								modal: true,
								minWidth: 400,
								minHeight: 200,
								buttons: [
									{
										id: 'okBtn',
										text: "Ok",
										click: function() {
											___submitForm();
										}
									},
									{
										text: "Cancel",
										click: function() {
											$( this ).dialog( "close" );
											$( this ).html('');
										}
									}
								]
							};
							let dlg = $('<div />');
							let form = $('<form />').appendTo(dlg);

							let elem_input = $('<input type="text" value="'+metaObj.wc_ticket.value_per_ticket+'" />');
							$('<div/>').css({"margin-top":"10px","margin-bottom": "15px","margin-right": "15px"})
								.html('Value per Ticket<br>')
								.append(elem_input)
								.appendTo(form);

							dlg.dialog(_options);

							form.on("submit", function(event) {
								event.preventDefault();
								___submitForm();
							});
							function ___submitForm() {
								let v = elem_input.val().trim();
								dlg.html(_getSpinnerHTML());
								let _data = {"value":v, "key":'wc_ticket.value_per_ticket'};
								form[0].reset();
								_data.code = codeObj.code;
								$('#okBtn').remove();
								_makeGet('editTicketMetaEntry', _data, _codeObj=>{
									//tabelle.ajax.reload();
									__getData(_codeObj);
									closeDialog(dlg);
								}, function() {
									closeDialog(dlg);
								});
							}
						})
					));
				}
				// Seat information
				if (typeof metaObj.wc_ticket != "undefined" && typeof metaObj.wc_ticket.seat_id != "undefined" && metaObj.wc_ticket.seat_id) {
					let seatInfo = metaObj.wc_ticket.seat_label || metaObj.wc_ticket.seat_identifier || ('Seat #' + metaObj.wc_ticket.seat_id);
					if (metaObj.wc_ticket.seat_category) {
						seatInfo += ' (' + metaObj.wc_ticket.seat_category + ')';
					}
					div.append($('<div>').html('<b>'+__('Seat', 'event-tickets-with-ticket-scanner')+':</b> ' + seatInfo));
				}
				if (typeof metaObj['woocommerce'] !== "undefined" && metaObj.woocommerce.order_id !== 0 && typeof metaObj.wc_ticket !== "undefined") {
					if (metaObj.wc_ticket.set_by_admin > 0) {
						div.append($("<div>").html("<b>Ticket set by admin user:</b> ").append($('<span>').text(metaObj.wc_ticket._set_by_admin_username+' ('+metaObj.wc_ticket.set_by_admin+') '+metaObj.wc_ticket.set_by_admin_date)));
					}
					if (metaObj.wc_ticket.redeemed_date != '') {
						div.append($("<div>").html("<b>Redeemed at:</b> ").append($('<span>').text(DateFormatStringToDateTimeText(metaObj.wc_ticket.redeemed_date)+' ('+metaObj.wc_ticket.redeemed_date+')')));
						div.append($("<div>").html("<b>Redeemed by wordpress userid:</b> ").append($('<span>').text(metaObj.wc_ticket.userid)));
						if (metaObj.wc_ticket._username) div.append($("<div>").html("<b>Redeemed by wordpress user:</b> ").append($('<span>').text(metaObj.wc_ticket._username)));
						div.append($("<div>").html("<b>IP while redeemed:</b> ").append($('<span>').text(metaObj.wc_ticket.ip)));
						if (metaObj.wc_ticket.redeemed_by_admin > 0) {
							div.append($("<div>").html("<b>Redeemed by admin user:</b> ").append($('<span>').text(metaObj.wc_ticket._redeemed_by_admin_username+' ('+metaObj.wc_ticket.redeemed_by_admin+')')));
						}
					}
					if (metaObj.wc_ticket.is_ticket == 1) {
						let _max_redeem_amount = typeof metaObj.wc_ticket._max_redeem_amount !== "undefined" ? metaObj.wc_ticket._max_redeem_amount : 1;
						$("<div>").html("<b>Ticket number: </b>"+codeObj.code_display).appendTo(div);
						$("<div>").html("<b>Public Ticket number: </b>"+metaObj.wc_ticket._public_ticket_id).appendTo(div);
						if (typeof metaObj.wc_ticket.stats_redeemed !== "undefined") {
							$("<div>").html("<b>Redeem usage: </b>"+metaObj.wc_ticket.stats_redeemed.length + ' of ' + (_max_redeem_amount == 0 ? 'unlimited' : _max_redeem_amount)).appendTo(div);
						}
						$("<div>").html('<b>Ticket Page:</b> <a target="_blank" href="'+metaObj.wc_ticket._url+'">Open Ticket Detail Page</a>').appendTo(div);
						$("<div>").html('<b>Ticket Page Testmode:</b> <a target="_blank" href="'+metaObj.wc_ticket._url+'?testDesigner=1">Open Ticket Detail Page with template test code</a>').appendTo(div);
						$("<div>").html('<b>Ticket PDF:</b> <a target="_blank" href="'+metaObj.wc_ticket._url+'?pdf">Open Ticket PDF</a>').appendTo(div);
						$("<div>").html('<b>Ticket PDF Testmode:</b> <a target="_blank" href="'+metaObj.wc_ticket._url+'?pdf&testDesigner=1">Open Ticket PDF with template test code</a>').appendTo(div);
						$("<div>").html('<b>Ticket Scanner:</b> <a target="_blank" href="'+_getTicketScannerURL()+encodeURIComponent(metaObj.wc_ticket._public_ticket_id)+'">Open Ticket Scanner with ticket</a>').appendTo(div);
						$("<div>").html('<b>Order Ticket Page:</b> <a target="_blank" href="'+metaObj.wc_ticket._order_page_url+'">Open Order Ticket Page</a>').appendTo(div);
						$("<div>").html('<b>Order PDF:</b> <a target="_blank" href="'+metaObj.wc_ticket._order_url+'">Open Order Ticket PDF</a>').appendTo(div);
					}

					let btngrp = $('<div style="margin-top:10px;">').appendTo(div);
					if (metaObj.wc_ticket.is_ticket == 1) {
						$('<button>').html("Download PDF").appendTo(btngrp).on("click", ()=>{
							_downloadFile('downloadPDFTicket', {'code':codeObj.code}, "eventticket_"+codeObj.code+".pdf");
							return false;
						});
						$('<button>').html("Download Ticket Badge").appendTo(btngrp).on("click", ()=>{
							_downloadFile('downloadPDFTicketBadge', {'code':codeObj.code}, "eventticket_badge_"+codeObj.code+".pdf");
							return false;
						});
						$('<button>').html("Display QR with URL to PDF").appendTo(btngrp).on("click", e=>{
							let id = 'qrcode_'+codeObj.code+'_'+time();
							let content = 'This QR image contains:<br><b>'+codeObj.code+'</b><br><br><div id="'+id+'" style="text-align:center;"></div><script>jQuery("#'+id+'").qrcode("'+metaObj.wc_ticket._url+'?pdf");</script>';
							LAYOUT.renderInfoBox('QR with URL to PDF', content);
						});
					}
					if (metaObj.wc_ticket.is_ticket == 0) {
						$('<button>').html("Set as ticket sale").on("click", ()=>{
							LAYOUT.renderYesNo('Set as a ticket', 'Do you want to set this purchased ticket number as a ticket sale?', ()=>{
								_makeGet('setWoocommerceTicketForCode', {'code':codeObj.code}, _codeObj=>{
									__getData(_codeObj);
								});
							});
						}).appendTo(btngrp);
					}
					let btn_redeem = $('<button>').addClass("button-delete").html('Redeem ticket').on("click", ()=>{
						let reg_userid = (metaObj.user && metaObj.user.reg_userid) ? metaObj.user.reg_userid : 0;
						LAYOUT.renderYesNo('Redeem ticket', 'Do you really want to redeem the ticket number "'+codeObj.code_display+'"? Click OK to redeem the ticket.', ()=>{
							let userid = prompt('Optional. You can enter a userid you redeem the ticket for', reg_userid);
							_makeGet('redeemWoocommerceTicketForCode', {'code':codeObj.code, 'userid':userid}, _codeObj=>{
								__getData(_codeObj);
							});
						});
					}).appendTo(btngrp);
					let _max_redeem_amount = typeof metaObj.wc_ticket._max_redeem_amount !== "undefined" ? metaObj.wc_ticket._max_redeem_amount : 1;
					if (metaObj.wc_ticket.is_ticket == 0 || _max_redeem_amount == 0 || metaObj.wc_ticket.stats_redeemed.length >= _max_redeem_amount) {
						btn_redeem.attr("disabled", true);
					}

					let btn_unredeem = $('<button>').addClass("button-delete").html('Delete redeem information').on("click", ()=>{
						LAYOUT.renderYesNo('Remove ticket information', 'Do you really want to remove the information that the ticket number "'+codeObj.code_display+'" is redeemed? Click OK to un-redeem the ticket and allow your customer to use the ticket again.', ()=>{
							_makeGet('removeRedeemWoocommerceTicketForCode', {'code':codeObj.code}, _codeObj=>{
								__getData(_codeObj);
							});
						});
					}).appendTo(btngrp);
					if (metaObj.wc_ticket.is_ticket == 0 || metaObj.wc_ticket.redeemed_date == "") {
						btn_unredeem.attr("disabled", true);
					}
					if (metaObj.wc_ticket.is_ticket == 1 && metaObj.wc_ticket.redeemed_date == "") {
						$('<button>').addClass("button-delete").html("Unset Ticket").on("click", ()=>{
							LAYOUT.renderYesNo('Remove ticket', 'Do you really want to remove the ticket info from this ticket number? The WooCommerce sale will be set and you need to remove it manually.', ()=>{
								_makeGet('removeWoocommerceTicketForCode', {'code':codeObj.code}, _codeObj=>{
									__getData(_codeObj);
								});
							});
						}).appendTo(btngrp);
					}
				}
			}
		}
		__getData();
		return div;
	}

	function _displayRedeemOperationsForCode(d, metaObj) {
		let div = $('<div/>');
		if (typeof metaObj.wc_ticket.stats_redeemed !== "undefined") {
			if (metaObj.wc_ticket.stats_redeemed.length > 0) {
				let t = $('<table>').appendTo(div);
				t.html('<tr><th>#</th><th>Date</th><th>IP</th><th>By admin</th><th>User ID</th></tr>').appendTo(t);
				metaObj.wc_ticket.stats_redeemed.forEach((v,idx)=>{
					let tr = $('<tr>').appendTo(t);
					$('<td>').html('#'+(idx+1)).appendTo(tr);
					$('<td>').html(DateFormatStringToDateTimeText(v.redeemed_date)+' ('+v.redeemed_date+')').appendTo(tr);
					$('<td>').html(v.ip).appendTo(tr);
					$('<td>').html(v.redeemed_by_admin == 1 ? 'Yes' : 'No').appendTo(tr);
					$('<td>').html(v.userid).appendTo(tr);
				});
			} else {
				div.html("no redeem operations yet");
			}
		}
		return div;
	}

	function _displayRegisteredUserForCode(codeObj, metaObj, tabelle) {
		let div = $('<div/>');
		function __getData(_codeObj) {
			if (_codeObj) { // um eine Aktualisierung in das codeObj aufzunehmen
				updateCodeObject(codeObj, _codeObj);
			}
			div.html("");
			let btngrp = $('<div style="margin-top:10px;">');
			if (typeof codeObj.meta !== "undefined" && codeObj.meta !== "") {
				let metaObj = getCodeObjectMeta(codeObj);
				if (metaObj.user.reg_request !== "") {
					div.append($("<div>").html("<b>Register value:</b> ").append($('<span>').text(metaObj.user.value)));
					div.append($("<div>").html("<b>Register by wordpress userid:</b> ").append($('<span>').text(metaObj.user.reg_userid)));
					if (metaObj.user._reg_username) div.append($("<div>").html("<b>Register by wordpress user:</b> ").append($('<span>').text(metaObj.user._reg_username)));
					div.append($("<div>").html("<b>Request from:</b> ").append($('<span>').text(metaObj.user.reg_request)));
					div.append($("<div>").html("<b>Request from IP:</b> ").append($('<span>').text(metaObj.user.reg_ip)));
					btngrp.append($('<button/>').addClass("button-delete").html('Delete registered user information').on("click", function(){
						LAYOUT.renderYesNo('Remove register user value', 'Do you really want to remove the registered user value of this ticket "'+codeObj.code_display+'"?', ()=>{
							// sende delete user from code operation zum server
							div.html(_getSpinnerHTML());
							_makeGet('removeUserRegistrationFromCode', {'code':codeObj.code}, _codeObj=>{
								//tabelle.ajax.reload();
								__getData(_codeObj);
							});
						});
					}));
				} else {
					div.append("No registration to this ticket done");
				}

				btngrp.append($('<button/>').addClass("button-edit").html('Edit registered user information').on("click", function(){
					// display eingabe maske für value und userid
					function __showMask(){
						let _options = {
							title: 'Edit registered user',
							modal: true,
							minWidth: 400,
							minHeight: 200,
							buttons: [
								{
									id: 'okBtn',
									text: "Ok",
									click: function() {
										___submitForm();
									}
								},
								{
									text: "Cancel",
									click: function() {
										$( this ).dialog( "close" );
										$( this ).html('');
									}
								}
							]
						};
						let dlg = $('<div />');
						let form = $('<form />').appendTo(dlg);

						let elem_value = $('<input type="text" value="'+metaObj.user.value+'" />');
						$('<div/>').css({"margin-top":"10px","margin-bottom": "15px","margin-right": "15px"})
							.html('Registered value<br>')
							.append(elem_value)
							//.append('<br><i>If CVV is set, then your user will be asked to enter also the CVV to check the serial code.</i>')
							.appendTo(form);
						let elem_userid = $('<input type="number" min="0" value="'+metaObj.user.reg_userid+'" />');
						$('<div/>').css({"margin-top":"10px","margin-bottom": "15px","margin-right": "15px"})
							.html('Registered wordpress userid<br>')
							.append(elem_userid)
							.appendTo(form);

						dlg.append('<p>Changes will trigger the webhook, if activated.<br>The IP will updated too. The registered date will only be changed, if it was not set already.</p>');
						dlg.dialog(_options);

						form.on("submit", function(event) {
							event.preventDefault();
							___submitForm();
						});
						function ___submitForm() {
							let reg_userid = intval(elem_userid.val().trim());
							let reg_value = elem_value.val().trim();
							dlg.html(_getSpinnerHTML());
							let _data = {"value":reg_value, "reg_userid":reg_userid};
							form[0].reset();
							_data.code = codeObj.code;
							$('#okBtn').remove();
							_makeGet('editUseridForUserRegistrationFromCode', _data, _codeObj=>{
								//tabelle.ajax.reload();
								__getData(_codeObj);
								closeDialog(dlg);
							}, function() {
								closeDialog(dlg);
							});
						}
					} // ende __showMask
					__showMask();
				})); // end button-edit
				div.append(btngrp);
				if (isPremium()) div.append(PREMIUM.displayRegisteredUserForCode(codeObj, tabelle, metaObj));
			} // endif typeof codeObj.meta !== "undefined" && codeObj.meta !== ""
		}
		__getData();
		return div;
	}

	function addStyleCode(content) {
		let c = document.createElement('style');
		c.innerHTML = content;
		document.getElementsByTagName("head")[0].appendChild(c);
	}
	function addStyleTag(url, id, onloadfkt, attrListe, loadLatest) {
	  var script  = document.createElement('link');
	  script.type = 'text/css';
	  script.rel = "stylesheet";
	  let myId = id;
	  if (!myId) myId = url;
		if (document.getElementById(id) && document.getElementById(id).src === url) {
			onloadfkt && onloadfkt();
			return; // prevent re-adding the same tag
		}
	  script.id = id;
	  if (attrListe) for(var attr in attrListe) script.setAttribute(attr, attrListe[attr]);
	  script.href = url;
	  if (loadLatest) script.href += '?t='+new Date().getTime();
	  if (typeof onloadfkt !== "undefined") script.onload = onloadfkt;
	  document.getElementsByTagName("head")[0].appendChild(script);
	}
	function addScriptCode(content, id) {
		if (typeof system.DYNJS_CACHE.scriptCodeElements === "undefined") {
			system.DYNJS_CACHE.scriptCodeElements = {};
		}
		let c;
		if (id && typeof system.DYNJS_CACHE.scriptCodeElements[id] !== "undefined") {
			c = system.DYNJS_CACHE.scriptCodeElements[id];
			document.getElementsByTagName("head")[0].removeChild(c);
		} else {
			c = document.createElement('script');
		}
		c.innerHTML = content;
		if (id) {
			system.DYNJS_CACHE.scriptCodeElements[id] = c;
		}
		document.getElementsByTagName("head")[0].appendChild(c);
	}
	function addScriptTag(url, id, onloadfkt, attrListe, loadLatest) {
	  	var head    = document.getElementsByTagName("head")[0];
	  	var script  = document.createElement('script');
	  	script.type = 'text/javascript';
	  	let myId = id;
	  	if (!myId) myId = url;
		if (document.getElementById(id) && document.getElementById(id).src === url) {
			onloadfkt && onloadfkt();
			return; // prevent re-adding the same tag
		}
	  script.id = id;
	  if (attrListe) for(var attr in attrListe) script.setAttribute(attr, attrListe[attr]);
	  script.src = url;
	  if (loadLatest) script.src += '?t='+new Date().getTime();
	  if (typeof onloadfkt !== "undefined") script.onload = onloadfkt;
	  head.appendChild(script);
	}

	function getPremiumProductURL() {
		return 'https://vollstart.com/event-tickets-with-ticket-scanner/?utm_source=etwts_plugin&utm_medium=plugin_link&utm_campaign=etwts_upgrade_to_premium';
	}
	function getLabelPremiumOnly() {
		return '[<a href="'+getPremiumProductURL()+'">PREMIUM ONLY</a>]';
	}

	function _getSpinnerHTML() {
		return '<span class="lds-dual-ring"></span>';
	}

	function _loadingJSDatatables(cbf) {
		let loaded = {};
		addStyleCode('table.dataTable tr.shown td.details-control {background: url('+myAjax._plugin_home_url+'/img/details_close.png) no-repeat center center;}td.details-control {background: url('+myAjax._plugin_home_url+'/img/details_open.png) no-repeat center center;cursor: pointer;}');
		addStyleTag(myAjax._plugin_home_url+'/3rd/datatables.min.css', 'jquery_dataTables', ()=>{
			loaded['1'] = true;
			if (loaded['2']) {
				cbf && cbf();
			}
		}, {'crossorigin':"anonymous"});
		addScriptTag(myAjax._plugin_home_url+"/3rd/datatables.min.js", 'jquery_dataTables', ()=>{
			loaded['2'] = true;
			if (loaded['1']) {
				cbf && cbf();
			}
		}, {'crossorigin':"anonymous", "charset":"utf8"});
	}

	function isPremium() {
		return myAjax._isPremium == "1" || myAjax._isPremium === true;
	}

	var BulkActions = {
		'codes': {
			'delete': {
				"label": _x('Delete', 'label', 'event-tickets-with-ticket-scanner'),
				"fkt": (selectedElems, tabelle_codes_datatable)=>{
					LAYOUT.renderYesNo('Delete all selected tickets?', 'Are you sure, you want to delete all selected tickets?<br><br>'+selectedElems.length+' tickets will be deleted.', ()=>{
						let _data = {'ids':[]};
						selectedElems.forEach(v=>{
							_data.ids.push($(v).attr("data-key"));
						});
						_makePost('removeCodes', _data, result=>{
							tabelle_codes_datatable.ajax.reload();
						});
					});
				}
			},
			'remove_marked_used': {
				"label": _x("Remove marked as used", 'option', 'event-tickets-with-ticket-scanner'),
				"fkt": (selectedElems, tabelle_codes_datatable)=>{
					LAYOUT.renderYesNo('Remove marked used?', 'Are you sure, you want to remove the used marked from all selected tickets?<br><br>'+selectedElems.length+' tickets will be changed.', ()=>{
						let _data = {'ids':[], 'codes':[]};
						selectedElems.forEach(v=>{
							_data.ids.push($(v).attr("data-key"));
							_data.codes.push($(v).attr("data-code"));
						});
						_makePost('removeUsedInformationFromCodeBulk', _data, result=>{
							tabelle_codes_datatable.ajax.reload();
						});
					});
				}
			},
			'remove_ticket_redeemed': {
				"label": _x("Delete Redeem Information", 'option', 'event-tickets-with-ticket-scanner'),
				"fkt": (selectedElems, tabelle_codes_datatable)=>{
					LAYOUT.renderYesNo('Delete the redeem information?', 'Are you sure, you want to remove the the information about the redeem operation of the ticket?<br><br>'+selectedElems.length+' tickets will be changed.', ()=>{
						let _data = {'ids':[], 'codes':[]};
						selectedElems.forEach(v=>{
							_data.ids.push($(v).attr("data-key"));
							_data.codes.push($(v).attr("data-code"));
						});
						_makePost('removeRedeemWoocommerceTicketForCodeBulk', _data, result=>{
							tabelle_codes_datatable.ajax.reload();
						});
					});
				}
			},
			'generate_pdf': {
				"label": _x("Generate ticket PDF", 'option', 'event-tickets-with-ticket-scanner'),
				"fkt": (selectedElems, tabelle_codes_datatable)=>{
					LAYOUT.renderYesNo('Generate the ticket PDF?', 'Are you sure, you want to generate the ticket PDFs for the selected tickets? This can take a while an could timeout the server.<br><br>'+selectedElems.length+' tickets will be added in one PDF.', ()=>{
						let _data = {'ids':[], 'codes':[]};
						selectedElems.forEach(v=>{
							_data.ids.push($(v).attr("data-key"));
							_data.codes.push($(v).attr("data-code"));
						});
						_downloadFile('generateOnePDFForTicketsBulk', _data, "tickets_merged.pdf");
					});
				}
			},
			'generate_badge': {
				"label": _x("Generate badge ticket", 'option', 'event-tickets-with-ticket-scanner'),
				"fkt": (selectedElems, tabelle_codes_datatable)=>{
					LAYOUT.renderYesNo('Generate the ticket badge PDF?', 'Are you sure, you want to generate the ticket badge PDFs for the selected tickets? This can take a while an could timeout the server.<br><br>'+selectedElems.length+' badges will be added in one PDF.', ()=>{
						let _data = {'ids':[], 'codes':[]};
						selectedElems.forEach(v=>{
							_data.ids.push($(v).attr("data-key"));
							_data.codes.push($(v).attr("data-code"));
						});
						_downloadFile('generateOnePDFForBadgesBulk', _data, "ticketbadges_merged.pdf");
					});
				}
			},
			'move_to_list':{
				"label": _x("Move to ticket list", 'option', 'event-tickets-with-ticket-scanner'),
				"fkt": (selectedElems, tabelle_codes_datatable)=>{
					let content = $('<div>');
					let div_code_list = _createDivInput(_x('Assign selected tickets to this ticket list', 'label', 'event-tickets-with-ticket-scanner')).appendTo(content);
					let input_code_list = $('<select><option value="0">'+_x('None', 'option value', 'event-tickets-with-ticket-scanner')+'</select></select>').appendTo(div_code_list);
					DATA_LISTS.forEach(v=>{
						input_code_list.append('<option value="'+v.id+'">'+v.name+'</option>');
					});
					content.append("<br>");
					LAYOUT.renderYesNo('Move ticket(s) to ticket list', content, ()=>{
						let _data = {'ids':[], 'codes':[], 'list_id':input_code_list.val()};
						selectedElems.forEach(v=>{
							_data.ids.push($(v).attr("data-key"));
							_data.codes.push($(v).attr("data-code"));
						});
						_makePost('assignTicketListToTicketsBulk', _data, result=>{
							tabelle_codes_datatable.ajax.reload();
						});
					});
				}
			}
		}
	}

	function addTabCSS() {
		$('<style>')
		.prop('type', 'text/css')
		.html(`
			.tabs {
				width: 100%;
				display: block;
			}
			.tab-nav {
				list-style: none;
				padding: 0;
				margin: 0;
				display: flex;
				border-bottom: 1px solid #ccc;
			}
			.tab-nav li {
				margin: 0;
			}
			.tab-nav a {
				display: block;
				padding: 10px 20px;
				text-decoration: none;
				color: #333;
				border: 1px solid #ccc;
				border-bottom: none;
				background: #f9f9f9;
				margin-right: 5px;
				border-radius: 5px 5px 0 0;
			}
			.tab-nav a.active {
				background: #fff;
				border-bottom: 1px solid #fff;
				font-weight: bold;
			}
			.tab-content {
				display: none;
				padding: 20px;
				border: 1px solid #ccc;
				border-radius: 0 5px 5px 5px;
				background: #fff;
			}
		`)
		.appendTo('head');
	}

	function getHelperFunktions() {
		return {
			_getSpinnerHTML:_getSpinnerHTML,
			_makePost:_makePost,
			_makeGet:_makeGet,
			_getMediaData:_getMediaData,
			_downloadFile:_downloadFile,
			_requestURL:_requestURL,
			_getLAYOUT:function(){ return LAYOUT;},
			_getDIV:function(){ return DIV;},
			_BulkActions:BulkActions,
			_closeDialog:closeDialog,
			_OPTIONS:function(){ return OPTIONS;},
			_getVarSYSTEM:function(){ return system;},
			_updateCodeObject:updateCodeObject,
			_getCodeObjectMeta:getCodeObjectMeta,
			_DateTime2Text:DateTime2Text,
			_DateFormatStringToDateTimeText:DateFormatStringToDateTimeText,
			_DateFormatStringToDateText:DateFormatStringToDateText,
			_compareVersions:compareVersions,
			_getBackButtonDiv:getBackButtonDiv,
			_addStyleTag:addStyleTag
		};
	}

	function refreshNoncePeriodically() {
        // check if the last check of nonce is older than 4 minutes
        // do a ping to get the new nonce
        setInterval(()=>{
            let last_check = DATA.last_nonce_check;
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

	function init() {
		addStyleCode('.lds-dual-ring {display:inline-block;width:64px;height:64px;}.lds-dual-ring:after {content:" ";display:block;width:46px;height:46px;margin:1px;border-radius:50%;border:5px solid #fff;border-color:#2e74b5 transparent #2e74b5 transparent;animation:lds-dual-ring 0.6s linear infinite;}@keyframes lds-dual-ring {0% {transform: rotate(0deg);}100% {transform: rotate(360deg);}}');
		addStyleTag(myAjax._plugin_home_url+'/css/styles_backend.css');

		addScriptTag(myAjax._plugin_home_url+'/3rd/ace/ace.js');

		addTabCSS();

    	DIV = $('#'+myAjax.divId);
    	DIV.html(_getSpinnerHTML());
    	LAYOUT = new Layout();
		function _init() {
			document.body.style.background = "#ffffff";
	 		_loadingJSDatatables(function() {
				if (typeof PARAS.display !== "undefined" && PARAS.display == 'options') {
					_displayOptionsArea();
				} else if (typeof PARAS.display !== "undefined" && PARAS.display == 'support') {
					_displaySupportInfoArea();
				} else if (typeof PARAS.display !== "undefined" && PARAS.display == 'authtokens') {
					_displayAuthTokensArea();
				} else if (typeof PARAS.display !== "undefined" && PARAS.display == 'faq') {
					_displayFAQArea();
				} else {
					LAYOUT.renderAdminPageLayout();
				}
			});
		}

    	if (isPremium() && myAjax._premJS !== "") {
    		addScriptTag(myAjax._premJS, null, function() {
    			PREMIUM = new sasoEventticketsPremium(myAjax, getHelperFunktions());
    			_init();
    		});
    	} else {
			_init();
    	}
		$('#wpfooter').css('display', 'none');
		refreshNoncePeriodically();
	}
	if (!doNotInit) init();
	return {
		init: init,
		form_fields_serial_format: _form_fields_serial_format,
		makePost: _makePost,
		getMediaData: _getMediaData
	};

}
if (typeof Ajax_sasoEventtickets !== "undefined") {
	window.sasoEventtickets_backend = sasoEventtickets(Ajax_sasoEventtickets);
}