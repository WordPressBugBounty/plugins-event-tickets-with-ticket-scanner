<?php
/**
 * Tests for Context-Wizards: dismissed suggestions (#232).
 */

class ContextWizardTest extends WP_UnitTestCase {

	private sasoEventtickets_AdminSettings $admin;
	private int $userId;
	private string $metaKey = 'saso_eventtickets_dismissed_suggestions';

	public function set_up(): void {
		parent::set_up();
		$this->userId = self::factory()->user->create(['role' => 'administrator']);
		wp_set_current_user($this->userId);
		$this->admin = sasoEventtickets::Instance()->getAdmin();

		// Clean slate
		delete_user_meta($this->userId, $this->metaKey);
	}

	public function tear_down(): void {
		delete_user_meta($this->userId, $this->metaKey);
		parent::tear_down();
	}

	// ── getDismissedSuggestions ─────────────────────────────

	public function test_getDismissedSuggestions_returns_empty_array_for_new_user(): void {
		$result = $this->admin->getDismissedSuggestions();
		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function test_getDismissedSuggestions_returns_saved_array(): void {
		update_user_meta($this->userId, $this->metaKey, ['email_ics_attach', 'scanner_vibrate']);

		$result = $this->admin->getDismissedSuggestions();
		$this->assertCount(2, $result);
		$this->assertContains('email_ics_attach', $result);
		$this->assertContains('scanner_vibrate', $result);
	}

	// ── dismissSuggestion ──────────────────────────────────

	public function test_dismissSuggestion_stores_suggestion_id(): void {
		$result = $this->admin->dismissSuggestion(['suggestion_id' => 'email_ics_attach']);

		$this->assertArrayHasKey('dismissed', $result);
		$this->assertContains('email_ics_attach', $result['dismissed']);

		// Verify in database
		$stored = get_user_meta($this->userId, $this->metaKey, true);
		$this->assertContains('email_ics_attach', $stored);
	}

	public function test_dismissSuggestion_does_not_duplicate(): void {
		$this->admin->dismissSuggestion(['suggestion_id' => 'scanner_vibrate']);
		$result = $this->admin->dismissSuggestion(['suggestion_id' => 'scanner_vibrate']);

		$this->assertCount(1, $result['dismissed']);
	}

	public function test_dismissSuggestion_requires_suggestion_id(): void {
		$this->expectException(\Exception::class);
		$this->admin->dismissSuggestion([]);
	}

	public function test_dismissSuggestion_requires_logged_in_user(): void {
		wp_set_current_user(0);
		$this->expectException(\Exception::class);
		$this->admin->dismissSuggestion(['suggestion_id' => 'test']);
	}

	// ── getOptions includes dismissed_suggestions ──────────

	public function test_getOptions_includes_dismissed_suggestions_key(): void {
		$result = $this->admin->getOptions();
		$this->assertArrayHasKey('dismissed_suggestions', $result);
	}

	public function test_getOptions_dismissed_suggestions_reflects_user_meta(): void {
		update_user_meta($this->userId, $this->metaKey, ['security_ip_block']);

		$result = $this->admin->getOptions();
		$this->assertContains('security_ip_block', $result['dismissed_suggestions']);
	}
}
