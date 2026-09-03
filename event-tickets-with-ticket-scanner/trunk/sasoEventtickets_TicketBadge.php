<?php
include_once(plugin_dir_path(__FILE__)."init_file.php");
class sasoEventtickets_TicketBadge {
    private $MAIN;

    private $codeObj;

    private $html;
    private $size_width;
    private $size_height;

    private $filepath = "";

    private $date_time_format;

	public static function Instance($html="") {
		static $inst = null;
        if ($inst === null) {
            $inst = new self($html);
        }
        return $inst;
	}

    public function __construct($html="") {
		global $sasoEventtickets;
		$this->MAIN = $sasoEventtickets;
    }

    private function setTemplate($html) {
        $this->html = trim($html);
        return $this;
    }

    public function getTemplate() {
        if (empty($this->html)) {
            return $this->getDefaultTemplate();
        }
        return $this->html;
    }

    public function getDefaultTemplate() {
        $html = '
        <b>{OPTIONS.wcTicketHeading}</b>
        <h2>{PRODUCT.name}</h2>
        <b>{TICKET.PRODUCT.ticket_start_date}</b>
        <h4>{ORDER.billing.first_name} {ORDER.billing.last_name}</h4>
        <br><center>{QRCODE_INLINE}</center>
        <br>{TICKET.code_display}
        <br>{TICKET.meta.wc_ticket._public_ticket_id}
        ';
        return $html;
    }

