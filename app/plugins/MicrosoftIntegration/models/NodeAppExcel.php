<?php

/*
    CLD: 2026-10-22T00:41:28+03:00: new step: Excel - reads and writes the cells of an Excel workbook in OneDrive or SharePoint (Microsoft Graph workbook API), through a
    OneDrive & SharePoint (Microsoft Graph) credential of the project (CredentialMicrosoftFiles, provides `msexcel`). As the Google Sheets step (GoogleIntegration plugin).
    Operations (setting `operation`):
	read      the values of a worksheet (its used cells) / a range; with "First row is the header": also a list of objects, one per row, keyed by the header
	append    rows after the last used row of the worksheet, or at the end of a table (Table: its header matches the objects)
	update    rows written from a cell (the range's first cell) down
	clear     the contents of a worksheet / a range (formatting kept)
	info      the workbook's worksheets and tables
	addSheet  a worksheet
    The workbook (.xlsx) is given by id, by path in the drive (/Reports/2026.xlsx) or by a sharing link; the drive as in OneDrive / SharePoint (empty: the account's OneDrive,
    a SharePoint site's address, a drive id); the worksheet by name (empty: the first), the cells in A1 notation (empty: the used cells). Rows to write are as Google Sheets'
    (util\SheetRows: lists of cells, or objects matched to the header, missing columns optionally added). Text starting with = is a formula. The answer goes into one
    variable (default `excel`).
    On error (Graph errors, e.g. not found, no access, a bad range, the workbook locked) - fail the step, or continue on a second output `error`
*/

namespace plugins\MicrosoftIntegration\models;

class NodeAppExcel extends NodeAppMicrosoft {
    use DriveItemsTrait;

    //static data/structure
    public static $name = 'excel';
    public static $label = 'Excel';
    public static $icon = 'bi-file-earmark-spreadsheet';
    public static $capability = 'msexcel';
    public static $credentialLabel = 'OneDrive & SharePoint credential';
    public static $credentialKinds = 'OneDrive & SharePoint';
    public static $defaultOperation = 'read';
    public static $defaultVariable = 'excel';

    const OPERATIONS = [
	'read' => 'Read rows',
	'append' => 'Append rows',
	'update' => 'Update cells',
	'clear' => 'Clear cells',
	'info' => 'Get workbook information',
	'addSheet' => 'Add a worksheet',
    ];

    //the operations on the cells of a worksheet
    const ON_CELLS = [ 'read', 'append', 'update', 'clear' ];

    const RENDER = [ 'values' => 'Values (numbers as numbers)', 'text' => 'As shown (formatted text)', 'formulas' => 'Formulas' ];

    const MAX_ROWS = 10000;
    //the largest address (Excel: XFD1048576)
    const MAX_COLUMNS = 16384;
    const MAX_ROW = 1048576;

    //the workbook's API path (asked once per run), its first worksheet's name
    protected $book = '';
    protected $firstSheet = null;

