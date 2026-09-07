<?php
include_once(plugin_dir_path(__FILE__)."init_file.php");
class sasoEventtickets_Authtoken {
    public static $authtoken_param = "auth";

	private $MAIN = null;

	public static function Instance() {
		static $inst = null;
        if ($inst === null) {
            $inst = new sasoEventtickets_Authtoken();
        }
        return $inst;
	}

    private function __construct() {
		global $sasoEventtickets;
		$this->MAIN = $sasoEventtickets;
	}

    public function checkAccessForAuthtoken($code) {
        $code = trim($code);
        if (empty($code)) return false;
        $sql = "select id from ".$this->MAIN->getDB()->getTabelle("authtokens")." where code = %s and aktiv = 1";
        $d = $this->MAIN->getDB()->_db_datenholen_prepared($sql, [$code]);
        if (count($d) == 0) return false;
        return apply_filters( $this->MAIN->_add_filter_prefix.'authtoken_checkAccessForAuthtoken', true, $code );
    }

	public function isProductAllowedByAuthToken($authtoken, $product_ids=[]) {
		if (!is_array($product_ids)) {
			$product_ids = [$product_ids];
		}

		if (count($product_ids) == 0) return true;

		$tokenObj = $this->getAuthtokenByCode($authtoken);
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObjectAuthtoken($tokenObj['meta']);

		if (empty($metaObj["ticketscanner"]["bound_to_products"])) return true; // no product_ids set up

		$allowed_product_ids = explode(",", $metaObj["ticketscanner"]["bound_to_products"]);
		$allowed_product_ids = array_map("intval", $allowed_product_ids);

		foreach($product_ids as $product_id) {
			$product_id = intval($product_id);
			if (!in_array($product_id, $allowed_product_ids)) return false;
		}
		return apply_filters( $this->MAIN->_add_filter_prefix.'authtoken_isProductAllowedByAuthToken', true, $authtoken, $product_ids );
	}

	/**
	 * May this scanner token redeem tickets of these ticket lists?
	 *
	 * Mirror of isProductAllowedByAuthToken() for the list side — the only
	 * restriction that can apply to tickets without an order, which have no
	 * product to bind to.
	 *
	 * @param string $authtoken Token code
	 * @param array|int $list_ids Ticket list ids of the scanned ticket
	 * @return bool
	 */
	public function isListAllowedByAuthToken($authtoken, $list_ids=[]) {
		if (!is_array($list_ids)) {
			$list_ids = [$list_ids];
		}

		$list_ids = array_filter(array_map("intval", $list_ids));
		if (count($list_ids) == 0) return true; // ticket without a list

		$tokenObj = $this->getAuthtokenByCode($authtoken);
		if (empty($tokenObj)) return true;
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObjectAuthtoken($tokenObj['meta']);

		$bound = trim((string) ($metaObj["ticketscanner"]["bound_to_lists"] ?? ""));
		if ($bound === "") return true; // no lists set up

		$allowed_list_ids = array_filter(array_map("intval", array_map("trim", explode(",", $bound))));

		foreach($list_ids as $list_id) {
			if (!in_array($list_id, $allowed_list_ids, true)) return false;
		}

		return apply_filters( $this->MAIN->_add_filter_prefix.'authtoken_isListAllowedByAuthToken', true, $authtoken, $list_ids );
	}

	public function getAuthtokens() {
		$sql = "select * from ".$this->MAIN->getDB()->getTabelle("authtokens")." order by name asc";
		$tokens = $this->MAIN->getDB()->_db_datenholen($sql);
		foreach($tokens as $idx => $value) {
			$tokens[$idx]["metaObj"] = $this->MAIN->getCore()->encodeMetaValuesAndFillObjectAuthtoken($value['meta']);
			$tokens[$idx]["meta"] = $this->MAIN->getCore()->json_encode_with_error_handling($tokens[$idx]["metaObj"]);
		}
		return $tokens;
	}

	public function getAuthtokenByCode($code) {
        $code = trim($code);
        if (empty($code)) throw new Exception("#510 auth token not valid");
        $sql = "select * from ".$this->MAIN->getDB()->getTabelle("authtokens")." where code = %s and aktiv = 1";
        $d = $this->MAIN->getDB()->_db_datenholen_prepared($sql, [$code]);
        if (count($d) == 0) throw new Exception("#509 auth token not found");
        return $d[0];
	}

	public function getAuthtoken($data) {
		if (!isset($data['id'])) throw new Exception("#504 id parameter is missing");
		$sql = "select * from ".$this->MAIN->getDB()->getTabelle("authtokens")." where id = ".intval($data['id']);
		$ret = $this->MAIN->getDB()->_db_datenholen($sql);
		if (count($ret) == 0) throw new Exception("#505 auth token not found");
		return $ret[0];
	}

