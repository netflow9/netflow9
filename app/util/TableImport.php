<?php

/*
    reads a CSV or Excel (.xlsx) file for the data table import (DatatableController::import) and guesses its columns:
	whether the first row is a header (else the columns are named Column 1, 2, ...),
	each column's type from its values (number, date, datetime, checkbox, email, textarea, else text)
	and a key made from its name.

    normalize() turns a file value into what the column's Field casts:
	- numbers with the file's decimal mark,
	- dates in the file's day / month order -> ISO 8601,
	- yes / no -> booleans).

    $t = new \util\TableImport($path, 'xlsx');
    $rows = $t->rows($sheet);
    $header = TableImport::hasHeader($rows);
    $cols = TableImport::guess($rows, $header);
    .xlsx is read with ZipArchive + XMLReader (first sheet unless chosen); the old binary .xls is not supported
*/
namespace util;

class TableImport {
    //limits of an imported file
    const MAX_BYTES = 10 * 1024 * 1024;
    const MAX_ROWS = 20000;
    const MAX_COLS = 100;

    //the file types read: extension => label
    const TYPES = [ 'csv' => 'CSV', 'txt' => 'CSV', 'tsv' => 'CSV', 'xlsx' => 'Excel' ];

    //rows looked at to decide whether the first row is a header
    const SAMPLE = 50;

    //values read as checkbox (lower case)
    const TRUE_WORDS = [ 'true', 'yes', 'da', 'y' ];
    const FALSE_WORDS = [ 'false', 'no', 'nu', 'n' ];

    //textarea: a value longer than this, or with a line break
    const LONG_TEXT = 200;

    //the keys a column cannot have (as util\Validator fieldKeyNotReserved)
    const RESERVED_KEYS = [ '_id', 'aid', 'created_at', 'updated_at' ];

    //date formats: day / month order is decided per column (dmy first, else mdy)
    const DATE_RE = [
	'ymd' => '/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/',
	'dmy' => '/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/',
    ];

    private $path;
    private $ext;

    public function __construct($path, $ext) {
	$this->path = $path;
	$this->ext = strtolower($ext);
    }

    //the sheet names (xlsx), [] for CSV
    public function sheets() {
	if ($this->ext !== 'xlsx')
	    return [];
	return array_keys($this->xlsxSheets($this->openZip()));
    }

    //the rows of the file (sheet $sheet of an xlsx, default the first): lists of scalar values, empty rows removed, trailing empty columns removed,
    //every row as long as the longest; \RuntimeException with a message for the user when the file cannot be read
    public function rows($sheet = null) {
	$rows = $this->ext === 'xlsx' ? $this->xlsxRows($sheet) : $this->csvRows();

	$width = 0;
	$kept = [];
	foreach($rows as $row) {
	    while ($row && self::isBlank(end($row)))
		array_pop($row);
	    if (!$row)
		continue;
	    $kept[] = $row;
	    $width = max($width, count($row));
	    if (count($kept) > self::MAX_ROWS + 1)
		throw new \RuntimeException('The file has more than ' . self::MAX_ROWS . ' rows');
	}
	if ($width > self::MAX_COLS)
	    throw new \RuntimeException('The file has more than ' . self::MAX_COLS . ' columns');
	foreach($kept as &$row)
	    $row = array_pad($row, $width, '');
	return $kept;
    }

    // ------------------------------------------------------------------------------------
    //guessing

    //whether the first row looks like a header: every cell is a distinct, non-empty text that is not a number, date or yes / no, and below it either
    //some column holds another kind of values, or its names do not appear in their columns
    public static function hasHeader(array $rows) {
	if (count($rows) < 2)
	    return false;
	$first = $rows[0];
	$seen = [];
	foreach($first as $v) {
	    $text = mb_strtolower(trim((string)$v));
	    if ($text === '' || isset($seen[$text]) || !is_string($v) || self::kindOf($v) !== 'text')
		return false;
	    $seen[$text] = true;
	}

	$sample = array_slice($rows, 1, self::SAMPLE);
	foreach(array_keys($first) as $c) {
	    $column = array_column($sample, $c);
	    if (self::typeOf($column)['type'] !== 'text')
		return true;
	}
	foreach($first as $c => $name) {
	    foreach($sample as $row) {
		if (mb_strtolower(trim((string)$row[$c])) === mb_strtolower(trim($name)))
		    return false;
	    }
	}
	return true;
    }

