<?php
/**
 * Batch 48 — TicketDesigner & TicketBadge:
 * - TicketDesigner: setTemplate, getTemplate, getDefaultTemplate, getTemplateList, renderHTML, getVariables
 * - TicketBadge: Instance, getDefaultTemplate, getReplacementTagsExplanation, getTemplate
 */

class TicketDesignerAndBadgeTest extends WP_UnitTestCase {

	private $main;

	public function set_up(): void {
		parent::set_up();
		$this->main = sasoEventtickets::Instance();

		if (!class_exists('WC_Product_Simple')) {
			$this->markTestSkipped('WooCommerce not available');
		}
	}

	/**
	 * Create a ticket product with optional meta, an order, and a linked code.
	 * Returns ['product_id', 'order', 'order_id', 'item_id', 'code', 'codeObj'].
	 */
	private function createFullTicketSetup(array $productMeta = []): array {
		$product = new WC_Product_Simple();
		$product->set_name('Designer Test ' . uniqid());
		$product->set_regular_price('25.00');
		$product->set_status('publish');
		$product->save();
		$pid = $product->get_id();

		update_post_meta($pid, 'saso_eventtickets_is_ticket', 'yes');

		foreach ($productMeta as $key => $value) {
			update_post_meta($pid, $key, $value);
		}

		// Create list
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'Designer List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		// Create order with product
		$order = wc_create_order();
		$order->add_product($product, 1);
		$order->set_status('completed');
		$order->save();

		$items = $order->get_items();
		$firstItemId = array_key_first($items);

		// Create code linked to product and order
		$metaObj = $this->main->getCore()->getMetaObject();
		$metaObj['woocommerce']['product_id'] = $pid;
		$metaObj['woocommerce']['item_id'] = $firstItemId;
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

		$codeStr = 'DSGN' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $codeStr,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => $order->get_id(),
			'user_id' => 0,
			'meta' => $metaJson,
		]);

		// Store code reference in order item meta (as the real flow does)
		$orderItem = $items[$firstItemId];
		$orderItem->update_meta_data('_saso_eventtickets_product_code', $codeStr);
		$orderItem->update_meta_data('_saso_eventtickets_is_ticket', 'yes');
		$orderItem->save();

		$codeObj = $this->main->getCore()->retrieveCodeByCode($codeStr);

		return [
			'product_id' => $pid,
			'list_id' => $listId,
			'order' => $order,
			'order_id' => $order->get_id(),
			'item_id' => $firstItemId,
			'code' => $codeStr,
			'codeObj' => $codeObj,
		];
	}

	// ── TicketDesigner: setTemplate / getTemplate ────────────────

	private function getDesigner(string $html = ''): sasoEventtickets_TicketDesigner {
		$this->main->loadOnce('sasoEventtickets_TicketDesigner');
		return new sasoEventtickets_TicketDesigner($this->main, $html);
	}

	public function test_designer_setTemplate_and_getTemplate(): void {
		$designer = $this->getDesigner();
		$designer->setTemplate('<p>Hello {{ name }}</p>');
		$this->assertEquals('<p>Hello {{ name }}</p>', $designer->getTemplate());
	}

	public function test_designer_getTemplate_empty_returns_default(): void {
		$designer = $this->getDesigner();
		$template = $designer->getTemplate();
		// Default template contains Twig blocks
		$this->assertStringContainsString('TICKET', $template);
		$this->assertStringContainsString('PRODUCT', $template);
	}

	public function test_designer_setTemplate_trims_whitespace(): void {
		$designer = $this->getDesigner();
		$designer->setTemplate('   <p>test</p>   ');
		$this->assertEquals('<p>test</p>', $designer->getTemplate());
	}

	// ── TicketDesigner: getDefaultTemplate ─────────────────────

	public function test_designer_getDefaultTemplate_not_empty(): void {
		$designer = $this->getDesigner();
		$template = $designer->getDefaultTemplate();
		$this->assertNotEmpty($template);
		$this->assertStringContainsString('spaceless', $template);
	}

	public function test_designer_getDefaultTemplate_contains_twig_variables(): void {
		$designer = $this->getDesigner();
		$template = $designer->getDefaultTemplate();
		$this->assertStringContainsString('PRODUCT', $template);
		$this->assertStringContainsString('ORDER', $template);
		$this->assertStringContainsString('TICKET', $template);
		$this->assertStringContainsString('CODEOBJ', $template);
	}

	// ── TicketDesigner: getTemplateList ─────────────────────────

	public function test_designer_getTemplateList_returns_array(): void {
		$designer = $this->getDesigner();
		$list = $designer->getTemplateList();
		$this->assertIsArray($list);
		$this->assertGreaterThan(0, count($list));
	}

	public function test_designer_getTemplateList_has_template_keys(): void {
		$designer = $this->getDesigner();
		$list = $designer->getTemplateList();
		foreach ($list as $item) {
			$this->assertArrayHasKey('template', $item);
			$this->assertArrayHasKey('image_url', $item);
			$this->assertArrayHasKey('wcTicketDesignerTemplateTest', $item);
		}
	}

	// ── TicketDesigner: renderHTML ──────────────────────────────

	public function test_designer_renderHTML_produces_output(): void {
		$setup = $this->createFullTicketSetup([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
		]);

		$designer = $this->getDesigner();
		$designer->setTemplate('<p>{{ PRODUCT.get_name }}</p>');
		$output = $designer->renderHTML($setup['codeObj']);

		$this->assertNotEmpty($output);
		$this->assertStringContainsString('Designer Test', $output);
	}

	public function test_designer_renderHTML_default_template(): void {
		$setup = $this->createFullTicketSetup([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
		]);

		$designer = $this->getDesigner();
		$output = $designer->renderHTML($setup['codeObj']);

		$this->assertNotEmpty($output);
		// Default template includes code display
		$this->assertStringContainsString($setup['codeObj']['code_display'], $output);
	}

	public function test_designer_renderHTML_forPDFOutput_flag(): void {
		$setup = $this->createFullTicketSetup([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
		]);

		$designer = $this->getDesigner();
		$designer->setTemplate('{% if forPDFOutput %}PDF{% else %}SCREEN{% endif %}');
		$output = $designer->renderHTML($setup['codeObj'], true);
		$this->assertStringContainsString('PDF', $output);

		// Reset singleton for fresh test
		$designer2 = new sasoEventtickets_TicketDesigner($this->main, '');
		$designer2->setTemplate('{% if forPDFOutput %}PDF{% else %}SCREEN{% endif %}');
		$output2 = $designer2->renderHTML($setup['codeObj'], false);
		$this->assertStringContainsString('SCREEN', $output2);
	}

	public function test_designer_renderHTML_throws_for_no_order(): void {
		$listId = $this->main->getDB()->insert('lists', [
			'name' => 'NoOrder List ' . uniqid(),
			'aktiv' => 1,
			'meta' => '{}',
		]);

		$metaObj = $this->main->getCore()->getMetaObject();
		$metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

		$code = 'NOORD' . strtoupper(uniqid());
		$this->main->getDB()->insert('codes', [
			'list_id' => $listId,
			'code' => $code,
			'aktiv' => 1,
			'cvv' => '',
			'order_id' => 0,
			'user_id' => 0,
			'meta' => $metaJson,
		]);

		$codeObj = $this->main->getCore()->retrieveCodeByCode($code);
		$designer = $this->getDesigner();

		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/#7001/');
		$designer->renderHTML($codeObj);
	}

	public function test_designer_getVariables_after_render(): void {
		$setup = $this->createFullTicketSetup([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
		]);

		$designer = $this->getDesigner();
		$designer->setTemplate('<p>{{ PRODUCT.get_name }}</p>');
		$designer->renderHTML($setup['codeObj']);

		$vars = $designer->getVariables();
		$this->assertIsArray($vars);
		$this->assertArrayHasKey('PRODUCT', $vars);
		$this->assertArrayHasKey('ORDER', $vars);
		$this->assertArrayHasKey('TICKET', $vars);
		$this->assertArrayHasKey('CODEOBJ', $vars);
		$this->assertArrayHasKey('METAOBJ', $vars);
		$this->assertArrayHasKey('LISTOBJ', $vars);
		$this->assertArrayHasKey('OPTIONS', $vars);
		$this->assertArrayHasKey('SERVER', $vars);
		$this->assertArrayHasKey('forPDFOutput', $vars);
	}

	public function test_designer_renderHTML_ticket_variables(): void {
		$setup = $this->createFullTicketSetup([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
			'saso_eventtickets_event_location' => 'Berlin Arena',
		]);

		$designer = $this->getDesigner();
		$designer->setTemplate('{{ TICKET.location }}|{{ TICKET.date_as_string }}');
		$output = $designer->renderHTML($setup['codeObj']);

		$this->assertStringContainsString('Berlin Arena', $output);
		$this->assertStringContainsString('2026', $output);
	}

	public function test_designer_renderHTML_order_variables(): void {
		$setup = $this->createFullTicketSetup([
			'saso_eventtickets_ticket_start_date' => '2026-12-25',
		]);

		$designer = $this->getDesigner();
		$designer->setTemplate('OrderID={{ ORDER.get_id }}');
		$output = $designer->renderHTML($setup['codeObj']);

		$this->assertStringContainsString('OrderID=' . $setup['order_id'], $output);
	}

	// ── TicketBadge: getDefaultTemplate ─────────────────────────

	public function test_badge_getDefaultTemplate_not_empty(): void {
		$badge = new sasoEventtickets_TicketBadge();
		$template = $badge->getDefaultTemplate();
		$this->assertNotEmpty($template);
		$this->assertStringContainsString('{PRODUCT.name}', $template);
		$this->assertStringContainsString('{QRCODE_INLINE}', $template);
	}

	public function test_badge_getDefaultTemplate_has_ticket_code(): void {
		$badge = new sasoEventtickets_TicketBadge();
		$template = $badge->getDefaultTemplate();
		$this->assertStringContainsString('{TICKET.code_display}', $template);
	}

	// ── TicketBadge: getTemplate (empty → default) ──────────────

	public function test_badge_getTemplate_empty_returns_default(): void {
		$badge = new sasoEventtickets_TicketBadge();
		$template = $badge->getTemplate();
		$this->assertStringContainsString('{PRODUCT.name}', $template);
	}

	// ── TicketBadge: getReplacementTagsExplanation ──────────────

	public function test_badge_getReplacementTagsExplanation_returns_html(): void {
		$badge = new sasoEventtickets_TicketBadge();
		$explanation = $badge->getReplacementTagsExplanation();
		$this->assertNotEmpty($explanation);
		$this->assertStringContainsString('TICKET', $explanation);
		$this->assertStringContainsString('ORDER', $explanation);
		$this->assertStringContainsString('PRODUCT', $explanation);
	}

	public function test_badge_getReplacementTagsExplanation_contains_order_fields(): void {
		$badge = new sasoEventtickets_TicketBadge();
		$explanation = $badge->getReplacementTagsExplanation();
		$this->assertStringContainsString('{ORDER.billing.first_name}', $explanation);
		$this->assertStringContainsString('{ORDER.billing.email}', $explanation);
		$this->assertStringContainsString('{ORDER.status}', $explanation);
	}

	public function test_badge_getReplacementTagsExplanation_contains_product_fields(): void {
		$badge = new sasoEventtickets_TicketBadge();
		$explanation = $badge->getReplacementTagsExplanation();
		$this->assertStringContainsString('{PRODUCT.name}', $explanation);
		$this->assertStringContainsString('{PRODUCT.price}', $explanation);
		$this->assertStringContainsString('{PRODUCT.sku}', $explanation);
	}

	public function test_badge_getReplacementTagsExplanation_contains_meta_tags(): void {
		$badge = new sasoEventtickets_TicketBadge();
		$explanation = $badge->getReplacementTagsExplanation();
		$this->assertStringContainsString('{TICKET.meta.', $explanation);
		$this->assertStringContainsString('{QRCODE_INLINE}', $explanation);
	}

	public function test_badge_getReplacementTagsExplanation_contains_loop_syntax(): void {
		$badge = new sasoEventtickets_TicketBadge();
		$explanation = $badge->getReplacementTagsExplanation();
		$this->assertStringContainsString('LOOP', $explanation);
		$this->assertStringContainsString('LOOPEND', $explanation);
	}
}
