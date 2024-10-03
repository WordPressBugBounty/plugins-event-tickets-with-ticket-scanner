function SasoEventticketsValidator_WC_frontend($, phpObject) {
	const { __, _x, _n, sprintf } = wp.i18n;
	let _self = this;
	let inputTypes = [phpObject.inputType, "text", "value"];

	function init() {
		_addHandlerToTheCodeFields();
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
		 		$.ajax(
		 			{
		 				type: 'GET',
		 				url: phpObject.ajaxurl,
		 				data: {
		 					action: phpObject.action,
		 					a: 'updateSerialCodeToCartItem',
		 					security: $('#woocommerce-cart-nonce').val(),
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
				.on('paste', ()=>{
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
	}

	init();
}

(function($){
 	$(document).ready(function(){
 		SasoEventticketsValidator_WC_frontend($, SasoEventticketsValidator_phpObject);
 	});
})(jQuery);