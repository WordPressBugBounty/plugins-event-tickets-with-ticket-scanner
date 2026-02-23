<?php
/**
 * Tests for Core methods: alignArrays, getUserIdsForCustomerName.
 */

class CoreAlignAndSearchTest extends WP_UnitTestCase {

    private $main;

    public function set_up(): void {
        parent::set_up();
        $this->main = sasoEventtickets::Instance();
    }

    // ── alignArrays ──────────────────────────────────────────────

    public function test_alignArrays_adds_missing_keys(): void {
        $template = ['a' => 1, 'b' => 2, 'c' => 3];
        $data = ['a' => 10];

        $this->main->getCore()->alignArrays($template, $data);

        $this->assertArrayHasKey('a', $data);
        $this->assertArrayHasKey('b', $data);
        $this->assertArrayHasKey('c', $data);
        $this->assertEquals(10, $data['a']);
        $this->assertNull($data['b']);
        $this->assertNull($data['c']);
    }

    public function test_alignArrays_removes_extra_keys(): void {
        $template = ['a' => 1];
        $data = ['a' => 10, 'extra' => 'removed'];

        $this->main->getCore()->alignArrays($template, $data);

        $this->assertArrayHasKey('a', $data);
        $this->assertArrayNotHasKey('extra', $data);
    }

    public function test_alignArrays_preserves_existing_values(): void {
        $template = ['a' => 1, 'b' => 2];
        $data = ['a' => 'original', 'b' => 'kept'];

        $this->main->getCore()->alignArrays($template, $data);

        $this->assertEquals('original', $data['a']);
        $this->assertEquals('kept', $data['b']);
    }

    public function test_alignArrays_recursive_for_nested(): void {
        $template = ['nested' => ['x' => 1, 'y' => 2]];
        $data = ['nested' => ['x' => 10]];

        $this->main->getCore()->alignArrays($template, $data);

        $this->assertArrayHasKey('y', $data['nested']);
        $this->assertNull($data['nested']['y']);
        $this->assertEquals(10, $data['nested']['x']);
    }

    public function test_alignArrays_adds_empty_array_for_array_values(): void {
        $template = ['items' => [1, 2, 3]];
        $data = [];

        $this->main->getCore()->alignArrays($template, $data);

        $this->assertArrayHasKey('items', $data);
        $this->assertIsArray($data['items']);
    }

    public function test_alignArrays_empty_arrays(): void {
        $template = [];
        $data = ['a' => 1];

        $this->main->getCore()->alignArrays($template, $data);

        $this->assertEmpty($data);
    }

    // ── getUserIdsForCustomerName ─────────────────────────────────

    public function test_getUserIdsForCustomerName_returns_expected_structure(): void {
        $result = $this->main->getCore()->getUserIdsForCustomerName('nonexistent_xyz_' . uniqid());
        $this->assertIsArray($result);
        $this->assertArrayHasKey('user_ids', $result);
        $this->assertArrayHasKey('order_ids', $result);
    }

    public function test_getUserIdsForCustomerName_empty_query(): void {
        $result = $this->main->getCore()->getUserIdsForCustomerName('');
        $this->assertEmpty($result['user_ids']);
        $this->assertEmpty($result['order_ids']);
    }

    public function test_getUserIdsForCustomerName_finds_by_first_name(): void {
        $uniqueName = 'SearchTest' . uniqid();
        $userId = self::factory()->user->create([
            'first_name' => $uniqueName,
            'last_name' => 'Doe',
            'user_login' => 'searchuser_' . uniqid(),
        ]);

        $result = $this->main->getCore()->getUserIdsForCustomerName($uniqueName);
        $this->assertContains($userId, $result['user_ids']);
    }

    public function test_getUserIdsForCustomerName_finds_by_last_name(): void {
        $uniqueLast = 'LastSearch' . uniqid();
        $userId = self::factory()->user->create([
            'first_name' => 'John',
            'last_name' => $uniqueLast,
            'user_login' => 'searchlast_' . uniqid(),
        ]);

        $result = $this->main->getCore()->getUserIdsForCustomerName($uniqueLast);
        $this->assertContains($userId, $result['user_ids']);
    }

    public function test_getUserIdsForCustomerName_no_match(): void {
        $result = $this->main->getCore()->getUserIdsForCustomerName('ZZZNobodyHasThisName' . uniqid());
        $this->assertEmpty($result['user_ids']);
    }
}