    /**
     * Single source of truth for the available replacement tags, grouped.
     * Used both for the help text (getReplacementTagsExplanation) and the
     * visual editor tag picker (vtext option type).
     *
     * @return array<int, array{group:string, tags:array<int,string>}>
     */
    public function getReplacementTags(): array {
        $groups = [];
        $groups[] = ['group' => 'Special', 'tags' => ['{QRCODE_INLINE}']];
        $groups[] = ['group' => 'TICKET', 'tags' => [
            '{TICKET.id}', '{TICKET.time}', '{TICKET.code}', '{TICKET.code_display}', '{TICKET.cvv}',
        ]];

        $metaTags = [];
        $metaObj  = $this->MAIN->getCore()->getMetaObject();
        foreach ($metaObj as $key => $value) {
            $name = 'TICKET.meta.' . $key;
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $metaTags[] = '{' . $name . '.' . $k . '}';
                }
            }
        }
        if ($metaTags) {
            $groups[] = ['group' => 'TICKET meta', 'tags' => $metaTags];
        }

        $groups[] = ['group' => 'Order', 'tags' => [
            '{ORDER.id}', '{ORDER.formatted_order_total}', '{ORDER.cart_tax}', '{ORDER.currency}',
            '{ORDER.item_count}', '{ORDER.item_total}', '{ORDER.items}', '{ORDER.coupon_codes}',
            '{ORDER.shipping_method}', '{ORDER.shipping_to_display}', '{ORDER.date.created}',
            '{ORDER.date.paid}', '{ORDER.date.completed}', '{ORDER.customer_id}', '{ORDER.user_id}',
            '{ORDER.customer_ip_address}', '{ORDER.customer_note}', '{ORDER.billing.first_name}',
            '{ORDER.billing.last_name}', '{ORDER.billing.company}', '{ORDER.billing.address_1}',
            '{ORDER.billing.address_2}', '{ORDER.billing.city}', '{ORDER.billing.state}',
            '{ORDER.billing.postcode}', '{ORDER.billing.country}', '{ORDER.billing.email}',
            '{ORDER.billing.phone}', '{ORDER.shipping_address}', '{ORDER.formatted_billing_full_name}',
            '{ORDER.formatted_shipping_full_name}', '{ORDER.formatted_billing_address}',
            '{ORDER.formatted_shipping_address}', '{ORDER.payment_method}', '{ORDER.payment_method_title}',
            '{ORDER.transaction_id}', '{ORDER.status}',
        ]];

        $groups[] = ['group' => 'Product', 'tags' => [
            '{PRODUCT.id}', '{PRODUCT.name}', '{PRODUCT.slug}', '{PRODUCT.date.created}',
            '{PRODUCT.date.modified}', '{PRODUCT.status}', '{PRODUCT.description}',
            '{PRODUCT.short_description}', '{PRODUCT.sku}', '{PRODUCT.price}', '{PRODUCT.regular_price}',
            '{PRODUCT.sale_price}', '{PRODUCT.stock_quantity}', '{PRODUCT.categories}', '{PRODUCT.average_rating}',
        ]];

        return $groups;
    }

    public function getReplacementTagsExplanation() {
        $text  = "Values from the <b>option</b> area can be referenced with the mentioned tag next to the label of the option.<br>";
        $text .= "{QRCODE_INLINE} = add the public ticket number as a QR Code.<br>";
        foreach ($this->getReplacementTags() as $g) {
            if ($g['group'] === 'Special') continue; // QRCODE_INLINE already explained above
            $text .= '<b>' . esc_html($g['group']) . '</b><ul>';
            foreach ($g['tags'] as $tag) {
                $text .= '<li>' . esc_html($tag) . '</li>';
                if ($tag === '{ORDER.items}') {
                    $text .= "<li><i>Use the loop to access the items. e.g. '&lt;ul>{{LOOP ORDER.items AS item}} &lt;li>{item.quantity} x {item.name}&lt;/li> {{LOOPEND}}&lt;/ul>'</i></li>";
                }
            }
            $text .= '</ul>';
            if ($g['group'] === 'Order') {
                $text .= "<p>If you need a meta value of the order, like an additional field. Get the field name and create the code like this: {ORDER.get_meta.YOURFIELDNAME}.<br>This will call the get_meta('YOURFIELDNAME').</p>";
            }
        }
        return $text;
    }

    public function setHTMLAndRender($codeObj, $html_to_render, $width=0, $height=0, $filemode="I") {
        $this->setHTMLToRender($codeObj, $html_to_render, $width, $height);
        return $this->renderPDF($filemode);
    }

    public function downloadPDFTicketBadge($codeObj) {
		$html = $this->MAIN->getOptions()->getOptionValue("wcTicketBadgeText", "");
		$w = $this->MAIN->getOptions()->getOptionValue("wcTicketBadgeSizeWidth", 0);
		$h = $this->MAIN->getOptions()->getOptionValue("wcTicketBadgeSizeHeight", 0);
		$this->setHTMLAndRender($codeObj, $html, $w, $h);
        exit;
    }
    public function getPDFTicketBadgeFilepath($codeObj, $filepath) {
        $this->filepath = trim($filepath);
		$html = $this->MAIN->getOptions()->getOptionValue("wcTicketBadgeText", "");
		$w = $this->MAIN->getOptions()->getOptionValue("wcTicketBadgeSizeWidth", 0);
		$h = $this->MAIN->getOptions()->getOptionValue("wcTicketBadgeSizeHeight", 0);
		return $this->setHTMLAndRender($codeObj, $html, $w, $h, "F");
    }

    private function setHTMLToRender($codeObj, $html_to_render, $width=0, $height=0) {
        $this->codeObj = $codeObj;
        $this->setTemplate($html_to_render);
        $this->size_width = intval($width);
        $this->size_height = intval($height);
    }

    private function renderPDF($filemode="I") {
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($this->codeObj['meta'], $this->codeObj);
		$ticket_id = $this->MAIN->getCore()->getTicketId($this->codeObj, $metaObj);
        $qr_content = $this->MAIN->getCore()->getQRCodeContent($this->codeObj, $metaObj);

        $width = $this->size_width > 0 ? $this->size_width : 80;
        $height = $this->size_height > 0 ? $this->size_height : 120;

        $html = $this->replacePlaceholder();

        $pdf = $this->MAIN->getNewPDFObject();
		$pdf->setFilemode($filemode);

        $pdf->setFilepath($this->filepath);
		$filename = "ticketbadge_".$this->codeObj['order_id']."_".$ticket_id.".pdf";
		$pdf->setFilename($filename);

        $pdf->setQRParams(['style'=>['position'=>'C'],'align'=>'N']);
        $qr_code_size = intval($this->MAIN->getOptions()->getOptionValue("wcTicketBadgeQRSize", 0));
        if ($qr_code_size > 0) {
            $pdf->setQRParams(['size'=>['width'=>$qr_code_size, 'height'=>$qr_code_size]]);
        }

        if($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketBadgePDFisRTL')) {
			$pdf->setRTL(true);
		}
		if ($pdf->isRTL()) {
			$lg = Array();
			$lg['a_meta_charset'] = 'UTF-8';
			$lg['a_meta_dir'] = 'rtl';
			$lg['a_meta_language'] = 'fa';
			$lg['w_page'] = 'page';
			// set some language-dependent strings (optional)
			$pdf->setLanguageArray($lg);
            $pdf->setQRParams(['style'=>['position'=>'T'],'align'=>'T']);
		}

        $product_id = intval($metaObj['woocommerce']['product_id']);

		// Background color (as fallback when no image or to fill gaps)
		$wcTicketPDFBackgroundColor = $this->MAIN->getOptions()->getOptionValue('wcTicketPDFBackgroundColor');
		if (!empty($wcTicketPDFBackgroundColor)) {
			$pdf->setBackgroundColor($wcTicketPDFBackgroundColor);
		}

		$wcTicketBadgeBG = $this->MAIN->getAdmin()->getOptionValue('wcTicketBadgeBG');
		$wcTicketBadgeBG = apply_filters( $this->MAIN->_add_filter_prefix.'wcTicketBadgeBG', $wcTicketBadgeBG, $product_id);
		if (!empty($wcTicketBadgeBG) && intval($wcTicketBadgeBG) >0) {
			$mediaData = SASO_EVENTTICKETS::getMediaData($wcTicketBadgeBG);
            if ($this->MAIN->getOptions()->isOptionCheckboxActive('wcTicketCompatibilityUseURL')) {
                if (!empty($mediaData['url'])) {
                    $pdf->setBackgroundImage($mediaData['url']);
                }
            } else {
                if (!empty($mediaData['for_pdf'])) {
				    $pdf->setBackgroundImage($mediaData['for_pdf']);
			    }
            }
		}

        $pdf->addPart($html);
        $qrTicketPDFPadding = intval($this->MAIN->getOptions()->getOptionValue('qrTicketPDFPadding'));
		$pdf->setQRCodeContent(["text"=>$qr_content, "style"=>["vpadding"=>$qrTicketPDFPadding, "hpadding"=>$qrTicketPDFPadding]]);
		$pdf->setSize($width, $height);
		$pdf->render();
        if ($pdf->getFilemode() == "F") {
			return $pdf->getFullFilePath();
		} else {
			exit;
		}
    }

    private function getPurchasedItemFromOderOnTicket($order) {
        $codeObj = $this->codeObj;
        $metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);

        if ($order == null) {
            $order_id = intval($codeObj['order_id']);
            $order = wc_get_order($order_id);
        }

        $ret = [];
        if ($order != null) {
			if (count($order->get_items()) > 1) {
				foreach ( $order->get_items() as $item_id => $item ) {
					if ($item_id == $metaObj['woocommerce']['item_id']) continue;
                    $product_id = $item->get_product_id();
                    $ret[] = $item;
				}
			}
		}
        return $ret;
    }

    private function replacePlaceholder() {
        $codeObj = $this->codeObj;
        $order = null;

        $html = " ".trim($this->getTemplate());

        $is_expired = $this->MAIN->getCore()->checkCodeExpired($codeObj);
        $this->date_time_format = $this->MAIN->getOptions()->getOptionDateTimeFormat();
        $date_format = $this->MAIN->getOptions()->getOptionDateFormat();
        $time_format = $this->MAIN->getOptions()->getOptionTimeFormat();

        while(true) {
            $loop = $this->MAIN->getCore()->parser_search_loop($html);
            if ($loop !== false) {
                // replace loop part with the actual values
                $loop_text = "";
                if (strtolower($loop['collection']) == "order.items") {
                    if ($order == null) {
                        $order_id = intval($codeObj['order_id']);
                        $order = wc_get_order($order_id);
                    }
                    $items = $this->getPurchasedItemFromOderOnTicket($order);
                    // iterate over items
                    foreach($items as $item) {
                        // create text for the items
                        $loop_text .= $this->replaceProductTemplateTags($item, $loop["loop_part"], $loop["item_var"]);
                    }
                }
                // replace loop with generated text
                $html = str_replace($loop["found_str"], $loop_text, $html);
            } else {
                break;
            }
        }

        $matches = [];
        if (preg_match_all('/\{OPTIONS\..*?\}/', $html, $matches)) {
            foreach($matches[0] as $item) {
                $key = substr(substr($item, 9), 0, -1);
                $value = $this->MAIN->getOptions()->getOptionValue($key, "");
                $html = str_replace($item, $value, $html);
            }
        }

        $metaObj = null;
        $product = null;

        if (!empty($html) && strpos($html, "{SITE_URL}")) $html = str_replace("{SITE_URL}", site_url(), $html);
        if (!empty($html) && strpos($html, "{TICKET.")) {
            $metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
            $codeObj['metaObj'] = $metaObj;

            $product_id = intval($metaObj['woocommerce']['product_id']);
            $product = wc_get_product( $product_id );
            $is_variation = $product->get_type() == "variation" ? true : false;
            $product_parent = $product;
            $product_parent_id = $product->get_parent_id();

            $saso_eventtickets_is_date_for_all_variants = true;
            if ($is_variation && $product_parent_id > 0) {
                $product_parent = wc_get_product( $product_parent_id );
                $saso_eventtickets_is_date_for_all_variants = get_post_meta( $product_parent->get_id(), 'saso_eventtickets_is_date_for_all_variants', true ) == "yes" ? true : false;
            }
            $product_for_dates = $product_parent;
            if (!$saso_eventtickets_is_date_for_all_variants) $product_for_dates = $product; // unter Umständen die Variante
            $product_dates = $this->MAIN->getTicketHandler()->calcDateStringAllowedRedeemFrom($product_for_dates->get_id(), $codeObj);

            $matches = [];
            if (preg_match_all('/\{TICKET\.PRODUCT\..*?\}/', $html, $matches)) {
                foreach($matches[0] as $item) {
                    $key = substr(substr($item, 16), 0, -1);
                    $value = $this->getValueOfArrayByPlaceholder($key, $product_dates);
                    if (!empty($value)) {
                        // Use date_i18n with gmt=true - ticket dates are stored in local time, gmt=true prevents timezone conversion but translates month/day names
                        if ($key == "ticket_start_date" || $key == "ticket_end_date") {
                            $value = date_i18n($date_format, strtotime($value), true);
                        }
                        if ($key == "ticket_start_time" || $key == "ticket_end_time") {
                            $value = date_i18n($date_format, strtotime($product_dates['ticket_start_date']." ".$value), true);
                        }
                    }
                    $html = str_replace($item, wp_kses_post($value), $html);
                }
            }

            $matches = [];
            if (preg_match_all('/\{TICKET\.meta\..*?\}/', $html, $matches)) {
                foreach($matches[0] as $item) {
                    $key = substr(substr($item, 8), 0, -1);
                    $value = $this->getValueOfArrayByPlaceholder($key, $metaObj);
                    $html = str_replace($item, wp_kses_post($value), $html);
                }
            }

            $cobj = $codeObj;
            $cobj['meta'] = $metaObj; // if special meta values are needed . ticket.meta.....
            $matches = [];
            if (preg_match_all('/\{TICKET\..*?\}/', $html, $matches)) {
                foreach($matches[0] as $item) {
                    $key = substr(substr($item, 8), 0, -1);
                    $value = $this->getValueOfArrayByPlaceholder($key, $codeObj);
                    $html = str_replace($item, wp_kses_post($value), $html);
                }
            }
        }

        if (!empty($html) && strpos($html, "{ORDER.")) {
            if ($order == null) {
                $order_id = intval($codeObj['order_id']);
                $order = wc_get_order($order_id);
            }
            if ($order != null) {
                $matches = [];
                if (preg_match_all('/\{ORDER\..*?\}/', $html, $matches)) {
                    foreach($matches[0] as $item) {
                        $key = substr(substr($item, 7), 0, -1);
                        if (is_array($order) && isset($order[$key])) {
                            $value = $this->getValueOfArrayByPlaceholder($key, $order);
                        } else {
                            $value = $this->getValueOfWCObject($key, $order);
                        }
                        if ($key == "date.paid" || $key == "date.completed" || $key == "date.created") {
                            $value = wp_date($this->date_time_format, strtotime($value));
                        }
                        $html = str_replace($item, wp_kses_post($value), $html);
                    }
                }
            }
        }
        if (!empty($html) && strpos($html, "{PRODUCT.")) {
            if ($product == null) {
                if ($metaObj == null) {
                    $metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObject($codeObj['meta'], $codeObj);
                }
                $product_id = intval($metaObj['woocommerce']['product_id']);
                $product = wc_get_product( $product_id );
            }
            $html = $this->setProductValues($product, $html);
        }

        return trim($html);
    }

    private function setProductValues($product, $html, $pattern='PRODUCT') {
        if ($product != null) {
            $html = $this->replaceProductTemplateTags($product, $html, $pattern);
        }
        return $html;
    }

    private function replaceProductTemplateTags($product, $html, $pattern) {
        $len_pattern = strlen($pattern) + 2;
        $matches = [];
        if (preg_match_all('/\{'.$pattern.'\..*?\}/', $html, $matches)) {
            foreach($matches[0] as $item) {
                $key = substr(substr($item, $len_pattern), 0, -1);
                $value = $this->getValueOfWCObject($key, $product);
                if ($key == "date.modified" || $key == "date.created") {
                    $value = wp_date($this->date_time_format, strtotime($value));
                }
                $html = str_replace($item, wp_kses_post($value), $html);
            }
        }
        return $html;
    }

    private function getValueOfArrayByPlaceholder($key, $object) {
        $value = "";
        $parts = explode(".", $key);
        $obj = $object;
        foreach($parts as $part) {
            if (isset($obj[$part])) {
                $obj = $obj[$part];
                $value = $obj;
            }
        }
        if (is_array($value)) {
            $value = $this->MAIN->getCore()->json_encode_with_error_handling($value);
        }
        return $value;
    }

    private function getValueOfWCObject($key, $object) {
        if (method_exists($object, "get_id")) {
            $product_original_id = $this->MAIN->getTicketHandler()->getWPMLProductId($object->get_id());
            $product_original = null;
            if ($product_original_id != $object->get_id()) {
                $product_original = $this->MAIN->getTicketHandler()->get_product($product_original_id);
            }
            if ($product_original != null) {
                $object = $product_original;
            }
        }

        $value = "";
        $method = "get_".str_replace(".", "_", $key);
        //if (method_exists($object, $method) && is_callable($object, $method)) {
        if (method_exists($object, $method)) {
            $value = $object->$method();
            if (is_array($value)) {
                $value = join(", ", $value);
            }
        } else {
            $teile = explode(".", $key);
            if (count($teile) > 1) { // min 2 elemente
                if ($teile[0] == "get_meta") {
                    $value = $object->get_meta($teile[1]);
                }
            }
        }
        return $value;
    }

}
?>