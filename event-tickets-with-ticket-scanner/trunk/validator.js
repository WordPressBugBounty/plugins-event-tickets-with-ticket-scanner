function SasoEventticketsValidator(_myAjaxVar) {
	const { __, _x, _n, sprintf } = wp.i18n;
	let _self = this;
	let PREMIUM = null;
	var $ = jQuery;
	let myAjax = typeof _myAjaxVar === "undefined" ? null : _myAjaxVar;
	if (myAjax === null) return;
	var _prefix = myAjax.divPrefix;
	var _spinnerElemId = _prefix + 'spinner';
	var DATA = {
        /*action: '',*/
        nonce: myAjax.nonce
    };
    var SHORTCODES = {};
	if (myAjax.shortcode_attr && myAjax.shortcode_attr !== "") SHORTCODES = JSON.parse(myAjax.shortcode_attr);
	var FATAL_ERROR = false;
    var LAYOUT = null;

	var DIV_ID = myAjax.divId;
	var DIV_MAIN = null;
	var DATEN_URL = myAjax.url;
	var PARAS = {};
	var CODE_ELEMENT;
	var BUTTON_ELEMENT;
	var ELEM_OUTPUT;
	var OPTIONS = {list:[], mapKeys:{}};

	function macheAjax(url, funcSuccess, funcError, doNotShowSpinner){
		let _spinnerElem;
		if (!doNotShowSpinner) {
			_spinnerElem = document.getElementById(_spinnerElemId);
			if (!_spinnerElem) {
				_spinnerElem = document.createElement('div');
				_spinnerElem.id = _spinnerElemId;
				_spinnerElem.innerHTML = _getSpinnerHTML();
				_spinnerElem.style.zIndex = "1000";
				_spinnerElem.style.verticalAlign = "center";
				_spinnerElem.style.height = "30px";
				_spinnerElem.style.width = "100px";
				_spinnerElem.style.display = "none";
				_spinnerElem.style.position = "absolute";
				_spinnerElem.style.top = "0";
				_spinnerElem.style.left = "0";
				_spinnerElem.style.marginTop = "30%";
				_spinnerElem.style.marginLeft = "50%";
				document.body.appendChild(_spinnerElem);
			}
			_spinnerElem.style.display = "block";
		}
		var xhttp = new XMLHttpRequest();
		xhttp.onreadystatechange = function() {
		  if (this.readyState === 4) {
		  	if (this.status === 200) {
		  		if (this.responseText === '505') {
		  			if (funcError) funcError(this.responseText);
		  			else alert("fehler 505");
		  		} else {
		  			if (funcSuccess) funcSuccess(this.responseText);
				}
			} else if (this.status === 404) {
				if (funcError) funcError(this.responseText, this.status);
			}
			if (_spinnerElem) _spinnerElem.style.display = "none";
		  }
		};
		xhttp.open("GET", url, true);
		xhttp.send(url);
		return xhttp;
	};

	function basics_ermittelJSPath(filename) {
		let basePath = null;
	  	var scripts = document.getElementsByTagName('script');
	    for (var i = scripts.length - 1; i >= 0; --i) {
	      var src = scripts[i].src.split('?')[0];
	      var l = src.length;
	      var length = filename.length;
	      if (src.substring(l - length) === filename) {
	        basePath = src.substring(0, l - length);
	      }
	    }
	    return basePath;
	}
	function _server_result_getError(error) {
		var erg = error.trim().match(/#([0-9|a-z|A-Z]+)/);
		let errorCode = 0;
	    if (erg) errorCode = trim(erg[1]);
	  	return parseInt(errorCode, 10);
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

	function _getOptions_getByKey(key) {
		if (OPTIONS.mapKeys[key]) return OPTIONS.mapKeys[key];
		return null;
	}
	function _getOptions_isActivatedByKey(key) {
		let po = _getOptions_getByKey(key);
		if (po == null || typeof po.value == "undefined") return false;
		return po.value == 1;
	}
	function _getOptions_getLabelByKey(key) {
		let po = _getOptions_getByKey(key);
		if (po == null || typeof po.label == "undefined") return "";
		return po.label;
	}
	function _getOptions_getValByKey(key) {
		let po = _getOptions_getByKey(key);
		if (po == null || typeof po.value == "undefined") return "";
		return po.value == "" ? po['default'] : po.value;
	}

	function _registerToCode(code, value) {
		let url = DATEN_URL+'?action='+myAjax._action+'&a_sngmbh=registerToCode&data[code]='+encodeURIComponent(code)+'&data[value]='+encodeURIComponent(value)+'&t='+new Date().getTime();
		macheAjax(url, responseText=>{
			//let obj = JSON.parse(responseText);
			$(ELEM_OUTPUT).html($('<center/>').text(_getOptions_getValByKey('textRegisterSaved')));
		});
	}

	function _renderJSRedirectBtn(label, url) {
		let elem = $('<center style="margin-top:10px;">');
		$('<button/>')
			.addClass('sngmbh_btn sngmbh_btn-primary sngmbh_mb-2')
			.text(label)
			.appendTo(elem)
			.on('click', function() {
				window.location.href = url;
			});
		return elem;
	}
	function _renderRegisterBtn(cbf) {
		let elem = $('<center style="margin-top:10px;">');
		$('<button/>')
			.addClass('sngmbh_btn sngmbh_btn-primary sngmbh_mb-2')
			.text(_getOptions_getValByKey('textRegisterButton'))
			.appendTo(elem)
			.on('click', function() {
				if (_getOptions_isActivatedByKey('allowUserRegisterSkipValueQuestion')) {
					_registerToCode($(CODE_ELEMENT).val().trim(), '');
					cbf && cbf();
				} else {
					// zeige eingabemaske für email addresse
					let wert = prompt(_getOptions_getValByKey('textRegisterValue'));
					if (wert && wert.trim() !== "") {
						_registerToCode($(CODE_ELEMENT).val().trim(), wert.trim());
						cbf && cbf();
					}
				}
			});
		return elem;
	}
	function __displayMessage(text) {
		ELEM_OUTPUT.innerHTML = text;
		BUTTON_ELEMENT.disabled = false;
		CODE_ELEMENT.disabled = false;
	}

	class Layout {
		renderEingabeMaske() {
			_renderEingabeMaske();
			if (_getOptions_isActivatedByKey('onlyForLoggedInWPuser') && !isUserLoggedIn()) {

				ELEM_OUTPUT.innerHTML = '<center><span style="color:red;">'+_getOptions_getValByKey('onlyForLoggedInWPuserMessage')+'</span></center>';

				$(BUTTON_ELEMENT).prop("disabled", true);
				$(CODE_ELEMENT).prop("disabled", true);
				$(CODE_ELEMENT).attr("placeholder", _getOptions_getValByKey('onlyForLoggedInWPuserMessage'));
			} else {
				$(BUTTON_ELEMENT).prop("disabled", false);
				$(CODE_ELEMENT).prop("disabled", false);
			}
		}
		renderInputMaske(input_id,btn_id) {
			let btnLabel = _getOptions_getValByKey('textValidationButtonLabel').replace(/</g,'');
			let inputFieldPlaceholder = _getOptions_getValByKey('textValidationInputPlaceholder').replace(/"/g,'');
			let textValidationBtnBgColor = _getOptions_getValByKey('textValidationBtnBgColor').replace(/"/g,'').replace(/;/g,'');
			let textValidationBtnBrdColor = _getOptions_getValByKey('textValidationBtnBrdColor').replace(/"/g,'').replace(/;/g,'');
			let textValidationBtnTextColor = _getOptions_getValByKey('textValidationBtnTextColor').replace(/"/g,'').replace(/;/g,'');
			let t = '<div class="sngmbh_container">'
			+ '<div class="sngmbh_input-group sngmbh_mb-3">'
			+ '<input required type="text" class="sngmbh_form-control sngmbh_mb-2" data-input="'+input_id+'" placeholder="'+inputFieldPlaceholder+'">'
			+ '<div class="sngmbh_input-group-append">'
			+ '<button type="submit" data-btn="'+btn_id+'" class="sngmbh_btn sngmbh_btn-primary sngmbh_mb-2" style="'
			+ (textValidationBtnBgColor ? 'background-color:'+textValidationBtnBgColor+';' : '')
			+ (textValidationBtnBrdColor ? 'border-color:'+textValidationBtnBrdColor+';' : '')
			+ (textValidationBtnTextColor ? 'color:'+textValidationBtnTextColor+';' : '')
			+ '">'+btnLabel+'</button>'
			+ '</div>'
			+ '</div>'
			+ '</div>';
			return t;
		}
		getDefaultMessages() {
			return {
			'msgCheck0':'Code not found',
			'msgCheck1':'Code confirmed',
			'msgCheck2':'Please contact support for further investigation',
			'msgCheck3':'Is registered to a user',
			'msgCheck4':'Code expired',
			'msgCheck5':'Code is already used',
			'msgCheck6':'Code and CVV is not valid',
			'msgCheck7':'Product is stolen'
			};
		}
		showCheckResult(obj) {
			if (obj.success === false) {
				//let error_code = _server_result_getError(obj.error.msg);
				__displayMessage('<center><span style="color:red;font-weight:bold;">'+obj.data.msg+'</span></center>');
				//return alert(obj.data.msg);
				return;
			}
			this.displayCheckResult(obj);

			function __userJSRedirectActiv() {
				if (_getOptions_isActivatedByKey('userJSRedirectActiv')) {
					//if (obj.data.valid === 1) {
						if (typeof obj.data.retObject !== "undefined" && typeof obj.data.retObject.userJSRedirect !== "undefined") {
							if (obj.data.retObject.userJSRedirect.url !== "") {
								if (obj.data.retObject.userJSRedirect.btnlabel && obj.data.retObject.userJSRedirect.btnlabel !== "") {
									// button mit redirect
									$(ELEM_OUTPUT).append(_renderJSRedirectBtn(obj.data.retObject.userJSRedirect.btnlabel, obj.data.retObject.userJSRedirect.url));
								} else {
									// redirect
									window.location.href = obj.data.retObject.userJSRedirect.url;
								}
							}
						}
					//}
				}
			}

			if (_getOptions_isActivatedByKey('allowUserRegisterCode')) {
				if (obj.data.valid === 1) {
					$(ELEM_OUTPUT).append(_renderRegisterBtn(__userJSRedirectActiv));
				}
			} else {
				__userJSRedirectActiv();
			}
		}
		displayCheckResult(obj) {
			let valid = obj.data.valid;
			let default_msgs = this.getDefaultMessages();
			let msgs = default_msgs;
			if (typeof myAjax._messages !== "undefined") msgs = myAjax._messages;
			if (obj.data.messages) msgs = obj.data.messages;

			CODE_ELEMENT.classList.remove("sngmbh_is-valid");
			CODE_ELEMENT.classList.remove("sngmbh_is-invalid");

			let _isOk = true;
			let _text = '';
			let _textColor = 'red';
			let _textWeight = 'bold';

			if (obj.data.retObject && obj.data.retObject.message) {
				if (valid === 1) _textColor = 'green';
				_isOk = obj.data.retObject.message.ok;
				_text = obj.data.retObject.message.text;
				if (typeof obj.data.retObject.message.color !== "undefined") _textColor = obj.data.retObject.message.color;
				if (typeof obj.data.retObject.message.weight !== "undefined") _textWeight = obj.data.retObject.message.weight;
			} else {
				if (valid === 1) {
					_textColor = 'green';
					_text = (msgs.msgCheck1.trim() !== "" ? msgs.msgCheck1 : default_msgs.msgCheck1);
				} else if (valid === 2) {
					_isOk = false;
					_text = (msgs.msgCheck2.trim() !== "" ? msgs.msgCheck2 : default_msgs.msgCheck2);
				} else if (valid === 3) {
					_text = (msgs.msgCheck3.trim() !== "" ? msgs.msgCheck3 : default_msgs.msgCheck3);
				} else if (valid === 4) {
					_text = (msgs.msgCheck4.trim() !== "" ? msgs.msgCheck4 : default_msgs.msgCheck4);
				} else if (valid === 5) {
					_text = (msgs.msgCheck5.trim() !== "" ? msgs.msgCheck5 : default_msgs.msgCheck5);
				} else if (valid === 6) {
					_isOk = false;
					_text = (typeof msgs.msgCheck6 !== "undefined" && msgs.msgCheck6.trim() !== "" ? msgs.msgCheck6 : default_msgs.msgCheck6);
				} else if (valid === 7) {
					_isOk = false;
					_text = default_msgs.msgCheck7;
				} else {
					_isOk = false;
					_text = (msgs.msgCheck0.trim() !== "" ? msgs.msgCheck0 : default_msgs.msgCheck0);
				}
			}

			CODE_ELEMENT.classList.add(_isOk ? "sngmbh_is-valid" : "sngmbh_is-invalid");
			let text = '<center><span style="'+(_textColor != "" ? 'color:'+_textColor : '')+';font-weight:'+_textWeight+';">'+_text+'</span></center>';

			__displayMessage(text);
		}
	}

	function _renderEingabeMaske() {
		let btn_id = _prefix + 'btn';
		let input_id = _prefix + 'code';
		DIV_MAIN.innerHTML = '';

		let elem = document.createElement("div");
		elem.className = "sngmbh_container";
		if (!SHORTCODES.inputid) {
			elem.innerHTML = LAYOUT.renderInputMaske(input_id, btn_id);
		}

		if (!SHORTCODES.outputid) {
			ELEM_OUTPUT = document.createElement("div");
			elem.appendChild(ELEM_OUTPUT);
			DIV_MAIN.appendChild(elem);
		} else {
			ELEM_OUTPUT = document.querySelector('[id="'+SHORTCODES.outputid+'"]');
			if (!ELEM_OUTPUT) alert("Event Ticket Code validator: Output element cannot be found. Please check your outputid-value on the shortcode");
		}

		if (!SHORTCODES.inputid) {
			CODE_ELEMENT = document.querySelector('[data-input="'+input_id+'"]');
		} else {
			CODE_ELEMENT = document.querySelector('[id="'+SHORTCODES.inputid+'"]');
			if (!CODE_ELEMENT) alert("Event Ticket Code Generator: Input element cannot be found. Please check your inputid-value on the shortcode");
		}

		if (typeof PARAS.code !== "undefined") {
			let c = PARAS.code.trim();
			let cs = PARAS.code.split(":");
			CODE_ELEMENT.value = encodeURIComponent(cs[0]);
			if (cs.length > 1) {
				CODE_ELEMENT.value = CODE_ELEMENT.value + ":" + encodeURIComponent(cs[1]);
			}
		}

		CODE_ELEMENT.onchange = function() {
			ELEM_OUTPUT.innerHTML = "";
			CODE_ELEMENT.classList.remove("is-valid");
			CODE_ELEMENT.classList.remove("is-invalid");
		}
		CODE_ELEMENT.onkeyup = function() {
			ELEM_OUTPUT.innerHTML = "";
			CODE_ELEMENT.classList.remove("is-valid");
			CODE_ELEMENT.classList.remove("is-invalid");
		}
		CODE_ELEMENT.onkeydown = function() {
			if (event.key === 'Enter') {
				BUTTON_ELEMENT.click();
			}
		}

		if (!SHORTCODES.triggerid) {
			BUTTON_ELEMENT = document.querySelector('[data-btn="'+btn_id+'"]');
		} else {
			BUTTON_ELEMENT = document.querySelector('[id="'+SHORTCODES.triggerid+'"]');
			if (!BUTTON_ELEMENT) alert("Event Ticket Code Generator: Trigger not found. Please check your triggerid-value on the shortcode");
		}

		BUTTON_ELEMENT.onclick = function() {
			let code = CODE_ELEMENT.value.trim();
			if (code === "") {
				CODE_ELEMENT.select();
				return;
			}
			if (SHORTCODES.jspre) {
				let _retcode = _execShortCodeJSFunction(SHORTCODES.jspre, code);
				if (_retcode) code = _retcode;
			}

			BUTTON_ELEMENT.disabled = true;
			CODE_ELEMENT.disabled = true;
			ELEM_OUTPUT.innerHTML = '<center>'+_getSpinnerHTML()+'</center>';

			function __callCheckService(cvv) {
				let url = DATEN_URL+'?action='+myAjax._action+'&a_sngmbh=checkCode&data[code]='+encodeURIComponent(code);
				if (cvv && cvv !== "") url += '&data[cvv]='+encodeURIComponent(cvv);
				url += '&t='+new Date().getTime();
				macheAjax(url, function(responseText) {
					let obj = JSON.parse(responseText);
					if (obj.data.valid === 6 && !cvv) { // cvv
						let value = prompt(__("Please enter your CVV for this ticket", 'event-tickets-with-ticket-scanner'));
						if (value) {
							__callCheckService(value);
							return;
						}
					}
					if (SHORTCODES.jsafter) _execShortCodeJSFunction(SHORTCODES.jsafter, obj.data);
					LAYOUT.showCheckResult(obj);
				}, null, true);
			}
			let cvv;
			if (code.match(/:/)) {
				let parts = code.split(':');
				code = parts[0].trim();
				cvv = parts[1].trim();
			}
			__callCheckService(cvv);
		}
	}

	function _execShortCodeJSFunction(fktname, paras) {
		var fn = window[fktname];
		if (typeof fn === "function") return fn(paras);
		else alert('function '+fktname+' not found. Please check your shortcode parameters');
	}

	function checkIfCssRuleExists(identifier) {
		let styles = document.styleSheets;
		for(var a=0;a<styles.length;a++) {
			try {
				let rules = styles[a].cssRules;
				for(var b=0;b<rules.length;b++){
					if (rules[b].cssText.match(identifier)) return true;
				};
			} catch(e) {}
		}
		return false;
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

	function getLabelPremiumOnly() {
		return "[PREMIUM ONLY]";
	}

	function _getSpinnerHTML() {
		return '<span class="lds-dual-ring"></span>';
	}

	function isPremium() {
		return myAjax._isPremium == "1" || myAjax._isPremium === true;
	}
	function isUserLoggedIn() {
		return myAjax._isUserLoggedin == "1" || myAjax._isUserLoggedin === true;
	}

	function getHelperFunktions() {
		return {
			_getSpinnerHTML:_getSpinnerHTML,
			_macheAjax:macheAjax,
			_basics_ermittelJSPath:basics_ermittelJSPath,
			_basics_ermittelURLParameter:basics_ermittelURLParameter,
			_renderEingabeMaske:_renderEingabeMaske,
			_addScriptTag:addScriptTag,
			_addStyleCode:addStyleCode,
			_checkIfCssRuleExists:checkIfCssRuleExists,
			_getCODE_ELEMENT:function(){return CODE_ELEMENT;},
			_getELEM_OUTPUT:function() {return ELEM_OUTPUT;},
			_getBUTTON_ELEMENT:function(){return BUTTON_ELEMENT;},
			_getBasicSelf:function() { return _self;},
			_getLAYOUT:function(){ return LAYOUT;},
			_getDIV_MAIN:function(){ return DIV_MAIN;},
			_getOptions_getByKey:_getOptions_getByKey,
			_getOptions_isActivatedByKey:_getOptions_isActivatedByKey,
			_getOptions_getLabelByKey:_getOptions_getLabelByKey,
			_getOptions_getValByKey:_getOptions_getValByKey
		};
	}

	function init() {
		PARAS = basics_ermittelURLParameter();
		if (!checkIfCssRuleExists('lds-dual-ring')) addStyleCode('.lds-dual-ring {display:inline-block;width:64px;height:64px;}.lds-dual-ring:after {content:" ";display:block;width:46px;height:46px;margin:1px;border-radius:50%;border:5px solid #fff;border-color:#2e74b5 transparent #2e74b5 transparent;animation:lds-dual-ring 0.6s linear infinite;}@keyframes lds-dual-ring {0% {transform: rotate(0deg);}100% {transform: rotate(360deg);}}');
		addStyleTag(myAjax._plugin_home_url+'/css/styles.css');
		//addStyleTag('https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css');
		//addStyleTag('https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.css');
		//addScriptTag('https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js');

		LAYOUT = new Layout();

		if (DIV_MAIN == null) {
			DIV_MAIN = document.getElementById(DIV_ID);
			if (DIV_MAIN == null) {
				DIV_MAIN = document.createElement('div');
				document.getElementsByTagName('body')[0].appendChild(DIV_MAIN);
			}
		}

		function _init() {
			LAYOUT.renderEingabeMaske();
			//_renderEingabeMaske();
		}
		function _initPremium() {
	    	if (isPremium() && myAjax._premJS !== "") {
				if (window.SasoEventticketsValidatorPremium) {
					PREMIUM = new SasoEventticketsValidatorPremium(myAjax, getHelperFunktions());
					_init();
				}
	    	} else {
				_init();
	    	}
		}

		let url = DATEN_URL+'?action='+myAjax._action+'&a_sngmbh=getOptions&t='+new Date().getTime();
		macheAjax(url, function(responseText) {
			let optionData = JSON.parse(responseText);
			OPTIONS.list = optionData.data;
			for (let a=0;a<OPTIONS.list.length;a++) {
				let item = OPTIONS.list[a];
				OPTIONS.mapKeys[item.key] = item;
			}
			_initPremium();
		},null,true);
	}

	init();
	//window.BASIC = getHelperFunktions();
}
Ajax_sasoEventtickets && SasoEventticketsValidator(Ajax_sasoEventtickets);