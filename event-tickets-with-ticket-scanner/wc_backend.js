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
						// Pass through debug parameter if set in URL
						var urlParams = new URLSearchParams(window.location.search);
						if (urlParams.has('VollstartValidatorDebug')) {
							_data['VollstartValidatorDebug'] = urlParams.get('VollstartValidatorDebug') || '1';
						}
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
						// Pass through debug parameter if set in URL
						var urlParams = new URLSearchParams(window.location.search);
						if (urlParams.has('VollstartValidatorDebug')) {
							_data['VollstartValidatorDebug'] = urlParams.get('VollstartValidatorDebug') || '1';
						}
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
			// Pass through debug parameter if set in URL
			var urlParams = new URLSearchParams(window.location.search);
			if (urlParams.has('VollstartValidatorDebug')) {
				_data['VollstartValidatorDebug'] = urlParams.get('VollstartValidatorDebug') || '1';
			}
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

	// ── Product Calendar (#191) ─────────────────────────────────
	let calendarData = {};
	let calendarCurrentMonth = null;

	let $calendarContent = null;

	function calendarInit() {
		if (!phpObject.product_id || phpObject.product_id <= 0) return;

		let $btn = $('button[data-id="' + phpObject.prefix + 'btn_product_calendar"]');
		if ($btn.length === 0) return;

		$btn.prop('disabled', false).on('click', function(e) {
			e.preventDefault();
			$calendarContent = $('<div>').html(_getSpinnerHTML());
			$calendarContent.dialog({
				title: __('Sold Tickets Calendar', 'event-tickets-with-ticket-scanner'),
				modal: true,
				width: 520,
				minHeight: 300,
				close: function() { $(this).dialog('destroy').remove(); }
			});
			calendarLoad();
		});

		let $printBtn = $('button[data-id="' + phpObject.prefix + 'btn_product_calendar_print"]');
		if ($printBtn.length > 0) {
			$printBtn.prop('disabled', false).on('click', function(e) {
				e.preventDefault();
				calendarPrintList();
			});
		}
	}

	function calendarPrintList() {
		let w = window.open('about:blank');
		addStyleCode('.lds-dual-ring {display:inline-block;width:64px;height:64px;}.lds-dual-ring:after {content:" ";display:block;width:46px;height:46px;margin:1px;border-radius:50%;border:5px solid #fff;border-color:#2e74b5 transparent #2e74b5 transparent;animation:lds-dual-ring 0.6s linear infinite;}@keyframes lds-dual-ring {0% {transform: rotate(0deg);}100% {transform: rotate(360deg);}}', w.document);
		w.document.body.innerHTML += _getSpinnerHTML();

		$.get(phpObject.ajaxurl, {
			action: phpObject.action,
			nonce: phpObject.nonce,
			a_sngmbh: 'getProductCalendarData',
			'data[product_id]': phpObject.product_id
		}, function(response) {
			if (!response.success) {
				w.document.body.innerHTML = '<p style="color:red;">' + (response.data || 'Error') + '</p>';
				return;
			}
			let dates = response.data.dates || {};
			let sortedDates = Object.keys(dates).sort();

			if (sortedDates.length === 0) {
				w.document.body.innerHTML = '<p>' + __('No tickets found.', 'event-tickets-with-ticket-scanner') + '</p>';
				return;
			}

			let promises = sortedDates.map(function(dateStr) {
				return new Promise(function(resolve) {
					$.get(phpObject.ajaxurl, {
						action: phpObject.action,
						nonce: phpObject.nonce,
						a_sngmbh: 'getProductCalendarDetails',
						'data[product_id]': phpObject.product_id,
						'data[date]': dateStr
					}, function(resp) {
						resolve({date: dateStr, count: dates[dateStr], tickets: resp.success ? resp.data : []});
					}).fail(function() {
						resolve({date: dateStr, count: dates[dateStr], tickets: []});
					});
				});
			});

			Promise.all(promises).then(function(groups) {
				let productName = $('#title').val() || document.title;
				let $output = $('<div style="margin:1cm 2cm;font-family:sans-serif;">');
				$output.append('<h2>' + sprintf(
					/* translators: %s: product name */
					__('Ticket List — %s', 'event-tickets-with-ticket-scanner'),
					productName
				) + '</h2>');

				let grandTotal = 0;
				for (let i = 0; i < groups.length; i++) {
					let g = groups[i];
					grandTotal += g.count;

					$output.append('<h3 style="margin-top:1.5em;border-bottom:1px solid #ccc;padding-bottom:4px;">' + g.date + ' (' + sprintf(
						/* translators: %d: number of tickets */
						_n('%d ticket', '%d tickets', g.count, 'event-tickets-with-ticket-scanner'),
						g.count
					) + ')</h3>');

					if (g.tickets.length === 0) {
						$output.append('<p><em>' + __('No details available.', 'event-tickets-with-ticket-scanner') + '</em></p>');
						continue;
					}

					let $table = $('<table style="width:100%;border-collapse:collapse;margin-bottom:1em;">');
					$table.append('<thead><tr style="background:#f0f0f0;">'
						+ '<th style="text-align:left;padding:4px 8px;border:1px solid #ddd;">#</th>'
						+ '<th style="text-align:left;padding:4px 8px;border:1px solid #ddd;">' + __('Ticket', 'event-tickets-with-ticket-scanner') + '</th>'
						+ '<th style="text-align:left;padding:4px 8px;border:1px solid #ddd;">' + __('Order', 'event-tickets-with-ticket-scanner') + '</th>'
						+ '<th style="text-align:left;padding:4px 8px;border:1px solid #ddd;">' + __('Status', 'event-tickets-with-ticket-scanner') + '</th>'
						+ '</tr></thead>');
					let $tbody = $('<tbody>');
					for (let j = 0; j < g.tickets.length; j++) {
						let t = g.tickets[j];
						let statusText = t.redeemed
							? __('Redeemed', 'event-tickets-with-ticket-scanner')
							: __('Active', 'event-tickets-with-ticket-scanner');
						$tbody.append('<tr>'
							+ '<td style="padding:3px 8px;border:1px solid #ddd;">' + (j + 1) + '</td>'
							+ '<td style="padding:3px 8px;border:1px solid #ddd;">' + t.code_display + '</td>'
							+ '<td style="padding:3px 8px;border:1px solid #ddd;">' + (t.order_id > 0 ? '#' + t.order_id : '-') + '</td>'
							+ '<td style="padding:3px 8px;border:1px solid #ddd;">' + statusText + '</td>'
							+ '</tr>');
					}
					$table.append($tbody);
					$output.append($table);
				}

				$output.append('<p style="margin-top:1em;font-weight:bold;">' + sprintf(
					/* translators: %d: total number of tickets */
					__('Total: %d tickets', 'event-tickets-with-ticket-scanner'),
					grandTotal
				) + '</p>');

				$(w.document.body).html($output);
				w.print();
			});
		});
	}

	function calendarLoad() {
		if ($calendarContent) $calendarContent.html(_getSpinnerHTML());

		$.get(phpObject.ajaxurl, {
			action: phpObject.action,
			nonce: phpObject.nonce,
			a_sngmbh: 'getProductCalendarData',
			'data[product_id]': phpObject.product_id
		}, function(response) {
			if (!response.success) {
				if ($calendarContent) $calendarContent.html('<p style="color:red;">' + (response.data || 'Error') + '</p>');
				return;
			}
			calendarData = response.data.dates || {};

			let dates = Object.keys(calendarData).sort();
			if (dates.length > 0) {
				let parts = dates[dates.length - 1].split('-');
				calendarCurrentMonth = {year: parseInt(parts[0]), month: parseInt(parts[1]) - 1};
			} else {
				let now = new Date();
				calendarCurrentMonth = {year: now.getFullYear(), month: now.getMonth()};
			}
			calendarRender();
		});
	}

	function calendarRender() {
		let $content = $calendarContent;
		if (!$content) return;
		let year = calendarCurrentMonth.year;
		let month = calendarCurrentMonth.month;

		let monthNames = [
			__('January', 'event-tickets-with-ticket-scanner'),
			__('February', 'event-tickets-with-ticket-scanner'),
			__('March', 'event-tickets-with-ticket-scanner'),
			__('April', 'event-tickets-with-ticket-scanner'),
			__('May', 'event-tickets-with-ticket-scanner'),
			__('June', 'event-tickets-with-ticket-scanner'),
			__('July', 'event-tickets-with-ticket-scanner'),
			__('August', 'event-tickets-with-ticket-scanner'),
			__('September', 'event-tickets-with-ticket-scanner'),
			__('October', 'event-tickets-with-ticket-scanner'),
			__('November', 'event-tickets-with-ticket-scanner'),
			__('December', 'event-tickets-with-ticket-scanner')
		];
		let dayHeaders = [
			__('Mon', 'event-tickets-with-ticket-scanner'),
			__('Tue', 'event-tickets-with-ticket-scanner'),
			__('Wed', 'event-tickets-with-ticket-scanner'),
			__('Thu', 'event-tickets-with-ticket-scanner'),
			__('Fri', 'event-tickets-with-ticket-scanner'),
			__('Sat', 'event-tickets-with-ticket-scanner'),
			__('Sun', 'event-tickets-with-ticket-scanner')
		];

		let $nav = $('<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">');
		let $prev = $('<button type="button" class="button button-small">&laquo;</button>');
		let $next = $('<button type="button" class="button button-small">&raquo;</button>');
		let $title = $('<strong>' + monthNames[month] + ' ' + year + '</strong>');
		$prev.on('click', function(e) { e.preventDefault(); calendarNavigate(-1); });
		$next.on('click', function(e) { e.preventDefault(); calendarNavigate(1); });
		$nav.append($prev).append($title).append($next);

		let $table = $('<table class="widefat" style="table-layout:fixed;text-align:center;">');
		let $thead = $('<thead><tr></tr></thead>');
		for (let i = 0; i < 7; i++) {
			$thead.find('tr').append('<th style="text-align:center;padding:4px;">' + dayHeaders[i] + '</th>');
		}
		$table.append($thead);

		let $tbody = $('<tbody>');
		let firstDay = new Date(year, month, 1).getDay();
		let startOffset = (firstDay === 0 ? 6 : firstDay - 1);
		let daysInMonth = new Date(year, month + 1, 0).getDate();
		let day = 1;

		for (let row = 0; row < 6 && day <= daysInMonth; row++) {
			let $tr = $('<tr>');
			for (let col = 0; col < 7; col++) {
				if ((row === 0 && col < startOffset) || day > daysInMonth) {
					$tr.append('<td style="padding:4px;"></td>');
				} else {
					let dateStr = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
					let count = calendarData[dateStr] || 0;
					let $td = $('<td style="padding:4px;position:relative;vertical-align:top;height:45px;">');
					$td.append('<div style="font-size:11px;color:#666;">' + day + '</div>');
					if (count > 0) {
						let $badge = $('<div style="font-weight:bold;color:#0073aa;cursor:pointer;">' + count + '</div>');
						$badge.data('cal-date', dateStr);
						$badge.on('click', function(e) {
							e.preventDefault();
							calendarShowDetails($(this).data('cal-date'));
						});
						$td.append($badge);
						$td.css('background', '#f0f7ff');
					}
					$tr.append($td);
					day++;
				}
			}
			$tbody.append($tr);
		}
		$table.append($tbody);

		let total = 0;
		for (let d in calendarData) { total += calendarData[d]; }
		let $totalDiv = $('<div style="margin-top:8px;font-size:12px;color:#666;">');
		$totalDiv.text(
			sprintf(
				/* translators: %d: total number of sold tickets */
				__('Total sold: %d tickets', 'event-tickets-with-ticket-scanner'),
				total
			)
		);

		$content.empty().append($nav).append($table).append($totalDiv);
	}

	function calendarNavigate(direction) {
		calendarCurrentMonth.month += direction;
		if (calendarCurrentMonth.month < 0) {
			calendarCurrentMonth.month = 11;
			calendarCurrentMonth.year--;
		} else if (calendarCurrentMonth.month > 11) {
			calendarCurrentMonth.month = 0;
			calendarCurrentMonth.year++;
		}
		calendarRender();
	}

	function calendarShowDetails(dateStr) {
		let $dlgContent = $('<div>').html(_getSpinnerHTML());
		$dlgContent.dialog({
			title: sprintf(
				/* translators: %s: date string */
				__('Tickets for %s', 'event-tickets-with-ticket-scanner'),
				dateStr
			),
			modal: true,
			width: 500,
			close: function() { $(this).dialog('destroy'); }
		});

		$.get(phpObject.ajaxurl, {
			action: phpObject.action,
			nonce: phpObject.nonce,
			a_sngmbh: 'getProductCalendarDetails',
			'data[product_id]': phpObject.product_id,
			'data[date]': dateStr
		}, function(response) {
			if (!response.success) {
				$dlgContent.html('<p style="color:red;">' + (response.data || 'Error') + '</p>');
				return;
			}
			let tickets = response.data;
			if (tickets.length === 0) {
				$dlgContent.html('<p>' + __('No tickets found for this date.', 'event-tickets-with-ticket-scanner') + '</p>');
				return;
			}

			let $table = $('<table class="widefat striped">');
			$table.append('<thead><tr>'
				+ '<th>' + __('Ticket', 'event-tickets-with-ticket-scanner') + '</th>'
				+ '<th>' + __('Order', 'event-tickets-with-ticket-scanner') + '</th>'
				+ '<th>' + __('Status', 'event-tickets-with-ticket-scanner') + '</th>'
				+ '</tr></thead>');
			let $tbody = $('<tbody>');

			for (let i = 0; i < tickets.length; i++) {
				let t = tickets[i];
				let $tr = $('<tr>');

				let ticketHtml;
				if (t.public_ticket_id && phpObject.ticket_base_url) {
					ticketHtml = '<a href="' + phpObject.ticket_base_url + t.public_ticket_id + '" target="_blank">' + t.code_display + '</a>';
				} else {
					ticketHtml = t.code_display;
				}
				$tr.append('<td>' + ticketHtml + '</td>');

				let orderHtml = t.order_id > 0
					? '<a href="' + phpObject.ajaxurl.replace('admin-ajax.php', 'post.php?post=' + t.order_id + '&action=edit') + '" target="_blank">#' + t.order_id + '</a>'
					: '-';
				$tr.append('<td>' + orderHtml + '</td>');

				let statusText = t.redeemed
					? __('Redeemed', 'event-tickets-with-ticket-scanner')
					: __('Active', 'event-tickets-with-ticket-scanner');
				let statusColor = t.redeemed ? '#d63638' : '#00a32a';
				$tr.append('<td style="color:' + statusColor + ';">' + statusText + '</td>');

				$tbody.append($tr);
			}
			$table.append($tbody);
			$dlgContent.empty().append($table);
		});
	}

	function starten() {
		_sasoEventtickets = sasoEventtickets(phpObject, true);
		if (phpObject.scope && phpObject.scope == "order") {
			_addHandlerToTheOrderCodeFields();
		} else {
			renderFormatterFields();
			_addHandlerToTheCodeFields();
			_addHandlerToTheInputFields();
			calendarInit();
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