	public function addAuthtoken($data) {
		if (!isset($data['name']) || trim($data['name']) == "") throw new Exception("#501 name parameter missing - cannot add a new auth token");
		if (!$this->MAIN->getBase()->_isMaxReachedForAuthtokens($this->MAIN->getDB()->_db_getRecordCountOfTable('authtokens'))) throw new Exception("#508 too many authtokens. Unlimited authtokens only with premium");
		$tokenObj = ['meta'=>''];
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObjectAuthtoken($tokenObj['meta']);

		$felder = ["name"=>strip_tags($data['name']), "time"=>wp_date("Y-m-d H:i:s")];
		// CWE-338 (DIG-1762): CSPRNG instead of a wall-clock/uniqid-seeded hash. This value
		// is a bearer credential for the ticket-scanner API, so predictability was the
		// highest-impact case in this class. random_bytes(16) keeps the same 32 hex chars.
		$felder['code'] = strtoupper(base64_encode(get_site_url())."_".bin2hex(random_bytes(16)));
		$felder['areacode'] = "ticketscanner";
		$felder['aktiv'] = isset($data['aktiv']) ? intval($data['aktiv']) : 1;
		$felder['time'] = wp_date("Y-m-d H:i:s");

		$metaObj = $this->setMetaDataForAuthtokens($data, $metaObj);

		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'setFelderAuthtokenEdit')) {
			$felder = $this->MAIN->getPremiumFunctions()->setFelderAuthtokenEdit($felder, $data, $tokenObj, $metaObj);
		}
		if (isset($felder['meta']) && !empty($felder['meta'])) { // evtl gesetzt vom premium plugin
			$f_meta = json_decode($felder['meta'], true);
			$f_meta["desc"] = strip_tags($f_meta["desc"]);
			$metaObj = array_replace_recursive($metaObj, $f_meta);
		}
		$felder["meta"] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);

		$ret = -1;
		try {
			$ret = $this->MAIN->getDB()->insert("authtokens", $felder);
		} catch(Exception $e) {
			throw new Exception("#502 ".__("Could not create authtoken. Auth token code exists already.", 'event-tickets-with-ticket-scanner'));
		}
		do_action( $this->MAIN->_do_action_prefix.'authtoken_addAuthtoken', $data, $ret );
		return $ret;
	}

	public function editAuthtoken($data) {
		if (!isset($data['id']) || intval($data['id']) == 0) throw new Exception("#506 id parameter missing - cannot edit auth token");
		if (isset($data['name']) && trim($data['name']) == "") throw new Exception("#507 name parameter missing - cannot edit auth token");
		$tokenObj = $this->getAuthtoken($data);
		$metaObj = $this->MAIN->getCore()->encodeMetaValuesAndFillObjectAuthtoken($tokenObj['meta']);
		$felder = [];

		if (isset($data['name']) && trim($data['name']) != "") $felder["name"] = strip_tags($data['name']);
		if (isset($data['aktiv'])) $felder["aktiv"] = intval($data['aktiv']);
		$felder['changed'] = wp_date("Y-m-d H:i:s");

		$metaObj = $this->setMetaDataForAuthtokens($data, $metaObj);

		if ($this->MAIN->isPremium() && method_exists($this->MAIN->getPremiumFunctions(), 'setFelderAuthtokenEdit')) {
			$felder = $this->MAIN->getPremiumFunctions()->setFelderAuthtokenEdit($felder, $data, $tokenObj, $metaObj);
		}
		if (isset($felder['meta']) && !empty($felder['meta'])) { // evtl gesetzt vom premium plugin
			$f_meta = json_decode($felder['meta'], true);
			$f_meta["desc"] = strip_tags($f_meta["desc"]);
			$metaObj = array_replace_recursive($metaObj, $f_meta);
		}
		$felder["meta"] = $this->MAIN->getCore()->json_encode_with_error_handling($metaObj);

		$where = ["id"=>intval($data['id'])];
		$ret = $this->MAIN->getDB()->update("authtokens", $felder, $where);
		do_action( $this->MAIN->_do_action_prefix.'authtoken_editAuthtoken', $data, $ret );
		return $ret;
	}

	public function removeAuthtoken($data) {
		if (!isset($data['id'])) throw new Exception("#507 id parameter is missing - cannot remove auth token");
		$sql = "delete from ".$this->MAIN->getDB()->getTabelle("authtokens")." where id = ".intval($data['id']);
		$ret = $this->MAIN->getDB()->_db_query($sql);
		do_action( $this->MAIN->_do_action_prefix.'authtoken_removeAuthtoken', $data, $ret );
		return $ret;
	}

    private function setMetaDataForAuthtokens($data, $metaObj) {
		if (isset($data['meta'])) {
			if (isset($data['meta']['desc'])) {
				$metaObj['desc'] = strip_tags(trim($data['meta']['desc']));
			}
			if (isset($data['meta']['ticketscanner']) && isset($data['meta']['ticketscanner']['bound_to_lists'])) {
				$_raw = strip_tags(trim((string) $data['meta']['ticketscanner']['bound_to_lists']));
				$_lists = array_filter(array_map("intval", array_map("trim", explode(",", $_raw))));
				// An entry that boils down to nothing must not silently clear the
				// restriction — empty means "all lists", so that would widen access
				if ($_raw === "" || count($_lists) > 0) {
					$metaObj['ticketscanner']['bound_to_lists'] = implode(",", $_lists);
				}
			}
			if (isset($data['meta']['ticketscanner']) && isset($data['meta']['ticketscanner']['bound_to_products'])) {
				$metaObj['ticketscanner']['bound_to_products'] = strip_tags(trim($data['meta']['ticketscanner']['bound_to_products']));
			}
			// der rotz hier ist BS und funktioniert nicht, da wieder data.meta genutzt wird
			//$this->MAIN->getCore()->alignArrays($metaObj, $data["meta"]);
			//$metaObj = array_merge($metaObj, $data["meta"]);
		}
		return $metaObj;
	}

}
?>