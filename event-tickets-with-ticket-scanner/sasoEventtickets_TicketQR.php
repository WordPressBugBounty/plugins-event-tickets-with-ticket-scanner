<?php
include_once(plugin_dir_path(__FILE__)."init_file.php");
class sasoEventtickets_TicketQR {
    private $MAIN;

    private $size_width;
    private $size_height;

    private $ticketQRUseURLToTicketScanner;

    private $filepath = "";

    public static function Instance() {
		static $inst = null;
        if ($inst === null) {
            $inst = new sasoEventtickets_TicketQR();
        }
        return $inst;
	}

    public function __construct() {
		global $sasoEventtickets;
		$this->MAIN = $sasoEventtickets;
        $this->ticketQRUseURLToTicketScanner = $this->MAIN->getOptions()->isOptionCheckboxActive('ticketQRUseURLToTicketScanner');
    }

    public function setWidth($width) {
        $this->size_width = intval($width);
    }
    public function setHeight($height) {
        $this->size_height = intval($height);
    }
    public function setFilepath($filepath) {
        $this->filepath = trim($filepath);
    }
    private function checkFilePath() {
		if (empty($this->filepath)) $this->filepath = get_temp_dir();
	}

    public function renderPNG($ticket_id, $filemode="I") {
        $this->checkFilePath();
        //$width = $this->size_width > 0 ? $this->size_width : 80;
        //$height = $this->size_height > 0 ? $this->size_height : 80;
        require_once("vendors/phpqrcode/qrlib.php");
        $PNG_TEMP_DIR = $this->filepath;
        $filename = $PNG_TEMP_DIR."ticketqr_".$ticket_id.".jpg";
        $errorCorrectionLevel = 'L';
        $matrixPointSize = 10;

        $qrcode_content = $ticket_id;
        if ($this->ticketQRUseURLToTicketScanner) {
            $qrcode_content = $this->MAIN->getCore()->getTicketScannerURL($ticket_id);
        }

        if (!file_exists($filename)) {
            QRcode1::png($qrcode_content, $filename, $errorCorrectionLevel, $matrixPointSize, 2);
        }
        return $filename;
    }
    public function renderPDF($ticket_id, $filemode="I") {
        $width = $this->size_width > 0 ? $this->size_width : 80;
        $height = $this->size_height > 0 ? $this->size_height : 80;

		$pdf = $this->MAIN->getNewPDFObject();
		$pdf->setFilemode($filemode);

        $pdf->setFilepath($this->filepath);
		$filename = "ticketqr_".$ticket_id.".pdf";
		$pdf->setFilename($filename);

        $pdf->marginsZero = true;
        $pdf->addPart("{QRCODE}");
        $pdf->setQRParams([
            'pos'=>['x'=>0, 'y'=>0],
            'size'=>['width'=>$width, 'height'=>$height],
            'style'=>['position'=>'C'],
            'align'=>'N'
        ]);

        $qrcode_content = $ticket_id;
        if ($this->ticketQRUseURLToTicketScanner) {
            $qrcode_content = $this->MAIN->getCore()->getTicketScannerURL($ticket_id);
        }
        $qrTicketPDFPadding = intval($this->MAIN->getOptions()->getOptionValue('qrTicketPDFPadding'));
		$pdf->setQRCodeContent(["text"=>$qrcode_content, "style"=>["vpadding"=>$qrTicketPDFPadding, "hpadding"=>$qrTicketPDFPadding]]);
		$pdf->setSize($width, $height);
		$pdf->render();
        if ($pdf->getFilemode() == "F") {
			return $pdf->getFullFilePath();
		} else {
			exit;
		}
    }

}
?>