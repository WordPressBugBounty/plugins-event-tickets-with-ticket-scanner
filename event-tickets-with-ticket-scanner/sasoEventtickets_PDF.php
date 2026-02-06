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

    private function _loadLibs() {
		// always load alternative config file for examples

		//require_once('vendors/TCPDF/config/tcpdf_config_alt.php');
		require_once('vendors/TCPDF/config/tcpdf_config.php');

		// Include the main TCPDF library (search the library on the following directories).
		if (!class_exists('TCPDF')) {
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
		}

		if (!class_exists('Fpdi')) {
			require_once('vendors/FPDI-2.3.7/src/autoload.php');
		}
		if (!class_exists('FPDF')) {
			require_once("vendors/fpdf185/fpdf.php");
		}
	}

	public function getFontInfos() {
		// Returns an array with font names and their descriptions
		// The array contains the font name as key and an array with "name", "lang_support" and "desc" as values
		// "name" is the display name
		// "lang_support" is a string with language codes that the font supports (e.g. "en, de, fr")
		// "desc" is a description of the font
		// The array is sorted by the font name
		// The font names are the same as in the TCPDF library, so you can use them directly in the SetFont() method
		// The font names are case-sensitive, so you must use the exact name as in the array
		// the font names are in the folder vendors/TCPDF/fonts
		// The array is used to populate a dropdown list in the settings page of the plugin
		$infos = [
			'aealarabiya'=>["name"=>'AE Al Arabiya', "lang_support"=>"ar", "desc"=>"AE Al Arabiya is a font family that supports Arabic characters."],
			'aefurat'=>["name"=>'AE Furat', "lang_support"=>"ar", "desc"=>"AE Furat is a font family that supports Arabic characters."],
			'cid0jp'=>["name"=>'CID0JP', "lang_support"=>"ja", "desc"=>"CID0JP is a font family that supports Japanese characters."],
			'cid0kr'=>["name"=>'CID0KR', "lang_support"=>"ko", "desc"=>"CID0KR is a font family that supports Korean characters."],
			'cid0cn'=>["name"=>'CID0CN', "lang_support"=>"zh", "desc"=>"CID0CN is a font family that supports Chinese characters."],
			'cid0tw'=>["name"=>'CID0TW', "lang_support"=>"zh", "desc"=>"CID0TW is a font family that supports Traditional Chinese characters."],
			'cid0vn'=>["name"=>'CID0VN', "lang_support"=>"vi", "desc"=>"CID0VN is a font family that supports Vietnamese characters."],
			'cid0th'=>["name"=>'CID0TH', "lang_support"=>"th", "desc"=>"CID0TH is a font family that supports Thai characters."],
			'cid0cs'=>["name"=>'CID0CS', "lang_support"=>"cn", "desc"=>"CID0CS is a font family that supports chinese simplified characters."],
			'cid0ct'=>["name"=>'CID0CT', "lang_support"=>"cn", "desc"=>"CID0CT is a font family that supports chinese traditional characters."],
			'cid0arabic'=>["name"=>'CID0Arabic', "lang_support"=>"ar", "desc"=>"CID0Arabic is a font family that supports Arabic characters."],
			'cid0hebrew'=>["name"=>'CID0Hebrew', "lang_support"=>"he", "desc"=>"CID0Hebrew is a font family that supports Hebrew characters."],
			'cid0cyrillic'=>["name"=>'CID0Cyrillic', "lang_support"=>"ru", "desc"=>"CID0Cyrillic is a font family that supports Cyrillic characters."],
			'cid0greek'=>["name"=>'CID0Greek', "lang_support"=>"el", "desc"=>"CID0Greek is a font family that supports Greek characters."],
			'cid0thai'=>["name"=>'CID0Thai', "lang_support"=>"th", "desc"=>"CID0Thai is a font family that supports Thai characters."],
			'cid0vietnamese'=>["name"=>'CID0Vietnamese', "lang_support"=>"vi", "desc"=>"CID0Vietnamese is a font family that supports Vietnamese characters."],
			'cid0latin'=>["name"=>'CID0Latin', "lang_support"=>"", "desc"=>"CID0Latin is a font family that supports Latin characters."],
			'cid0latinext'=>["name"=>'CID0Latin Extended', "lang_support"=>"", "desc"=>"CID0Latin Extended is a font family that supports Latin characters with extended glyphs."],
			'cid0cyrillicext'=>["name"=>'CID0Cyrillic Extended', "lang_support"=>"", "desc"=>"CID0Cyrillic Extended is a font family that supports Cyrillic characters with extended glyphs."],
			'cid0greekext'=>["name"=>'CID0Greek Extended', "lang_support"=>"", "desc"=>"CID0Greek Extended is a font family that supports Greek characters with extended glyphs."],
			'cid0arabicext'=>["name"=>'CID0Arabic Extended', "lang_support"=>"", "desc"=>"CID0Arabic Extended is a font family that supports Arabic characters with extended glyphs."],
			'courier'=>["name"=>'Courier', "lang_support"=>"", "desc"=>"Courier is a monospaced font that is widely used for programming and technical documents."],
			'courierb'=>["name"=>'Courier Bold', "lang_support"=>"", "desc"=>"Courier Bold is a bold version of the Courier font."],
			'courieri'=>["name"=>'Courier Italic', "lang_support"=>"", "desc"=>"Courier Italic is an italic version of the Courier font."],
			'courierbi'=>["name"=>'Courier Bold Italic', "lang_support"=>"", "desc"=>"Courier Bold Italic is a bold italic version of the Courier font."],
			'dejavusans'=>["name"=>'DejaVu Sans', "lang_support"=>"", "desc"=>"DejaVu Sans is a font family based on the Bitstream Vera Fonts with a wider range of characters."],
			'dejavusansb'=>["name"=>'DejaVu Sans Bold', "lang_support"=>"", "desc"=>"DejaVu Sans Bold is a bold version of the DejaVu Sans font."],
			'dejavusansi'=>["name"=>'DejaVu Sans Italic', "lang_support"=>"", "desc"=>"DejaVu Sans Italic is an italic version of the DejaVu Sans font."],
			'dejavusansbi'=>["name"=>'DejaVu Sans Bold Italic', "lang_support"=>"", "desc"=>"DejaVu Sans Bold Italic is a bold italic version of the DejaVu Sans font."],
			'dejavusanscondensed'=>["name"=>'DejaVu Sans Condensed', "lang_support"=>"", "desc"=>"DejaVu Sans Condensed is a font family based on the Bitstream Vera Fonts with a wider range of characters."],
			'dejavusanscondensedb'=>["name"=>'DejaVu Sans Condensed Bold', "lang_support"=>"", "desc"=>"DejaVu Sans Condensed Bold is a bold version of the DejaVu Sans Condensed font."],
			'dejavusanscondensedi'=>["name"=>'DejaVu Sans Condensed Italic', "lang_support"=>"", "desc"=>"DejaVu Sans Condensed Italic is an italic version of the DejaVu Sans Condensed font."],
			'dejavusanscondensedbi'=>["name"=>'DejaVu Sans Condensed Bold Italic', "lang_support"=>"", "desc"=>"DejaVu Sans Condensed Bold Italic is a bold italic version of the DejaVu Sans Condensed font."],
			'dejavusansextralight'=>["name"=>'DejaVu Sans ExtraLight', "lang_support"=>"", "desc"=>"DejaVu Sans ExtraLight is a font family based on the Bitstream Vera Fonts with a wider range of characters."],
			'dejavusansi'=>["name"=>'DejaVu Sans ExtraLight Italic', "lang_support"=>"", "desc"=>"DejaVu Sans ExtraLight Italic is an italic version of the DejaVu Sans ExtraLight font."],
			'dejavusansmono'=>["name"=>'DejaVu Sans Mono', "lang_support"=>"", "desc"=>"DejaVu Sans Mono is a font family based on the Bitstream Vera Fonts with a wider range of characters."],
			'dejavusansmonob'=>["name"=>'DejaVu Sans Mono Bold', "lang_support"=>"", "desc"=>"DejaVu Sans Mono Bold is a bold version of the DejaVu Sans Mono font."],
			'dejavusansmonoi'=>["name"=>'DejaVu Sans Mono Italic', "lang_support"=>"", "desc"=>"DejaVu Sans Mono Italic is an italic version of the DejaVu Sans Mono font."],
			'dejavusansmonobi'=>["name"=>'DejaVu Sans Mono Bold Italic', "lang_support"=>"", "desc"=>"DejaVu Sans Mono Bold Italic is a bold italic version of the DejaVu Sans Mono font."],
			'dejavuserif'=>["name"=>'DejaVu Serif', "lang_support"=>"", "desc"=>"DejaVu Serif is a font family based on the Bitstream Vera Fonts with a wider range of characters."],
			'dejavuserifcondensed'=>["name"=>'DejaVu Serif Condensed', "lang_support"=>"", "desc"=>"DejaVu Serif Condensed is a font family based on the Bitstream Vera Fonts with a wider range of characters."],
			'dejavuserifmono'=>["name"=>'DejaVu Serif Mono', "lang_support"=>"", "desc"=>"DejaVu Serif Mono is a font family based on the Bitstream Vera Fonts with a wider range of characters."],
			'freemono'=>["name"=>'FreeMono', "lang_support"=>"", "desc"=>"FreeMono is a monospaced font that is widely used for programming and technical documents."],
			'freemonob'=>["name"=>'FreeMono Bold', "lang_support"=>"", "desc"=>"FreeMono Bold is a bold version of the FreeMono font."],
			'freemonoi'=>["name"=>'FreeMono Italic', "lang_support"=>"", "desc"=>"FreeMono Italic is an italic version of the FreeMono font."],
			'freemonobi'=>["name"=>'FreeMono Bold Italic', "lang_support"=>"", "desc"=>"FreeMono Bold Italic is a bold italic version of the FreeMono font."],
			'freesans'=>["name"=>'FreeSans', "lang_support"=>"", "desc"=>"FreeSans is a sans-serif font that is widely used for general-purpose documents."],
			'freesansb'=>["name"=>'FreeSans Bold', "lang_support"=>"", "desc"=>"FreeSans Bold is a bold version of the FreeSans font."],
			'freesansi'=>["name"=>'FreeSans Italic', "lang_support"=>"", "desc"=>"FreeSans Italic is an italic version of the FreeSans font."],
			'freesansbi'=>["name"=>'FreeSans Bold Italic', "lang_support"=>"", "desc"=>"FreeSans Bold Italic is a bold italic version of the FreeSans font."],
			'freeserif'=>["name"=>'FreeSerif', "lang_support"=>"", "desc"=>"FreeSerif is a serif font that is widely used for general-purpose documents."],
			'freeserifb'=>["name"=>'FreeSerif Bold', "lang_support"=>"", "desc"=>"FreeSerif Bold is a bold version of the FreeSerif font."],
			'freeserifi'=>["name"=>'FreeSerif Italic', "lang_support"=>"", "desc"=>"FreeSerif Italic is an italic version of the FreeSerif font."],
			'freeserifbi'=>["name"=>'FreeSerif Bold Italic', "lang_support"=>"", "desc"=>"FreeSerif Bold Italic is a bold italic version of the FreeSerif font."],
			'helvetica'=>["name"=>'Helvetica', "lang_support"=>"", "desc"=>"Helvetica is a widely used sans-serif font that is known for its clean and modern appearance."],
			'helveticab'=>["name"=>'Helvetica Bold', "lang_support"=>"", "desc"=>"Helvetica Bold is a bold version of the Helvetica font."],
			'helveticai'=>["name"=>'Helvetica Italic', "lang_support"=>"", "desc"=>"Helvetica Italic is an italic version of the Helvetica font."],
			'helveticabi'=>["name"=>'Helvetica Bold Italic', "lang_support"=>"", "desc"=>"Helvetica Bold Italic is a bold italic version of the Helvetica font."],
			'hysmyeongjostdmedium'=>["name"=>'HYSMyeongJoStd-Medium', "lang_support"=>"ko", "desc"=>"HYSMyeongJoStd-Medium is a font family that supports Korean characters."],
			'kozgopromedium'=>["name"=>'KoZGoPro-Medium', "lang_support"=>"ko", "desc"=>"KoZGoPro-Medium is a font family that supports Korean characters."],
			'kozminproregular'=>["name"=>'KoZMinPro-Regular', "lang_support"=>"ja", "desc"=>"KoZMinPro-Regular is a font family that supports Japanese characters."],
			'msungstdlight'=>["name"=>'MyeongJoStd-Light', "lang_support"=>"ko", "desc"=>"MyeongJoStd-Light is a font family that supports Korean characters."],
			'pdfacourier'=>["name"=>'PDFa Courier', "lang_support"=>"", "desc"=>"PDFa Courier is a monospaced font that is widely used for programming and technical documents."],
			'pdfacourierb'=>["name"=>'PDFa Courier Bold', "lang_support"=>"", "desc"=>"PDFa Courier Bold is a bold version of the PDFa Courier font."],
			'pdfacourieri'=>["name"=>'PDFa Courier Italic', "lang_support"=>"", "desc"=>"PDFa Courier Italic is an italic version of the PDFa Courier font."],
			'pdfacourierbi'=>["name"=>'PDFa Courier Bold Italic', "lang_support"=>"", "desc"=>"PDFa Courier Bold Italic is a bold italic version of the PDFa Courier font."],
			'pdfahelvetica'=>["name"=>'PDFa Helvetica', "lang_support"=>"", "desc"=>"PDFa Helvetica is a widely used sans-serif font that is known for its clean and modern appearance."],
			'pdfahelveticab'=>["name"=>'PDFa Helvetica Bold', "lang_support"=>"", "desc"=>"PDFa Helvetica Bold is a bold version of the PDFa Helvetica font."],
			'pdfahelveticai'=>["name"=>'PDFa Helvetica Italic', "lang_support"=>"", "desc"=>"PDFa Helvetica Italic is an italic version of the PDFa Helvetica font."],
			'pdfahelveticabi'=>["name"=>'PDFa Helvetica Bold Italic', "lang_support"=>"", "desc"=>"PDFa Helvetica Bold Italic is a bold italic version of the PDFa Helvetica font."],
			'pdfasymbol'=>["name"=>'PDFa Symbol', "lang_support"=>"", "desc"=>"PDFa Symbol is a font family that supports mathematical symbols and special characters."],
			'pdfatimes'=>["name"=>'PDFa Times', "lang_support"=>"", "desc"=>"PDFa Times is a serif font that is widely used for general-purpose documents."],
			'pdfatimesb'=>["name"=>'PDFa Times Bold', "lang_support"=>"", "desc"=>"PDFa Times Bold is a bold version of the PDFa Times font."],
			'pdfatimesi'=>["name"=>'PDFa Times Italic', "lang_support"=>"", "desc"=>"PDFa Times Italic is an italic version of the PDFa Times font."],
			'pdfatimesbi'=>["name"=>'PDFa Times Bold Italic', "lang_support"=>"", "desc"=>"PDFa Times Bold Italic is a bold italic version of the PDFa Times font."],
			'pdfazapfdingbats'=>["name"=>'PDFa ZapfDingbats', "lang_support"=>"", "desc"=>"PDFa ZapfDingbats is a font family that supports various symbols and icons."],
			'roboto'=>["name"=>'Roboto', "lang_support"=>"", "desc"=>"Roboto is a sans-serif font that is widely used for general-purpose documents."],
			'stsongstdlight'=>["name"=>'STSongStd-Light', "lang_support"=>"zh", "desc"=>"STSongStd-Light is a font family that supports Simplified Chinese characters."],
			'symbol'=>["name"=>'Symbol', "lang_support"=>"", "desc"=>"Symbol is a font family that supports mathematical symbols and special characters."],
			'times'=>["name"=>'Times', "lang_support"=>"", "desc"=>"Times is a serif font that is widely used for general-purpose documents."],
			'timesb'=>["name"=>'Times Bold', "lang_support"=>"", "desc"=>"Times Bold is a bold version of the Times font."],
			'timesi'=>["name"=>'Times Italic', "lang_support"=>"", "desc"=>"Times Italic is an italic version of the Times font."],
			'timesbi'=>["name"=>'Times Bold Italic', "lang_support"=>"", "desc"=>"Times Bold Italic is a bold italic version of the Times font."],
			'uni2cid_ac15'=>["name"=>'Uni2CID_AC15', "lang_support"=>"", "desc"=>"Uni2CID_AC15 is a font family that supports various characters and symbols."],
			'uni2cid_ag15'=>["name"=>'Uni2CID_AG15', "lang_support"=>"", "desc"=>"Uni2CID_AG15 is a font family that supports various characters and symbols."],
			'uni2cid_aj16'=>["name"=>'Uni2CID_AJ16', "lang_support"=>"", "desc"=>"Uni2CID_AJ16 is a font family that supports various characters and symbols."],
			'uni2cid_ak12'=>["name"=>'Uni2CID_AK12', "lang_support"=>"", "desc"=>"Uni2CID_AK12 is a font family that supports various characters and symbols."],
			'zapfdingbats'=>["name"=>'ZapfDingbats', "lang_support"=>"", "desc"=>"ZapfDingbats is a font family that supports various symbols and icons."],
		];
		// sort by name
		ksort($infos);
		return $infos;
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

	private function prepareOutputBuffer() {
		if ($this->filemode != "F" && ob_get_length() !== false) ob_clean();
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
		$pdf = new Fpdi($this->orientation, PDF_UNIT, $format, true, 'UTF-8', false, false);
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

		$pdf = new Fpdi($this->orientation, PDF_UNIT, $format, true, 'UTF-8', false, false);
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
				if (is_array($finfo) && count($finfo) > 1) {
					$bg_size_w = $pdf->pixelsToUnits($finfo[0]);
					$bg_size_h = $pdf->pixelsToUnits($finfo[1]);
				}
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

		// Build QR code inline - with fallback for FPDI versions that don't have serializeTCPDFtagParameters
		// This can happen when another plugin loads setasign/fpdi via composer which overrides our bundled FPDI
		$qr_code_inline = '';
		$use_qrcode_fallback = false;
		if (method_exists($pdf, 'serializeTCPDFtagParameters')) {
			$qr_params = $pdf->serializeTCPDFtagParameters([$this->qr['text'], $this->qr['type'], '', '', $this->qr['size']['width'], $this->qr['size']['height'], $this->qr['style'], $this->qr['align']]);
			$qr_code_inline = '<tcpdf method="write2DBarcode" params="'.$qr_params.'" />';
		} else {
			// Fallback: replace {QRCODE_INLINE} with marker, render QR code after HTML
			$use_qrcode_fallback = true;
		}
		//$pdf->writeHTML(print_r($this->qr, true));

		foreach($page_parts as $p) {

			// Check if this part contains {QRCODE_INLINE} and we need fallback
			$needs_qr_fallback = $use_qrcode_fallback && strpos($p, '{QRCODE_INLINE}') !== false;
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
					} else {
						$pdf->writeHTML($teil, false, false, true, false, '');
						// Fallback: render QR code after HTML if inline method not available
						if ($needs_qr_fallback && !empty($this->qr['text'])) {
							$qr = $this->getDefaultQRValues();
							$pdf->write2DBarcode($this->qr['text'], $this->qr['type'], $this->qr['pos']['x'], $this->qr['pos']['y'], $this->qr['size']['width'], $this->qr['size']['height'], $qr['style'], $qr['align']);
							$needs_qr_fallback = false; // Only render once
						}
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