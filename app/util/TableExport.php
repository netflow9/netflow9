<?php

namespace util;

//CLD: 2026-11-04T10:00:00+02:00: writes a table as an Excel (.xlsx) file, one sheet (the data table export, DatatableController::export; the reverse of util\TableImport):
//
//    $x = new \util\TableExport('customers', [ [ 'label' => 'Name' ], [ 'label' => 'Born', 'format' => 'date' ] ]);
//    $x->addRow([ 'Ann', new \DateTimeImmutable('2001-02-03') ]);
//    $path = $x->finish();    //a temporary file, for the caller to send and remove
//
//Cells by PHP type: bool, int / float, \DateTimeInterface (the column's `format`: date | datetime, as shown in Excel by the user's locale), everything else text; null is an empty cell.
//The header row is frozen, with the filter buttons. Text cells are inline strings, never formulas: a value that starts with = stays text.
//The rows go to a temporary file as they come, so the size of the table does not matter for memory.
class TableExport {

    //an Excel sheet's limits: the rows, with the header; the characters (UTF-16 units) of a cell; the characters of a sheet name
    const MAX_ROWS = 1048576;
    const MAX_CELL = 32767;
    const MAX_SHEET_NAME = 31;

    //cell styles, by their order in styles.xml
    const STYLE_HEADER = 1;
    const STYLE_DATE = 2;
    const STYLE_DATETIME = 3;

    //Excel's day number of 1970-01-01
    const EPOCH_DAYS = 25569;
    //before 1 March 1900 Excel's day numbers are off by one (its 29 February 1900): such a date is written as text
    const FIRST_DAY = 61;

    const MAX_WIDTH = 60;

    private string $sheet;
    private array $columns;
    private array $widths = [];
    private string $dataPath;
    private $data;
    private int $nRows = 0;

    public function __construct($sheet, array $columns) {
	$this->sheet = self::sheetName($sheet);
	$this->columns = array_values($columns);
	$this->dataPath = tempnam(sys_get_temp_dir(), 'nfx');
	$this->data = fopen($this->dataPath, 'wb');

	$header = [];
	foreach($this->columns as $c)
	    $header[] = self::text((string)($c['label'] ?? ''));
	$this->writeRow($header, self::STYLE_HEADER);
    }

    //the rows written so far, without the header
    public function count() {
	return $this->nRows - 1;
    }

    //$cells: the values of a row, in the columns' order
    public function addRow(array $cells) {
	$this->writeRow(array_values($cells));
    }

