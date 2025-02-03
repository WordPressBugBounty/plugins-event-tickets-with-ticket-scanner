<?php
use setasign\Fpdi\Tcpdf\Fpdi;
include_once(plugin_dir_path(__FILE__)."init_file.php");
class sasoEventtickets_PDF {
    private $parts = [];
    private $filemode;
    private $filepath;
    private $filename;
    private $orientation = "P";
    private $page_format = 'A4';
	private $isRTL = false;
	private $languageArray = null;
	private $background_image = null;
	private $fontSize = 10;
	private $fontFamily = "dejavusans";

	private $is_own_page_format = false;
	private $size_width = 210;
	private $size_height = 297;
	public $marginsZero = false;

	private $attach_pdfs = [];

	private $qr;
	private $qr_values;

    public function __construct($parts=[], $filemode="I", $filename="PDF.pdf") {
		$this->qr_values = $this->getDefaultQRValues();
        if (is_array($parts)) $this->setParts($parts);
		$this->setFilemode($filemode);
		$this->setFilename($filename);
        $this->_loadLibs();
    }

	public function getPossibleFontFamiles() {
		$ret = ["default"=>'dejavusans', "fonts"=>[]];
		if ($handle = opendir(__DIR__.'/vendors/TCPDF/fonts')) {
			while (false !== ($entry = readdir($handle))) {
				if (pathinfo($entry, PATHINFO_EXTENSION) == "php") {
					$ret["fonts"][] = substr($entry, 0, -4);
				}
			}
			closedir($handle);
		}
		return $ret;
	}

	public function setAdditionalPDFsToAttachThem($pdfs) {
		if (!is_array($pdfs)) {
			$pdfs = [$pdfs];
		}
		$this->attach_pdfs = $pdfs;
	}

	public function setBackgroundImage($background_image=null) {
		$this->background_image = $background_image;
	}

	public function setFontSize($number=10) {
		$this->fontSize = intval($number);
	}

	public function convertPixelIntoMm($pixels, $dpi=96) {
		if ($dpi < 1) $dpi = 96;
		return $pixels * 25.4 / $dpi;
	}

	private function getDefaultQRValues() {
		return [
			'pos'=>['x'=>150, 'y'=>10],
			'size'=>['width'=>50, 'height'=>50],
			"type"=>"QRCODE,Q",
			'style'=>[
				'position'=>'R',
				//'align'=>'C',
				'border' => 0,
				'vpadding' => 0,//'auto',
				'hpadding' => 0,//'auto',
				'fgcolor' => array(0,0,0),
				//'bgcolor' => false, //array(255,255,255)
				'bgcolor' => array(255,255,255),
				'module_width' => 1, // width of a single module in points
				'module_height' => 1 // height of a single module in points
			],
			'align'=>'C'
		];
	}

