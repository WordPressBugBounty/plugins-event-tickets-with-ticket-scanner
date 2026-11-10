<?php
if (!defined('ABSPATH')) exit; // direct access protection
include_once(plugin_dir_path(__FILE__)."init_file.php");
/**
 * Twig template overrides for the public shop faces (3.3.0 Slice 3):
 * the catalog shortcode and the event detail shortcode can be taken
 * over by admin-authored Twig templates (options shopTemplateCatalog /
 * shopTemplateEventDetail). An empty option = the built-in code
 * renderer keeps rendering (byte-identical default).
 *
 * Engine: the bundled Twig 3.22 shared with the ticket designer — only
 * its bootstrap is reused (polyfill + PHP-8.1 guard + autoload, same
 * library version, no new dependency). The render environment is NOT
 * the ticket designer's plain \Twig\Environment: shop templates are
 * customer-editable source rendering a public face, so every template
 * runs in a REAL sandbox (Twig\Extension\SandboxExtension +
 * Twig\Sandbox\SecurityPolicy) with a fixed whitelist of tags, filters
 * and functions. The payload is plain arrays — object methods and
 * properties are locked entirely. Raw PHP is impossible by Twig design
 * and sources containing <?php / <?= tags are rejected on save.
 *
 * Every buy URL flows through shopEventBuyUrl() via the shop_buy_url()
 * template function — the saso_eventtickets_shop_event_buy_url filter
 * output cannot be bypassed by template code (URL building by hand is
 * not the sanctioned path and stays undocumented).
 *
 * Contracts, variables and validation flow: docs/plan-shop-templates.md
 */
class sasoEventtickets_ShopTemplates {
	/** Storage keys per surface (options registry, def ''). */
	public const OPTION_KEYS = [
		'catalog'      => 'shopTemplateCatalog',
		'event_detail' => 'shopTemplateEventDetail',
	];

	/** Tags a shop template may use — no include/import/extends/macro/block. */
	private const ALLOWED_TAGS = ['if', 'for', 'set', 'apply', 'do', 'verbatim'];

	/** Filters a shop template may use (Twig core safe set + plugin extras). */
	private const ALLOWED_FILTERS = [
		// output shaping
		'escape', 'e', 'raw', 'striptags', 'nl2br', 'spaceless', 'format',
		// strings
		'upper', 'lower', 'title', 'capitalize', 'trim', 'replace',
		'number_format', 'url_encode', 'json_encode',
		// arrays / sequences
		'length', 'first', 'last', 'slice', 'join', 'split', 'keys',
		'values', 'column', 'merge', 'batch', 'reverse', 'sort', 'map',
		'filter', 'reduce', 'default',
		// numbers
		'abs', 'round',
		// dates + plugin extras (same helpers the ticket designer exposes)
		'date', 'date_modify', 'format_datetime', 'stripslashes',
	];

	/** Functions a shop template may call — only the shop helpers + the harmless Twig core trio. */
	private const ALLOWED_FUNCTIONS = ['shop_buy_url', 'shop_price', 'date', 'max', 'min', 'range'];

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

	/**
	 * Compile + sandbox check of a template BEFORE it is saved. Empty
	 * source is always valid (= back to the built-in rendering).
	 * Throws an Exception with a readable message on invalid Twig.
	 */
	public function validateTemplate(string $source): void {
		$source = trim($source);
		if ($source === "") return; // empty = default rendering, always valid
		if (strpos($source, '<?') !== false) {
			throw new Exception("Shop template: PHP tags (<?php / <?=) are not allowed in templates");
		}
		try {
			$twig = $this->createTwig($source);
			$twig->parse($twig->tokenize(new \Twig\Source($source, 'shop_template_validation')));
		} catch (\Twig\Error\SyntaxError $e) {
			throw new Exception("Shop template: invalid Twig syntax — ".$e->getMessage(), 0, $e);
		}
		// Trial render with a representative payload — this is where the
		// sandbox rejects blocked tags/filters/functions (SecurityError is
		// a render-time, not compile-time, check) and where templates that
		// would explode on real data fail before anything is stored.
		try {
			$sample = self::sampleEntry();
			$this->renderSource($source, $this->buildVars([$sample], true, $sample));
		} catch (\Twig\Sandbox\SecurityError $e) {
			throw new Exception("Shop template: blocked by the Twig sandbox — ".$e->getMessage(), 0, $e);
		} catch (\Throwable $e) {
			throw new Exception("Shop template: template failed on the test render — ".$e->getMessage(), 0, $e);
		}
	}

	/**
	 * Catalog shortcode override. null = no template set → the caller
	 * keeps the built-in rendering. A render error degrades to ''
	 * (a public face never fatals on a broken template).
	 */
	public function renderCatalog(array $entries): ?string {
		$source = trim((string)$this->MAIN->getOptions()->getOptionValue(self::OPTION_KEYS['catalog']));
		if ($source === "") return null;
		try {
			return $this->renderSource($source, $this->buildVars($entries, false, null));
		} catch (\Throwable $e) {
			return '';
		}
	}

