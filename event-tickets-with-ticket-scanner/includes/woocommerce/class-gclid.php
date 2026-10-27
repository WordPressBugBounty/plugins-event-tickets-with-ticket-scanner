<?php
/**
 * Google Ads Click Tracking (gclid)
 *
 * Measures gads -> landing page -> sale: a Google Ads click carries ?gclid=…
 * on the landing URL. We persist it in the WooCommerce session (last click
 * wins) and write it onto the order at checkout as meta:
 *   _gclid            the raw (sanitized) gclid of the last click
 *   _gclid_timestamp  unix ts when that click was captured
 * collectors/gads_conversion_uploader.py (control) reads these metas and
 * uploads click conversions to Google Ads.
 *
 * @package    Event_Tickets_With_Ticket_Scanner
 * @subpackage WooCommerce
 * @since      3.2.9
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('sasoEventtickets_WC_Gclid')) {
	class sasoEventtickets_WC_Gclid extends sasoEventtickets_WC_Base {

		const SESSION_KEY = 'saso_et_gclid';

		/**
		 * Capture ?gclid= from the landing request into the WC session.
		 * Last click wins: a fresh gclid overwrites a stale one; requests
		 * without gclid leave the session untouched.
		 *
		 * @param array      $get     usually $_GET (injected for testability).
		 * @param object|null $session WC_Session-like (set/get); null = live WC().
		 * @return bool true when a gclid was stored.
		 */
		public function captureFromRequest(array $get, $session = null): bool {
			$raw = isset($get['gclid']) ? (string) $get['gclid'] : '';
			$gclid = $this->sanitizeGclid($raw);
			if ($gclid === '') {
				return false;
			}
			$session = $session ?? $this->liveSession();
			if ($session === null) {
				return false;
			}
			$session->set(self::SESSION_KEY, [
				'gclid' => $gclid,
				'ts'    => time(),
			]);
			return true;
		}

		/**
		 * Write the session gclid onto the order at checkout.
		 *
		 * @param \WC_Order  $order   order being created.
		 * @param object|null $session WC_Session-like; null = live WC().
		 * @return bool true when meta was written.
		 */
		public function storeOnOrder($order, $session = null): bool {
			$session = $session ?? $this->liveSession();
			if ($session === null || !is_object($order) || !method_exists($order, 'update_meta_data')) {
				return false;
			}
			$data = $session->get(self::SESSION_KEY);
			if (!is_array($data) || empty($data['gclid'])) {
				return false;
			}
			$order->update_meta_data('_gclid', (string) $data['gclid']);
			$order->update_meta_data('_gclid_timestamp', (string) intval($data['ts'] ?? time()));
			return true;
		}

		/**
		 * Keep gclid characters only (Google uses base64url-ish ids). Length
		 * cap guards against junk traffic abuse of the session entry.
		 */
		private function sanitizeGclid(string $raw): string {
			$clean = preg_replace('/[^A-Za-z0-9\-_.]/', '', trim($raw));
			return substr($clean, 0, 255);
		}

		/**
		 * Live WC session or null when unavailable (CLI, Woo not active,
		 * session not initialized yet).
		 */
		private function liveSession() {
			if (!function_exists('WC') || !WC()->session) {
				return null;
			}
			return WC()->session;
		}
	}
}
