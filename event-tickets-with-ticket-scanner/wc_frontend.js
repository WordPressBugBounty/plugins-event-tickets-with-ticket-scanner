function SasoEventticketsValidator_WC_frontend($, phpObject) {
	const { __, _x, _n, sprintf } = wp.i18n;
	let _self = this;
	let inputTypes = [phpObject.inputType, "text", "value"];

	function init() {
		_addHandlerToTheCodeFields();
	}

	function addStyleCode(content) {
		let c = document.createElement('style');
		c.innerHTML = content;
		document.getElementsByTagName("head")[0].appendChild(c);
	}

	// fkt nicht bei cart updates, da dies nicht neu initialisiert wird
	function _addHandlerToTheCodeFields() {
		let isStoring = false;
		let waitingTimeout = null;
		let isChanged = false;

		function sendCode(elem, code, type) {
			//clearWaitingTimeout();
			if (!isStoring) {
				$('div[class="woocommerce"]').block({
	 				//message: '...loading...',
	 				message: null,
	 				overlayCSS: {
	 					background: '#fff',
	 					opacity: 0.6
	 				}
	 			});
				isStoring = true;
				let cart_item_id = elem.attr('data-cart-item-id');
				let cart_item_count = elem.attr('data-cart-item-count');
				let nonce = phpObject.nonce;
		 		$.ajax(
		 			{
		 				type: 'POST',
		 				url: phpObject.ajaxurl,
		 				data: {
		 					action: phpObject.action,
		 					a: 'updateSerialCodeToCartItem',
		 					security: nonce,
		 					cart_item_id: cart_item_id,
							cart_item_count: cart_item_count,
							type: type,
		 					code: code
		 				},
		 				success: function( response ) {
		 					$('div[class="woocommerce"]').unblock();
		 					$('.cart_totals').unblock();
		 					if (response.success) {
			 					elem.val(response.code);
		 					} else {
		 						if (response.msg) alert(response.msg);
		 					}
							isStoring = false;
							//window.location.reload();
		 				}
		 			}
		 		)
	 		}
		}

		function clearWaitingTimeout() {
			clearTimeout(waitingTimeout);
		}
		function setWaitingTimeout(elem, code) {
			clearWaitingTimeout();
			waitingTimeout = setTimeout(()=>{
				if (isChanged) {
					isChanged = false;
					sendCode(elem, code);
				}
			}, 2500);
		}

		// finde die code text inputs
		inputTypes.forEach(inputType => {
			$('body').find('input[data-input-type="'+inputType+'"][data-plugin="event"]')
				.on('keyup',function(){
					/*
					$('.cart_totals').block({
						message: null,
						overlayCSS: {
							background: '#fff',
							opacity: 0.6
						}
					});
					isStoring = false;
					isChanged = true;
					let elem = $(this);
					let code = elem.val().trim();
					setWaitingTimeout(elem, code);
					*/
				})
				.on('paste', event=>{
					isStoring = false;
					let elem = $(event.srcElement);
					let code = (event.clipboardData || window.clipboardData).getData('text');
					if (typeof code == "string") {
						code = code.trim();
						isChanged = true;
						sendCode(elem, code, "saso_eventtickets_request_name_per_ticket");
					} else { alert("no text"); }
				})
				.on('change',function(){
					let elem = $(this);
					let code = elem.val().trim();
					//let cart_item_id = elem.data('cart-item-id');
					//let d = document.querySelector('input[data-cart-item-id="'+cart_item_id+'"]').value
					isChanged = true;
					sendCode(elem, code, "saso_eventtickets_request_name_per_ticket");
				})
				/*
				.on('blur',function(){
					let elem = $(this);
					let code = elem.val().trim();
					if (code != "" && isChanged) {
						let cart_item_id = elem.data('cart-item-id');
						//let d = document.querySelector('input[data-cart-item-id="'+cart_item_id+'"]').value
						sendCode(elem, code, "saso_eventtickets_request_name_per_ticket");
					}
				})
				*/
				.removeAttr('disabled');
		});

		inputTypes.forEach(inputType => {
			$('body').find('select[data-input-type="'+inputType+'"][data-plugin="event"]')
				.on('change',function(){
					let elem = $(this);
					let code = elem.val().trim();
					//let cart_item_id = elem.data('cart-item-id');
					//let d = document.querySelector('input[data-cart-item-id="'+cart_item_id+'"]').value
					isChanged = true;
					sendCode(elem, code, "saso_eventtickets_request_value_per_ticket");
				})
				.removeAttr('disabled');
		});

		$('body').find('input[data-input-type="daychooser"][data-plugin="event"]').each((idx, input) => {
			let elem = $(input);
			let dateFormat = elem.attr('placeholder');
			dateFormat = dateFormat != null ? dateFormat.trim() : '';
			dateFormat = dateFormat ? dateFormat : 'YYYY-MM-DD';
			elem.attr('placeholder', __(dateFormat));
			let data_offset_start = 0;
			let data_offset_end = 0;
			try {
				data_offset_start =	parseInt(elem.attr('data-offset-start'));
			} catch (error) {
				//console.log(error);
			}
			if (elem.attr('min') && elem.attr('min').length > 0) {
				data_offset_start = elem.attr('min');
			}
			try {
				data_offset_end = parseInt(elem.attr('data-offset-end'));
			} catch (error) {
				//console.log(error);
			}
			if (elem.attr('max') && elem.attr('max').length > 0) {
				data_offset_end = elem.attr('max');
			}
			//let today = new Date();
			//let start = new Date(today.getFullYear(), today.getMonth(), today.getDate() + data_offset_start);
			//let end = new Date(today.getFullYear(), today.getMonth(), today.getDate() + data_offset_end);

			elem.on('change',()=>{
				let code = elem.val().trim();
				isChanged = true;
				let to_be_changed = [elem];
				// update the other date pickers if no value is set to use this date
				let data_cart_item_id = elem.attr('data-cart-item-id');
				$('body').find('input[data-input-type="daychooser"][data-plugin="event"][id^="saso_eventtickets_request_daychooser['+data_cart_item_id+']"]').each((idx, input) => {
					let input_elem = $(input);
					let v = input_elem.val().trim();
					if (!v) {
						console.log(input_elem.attr("id")+'set value to: '+code);
						input_elem.val(code);
						to_be_changed.push(input_elem);
					}
				});

				// remove the related error message on the cart
				//let data_cart_item_count = elem.attr('data-cart-item-count');
				//$('li[data-cart-item-id="'+data_cart_item_id+'"][data-cart-item-count="'+data_cart_item_count+'"]').remove();
				// send data to the server
				wait = 0;
				to_be_changed.forEach(input => {
					console.log(input.attr("id")+'send code: '+code);
					window.setTimeout(()=>{
						sendCode(input, code, "saso_eventtickets_request_daychooser");
					}, wait);
					if (wait == 0) wait = 250;
				});
			})
			.datepicker({
				dateFormat: 'yy-mm-dd',
				//dateFormat: dateFormat,
				showWeek: true,
				firstDay: 1,
				hideIfNoPrevNext : true,
				minDate: data_offset_start,
				maxDate: data_offset_end,
				beforeShow: function(input, options) {
					this._sasoevent_input_field = $(input);
				},
				beforeShowDay: function(date) { // https://api.jqueryui.com/datepicker/#option-beforeShow
					let day = date.getDay();
					let data_exclude_wdays = this._sasoevent_input_field.attr('data-exclude-wdays');
					let selectable = true;
					let cssClass = '';
					let toolTipp = '';
					if (data_exclude_wdays && data_exclude_wdays.length > 0) {
						let excludedDays = data_exclude_wdays.split(',');
						selectable = excludedDays.indexOf(day.toString()) == -1;

						cssClass = selectable ? '' : 'ui-datepicker-unselectable';
						toolTipp = selectable ? '' : __('This day is not selectable');
					}
					if (selectable) {
						// check if the date is in the past
						let today = new Date();
						let y = date.getFullYear();
						let m = date.getMonth() + 1;
						let d = date.getDate();
						let dateStr = y + '-' + (m < 10 ? '0' : '') + m + '-' + (d < 10 ? '0' : '') + d;
						let todayStr = today.getFullYear() + '-' + (today.getMonth() + 1 < 10 ? '0' : '') + (today.getMonth() + 1) + '-' + (today.getDate() < 10 ? '0' : '') + today.getDate();
						if (dateStr < todayStr) {
							selectable = false;
							cssClass = 'ui-datepicker-unselectable';
							toolTipp = __('This day is not selectable');
						}
					}
					if (selectable) {
						let data_exclude_dates = this._sasoevent_input_field.attr('data-exclude-dates');
						if (data_exclude_dates && data_exclude_dates.length > 0) {
							let excludedDates = data_exclude_dates.split(',');
							let y = date.getFullYear();
							let m = date.getMonth() + 1;
							let d = date.getDate();
							let dateStr = y + '-' + (m < 10 ? '0' : '') + m + '-' + (d < 10 ? '0' : '') + d;
							selectable = excludedDates.indexOf(dateStr) == -1;

							cssClass = selectable ? '' : 'ui-datepicker-unselectable';
							toolTipp = selectable ? '' : __('This day is not selectable');
						}
					}
					return [selectable, cssClass, toolTipp];
					//return [true, ''];
				}
			})
			.removeAttr('disabled');
		});

		//addStyleCode('#ui-datepicker-div > table {background-color: white;}');
	}

	init();
}

(function($){
 	$(document).ready(function(){
 		SasoEventticketsValidator_WC_frontend($, SasoEventticketsValidator_phpObject);
 	});
})(jQuery);