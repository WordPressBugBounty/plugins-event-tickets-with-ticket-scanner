function sasoEventtickets_order_detail() {
    const { __, _x, _n, sprintf } = wp.i18n;
    if (typeof sasoEventtickets_order_detail_data == "undefined") return "";
    let DATA = sasoEventtickets_order_detail_data;
    let div = null;
    let div_order = null;
    //console.log(DATA);

    function addStyleCode(content) {
		let c = document.createElement('style');
		c.innerHTML = content;
		document.getElementsByTagName("head")[0].appendChild(c);
	}

    function displayTicket(idx, item, extra_content) {
        $(div).find('div[data-element="ticket_content"]').parent().css("background-color", "#f3f3f3");
        $(div).find('div[data-element="ticket_content"]').css("display", "none");
        $(div).find('div[data-element="ticket_opener"]').css("display", "block");
        $(div).find('div[data-id="ticket_opener_'+idx+'"]').css("display", "none");
        $(div).find('div[data-id="ticket_content_'+idx+'"]').css("display", "block").parent().css("background-color", "#ffffff");;
        $(div).find('div[data-id="'+DATA.system.divPrefix+'_qr_'+idx+'"]').html("").qrcode(item.qrcode_content).append($('<div>').html(item.ticket_id));
        if (extra_content && extra_content != "") {
            $(div).find('div[data-id="'+DATA.system.divPrefix+'_qr_'+idx+'"]').append($('<div>').html(extra_content));
        }
    }
    function displayOrderInfo() {
        $('<div style="width:50%;">').html("Order # "+DATA.order.id).appendTo(div_order);
        $('<div style="width:50%;text-align:right;">').html(DATA.order.date_paid.split(" ")[0]).appendTo(div_order);
    }
    function displayOrderDetails() {
        let div_order = $('<div>').appendTo(div);
        let text_content = __("Order Ticket Infos", 'event-tickets-with-ticket-scanner')+'<br>'
            +DATA.order.products.length+" "+__("Products", 'event-tickets-with-ticket-scanner')+"<br>"
            +DATA.tickets.length+" "+__("Tickets", 'event-tickets-with-ticket-scanner')
        let extra_content = "";

        for (let pidx=0;pidx<DATA.order.products.length;pidx++) {
            let product = DATA.order.products[pidx];
            extra_content += "<b>"+product.product_name
                +(product.product_name_variant != "" ? " - "
                +product.product_name_variant : "")
                +'</b><div style="padding-top:5px;text-align:left;"><ol>';
            for (let idx=0;idx<DATA.tickets.length;idx++) {
                let item = DATA.tickets[idx];
                if (item.product_id == product.product_id && item.product_parent_id == product.product_parent_id) {
                    extra_content += '<li style="padding-bottom:5px;">';
                    extra_content += item.code_display;
                    if (item.name_per_ticket != "" || item.value_per_ticket != "") {
                        if (item.name_per_ticket != "") {
                            extra_content += '<br>'+item.name_per_ticket;
                        }
                        if (item.value_per_ticket != "") {
                            extra_content += '<br>'+item.value_per_ticket;
                        }
                    } else {
                        //extra_content += "No name or value on ticket set";
                    }
                    if (item.location) {
                        extra_content += "<br>"+item.location;
                    }
                    if (item.ticket_date) {
                        extra_content += "<br>"+item.ticket_date;
                    }

                    extra_content += '</li>';
                }
            }
            extra_content += '</ol></div>';
        }

        let idx = -1;
        let qr_code = $('<div style="padding:20px;text-align:center;" data-id="'+DATA.system.divPrefix+'_qr_'+idx+'">');
        let header = $('<div style="display:flex;justify-content:flex-start">')
            .append($('<div style="padding-right:10px;">').append($('<img src="'+DATA.system.base_url+'img/circle-check-3x.png">')))
            .append($('<div>').html(text_content));
        let header2 = $('<div style="display:flex;justify-content:flex-start">')
            .append($('<div style="padding-right:10px;">').append($('<img src="'+DATA.system.base_url+'img/circle-check-3x.png">')))
            .append($('<div>').html(text_content));

        let opener = $('<div style="cursor:pointer;" data-element="ticket_opener" data-id="ticket_opener_'+idx+'">')
        .on("click", e=>{
            displayTicket(idx, {"qrcode_content":DATA.order.qrcode_content, "ticket_id":DATA.order.code}, extra_content);
        });
        $('<div style="display:flex;justify-content:space-between;">')
            .append(header)
            .append(
                $('<div style="display:flex;justify-content:flex-end">').append(
                    $('<div>').append(
                        $('<img src="'+DATA.system.base_url+'img/caret-bottom-3x.png" style="padding:3px;width:32px;height;32px;border:1px solid grey;border-radius:50%;">')
                    )
                )
            )
            .appendTo(opener);

        let content = $('<div data-element="ticket_content" data-id="ticket_content_'+idx+'" style="display:none;">');
        $('<div>')
            .append(header2)
            .append(qr_code)
            .appendTo(content);

        $('<div style="margin:5px;padding:10px;background-color:#f3f3f3;color:black;border:1px solid gray;">')
            .append(opener)
            .append(content)
            .appendTo(div_order);

    }
    function displayOrderTickets() {
        // location, name_per_ticket, value_per_ticket, product_name, product_name_variant, ticket_date, ticket_id

        let div_tickets = $('<div>').appendTo(div);
        for (let idx=0;idx<DATA.tickets.length;idx++) {
            let item = DATA.tickets[idx];

            let text_content = item.product_name;
            if (item.product_name_variant) {
                text_content += "<br>"+item.product_name_variant;
            }
            if (item.name_per_ticket) {
                text_content += "<br>"+item.name_per_ticket;
            }
            if (item.value_per_ticket) {
                text_content += "<br>"+item.value_per_ticket;
            }
            if (item.location) {
                text_content += "<br>"+item.location;
            }
            if (item.ticket_date) {
                text_content += "<br>"+item.ticket_date;
            }

            let qr_code = $('<div style="padding:20px;text-align:center;" data-id="'+DATA.system.divPrefix+'_qr_'+idx+'">');
            let header = $('<div style="display:flex;justify-content:flex-start">')
                .append($('<div style="padding-right:10px;">').append($('<img src="'+DATA.system.base_url+'img/circle-check-3x.png">')))
                .append($('<div>').html(text_content));
            let header2 = $('<div style="display:flex;justify-content:flex-start">')
                .append($('<div style="padding-right:10px;">').append($('<img src="'+DATA.system.base_url+'img/circle-check-3x.png">')))
                .append($('<div>').html(text_content));

            let opener = $('<div style="cursor:pointer;" data-element="ticket_opener" data-id="ticket_opener_'+idx+'">')
            .on("click", e=>{
                displayTicket(idx, item);
            });
            $('<div style="display:flex;justify-content:space-between;">')
                .append(header)
                .append(
                    $('<div style="display:flex;justify-content:flex-end">').append(
                        $('<div>').append(
                            $('<img src="'+DATA.system.base_url+'img/caret-bottom-3x.png" style="padding:3px;width:32px;height;32px;border:1px solid grey;border-radius:50%;">')
                        )
                    )
                )
                .appendTo(opener);

            let content = $('<div data-element="ticket_content" data-id="ticket_content_'+idx+'" style="display:none;">');
            $('<div>')
                .append(header2)
                .append(qr_code)
                .appendTo(content);

            $('<div style="margin:5px;padding:10px;background-color:#f3f3f3;color:black;border:1px solid gray;">')
                .append(opener)
                .append(content)
                .appendTo(div_tickets);
        }

        if (DATA.tickets.length > 0) {
            //displayTicket(0, DATA.tickets[0]);
            $('body').find('div[data-id="ticket_opener_-1"]').trigger("click");

            if (DATA.order.wcTicketDisplayDownloadAllTicketsPDFButtonOnOrderdetail !== "undefined" && DATA.order.wcTicketDisplayDownloadAllTicketsPDFButtonOnOrderdetail == 1) {
                let div_appendix = $('<div style="margin-top:50px;">').appendTo(div);
                $('<h2>').html(DATA.order.wcTicketLabelPDFDownloadHeading).appendTo(div_appendix);
                $('<p>').append(
                    $('<a href="'+DATA.order.url_order_tickets+'" target="_blank">')
                        .html(DATA.order.wcTicketLabelPDFDownload)
                        .css("font-weight","bold")
                ).appendTo(div_appendix);
            }

        }

    }
    function init() {
        document.title = __("Order Ticket", 'event-tickets-with-ticket-scanner');
        $ = jQuery;
        div = $('#'+DATA.system.divPrefix+'_order_detail_area');
        if (!div) {
            document.write('<div id="'+DATA.system.divPrefix+'_order_detail_area"></div>');
            div = $('#'+DATA.system.divPrefix+'_order_detail_area');
        }
        div_order = $('<div style="display:flex;justify-content:space-between;padding:5px;border-top:1px solid gray;border-bottom:1px solid gray;margin-bottom:5px;">').appendTo(div);
    }
    function starten() {
        init();
        displayOrderInfo();
        displayOrderDetails();
        displayOrderTickets();
    }

    starten();
}
sasoEventtickets_order_detail();