    //the columns: [ [ label, key, type, format, samples ] ]; $header: the first row names them (and is not data)
    public static function guess(array $rows, $header) {
	$names = $header && $rows ? $rows[0] : [];
	$data = $header ? array_slice($rows, 1) : $rows;
	$width = $rows ? count($rows[0]) : 0;

	$columns = [];
	$keys = [];
	for ($c = 0; $c < $width; $c++) {
	    $label = trim((string)($names[$c] ?? ''));
	    if ($label === '')
		$label = 'Column ' . ($c + 1);
	    $values = array_column($data, $c);
	    $type = self::typeOf($values);
	    $key = self::keyFor($label, $c, $keys);
	    $keys[$key] = true;
	    $samples = [];
	    foreach($values as $v) {
		if (!self::isBlank($v))
		    $samples[] = mb_substr(self::text($v), 0, 40);
		if (count($samples) == 3)
		    break;
	    }
	    $columns[] = [ 'label' => mb_substr($label, 0, 100), 'key' => $key, 'type' => $type['type'], 'format' => $type['format'], 'samples' => $samples ];
	}
	return $columns;
    }

    //the type of a column from its values (empty values ignored): [ type, format ]; format: the decimal mark of numbers (dot / comma), the day order of
    //dates (ymd / dmy / mdy)
    public static function typeOf(array $values) {
	$values = array_values(array_filter($values, fn($v) => !self::isBlank($v)));
	if (!$values)
	    return [ 'type' => 'text', 'format' => null ];

	$all = function($test) use ($values) {
	    foreach($values as $v) {
		if (!$test($v))
		    return false;
	    }
	    return true;
	};

	if ($all(fn($v) => is_bool($v) || in_array(mb_strtolower(trim((string)$v)), array_merge(self::TRUE_WORDS, self::FALSE_WORDS), true)))
	    return [ 'type' => 'checkbox', 'format' => null ];

	foreach([ 'dot', 'comma' ] as $mark) {
	    if ($all(fn($v) => self::number($v, $mark) !== null))
		return [ 'type' => 'number', 'format' => $mark ];
	}

	foreach([ 'ymd', 'dmy', 'mdy' ] as $order) {
	    if ($all(fn($v) => self::date($v, $order) !== null)) {
		$withTime = !$all(fn($v) => preg_match('/^\S+$/', trim((string)$v)) || preg_match('/ 00:00(:00)?$/', trim((string)$v)));
		return [ 'type' => $withTime ? 'datetime' : 'date', 'format' => $order ];
	    }
	}

	if ($all(fn($v) => filter_var(trim((string)$v), FILTER_VALIDATE_EMAIL) !== false))
	    return [ 'type' => 'email', 'format' => null ];

	foreach($values as $v) {
	    $text = (string)$v;
	    if (mb_strlen($text) > self::LONG_TEXT || str_contains($text, "\n"))
		return [ 'type' => 'textarea', 'format' => null ];
	}
	return [ 'type' => 'text', 'format' => null ];
    }

    //the format of $type that reads the most of $values (a type chosen in the preview, not the guessed one): number dot / comma, date ymd / dmy / mdy
    public static function formatFor(array $values, $type) {
	$formats = [ 'number' => [ 'dot', 'comma' ], 'date' => [ 'ymd', 'dmy', 'mdy' ], 'datetime' => [ 'ymd', 'dmy', 'mdy' ] ][$type] ?? [ null ];
	$best = $formats[0];
	$bestCount = -1;
	foreach($formats as $f) {
	    $count = count(array_filter($values, fn($v) => !self::isBlank($v) && self::normalize($v, $type, $f) !== false));
	    if ($count > $bestCount) {
		$best = $f;
		$bestCount = $count;
	    }
	}
	return $best;
    }

