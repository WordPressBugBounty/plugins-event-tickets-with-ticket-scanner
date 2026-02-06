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
		//$mvct = $this->getOverallTicketCounterValue();
		//if ( $mvct > 0 && $mvct > ($total+150)) return false;
		return true;
	}
	public function _isMaxReachedForAuthtokens($total) {
		if ($this->getMaxValue('authtokens_total', 0) == 0) return true;
		if ($total > $this->getMaxValue('authtokens_total')) return false;
		return true;
	}
}
?>