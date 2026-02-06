<?php
include_once(plugin_dir_path(__FILE__)."init_file.php");
class sasoEventticketsDB extends sasoEventtickets_DB {
	public $dbversion = '1.10';
	public function __construct($MAIN) {
		$this->MAIN = $MAIN;
		parent::$dbprefix = "saso_eventtickets_";
		$this->_tabellen = ['lists', 'codes', 'ips', 'authtokens', 'errorlogs', 'seatingplans', 'seats', 'seat_blocks'];
		$this->init();
	}

	protected function _system_installiereTabellen() {
		$tabellen = [];
		$tabellen[] = [
			"sql"=>
				"CREATE TABLE ".$this->getTabelle('lists')." (
				id int(32) unsigned NOT NULL auto_increment,
				time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
				timezone varchar(255) NOT NULL DEFAULT '',
				name varchar(255) NOT NULL DEFAULT '',
				aktiv int(1) unsigned NOT NULL DEFAULT 0,
				meta longtext NOT NULL DEFAULT '',
				PRIMARY KEY (id)) ".$this->getCharsetCollate().";",
			"additional"=>[
				"CREATE UNIQUE INDEX idx1 ON ".$this->getTabelle('lists')." (name)"
			]
		];
		$tabellen[] = [
			"sql"=>
				"CREATE TABLE ".$this->getTabelle('codes')." (
				id int(32) unsigned NOT NULL auto_increment,
				time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
				timezone varchar(255) NOT NULL DEFAULT '',
				code varchar(150) NOT NULL DEFAULT '',
				code_display varchar(250) NOT NULL DEFAULT '',
				cvv varchar(50) NOT NULL DEFAULT '',
				meta longtext NOT NULL DEFAULT '',
				aktiv int(1) unsigned NOT NULL DEFAULT 0,
				redeemed int(1) unsigned NOT NULL DEFAULT 0,
				list_id int(32) unsigned NOT NULL DEFAULT 0,
				user_id int(32) unsigned NOT NULL DEFAULT 0,
				order_id int(32) unsigned NOT NULL DEFAULT 0,
				semaphorecode varchar(50) NOT NULL DEFAULT '',
				PRIMARY KEY (id)) ".$this->getCharsetCollate().";",
			"additional"=>[
				"CREATE UNIQUE INDEX idx1 ON ".$this->getTabelle('codes')." (code)",
				"CREATE INDEX idx2 ON ".$this->getTabelle('codes')." (time)",
				"CREATE INDEX idx3 ON ".$this->getTabelle('codes')." (order_id)",
				"CREATE INDEX idx4 ON ".$this->getTabelle('codes')." (user_id)",
				"CREATE INDEX idx5 ON ".$this->getTabelle('codes')." (redeemed)"
			]
		];
		$tabellen[] = [
			"sql"=>
				"CREATE TABLE ".$this->getTabelle('ips')." (
				id int(32) unsigned NOT NULL auto_increment,
				time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
				timezone varchar(255) NOT NULL DEFAULT '',
				code varchar(150) NOT NULL DEFAULT '',
				valid int(1) NOT NULL DEFAULT 0,
				ip varchar(40) NOT NULL DEFAULT '',
				action varchar(150) NOT NULL DEFAULT 'Validation',
				PRIMARY KEY (id)) ".$this->getCharsetCollate().";",
			"additional"=>[
				"CREATE INDEX idx1 ON ".$this->getTabelle('ips')." (code,time)",
				"CREATE INDEX idx2 ON ".$this->getTabelle('ips')." (ip,time)"
			]
		];
		$tabellen[] = [
			"sql"=>
				"CREATE TABLE ".$this->getTabelle('authtokens')." (
				id int(32) unsigned NOT NULL auto_increment,
				time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
				timezone varchar(255) NOT NULL DEFAULT '',
				changed datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
				changed_timezone varchar(255) NOT NULL DEFAULT '',
				name varchar(255) NOT NULL DEFAULT '',
				aktiv int(1) unsigned NOT NULL DEFAULT 0,
				code varchar(200) NOT NULL DEFAULT '',
				areacode varchar(25) NOT NULL DEFAULT '',
				user_id int(32) unsigned NOT NULL DEFAULT 0,
				meta longtext NOT NULL DEFAULT '',
				PRIMARY KEY (id)) ".$this->getCharsetCollate().";",
			"additional"=>[
				"CREATE UNIQUE INDEX idx1 ON ".$this->getTabelle('authtokens')." (code, areacode)"
			]
		];
		$tabellen[] = [
			"sql"=>
				"CREATE TABLE ".$this->getTabelle('errorlogs')." (
				id int(32) unsigned NOT NULL auto_increment,
				time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
				timezone varchar(255) NOT NULL DEFAULT '',
				exception_msg varchar(250) NOT NULL DEFAULT '',
				msg longtext NOT NULL DEFAULT '',
				caller_name varchar(250) NOT NULL DEFAULT '',
				PRIMARY KEY (id)) ".$this->getCharsetCollate().";",
			"additional"=>[
				"CREATE INDEX idx1 ON ".$this->getTabelle('errorlogs')." (time)",
				"CREATE INDEX idx2 ON ".$this->getTabelle('errorlogs')." (caller_name)"
			]
		];
		// Seating Plans - v1.8, extended v1.9 for Visual Designer
		$tabellen[] = [
			"sql"=>
				"CREATE TABLE ".$this->getTabelle('seatingplans')." (
				id int(32) unsigned NOT NULL auto_increment,
				time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
				timezone varchar(255) NOT NULL DEFAULT '',
				name varchar(255) NOT NULL DEFAULT '',
				aktiv int(1) unsigned NOT NULL DEFAULT 0,
				meta longtext NOT NULL DEFAULT '',
				layout_type varchar(20) NOT NULL DEFAULT 'simple',
				meta_draft longtext NOT NULL DEFAULT '',
				meta_published longtext NOT NULL DEFAULT '',
				published_at datetime DEFAULT NULL,
				published_by int(32) unsigned DEFAULT NULL,
				created_by int(32) unsigned DEFAULT NULL,
				updated_by int(32) unsigned DEFAULT NULL,
				created_at datetime DEFAULT NULL,
				updated_at datetime DEFAULT NULL,
				PRIMARY KEY (id)) ".$this->getCharsetCollate().";",
			"additional"=>[
				"CREATE UNIQUE INDEX idx1 ON ".$this->getTabelle('seatingplans')." (name)"
			]
		];
		// Seats - v1.8, extended v1.9 for Visual Designer
		$tabellen[] = [
			"sql"=>
				"CREATE TABLE ".$this->getTabelle('seats')." (
				id int(32) unsigned NOT NULL auto_increment,
				time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
				timezone varchar(255) NOT NULL DEFAULT '',
				seatingplan_id int(32) unsigned NOT NULL DEFAULT 0,
				seat_identifier varchar(100) NOT NULL DEFAULT '',
				aktiv int(1) unsigned NOT NULL DEFAULT 1,
				sort_order int(32) unsigned NOT NULL DEFAULT 0,
				meta longtext NOT NULL DEFAULT '',
				is_deleted tinyint(1) unsigned NOT NULL DEFAULT 0,
				deleted_at datetime DEFAULT NULL,
				deleted_by int(32) unsigned DEFAULT NULL,
				created_by int(32) unsigned DEFAULT NULL,
				updated_by int(32) unsigned DEFAULT NULL,
				created_at datetime DEFAULT NULL,
				updated_at datetime DEFAULT NULL,
				PRIMARY KEY (id)) ".$this->getCharsetCollate().";",
			"additional"=>[
				"CREATE INDEX idx1 ON ".$this->getTabelle('seats')." (seatingplan_id, aktiv, sort_order)",
				"CREATE UNIQUE INDEX idx2 ON ".$this->getTabelle('seats')." (seatingplan_id, seat_identifier)",
				"CREATE INDEX idx3 ON ".$this->getTabelle('seats')." (seatingplan_id, is_deleted)"
			]
		];
		// Seat Blocks (Semaphore) - v1.8
		$tabellen[] = [
			"sql"=>
				"CREATE TABLE ".$this->getTabelle('seat_blocks')." (
				id int(32) unsigned NOT NULL auto_increment,
				time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
				timezone varchar(255) NOT NULL DEFAULT '',
				seat_id int(32) unsigned NOT NULL DEFAULT 0,
				seatingplan_id int(32) unsigned NOT NULL DEFAULT 0,
				product_id int(32) unsigned NOT NULL DEFAULT 0,
				event_date date DEFAULT NULL,
				session_id varchar(100) NOT NULL DEFAULT '',
				order_id int(32) unsigned DEFAULT NULL,
				code_id int(32) unsigned DEFAULT NULL,
				expires_at datetime DEFAULT NULL,
				last_seen datetime DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'blocked',
				meta longtext NOT NULL DEFAULT '',
				PRIMARY KEY (id)) ".$this->getCharsetCollate().";",
			"additional"=>[
				"CREATE INDEX idx1 ON ".$this->getTabelle('seat_blocks')." (seatingplan_id, event_date, status)",
				"CREATE INDEX idx2 ON ".$this->getTabelle('seat_blocks')." (seat_id, product_id, event_date, status)",
				"CREATE INDEX idx3 ON ".$this->getTabelle('seat_blocks')." (status, expires_at)",
				"CREATE INDEX idx4 ON ".$this->getTabelle('seat_blocks')." (session_id)",
				"CREATE INDEX idx5 ON ".$this->getTabelle('seat_blocks')." (order_id)",
				"CREATE INDEX idx6 ON ".$this->getTabelle('seat_blocks')." (code_id)"
			]
		];
		$tabellen = apply_filters( $this->MAIN->_add_filter_prefix.'db_system_installiereTabellen', $tabellen );
		do_action( $this->MAIN->_do_action_prefix.'db_system_installiereTabellen', $tabellen );
		return $tabellen;
	}
}