    //a value as the column's Field casts it ($format from typeOf); null = empty; false (strict) = cannot be read as this type
    public static function normalize($value, $type, $format = null) {
	if (self::isBlank($value))
	    return null;
	switch($type) {
	    case 'number':
		$n = self::number($value, $format ?: 'dot');
		return $n === null ? false : $n;
	    case 'date':
	    case 'datetime':
		$d = self::date($value, $format ?: 'ymd');
		if ($d === null)
		    return false;
		return $type === 'date' ? substr($d, 0, 10) : $d;
	    case 'checkbox':
	    case 'switch':
		if (is_bool($value))
		    return $value;
		$word = mb_strtolower(trim((string)$value));
		if (in_array($word, array_merge(self::TRUE_WORDS, [ '1' ]), true))
		    return true;
		if (in_array($word, array_merge(self::FALSE_WORDS, [ '0' ]), true))
		    return false;
		return null;
	    case 'email':
		return trim((string)$value);
	}
	return self::text($value);
    }

    //a column key from its name: letters (diacritics removed), digits, underscore; unique in $used; not reserved
    public static function keyFor($label, $index, array $used) {
	$text = strtr($label, [ 'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T' ]);
	$ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
	$key = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $ascii !== false ? $ascii : $text), '_'));
	if ($key === '')
	    $key = 'column_' . ($index + 1);
	if (ctype_digit($key[0]))
	    $key = 'c_' . $key;
	$key = substr($key, 0, 50);
	$base = $key;
	for ($n = 2; isset($used[$key]) || in_array($key, self::RESERVED_KEYS, true); $n++)
	    $key = $base . '_' . $n;
	return $key;
    }

    // ------------------------------------------------------------------------------------
    //values

    public static function isBlank($v) {
	return $v === null || (is_string($v) && trim($v) === '');
    }

    //a value as text (numbers without float noise)
    public static function text($v) {
	if (is_bool($v))
	    return $v ? 'TRUE' : 'FALSE';
	if (is_float($v))
	    return rtrim(rtrim(sprintf('%.15F', (float)sprintf('%.15g', $v)), '0'), '.');
	return (string)$v;
    }

    //what kind a single value looks like: number, date, bool, text (header detection)
    private static function kindOf($v) {
	if (is_bool($v) || in_array(mb_strtolower(trim((string)$v)), array_merge(self::TRUE_WORDS, self::FALSE_WORDS), true))
	    return 'bool';
	if (self::number($v, 'dot') !== null || self::number($v, 'comma') !== null)
	    return 'number';
	if (self::date($v, 'ymd') !== null || self::date($v, 'dmy') !== null || self::date($v, 'mdy') !== null)
	    return 'date';
	return 'text';
    }

    //a number from $v with decimal mark $mark (dot: 1,234.5; comma: 1.234,5), null when it is not one; numbers with leading zeros (codes) are not
    public static function number($v, $mark) {
	if (is_int($v) || is_float($v))
	    return $v;
	$s = str_replace([ ' ', "\u{00A0}" ], '', trim((string)$v));
	[ $dec, $thou ] = $mark === 'comma' ? [ ',', '.' ] : [ '.', ',' ];
	$re = '/^[+-]?(\d{1,3}(' . preg_quote($thou, '/') . '\d{3})+|\d+)(' . preg_quote($dec, '/') . '\d+)?$/';
	if (!preg_match($re, $s))
	    return null;
	$digits = ltrim($s, '+-');
	if (strlen($digits) > 1 && $digits[0] === '0' && $digits[1] !== $dec)
	    return null;
	$s = str_replace([ $thou, $dec ], [ '', '.' ], $s);
	return str_contains($s, '.') ? (float)$s : (abs((float)$s) < PHP_INT_MAX ? (int)$s : (float)$s);
    }

    //a date / date and time from $v in day order $order: 'Y-m-d' or 'Y-m-d H:i:s'; null when it is not one
    public static function date($v, $order) {
	if (!is_string($v))
	    return null;
	$s = trim($v);
	$re = $order === 'ymd' ? self::DATE_RE['ymd'] : self::DATE_RE['dmy'];
	if (!preg_match($re, $s, $m))
	    return null;
	switch($order) {
	    case 'ymd': [ $y, $mo, $d ] = [ $m[1], $m[2], $m[3] ]; break;
	    case 'dmy': [ $d, $mo, $y ] = [ $m[1], $m[2], $m[3] ]; break;
	    default:    [ $mo, $d, $y ] = [ $m[1], $m[2], $m[3] ]; break;
	}
	if (!checkdate((int)$mo, (int)$d, (int)$y))
	    return null;
	$out = sprintf('%04d-%02d-%02d', $y, $mo, $d);
	if (isset($m[4]) && $m[4] !== '') {
	    if ((int)$m[4] > 23 || (int)$m[5] > 59 || (int)($m[6] ?? 0) > 59)
		return null;
	    $out .= sprintf(' %02d:%02d:%02d', $m[4], $m[5], (int)($m[6] ?? 0));
	}
	return $out;
    }

    // ------------------------------------------------------------------------------------
    //CSV

    //UTF-8 (BOM removed), UTF-16 with BOM, else Windows-1250 (Romanian); the separator (, ; tab |) is the one giving the same count on the first lines
    private function csvRows() {
	$text = file_get_contents($this->path);
	if ($text === false)
	    throw new \RuntimeException('The file cannot be read');
	if (str_starts_with($text, "\xEF\xBB\xBF"))
	    $text = substr($text, 3);
	elseif (str_starts_with($text, "\xFF\xFE"))
	    $text = mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16LE');
	elseif (str_starts_with($text, "\xFE\xFF"))
	    $text = mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16BE');
	elseif (!mb_check_encoding($text, 'UTF-8'))
	    $text = (string)@iconv('Windows-1250', 'UTF-8//IGNORE', $text);
	if (str_contains($text, "\0"))
	    throw new \RuntimeException('This is not a text (CSV) file');

	$delimiter = self::csvDelimiter($text);
	$fh = fopen('php://temp', 'w+');
	fwrite($fh, $text);
	rewind($fh);
	$rows = [];
	while (($row = fgetcsv($fh, null, $delimiter, '"', '')) !== false) {
	    if ($row === [ null ])
		continue;
	    $rows[] = $row;
	    if (count($rows) > self::MAX_ROWS + 1)
		break;
	}
	fclose($fh);
	return $rows;
    }

    private static function csvDelimiter($text) {
	$lines = array_slice(array_filter(preg_split('/\r\n|\n|\r/', $text, 12), fn($l) => trim($l) !== ''), 0, 10);
	$best = ',';
	$bestScore = 0;
	foreach([ ',', ';', "\t", '|' ] as $d) {
	    $counts = array_map(fn($l) => count(str_getcsv($l, $d, '"', '')), $lines);
	    if (!$counts || min($counts) < 2)
		continue;
	    //the same number of fields on most lines, and more fields are better
	    $common = array_count_values($counts);
	    arsort($common);
	    $fields = array_key_first($common);
	    $score = reset($common) * 100 + $fields;
	    if ($score > $bestScore) {
		$best = $d;
		$bestScore = $score;
	    }
	}
	return $best;
    }

    // ------------------------------------------------------------------------------------
    //Excel (.xlsx)

    private function openZip() {
	$zip = new \ZipArchive();
	if ($zip->open($this->path, \ZipArchive::RDONLY) !== true || $zip->locateName('xl/workbook.xml') === false)
	    throw new \RuntimeException('This is not an Excel (.xlsx) file');
	return $zip;
    }

    private static function xml($zip, $name) {
	$text = $zip->getFromName($name);
	if ($text === false)
	    return null;
	$xml = @simplexml_load_string($text, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
	return $xml === false ? null : $xml;
    }

    //[ sheet name => path in the zip ]
    private function xlsxSheets($zip) {
	$wb = self::xml($zip, 'xl/workbook.xml');
	$rels = self::xml($zip, 'xl/_rels/workbook.xml.rels');
	if (!$wb || !$rels)
	    throw new \RuntimeException('The Excel file cannot be read');
	$targets = [];
	foreach($rels->Relationship as $r)
	    $targets[(string)$r['Id']] = (string)$r['Target'];
	$sheets = [];
	foreach($wb->sheets->sheet as $s) {
	    $rid = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
	    $target = $targets[$rid] ?? null;
	    if ($target === null)
		continue;
	    $sheets[(string)$s['name']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
	}
	if (!$sheets)
	    throw new \RuntimeException('The Excel file has no sheets');
	return $sheets;
    }

    private function xlsxRows($sheet) {
	$zip = $this->openZip();
	$sheets = $this->xlsxSheets($zip);
	$path = ($sheet !== null && isset($sheets[$sheet])) ? $sheets[$sheet] : reset($sheets);

	$wb = self::xml($zip, 'xl/workbook.xml');
	$date1904 = in_array(strtolower((string)($wb->workbookPr['date1904'] ?? '')), [ '1', 'true' ], true);

	$strings = [];
	if ($sst = self::xml($zip, 'xl/sharedStrings.xml')) {
	    foreach($sst->si as $si) {
		if (isset($si->t)) {
		    $strings[] = (string)$si->t;
		} else {
		    $text = '';
		    foreach($si->r as $r)
			$text .= (string)$r->t;
		    $strings[] = $text;
		}
	    }
	}
	$dateStyles = self::xlsxDateStyles($zip);

	$xml = $zip->getFromName($path);
	$zip->close();
	if ($xml === false)
	    throw new \RuntimeException('The Excel sheet cannot be read');

	$reader = new \XMLReader();
	$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
	$rows = [];
	while ($reader->read()) {
	    if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'row')
		continue;
	    $rowXml = @simplexml_load_string($reader->readOuterXml());
	    if ($rowXml === false)
		continue;
	    $row = [];
	    foreach($rowXml->c as $cell) {
		$col = count($row);
		if (preg_match('/^([A-Z]+)/', (string)$cell['r'], $m))
		    $col = self::columnIndex($m[1]);
		while (count($row) < $col)
		    $row[] = '';
		$row[$col] = self::xlsxValue($cell, $strings, $dateStyles, $date1904);
	    }
	    $rows[] = $row;
	    if (count($rows) > self::MAX_ROWS + 1)
		break;
	}
	$reader->close();
	return $rows;
    }

    //A -> 0, Z -> 25, AA -> 26
    private static function columnIndex($letters) {
	$n = 0;
	foreach(str_split($letters) as $ch)
	    $n = $n * 26 + (ord($ch) - 64);
	return $n - 1;
    }

    //[ style index => true ] of the cell styles that show dates (built-in date formats, or custom formats with day / month / year / hour)
    private static function xlsxDateStyles($zip) {
	$styles = self::xml($zip, 'xl/styles.xml');
	if (!$styles)
	    return [];
	$custom = [];
	if (isset($styles->numFmts)) {
	    foreach($styles->numFmts->numFmt as $f) {
		$code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', (string)$f['formatCode']);
		$custom[(int)$f['numFmtId']] = (bool)preg_match('/[dmyhs]/i', $code) && !preg_match('/^general$/i', $code);
	    }
	}
	$builtin = array_merge(range(14, 22), range(45, 47));
	$dates = [];
	$i = 0;
	foreach(($styles->cellXfs->xf ?? []) as $xf) {
	    $id = (int)$xf['numFmtId'];
	    if (in_array($id, $builtin, true) || !empty($custom[$id]))
		$dates[$i] = true;
	    $i++;
	}
	return $dates;
    }

    private static function xlsxValue($cell, array $strings, array $dateStyles, $date1904) {
	$t = (string)$cell['t'];
	switch($t) {
	    case 's':
		return $strings[(int)$cell->v] ?? '';
	    case 'inlineStr':
		if (isset($cell->is->t))
		    return (string)$cell->is->t;
		$text = '';
		foreach(($cell->is->r ?? []) as $r)
		    $text .= (string)$r->t;
		return $text;
	    case 'b':
		return (string)$cell->v === '1';
	    case 'str':
		return (string)$cell->v;
	    case 'e':
		return '';
	}
	if (!isset($cell->v) || (string)$cell->v === '')
	    return '';
	$v = (string)$cell->v;
	if (!is_numeric($v))
	    return $v;
	$n = $v + 0;
	if (isset($dateStyles[(int)$cell['s']]))
	    return self::excelDate((float)$n, $date1904);
	return is_float($n) ? (float)sprintf('%.15g', $n) : $n;
    }

    //an Excel serial date as 'Y-m-d' (whole days) or 'Y-m-d H:i:s'
    private static function excelDate($serial, $date1904) {
	$base = new \DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-30', new \DateTimeZone('UTC'));
	$seconds = (int)round($serial * 86400);
	$d = $base->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds');
	return $seconds % 86400 === 0 ? $d->format('Y-m-d') : $d->format('Y-m-d H:i:s');
    }
}
