<?php
/**
 * Tests for Core methods: replaceURLParameters, checkCodeExpired, parser_search_loop,
 * getUserIdsForCustomerName, setMetaObj, getTicketURLBase, getTicketURLPath.
 */

class CoreReplaceAndExpireTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    private function createCodeInList(): array {
        $listId = $this->main->getDB()->insert('lists', [
            'name' => 'Replace Test ' . uniqid(),
            'aktiv' => 1,
            'meta' => '{}',
        ]);

        $metaObj = $this->main->getCore()->getMetaObject();
        $metaJson = $this->main->getCore()->json_encode_with_error_handling($metaObj);

        $code = 'RPL' . strtoupper(uniqid());
        $this->main->getDB()->insert('codes', [
            'code' => $code,
            'code_display' => $code,
            'cvv' => '',
            'meta' => $metaJson,
            'aktiv' => 1,
            'redeemed' => 0,
            'list_id' => $listId,
            'order_id' => 0,
        ]);

        return ['code' => $code, 'list_id' => $listId];
    }

    // ── replaceURLParameters ────────────────────────────────────

    public function test_replaceURLParameters_replaces_code(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        $url = $this->main->getCore()->replaceURLParameters(
            'https://example.com/?ticket={CODE}',
            $codeObj
        );

        $this->assertStringContainsString($data['code'], $url);
        $this->assertStringNotContainsString('{CODE}', $url);
    }

    public function test_replaceURLParameters_replaces_codedisplay(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        $url = $this->main->getCore()->replaceURLParameters(
            'https://example.com/?display={CODEDISPLAY}',
            $codeObj
        );

        $this->assertStringNotContainsString('{CODEDISPLAY}', $url);
    }

    public function test_replaceURLParameters_replaces_ip(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        $url = $this->main->getCore()->replaceURLParameters(
            'https://example.com/?ip={IP}',
            $codeObj
        );

        $this->assertStringNotContainsString('{IP}', $url);
    }

    public function test_replaceURLParameters_replaces_list(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        $url = $this->main->getCore()->replaceURLParameters(
            'https://example.com/?list={LIST}',
            $codeObj
        );

        $this->assertStringNotContainsString('{LIST}', $url);
        // List name should be URL-encoded
        $this->assertStringContainsString('Replace', urldecode($url));
    }

    public function test_replaceURLParameters_empty_url_stays_empty(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        $url = $this->main->getCore()->replaceURLParameters('', $codeObj);
        $this->assertEquals('', $url);
    }

    public function test_replaceURLParameters_no_placeholders_unchanged(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        $url = $this->main->getCore()->replaceURLParameters(
            'https://example.com/static',
            $codeObj
        );

        $this->assertEquals('https://example.com/static', $url);
    }

    // ── checkCodeExpired ────────────────────────────────────────

    public function test_checkCodeExpired_returns_false_in_free_version(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        // Free version has no expiration logic — always false
        $result = $this->main->getCore()->checkCodeExpired($codeObj);
        $this->assertFalse($result);
    }

    // ── setMetaObj ──────────────────────────────────────────────

    public function test_setMetaObj_sets_metaObj_on_codeObj(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);

        // metaObj should not yet be set
        $this->assertArrayNotHasKey('metaObj', $codeObj);

        $result = $this->main->getCore()->setMetaObj($codeObj);
        $this->assertArrayHasKey('metaObj', $result);
        $this->assertIsArray($result['metaObj']);
    }

    public function test_setMetaObj_does_not_overwrite_existing(): void {
        $data = $this->createCodeInList();
        $codeObj = $this->main->getCore()->retrieveCodeByCode($data['code']);
        $codeObj['metaObj'] = ['test' => 'marker'];

        $result = $this->main->getCore()->setMetaObj($codeObj);
        $this->assertEquals('marker', $result['metaObj']['test']);
    }

    // ── getTicketURLBase / getTicketURLPath ──────────────────────

    public function test_getTicketURLBase_returns_url(): void {
        $url = $this->main->getCore()->getTicketURLBase();
        $this->assertIsString($url);
        $this->assertStringContainsString('ticket', $url);
        $this->assertStringEndsWith('/', $url);
    }

    public function test_getTicketURLBase_default_path(): void {
        $url = $this->main->getCore()->getTicketURLBase(true);
        $this->assertIsString($url);
        $this->assertStringContainsString('ticket', $url);
    }

    public function test_getTicketURLPath_returns_path(): void {
        $path = $this->main->getCore()->getTicketURLPath();
        $this->assertIsString($path);
        $this->assertStringContainsString('ticket', $path);
    }

    // ── parser_search_loop ──────────────────────────────────────

    public function test_parser_search_loop_empty_returns_false(): void {
        $result = $this->main->getCore()->parser_search_loop('');
        $this->assertFalse($result);
    }

    public function test_parser_search_loop_no_loop_returns_false(): void {
        $result = $this->main->getCore()->parser_search_loop('Hello World');
        $this->assertFalse($result);
    }

    public function test_parser_search_loop_detects_loop(): void {
        $text = '{{LOOP ORDER.items AS item}} <div>{item.name}</div> {{LOOPEND}}';
        $result = $this->main->getCore()->parser_search_loop($text);
        $this->assertIsArray($result);
    }

    // ── getUserIdsForCustomerName ───────────────────────────────

    public function test_getUserIdsForCustomerName_empty_returns_empty(): void {
        $result = $this->main->getCore()->getUserIdsForCustomerName('');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_ids', $result);
        $this->assertArrayHasKey('order_ids', $result);
        $this->assertEmpty($result['user_ids']);
        $this->assertEmpty($result['order_ids']);
    }

    public function test_getUserIdsForCustomerName_finds_user(): void {
        $userId = self::factory()->user->create([
            'first_name' => 'TestCustomer' . uniqid(),
            'last_name' => 'SearchTest',
        ]);

        $firstName = get_user_meta($userId, 'first_name', true);

        $result = $this->main->getCore()->getUserIdsForCustomerName($firstName);
        $this->assertContains($userId, $result['user_ids']);
    }

    public function test_getUserIdsForCustomerName_nonexistent_returns_empty(): void {
        $result = $this->main->getCore()->getUserIdsForCustomerName('XxNonExistentNamexX_' . uniqid());
        $this->assertEmpty($result['user_ids']);
        $this->assertEmpty($result['order_ids']);
    }
}