class sasoEventtickets_DB {
	// irgendwann nach dem https://codex.wordpress.org/Creating_Tables_with_Plugins
	//https://tobier.de/wordpress-plugin-erstellen-datenbank/
	public $dbversion;
	protected static $dbprefix;
	protected $_tabellen = [];
	private $tabellen;
	protected $callerValue = "basic";

	protected $MAIN;

	public function __construct($MAIN) {
		$this->MAIN = $MAIN;
		$this->init();
	}
	protected function init() {
		$this->tabellen = [];
		foreach($this->_tabellen as $t) {
			$this->tabellen[$t] = $this->getPrefix().$t;
		}
	}

	public function getTabelle($tabelle) {
		return $this->tabellen[$tabelle];
	}

	public function getTables() {
		return $this->_tabellen;
	}

	public function reinigen_in($text, $len=0, $addsl=1, $utf=0, $html=0) {
		$text = trim($text);
		if ($len > 0)
		    $text = substr($text, 0, $len);
		if ($utf == 1)
			$text = mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
		    //$text = utf8_decode($text); // die zeichen sind utf kodiert
		if ($html == 1)
		    $text = htmlentities($text); // zerstört HTML zeug im text
		if ($addsl == 1)
			$text = addslashes($text);
		return $text;
	}

	private function getPrefix() {
		global $wpdb;
		return $wpdb->prefix . self::$dbprefix;
	}

