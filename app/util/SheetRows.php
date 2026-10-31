<?php

/*
    the rows and cells of a spreadsheet step, shared by Google Sheets (GoogleIntegration plugin) and Excel (MicrosoftIntegration
    plugin); were NodeAppGoogleSheets' own (columnName, headerOf, objectsOf, rowsOf, matrixFor, cellValue), which now call these
	columnName / columnIndex   A <-> 0, Z <-> 25, AA <-> 26
	headerOf                   the header of a first row (empty / repeated names: the column name)
	objectsOf                  rows of cells -> objects keyed by the header (empty rows left out)
	rowsOf                     the rows to write from a setting's value (an expression's value or JSON): lists of cells or objects
	matrixFor                  objects -> rows of cells in the order of a header (missing keys added to it, or left out)
	cellValue                  a value for a cell (lists / objects as JSON)
*/
namespace util;

class SheetRows {

    //the column name of position $i (0: A, 25: Z, 26: AA)
    public static function columnName($i) {
	$name = '';
	for ($n = (int)$i + 1; $n > 0; $n = intdiv($n - 1, 26))
	    $name = chr(65 + ($n - 1) % 26) . $name;
	return $name;
    }

    //the position of column $name (A: 0, Z: 25, AA: 26); -1 when it is not a column name
    public static function columnIndex($name) {
	$name = strtoupper(trim((string)$name));
	if (!preg_match('/^[A-Z]{1,3}$/', $name))
	    return -1;
	$n = 0;
	foreach(str_split($name) as $c)
	    $n = $n * 26 + (ord($c) - 64);
	return $n - 1;
    }

    //the header of a first row: the cells as text; an empty cell or a name used before: the column name (A, B, ...)
    public static function headerOf($row) {
	$header = [];
	foreach(array_values((array)$row) as $i => $cell) {
	    $name = trim(\util\Expression::toText($cell));
	    $header[] = $name === '' || in_array($name, $header, true) ? self::columnName($i) : $name;
	}
	return $header;
    }

    //rows of cells -> objects keyed by $header (missing cells: ''; cells past the header: by column name); empty rows left out
    public static function objectsOf(array $rows, array $header) {
	$objects = [];
	foreach($rows as $row) {
	    $row = array_values((array)$row);
	    if (!array_filter($row, fn($c) => $c !== '' && $c !== null))
		continue;
	    $o = [];
	    foreach($header as $i => $name)
		$o[$name] = $row[$i] ?? '';
	    for ($i = count($header); $i < count($row); $i++)
		$o[self::columnName($i)] = $row[$i];
	    $objects[] = $o;
	}
	return $objects;
    }

    //the rows to write from the setting's value (an expression's value, or JSON text): a list of rows, each a list of cells or an object; one object / one list of cells is one row;
    //$label starts the messages (the step's name)
    public static function rowsOf($value, $label) {
	if (is_string($value)) {
	    $value = trim($value);
	    if ($value === '')
		return [];
	    $decoded = json_decode($value, true);
	    if (!is_array($decoded))
		throw new \ErrorException("{$label}: the rows are not a list (JSON or an expression giving a list)");
	    $value = $decoded;
	}
	if (!is_array($value))
	    throw new \ErrorException("{$label}: the rows are not a list (got " . get_debug_type($value) . ')');
	if ($value === [])
	    return [];
	//one object, or one row of cells
	if (!array_is_list($value) || !array_filter($value, 'is_array'))
	    $value = [ $value ];
	$objects = null;
	foreach($value as $i => $row) {
	    if (!is_array($row))
		throw new \ErrorException("{$label}: row " . ($i + 1) . ' is not a list of cells or an object');
	    $isObject = $row !== [] && !array_is_list($row);
	    if ($objects !== null && $objects !== $isObject && $row !== [])
		throw new \ErrorException("{$label}: the rows mix objects and lists of cells (row " . ($i + 1) . ')');
	    if ($row !== [])
		$objects = $isObject;
	}
	return array_values($value);
    }

    //objects -> rows of cells in the order of the header $current; keys not in it are added to the header ($add) or left out: [ header, rows ]
    public static function matrixFor(array $objects, array $current, $add) {
	$header = $current;
	if ($add) {
	    foreach($objects as $o) {
		foreach(array_keys((array)$o) as $key) {
		    if (!in_array((string)$key, $header, true))
			$header[] = (string)$key;
		}
	    }
	}
	$rows = [];
	foreach($objects as $o) {
	    $o = (array)$o;
	    $rows[] = array_map(fn($name) => $name !== '' && array_key_exists($name, $o) ? $o[$name] : null, $header);
	}
	return [ $header, $rows ];
    }

    //a value for a cell: numbers / booleans as they are, null as empty, lists / objects as JSON, else text
    public static function cellValue($v) {
	if ($v === null)
	    return '';
	if (is_int($v) || is_float($v) || is_bool($v))
	    return $v;
	if (is_array($v) || is_object($v))
	    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	return (string)$v;
    }
}
