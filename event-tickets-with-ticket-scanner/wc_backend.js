function SasoEventticketsValidator_WC_backend($, phpObject) {
	const { __, _x, _n, sprintf } = wp.i18n;
	let _self = this;
	let _sasoEventtickets;
	let DATA = {};

	function renderFormatterFields() {
		let hiddenValueField = $('input[data-id="'+phpObject.formatterInputFieldDataId+'"]');
		let formatterValues = $(hiddenValueField).val();

		if (formatterValues != "") {
			try {
				formatterValues = JSON.parse(formatterValues);
			} catch (e) {
				//console.log(e);
			}
		}

		let serialCodeFormatter = _sasoEventtickets.form_fields_serial_format($('#'+phpObject._divAreaId));
		serialCodeFormatter.setNoNumberOptions();
		serialCodeFormatter.setFormatterValues(formatterValues);
		serialCodeFormatter.setCallbackHandle(_formatterValues=>{
			$(hiddenValueField).val(JSON.stringify(_formatterValues));
		});
		serialCodeFormatter.render();

		$(hiddenValueField).val(JSON.stringify(serialCodeFormatter.getFormatterValues()));
	}

	function _addHandlerToTheOrderCodeFields() {
		if (typeof phpObject.tickets != "undefined") {
			let ok = false;
			for(let key in phpObject.tickets) {
				if (phpObject.tickets[key].codes != "") {
					ok = true;
					break;
				}
			}
			if (ok) {
				$('body').find('button[data-id="'+phpObject.prefix+'btn_download_alltickets_one_pdf"]').prop('disabled', false).on('click', ()=>{
					let url = phpObject.ajaxurl + '?'
					+'action='+encodeURIComponent(phpObject.action)
					+'&nonce='+encodeURIComponent(phpObject.nonce)
					+'&a_sngmbh=downloadAllTicketsAsOnePDF'
					+'&data[order_id]='+encodeURIComponent(phpObject.order_id);
					window.open(url, 'download_tickets');
					return false;
				});
				$('body').find('button[data-id="'+phpObject.prefix+'btn_remove_tickets"]').prop('disabled', false).on('click', event=>{
					event.preventDefault();
					if (confirm("Do you want to remove the ticket from the order? Your customer will not be informed. New tickets will be assigned to the order if you change the order status and the status is set to assign ticket numbers. Or you use the add tickets button (Premium).")) {
						let btn = event.target;
						$(btn).prop("disabled", true);
						let url = phpObject.ajaxurl;
						let _data = {
							action:encodeURIComponent(phpObject.action),
							nonce:encodeURIComponent(phpObject.nonce),
							a_sngmbh:'removeAllTicketsFromOrder',
							"data[order_id]":encodeURIComponent(phpObject.order_id)
						};
						$.get( url, _data, function( response ) {
							if (!response.success) {
								alert(response);
							} else {
								window.location.reload(true);
							}
						});
					}
					return false;
				});
				$('body').find('button[data-id="'+phpObject.prefix+'btn_download_badge"]').prop('disabled', false).on('click', event=>{
					event.preventDefault();
					// check how many tickets are in the order
					// if more than 1, show a list of tickets
					// if only 1, show the ticket

					let ticket_numbers = [];
					for(var key in phpObject.tickets) {
						let ticket = phpObject.tickets[key];
						if (ticket.codes != "") {
							let codes = ticket.codes.split(',');
							for(let i=0;i<codes.length;i++) {
								let code = codes[i].trim();
								if (code != "") {
									ticket_numbers.push(code);
								}
							}
						}
					}

					if (ticket_numbers.length > 1) {
						let ticketList = $('<div>');
						for(let i=0;i<ticket_numbers.length;i++) {
							let ticket_number = ticket_numbers[i];
							let elem = $('<div>').appendTo(ticketList);
							elem.append($('<h4>').html('#'+(i+1)+'. '+ticket_number));
							elem.append($('<button>').html('Download').addClass('button button-primary')).on('click', event=>{
								event.preventDefault();
								_downloadFile('downloadPDFTicketBadge', {'code':ticket_number});
								return false;
							});
							elem.append('<hr>');
							elem.appendTo(ticketList);
						}
						renderInfoBox(ticketList, 'Select a ticket badge to download');

					} else {
						_downloadFile('downloadPDFTicketBadge', {'code':ticket_numbers[0]});
					}
					return false;
				});
				$('body').find('button[data-id="'+phpObject.prefix+'btn_remove_non_tickets"]').prop('disabled', false).on('click', event=>{
					event.preventDefault();
					if (confirm("Do you want to remove the all ticket that cannot be found in the database from the order? This will keep the ticket numbers, that exists. Your customer will not be informed. New tickets will be assigned to the order if you change the order status and the status is set to assign ticket numbers. Or you use the add tickets button (Premium).")) {
						let btn = event.target;
						$(btn).prop("disabled", true);
						let url = phpObject.ajaxurl;
						let _data = {
							action:encodeURIComponent(phpObject.action),
							nonce:encodeURIComponent(phpObject.nonce),
							a_sngmbh:'removeAllNonTicketsFromOrder',
							"data[order_id]":encodeURIComponent(phpObject.order_id)
						};
						$.get( url, _data, function( response ) {
							if (!response.success) {
								alert(response);
							} else {
								window.location.reload(true);
							}
						});
					}
					return false;
				});
			}
		}
	}

	function _addHandlerToTheCodeFields() {
		$('body').find('button[data-id="'+phpObject.prefix+'btn_download_flyer"]').prop('disabled', false).on('click', ()=>{
			let url = phpObject.ajaxurl + '?'
			+'action='+encodeURIComponent(phpObject.action)
			+'&nonce='+encodeURIComponent(phpObject.nonce)
			+'&a_sngmbh=downloadFlyer'
			+'&data[product_id]='+encodeURIComponent(phpObject.product_id);
			window.open(url, 'download_flyer');
			return false;
		});

		$('body').find('button[data-id="'+phpObject.prefix+'btn_download_ics"]').prop('disabled', false).on('click', ()=>{
			let url = phpObject.ajaxurl + '?'
			+'action='+encodeURIComponent(phpObject.action)
			+'&nonce='+encodeURIComponent(phpObject.nonce)
			+'&a_sngmbh=downloadICSFile'
			+'&data[product_id]='+encodeURIComponent(phpObject.product_id);
			window.open(url, 'download_ics');
			return false;
		});

		$('body').find('button[data-id="'+phpObject.prefix+'btn_download_ticket_infos"]').prop('disabled', false).on('click', event=>{
			event.preventDefault();
			let btn = event.target;
			$(btn).prop("disabled", true);
			let url = phpObject.ajaxurl;
			let _data = {
				action:encodeURIComponent(phpObject.action),
				nonce:encodeURIComponent(phpObject.nonce),
				a_sngmbh:'downloadTicketInfosOfProduct',
				"data[product_id]":encodeURIComponent(phpObject.product_id)
			};
			$.get( url, _data, function( response ) {
				if (!response.success) {
					alert(response);
				} else {
					let ticket_infos = response.data.ticket_infos;
					let product = response.data.product;
					let w = window.open('about:blank');
					addStyleCode('.lds-dual-ring {display:inline-block;width:64px;height:64px;}.lds-dual-ring:after {content:" ";display:block;width:46px;height:46px;margin:1px;border-radius:50%;border:5px solid #fff;border-color:#2e74b5 transparent #2e74b5 transparent;animation:lds-dual-ring 0.6s linear infinite;}@keyframes lds-dual-ring {0% {transform: rotate(0deg);}100% {transform: rotate(360deg);}}', w.document);
					w.document.body.innerHTML += _getSpinnerHTML();
					window.setTimeout(()=>{
						let output = $('<div style="margin-left:2.5cm;margin-top:1cm;">');
						output.append($('<h3>').html('Ticket Infos for Product "'+product.name+'"'));
						for(let i=0;i<ticket_infos.length;i++) {
							let ticket_info = ticket_infos[i];
							let metaObj = getCodeObjectMeta(ticket_info);
							let elem = $('<div>').appendTo(output);
							elem.append($('<h4>').html('#'+(i+1)+'. '+ticket_info.code_display));
							if (metaObj.wc_ticket._public_ticket_id) {
								elem.append($('<div>').html('Ticket Public Id: '+metaObj.wc_ticket._public_ticket_id));
							}
							elem.append("Order Id: "+metaObj.woocommerce.order_id+"<br>");
							if (ticket_info._customer_name) {
								elem.append(ticket_info._customer_name);
							}
							elem.append($('<div style="margin-top:10px;margin-bottom:15px;">').qrcode(ticket_info.code));
							elem.append('<hr>');
							elem.appendTo(output);
						}
						$(w.document.body).html(output);
						$(btn).prop("disabled", false);
						w.print();
					}, 250);
				}
			});
		});
	}

	function _addHandlerToTheInputFields() {
		//console.log(phpObject);
	}

	function getCodeObjectMeta(codeObj) {
		if (!codeObj.metaObj) codeObj.metaObj = JSON.parse(codeObj.meta);
		return codeObj.metaObj;
	}

	function _downloadFile(action, myData, filenameToStore, cbf, ecbf, pcbf) {
		let _data = Object.assign({}, DATA);
		_data.action = phpObject.action;
		_data.a_sngmbh = action;
		_data.t = new Date().getTime();
		_data.nonce = phpObject.nonce;
		pcbf && pcbf();
		for(var key in myData) _data['data['+key+']'] = myData[key];
		let params = "";
		for(var key in _data) params += key+"="+_data[key]+"&";
		let url = phpObject.ajaxurl+'?'+params;
		let window_name = myData.code ? myData.code : '_blank';
		let new_window = window.open(url, window_name);
		//window.location.href = url;
		//ajax_downloadFile(url, filenameToStore, cbf);
	}

	function renderInfoBox(content, myTitle) {
		let dlg = $('<div/>').html(content);
		let _options = {
			title: myTitle ? myTitle : 'Info',
			modal: true,
			minWidth: 400,
			minHeight: 200,
			buttons: [{text:'Ok', click:()=>{
				closeDialog(dlg);
			}}]
		};
		dlg.dialog(_options);
		return dlg;
	}

	function closeDialog(dlg) {
		$(dlg).dialog( "close" );
		$(dlg).html('');
		$(dlg).dialog("destroy").remove();
		$(dlg).empty();
		$(dlg).remove();
		$('.ui-dialog-content').dialog('destroy');
	}

	function addStyleCode(content, d) {
		if (!d) d = document;
		let c = d.createElement('style');
		c.innerHTML = content;
		d.getElementsByTagName("head")[0].appendChild(c);
	}

	function _getSpinnerHTML() {
		return '<span class="lds-dual-ring"></span>';
	}

	function starten() {
		_sasoEventtickets = sasoEventtickets(phpObject, true);
		if (phpObject.scope && phpObject.scope == "order") {
			_addHandlerToTheOrderCodeFields();
		} else {
			renderFormatterFields();
			_addHandlerToTheCodeFields();
			_addHandlerToTheInputFields();
		}
	}

	function init() {
		if (typeof sasoEventtickets === "undefined") {
			$.ajax({
				url: phpObject._backendJS,
				dataType: 'script',
				success: function( data, textStatus, jqxhr ) {
					starten();
				}
			});
		} else {
			starten();
		}
	}

	init();
}
(function($){
 	$(document).ready(function(){
 		SasoEventticketsValidator_WC_backend($, Ajax_sasoEventtickets_wc);
 	});
})(jQuery);