	protected function getCharsetCollate() {
		global $wpdb;
		return $wpdb->get_charset_collate();
	}

	public function _db_datenholen_prepared($sql, $felder) {
		global $wpdb;
		for ($a=0;$a<count($felder);$a++) {
			//$felder[$a] = $wpdb->esc_like( $felder[$a] );
			$felder[$a] = $this->reinigen_in( $felder[$a] );
		}
		$sql = $wpdb->prepare( $sql, $felder );
		return $this->_db_datenholen($sql);
	}

	public function _db_datenholen($sql, $again=true) {
	  	global $wpdb;
	  	//update_option( self::$dbprefix."db_version", "1.4" );
	  	//$installed_ver = get_option( self::$dbprefix."db_version" );
	  	//if ($installed_ver != $this->dbversion) $this->installiereTabellen();
	  	//echo $installed_ver;
		try {
	  		$ret = $wpdb->get_results($sql, ARRAY_A);
		} catch(Exception $e) {
			if ($again == true) {
				$this->installiereTabellen(true);
				return $this->_db_datenholen($sql, false);
			} else {
				throw $e;
			}
		}
		if ( $wpdb->last_error ) {
			$this->MAIN->getAdmin()->logErrorToDB(new Exception("Database error - Again: ".$again), null, $wpdb->last_error);
			if ($again == true) {
				$this->installiereTabellen(true);
				$ret = $this->_db_datenholen($sql, false);
			}
		}
		return $ret;
	}

