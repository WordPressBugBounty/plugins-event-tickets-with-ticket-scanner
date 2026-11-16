<?php
if (!defined('ABSPATH')) exit; // direct access protection
include_once(plugin_dir_path(__FILE__)."init_file.php");
/**
 * DEPRECATED API SHIM (free 3.4.0): the shop template engine moved to
 * the premium plugin (includes/shop-templates/, premium 1.10.0). This
 * class keeps the legacy name and the full legacy API surface so old
 * premium versions and the free save-guard keep working in every
 * plugin combination:
 *
 *  - premium >= 1.10.0 boots its engine class
 *    (sasoEventtickets_ShopTemplatesPremium) — every pass-through below
 *    delegates to it, saved templates keep rendering unchanged.
 *  - premium < 1.10.0 (or none): shop templates are simply inactive —
 *    render* return null (built-in rendering / built-in plain-text
 *    mails), validators accept silently. Never a fatal; the shop
 *    itself (submit, kasse, payments) never touches this class.
 *
 * Compat matrix: vault plan 2026-11-11_baustein-a-shop-template-engine-umzug.
 * Contracts, variables and validation flow: vault specs/imported-from-plugin/plan-shop-templates.md
 */
class sasoEventtickets_ShopTemplates {
	/** Storage keys per surface (options registry, def ''). */
	public const OPTION_KEYS = [
		'catalog'      => 'shopTemplateCatalog',
		'event_detail' => 'shopTemplateEventDetail',
		// order emails — rendered as HTML mail when set, empty = the
		// built-in plain-text mail.
		'mail_order_customer' => 'shopTemplateMailOrderCustomer',
		'mail_order_admin'    => 'shopTemplateMailOrderAdmin',
		'mail_tickets'        => 'shopTemplateMailTickets',
	];

	/** Mail template surfaces (renderMail); page surfaces keep buildVars. */
	public const MAIL_SURFACES = ['mail_order_customer', 'mail_order_admin', 'mail_tickets'];

	private $MAIN;

	public static function Instance($main = null) {
		static $inst = null;
		if ($inst === null) {
			$inst = new self($main);
		}
		return $inst;
	}

	public function __construct($main = null) {
		if ($main == null) {
			global $sasoEventtickets;
			$this->MAIN = $sasoEventtickets;
		} else {
			$this->MAIN = $main;
		}
	}

	/** Shim marker: premium detects the shim via method_exists(). */
	public function isShim(): bool { return true; }

	/**
	 * The premium engine when it is loaded (premium >= 1.10.0 boots it
	 * from its index.php), null otherwise. class_exists first — the
	 * class literal below is only reached when the class really exists
	 * (boot-order safe, no file loading from the free side).
	 */
	private function engine() {
		if (!class_exists('sasoEventtickets_ShopTemplatesPremium')) return null;
		return sasoEventtickets_ShopTemplatesPremium::Instance($this->MAIN);
	}

	/**
	 * Save-time guard (deprecated pass-through): delegates to the
	 * premium engine. Without an engine the option is not registered
	 * anymore — legacy option exports must still import without a
	 * fatal, so the no-engine case silently accepts.
	 */
	public function validateTemplate(string $source): void {
		$engine = $this->engine();
		if ($engine !== null) $engine->validateTemplate($source);
	}

	/** Mail variant of the save-time guard (deprecated pass-through). */
	public function validateMailTemplate(string $source): void {
		$engine = $this->engine();
		if ($engine !== null) $engine->validateMailTemplate($source);
	}

	/**
	 * Catalog shortcode override (deprecated pass-through). null = no
	 * engine or no template set -> the caller keeps the built-in
	 * rendering. A render error degrades to '' inside the engine (a
	 * public face never fatals on a broken template).
	 */
	public function renderCatalog(array $entries, string $divId = ''): ?string {
		$engine = $this->engine();
		if ($engine === null) return null;
		return $engine->renderCatalog($entries, $divId);
	}

	/** Event detail shortcode override (deprecated pass-through). */
	public function renderEventDetail(array $entry, string $divId = ''): ?string {
		$engine = $this->engine();
		if ($engine === null) return null;
		return $engine->renderEventDetail($entry, $divId);
	}

	/**
	 * Order-mail override (deprecated pass-through). null = no engine,
	 * no template set or render error — the caller keeps the built-in
	 * plain-text mail. A broken template must NEVER swallow an order
	 * confirmation (mail contract, see renderMail in the engine).
	 */
	public function renderMail(string $surface, array $vars): ?string {
		$engine = $this->engine();
		if ($engine === null) return null;
		return $engine->renderMail($surface, $vars);
	}

	/**
	 * Formatted variants of the raw event meta (deprecated pass-through;
	 * pure date_i18n formatting with the plugin display formats, kept
	 * locally so the legacy API stays functional without premium).
	 * '' when missing/unparsable.
	 */
	public function shopFormattedEventMeta(array $eventMeta): array {
		$engine = $this->engine();
		if ($engine !== null) return $engine->shopFormattedEventMeta($eventMeta);
		$fmtDate = (string)$this->MAIN->getOptions()->getOptionDateFormat();
		$fmtTime = (string)$this->MAIN->getOptions()->getOptionTimeFormat();
		$fmtDateTime = (string)$this->MAIN->getOptions()->getOptionDateTimeFormat();
		$rawDate = trim((string)($eventMeta['event_start_date'] ?? ""));
		$rawTime = trim((string)($eventMeta['event_start_time'] ?? ""));
		$out = [
			"event_start_date_formatted" => "",
			"event_start_time_formatted" => "",
			"event_start_datetime_formatted" => "",
		];
		$tsDate = ($rawDate != "" && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) ? strtotime($rawDate) : false;
		if ($tsDate !== false) {
			$out["event_start_date_formatted"] = date_i18n($fmtDate, $tsDate, true);
			// public shop faces (free 3.4.0 / premium 2.0.0 design polish):
			// weekday-first short-month date a visitor reads without
			// decoding — "So, 1. Nov 2026" in a German locale,
			// "Sun, Nov 1, 2026" everywhere else. Raw values stay
			// available alongside (documented template contract).
			$out["event_start_date_human"] = date_i18n(self::shopHumanDatePattern(), $tsDate, true);
		}
		$tsTime = ($rawTime != "" && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $rawTime)) ? strtotime('2000-01-01 '.$rawTime) : false;
		if ($tsTime !== false) {
			$out["event_start_time_formatted"] = date_i18n($fmtTime, $tsTime, true);
		}
		if ($tsDate !== false && $tsTime !== false) {
			$out["event_start_datetime_formatted"] = date_i18n($fmtDateTime, strtotime($rawDate.' '.$rawTime), true);
		}
		return $out;
	}

	/**
	 * Pattern for the human date on the public shop faces: German locales
	 * read "So, 1. Nov 2026", everyone else "Sun, Nov 1, 2026" (the
	 * weekday/month NAMES still localize through date_i18n — only the
	 * ordering differs). Kept identical in the premium engine and this
	 * shim fallback.
	 */
	public static function shopHumanDatePattern(): string {
		return (strncmp((string) get_locale(), 'de', 2) === 0) ? 'D, j. M Y' : 'D, M j, Y';
	}
}