	public function setQRParams($data) {
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$this->qr_values[$key] = array_merge($this->qr_values[$key], $value);
			} else {
				$this->qr_values[$key] = $value;
			}
		}
	}

	public function setFontFamily($fontFamily="dejavusans") {
		$this->fontFamily = trim($fontFamily);
	}

	public function initQR() {
		$this->qr = array_merge(["text"=>""], $this->qr_values);
	}

	public function setSize($w, $h) {
		$this->is_own_page_format = true;
		$this->size_width = intval($w);
		$this->size_height = intval($h);
	}
	public function setRTL($rtl=false) {
		$this->isRTL = $rtl;
	}
	public function isRTL() {
		return $this->isRTL;
	}
	public function setLanguageArray($a) {
		$this->languageArray = $a;
	}
	public function setQRCodeContent($qr) {
		if ($this->qr == null) {
			$this->initQR();
		}
		foreach ($qr as $key => $value) {
			if (is_array($this->qr[$key]) && is_array($value)) {
				$this->qr[$key] = array_merge($this->qr[$key], $value);
			} else {
				$this->qr[$key] = $value;
			}
		}
	}
    public function setPageFormat($format) {
        $this->page_format = trim($format);
    }
	public function setOrientation($value){
		// L oder P
		$this->orientation = addslashes(trim($value));
	}
	public function setFilemode($m) {
		$this->filemode = strtoupper($m);
	}

	public function getFilemode() {
		return $this->filemode;
	}
	public function setFilepath($path) {
		$this->filepath = trim($path);
	}
	public function setFilename($p) {
		$this->filename = trim($p);
	}

	public function getFullFilePath() {
		return $this->filepath.$this->filename;
	}
    public function setParts($parts=[]) {
		$this->parts = [];
		foreach($parts as $part) {
			$this->addPart($part);
		}
	}
	public function addPart($part) {
		$teile = explode('{PAGEBREAK}', $part);
		foreach($teile as $teil) {
			$this->parts[] = $teil;
		}
	}

	private function getParts() {
		return $this->parts;
	}

    private function _loadLibs() {
		// always load alternative config file for examples
		require_once('vendors/TCPDF/config/tcpdf_config.php');

		// Include the main TCPDF library (search the library on the following directories).
		$tcpdf_include_dirs = array(
			plugin_dir_path(__FILE__).'vendors/TCPDF/tcpdf.php',
			realpath(dirname(__FILE__) . '/vendors/TCPDF/tcpdf.php'),// True source file
			realpath('vendors/TCPDF/tcpdf.php'),// Relative from $PWD
			'/usr/share/php/tcpdf/tcpdf.php',
			'/usr/share/tcpdf/tcpdf.php',
			'/usr/share/php-tcpdf/tcpdf.php',
			'/var/www/tcpdf/tcpdf.php',
			'/var/www/html/tcpdf/tcpdf.php',
			'/usr/local/apache2/htdocs/tcpdf/tcpdf.php'
		);
		foreach ($tcpdf_include_dirs as $tcpdf_include_path) {
			if (@file_exists($tcpdf_include_path)) {
				require_once($tcpdf_include_path);
				break;
			}
		}

		require_once('vendors/FPDI-2.3.7/src/autoload.php');
		require_once("vendors/fpdf185/fpdf.php");
	}

	private function prepareOutputBuffer() {
		if ($this->filemode != "F") ob_clean();
		if ($this->filemode != "F") ob_start();
	}
	private function cleanOutputBuffer() {
		if ($this->filemode != "F") {
			$output_level = ob_get_level();
			for ($a=0;$a<$output_level;$a++) {
				ob_end_clean();
			}
		}
	}
	private function outputPDF($pdf) {
		if ($this->filemode == "F") {
			$pdf->Output($this->filepath.$this->filename, $this->filemode);
		} else {
			header_remove();
			$pdf->Output($this->filename, $this->filemode);
		}
	}

	private function getFormat() {
		$format = $this->page_format;
		if ($this->is_own_page_format) {
			$format = [$this->size_width, $this->size_height];
		}
		return $format;
	}

	private function checkFilePath() {
		if (empty($this->filepath)) $this->filepath = get_temp_dir();
	}

	private function attachPDFs($pdf, $pdf_filelocations=[]) {
		if (count($pdf_filelocations) > 0) {
			foreach($pdf_filelocations as $pdf_filelocation) {
				// mergen und entsprechend dem filemode senden
				$pagenumbers = $pdf->setSourceFile($pdf_filelocation);
				for ($a=1;$a<=$pagenumbers;$a++) {
					$tplIdx = $pdf->importPage($a);
					$pdf->AddPage();
					$pdf->useTemplate($tplIdx,0,0,null,null,true);
				}
			}
		}
		return $pdf;
	}

	public function mergeFiles($pdf_filelocations=[]) {
		if (count($pdf_filelocations) == 0) throw new Exception("no files to merge");
		$this->prepareOutputBuffer();
		$this->checkFilePath();
		$format = $this->getFormat();
		$pdf = new FPDI($this->orientation, PDF_UNIT, $format, true, 'UTF-8', false, false);
		$pdf = $this->attachPDFs($pdf, $pdf_filelocations);

		$this->cleanOutputBuffer();
		$this->outputPDF($pdf);
	}

    public function render() {
		$this->prepareOutputBuffer();
		$this->checkFilePath();
		$format = $this->getFormat();

		if ($this->size_width > $this->size_height) {
			$this->orientation = "L";
		}

		$pdf = new FPDI($this->orientation, PDF_UNIT, $format, true, 'UTF-8', false, false);
		//$pdf->error = function ($msg) {throw new Exception("PDF-Parser: ".$msg);};

        $preferences = [
            //'HideToolbar' => true,
            //'HideMenubar' => true,
            //'HideWindowUI' => true,
            //'FitWindow' => true,
            'CenterWindow' => true,
            //'DisplayDocTitle' => true,
            //'NonFullScreenPageMode' => 'UseNone', // UseNone, UseOutlines, UseThumbs, UseOC
            //'ViewArea' => 'CropBox', // CropBox, BleedBox, TrimBox, ArtBox
            //'ViewClip' => 'CropBox', // CropBox, BleedBox, TrimBox, ArtBox
            'PrintArea' => 'CropBox', // CropBox, BleedBox, TrimBox, ArtBox
            //'PrintClip' => 'CropBox', // CropBox, BleedBox, TrimBox, ArtBox
            'PrintScaling' => 'None', // None, AppDefault
            'Duplex' => 'DuplexFlipLongEdge', // Simplex, DuplexFlipShortEdge, DuplexFlipLongEdge
            'PickTrayByPDFSize' => true,
            //'PrintPageRange' => array(1,1,2,3),
            //'NumCopies' => 2
        ];
        if ($this->orientation == "L") $preferences['Duplex'] = "DuplexFlipShortEdge";
        $pdf->setViewerPreferences($preferences);
		$pdf->SetAutoPageBreak(TRUE, 5);

		// set image scale factor
		$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
		$pdf->setJPEGQuality(90);

		//$pdf->addFormat("custom", $this->size_width, $this->size_height);

		// set margins
		if ($this->marginsZero) {
			$pdf->SetMargins(0, 0, 0);
		}
		//$pdf->SetMargins(PDF_MARGIN_LEFT, 17, 10);
		//$pdf->SetHeaderMargin(10);
		//$pdf->SetFooterMargin(10);

		$pdf->SetPrintHeader(false);
		$pdf->SetPrintFooter(false);

		if ($this->isRTL) {
			$pdf->setRTL(true);
		}

		if ($this->languageArray != null) {
			$pdf->setLanguageArray($this->languageArray);
		}

		//$pdf->SetFont('helvetica', '', "10pt");
		//$pdf->SetFont('dejavusans', '', $this->fontSize."pt");
		//$pdf->SetFont('cid0jp', '', $this->fontSize."pt"); // support for japanese
		$pdf->SetFont($this->fontFamily, '', $this->fontSize."pt"); // support for japanese

		$page_parts = $this->getParts();
		// Print text using writeHTMLCell()
		$pdf->AddPage();

		// background image
		if ($this->background_image != null) {
			//$w_image = $this->orientation == "L" ? $this->size_height : $this->size_width;
			//$h_image = $this->orientation == "L" ? $this->size_width : $this->size_height;
			$w_image = $this->size_width;
			$h_image = $this->size_height;
			$pdf->SetAutoPageBreak(false, 0);
			$bg_pos_x = 0;
			$bg_pos_y = 0;
			$bg_size_w = $w_image;
			$bg_size_h = $h_image;
			if (function_exists("getimagesize")){
				$finfo = getimagesize($this->background_image);
				//print_r($finfo);exit;
				$bg_size_w = $pdf->pixelsToUnits($finfo[0]);
				$bg_size_h = $pdf->pixelsToUnits($finfo[1]);
				$faktor = 1;
				if ($bg_size_w > $w_image) {
					$faktor = $bg_size_w / $w_image;
					$bg_size_w = $w_image;
					$bg_size_h /= $faktor;
				}
				if ($bg_size_h > $h_image) {
					$faktor = $bg_size_h / $h_image;
					$bg_size_h = $h_image;
					$bg_size_w /= $faktor;
				}
				$bg_pos_x = ($w_image - $bg_size_w) / 2;
				$bg_pos_y = ($h_image - $bg_size_h) / 2;
			}
			//$pdf->Image($this->background_image, $bg_pos_x, $bg_pos_y, $bg_size_w, $bg_size_h, '', '', '', false, 300, '', false, false, 1, 'CM');
			$pdf->Image($this->background_image, $bg_pos_x, $bg_pos_y, $bg_size_w, $bg_size_h, '', '', '', false, 300, '', false, false, 0);
			$pdf->SetAutoPageBreak(TRUE, 5);
			$pdf->setPageMark();
		}

		$qr_params = $pdf->serializeTCPDFtagParameters([$this->qr['text'], $this->qr['type'], '', '', $this->qr['size']['width'], $this->qr['size']['height'], $this->qr['style'], $this->qr['align']]);
		$qr_code_inline = '<tcpdf method="write2DBarcode" params="'.$qr_params.'" />';
		//$pdf->writeHTML(print_r($this->qr, true));

		foreach($page_parts as $p) {

			$p = str_replace("{QRCODE_INLINE}", $qr_code_inline, $p);

			try {
				if ($p == "{PAGEBREAK}") {
					$pdf->AddPage();
					continue;
				}
				$teile = explode('{PAGEBREAK}', $p);
				$counter = 0;
				foreach($teile as $teil) {
					$counter++;
					if ($counter > 1) $pdf->AddPage();
					if ($teil == "{QRCODE}") {
						if (!empty($this->qr['text'])) {
							$qr = $this->getDefaultQRValues();
							$pdf->write2DBarcode($this->qr['text'], $this->qr['type'], $this->qr['pos']['x'], $this->qr['pos']['y'], $this->qr['size']['width'], $this->qr['size']['height'], $qr['style'], $qr['align']);
						}
					/*} else if ($teil == "{QRCODE_INLINE}") {
						if (!empty($this->qr['text'])) {
							$pdf->write2DBarcode($this->qr['text'], $this->qr['type'], '', '', $this->qr['size']['width'], $this->qr['size']['height'], $this->qr['style'], $this->qr['align']);
						}*/
					} else {
						/*
						$pos = strpos($teil,"{QRCODE_INLINE}");
						if ($pos !== false) {
							$pdf->writeHTML(substr($teil,0,$pos), false, false, true, false, '');
							if (!empty($this->qr['text'])) {
								$pdf->write2DBarcode($this->qr['text'], $this->qr['type'], null, null, $this->qr['size']['width'], $this->qr['size']['height']);
							}
							$pdf->writeHTML(substr($teil, $pos+15), false, false, true, false, '');
						} else {
							//$pdf->writeHTMLCell(0, 0, '', '', $teil, 0, 1, 0, true, '', true);
							$pdf->writeHTML($teil, false, false, true, false, '');
						}
						*/
						$pdf->writeHTML($teil, false, false, true, false, '');
					}
				}
			} catch(Exception $e) {	}
		}

		$pdf->lastPage();
		$pdf = $this->attachPDFs($pdf, $this->attach_pdfs);

		$this->cleanOutputBuffer();
		$this->outputPDF($pdf);
    }

}
?>