    public static function configSchema() {
	//CLD: 2026-10-24T12:47:49+03:00: the help texts without their "; {{ ... }} for expressions" ending (the expression inputs suggest as one types; less height in the wide dialog)
	$op = fn($ops) => [ 'key' => 'operation', 'operator' => 'in', 'value' => $ops ];

	return [
	    static::credentialField(),
	    static::operationField(),
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide, on a new line; shorter help (was: Empty: your OneDrive; a SharePoint site (https://contoso.sharepoint.com/sites/Team: its documents) or a drive id)
		'key' => 'drive',
		'type' => 'text',
		'label' => 'Drive',
		'default' => '',
		'help' => 'Empty: your OneDrive; a SharePoint site link or a drive id',
		'ui' => [ 'width' => 6, 'newRow' => true, 'placeholder' => 'https://contoso.sharepoint.com/sites/Team', 'expression' => true ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide; shorter help (was: The .xlsx file: its id, path in the drive (/Reports/2026.xlsx) or a sharing link; {{ ... }} for expressions, e.g. {{ files.id }})
		'key' => 'workbook',
		'type' => 'text',
		'label' => 'Workbook',
		'required' => true,
		'default' => '',
		'help' => 'The .xlsx file: id, path (/Reports/2026.xlsx) or sharing link',
		'ui' => [ 'width' => 6, 'placeholder' => '/Reports/2026.xlsx', 'expression' => true ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6), on a new line
		'key' => 'sheet',
		'type' => 'text',
		'label' => 'Worksheet',
		'default' => '',
		'help' => 'The worksheet name; empty: the first one',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'addSheet' ] ],
		'ui' => [ 'width' => 3, 'newRow' => true, 'placeholder' => 'Sheet1', 'expression' => true, 'showIf' => $op(array_merge(self::ON_CELLS, [ 'addSheet' ])) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6); shorter help (was: A1 notation, e.g. A1:D100; update: the first cell written (e.g. A5); empty: the used cells (update: A1))
		'key' => 'range',
		'type' => 'text',
		'label' => 'Cells',
		'default' => '',
		'help' => 'A1 notation, e.g. A1:D100; empty: the used cells',
		'validate' => [ [ 'regex', 'pattern' => '^\s*(\{\{.*\}\}|[A-Za-z]{0,3}[0-9]{0,7}(:[A-Za-z]{0,3}[0-9]{0,7})?)\s*$', 'skipEmpty' => true, 'message' => 'Cells in A1 notation, e.g. A1:D100' ] ],
		'ui' => [ 'width' => 3, 'placeholder' => 'A1:D100', 'expression' => true, 'showIf' => $op([ 'read', 'update', 'clear' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide; shorter help (was: Optional: the name of a table (Insert > Table) to add the rows to; empty: after the last used row of the worksheet)
		'key' => 'table',
		'type' => 'text',
		'label' => 'Table',
		'default' => '',
		'help' => 'Optional: a table to add the rows to; empty: after the last row',
		'ui' => [ 'width' => 6, 'expression' => true, 'showIf' => $op([ 'append' ]) ],
	    ],

	    //read
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide, on a new line; shorter help (was: The rows are also given as objects keyed by the header, e.g. {{ excel.rows[0].Email }})
		'key' => 'header',
		'type' => 'switch',
		'label' => 'First row is the header',
		'default' => true,
		'help' => 'Rows also as objects keyed by the header',
		'ui' => [ 'width' => 3, 'newRow' => true, 'showIf' => $op([ 'read' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide
		'key' => 'render',
		'type' => 'select',
		'label' => 'Read values',
		'required' => true,
		'default' => 'values',
		'choices' => self::RENDER,
		'ui' => [ 'width' => 3, 'showIf' => $op([ 'read' ]) ],
	    ],

	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide, on a new line; shorter help (was: Rows as objects: keys not in the header become new columns (else they are left out; a table's columns are not added))
		'key' => 'addColumns',
		'type' => 'switch',
		'label' => 'Add the missing columns to the header',
		'default' => true,
		'help' => 'New keys become new columns',
		'ui' => [ 'width' => 3, 'newRow' => true, 'showIf' => $op([ 'append', 'update' ]) ],
	    ],
	    //append / update
	    [
		'key' => 'values',
		'type' => 'textarea',
		'label' => 'Rows',
		'default' => '',
		'help' => 'A list of rows: lists of cells ([["a", 1], ["b", 2]]) or objects matched to the header ([{"Name": "a", "Qty": 1}]); one object or list is one row',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'append', 'update' ] ],
		'ui' => [ 'rows' => 5, 'expression' => true, 'placeholder' => '{{ [ { Name: mail.from_name, Email: mail.from } ] }}', 'showIf' => $op([ 'append', 'update' ]) ],
	    ],

	    static::timeoutField(60),
	    static::variableField(),
	    //CLD: 2026-10-24T12:46:54+03:00: shorter help (the narrow columns of the wide dialog; was: An error from Excel, e.g. not found, no access, a worksheet or range that does not exist, the workbook locked)
	    static::onErrorField('Excel errors, no such range, locked'),
	];
    }

    //the type of a workbook object (info)
    public static function workbookType() {
	return [ 'type' => 'object', 'info' => 'the workbook: { worksheets: [ { id, name, position, visible } ], tables: [ { id, name, worksheet } ] }', 'fields' => [
	    'worksheets' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => self::sheetFields() ] ],
	    'tables' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => [ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ],
		'worksheet' => [ 'type' => 'string' ] ] ] ] ] ];
    }

    protected static function sheetFields() {
	return [ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'position' => [ 'type' => 'number' ], 'visible' => [ 'type' => 'boolean' ] ];
    }

    //the variable, by operation
    public static function outputVariables($config) {
	$config = is_array($config) ? $config : [];
	$name = static::variableName($config);
	if ($name === null)
	    return [];
	$written = [ 'type' => 'object', 'info' => 'what was written: { range, rows, columns, cells, header }', 'fields' => [ 'range' => [ 'type' => 'string', 'info' => 'e.g. Sheet1!A5:C7' ],
	    'rows' => [ 'type' => 'number' ], 'columns' => [ 'type' => 'number' ], 'cells' => [ 'type' => 'number' ], 'header' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ],
	    'info' => 'the header used (rows as objects)' ] ] ];
	return [ $name => match($config['operation'] ?? 'read') {
	    'read' => [ 'type' => 'object', 'info' => 'the cells read: { range, values (rows of cells), header, rows (objects keyed by the header), count }', 'fields' => [
		'range' => [ 'type' => 'string' ], 'values' => [ 'type' => 'array', 'items' => [ 'type' => 'array' ], 'info' => 'all the rows read, lists of cells' ],
		'header' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
		'rows' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'info' => 'the rows after the header as objects (First row is the header), else the lists of cells' ],
		'count' => [ 'type' => 'number', 'info' => 'the number of rows (without the header)' ] ] ],
	    'append', 'update' => $written,
	    'clear' => [ 'type' => 'object', 'info' => '{ range }', 'fields' => [ 'range' => [ 'type' => 'string' ] ] ],
	    'addSheet' => [ 'type' => 'object', 'info' => 'the new worksheet: { id, name, position, visible }', 'fields' => self::sheetFields() ],
	    default => self::workbookType(),
	} ];
    }

    // ------------------------------------------------------------------------------------
    //running

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$this->drivePath = $this->resolveDrive($text('drive'));
	$this->book = $this->itemRef($text('workbook'), 'workbook') . '/workbook';
	return match($operation) {
	    'read' => $this->opRead($text),
	    'append' => $this->opAppend($text, $value),
	    'update' => $this->opUpdate($text, $value),
	    'clear' => $this->opClear($text),
	    'info' => $this->opInfo(),
	    'addSheet' => $this->opAddSheet($text),
	};
    }

    protected function opRead(\Closure $text) {
	$data = $this->api('GET', $this->rangePath($text('sheet'), $text('range')), [ '$select' => 'address,values,text,formulas' ]);
	$render = isset(self::RENDER[$this->cfg('render')]) ? (string)$this->cfg('render') : 'values';
	$values = self::trimEmpty(array_slice(array_values((array)($data[$render] ?? $data['values'] ?? [])), 0, self::MAX_ROWS + 1));
	$result = [ 'range' => (string)($data['address'] ?? ''), 'values' => $values ];
	if ($this->cfg('header', true)) {
	    $result['header'] = \util\SheetRows::headerOf($values[0] ?? []);
	    $result['rows'] = \util\SheetRows::objectsOf(array_slice($values, 1), $result['header']);
	} else {
	    $result['header'] = [];
	    $result['rows'] = $values;
	}
	$result['count'] = count($result['rows']);
	return [ $result, "{$result['count']} row(s) from {$result['range']}" ];
    }

    protected function opAppend(\Closure $text, \Closure $value) {
	$rows = $this->rowsToWrite($value);
	$table = $text('table');
	if ($table !== '') {
	    $t = '/tables/' . rawurlencode($table);
	    $header = null;
	    if (!array_is_list($rows[0])) {
		$head = $this->api('GET', $this->book . $t . '/headerRowRange', [ '$select' => 'values' ]);
		$header = array_map(fn($c) => trim(\util\Expression::toText($c)), (array)($head['values'][0] ?? []));
		[ , $rows ] = \util\SheetRows::matrixFor($rows, $header, false);
	    }
	    $values = $this->matrix($rows);
	    $this->api('POST', $this->book . $t . '/rows', [], [ 'values' => $values ]);
	    $cols = max(array_map('count', $values));
	    $result = [ 'range' => "table {$table}", 'rows' => count($values), 'columns' => $cols, 'cells' => count($values) * $cols, 'header' => $header ];
	    return [ $result, count($values) . " row(s) added to table `{$table}`" ];
	}

	$sheet = $this->sheetName($text('sheet'));
	//the used cells' address only (their values may be large), then the first row: the header, and whether the worksheet is empty (its used range: A1, empty)
	$used = $this->api('GET', $this->sheetPath($sheet) . '/usedRange(valuesOnly=true)', [ '$select' => 'address' ]);
	[ , , $lastCol ] = self::bounds(self::cellsOf((string)($used['address'] ?? 'A1')));
	$first = $this->api('GET', $this->sheetPath($sheet) . "/range(address='" . rawurlencode('A1:' . \util\SheetRows::columnName($lastCol) . '1') . "')", [ '$select' => 'values' ]);
	$empty = self::cellsOf((string)($used['address'] ?? 'A1')) === 'A1' && self::isEmptyRange($first);
	$header = null;
	if (!array_is_list($rows[0])) {
	    $current = $empty ? [] : array_map(fn($c) => trim(\util\Expression::toText($c)), (array)($first['values'][0] ?? []));
	    while ($current && end($current) === '')
		array_pop($current);
	    [ $header, $rows ] = \util\SheetRows::matrixFor($rows, $current, (bool)$this->cfg('addColumns', true));
	    if ($header !== $current) {
		$this->writeAt($sheet, 'A1', [ $header ]);
		$this->exec->log($this->wfid, "Excel: header of `{$sheet}`: " . (empty($current) ? 'written' : 'columns added: ' . implode(', ', array_slice($header, count($current)))));
		if ($empty)
		    $empty = false;
	    }
	}
	//after the last used row (an empty worksheet: row 1, or 2 under a header just written)
	[ , , , $lastRow ] = $empty ? [ 0, 0, 0, $header !== null ? 1 : 0 ] : self::bounds(self::cellsOf((string)$used['address']));
	$result = $this->writeAt($sheet, 'A' . ($lastRow + 1), $rows) + [ 'header' => $header ];
	return [ $result, "{$result['rows']} row(s) written to {$result['range']}" ];
    }

    protected function opUpdate(\Closure $text, \Closure $value) {
	$rows = $this->rowsToWrite($value);
	$sheet = $this->sheetName($text('sheet'));
	$cells = $text('range');
	$start = $cells === '' ? 'A1' : explode(':', $cells)[0];
	$header = null;
	if (!array_is_list($rows[0])) {
	    $first = $this->api('GET', $this->sheetPath($sheet) . "/range(address='1:1')/usedRange(valuesOnly=true)", [ '$select' => 'address,values' ], null, [], self::MAX_ANSWER);
	    $current = self::isEmptyRange($first) ? [] : array_map(fn($c) => trim(\util\Expression::toText($c)), (array)($first['values'][0] ?? []));
	    [ $header, $rows ] = \util\SheetRows::matrixFor($rows, $current, (bool)$this->cfg('addColumns', true));
	    if ($header !== $current)
		$this->writeAt($sheet, 'A1', [ $header ]);
	}
	$result = $this->writeAt($sheet, $start, $rows) + [ 'header' => $header ];
	return [ $result, "{$result['rows']} row(s) written to {$result['range']}" ];
    }

    protected function opClear(\Closure $text) {
	$path = $this->rangePath($text('sheet'), $text('range'));
	$range = $this->api('GET', $path, [ '$select' => 'address' ]);
	$this->api('POST', $path . '/clear', [], [ 'applyTo' => 'Contents' ]);
	$result = [ 'range' => (string)($range['address'] ?? '') ];
	return [ $result, "{$result['range']} cleared" ];
    }

    protected function opInfo() {
	$sheets = (array)($this->api('GET', $this->book . '/worksheets', [ '$select' => 'id,name,position,visibility' ])['value'] ?? []);
	$tables = (array)($this->api('GET', $this->book . '/tables', [ '$select' => 'id,name', '$expand' => 'worksheet($select=name)' ])['value'] ?? []);
	$result = [ 'worksheets' => array_map([ self::class, 'sheetInfo' ], $sheets),
	    'tables' => array_map(fn($t) => [ 'id' => (string)($t['id'] ?? ''), 'name' => (string)($t['name'] ?? ''), 'worksheet' => (string)($t['worksheet']['name'] ?? '') ], $tables) ];
	return [ $result, count($result['worksheets']) . ' worksheet(s), ' . count($result['tables']) . ' table(s)' ];
    }

    protected function opAddSheet(\Closure $text) {
	$name = $text('sheet');
	if ($name === '' || preg_match('~[\\\\/?*\[\]:]~', $name) || mb_strlen($name) > 31)
	    throw new \RuntimeException('not a worksheet name: `' . mb_substr($name, 0, 60) . '` (up to 31 characters, no \\ / ? * [ ] :)');
	$result = self::sheetInfo($this->api('POST', $this->book . '/worksheets/add', [], [ 'name' => $name ]));
	return [ $result, "worksheet `{$result['name']}` added" ];
    }

    // ------------------------------------------------------------------------------------
    //the workbook API

    //the worksheet named $name, else the workbook's first
    protected function sheetName($name) {
	if ($name !== '')
	    return $name;
	if ($this->firstSheet === null) {
	    $sheets = (array)($this->api('GET', $this->book . '/worksheets', [ '$select' => 'name,position' ])['value'] ?? []);
	    usort($sheets, fn($a, $b) => (int)($a['position'] ?? 0) <=> (int)($b['position'] ?? 0));
	    $this->firstSheet = (string)($sheets[0]['name'] ?? '');
	    if ($this->firstSheet === '')
		throw new \RuntimeException('the workbook has no worksheets');
	}
	return $this->firstSheet;
    }

    protected function sheetPath($sheet) {
	return $this->book . '/worksheets/' . rawurlencode(str_replace("'", "''", $sheet));
    }

    //the path of a range of a worksheet: its cells, or the used ones (values only) when none are given
    protected function rangePath($sheet, $cells) {
	$cells = trim((string)$cells);
	$path = $this->sheetPath($this->sheetName($sheet));
	if ($cells === '')
	    return $path . '/usedRange(valuesOnly=true)';
	if (!preg_match('/^[A-Za-z]{0,3}[0-9]{0,7}(:[A-Za-z]{0,3}[0-9]{0,7})?$/', $cells))
	    throw new \RuntimeException('not cells in A1 notation: `' . mb_substr($cells, 0, 60) . '`');
	return $path . "/range(address='" . rawurlencode($cells) . "')";
    }

    //$rows written from cell $start (A5) down: the range of exactly their size (the rows padded to the longest); { range, rows, columns, cells }
    protected function writeAt($sheet, $start, array $rows) {
	$values = $this->matrix($rows);
	if (!preg_match('/^([A-Za-z]{1,3})([0-9]{1,7})$/', trim((string)$start), $m))
	    throw new \RuntimeException('not a cell: `' . mb_substr((string)$start, 0, 20) . '` (e.g. A5)');
	$col = \util\SheetRows::columnIndex($m[1]);
	$row = (int)$m[2];
	$cols = max(array_map('count', $values));
	if ($row < 1 || $col + $cols > self::MAX_COLUMNS || $row + count($values) - 1 > self::MAX_ROW)
	    throw new \RuntimeException('the rows do not fit in the worksheet from ' . $start);
	$address = strtoupper($m[1]) . $row . ':' . \util\SheetRows::columnName($col + $cols - 1) . ($row + count($values) - 1);
	$data = $this->api('PATCH', $this->sheetPath($sheet) . "/range(address='" . rawurlencode($address) . "')", [], [ 'values' => $values ]);
	return [ 'range' => (string)($data['address'] ?? "{$sheet}!{$address}"), 'rows' => count($values), 'columns' => $cols, 'cells' => count($values) * $cols ];
    }

    //the rows of the setting, checked
    protected function rowsToWrite(\Closure $value) {
	$rows = \util\SheetRows::rowsOf($value('values'), 'Excel');
	if (empty($rows))
	    throw new \ErrorException('Excel: no rows to write');
	if (count($rows) > self::MAX_ROWS)
	    throw new \ErrorException('Excel: more than ' . self::MAX_ROWS . ' rows at once');
	return $rows;
    }

    //rows of cells as Graph writes them: cell values, every row as long as the longest (Graph wants a rectangle)
    protected function matrix(array $rows) {
	$values = array_map(fn($row) => array_map([ \util\SheetRows::class, 'cellValue' ], array_values((array)$row)), $rows);
	$cols = max(1, ...array_map('count', $values));
	return array_map(fn($row) => array_pad($row, $cols, ''), $values);
    }

    public static function sheetInfo($s) {
	$s = is_array($s) ? $s : [];
	return [ 'id' => (string)($s['id'] ?? ''), 'name' => (string)($s['name'] ?? ''), 'position' => (int)($s['position'] ?? 0),
	    'visible' => ($s['visibility'] ?? 'Visible') === 'Visible' ];
    }

    // ------------------------------------------------------------------------------------
    //addresses

    //the cells of an address: Sheet1!A1:D20, 'My sheet'!B2 -> A1:D20, B2
    public static function cellsOf($address) {
	$address = (string)$address;
	$at = strrpos($address, '!');
	return $at === false ? $address : substr($address, $at + 1);
    }

    //[ first column, first row, last column, last row ] of cells (A1:D20; B2) - columns from 0, rows from 1
    public static function bounds($cells) {
	$parts = explode(':', strtoupper(trim((string)$cells)));
	$read = function($c) {
	    if (!preg_match('/^([A-Z]{1,3})([0-9]{1,7})$/', $c, $m))
		throw new \RuntimeException('not a cell address: `' . mb_substr($c, 0, 20) . '`');
	    return [ \util\SheetRows::columnIndex($m[1]), (int)$m[2] ];
	};
	[ $c1, $r1 ] = $read($parts[0]);
	[ $c2, $r2 ] = isset($parts[1]) ? $read($parts[1]) : [ $c1, $r1 ];
	return [ $c1, $r1, $c2, $r2 ];
    }

    //an empty worksheet's used range: one empty cell
    public static function isEmptyRange($range) {
	$values = (array)($range['values'] ?? []);
	return !$values || (count($values) === 1 && count((array)$values[0]) <= 1 && trim(\util\Expression::toText($values[0][0] ?? '')) === '');
    }

    //the rows read, without the empty rows at the end (an empty worksheet: none)
    public static function trimEmpty(array $values) {
	while ($values && !array_filter((array)end($values), fn($c) => $c !== '' && $c !== null))
	    array_pop($values);
	return $values;
    }
}
