<?php

/*
    CLD: 2026-10-20T11:00:00+03:00: new step: Google Sheets - reads and writes the cells of a Google spreadsheet (Sheets API v4), through a Google Sheets or Google Drive (OAuth)
    credential of the project (both provide `gsheets`). Inspired by sim.ai's Google Sheets block. Operations (setting `operation`):
	read      the values of a sheet / a range; with "First row is the header": also a list of objects, one per row, keyed by the header (empty rows left out)
	append    rows after the last row of the table of the sheet
	update    rows written from a cell (the range's first cell) down
	clear     the values of a sheet / a range (formatting kept)
	info      the spreadsheet's title, link and sheets
	create    a new spreadsheet (title, sheet names)
	addSheet  a sheet (tab) in a spreadsheet
    The spreadsheet is given by id or by its link; the sheet by name (empty: the first sheet), the cells in A1 notation (empty: the whole sheet).
    Rows to write (append / update) are an expression or JSON: a list of lists (cells), a list of objects (matched to the header: the sheet's first row; keys missing from
    it are added as new columns, an empty sheet gets the header first), one object or one list (one row). Values are entered as typed in the sheet (formulas, dates, numbers)
    or as they are (raw). The answer goes into one variable (default `sheet`).
    On error (Sheets API errors, e.g. not found, no access, a bad range) - fail the step, or continue on a second output `error` (as HTTP Request: $outPorts)
*/

//CLD: 2026-10-22T00:35:40+03:00: moved to the GoogleIntegration plugin (was models\node\NodeAppGoogleSheets); core classes fully qualified
//CLD: 2026-10-26T23:39:49+02:00: moved back to the core (was plugins\GoogleIntegration\models): the Google credentials and steps are core, for every account (no plan feature)
namespace models\node;

class NodeAppGoogleSheets extends NodeAppGoogle {
    //static data/structure
    //CLD: 2026-10-20T14:00:00+03:00: the shape, the credential, the operation run and the API calls are NodeAppGoogle's
    public static $name = 'google-sheets';
    public static $label = 'Google Sheets';
    public static $icon = 'bi-table';
    public static $capability = 'gsheets';
    public static $credentialLabel = 'Google credential';
    public static $credentialKinds = 'Google Sheets or Google Drive';
    public static $defaultOperation = 'read';
    public static $defaultVariable = 'sheet';

    const OPERATIONS = [
	'read' => 'Read rows',
	'append' => 'Append rows',
	'update' => 'Update cells',
	'clear' => 'Clear cells',
	'info' => 'Get spreadsheet information',
	'create' => 'Create a spreadsheet',
	'addSheet' => 'Add a sheet',
    ];

    //the operations on the cells of a sheet (sheet, range)
    const ON_CELLS = [ 'read', 'append', 'update', 'clear' ];

    const RENDER = [
	'FORMATTED_VALUE' => 'As shown (formatted)',
	'UNFORMATTED_VALUE' => 'Values (numbers as numbers)',
	'FORMULA' => 'Formulas',
    ];

    const INPUT = [
	'USER_ENTERED' => 'As if typed (formulas, dates and numbers recognized)',
	'RAW' => 'As they are (text stays text)',
    ];

    const API = 'https://sheets.googleapis.com/v4/spreadsheets';

    //the largest number of rows written at once, of cells read kept
    const MAX_ROWS = 10000;

    //the spreadsheet's id and its first sheet's name (asked once per run)
    protected $spreadsheet = '';
    protected $firstSheet = null;

