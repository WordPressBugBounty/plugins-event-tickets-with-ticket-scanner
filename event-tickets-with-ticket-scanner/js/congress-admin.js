/* global jQuery, sasoEtCongress, wp */
(function ($) {
    'use strict';

    var __ = wp.i18n.__;
    var ajaxUrl = '';
    var nonce   = '';
    var $app    = null;
    var dtTable = null;
    var layout  = null;

    function escAttr(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function post(congressAction, data, cb, rawResponse) {
        $.post(ajaxUrl, Object.assign({
            action: 'saso_et_congress',
            congress_action: congressAction,
            _ajax_nonce: nonce
        }, data), function (r) {
            if (rawResponse) { cb(r); return; }
            if (r && r.success) {
                cb(r.data);
            } else {
                layout && layout.renderSpinnerHide();
                layout
                    ? layout.renderFatalError((r && r.data) || __('Error', 'event-tickets-with-ticket-scanner'))
                    : alert((r && r.data) || __('Error', 'event-tickets-with-ticket-scanner'));
            }
        });
    }

    // ── List view ─────────────────────────────────────────────

    function renderListView() {
        $app.html('');

        var $card = $('<div class="et-card">').appendTo($app);

        $('<div style="display:flex;gap:8px;justify-content:flex-end;margin-bottom:12px;">')
            .append(
                $('<button>').addClass('button-primary').html(__('New congress', 'event-tickets-with-ticket-scanner'))
                    .on('click', function () { openEditor(null); })
            )
            .appendTo($card);

        var $tbl = $('<table/>').attr('id', 'congress-dt');
        $tbl.html(
            '<thead><tr>' +
            '<th class="dt-left">' + __('Title', 'event-tickets-with-ticket-scanner') + '</th>' +
            '<th class="dt-left">Slug</th>' +
            '<th class="dt-right">' + __('Products', 'event-tickets-with-ticket-scanner') + '</th>' +
            '<th class="dt-center">' + __('Updated', 'event-tickets-with-ticket-scanner') + '</th>' +
            '<th class="dt-center">' + __('Status', 'event-tickets-with-ticket-scanner') + '</th>' +
            '<th></th>' +
            '</tr></thead>'
        );
        $('<div>').append($tbl).appendTo($card);

        if (dtTable) { try { dtTable.destroy(); } catch (e) {} dtTable = null; }

        dtTable = $tbl.DataTable({
            responsive:  true,
            searching:   true,
            ordering:    true,
            processing:  true,
            serverSide:  true,
            stateSave:   false,
            pageLength:  25,
            ajax: {
                url:  ajaxUrl,
                type: 'POST',
                data: { action: 'saso_et_congress', congress_action: 'list', _ajax_nonce: nonce }
            },
            columns: [
                { data: 'title',         className: 'dt-left' },
                { data: 'slug',          className: 'dt-left' },
                { data: 'product_count', className: 'dt-right' },
                { data: 'updated_at',    className: 'dt-center' },
                { data: 'status',        className: 'dt-center', render: function (d) {
                    var cls = d === 'active' ? 'congress-badge-active' : 'congress-badge-expired';
                    var lbl = d === 'active' ? __('Active', 'event-tickets-with-ticket-scanner') : __('Expired', 'event-tickets-with-ticket-scanner');
                    return '<span class="congress-badge ' + cls + '">' + lbl + '</span>';
                }},
                { data: 'id', orderable: false, className: 'dt-right', render: function (id) {
                    return '<div class="et-btn-group"><button class="et-btn-action congress-btn-edit" data-id="' + id + '">' + __('Edit', 'event-tickets-with-ticket-scanner') + '</button><button class="et-btn-action congress-btn-edition" data-id="' + id + '">' + __('New edition', 'event-tickets-with-ticket-scanner') + '</button></div><div class="et-btn-group et-btn-group--danger"><button class="et-btn-action et-btn-action--danger congress-btn-delete" data-id="' + id + '">' + __('Delete', 'event-tickets-with-ticket-scanner') + '</button></div>';
                }}
            ]
        });
        $tbl.css('width', '100%');

        $('<div class="notice notice-info" style="margin:16px 0 0;padding:10px 14px;">')
            .html(
                '<span class="dashicons dashicons-info-outline" style="color:var(--et-primary);margin-right:6px;vertical-align:middle;"></span>' +
                __('Congresses let you attach structured information (programme, downloads, media, texts) to an event. Ticket holders can access the congress page using their ticket ID. Assign a congress to a product in the WooCommerce product settings — one congress can be shared across multiple products.', 'event-tickets-with-ticket-scanner')
            )
            .appendTo($app);

        $app.on('click', '.congress-btn-edit', function () { openEditor($(this).data('id')); });
        $app.on('click', '.congress-btn-delete', function () {
            var id = $(this).data('id');
            layout.renderYesNo(
                __('Delete this congress?', 'event-tickets-with-ticket-scanner'),
                __('This action cannot be undone. All sections will be deleted as well.', 'event-tickets-with-ticket-scanner'),
                function () { post('delete', { id: id }, function () { dtTable.ajax.reload(); }); }
            );
        });
        $app.on('click', '.congress-btn-edition', function () {
            var id = $(this).data('id');
            // Pull the current congress' title/slug from the DataTable row as a reference
            var rowData = dtTable ? dtTable.row($(this).closest('tr')).data() : null;
            var curTitle = rowData ? (rowData.title || '') : '';
            var curSlug  = rowData ? (rowData.slug || '') : '';

            var dlg = $('<div/>');
            if (curTitle || curSlug) {
                dlg.append(
                    $('<div class="ce-dialog-meta" style="margin:0 0 14px;">').html(
                        '<span><b>' + __('Current title:', 'event-tickets-with-ticket-scanner') + '</b> ' + curTitle + '</span>' +
                        '<span><b>' + __('Current slug:', 'event-tickets-with-ticket-scanner') + '</b> ' + curSlug + '</span>'
                    )
                );
            }
            dlg.append(
                $('<p>').html('<label>' + __('Slug for new edition:', 'event-tickets-with-ticket-scanner') + '<br><input type="text" class="ce-new-slug" style="width:100%;margin-top:4px;"></label>'),
                $('<p style="margin-top:8px;">').html('<label>' + __('Title for new edition (leave empty to auto-generate):', 'event-tickets-with-ticket-scanner') + '<br><input type="text" class="ce-new-title" style="width:100%;margin-top:4px;"></label>')
            );
            // Show old values as placeholders to make it easy to derive the new ones
            dlg.find('.ce-new-slug').attr('placeholder', curSlug);
            dlg.find('.ce-new-title').attr('placeholder', curTitle);
            dlg.dialog({
                title:    __('New edition', 'event-tickets-with-ticket-scanner'),
                modal:    true,
                minWidth: 460,
                buttons: [{
                    text:  __('Create', 'event-tickets-with-ticket-scanner'),
                    class: 'button-primary',
                    click: function () {
                        var newSlug  = dlg.find('.ce-new-slug').val().trim();
                        var newTitle = dlg.find('.ce-new-title').val().trim();
                        if (!newSlug) { dlg.find('.ce-new-slug').focus(); return; }
                        $(this).dialog('close');
                        post('duplicate', { id: id, new_slug: newSlug, new_title: newTitle }, function (data) {
                            dtTable.ajax.reload();
                            openEditor(data.id);
                        });
                    }
                }, {
                    text:  __('Cancel', 'event-tickets-with-ticket-scanner'),
                    class: 'button-secondary',
                    click: function () { $(this).dialog('close'); }
                }]
            });
        });
    }

    // ── Editor ────────────────────────────────────────────────

    function openEditor(id) {
        if (id) {
            layout && layout.renderSpinnerShow();
            post('get', { id: id }, function (congress) {
                layout && layout.renderSpinnerHide();
                renderEditor(congress);
            });
        } else {
            renderEditor(null);
        }
    }

    function renderEditor(congress) {
        var c = congress || { id: '', title: '', slug: '', access_expires_at: '', is_active: 1, product_ids: [], sections: [] };
        var isNew = !c.id;
        var heading = isNew ? __('New congress', 'event-tickets-with-ticket-scanner') : __('Edit congress', 'event-tickets-with-ticket-scanner');
        var toLocal = function (v) { return (v || '').replace(' ', 'T').substring(0, 16); };
        var expiresVal = toLocal(c.access_expires_at);
        var productCount = (c.product_ids && c.product_ids.length) ? c.product_ids.length : 0;

        var $editor = $('<div class="ce-editor">');

        // Heading row with back-link
        $editor.append(
            $('<div class="ce-editor-heading">').append(
                $('<h2>').text(heading)
            )
        );

        // ── Details card ──
        var $detailsCard = $('<div class="et-card">').appendTo($editor);
        $detailsCard.append('<div class="et-card-header"><span class="dashicons dashicons-admin-settings" style="margin-right:6px;color:var(--et-primary);"></span>' + __('Details', 'event-tickets-with-ticket-scanner') + '</div>');
        $('<input type="hidden" id="ce-id">').val(c.id || '').appendTo($detailsCard);

        var $grid = $('<div class="ce-form-grid">').appendTo($detailsCard);

        // Title
        $grid.append(
            $('<div class="ce-field ce-field--full">').append(
                $('<label class="ce-label" for="ce-title">').html(__('Title', 'event-tickets-with-ticket-scanner') + '<span class="ce-required">*</span>'),
                $('<input type="text" id="ce-title" class="ce-input">').val(c.title || '')
            )
        );

        // Slug
        var $slugAuto = $('<span class="ce-slug-auto">').html('<span class="dashicons dashicons-admin-links" style="font-size:12px;width:12px;height:12px;margin-right:2px;vertical-align:middle;"></span> auto');
        var $slugField = $('<div class="ce-field ce-field--full">').append(
            $('<label class="ce-label" for="ce-slug">').html('Slug <span class="ce-required">*</span>'),
            $('<div class="ce-slug-wrap">').append(
                $('<input type="text" id="ce-slug" class="ce-input">').val(c.slug || ''),
                $slugAuto
            ),
            $('<span class="ce-hint">').text(__('Unique identifier for this congress. Visitors open it through their personal ticket link, not the slug.', 'event-tickets-with-ticket-scanner'))
        );
        $grid.append($slugField);

        // Access expires (manual hard cutoff)
        $grid.append(
            $('<div class="ce-field">').append(
                $('<label class="ce-label" for="ce-expires">').text(__('Access expires (optional)', 'event-tickets-with-ticket-scanner')),
                $('<input type="datetime-local" id="ce-expires" class="ce-input">').val(expiresVal),
                $('<span class="ce-hint">').text(__('Manual hard cutoff. Leave empty for unlimited access.', 'event-tickets-with-ticket-scanner'))
            )
        );

        // Active / visible to ticket holders
        var activeChecked = (typeof c.is_active === 'undefined') ? true : (parseInt(c.is_active, 10) === 1);
        $grid.append(
            $('<div class="ce-field ce-field--full">').append(
                $('<label class="ce-label" style="display:flex;align-items:center;gap:8px;font-weight:normal;">').append(
                    $('<input type="checkbox" id="ce-is-active">').prop('checked', activeChecked),
                    document.createTextNode(__('Active — visible to ticket holders', 'event-tickets-with-ticket-scanner'))
                ),
                $('<span class="ce-hint">').text(__('Turn off to hide this congress everywhere (page + wallet) without unassigning it — e.g. to publish the info only later.', 'event-tickets-with-ticket-scanner'))
            )
        );

        // Products
        var $productsInfo = $('<div class="ce-field">').append(
            $('<label class="ce-label">').text(__('Products', 'event-tickets-with-ticket-scanner')),
            $('<div class="ce-products-info">').append(
                $('<span class="dashicons dashicons-products" style="color:var(--et-primary);flex-shrink:0;">'),
                productCount
                    ? $('<span>').html('<span class="ce-products-count">' + productCount + '</span> ' + __('assigned (manage on product level)', 'event-tickets-with-ticket-scanner'))
                    : $('<span>').text(__('Assign via the WooCommerce product settings.', 'event-tickets-with-ticket-scanner'))
            )
        );
        $grid.append($productsInfo);

        // ── Pages card (pages → sections) ──
        var $sectionsCard = $('<div class="et-card">').appendTo($editor);
        if (isNew) {
            $sectionsCard.addClass('ce-card-disabled');
            $sectionsCard.prepend(
                $('<div class="ce-card-disabled-hint">').html(
                    '<span class="dashicons dashicons-lock" style="font-size:14px;width:14px;height:14px;flex-shrink:0;"></span>' +
                    __('Save the congress first to add pages and sections.', 'event-tickets-with-ticket-scanner')
                )
            );
        }

        var $sectionsHeader = $('<div class="ce-sections-header">').appendTo($sectionsCard);
        $sectionsHeader.append($('<span class="ce-sections-title">').html('<span class="dashicons dashicons-list-view" style="margin-right:6px;color:var(--et-primary);vertical-align:middle;"></span>' + __('Pages', 'event-tickets-with-ticket-scanner')));
        $('<button class="button-primary" id="ce-add-page">').html(
            '<span class="dashicons dashicons-plus-alt2" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:3px;"></span>' + __('Add page', 'event-tickets-with-ticket-scanner')
        ).appendTo($sectionsHeader);

        $('<p class="ce-dialog-hint" style="margin:0 0 10px;">').html(
            '<span class="dashicons dashicons-info" style="font-size:13px;width:13px;height:13px;vertical-align:middle;margin-right:3px;"></span>' +
            __('The first page is the start page (shown first to visitors). Drag pages and sections to reorder.', 'event-tickets-with-ticket-scanner')
        ).appendTo($sectionsCard);

        $('<div id="ce-pages-list">').appendTo($sectionsCard);

        // ── Action bar ──
        var $actions = $('<div class="ce-actions">').appendTo($editor);
        $('<button class="button" id="ce-cancel">').html(
            '<span class="dashicons dashicons-arrow-left-alt" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:3px;"></span>' + __('Cancel', 'event-tickets-with-ticket-scanner')
        ).appendTo($actions);
        $('<div class="ce-actions-right">').append(
            $('<button class="button-primary" id="ce-save">').html(
                '<span class="dashicons dashicons-saved" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:3px;"></span>' + __('Save congress', 'event-tickets-with-ticket-scanner')
            )
        ).appendTo($actions);

        $app.html('').append($editor);
        if (c.id) { loadPagesTree(c.id); }

        // Auto-generate slug from title
        var slugManuallyEdited = (c.slug !== '');
        if (!slugManuallyEdited) { $slugAuto.addClass('active'); }

        $app.off('input', '#ce-slug').on('input', '#ce-slug', function () {
            slugManuallyEdited = true;
            $slugAuto.removeClass('active');
        });
        $app.off('input', '#ce-title').on('input', '#ce-title', function () {
            if (slugManuallyEdited) return;
            var slug = $(this).val()
                .toLowerCase()
                .replace(/[äöüß]/g, function (ch) { return { ä: 'ae', ö: 'oe', ü: 'ue', ß: 'ss' }[ch]; })
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
            $('#ce-slug').val(slug);
        });

        $app.off('click', '#ce-cancel').on('click', '#ce-cancel', function () {
            $app.off('click', '#ce-cancel').off('click', '#ce-save').off('click', '#ce-add-page')
                .off('click', '.ce-page-rename').off('click', '.ce-page-delete').off('click', '.ce-page-add-section')
                .off('click', '.section-btn-edit').off('click', '.section-btn-delete');
            renderListView();
        });
        $app.off('click', '#ce-save').on('click', '#ce-save', saveCongress);

        $app.off('click', '#ce-add-page').on('click', '#ce-add-page', function () {
            var cid = parseInt($('#ce-id').val(), 10);
            if (!cid) return;
            _promptPageTitle('', __('Add page', 'event-tickets-with-ticket-scanner'), function (title) {
                layout && layout.renderSpinnerShow();
                post('save_page', { congress_id: cid, page_id: 0, title: title }, function () {
                    layout && layout.renderSpinnerHide();
                    loadPagesTree(cid);
                });
            });
        });

        $app.off('click', '.ce-page-rename').on('click', '.ce-page-rename', function () {
            var $block = $(this).closest('.ce-page-block');
            var pid = $block.data('page-id');
            var cid = parseInt($('#ce-id').val(), 10);
            var cur = $block.find('.ce-page-name').first().text();
            _promptPageTitle(cur, __('Rename page', 'event-tickets-with-ticket-scanner'), function (title) {
                layout && layout.renderSpinnerShow();
                post('save_page', { congress_id: cid, page_id: pid, title: title }, function () {
                    layout && layout.renderSpinnerHide();
                    loadPagesTree(cid);
                });
            });
        });

        $app.off('click', '.ce-page-delete').on('click', '.ce-page-delete', function () {
            var pid = $(this).closest('.ce-page-block').data('page-id');
            var cid = parseInt($('#ce-id').val(), 10);
            layout.renderYesNo(
                __('Delete page?', 'event-tickets-with-ticket-scanner'),
                __('Its sections will be moved to the start page. This cannot be undone.', 'event-tickets-with-ticket-scanner'),
                function () {
                    layout && layout.renderSpinnerShow();
                    post('delete_page', { page_id: pid }, function (resp, ok) {
                        layout && layout.renderSpinnerHide();
                        loadPagesTree(cid);
                    }, true);
                }
            );
        });

        $app.off('click', '.ce-page-add-section').on('click', '.ce-page-add-section', function () {
            var pid = $(this).closest('.ce-page-block').data('page-id');
            var cid = parseInt($('#ce-id').val(), 10);
            openSectionDialog(null, cid, pid);
        });

        $app.off('click', '.section-btn-edit').on('click', '.section-btn-edit', function () {
            var $row = $(this).closest('.congress-section-row');
            openSectionDialog($row.data('section-id'), $row.data('congress-id'), $row.data('page-id'));
        });
        $app.off('click', '.section-btn-delete').on('click', '.section-btn-delete', function () {
            var sid = $(this).closest('.congress-section-row').data('section-id');
            var cid = $(this).closest('.congress-section-row').data('congress-id');
            layout.renderYesNo(
                __('Delete section?', 'event-tickets-with-ticket-scanner'),
                __('This action cannot be undone.', 'event-tickets-with-ticket-scanner'),
                function () {
                    layout && layout.renderSpinnerShow();
                    post('delete_section', { section_id: sid }, function () {
                        layout && layout.renderSpinnerHide();
                        loadPagesTree(cid);
                    });
                }
            );
        });
    }

    // ── Pages tree (pages → sections) ──────────────────────────

    var currentPages = []; // [{id,title,...}] — also feeds the section dialog's page dropdown

    // Small jQuery UI prompt for a page title (no native prompt()).
    function _promptPageTitle(initial, title, onOk) {
        var $dlg = $('<div>').append(
            $('<input type="text" class="ce-input" style="width:100%;box-sizing:border-box;">').val(initial || '')
        );
        $dlg.dialog({
            title: title, modal: true, width: 420,
            open: function () { $dlg.find('input').focus().select(); },
            buttons: [{
                text: __('OK', 'event-tickets-with-ticket-scanner'), class: 'button-primary',
                click: function () {
                    var v = $dlg.find('input').val().trim();
                    if (!v) { $dlg.find('input').focus(); return; }
                    $(this).dialog('close');
                    onOk(v);
                }
            }, {
                text: __('Cancel', 'event-tickets-with-ticket-scanner'), class: 'button-secondary',
                click: function () { $(this).dialog('close'); }
            }],
            close: function () { $(this).dialog('destroy').remove(); }
        });
    }

    function loadPagesTree(congressId) {
        post('get_pages', { congress_id: congressId }, function (data) {
            currentPages = (data && data.pages) || [];
            renderPagesTree(currentPages, congressId);
        });
    }

    function renderPagesTree(pages, congressId) {
        var $list = $('#ce-pages-list').empty();
        if (!pages.length) {
            $list.append('<p>' + __('No pages yet. Add a page to start.', 'event-tickets-with-ticket-scanner') + '</p>');
            return;
        }
        pages.forEach(function (p, idx) {
            var isStart = (idx === 0);
            var $block = $('<div class="ce-page-block" data-page-id="' + p.id + '">');

            var $head = $('<div class="ce-page-head">').appendTo($block);
            $head.append($('<span class="ce-page-handle dashicons dashicons-move" title="' + escAttr(__('Drag to reorder pages', 'event-tickets-with-ticket-scanner')) + '">'));
            $head.append($('<span class="ce-page-name">').text(p.title || ('#' + p.id)));
            if (isStart) $head.append($('<span class="ce-page-start-badge">').text(__('Start page', 'event-tickets-with-ticket-scanner')));

            var $pageActions = $('<span class="ce-page-actions">').appendTo($head);
            $pageActions.append($('<div class="et-btn-group"><button class="et-btn-action ce-page-add-section">' + __('Add section', 'event-tickets-with-ticket-scanner') + '</button></div>'));
            $pageActions.append($('<div class="et-btn-group"><button class="et-btn-action ce-page-rename">' + __('Rename', 'event-tickets-with-ticket-scanner') + '</button></div>'));
            // The last remaining page cannot be deleted (server refuses); hide its delete button.
            if (pages.length > 1) {
                $pageActions.append($('<div class="et-btn-group et-btn-group--danger"><button class="et-btn-action et-btn-action--danger ce-page-delete">' + __('Delete', 'event-tickets-with-ticket-scanner') + '</button></div>'));
            }

            var $secList = $('<div class="ce-page-sections" data-page-id="' + p.id + '">').appendTo($block);
            renderSectionRows($secList, p.sections || [], congressId, p.id);

            $list.append($block);
        });

        initPagesDragSort(congressId);
        pages.forEach(function (p) { initSectionsDragSort(congressId, p.id); });
    }

    function renderSectionRows($list, sections, congressId, pageId) {
        $list.empty();
        if (!sections.length) {
            $list.append('<p class="ce-page-empty">' + __('No sections on this page yet.', 'event-tickets-with-ticket-scanner') + '</p>');
            return;
        }
        sections.forEach(function (s) {
            var pw = s.password_hash ? ' 🔒' : '';
            var row = $('<div class="congress-section-row" ' +
                'data-section-id="' + s.id + '" data-congress-id="' + congressId + '" data-page-id="' + pageId + '">' +
                '<span class="section-handle dashicons dashicons-move"></span>' +
                '<span class="section-title">' + $('<div>').text(s.title).html() + pw + '</span>' +
                '<span class="section-type-badge">' + s.type + '</span>' +
                '<span class="section-actions">' +
                '<div class="et-btn-group"><button class="et-btn-action section-btn-edit">' + __('Edit', 'event-tickets-with-ticket-scanner') + '</button></div>' +
                '<div class="et-btn-group et-btn-group--danger"><button class="et-btn-action et-btn-action--danger section-btn-delete">' + __('Delete', 'event-tickets-with-ticket-scanner') + '</button></div>' +
                '</span></div>');
            $list.append(row);
        });
    }

    function initPagesDragSort(congressId) {
        var $list = $('#ce-pages-list');
        if (!$list.length || typeof $list.sortable !== 'function') return;
        $list.sortable({
            handle: '.ce-page-handle',
            items: '.ce-page-block',
            axis: 'y',
            tolerance: 'pointer',
            update: function () {
                var ids = [];
                $list.find('.ce-page-block').each(function () { ids.push($(this).data('page-id')); });
                post('reorder_pages', { congress_id: congressId, ordered_ids: ids }, function () {
                    loadPagesTree(congressId); // re-render so the start-page badge follows the new order
                });
            }
        });
    }

    function initSectionsDragSort(congressId, pageId) {
        var $list = $('.ce-page-sections[data-page-id="' + pageId + '"]');
        if (!$list.length || typeof $list.sortable !== 'function') return;
        $list.sortable({
            handle: '.section-handle',
            items: '.congress-section-row',
            axis: 'y',
            tolerance: 'pointer',
            update: function () {
                var ids = [];
                $list.find('.congress-section-row').each(function () { ids.push($(this).data('section-id')); });
                post('reorder_sections', { congress_id: congressId, ordered_ids: ids }, function () {});
            }
        });
    }

    // ── Section editor (unified add + edit) ────────────────────

    function openSectionEditor(sectionId, congressId) {
        openSectionDialog(sectionId, congressId);
    }

    function _sectionTypeHasHtml(type) { return type === 'info' || type === 'custom'; }

    // ── Section dialog (unified add + edit, all types) ─────────

    function openSectionDialog(sectionId, congressId, pageId) {
        var isNew = !sectionId;

        function buildAndShow(s) {
            var content = {};
            try { content = JSON.parse(s.content || '{}'); } catch (e) {}
            var editorId = 'ce-sec-html-' + (s.id || 'new');

            var dlg = $('<div class="ce-section-dialog">');

            // ── Type + Title row ──
            var $row1 = $('<div class="ce-dialog-row2">').appendTo(dlg);
            var $typeSelect = $('<select class="ce-dialog-input ce-sec-type">').append(
                '<option value="info">Info</option>',
                '<option value="program">' + __('Program', 'event-tickets-with-ticket-scanner') + '</option>',
                '<option value="download">Download</option>',
                '<option value="url">URL</option>',
                '<option value="image">' + __('Image', 'event-tickets-with-ticket-scanner') + '</option>',
                '<option value="video">' + __('Video', 'event-tickets-with-ticket-scanner') + '</option>',
                '<option value="media">' + __('Gallery', 'event-tickets-with-ticket-scanner') + '</option>',
                '<option value="custom">Custom</option>'
            ).val(s.type || 'info');
            $row1.append(
                $('<div class="ce-dialog-field" style="flex:0 0 130px;">').append(
                    $('<label class="ce-dialog-label">').text(__('Type', 'event-tickets-with-ticket-scanner')),
                    $typeSelect
                ),
                $('<div class="ce-dialog-field" style="flex:1;">').append(
                    $('<label class="ce-dialog-label">').html(__('Title', 'event-tickets-with-ticket-scanner') + '<span style="color:var(--et-danger);margin-left:2px;">*</span>'),
                    $('<input type="text" class="ce-dialog-input ce-sec-title">').val(s.title || '')
                )
            );

            // ── Page select (assign / move between pages) ──
            var selectedPageId = parseInt(s.page_id || pageId || (currentPages[0] && currentPages[0].id) || 0, 10);
            var $pageSelect = $('<select class="ce-dialog-input ce-sec-page">');
            currentPages.forEach(function (p, idx) {
                var label = (p.title || ('#' + p.id)) + (idx === 0 ? ' (' + __('start page', 'event-tickets-with-ticket-scanner') + ')' : '');
                $pageSelect.append($('<option>').val(p.id).text(label));
            });
            $pageSelect.val(selectedPageId);
            $row1.after(
                $('<div class="ce-dialog-field" style="margin-bottom:12px;">').append(
                    $('<label class="ce-dialog-label">').text(__('Page', 'event-tickets-with-ticket-scanner')),
                    $pageSelect,
                    $('<p class="ce-dialog-hint">').text(__('Choose which page this section belongs to. Change it to move the section to another page.', 'event-tickets-with-ticket-scanner'))
                )
            );

            // ── Type-specific content area ──
            var $contentArea = $('<div class="ce-sec-content-area">').appendTo(dlg);

            function _buildHtmlEditor() {
                _removeEditor();
                var $f = $('<div class="ce-dialog-field">').append(
                    $('<label class="ce-dialog-label">').text(__('Content', 'event-tickets-with-ticket-scanner')),
                    $('<p class="ce-dialog-hint">').html('<span class="dashicons dashicons-info" style="font-size:13px;width:13px;height:13px;vertical-align:middle;margin-right:3px;"></span>' + __('HTML allowed (same rules as blog posts). JavaScript is stripped.', 'event-tickets-with-ticket-scanner')),
                    $('<textarea id="' + editorId + '" class="ce-dialog-textarea ce-sec-html">').val(content.html || '')
                );
                $contentArea.html('').append($f);
                setTimeout(function () {
                    if (typeof wp !== 'undefined' && wp.oldEditor) {
                        wp.oldEditor.initialize(editorId, {
                            tinymce:      { toolbar1: 'bold,italic,underline,link,unlink,bullist,numlist,blockquote,hr,removeformat', height: 200 },
                            quicktags:    true,
                            mediaButtons: false
                        });
                    }
                }, 50);
            }

            // Move focus off the trigger button before opening the media modal: wp.media sets
            // aria-hidden on our dialog, and a focused descendant under aria-hidden is an a11y error.
            function _blurActive() {
                if (document.activeElement && typeof document.activeElement.blur === 'function') {
                    document.activeElement.blur();
                }
            }

            function _openMediaPicker(onSelect) {
                _blurActive();
                var frame = wp.media({
                    title:    __('Select file', 'event-tickets-with-ticket-scanner'),
                    multiple: false,
                    library:  { type: '' }   // all file types
                });
                frame.on('select', function () {
                    var att = frame.state().get('selection').first().toJSON();
                    onSelect({ id: att.id, url: att.url, filename: att.filename || att.title || att.url });
                });
                frame.open();
            }

            // Drag handle + sortable helpers for reorderable list rows
            function _listHandle() {
                return $('<span class="ce-list-handle dashicons dashicons-menu" title="' + __('Drag to reorder', 'event-tickets-with-ticket-scanner') + '">');
            }
            function _makeListSortable($rows) {
                if (typeof $rows.sortable === 'function') {
                    $rows.sortable({ handle: '.ce-list-handle', axis: 'y', tolerance: 'pointer', containment: 'parent' });
                }
            }
            function _refreshSortable($rows) {
                if (typeof $rows.sortable === 'function' && $rows.data('ui-sortable')) {
                    $rows.sortable('refresh');
                }
            }

            function _buildDownloadEditor() {
                var files = content.files || [];
                var $wrap = $('<div class="ce-list-editor">');
                var $rows = $('<div class="ce-list-rows">').appendTo($wrap);

                function _addFileRow(f) {
                    var $attId  = $('<input type="hidden" class="ce-file-att-id">').val(f.attachment_id || 0);
                    var $attUrl = $('<input type="hidden" class="ce-file-url">').val(f.url || '');
                    var $name   = $('<span class="ce-file-name">').text(f.filename || f.url || __('No file selected', 'event-tickets-with-ticket-scanner'));
                    var $lbl    = $('<input type="text" class="ce-dialog-input ce-file-label" placeholder="' + __('Label for visitors', 'event-tickets-with-ticket-scanner') + '" style="flex:1;">').val(f.label || '');
                    var $pick   = $('<button class="button-secondary ce-file-pick">').html(
                        '<span class="dashicons dashicons-paperclip" style="vertical-align:middle;font-size:14px;width:14px;height:14px;margin-right:3px;"></span>' + __('Choose', 'event-tickets-with-ticket-scanner')
                    ).on('click', function () {
                        _openMediaPicker(function (att) {
                            $attId.val(att.id);
                            $attUrl.val(att.url);
                            $name.text(att.filename);
                        });
                    });
                    var $inline = $('<input type="checkbox" class="ce-file-inline">').prop('checked', !!f.inline);
                    var $inlineLbl = $('<label class="ce-file-inline-lbl" title="' + escAttr(__('Open in browser instead of downloading (PDF, images).', 'event-tickets-with-ticket-scanner')) + '">').append(
                        $inline, document.createTextNode(' ' + __('Open inline', 'event-tickets-with-ticket-scanner'))
                    );
                    var $r = $('<div class="ce-list-row ce-download-row">').append(
                        _listHandle(),
                        $attId, $attUrl,
                        $('<div class="ce-download-pick-wrap">').append($pick, $name),
                        $lbl,
                        $inlineLbl,
                        $('<button class="ce-list-remove" title="' + __('Remove', 'event-tickets-with-ticket-scanner') + '">×</button>').on('click', function () { $r.remove(); })
                    );
                    $rows.append($r);
                }
                files.forEach(_addFileRow);

                $wrap.append(
                    $('<button class="button-secondary ce-list-add">').html(
                        '<span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;font-size:14px;width:14px;height:14px;margin-right:3px;"></span>' + __('Add file', 'event-tickets-with-ticket-scanner')
                    ).on('click', function () { _addFileRow({ attachment_id: 0, url: '', label: '', filename: '' }); _refreshSortable($rows); })
                );
                $contentArea.html('').append(
                    $('<div class="ce-dialog-field">').append(
                        $('<label class="ce-dialog-label">').text(__('Files', 'event-tickets-with-ticket-scanner')),
                        $('<p class="ce-dialog-hint">').text(__('Pick files from the media library. Label is shown to visitors. Drag to reorder.', 'event-tickets-with-ticket-scanner')),
                        $wrap
                    )
                );
                _makeListSortable($rows);
            }

            function _buildUrlEditor() {
                var urls = content.urls || [];
                var $wrap = $('<div class="ce-list-editor">');
                var $rows = $('<div class="ce-list-rows">').appendTo($wrap);

                function _addUrlRow(u) {
                    var $r = $('<div class="ce-list-row ce-url-row">').append(
                        _listHandle(),
                        $('<input type="text" class="ce-dialog-input ce-url-label" placeholder="' + __('Label', 'event-tickets-with-ticket-scanner') + '" style="flex:1;">').val(u.label || ''),
                        $('<input type="url" class="ce-dialog-input ce-url-href" placeholder="https://" style="flex:1.5;">').val(u.url || ''),
                        $('<label class="ce-url-internal-label">').append(
                            $('<input type="checkbox" class="ce-url-internal">').prop('checked', !!u.internal),
                            $('<span>').text(__('Internal', 'event-tickets-with-ticket-scanner'))
                        ),
                        $('<button class="ce-list-remove" title="' + __('Remove', 'event-tickets-with-ticket-scanner') + '">×</button>').on('click', function () { $r.remove(); })
                    );
                    $rows.append($r);
                }
                urls.forEach(_addUrlRow);

                $wrap.append(
                    $('<button class="button-secondary ce-list-add">').html(
                        '<span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;font-size:14px;width:14px;height:14px;margin-right:3px;"></span>' + __('Add URL', 'event-tickets-with-ticket-scanner')
                    ).on('click', function () { _addUrlRow({ label: '', url: '', internal: false }); _refreshSortable($rows); })
                );
                $contentArea.html('').append(
                    $('<div class="ce-dialog-field">').append(
                        $('<label class="ce-dialog-label">').text(__('URLs', 'event-tickets-with-ticket-scanner')),
                        $('<p class="ce-dialog-hint">').text(__('Internal links open in the same tab. External links open in a new tab. Drag to reorder.', 'event-tickets-with-ticket-scanner')),
                        $wrap
                    )
                );
                _makeListSortable($rows);
            }

            function _buildMediaEditor() {
                var items = content.items || [];
                var $wrap = $('<div class="ce-list-editor">');
                var $rows = $('<div class="ce-list-rows">').appendTo($wrap);

                function _addMediaRow(item) {
                    var $r = $('<div class="ce-list-row ce-media-row">').append(
                        _listHandle(),
                        $('<input type="url" class="ce-dialog-input ce-media-url" placeholder="https://..." style="flex:1.5;">').val(item.url || ''),
                        $('<input type="text" class="ce-dialog-input ce-media-caption" placeholder="' + __('Caption (optional)', 'event-tickets-with-ticket-scanner') + '" style="flex:1;">').val(item.caption || ''),
                        $('<button class="ce-list-remove" title="' + __('Remove', 'event-tickets-with-ticket-scanner') + '">×</button>').on('click', function () { $r.remove(); })
                    );
                    $rows.append($r);
                }
                items.forEach(_addMediaRow);

                $wrap.append(
                    $('<button class="button-secondary ce-list-add">').html(
                        '<span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;font-size:14px;width:14px;height:14px;margin-right:3px;"></span>' + __('Add image', 'event-tickets-with-ticket-scanner')
                    ).on('click', function () { _addMediaRow({ url: '', caption: '' }); _refreshSortable($rows); })
                );
                $contentArea.html('').append(
                    $('<div class="ce-dialog-field">').append(
                        $('<label class="ce-dialog-label">').text(__('Images', 'event-tickets-with-ticket-scanner')),
                        $('<p class="ce-dialog-hint">').text(__('Image URL and optional caption for each item. Drag to reorder.', 'event-tickets-with-ticket-scanner')),
                        $wrap
                    )
                );
                _makeListSortable($rows);
            }

            // Media picker restricted to images only (for the image section type).
            function _openImagePicker(onSelect) {
                _blurActive();
                var frame = wp.media({ title: __('Select image', 'event-tickets-with-ticket-scanner'), multiple: false, library: { type: 'image' } });
                frame.on('select', function () {
                    var att = frame.state().get('selection').first().toJSON();
                    onSelect({ id: att.id, url: att.url });
                });
                frame.open();
            }

            function _buildImageEditor() {
                var $attId = $('<input type="hidden" class="ce-img-att-id">').val(content.attachment_id || 0);
                var $attUrl = $('<input type="hidden" class="ce-img-url">').val(content.url || '');
                var $preview = $('<img class="ce-img-preview" style="max-width:240px;max-height:160px;display:' + (content.url ? 'block' : 'none') + ';border-radius:6px;margin:8px 0;">').attr('src', content.url || '');
                var $pick = $('<button class="button-secondary">').html(
                    '<span class="dashicons dashicons-format-image" style="vertical-align:middle;font-size:14px;width:14px;height:14px;margin-right:3px;"></span>' + __('Choose image', 'event-tickets-with-ticket-scanner')
                ).on('click', function () {
                    _openImagePicker(function (att) { $attId.val(att.id); $attUrl.val(att.url); $preview.attr('src', att.url).show(); });
                });
                // Size controls: full width (default) / original / custom (value + unit).
                var size = content.size || 'full';
                var $size = $('<select class="ce-dialog-input ce-img-size" style="max-width:220px;">').append(
                    '<option value="full">' + __('Full width', 'event-tickets-with-ticket-scanner') + '</option>',
                    '<option value="original">' + __('Original size', 'event-tickets-with-ticket-scanner') + '</option>',
                    '<option value="custom">' + __('Custom width', 'event-tickets-with-ticket-scanner') + '</option>'
                ).val(size);
                var $width = $('<input type="number" min="1" class="ce-dialog-input ce-img-width" style="max-width:110px;">').val(content.width || '');
                var $unit  = $('<select class="ce-dialog-input ce-img-width-unit" style="max-width:80px;">').append(
                    '<option value="px">px</option>',
                    '<option value="%">%</option>'
                ).val(content.width_unit || 'px');
                var $customRow = $('<div class="ce-img-custom-row" style="display:flex;gap:8px;align-items:center;margin-top:6px;">').append($width, $unit);
                function _syncSize() { $customRow.toggle($size.val() === 'custom'); }
                $size.on('change', _syncSize);

                // Lightbox: click the image to open it full-size. Default off.
                var $lightbox = $('<input type="checkbox" class="ce-img-lightbox">').prop('checked', !!content.lightbox);
                var $lightboxRow = $('<label class="ce-dialog-label" style="margin-top:10px;display:flex;align-items:center;gap:6px;font-weight:normal;">').append(
                    $lightbox, document.createTextNode(__('Open full size when clicked', 'event-tickets-with-ticket-scanner'))
                );

                $contentArea.html('').append(
                    $('<div class="ce-dialog-field">').append(
                        $('<label class="ce-dialog-label">').text(__('Image', 'event-tickets-with-ticket-scanner')),
                        $attId, $attUrl, $pick, $preview,
                        $('<label class="ce-dialog-label" style="margin-top:8px;">').text(__('Caption (optional)', 'event-tickets-with-ticket-scanner')),
                        $('<input type="text" class="ce-dialog-input ce-img-caption">').val(content.caption || ''),
                        $('<label class="ce-dialog-label" style="margin-top:8px;">').text(__('Display size', 'event-tickets-with-ticket-scanner')),
                        $size, $customRow,
                        $lightboxRow
                    )
                );
                _syncSize();
            }

            function _buildVideoEditor() {
                var provider = content.provider || 'oembed';
                var $provider = $('<select class="ce-dialog-input ce-video-provider" style="max-width:260px;">').append(
                    '<option value="oembed">' + __('Link (YouTube, Vimeo, …)', 'event-tickets-with-ticket-scanner') + '</option>',
                    '<option value="file">' + __('Uploaded video file', 'event-tickets-with-ticket-scanner') + '</option>'
                ).val(provider);

                var $attId = $('<input type="hidden" class="ce-video-att-id">').val(content.attachment_id || 0);
                var $url   = $('<input type="text" class="ce-dialog-input ce-video-url" placeholder="https://www.youtube.com/watch?v=…">').val(content.url || '');
                var $fileName = $('<span class="ce-file-name">').text(content.url ? content.url : __('No file selected', 'event-tickets-with-ticket-scanner'));
                var $pick = $('<button class="button-secondary">').html(
                    '<span class="dashicons dashicons-video-alt3" style="vertical-align:middle;font-size:14px;width:14px;height:14px;margin-right:3px;"></span>' + __('Choose video', 'event-tickets-with-ticket-scanner')
                ).on('click', function () {
                    _blurActive();
                    var frame = wp.media({ title: __('Select video', 'event-tickets-with-ticket-scanner'), multiple: false, library: { type: 'video' } });
                    frame.on('select', function () {
                        var att = frame.state().get('selection').first().toJSON();
                        $attId.val(att.id); $url.val(att.url); $fileName.text(att.filename || att.url);
                    });
                    frame.open();
                });

                var $oembedRow = $('<div class="ce-video-oembed">').append(
                    $('<label class="ce-dialog-label">').text(__('Video URL', 'event-tickets-with-ticket-scanner')),
                    $url,
                    $('<p class="ce-dialog-hint">').text(__('Paste a YouTube, Vimeo or other supported link. It is embedded automatically.', 'event-tickets-with-ticket-scanner'))
                );
                var $fileRow = $('<div class="ce-video-file" style="display:none;">').append(
                    $('<label class="ce-dialog-label">').text(__('Video file', 'event-tickets-with-ticket-scanner')),
                    $('<div class="ce-download-pick-wrap">').append($pick, $fileName)
                );
                function _syncProvider() {
                    if ($provider.val() === 'file') { $fileRow.show(); $oembedRow.hide(); }
                    else { $oembedRow.show(); $fileRow.hide(); }
                }
                $provider.on('change', _syncProvider);

                $contentArea.html('').append(
                    $('<div class="ce-dialog-field">').append(
                        $('<label class="ce-dialog-label">').text(__('Source', 'event-tickets-with-ticket-scanner')),
                        $provider, $attId, $oembedRow, $fileRow
                    )
                );
                _syncProvider();
            }

            function _buildProgramEditor() {
                var days = content.days || [];
                var $wrap = $('<div class="ce-program-editor">');

                function _addDayBlock(day) {
                    var $day = $('<div class="ce-program-day">').append(
                        $('<div class="ce-program-day-header">').append(
                            $('<input type="date" class="ce-dialog-input ce-day-date" style="max-width:180px;">').val(day.date || ''),
                            $('<button class="ce-list-remove" title="' + __('Remove day', 'event-tickets-with-ticket-scanner') + '">×</button>').on('click', function () { $day.remove(); })
                        )
                    );
                    var $slots = $('<div class="ce-program-slots">').appendTo($day);

                    function _addSlot(slot) {
                        var $sl = $('<div class="ce-program-slot">').append(
                            $('<input type="text" class="ce-dialog-input ce-slot-time" placeholder="' + __('Time', 'event-tickets-with-ticket-scanner') + '" style="max-width:90px;">').val(slot.time || ''),
                            $('<input type="text" class="ce-dialog-input ce-slot-title" placeholder="' + __('Title', 'event-tickets-with-ticket-scanner') + '" style="flex:1;">').val(slot.title || ''),
                            $('<input type="text" class="ce-dialog-input ce-slot-speaker" placeholder="' + __('Speaker', 'event-tickets-with-ticket-scanner') + '" style="max-width:160px;">').val(slot.speaker || ''),
                            $('<input type="text" class="ce-dialog-input ce-slot-room" placeholder="' + __('Room', 'event-tickets-with-ticket-scanner') + '" style="max-width:110px;">').val(slot.room || ''),
                            $('<button class="ce-list-remove" title="' + __('Remove slot', 'event-tickets-with-ticket-scanner') + '">×</button>').on('click', function () { $sl.remove(); })
                        );
                        $slots.append($sl);
                    }
                    (day.slots || []).forEach(_addSlot);

                    $day.append(
                        $('<button class="button-secondary ce-list-add ce-slot-add">').html(
                            '<span class="dashicons dashicons-plus" style="vertical-align:middle;font-size:12px;width:12px;height:12px;margin-right:2px;"></span>' + __('Add slot', 'event-tickets-with-ticket-scanner')
                        ).on('click', function () { _addSlot({ time: '', title: '', speaker: '', room: '' }); })
                    );
                    $wrap.append($day);
                }
                days.forEach(_addDayBlock);

                $wrap.append(
                    $('<button class="button-secondary ce-list-add">').html(
                        '<span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;font-size:14px;width:14px;height:14px;margin-right:3px;"></span>' + __('Add day', 'event-tickets-with-ticket-scanner')
                    ).on('click', function () { _addDayBlock({ date: '', slots: [] }); })
                );
                $contentArea.html('').append(
                    $('<div class="ce-dialog-field">').append(
                        $('<label class="ce-dialog-label">').text(__('Program', 'event-tickets-with-ticket-scanner')),
                        $('<p class="ce-dialog-hint">').text(__('Add days and time slots. Each slot has time, title, speaker and room.', 'event-tickets-with-ticket-scanner')),
                        $wrap
                    )
                );
            }

            function _rebuildContentArea(type) {
                _removeEditor();
                if (type === 'info' || type === 'custom')  { _buildHtmlEditor(); }
                else if (type === 'download')               { _buildDownloadEditor(); }
                else if (type === 'url')                    { _buildUrlEditor(); }
                else if (type === 'image')                  { _buildImageEditor(); }
                else if (type === 'video')                  { _buildVideoEditor(); }
                else if (type === 'media')                  { _buildMediaEditor(); }
                else if (type === 'program')                { _buildProgramEditor(); }
                else                                        { $contentArea.html(''); }
            }

            function _removeEditor() {
                if (typeof wp !== 'undefined' && wp.oldEditor) { wp.oldEditor.remove(editorId); }
            }

            function _getContentObj(type) {
                if (type === 'info' || type === 'custom') {
                    var html = (typeof tinymce !== 'undefined' && tinymce.get(editorId))
                        ? tinymce.get(editorId).getContent()
                        : dlg.find('#' + editorId).val();
                    return { html: html };
                }
                if (type === 'download') {
                    var files = [];
                    $contentArea.find('.ce-download-row').each(function () {
                        var lbl    = $(this).find('.ce-file-label').val().trim();
                        var url    = $(this).find('.ce-file-url').val().trim();
                        var attId  = parseInt($(this).find('.ce-file-att-id').val(), 10) || 0;
                        var name   = $(this).find('.ce-file-name').text().trim();
                        var inline = $(this).find('.ce-file-inline').is(':checked');
                        if (attId || url) files.push({ label: lbl, attachment_id: attId, url: url, filename: name, inline: inline });
                    });
                    return { files: files };
                }
                if (type === 'url') {
                    var urls = [];
                    $contentArea.find('.ce-url-row').each(function () {
                        var lbl      = $(this).find('.ce-url-label').val().trim();
                        var href     = $(this).find('.ce-url-href').val().trim();
                        var internal = $(this).find('.ce-url-internal').is(':checked');
                        if (href) urls.push({ label: lbl || href, url: href, internal: internal });
                    });
                    return { urls: urls };
                }
                if (type === 'media') {
                    var items = [];
                    $contentArea.find('.ce-list-row').each(function () {
                        var url = $(this).find('.ce-media-url').val().trim();
                        var cap = $(this).find('.ce-media-caption').val().trim();
                        if (url) items.push({ url: url, caption: cap });
                    });
                    return { items: items };
                }
                if (type === 'image') {
                    return {
                        attachment_id: parseInt($contentArea.find('.ce-img-att-id').val(), 10) || 0,
                        url:           $contentArea.find('.ce-img-url').val().trim(),
                        caption:       $contentArea.find('.ce-img-caption').val().trim(),
                        size:          $contentArea.find('.ce-img-size').val() || 'full',
                        width:         parseInt($contentArea.find('.ce-img-width').val(), 10) || 0,
                        width_unit:    $contentArea.find('.ce-img-width-unit').val() || 'px',
                        lightbox:      $contentArea.find('.ce-img-lightbox').is(':checked')
                    };
                }
                if (type === 'video') {
                    var prov = $contentArea.find('.ce-video-provider').val();
                    return {
                        provider:      prov === 'file' ? 'file' : 'oembed',
                        url:           $contentArea.find('.ce-video-url').val().trim(),
                        attachment_id: parseInt($contentArea.find('.ce-video-att-id').val(), 10) || 0
                    };
                }
                if (type === 'program') {
                    var days = [];
                    $contentArea.find('.ce-program-day').each(function () {
                        var date = $(this).find('.ce-day-date').val().trim();
                        var slots = [];
                        $(this).find('.ce-program-slot').each(function () {
                            slots.push({
                                time:    $(this).find('.ce-slot-time').val().trim(),
                                title:   $(this).find('.ce-slot-title').val().trim(),
                                speaker: $(this).find('.ce-slot-speaker').val().trim(),
                                room:    $(this).find('.ce-slot-room').val().trim()
                            });
                        });
                        days.push({ date: date, slots: slots });
                    });
                    return { days: days };
                }
                return {};
            }

            // Content cache — preserves data per type when switching
            var contentCache = {};
            contentCache[s.type || 'info'] = content;
            var currentType = s.type || 'info';

            function _saveCurrentToCache() {
                contentCache[currentType] = _getContentObj(currentType);
            }

            function _contentForType(type) {
                return contentCache[type] || {};
            }

            // Override builders to use cache
            function _rebuildContentAreaCached(type) {
                _saveCurrentToCache();
                content = _contentForType(type); // update closure var for builders
                currentType = type;
                _rebuildContentArea(type);
            }

            _rebuildContentArea(s.type || 'info');
            $typeSelect.on('change', function () { _rebuildContentAreaCached($(this).val()); });

            // ── Meta info (edit only) ──
            if (!isNew && (s.created_at || s.updated_at)) {
                var metaHtml = '';
                if (s.created_at) metaHtml += '<span><b>' + __('Created:', 'event-tickets-with-ticket-scanner') + '</b> ' + s.created_at + (s.created_by_user_id ? ' &nbsp;(ID&nbsp;' + s.created_by_user_id + ')' : '') + '</span>';
                if (s.updated_at) metaHtml += '<span><b>' + __('Updated:', 'event-tickets-with-ticket-scanner') + '</b> ' + s.updated_at + (s.updated_by_user_id ? ' &nbsp;(ID&nbsp;' + s.updated_by_user_id + ')' : '') + '</span>';
                dlg.append($('<div class="ce-dialog-meta">').html(metaHtml));
            }

            dlg.dialog({
                title:     isNew ? __('Add section', 'event-tickets-with-ticket-scanner') : __('Edit section', 'event-tickets-with-ticket-scanner'),
                modal:     true,
                width:     720,
                minHeight: 440,
                open:      function () { dlg.find('.ce-sec-title').focus(); },
                close:     function () { _removeEditor(); $(this).dialog('destroy').remove(); },
                buttons: [{
                    text:  isNew ? __('Add', 'event-tickets-with-ticket-scanner') : __('Save', 'event-tickets-with-ticket-scanner'),
                    class: 'button-primary',
                    click: function () {
                        var title = dlg.find('.ce-sec-title').val().trim();
                        if (!title) { dlg.find('.ce-sec-title').focus(); return; }
                        var type       = $typeSelect.val();
                        var newContent = JSON.stringify(_getContentObj(type));
                        var targetPageId = parseInt($pageSelect.val(), 10) || selectedPageId;
                        $(this).dialog('close');
                        layout && layout.renderSpinnerShow();
                        post('save_section', {
                            section_id:  isNew ? 0 : parseInt(s.id, 10),
                            congress_id: congressId,
                            page_id:     targetPageId,
                            type:        type,
                            title:       title,
                            content:     newContent,
                            sort_order:  isNew ? 999 : (parseInt(s.sort_order, 10) || 0)
                        }, function () {
                            layout && layout.renderSpinnerHide();
                            loadPagesTree(congressId);
                        });
                    }
                }, {
                    text:  __('Cancel', 'event-tickets-with-ticket-scanner'),
                    class: 'button-secondary',
                    click: function () { $(this).dialog('close'); }
                }]
            });
        }

        if (isNew) {
            buildAndShow({ type: 'info', title: '', content: '{}', sort_order: 999 });
        } else {
            layout && layout.renderSpinnerShow();
            post('get_section', { section_id: sectionId }, function (s) {
                layout && layout.renderSpinnerHide();
                buildAndShow(s);
            });
        }
    }

    function saveCongress() {
        var title = $('#ce-title').val().trim();
        var slug  = $('#ce-slug').val().trim();
        if (!title || !slug) {
            layout
                ? layout.renderInfoBox(__('Title and slug are required.', 'event-tickets-with-ticket-scanner'), '')
                : alert(__('Title and slug are required.', 'event-tickets-with-ticket-scanner'));
            return;
        }
        var toMysql = function (sel) {
            var v = $(sel).val();
            return v ? v.replace('T', ' ') + ':00' : '';
        };
        var expires    = toMysql('#ce-expires');
        var wasExisting = $('#ce-id').val() !== '';
        layout && layout.renderSpinnerShow();
        post('save', {
            id:                $('#ce-id').val(),
            title:             title,
            slug:              slug,
            access_expires_at: expires,
            is_active:         $('#ce-is-active').is(':checked') ? 1 : 0
        }, function (data) {
            layout && layout.renderSpinnerHide();
            if (wasExisting) {
                // Existing congress saved → back to the overview list
                renderListView();
            } else {
                // New congress just created → reopen editor so sections can be added
                openEditor(data.id);
            }
        });
    }

    // ── Boot ──────────────────────────────────────────────────

    function init(container, cfg) {
        $app    = $(container);
        ajaxUrl = cfg.ajaxUrl;
        nonce   = cfg.nonce;
        layout  = cfg.layout || null;
        dtTable = null;
        $app.off(); // detach old event handlers if re-initialising
        renderListView();
    }

    // Backward compat: standalone page enqueued via renderPage()
    $(document).ready(function () {
        var $standalone = $('#saso-et-congress-app');
        if ($standalone.length && window.sasoEtCongress) {
            init($standalone, window.sasoEtCongress);
        }
    });

    window.sasoEtCongressAdmin = { init: init };

}(jQuery));
