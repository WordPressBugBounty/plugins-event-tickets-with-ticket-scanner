<?php
/**
 * Admin notice: seating plan bound to a product but never published (#015050).
 *
 * The frontend renders NOTHING for an unpublished plan (class-seating-frontend
 * returns null when published_at is empty). The merchant places chairs in the
 * designer (draft), sees the plan in the editor — and an empty product page
 * with no hint why. This notice names the cause and the fix on the product
 * edit screen and the plugin seating list.
 */
if (!class_exists('sasoEventtickets_UnpublishedPlanNotice')) {
class sasoEventtickets_UnpublishedPlanNotice {

	/**
	 * Render the notice HTML for a product whose bound seating plan is not
	 * published. Returns/echoes '' when everything is fine (published plan,
	 * no plan bound, or plan row missing entirely — the latter is covered by
	 * the separate broken-binding case and stays silent here).
	 */
	public static function renderForPost($postId) {
		$postId = intval($postId);
		if ($postId < 1) return '';

		$MAIN = sasoEventtickets::Instance();
		if (!$MAIN->getSeating()) return '';

		$planId = intval(get_post_meta($postId, $MAIN->getSeating()->getMetaProductSeatingplan(), true));
		if ($planId < 1) return '';

		$plan = $MAIN->getSeating()->getPlanManager()->getById($planId);
		if (empty($plan)) return '';
		// Null-Date-Schutz: MariaDB ohne strict mode speichert "leer" als
		// '0000-00-00 00:00:00' — empty() hält das fälschlich für published.
		if (!empty($plan['published_at']) && $plan['published_at'] !== '0000-00-00 00:00:00') return '';
		if (empty($plan['published_at'])) return '';
		// Inaktive Pläne haben ihr eigenes Signal (Dropdown zeigt inactive);
		// hier geht es nur um draft-ohne-publish.
		if (empty($plan['aktiv'])) return '';
		// "Nie veröffentlicht, aber Stühle vorhanden" erkennen: Seats leben in
		// der eigenen Tabelle (nicht in meta_draft) — nur dann ist die leere
		// Produktseite für den Händler überraschend.
		global $wpdb;
		$seatCount = intval($wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM " . $MAIN->getDB()->getTabelle('seats') . " WHERE seatingplan_id = %d",
			$planId
		)));
		if ($seatCount < 1) return '';

		$editUrl = admin_url('admin.php?page=event-tickets-with-ticket-scanner&tab=seating&action=edit&id=' . $planId);

		echo '<div class="notice notice-warning" style="border-left-color:#f0b849;padding:12px 16px;">';
		echo '<p style="margin:0 0 6px 0;font-weight:600;">' .
			esc_html__('Event Tickets: This product uses a seating plan that is not published yet.', 'event-tickets-with-ticket-scanner') .
			'</p>';
		echo '<p style="margin:0 0 6px 0;">' .
			sprintf(
				/* translators: 1: plan name, 2: plan id */
				esc_html__('The plan "%1$s" (ID %2$d) has seats in the designer, but only the PUBLISHED version is shown in your shop — the product page will not show any seat selection until you publish it.', 'event-tickets-with-ticket-scanner'),
				esc_html($plan['name']),
				$planId
			) . '</p>';
		echo '<p style="margin:0;"><a class="button button-primary" href="' . esc_url($editUrl) . '">' .
			esc_html__('Open seating plan and publish', 'event-tickets-with-ticket-scanner') . '</a></p>';
		echo '</div>';
	}

	/**
	 * admin_notices hook: only on the product edit screen.
	 */
	public static function hookAdminNotices() {
		global $post, $pagenow;
		if (!is_admin()) return;
		if ($pagenow !== 'post.php' && $pagenow !== 'post-new.php') return;
		if (empty($post) || $post->post_type !== 'product') return;
		self::renderForPost($post->ID);
	}
}
}