    public static function configSchema() {
	//CLD: 2026-10-24T12:47:49+03:00: the help texts without their "; {{ ... }} for expressions" ending (the expression inputs suggest as one types; less height in the wide dialog)
	$op = fn($ops) => [ 'key' => 'operation', 'operator' => 'in', 'value' => $ops ];
	$notCreate = [ 'read', 'append', 'update', 'clear', 'info', 'addSheet' ];

	return [
	    static::credentialField(),
	    static::operationField(),
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide, on a new line; shorter help (was: The spreadsheet id or its link (https://docs.google.com/spreadsheets/d/...); {{ ... }} for expressions, e.g. {{ drive.id }})
		'key' => 'spreadsheetId',
		'type' => 'text',
		'label' => 'Spreadsheet',
		'default' => '',
		'help' => 'The spreadsheet id or its link, e.g. {{ drive.id }}',
		'requiredIf' => [ 'if' => 'operation', 'in' => $notCreate ],
		'ui' => [ 'width' => 6, 'newRow' => true, 'placeholder' => '1AbC... or https://docs.google.com/spreadsheets/d/...', 'expression' => true, 'showIf' => $op($notCreate) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide (was 6)
		'key' => 'sheet',
		'type' => 'text',
		'label' => 'Sheet',
		'default' => '',
		'help' => 'The sheet (tab) name; empty: the first sheet',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'addSheet' ] ],
		'ui' => [ 'width' => 3, 'placeholder' => 'Sheet1', 'expression' => true, 'showIf' => $op(array_merge(self::ON_CELLS, [ 'addSheet' ])) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide (was 6); shorter help (was: A1 notation, e.g. A1:D100, A:C, 5:5; update: the first cell written (e.g. A5); empty: the whole sheet (update: A1))
		'key' => 'range',
		'type' => 'text',
		'label' => 'Cells',
		'default' => '',
		'help' => 'A1 notation, e.g. A1:D100; empty: the whole sheet',
		'validate' => [ [ 'regex', 'pattern' => '^\s*(\{\{.*\}\}|[A-Za-z]{0,3}[0-9]{0,7}(:[A-Za-z]{0,3}[0-9]{0,7})?)\s*$', 'skipEmpty' => true, 'message' => 'Cells in A1 notation, e.g. A1:D100' ] ],
		'ui' => [ 'width' => 3, 'placeholder' => 'A1:D100', 'expression' => true, 'showIf' => $op([ 'read', 'update', 'clear' ]) ],
	    ],

	    //read
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide, on a new line; shorter help (was: The rows are also given as objects keyed by the header, e.g. {{ sheet.rows[0].Email }})
		'key' => 'header',
		'type' => 'switch',
		'label' => 'First row is the header',
		'default' => true,
		'help' => 'Rows also as objects keyed by the header',
		'ui' => [ 'width' => 3, 'newRow' => true, 'showIf' => $op([ 'read' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'render',
		'type' => 'select',
		'label' => 'Read values',
		'required' => true,
		'default' => 'FORMATTED_VALUE',
		'choices' => self::RENDER,
		'ui' => [ 'advanced' => true, 'width' => 3, 'showIf' => $op([ 'read' ]) ],
	    ],

	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide, on a new line
		'key' => 'input',
		'type' => 'select',
		'label' => 'Write values',
		'required' => true,
		'default' => 'USER_ENTERED',
		'choices' => self::INPUT,
		'ui' => [ 'width' => 3, 'newRow' => true, 'showIf' => $op([ 'append', 'update' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide; shorter help (was: Rows as objects: keys not in the header become new columns (else they are left out))
		'key' => 'addColumns',
		'type' => 'switch',
		'label' => 'Add the missing columns to the header',
		'default' => true,
		'help' => 'New keys become new columns',
		'ui' => [ 'width' => 3, 'showIf' => $op([ 'append', 'update' ]) ],
	    ],
	    //append / update
	    [
		'key' => 'values',
		'type' => 'textarea',
		'label' => 'Rows',
		'default' => '',
		'help' => 'A list of rows: lists of cells ([["a", 1], ["b", 2]]) or objects matched to the header ([{"Name": "a", "Qty": 1}]); one object or list is one row; {{ ... }} for expressions, e.g. {{ items }}',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'append', 'update' ] ],
		'ui' => [ 'rows' => 5, 'expression' => true, 'placeholder' => '{{ [ { Name: mail.from_name, Email: mail.from } ] }}', 'showIf' => $op([ 'append', 'update' ]) ],
	    ],

	    //create
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide, on a new line
		'key' => 'title',
		'type' => 'text',
		'label' => 'Title',
		'default' => '',
		'help' => '{{ ... }} for expressions',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'create' ] ],
		'ui' => [ 'width' => 6, 'newRow' => true, 'placeholder' => 'Report {{ date }}', 'expression' => true, 'showIf' => $op([ 'create' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide; shorter help (was: The sheet names, separated by commas; empty: one sheet, Sheet1)
		'key' => 'sheets',
		'type' => 'text',
		'label' => 'Sheets',
		'default' => '',
		'help' => 'Sheet names separated by commas; empty: Sheet1',
		'ui' => [ 'width' => 6, 'placeholder' => 'Orders, Customers', 'expression' => true, 'showIf' => $op([ 'create' ]) ],
	    ],

	    static::timeoutField(60),
	    static::variableField(),
	    //CLD: 2026-10-24T12:46:54+03:00: shorter help (the narrow columns of the wide dialog; was: An error from Google Sheets, e.g. not found, no access, a sheet or range that does not exist)
	    static::onErrorField('Sheets errors, no such sheet / range'),
	];
    }

    //the type of a spreadsheet object (info / create)
    public static function spreadsheetType() {
	return [ 'type' => 'object', 'info' => 'the spreadsheet: { id, title, url, sheets: [ { id, title, index, rowCount, columnCount } ] }', 'fields' => [
	    'id' => [ 'type' => 'string' ], 'title' => [ 'type' => 'string' ], 'url' => [ 'type' => 'string', 'info' => 'the link that opens it' ],
	    'sheets' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => self::sheetFields() ] ] ] ];
    }

    protected static function sheetFields() {
	return [ 'id' => [ 'type' => 'number', 'info' => 'the sheet id (gid)' ], 'title' => [ 'type' => 'string' ], 'index' => [ 'type' => 'number' ],
	    'rowCount' => [ 'type' => 'number' ], 'columnCount' => [ 'type' => 'number' ] ];
    }

    //the variable, by operation
    public static function outputVariables($config) {
	$config = is_array($config) ? $config : [];
	$name = static::variableName($config);
	if ($name === null)
	    return [];
	$written = [ 'type' => 'object', 'info' => 'what was written: { range, rows, columns, cells }', 'fields' => [ 'range' => [ 'type' => 'string', 'info' => 'A1 notation, e.g. Sheet1!A5:C7' ],
	    'rows' => [ 'type' => 'number' ], 'columns' => [ 'type' => 'number' ], 'cells' => [ 'type' => 'number' ], 'header' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ],
	    'info' => 'the header used (rows as objects)' ] ] ];
	return [ $name => match($config['operation'] ?? 'read') {
	    'read' => [ 'type' => 'object', 'info' => 'the cells read: { range, values (rows of cells), header, rows (objects keyed by the header), count }', 'fields' => [
		'range' => [ 'type' => 'string', 'info' => 'A1 notation' ], 'values' => [ 'type' => 'array', 'items' => [ 'type' => 'array' ], 'info' => 'all the rows read, lists of cells' ],
		'header' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'info' => 'the first row (First row is the header)' ],
		'rows' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'info' => 'the rows after the header as objects (First row is the header), else the lists of cells' ],
		'count' => [ 'type' => 'number', 'info' => 'the number of rows (without the header)' ] ] ],
	    'append', 'update' => $written,
	    'clear' => [ 'type' => 'object', 'info' => '{ range }', 'fields' => [ 'range' => [ 'type' => 'string', 'info' => 'the cleared range' ] ] ],
	    'addSheet' => [ 'type' => 'object', 'info' => 'the new sheet: { id, title, index, rowCount, columnCount }', 'fields' => self::sheetFields() ],
	    default => self::spreadsheetType(),
	} ];
    }

    // ------------------------------------------------------------------------------------
    //running

    //CLD: 2026-10-20T14:00:00+03:00: the operation (NodeAppGoogle::execute runs it: the variable, the log, On error)
    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if ($operation !== 'create') {
	    $this->spreadsheet = self::driveId($text('spreadsheetId'));
	    if (!preg_match('/^[A-Za-z0-9_-]{10,200}$/', $this->spreadsheet))
		throw new \RuntimeException('not a spreadsheet id: `' . mb_substr($this->spreadsheet, 0, 100) . '`');
	}
	return match($operation) {
	    'read' => $this->opRead($text),
	    'append' => $this->opWrite($text, $value, true),
	    'update' => $this->opWrite($text, $value, false),
	    'clear' => $this->opClear($text),
	    'info' => $this->opInfo(),
	    'create' => $this->opCreate($text),
	    'addSheet' => $this->opAddSheet($text),
	};
    }

    protected function opRead(\Closure $text) {
	$range = $this->rangeOf($text('sheet'), $text('range'));
	$render = isset(self::RENDER[$this->cfg('render')]) ? (string)$this->cfg('render') : 'FORMATTED_VALUE';
	$data = $this->api('GET', '/' . $this->spreadsheet . '/values/' . rawurlencode($range), [ 'valueRenderOption' => $render, 'dateTimeRenderOption' => 'FORMATTED_STRING',
	    'majorDimension' => 'ROWS' ]);
	$values = array_slice(array_values((array)($data['values'] ?? [])), 0, self::MAX_ROWS + 1);
	$result = [ 'range' => (string)($data['range'] ?? $range), 'values' => $values ];
	if ($this->cfg('header', true)) {
	    $result['header'] = self::headerOf($values[0] ?? []);
	    $result['rows'] = self::objectsOf(array_slice($values, 1), $result['header']);
	} else {
	    $result['header'] = [];
	    $result['rows'] = $values;
	}
	$result['count'] = count($result['rows']);
	return [ $result, "{$result['count']} row(s) from {$result['range']}" ];
    }

    //append: after the table of the sheet; update: from the range's first cell
    protected function opWrite(\Closure $text, \Closure $value, $append) {
	$sheet = $this->sheetName($text('sheet'));
	$rows = self::rowsOf($value('values'));
	if (empty($rows))
	    throw new \ErrorException('Google Sheets: no rows to write');
	if (count($rows) > self::MAX_ROWS)
	    throw new \ErrorException('Google Sheets: more than ' . self::MAX_ROWS . ' rows at once');

	//objects: matched to the header (the sheet's first row), which may get new columns
	$header = null;
	if (!array_is_list($rows[0])) {
	    $first = $this->api('GET', '/' . $this->spreadsheet . '/values/' . rawurlencode(self::quoteSheet($sheet) . '!1:1'), [ 'valueRenderOption' => 'FORMATTED_VALUE' ]);
	    $current = array_map(fn($c) => trim(\util\Expression::toText($c)), (array)($first['values'][0] ?? []));
	    [ $header, $rows ] = self::matrixFor($rows, $current, (bool)$this->cfg('addColumns', true));
	    if ($header !== $current) {
		$this->api('PUT', '/' . $this->spreadsheet . '/values/' . rawurlencode(self::quoteSheet($sheet) . '!A1'), [ 'valueInputOption' => 'RAW' ], [ 'values' => [ $header ] ]);
		$this->exec->log($this->wfid, 'Google Sheets: header of `' . $sheet . '`: ' . (empty($current) ? 'written' : 'columns added: ' . implode(', ', array_slice($header, count($current)))));
	    }
	}
	$values = array_map(fn($row) => array_map([ self::class, 'cellValue' ], $row), $rows);
	$input = isset(self::INPUT[$this->cfg('input')]) ? (string)$this->cfg('input') : 'USER_ENTERED';

	if ($append) {
	    $data = $this->api('POST', '/' . $this->spreadsheet . '/values/' . rawurlencode(self::quoteSheet($sheet) . '!A1') . ':append',
		[ 'valueInputOption' => $input, 'insertDataOption' => 'INSERT_ROWS' ], [ 'values' => $values ]);
	    $data = (array)($data['updates'] ?? []);
	} else {
	    $cells = $text('range');
	    $start = $cells === '' ? 'A1' : explode(':', $cells)[0];
	    $data = $this->api('PUT', '/' . $this->spreadsheet . '/values/' . rawurlencode(self::quoteSheet($sheet) . '!' . $start), [ 'valueInputOption' => $input ], [ 'values' => $values ]);
	}
	$result = [ 'range' => (string)($data['updatedRange'] ?? ''), 'rows' => (int)($data['updatedRows'] ?? 0), 'columns' => (int)($data['updatedColumns'] ?? 0),
	    'cells' => (int)($data['updatedCells'] ?? 0), 'header' => $header ];
	return [ $result, "{$result['rows']} row(s) written to {$result['range']}" ];
    }

    protected function opClear(\Closure $text) {
	$range = $this->rangeOf($text('sheet'), $text('range'));
	$data = $this->api('POST', '/' . $this->spreadsheet . '/values/' . rawurlencode($range) . ':clear', [], (object)[]);
	$result = [ 'range' => (string)($data['clearedRange'] ?? $range) ];
	return [ $result, "{$result['range']} cleared" ];
    }

    protected function opInfo() {
	$result = self::spreadsheetInfo($this->api('GET', '/' . $this->spreadsheet, [ 'fields' => 'spreadsheetId,spreadsheetUrl,properties.title,sheets.properties' ]));
	return [ $result, "`{$result['title']}`: " . count($result['sheets']) . ' sheet(s)' ];
    }

    protected function opCreate(\Closure $text) {
	$title = $text('title');
	if ($title === '')
	    throw new \ErrorException('Google Sheets: create: the spreadsheet needs a title');
	$body = [ 'properties' => [ 'title' => $title ] ];
	$names = array_values(array_unique(array_filter(array_map('trim', explode(',', $text('sheets'))), fn($s) => $s !== '')));
	if ($names)
	    $body['sheets'] = array_map(fn($s) => [ 'properties' => [ 'title' => mb_substr($s, 0, 100) ] ], $names);
	$result = self::spreadsheetInfo($this->api('POST', '', [], $body));
	return [ $result, "`{$result['title']}` created ({$result['id']})" ];
    }

    protected function opAddSheet(\Closure $text) {
	$title = $text('sheet');
	if ($title === '')
	    throw new \ErrorException('Google Sheets: add a sheet: the sheet needs a name');
	$data = $this->api('POST', '/' . $this->spreadsheet . ':batchUpdate', [], [ 'requests' => [ [ 'addSheet' => [ 'properties' => [ 'title' => mb_substr($title, 0, 100) ] ] ] ] ]);
	$result = self::sheetInfo($data['replies'][0]['addSheet']['properties'] ?? []);
	return [ $result, "sheet `{$result['title']}` added" ];
    }

    // ------------------------------------------------------------------------------------
    //the Sheets API

    //the sheet named $name, else the spreadsheet's first sheet
    protected function sheetName($name) {
	if ($name !== '')
	    return $name;
	if ($this->firstSheet === null) {
	    $info = $this->api('GET', '/' . $this->spreadsheet, [ 'fields' => 'sheets.properties.title' ]);
	    $this->firstSheet = (string)($info['sheets'][0]['properties']['title'] ?? '');
	    if ($this->firstSheet === '')
		throw new \RuntimeException('the spreadsheet has no sheets');
	}
	return $this->firstSheet;
    }

    //'<sheet>'!<cells> (the whole sheet when no cells)
    protected function rangeOf($sheet, $cells) {
	$cells = trim((string)$cells);
	if ($cells !== '' && !preg_match('/^[A-Za-z]{0,3}[0-9]{0,7}(:[A-Za-z]{0,3}[0-9]{0,7})?$/', $cells))
	    throw new \RuntimeException("not cells in A1 notation: `" . mb_substr($cells, 0, 60) . '`');
	return self::quoteSheet($this->sheetName($sheet)) . ($cells !== '' ? "!{$cells}" : '');
    }

    //a sheet name in a range: '<name>' (quotes doubled)
    public static function quoteSheet($name) {
	return "'" . str_replace("'", "''", (string)$name) . "'";
    }

    public static function sheetInfo($p) {
	$p = is_array($p) ? $p : [];
	return [ 'id' => (int)($p['sheetId'] ?? 0), 'title' => (string)($p['title'] ?? ''), 'index' => (int)($p['index'] ?? 0),
	    'rowCount' => (int)($p['gridProperties']['rowCount'] ?? 0), 'columnCount' => (int)($p['gridProperties']['columnCount'] ?? 0) ];
    }

    public static function spreadsheetInfo($s) {
	$s = is_array($s) ? $s : [];
	return [ 'id' => (string)($s['spreadsheetId'] ?? ''), 'title' => (string)($s['properties']['title'] ?? ''), 'url' => (string)($s['spreadsheetUrl'] ?? ''),
	    'sheets' => array_map(fn($sh) => self::sheetInfo($sh['properties'] ?? []), array_values((array)($s['sheets'] ?? []))) ];
    }

    // ------------------------------------------------------------------------------------
    //rows and cells
    //CLD: 2026-10-22T00:41:28+03:00: util\SheetRows (moved to the core: the Excel step of the MicrosoftIntegration plugin uses them too); kept here by name

    public static function columnName($i) {
	return \util\SheetRows::columnName($i);
    }

    public static function headerOf($row) {
	return \util\SheetRows::headerOf($row);
    }

    public static function objectsOf(array $rows, array $header) {
	return \util\SheetRows::objectsOf($rows, $header);
    }

    public static function rowsOf($value) {
	return \util\SheetRows::rowsOf($value, 'Google Sheets');
    }

    public static function matrixFor(array $objects, array $current, $add) {
	return \util\SheetRows::matrixFor($objects, $current, $add);
    }

    public static function cellValue($v) {
	return \util\SheetRows::cellValue($v);
    }
}
