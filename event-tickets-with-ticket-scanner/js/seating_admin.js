/**
 * Seating Admin JavaScript
 *
 * Handles admin UI for seating plan management.
 *
 * @package Event_Tickets_With_Ticket_Scanner
 * @since 2.8.0
 */
function sasoEventtickets_js_seating_admin(_myAjaxVar, _basicObj) {
	const $ = jQuery;
	const { __, _x, _n, sprintf } = wp.i18n;
	let myAjax = _myAjaxVar;
	let BASIC = _basicObj;
	let adminDiv = null;

	// Config
	const config = {
		i18n: {
			confirmDelete: __('Are you sure you want to delete this?', 'event-tickets-with-ticket-scanner'),
			confirmDeleteWithSeats: __('This plan has seats. Delete plan and all seats?', 'event-tickets-with-ticket-scanner'),
			planCreated: __('Seating plan created successfully', 'event-tickets-with-ticket-scanner'),
			planUpdated: __('Seating plan updated successfully', 'event-tickets-with-ticket-scanner'),
			planDeleted: __('Seating plan deleted successfully', 'event-tickets-with-ticket-scanner'),
			planCloned: __('Seating plan cloned successfully', 'event-tickets-with-ticket-scanner'),
			seatCreated: __('Seat created successfully', 'event-tickets-with-ticket-scanner'),
			seatsCreated: __('Seats created successfully', 'event-tickets-with-ticket-scanner'),
			seatUpdated: __('Seat updated successfully', 'event-tickets-with-ticket-scanner'),
			seatDeleted: __('Seat deleted successfully', 'event-tickets-with-ticket-scanner'),
			limitReached: __('Limit reached. Upgrade to Premium for unlimited access.', 'event-tickets-with-ticket-scanner'),
			error: __('An error occurred. Please try again.', 'event-tickets-with-ticket-scanner'),
			loading: __('Loading...', 'event-tickets-with-ticket-scanner'),
			noPlans: __('No seating plans found. Create your first plan!', 'event-tickets-with-ticket-scanner'),
			noSeats: __('No seats in this plan. Add seats below.', 'event-tickets-with-ticket-scanner'),
			layoutSimpleTitle: __('Simple Layout (Dropdown)', 'event-tickets-with-ticket-scanner'),
			layoutSimpleDesc: __('Customers will see a dropdown menu to select their seat. Available seats are shown in a list sorted by identifier. This is ideal for smaller venues or when visual seat selection is not needed.', 'event-tickets-with-ticket-scanner'),
			layoutVisualTitle: __('Visual Layout (Seat Map)', 'event-tickets-with-ticket-scanner'),
			layoutVisualDesc: __('Customers will see an interactive seat map where they can click on available seats. Occupied seats are shown in a different color. This provides a visual overview of the venue. (Premium feature)', 'event-tickets-with-ticket-scanner'),
			showLayout: __('Show Layout', 'event-tickets-with-ticket-scanner'),
			hideLayout: __('Hide Layout', 'event-tickets-with-ticket-scanner'),
			// Batch operations
			batchConfirmDelete: __('Delete %d seats?', 'event-tickets-with-ticket-scanner'),
			batchSeatsUpdated: __('%d seats updated', 'event-tickets-with-ticket-scanner'),
			batchSeatsDeleted: __('%d seats deleted', 'event-tickets-with-ticket-scanner'),
			batchWithErrors: __('%d seats processed, %d errors', 'event-tickets-with-ticket-scanner'),
			selected: __('selected', 'event-tickets-with-ticket-scanner'),
			batchActionSelect: __('-- Action --', 'event-tickets-with-ticket-scanner'),
			batchActivate: __('Activate', 'event-tickets-with-ticket-scanner'),
			batchDeactivate: __('Deactivate', 'event-tickets-with-ticket-scanner'),
			batchDelete: __('Delete', 'event-tickets-with-ticket-scanner')
		}
	};

	// State
	let currentPlanId = null;
	let currentPlanName = '';
	let currentPlanLayoutType = 'simple';
	let plansData = [];
	let seatsData = [];
	let plansDataTable = null;
	let seatsDataTable = null;
	let batchInProgress = false;

	/**
	 * Make AJAX request via BASIC._makePost
	 */
	function makeRequest(action, data, successCb, errorCb) {
		data = data || {};
		data.c = action;
		BASIC._makePost('seating', data, successCb, errorCb);
	}

	function showNotice(message, type) {
		type = type || 'success';
		const $notice = $('<div class="saso-notice saso-notice-' + type + '">' + message + '</div>');
		// Use .first() to prevent duplicate notices if multiple wrappers exist
		$('.saso-seating-admin-wrap').first().prepend($notice);
		setTimeout(function() {
			$notice.fadeOut(300, function() { $(this).remove(); });
		}, 3000);
	}

	function showLoading($container) {
		$container.html('<div class="saso-loading">' + config.i18n.loading + '</div>');
	}

	// =========================================================================
	// Plans Management
	// =========================================================================

	function loadPlans() {
		const $list = $('.saso-seating-plans-list');
		showLoading($list);

		makeRequest('getPlans', {}, function(data) {
			plansData = data.plans || [];
			renderPlans();
			updateAddPlanButton();
		});
	}

	function renderPlans() {
		const $list = $('.saso-seating-plans-list');
		const tableId = 'saso-plans-datatable';

		// Destroy existing DataTable
		if (plansDataTable) {
			plansDataTable.destroy();
			plansDataTable = null;
		}

		// Create table structure
		$list.html('<table id="' + tableId + '" class="display" style="width:100%">' +
			'<thead><tr>' +
			'<th>ID</th>' +
			'<th>' + __('Name', 'event-tickets-with-ticket-scanner') + '</th>' +
			'<th>' + __('Layout', 'event-tickets-with-ticket-scanner') + '</th>' +
			'<th>' + __('Seats', 'event-tickets-with-ticket-scanner') + '</th>' +
			'<th>' + __('Status', 'event-tickets-with-ticket-scanner') + '</th>' +
			'<th>' + __('Actions', 'event-tickets-with-ticket-scanner') + '</th>' +
			'</tr></thead></table>');

		plansDataTable = $('#' + tableId).DataTable({
			language: {
				emptyTable: config.i18n.noPlans
			},
			responsive: true,
			searching: true,
			ordering: true,
			processing: false,
			serverSide: false,
			stateSave: true,
			data: plansData,
			order: [[1, 'asc']],
			columns: [
				{
					data: 'id',
					orderable: true,
					className: 'dt-center',
					width: 50
				},
				{
					data: 'name',
					orderable: true,
					render: (data) => '<strong>' + escapeHtml(data) + '</strong>'
				},
				{
					data: 'layout_type',
					orderable: true,
					render: (data) => data === 'visual'
						? __('Visual (Seat Map)', 'event-tickets-with-ticket-scanner')
						: __('Simple (Dropdown)', 'event-tickets-with-ticket-scanner')
				},
				{
					data: 'seat_count',
					orderable: true,
					className: 'dt-center',
					width: 60,
					render: (data) => data || 0
				},
				{
					data: 'aktiv',
					orderable: true,
					width: 80,
					render: (data) => data == 1
						? '<span class="saso-status-active">' + __('Active', 'event-tickets-with-ticket-scanner') + '</span>'
						: '<span class="saso-status-inactive">' + __('Inactive', 'event-tickets-with-ticket-scanner') + '</span>'
				},
				{
					data: null,
					orderable: false,
					className: 'dt-right',
					width: 250,
					render: (data, type, row) => {
						let buttons = '';
						// View button only if image exists (check > 0)
						if (row.meta?.image_id && parseInt(row.meta.image_id) > 0) {
							buttons += '<button type="button" class="button button-small saso-view-plan-image" title="' + __('View Venue Photo', 'event-tickets-with-ticket-scanner') + '"><span class="dashicons dashicons-format-image"></span></button> ';
						}
						// Open Designer button for visual layout plans
						if (row.layout_type === 'visual') {
							buttons += '<button type="button" class="button button-small button-primary saso-open-designer" title="' + __('Open Designer', 'event-tickets-with-ticket-scanner') + '"><span class="dashicons dashicons-layout"></span></button> ';
						}
						buttons += '<button type="button" class="button button-small saso-edit-plan" title="' + __('Edit', 'event-tickets-with-ticket-scanner') + '"><span class="dashicons dashicons-edit"></span></button> ';
						buttons += '<button type="button" class="button button-small saso-manage-seats" title="' + __('Manage Seats', 'event-tickets-with-ticket-scanner') + '"><span class="dashicons dashicons-grid-view"></span></button> ';
						buttons += '<button type="button" class="button button-small saso-clone-plan" title="' + __('Clone', 'event-tickets-with-ticket-scanner') + '"><span class="dashicons dashicons-admin-page"></span></button> ';
						buttons += '<button type="button" class="button button-small saso-delete-plan" title="' + __('Delete', 'event-tickets-with-ticket-scanner') + '"><span class="dashicons dashicons-trash"></span></button>';
						return buttons;
					}
				}
			]
		});

		// Set table width to 100%
		$('#' + tableId).css('width', '100%');

		// Event handlers for DataTable buttons - use .off() first to prevent duplicates
		const $tbody = $('#' + tableId + ' tbody');
		$tbody.off('click.sasoPlanActions');

		$tbody.on('click.sasoPlanActions', '.saso-view-plan-image', function() {
			const row = plansDataTable.row($(this).closest('tr'));
			const plan = row.data();
			if (plan?.meta?.image_id) showPlanImageModal(plan.meta.image_id, plan.name);
		});

		$tbody.on('click.sasoPlanActions', '.saso-edit-plan', function() {
			const row = plansDataTable.row($(this).closest('tr'));
			const plan = row.data();
			if (plan) openPlanModal(plan);
		});

		$tbody.on('click.sasoPlanActions', '.saso-manage-seats', function() {
			const row = plansDataTable.row($(this).closest('tr'));
			const plan = row.data();
			if (plan) showSeatsView(plan);
		});

		$tbody.on('click.sasoPlanActions', '.saso-delete-plan', function() {
			const row = plansDataTable.row($(this).closest('tr'));
			const plan = row.data();
			if (plan) deletePlan(plan.id, plan.seat_count || 0);
		});

		$tbody.on('click.sasoPlanActions', '.saso-clone-plan', function() {
			const row = plansDataTable.row($(this).closest('tr'));
			const plan = row.data();
			if (plan) clonePlan(plan.id);
		});

		$tbody.on('click.sasoPlanActions', '.saso-open-designer', function() {
			const row = plansDataTable.row($(this).closest('tr'));
			const plan = row.data();
			if (plan) openDesigner(plan);
		});
	}

	function updateAddPlanButton() {
		const $btn = $('.saso-add-plan');
		const isPremium = myAjax._isPremium || false;
		const max = myAjax._max?.seatingplans || 1;
		const allowed = isPremium || plansData.length < max;

		if (!allowed) {
			$btn.prop('disabled', true).attr('title', config.i18n.limitReached);
		} else {
			$btn.prop('disabled', false).removeAttr('title');
		}
	}

	function openPlanModal(plan) {
		const $modal = $('#saso-plan-editor-modal');
		const $form = $('#saso-plan-form');
		const isEdit = plan !== null;

		$form[0].reset();
		clearImagePreview();

		if (isEdit) {
			$form.find('[name="plan_id"]').val(plan.id);
			$form.find('[name="name"]').val(plan.name);
			$form.find('[name="description"]').val(plan.meta?.description || '');
			$form.find('[name="layout_type"]').val(plan.layout_type || 'simple');
			$form.find('[name="aktiv"]').prop('checked', plan.aktiv == 1);
			$modal.find('.saso-modal-title').text(__('Edit Seating Plan', 'event-tickets-with-ticket-scanner'));

			// Load image if exists (check > 0 to handle "0" string)
			if (plan.meta?.image_id && parseInt(plan.meta.image_id) > 0) {
				$form.find('[name="image_id"]').val(plan.meta.image_id);
				loadImagePreview(plan.meta.image_id);
			}
		} else {
			$form.find('[name="plan_id"]').val('');
			$form.find('[name="aktiv"]').prop('checked', true);
			$modal.find('.saso-modal-title').text(__('New Seating Plan', 'event-tickets-with-ticket-scanner'));
		}

		$modal.show();
	}

	let isSavingPlan = false;
	function savePlan() {
		// Prevent duplicate saves
		if (isSavingPlan) {
			console.warn('savePlan: Already saving, ignoring duplicate call');
			return;
		}
		isSavingPlan = true;

		const $form = $('#saso-plan-form');
		const planId = $form.find('[name="plan_id"]').val();
		const isEdit = planId !== '';

		const data = {
			name: $form.find('[name="name"]').val(),
			description: $form.find('[name="description"]').val(),
			layout_type: $form.find('[name="layout_type"]').val(),
			image_id: $form.find('[name="image_id"]').val() || '',
			aktiv: $form.find('[name="aktiv"]').is(':checked') ? 1 : 0
		};

		if (isEdit) {
			data.plan_id = planId;
		}

		const action = isEdit ? 'updatePlan' : 'createPlan';

		makeRequest(action, data, function(response) {
			isSavingPlan = false;
			$('#saso-plan-editor-modal').hide();
			showNotice(isEdit ? config.i18n.planUpdated : config.i18n.planCreated);
			loadPlans();
		}, function() {
			isSavingPlan = false;
		});
	}

	// Image handling - use 'close' event like backend.js _openMediaChooser
	function openMediaChooser() {
		if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
			alert('Media library not available');
			return;
		}

		const frame = wp.media({
			title: config.i18n.selectImage || 'Select Image',
			multiple: false,
			library: { type: 'image' }
		});

		// Use 'close' event (not 'select') - this fires when modal closes
		frame.on('close', function() {
			const selection = frame.state().get('selection');
			if (selection.length === 0) return; // User cancelled

			const attachment = selection.first().toJSON();
			const $modal = $('#saso-plan-editor-modal');
			$modal.find('[name="image_id"]').val(attachment.id);
			$modal.find('.saso-image-preview').html('<img src="' + attachment.url + '" alt="">');
			$modal.find('.saso-remove-image').show();
		});

		frame.open();
	}

	function loadImagePreview(imageId) {
		if (!imageId) return;

		BASIC._getMediaData(imageId, function(data) {
			if (data?.url) {
				const $modal = $('#saso-plan-editor-modal');
				$modal.find('.saso-image-preview').html('<img src="' + data.url + '" alt="">');
				$modal.find('.saso-remove-image').show();
			}
		});
	}

	function clearImagePreview() {
		const $modal = $('#saso-plan-editor-modal');
		$modal.find('[name="image_id"]').val('');
		$modal.find('.saso-image-preview').empty();
		$modal.find('.saso-remove-image').hide();
	}

	function deletePlan(planId, seatCount) {
		const confirmMsg = seatCount > 0 ? config.i18n.confirmDeleteWithSeats : config.i18n.confirmDelete;
		if (!confirm(confirmMsg)) return;

		makeRequest('deletePlan', { plan_id: planId, force: 'true' }, function(response) {
			showNotice(config.i18n.planDeleted);
			loadPlans();
		});
	}

	function clonePlan(planId) {
		makeRequest('clonePlan', { plan_id: planId }, function(response) {
			showNotice(config.i18n.planCloned);
			loadPlans();
		});
	}

	// =========================================================================
	// Visual Designer
	// =========================================================================

	let designerInstance = null;
	let designerScriptLoaded = false;

	/**
	 * Load designer script dynamically if not already loaded
	 */
	function loadDesignerScript(callback) {
		// Already loaded?
		if (typeof window.initSeatingDesigner === 'function') {
			callback();
			return;
		}

		let version = myAjax._plugin_version || new Date().getTime();
		const baseUrl = myAjax._plugin_home_url;

		if (BASIC._getVarSYSTEM().is_debug) {
			version = new Date().getTime();
		}

		// Load CSS first
		BASIC._addStyleTag(baseUrl + '/css/seating_designer.css?v=' + version, 'saso_seating_designer_css');

		// Load JS
		const jsUrl = baseUrl + '/js/seating_designer.js?v=' + version;
		$.getScript(jsUrl)
			.done(function() {
				designerScriptLoaded = true;
				callback();
			})
			.fail(function(jqxhr, settings, exception) {
				console.error('Failed to load seating_designer.js:', exception);
				showNotice(__('Failed to load designer script', 'event-tickets-with-ticket-scanner'), 'error');
			});
	}

	function openDesigner(plan) {
		const $wrap = $('.saso-seating-admin-wrap');

		// Destroy any existing designer BEFORE rendering new HTML
		// (otherwise destroy() would empty the new container)
		if (designerInstance) {
			designerInstance.destroy();
			designerInstance = null;
		}
		if (window.SasoSeatingDesigner) {
			window.SasoSeatingDesigner = null;
		}

		$wrap.html('<div class="saso-loading">' + config.i18n.loading + '</div>');

		// First load the designer script, then fetch data
		loadDesignerScript(function() {
			makeRequest('getDesignerPage', { plan_id: plan.id }, function(response) {
				// Generate designer HTML in JS (no HTML from server)
				$wrap.addClass('designer-mode').html(renderDesignerHTML(response));

				// Initialize designer with config and data
				if (typeof window.initSeatingDesigner === 'function') {
					designerInstance = window.initSeatingDesigner(response.config);
					// Apply loaded data
					if (designerInstance && designerInstance.applyLoadedData) {
						designerInstance.applyLoadedData(response);
					}
				} else {
					console.error('SeatingDesigner not loaded');
					showNotice(__('Designer failed to load', 'event-tickets-with-ticket-scanner'), 'error');
				}
			}, function(error) {
				showNotice(error || config.i18n.error, 'error');
				// Restore admin view
				$wrap.html(renderAdminHTML());
				initEventHandlers();
				loadPlans();
			});
		});
	}

	function renderDesignerHTML(response) {
		const plan = response.plan;
		// These are now inside plan object (from getFullPlan)
		const publishInfo = plan.publish_info;
		const auditInfo = plan.audit_info || {};
		const hasUnpublishedChanges = plan.has_unpublished_changes;

		const statusClass = plan.aktiv ? 'active' : 'inactive';
		const statusText = plan.aktiv ? __('Active', 'event-tickets-with-ticket-scanner') : __('Inactive', 'event-tickets-with-ticket-scanner');

		// Build status badges
		let badges = '<span class="saso-plan-status ' + statusClass + '">' + statusText + '</span>';

		// Published badge with date (clickable to view published version)
		if (publishInfo && publishInfo.published_at) {
			badges += '<span class="saso-plan-status published clickable" title="' + __('Click to view published version', 'event-tickets-with-ticket-scanner') + '">' +
				'<span class="dashicons dashicons-yes-alt"></span> ' +
				__('Published', 'event-tickets-with-ticket-scanner') +
				'<span class="saso-status-date">' + formatDate(publishInfo.published_at) + '</span>' +
				'</span>';
		}

		// Draft badge with date (if has unpublished changes, clickable to view/edit draft)
		if (hasUnpublishedChanges) {
			const draftDate = auditInfo.updated_at || '';
			badges += '<span class="saso-plan-status draft clickable viewing" title="' + __('Click to view/edit draft', 'event-tickets-with-ticket-scanner') + '">' +
				'<span class="dashicons dashicons-edit"></span> ' +
				__('Draft', 'event-tickets-with-ticket-scanner') +
				(draftDate ? '<span class="saso-status-date">' + formatDate(draftDate) + '</span>' : '') +
				'</span>';
		}

		return '<div class="saso-designer-container" id="saso-designer-container" data-plan-id="' + plan.id + '">' +
			'<div class="saso-designer-header">' +
				'<div class="header-left">' +
					'<button type="button" class="button saso-back-to-plans">' +
						'<span class="dashicons dashicons-arrow-left-alt"></span> ' +
						__('Back to Plans', 'event-tickets-with-ticket-scanner') +
					'</button>' +
					'<h2>' + plan.name + ' - ' + __('Visual Designer', 'event-tickets-with-ticket-scanner') + '</h2>' +
				'</div>' +
				'<div class="header-right">' +
					badges +
				'</div>' +
			'</div>' +
			'<div class="saso-designer-notices"></div>' +
			'<div class="saso-designer-wrap">' +
				'<div class="saso-designer-main">' +
					'<div class="saso-designer-toolbar-area"></div>' +
					'<div class="saso-designer-canvas-area">' +
						'<div class="saso-loading">' + __('Loading designer...', 'event-tickets-with-ticket-scanner') + '</div>' +
					'</div>' +
				'</div>' +
				'<div class="saso-designer-sidebar">' +
					'<div class="saso-designer-properties-area"></div>' +
					'<div class="saso-designer-actions-area"></div>' +
				'</div>' +
			'</div>' +
		'</div>';
	}

	/**
	 * Format date string for display
	 */
	function formatDate(dateStr) {
		if (!dateStr) return '';
		try {
			const date = new Date(dateStr);
			// Format: DD.MM.YY HH:MM
			const day = String(date.getDate()).padStart(2, '0');
			const month = String(date.getMonth() + 1).padStart(2, '0');
			const year = String(date.getFullYear()).slice(-2);
			const hours = String(date.getHours()).padStart(2, '0');
			const mins = String(date.getMinutes()).padStart(2, '0');
			return day + '.' + month + '.' + year + ' ' + hours + ':' + mins;
		} catch (e) {
			return dateStr;
		}
	}

	function closeDesigner() {
		if (designerInstance) {
			designerInstance.destroy();
			designerInstance = null;
		}
		// Restore admin view
		const $wrap = $('.saso-seating-admin-wrap');
		$wrap.removeClass('designer-mode').html(renderAdminHTML());
		initEventHandlers();
		loadPlans();
	}

	// Make closeDesigner available globally for designer to call
	window.sasoSeatingCloseDesigner = closeDesigner;

	// =========================================================================
	// Seats Management
	// =========================================================================

	function showSeatsView(plan) {
		currentPlanId = plan.id;
		currentPlanName = plan.name;
		currentPlanLayoutType = plan.layout_type || 'simple';

		$('.saso-seating-plans-section').hide();
		$('.saso-seating-seats-section').show();
		$('.saso-current-plan-name').text(plan.name);

		// Show layout explanation
		showLayoutExplanation(currentPlanLayoutType);

		// Show plan image if exists
		showPlanImage(plan.meta?.image_id);

		// Visual mode: disable Add Seat button, show note
		updateVisualModeUI();

		loadSeats();
	}

	/**
	 * Update UI for Visual layout mode
	 * In visual mode, seats should be added/deleted via the Visual Designer only
	 */
	function updateVisualModeUI() {
		const $addBtn = $('.saso-add-seat');
		const $note = $('.saso-visual-mode-note');

		if (currentPlanLayoutType === 'visual') {
			// Disable Add button in Visual mode
			$addBtn.prop('disabled', true)
				.attr('title', __('Use Visual Designer to add seats', 'event-tickets-with-ticket-scanner'));

			// Show note if not already shown
			if ($note.length === 0) {
				$('.saso-seating-seats-toolbar').after(
					'<div class="saso-visual-mode-note saso-notice saso-notice-info">' +
					'<span class="dashicons dashicons-info"></span> ' +
					__('This plan uses Visual Layout. Add and delete seats using the Visual Designer. You can edit seat details (label, category) here.', 'event-tickets-with-ticket-scanner') +
					'</div>'
				);
			}
		} else {
			// Simple mode: ensure Add button is enabled
			$addBtn.prop('disabled', false).removeAttr('title');
			$note.remove();
		}
	}

	function showPlanImage(imageId) {
		const $section = $('.saso-plan-image-section');
		const $container = $('.saso-plan-image-container');
		const $toggleBtn = $('.saso-toggle-plan-image');

		if (!imageId || imageId == 0) {
			$section.hide();
			$container.empty();
			$toggleBtn.hide();
			return;
		}

		BASIC._getMediaData(imageId, function(data) {
			if (data?.url) {
				$container.html('<img src="' + data.url + '" alt="">');
				$toggleBtn.show();
				$section.hide();
				updateToggleButtonText(false);
			} else {
				$section.hide();
				$toggleBtn.hide();
			}
		});
	}

	function showPlanImageModal(imageId, planName) {
		if (!imageId) return;

		BASIC._getMediaData(imageId, function(data) {
			if (data?.url) {
				// Create and show modal
				const $modal = $(`
					<div class="saso-modal saso-image-modal" style="display:flex;">
						<div class="saso-modal-content" style="max-width:90vw;max-height:90vh;">
							<div class="saso-modal-header">
								<h3 class="saso-modal-title">${escapeHtml(planName)}</h3>
								<button type="button" class="saso-modal-close">&times;</button>
							</div>
							<div class="saso-modal-body" style="padding:0;overflow:auto;">
								<img src="${data.url}" alt="${escapeHtml(planName)}" style="max-width:100%;height:auto;display:block;">
							</div>
						</div>
					</div>
				`);

				$modal.on('click', '.saso-modal-close', function() {
					$modal.remove();
				});
				$modal.on('click', function(e) {
					if (e.target === this) $modal.remove();
				});

				$('body').append($modal);
			}
		});
	}

	function togglePlanImage() {
		const $section = $('.saso-plan-image-section');
		const isVisible = $section.is(':visible');

		if (isVisible) {
			$section.slideUp(200);
		} else {
			$section.slideDown(200);
		}
		updateToggleButtonText(!isVisible);
	}

	function updateToggleButtonText(isVisible) {
		const $btn = $('.saso-toggle-plan-image');
		const showText = config.i18n.showLayout || 'Show Layout';
		const hideText = config.i18n.hideLayout || 'Hide Layout';

		$btn.find('.dashicons').removeClass('dashicons-hidden dashicons-format-image')
			.addClass(isVisible ? 'dashicons-hidden' : 'dashicons-format-image');
		$btn.contents().filter(function() { return this.nodeType === 3; }).remove();
		$btn.append(' ' + (isVisible ? hideText : showText));
	}

	function showLayoutExplanation(layoutType) {
		const $box = $('.saso-layout-explanation');
		let title, desc, icon;

		if (layoutType === 'visual') {
			title = config.i18n.layoutVisualTitle;
			desc = config.i18n.layoutVisualDesc;
			icon = 'dashicons-layout';
		} else {
			title = config.i18n.layoutSimpleTitle;
			desc = config.i18n.layoutSimpleDesc;
			icon = 'dashicons-list-view';
		}

		$box.html(
			'<div class="saso-layout-box">' +
			'<span class="dashicons ' + icon + '"></span>' +
			'<div class="saso-layout-text">' +
			'<strong>' + title + '</strong>' +
			'<p>' + desc + '</p>' +
			'</div></div>'
		).show();
	}

	function showPlansView() {
		currentPlanId = null;
		currentPlanName = '';
		currentPlanLayoutType = 'simple';

		// Remove visual mode note if exists
		$('.saso-visual-mode-note').remove();

		$('.saso-seating-seats-section').hide();
		$('.saso-seating-plans-section').show();
	}

	function loadSeats() {
		if (!currentPlanId) return;

		const $list = $('.saso-seating-seats-list');
		$list.html('<div class="saso-loading">' + config.i18n.loading + '</div>');

		makeRequest('getSeats', { plan_id: currentPlanId }, function(data) {
			seatsData = data.seats || [];
			renderSeats();
			updateSeatsCount();
			updateAddSeatButton();
		});
	}

	function renderSeats() {
		const $list = $('.saso-seating-seats-list');
		const tableId = 'saso-seats-datatable';

		// Destroy existing DataTable
		if (seatsDataTable) {
			seatsDataTable.destroy();
			seatsDataTable = null;
		}

		// Create table structure with checkbox column
		$list.html('<table id="' + tableId + '" class="display" style="width:100%">' +
			'<thead><tr>' +
			'<th class="saso-batch-checkbox-col"><input type="checkbox" class="saso-seat-select-all" title="' + __('Select All', 'event-tickets-with-ticket-scanner') + '"></th>' +
			'<th>' + __('Identifier', 'event-tickets-with-ticket-scanner') + '</th>' +
			'<th>' + __('Label', 'event-tickets-with-ticket-scanner') + '</th>' +
			'<th>' + __('Category', 'event-tickets-with-ticket-scanner') + '</th>' +
			'<th>' + __('Status', 'event-tickets-with-ticket-scanner') + '</th>' +
			'<th>' + __('Actions', 'event-tickets-with-ticket-scanner') + '</th>' +
			'</tr></thead></table>');

		seatsDataTable = $('#' + tableId).DataTable({
			language: {
				emptyTable: config.i18n.noSeats
			},
			responsive: true,
			searching: true,
			ordering: true,
			processing: false,
			serverSide: false,
			stateSave: false,
			pageLength: 25,
			data: seatsData,
			order: [[1, 'asc']],  // Sort by Identifier (column 1 now, checkbox is 0)
			columns: [
				{
					// Checkbox column
					data: null,
					orderable: false,
					className: 'dt-center saso-batch-checkbox-col',
					width: 30,
					render: (data, type, row) => '<input type="checkbox" class="saso-seat-checkbox" data-seat-id="' + row.id + '">'
				},
				{
					data: 'seat_identifier',
					orderable: true,
					render: (data) => '<code>' + escapeHtml(data) + '</code>'
				},
				{
					data: 'meta',
					orderable: true,
					render: (data) => escapeHtml(data?.seat_label || '-')
				},
				{
					data: 'meta',
					orderable: true,
					render: (data) => escapeHtml(data?.seat_category || '-')
				},
				{
					data: 'aktiv',
					orderable: true,
					width: 80,
					render: (data) => data == 1
						? '<span class="saso-status-active">' + __('Active', 'event-tickets-with-ticket-scanner') + '</span>'
						: '<span class="saso-status-inactive">' + __('Inactive', 'event-tickets-with-ticket-scanner') + '</span>'
				},
				{
					data: null,
					orderable: false,
					className: 'dt-right',
					width: 100,
					render: () => {
						let buttons = '<button type="button" class="button button-small saso-edit-seat" title="' + __('Edit', 'event-tickets-with-ticket-scanner') + '"><span class="dashicons dashicons-edit"></span></button> ';
						// In Visual mode, hide delete button (must use Designer)
						if (currentPlanLayoutType !== 'visual') {
							buttons += '<button type="button" class="button button-small saso-delete-seat" title="' + __('Delete', 'event-tickets-with-ticket-scanner') + '"><span class="dashicons dashicons-trash"></span></button>';
						}
						return buttons;
					}
				}
			]
		});

		// Set table width to 100%
		$('#' + tableId).css('width', '100%');

		// Event handlers for DataTable buttons - use .off() first to prevent duplicates
		const $seatsTbody = $('#' + tableId + ' tbody');
		$seatsTbody.off('click.sasoSeatActions');

		$seatsTbody.on('click.sasoSeatActions', '.saso-edit-seat', function() {
			const row = seatsDataTable.row($(this).closest('tr'));
			const seat = row.data();
			if (seat) openSeatModal(seat);
		});

		$seatsTbody.on('click.sasoSeatActions', '.saso-delete-seat', function() {
			const row = seatsDataTable.row($(this).closest('tr'));
			const seat = row.data();
			if (seat) deleteSeat(seat.id);
		});
	}

	function updateSeatsCount() {
		$('.saso-seats-count').text(sprintf(__('%d seats', 'event-tickets-with-ticket-scanner'), seatsData.length));
	}

	function updateAddSeatButton() {
		const $btn = $('.saso-add-seat');

		// In Visual mode, Add button is always disabled (use Designer)
		if (currentPlanLayoutType === 'visual') {
			$btn.prop('disabled', true)
				.attr('title', __('Use Visual Designer to add seats', 'event-tickets-with-ticket-scanner'));
			return;
		}

		// Simple mode: check limits
		const isPremium = myAjax._isPremium || false;
		const max = myAjax._max?.seats_per_plan || 20;
		const allowed = isPremium || seatsData.length < max;

		if (!allowed) {
			$btn.prop('disabled', true).attr('title', config.i18n.limitReached);
		} else {
			$btn.prop('disabled', false).removeAttr('title');
		}
	}

	function openSeatModal(seat) {
		const $modal = $('#saso-seat-editor-modal');
		const $form = $('#saso-seat-form');
		const isEdit = seat !== null;

		$form[0].reset();
		$form.find('[name="plan_id"]').val(currentPlanId);

		if (isEdit) {
			$form.find('[name="seat_id"]').val(seat.id);
			$form.find('[name="seat_identifier"]').val(seat.seat_identifier);
			$form.find('[name="seat_label"]').val(seat.meta?.seat_label || '');
			$form.find('[name="seat_category"]').val(seat.meta?.seat_category || '');
			$form.find('[name="aktiv"]').prop('checked', seat.aktiv == 1);
			$modal.find('.saso-modal-title').text(__('Edit Seat', 'event-tickets-with-ticket-scanner'));
		} else {
			$form.find('[name="seat_id"]').val('');
			$form.find('[name="aktiv"]').prop('checked', true);
			$modal.find('.saso-modal-title').text(__('New Seat', 'event-tickets-with-ticket-scanner'));
		}

		$modal.show();
	}

	function saveSeat(keepOpen) {
		const $form = $('#saso-seat-form');
		const $modal = $('#saso-seat-editor-modal');
		const seatId = $form.find('[name="seat_id"]').val();
		const isEdit = seatId !== '';

		const identifier = $form.find('[name="seat_identifier"]').val().trim();
		if (!identifier) {
			showNotice(__('Identifier is required', 'event-tickets-with-ticket-scanner'), 'error');
			$form.find('[name="seat_identifier"]').focus();
			return;
		}

		const data = {
			plan_id: $form.find('[name="plan_id"]').val(),
			seat_identifier: identifier,
			seat_label: $form.find('[name="seat_label"]').val(),
			seat_category: $form.find('[name="seat_category"]').val(),
			aktiv: $form.find('[name="aktiv"]').is(':checked') ? 1 : 0
		};

		if (isEdit) {
			data.seat_id = seatId;
		}

		const action = isEdit ? 'updateSeat' : 'createSeat';

		makeRequest(action, data, function() {
			showNotice(isEdit ? config.i18n.seatUpdated : config.i18n.seatCreated);
			loadSeats();

			if (keepOpen && !isEdit) {
				// Clear form for next entry, keep modal open
				$form.find('[name="seat_identifier"]').val('').focus();
				$form.find('[name="seat_label"]').val('');
				$form.find('[name="seat_category"]').val('');
				// Keep aktiv checked and plan_id
			} else {
				$modal.hide();
			}
		});
	}

	function deleteSeat(seatId) {
		if (!confirm(config.i18n.confirmDelete)) return;

		makeRequest('deleteSeat', { seat_id: seatId, force: 'true' }, function() {
			showNotice(config.i18n.seatDeleted);
			loadSeats();
		});
	}

	// =========================================================================
	// Batch Operations
	// =========================================================================

	function getSelectedSeatIds() {
		return $('.saso-seat-checkbox:checked').map(function() {
			return $(this).data('seat-id');
		}).get();
	}

	function updateBatchToolbar() {
		if (batchInProgress) return;  // Don't update during batch

		const count = getSelectedSeatIds().length;
		$('.saso-selected-count').text(count);
		$('.saso-batch-toolbar').toggle(count > 0);
		$('.saso-batch-execute').prop('disabled', count === 0 || !$('.saso-batch-action').val());

		// In Visual mode: hide delete option
		if (currentPlanLayoutType === 'visual') {
			$('.saso-batch-delete-option').hide();
		} else {
			$('.saso-batch-delete-option').show();
		}

		// Update select-all checkbox state
		const totalCheckboxes = $('.saso-seat-checkbox').length;
		const checkedCheckboxes = $('.saso-seat-checkbox:checked').length;
		$('.saso-seat-select-all').prop('checked', totalCheckboxes > 0 && checkedCheckboxes === totalCheckboxes);
	}

	function setBatchUIState(inProgress, current, total) {
		batchInProgress = inProgress;

		// Disable/Enable UI elements
		$('.saso-seat-checkbox, .saso-seat-select-all, .saso-batch-action, .saso-batch-execute, .saso-add-seat, .saso-back-to-plans')
			.prop('disabled', inProgress);

		// Show/hide progress
		$('.saso-batch-progress').toggle(inProgress);
		if (inProgress && total > 0) {
			$('.saso-batch-progress-text').text(current + '/' + total);
		}
	}

	function executeBatchAction() {
		if (batchInProgress) return;

		// IMPORTANT: Capture all values BEFORE the loop!
		const ids = getSelectedSeatIds().slice();  // Copy array
		const action = $('.saso-batch-action').val();

		if (!ids.length || !action) return;

		if (action === 'delete') {
			if (!confirm(sprintf(config.i18n.batchConfirmDelete, ids.length))) return;
			runBatchOperation(ids, 'delete', {});
		} else if (action === 'activate') {
			runBatchOperation(ids, 'update', { aktiv: 1 });
		} else if (action === 'deactivate') {
			runBatchOperation(ids, 'update', { aktiv: 0 });
		}
	}

	function runBatchOperation(ids, operation, updateData) {
		const total = ids.length;
		const useDelay = total > 10;  // Anti-DDOS: 500ms delay for >10 items
		let completed = 0;
		let errors = 0;
		let index = 0;

		setBatchUIState(true, 0, total);

		function processNext() {
			if (index >= ids.length) {
				// Done
				finishBatch(completed, errors, total, operation);
				return;
			}

			const id = ids[index];
			index++;

			const onSuccess = function() {
				completed++;
				setBatchUIState(true, completed, total);
				scheduleNext();
			};

			const onError = function() {
				errors++;
				completed++;
				setBatchUIState(true, completed, total);
				scheduleNext();
			};

			if (operation === 'delete') {
				makeRequest('deleteSeat', { seat_id: id, force: 'true' }, onSuccess, onError);
			} else {
				const requestData = Object.assign({ seat_id: id }, updateData);
				makeRequest('updateSeat', requestData, onSuccess, onError);
			}
		}

		function scheduleNext() {
			if (useDelay) {
				setTimeout(processNext, 500);
			} else {
				processNext();
			}
		}

		// Start
		processNext();
	}

	function finishBatch(completed, errors, total, operation) {
		setBatchUIState(false, 0, 0);

		const success = total - errors;

		if (errors > 0) {
			showNotice(sprintf(config.i18n.batchWithErrors, success, errors), 'warning');
		} else {
			if (operation === 'delete') {
				showNotice(sprintf(config.i18n.batchSeatsDeleted, success));
			} else {
				showNotice(sprintf(config.i18n.batchSeatsUpdated, success));
			}
		}

		// Reset selection and reload
		$('.saso-batch-action').val('');
		$('.saso-seat-select-all').prop('checked', false);
		loadSeats();
	}

	// =========================================================================
	// Utility Functions
	// =========================================================================

	function escapeHtml(text) {
		if (!text) return '';
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	// =========================================================================
	// Event Handlers
	// =========================================================================

	function initEventHandlers() {
		const $wrap = $('.saso-seating-admin-wrap');

		// IMPORTANT: Remove all existing handlers first to prevent duplicates
		$wrap.off('click.sasoSeating');
		$(document).off('keydown.sasoSeatingAdmin');

		// Toolbar buttons (not in DataTable) - use namespaced events
		$wrap.on('click.sasoSeating', '.saso-add-plan', function() { openPlanModal(null); });
		$wrap.on('click.sasoSeating', '.saso-back-to-plans', function() { showPlansView(); });
		$wrap.on('click.sasoSeating', '.saso-add-seat', function() { openSeatModal(null); });

		// Modal buttons
		$wrap.on('click.sasoSeating', '.saso-save-plan', function() { savePlan(); });
		$wrap.on('click.sasoSeating', '.saso-save-seat', function() { saveSeat(false); });
		$wrap.on('click.sasoSeating', '.saso-save-seat-next', function() { saveSeat(true); });

		// Image handlers
		$wrap.on('click.sasoSeating', '.saso-select-image', function() { openMediaChooser(); });
		$wrap.on('click.sasoSeating', '.saso-remove-image', function() { clearImagePreview(); });
		$wrap.on('click.sasoSeating', '.saso-toggle-plan-image', function() { togglePlanImage(); });

		// Modal close
		$wrap.on('click.sasoSeating', '.saso-modal-close, .saso-modal-cancel', function() {
			$(this).closest('.saso-modal').hide();
		});
		$wrap.on('click.sasoSeating', '.saso-modal', function(e) {
			if (e.target === this) $(this).hide();
		});
		// Use namespaced event so we can remove it later
		$(document).on('keydown.sasoSeatingAdmin', function(e) {
			if (e.key === 'Escape') $('.saso-modal:visible').hide();
		});

		// Batch operations
		$wrap.on('change.sasoSeating', '.saso-seat-checkbox', function() {
			updateBatchToolbar();
		});
		$wrap.on('change.sasoSeating', '.saso-seat-select-all', function() {
			const checked = $(this).prop('checked');
			$('.saso-seat-checkbox').prop('checked', checked);
			updateBatchToolbar();
		});
		$wrap.on('change.sasoSeating', '.saso-batch-action', function() {
			updateBatchToolbar();
		});
		$wrap.on('click.sasoSeating', '.saso-batch-execute', function() {
			executeBatchAction();
		});
	}

	// =========================================================================
	// HTML Templates
	// =========================================================================

	function renderAdminHTML() {
		const isPremium = myAjax._isPremium || false;
		const max = myAjax._max?.seatingplans || 1;
		const limitsText = !isPremium ?
			sprintf(__('Free Version: %d/%d plans', 'event-tickets-with-ticket-scanner'), plansData.length, max) : '';

		return `
		<div class="saso-seating-admin-wrap">
			<div class="saso-seating-header">
				<h2>${__('Seating Plans', 'event-tickets-with-ticket-scanner')}</h2>
				${!isPremium ? `
				<div class="saso-seating-limits">
					${limitsText}
					<a href="https://vollstart.com/event-tickets-with-ticket-scanner/" target="_blank" class="button">
						${__('Upgrade to Premium', 'event-tickets-with-ticket-scanner')}
					</a>
				</div>` : ''}
			</div>

			<!-- Plans List -->
			<div class="saso-seating-plans-section">
				<div class="saso-seating-toolbar">
					<button type="button" class="button button-primary saso-add-plan">
						${__('+ Add Seating Plan', 'event-tickets-with-ticket-scanner')}
					</button>
				</div>
				<div class="saso-seating-plans-list">
					<div class="saso-loading">${config.i18n.loading}</div>
				</div>
			</div>

			<!-- Plan Editor Modal -->
			${renderPlanModal()}

			<!-- Seats Section -->
			<div class="saso-seating-seats-section" style="display:none;">
				<div class="saso-seating-seats-header">
					<h3>${__('Seats', 'event-tickets-with-ticket-scanner')} - <span class="saso-current-plan-name"></span></h3>
					<button type="button" class="button saso-back-to-plans">&larr; ${__('Back to Plans', 'event-tickets-with-ticket-scanner')}</button>
				</div>
				<div class="saso-layout-explanation" style="display:none;"></div>
				<div class="saso-seating-seats-toolbar">
					<button type="button" class="button button-primary saso-add-seat">${__('+ Add Seat', 'event-tickets-with-ticket-scanner')}</button>
					<button type="button" class="button saso-toggle-plan-image" style="display:none;">
						<span class="dashicons dashicons-format-image"></span>
						${__('Show Layout', 'event-tickets-with-ticket-scanner')}
					</button>
					<span class="saso-seats-count"></span>
				</div>
				<div class="saso-batch-toolbar" style="display:none;">
					<span class="saso-batch-selection">
						<span class="saso-selected-count">0</span> ${config.i18n.selected}
					</span>
					<select class="saso-batch-action">
						<option value="">${config.i18n.batchActionSelect}</option>
						<option value="activate">${config.i18n.batchActivate}</option>
						<option value="deactivate">${config.i18n.batchDeactivate}</option>
						<option value="delete" class="saso-batch-delete-option">${config.i18n.batchDelete}</option>
					</select>
					<button type="button" class="button saso-batch-execute" disabled>${__('Apply', 'event-tickets-with-ticket-scanner')}</button>
					<span class="saso-batch-progress" style="display:none;">
						<span class="spinner is-active" style="float:none;margin:0 5px;"></span>
						<span class="saso-batch-progress-text">0/0</span>
					</span>
				</div>
				<div class="saso-seating-seats-list"></div>
				<div class="saso-plan-image-section" style="display:none;">
					<h4>${__('Seating Plan Layout', 'event-tickets-with-ticket-scanner')}</h4>
					<div class="saso-plan-image-container"></div>
				</div>
			</div>

			<!-- Seat Editor Modal -->
			${renderSeatModal()}
		</div>`;
	}

	function renderPlanModal() {
		return `
		<div id="saso-plan-editor-modal" class="saso-modal" style="display:none;">
			<div class="saso-modal-content">
				<div class="saso-modal-header">
					<h3 class="saso-modal-title">${__('Seating Plan', 'event-tickets-with-ticket-scanner')}</h3>
					<button type="button" class="saso-modal-close">&times;</button>
				</div>
				<div class="saso-modal-body">
					<form id="saso-plan-form">
						<input type="hidden" name="plan_id" value="">
						<div class="saso-form-group">
							<label for="plan_name">${__('Plan Name', 'event-tickets-with-ticket-scanner')} <span class="required">*</span></label>
							<input type="text" id="plan_name" name="name" required class="regular-text">
						</div>
						<div class="saso-form-group">
							<label for="plan_description">${__('Description', 'event-tickets-with-ticket-scanner')}</label>
							<textarea id="plan_description" name="description" rows="3" class="large-text"></textarea>
						</div>
						<div class="saso-form-row">
							<div class="saso-form-group">
								<label for="plan_layout_type">${__('Layout Type', 'event-tickets-with-ticket-scanner')}</label>
								<select id="plan_layout_type" name="layout_type">
									<option value="simple">${__('Simple (Dropdown)', 'event-tickets-with-ticket-scanner')}</option>
									<option value="visual">${__('Visual (Seat Map)', 'event-tickets-with-ticket-scanner')}</option>
								</select>
							</div>
							<div class="saso-form-group">
								<label><input type="checkbox" name="aktiv" value="1" checked> ${__('Active', 'event-tickets-with-ticket-scanner')}</label>
							</div>
						</div>
						<div class="saso-form-group">
							<label>${__('Venue Photo', 'event-tickets-with-ticket-scanner')} <span class="optional">(${__('optional', 'event-tickets-with-ticket-scanner')})</span></label>
							<p class="description">${__('Optional: Upload a photo of the entire venue/hall. Customers can view this via "View Seating Plan" button. Useful when the seat map only shows a specific section.', 'event-tickets-with-ticket-scanner')}</p>
							<input type="hidden" name="image_id" id="plan_image_id" value="">
							<div class="saso-image-preview" id="plan_image_preview"></div>
							<div class="saso-image-buttons">
								<button type="button" class="button saso-select-image">${__('Select Image', 'event-tickets-with-ticket-scanner')}</button>
								<button type="button" class="button saso-remove-image" style="display:none;">${__('Remove', 'event-tickets-with-ticket-scanner')}</button>
							</div>
						</div>
					</form>
				</div>
				<div class="saso-modal-footer">
					<button type="button" class="button saso-modal-cancel">${__('Cancel', 'event-tickets-with-ticket-scanner')}</button>
					<button type="button" class="button button-primary saso-save-plan">${__('Save Plan', 'event-tickets-with-ticket-scanner')}</button>
				</div>
			</div>
		</div>`;
	}

	function renderSeatModal() {
		return `
		<div id="saso-seat-editor-modal" class="saso-modal" style="display:none;">
			<div class="saso-modal-content">
				<div class="saso-modal-header">
					<h3 class="saso-modal-title">${__('Seat', 'event-tickets-with-ticket-scanner')}</h3>
					<button type="button" class="saso-modal-close">&times;</button>
				</div>
				<div class="saso-modal-body">
					<form id="saso-seat-form">
						<input type="hidden" name="seat_id" value="">
						<input type="hidden" name="plan_id" value="">
						<div class="saso-form-group">
							<label for="seat_identifier">${__('Seat Identifier', 'event-tickets-with-ticket-scanner')} <span class="required">*</span></label>
							<input type="text" id="seat_identifier" name="seat_identifier" required class="regular-text" placeholder="A-1">
							<p class="description">${__('Unique ID like A-1, B-2, VIP-01', 'event-tickets-with-ticket-scanner')}</p>
						</div>
						<div class="saso-form-group">
							<label for="seat_label">${__('Display Label', 'event-tickets-with-ticket-scanner')}</label>
							<input type="text" id="seat_label" name="seat_label" class="regular-text" placeholder="Row A, Seat 1">
							<p class="description">${__('Shown on ticket PDF', 'event-tickets-with-ticket-scanner')}</p>
						</div>
						<div class="saso-form-group">
							<label for="seat_category">${__('Category', 'event-tickets-with-ticket-scanner')}</label>
							<input type="text" id="seat_category" name="seat_category" class="regular-text" placeholder="VIP, Standard, Balcony">
						</div>
						<div class="saso-form-group">
							<label><input type="checkbox" name="aktiv" value="1" checked> ${__('Active', 'event-tickets-with-ticket-scanner')}</label>
						</div>
					</form>
				</div>
				<div class="saso-modal-footer">
					<button type="button" class="button saso-modal-cancel">${__('Cancel', 'event-tickets-with-ticket-scanner')}</button>
					<button type="button" class="button saso-save-seat-next">${__('Save & Add Next', 'event-tickets-with-ticket-scanner')}</button>
					<button type="button" class="button button-primary saso-save-seat">${__('Save & Close', 'event-tickets-with-ticket-scanner')}</button>
				</div>
			</div>
		</div>`;
	}

	// =========================================================================
	// Init
	// =========================================================================

	function cleanup() {
		// Destroy designer if open
		if (designerInstance) {
			designerInstance.destroy();
			designerInstance = null;
		}

		// Remove all namespaced event handlers to prevent duplicates
		$('.saso-seating-admin-wrap').off('click.sasoSeating');
		$(document).off('keydown.sasoSeatingAdmin');

		// Destroy DataTables
		if (plansDataTable) {
			plansDataTable.destroy();
			plansDataTable = null;
		}
		if (seatsDataTable) {
			seatsDataTable.destroy();
			seatsDataTable = null;
		}
	}

	function initAdmin(div) {
		// Clean up any previous state first
		cleanup();

		adminDiv = div;
		adminDiv.html(renderAdminHTML());
		initEventHandlers();
		loadPlans();
	}

	return {
		initAdmin: initAdmin,
		cleanup: cleanup
	};
}