	/**
	 * Event detail shortcode override. Same contract as renderCatalog.
	 */
	public function renderEventDetail(array $entry): ?string {
		$source = trim((string)$this->MAIN->getOptions()->getOptionValue(self::OPTION_KEYS['event_detail']));
		if ($source === "") return null;
		try {
			return $this->renderSource($source, $this->buildVars([$entry], true, $entry));
		} catch (\Throwable $e) {
			return '';
		}
	}

	/**
	 * Formatted variants of the raw event meta (date_i18n with the
	 * plugin's display formats — the same pattern the ticket designer's
	 * format_datetime filter uses). '' when missing/unparsable.
	 */
	public function shopFormattedEventMeta(array $eventMeta): array {
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

	private function buildVars(array $entries, bool $withBuyButtons, $singleEntry): array {
		$entries = array_values(array_map([$this, 'decorateEntry'], $entries));
		$vars = [
			'entries' => $entries,
			'with_buy_buttons' => $withBuyButtons,
		];
		if (is_array($singleEntry)) {
			$entry = $entries[0] ?? null;
			$event = (is_array($entry) && isset($entry['event']) && is_array($entry['event'])) ? $entry['event'] : [];
			$vars['entry'] = $entry;
			$vars['event_date'] = (string)($event['event_start_date_formatted'] ?? "");
			$vars['event_time'] = (string)($event['event_start_time_formatted'] ?? "");
			$vars['event_datetime'] = (string)($event['event_start_datetime_formatted'] ?? "");
		}
		return $vars;
	}

	/** Adds the *_formatted variants into the entry's event map. */
	private function decorateEntry(array $entry): array {
		if (!isset($entry['event']) || !is_array($entry['event'])) return $entry;
		$entry['event'] = array_merge($entry['event'], $this->shopFormattedEventMeta($entry['event']));
		return $entry;
	}

	private function renderSource(string $source, array $vars): string {
		$twig = $this->createTwig($source);
		return $twig->render('index', $vars);
	}

	/**
	 * The sandboxed environment for one render. Bootstrap comes from the
	 * ticket designer (polyfill + PHP-8.1 guard + Twig autoload), the
	 * Environment itself is always sandboxed with the fixed whitelist.
	 */
	private function createTwig(string $source): \Twig\Environment {
		$this->ensureEngine();
		$loader = new \Twig\Loader\ArrayLoader(['index' => $source]);
		$twig = new \Twig\Environment($loader);
		// methods/properties stay EMPTY: the payload is plain arrays, so
		// anything outside the whitelist is a sandbox violation by definition
		$twig->addExtension(new \Twig\Extension\SandboxExtension(
			new \Twig\Sandbox\SecurityPolicy(self::ALLOWED_TAGS, self::ALLOWED_FILTERS, [], [], self::ALLOWED_FUNCTIONS),
			true
		));
		$this->registerShopHelpers($twig);
		return $twig;
	}

	/**
	 * The plugin's own helpers a shop template may call. Everything else
	 * that Twig core ships stays available as-is (the policy decides what
	 * of it may run); these two filters/functions do not exist in core
	 * and must be registered — same behavior as the ticket designer's
	 * environment (format_datetime: date_i18n, plugin display formats).
	 */
	private function registerShopHelpers(\Twig\Environment $twig): void {
		$twig->addFilter(new \Twig\TwigFilter('format_datetime', function ($date, $pattern = "") {
			if (trim((string) $pattern) === "") { $pattern = $this->MAIN->getOptions()->getOptionDateTimeFormat(); }
			if (is_int($date)) {
				return date_i18n($pattern, $date, true);
			}
			return date_i18n($pattern, strtotime((string) $date), true);
		}));
		$twig->addFilter(new \Twig\TwigFilter('stripslashes', function ($text) {
			return stripslashes((string) $text);
		}));
		$twig->addFunction(new \Twig\TwigFunction('shop_buy_url', function ($entry, $product) {
			$listId = is_array($entry) ? intval($entry['id'] ?? 0) : 0;
			$productId = is_array($product) ? intval($product['id'] ?? 0) : 0;
			return $this->MAIN->shopEventBuyUrl($listId, $productId);
		}));
		$twig->addFunction(new \Twig\TwigFunction('shop_price', function ($cents) {
			return sasoEventtickets_AdminSettings::ticketCentsToPrice(intval($cents));
		}));
	}

	/** Shared engine bootstrap (polyfill + guard + autoload) via the ticket designer handler. */
	private function ensureEngine(): void {
		if (!class_exists('\Twig\Environment')) {
			$this->MAIN->getTicketDesignerHandler();
		}
		if (!class_exists('\Twig\Environment')) {
			throw new Exception("Shop template: the Twig engine could not be loaded");
		}
	}

	/** Representative payload for the save-time trial render (documented variable shape). */
	private static function sampleEntry(): array {
		return [
			'id' => 1,
			'name' => 'Sample Event',
			'event' => [
				'event_start_date' => '2030-03-07',
				'event_start_time' => '19:30',
				'event_location' => 'Sample Location',
			],
			'groups' => [
				[
					'category_id' => 1,
					'category_name' => 'Sample Category',
					'products' => [
						[
							'id' => 1,
							'name' => 'Sample Product',
							'price_cents' => 990,
							'currency' => 'EUR',
							'category_id' => 1,
							'display_desc' => '<p>Sample description</p>',
						],
					],
				],
			],
		];
	}
}
