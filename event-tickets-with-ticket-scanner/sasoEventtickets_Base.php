<?php
include_once(plugin_dir_path(__FILE__)."init_file.php");
class sasoEventtickets_Base {
	private $_isPremInitialized = false;
	private $_maxValues = [];

	private $MAIN = null;

	public function __construct($MAIN) {
		$this->MAIN = $MAIN;
	}
	private function initPrem() {
		if (count($this->_maxValues) == 0) {
			$this->_maxValues = $this->MAIN->getMV();
		}
		if ($this->_isPremInitialized == false) {
			$prem = $this->MAIN->getPremiumFunctions();
			if ($prem != null) {
				if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'maxValues')) {
					$this->_maxValues = $prem->maxValues();
				}
			}
			$this->_isPremInitialized = true;
		}
	}
	public function increaseGlobalTicketCounter($a=1) {
		$mvct = $this->getOverallTicketCounterValue() + $a;
		update_option($this->MAIN->getPrefix()."mvct", $mvct);
		do_action( $this->MAIN->_do_action_prefix.'base_increaseGlobalTicketCounter', $mvct );
	}
	public function getOverallTicketCounterValue() {
		return intval(get_option( $this->MAIN->getPrefix()."mvct" ));
	}
	public function getMaxValues() {
		$this->initPrem();
		return $this->_maxValues;
	}
	public function getMaxValue($key, $def = 1) {
		$maxValues = $this->getMaxValues();
		if (isset($maxValues[$key])) return $maxValues[$key];
		return $def;
	}
	public function _isMaxReachedForList($total) {
		if ($this->getMaxValue('lists') == 0) return true;
		if ($total > $this->getMaxValue('lists')) return false;
		return true;
	}
	public function _isMaxReachedForTickets($total) {
		if ($this->getMaxValue('codes_total') == 0) return true;
		if ($total > $this->getMaxValue('codes_total')) return false;
		$mvct = $this->getOverallTicketCounterValue();
		if ($mvct > 0 && $mvct > ($total + 150)) return false;
		return true;
	}
	public function _isMaxReachedForAuthtokens($total) {
		if ($this->getMaxValue('authtokens_total', 0) == 0) return true;
		if ($total > $this->getMaxValue('authtokens_total')) return false;
		return true;
	}

	/**
	 * What a shop owner needs to judge "is this thing maintained, will it still
	 * be here in two years, does anybody answer?" - the question customers
	 * actually asked us, and the one they answer inside wp-admin long before
	 * they see any sales page.
	 *
	 * Every value is evidence, none is a claim: the date comes out of the
	 * shipped changelog, and the support time stays hidden until somebody
	 * supplies a measured number.
	 *
	 * @return array{version:string,last_update:string,changelog_url:string,support_response_hours:int}
	 */
	public function getPluginTrustInfo(): array {
		$hours = (int) apply_filters( $this->MAIN->_add_filter_prefix.'support_response_hours', 0 );

		return [
			'version' => (string) SASO_EVENTTICKETS_PLUGIN_VERSION,
			'last_update' => $this->getLastChangelogDate(),
			'changelog_url' => 'https://wordpress.org/plugins/event-tickets-with-ticket-scanner/#developers',
			'support_response_hours' => $hours > 0 ? $hours : 0
		];
	}

	/**
	 * The date of the most recent released changelog entry in readme.txt.
	 *
	 * The topmost entry of ongoing development carries no date on purpose (that
	 * is set at release), so undated headings are skipped instead of guessed.
	 * Reads line by line and stops at the first hit - readme.txt is shipped with
	 * the plugin, but there is no reason to pull all of it into memory.
	 */
	public function getLastChangelogDate(): string {
		static $cached = null;
		if ($cached !== null) return $cached;

		$cached = '';
		$readme = plugin_dir_path(dirname(__FILE__) . '/index.php') . 'readme.txt';
		if (!is_readable($readme)) return $cached;

		$handle = fopen($readme, 'r');
		if ($handle === false) return $cached;
		while (($line = fgets($handle)) !== false) {
			if (preg_match('/^= *[0-9.]+ +- +(\d{4}-\d{2}-\d{2}) *=/', $line, $match)) {
				$cached = $match[1];
				break;
			}
		}
		fclose($handle);

		return $cached;
	}
}
?>