    //the path of the finished file (a temporary one: the caller removes it)
    public function finish() {
	fclose($this->data);

	$sheetPath = tempnam(sys_get_temp_dir(), 'nfx');
	$out = fopen($sheetPath, 'wb');
	fwrite($out, $this->sheetHead());
	$in = fopen($this->dataPath, 'rb');
	stream_copy_to_stream($in, $out);
	fclose($in);
	fwrite($out, '</sheetData>' . $this->autoFilter() . '</worksheet>');
	fclose($out);
	@unlink($this->dataPath);

	$path = tempnam(sys_get_temp_dir(), 'nfx');
	$zip = new \ZipArchive();
	if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
	    @unlink($sheetPath);
	    throw new \RuntimeException('The Excel file could not be created');
	}
	$zip->addFromString('[Content_Types].xml', self::XML_HEAD . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
	    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
	    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
	    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
	    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
	$zip->addFromString('_rels/.rels', self::XML_HEAD . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
	    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
	$zip->addFromString('xl/workbook.xml', self::XML_HEAD . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
	    . '<sheets><sheet name="' . self::escape($this->sheet) . '" sheetId="1" r:id="rId1"/></sheets>' . $this->definedNames() . '</workbook>');
	$zip->addFromString('xl/_rels/workbook.xml.rels', self::XML_HEAD . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
	    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
	    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
	//the date formats are Excel's built-in 14 and 22: shown in the order of the user's locale
	$zip->addFromString('xl/styles.xml', self::XML_HEAD . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
	    . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
	    . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
	    . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
	    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
	    . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
	    . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
	    . '<xf numFmtId="14" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
	    . '<xf numFmtId="22" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs></styleSheet>');
	$zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');
	$ok = $zip->close();
	@unlink($sheetPath);
	if (!$ok)
	    throw new \RuntimeException('The Excel file could not be created');
	return $path;
    }

    const XML_HEAD = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

    private function writeRow(array $cells, $style = 0) {
	$this->nRows++;
	$r = $this->nRows;
	$xml = '<row r="' . $r . '">';
	foreach($cells as $i => $v) {
	    $ref = self::colName($i) . $r;
	    $xml .= $this->cell($ref, $i, $v, $style);
	}
	fwrite($this->data, $xml . '</row>');
    }

    private function cell($ref, $i, $v, $style) {
	if ($v === null)
	    return '';
	$s = $style ? ' s="' . $style . '"' : '';

	if (is_bool($v)) {
	    $this->fit($i, 5);
	    return '<c r="' . $ref . '"' . $s . ' t="b"><v>' . ($v ? 1 : 0) . '</v></c>';
	}
	if ((is_int($v) && abs($v) < 1e15) || (is_float($v) && is_finite($v))) {
	    $n = json_encode($v);
	    $this->fit($i, strlen($n));
	    return '<c r="' . $ref . '"' . $s . '><v>' . $n . '</v></c>';
	}
	if ($v instanceof \DateTimeInterface) {
	    $isDate = ($this->columns[$i]['format'] ?? '') === 'date';
	    $days = ($v->getTimestamp() + $v->getOffset()) / 86400 + self::EPOCH_DAYS;
	    if ($days >= self::FIRST_DAY) {
		$this->fit($i, $isDate ? 11 : 17);
		return '<c r="' . $ref . '" s="' . ($isDate ? self::STYLE_DATE : self::STYLE_DATETIME) . '"><v>' . json_encode($isDate ? floor($days) : $days) . '</v></c>';
	    }
	    $v = $v->format($isDate ? 'Y-m-d' : 'Y-m-d H:i:s');
	}

	//text, also a whole number with more digits than Excel keeps (15)
	$t = self::text(is_scalar($v) ? (string)$v : \util\JSON::encode($v));
	if ($t === '')
	    return '';
	foreach(explode("\n", $t) as $line)
	    $this->fit($i, mb_strlen($line));
	return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . self::escape($t) . '</t></is></c>';
    }

    //the column's width: its longest value so far
    private function fit($i, $length) {
	$this->widths[$i] = max($this->widths[$i] ?? 0, $length);
    }

    private function sheetHead() {
	$last = self::colName(max(count($this->columns), 1) - 1);
	$xml = self::XML_HEAD . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
	    . '<dimension ref="A1:' . $last . $this->nRows . '"/>'
	    . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
	    . '<sheetFormatPr defaultRowHeight="15"/>';
	if ($this->widths) {
	    $xml .= '<cols>';
	    foreach($this->columns as $i => $c) {
		$w = min(($this->widths[$i] ?? 0) + 4, self::MAX_WIDTH);
		$xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
	    }
	    $xml .= '</cols>';
	}
	return $xml . '<sheetData>';
    }

    private function filterRange() {
	return 'A1:' . self::colName(max(count($this->columns), 1) - 1) . $this->nRows;
    }

    private function autoFilter() {
	return '<autoFilter ref="' . $this->filterRange() . '"/>';
    }

    //Excel looks for the filter's range under this name
    private function definedNames() {
	$sheet = "'" . str_replace("'", "''", $this->sheet) . "'";
	[ $from, $to ] = explode(':', $this->filterRange());
	return '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">' . self::escape($sheet . '!' . self::absoluteRef($from) . ':' . self::absoluteRef($to)) . '</definedName></definedNames>';
    }

    //CLD: 2026-11-07T01:22:46+02:00: cell reference $ref (A1) as an absolute one ($A$1)
    private static function absoluteRef($ref) {
	return preg_replace('/^([A-Z]+)(\d+)$/', '\$$1\$$2', $ref);
    }

    //0 -> A, 26 -> AA
    public static function colName($i) {
	$name = '';
	for($i++; $i > 0; $i = intdiv($i - 1, 26))
	    $name = chr(65 + ($i - 1) % 26) . $name;
	return $name;
    }

    //what a sheet may be called
    public static function sheetName($name) {
	$name = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', mb_scrub((string)$name, 'UTF-8')), " '");
	return mb_substr($name, 0, self::MAX_SHEET_NAME) ?: 'Sheet1';
    }

    //text as a cell can hold it: valid UTF-8, without the control characters XML refuses, within Excel's limit
    public static function text($s) {
	$s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', mb_scrub((string)$s, 'UTF-8'));
	if (strlen((string)$s) <= self::MAX_CELL)
	    return (string)$s;
	$units = mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
	if (strlen($units) <= self::MAX_CELL * 2)
	    return $s;
	$units = substr($units, 0, self::MAX_CELL * 2);
	//not half of a character: its high surrogate (0xD800-0xDBFF, the second byte here) dropped
	if ((ord($units[-1]) & 0xFC) === 0xD8)
	    $units = substr($units, 0, -2);
	return mb_convert_encoding($units, 'UTF-8', 'UTF-16LE');
    }

    private static function escape($s) {
	return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public function __destruct() {
	if (is_resource($this->data))
	    fclose($this->data);
	if (is_file($this->dataPath))
	    @unlink($this->dataPath);
    }
}
