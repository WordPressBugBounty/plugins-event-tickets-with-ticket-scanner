<?php
include_once(plugin_dir_path(__FILE__)."init_file.php");
// https://twig.symfony.com/doc/3.x/
class sasoEventtickets_TicketDesigner {
    private $MAIN;

    private $codeObj;
    private $variables;

    private $html;

	public static function Instance($main=null, $html="") {
		static $inst = null;
        if ($inst === null) {
            $inst = new self($main, $html);
            require_once __DIR__.'/vendors/twig/autoload.php';
        }
        return $inst;
	}

    public function __construct($main=null, $html="") {
        if ($main == null) {
            global $sasoEventtickets;
            $this->MAIN = $sasoEventtickets;
        } else {
            $this->MAIN = $main;
        }
        $this->setTemplate($html);
    }

    public function getVariables() {
        return $this->variables;
    }

    public function setTemplate($html) {
        $this->html = trim($html);
        return $this;
    }

    public function renderHTML($codeObj, $forPDFOutput=false) {
        $codeObj = $this->MAIN->getCore()->setMetaObj($codeObj);
		$metaObj = $codeObj['metaObj'];
        $order_id = intval($codeObj['order_id']);
		$order = wc_get_order($order_id);
        if ($order == null) throw new Exception("#7001 Ticket Designer: Order not available");

        $listObj = $this->MAIN->getAdmin()->getList(['id'=>$codeObj['list_id']]);
        $list_metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObjectList($listObj['meta']);

		// suche item in der order
		$order_item = $this->MAIN->getTicketHandler()->getOrderItem($order, $metaObj);
		if ($order_item == null) throw new Exception("#7002 Order not found");
		$product = $order_item->get_product();
		$is_variation = $product->get_type() == "variation" ? true : false;
		$product_parent = $product;
		$product_parent_id = $product->get_parent_id();
		$saso_eventtickets_is_date_for_all_variants = true;
		if ($is_variation && $product_parent_id > 0) {
			$product_parent = $this->MAIN->getTicketHandler()->get_product( $product_parent_id );
			$saso_eventtickets_is_date_for_all_variants = get_post_meta( $product_parent->get_id(), 'saso_eventtickets_is_date_for_all_variants', true ) == "yes" ? true : false;
		}

        $ticket = [];
        $ticket["public_ticket_number"] = $this->MAIN->getCore()->getTicketId($codeObj, $metaObj);
		$date_time_format = $this->MAIN->getOptions()->getOptionDateTimeFormat();
		$ticket["location"] = trim(get_post_meta( $product_parent->get_id(), 'saso_eventtickets_event_location', true ));
		// zeige datum
		$tmp_product = $product_parent;
		if (!$saso_eventtickets_is_date_for_all_variants) $tmp_product = $product; // unter Umständen die Variante
        $ticket['timezone_id'] = wp_timezone_string();
        $ticket_times = $this->MAIN->getTicketHandler()->calcDateStringAllowedRedeemFrom($tmp_product->get_id(), $codeObj);
        $ticket["start_date"] = $ticket_times["ticket_start_date"];
        $ticket["start_time"] = $ticket_times["ticket_start_time"];
        $ticket['start_date_timestamp'] = $ticket_times["ticket_start_date_timestamp"];
        $ticket["end_date"] = $ticket_times["ticket_end_date"];
        $ticket["end_time"] = $ticket_times["ticket_end_time"];
        $ticket["end_date_timestamp"] = $ticket_times["ticket_end_date_timestamp"];
        $ticket["redeem_allowed_from"] = $ticket_times["redeem_allowed_from"];
        $ticket["redeem_allowed_from_timestamp"] = $ticket_times["redeem_allowed_from_timestamp"];
        $ticket["redeem_allowed_until"] = $ticket_times["redeem_allowed_until"];
        $ticket["redeem_allowed_until_timestamp"] = $ticket_times["redeem_allowed_until_timestamp"];
        $ticket["redeem_allowed_until"] = $ticket_times["redeem_allowed_until"];
        $ticket["is_event_over"] =  $ticket_times["is_date_set"] && $ticket_times["ticket_end_date_timestamp"] < $ticket_times["server_time_timestamp"];
        $ticket["is_daychooser"] = $ticket_times["is_daychooser"];
        $ticket["is_expired"] = $this->MAIN->getCore()->checkCodeExpired($codeObj); // prem expiration
        $ticket["date_as_string"] = $this->MAIN->getTicketHandler()->displayTicketDateAsString($tmp_product, $this->MAIN->getOptions()->getOptionDateFormat(), $this->MAIN->getOptions()->getOptionTimeFormat(), $codeObj);
        $ticket["short_desc"] = $is_variation ? $product_parent->get_short_description() : $product->get_short_description();
        $ticket["info"] = trim(get_post_meta( $product_parent->get_id(), 'saso_eventtickets_ticket_is_ticket_info', true ));

        //$ticket["date_time_format"] = str_replace("Y","yyyy", str_replace("i", "mm", str_replace("H", "kk", str_replace("d", "dd", str_replace("m", "MM", $date_time_format)))));
        $ticket["date_time_format"] = $date_time_format;

        $ticket["order_date_paid_text"] = empty($order->get_date_paid()) ? "-" : wp_date($date_time_format, strtotime($order->get_date_paid()));
        $ticket["order_date_completed_text"] = empty($order->get_date_completed()) ? "-" : wp_date($date_time_format, strtotime($order->get_date_completed()));
        $ticket["order_item_pos"] = 1;
        $ticket["codes"] = explode(",", $order_item->get_meta('_saso_eventtickets_product_code', true));
        if (count($ticket["codes"]) > 1) {
            // ermittel ticket pos
            $ticket["order_item_pos"] = $this->MAIN->getTicketHandler()->ermittelCodePosition($codeObj['code_display'], $ticket["codes"]);
        }
        $ticket["text_redeem_amount"] = $this->MAIN->getTicketHandler()->getRedeemAmountText($codeObj, $metaObj, $forPDFOutput);
        $ticket["qrCodeContent"] = $this->MAIN->getCore()->getQRCodeContent($codeObj, $metaObj);

        $label = $this->MAIN->getTicketHandler()->getLabelNamePerTicket($product_parent->get_id());
        $ticket["name_per_ticket_pos"] = "";
        if (strpos(" ".$label, "{count}") > 0) {
            // ermittel ticket pos
            $codes = explode(",", $order_item->get_meta("_saso_eventtickets_product_code", true));
            $ticket["name_per_ticket_pos"] = $this->MAIN->getTicketHandler()->ermittelCodePosition($codeObj["code_display"], $codes);
            $label = str_replace("{count}", $ticket["name_per_ticket_pos"], $label);
        }
		$ticket['name_per_ticket_label'] = $label;

        $label = $this->MAIN->getTicketHandler()->getLabelValuePerTicket($product_parent->get_id());
        $ticket["value_per_ticket_pos"] = "";
        if (strpos(" ".$label, "{count}") > 0) {
            // ermittel ticket pos
            $codes = explode(",", $order_item->get_meta("_saso_eventtickets_product_code", true));
            $ticket["value_per_ticket_pos"] = $this->MAIN->getTicketHandler()->ermittelCodePosition($codeObj["code_display"], $codes);
            $label = str_replace("{count}", $ticket["value_per_ticket_pos"], $label);
        }
		$ticket['value_per_ticket_label'] = $label;

        $label = $this->MAIN->getTicketHandler()->getLabelDaychooserPerTicket($product_parent->get_id());
        $ticket["day_per_ticket_pos"] = "";
        if (strpos(" ".$label, "{count}") > 0) {
            // ermittel ticket pos
            $codes = explode(",", $order_item->get_meta("_saso_eventtickets_product_code", true));
            $ticket["day_per_ticket_pos"] = $this->MAIN->getTicketHandler()->ermittelCodePosition($codeObj["code_display"], $codes);
            $label = str_replace("{count}", $ticket["day_per_ticket_pos"], $label);
        }
		$ticket['day_per_ticket_label'] = $label;

        $options = [];
        foreach($this->MAIN->getOptions()->getOptionsKeys() as $key) {
            if ($key == "wcTicketDesignerTemplate") continue;
            $options[$key] = $this->MAIN->getOptions()->getOptionValue($key);
        }

        $html = $this->getTemplate();

        $loader = new \Twig\Loader\ArrayLoader(['index' => $html]);
        $twig = new \Twig\Environment($loader);
        $twig->getExtension(\Twig\Extension\CoreExtension::class)->setTimezone($ticket['timezone_id']);

        $twig->getExtension(\Twig\Extension\EscaperExtension::class)->setEscaper('wp_kses_post', function($twig_env, $value, $charset) {
            return wp_kses_post($value);
        });
        $twig->getExtension(\Twig\Extension\EscaperExtension::class)->setEscaper('wp_filter_nohtml_kses', function ($twig_env, $value, $charset) {
            return wp_filter_nohtml_kses($value);
        });
        $twig->getExtension(\Twig\Extension\EscaperExtension::class)->setEscaper('stripslashes', function ($twig_env, $value, $charset) {
            return stripslashes($value);
        });
        $filter_format_datetime = new \Twig\TwigFilter('format_datetime', function ($date, $pattern="", $timezone="") {
            if (empty($pattern)) {
                $pattern = $this->MAIN->getOptions()->getOptionDateTimeFormat();
            }
            if (is_object($date)) {
                if (!empty($timezone)) {
                    $date->setTimezone($timezone);
                }
                return $date->format($pattern);
            } else if (is_int($date)) {
                return date($pattern, $date);
            }
            return date($pattern, strtotime($date));
        });
        $twig->addFilter($filter_format_datetime);
        $filter_stripslashes = new \Twig\TwigFilter('stripslashes', function ($text) {
            return stripslashes($text);
        });
        $twig->addFilter($filter_stripslashes);

        $filter_wc_price = new \Twig\TwigFunction('wc_price', function ($value) {
            return wc_price($value, ['decimals'=>2]);
        });
        $twig->addFunction($filter_wc_price);
        $filter_getMediaData = new \Twig\TwigFunction('getMediaData', function ($media_id) {
            return SASO_EVENTTICKETS::getMediaData($media_id);
        });
        $twig->addFunction($filter_getMediaData);

        // hmm. oder get_product_addons auf dem Produkt. - no clue which plugin is extending the wc product to have add ons
        if (function_exists("wc_product_addons_get_product_addons")) {
            $filter_wc_product_addons_get_product_addons = new \Twig\TwigFunction('wc_product_addons_get_product_addons', function ($product) {
                return wc_product_addons_get_product_addons($product); // wc plugin spezifisch
            });
            $twig->addFunction($filter_wc_product_addons_get_product_addons);
        } else {
            $filter_wc_product_addons_get_product_addons = new \Twig\TwigFunction('wc_product_addons_get_product_addons', function ($product) {
                return [];
            });
            $twig->addFunction($filter_wc_product_addons_get_product_addons);
        }
        if (function_exists("get_field")) { // ACF support
            $filter_get_field = new \Twig\TwigFunction('get_field', function ($field_name, $product_id, array $options = []) {
                return get_field($field_name, $product_id, ...$options);
            }, ['is_variadic' => true]);
            $twig->addFunction($filter_get_field);
        }

        global $wpdb;

        $this->variables = [
            'PRODUCT' => $product,
            'PRODUCT_PARENT' => $product_parent,
            'OPTIONS' => $options,
            'TICKET' => $ticket,
            'ORDER' => $order,
            'CUSTOMER' => $order->get_user(),
            'ORDER_ITEM' => $order_item,
            'CODEOBJ' => $codeObj,
            'METAOBJ' => $metaObj,
            'LISTOBJ' => $listObj,
            'LIST_METAOBJ' => $list_metaObj,
            'is_variation' => $is_variation,
            'forPDFOutput' => $forPDFOutput,
            'isScanner' => $this->MAIN->getTicketHandler()->isScanner(),
            'SERVER' => [
                "time"=>date("Y-m-d H:i:s", current_time("timestamp")),
                "timestamp"=>current_time("timestamp"),
                "timezone"=>wp_timezone()
            ],
            'WPDB' => $wpdb
        ];
        $output = $twig->render('index', $this->variables);

        return $output;
    }

