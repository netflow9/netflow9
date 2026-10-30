<?php

namespace controllers;

class DatatableController extends \sys\BaseController {

    use DataTrait;

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());

	$this->project = \V::app()->om->findById('Project', \V::app()->user->crtProject);
    }

    public function list($req, $resp) {
	if (!\V::app()->user->can('DataTable::list'))
	    \V::app()->error(403, 'Unauthorized');

	if (!empty($req->query['data'])) {
	    //populate manually
	    $account = \V::app()->var('rt.account');
	    $query = [
		'aid' => $account->_id,
		'project' => $this->project->_id,
	    ];
	    $response = [
		'nRecords' => \V::app()->om->count('DataTableDef', $query),
		'data' => [],
	    ];

	    $tables = \V::app()->om->find('DataTableDef', $query);
	    foreach($tables as $t) {
		$response['data'][] = [
		    '_id' => (string)$t->_id,
		    'name' => $t->name,
		    'rows' => \V::app()->om->count($t->iname, [ 'aid' => $account->_id ]),
		    'cols' => \V::app()->om->count('Field', [ 'objectName' => $t->iname ]),
		    'created_at' => $t->schema['created_at']->display(),
		];
	    }

	    //CLD: 2026-10-18T12:49:28+03:00: Response::sendJSON (was write + end: sent as text/html)
	    $resp->sendJSON($response);
	}

	$this->render('list');
    }

    //CLD: 2026-10-11T21:32:01+03:00: POST only (the Create link has data-method="post"; a GET, e.g. a prefetch or a typed URL, created a table)
    public function create($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	if (!\V::app()->user->can('DataTable::create'))
	    \V::app()->error(403, 'Unauthorized');

	$account = \V::app()->var('rt.account');
	//CLD: 2026-10-24T09:45:36+03:00: the tier's limit of data tables (util\Tiers)
	try {
	    \util\Tiers::check($account, 'datatables');
	} catch (\util\LimitException $e) {
	    $this->addToast('danger', 'Limit reached', $e->getMessage());
	    $this->redirect(\V::app()->createUrl('/datatable/list'));
	    return;
	}
	$n = \V::app()->om->count('DataTableDef', [ 'aid' => $account->_id ]);

	$dt = \V::app()->om->create('DataTableDef', [
	    'aid' => $account->_id,
	    'name' => 'data_table_' . ++$n,
	    'iname' => "data-table-{$n}-{$this->project->_id}",
	    'project' => $this->project->_id,
	]);
	if (!$dt->validates())
	    \V::app()->error(400, 'Error creating datatable:' . print_r($dt->errors(), true));

	$dt->save();

	//create a field
	//CLD: 2026-10-11T22:10:29+03:00: deletable from the grid like the columns made in the designer (Field defaults perm:delete to nobody)
	$f = \sys\fields\Field::create([
	    'type' => 'text',
	    'aid' => $account->_id,
	    'key' => 'name',
	    'label' => 'Name',
	    'objectName' => $dt->iname,
	    'perm:delete' => 'everybody',
	]);
	$f->save();

	$this->addToast('success', 'OK', 'Datatable created!');
	$this->redirect(\V::app()->createUrl('/datatable/manage', [ 'id' => $dt->_id ]));
    }

    //CLD: 2026-10-11T20:51:55+03:00: import a CSV / Excel (.xlsx) file as a new data table (DataTable::create). Steps (one dialog): upload (GET: the file form; POST with `file`),
    //preview (the guessed columns: header row or not, name, key, type, which to import; first rows; another sheet / header choice posts `step=preview`
    //again), import (POST `step=import`: the table, its columns and rows are created; values a column cannot read are left empty, rows that fail
    //the column rules are skipped; one audit entry `datatable.import`). The uploaded file waits in tmp/import/<token>.<ext> (+ .json: account,
    //user, name) until imported; files older than a day are removed
    public function import($req, $resp) {
	if (!\V::app()->user->can('DataTable::create'))
	    \V::app()->error(403, 'Unauthorized');

	$dir = BASE . '/tmp/import';
	if (!is_dir($dir))
	    mkdir($dir, 0700, true);
	foreach(glob($dir . '/*') ?: [] as $old) {
	    if (filemtime($old) < time() - 86400)
		@unlink($old);
	}

	if (!$req->is('post')) {
	    $this->render('import_upload', [ 'error' => null ]);
	    return;
	}
	//CLD: 2026-10-24T09:45:36+03:00: the tier's limit of data tables (util\Tiers), before the file is uploaded
	try {
	    \util\Tiers::check(\V::app()->var('rt.account'), 'datatables');
	} catch (\util\LimitException $e) {
	    $this->render('import_upload', [ 'error' => $e->getMessage() ]);
	    return;
	}

	$data = is_array($req->data) ? $req->data : [];
	$step = (string)($data['step'] ?? '');
	$aid = (string)\V::app()->var('rt.account')->_id;

	//upload: keep the file, then the preview
	if ($step === '') {
	    $file = $req->files['file'] ?? null;
	    $error = null;
	    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
	    if (empty($file) || ($file['error'] ?? 1) == UPLOAD_ERR_NO_FILE)
		$error = 'Choose a file';
	    elseif ($file['error'] > 0)
		$error = 'The file could not be uploaded';
	    elseif ($file['size'] > \util\TableImport::MAX_BYTES)
		//CLD: 2026-10-24T16:30:00+03:00: util\Format::bytes (was the MB inline)
		$error = 'The file is larger than ' . \util\Format::bytes(\util\TableImport::MAX_BYTES);
	    elseif ($ext === 'xls')
		$error = 'Old Excel files (.xls) cannot be read: save the file as .xlsx or CSV';
	    elseif (!isset(\util\TableImport::TYPES[$ext]))
		$error = 'Choose a CSV or Excel (.xlsx) file';
	    if ($error !== null) {
		$this->render('import_upload', compact('error'));
		return;
	    }

	    $token = bin2hex(random_bytes(16));
	    move_uploaded_file($file['tmp_name'], "{$dir}/{$token}.{$ext}");
	    //CLD: 2026-10-14T16:35:21+03:00: util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing)
	    file_put_contents("{$dir}/{$token}.json", \util\JSON::encode([ 'aid' => $aid, 'user' => (string)\V::app()->user->_id, 'name' => (string)$file['name'], 'ext' => $ext ]));
	    $data = [ 'token' => $token ];
	    $step = 'preview';
	}

	//the uploaded file of this user
	$token = (string)($data['token'] ?? '');
	$meta = preg_match('/^[0-9a-f]{32}$/', $token) && is_file("{$dir}/{$token}.json") ? json_decode(file_get_contents("{$dir}/{$token}.json"), true) : null;
	if (empty($meta) || $meta['aid'] !== $aid || $meta['user'] !== (string)\V::app()->user->_id || !is_file("{$dir}/{$token}.{$meta['ext']}")) {
	    $this->render('import_upload', [ 'error' => 'The uploaded file is no longer available: upload it again' ]);
	    return;
	}

	$reader = new \util\TableImport("{$dir}/{$token}.{$meta['ext']}", $meta['ext']);
	try {
	    $sheets = $reader->sheets();
	    $sheet = in_array($data['sheet'] ?? null, $sheets, true) ? $data['sheet'] : ($sheets[0] ?? null);
	    $rows = $reader->rows($sheet);
	} catch (\RuntimeException $e) {
	    $this->render('import_upload', [ 'error' => $e->getMessage() ]);
	    return;
	}
	if (empty($rows)) {
	    $this->render('import_upload', [ 'error' => 'The file has no data' . ($sheet !== null ? " (sheet {$sheet})" : '') ]);
	    return;
	}

	//header: guessed when the sheet is shown first, else as chosen
	$header = isset($data['header_set']) && ($data['sheet_shown'] ?? null) === $sheet ? !empty($data['header']) : \util\TableImport::hasHeader($rows);
	$guessed = \util\TableImport::guess($rows, $header);
	$dataRows = $header ? array_slice($rows, 1) : $rows;
	$name = trim((string)($data['name'] ?? ''));
	//from the file name, as a table name may be (letters, digits, underscore)
	if ($name === '')
	    $name = \util\TableImport::keyFor(pathinfo($meta['name'], PATHINFO_FILENAME), 0, []);

	//the columns as posted (import, or a preview that keeps them: same sheet and header), else as guessed
	$columns = $guessed;
	$posted = is_array($data['cols'] ?? null) ? $data['cols'] : null;
	if ($step === 'import' && $posted !== null) {
	    foreach($columns as $i => &$col) {
		$p = is_array($posted[$i] ?? null) ? $posted[$i] : [];
		$col['on'] = !empty($p['on']);
		$col['label'] = trim((string)($p['label'] ?? $col['label']));
		$col['key'] = trim((string)($p['key'] ?? $col['key']));
		if (($p['type'] ?? $col['type']) !== $col['type']) {
		    $col['type'] = (string)$p['type'];
		    $col['format'] = \util\TableImport::formatFor(array_column($dataRows, $i), $col['type']);
		}
	    }
	    unset($col);
	} else {
	    foreach($columns as &$col)
		$col['on'] = true;
	    unset($col);
	}

	$errors = [];
	if ($step === 'import') {
	    //CLD: 2026-10-11T20:51:55+03:00: the uploaded file is removed by importTable (its redirect ends the request)
	    $errors = $this->importTable($meta, $name, $columns, $dataRows, $header ? 2 : 1, [ "{$dir}/{$token}.{$meta['ext']}", "{$dir}/{$token}.json" ]);
	    if ($errors === null)
		return;
	}

	$types = \V::app()->om->getFieldTypes();
	unset($types['object']);
	$this->render('import_preview', compact('meta', 'token', 'sheets', 'sheet', 'header', 'name', 'columns', 'dataRows', 'types', 'errors'));
    }

    //creates the table, its columns and rows; null when done (toast + redirect to the table), else the errors [ 'name' | column index => [ messages ] ];
    //$firstRow: the file's row number of the first data row (skipped rows are reported by it); $files: the uploaded file's paths, removed once imported
    private function importTable(array $meta, $name, array $columns, array $dataRows, $firstRow, array $files) {
	$account = \V::app()->var('rt.account');
	$errors = [];

	//the table: the next free iname (as create())
	$n = \V::app()->om->count('DataTableDef', [ 'aid' => $account->_id ]);
	do {
	    $iname = 'data-table-' . ++$n . '-' . $this->project->_id;
	} while (\V::app()->om->count('DataTableDef', [ 'iname' => $iname ]) > 0);
	$dt = \V::app()->om->create('DataTableDef', [ 'aid' => $account->_id, 'name' => $name, 'iname' => $iname, 'project' => $this->project->_id ]);
	if (!$dt->validates())
	    $errors['name'] = array_merge(...array_values(array_map(fn($m) => (array)$m, (array)$dt->errors())));
	//CLD: 2026-10-24T09:45:36+03:00: the tier's limits (util\Tiers): one more table, and its rows (all the file's: some may be skipped)
	try {
	    \util\Tiers::check($account, 'datatables');
	    $limit = \util\Tiers::limit($account, 'datatable_records');
	    if ($limit !== null && count($dataRows) > $limit)
		//CLD: 2026-10-24T16:30:00+03:00: util\Format::plural (was number_format)
		throw new \util\LimitException(\util\Tiers::limitMessage($account, 'datatable_records') . ' The file has ' . \util\Format::plural(count($dataRows), 'row') . '.', 'datatable_records', $limit, 0);
	} catch (\util\LimitException $e) {
	    $errors['name'][] = $e->getMessage();
	}

	//the columns through the column form (models\datatable\FieldForm: key rules, types); select choices = the column's values
	$forms = [];
	$keys = [];
	foreach($columns as $i => $col) {
	    if (empty($col['on']))
		continue;
	    $values = [];
	    if ($col['type'] === 'select') {
		foreach(array_column($dataRows, $i) as $v) {
		    if (!\util\TableImport::isBlank($v))
			$values[\util\TableImport::text($v)] = true;
		}
		if (count($values) > 100)
		    $errors[$i][] = 'Too many different values for a select (more than 100)';
	    }
	    $form = \models\datatable\FieldForm::create([ 'objectName' => $iname, 'aid' => $account->_id ]);
	    $form->assign([ 'label' => $col['label'], 'key' => $col['key'], 'type' => $col['type'], 'choices' => implode("\n", array_keys($values)) ], true);
	    if (!$form->validates()) {
		foreach((array)$form->errors() as $key => $messages) {
		    foreach((array)$messages as $m)
			$errors[$i][] = (string)$form->schema[$key]->label . ': ' . $m;
		}
	    }
	    if (isset($keys[$col['key']]))
		$errors[$i][] = 'Key: used by another column';
	    $keys[$col['key']] = true;
	    $forms[$i] = $form;
	}
	if (empty($forms))
	    $errors['name'][] = 'Choose at least one column to import';
	if ($errors)
	    return $errors;

	$dt->save();
	foreach($forms as $form)
	    \sys\fields\Field::create($form->data())->save();
	\V::app()->om->registerDataTables();

	//the rows: values the column cannot read are left empty; rows the column rules refuse are skipped
	//CLD: 2026-10-12T13:54:43+03:00: stored with om->insertMany in batches of 500 (was the MongoDB collection directly)
	$batch = [];
	$imported = 0;
	$unread = 0;
	$skipped = [];
	foreach($dataRows as $r => $raw) {
	    $values = [];
	    foreach($forms as $i => $form) {
		$col = $columns[$i];
		$v = \util\TableImport::normalize($raw[$i] ?? null, $col['type'], $col['format'] ?? null);
		if ($v === false && !in_array($col['type'], [ 'checkbox', 'switch' ], true)) {
		    $unread++;
		    $v = null;
		}
		if ($v !== null)
		    $values[$col['key']] = $v;
	    }
	    $row = \V::app()->om->create($iname, [ 'aid' => $account->_id ]);
	    $row->assign($values);
	    if (!$row->validates()) {
		$skipped[] = $r + $firstRow;
		continue;
	    }
	    $batch[] = $row;
	    if (count($batch) >= 500) {
		$imported += \V::app()->om->insertMany($iname, $batch);
		$batch = [];
	    }
	}
	if ($batch)
	    $imported += \V::app()->om->insertMany($iname, $batch);

	$details = "{$imported} rows from {$meta['name']}" . ($skipped ? ', ' . count($skipped) . ' skipped' : '') . ($unread ? ", {$unread} " . ($unread > 1 ? 'values' : 'value') . ' left empty' : '');
	\models\AuditLog::record('datatable.import', $dt, $details);
	foreach($files as $f)
	    @unlink($f);

	//CLD: 2026-10-24T16:30:00+03:00: util\Format::plural (was the plural inline)
	$message = \util\Format::plural($imported, 'row') . ' imported';
	if ($skipped)
	    $message .= '; skipped (not valid): row' . (count($skipped) > 1 ? 's ' : ' ') . implode(', ', array_slice($skipped, 0, 10)) . (count($skipped) > 10 ? '…' : '');
	if ($unread)
	    $message .= "; {$unread} " . ($unread > 1 ? 'values' : 'value') . ' could not be read as the column type and ' . ($unread > 1 ? 'were' : 'was') . ' left empty';
	$this->addToast($skipped || $unread ? 'warning' : 'success', 'Imported', $message);
	$this->redirect(\V::app()->createUrl('/datatable/manage', [ 'id' => $dt->_id ]));
	return null;
    }

    public function update($req, $resp) {
	//CLD: 2026-10-05T21:24:12+03:00: shared lookup + permission check
	$dt = $this->findTable('DataTable::update');

	if ($req->is('post')) {
	    //CLD: 2026-10-05T20:44:56+03:00: only the form's field; `iname` is part of the data collection name and `project` must not move (was the whole POST)
	    //CLD: 2026-10-10T18:57:33+03:00: assign with permissions (the form fields the user may update; was onlyFields name)
	    $dt->assign($req->data, true);
	    if ($dt->validates()) {
		$dt->save();
		$this->addToast('success', 'OK', 'Data Table saved!');
		$this->addEvent('object::updated', [ 'object' => 'DataTableDef', 'oid' => (string)$dt->_id ]);
		$this->sendEvents();
	    }
	}
	$this->render('form', compact('dt'));
    }

    public function delete($req, $resp) {
	//CLD: 2026-10-05T21:24:12+03:00: shared lookup + permission check
	$dt = $this->findTable('DataTable::delete');

	if ($req->is('post')) {
	    $dt->delete();
	    $this->addToast('success', 'OK', 'DataTable deleted!');
	    $this->addEvent('object::deleted', [ 'object' => 'DataTableDef', 'oid' => null ]);
	    $this->sendEvents();
	}
    }

    //---------------------------------------------------------------------------------------------------------------
    //CLD: 2026-10-05T21:06:21+03:00: column designer: a column is a Field with objectName = the table's iname

    //the data table from `id`, within the current account, if the user has $permName on it (404/403 otherwise)
    //CLD: 2026-10-05T21:24:12+03:00: renamed from findTableForUpdate(), permission as parameter, reused by update/delete/manage; columns are
    //the table's schema, so the column actions keep the DataTable::update default
    private function findTable($permName = 'DataTable::update') {
	$oid = $this->getOidOrError();

	$dt = \V::app()->om->findOne('DataTableDef', [ '_id' => $oid, 'aid' => \V::app()->var('rt.account')->_id ]);
	if (empty($dt))
	    \V::app()->error(404, 'Not found');

	if (!\V::app()->user->can($permName, [ 'object' => $dt ]))
	    \V::app()->error(403, 'Unauthorized');

	return $dt;
    }

    //the column `key` of table $dt, or 404
    private function findColumnOrError($dt, $key) {
	$field = null;
	if (is_string($key) && $key !== '')
	    $field = \V::app()->om->findOne('Field', [ 'objectName' => $dt->iname, 'key' => $key ]);
	if (empty($field))
	    \V::app()->error(404, 'Column not found');
	return $field;
    }

    //CLD: 2026-10-06T09:23:42+03:00: removed validateColumn(): the checks are validation rules of models\datatable\FieldForm

    public function create_column($req, $resp) {
	$dt = $this->findTable();
	//CLD: 2026-10-06T09:23:42+03:00: FieldForm (was ColumnForm) for this table; assign with permission check + the form's own validation rules
	$form = \models\datatable\FieldForm::create([ 'objectName' => $dt->iname, 'aid' => \V::app()->var('rt.account')->_id ]);

	if ($req->is('post')) {
	    $form->assign(is_array($req->data) ? $req->data : [], true);

	    if ($form->validates()) {
		\sys\fields\Field::create($form->data())->save();

		$this->addToast('success', 'OK', 'Column created!');
		$this->redirect(\V::app()->createUrl('/datatable/manage', [ 'id' => $dt->_id ]));
	    } else {
		\V::app()->log->debug('Column::errors: ' . print_r($form->errors(), true));
	    }
	}

	$this->render('column_form', compact('dt', 'form'));
    }

    public function update_column($req, $resp) {
	$dt = $this->findTable();
	$field = $this->findColumnOrError($dt, $req->query['key'] ?? null);

	//CLD: 2026-10-06T09:23:42+03:00: FieldForm::forField: an existing field, key/type/object locked (FieldForm::afterAttachSchema), so
	//assign with permission check skips them; validates() runs the `update` rules
	//CLD: 2026-10-06T21:22:22+03:00: as an existing record: FieldForm::afterAttachSchema locks key/type/object, assign(..., true) skips them
	$form = \models\datatable\FieldForm::create($field->data(), [ 'exists' => true ]);

	if ($req->is('post')) {
	    $form->assign(is_array($req->data) ? $req->data : [], true);

	    if ($form->validates()) {
		//CLD: 2026-10-06T21:04:37+03:00: fields are loaded without a schema (Field::_init: attachSchema false), so assign() skipped
		//every key and nothing was saved; attach it first
		$field->attachSchema();
		$field->assign($form->data());
		$field->save();

		$this->addToast('success', 'OK', 'Column saved!');
		$this->redirect(\V::app()->createUrl('/datatable/manage', [ 'id' => $dt->_id ]));
	    }
	}

	$this->render('column_form', compact('dt', 'form', 'field'));
    }

    public function delete_column($req, $resp) {
	$dt = $this->findTable();
	$field = $this->findColumnOrError($dt, $req->query['key'] ?? null);

	if (!$req->is('post'))
	    \V::app()->error(400, 'malformed request: POST required');

	$key = $field->key;
	$field->delete();

	//remove the column's values from the rows
	//CLD: 2026-10-12T13:54:43+03:00: through ObjectManager (om->updateMany; was the MongoDB collection directly)
	if (\V::app()->om->isDefined($dt->iname))
	    \V::app()->om->updateMany($dt->iname, [ 'aid' => \V::app()->var('rt.account')->_id ], [ '$unset' => [ $key => '' ] ]);

	$this->addToast('success', 'OK', 'Column deleted!');
	$this->redirect(\V::app()->createUrl('/datatable/manage', [ 'id' => $dt->_id ]));
    }

    //---------------------------------------------------------------------------------------------------------------
    //CLD: 2026-10-05T21:30:59+03:00: row editing: a row is an object of the table's iname (collection "<hostname>-<iname>"); DataTable::writeData required

    //CLD: 2026-10-11T22:14:58+03:00: removed rowKeys() (never called; it used FieldForm::RESERVED_KEYS, which does not exist)

    //the row `row` (query) of table $dt within the current account, or 400/404
    private function findRowOrError($dt) {
	$rowId = $this->getOidParam('row');
	if (empty($rowId))
	    \V::app()->error(400, 'malformed request: row missing');

	$row = \V::app()->om->findOne($dt->iname, [ '_id' => $rowId, 'aid' => \V::app()->var('rt.account')->_id ]);
	if (empty($row))
	    \V::app()->error(404, 'Row not found');
	return $row;
    }

    public function create_row($req, $resp) {
	$dt = $this->findTable('DataTable::writeData');
	$row = \V::app()->om->create($dt->iname, [ 'aid' => \V::app()->var('rt.account')->_id ]);

	if ($req->is('post')) {
	    $row->assign($req->data, true);
	    //CLD: 2026-10-24T09:45:36+03:00: the tier's limit of rows in a data table (util\Tiers)
	    $over = null;
	    try {
		\util\Tiers::check(\V::app()->var('rt.account'), 'datatable_records', 1, $dt->iname);
	    } catch (\util\LimitException $e) {
		$over = $e->getMessage();
		$this->addToast('danger', 'Limit reached', $over);
	    }
	    if ($over === null && $row->validates()) {
		$row->save();
		$this->addToast('success', 'OK', 'Row added!');
		$this->addEvent('object::created', [ 'object' => $dt->iname, 'oid' => (string)$row->_id ]);
		$this->sendEvents();
	    }
	}
	$this->render('row_form', compact('dt', 'row'));
    }

    //CLD: 2026-10-11T22:16:09+03:00: the objects an object column can point to, searched as the user types (row form select with more than Field_object::CHOICES_MAX
    //targets; app.SelectPlus ajax): ?id=<table>&key=<column>&q=<text>&page=<n> -> { results: [ {id, text} ], pagination: { more } }. DataTable::writeData
    //on the table; a data table as target also needs DataTable::readData on it
    public function object_choices($req, $resp) {
	$dt = $this->findTable('DataTable::writeData');
	$row = \V::app()->om->create($dt->iname, [ 'aid' => \V::app()->var('rt.account')->_id ]);
	$field = $row->schema[(string)($req->query['key'] ?? '')] ?? null;
	if (empty($field) || $field->type !== 'object')
	    \V::app()->error(404, 'Column not found');

	$targetDef = \V::app()->om->getObjectDef((string)$field->object);
	if (is_array($targetDef) && !empty($targetDef['_id'])) {
	    $target = \V::app()->om->findOne('DataTableDef', [ '_id' => $targetDef['_id'], 'aid' => \V::app()->var('rt.account')->_id ]);
	    if (empty($target) || !\V::app()->user->can('DataTable::readData', [ 'object' => $target ]))
		\V::app()->error(403, 'Unauthorized');
	}

	[ $results, $more ] = $field->searchChoices($req->query['q'] ?? '', $req->query['page'] ?? 1);
	//CLD: 2026-10-18T12:49:28+03:00: Response::sendJSON (was write + end: sent as text/html)
	$resp->sendJSON([ 'results' => $results, 'pagination' => [ 'more' => $more ] ]);
    }

    public function update_row($req, $resp) {
	$dt = $this->findTable('DataTable::writeData');
	$row = $this->findRowOrError($dt);

	if ($req->is('post')) {
	    //CLD: 2026-10-11T22:15:32+03:00: with the permission check, as create_row (only the form's columns the user may update; without it any schema field could be
	    //posted, e.g. `aid`, `created_at`)
	    $row->assign($req->data, true);
	    if ($row->validates()) {
		$row->save();
		$this->addToast('success', 'OK', 'Row saved!');
		$this->addEvent('object::updated', [ 'object' => $dt->iname, 'oid' => (string)$row->_id ]);
		$this->sendEvents();
	    }
	}
	$this->render('row_form', compact('dt', 'row'));
    }

    public function delete_row($req, $resp) {
	$dt = $this->findTable('DataTable::writeData');
	$row = $this->findRowOrError($dt);

	if (!$req->is('post'))
	    \V::app()->error(400, 'malformed request: POST required');

	$row->delete();
	$this->addToast('success', 'OK', 'Row deleted!');
	$this->addEvent('object::deleted', [ 'object' => $dt->iname, 'oid' => (string)$row->_id ]);
	$this->sendEvents();
    }

    //---------------------------------------------------------------------------------------------------------------
    //CLD: 2026-10-30T17:18:20+02:00: saved views (models\DataTableView: a named filter of the table), shared by the users with DataTable::readData on the table; JSON answers
    //for the grid's Filter (app.dataview.js), errors as { error }

    //a view as the grid uses it
    private function viewData($view) {
	return [ 'id' => (string)$view->_id, 'name' => (string)$view->name, 'filter' => array_values((array)$view->filter) ];
    }

    //?id=<table> -> { views: [ { id, name, filter } ] }, by name
    public function views($req, $resp) {
	$dt = $this->findTable('DataTable::readData');
	$views = [];
	foreach(\V::app()->om->find('DataTableView', [ 'table' => $dt->_id, 'aid' => $dt->aid ], [ 'sort' => [ 'name' => 1 ] ]) as $view)
	    $views[] = $this->viewData($view);
	$resp->sendJSON([ 'views' => $views ]);
    }

    //POST ?id=<table>, name, filter (JSON: [ { field, op, value } ]) -> { view }; the name unique within the table, the fields its filterable columns
    public function create_view($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$dt = $this->findTable('DataTable::readData');

	$name = trim((string)($req->data['name'] ?? ''));
	if ($name === '' || mb_strlen($name) > 64)
	    $resp->sendJSON([ 'error' => 'A name is required (at most 64 characters)' ], true, 422);
	if (\V::app()->om->count('DataTableView', [ 'table' => $dt->_id, 'aid' => $dt->aid, 'name' => $name ]) > 0)
	    $resp->sendJSON([ 'error' => 'A view named "' . $name . '" already exists' ], true, 422);

	$columns = $dt->columns();
	$filter = [];
	foreach((array)json_decode((string)($req->data['filter'] ?? ''), true) as $row) {
	    if (!is_array($row) || !is_string($row['field'] ?? null) || !isset($columns[$row['field']]) || !is_string($row['op'] ?? null))
		continue;
	    $filter[] = [ 'field' => $row['field'], 'op' => $row['op'], 'value' => is_scalar($row['value'] ?? null) ? (string)$row['value'] : '' ];
	}
	if (empty($filter))
	    $resp->sendJSON([ 'error' => 'The filter is empty' ], true, 422);

	$view = \V::app()->om->create('DataTableView', [
	    'aid' => $dt->aid,
	    'table' => $dt->_id,
	    'name' => $name,
	    'filter' => $filter,
	    'user' => \V::app()->user->_id,
	]);
	if (!$view->validates())
	    $resp->sendJSON([ 'error' => 'The view is not valid' ], true, 422);
	$view->save();
	$resp->sendJSON([ 'view' => $this->viewData($view) ]);
    }

    //POST ?id=<table>&view=<view> -> { deleted: <view id> }
    public function delete_view($req, $resp) {
	if (!$req->is('post'))
	    \V::app()->error(405, 'Method not allowed');
	$dt = $this->findTable('DataTable::readData');
	$viewId = $this->getOidParam('view');
	if (empty($viewId))
	    \V::app()->error(400, 'malformed request: view missing');

	$view = \V::app()->om->findOne('DataTableView', [ '_id' => $viewId, 'table' => $dt->_id, 'aid' => $dt->aid ]);
	if (empty($view))
	    $resp->sendJSON([ 'error' => 'The view no longer exists' ], true, 404);
	$view->delete();
	$resp->sendJSON([ 'deleted' => $viewId ]);
    }

    public function manage($req, $resp) {
	//CLD: 2026-10-05T21:24:12+03:00: shared lookup + permission check; also restricts to the current account (was findById)
	//CLD: 2026-10-11T22:10:29+03:00: viewing the table and its rows needs DataTable::readData (was DataTable::update); the view offers the column designer only with
	//DataTable::update and the row editing only with DataTable::writeData
	$dt = $this->findTable('DataTable::readData');

	if (!empty($req->query['data'])) {
	    $response = $this->__data($dt->iname);
	    //CLD: 2026-10-18T12:49:28+03:00: Response::sendJSON (was write + end: sent as text/html)
	    $resp->sendJSON($response);
	}

	$this->render('manage', compact('dt'));
    }

}