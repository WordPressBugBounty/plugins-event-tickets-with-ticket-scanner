/**
 * Seating Frontend JavaScript
 *
 * Handles seat selection UI on product pages.
 *
 * @package Event_Tickets_With_Ticket_Scanner
 * @since 2.8.0
 */

(function($) {
	'use strict';

	// WordPress i18n
	var __ = wp.i18n.__;
	var _x = wp.i18n._x;

	/**
	 * Seating Frontend Module
	 */
	var SasoSeatingFrontend = {
		/**
		 * Current seat block IDs (for releasing previous selections)
		 * Map of seat_id => block_id
		 */
		currentBlockIds: {},

		/**
		 * Currently selected seats data (array for multi-select)
		 */
		selectedSeats: [],

		/**
		 * Temporary selections in modal (before confirm)
		 */
		tempSelections: [],

		/**
		 * Maximum number of seats to select (from quantity input)
		 */
		maxSeats: 1,

		/**
		 * Reference to current modal container
		 */
		$currentModal: null,

		/**
		 * Reference to current selector container
		 */
		$currentSelector: null,

		/**
		 * Interval ID for auto-refresh while modal is open
		 */
		refreshIntervalId: null,

		/**
		 * Interval ID for countdown timer
		 */
		countdownIntervalId: null,

		/**
		 * Auto-refresh interval in milliseconds (30 seconds)
		 */
		REFRESH_INTERVAL: 30000,

		/**
		 * Plan colors (loaded from data attributes)
		 */
		planColors: {
			available: '#4CAF50',
			reserved: '#FFC107',
			booked: '#F44336',
			selected: '#2196F3'
		},

		/**
		 * Initialize the module
		 */
		init: function() {
			this.initSelectors();
			this.bindEvents();
			this.initializeExistingSelections();
			this.initCartCountdowns();
			this.initLockedDatepickers();
			this.initHeartbeat();
		},

		/**
		 * Initialize WordPress Heartbeat API integration
		 * Sends block IDs with each heartbeat to update last_seen on server
		 */
		initHeartbeat: function() {
			var self = this;

			// Hook into heartbeat-send to add our data
			$(document).on('heartbeat-send', function(e, data) {
				// Get all active block IDs
				var blockIds = Object.values(self.currentBlockIds);
				if (blockIds.length > 0) {
					data.saso_seating_blocks = blockIds;
				}
			});

			// Optionally handle response (for debugging or future use)
			$(document).on('heartbeat-tick', function(e, data) {
				if (data.saso_seating) {
					// Server acknowledged our blocks
					// Could be used to handle expired blocks in the future
				}
			});
		},

		/**
		 * Disable datepickers that have seats selected (date change would invalidate blocks)
		 */
		initLockedDatepickers: function() {
			$('.saso-datepicker-locked input').prop('disabled', true);
		},

		/**
		 * Initialize countdown timers for cart/checkout pages
		 * Finds any existing countdown elements and starts the timer
		 */
		initCartCountdowns: function() {
			var self = this;

			// Find all countdown containers (e.g., in cart)
			$('.saso-selected-seats-labels').each(function() {
				var $container = $(this);
				// Only init if has countdown elements and not already part of a selector
				if ($container.find('.saso-seat-countdown').length > 0 &&
					!$container.closest('.saso-seating-selector').length) {

					// Stop any existing timer first (important for AJAX reloads)
					self.stopCountdownTimer($container);

					// Convert remaining-seconds to countdown-end (client timestamp)
					// Use .attr() instead of .data() to get fresh DOM values after AJAX update
					$container.find('.saso-seat-countdown[data-remaining-seconds]').each(function() {
						var $countdown = $(this);
						var remainingSeconds = parseInt($countdown.attr('data-remaining-seconds')) || 0;
						if (remainingSeconds > 0) {
							var countdownEnd = Date.now() + (remainingSeconds * 1000);
							// Update both cache and attribute
							$countdown.data('countdown-end', countdownEnd);
							$countdown.attr('data-countdown-end', countdownEnd);
						}
					});

					self.startCountdownTimer($container);
				}
			});
		},

		/**
		 * Initialize all seating selectors on the page
		 * Reads JSON data from script tags and builds UI
		 */
		initSelectors: function() {
			var self = this;

			$('.saso-seating-selector').each(function() {
				var $selector = $(this);
				var selectorId = $selector.attr('id');

				if (!selectorId) {
					return;
				}

				// Get JSON data from associated script tag
				var $dataScript = $('#' + selectorId + '-data');
				if (!$dataScript.length) {
					return;
				}

				try {
					var data = JSON.parse($dataScript.text());
					self.buildSelectorUI($selector, data);
				} catch (e) {
					console.error('SasoSeating: Failed to parse selector data', e);
				}
			});
		},

		/**
		 * Build the selector UI from data
		 *
		 * @param {jQuery} $selector The selector container
		 * @param {Object} data Plan and seats data from PHP
		 */
		buildSelectorUI: function($selector, data) {
			var html = '';

			// Store data on element for later access
			$selector.data('plan-data', data);
			$selector.attr('data-layout', data.layoutType);
			$selector.attr('data-plan-id', data.planId);

			// Check if date is required but not yet selected
			var $wrapper = $selector.closest('.saso-seating-wrapper');
			var requiresDate = $wrapper.data('requires-date') == '1';
			var eventDate = $selector.data('event-date');
			var dateNotSelected = requiresDate && !eventDate;

			// Admin preview notice
			if (data.isPreview) {
				html += '<div class="saso-seating-preview-notice">' +
					'<strong>⚠️ ' + __('Admin Preview', 'event-tickets-with-ticket-scanner') + ':</strong> ' +
					__('This seating plan is not published yet.', 'event-tickets-with-ticket-scanner') + ' ' +
					'<a href="' + this.escapeHtml(data.adminUrl) + '" target="_blank">' +
					__('Publish plan', 'event-tickets-with-ticket-scanner') + '</a>' +
					'</div>';
			}

			// Label
			html += '<label class="saso-seating-label">' +
				__('Select your seat:', 'event-tickets-with-ticket-scanner');
			if (data.isRequired) {
				html += ' <span class="required">*</span>';
			}
			html += '</label>';

			// Venue plan image button (if set) - always visible
			if (data.planImage) {
				html += '<div class="saso-plan-image-preview">' +
					'<button type="button" class="button saso-view-plan-image" data-image="' + this.escapeHtml(data.planImage) + '">' +
					__('View Venue Plan', 'event-tickets-with-ticket-scanner') +
					'</button></div>';
			}

			// If date required but not selected, show message instead of selector
			if (dateNotSelected) {
				html += '<div class="saso-seating-date-required">' +
					__('Please select a date first to see available seats.', 'event-tickets-with-ticket-scanner') +
					'</div>';
			} else {
				// Build layout-specific UI
				if (data.layoutType === 'simple') {
					html += this.buildSimpleSelector(data);
				} else {
					html += this.buildVisualSelector(data);
				}
			}

			// Status area
			html += '<div class="saso-seating-status"></div>';

			// Insert HTML before the hidden input
			$selector.find('.saso-seat-selection-input').before(html);

			// Initialize colors for visual layout
			if (data.layoutType === 'visual') {
				this.planColors = {
					available: (data.meta.colors && data.meta.colors.available) || '#4CAF50',
					reserved: (data.meta.colors && data.meta.colors.reserved) || '#FFC107',
					booked: (data.meta.colors && data.meta.colors.booked) || '#F44336',
					selected: (data.meta.colors && data.meta.colors.selected) || '#2196F3'
				};
			}
		},

		/**
		 * Build simple dropdown selector HTML
		 *
		 * @param {Object} data Plan and seats data
		 * @returns {string} HTML string
		 */
		buildSimpleSelector: function(data) {
			var self = this;
			var selectedId = data.currentSelection ? (data.currentSelection.seat_id || (data.currentSelection[0] && data.currentSelection[0].seat_id)) : '';

			var html = '<select class="saso-seat-dropdown" name="saso_seat_id">';
			html += '<option value="">' + __('-- Select Seat --', 'event-tickets-with-ticket-scanner') + '</option>';

			data.seats.forEach(function(seat) {
				var isAvailable = seat.availability === 'free';
				var isSelected = String(seat.id) === String(selectedId);
				var label = (seat.meta && seat.meta.seat_label) || seat.seat_identifier;
				if (seat.meta && seat.meta.seat_category) {
					label += ' (' + seat.meta.seat_category + ')';
				}

				var statusText = '';
				if (!isAvailable && !isSelected) {
					statusText = seat.availability === 'sold'
						? ' (' + __('Sold', 'event-tickets-with-ticket-scanner') + ')'
						: ' (' + __('Reserved', 'event-tickets-with-ticket-scanner') + ')';
				}

				html += '<option value="' + seat.id + '"' +
					' data-seat-label="' + self.escapeHtml((seat.meta && seat.meta.seat_label) || seat.seat_identifier) + '"' +
					' data-seat-category="' + self.escapeHtml((seat.meta && seat.meta.seat_category) || '') + '"' +
					' data-seat-desc="' + self.escapeHtml((seat.meta && seat.meta.seat_desc) || '') + '"' +
					(isSelected ? ' selected' : '') +
					(!isAvailable && !isSelected ? ' disabled' : '') +
					'>' + self.escapeHtml(label + statusText) + '</option>';
			});

			html += '</select>';
			return html;
		},

		/**
		 * Build visual seat map selector HTML
		 *
		 * @param {Object} data Plan and seats data
		 * @returns {string} HTML string
		 */
		buildVisualSelector: function(data) {
			var self = this;
			var meta = data.meta || {};
			var colors = meta.colors || {};

			var colorAvailable = colors.available || '#4CAF50';
			var colorReserved = colors.reserved || '#FFC107';
			var colorBooked = colors.booked || '#F44336';
			var colorSelected = colors.selected || '#2196F3';

			var selectedId = data.currentSelection ? (data.currentSelection.seat_id || (data.currentSelection[0] && data.currentSelection[0].seat_id)) : '';

			// Container with color data attributes
			var html = '<div class="saso-seat-visual-container"' +
				' data-color-available="' + colorAvailable + '"' +
				' data-color-reserved="' + colorReserved + '"' +
				' data-color-booked="' + colorBooked + '"' +
				' data-color-selected="' + colorSelected + '">';

			// Button to open map
			var buttonText = __('Open Seat Map', 'event-tickets-with-ticket-scanner');
			if (selectedId && data.seats) {
				var selectedSeat = data.seats.find(function(s) { return String(s.id) === String(selectedId); });
				if (selectedSeat) {
					var seatLabel = (selectedSeat.meta && selectedSeat.meta.seat_label) || selectedSeat.seat_identifier;
					buttonText = __('Selected: {label} - Click to change', 'event-tickets-with-ticket-scanner').replace('{label}', seatLabel);
				}
			}
			html += '<button type="button" class="button saso-open-seat-map">' + this.escapeHtml(buttonText) + '</button>';
			html += '</div>';

			// Modal (hidden by default)
			html += '<div class="saso-seat-map-modal">';
			html += '<div class="saso-seat-map-header">';
			html += '<h3>' + this.escapeHtml(data.planName) + '</h3>';
			html += '<button type="button" class="saso-close-modal">&times;</button>';
			html += '</div>';

			html += '<div class="saso-seat-map-body">';
			html += this.buildSvgMap(data, selectedId, colorAvailable, colorReserved, colorBooked, colorSelected);
			html += '</div>';

			// Legend
			html += '<div class="saso-seat-map-legend">';
			html += '<span class="legend-item"><span class="legend-color free"></span> ' + __('Available', 'event-tickets-with-ticket-scanner') + '</span>';
			html += '<span class="legend-item"><span class="legend-color blocked"></span> ' + __('Reserved', 'event-tickets-with-ticket-scanner') + '</span>';
			html += '<span class="legend-item"><span class="legend-color sold"></span> ' + __('Sold', 'event-tickets-with-ticket-scanner') + '</span>';
			html += '<span class="legend-item"><span class="legend-color selected"></span> ' + __('Your Selection', 'event-tickets-with-ticket-scanner') + '</span>';
			html += '</div>';

			// Footer
			html += '<div class="saso-seat-map-footer">';
			html += '<div class="saso-seat-info">' + __('Click a seat to select it', 'event-tickets-with-ticket-scanner') + '</div>';
			html += '<div class="saso-seat-map-actions">';
			html += '<button type="button" class="button saso-cancel-selection">' + __('Cancel', 'event-tickets-with-ticket-scanner') + '</button>';
			html += '<button type="button" class="button button-primary saso-confirm-selection" disabled>' + __('Confirm Selection', 'event-tickets-with-ticket-scanner') + '</button>';
			html += '</div></div>';

			html += '</div>'; // .saso-seat-map-modal

			return html;
		},

		/**
		 * Build SVG seat map
		 *
		 * @param {Object} data Plan data
		 * @param {string} selectedId Currently selected seat ID
		 * @param {string} colorAvailable Color for available seats
		 * @param {string} colorReserved Color for reserved seats
		 * @param {string} colorBooked Color for sold seats
		 * @param {string} colorSelected Color for selected seats
		 * @returns {string} SVG HTML string
		 */
		buildSvgMap: function(data, selectedId, colorAvailable, colorReserved, colorBooked, colorSelected) {
			var self = this;
			var meta = data.meta || {};
			var width = meta.canvas_width || 800;
			var height = meta.canvas_height || 600;
			var bgColor = meta.background_color || '#ffffff';
			var bgImage = meta.background_image || '';

			var svg = '<svg class="saso-seat-map" viewBox="0 0 ' + width + ' ' + height + '" style="background-color: ' + bgColor + ';">';

			// Background image
			if (bgImage) {
				svg += '<image href="' + this.escapeHtml(bgImage) + '" x="0" y="0" width="' + width + '" height="' + height + '" preserveAspectRatio="xMidYMid meet" />';
			}

			// Decorations layer
			(meta.decorations || []).forEach(function(el) {
				svg += self.buildSvgElement(el);
			});

			// Lines layer
			(meta.lines || []).forEach(function(el) {
				svg += self.buildSvgElement(el);
			});

			// Labels layer
			(meta.labels || []).forEach(function(el) {
				svg += self.buildSvgElement(el);
			});

			// Seats layer
			data.seats.forEach(function(seat) {
				svg += self.buildSeatElement(seat, selectedId, colorAvailable, colorReserved, colorBooked, colorSelected);
			});

			svg += '</svg>';
			return svg;
		},

		/**
		 * Build SVG element (decoration, line, label)
		 *
		 * @param {Object} el Element data
		 * @returns {string} SVG element string
		 */
		buildSvgElement: function(el) {
			var type = el.type || 'rect';
			var x = parseFloat(el.x) || 0;
			var y = parseFloat(el.y) || 0;
			var fill = el.fill || 'transparent';
			var stroke = el.stroke || 'none';
			var strokeWidth = el.strokeWidth || 1;
			var fillOpacity = el.fillOpacity !== undefined ? (parseFloat(el.fillOpacity) / 100) : 1;
			var strokeOpacity = el.strokeOpacity !== undefined ? (parseFloat(el.strokeOpacity) / 100) : 0;

			var svg = '';

			switch (type) {
				case 'rect':
					var rw = parseFloat(el.width) || 50;
					var rh = parseFloat(el.height) || 50;
					var rx = el.rx || 0;
					svg = '<rect x="' + x + '" y="' + y + '" width="' + rw + '" height="' + rh + '" rx="' + rx + '" fill="' + fill + '" fill-opacity="' + fillOpacity + '" stroke="' + stroke + '" stroke-opacity="' + strokeOpacity + '" stroke-width="' + (strokeOpacity > 0 ? strokeWidth : 0) + '" />';
					if (el.label) {
						svg += this.buildLabelText(x + rw/2, y + rh/2, el.label, el);
					}
					break;

				case 'circle':
					var r = parseFloat(el.r) || 25;
					var cx = x + r;
					var cy = y + r;
					svg = '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="' + fill + '" fill-opacity="' + fillOpacity + '" stroke="' + stroke + '" stroke-opacity="' + strokeOpacity + '" stroke-width="' + (strokeOpacity > 0 ? strokeWidth : 0) + '" />';
					if (el.label) {
						svg += this.buildLabelText(cx, cy, el.label, el);
					}
					break;

				case 'ellipse':
					var erx = parseFloat(el.rx) || 50;
					var ery = parseFloat(el.ry) || 30;
					var ecx = parseFloat(el.cx) || (x + erx);
					var ecy = parseFloat(el.cy) || (y + ery);
					svg = '<ellipse cx="' + ecx + '" cy="' + ecy + '" rx="' + erx + '" ry="' + ery + '" fill="' + fill + '" fill-opacity="' + fillOpacity + '" stroke="' + stroke + '" stroke-opacity="' + strokeOpacity + '" stroke-width="' + (strokeOpacity > 0 ? strokeWidth : 0) + '" />';
					if (el.label) {
						svg += this.buildLabelText(ecx, ecy, el.label, el);
					}
					break;

				case 'line':
					var x1 = parseFloat(el.x1) || 0;
					var y1 = parseFloat(el.y1) || 0;
					var x2 = parseFloat(el.x2) || 100;
					var y2 = parseFloat(el.y2) || 100;
					var lineStrokeOpacity = el.strokeOpacity !== undefined ? (parseFloat(el.strokeOpacity) / 100) : 1;
					svg = '<line x1="' + x1 + '" y1="' + y1 + '" x2="' + x2 + '" y2="' + y2 + '" stroke="' + stroke + '" stroke-opacity="' + lineStrokeOpacity + '" stroke-width="' + strokeWidth + '" stroke-linecap="round" />';
					if (el.label) {
						svg += this.buildLabelText((x1+x2)/2, (y1+y2)/2, el.label, el);
					}
					break;

				case 'text':
					var fontSize = el.fontSize || 14;
					var fontFamily = el.fontFamily || 'sans-serif';
					var textAnchor = el.textAnchor || 'start';
					svg = '<text x="' + x + '" y="' + y + '" fill="' + fill + '" fill-opacity="' + fillOpacity + '" font-size="' + fontSize + '" font-family="' + fontFamily + '" text-anchor="' + textAnchor + '">' + this.escapeHtml(el.text || '') + '</text>';
					break;

				case 'image':
					var iw = el.width || 100;
					var ih = el.height || 100;
					svg = '<image href="' + this.escapeHtml(el.href || '') + '" x="' + x + '" y="' + y + '" width="' + iw + '" height="' + ih + '" opacity="' + fillOpacity + '" />';
					break;
			}

			return svg;
		},

		/**
		 * Build label text element
		 *
		 * @param {number} x Center X
		 * @param {number} y Center Y
		 * @param {string} text Label text
		 * @param {Object} el Original element for color settings
		 * @returns {string} SVG text element
		 */
		buildLabelText: function(x, y, text, el) {
			var fillColor = el.labelColor || '#333333';
			var strokeColor = el.labelStroke || '#ffffff';
			var fillOpacity = el.labelColorOpacity !== undefined ? (parseFloat(el.labelColorOpacity) / 100) : 1;
			var strokeOpacity = el.labelStrokeOpacity !== undefined ? (parseFloat(el.labelStrokeOpacity) / 100) : 0.5;

			return '<text x="' + x + '" y="' + y + '" text-anchor="middle" dominant-baseline="middle" fill="' + fillColor + '" fill-opacity="' + fillOpacity + '" stroke="' + strokeColor + '" stroke-opacity="' + strokeOpacity + '" stroke-width="2" paint-order="stroke" font-size="10" font-weight="bold" pointer-events="none" class="saso-element-label">' + this.escapeHtml(text) + '</text>';
		},

		/**
		 * Build seat SVG element
		 *
		 * @param {Object} seat Seat data
		 * @param {string} selectedId Currently selected seat ID
		 * @param {string} colorAvailable Available color
		 * @param {string} colorReserved Reserved color
		 * @param {string} colorBooked Booked color
		 * @param {string} colorSelected Selected color
		 * @returns {string} SVG elements for seat
		 */
		buildSeatElement: function(seat, selectedId, colorAvailable, colorReserved, colorBooked, colorSelected) {
			var meta = seat.meta || {};
			var posX = parseFloat(meta.pos_x) || 0;
			var posY = parseFloat(meta.pos_y) || 0;
			var shapeConfig = meta.shape_config || {width: 30, height: 30};
			var shapeType = meta.shape_type || 'rect';
			var seatWidth = parseFloat(shapeConfig.width) || 30;
			var seatHeight = parseFloat(shapeConfig.height) || 30;
			var seatLabel = meta.seat_label || seat.seat_identifier;

			var isAvailable = seat.availability === 'free';
			var isSelected = String(seat.id) === String(selectedId);
			var statusClass = isSelected ? 'selected' : (isAvailable ? 'free' : (seat.availability === 'sold' ? 'sold' : 'blocked'));

			var seatOwnColor = meta.color || colorAvailable;
			var fillColor;
			if (isSelected) {
				fillColor = colorSelected;
			} else if (isAvailable) {
				fillColor = seatOwnColor;
			} else if (seat.availability === 'sold') {
				fillColor = colorBooked;
			} else {
				fillColor = colorReserved;
			}

			var strokeColor = isSelected ? '#000' : 'transparent';
			var strokeWidth = isSelected ? '2' : '0';

			var dataAttrs = 'class="saso-seat ' + statusClass + '" data-seat-id="' + seat.id + '" data-seat-label="' + this.escapeHtml(seatLabel) + '" data-seat-category="' + this.escapeHtml(meta.seat_category || '') + '" data-seat-desc="' + this.escapeHtml(meta.seat_desc || '') + '" data-available="' + (isAvailable ? '1' : '0') + '" data-original-color="' + seatOwnColor + '"';

			var svg = '';
			var textX, textY;

			if (shapeType === 'circle') {
				var r = seatWidth / 2;
				var cx = posX + r;
				var cy = posY + r;
				textX = cx;
				textY = cy;
				var tooltipText = this.escapeHtml(seatLabel);
			if (sasoSeatingData.showSeatDescInChooser && meta.seat_desc) {
				tooltipText += '\n' + this.escapeHtml(meta.seat_desc);
			}
			svg = '<circle ' + dataAttrs + ' cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="' + fillColor + '" stroke="' + strokeColor + '" stroke-width="' + strokeWidth + '"><title>' + tooltipText + '</title></circle>';
			} else {
				textX = posX + seatWidth / 2;
				textY = posY + seatHeight / 2;
				var tooltipTextRect = this.escapeHtml(seatLabel);
			if (sasoSeatingData.showSeatDescInChooser && meta.seat_desc) {
				tooltipTextRect += '\n' + this.escapeHtml(meta.seat_desc);
			}
			svg = '<rect ' + dataAttrs + ' x="' + posX + '" y="' + posY + '" width="' + seatWidth + '" height="' + seatHeight + '" rx="3" ry="3" fill="' + fillColor + '" stroke="' + strokeColor + '" stroke-width="' + strokeWidth + '"><title>' + tooltipTextRect + '</title></rect>';
			}

			// Seat label text
			var fontSize = Math.min(14, Math.max(8, Math.min(seatWidth, seatHeight) / 3));
			svg += '<text class="saso-seat-label" x="' + textX + '" y="' + textY + '" text-anchor="middle" dominant-baseline="central" font-size="' + fontSize + '" fill="#fff" pointer-events="none">' + this.escapeHtml(seatLabel) + '</text>';

			return svg;
		},

		/**
		 * Bind all event handlers
		 */
		bindEvents: function() {
			var self = this;

			// Simple selector (dropdown)
			$(document).on('change', '.saso-seat-dropdown', function(e) {
				var $selector = $(this).closest('.saso-seating-selector');

				// Check if date is required but not selected
				var $wrapper = $selector.closest('.saso-seating-wrapper');
				if ($wrapper.data('requires-date') == '1') {
					var eventDate = self.getEventDate($selector);
					if (!eventDate) {
						$(this).val(''); // Reset dropdown
						self.setStatus($selector, __('Please select a date first', 'event-tickets-with-ticket-scanner'), 'error');
						return;
					}
				}

				self.onDropdownChange(e);
			});

			// Visual selector - Open modal
			$(document).on('click', '.saso-open-seat-map', function(e) {
				e.preventDefault();
				var $selector = $(this).closest('.saso-seating-selector');

				// Check if date is required but not selected
				var $wrapper = $selector.closest('.saso-seating-wrapper');
				if ($wrapper.data('requires-date') == '1') {
					var eventDate = self.getEventDate($selector);
					if (!eventDate) {
						self.setStatus($selector, __('Please select a date first', 'event-tickets-with-ticket-scanner'), 'error');
						return;
					}
				}

				self.openModal($selector);
			});

			// Ensure seat selection is submitted with form
			$(document).on('submit', 'form.cart', function(e) {
				var $form = $(this);
				var fieldName = sasoSeatingData.fieldName || 'sasoEventtickets_seat_selection';
				var $input = $form.find('input[name="' + fieldName + '"]');
				var inputValue = $input.length ? $input.val() : '';

				// If no input in form, check in selector and copy/create
				if (!$input.length) {
					var $selectorInput = $form.find('.saso-seat-selection-input');
					if ($selectorInput.length) {
						inputValue = $selectorInput.val();
					}

					// Create hidden input in form
					if (self.selectedSeats && self.selectedSeats.length > 0) {
						inputValue = JSON.stringify(self.selectedSeats);
					}

					if (inputValue) {
						$form.append('<input type="hidden" name="' + fieldName + '" value="' + inputValue.replace(/"/g, '&quot;') + '">');
					}
				} else if ((!inputValue || inputValue === '') && self.selectedSeats && self.selectedSeats.length > 0) {
					// Input exists but empty - fill it
					var jsonValue = JSON.stringify(self.selectedSeats);
					$input.val(jsonValue);
				}
			});

			// Visual selector - Close modal
			$(document).on('click', '.saso-close-modal, .saso-cancel-selection', function(e) {
				e.preventDefault();
				self.closeModal();
			});

			// Visual selector - Seat click
			$(document).on('click', '.saso-seat[data-available="1"]', function(e) {
				self.onSeatClick($(this));
			});

			// Visual selector - Confirm selection
			$(document).on('click', '.saso-confirm-selection', function(e) {
				e.preventDefault();
				self.confirmSelection();
			});

			// Close modal on overlay click
			$(document).on('click', '.saso-seat-map-overlay', function(e) {
				if ($(e.target).hasClass('saso-seat-map-overlay')) {
					self.closeModal();
				}
			});

			// Close modal on Escape key
			$(document).on('keydown', function(e) {
				if (e.key === 'Escape') {
					if (self.$currentModal) {
						self.closeModal();
					}
					// Also close plan image lightbox
					$('.saso-plan-image-lightbox').remove();
				}
			});

			// View plan image button
			$(document).on('click', '.saso-view-plan-image', function(e) {
				e.preventDefault();
				var imageUrl = $(this).data('image');
				if (imageUrl) {
					self.showPlanImage(imageUrl);
				}
			});

			// Close plan image lightbox on click
			$(document).on('click', '.saso-plan-image-lightbox', function(e) {
				if ($(e.target).hasClass('saso-plan-image-lightbox') || $(e.target).hasClass('saso-lightbox-close')) {
					$(this).remove();
				}
			});

			// Update seat availability when event date changes
			// Note: Daychooser input has name="event_date_PRODUCT_ID", so we use starts-with selector
			$(document).on('change', '[data-input-type="daychooser"], [name^="event_date"], .hasDatepicker', function() {
				self.onEventDateChange($(this));
			});
		},

		/**
		 * Initialize any existing selections (e.g., from cart or session blocks)
		 */
		initializeExistingSelections: function() {
			var self = this;

			$('.saso-seating-selector').each(function() {
				var $selector = $(this);
				var $input = $selector.find('.saso-seat-selection-input');
				var existingValue = $input.val();
				var planData = $selector.data('plan-data');

				// First, check hidden input (cart/form data)
				if (existingValue) {
					try {
						var data = JSON.parse(existingValue);
						var seats = [];

						// Support both single seat (legacy) and array of seats
						if (Array.isArray(data)) {
							seats = data;
						} else if (data && data.seat_id) {
							// Legacy single seat format
							seats = [data];
						}

						// Filter out expired seats (use countdown_end if available)
						var validSeats = seats.filter(function(seat) {
							if (!seat.countdown_end) {
								return true; // No countdown info, keep it
							}
							var remaining = self.getTimeRemaining(seat.countdown_end);
							return remaining > 0;
						});

						// Update block IDs for valid seats only
						validSeats.forEach(function(seat) {
							if (seat.block_id) {
								self.currentBlockIds[seat.seat_id] = seat.block_id;
							}
						});

						self.selectedSeats = validSeats;

						// Update hidden input if some seats were removed (expired)
						if (validSeats.length !== seats.length) {
							$input.val(validSeats.length > 0 ? JSON.stringify(validSeats) : '');
						}

						self.updateButtonText($selector, self.selectedSeats);
						return; // Done with this selector
					} catch (e) {
						// Invalid JSON, continue to check existingBlocks
					}
				}

				// If no cart selection, check for existing session blocks (user blocked but didn't add to cart yet)
				if (planData && planData.existingBlocks && planData.existingBlocks.length > 0) {
					var restoredSeats = [];

					planData.existingBlocks.forEach(function(block) {
						// Calculate countdown_end from remaining_seconds (same pattern as AJAX response)
						var remainingSeconds = block.remaining_seconds || 0;
						if (remainingSeconds <= 0) {
							return; // Skip expired blocks
						}
						var countdownEnd = Date.now() + (remainingSeconds * 1000);

						var seatData = {
							seat_id: block.seat_id,
							seat_label: block.seat_label || '',
							seat_category: block.seat_category || '',
							seat_desc: block.seat_desc || '',
							block_id: block.block_id,
							expires_at: block.expires_at,
							countdown_end: countdownEnd
						};

						// Track block ID
						self.currentBlockIds[block.seat_id] = block.block_id;
						restoredSeats.push(seatData);
					});

					if (restoredSeats.length > 0) {
						self.selectedSeats = restoredSeats;
						// Update hidden input with restored selection
						$input.val(JSON.stringify(restoredSeats));
						self.updateButtonText($selector, restoredSeats);
					}
				}
			});
		},

		/**
		 * Handle dropdown change (simple layout)
		 *
		 * @param {Event} e Change event
		 */
		onDropdownChange: function(e) {
			var self = this;
			var $dropdown = $(e.target);
			var $selector = $dropdown.closest('.saso-seating-selector');
			var seatId = $dropdown.val();

			if (!seatId) {
				// Clear selection
				this.clearSelection($selector);
				return;
			}

			var $option = $dropdown.find('option:selected');
			var seatData = {
				seat_id: parseInt(seatId),
				seat_label: $option.data('seat-label') || $option.text(),
				seat_category: $option.data('seat-category') || '',
				seat_desc: $option.data('seat-desc') || ''
			};

			this.selectSeat($selector, seatData);
		},

		/**
		 * Open the visual seat map modal
		 *
		 * @param {jQuery} $selector The selector container
		 */
		openModal: function($selector) {
			var self = this;
			this.$currentSelector = $selector;
			this.$currentModal = $selector.find('.saso-seat-map-modal');

			if (!this.$currentModal.length) {
				return;
			}

			// Load plan colors from data attributes
			var $visualContainer = $selector.find('.saso-seat-visual-container');
			this.planColors = {
				available: $visualContainer.data('color-available') || '#4CAF50',
				reserved: $visualContainer.data('color-reserved') || '#FFC107',
				booked: $visualContainer.data('color-booked') || '#F44336',
				selected: $visualContainer.data('color-selected') || '#2196F3'
			};

			// Get quantity from form (default 1)
			var $form = $selector.closest('form');
			var $qtyInput = $form.find('input[name="quantity"]');
			this.maxSeats = $qtyInput.length ? parseInt($qtyInput.val()) || 1 : 1;

			// Restore previous selections as temp selections
			this.tempSelections = this.selectedSeats.slice();

			// Create overlay if not exists
			if (!$('.saso-seat-map-overlay').length) {
				$('body').append('<div class="saso-seat-map-overlay"></div>');
			}

			// Show modal and overlay
			$('.saso-seat-map-overlay').addClass('active');
			this.$currentModal.addClass('active');

			// Disable body scroll
			$('body').css('overflow', 'hidden');

			// Restore visual selection state for previously selected seats
			this.restoreVisualSelections();

			// Update confirm button state
			var canConfirm = this.tempSelections.length === this.maxSeats;
			this.$currentModal.find('.saso-confirm-selection').prop('disabled', !canConfirm);

			// Update seat info text with counter
			this.updateModalSeatInfo();

			// Focus trap for accessibility
			this.$currentModal.find('.saso-close-modal').focus();

			// Refresh availability immediately when opening
			this.refreshAvailability($selector);

			// Start auto-refresh interval (every 30 seconds)
			this.startAutoRefresh($selector);
		},

		/**
		 * Restore visual selection state for temp selections
		 */
		restoreVisualSelections: function() {
			var self = this;

			if (!this.$currentModal) {
				return;
			}

			// Clear all temp-selected first and reset colors
			this.$currentModal.find('.saso-seat.temp-selected').each(function() {
				var $el = $(this);
				$el.removeClass('temp-selected');
				var origColor = $el.data('original-color') || self.planColors.available;
				$el.attr('fill', origColor);
			});

			// Mark each selected seat and update its color
			this.tempSelections.forEach(function(seat) {
				var seatId = String(seat.seat_id); // Ensure string for attribute selector
				var $seat = self.$currentModal.find('.saso-seat[data-seat-id="' + seatId + '"]');
				if ($seat.length) {
					$seat.addClass('temp-selected');
					$seat.attr('fill', self.planColors.selected);
				}
			});
		},

		/**
		 * Close the modal
		 */
		closeModal: function() {
			if (!this.$currentModal) {
				return;
			}

			// Stop auto-refresh
			this.stopAutoRefresh();

			// Hide modal and overlay
			this.$currentModal.removeClass('active');
			$('.saso-seat-map-overlay').removeClass('active');

			// Re-enable body scroll
			$('body').css('overflow', '');

			// Clear temp selection visual
			this.$currentModal.find('.saso-seat').removeClass('temp-selected');

			// Reset references
			this.tempSelections = [];
			this.$currentModal = null;
		},

		/**
		 * Handle seat click in modal (toggle selection)
		 *
		 * @param {jQuery} $seat The clicked seat element
		 */
		onSeatClick: function($seat) {
			if (!this.$currentModal) {
				return;
			}

			var seatId = parseInt($seat.data('seat-id'));
			var seatData = {
				seat_id: seatId,
				seat_label: $seat.data('seat-label'),
				seat_category: $seat.data('seat-category') || '',
				seat_desc: $seat.data('seat-desc') || ''
			};

			// Check if seat is already selected (toggle behavior)
			var existingIndex = this.findSeatIndex(this.tempSelections, seatId);

			if (existingIndex !== -1) {
				// Seat already selected - check if deselection is allowed
				var lockSelectedSeats = typeof sasoSeatingData !== 'undefined' && sasoSeatingData.lockSelectedSeats;
				if (lockSelectedSeats) {
					// Deselection disabled - show message
					this.showTempMessage(__('Seat cannot be deselected', 'event-tickets-with-ticket-scanner'));
					return;
				}
				// Deselect: remove from array and restore original color
				this.tempSelections.splice(existingIndex, 1);
				$seat.removeClass('temp-selected');
				// Restore original color
				var originalColor = $seat.data('original-color') || this.planColors.available;
				$seat.attr('fill', originalColor);
			} else {
				// Check if we've reached max seats
				if (this.tempSelections.length >= this.maxSeats) {
					// At max capacity - replace oldest selection (FIFO)
					var oldSeatId = this.tempSelections[0].seat_id;
					var $oldSeat = this.$currentModal.find('[data-seat-id="' + oldSeatId + '"]');
					$oldSeat.removeClass('temp-selected');
					// Restore old seat's original color
					var oldOriginalColor = $oldSeat.data('original-color') || this.planColors.available;
					$oldSeat.attr('fill', oldOriginalColor);
					// Remove oldest from array
					this.tempSelections.shift();
				}

				// Add to selections and change to selected color
				this.tempSelections.push(seatData);
				$seat.addClass('temp-selected');
				$seat.attr('fill', this.planColors.selected);
			}

			// Enable confirm button only when correct number of seats selected
			var canConfirm = this.tempSelections.length === this.maxSeats;
			this.$currentModal.find('.saso-confirm-selection').prop('disabled', !canConfirm);

			// Update seat info display
			this.updateModalSeatInfo();
		},

		/**
		 * Find seat index in array by seat_id
		 *
		 * @param {Array} seats Array of seat objects
		 * @param {number|string} seatId Seat ID to find
		 * @returns {number} Index or -1 if not found
		 */
		findSeatIndex: function(seats, seatId) {
			var searchId = parseInt(seatId, 10);
			for (var i = 0; i < seats.length; i++) {
				if (parseInt(seats[i].seat_id, 10) === searchId) {
					return i;
				}
			}
			return -1;
		},

		/**
		 * Show temporary message in modal
		 *
		 * @param {string} message Message to show
		 */
		showTempMessage: function(message) {
			var $info = this.$currentModal.find('.saso-seat-info');
			var originalHtml = $info.html();

			$info.html('<span class="saso-temp-warning">' + this.escapeHtml(message) + '</span>');

			setTimeout(function() {
				// Restore original content (will be updated by next interaction anyway)
			}, 2000);
		},

		/**
		 * Update the modal seat info display
		 *
		 * Shows counter (X/Y) and selected seat labels
		 */
		updateModalSeatInfo: function() {
			if (!this.$currentModal) {
				return;
			}

			var $info = this.$currentModal.find('.saso-seat-info');

			if (!$info.length) {
				// Create info element if not exists
				this.$currentModal.find('.saso-seat-map-footer').prepend(
					'<div class="saso-seat-info"></div>'
				);
				$info = this.$currentModal.find('.saso-seat-info');
			}

			var selectedCount = this.tempSelections.length;
			var maxSeats = this.maxSeats;

			// Build counter text
			var counterText = '<span class="saso-seat-counter">' +
				__('Selected', 'event-tickets-with-ticket-scanner') + ': ' +
				'<strong>' + selectedCount + '/' + maxSeats + '</strong>' +
				'</span>';

			if (selectedCount === 0) {
				// No selections yet
				var instructionText = maxSeats > 1
					? __('Select {count} seats', 'event-tickets-with-ticket-scanner').replace('{count}', maxSeats)
					: __('Select a seat', 'event-tickets-with-ticket-scanner');
				$info.html(counterText + ' - ' + instructionText);
			} else {
				// Show selected seat labels
				var labels = this.tempSelections.map(function(seat) {
					var label = seat.seat_label;
					if (seat.seat_category) {
						label += ' (' + seat.seat_category + ')';
					}
					return label;
				});
				$info.html(counterText + ' - ' + this.escapeHtml(labels.join(', ')));
			}
		},

		/**
		 * Confirm the modal selection
		 */
		confirmSelection: function() {
			if (!this.tempSelections.length || !this.$currentSelector) {
				return;
			}

			// IMPORTANT: Immediately update hidden input and button BEFORE AJAX
			// This ensures form has seat data even if user submits before AJAX completes
			var seatsToSave = this.tempSelections.slice();
			this.selectedSeats = seatsToSave;
			this.updateSelection(this.$currentSelector, seatsToSave);
			this.updateButtonText(this.$currentSelector, seatsToSave);

			// Check if we should block now or wait for add-to-cart
			var blockOnAddToCart = typeof sasoSeatingData !== 'undefined' && sasoSeatingData.blockOnAddToCart;
			if (blockOnAddToCart) {
				// Don't block now - will be blocked during add-to-cart
				// Just show a message that reservation is pending
				this.setStatus(this.$currentSelector, __('Seat will be reserved when adding to cart', 'event-tickets-with-ticket-scanner'), 'info');
				setTimeout(function() {
					var self = this;
					// Don't clear status - keep info visible
				}.bind(this), 3000);
			} else {
				// Block all selected seats via AJAX (will update with block_ids when done)
				this.blockSeatsInBackground(this.$currentSelector, seatsToSave);
			}
			this.closeModal();
		},

		/**
		 * Block seats in background via AJAX (doesn't clear selectedSeats)
		 *
		 * @param {jQuery} $selector The selector container
		 * @param {Array} seatsData Array of seat data objects
		 */
		blockSeatsInBackground: function($selector, seatsData) {
			var self = this;
			var productId = $selector.data('product-id');
			var eventDate = this.getEventDate($selector);

			// Show loading state
			$selector.addClass('loading');
			this.setStatus($selector, __('Loading...', 'event-tickets-with-ticket-scanner'), 'loading');

			// Release all previous seat blocks (but don't clear selectedSeats/hidden input)
			var releasePromises = [];
			Object.keys(this.currentBlockIds).forEach(function(seatId) {
				releasePromises.push(self.releaseSeat(self.currentBlockIds[seatId]));
			});

			$.when.apply($, releasePromises).always(function() {
				self.currentBlockIds = {};

				// Block all new seats sequentially
				var blockedSeats = [];
				var errors = [];

				function blockNext(index) {
					if (index >= seatsData.length) {
						// All done - update UI
						$selector.removeClass('loading');

						if (errors.length > 0) {
							self.setStatus($selector, errors[0], 'error');
							// Clear selection on error
							self.selectedSeats = [];
							self.updateSelection($selector, []);
							self.updateButtonText($selector, []);
							self.refreshAvailability($selector);
						} else if (blockedSeats.length > 0) {
							// Update with block_ids
							self.selectedSeats = blockedSeats;
							self.updateSelection($selector, blockedSeats);
							self.updateButtonText($selector, blockedSeats);

							var msg = blockedSeats.length > 1
								? __('%d seats selected', 'event-tickets-with-ticket-scanner').replace('%d', blockedSeats.length)
								: __('Seat selected', 'event-tickets-with-ticket-scanner');
							self.setStatus($selector, msg, 'success');

							setTimeout(function() {
								self.setStatus($selector, '', '');
							}, 3000);
						}
						return;
					}

					var seatData = seatsData[index];
					self.blockSeat(seatData.seat_id, productId, eventDate, function(response) {
						if (response.success) {
							// Calculate countdown_end from remaining_seconds (avoids timezone issues)
							var remainingSeconds = response.data.remaining_seconds || 0;
							var countdownEnd = Date.now() + (remainingSeconds * 1000);

							// Copy seat data and add block info
							var blockedSeat = {
								seat_id: seatData.seat_id,
								seat_label: seatData.seat_label,
								seat_category: seatData.seat_category,
								seat_desc: seatData.seat_desc || '',
								block_id: response.data.block_id,
								expires_at: response.data.expires_at,
								countdown_end: countdownEnd
							};
							self.currentBlockIds[seatData.seat_id] = response.data.block_id;
							blockedSeats.push(blockedSeat);
						} else {
							var errorMsg = response.data && response.data.error === 'seat_unavailable'
								? __('This seat is no longer available', 'event-tickets-with-ticket-scanner')
								: __('Error blocking seat', 'event-tickets-with-ticket-scanner');
							errors.push(errorMsg + ' (' + seatData.seat_label + ')');
						}
						blockNext(index + 1);
					});
				}

				blockNext(0);
			});
		},

		/**
		 * Select multiple seats (block via AJAX)
		 *
		 * @param {jQuery} $selector The selector container
		 * @param {Array} seatsData Array of seat data objects
		 */
		selectSeats: function($selector, seatsData) {
			var self = this;
			var productId = $selector.data('product-id');
			var eventDate = this.getEventDate($selector);

			// Show loading state
			$selector.addClass('loading');
			this.setStatus($selector, __('Loading...', 'event-tickets-with-ticket-scanner'), 'loading');

			// Release all previous seats
			var releasePromises = [];
			Object.keys(this.currentBlockIds).forEach(function(seatId) {
				releasePromises.push(self.releaseSeat(self.currentBlockIds[seatId]));
			});

			$.when.apply($, releasePromises).always(function() {
				self.currentBlockIds = {};
				self.selectedSeats = [];

				// Block all new seats sequentially to avoid race conditions
				var blockedSeats = [];
				var errors = [];

				function blockNext(index) {
					if (index >= seatsData.length) {
						// All done - update UI
						$selector.removeClass('loading');

						if (errors.length > 0) {
							self.setStatus($selector, errors[0], 'error');
							self.refreshAvailability($selector);
						} else if (blockedSeats.length > 0) {
							self.selectedSeats = blockedSeats;
							self.updateSelection($selector, blockedSeats);
							self.updateButtonText($selector, blockedSeats);

							var msg = blockedSeats.length > 1
								? __('%d seats selected', 'event-tickets-with-ticket-scanner').replace('%d', blockedSeats.length)
								: __('Seat selected', 'event-tickets-with-ticket-scanner');
							self.setStatus($selector, msg, 'success');

							setTimeout(function() {
								self.setStatus($selector, '', '');
							}, 3000);
						}
						return;
					}

					var seatData = seatsData[index];
					self.blockSeat(seatData.seat_id, productId, eventDate, function(response) {
						if (response.success) {
							// Calculate countdown_end from remaining_seconds (avoids timezone issues)
							var remainingSeconds = response.data.remaining_seconds || 0;
							seatData.block_id = response.data.block_id;
							seatData.expires_at = response.data.expires_at;
							seatData.countdown_end = Date.now() + (remainingSeconds * 1000);
							self.currentBlockIds[seatData.seat_id] = response.data.block_id;
							blockedSeats.push(seatData);
						} else {
							var errorMsg = response.data && response.data.error === 'seat_unavailable'
								? __('This seat is no longer available', 'event-tickets-with-ticket-scanner')
								: __('Error blocking seat', 'event-tickets-with-ticket-scanner');
							errors.push(errorMsg + ' (' + seatData.seat_label + ')');
						}
						blockNext(index + 1);
					});
				}

				blockNext(0);
			});
		},

		/**
		 * Select a single seat (convenience wrapper)
		 *
		 * @param {jQuery} $selector The selector container
		 * @param {Object} seatData Seat data
		 */
		selectSeat: function($selector, seatData) {
			this.selectSeats($selector, [seatData]);
		},

		/**
		 * Clear the current selection
		 *
		 * @param {jQuery} $selector The selector container
		 */
		clearSelection: function($selector) {
			var self = this;

			// Release all blocked seats
			Object.keys(this.currentBlockIds).forEach(function(seatId) {
				self.releaseSeat(self.currentBlockIds[seatId]);
			});

			this.currentBlockIds = {};
			this.selectedSeats = [];
			this.updateSelection($selector, []);
			this.updateButtonText($selector, []);
		},

		/**
		 * Block a seat via AJAX
		 *
		 * @param {number} seatId Seat ID
		 * @param {number} productId Product ID
		 * @param {string|null} eventDate Event date
		 * @param {Function} callback Callback function
		 */
		blockSeat: function(seatId, productId, eventDate, callback) {
			$.ajax({
				url: sasoSeatingData.ajaxurl,
				type: 'POST',
				data: {
					action: sasoSeatingData.action, // sasoEventtickets_executeSeatingFrontend
					a: 'blockSeat',
					security: sasoSeatingData.nonce,
					seat_id: seatId,
					product_id: productId,
					event_date: eventDate || ''
				},
				success: function(response) {
					callback(response);
				},
				error: function() {
					callback({ success: false, data: { error: 'ajax_error' } });
				}
			});
		},

		/**
		 * Release a seat via AJAX
		 *
		 * @param {number} blockId Block ID
		 * @returns {jQuery.Promise}
		 */
		releaseSeat: function(blockId) {
			return $.ajax({
				url: sasoSeatingData.ajaxurl,
				type: 'POST',
				data: {
					action: sasoSeatingData.action, // sasoEventtickets_executeSeatingFrontend
					a: 'releaseSeat',
					security: sasoSeatingData.nonce,
					block_id: blockId
				}
			});
		},

		/**
		 * Refresh seat availability
		 *
		 * @param {jQuery} $selector The selector container
		 */
		refreshAvailability: function($selector) {
			var self = this;
			var productId = $selector.data('product-id');
			var eventDate = this.getEventDate($selector);

			$.ajax({
				url: sasoSeatingData.ajaxurl,
				type: 'POST',
				data: {
					action: sasoSeatingData.action, // sasoEventtickets_executeSeatingFrontend
					a: 'getAvailableSeats',
					security: sasoSeatingData.nonce,
					product_id: productId,
					event_date: eventDate || ''
				},
				success: function(response) {
					if (response.success) {
						self.updateSeatAvailability($selector, response.data.seats);
					}
				},
				error: function(xhr, status, error) {
					// AJAX error - silently handle
				}
			});
		},

		/**
		 * Start auto-refresh interval while modal is open
		 *
		 * @param {jQuery} $selector The selector container
		 */
		startAutoRefresh: function($selector) {
			var self = this;

			// Clear any existing interval first
			this.stopAutoRefresh();

			// Start new interval
			this.refreshIntervalId = setInterval(function() {
				// Only refresh if modal is still open
				if (self.$currentModal && self.$currentModal.hasClass('active')) {
					self.refreshAvailability($selector);
				} else {
					// Modal was closed, stop interval
					self.stopAutoRefresh();
				}
			}, this.REFRESH_INTERVAL);
		},

		/**
		 * Stop auto-refresh interval
		 */
		stopAutoRefresh: function() {
			if (this.refreshIntervalId) {
				clearInterval(this.refreshIntervalId);
				this.refreshIntervalId = null;
			}
		},

		/**
		 * Update seat availability in UI
		 *
		 * @param {jQuery} $selector The selector container
		 * @param {Array} seats Seats with status
		 */
		updateSeatAvailability: function($selector, seats) {
			var self = this;
			var layout = $selector.data('layout');

			if (layout === 'simple') {
				// Update dropdown
				var $dropdown = $selector.find('.saso-seat-dropdown');
				seats.forEach(function(seat) {
					var $option = $dropdown.find('option[value="' + seat.id + '"]');
					var isAvailable = seat.availability === 'free';
					$option.prop('disabled', !isAvailable);
				});
			} else {
				// Update SVG
				var $svg = $selector.find('.saso-seat-map');
				seats.forEach(function(seat) {
					var $seatEl = $svg.find('[data-seat-id="' + seat.id + '"]');
					var isAvailable = seat.availability === 'free';
					var isSelected = self.findSeatIndex(self.tempSelections, seat.id) !== -1;

					$seatEl
						.attr('data-available', isAvailable ? '1' : '0')
						.removeClass('free blocked sold')
						.addClass(seat.availability);

					// Update fill color based on status (but keep selected color if selected)
					if (isSelected) {
						$seatEl.attr('fill', self.planColors.selected);
					} else if (isAvailable) {
						// Restore original color for available seats
						var originalColor = $seatEl.data('original-color') || self.planColors.available;
						$seatEl.attr('fill', originalColor);
					} else if (seat.availability === 'sold') {
						$seatEl.attr('fill', self.planColors.booked);
					} else {
						$seatEl.attr('fill', self.planColors.reserved);
					}
				});
			}
		},

		/**
		 * Handle event date change
		 *
		 * @param {jQuery} $input The date input
		 */
		onEventDateChange: function($input) {
			var self = this;
			var dateValue = $input.val();

			// Find matching seating selector by product ID
			var productId = $input.data('product-id');
			var $selector = null;

			if (productId) {
				// Match by product ID (works on shop pages with multiple products)
				$selector = $('.saso-seating-selector[data-product-id="' + productId + '"]');
			} else {
				// Fallback: find within same form
				var $form = $input.closest('form');
				$selector = $form.find('.saso-seating-selector');
			}

			if ($selector.length && dateValue) {
				// Update the event date in selector (use .data() for jQuery cache consistency)
				$selector.data('event-date', dateValue);
				$selector.attr('data-event-date', dateValue);

				// Check if we need to rebuild the UI (was showing "please select date" message)
				var $dateRequired = $selector.find('.saso-seating-date-required');
				if ($dateRequired.length) {
					// Remove old content except hidden input
					$selector.children().not('.saso-seat-selection-input').remove();

					// Rebuild UI with the stored plan data
					var data = $selector.data('plan-data');
					if (data) {
						this.buildSelectorUI($selector, data);
					}
				}

				// Clear current selection (availability may have changed)
				this.clearSelection($selector);

				// Refresh availability for the new date
				this.refreshAvailability($selector);
			}
		},

		/**
		 * Get event date from form
		 *
		 * @param {jQuery} $selector The selector container
		 * @returns {string|null}
		 */
		getEventDate: function($selector) {
			var $form = $selector.closest('form');
			var $dateInput = $form.find('[data-input-type="daychooser"], [name^="event_date"], .hasDatepicker').first();

			return $dateInput.length ? $dateInput.val() : $selector.data('event-date') || null;
		},

		/**
		 * Update the hidden input with selection data
		 *
		 * @param {jQuery} $selector The selector container
		 * @param {Array} seatsData Array of seat data objects
		 */
		updateSelection: function($selector, seatsData) {
			var $input = $selector.find('.saso-seat-selection-input');
			var jsonValue = seatsData && seatsData.length ? JSON.stringify(seatsData) : '';
			$input.val(jsonValue);
		},

		/**
		 * Update the "Open Seat Map" button text and seat labels display with countdown
		 *
		 * @param {jQuery} $selector The selector container
		 * @param {Array} seatsData Array of seat data objects
		 */
		updateButtonText: function($selector, seatsData) {
			var self = this;
			var $button = $selector.find('.saso-open-seat-map');
			var $labelsContainer = $selector.find('.saso-selected-seats-labels');

			// Create labels container if it doesn't exist
			if (!$labelsContainer.length && $button.length) {
				$button.after('<div class="saso-selected-seats-labels"></div>');
				$labelsContainer = $selector.find('.saso-selected-seats-labels');
			}

			if (!$button.length) {
				return;
			}

			// Stop previous countdown timer
			this.stopCountdownTimer();

			if (seatsData && seatsData.length > 0) {
				// Show count in button (cleaner for many seats)
				var count = seatsData.length;
				var text = count === 1
					? __('1 seat selected', 'event-tickets-with-ticket-scanner')
					: __('%d seats selected', 'event-tickets-with-ticket-scanner').replace('%d', count);

				$button
					.addClass('has-selection')
					.html(text + ' - ' + __('Click to change', 'event-tickets-with-ticket-scanner'));

				// Sort seats for display
				var sortedSeats = seatsData.slice().sort(function(a, b) {
					var labelA = a.seat_label || ('Seat ' + a.seat_id);
					var labelB = b.seat_label || ('Seat ' + b.seat_id);
					return labelA.localeCompare(labelB, undefined, {numeric: true, sensitivity: 'base'});
				});

				// Check if expiration time should be hidden (option)
				var hideExpiration = typeof sasoSeatingData !== 'undefined' && sasoSeatingData.hideExpirationTime;

				// Build HTML list with countdown per seat
				var html = '<ul class="saso-seat-list">';
				sortedSeats.forEach(function(seat) {
					var label = seat.seat_label || ('Seat ' + seat.seat_id);
					if (seat.seat_category) {
						label += ' (' + seat.seat_category + ')';
					}
					// Use countdown_end (client timestamp) for countdown - avoids timezone issues
					var countdownEnd = hideExpiration ? 0 : (seat.countdown_end || 0);
					var countdownAttr = countdownEnd ? ' data-countdown-end="' + countdownEnd + '"' : '';

					html += '<li class="saso-seat-item" data-seat-id="' + seat.seat_id + '"' + countdownAttr + '>';
					html += '<span class="saso-seat-name">' + self.escapeHtml(label) + '</span>';
					// Only show countdown if not hidden by option
					if (countdownEnd) {
						html += '<span class="saso-seat-countdown" data-countdown-end="' + countdownEnd + '"></span>';
					}
					html += '</li>';
				});
				html += '</ul>';
				$labelsContainer.html(html).show();

				// Start countdown timer (only if not hidden)
				if (!hideExpiration) {
					this.startCountdownTimer($labelsContainer);
				}
			} else {
				$button
					.removeClass('has-selection')
					.html(__('Open Seat Map', 'event-tickets-with-ticket-scanner'));
				$labelsContainer.empty().hide();
			}
		},

		/**
		 * Start countdown timer for seat reservations
		 *
		 * @param {jQuery} $container Container with countdown elements
		 */
		startCountdownTimer: function($container) {
			var self = this;

			// Stop any existing timer on this container
			this.stopCountdownTimer($container);

			// Update immediately
			this.updateCountdowns($container);

			// Update every second - store timer ID on container
			var timerId = setInterval(function() {
				self.updateCountdowns($container);
			}, 1000);
			$container.data('countdown-timer', timerId);
		},

		/**
		 * Stop countdown timer for a specific container
		 *
		 * @param {jQuery} $container Container to stop timer for (optional - stops global if not provided)
		 */
		stopCountdownTimer: function($container) {
			if ($container && $container.length) {
				var timerId = $container.data('countdown-timer');
				if (timerId) {
					clearInterval(timerId);
					$container.removeData('countdown-timer');
				}
			} else if (this.countdownIntervalId) {
				// Legacy: stop global timer
				clearInterval(this.countdownIntervalId);
				this.countdownIntervalId = null;
			}
		},

		/**
		 * Update all countdown displays
		 *
		 * @param {jQuery} $container Container with countdown elements
		 */
		updateCountdowns: function($container) {
			var self = this;
			var totalCountdowns = 0;
			var expiredCountdowns = 0;

			$container.find('.saso-seat-countdown').each(function() {
				var $countdown = $(this);
				var countdownEnd = $countdown.data('countdown-end');

				if (!countdownEnd) {
					return;
				}

				totalCountdowns++;
				var remaining = self.getTimeRemaining(countdownEnd);

				if (remaining <= 0) {
					$countdown.html('<span class="expired-text">' + __('Expired!', 'event-tickets-with-ticket-scanner') + '</span>');
					$countdown.addClass('expired');
					$countdown.closest('.saso-seat-item').addClass('expired');
					expiredCountdowns++;
				} else {
					$countdown.html(self.formatCountdown(remaining));
					// Add warning class if less than 1 minute
					if (remaining < 60) {
						$countdown.addClass('warning');
					} else {
						$countdown.removeClass('warning');
					}
				}
			});

			// If ALL countdowns expired, stop timer
			if (totalCountdowns > 0 && expiredCountdowns === totalCountdowns) {
				this.stopCountdownTimer($container);
			}
		},

		/**
		 * Get time remaining in seconds
		 * Uses countdown_end (client timestamp) calculated from server's remaining_seconds
		 *
		 * @param {number} countdownEnd Client timestamp when countdown expires (Date.now() based)
		 * @return {number} Seconds remaining (negative if expired)
		 */
		getTimeRemaining: function(countdownEnd) {
			if (!countdownEnd || typeof countdownEnd !== 'number') {
				return 0;
			}

			return Math.floor((countdownEnd - Date.now()) / 1000);
		},

		/**
		 * Format remaining seconds as MM:SS
		 *
		 * @param {number} seconds Seconds remaining
		 * @return {string} Formatted time
		 */
		formatCountdown: function(seconds) {
			var minutes = Math.floor(seconds / 60);
			var secs = seconds % 60;
			return minutes + ':' + (secs < 10 ? '0' : '') + secs;
		},

		/**
		 * Set status message
		 *
		 * @param {jQuery} $selector The selector container
		 * @param {string} message Status message
		 * @param {string} type Status type (success, error, loading)
		 */
		setStatus: function($selector, message, type) {
			var $status = $selector.find('.saso-seating-status');
			$status
				.text(message)
				.removeClass('success error loading')
				.addClass(type);
		},

		/**
		 * Show plan image in a lightbox
		 *
		 * @param {string} imageUrl URL of the plan image
		 */
		showPlanImage: function(imageUrl) {
			// Remove any existing lightbox
			$('.saso-plan-image-lightbox').remove();

			// Create lightbox
			var $lightbox = $('<div class="saso-plan-image-lightbox">' +
				'<button type="button" class="saso-lightbox-close">&times;</button>' +
				'<img src="' + imageUrl + '" alt="' + __('Venue Plan', 'event-tickets-with-ticket-scanner') + '">' +
				'</div>');

			$('body').append($lightbox);
		},

		/**
		 * Escape HTML entities
		 *
		 * @param {string} text Text to escape
		 * @returns {string}
		 */
		escapeHtml: function(text) {
			if (!text) return '';
			var div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}
	};

	/**
	 * Initialize on document ready
	 */
	$(document).ready(function() {
		if (typeof sasoSeatingData !== 'undefined') {
			SasoSeatingFrontend.init();
		}
	});

	/**
	 * Re-initialize countdowns after WooCommerce AJAX updates (e.g., coupon applied)
	 */
	$(document.body).on('updated_cart_totals updated_wc_div wc_fragments_refreshed', function() {
		if (typeof sasoSeatingData !== 'undefined') {
			SasoSeatingFrontend.initCartCountdowns();
		}
	});

	// Expose for external access if needed
	window.SasoSeatingFrontend = SasoSeatingFrontend;

})(jQuery);