    public function getTemplate() {
        if (empty($this->html)) {
            return $this->getDefaultTemplate();
        }
        return $this->html;
    }

    public function getTemplateList() {
        $ret = [];
        $methods = get_class_methods($this);
        foreach ($methods as $method) {
            if (strlen($method) > 12 && "getTicketTemplate_" == substr($method, 0, 18)) {
                $ret[] = array_merge(["template"=>$method], $this->$method());
            }
        }
        return $ret;
    }

    private function getTicketTemplate_0() {
        return ["image_url"=>"ticket_template_0.jpg",
            "wcTicketPDFisRTLTest"=>false, "wcTicketSizeWidthTest"=>210, "wcTicketSizeHeightTest"=>297, "wcTicketQRSizeTest"=>50, "wcTicketPDFZeroMarginTest"=>false,
            "wcTicketDesignerTemplateTest"=>'{% apply spaceless %}{% autoescape false %}
            {%- if not forPDFOutput -%}
                <h3 style="color:black;text-align:center;">{{ OPTIONS.wcTicketHeading|escape(\'wp_kses_post\')|raw }}</h3>
            {% else %}
                <style>h4{font-size:16pt;} table.ticket_content_upper {width:14cm;padding-top:10pt;} table.ticket_content_upper td {height:5cm;}</style>
                <h1 style="font-size:20pt;text-align:center;">{{ OPTIONS.wcTicketHeading|escape|raw }}</h1>
            {%- endif -%}

            <h4 style="color:black;">{{ PRODUCT.get_name|escape }}</h4>

            <table style="width:100%;padding:0;margin:0;" class="ticket_content_upper">
                <tr valign="top">
                    <td style="{% if forPDFOutput %}width:70%;{% endif %}padding:0;margin:0;{% if not forPDFOutput %}background-color:white;border:0;{% endif %}">
                        {%- if OPTIONS.wcTicketPDFDisplayVariantName and PRODUCT.get_attributes|length > 0 -%}
                            <p>
                            {%- for item in PRODUCT.get_attributes -%}
                                {% if item is not iterable %}
                                    {{- item|striptags -}}
                                {% endif %}
                            {%- endfor -%}
                            </p>
                        {%- endif -%}

                        {%- if not OPTIONS.wcTicketHideDateOnPDF and TICKET.start_date is not empty -%}
                            <p>{{- TICKET.date_as_string -}}
                            {%- if TICKET.end_date is not empty and TICKET.is_event_over -%}
                                <span style="color:red;"> {{ OPTIONS.wcTicketTransExpired }}</span>
                            {%- endif -%}
                            {%- if TICKET.location is not empty -%}
                                <br>{{ OPTIONS.wcTicketTransLocation }} <b>{{ TICKET.location|escape(\'wp_kses_post\') }}</b>
                            {%- endif -%}
                            </p>
                        {%- else -%}
                            {%- if TICKET.location is not empty -%}
                                <p>{{ OPTIONS.wcTicketTransLocation }} <b>{{ TICKET.location|escape(\'wp_kses_post\') }}</b></p>
                            {%- endif -%}
                        {%- endif -%}

                        {%- if OPTIONS.wcTicketDisplayShortDesc -%}
                            <p>
                            {%- if OPTIONS.wcTicketPDFStripHTML == 3 -%}
                                {{- TICKET.short_desc|escape -}}
                            {% endif %}
                            {%- if OPTIONS.wcTicketPDFStripHTML == 2 -%}
                                {{- TICKET.short_desc|escape(\'wp_filter_nohtml_kses\')|stripslashes -}}
                            {%- else -%}
                                {{- TICKET.short_desc|escape(\'wp_kses_post\') -}}
                            {% endif %}
                            </p>
                        {% endif %}

                        {%- if TICKET.info is not empty -%}
                            <p>
                            {%- if OPTIONS.wcTicketPDFStripHTML == 3 -%}
                                {{- TICKET.info|escape -}}
                            {% endif %}
                            {%- if OPTIONS.wcTicketPDFStripHTML == 2 -%}
                                {{- TICKET.info|escape(\'wp_filter_nohtml_kses\')|stripslashes -}}
                            {%- else -%}
                                {{- TICKET.info|escape(\'wp_kses_post\') -}}
                            {%- endif -%}
                            </p>
                        {%- endif -%}
                    </td>
                    {% if forPDFOutput %}<td style="width:30%";>{QRCODE_INLINE}</td>{% endif %}
                </tr>
            </table>
            {%- if forPDFOutput -%}<br><br>{% endif %}

            <table style="width:100%;padding:0;margin:0;">
                <tr valign="top">
                    <td style="color:black;width:50%;padding:0;padding-right:5px;margin:0;{% if not forPDFOutput %}background-color:white;border:0;{% endif %}">
                        {%- if not OPTIONS.wcTicketDontDisplayCustomer -%}
                            <p><b>{{ OPTIONS.wcTicketTransCustomer|escape(\'wp_kses_post\') }}</b>
                            <br>{{ ORDER.get_formatted_billing_address|trim|escape(\'wp_kses_post\') }}
                            </p>
                        {% endif %}
                    </td>
                    <td style="color:black;width:50%;padding:0;margin:0;{% if not forPDFOutput %}background-color:white;border:0;{% endif %}">
                        {%- if not OPTIONS.wcTicketDontDisplayPayment -%}
                            <p><b>{{ OPTIONS.wcTicketTransPaymentDetail|escape(\'wp_kses_post\') }}</b>
                            <br>{{ OPTIONS.wcTicketTransPaymentDetailPaidAt|escape(\'wp_kses_post\') }} <b>{{ TICKET.order_date_paid_text|escape }}</b>
                            <br>{{ OPTIONS.wcTicketTransPaymentDetailCompletedAt|escape(\'wp_kses_post\') }} <b>{{ TICKET.order_date_completed_text|escape }}</b>
                            {%- if ORDER.get_payment_method_title is empty %}
                                <br>{{ OPTIONS.wcTicketTransPaymentDetailFreeTicket|escape(\'wp_kses_post\') }}
                            {% else %}
                                <br>{{ OPTIONS.wcTicketTransPaymentDetailPaidVia|escape(\'wp_kses_post\') }} <b>{{ ORDER.get_payment_method_title|escape }} (#{{ ORDER.get_transaction_id|escape }})</b>
                            {% endif %}
                            {%- if ORDER.get_coupon_codes -%}
                                <br>{{ OPTIONS.wcTicketTransPaymentDetailCouponUsed|escape(\'wp_kses_post\') }} <b>{{ ORDER.get_coupon_codes|join(", ") }}</b><br>
                            {% endif %}
                            </p>
                        {% endif %}
                    </td>
                </tr>
            </table>

            {%- if METAOBJ.user.value is not empty and OPTIONS.wcTicketDisplayTicketUserValue -%}
                <p>{{ OPTIONS.wcTicketTransDisplayTicketUserValue|escape(\'wp_kses_post\') }} {{ METAOBJ.user.value|escape }}</p>
            {%- endif -%}

            {%- if METAOBJ.wc_ticket.is_daychooser == 1 and METAOBJ.wc_ticket.day_per_ticket is not empty -%}
                <p>{{ TICKET.day_per_ticket_label ~ " " ~ METAOBJ.wc_ticket.day_per_ticket }}</p>
            {%- endif -%}

            {%- if METAOBJ.wc_ticket.name_per_ticket is not empty -%}
                <p>{{ TICKET.name_per_ticket_label ~ " " ~ METAOBJ.wc_ticket.name_per_ticket }}</p>
            {%- endif -%}

            {%- if METAOBJ.wc_ticket.value_per_ticket is not empty -%}
                <p>{{ TICKET.value_per_ticket_label ~ " " ~ METAOBJ.wc_ticket.value_per_ticket }}</p>
            {%- endif -%}

            {%- if OPTIONS.wcTicketDisplayPurchasedItemFromOrderOnTicket and ORDER.get_items|length > 1 %}
                <br><b>Additional order items</b>
                {% for item_id, item in ORDER.get_items %}
                    {%- if item_id != METAOBJ.woocommerce.item_id -%}
                        <br>{{ item.get_quantity }}x {{ item.get_name|escape }}
                    {%- endif -%}
                {% endfor %}
            {%- endif -%}

            {%- if OPTIONS.wcTicketDisplayProductAddons %}
                {% set addOns = PRODUCT.get_meta("_product_addons", true) %}
                {%- if addOns is not empty %}
                    <br><b>Add-ons</b>
                    {% for item in addOns %}
                        <br>{{ item["name"]|escape }}
                    {% endfor %}
                {%- endif -%}
            {%- endif -%}

            {%- if OPTIONS.wcTicketDisplayCustomerNote and ORDER.get_customer_note is not empty -%}
                <p><i>"{{ ORDER.get_customer_note|escape }}"</i></p>
            {% endif %}

            {%- if OPTIONS.wcTicketDisplayPurchasedTicketQuantity -%}
                {% if forPDFOutput %}<br>{% endif %}
                <br>{{ OPTIONS.wcTicketPrefixTextTicketQuantity|escape(\'wp_kses_post\')|replace({\'{TICKET_POSITION}\': TICKET.order_item_pos, \'{TICKET_TOTAL_AMOUNT}\': TICKET.codes|length}) }}
            {% endif %}

            {%- if TICKET.text_redeem_amount is not empty -%}
                <br>{{ TICKET.text_redeem_amount }}
            {% endif %}

            <br><br>
            <table style="width:100%;padding:0;margin:0;">
                <tr valign="top">
                    <td style="color:black;width:50%;padding:0;padding-right:5px;margin:0;{% if not forPDFOutput %}background-color:white;border:0;{% endif %}">{{ OPTIONS.wcTicketTransTicket|escape(\'wp_kses_post\') }} <b>{{ CODEOBJ.code_display }}</b>
                        {%- if not OPTIONS.wcTicketDontDisplayPrice -%}
                            <br>{{ OPTIONS.wcTicketTransPrice|escape(\'wp_kses_post\') }}
                            <b>{{ wc_price((ORDER_ITEM.get_subtotal + ORDER_ITEM.get_subtotal_tax) / ORDER_ITEM.get_quantity) }}</b>
                            {% if PRODUCT.get_price != ORDER_ITEM.get_subtotal %}
                            <br>{{ OPTIONS.wcTicketTransProductPrice|escape(\'wp_kses_post\') }} {{ wc_price(PRODUCT.get_price) }}
                            {% endif %}
                        {% endif %}
                    </td>
                    <td style="color:black;width:50%;padding:0;margin:0;{% if not forPDFOutput %}background-color:white;border:0;{% endif %}">
                        {%- if OPTIONS.wcTicketDisplayTicketListName -%}
                            {{ LISTOBJ.name|escape(\'wp_kses_post\')|raw|nl2br }}
                        {% endif %}
                        {%- if OPTIONS.wcTicketDisplayTicketListDesc -%}
                            {%- if OPTIONS.wcTicketDisplayTicketListName -%}<br>{% endif %}{{ LIST_METAOBJ.desc|escape(\'wp_kses_post\')|raw|nl2br }}<br>
                        {% endif %}
                    </td>
                </tr>
            </table>

            {%- if not forPDFOutput -%}
                {%- if not isScanner -%}
                    <div id="qrcode" style="background-color:white !important;padding:15px;margin-top:3em;text-align:center;"></div>
                    <script>jQuery("#qrcode").qrcode("{{ TICKET.qrCodeContent|raw }}");</script>
                {% endif %}

                <p style="text-align:center;">{{ TICKET.public_ticket_number }}</p>
            {% else %}
                <br><br><p style="text-align:center;">{{ TICKET.public_ticket_number }}</p>
                {%- if OPTIONS.wcTicketAdditionalTextBottom is not empty -%}
                    {{ OPTIONS.wcTicketAdditionalTextBottom|raw|nl2br }}
                {%- endif -%}
            {% endif %}

            {% endautoescape %}{% endapply %}'];
    }

    private function getTicketTemplate_1() {
        return ["image_url"=>"ticket_template_1.jpg",
            "wcTicketPDFisRTLTest"=>false, "wcTicketSizeWidthTest"=>210, "wcTicketSizeHeightTest"=>90, "wcTicketQRSizeTest"=>50, "wcTicketPDFZeroMarginTest"=>true,
            "wcTicketDesignerTemplateTest"=>'{% apply spaceless %}{% autoescape false %}
        {%- if forPDFOutput -%}
            <table style="width:100%;padding:0;margin:0;">
                <tr>
                    <td style="width:33%;"><img width="70mm" src="{{ getMediaData(PRODUCT.get_image_id).url }}"></td>
                    <td style="width:34%;font-size:10pt;">
                        <div style="color:black;text-align:center;font-size:14pt;font-weight:bold;">{{ PRODUCT.get_name|escape }}</div>

                        {%- if not OPTIONS.wcTicketHideDateOnPDF and TICKET.start_date is not empty -%}
                        <table style="font-size:9p;"><tr>
                            <td style="border-top:1px solid black;border-bottom:1px solid black;">{{ TICKET.start_date_timestamp|date("l")|upper }}</td>
                            <td style="border-top:1px solid black;border-bottom:1px solid black;text-align:center;color:#d83565;"><b>{{ TICKET.start_date_timestamp|date("M jS")|upper }}</b></td>
                            <td style="border-top:1px solid black;border-bottom:1px solid black;text-align:right;">{{ TICKET.start_date_timestamp|date("Y")|upper }}</td>
                        </tr></table>
                        {%- endif -%}

                        {%- if OPTIONS.wcTicketDisplayShortDesc -%}
                            <div>
                            {%- if OPTIONS.wcTicketPDFStripHTML == 3 -%}
                                {{- TICKET.short_desc|escape -}}
                            {% endif %}
                            {%- if OPTIONS.wcTicketPDFStripHTML == 2 -%}
                                {{- TICKET.short_desc|escape(\'wp_filter_nohtml_kses\')|stripslashes -}}
                            {%- else -%}
                                {{- TICKET.short_desc|escape(\'wp_kses_post\')|raw|nl2br -}}
                            {% endif %}
                            </div>
                        {% endif %}

                        {%- if not OPTIONS.wcTicketDontDisplayPrice -%}
                            <div style="text-align:center;">
                            <br><b>{{ wc_price((ORDER_ITEM.get_subtotal + ORDER_ITEM.get_subtotal_tax) / ORDER_ITEM.get_quantity) }}</b>
                            </div>
                        {% endif %}

                        {%- if TICKET.location is not empty -%}
                            <div style="text-align:center;font-size:9pt;border-top:1px solid black;">{{ TICKET.location|escape(\'wp_kses_post\') }}</div>
                        {%- endif -%}

                        <div style="text-align:center;font-size:8pt;">{{ TICKET.public_ticket_number }}</div>
                    </td>
                    <td style="width:33%;text-align:center;">
                        <br>
                        {%- if TICKET.info is not empty -%}
                            <p>
                            {%- if OPTIONS.wcTicketPDFStripHTML == 3 -%}
                                {{- TICKET.info|escape -}}
                            {% endif %}
                            {%- if OPTIONS.wcTicketPDFStripHTML == 2 -%}
                                {{- TICKET.info|escape(\'wp_filter_nohtml_kses\')|stripslashes -}}
                            {%- else -%}
                                {{- TICKET.info|escape(\'wp_kses_post\')|raw|nl2br -}}
                            {%- endif -%}
                            </p>
                        {%- endif -%}
                        {QRCODE_INLINE}
                    </td>
                </tr>
            </table>
        {% else %}
            <!-- screen -->
            <h3 style="color:black;text-align:center;">{{ OPTIONS.wcTicketHeading|escape(\'wp_kses_post\')|raw }}</h3>
            <h4 style="color:black;">{{ PRODUCT.get_name|escape }}</h4>

            <table style="width:100%;padding:0;margin:0;" class="ticket_content_upper">
                <tr valign="top">
                    <td style="padding:0;margin:0;background-color:white;">
                        {%- if OPTIONS.wcTicketPDFDisplayVariantName and PRODUCT.get_attributes|length > 0 -%}
                            <p>
                            {%- for item in PRODUCT.get_attributes -%}
                                {% if item is not iterable %}
                                    {{- item|striptags -}}
                                {% endif %}
                            {%- endfor -%}
                            </p>
                        {%- endif -%}

                        {%- if not OPTIONS.wcTicketHideDateOnPDF and TICKET.start_date is not empty -%}
                            <p>{{- TICKET.date_as_string -}}
                            {%- if TICKET.end_date is not empty and TICKET.is_event_over -%}
                                <span style="color:red;"> {{ OPTIONS.wcTicketTransExpired }}</span>
                            {%- endif -%}
                            {%- if TICKET.location is not empty -%}
                                <br>{{ OPTIONS.wcTicketTransLocation }} <b>{{ TICKET.location|escape(\'wp_kses_post\') }}</b>
                            {%- endif -%}
                            </p>
                        {%- else -%}
                            {%- if TICKET.location is not empty -%}
                                <p>{{ OPTIONS.wcTicketTransLocation }} <b>{{ TICKET.location|escape(\'wp_kses_post\') }}</b></p>
                            {%- endif -%}
                        {%- endif -%}

                        {%- if OPTIONS.wcTicketDisplayShortDesc -%}
                            <p>
                            {%- if OPTIONS.wcTicketPDFStripHTML == 3 -%}
                                {{- TICKET.short_desc|escape -}}
                            {% endif %}
                            {%- if OPTIONS.wcTicketPDFStripHTML == 2 -%}
                                {{- TICKET.short_desc|escape(\'wp_filter_nohtml_kses\')|stripslashes -}}
                            {%- else -%}
                                {{- TICKET.short_desc|escape(\'wp_kses_post\') -}}
                            {% endif %}
                            </p>
                        {% endif %}

                        {%- if TICKET.info is not empty -%}
                            <p>
                            {%- if OPTIONS.wcTicketPDFStripHTML == 3 -%}
                                {{- TICKET.info|escape -}}
                            {% endif %}
                            {%- if OPTIONS.wcTicketPDFStripHTML == 2 -%}
                                {{- TICKET.info|escape(\'wp_filter_nohtml_kses\')|stripslashes -}}
                            {%- else -%}
                                {{- TICKET.info|escape(\'wp_kses_post\') -}}
                            {%- endif -%}
                            </p>
                        {%- endif -%}
                    </td>
                </tr>
            </table>

            <table style="width:100%;padding:0;margin:0;">
                <tr valign="top">
                    <td style="color:black;width:50%;padding:0;padding-right:5px;margin:0;background-color:white;border:0;">
                        {%- if not OPTIONS.wcTicketDontDisplayCustomer -%}
                            <p><b>{{ OPTIONS.wcTicketTransCustomer|escape(\'wp_kses_post\') }}</b>
                            <br>{{ ORDER.get_formatted_billing_address|trim|escape(\'wp_kses_post\') }}
                            </p>
                        {% endif %}
                    </td>
                    <td style="color:black;width:50%;padding:0;margin:0;background-color:white;border:0;">
                        {%- if not OPTIONS.wcTicketDontDisplayPayment -%}
                            <p><b>{{ OPTIONS.wcTicketTransPaymentDetail|escape(\'wp_kses_post\') }}</b>
                            <br>{{ OPTIONS.wcTicketTransPaymentDetailPaidAt|escape(\'wp_kses_post\') }} <b>{{ TICKET.order_date_paid_text|escape }}</b>
                            <br>{{ OPTIONS.wcTicketTransPaymentDetailCompletedAt|escape(\'wp_kses_post\') }} <b>{{ TICKET.order_date_completed_text|escape }}</b>
                            {%- if ORDER.get_payment_method_title is empty %}
                                <br>{{ OPTIONS.wcTicketTransPaymentDetailFreeTicket|escape(\'wp_kses_post\') }}
                            {% else %}
                                <br>{{ OPTIONS.wcTicketTransPaymentDetailPaidVia|escape(\'wp_kses_post\') }} <b>{{ ORDER.get_payment_method_title|escape }} (#{{ ORDER.get_transaction_id|escape }})</b>
                            {% endif %}
                            {%- if ORDER.get_coupon_codes -%}
                                <br>{{ OPTIONS.wcTicketTransPaymentDetailCouponUsed|escape(\'wp_kses_post\') }} <b>{{ ORDER.get_coupon_codes|join(", ") }}</b><br>
                            {% endif %}
                            </p>
                        {% endif %}
                    </td>
                </tr>
            </table>

            {%- if METAOBJ.user.value is not empty and OPTIONS.wcTicketDisplayTicketUserValue -%}
                <p>{{ OPTIONS.wcTicketTransDisplayTicketUserValue|escape(\'wp_kses_post\') }} {{ METAOBJ.user.value|escape }}</p>
            {%- endif -%}

            {%- if METAOBJ.wc_ticket.name_per_ticket is not empty -%}
                <p>{{ TICKET.name_per_ticket_label ~ " " ~ METAOBJ.wc_ticket.name_per_ticket }}</p>
            {%- endif -%}

            {%- if METAOBJ.wc_ticket.value_per_ticket is not empty -%}
                <p>{{ TICKET.value_per_ticket_label ~ " " ~ METAOBJ.wc_ticket.value_per_ticket }}</p>
            {%- endif -%}

            {%- if OPTIONS.wcTicketDisplayPurchasedItemFromOrderOnTicket and ORDER.get_items|length > 1 %}
                <br><b>Additional order items</b>
                {% for item_id, item in ORDER.get_items %}
                    {%- if item_id != METAOBJ.woocommerce.item_id -%}
                        <br>{{ item.get_quantity }}x {{ item.get_name|escape }}
                    {%- endif -%}
                {% endfor %}
            {%- endif -%}

            {%- if OPTIONS.wcTicketDisplayCustomerNote and ORDER.get_customer_note is not empty -%}
                <p><i>"{{ ORDER.get_customer_note|escape }}"</i></p>
            {% endif %}

            {%- if OPTIONS.wcTicketDisplayPurchasedTicketQuantity -%}
                {% if forPDFOutput %}<br>{% endif %}
                <br>{{ OPTIONS.wcTicketPrefixTextTicketQuantity|escape(\'wp_kses_post\')|replace({\'{TICKET_POSITION}\': TICKET.order_item_pos, \'{TICKET_TOTAL_AMOUNT}\': TICKET.codes|length}) }}
            {% endif %}

            {%- if TICKET.text_redeem_amount is not empty -%}
                <br>{{ TICKET.text_redeem_amount }}
            {% endif %}

            <br><br>
            <table style="width:100%;padding:0;margin:0;">
                <tr valign="top">
                    <td style="color:black;width:50%;padding:0;padding-right:5px;margin:0;background-color:white;border:0;">{{ OPTIONS.wcTicketTransTicket|escape(\'wp_kses_post\') }} <b>{{ CODEOBJ.code_display }}</b>
                        {%- if not OPTIONS.wcTicketDontDisplayPrice -%}
                            <br>{{ OPTIONS.wcTicketTransPrice|escape(\'wp_kses_post\') }}
                            <b>{{ wc_price(ORDER_ITEM.get_subtotal + ORDER_ITEM.get_subtotal_tax) }}</b>
                            {% if PRODUCT.get_price != ORDER_ITEM.get_subtotal %}
                            <br>{{ OPTIONS.wcTicketTransProductPrice|escape(\'wp_kses_post\') }} {{ wc_price(PRODUCT.get_price) }}
                            {% endif %}
                        {% endif %}
                    </td>
                    <td style="color:black;width:50%;padding:0;margin:0;background-color:white;border:0;">
                        {%- if OPTIONS.wcTicketDisplayTicketListName -%}
                            {{ LISTOBJ.name|escape(\'wp_kses_post\')|raw|nl2br }}
                        {% endif %}
                        {%- if OPTIONS.wcTicketDisplayTicketListDesc -%}
                            {%- if OPTIONS.wcTicketDisplayTicketListName -%}<br>{% endif %}{{ LIST_METAOBJ.desc|escape(\'wp_kses_post\')|raw|nl2br }}<br>
                        {% endif %}
                    </td>
                </tr>
            </table>

            {%- if not isScanner -%}
                <div id="qrcode" style="background-color:white !important;padding:15px;margin-top:3em;text-align:center;"></div>
                <script>jQuery("#qrcode").qrcode("{{ TICKET.qrCodeContent|raw }}");</script>
            {% endif %}
            <p style="text-align:center;">{{ TICKET.public_ticket_number }}</p>

        {%- endif -%}
        {% endautoescape %}{% endapply %}'];
    }

    public function getDefaultTemplate() {
        $template = $this->getTicketTemplate_0();
        return $template["wcTicketDesignerTemplateTest"];
    }
}
?>
