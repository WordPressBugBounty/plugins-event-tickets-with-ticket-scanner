/**
 * Seating Plan Visual Designer
 *
 * SVG-based visual editor for seating plans.
 * Handles shapes, lines, labels, and seats with drag & drop.
 *
 * @package Event_Tickets_With_Ticket_Scanner
 * @since 2.8.0
 */

(function($) {
	'use strict';

	// Designer instance
	window.SasoSeatingDesigner = null;

	/**
	 * Seating Designer Class
	 *
	 * @param {Object} config Configuration object
	 */
	function SeatingDesigner(config) {
		this.config = $.extend({
			container: '#saso-designer-container',
			planId: 0,
			canvasWidth: 800,
			canvasHeight: 600,
			backgroundColor: '#ffffff',
			backgroundImage: '',
			gridSize: 10,
			snapToGrid: true,
			colors: {
				available: '#4CAF50',
				reserved: '#FFC107',
				booked: '#F44336',
				selected: '#2196F3'
			},
			ajaxUrl: '',
			ajaxAction: '',
			nonce: '',
			i18n: {}
		}, config);

		// State
		this.svg = null;
		this.layers = {};
		this.elements = {
			seats: [],
			decorations: [],
			lines: [],
			labels: []
		};
		this.selectedElement = null;
		this.selectedElements = []; // Array for multi-select
		this.currentTool = 'select';
		this.isDragging = false;
		this.justFinishedDragging = false; // Flag to ignore click after drag
		this.dragStart = { x: 0, y: 0 };
		this.hasUnsavedChanges = false;
		this.isDrawingLine = false;
		this.lineStart = null;

		// Marquee selection state
		this.isMarqueeSelecting = false;
		this.marqueeStart = { x: 0, y: 0 };
		this.marqueeRect = null;

		// Resize state
		this.isResizing = false;
		this.resizeHandle = null;
		this.resizeStart = { x: 0, y: 0, width: 0, height: 0, r: 0 };

		// Zoom and pan state
		this.zoom = 1;
		this.minZoom = 0.25;
		this.maxZoom = 4;
		this.pan = { x: 0, y: 0 };
		this.isPanning = false;
		this.panStart = { x: 0, y: 0, panX: 0, panY: 0 };

		// Version toggle state (draft/published preview)
		this.viewingPublished = false;
		this.plan = null; // Das komplette Sitzplan-Objekt (von getFullPlan)
		this.spaceKeyHeld = false; // For Space+drag panning

		// Bulk insert state
		this.bulkInsertPreview = null; // SVG group for preview
		this.bulkInsertStart = null; // Starting position

		// Element ID counter
		this.nextId = 1;

		// Initialize
		this.init();
	}

	/**
	 * Initialize the designer
	 */
	SeatingDesigner.prototype.init = function() {
		this.createCanvas();
		this.createToolbar();
		this.createPropertiesPanel();
		this.createActionsPanel();
		this.bindEvents();
		this.bindVersionToggleHandlers();
		// Data is loaded by openDesigner() via applyLoadedData(), not here
	};

	// =========================================================================
	// Canvas Creation
	// =========================================================================

	/**
	 * Create SVG canvas with layers
	 */
	SeatingDesigner.prototype.createCanvas = function() {
		var self = this;
		var $container = $(this.config.container);

		// Create wrapper
		var $wrapper = $('<div class="saso-designer-canvas-wrapper"></div>');
		$wrapper.css({
			width: this.config.canvasWidth + 'px',
			height: this.config.canvasHeight + 'px',
			position: 'relative',
			overflow: 'hidden',
			border: '1px solid #c3c4c7',
			borderRadius: '4px',
			backgroundColor: this.config.backgroundColor
		});

		// Create SVG
		var svgNS = 'http://www.w3.org/2000/svg';
		this.svg = document.createElementNS(svgNS, 'svg');
		this.svg.setAttribute('width', this.config.canvasWidth);
		this.svg.setAttribute('height', this.config.canvasHeight);
		this.svg.setAttribute('class', 'saso-designer-svg');
		this.svg.style.display = 'block';

		// Initialize viewBox (zoom and pan start at 1x, 0,0)
		this.zoom = 1;
		this.pan = { x: 0, y: 0 };
		this.svg.setAttribute('viewBox', '0 0 ' + this.config.canvasWidth + ' ' + this.config.canvasHeight);

		// Create layers (order matters for z-index)
		var layerNames = ['background', 'lines', 'decorations', 'seats', 'labels'];
		layerNames.forEach(function(name, index) {
			var group = document.createElementNS(svgNS, 'g');
			group.setAttribute('class', 'saso-layer-' + name);
			group.setAttribute('data-layer', name);
			self.svg.appendChild(group);
			self.layers[name] = group;
		});

		// Add background rect
		this.createBackgroundRect();

		// Add SVG to wrapper
		$wrapper.append(this.svg);
		$container.find('.saso-designer-canvas-area').html('').append($wrapper);

		// Store reference
		this.$canvas = $wrapper;

		// Rebind canvas events (important when canvas is recreated)
		this.bindCanvasEvents();
	};

	/**
	 * Create background rectangle
	 */
	SeatingDesigner.prototype.createBackgroundRect = function() {
		var svgNS = 'http://www.w3.org/2000/svg';
		var rect = document.createElementNS(svgNS, 'rect');
		rect.setAttribute('x', 0);
		rect.setAttribute('y', 0);
		rect.setAttribute('width', this.config.canvasWidth);
		rect.setAttribute('height', this.config.canvasHeight);
		rect.setAttribute('fill', this.config.backgroundColor);
		rect.setAttribute('class', 'saso-background-rect');
		this.layers.background.appendChild(rect);

		// Add background image if set
		if (this.config.backgroundImage) {
			this.setBackgroundImage(this.config.backgroundImage);
		}
	};

	/**
	 * Set background image
	 *
	 * @param {string} url Image URL
	 */
	SeatingDesigner.prototype.setBackgroundImage = function(url) {
		var svgNS = 'http://www.w3.org/2000/svg';

		// Remove existing image
		var existing = this.layers.background.querySelector('.saso-background-image');
		if (existing) {
			existing.remove();
		}

		if (!url) return;

		// Get fit and align settings
		var fit = this.config.backgroundImageFit || 'contain';
		var align = this.config.backgroundImageAlign || 'center';
		var aspectRatio;

		switch (fit) {
			case 'contain':
				aspectRatio = this.getPreserveAspectRatio(align, 'meet');
				break;
			case 'cover':
				aspectRatio = this.getPreserveAspectRatio(align, 'slice');
				break;
			case 'stretch':
				aspectRatio = 'none';
				break;
			case 'original':
				aspectRatio = this.getPreserveAspectRatio(align, 'meet');
				break;
			default:
				aspectRatio = 'xMidYMid meet';
		}

		var image = document.createElementNS(svgNS, 'image');
		image.setAttribute('x', 0);
		image.setAttribute('y', 0);
		image.setAttribute('width', this.config.canvasWidth);
		image.setAttribute('height', this.config.canvasHeight);
		image.setAttribute('href', url);
		image.setAttribute('preserveAspectRatio', aspectRatio);
		image.setAttribute('class', 'saso-background-image');
		this.layers.background.appendChild(image);

		this.config.backgroundImage = url;
	};

	// =========================================================================
	// Toolbar
	// =========================================================================

	/**
	 * Create toolbar
	 */
	SeatingDesigner.prototype.createToolbar = function() {
		var self = this;
		var $container = $(this.config.container);

		var tools = [
			{ id: 'select', icon: 'dashicons-move', label: this.config.i18n.toolSelect || 'Select' },
			{ id: 'seat', icon: 'dashicons-tickets-alt', label: this.config.i18n.toolSeat || 'Seat', primary: true },
			{ id: 'row', icon: 'dashicons-grid-view', label: this.config.i18n.toolRow || 'Row' },
			{ id: 'rect', icon: 'dashicons-screenoptions', label: this.config.i18n.toolRect || 'Rectangle' },
			{ id: 'circle', icon: 'dashicons-marker', label: this.config.i18n.toolCircle || 'Circle' },
			{ id: 'line', icon: 'dashicons-minus', label: this.config.i18n.toolLine || 'Line' },
			{ id: 'text', icon: 'dashicons-editor-textcolor', label: this.config.i18n.toolText || 'Text' },
			{ id: 'delete', icon: 'dashicons-trash', label: this.config.i18n.toolDelete || 'Delete' }
		];

		var $toolbar = $('<div class="saso-designer-toolbar"></div>');

		tools.forEach(function(tool) {
			var btnClass = 'saso-tool-btn';
			if (tool.primary) btnClass += ' saso-tool-primary';

			var $btn = $('<button type="button" class="' + btnClass + '" data-tool="' + tool.id + '" title="' + tool.label + '">' +
				'<span class="dashicons ' + tool.icon + '"></span>' +
				'<span class="tool-label">' + tool.label + '</span>' +
				'</button>');

			if (tool.id === self.currentTool) {
				$btn.addClass('active');
			}

			$toolbar.append($btn);
		});

		// Add separator and additional controls
		$toolbar.append('<span class="toolbar-separator"></span>');

		// Grid snap toggle
		var $gridSnap = $('<label class="saso-grid-snap">' +
			'<input type="checkbox" ' + (this.config.snapToGrid ? 'checked' : '') + '> ' +
			(this.config.i18n.snapToGrid || 'Snap to Grid') +
			'</label>');
		$toolbar.append($gridSnap);

		// Help button
		var $helpBtn = $('<button type="button" class="saso-tool-btn saso-help-btn" title="' + (this.config.i18n.help || 'Help') + '">' +
			'<span class="dashicons dashicons-editor-help"></span>' +
			'</button>');
		$toolbar.append($helpBtn);

		// Zoom controls separator
		$toolbar.append('<span class="toolbar-separator"></span>');

		// Zoom out button
		var $zoomOut = $('<button type="button" class="saso-tool-btn saso-zoom-out" title="' + (this.config.i18n.zoomOut || 'Zoom Out') + '">' +
			'<span class="dashicons dashicons-minus"></span>' +
			'</button>');
		$toolbar.append($zoomOut);

		// Zoom level display
		var $zoomLevel = $('<span class="saso-zoom-level" title="' + (this.config.i18n.zoomLevel || 'Zoom Level') + '">100%</span>');
		$toolbar.append($zoomLevel);

		// Zoom in button
		var $zoomIn = $('<button type="button" class="saso-tool-btn saso-zoom-in" title="' + (this.config.i18n.zoomIn || 'Zoom In') + '">' +
			'<span class="dashicons dashicons-plus"></span>' +
			'</button>');
		$toolbar.append($zoomIn);

		// Fit to view button
		var $fitView = $('<button type="button" class="saso-tool-btn saso-fit-view" title="' + (this.config.i18n.fitToView || 'Fit to View') + '">' +
			'<span class="dashicons dashicons-fullscreen-alt"></span>' +
			'</button>');
		$toolbar.append($fitView);

		// Reset zoom button
		var $resetZoom = $('<button type="button" class="saso-tool-btn saso-reset-zoom" title="' + (this.config.i18n.resetZoom || 'Reset Zoom (100%)') + '">' +
			'<span class="dashicons dashicons-image-rotate"></span>' +
			'</button>');
		$toolbar.append($resetZoom);

		$container.find('.saso-designer-toolbar-area').html('').append($toolbar);
	};

	/**
	 * Show help modal
	 */
	SeatingDesigner.prototype.showHelpModal = function() {
		var i18n = this.config.i18n;
		var html = '<div class="saso-modal saso-help-modal" style="display:flex;">' +
			'<div class="saso-modal-content" style="max-width:600px;">' +
			'<div class="saso-modal-header">' +
			'<h3 class="saso-modal-title">' + (i18n.helpTitle || 'Visual Designer - Help') + '</h3>' +
			'<button type="button" class="saso-modal-close">&times;</button>' +
			'</div>' +
			'<div class="saso-modal-body">' +

			'<h4>' + (i18n.helpToolsTitle || 'Tools') + '</h4>' +
			'<table class="saso-help-table">' +
			'<tr><td><span class="dashicons dashicons-move"></span> <strong>Select</strong></td>' +
			'<td>' + (i18n.helpSelectTool || 'Select and drag elements to move them') + '</td></tr>' +

			'<tr><td><span class="dashicons dashicons-tickets-alt"></span> <strong>Seat</strong></td>' +
			'<td>' + (i18n.helpSeatTool || 'Quick-add a bookable seat (creates rectangle with "Is Seat" enabled)') + '</td></tr>' +

			'<tr><td><span class="dashicons dashicons-grid-view"></span> <strong>Row</strong></td>' +
			'<td>' + (i18n.helpRowTool || 'Bulk insert a row or grid of seats with auto-numbering') + '</td></tr>' +

			'<tr><td><span class="dashicons dashicons-screenoptions"></span> <strong>Rectangle</strong></td>' +
			'<td>' + (i18n.helpRectTool || 'Add a rectangle shape (decoration or convert to seat via Properties)') + '</td></tr>' +

			'<tr><td><span class="dashicons dashicons-marker"></span> <strong>Circle</strong></td>' +
			'<td>' + (i18n.helpCircleTool || 'Add a circle shape (decoration or convert to seat)') + '</td></tr>' +

			'<tr><td><span class="dashicons dashicons-minus"></span> <strong>Line</strong></td>' +
			'<td>' + (i18n.helpLineTool || 'Click twice to draw a line from point A to B') + '</td></tr>' +

			'<tr><td><span class="dashicons dashicons-editor-textcolor"></span> <strong>Text</strong></td>' +
			'<td>' + (i18n.helpTextTool || 'Add text labels (e.g., "Stage", "Exit", row numbers)') + '</td></tr>' +

			'<tr><td><span class="dashicons dashicons-trash"></span> <strong>Delete</strong></td>' +
			'<td>' + (i18n.helpDeleteTool || 'Click on elements to delete them') + '</td></tr>' +
			'</table>' +

			'<h4>' + (i18n.helpPropertiesTitle || 'Properties Panel') + '</h4>' +
			'<p>' + (i18n.helpPropertiesDesc || 'Select an element to edit its properties. Enable "Is Seat" to make a shape bookable. Seats require a unique Seat ID (e.g., "A-1") which is shown on tickets.') + '</p>' +

			'<h4>' + (i18n.helpKeyboardTitle || 'Keyboard Shortcuts') + '</h4>' +
			'<table class="saso-help-table">' +
			'<tr><td><code>Delete</code> / <code>Backspace</code></td><td>' + (i18n.helpDeleteKey || 'Delete selected element(s)') + '</td></tr>' +
			'<tr><td><code>Escape</code></td><td>' + (i18n.helpEscapeKey || 'Deselect all') + '</td></tr>' +
			'<tr><td><code>Ctrl+A</code></td><td>' + (i18n.helpSelectAll || 'Select all elements') + '</td></tr>' +
			'<tr><td><code>Ctrl+S</code></td><td>' + (i18n.helpSaveKey || 'Save draft') + '</td></tr>' +
			'<tr><td><code>Shift+Click</code></td><td>' + (i18n.helpShiftClick || 'Add/remove from selection') + '</td></tr>' +
			'<tr><td>' + (i18n.helpMarquee || 'Drag on canvas') + '</td><td>' + (i18n.helpMarqueeDesc || 'Marquee select multiple elements') + '</td></tr>' +
			'</table>' +

			'<h4>' + (i18n.helpZoomTitle || 'Zoom & Pan') + '</h4>' +
			'<table class="saso-help-table">' +
			'<tr><td><code>Mouse Wheel</code></td><td>' + (i18n.helpZoomWheel || 'Zoom in/out at cursor position') + '</td></tr>' +
			'<tr><td><code>+</code> / <code>-</code></td><td>' + (i18n.helpZoomKeys || 'Zoom in / Zoom out') + '</td></tr>' +
			'<tr><td><code>0</code></td><td>' + (i18n.helpZoomReset || 'Reset zoom to 100%') + '</td></tr>' +
			'<tr><td>' + (i18n.helpRightMouse || 'Right Mouse Drag') + '</td><td>' + (i18n.helpRightMouseDesc || 'Pan/scroll the canvas') + '</td></tr>' +
			'<tr><td><code>Space</code> + ' + (i18n.helpDrag || 'Drag') + '</td><td>' + (i18n.helpSpaceDrag || 'Pan/scroll the canvas (hold Space, then drag)') + '</td></tr>' +
			'<tr><td>' + (i18n.helpMiddleMouse || 'Middle Mouse') + '</td><td>' + (i18n.helpMiddleMouseDesc || 'Pan/scroll the canvas') + '</td></tr>' +
			'</table>' +

			'<h4>' + (i18n.helpWorkflowTitle || 'Workflow') + '</h4>' +
			'<ol>' +
			'<li>' + (i18n.helpStep1 || 'Add seats using the Seat tool or create shapes and enable "Is Seat"') + '</li>' +
			'<li>' + (i18n.helpStep2 || 'Use Text tool to add labels like "Stage", "Row A", etc.') + '</li>' +
			'<li>' + (i18n.helpStep3 || 'Save Draft to save your work without publishing') + '</li>' +
			'<li>' + (i18n.helpStep4 || 'Publish to make changes visible to customers') + '</li>' +
			'</ol>' +

			'</div>' +
			'</div>' +
			'</div>';

		var $modal = $(html);
		$modal.on('click', '.saso-modal-close', function() { $modal.remove(); });
		$modal.on('click', function(e) { if (e.target === this) $modal.remove(); });
		$('body').append($modal);
	};

	// =========================================================================
	// Bulk Insert (Row/Grid)
	// =========================================================================

	/**
	 * Show bulk insert modal for row/grid creation
	 *
	 * @param {number} startX Starting X position
	 * @param {number} startY Starting Y position
	 */
	SeatingDesigner.prototype.showBulkInsertModal = function(startX, startY) {
		var self = this;
		var i18n = this.config.i18n;

		// Store starting position
		this.bulkInsertStart = { x: startX, y: startY };

		// Default values
		var defaults = {
			count: 10,
			rowLabel: this.getNextRowLabel(),
			startNumber: 1,
			spacing: 35,
			direction: 'horizontal',
			rows: 1,
			rowSpacing: 40
		};

		var html = '<div class="saso-modal saso-bulk-insert-modal" style="display:flex;">' +
			'<div class="saso-modal-content" style="max-width:450px;">' +
			'<div class="saso-modal-header">' +
			'<h3 class="saso-modal-title">' + (i18n.bulkInsertTitle || 'Insert Seat Row') + '</h3>' +
			'<button type="button" class="saso-modal-close">&times;</button>' +
			'</div>' +
			'<div class="saso-modal-body">' +

			'<div class="saso-bulk-form">' +
			// Row Label
			'<div class="saso-bulk-row">' +
			'<label>' + (i18n.rowLabel || 'Row Label') + '</label>' +
			'<input type="text" id="saso-bulk-row-label" value="' + defaults.rowLabel + '" placeholder="A, B, 1, 2...">' +
			'</div>' +

			// Number of seats
			'<div class="saso-bulk-row">' +
			'<label>' + (i18n.seatsPerRow || 'Seats per Row') + '</label>' +
			'<input type="number" id="saso-bulk-count" value="' + defaults.count + '" min="1" max="100">' +
			'</div>' +

			// Starting number
			'<div class="saso-bulk-row">' +
			'<label>' + (i18n.startNumber || 'Start Number') + '</label>' +
			'<input type="number" id="saso-bulk-start" value="' + defaults.startNumber + '" min="1">' +
			'</div>' +

			// Spacing
			'<div class="saso-bulk-row">' +
			'<label>' + (i18n.spacing || 'Spacing (px)') + '</label>' +
			'<input type="number" id="saso-bulk-spacing" value="' + defaults.spacing + '" min="20" max="200">' +
			'</div>' +

			// Direction
			'<div class="saso-bulk-row">' +
			'<label>' + (i18n.direction || 'Direction') + '</label>' +
			'<select id="saso-bulk-direction">' +
			'<option value="horizontal">' + (i18n.horizontal || 'Horizontal (Left to Right)') + '</option>' +
			'<option value="horizontal-rtl">' + (i18n.horizontalRtl || 'Horizontal (Right to Left)') + '</option>' +
			'<option value="vertical">' + (i18n.vertical || 'Vertical (Top to Bottom)') + '</option>' +
			'<option value="vertical-btt">' + (i18n.verticalBtt || 'Vertical (Bottom to Top)') + '</option>' +
			'</select>' +
			'</div>' +

			// Grid mode toggle
			'<div class="saso-bulk-row saso-bulk-grid-toggle">' +
			'<label class="checkbox-label">' +
			'<input type="checkbox" id="saso-bulk-grid-mode"> ' +
			(i18n.gridMode || 'Grid Mode (Multiple Rows)') +
			'</label>' +
			'</div>' +

			// Grid options (hidden by default)
			'<div class="saso-bulk-grid-options" style="display:none;">' +
			'<div class="saso-bulk-row">' +
			'<label>' + (i18n.numberOfRows || 'Number of Rows') + '</label>' +
			'<input type="number" id="saso-bulk-rows" value="' + defaults.rows + '" min="1" max="50">' +
			'</div>' +
			'<div class="saso-bulk-row">' +
			'<label>' + (i18n.rowSpacing || 'Row Spacing (px)') + '</label>' +
			'<input type="number" id="saso-bulk-row-spacing" value="' + defaults.rowSpacing + '" min="20" max="200">' +
			'</div>' +
			'<div class="saso-bulk-row">' +
			'<label class="checkbox-label">' +
			'<input type="checkbox" id="saso-bulk-auto-label" checked> ' +
			(i18n.autoIncrementLabel || 'Auto-increment row labels (A→B→C)') +
			'</label>' +
			'</div>' +
			'</div>' +

			// Preview info
			'<div class="saso-bulk-preview-info">' +
			'<span class="dashicons dashicons-info"></span> ' +
			'<span id="saso-bulk-preview-text">' + (i18n.previewText || 'Will create') + ' <strong>' + defaults.count + '</strong> ' + (i18n.seats || 'seats') + '</span>' +
			'</div>' +

			'</div>' +

			'</div>' +
			'<div class="saso-modal-footer">' +
			'<button type="button" class="button saso-bulk-cancel">' + (i18n.cancel || 'Cancel') + '</button>' +
			'<button type="button" class="button button-primary saso-bulk-insert">' + (i18n.insertSeats || 'Insert Seats') + '</button>' +
			'</div>' +
			'</div>' +
			'</div>';

		var $modal = $(html);

		// Update preview text when values change
		function updatePreview() {
			var count = parseInt($modal.find('#saso-bulk-count').val()) || 1;
			var isGrid = $modal.find('#saso-bulk-grid-mode').is(':checked');
			var rows = isGrid ? (parseInt($modal.find('#saso-bulk-rows').val()) || 1) : 1;
			var total = count * rows;
			$modal.find('#saso-bulk-preview-text').html(
				(i18n.previewText || 'Will create') + ' <strong>' + total + '</strong> ' + (i18n.seats || 'seats') +
				(rows > 1 ? ' (' + rows + ' ' + (i18n.rows || 'rows') + ')' : '')
			);
		}

		// Toggle grid options
		$modal.on('change', '#saso-bulk-grid-mode', function() {
			$modal.find('.saso-bulk-grid-options').toggle($(this).is(':checked'));
			updatePreview();
		});

		// Update preview on input changes
		$modal.on('input change', '#saso-bulk-count, #saso-bulk-rows', updatePreview);

		// Cancel button
		$modal.on('click', '.saso-bulk-cancel, .saso-modal-close', function() {
			self.clearBulkInsertPreview();
			$modal.remove();
		});

		// Close on backdrop click
		$modal.on('click', function(e) {
			if (e.target === this) {
				self.clearBulkInsertPreview();
				$modal.remove();
			}
		});

		// Insert button
		$modal.on('click', '.saso-bulk-insert', function() {
			var settings = {
				rowLabel: $modal.find('#saso-bulk-row-label').val() || 'A',
				count: parseInt($modal.find('#saso-bulk-count').val()) || 10,
				startNumber: parseInt($modal.find('#saso-bulk-start').val()) || 1,
				spacing: parseInt($modal.find('#saso-bulk-spacing').val()) || 35,
				direction: $modal.find('#saso-bulk-direction').val(),
				isGrid: $modal.find('#saso-bulk-grid-mode').is(':checked'),
				rows: parseInt($modal.find('#saso-bulk-rows').val()) || 1,
				rowSpacing: parseInt($modal.find('#saso-bulk-row-spacing').val()) || 40,
				autoLabel: $modal.find('#saso-bulk-auto-label').is(':checked')
			};

			self.createSeatRow(settings);
			$modal.remove();
		});

		$('body').append($modal);

		// Focus first input
		$modal.find('#saso-bulk-row-label').focus().select();
	};

	/**
	 * Get the next available row label
	 *
	 * @return {string} Next row label (A, B, C... or 1, 2, 3...)
	 */
	SeatingDesigner.prototype.getNextRowLabel = function() {
		var usedLabels = {};

		// Collect used row labels from existing seats
		this.elements.seats.forEach(function(seat) {
			if (seat.identifier) {
				// Extract row part (everything before the last number/dash)
				var match = seat.identifier.match(/^([A-Za-z]+|\d+)/);
				if (match) {
					usedLabels[match[1].toUpperCase()] = true;
				}
			}
		});

		// Try letters first (A-Z)
		for (var i = 0; i < 26; i++) {
			var letter = String.fromCharCode(65 + i);
			if (!usedLabels[letter]) {
				return letter;
			}
		}

		// Fall back to numbers
		for (var n = 1; n <= 100; n++) {
			if (!usedLabels[n.toString()]) {
				return n.toString();
			}
		}

		return 'A';
	};

	/**
	 * Get next row label (A→B, B→C, 1→2, etc.)
	 *
	 * @param {string} current Current label
	 * @return {string} Next label
	 */
	SeatingDesigner.prototype.incrementRowLabel = function(current) {
		if (!current) return 'A';

		// Check if it's a letter
		if (/^[A-Za-z]+$/.test(current)) {
			var chars = current.toUpperCase().split('');
			var i = chars.length - 1;

			while (i >= 0) {
				if (chars[i] === 'Z') {
					chars[i] = 'A';
					i--;
				} else {
					chars[i] = String.fromCharCode(chars[i].charCodeAt(0) + 1);
					break;
				}
			}

			if (i < 0) {
				chars.unshift('A');
			}

			return chars.join('');
		}

		// It's a number
		var num = parseInt(current);
		if (!isNaN(num)) {
			return (num + 1).toString();
		}

		return current;
	};

	/**
	 * Create a row (or grid) of seats
	 *
	 * @param {Object} settings Bulk insert settings
	 */
	SeatingDesigner.prototype.createSeatRow = function(settings) {
		var startX = this.bulkInsertStart.x;
		var startY = this.bulkInsertStart.y;
		var currentRowLabel = settings.rowLabel;
		var totalRows = settings.isGrid ? settings.rows : 1;
		var createdSeats = [];

		for (var row = 0; row < totalRows; row++) {
			for (var i = 0; i < settings.count; i++) {
				var seatNum = settings.startNumber + i;
				var identifier = currentRowLabel + '-' + seatNum;

				// Calculate position based on direction
				var x = startX;
				var y = startY;

				switch (settings.direction) {
					case 'horizontal':
						x = startX + (i * settings.spacing);
						y = startY + (row * settings.rowSpacing);
						break;
					case 'horizontal-rtl':
						x = startX - (i * settings.spacing);
						y = startY + (row * settings.rowSpacing);
						break;
					case 'vertical':
						x = startX + (row * settings.rowSpacing);
						y = startY + (i * settings.spacing);
						break;
					case 'vertical-btt':
						x = startX + (row * settings.rowSpacing);
						y = startY - (i * settings.spacing);
						break;
				}

				// Create seat element
				var seat = this.createSeatElement(x, y, identifier, currentRowLabel + ' ' + seatNum);
				createdSeats.push(seat);
			}

			// Increment row label for next row
			if (settings.isGrid && settings.autoLabel) {
				currentRowLabel = this.incrementRowLabel(currentRowLabel);
			}
		}

		// Select all newly created seats
		this.deselectAll();
		var self = this;
		createdSeats.forEach(function(seat) {
			self.addToSelection(seat);
		});
		this.updatePropertiesPanelMulti();
		this.updateElementCounts();
		this.markUnsaved();

		// Show success message
		var total = createdSeats.length;
		this.showNotice(
			(this.config.i18n.seatsCreated || '{count} seats created').replace('{count}', total),
			'success'
		);
	};

	/**
	 * Create a single seat element (used by bulk insert)
	 *
	 * @param {number} x X position
	 * @param {number} y Y position
	 * @param {string} identifier Seat identifier (e.g., "A-1")
	 * @param {string} label Display label (e.g., "A 1")
	 * @return {Object} Created seat element
	 */
	SeatingDesigner.prototype.createSeatElement = function(x, y, identifier, label) {
		var id = 'seat_new_' + this.nextId++;

		var element = {
			id: id,
			type: 'rect',
			x: x,
			y: y,
			width: 30,
			height: 30,
			fill: this.config.colors.available,
			fillOpacity: 100,
			stroke: '#333333',
			strokeOpacity: 0,
			isSeat: true,
			identifier: identifier,
			label: label,
			labelColor: '#ffffff',
			labelColorOpacity: 100,
			labelStroke: '#000000',
			labelStrokeOpacity: 50,
			category: '',
			seat_desc: ''
		};

		this.elements.seats.push(element);
		this.renderElement(element);

		return element;
	};

	/**
	 * Clear bulk insert preview elements
	 */
	SeatingDesigner.prototype.clearBulkInsertPreview = function() {
		if (this.bulkInsertPreview) {
			$(this.bulkInsertPreview).remove();
			this.bulkInsertPreview = null;
		}
	};

	// =========================================================================
	// Properties Panel
	// =========================================================================

	/**
	 * Create properties panel
	 */
	SeatingDesigner.prototype.createPropertiesPanel = function() {
		var $container = $(this.config.container);

		var $panel = $('<div class="saso-designer-properties">' +
			'<h4>' + (this.config.i18n.properties || 'Properties') + '</h4>' +
			'<div class="saso-props-content">' +
			'<p class="saso-no-selection">' + (this.config.i18n.noSelection || 'Select an element to edit its properties') + '</p>' +
			'</div>' +
			'</div>');

		$container.find('.saso-designer-properties-area').html('').append($panel);
		this.$propsPanel = $panel.find('.saso-props-content');
	};

	/**
	 * Update properties panel for selected element
	 * Shows canvas properties when no element is selected
	 *
	 * @param {Object} element Selected element data (null for canvas)
	 */
	SeatingDesigner.prototype.updatePropertiesPanel = function(element) {
		if (!element) {
			this.showCanvasProperties();
			return;
		}

		var self = this;
		var html = '';

		// Common properties
		html += '<div class="saso-prop-group">';
		html += '<label>' + (this.config.i18n.propType || 'Type') + '</label>';
		html += '<span class="prop-value">' + element.type + '</span>';
		html += '</div>';

		// Position
		html += '<div class="saso-prop-row">';
		html += '<div class="saso-prop-group half">';
		html += '<label>X</label>';
		html += '<input type="number" class="prop-input" data-prop="x" value="' + (element.x || 0) + '">';
		html += '</div>';
		html += '<div class="saso-prop-group half">';
		html += '<label>Y</label>';
		html += '<input type="number" class="prop-input" data-prop="y" value="' + (element.y || 0) + '">';
		html += '</div>';
		html += '</div>';

		// Size (for shapes)
		if (element.type === 'rect' || element.type === 'circle' || element.type === 'seat') {
			html += '<div class="saso-prop-row">';
			html += '<div class="saso-prop-group half">';
			html += '<label>' + (element.type === 'circle' ? 'Radius' : (this.config.i18n.propWidth || 'Width')) + '</label>';
			// For circles: show radius (r), not width (diameter)
			var sizeValue = element.type === 'circle' ? (element.r || 15) : (element.width || 30);
			html += '<input type="number" class="prop-input" data-prop="' + (element.type === 'circle' ? 'r' : 'width') + '" value="' + sizeValue + '">';
			html += '</div>';
			if (element.type !== 'circle') {
				html += '<div class="saso-prop-group half">';
				html += '<label>' + (this.config.i18n.propHeight || 'Height') + '</label>';
				html += '<input type="number" class="prop-input" data-prop="height" value="' + (element.height || 30) + '">';
				html += '</div>';
			}
			html += '</div>';

			// Rotation
			html += '<div class="saso-prop-group">';
			html += '<label>' + (this.config.i18n.propRotation || 'Rotation') + '</label>';
			html += '<div class="saso-rotation-input">';
			html += '<input type="number" class="prop-input" data-prop="rotation" value="' + (element.rotation || 0) + '" min="0" max="359" step="1">';
			html += '<span class="saso-rotation-unit">°</span>';
			html += '<div class="saso-rotation-presets">';
			html += '<button type="button" class="saso-rotation-preset" data-angle="0">0°</button>';
			html += '<button type="button" class="saso-rotation-preset" data-angle="45">45°</button>';
			html += '<button type="button" class="saso-rotation-preset" data-angle="90">90°</button>';
			html += '<button type="button" class="saso-rotation-preset" data-angle="180">180°</button>';
			html += '<button type="button" class="saso-rotation-preset" data-angle="270">270°</button>';
			html += '</div>';
			html += '</div>';
			html += '</div>';
		}

		// Color (fill) with opacity for shapes
		if (element.type !== 'line') {
			html += '<div class="saso-prop-row">';
			html += '<div class="saso-prop-group half">';
			html += '<label>' + (this.config.i18n.propColor || 'Fill') + '</label>';
			html += this.renderColorButton('fill', element.fill || '#cccccc', 'fillOpacity', element.fillOpacity !== undefined ? element.fillOpacity : 100);
			html += '</div>';
			html += '<div class="saso-prop-group half">';
			html += '<label>' + (this.config.i18n.propOutline || 'Outline') + '</label>';
			html += this.renderColorButton('stroke', element.stroke || '#333333', 'strokeOpacity', element.strokeOpacity !== undefined ? element.strokeOpacity : 0);
			html += '</div>';
			html += '</div>';
		}

		// Stroke for lines
		if (element.type === 'line') {
			html += '<div class="saso-prop-group">';
			html += '<label>' + (this.config.i18n.propStroke || 'Line Color') + '</label>';
			html += this.renderColorButton('stroke', element.stroke || '#333333', 'strokeOpacity', element.strokeOpacity !== undefined ? element.strokeOpacity : 100);
			html += '</div>';
		}

		// Stroke width for lines
		if (element.type === 'line') {
			html += '<div class="saso-prop-group">';
			html += '<label>' + (this.config.i18n.propStrokeWidth || 'Stroke Width') + '</label>';
			html += '<input type="number" class="prop-input" data-prop="strokeWidth" value="' + (element.strokeWidth || 2) + '" min="1" max="20">';
			html += '</div>';
		}

		// Text content for text labels
		if (element.type === 'text') {
			html += '<div class="saso-prop-group">';
			html += '<label>' + (this.config.i18n.propText || 'Text') + '</label>';
			html += '<input type="text" class="prop-input" data-prop="text" value="' + (element.text || '') + '">';
			html += '</div>';
			html += '<div class="saso-prop-group">';
			html += '<label>' + (this.config.i18n.propFontSize || 'Font Size') + '</label>';
			html += '<input type="number" class="prop-input" data-prop="fontSize" value="' + (element.fontSize || 14) + '" min="8" max="72">';
			html += '</div>';
		}

		// Label field for all elements except text (which uses 'text' property)
		if (element.type !== 'text') {
			html += '<div class="saso-prop-group">';
			html += '<label>' + (this.config.i18n.propLabel || 'Label') + '</label>';
			html += '<input type="text" class="prop-input" data-prop="label" value="' + (element.label || '') + '">';
			html += '</div>';

			// Label colors (text color and outline color) with opacity
			html += '<div class="saso-prop-row">';
			html += '<div class="saso-prop-group half">';
			html += '<label>' + (this.config.i18n.propLabelColor || 'Label Color') + '</label>';
			html += this.renderColorButton('labelColor', element.labelColor || '#333333', 'labelColorOpacity', element.labelColorOpacity !== undefined ? element.labelColorOpacity : 100);
			html += '</div>';
			html += '<div class="saso-prop-group half">';
			html += '<label>' + (this.config.i18n.propLabelOutline || 'Label Outline') + '</label>';
			html += this.renderColorButton('labelStroke', element.labelStroke || '#ffffff', 'labelStrokeOpacity', element.labelStrokeOpacity !== undefined ? element.labelStrokeOpacity : 100);
			html += '</div>';
			html += '</div>';
		}

		// Is Seat checkbox (for shapes)
		if (element.type === 'rect' || element.type === 'circle') {
			html += '<div class="saso-prop-group">';
			html += '<label class="checkbox-label">';
			html += '<input type="checkbox" class="prop-input" data-prop="isSeat" ' + (element.isSeat ? 'checked' : '') + '>';
			html += ' ' + (this.config.i18n.propIsSeat || 'Is Seat (bookable)');
			html += '</label>';
			html += '</div>';

			// Seat-specific properties (Seat ID and Category - Label is already shown above)
			if (element.isSeat) {
				html += '<div class="saso-seat-props">';
				html += '<div class="saso-prop-group">';
				html += '<label>' + (this.config.i18n.propIdentifier || 'Seat ID') + '</label>';
				html += '<input type="text" class="prop-input" data-prop="identifier" value="' + (element.identifier || '') + '" placeholder="A-1">';
				html += '<p class="description">' + (this.config.i18n.propIdentifierDesc || 'Unique ID for booking') + '</p>';
				html += '</div>';
				html += '<div class="saso-prop-group">';
				html += '<label>' + (this.config.i18n.propCategory || 'Category') + '</label>';
				html += '<input type="text" class="prop-input" data-prop="category" value="' + (element.category || '') + '">';
				html += '</div>';
				html += '</div>';
			}
		}

		// Description (optional)
		html += '<div class="saso-prop-group">';
		html += '<label>' + (this.config.i18n.propDescription || 'Description') + '</label>';
		html += '<textarea class="prop-input" data-prop="seat_desc" rows="2">' + (element.seat_desc || '') + '</textarea>';
		html += '</div>';

		// Action buttons
		html += '<div class="saso-prop-actions">';
		html += '<button type="button" class="button saso-duplicate-element">';
		html += '<span class="dashicons dashicons-admin-page"></span> ' + (this.config.i18n.duplicate || 'Duplicate');
		html += '</button>';
		html += '<button type="button" class="button saso-delete-element">';
		html += '<span class="dashicons dashicons-trash"></span> ' + (this.config.i18n.delete || 'Delete');
		html += '</button>';
		html += '</div>';

		this.$propsPanel.html(html);

		// Bind property change events
		this.$propsPanel.find('.prop-input').on('change input', function() {
			var prop = $(this).data('prop');
			var value = $(this).attr('type') === 'checkbox' ? $(this).is(':checked') : $(this).val();

			if ($(this).attr('type') === 'number') {
				value = parseFloat(value) || 0;
			}

			self.updateElementProperty(element.id, prop, value);
		});

		// Bind action buttons
		this.$propsPanel.find('.saso-duplicate-element').on('click', function() {
			self.duplicateElement(element.id);
		});

		this.$propsPanel.find('.saso-delete-element').on('click', function() {
			if (element.isSeat) {
				if (confirm(self.config.i18n.confirmDeleteSeat || 'Delete this seat? This cannot be undone.')) {
					self.deleteElement(element.id);
				}
			} else {
				self.deleteElement(element.id);
			}
		});

		// Bind color buttons
		this.bindColorButtons(element);

		// Bind rotation preset buttons
		this.$propsPanel.find('.saso-rotation-preset').on('click', function() {
			var angle = parseInt($(this).data('angle')) || 0;
			self.$propsPanel.find('.prop-input[data-prop="rotation"]').val(angle).trigger('change');
		});
	};

	/**
	 * Render a color button with preview (opens modal with color + opacity)
	 */
	SeatingDesigner.prototype.renderColorButton = function(colorProp, colorValue, opacityProp, opacityValue, isMultiSelect) {
		var isMixed = colorValue === 'mixed';
		var displayColor = isMixed ? '#888888' : colorValue;
		var opacity = opacityValue / 100;
		var rgbaPreview = this.hexToRgba(displayColor, isMixed ? 0.5 : opacity);
		return '<button type="button" class="saso-color-btn' + (isMultiSelect ? ' multi-select' : '') + '" ' +
			'data-color-prop="' + colorProp + '" ' +
			'data-opacity-prop="' + opacityProp + '" ' +
			'data-color="' + displayColor + '" ' +
			'data-opacity="' + opacityValue + '">' +
			'<span class="saso-color-preview"><span class="saso-color-swatch" style="background:' + rgbaPreview + ';"></span></span>' +
			'<span class="saso-color-value">' + (isMixed ? 'Mixed' : (opacityValue < 100 ? opacityValue + '%' : '')) + '</span>' +
			'</button>';
	};

	/**
	 * Bind color button click events
	 */
	SeatingDesigner.prototype.bindColorButtons = function(element) {
		var self = this;
		this.$propsPanel.find('.saso-color-btn').on('click', function(e) {
			e.preventDefault();
			var $btn = $(this);
			var isMulti = $btn.hasClass('multi-select');
			self.openColorModal($btn, isMulti ? null : element, isMulti);
		});
	};

	/**
	 * Open color picker modal
	 *
	 * @param {jQuery} $btn Button element
	 * @param {Object|null} element Single element or null for multi-select
	 * @param {boolean} isMultiSelect Whether this is for multiple elements
	 */
	SeatingDesigner.prototype.openColorModal = function($btn, element, isMultiSelect) {
		var self = this;
		var colorProp = $btn.data('color-prop');
		var opacityProp = $btn.data('opacity-prop');
		var currentColor = $btn.data('color');
		var currentOpacity = $btn.data('opacity');

		// Remove existing modal
		$('.saso-color-modal').remove();

		// Create modal
		var $modal = $('<div class="saso-color-modal">' +
			'<div class="saso-color-modal-content">' +
			'<div class="saso-color-modal-row">' +
			'<input type="color" class="saso-modal-color" value="' + currentColor + '">' +
			'<div class="saso-modal-opacity-wrap">' +
			'<input type="range" class="saso-modal-opacity" min="0" max="100" value="' + currentOpacity + '">' +
			'<span class="saso-modal-opacity-value">' + currentOpacity + '%</span>' +
			'</div>' +
			'</div>' +
			'<div class="saso-color-modal-preview">' +
			'<span class="saso-preview-box"><span class="saso-preview-swatch" style="background:' + this.hexToRgba(currentColor, currentOpacity/100) + ';"></span></span>' +
			'</div>' +
			'</div>' +
			'</div>');

		// Position modal near button
		var btnOffset = $btn.offset();
		var btnHeight = $btn.outerHeight();
		$modal.css({
			position: 'absolute',
			top: btnOffset.top + btnHeight + 5,
			left: btnOffset.left,
			zIndex: 100000
		});

		$('body').append($modal);

		// Update preview on change
		var updatePreview = function() {
			var color = $modal.find('.saso-modal-color').val();
			var opacity = parseInt($modal.find('.saso-modal-opacity').val(), 10);
			$modal.find('.saso-modal-opacity-value').text(opacity + '%');
			$modal.find('.saso-preview-swatch').css('background', self.hexToRgba(color, opacity/100));

			// Update button preview
			$btn.data('color', color);
			$btn.data('opacity', opacity);
			$btn.find('.saso-color-swatch').css('background', self.hexToRgba(color, opacity/100));
			$btn.find('.saso-color-value').text(opacity < 100 ? opacity + '%' : '');

			if (isMultiSelect) {
				// Update all selected elements
				self.updateSelectedProperty(colorProp, color);
				self.updateSelectedProperty(opacityProp, opacity);
			} else {
				// Update single element
				element[colorProp] = color;
				element[opacityProp] = opacity;
				self.updateSvgElement(element);
				self.markUnsaved();
			}
		};

		$modal.find('.saso-modal-color').on('input change', updatePreview);
		$modal.find('.saso-modal-opacity').on('input change', updatePreview);

		// Close on click outside
		setTimeout(function() {
			$(document).on('click.colormodal', function(e) {
				if (!$(e.target).closest('.saso-color-modal, .saso-color-btn').length) {
					$modal.remove();
					$(document).off('click.colormodal');
				}
			});
		}, 10);
	};

	/**
	 * Convert hex color to rgba
	 */
	SeatingDesigner.prototype.hexToRgba = function(hex, alpha) {
		var r = parseInt(hex.slice(1, 3), 16);
		var g = parseInt(hex.slice(3, 5), 16);
		var b = parseInt(hex.slice(5, 7), 16);
		return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
	};

	/**
	 * Show canvas properties (when no element selected)
	 */
	SeatingDesigner.prototype.showCanvasProperties = function() {
		var self = this;
		var html = '';

		html += '<div class="saso-canvas-props">';
		html += '<p class="saso-prop-title"><span class="dashicons dashicons-art"></span> ' +
			(this.config.i18n.canvasProperties || 'Canvas Properties') + '</p>';

		// Canvas size
		html += '<div class="saso-prop-row">';
		html += '<div class="saso-prop-group half">';
		html += '<label>' + (this.config.i18n.propWidth || 'Width') + '</label>';
		html += '<input type="number" class="prop-input canvas-prop" data-prop="canvasWidth" value="' + this.config.canvasWidth + '" min="400" max="2000">';
		html += '</div>';
		html += '<div class="saso-prop-group half">';
		html += '<label>' + (this.config.i18n.propHeight || 'Height') + '</label>';
		html += '<input type="number" class="prop-input canvas-prop" data-prop="canvasHeight" value="' + this.config.canvasHeight + '" min="300" max="2000">';
		html += '</div>';
		html += '</div>';

		// Background color
		html += '<div class="saso-prop-group">';
		html += '<label>' + (this.config.i18n.backgroundColor || 'Background Color') + '</label>';
		html += '<input type="color" class="prop-input canvas-prop" data-prop="backgroundColor" value="' + (this.config.backgroundColor || '#ffffff') + '">';
		html += '</div>';

		// Background image
		html += '<div class="saso-prop-group">';
		html += '<label>' + (this.config.i18n.backgroundImage || 'Background Image') + '</label>';
		if (this.config.backgroundImage) {
			html += '<div class="saso-bg-image-preview"><img src="' + this.config.backgroundImage + '" alt=""></div>';
		}
		html += '<div class="saso-image-buttons">';
		html += '<button type="button" class="button saso-select-bg-image">' +
			'<span class="dashicons dashicons-format-image"></span> ' +
			(this.config.backgroundImage ? (this.config.i18n.changeImage || 'Change Image') : (this.config.i18n.selectImage || 'Select Image')) +
			'</button>';
		if (this.config.backgroundImage) {
			html += ' <button type="button" class="button saso-remove-bg-image">' +
				'<span class="dashicons dashicons-no"></span> ' +
				(this.config.i18n.removeImage || 'Remove') +
				'</button>';
		}
		html += '</div>';

		// Image fit options (only show if image is set)
		if (this.config.backgroundImage) {
			var currentFit = this.config.backgroundImageFit || 'contain';
			var currentAlign = this.config.backgroundImageAlign || 'center';

			html += '<div class="saso-prop-row" style="margin-top:10px;">';
			html += '<div class="saso-prop-group half">';
			html += '<label>' + (this.config.i18n.imageFit || 'Fit') + '</label>';
			html += '<select class="canvas-prop" data-prop="backgroundImageFit">';
			html += '<option value="contain"' + (currentFit === 'contain' ? ' selected' : '') + '>' + (this.config.i18n.fitContain || 'Contain') + '</option>';
			html += '<option value="cover"' + (currentFit === 'cover' ? ' selected' : '') + '>' + (this.config.i18n.fitCover || 'Cover') + '</option>';
			html += '<option value="stretch"' + (currentFit === 'stretch' ? ' selected' : '') + '>' + (this.config.i18n.fitStretch || 'Stretch') + '</option>';
			html += '<option value="original"' + (currentFit === 'original' ? ' selected' : '') + '>' + (this.config.i18n.fitOriginal || 'Original') + '</option>';
			html += '</select>';
			html += '</div>';
			html += '<div class="saso-prop-group half">';
			html += '<label>' + (this.config.i18n.imageAlign || 'Align') + '</label>';
			html += '<select class="canvas-prop" data-prop="backgroundImageAlign">';
			html += '<option value="top-left"' + (currentAlign === 'top-left' ? ' selected' : '') + '>↖ ' + (this.config.i18n.alignTopLeft || 'Top Left') + '</option>';
			html += '<option value="top"' + (currentAlign === 'top' ? ' selected' : '') + '>↑ ' + (this.config.i18n.alignTop || 'Top') + '</option>';
			html += '<option value="top-right"' + (currentAlign === 'top-right' ? ' selected' : '') + '>↗ ' + (this.config.i18n.alignTopRight || 'Top Right') + '</option>';
			html += '<option value="left"' + (currentAlign === 'left' ? ' selected' : '') + '>← ' + (this.config.i18n.alignLeft || 'Left') + '</option>';
			html += '<option value="center"' + (currentAlign === 'center' ? ' selected' : '') + '>⊙ ' + (this.config.i18n.alignCenter || 'Center') + '</option>';
			html += '<option value="right"' + (currentAlign === 'right' ? ' selected' : '') + '>→ ' + (this.config.i18n.alignRight || 'Right') + '</option>';
			html += '<option value="bottom-left"' + (currentAlign === 'bottom-left' ? ' selected' : '') + '>↙ ' + (this.config.i18n.alignBottomLeft || 'Bottom Left') + '</option>';
			html += '<option value="bottom"' + (currentAlign === 'bottom' ? ' selected' : '') + '>↓ ' + (this.config.i18n.alignBottom || 'Bottom') + '</option>';
			html += '<option value="bottom-right"' + (currentAlign === 'bottom-right' ? ' selected' : '') + '>↘ ' + (this.config.i18n.alignBottomRight || 'Bottom Right') + '</option>';
			html += '</select>';
			html += '</div>';
			html += '</div>';
		}

		html += '<p class="description">' + (this.config.i18n.backgroundImageDesc || 'Add a floor plan or venue layout as background reference.') + '</p>';
		html += '</div>';

		// Seat status colors
		html += '<div class="saso-prop-group">';
		html += '<label><strong>' + (this.config.i18n.seatStatusColors || 'Seat Status Colors') + '</strong></label>';
		html += '<div class="saso-prop-row">';
		html += '<div class="saso-prop-group half">';
		html += '<label>' + (this.config.i18n.colorAvailable || 'Available') + '</label>';
		html += '<input type="color" class="prop-input canvas-prop color-prop" data-prop="colorAvailable" value="' + (this.config.colors.available || '#4CAF50') + '">';
		html += '</div>';
		html += '<div class="saso-prop-group half">';
		html += '<label>' + (this.config.i18n.colorReserved || 'Reserved') + '</label>';
		html += '<input type="color" class="prop-input canvas-prop color-prop" data-prop="colorReserved" value="' + (this.config.colors.reserved || '#FFC107') + '">';
		html += '</div>';
		html += '</div>';
		html += '<div class="saso-prop-row">';
		html += '<div class="saso-prop-group half">';
		html += '<label>' + (this.config.i18n.colorBooked || 'Booked/Sold') + '</label>';
		html += '<input type="color" class="prop-input canvas-prop color-prop" data-prop="colorBooked" value="' + (this.config.colors.booked || '#F44336') + '">';
		html += '</div>';
		html += '<div class="saso-prop-group half">';
		html += '<label>' + (this.config.i18n.colorSelected || 'Selected') + '</label>';
		html += '<input type="color" class="prop-input canvas-prop color-prop" data-prop="colorSelected" value="' + (this.config.colors.selected || '#2196F3') + '">';
		html += '</div>';
		html += '</div>';
		html += '<p class="description">' + (this.config.i18n.seatStatusColorsDesc || 'Colors shown in the frontend seat map.') + '</p>';
		html += '</div>';

		html += '</div>';

		this.$propsPanel.html(html);

		// Bind canvas property changes
		this.$propsPanel.find('.canvas-prop').on('change input', function() {
			var prop = $(this).data('prop');
			var value = $(this).val();

			if ($(this).attr('type') === 'number') {
				value = parseInt(value, 10) || 0;
			}

			self.updateCanvasProperty(prop, value);
		});

		// Bind image buttons
		this.$propsPanel.find('.saso-select-bg-image').on('click', function() {
			self.openBackgroundImageChooser();
		});

		this.$propsPanel.find('.saso-remove-bg-image').on('click', function() {
			self.removeBackgroundImage();
		});

	};

	/**
	 * Update properties panel for multiple selected elements
	 * Shows common properties that can be changed for all selected elements
	 */
	SeatingDesigner.prototype.updatePropertiesPanelMulti = function() {
		var self = this;
		var count = this.selectedElements.length;

		if (count === 0) {
			this.showCanvasProperties();
			return;
		}

		if (count === 1) {
			this.updatePropertiesPanel(this.selectedElements[0]);
			return;
		}

		var html = '';

		// Header showing selection count with highlight
		html += '<div class="saso-multi-select-header">';
		html += '<span class="saso-multi-badge">' + count + '</span>';
		html += '<span>' + (this.config.i18n.elementsSelected || 'elements selected') + '</span>';
		html += '</div>';

		// Get common properties
		var commonX = this.getCommonProperty('x');
		var commonY = this.getCommonProperty('y');
		var commonWidth = this.getCommonProperty('width');
		var commonHeight = this.getCommonProperty('height');
		var commonRotation = this.getCommonProperty('rotation');
		var commonFill = this.getCommonProperty('fill');
		var commonStroke = this.getCommonProperty('stroke');
		var commonFillOpacity = this.getCommonProperty('fillOpacity');
		var commonStrokeOpacity = this.getCommonProperty('strokeOpacity');
		var commonCategory = this.getCommonProperty('category');
		var commonDescription = this.getCommonProperty('seat_desc');
		var commonIsSeat = this.getCommonProperty('isSeat');

		// Position (only if identical)
		if (commonX !== undefined && commonX !== 'mixed') {
			html += '<div class="saso-prop-row">';
			html += '<div class="saso-prop-group half">';
			html += '<label>X</label>';
			html += '<input type="number" class="prop-input multi-prop" data-prop="x" value="' + commonX + '">';
			html += '</div>';
			if (commonY !== undefined && commonY !== 'mixed') {
				html += '<div class="saso-prop-group half">';
				html += '<label>Y</label>';
				html += '<input type="number" class="prop-input multi-prop" data-prop="y" value="' + commonY + '">';
				html += '</div>';
			}
			html += '</div>';
		} else if (commonY !== undefined && commonY !== 'mixed') {
			html += '<div class="saso-prop-group">';
			html += '<label>Y</label>';
			html += '<input type="number" class="prop-input multi-prop" data-prop="y" value="' + commonY + '">';
			html += '</div>';
		}

		// Size (only if identical)
		if ((commonWidth !== undefined && commonWidth !== 'mixed') || (commonHeight !== undefined && commonHeight !== 'mixed')) {
			html += '<div class="saso-prop-row">';
			if (commonWidth !== undefined && commonWidth !== 'mixed') {
				html += '<div class="saso-prop-group half">';
				html += '<label>' + (this.config.i18n.propWidth || 'Width') + '</label>';
				html += '<input type="number" class="prop-input multi-prop" data-prop="width" value="' + commonWidth + '">';
				html += '</div>';
			}
			if (commonHeight !== undefined && commonHeight !== 'mixed') {
				html += '<div class="saso-prop-group half">';
				html += '<label>' + (this.config.i18n.propHeight || 'Height') + '</label>';
				html += '<input type="number" class="prop-input multi-prop" data-prop="height" value="' + commonHeight + '">';
				html += '</div>';
			}
			html += '</div>';
		}

		// Rotation (for shapes only)
		var allShapesForRotation = this.selectedElements.every(function(el) {
			return el.type === 'rect' || el.type === 'circle' || el.type === 'seat';
		});
		if (allShapesForRotation) {
			html += '<div class="saso-prop-group">';
			html += '<label>' + (this.config.i18n.propRotation || 'Rotation') + '</label>';
			html += '<div class="saso-rotation-input">';
			var rotationValue = (commonRotation !== undefined && commonRotation !== 'mixed') ? commonRotation : 0;
			var rotationMixed = commonRotation === 'mixed';
			html += '<input type="number" class="prop-input multi-prop" data-prop="rotation" value="' + rotationValue + '" min="0" max="359" step="1"' + (rotationMixed ? ' placeholder="mixed"' : '') + '>';
			html += '<span class="saso-rotation-unit">°</span>';
			if (rotationMixed) {
				html += '<span class="saso-mixed-indicator">(mixed)</span>';
			}
			html += '<div class="saso-rotation-presets">';
			html += '<button type="button" class="saso-rotation-preset multi-preset" data-angle="0">0°</button>';
			html += '<button type="button" class="saso-rotation-preset multi-preset" data-angle="45">45°</button>';
			html += '<button type="button" class="saso-rotation-preset multi-preset" data-angle="90">90°</button>';
			html += '<button type="button" class="saso-rotation-preset multi-preset" data-angle="180">180°</button>';
			html += '<button type="button" class="saso-rotation-preset multi-preset" data-angle="270">270°</button>';
			html += '</div>';
			html += '</div>';
			html += '</div>';
		}

		// Fill color (if all have fill property)
		if (commonFill !== undefined) {
			html += '<div class="saso-prop-row">';
			html += '<div class="saso-prop-group half">';
			html += '<label>' + (this.config.i18n.propColor || 'Fill') + '</label>';
			html += this.renderColorButton('fill', commonFill || '#cccccc', 'fillOpacity', commonFillOpacity !== undefined ? commonFillOpacity : 100, true);
			html += '</div>';
			// Stroke color
			if (commonStroke !== undefined) {
				html += '<div class="saso-prop-group half">';
				html += '<label>' + (this.config.i18n.propStroke || 'Outline') + '</label>';
				html += this.renderColorButton('stroke', commonStroke || '#333333', 'strokeOpacity', commonStrokeOpacity !== undefined ? commonStrokeOpacity : 0, true);
				html += '</div>';
			}
			html += '</div>';
		} else if (commonStroke !== undefined) {
			html += '<div class="saso-prop-group">';
			html += '<label>' + (this.config.i18n.propStroke || 'Outline') + '</label>';
			html += this.renderColorButton('stroke', commonStroke || '#333333', 'strokeOpacity', commonStrokeOpacity !== undefined ? commonStrokeOpacity : 0, true);
			html += '</div>';
		}

		// Is Seat checkbox - ALWAYS show for shapes (can overwrite)
		var allShapes = this.selectedElements.every(function(el) {
			return el.type === 'rect' || el.type === 'circle';
		});
		if (allShapes) {
			html += '<div class="saso-prop-group">';
			html += '<label class="checkbox-label">';
			var isSeatChecked = commonIsSeat === true;
			var isSeatMixed = commonIsSeat === 'mixed';
			html += '<input type="checkbox" class="prop-input multi-prop" data-prop="isSeat" ' +
				(isSeatChecked ? 'checked' : '') +
				(isSeatMixed ? ' data-mixed="true"' : '') + '>';
			html += ' ' + (this.config.i18n.propIsSeat || 'Is Seat (bookable)');
			if (isSeatMixed) {
				html += ' <span class="saso-mixed-indicator">(mixed)</span>';
			}
			html += '</label>';
			html += '</div>';
		}

		// Category (only if identical)
		if (commonCategory !== undefined && commonCategory !== 'mixed') {
			html += '<div class="saso-prop-group">';
			html += '<label>' + (this.config.i18n.propCategory || 'Category') + '</label>';
			html += '<input type="text" class="prop-input multi-prop" data-prop="category" value="' + (commonCategory || '') + '">';
			html += '</div>';
		}

		// Description (only if identical)
		if (commonDescription !== undefined && commonDescription !== 'mixed') {
			html += '<div class="saso-prop-group">';
			html += '<label>' + (this.config.i18n.propDescription || 'Description') + '</label>';
			html += '<textarea class="prop-input multi-prop" data-prop="seat_desc" rows="2">' + (commonDescription || '') + '</textarea>';
			html += '</div>';
		}

		// Group Rotation (only for shapes)
		if (allShapesForRotation) {
			html += '<div class="saso-prop-group saso-group-rotation">';
			html += '<label>' + (this.config.i18n.rotateGroup || 'Rotate Group') + '</label>';
			html += '<div class="saso-rotation-presets">';
			html += '<button type="button" class="saso-group-rotate-btn" data-angle="-90">↺ 90°</button>';
			html += '<button type="button" class="saso-group-rotate-btn" data-angle="-45">↺ 45°</button>';
			html += '<button type="button" class="saso-group-rotate-btn" data-angle="45">↻ 45°</button>';
			html += '<button type="button" class="saso-group-rotate-btn" data-angle="90">↻ 90°</button>';
			html += '</div>';
			html += '</div>';
		}

		// Actions
		html += '<div class="saso-prop-actions saso-multi-actions">';
		html += '<button type="button" class="button saso-duplicate-selected">';
		html += '<span class="dashicons dashicons-admin-page"></span> ' + (this.config.i18n.duplicateSelected || 'Duplicate All');
		html += '</button>';
		html += '<button type="button" class="button button-link-delete saso-delete-selected">';
		html += '<span class="dashicons dashicons-trash"></span> ' + (this.config.i18n.deleteSelected || 'Delete All');
		html += '</button>';
		html += '</div>';

		// Tip
		html += '<p class="description saso-multi-tip">';
		html += (this.config.i18n.multiSelectTip || 'Shift+Click to add/remove. Press Delete to remove all selected.');
		html += '</p>';

		this.$propsPanel.html(html);

		// Bind property change events for multi-select
		this.$propsPanel.find('.multi-prop').on('change input', function() {
			var prop = $(this).data('prop');
			var value = $(this).attr('type') === 'checkbox' ? $(this).is(':checked') : $(this).val();

			if ($(this).attr('type') === 'number') {
				value = parseFloat(value) || 0;
			}

			self.updateSelectedProperty(prop, value);
		});

		// Bind color buttons for multi-select
		this.bindColorButtons(null);

		// Bind rotation preset buttons for multi-select
		this.$propsPanel.find('.saso-rotation-preset.multi-preset').on('click', function() {
			var angle = parseInt($(this).data('angle')) || 0;
			self.$propsPanel.find('.multi-prop[data-prop="rotation"]').val(angle).trigger('change');
		});

		// Bind group rotation buttons
		this.$propsPanel.find('.saso-group-rotate-btn').on('click', function() {
			var angle = parseInt($(this).data('angle')) || 0;
			self.rotateGroup(angle);
		});

		// Bind duplicate button
		this.$propsPanel.find('.saso-duplicate-selected').on('click', function() {
			self.duplicateSelectedElements();
		});

		// Bind delete button
		this.$propsPanel.find('.saso-delete-selected').on('click', function() {
			self.deleteSelectedElements();
		});
	};

	/**
	 * Duplicate all selected elements
	 */
	SeatingDesigner.prototype.duplicateSelectedElements = function() {
		var self = this;
		var offset = 20;
		var newElements = [];

		this.selectedElements.forEach(function(el) {
			var newEl = self.duplicateElementInternal(el, offset);
			if (newEl) {
				newElements.push(newEl);
			}
		});

		// Select the new elements
		if (newElements.length > 0) {
			this.clearSelection();
			newElements.forEach(function(el) {
				self.addToSelection(el);
			});
			this.updatePropertiesPanelMulti();
			this.markUnsaved();
		}
	};

	/**
	 * Rotate all selected elements as a group around their common center
	 *
	 * @param {number} angle Rotation angle in degrees (positive = clockwise)
	 */
	SeatingDesigner.prototype.rotateGroup = function(angle) {
		if (this.selectedElements.length < 2) return;

		var self = this;

		// Calculate bounding box center
		var minX = Infinity, minY = Infinity;
		var maxX = -Infinity, maxY = -Infinity;

		this.selectedElements.forEach(function(el) {
			var elWidth = el.width || (el.r ? el.r * 2 : 40);
			var elHeight = el.height || (el.r ? el.r * 2 : 40);
			minX = Math.min(minX, el.x);
			minY = Math.min(minY, el.y);
			maxX = Math.max(maxX, el.x + elWidth);
			maxY = Math.max(maxY, el.y + elHeight);
		});

		var centerX = (minX + maxX) / 2;
		var centerY = (minY + maxY) / 2;

		// Convert angle to radians
		var rad = angle * Math.PI / 180;
		var cos = Math.cos(rad);
		var sin = Math.sin(rad);

		// Rotate each element around the group center
		this.selectedElements.forEach(function(el) {
			var elWidth = el.width || (el.r ? el.r * 2 : 40);
			var elHeight = el.height || (el.r ? el.r * 2 : 40);

			// Element center
			var elCenterX = el.x + elWidth / 2;
			var elCenterY = el.y + elHeight / 2;

			// Vector from group center to element center
			var dx = elCenterX - centerX;
			var dy = elCenterY - centerY;

			// Rotate the vector
			var newCenterX = centerX + dx * cos - dy * sin;
			var newCenterY = centerY + dx * sin + dy * cos;

			// Update position (top-left corner)
			el.x = Math.round(newCenterX - elWidth / 2);
			el.y = Math.round(newCenterY - elHeight / 2);

			// Update element's own rotation
			el.rotation = ((el.rotation || 0) + angle) % 360;
			if (el.rotation < 0) el.rotation += 360;

			// Update SVG
			self.updateSvgElement(el);
		});

		this.updatePropertiesPanelMulti();
		this.markUnsaved();
	};

	/**
	 * Internal duplicate helper - creates a copy of an element
	 */
	SeatingDesigner.prototype.duplicateElementInternal = function(element, offset) {
		offset = offset || 20;

		var newElement = JSON.parse(JSON.stringify(element));
		newElement.id = element.type + '_' + this.nextId++;
		newElement.x = (element.x || 0) + offset;
		newElement.y = (element.y || 0) + offset;

		// Clear DB ID for seats (will be created as new)
		if (newElement.dbId) {
			delete newElement.dbId;
		}

		// Make identifier unique for seats
		if (newElement.identifier) {
			newElement.identifier = newElement.identifier + '_copy';
		}

		// Add to appropriate array
		if (element.type === 'line') {
			// Also offset line endpoints
			if (newElement.x2 !== undefined) newElement.x2 += offset;
			if (newElement.y2 !== undefined) newElement.y2 += offset;
			this.elements.lines.push(newElement);
		} else if (element.type === 'text') {
			this.elements.labels.push(newElement);
		} else if (element.isSeat) {
			this.elements.seats.push(newElement);
		} else {
			this.elements.decorations.push(newElement);
		}

		this.renderElement(newElement);
		return newElement;
	};

	/**
	 * Get a property value if it's common across all selected elements
	 * Returns undefined if elements don't all have this property
	 * Returns 'mixed' if values differ
	 *
	 * @param {string} prop Property name
	 * @return {*} Common value, 'mixed', or undefined
	 */
	SeatingDesigner.prototype.getCommonProperty = function(prop) {
		if (this.selectedElements.length === 0) return undefined;

		var firstValue = this.selectedElements[0][prop];
		var allHave = true;
		var allSame = true;

		for (var i = 0; i < this.selectedElements.length; i++) {
			var el = this.selectedElements[i];
			if (el[prop] === undefined) {
				allHave = false;
				break;
			}
			if (el[prop] !== firstValue) {
				allSame = false;
			}
		}

		if (!allHave) return undefined;
		if (!allSame) return 'mixed';
		return firstValue;
	};

	/**
	 * Update property for all selected elements
	 *
	 * @param {string} prop Property name
	 * @param {*} value New value
	 */
	SeatingDesigner.prototype.updateSelectedProperty = function(prop, value) {
		var self = this;

		this.selectedElements.forEach(function(el) {
			if (el[prop] !== undefined || prop === 'fill' || prop === 'stroke' ||
			    prop === 'fillOpacity' || prop === 'strokeOpacity' || prop === 'rotation') {
				el[prop] = value;
				self.updateSvgElement(el);
			}
		});

		this.markUnsaved();
	};

	/**
	 * Update canvas property
	 *
	 * @param {string} prop Property name
	 * @param {*} value New value
	 */
	SeatingDesigner.prototype.updateCanvasProperty = function(prop, value) {
		// Handle color properties separately (stored in colors object)
		var colorMap = {
			colorAvailable: 'available',
			colorReserved: 'reserved',
			colorBooked: 'booked',
			colorSelected: 'selected'
		};

		if (colorMap[prop]) {
			this.config.colors[colorMap[prop]] = value;
			this.markUnsaved();
			return;
		}

		this.config[prop] = value;

		if (prop === 'canvasWidth' || prop === 'canvasHeight') {
			// Update canvas size
			this.$canvas.css({
				width: this.config.canvasWidth + 'px',
				height: this.config.canvasHeight + 'px'
			});
			this.svg.setAttribute('width', this.config.canvasWidth);
			this.svg.setAttribute('height', this.config.canvasHeight);

			// Update background rect
			var bgRect = this.layers.background.querySelector('.saso-background-rect');
			if (bgRect) {
				bgRect.setAttribute('width', this.config.canvasWidth);
				bgRect.setAttribute('height', this.config.canvasHeight);
			}

			// Update background image
			this.updateBackgroundImageDisplay();
		} else if (prop === 'backgroundColor') {
			// Update background color
			this.$canvas.css('backgroundColor', value);
			var bgRect = this.layers.background.querySelector('.saso-background-rect');
			if (bgRect) {
				bgRect.setAttribute('fill', value);
			}
		} else if (prop === 'backgroundImageFit' || prop === 'backgroundImageAlign') {
			// Update background image fit/align
			this.updateBackgroundImageDisplay();
		}

		this.markUnsaved();
	};

	/**
	 * Update background image display based on fit and align settings
	 */
	SeatingDesigner.prototype.updateBackgroundImageDisplay = function() {
		var bgImage = this.layers.background.querySelector('.saso-background-image');
		if (!bgImage) return;

		var fit = this.config.backgroundImageFit || 'contain';
		var align = this.config.backgroundImageAlign || 'center';

		// Map fit mode to SVG preserveAspectRatio
		var aspectRatio;
		switch (fit) {
			case 'contain':
				aspectRatio = this.getPreserveAspectRatio(align, 'meet');
				break;
			case 'cover':
				aspectRatio = this.getPreserveAspectRatio(align, 'slice');
				break;
			case 'stretch':
				aspectRatio = 'none';
				break;
			case 'original':
				aspectRatio = this.getPreserveAspectRatio(align, 'meet');
				// For original, we don't stretch at all - handled below
				break;
			default:
				aspectRatio = 'xMidYMid meet';
		}

		bgImage.setAttribute('preserveAspectRatio', aspectRatio);
		bgImage.setAttribute('width', this.config.canvasWidth);
		bgImage.setAttribute('height', this.config.canvasHeight);
	};

	/**
	 * Get SVG preserveAspectRatio value from alignment
	 */
	SeatingDesigner.prototype.getPreserveAspectRatio = function(align, meetOrSlice) {
		var xAlign, yAlign;
		switch (align) {
			case 'top-left':
				xAlign = 'xMin'; yAlign = 'YMin'; break;
			case 'top':
				xAlign = 'xMid'; yAlign = 'YMin'; break;
			case 'top-right':
				xAlign = 'xMax'; yAlign = 'YMin'; break;
			case 'left':
				xAlign = 'xMin'; yAlign = 'YMid'; break;
			case 'center':
				xAlign = 'xMid'; yAlign = 'YMid'; break;
			case 'right':
				xAlign = 'xMax'; yAlign = 'YMid'; break;
			case 'bottom-left':
				xAlign = 'xMin'; yAlign = 'YMax'; break;
			case 'bottom':
				xAlign = 'xMid'; yAlign = 'YMax'; break;
			case 'bottom-right':
				xAlign = 'xMax'; yAlign = 'YMax'; break;
			default:
				xAlign = 'xMid'; yAlign = 'YMid';
		}
		return xAlign + yAlign + ' ' + meetOrSlice;
	};

	/**
	 * Open background image chooser (WordPress media library)
	 */
	SeatingDesigner.prototype.openBackgroundImageChooser = function() {
		var self = this;

		if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
			alert('Media library not available');
			return;
		}

		var frame = wp.media({
			title: this.config.i18n.selectBackgroundImage || 'Select Background Image',
			multiple: false,
			library: { type: 'image' }
		});

		frame.on('close', function() {
			var selection = frame.state().get('selection');
			if (selection.length === 0) return;

			var attachment = selection.first().toJSON();
			self.setBackgroundImage(attachment.url);
			self.config.backgroundImageId = attachment.id;

			// Refresh properties panel to show the image
			self.showCanvasProperties();
		});

		frame.open();
	};

	/**
	 * Remove background image
	 */
	SeatingDesigner.prototype.removeBackgroundImage = function() {
		this.config.backgroundImage = '';
		this.config.backgroundImageId = 0;

		// Remove from SVG
		var existing = this.layers.background.querySelector('.saso-background-image');
		if (existing) {
			existing.remove();
		}

		// Refresh properties panel
		this.showCanvasProperties();
		this.markUnsaved();
	};

	/**
	 * Duplicate an element
	 *
	 * @param {string} id Element ID to duplicate
	 */
	SeatingDesigner.prototype.duplicateElement = function(id) {
		var element = this.findElement(id);
		if (!element) return;

		// Create a copy with new ID
		var newId;
		var copy = JSON.parse(JSON.stringify(element));

		// Offset position
		copy.x = (copy.x || 0) + 20;
		copy.y = (copy.y || 0) + 20;

		if (element.isSeat) {
			var seatNum = this.elements.seats.length + 1;
			newId = 'seat_new_' + this.nextId++;
			copy.id = newId;
			copy.identifier = 'SEAT-' + seatNum;
			copy.label = 'Seat ' + seatNum;
			copy.dbId = null; // New seat, no DB ID yet
			this.elements.seats.push(copy);
		} else if (element.type === 'line') {
			newId = 'line_' + this.nextId++;
			copy.id = newId;
			if (copy.x1 !== undefined) {
				copy.x1 += 20;
				copy.y1 += 20;
				copy.x2 += 20;
				copy.y2 += 20;
			}
			this.elements.lines.push(copy);
		} else if (element.type === 'text') {
			newId = 'label_' + this.nextId++;
			copy.id = newId;
			this.elements.labels.push(copy);
		} else {
			newId = 'shape_' + this.nextId++;
			copy.id = newId;
			this.elements.decorations.push(copy);
		}

		this.renderElement(copy);
		this.selectElement(newId);
		this.markUnsaved();
	};

	// =========================================================================
	// Actions Panel
	// =========================================================================

	/**
	 * Create actions panel (Save, Publish, Discard)
	 */
	SeatingDesigner.prototype.createActionsPanel = function() {
		var self = this;
		var $container = $(this.config.container);

		var html = '<div class="saso-designer-actions-wrap">';

		// Element counts
		html += '<div class="saso-element-counts">';
		html += '<span class="count-item count-seats"><span class="dashicons dashicons-tickets-alt"></span> <span class="count-value">0</span> ' + (this.config.i18n.seats || 'Seats') + '</span>';
		html += '<span class="count-item count-elements"><span class="dashicons dashicons-layout"></span> <span class="count-value">0</span> ' + (this.config.i18n.elements || 'Elements') + '</span>';
		html += '</div>';

		// Action buttons (Publish is in the unpublished-changes banner)
		html += '<div class="saso-designer-actions">';
		html += '<label class="saso-sync-option"><input type="checkbox" class="saso-sync-to-pub-checkbox"> ';
		html += (this.config.i18n.syncToPubData || 'Sync seats to DB');
		html += '</label>';
		html += '<button type="button" class="button saso-save-draft">';
		html += '<span class="dashicons dashicons-saved"></span> ';
		html += (this.config.i18n.saveDraft || 'Save Draft');
		html += '</button>';
		html += '<button type="button" class="button saso-discard-draft">';
		html += (this.config.i18n.discardDraft || 'Discard');
		html += '</button>';
		html += '</div>';

		html += '</div>';

		$container.find('.saso-designer-actions-area').html(html);
		this.updateElementCounts();
	};

	/**
	 * Update element counts in actions panel
	 */
	SeatingDesigner.prototype.updateElementCounts = function() {
		var $container = $(this.config.container);
		var seatCount = this.elements.seats.length;
		var elementCount = this.elements.decorations.length + this.elements.lines.length + this.elements.labels.length;

		$container.find('.count-seats .count-value').text(seatCount);
		$container.find('.count-elements .count-value').text(elementCount);
	};

	// =========================================================================
	// Event Binding
	// =========================================================================

	/**
	 * Bind canvas-specific events (called after canvas creation)
	 */
	SeatingDesigner.prototype.bindCanvasEvents = function() {
		var self = this;

		if (!this.svg) return;

		// Canvas events - use native listeners for SVG
		this.svg.addEventListener('mousedown', function(e) {
			self.handleCanvasMouseDown(e);
		});

		this.svg.addEventListener('mousemove', function(e) {
			self.handleCanvasMouseMove(e);
		});

		this.svg.addEventListener('mouseup', function(e) {
			self.handleCanvasMouseUp(e);
		});

		// Mouse leave - end any dragging/panning
		this.svg.addEventListener('mouseleave', function(e) {
			if (self.isPanning) {
				self.endPan();
			}
		});

		// Wheel event for zoom
		this.svg.addEventListener('wheel', function(e) {
			e.preventDefault();
			var rect = self.svg.getBoundingClientRect();
			var screenPos = {
				x: e.clientX - rect.left,
				y: e.clientY - rect.top
			};
			var delta = e.deltaY < 0 ? 1 : -1;
			self.zoomAtPoint(delta, screenPos);
		}, { passive: false });

		// Context menu prevention (for right-click)
		this.svg.addEventListener('contextmenu', function(e) {
			e.preventDefault();
		});
	};

	/**
	 * Bind all events
	 */
	SeatingDesigner.prototype.bindEvents = function() {
		var self = this;
		var $container = $(this.config.container);

		// Tool selection
		$container.on('click', '.saso-tool-btn', function(e) {
			e.preventDefault();
			var tool = $(this).data('tool');
			// Delete is an action, not a tool mode
			if (tool === 'delete') {
				if (self.selectedElements.length > 0) {
					self.deleteSelectedElements();
				} else {
					self.showNotice(self.config.i18n.noElementsSelected || 'No elements selected to delete', 'warning');
				}
				return;
			}
			self.setTool(tool);
		});

		// Grid snap toggle
		$container.on('change', '.saso-grid-snap input', function() {
			self.config.snapToGrid = $(this).is(':checked');
		});

		// Help button
		$container.on('click', '.saso-help-btn', function(e) {
			e.preventDefault();
			self.showHelpModal();
		});

		// Note: Canvas events (mousedown/move/up) are bound in createCanvas()
		// via bindCanvasEvents() - this ensures they're rebound when canvas is recreated

		// Element click (delegated, so survives canvas recreation)
		$container.on('click', '.saso-element', function(e) {
			e.stopPropagation();
			// Ignore click if we just finished dragging (click fires after mouseup)
			if (self.justFinishedDragging) {
				self.justFinishedDragging = false;
				return;
			}
			var id = $(this).data('id');
			self.selectElement(id);
		});

		// Action buttons (Publish is now in the unpublished-changes banner)
		$container.on('click', '.saso-save-draft', function() {
			self.saveDraft();
		});

		$container.on('click', '.saso-discard-draft', function() {
			self.discardDraft();
		});

		$container.on('click', '.saso-preview', function() {
			self.preview();
		});

		// Back to plans button
		$container.on('click', '.saso-back-to-plans', function() {
			if (self.hasUnsavedChanges) {
				if (!confirm(self.config.i18n.unsavedChanges || 'You have unsaved changes. Are you sure you want to leave?')) {
					return;
				}
			}
			if (typeof window.sasoSeatingCloseDesigner === 'function') {
				window.sasoSeatingCloseDesigner();
			}
		});

		// Keyboard shortcuts (namespaced to allow removal)
		$(document).on('keydown.sasoSeatingDesigner', function(e) {
			if (!self.svg) return;

			// Delete key - delete all selected elements
			if (e.key === 'Delete' || e.key === 'Backspace') {
				if (self.selectedElements.length > 0 && !$(e.target).is('input, textarea')) {
					e.preventDefault();
					self.deleteSelectedElements();
				}
			}

			// Escape - deselect all
			if (e.key === 'Escape') {
				self.deselectAll();
			}

			// Ctrl+A - select all
			if (e.key === 'a' && (e.ctrlKey || e.metaKey)) {
				if (!$(e.target).is('input, textarea')) {
					e.preventDefault();
					self.selectAll();
				}
			}

			// Ctrl+S - save
			if (e.key === 's' && (e.ctrlKey || e.metaKey)) {
				e.preventDefault();
				self.saveDraft();
			}

			// + / = - zoom in
			if ((e.key === '+' || e.key === '=') && !$(e.target).is('input, textarea')) {
				e.preventDefault();
				self.zoomIn();
			}

			// - - zoom out
			if (e.key === '-' && !$(e.target).is('input, textarea')) {
				e.preventDefault();
				self.zoomOut();
			}

			// 0 - reset zoom
			if (e.key === '0' && !$(e.target).is('input, textarea')) {
				e.preventDefault();
				self.resetZoom();
			}

			// Space key - enable pan mode
			if (e.key === ' ' && !$(e.target).is('input, textarea')) {
				e.preventDefault();
				if (!self.spaceKeyHeld) {
					self.spaceKeyHeld = true;
					$(self.svg).css('cursor', 'grab');
				}
			}
		});

		// Space key release - disable pan mode
		$(document).on('keyup.sasoSeatingDesigner', function(e) {
			if (e.key === ' ') {
				self.spaceKeyHeld = false;
				if (!self.isPanning) {
					// Restore cursor based on current tool
					self.setTool(self.currentTool);
				}
			}
		});

		// Zoom button handlers
		$container.on('click', '.saso-zoom-in', function(e) {
			e.preventDefault();
			self.zoomIn();
		});

		$container.on('click', '.saso-zoom-out', function(e) {
			e.preventDefault();
			self.zoomOut();
		});

		$container.on('click', '.saso-fit-view', function(e) {
			e.preventDefault();
			self.fitToView();
		});

		$container.on('click', '.saso-reset-zoom', function(e) {
			e.preventDefault();
			self.resetZoom();
		});
	};

	// =========================================================================
	// Tool Management
	// =========================================================================

	/**
	 * Set current tool
	 *
	 * @param {string} tool Tool ID
	 */
	SeatingDesigner.prototype.setTool = function(tool) {
		this.currentTool = tool;

		// Update toolbar UI
		$(this.config.container).find('.saso-tool-btn').removeClass('active');
		$(this.config.container).find('.saso-tool-btn[data-tool="' + tool + '"]').addClass('active');

		// Update cursor
		var cursor = 'default';
		switch (tool) {
			case 'select':
				cursor = 'default';
				break;
			case 'seat':
			case 'rect':
			case 'circle':
			case 'text':
			case 'line':
			case 'row':
				cursor = 'crosshair';
				break;
		}
		$(this.svg).css('cursor', cursor);

		// Reset line drawing state
		if (tool !== 'line') {
			this.isDrawingLine = false;
			this.lineStart = null;
		}

		// Reset bulk insert state
		if (tool !== 'row') {
			this.clearBulkInsertPreview();
			this.bulkInsertStart = null;
		}
	};

	// =========================================================================
	// Zoom and Pan
	// =========================================================================

	/**
	 * Update the SVG viewBox based on current zoom and pan
	 */
	SeatingDesigner.prototype.updateViewBox = function() {
		var width = this.config.canvasWidth / this.zoom;
		var height = this.config.canvasHeight / this.zoom;

		// Ensure pan stays within reasonable bounds
		var maxPanX = this.config.canvasWidth - width;
		var maxPanY = this.config.canvasHeight - height;

		// Allow panning past edges when zoomed in
		if (this.zoom > 1) {
			this.pan.x = Math.max(0, Math.min(maxPanX, this.pan.x));
			this.pan.y = Math.max(0, Math.min(maxPanY, this.pan.y));
		} else {
			// When zoomed out, center the content
			this.pan.x = (this.config.canvasWidth - width) / 2;
			this.pan.y = (this.config.canvasHeight - height) / 2;
		}

		var viewBox = this.pan.x + ' ' + this.pan.y + ' ' + width + ' ' + height;
		this.svg.setAttribute('viewBox', viewBox);

		// Update zoom level display
		$(this.config.container).find('.saso-zoom-level').text(Math.round(this.zoom * 100) + '%');
	};

	/**
	 * Set zoom level
	 *
	 * @param {number} level Zoom level (1 = 100%)
	 */
	SeatingDesigner.prototype.setZoom = function(level) {
		this.zoom = Math.max(this.minZoom, Math.min(this.maxZoom, level));
		this.updateViewBox();
	};

	/**
	 * Zoom in by a fixed step
	 */
	SeatingDesigner.prototype.zoomIn = function() {
		var step = this.zoom < 1 ? 0.25 : 0.5;
		this.setZoom(this.zoom + step);
	};

	/**
	 * Zoom out by a fixed step
	 */
	SeatingDesigner.prototype.zoomOut = function() {
		var step = this.zoom <= 1 ? 0.25 : 0.5;
		this.setZoom(this.zoom - step);
	};

	/**
	 * Reset zoom to 100%
	 */
	SeatingDesigner.prototype.resetZoom = function() {
		this.zoom = 1;
		this.pan = { x: 0, y: 0 };
		this.updateViewBox();
	};

	/**
	 * Zoom at a specific point (for mouse wheel zoom)
	 *
	 * @param {number} delta Zoom delta (positive = zoom in, negative = zoom out)
	 * @param {Object} point {x, y} point to zoom at (canvas coordinates)
	 */
	SeatingDesigner.prototype.zoomAtPoint = function(delta, point) {
		var oldZoom = this.zoom;
		var factor = delta > 0 ? 1.1 : 0.9;
		var newZoom = Math.max(this.minZoom, Math.min(this.maxZoom, oldZoom * factor));

		if (newZoom === oldZoom) return;

		// Calculate the point in viewBox coordinates before zoom
		var viewBoxX = this.pan.x + point.x / oldZoom;
		var viewBoxY = this.pan.y + point.y / oldZoom;

		// Update zoom
		this.zoom = newZoom;

		// Adjust pan so the point under the cursor stays in the same place
		this.pan.x = viewBoxX - point.x / newZoom;
		this.pan.y = viewBoxY - point.y / newZoom;

		this.updateViewBox();
	};

	/**
	 * Fit all content to view
	 */
	SeatingDesigner.prototype.fitToView = function() {
		// Calculate bounding box of all elements
		var bounds = this.getContentBounds();

		if (!bounds) {
			// No content, reset to default
			this.resetZoom();
			return;
		}

		// Add some padding
		var padding = 40;
		bounds.minX -= padding;
		bounds.minY -= padding;
		bounds.maxX += padding;
		bounds.maxY += padding;

		var contentWidth = bounds.maxX - bounds.minX;
		var contentHeight = bounds.maxY - bounds.minY;

		// Calculate zoom to fit content
		var zoomX = this.config.canvasWidth / contentWidth;
		var zoomY = this.config.canvasHeight / contentHeight;
		var fitZoom = Math.min(zoomX, zoomY, this.maxZoom);

		// Calculate pan to center content
		var viewWidth = this.config.canvasWidth / fitZoom;
		var viewHeight = this.config.canvasHeight / fitZoom;
		var centerX = (bounds.minX + bounds.maxX) / 2;
		var centerY = (bounds.minY + bounds.maxY) / 2;

		this.zoom = fitZoom;
		this.pan.x = centerX - viewWidth / 2;
		this.pan.y = centerY - viewHeight / 2;

		this.updateViewBox();
	};

	/**
	 * Get bounding box of all content
	 *
	 * @return {Object|null} {minX, minY, maxX, maxY} or null if no content
	 */
	SeatingDesigner.prototype.getContentBounds = function() {
		var bounds = null;

		var updateBounds = function(x, y, w, h) {
			w = w || 0;
			h = h || 0;
			if (!bounds) {
				bounds = { minX: x, minY: y, maxX: x + w, maxY: y + h };
			} else {
				bounds.minX = Math.min(bounds.minX, x);
				bounds.minY = Math.min(bounds.minY, y);
				bounds.maxX = Math.max(bounds.maxX, x + w);
				bounds.maxY = Math.max(bounds.maxY, y + h);
			}
		};

		// Check all element types
		var allElements = [].concat(
			this.elements.seats,
			this.elements.decorations,
			this.elements.labels
		);

		allElements.forEach(function(el) {
			if (el.type === 'circle') {
				updateBounds(el.x - el.r, el.y - el.r, el.r * 2, el.r * 2);
			} else if (el.type === 'text') {
				// Approximate text bounds
				updateBounds(el.x, el.y - 20, 100, 30);
			} else {
				updateBounds(el.x, el.y, el.width || 40, el.height || 40);
			}
		});

		// Check lines
		this.elements.lines.forEach(function(line) {
			updateBounds(Math.min(line.x1, line.x2), Math.min(line.y1, line.y2),
				Math.abs(line.x2 - line.x1), Math.abs(line.y2 - line.y1));
		});

		return bounds;
	};

	/**
	 * Start panning
	 *
	 * @param {Object} screenPos {x, y} screen position
	 */
	SeatingDesigner.prototype.startPan = function(screenPos) {
		this.isPanning = true;
		this.panStart = {
			x: screenPos.x,
			y: screenPos.y,
			panX: this.pan.x,
			panY: this.pan.y
		};
		$(this.svg).addClass('panning');
	};

	/**
	 * Handle panning during mouse move
	 *
	 * @param {Object} screenPos {x, y} screen position
	 */
	SeatingDesigner.prototype.handlePan = function(screenPos) {
		if (!this.isPanning) return;

		// Calculate delta in screen coordinates, then convert to viewBox coordinates
		var deltaX = (screenPos.x - this.panStart.x) / this.zoom;
		var deltaY = (screenPos.y - this.panStart.y) / this.zoom;

		this.pan.x = this.panStart.panX - deltaX;
		this.pan.y = this.panStart.panY - deltaY;

		this.updateViewBox();
	};

	/**
	 * End panning
	 */
	SeatingDesigner.prototype.endPan = function() {
		this.isPanning = false;
		$(this.svg).removeClass('panning');
		// Restore cursor based on current tool (or grab if space still held)
		if (this.spaceKeyHeld) {
			$(this.svg).css('cursor', 'grab');
		} else {
			this.setTool(this.currentTool);
		}
	};

	// =========================================================================
	// Canvas Event Handlers
	// =========================================================================

	/**
	 * Handle mouse down on canvas
	 *
	 * @param {Event} e Mouse event
	 */
	SeatingDesigner.prototype.handleCanvasMouseDown = function(e) {
		// Middle mouse (button === 1), right-click (button === 2), or Space+Click - start panning
		if (e.button === 1 || e.button === 2 || (this.spaceKeyHeld && e.button === 0)) {
			e.preventDefault();
			var rect = this.svg.getBoundingClientRect();
			this.startPan({
				x: e.clientX - rect.left,
				y: e.clientY - rect.top
			});
			return;
		}

		var pos = this.getCanvasPosition(e);
		var target = e.target;
		var $el = null;
		var clickedOnElement = false;

		// Check if clicking on a resize handle
		if ($(target).hasClass('saso-resize-handle')) {
			var handlePos = $(target).data('handle');
			this.startResize(handlePos, pos);
			return;
		}

		// Check if clicking on an existing element
		if ($(target).hasClass('saso-element') || $(target).closest('.saso-element').length) {
			$el = $(target).hasClass('saso-element') ? $(target) : $(target).closest('.saso-element');
			clickedOnElement = true;
		}

		// If clicking on element with any tool except delete: select it and switch to select tool
		if (clickedOnElement && this.currentTool !== 'delete' && this.currentTool !== 'select') {
			var id = $el.data('id');
			this.setTool('select');
			// If already selected, keep selection; otherwise select this element
			if (!this.isSelected(id)) {
				this.selectElement(id, e.shiftKey);
			}
			this.startDrag(pos);
			return;
		}

		switch (this.currentTool) {
			case 'select':
				if (clickedOnElement) {
					var id = $el.data('id');
					// If element is already selected and no shift: keep selection, just start drag
					if (this.isSelected(id) && !e.shiftKey) {
						// Already selected - start dragging all selected elements
						this.startDrag(pos);
					} else {
						// Select (or toggle with shift) and start drag
						this.selectElement(id, e.shiftKey);
						this.startDrag(pos);
					}
				} else if (!e.shiftKey) {
					// Start marquee selection on empty canvas (only if not Shift+Click)
					this.startMarqueeSelection(pos);
				} else {
					// Shift+Click on empty area - do nothing (keep selection)
				}
				break;

			case 'seat':
				this.addSeat(pos.x, pos.y);
				break;

			case 'rect':
				this.addShape('rect', pos.x, pos.y);
				break;

			case 'circle':
				this.addShape('circle', pos.x, pos.y);
				break;

			case 'line':
				if (!this.isDrawingLine) {
					this.lineStart = pos;
					this.isDrawingLine = true;
				} else {
					this.addLine(this.lineStart.x, this.lineStart.y, pos.x, pos.y);
					this.isDrawingLine = false;
					this.lineStart = null;
				}
				break;

			case 'text':
				this.addText(pos.x, pos.y);
				break;

			case 'row':
				this.showBulkInsertModal(pos.x, pos.y);
				break;
		}
	};

	/**
	 * Handle mouse move on canvas
	 *
	 * @param {Event} e Mouse event
	 */
	SeatingDesigner.prototype.handleCanvasMouseMove = function(e) {
		// Handle panning (check first, uses screen coordinates)
		if (this.isPanning) {
			var rect = this.svg.getBoundingClientRect();
			this.handlePan({
				x: e.clientX - rect.left,
				y: e.clientY - rect.top
			});
			return;
		}

		var pos = this.getCanvasPosition(e);

		// Handle resizing
		if (this.isResizing) {
			this.handleResize(pos);
			return;
		}

		// Handle marquee selection
		if (this.isMarqueeSelecting) {
			this.updateMarquee(pos);
			return;
		}

		// Handle dragging (multi or single)
		if (this.isDragging && this.selectedElements.length > 0) {
			this.dragElements(pos);
		}
	};

	/**
	 * Handle mouse up on canvas
	 *
	 * @param {Event} e Mouse event
	 */
	SeatingDesigner.prototype.handleCanvasMouseUp = function(e) {
		if (this.isPanning) {
			this.endPan();
		}
		if (this.isResizing) {
			this.endResize();
		}
		if (this.isMarqueeSelecting) {
			this.endMarqueeSelection(e.shiftKey);
		}
		if (this.isDragging) {
			this.endDrag();
		}
	};

	/**
	 * Get position relative to canvas (accounting for zoom/pan)
	 *
	 * @param {Event} e Mouse event
	 * @return {Object} {x, y} in SVG coordinate space
	 */
	SeatingDesigner.prototype.getCanvasPosition = function(e) {
		var rect = this.svg.getBoundingClientRect();

		// Screen position relative to SVG element
		var screenX = e.clientX - rect.left;
		var screenY = e.clientY - rect.top;

		// Convert to SVG coordinate space (accounting for viewBox)
		// Screen coordinates map to viewBox coordinates
		var viewBoxWidth = this.config.canvasWidth / this.zoom;
		var viewBoxHeight = this.config.canvasHeight / this.zoom;

		// Scale factor from screen to viewBox
		var scaleX = viewBoxWidth / rect.width;
		var scaleY = viewBoxHeight / rect.height;

		// Convert screen position to viewBox position
		var x = this.pan.x + (screenX * scaleX);
		var y = this.pan.y + (screenY * scaleY);

		// Snap to grid
		if (this.config.snapToGrid) {
			x = Math.round(x / this.config.gridSize) * this.config.gridSize;
			y = Math.round(y / this.config.gridSize) * this.config.gridSize;
		}

		return { x: x, y: y };
	};

	// =========================================================================
	// Element Creation
	// =========================================================================

	/**
	 * Add a shape (rect or circle)
	 *
	 * @param {string} type 'rect' or 'circle'
	 * @param {number} x X position
	 * @param {number} y Y position
	 */
	SeatingDesigner.prototype.addShape = function(type, x, y) {
		var id = 'shape_' + this.nextId++;
		var shapeNum = this.elements.decorations.length + 1;
		var element = {
			id: id,
			type: type,
			x: x,
			y: y,
			rotation: 0,
			width: 40,
			height: 40,
			r: 20,
			fill: '#cccccc',
			fillOpacity: 100,
			stroke: '#333333',
			strokeOpacity: 0,
			isSeat: false,
			identifier: '',
			label: type === 'rect' ? 'Rect ' + shapeNum : 'Circle ' + shapeNum,
			labelColor: '#333333',
			labelColorOpacity: 100,
			labelStroke: '#ffffff',
			labelStrokeOpacity: 0,
			category: '',
			description: ''
		};

		this.elements.decorations.push(element);
		this.renderElement(element);
		this.selectElement(id);
		this.markUnsaved();
	};

	/**
	 * Add a seat directly (dedicated seat tool)
	 *
	 * @param {number} x X position
	 * @param {number} y Y position
	 */
	SeatingDesigner.prototype.addSeat = function(x, y) {
		var seatNum = this.elements.seats.length + 1;
		var id = 'seat_new_' + this.nextId++;

		var element = {
			id: id,
			type: 'rect',
			x: x,
			y: y,
			rotation: 0,
			width: 30,
			height: 30,
			fill: this.config.colors.available,
			fillOpacity: 100,
			stroke: '#333333',
			strokeOpacity: 0,
			isSeat: true,
			identifier: 'SEAT-' + seatNum,
			label: 'Seat ' + seatNum,
			labelColor: '#333333',
			labelColorOpacity: 100,
			labelStroke: '#ffffff',
			labelStrokeOpacity: 0,
			category: '',
			seat_desc: ''
		};

		this.elements.seats.push(element);
		this.renderElement(element);
		this.selectElement(id);
		this.markUnsaved();
	};

	/**
	 * Add a line
	 *
	 * @param {number} x1 Start X
	 * @param {number} y1 Start Y
	 * @param {number} x2 End X
	 * @param {number} y2 End Y
	 */
	SeatingDesigner.prototype.addLine = function(x1, y1, x2, y2) {
		var id = 'line_' + this.nextId++;
		var element = {
			id: id,
			type: 'line',
			x1: x1,
			y1: y1,
			x2: x2,
			y2: y2,
			x: x1,
			y: y1,
			stroke: '#333333',
			strokeWidth: 2,
			label: '',
			description: ''
		};

		this.elements.lines.push(element);
		this.renderElement(element);
		this.selectElement(id);
		this.markUnsaved();
	};

	/**
	 * Add a text label
	 *
	 * @param {number} x X position
	 * @param {number} y Y position
	 */
	SeatingDesigner.prototype.addText = function(x, y) {
		var id = 'label_' + this.nextId++;
		var text = prompt(this.config.i18n.enterText || 'Enter text:', 'Label');
		if (!text) return;

		var element = {
			id: id,
			type: 'text',
			x: x,
			y: y,
			text: text,
			fontSize: 14,
			fill: '#333333',
			fontWeight: 'normal',
			description: ''
		};

		this.elements.labels.push(element);
		this.renderElement(element);
		this.selectElement(id);
		this.markUnsaved();
	};

	// =========================================================================
	// Element Rendering
	// =========================================================================

	/**
	 * Apply rotation transform to SVG element
	 *
	 * @param {SVGElement} svgEl SVG element
	 * @param {Object} element Element data
	 */
	SeatingDesigner.prototype.applyRotation = function(svgEl, element) {
		var rotation = parseInt(element.rotation) || 0;
		if (rotation !== 0) {
			var cx, cy;
			if (element.type === 'circle') {
				cx = element.x + (element.r || 20);
				cy = element.y + (element.r || 20);
			} else {
				cx = element.x + (element.width || 40) / 2;
				cy = element.y + (element.height || 40) / 2;
			}
			svgEl.setAttribute('transform', 'rotate(' + rotation + ', ' + cx + ', ' + cy + ')');
		} else {
			svgEl.removeAttribute('transform');
		}
	};

	/**
	 * Render a single element
	 *
	 * @param {Object} element Element data
	 */
	SeatingDesigner.prototype.renderElement = function(element) {
		var svgNS = 'http://www.w3.org/2000/svg';
		var el;
		var labelText = null;

		// Get opacity values
		var fillOpacity = element.fillOpacity !== undefined ? element.fillOpacity / 100 : 1;
		var strokeOpacity = element.strokeOpacity !== undefined ? element.strokeOpacity / 100 : 0;

		switch (element.type) {
			case 'rect':
			case 'seat':
				el = document.createElementNS(svgNS, 'rect');
				el.setAttribute('x', element.x);
				el.setAttribute('y', element.y);
				el.setAttribute('width', element.width || 40);
				el.setAttribute('height', element.height || 40);
				el.setAttribute('fill', element.fill || '#cccccc');
				el.setAttribute('fill-opacity', fillOpacity);
				el.setAttribute('stroke', element.stroke || '#333333');
				el.setAttribute('stroke-opacity', strokeOpacity);
				el.setAttribute('stroke-width', strokeOpacity > 0 ? 2 : 0);
				el.setAttribute('rx', 3);
				// Get label - for seats: label or identifier, for shapes: label
				if (element.isSeat) {
					labelText = element.label || element.identifier || '';
				} else {
					labelText = element.label || '';
				}
				break;

			case 'circle':
				el = document.createElementNS(svgNS, 'circle');
				el.setAttribute('cx', element.x + (element.r || 20));
				el.setAttribute('cy', element.y + (element.r || 20));
				el.setAttribute('r', element.r || 20);
				el.setAttribute('fill', element.fill || '#cccccc');
				el.setAttribute('fill-opacity', fillOpacity);
				el.setAttribute('stroke', element.stroke || '#333333');
				el.setAttribute('stroke-opacity', strokeOpacity);
				el.setAttribute('stroke-width', strokeOpacity > 0 ? 2 : 0);
				// Get label - for seats: label or identifier, for shapes: label
				if (element.isSeat) {
					labelText = element.label || element.identifier || '';
				} else {
					labelText = element.label || '';
				}
				break;

			case 'line':
				var lineStrokeOpacity = element.strokeOpacity !== undefined ? element.strokeOpacity / 100 : 1;
				el = document.createElementNS(svgNS, 'line');
				el.setAttribute('x1', element.x1);
				el.setAttribute('y1', element.y1);
				el.setAttribute('x2', element.x2);
				el.setAttribute('y2', element.y2);
				el.setAttribute('stroke', element.stroke || '#333333');
				el.setAttribute('stroke-opacity', lineStrokeOpacity);
				el.setAttribute('stroke-width', element.strokeWidth || 2);
				el.setAttribute('stroke-linecap', 'round');
				// Lines can have a label at midpoint
				labelText = element.label || '';
				break;

			case 'text':
				el = document.createElementNS(svgNS, 'text');
				el.setAttribute('x', element.x);
				el.setAttribute('y', element.y);
				el.setAttribute('fill', element.fill || '#333333');
				el.setAttribute('fill-opacity', fillOpacity);
				el.setAttribute('font-size', element.fontSize || 14);
				el.setAttribute('font-weight', element.fontWeight || 'normal');
				el.textContent = element.text || '';
				// Text elements don't need an extra label
				break;
		}

		if (el) {
			el.setAttribute('class', 'saso-element' + (element.isSeat ? ' saso-seat' : ''));
			el.setAttribute('data-id', element.id);
			el.setAttribute('data-type', element.type);

			// Apply rotation for shapes
			if (element.type === 'rect' || element.type === 'circle' || element.type === 'seat') {
				this.applyRotation(el, element);
			}

			// Add description as title (native tooltip)
			var descText = element.seat_desc || element.description || '';
			if (descText) {
				var title = document.createElementNS(svgNS, 'title');
				title.textContent = descText;
				el.appendChild(title);
			}

			// Add to appropriate layer
			var layer = this.getLayerForElement(element);
			this.layers[layer].appendChild(el);

			// Add label text on element (not for text type - it already has text)
			if (labelText && element.type !== 'text') {
				this.renderElementLabel(element, labelText);
			}
		}
	};

	/**
	 * Render label text on an element
	 *
	 * @param {Object} element Element data
	 * @param {string} text Label text
	 */
	SeatingDesigner.prototype.renderElementLabel = function(element, text) {
		var svgNS = 'http://www.w3.org/2000/svg';
		var label = document.createElementNS(svgNS, 'text');

		// Calculate center position based on element type
		var centerX, centerY;
		var fillColor = '#333333';
		var strokeColor = '#ffffff';
		var fontSize = '10';

		if (element.type === 'circle') {
			centerX = element.x + (element.r || 20);
			centerY = element.y + (element.r || 20);
		} else if (element.type === 'line') {
			// Line: label at midpoint
			centerX = (element.x1 + element.x2) / 2;
			centerY = (element.y1 + element.y2) / 2;
			fillColor = element.stroke || '#333333';
			strokeColor = '#ffffff';
			fontSize = '9';
		} else {
			// rect
			centerX = element.x + (element.width || 40) / 2;
			centerY = element.y + (element.height || 40) / 2;
		}

		// Use custom label colors if set, otherwise auto-detect based on background
		if (element.labelColor || element.labelStroke) {
			fillColor = element.labelColor || '#333333';
			strokeColor = element.labelStroke || '#ffffff';
		} else if (element.type !== 'line') {
			// Auto-detect: dark text with light outline on light backgrounds, vice versa
			var fill = element.fill || '#cccccc';
			if (this.isLightColor(fill)) {
				fillColor = '#333333';
				strokeColor = '#ffffff';
			} else {
				fillColor = '#ffffff';
				strokeColor = '#333333';
			}
		}

		// Get opacity values (default 100%)
		var fillOpacity = element.labelColorOpacity !== undefined ? element.labelColorOpacity / 100 : 1;
		var strokeOpacity = element.labelStrokeOpacity !== undefined ? element.labelStrokeOpacity / 100 : 1;

		label.setAttribute('x', centerX);
		label.setAttribute('y', centerY);
		label.setAttribute('text-anchor', 'middle');
		label.setAttribute('dominant-baseline', 'middle');
		label.setAttribute('fill', fillColor);
		label.setAttribute('fill-opacity', fillOpacity);
		label.setAttribute('stroke', strokeColor);
		label.setAttribute('stroke-opacity', strokeOpacity);
		label.setAttribute('stroke-width', '2');
		label.setAttribute('paint-order', 'stroke'); // Stroke behind fill
		label.setAttribute('font-size', fontSize);
		label.setAttribute('font-weight', 'bold');
		label.setAttribute('pointer-events', 'none'); // Don't capture clicks
		label.setAttribute('class', 'saso-element-label');
		label.setAttribute('data-for', element.id);
		label.textContent = text;

		// Add to labels layer (on top)
		this.layers.labels.appendChild(label);
	};

	/**
	 * Check if a color is light (for determining text contrast)
	 *
	 * @param {string} color Hex color
	 * @return {boolean} True if light
	 */
	SeatingDesigner.prototype.isLightColor = function(color) {
		var hex = color.replace('#', '');
		if (hex.length === 3) {
			hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
		}
		var r = parseInt(hex.substr(0, 2), 16);
		var g = parseInt(hex.substr(2, 2), 16);
		var b = parseInt(hex.substr(4, 2), 16);
		// Calculate relative luminance
		var luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
		return luminance > 0.5;
	};

	/**
	 * Get layer name for element type
	 *
	 * @param {Object} element Element data
	 * @return {string} Layer name
	 */
	SeatingDesigner.prototype.getLayerForElement = function(element) {
		if (element.type === 'line') return 'lines';
		if (element.type === 'text') return 'labels';
		if (element.isSeat || element.type === 'seat') return 'seats';
		return 'decorations';
	};

	/**
	 * Render all elements
	 */
	SeatingDesigner.prototype.renderAllElements = function() {
		var self = this;

		// Clear layers (except background)
		['lines', 'decorations', 'seats', 'labels'].forEach(function(layer) {
			self.layers[layer].innerHTML = '';
		});

		// Render in order (labels are rendered as part of seats, text labels come last)
		this.elements.lines.forEach(function(el) { self.renderElement(el); });
		this.elements.decorations.forEach(function(el) { self.renderElement(el); });
		this.elements.seats.forEach(function(el) { self.renderElement(el); });
		this.elements.labels.forEach(function(el) { self.renderElement(el); });
	};

	/**
	 * Update element label position and text
	 *
	 * @param {Object} element Element data
	 */
	SeatingDesigner.prototype.updateElementLabel = function(element) {
		// Find existing label
		var $label = $(this.svg).find('.saso-element-label[data-for="' + element.id + '"]');

		// Determine label text
		var labelText = '';
		if (element.type === 'text') {
			// Text elements don't have separate labels
			return;
		} else if (element.isSeat) {
			labelText = element.label || element.identifier || '';
		} else {
			labelText = element.label || '';
		}

		// If no label text and label exists, remove it
		if (!labelText && $label.length) {
			$label.remove();
			return;
		}

		// If label text but no label element, create it
		if (labelText && !$label.length) {
			this.renderElementLabel(element, labelText);
			return;
		}

		// Update existing label
		if (!$label.length) return;

		var centerX, centerY;
		if (element.type === 'circle') {
			centerX = element.x + (element.r || 20);
			centerY = element.y + (element.r || 20);
		} else if (element.type === 'line') {
			centerX = (element.x1 + element.x2) / 2;
			centerY = (element.y1 + element.y2) / 2;
		} else {
			centerX = element.x + (element.width || 40) / 2;
			centerY = element.y + (element.height || 40) / 2;
		}

		$label.attr('x', centerX);
		$label.attr('y', centerY);
		$label.text(labelText);

		// Update text color and stroke - use custom colors if set
		if (element.labelColor || element.labelStroke) {
			$label.attr('fill', element.labelColor || '#333333');
			$label.attr('stroke', element.labelStroke || '#ffffff');
		} else if (element.type !== 'line') {
			var fill = element.fill || '#cccccc';
			var fillColor, strokeColor;
			if (this.isLightColor(fill)) {
				fillColor = '#333333';
				strokeColor = '#ffffff';
			} else {
				fillColor = '#ffffff';
				strokeColor = '#333333';
			}
			$label.attr('fill', fillColor);
			$label.attr('stroke', strokeColor);
		}

		// Update opacity
		var fillOpacity = element.labelColorOpacity !== undefined ? element.labelColorOpacity / 100 : 1;
		var strokeOpacity = element.labelStrokeOpacity !== undefined ? element.labelStrokeOpacity / 100 : 1;
		$label.attr('fill-opacity', fillOpacity);
		$label.attr('stroke-opacity', strokeOpacity);
	};

	// =========================================================================
	// Selection & Drag
	// =========================================================================

	/**
	 * Select an element (with optional multi-select via Shift key)
	 *
	 * @param {string} id Element ID
	 * @param {boolean} addToSelection If true, add to existing selection (Shift+Click)
	 */
	SeatingDesigner.prototype.selectElement = function(id, addToSelection) {
		var element = this.findElement(id);
		if (!element) return;

		if (addToSelection) {
			// Toggle selection for this element
			if (this.isSelected(id)) {
				this.removeFromSelection(id);
			} else {
				this.addToSelection(element);
			}
		} else {
			// Clear and select only this element
			this.deselectAll();
			this.selectedElement = element;
			this.selectedElements = [element];

			// Add selection highlight
			var $el = $(this.svg).find('.saso-element[data-id="' + id + '"]');
			$el.addClass('selected');

			// Show resize handles for resizable elements (only for single selection)
			if (element.type === 'rect' || element.type === 'circle' || element.type === 'seat') {
				this.showResizeHandles(element);
			}

			// Update properties panel
			this.updatePropertiesPanel(element);
		}
	};

	/**
	 * Check if an element is selected
	 *
	 * @param {string} id Element ID
	 * @return {boolean}
	 */
	SeatingDesigner.prototype.isSelected = function(id) {
		return this.selectedElements.some(function(el) {
			return el.id === id;
		});
	};

	/**
	 * Add element to selection
	 *
	 * @param {Object} element Element to add
	 */
	SeatingDesigner.prototype.addToSelection = function(element) {
		if (this.isSelected(element.id)) return;

		this.selectedElements.push(element);
		this.selectedElement = element; // Last selected becomes primary

		// Add selection highlight
		var $el = $(this.svg).find('.saso-element[data-id="' + element.id + '"]');
		$el.addClass('selected');

		// Hide resize handles when multi-selecting
		if (this.selectedElements.length > 1) {
			this.hideResizeHandles();
			// Add multi-selected class to all selected elements for enhanced styling
			var self = this;
			this.selectedElements.forEach(function(el) {
				$(self.svg).find('.saso-element[data-id="' + el.id + '"]').addClass('multi-selected');
			});
		}

		// Update properties panel for multi-select
		this.updatePropertiesPanelMulti();
	};

	/**
	 * Remove element from selection
	 *
	 * @param {string} id Element ID
	 */
	SeatingDesigner.prototype.removeFromSelection = function(id) {
		this.selectedElements = this.selectedElements.filter(function(el) {
			return el.id !== id;
		});

		// Remove highlight
		var $el = $(this.svg).find('.saso-element[data-id="' + id + '"]');
		$el.removeClass('selected multi-selected');

		// Update primary selection
		if (this.selectedElements.length > 0) {
			this.selectedElement = this.selectedElements[this.selectedElements.length - 1];
			if (this.selectedElements.length === 1) {
				// Back to single selection - remove multi-selected from remaining element
				$(this.svg).find('.saso-element.multi-selected').removeClass('multi-selected');
				this.showResizeHandles(this.selectedElement);
				this.updatePropertiesPanel(this.selectedElement);
			} else {
				this.updatePropertiesPanelMulti();
			}
		} else {
			this.selectedElement = null;
			this.hideResizeHandles();
			this.updatePropertiesPanel(null);
		}
	};

	/**
	 * Deselect all elements
	 */
	SeatingDesigner.prototype.deselectAll = function() {
		this.selectedElement = null;
		this.selectedElements = [];
		$(this.svg).find('.saso-element').removeClass('selected multi-selected');
		this.hideResizeHandles();
		this.updatePropertiesPanel(null);
	};

	/**
	 * Select all elements
	 */
	SeatingDesigner.prototype.selectAll = function() {
		var self = this;
		this.deselectAll();

		// Collect all elements
		var allElements = []
			.concat(this.elements.decorations)
			.concat(this.elements.lines)
			.concat(this.elements.labels)
			.concat(this.elements.seats);

		allElements.forEach(function(el) {
			self.selectedElements.push(el);
			$(self.svg).find('.saso-element[data-id="' + el.id + '"]').addClass('selected multi-selected');
		});

		if (this.selectedElements.length > 0) {
			this.selectedElement = this.selectedElements[this.selectedElements.length - 1];
			this.updatePropertiesPanelMulti();
		}
	};

	// =========================================================================
	// Marquee Selection
	// =========================================================================

	/**
	 * Start marquee selection
	 *
	 * @param {Object} pos Start position {x, y}
	 */
	SeatingDesigner.prototype.startMarqueeSelection = function(pos) {
		this.isMarqueeSelecting = true;
		this.marqueeStart = { x: pos.x, y: pos.y };

		// Create marquee rectangle in SVG
		var svgNS = 'http://www.w3.org/2000/svg';
		this.marqueeRect = document.createElementNS(svgNS, 'rect');
		this.marqueeRect.setAttribute('class', 'saso-marquee');
		this.marqueeRect.setAttribute('x', pos.x);
		this.marqueeRect.setAttribute('y', pos.y);
		this.marqueeRect.setAttribute('width', 0);
		this.marqueeRect.setAttribute('height', 0);
		this.marqueeRect.setAttribute('fill', 'rgba(33, 150, 243, 0.2)');
		this.marqueeRect.setAttribute('stroke', '#2196F3');
		this.marqueeRect.setAttribute('stroke-width', '1');
		this.marqueeRect.setAttribute('stroke-dasharray', '4,4');
		this.svg.appendChild(this.marqueeRect);
	};

	/**
	 * Update marquee rectangle during drag
	 *
	 * @param {Object} pos Current position {x, y}
	 */
	SeatingDesigner.prototype.updateMarquee = function(pos) {
		if (!this.marqueeRect) return;

		var x = Math.min(this.marqueeStart.x, pos.x);
		var y = Math.min(this.marqueeStart.y, pos.y);
		var width = Math.abs(pos.x - this.marqueeStart.x);
		var height = Math.abs(pos.y - this.marqueeStart.y);

		this.marqueeRect.setAttribute('x', x);
		this.marqueeRect.setAttribute('y', y);
		this.marqueeRect.setAttribute('width', width);
		this.marqueeRect.setAttribute('height', height);
	};

	/**
	 * End marquee selection and select elements within bounds
	 *
	 * @param {boolean} addToExisting If true, add to existing selection (Shift held)
	 */
	SeatingDesigner.prototype.endMarqueeSelection = function(addToExisting) {
		if (!this.marqueeRect) {
			this.isMarqueeSelecting = false;
			return;
		}

		var x = parseFloat(this.marqueeRect.getAttribute('x'));
		var y = parseFloat(this.marqueeRect.getAttribute('y'));
		var width = parseFloat(this.marqueeRect.getAttribute('width'));
		var height = parseFloat(this.marqueeRect.getAttribute('height'));

		// Remove marquee rectangle
		this.marqueeRect.remove();
		this.marqueeRect = null;
		this.isMarqueeSelecting = false;

		// If marquee is too small, treat as click (deselect)
		if (width < 5 && height < 5) {
			if (!addToExisting) {
				this.deselectAll();
			}
			return;
		}

		// Find elements within the marquee bounds
		var marqueeBounds = {
			left: x,
			right: x + width,
			top: y,
			bottom: y + height
		};

		// Clear selection if not adding to existing
		if (!addToExisting) {
			this.deselectAll();
		}

		var self = this;

		// Check all elements
		var allElements = []
			.concat(this.elements.decorations)
			.concat(this.elements.lines)
			.concat(this.elements.labels)
			.concat(this.elements.seats);

		allElements.forEach(function(el) {
			if (self.elementIntersectsMarquee(el, marqueeBounds)) {
				if (!self.isSelected(el.id)) {
					self.addToSelection(el);
				}
			}
		});

		// Update UI
		if (this.selectedElements.length === 1) {
			this.showResizeHandles(this.selectedElement);
			this.updatePropertiesPanel(this.selectedElement);
		} else if (this.selectedElements.length > 1) {
			this.updatePropertiesPanelMulti();
		}
	};

	/**
	 * Check if element intersects with marquee bounds
	 *
	 * @param {Object} el Element
	 * @param {Object} bounds Marquee bounds {left, right, top, bottom}
	 * @return {boolean}
	 */
	SeatingDesigner.prototype.elementIntersectsMarquee = function(el, bounds) {
		var elBounds = this.getElementBounds(el);

		// Check intersection
		return !(elBounds.right < bounds.left ||
			elBounds.left > bounds.right ||
			elBounds.bottom < bounds.top ||
			elBounds.top > bounds.bottom);
	};

	/**
	 * Get bounding box for an element
	 *
	 * @param {Object} el Element
	 * @return {Object} {left, right, top, bottom}
	 */
	SeatingDesigner.prototype.getElementBounds = function(el) {
		var x = el.x || 0;
		var y = el.y || 0;

		if (el.type === 'circle' || el.type === 'seat') {
			var r = el.r || el.width / 2 || 15;
			return {
				left: x - r,
				right: x + r,
				top: y - r,
				bottom: y + r
			};
		} else if (el.type === 'rect') {
			return {
				left: x,
				right: x + (el.width || 50),
				top: y,
				bottom: y + (el.height || 50)
			};
		} else if (el.type === 'line') {
			var x1 = el.x1 || 0;
			var y1 = el.y1 || 0;
			var x2 = el.x2 || 0;
			var y2 = el.y2 || 0;
			return {
				left: Math.min(x1, x2),
				right: Math.max(x1, x2),
				top: Math.min(y1, y2),
				bottom: Math.max(y1, y2)
			};
		} else if (el.type === 'label') {
			// Labels - approximate based on text length
			var textLen = (el.text || '').length * 6;
			return {
				left: x,
				right: x + textLen,
				top: y - 10,
				bottom: y + 10
			};
		}

		// Default fallback
		return {
			left: x - 20,
			right: x + 20,
			top: y - 20,
			bottom: y + 20
		};
	};

	// =========================================================================
	// Resize Handles
	// =========================================================================

	/**
	 * Show resize handles for an element
	 *
	 * @param {Object} element Element data
	 */
	SeatingDesigner.prototype.showResizeHandles = function(element) {
		this.hideResizeHandles();

		var svgNS = 'http://www.w3.org/2000/svg';
		var handleSize = 8;
		var handles = [];

		if (element.type === 'circle') {
			// Circle: 4 handles at cardinal points
			var cx = element.x + (element.r || 20);
			var cy = element.y + (element.r || 20);
			var r = element.r || 20;

			handles = [
				{ pos: 'n', x: cx - handleSize/2, y: cy - r - handleSize/2 },
				{ pos: 'e', x: cx + r - handleSize/2, y: cy - handleSize/2 },
				{ pos: 's', x: cx - handleSize/2, y: cy + r - handleSize/2 },
				{ pos: 'w', x: cx - r - handleSize/2, y: cy - handleSize/2 }
			];
		} else {
			// Rectangle: 8 handles at corners and edges
			var x = element.x;
			var y = element.y;
			var w = element.width || 40;
			var h = element.height || 40;

			handles = [
				{ pos: 'nw', x: x - handleSize/2, y: y - handleSize/2 },
				{ pos: 'n', x: x + w/2 - handleSize/2, y: y - handleSize/2 },
				{ pos: 'ne', x: x + w - handleSize/2, y: y - handleSize/2 },
				{ pos: 'e', x: x + w - handleSize/2, y: y + h/2 - handleSize/2 },
				{ pos: 'se', x: x + w - handleSize/2, y: y + h - handleSize/2 },
				{ pos: 's', x: x + w/2 - handleSize/2, y: y + h - handleSize/2 },
				{ pos: 'sw', x: x - handleSize/2, y: y + h - handleSize/2 },
				{ pos: 'w', x: x - handleSize/2, y: y + h/2 - handleSize/2 }
			];
		}

		var self = this;
		handles.forEach(function(handle) {
			var rect = document.createElementNS(svgNS, 'rect');
			rect.setAttribute('x', handle.x);
			rect.setAttribute('y', handle.y);
			rect.setAttribute('width', handleSize);
			rect.setAttribute('height', handleSize);
			rect.setAttribute('fill', '#2271b1');
			rect.setAttribute('stroke', '#ffffff');
			rect.setAttribute('stroke-width', '1');
			rect.setAttribute('class', 'saso-resize-handle');
			rect.setAttribute('data-handle', handle.pos);
			rect.style.cursor = self.getResizeCursor(handle.pos);

			self.svg.appendChild(rect);
		});
	};

	/**
	 * Hide resize handles
	 */
	SeatingDesigner.prototype.hideResizeHandles = function() {
		$(this.svg).find('.saso-resize-handle').remove();
	};

	/**
	 * Update resize handles position
	 */
	SeatingDesigner.prototype.updateResizeHandles = function() {
		if (this.selectedElement) {
			this.showResizeHandles(this.selectedElement);
		}
	};

	/**
	 * Get cursor style for resize handle
	 *
	 * @param {string} pos Handle position
	 * @return {string} CSS cursor value
	 */
	SeatingDesigner.prototype.getResizeCursor = function(pos) {
		var cursors = {
			'nw': 'nwse-resize',
			'n': 'ns-resize',
			'ne': 'nesw-resize',
			'e': 'ew-resize',
			'se': 'nwse-resize',
			's': 'ns-resize',
			'sw': 'nesw-resize',
			'w': 'ew-resize'
		};
		return cursors[pos] || 'pointer';
	};

	/**
	 * Start resizing
	 *
	 * @param {string} handle Handle position
	 * @param {Object} pos Mouse position
	 */
	SeatingDesigner.prototype.startResize = function(handle, pos) {
		if (!this.selectedElement) return;

		this.isResizing = true;
		this.resizeHandle = handle;

		var el = this.selectedElement;
		this.resizeStart = {
			x: el.x,
			y: el.y,
			width: el.width || 40,
			height: el.height || 40,
			r: el.r || 20,
			mouseX: pos.x,
			mouseY: pos.y
		};
	};

	/**
	 * Handle resize drag
	 *
	 * @param {Object} pos Mouse position
	 */
	SeatingDesigner.prototype.handleResize = function(pos) {
		if (!this.isResizing || !this.selectedElement) return;

		var el = this.selectedElement;
		var start = this.resizeStart;
		var dx = pos.x - start.mouseX;
		var dy = pos.y - start.mouseY;
		var minSize = 20;

		if (el.type === 'circle') {
			// Circle: resize radius based on distance from center
			var newR;
			if (this.resizeHandle === 'n' || this.resizeHandle === 's') {
				newR = Math.max(minSize/2, start.r + (this.resizeHandle === 's' ? dy : -dy));
			} else {
				newR = Math.max(minSize/2, start.r + (this.resizeHandle === 'e' ? dx : -dx));
			}

			// Snap to grid
			if (this.config.snapToGrid) {
				newR = Math.round(newR / this.config.gridSize) * this.config.gridSize;
			}

			el.r = newR;
			// Adjust position to keep center stable for n/w handles
			if (this.resizeHandle === 'n') {
				el.y = start.y + start.r - newR;
			} else if (this.resizeHandle === 'w') {
				el.x = start.x + start.r - newR;
			}
		} else {
			// Rectangle: resize based on handle position
			var newX = start.x;
			var newY = start.y;
			var newW = start.width;
			var newH = start.height;

			// Handle horizontal resize
			if (this.resizeHandle.includes('e')) {
				newW = Math.max(minSize, start.width + dx);
			} else if (this.resizeHandle.includes('w')) {
				newW = Math.max(minSize, start.width - dx);
				newX = start.x + start.width - newW;
			}

			// Handle vertical resize
			if (this.resizeHandle.includes('s')) {
				newH = Math.max(minSize, start.height + dy);
			} else if (this.resizeHandle.includes('n')) {
				newH = Math.max(minSize, start.height - dy);
				newY = start.y + start.height - newH;
			}

			// Snap to grid
			if (this.config.snapToGrid) {
				newX = Math.round(newX / this.config.gridSize) * this.config.gridSize;
				newY = Math.round(newY / this.config.gridSize) * this.config.gridSize;
				newW = Math.round(newW / this.config.gridSize) * this.config.gridSize;
				newH = Math.round(newH / this.config.gridSize) * this.config.gridSize;
			}

			el.x = newX;
			el.y = newY;
			el.width = newW;
			el.height = newH;
		}

		// Update SVG and handles
		this.updateSvgElement(el);
		this.updateResizeHandles();

		// Update properties panel
		this.updatePropertiesPanel(el);
	};

	/**
	 * End resizing
	 */
	SeatingDesigner.prototype.endResize = function() {
		if (this.isResizing) {
			this.isResizing = false;
			this.resizeHandle = null;
			this.markUnsaved();
		}
	};

	/**
	 * Find element by ID
	 *
	 * @param {string} id Element ID
	 * @return {Object|null} Element data
	 */
	SeatingDesigner.prototype.findElement = function(id) {
		var all = [].concat(
			this.elements.seats,
			this.elements.decorations,
			this.elements.lines,
			this.elements.labels
		);

		for (var i = 0; i < all.length; i++) {
			if (all[i].id === id) return all[i];
		}
		return null;
	};

	/**
	 * Start dragging
	 *
	 * @param {Object} pos Start position
	 * @param {boolean} addedToSelection If element was just added to selection (shift+click)
	 */
	SeatingDesigner.prototype.startDrag = function(pos, addedToSelection) {
		if (this.selectedElements.length === 0) return;

		this.isDragging = true;

		// Store initial offsets for all selected elements
		this.dragOffsets = [];
		var self = this;
		this.selectedElements.forEach(function(el) {
			self.dragOffsets.push({
				id: el.id,
				offsetX: pos.x - (el.x || 0),
				offsetY: pos.y - (el.y || 0)
			});
		});
	};

	/**
	 * Drag all selected elements to new positions
	 *
	 * @param {Object} pos Current position
	 */
	SeatingDesigner.prototype.dragElements = function(pos) {
		if (this.selectedElements.length === 0 || !this.dragOffsets) return;

		var self = this;

		// Calculate delta from first element (the one clicked)
		var primaryOffset = this.dragOffsets[0];
		var deltaX = pos.x - primaryOffset.offsetX - (this.selectedElements[0].x || 0);
		var deltaY = pos.y - primaryOffset.offsetY - (this.selectedElements[0].y || 0);

		// Snap delta to grid
		if (this.config.snapToGrid) {
			deltaX = Math.round(deltaX / this.config.gridSize) * this.config.gridSize;
			deltaY = Math.round(deltaY / this.config.gridSize) * this.config.gridSize;
		}

		// If no movement, skip
		if (deltaX === 0 && deltaY === 0) return;

		// Move all selected elements
		this.selectedElements.forEach(function(el, index) {
			var offset = self.dragOffsets[index];
			var newX = (el.x || 0) + deltaX;
			var newY = (el.y || 0) + deltaY;

			// Constrain to canvas bounds
			var elWidth = el.width || (el.r ? el.r * 2 : 40);
			var elHeight = el.height || (el.r ? el.r * 2 : 40);
			newX = Math.max(0, Math.min(newX, self.config.canvasWidth - elWidth));
			newY = Math.max(0, Math.min(newY, self.config.canvasHeight - elHeight));

			// Update position
			el.x = newX;
			el.y = newY;
			self.updateSvgElement(el);

			// Update offset for next movement
			self.dragOffsets[index].offsetX = pos.x - newX;
			self.dragOffsets[index].offsetY = pos.y - newY;
		});

		// Update properties panel (show primary element's position)
		if (this.selectedElements.length === 1) {
			this.updatePositionInPanel(this.selectedElements[0].x, this.selectedElements[0].y);
		}
	};

	/**
	 * Update position inputs in properties panel (live during drag)
	 *
	 * @param {number} x X position
	 * @param {number} y Y position
	 */
	SeatingDesigner.prototype.updatePositionInPanel = function(x, y) {
		var $panel = this.$propsPanel;
		if (!$panel) return;

		$panel.find('.prop-input[data-prop="x"]').val(Math.round(x));
		$panel.find('.prop-input[data-prop="y"]').val(Math.round(y));
	};

	/**
	 * End dragging
	 */
	SeatingDesigner.prototype.endDrag = function() {
		// Set flag to ignore the click event that follows mouseup
		if (this.isDragging) {
			this.justFinishedDragging = true;
		}

		this.isDragging = false;
		this.dragOffsets = null;

		if (this.selectedElements.length > 0) {
			this.markUnsaved();
			// Update resize handles if single selection
			if (this.selectedElements.length === 1) {
				this.updateResizeHandles();
			}
		}
	};

	// =========================================================================
	// Element Updates
	// =========================================================================

	/**
	 * Update element property
	 *
	 * @param {string} id Element ID
	 * @param {string} prop Property name
	 * @param {*} value New value
	 */
	SeatingDesigner.prototype.updateElementProperty = function(id, prop, value) {
		var element = this.findElement(id);
		if (!element) return;

		element[prop] = value;

		// Special handling for isSeat toggle
		if (prop === 'isSeat') {
			this.handleSeatToggle(element);
		}

		// Update SVG element
		this.updateSvgElement(element);

		// Refresh properties panel if this is the selected element
		if (this.selectedElement && this.selectedElement.id === id && prop === 'isSeat') {
			this.updatePropertiesPanel(element);
		}

		this.markUnsaved();
	};

	/**
	 * Handle toggling isSeat property
	 *
	 * @param {Object} element Element data
	 */
	SeatingDesigner.prototype.handleSeatToggle = function(element) {
		// Move between decorations and seats arrays
		if (element.isSeat) {
			// Remove from decorations, add to seats
			var idx = this.elements.decorations.indexOf(element);
			if (idx > -1) {
				this.elements.decorations.splice(idx, 1);
				this.elements.seats.push(element);
			}
			// Update color to available
			element.fill = this.config.colors.available;

			// Assign default identifier if not set
			if (!element.identifier) {
				var seatNum = this.elements.seats.length;
				element.identifier = 'SEAT-' + seatNum;
				element.label = 'Seat ' + seatNum;
			}
		} else {
			// Remove from seats, add to decorations
			var idx = this.elements.seats.indexOf(element);
			if (idx > -1) {
				this.elements.seats.splice(idx, 1);
				this.elements.decorations.push(element);
			}
			// Reset color
			element.fill = '#cccccc';
			// Clear seat properties
			element.identifier = '';
			element.label = '';
			element.category = '';
		}

		// Re-render to move to correct layer
		this.renderAllElements();
		this.selectElement(element.id);
	};

	/**
	 * Update SVG element from data
	 *
	 * @param {Object} element Element data
	 */
	SeatingDesigner.prototype.updateSvgElement = function(element) {
		var $el = $(this.svg).find('.saso-element[data-id="' + element.id + '"]');
		if (!$el.length) return;

		var svgEl = $el[0];
		var fillOpacity = element.fillOpacity !== undefined ? element.fillOpacity / 100 : 1;
		var strokeOpacity = element.strokeOpacity !== undefined ? element.strokeOpacity / 100 : 0;

		switch (element.type) {
			case 'rect':
			case 'seat':
				svgEl.setAttribute('x', element.x);
				svgEl.setAttribute('y', element.y);
				svgEl.setAttribute('width', element.width || 40);
				svgEl.setAttribute('height', element.height || 40);
				svgEl.setAttribute('fill', element.fill || '#cccccc');
				svgEl.setAttribute('fill-opacity', fillOpacity);
				svgEl.setAttribute('stroke', element.stroke || '#333333');
				svgEl.setAttribute('stroke-opacity', strokeOpacity);
				svgEl.setAttribute('stroke-width', strokeOpacity > 0 ? 2 : 0);
				break;

			case 'circle':
				svgEl.setAttribute('cx', element.x + (element.r || 20));
				svgEl.setAttribute('cy', element.y + (element.r || 20));
				svgEl.setAttribute('r', element.r || 20);
				svgEl.setAttribute('fill', element.fill || '#cccccc');
				svgEl.setAttribute('fill-opacity', fillOpacity);
				svgEl.setAttribute('stroke', element.stroke || '#333333');
				svgEl.setAttribute('stroke-opacity', strokeOpacity);
				svgEl.setAttribute('stroke-width', strokeOpacity > 0 ? 2 : 0);
				break;

			case 'line':
				var lineStrokeOpacity = element.strokeOpacity !== undefined ? element.strokeOpacity / 100 : 1;
				svgEl.setAttribute('x1', element.x1 || element.x);
				svgEl.setAttribute('y1', element.y1 || element.y);
				svgEl.setAttribute('x2', element.x2);
				svgEl.setAttribute('y2', element.y2);
				svgEl.setAttribute('stroke', element.stroke || '#333333');
				svgEl.setAttribute('stroke-opacity', lineStrokeOpacity);
				svgEl.setAttribute('stroke-width', element.strokeWidth || 2);
				break;

			case 'text':
				svgEl.setAttribute('x', element.x);
				svgEl.setAttribute('y', element.y);
				svgEl.setAttribute('fill', element.fill || '#333333');
				svgEl.setAttribute('fill-opacity', fillOpacity);
				svgEl.setAttribute('font-size', element.fontSize || 14);
				svgEl.textContent = element.text || '';
				break;
		}

		// Apply rotation transform for rect, circle, seat
		if (element.type === 'rect' || element.type === 'circle' || element.type === 'seat') {
			this.applyRotation(svgEl, element);
		}

		// Update label for all elements (except text which uses text content)
		if (element.type !== 'text') {
			this.updateElementLabel(element);
		}
	};

	/**
	 * Delete element
	 *
	 * @param {string} id Element ID
	 */
	SeatingDesigner.prototype.deleteElement = function(id) {
		var element = this.findElement(id);
		if (!element) return;

		// Check if seat has tickets (would need AJAX check in real implementation)
		// For now, just delete

		// Remove from array (find by ID, not reference)
		var arrays = ['seats', 'decorations', 'lines', 'labels'];
		for (var i = 0; i < arrays.length; i++) {
			var arr = this.elements[arrays[i]];
			for (var j = arr.length - 1; j >= 0; j--) {
				if (arr[j].id === id) {
					arr.splice(j, 1);
					break;
				}
			}
		}

		// Remove SVG element AND its label
		$(this.svg).find('[data-id="' + id + '"]').remove();
		$(this.svg).find('.saso-element-label[data-for="' + id + '"]').remove();

		// Remove from selection if was selected
		this.removeFromSelection(id);

		this.updateElementCounts();
		this.markUnsaved();
	};

	/**
	 * Delete all selected elements
	 */
	SeatingDesigner.prototype.deleteSelectedElements = function() {
		var self = this;
		var toDelete = this.selectedElements.slice(); // Copy array

		if (toDelete.length === 0) return;

		toDelete.forEach(function(el) {
			// Remove from arrays (find by ID, not reference)
			var arrays = ['seats', 'decorations', 'lines', 'labels'];
			for (var i = 0; i < arrays.length; i++) {
				var arr = self.elements[arrays[i]];
				for (var j = arr.length - 1; j >= 0; j--) {
					if (arr[j].id === el.id) {
						arr.splice(j, 1);
						break;
					}
				}
			}

			// Remove SVG element AND its label
			$(self.svg).find('[data-id="' + el.id + '"]').remove();
			$(self.svg).find('.saso-element-label[data-for="' + el.id + '"]').remove();
		});

		this.selectedElements = [];
		this.selectedElement = null;
		this.hideResizeHandles();
		this.updatePropertiesPanel(null);
		this.updateElementCounts();
		this.markUnsaved();
	};

	// =========================================================================
	// Save / Load / Publish
	// =========================================================================

	/**
	 * Load data from server
	 */
	SeatingDesigner.prototype.loadData = function() {
		var self = this;

		if (!this.config.planId) {
			console.warn('No plan ID set');
			return;
		}

		$.ajax({
			url: this.config.ajaxUrl,
			type: 'POST',
			data: {
				action: this.config.ajaxAction,
				a: 'getDesignerData',
				plan_id: this.config.planId,
				nonce: this.config.nonce
			},
			success: function(response) {
				if (response.success && response.data) {
					self.applyLoadedData(response.data);
				}
			},
			error: function(xhr, status, error) {
				console.error('Failed to load designer data:', error);
			}
		});
	};

	/**
	 * Apply loaded data
	 *
	 * @param {Object} data Server response data
	 */
	SeatingDesigner.prototype.applyLoadedData = function(data, usePublished) {
		var self = this;
		var maxId = 0;

		// Determine if we received full response or just plan object
		// Full response: {plan: {...}, config: {...}} (from getDesignerPage)
		// Plan object: {id, draft, published, seats, ...} (from switchToVersion)
		var plan;
		if (data.plan) {
			plan = data.plan;
		} else if (data.draft !== undefined || data.published !== undefined) {
			plan = data;
		} else {
			console.error('applyLoadedData: invalid data structure', data);
			return;
		}

		// Store the plan object
		this.plan = plan;

		// Which meta to use: draft or published
		var meta = usePublished ? plan.published : plan.draft;
		this.viewingPublished = !!usePublished;

		// Helper to ensure element has ID and track max ID
		function ensureId(element, prefix) {
			if (!element.id) {
				element.id = prefix + '_' + self.nextId++;
			} else {
				// Extract number from existing ID to track max
				var match = element.id.match(/\d+$/);
				if (match) {
					maxId = Math.max(maxId, parseInt(match[0], 10));
				}
			}
			return element;
		}

		// Apply canvas settings from meta (draft or published)
		if (meta) {
			this.config.canvasWidth = meta.canvas_width || 800;
			this.config.canvasHeight = meta.canvas_height || 600;
			this.config.backgroundColor = meta.background_color || '#ffffff';
			this.config.backgroundImage = meta.background_image || '';

			if (meta.colors) {
				this.config.colors = meta.colors;
			}

			// Load decorations
			this.elements.decorations = (meta.decorations || []).map(function(el) {
				var copy = Object.assign({}, el);
				return ensureId(copy, 'shape');
			});

			// Load lines
			this.elements.lines = (meta.lines || []).map(function(el) {
				var copy = Object.assign({}, el);
				copy = ensureId(copy, 'line');
				if (copy.x1 !== undefined && copy.x === undefined) {
					copy.x = copy.x1;
					copy.y = copy.y1;
				}
				return copy;
			});

			// Load labels
			this.elements.labels = (meta.labels || []).map(function(el) {
				var copy = Object.assign({}, el);
				return ensureId(copy, 'label');
			});
		}

		// Load seats from seats array
		if (plan.seats && plan.seats.length > 0) {
			this.elements.seats = plan.seats.map(function(seat) {
				var seatMeta = seat.meta || {};
				var shapeConfig = seatMeta.shape_config || {};
				// Track max seat ID for nextId calculation
				var seatDbId = parseInt(seat.id, 10) || 0;
				if (seatDbId > maxId) {
					maxId = seatDbId;
				}
				return {
					id: 'seat_' + seat.id,
					dbId: seat.id,
					type: seatMeta.shape_type || 'rect',
					x: parseFloat(seatMeta.pos_x) || 0,
					y: parseFloat(seatMeta.pos_y) || 0,
					rotation: parseInt(seatMeta.rotation) || 0,
					width: shapeConfig.width || 30,
					height: shapeConfig.height || 30,
					r: shapeConfig.width ? shapeConfig.width / 2 : 15,
					fill: seatMeta.color || self.config.colors.available,
					stroke: '#999999',
					strokeWidth: 1,
					isSeat: true,
					identifier: seat.seat_identifier || '',
					label: seatMeta.seat_label || '',
					category: seatMeta.seat_category || '',
					seat_desc: seatMeta.seat_desc || ''
				};
			});
		} else {
			this.elements.seats = [];
		}

		// Update nextId to be higher than any loaded ID (including seat DB IDs)
		this.nextId = Math.max(this.nextId, maxId + 1);

		// Ensure all elements have unique IDs (fix any duplicates)
		this.ensureUniqueElementIds();

		// Update canvas
		this.createCanvas();
		this.renderAllElements();

		// Update element counts
		this.updateElementCounts();

		// Show warnings if needed (only on initial load, not on version switch)
		if (!usePublished) {
			if (plan.active_sales && plan.active_sales.has_active_sales) {
				this.showActiveSalesWarning(plan.active_sales);
			}

			if (plan.has_unpublished_changes) {
				this.showUnpublishedBanner(plan.publish_info);
			}
		}
	};

	/**
	 * Ensure all element IDs are unique across all element arrays
	 * Fixes any duplicates by assigning new unique IDs
	 */
	SeatingDesigner.prototype.ensureUniqueElementIds = function() {
		var self = this;
		var seenIds = {};
		var duplicatesFound = [];

		// Helper to check and fix ID
		function checkAndFixId(element, arrayName) {
			if (!element.id) {
				// No ID - assign one
				element.id = arrayName + '_' + self.nextId++;
				return;
			}

			if (seenIds[element.id]) {
				// Duplicate found!
				var oldId = element.id;
				var newId = arrayName + '_unique_' + self.nextId++;
				duplicatesFound.push({
					oldId: oldId,
					newId: newId,
					array: arrayName,
					label: element.label || element.identifier || ''
				});
				element.id = newId;
			} else {
				seenIds[element.id] = true;
			}
		}

		// Check all element arrays
		this.elements.seats.forEach(function(el) { checkAndFixId(el, 'seat'); });
		this.elements.decorations.forEach(function(el) { checkAndFixId(el, 'shape'); });
		this.elements.lines.forEach(function(el) { checkAndFixId(el, 'line'); });
		this.elements.labels.forEach(function(el) { checkAndFixId(el, 'label'); });

		// Log if duplicates were found
		if (duplicatesFound.length > 0) {
			console.warn('SeatingDesigner: Fixed ' + duplicatesFound.length + ' duplicate element IDs:', duplicatesFound);
		}
	};

	/**
	 * Save draft to server
	 */
	SeatingDesigner.prototype.saveDraft = function() {
		var self = this;

		var draftData = {
			canvas_width: this.config.canvasWidth,
			canvas_height: this.config.canvasHeight,
			background_color: this.config.backgroundColor,
			background_image: this.config.backgroundImage,
			background_image_id: this.config.backgroundImageId || 0,
			background_image_fit: this.config.backgroundImageFit || 'contain',
			background_image_align: this.config.backgroundImageAlign || 'center',
			colors: this.config.colors,
			decorations: this.elements.decorations,
			lines: this.elements.lines,
			labels: this.elements.labels
		};

		// Check if sync to published data is requested
		var $container = $(this.config.container);
		var syncToPubData = $container.find('.saso-sync-to-pub-checkbox').is(':checked');

		// Seats are saved separately via the seats endpoint
		var seatsData = this.elements.seats.map(function(seat) {
			// For circles: always use r*2 (diameter), ignore stale width/height
			// For rects: use width/height
			var seatWidth, seatHeight;
			if (seat.type === 'circle') {
				seatWidth = seatHeight = (seat.r || 15) * 2;
			} else {
				seatWidth = seat.width || 30;
				seatHeight = seat.height || 30;
			}
			return {
				id: seat.dbId || null,
				identifier: seat.identifier,
				label: seat.label,
				category: seat.category,
				pos_x: seat.x,
				pos_y: seat.y,
				rotation: seat.rotation || 0,
				shape_type: seat.type,
				shape_config: {
					width: seatWidth,
					height: seatHeight
				},
				color: seat.fill,
				seat_desc: seat.seat_desc,
				syncToPubData: syncToPubData
			};
		});

		$.ajax({
			url: this.config.ajaxUrl,
			type: 'POST',
			data: {
				action: this.config.ajaxAction,
				a: 'saveDraft',
				plan_id: this.config.planId,
				nonce: this.config.nonce,
				decorations: JSON.stringify(draftData.decorations),
				lines: JSON.stringify(draftData.lines),
				labels: JSON.stringify(draftData.labels),
				canvas_width: draftData.canvas_width,
				canvas_height: draftData.canvas_height,
				background_color: draftData.background_color,
				background_image: draftData.background_image,
				background_image_id: draftData.background_image_id,
				background_image_fit: draftData.background_image_fit,
				background_image_align: draftData.background_image_align,
				colors: JSON.stringify(draftData.colors),
				seats: JSON.stringify(seatsData)
			},
			success: function(response) {
				if (response.success) {
					self.hasUnsavedChanges = false;
					// Reset sync checkbox after save
					$(self.config.container).find('.saso-sync-to-pub-checkbox').prop('checked', false);
					self.showNotice('success', self.config.i18n.draftSaved || 'Draft saved successfully');
					// Show unpublished banner if not already visible
					if (response.data && response.data.has_unpublished_changes) {
						var $existing = $(self.config.container).find('.saso-unpublished-banner');
						if ($existing.length === 0) {
							self.showUnpublishedBanner(response.data.publish_info);
						}
					}
					// Update header badges
					self.updateHeaderBadges(response.data);
				} else {
					self.showNotice('error', response.data.error || 'Save failed');
				}
			},
			error: function(xhr, status, error) {
				self.showNotice('error', 'Save failed: ' + error);
			}
		});
	};

	/**
	 * Publish plan
	 */
	SeatingDesigner.prototype.publish = SeatingDesigner.prototype.publishPlan = function() {
		var self = this;

		if (!confirm(this.config.i18n.confirmPublish || 'Publish changes? This will make them visible to customers.')) {
			return;
		}

		// Save draft first, then publish
		this.saveDraft();

		setTimeout(function() {
			$.ajax({
				url: self.config.ajaxUrl,
				type: 'POST',
				data: {
					action: self.config.ajaxAction,
					a: 'publishPlan',
					plan_id: self.config.planId,
					nonce: self.config.nonce
				},
				success: function(response) {
					if (response.success && response.data.success) {
						self.showNotice('success', self.config.i18n.planPublished || 'Seating plan published successfully');
						// Hide unpublished banner
						$(self.config.container).find('.saso-unpublished-banner').remove();
						// Update header badges
						self.updateHeaderBadges(response.data);
					} else {
						// Show conflicts
						if (response.data.conflicts) {
							self.showConflictsModal(response.data.conflicts);
						} else {
							self.showNotice('error', response.data.message || 'Publish failed');
						}
					}
				},
				error: function(xhr, status, error) {
					self.showNotice('error', 'Publish failed: ' + error);
				}
			});
		}, 500);
	};

	/**
	 * Discard draft changes
	 */
	SeatingDesigner.prototype.discardDraft = function() {
		var self = this;

		if (!confirm(this.config.i18n.confirmDiscard || 'Discard all unsaved changes and revert to published version?')) {
			return;
		}

		$.ajax({
			url: this.config.ajaxUrl,
			type: 'POST',
			data: {
				action: this.config.ajaxAction,
				a: 'discardDraft',
				plan_id: this.config.planId,
				nonce: this.config.nonce
			},
			success: function(response) {
				if (response.success) {
					self.showNotice('success', self.config.i18n.draftDiscarded || 'Draft changes discarded');
					self.loadData(); // Reload
				} else {
					self.showNotice('error', response.data.error || 'Discard failed');
				}
			},
			error: function(xhr, status, error) {
				self.showNotice('error', 'Discard failed: ' + error);
			}
		});
	};

	/**
	 * Preview (open in new tab)
	 */
	SeatingDesigner.prototype.preview = function() {
		// For now, just save and alert
		this.saveDraft();
		alert(this.config.i18n.previewNotAvailable || 'Preview will open the customer view in a new tab. (Not implemented yet)');
	};

	// =========================================================================
	// UI Helpers
	// =========================================================================

	/**
	 * Mark as having unsaved changes
	 */
	SeatingDesigner.prototype.markUnsaved = function() {
		this.hasUnsavedChanges = true;
		$(this.config.container).find('.saso-save-draft').addClass('has-changes');
		this.updateElementCounts();
	};

	/**
	 * Show notice as toast (fixed position, doesn't affect layout)
	 *
	 * @param {string} type 'success', 'error', 'warning'
	 * @param {string} message Message text
	 */
	SeatingDesigner.prototype.showNotice = function(type, message) {
		// Get or create toast container
		var $container = $('.saso-toast-container');
		if ($container.length === 0) {
			$container = $('<div class="saso-toast-container"></div>');
			$('body').append($container);
		}

		var $notice = $('<div class="saso-notice saso-notice-' + type + '">' + message + '</div>');
		$container.append($notice);

		setTimeout(function() {
			$notice.fadeOut(function() { $(this).remove(); });
		}, 3000);
	};

	/**
	 * Update header badges (Published, Draft)
	 *
	 * @param {Object} data Response data with audit_info, publish_info, has_unpublished_changes
	 */
	SeatingDesigner.prototype.updateHeaderBadges = function(data) {
		var $headerRight = $(this.config.container).find('.saso-designer-header .header-right');
		if ($headerRight.length === 0) return;

		var auditInfo = data.audit_info || {};
		var publishInfo = data.publish_info;
		var hasUnpublishedChanges = data.has_unpublished_changes;

		// Keep the active/inactive badge, rebuild the rest
		var $activeStatus = $headerRight.find('.saso-plan-status.active, .saso-plan-status.inactive');
		var activeHtml = $activeStatus.length ? $activeStatus[0].outerHTML : '';

		var badges = activeHtml;

		// Store for later reference
		this.lastPublishInfo = publishInfo;
		this.lastAuditInfo = auditInfo;

		// Published badge (clickable to view published version)
		if (publishInfo && publishInfo.published_at) {
			var publishedActive = this.viewingPublished ? ' viewing' : '';
			badges += '<span class="saso-plan-status published clickable' + publishedActive + '" ' +
				'title="' + (this.config.i18n.clickToViewPublished || 'Click to view published version') + '">' +
				'<span class="dashicons dashicons-yes-alt"></span> ' +
				(this.config.i18n.published || 'Published') +
				'<span class="saso-status-date">' + this.formatDate(publishInfo.published_at) + '</span>' +
				'</span>';
		}

		// Draft badge (clickable to view/edit draft)
		if (hasUnpublishedChanges) {
			var draftDate = auditInfo.updated_at || '';
			var draftActive = !this.viewingPublished ? ' viewing' : '';
			badges += '<span class="saso-plan-status draft clickable' + draftActive + '" ' +
				'title="' + (this.config.i18n.clickToViewDraft || 'Click to view/edit draft') + '">' +
				'<span class="dashicons dashicons-edit"></span> ' +
				(this.config.i18n.draft || 'Draft') +
				(draftDate ? '<span class="saso-status-date">' + this.formatDate(draftDate) + '</span>' : '') +
				'</span>';
		}

		$headerRight.html(badges);
	};

	/**
	 * Bind version toggle click handlers (called once during init)
	 */
	SeatingDesigner.prototype.bindVersionToggleHandlers = function() {
		var self = this;
		var $container = $(this.config.container);

		// Use event delegation for badges that get rebuilt
		$container.on('click', '.saso-plan-status.published.clickable', function(e) {
			e.preventDefault();
			e.stopPropagation();
			console.log('Published badge clicked');
			self.switchToVersion(true);
		});

		$container.on('click', '.saso-plan-status.draft.clickable', function(e) {
			e.preventDefault();
			e.stopPropagation();
			console.log('Draft badge clicked');
			self.switchToVersion(false);
		});
	};

	/**
	 * Format date string for display
	 *
	 * @param {string} dateStr Date string
	 * @return {string} Formatted date
	 */
	SeatingDesigner.prototype.formatDate = function(dateStr) {
		if (!dateStr) return '';
		try {
			var date = new Date(dateStr);
			var day = String(date.getDate()).padStart(2, '0');
			var month = String(date.getMonth() + 1).padStart(2, '0');
			var year = String(date.getFullYear()).slice(-2);
			var hours = String(date.getHours()).padStart(2, '0');
			var mins = String(date.getMinutes()).padStart(2, '0');
			return day + '.' + month + '.' + year + ' ' + hours + ':' + mins;
		} catch (e) {
			return dateStr;
		}
	};

	/**
	 * Switch between draft and published view
	 *
	 * @param {boolean} showPublished Whether to show published version
	 */
	SeatingDesigner.prototype.switchToVersion = function(showPublished) {
		if (this.viewingPublished === showPublished) {
			return;
		}

		if (showPublished && this.hasUnsavedChanges) {
			if (!confirm(this.config.i18n.unsavedChangesPreview || 'You have unsaved changes. Switch to published view anyway?')) {
				return;
			}
		}

		if (showPublished && (!this.plan.published || Object.keys(this.plan.published).length === 0)) {
			this.showNotice('warning', this.config.i18n.noPublishedVersion || 'No published version available');
			return;
		}

		// Neu laden mit dem gewünschten Meta (draft oder published)
		this.applyLoadedData(this.plan, showPublished);
		this.showNotice('info', showPublished
			? (this.config.i18n.viewingPublished || 'Viewing published version')
			: (this.config.i18n.viewingDraft || 'Back to draft version'));
	};

	/**
	 * Show unpublished changes banner
	 *
	 * @param {Object} publishInfo Last publish info
	 */
	SeatingDesigner.prototype.showUnpublishedBanner = function(publishInfo) {
		var self = this;
		var $container = $(this.config.container).find('.saso-designer-notices');

		// Remove existing banner first
		$container.find('.saso-unpublished-banner').remove();

		var $banner = $('<div class="saso-unpublished-banner">' +
			'<div class="banner-content">' +
			'<div class="banner-text">' +
			'<span class="dashicons dashicons-warning"></span>' +
			'<span>' + (this.config.i18n.unpublishedChanges || 'You have unpublished changes') + '</span>' +
			'</div>');

		if (publishInfo) {
			$banner.find('.banner-text').append('<span class="publish-info">' +
				(this.config.i18n.lastPublished || 'Last published:') + ' ' +
				publishInfo.published_at + ' ' + (this.config.i18n.by || 'by') + ' ' +
				publishInfo.published_by_name +
				'</span>');
		}

		// Add publish button right-aligned
		var $publishBtn = $('<button type="button" class="button button-primary saso-banner-publish">' +
			'<span class="dashicons dashicons-yes-alt"></span> ' +
			(this.config.i18n.publish || 'Publish') +
			'</button>');

		$publishBtn.on('click', function() {
			self.publishPlan();
		});

		$banner.append('<div class="banner-actions"></div>');
		$banner.find('.banner-actions').append($publishBtn);
		$banner.append('</div></div>');

		$container.prepend($banner);
	};

	/**
	 * Show active sales warning
	 *
	 * @param {Object} salesInfo Active sales info
	 */
	SeatingDesigner.prototype.showActiveSalesWarning = function(salesInfo) {
		var $container = $(this.config.container).find('.saso-designer-notices');

		// Remove existing warning first
		$container.find('.saso-active-sales-warning').remove();

		var html = '<div class="saso-active-sales-warning">' +
			'<span class="dashicons dashicons-warning"></span>' +
			'<div class="warning-content">' +
			'<div class="warning-title">' + (this.config.i18n.activeSalesWarning || 'Warning: Active ticket sales') + '</div>' +
			'<div class="warning-details">' +
			salesInfo.total_tickets + ' ' + (this.config.i18n.ticketsSold || 'tickets sold') +
			'<ul class="product-list">';

		salesInfo.products.forEach(function(product) {
			html += '<li>' + product.name + ' (' + product.ticket_count + ')</li>';
		});

		html += '</ul></div></div></div>';

		$container.prepend(html);
	};

	/**
	 * Show conflicts modal
	 *
	 * @param {Object} conflicts Seat conflicts
	 */
	SeatingDesigner.prototype.showConflictsModal = function(conflicts) {
		var html = '<div class="saso-conflicts-list">' +
			'<p>' + (this.config.i18n.publishConflicts || 'Cannot publish: The following seats have sold tickets and cannot be deleted:') + '</p>' +
			'<ul>';

		for (var identifier in conflicts) {
			html += '<li><strong>' + identifier + '</strong>: ' + conflicts[identifier] + ' ' + (this.config.i18n.tickets || 'tickets') + '</li>';
		}

		html += '</ul></div>';

		// Simple alert for now - could be a proper modal
		alert($(html).text());
	};

	// =========================================================================
	// Destroy
	// =========================================================================

	/**
	 * Destroy designer instance
	 */
	SeatingDesigner.prototype.destroy = function() {
		if (this.svg) {
			$(this.svg).off();
		}
		$(this.config.container).off();
		$(document).off('keydown.sasoSeatingDesigner');
		$(document).off('keyup.sasoSeatingDesigner');
		$(this.config.container).empty();
		this.svg = null;
		this.layers = {};
		this.elements = { seats: [], decorations: [], lines: [], labels: [] };
	};

	// =========================================================================
	// Export
	// =========================================================================

	/**
	 * Initialize designer
	 *
	 * @param {Object} config Configuration
	 * @return {SeatingDesigner} Designer instance
	 */
	window.initSeatingDesigner = function(config) {
		if (window.SasoSeatingDesigner) {
			window.SasoSeatingDesigner.destroy();
		}
		window.SasoSeatingDesigner = new SeatingDesigner(config);
		return window.SasoSeatingDesigner;
	};

})(jQuery);