	public function _db_getRecordCountOfTable($tabelle, $where="") {
		$sql = "select count(*) as anzahl from ".$this->getTabelle($tabelle);
		if ($where != "") $sql .= " where ".$where;
		list($d) = $this->_db_datenholen($sql);
		return $d['anzahl'];
	}

	public function getCodesSize() {
		return $this->_db_getRecordCountOfTable('codes');
	}

	private function addMissingFelder($felder) {
		if (($this->callerValue == "basic" && version_compare( $this->dbversion, '1.6', '>' )) ||
			($this->callerValue == "prem" && version_compare( $this->dbversion, '1.3', '>' ))) {
			if (array_key_exists("time", $felder)) {
				if (!array_key_exists("timezone", $felder)) {
					$felder["timezone"] = wp_timezone_string();
				}
			}
			if (array_key_exists("changed", $felder)) {
				if (!array_key_exists("changed_timezone", $felder)) {
					$felder["changed_timezone"] = wp_timezone_string();
				}
			}
		}
		$felder = apply_filters( $this->MAIN->_add_filter_prefix.'db_addMissingFelder', $felder );
		return $felder;
	}

	public function insert($tabelle, $felder=[]) {
		global $wpdb;
		if (count($felder) == 0) throw new Exception("no fields provided");
		$felder = $this->addMissingFelder($felder);
		$wpdb->insert( $this->getTabelle($tabelle), $felder );
		return $wpdb->insert_id;
	}

	public function update($tabelle, $felder, $where) {
		global $wpdb;
		if (count($felder) == 0) throw new Exception("no fields provided");
		$felder = $this->addMissingFelder($felder);
		if (count($where) == 0) throw new Exception("no where fields provided");
		return $wpdb->update( $this->getTabelle($tabelle), $felder, $where);
	}

	public function _db_query($sql) {
		global $wpdb;
  	    $erg = $wpdb->query($sql);
		if ($erg):
			if (strtolower(substr($sql, 0, 6)) == "insert") {
				return $wpdb->insert_id;
			}
			return $erg;
		else:
			if (!empty($wpdb->last_error)) {
				$this->installiereTabellen(true);
				$this->MAIN->getAdmin()->logErrorToDB(new Exception("Database error"), null, $wpdb->last_error);
				echo $wpdb->last_error;
				wp_die($wpdb->last_error);
			}
		endif;
		return $erg;
	}

	public function installiereTabellen($force=false) {
		global $wpdb;
		if (empty($this->dbversion)) throw new Exception("dbversion is not set");
		if (empty(self::$dbprefix)) throw new Exception("dbprefix is not set");

		$installed_ver = get_option( self::$dbprefix."db_version" );

		if ($force || $installed_ver != $this->dbversion ) {
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

			$tabellen = $this->_system_installiereTabellen(); // array
			foreach($tabellen as $tabelle)  {
				dbDelta( $tabelle['sql'] ); // tabelle erstellen
				if (isset($tabelle['additional'])) {
					$wpdb->suppress_errors = true;
					foreach($tabelle['additional'] as $sql) {
						//echo $sql;
						$wpdb->query($sql); // zusätzlich sql wie index
					}
					$wpdb->suppress_errors = false;
				}
			}

			update_option( self::$dbprefix."db_version", $this->dbversion );
			if ($this->callerValue == "basic") {
				$this->MAIN->getAdmin()->performJobsAfterDBUpgraded($this->dbversion, $installed_ver);
			} else { // wenn für die prem DB dann direkt aufruf
				if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'performJobsAfterPremDBUpgraded')) {
					$this->MAIN->getPremiumFunctions()->performJobsAfterPremDBUpgraded($this->dbversion, $installed_ver);
				}
			}
		}
	}
	public static function plugin_deactivated() {
		//delete_option(self::$dbprefix."db_version");
	}
	public static function plugin_uninstall(){
		self::plugin_deactivated();
		//delete tabellen
		/*
		global $wpdb;
		foreach($this->tabellen as $key => $value) {
			$wpdb->query("DROP TABLE IF EXISTS ".$value);
		}
		*/
	}
	protected function _system_installiereTabellen()
	{
		throw new Exception("overwrite this function");
	}
}
?>