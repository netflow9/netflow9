<?php

/*
    CLD: 2026-10-28T10:00:00+02:00: new: moves a project's workflows, credentials and data tables to another installation (e.g. development -> pre-production) as one
    JSON file (ProjectController::export / import, project settings: Export / import).

    The file: { format: netflow9-transfer, version, exported_at, source: { url, project }, secrets, workflows, credentials, datatables, refs }
	workflows: [ { id, name, icon, nodes } ]			the draft's steps (imported as drafts: publish them on the new server)
	credentials: [ { id, name, type, fields, secrets? } ]		type: the variant (CredentialBasicAuth, ...); secrets: the encrypted fields, only in a sealed file
	datatables: [ { id, iname, name, columns, rows? } ]		columns: the Field records (key, type, label, ...); rows as MongoDB canonical extended JSON (types kept)
	refs: { credentials: { id => { name, type } }, datatables: { id => { name, iname } }, workflows: { id => { name } } }
									what the exported items point to (step settings, object columns), found again on the new server by name
    With a passphrase the file is sealed: { format, version, exported_at, sealed: { kdf, iterations, salt, cipher, iv, tag, data } } (PBKDF2-SHA256 + AES-256-GCM), and
    holds the credentials' secrets; without one the secrets are left out (entered again after the import). APP_SECRET is never needed: each server encrypts with its own.

    Import: plan() says, for each item, whether one of that name is in the project and what can be done (create, replace / update, copy, skip); apply() does the chosen
    actions: tables first (columns, rows), then credentials, then workflows, whose step settings get the new servers' ids (remap: any setting text equal to a
    known id). Webhook / form / email triggers keep their token, unless another workflow of the account has it (then a new one); their URLs are this server's.
    Not moved: files, users (e.g. a task's assignee), executions, versions.
*/
namespace util;

class Transfer {
    const FORMAT = 'netflow9-transfer';
    const VERSION = 1;
    //the largest file accepted for an import
    const MAX_BYTES = 64 * 1024 * 1024;
    const KDF_ITERATIONS = 600000;
    const CIPHER = 'aes-256-gcm';

    //a credential's keys that stay on its server: the record's own, its project, its last check
    const CREDENTIAL_SKIP = [ '_id', 'aid', 'project', '__variant', 'created_at', 'updated_at', 'status', 'status_message', 'checked_at' ];
    //a column's (Field) keys that are its table's
    const COLUMN_SKIP = [ '_id', 'aid', 'objectName', 'created_at', 'updated_at' ];

    // ------------------------------------------------------------------------------------
    //export

    //the credentials and tables of $project the steps of $workflows point to: [ credentials, tables ] (models)
    public static function usedBy($project, array $workflows) {
	$om = \V::app()->om;
	$texts = [];
	foreach($workflows as $wf)
	    self::collectTexts(self::configsOf($wf->nodes), $texts);
	$ids = array_values(array_filter(array_keys($texts), fn($t) => \util\Validator::rule('oid', (string)$t)));
	if (empty($ids))
	    return [ [], [] ];
	$query = [ '_id' => [ '$in' => array_map(fn($id) => $om->to_oid($id), $ids) ], 'aid' => $project->aid, 'project' => $project->_id ];
	return [ iterator_to_array($om->find('Credential', $query), false), iterator_to_array($om->find('DataTableDef', $query), false) ];
    }

    //the file's text; $rows: ids of the tables exported with their rows; $passphrase: '' = not sealed, without the credentials' secrets
    public static function export($project, array $workflows, array $credentials, array $tables, array $rows = [], $passphrase = '') {
	$om = \V::app()->om;
	$sealed = (string)$passphrase !== '';
	$bundle = [
	    'format' => self::FORMAT,
	    'version' => self::VERSION,
	    'exported_at' => date(DATE_ATOM),
	    'source' => [ 'url' => \V::app()->createAbsoluteUrl('/'), 'project' => (string)$project->name ],
	    'secrets' => $sealed,
	    'workflows' => [],
	    'credentials' => [],
	    'datatables' => [],
	    'refs' => [ 'credentials' => [], 'datatables' => [], 'workflows' => [] ],
	];

	foreach($workflows as $wf)
	    $bundle['workflows'][] = [ 'id' => (string)$wf->_id, 'name' => (string)$wf->name, 'icon' => (string)$wf->icon, 'nodes' => self::plain(is_array($wf->nodes) ? $wf->nodes : []) ];

	foreach($credentials as $c) {
	    $encrypted = $c->encryptedFields();
	    $item = [ 'id' => (string)$c->_id, 'name' => (string)$c->name, 'type' => $c->getObjectName(), 'fields' => [] ];
	    foreach($c->data() as $key => $value) {
		if (in_array($key, self::CREDENTIAL_SKIP, true) || !isset($c->schema[$key]))
		    continue;
		if (in_array($key, $encrypted, true)) {
		    if ($sealed && $value !== null && $value !== '')
			$item['secrets'][$key] = self::plain($value);
		} else
		    $item['fields'][$key] = self::plain($value);
	    }
	    $bundle['credentials'][] = $item;
	}

	$rows = array_map('strval', $rows);
	foreach($tables as $t) {
	    $item = [ 'id' => (string)$t->_id, 'iname' => (string)$t->iname, 'name' => (string)$t->name, 'columns' => [] ];
	    foreach($om->find('Field', [ 'objectName' => $t->iname ], [ 'sort' => [ '_id' => 1 ] ]) as $f)
		$item['columns'][] = self::plain(array_diff_key($f->data(), array_flip(self::COLUMN_SKIP)));
	    if (in_array((string)$t->_id, $rows, true)) {
		if (!$om->isDefined($t->iname))
		    $om->registerDataTables();
		$item['rows'] = [];
		foreach($om->find($t->iname, [ 'aid' => $project->aid ], [ 'sort' => [ '_id' => 1 ] ]) as $row) {
		    $doc = $row->storageDocument($row->data());
		    unset($doc['aid']);
		    $item['rows'][] = json_decode(\MongoDB\BSON\Document::fromPHP($doc)->toCanonicalExtendedJSON(), true);
		}
	    }
	    $bundle['datatables'][] = $item;
	}

	//what the exported items point to: the ids in the step settings, the tables of the object columns
	$texts = [];
	foreach($bundle['workflows'] as $wf)
	    self::collectTexts(self::configsOf($wf['nodes']), $texts);
	$ids = array_values(array_filter(array_keys($texts), fn($t) => \util\Validator::rule('oid', (string)$t)));
	$inames = [];
	foreach($bundle['datatables'] as $t) {
	    foreach($t['columns'] as $col) {
		if (($col['type'] ?? '') === 'object' && is_string($col['object'] ?? null))
		    $inames[] = $col['object'];
	    }
	}
	$oids = array_map(fn($id) => $om->to_oid($id), $ids);
	$inProject = [ 'aid' => $project->aid, 'project' => $project->_id ];
	foreach($om->find('Credential', [ '_id' => [ '$in' => $oids ] ] + $inProject) as $c)
	    $bundle['refs']['credentials'][(string)$c->_id] = [ 'name' => (string)$c->name, 'type' => $c->getObjectName() ];
	foreach($om->find('DataTableDef', [ '$or' => [ [ '_id' => [ '$in' => $oids ] ], [ 'iname' => [ '$in' => $inames ] ] ] ] + $inProject) as $t)
	    $bundle['refs']['datatables'][(string)$t->_id] = [ 'name' => (string)$t->name, 'iname' => (string)$t->iname ];
	foreach($om->find('Workflow', [ '_id' => [ '$in' => $oids ] ] + $inProject) as $w)
	    $bundle['refs']['workflows'][(string)$w->_id] = [ 'name' => (string)$w->name ];

	$text = \util\JSON::encode($bundle, JSON_PRETTY_PRINT);
	return $sealed ? self::seal($text, (string)$passphrase, $bundle['exported_at']) : $text;
    }

    //a value as plain JSON data (BSON values: dates as ISO 8601, ids as text)
    private static function plain($value) {
	if ($value instanceof \MongoDB\BSON\UTCDateTime)
	    return $value->toDateTime()->format(DATE_ATOM);
	if ($value instanceof \MongoDB\BSON\ObjectId)
	    return (string)$value;
	if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray)
	    $value = $value->getArrayCopy();
	if (is_array($value)) {
	    foreach($value as $k => $v)
		$value[$k] = self::plain($v);
	}
	return $value;
    }

    //the step settings of $nodes (a workflow's steps and connections)
    private static function configsOf($nodes) {
	$configs = [];
	foreach((is_array($nodes) ? $nodes : []) as $n) {
	    if (is_array($n) && !empty($n['isNode']) && is_array($n['config'] ?? null))
		$configs[] = $n['config'];
	}
	return $configs;
    }

    //every text in $value, as keys of $texts
    private static function collectTexts($value, array &$texts) {
	if (is_string($value))
	    $texts[$value] = true;
	elseif (is_array($value)) {
	    foreach($value as $v)
		self::collectTexts($v, $texts);
	}
    }

    //$value with each text that is a key of $ids replaced by its value
    private static function remap($value, array $ids) {
	if (is_string($value))
	    return $ids[$value] ?? $value;
	if (is_array($value)) {
	    foreach($value as $k => $v)
		$value[$k] = self::remap($v, $ids);
	}
	return $value;
    }

    private static function seal($text, $passphrase, $exportedAt) {
	$salt = random_bytes(16);
	$iv = random_bytes(12);
	$key = hash_pbkdf2('sha256', $passphrase, $salt, self::KDF_ITERATIONS, 32, true);
	$tag = '';
	$data = openssl_encrypt($text, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
	if ($data === false)
	    throw new \RuntimeException('The file could not be encrypted');
	return \util\JSON::encode([
	    'format' => self::FORMAT,
	    'version' => self::VERSION,
	    'exported_at' => $exportedAt,
	    'sealed' => [ 'kdf' => 'pbkdf2-sha256', 'iterations' => self::KDF_ITERATIONS, 'salt' => base64_encode($salt), 'cipher' => self::CIPHER,
		'iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'data' => base64_encode($data) ],
	], JSON_PRETTY_PRINT);
    }

    // ------------------------------------------------------------------------------------
    //import

    //whether the file's text is sealed (needs its passphrase); null when it is not an export file
    public static function isSealed($text) {
	$data = json_decode((string)$text, true);
	if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT)
	    return null;
	return isset($data['sealed']);
    }

    //the file's contents (opened with $passphrase when sealed); \RuntimeException with a message for the user
    public static function open($text, $passphrase = '') {
	$data = json_decode((string)$text, true);
	if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT)
	    throw new \RuntimeException('This is not an export file of NetFlow9 (Project settings: Export / import)');
	if ((int)($data['version'] ?? 0) > self::VERSION)
	    throw new \RuntimeException('The file was made by a newer version of NetFlow9: update this server first');

	if (isset($data['sealed'])) {
	    $s = is_array($data['sealed']) ? $data['sealed'] : [];
	    if ((string)$passphrase === '')
		throw new \RuntimeException('The file is protected by a passphrase: enter it');
	    if (($s['kdf'] ?? '') !== 'pbkdf2-sha256' || ($s['cipher'] ?? '') !== self::CIPHER)
		throw new \RuntimeException('The file is protected in a way this server cannot read');
	    $iterations = (int)($s['iterations'] ?? 0);
	    if ($iterations < 10000 || $iterations > 10000000)
		throw new \RuntimeException('The file is damaged');
	    $key = hash_pbkdf2('sha256', (string)$passphrase, (string)base64_decode((string)($s['salt'] ?? '')), $iterations, 32, true);
	    $text = openssl_decrypt((string)base64_decode((string)($s['data'] ?? '')), self::CIPHER, $key, OPENSSL_RAW_DATA,
		(string)base64_decode((string)($s['iv'] ?? '')), (string)base64_decode((string)($s['tag'] ?? '')));
	    if ($text === false)
		throw new \RuntimeException('Wrong passphrase, or the file was changed');
	    $data = json_decode($text, true);
	    if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT)
		throw new \RuntimeException('The file is damaged');
	}

	foreach([ 'workflows', 'credentials', 'datatables' ] as $list) {
	    $data[$list] = array_values(array_filter(is_array($data[$list] ?? null) ? $data[$list] : [], fn($i) => is_array($i) && trim((string)($i['name'] ?? '')) !== ''));
	}
	$refs = is_array($data['refs'] ?? null) ? $data['refs'] : [];
	foreach([ 'credentials', 'datatables', 'workflows' ] as $list)
	    $data['refs'][$list] = is_array($refs[$list] ?? null) ? $refs[$list] : [];
	return $data;
    }

    //the labels of the actions
    const ACTIONS = [
	'create' => 'Create',
	'replace' => 'Replace the existing one',
	'update' => 'Update its columns, keep its rows',
	'update_rows' => 'Update its columns, replace its rows',
	'copy' => 'Create a copy',
	'skip' => 'Skip (keep this server\'s)',
    ];

    //for each item of $bundle: [ 'workflows' | 'credentials' | 'datatables' => [ index => [ name, label, existing (model|null), actions [ key => label ], default, notes [ ] ] ] ],
    //+ 'warnings': what the steps point to and is found neither in the file nor in $project; $can($list, $action, $existing): whether the user may (else not offered)
    public static function plan(array $bundle, $project, ?\Closure $can = null) {
	$om = \V::app()->om;
	$inProject = [ 'aid' => $project->aid, 'project' => $project->_id ];
	$plan = [ 'workflows' => [], 'credentials' => [], 'datatables' => [], 'warnings' => [] ];
	$pick = fn(array $keys) => array_intersect_key(self::ACTIONS, array_flip($keys));

	foreach($bundle['datatables'] as $i => $t) {
	    $existing = $om->findOne('DataTableDef', [ 'name' => (string)$t['name'] ] + $inProject);
	    $hasRows = is_array($t['rows'] ?? null);
	    $notes = [ \util\Format::plural(count(is_array($t['columns'] ?? null) ? $t['columns'] : []), 'column') . ($hasRows ? ', ' . \util\Format::plural(count($t['rows']), 'row') : ', no rows') ];
	    if ($existing)
		$actions = $pick($hasRows ? [ 'update', 'update_rows', 'copy', 'skip' ] : [ 'update', 'copy', 'skip' ]);
	    else
		$actions = $pick([ 'create', 'skip' ]);
	    $plan['datatables'][$i] = [ 'name' => (string)$t['name'], 'label' => (string)$t['name'], 'existing' => $existing, 'actions' => $actions,
		'default' => $existing ? 'update' : 'create', 'notes' => $notes ];
	}

	foreach($bundle['credentials'] as $i => $c) {
	    $type = (string)($c['type'] ?? '');
	    $class = \models\Credential::classFor($type);
	    $existing = $om->findOne('Credential', [ 'name' => (string)$c['name'] ] + $inProject);
	    $notes = [];
	    if (!$class || !\util\Tiers::allows($project->aid, $type)) {
		$actions = $pick([ 'skip' ]);
		$notes[] = 'its type (' . $type . ') is not available on this server';
	    } elseif ($existing && $existing->getObjectName() !== $type) {
		$actions = $pick([ 'copy', 'skip' ]);
		$notes[] = 'a credential of another type (' . $existing::$label . ') has this name';
	    } else
		$actions = $pick($existing ? [ 'replace', 'copy', 'skip' ] : [ 'create', 'skip' ]);
	    if ($class && empty($c['secrets']) && $om->create($type, [])->encryptedFields())
		$notes[] = 'without its secrets (the file has none): enter them after the import';
	    if ($class && $class::isOAuth())
		$notes[] = 'OAuth: add this server\'s redirect URI (' . $class::redirectUri() . ') to the app at the provider';
	    $plan['credentials'][$i] = [ 'name' => (string)$c['name'], 'label' => (string)$c['name'] . ($class ? ' (' . $class::$label . ')' : ''), 'existing' => $existing,
		'actions' => $actions, 'default' => isset($actions['create']) ? 'create' : 'skip', 'notes' => $notes ];
	}

	foreach($bundle['workflows'] as $i => $w) {
	    $same = iterator_to_array($om->find('Workflow', [ 'name' => (string)$w['name'] ] + $inProject, [ 'sort' => [ '_id' => 1 ] ]), false);
	    $existing = $same[0] ?? null;
	    $notes = [];
	    $steps = count(array_filter(is_array($w['nodes'] ?? null) ? $w['nodes'] : [], fn($n) => is_array($n) && !empty($n['isNode'])));
	    $notes[] = \util\Format::plural($steps, 'step') . '; imported as a draft';
	    if (count($same) > 1)
		$notes[] = count($same) . ' workflows have this name: the oldest is replaced';
	    if ($existing && $existing->status === \models\Workflow::PUBLISHED)
		$notes[] = 'the existing one is published: its live version keeps running until you publish the new draft';
	    $plan['workflows'][$i] = [ 'name' => (string)$w['name'], 'label' => (string)$w['name'], 'existing' => $existing,
		'actions' => $pick($existing ? [ 'replace', 'copy', 'skip' ] : [ 'create', 'skip' ]), 'default' => $existing ? 'replace' : 'create', 'notes' => $notes ];
	}

	//the actions the user may not do are not offered (skip always is)
	if ($can) {
	    foreach([ 'workflows', 'credentials', 'datatables' ] as $list) {
		foreach($plan[$list] as $i => $item) {
		    $item['actions'] = array_filter($item['actions'], fn($a) => $a === 'skip' || $can($list, $a, $item['existing']), ARRAY_FILTER_USE_KEY);
		    if (!isset($item['actions'][$item['default']]))
			$item['default'] = 'skip';
		    $plan[$list][$i] = $item;
		}
	    }
	}

	//what the steps and columns point to, not in the file: found in the project by name?
	$inFile = [];
	foreach([ 'credentials', 'datatables', 'workflows' ] as $list) {
	    foreach($bundle[$list] as $item)
		$inFile[$list][(string)($item['id'] ?? '')] = true;
	}
	$kinds = [ 'credentials' => [ 'Credential', 'credential' ], 'datatables' => [ 'DataTableDef', 'data table' ], 'workflows' => [ 'Workflow', 'workflow' ] ];
	foreach($kinds as $list => [ $objectName, $what ]) {
	    foreach($bundle['refs'][$list] as $id => $ref) {
		if (isset($inFile[$list][(string)$id]) || !is_array($ref))
		    continue;
		if (!$om->findOne($objectName, [ 'name' => (string)($ref['name'] ?? '') ] + $inProject))
		    $plan['warnings'][] = "The {$what} `" . ($ref['name'] ?? $id) . "` is used but is neither in the file nor in this project: the steps using it need another one";
	    }
	}
	return $plan;
    }

    //does the $choices ([ 'workflows' | 'credentials' | 'datatables' => [ index => action ] ]; not among the item's actions: its default) in $project ($can: as plan());
    //returns [ 'done' => [ lines ], 'warnings' => [ lines ] ]
    public static function apply(array $bundle, $project, array $choices, ?\Closure $can = null) {
	$om = \V::app()->om;
	$aid = $project->aid;
	$inProject = [ 'aid' => $aid, 'project' => $project->_id ];
	$plan = self::plan($bundle, $project, $can);
	$done = [];
	$warnings = $plan['warnings'];
	$action = function($list, $i) use ($plan, $choices) {
	    $a = (string)($choices[$list][$i] ?? '');
	    return isset($plan[$list][$i]['actions'][$a]) ? $a : $plan[$list][$i]['default'];
	};

	//source id / iname -> this server's: first what is in the project by name (refs, skipped items), then what is imported
	$ids = [];
	$inames = [];
	foreach($bundle['refs']['credentials'] as $id => $ref) {
	    if ($c = $om->findOne('Credential', [ 'name' => (string)($ref['name'] ?? '') ] + $inProject))
		$ids[(string)$id] = (string)$c->_id;
	}
	foreach($bundle['refs']['datatables'] as $id => $ref) {
	    if ($t = $om->findOne('DataTableDef', [ 'name' => (string)($ref['name'] ?? '') ] + $inProject)) {
		$ids[(string)$id] = (string)$t->_id;
		if (isset($ref['iname']))
		    $inames[(string)$ref['iname']] = (string)$t->iname;
	    }
	}
	foreach($bundle['refs']['workflows'] as $id => $ref) {
	    if ($w = $om->findOne('Workflow', [ 'name' => (string)($ref['name'] ?? '') ] + $inProject))
		$ids[(string)$id] = (string)$w->_id;
	}

	//data tables: the tables first (their inames are needed by the object columns), then the columns, then the rows
	$tables = [];
	foreach($bundle['datatables'] as $i => $t) {
	    $a = $action('datatables', $i);
	    $existing = $plan['datatables'][$i]['existing'];
	    if ($a === 'skip') {
		if ($existing) {
		    $ids[(string)($t['id'] ?? '')] = (string)$existing->_id;
		    $inames[(string)($t['iname'] ?? '')] = (string)$existing->iname;
		}
		continue;
	    }
	    if ($a === 'update' || $a === 'update_rows')
		$dt = $existing;
	    else {
		try {
		    \util\Tiers::check($aid, 'datatables');
		} catch (\util\LimitException $e) {
		    $warnings[] = "Data table `{$t['name']}` not imported: " . $e->getMessage();
		    continue;
		}
		$name = $a === 'copy' ? self::freeName('DataTableDef', (string)$t['name'], $inProject, '_') : (string)$t['name'];
		$n = $om->count('DataTableDef', [ 'aid' => $aid ]);
		do {
		    $iname = 'data-table-' . ++$n . '-' . $project->_id;
		} while ($om->count('DataTableDef', [ 'iname' => $iname ]) > 0);
		$dt = $om->create('DataTableDef', [ 'aid' => $aid, 'name' => $name, 'iname' => $iname, 'project' => $project->_id ]);
		if (!$dt->validates()) {
		    $warnings[] = "Data table `{$t['name']}` not imported: " . self::errorText($dt);
		    continue;
		}
		$dt->save();
	    }
	    $ids[(string)($t['id'] ?? '')] = (string)$dt->_id;
	    $inames[(string)($t['iname'] ?? '')] = (string)$dt->iname;
	    $tables[$i] = [ $dt, $a ];
	}

	foreach($tables as $i => [ $dt, $a ]) {
	    $t = $bundle['datatables'][$i];
	    $added = 0;
	    $changed = 0;
	    foreach((is_array($t['columns'] ?? null) ? $t['columns'] : []) as $col) {
		if (!is_array($col) || !is_string($col['key'] ?? null) || $col['key'] === '')
		    continue;
		$col = array_diff_key($col, array_flip(self::COLUMN_SKIP));
		if (($col['type'] ?? '') === 'object' && is_string($col['object'] ?? null) && !$om->isDefined($col['object'])) {
		    if (!isset($inames[$col['object']])) {
			$warnings[] = "Data table `{$dt->name}`: column `{$col['key']}` not imported: it points to a data table that is not on this server";
			continue;
		    }
		    $col['object'] = $inames[$col['object']];
		}
		$field = $om->findOne('Field', [ 'objectName' => $dt->iname, 'key' => $col['key'] ]);
		if ($field) {
		    if ((string)$field->type !== (string)($col['type'] ?? '')) {
			$warnings[] = "Data table `{$dt->name}`: column `{$col['key']}` kept: it is a {$field->type} column here, a " . ($col['type'] ?? '?') . ' one in the file';
			continue;
		    }
		    $field->attachSchema();
		    $field->assign($col);
		    $field->save();
		    $changed++;
		} else {
		    \sys\fields\Field::create($col + [ 'objectName' => $dt->iname, 'aid' => $aid ])->save();
		    $added++;
		}
	    }
	    $om->resetCache();
	    $om->registerDataTables();
	    $line = ($a === 'create' || $a === 'copy' ? 'Data table created' : 'Data table updated') . ": {$dt->name} (" . \util\Format::plural($added, 'column') . ' added'
		. ($changed ? ", {$changed} updated" : '') . ')';

	    //the rows (their ids kept: object columns of other tables point to them)
	    if (is_array($t['rows'] ?? null) && $a !== 'update') {
		try {
		    $limit = \util\Tiers::limit($aid, 'datatable_records');
		    if ($limit !== null && count($t['rows']) > $limit)
			throw new \util\LimitException(\util\Tiers::limitMessage($aid, 'datatable_records'), 'datatable_records', $limit, 0);
		    if ($a === 'update_rows' && $om->isDefined($dt->iname))
			$om->drop($dt->iname);
		    $batch = [];
		    $imported = 0;
		    foreach($t['rows'] as $raw) {
			$doc = \MongoDB\BSON\Document::fromJSON(\util\JSON::encode($raw))->toPHP([ 'root' => 'array', 'document' => 'array', 'array' => 'array' ]);
			$batch[] = $om->create($dt->iname, [ 'aid' => $aid ] + $doc);
			if (count($batch) >= 500) {
			    $imported += $om->insertMany($dt->iname, $batch);
			    $batch = [];
			}
		    }
		    if ($batch)
			$imported += $om->insertMany($dt->iname, $batch);
		    $line .= ', ' . \util\Format::plural($imported, 'row');
		} catch (\util\LimitException $e) {
		    $warnings[] = "Data table `{$dt->name}`: rows not imported: " . $e->getMessage();
		} catch (\Throwable $e) {
		    $warnings[] = "Data table `{$dt->name}`: rows not imported: " . $e->getMessage();
		}
	    }
	    $done[] = $line;
	}

	//credentials
	foreach($bundle['credentials'] as $i => $c) {
	    $a = $action('credentials', $i);
	    $existing = $plan['credentials'][$i]['existing'];
	    if ($a === 'skip') {
		if ($existing && $existing->getObjectName() === (string)($c['type'] ?? ''))
		    $ids[(string)($c['id'] ?? '')] = (string)$existing->_id;
		continue;
	    }
	    $type = (string)$c['type'];
	    $cred = $a === 'replace' ? $existing : $om->create($type, [ 'aid' => $aid, 'project' => $project->_id,
		'name' => $a === 'copy' ? self::freeName('Credential', (string)$c['name'], $inProject, ' ') : (string)$c['name'] ]);
	    $values = (is_array($c['fields'] ?? null) ? $c['fields'] : []) + (is_array($c['secrets'] ?? null) ? $c['secrets'] : []);
	    unset($values['name']);
	    foreach($values as $key => $value) {
		if (!in_array($key, self::CREDENTIAL_SKIP, true) && isset($cred->schema[$key]))
		    $cred->$key = $cred->schema[$key]->cast($value);
	    }
	    //secrets not in the file: entered after the import (only their errors are let through)
	    $encrypted = $cred->encryptedFields();
	    $cred->validates();
	    $errors = array_diff_key((array)$cred->errors(), empty($c['secrets']) ? array_flip($encrypted) : []);
	    if ($errors) {
		foreach($errors as $key => $messages)
		    $warnings[] = "Credential `{$c['name']}` not imported: {$key}: " . implode(', ', (array)$messages);
		continue;
	    }
	    $cred->status = 'new';
	    $cred->save();
	    $ids[(string)($c['id'] ?? '')] = (string)$cred->_id;
	    $done[] = ($a === 'replace' ? 'Credential replaced' : 'Credential created') . ": {$cred->name}" . (empty($c['secrets']) && $encrypted ? ' (enter its secrets)' : '');
	}

	//workflows: the ids of the new ones first (steps may point to each other's workflows), then their steps with this server's ids
	$targets = [];
	foreach($bundle['workflows'] as $i => $w) {
	    $a = $action('workflows', $i);
	    $existing = $plan['workflows'][$i]['existing'];
	    if ($a === 'skip') {
		if ($existing)
		    $ids[(string)($w['id'] ?? '')] = (string)$existing->_id;
		continue;
	    }
	    $wf = $a === 'replace' ? $existing : $om->create('Workflow', [ '_id' => new \MongoDB\BSON\ObjectId(), 'aid' => $aid, 'project' => $project->_id,
		'name' => $a === 'copy' ? self::freeName('Workflow', (string)$w['name'], $inProject, ' ') : (string)$w['name'] ]);
	    $ids[(string)($w['id'] ?? '')] = (string)$wf->_id;
	    $targets[$i] = [ $wf, $a ];
	}

	//the step settings' choices (tables, credentials of the project) are read again: some were just imported
	$om->resetCache();
	$tokens = self::tokensInUse($aid);
	$defs = \models\WfNode::getAll();
	foreach($targets as $i => [ $wf, $a ]) {
	    //a replaced workflow's triggers keep the tokens they have here (their URLs do not change at each import)
	    $here = [];
	    foreach(($a === 'replace' && is_array($wf->nodes) ? $wf->nodes : []) as $n) {
		if (is_array($n) && is_string($n['config']['token'] ?? null))
		    $here[(string)($n['wfid'] ?? '') . '|' . ($n['fqname'] ?? '')] = $n['config']['token'];
	    }
	    $nodes = [];
	    foreach((is_array($bundle['workflows'][$i]['nodes'] ?? null) ? $bundle['workflows'][$i]['nodes'] : []) as $key => $n) {
		if (is_array($n) && !empty($n['isNode']) && is_array($n['config'] ?? null)) {
		    $n['config'] = self::remap($n['config'], $ids);
		    $def = $defs[$n['fqname'] ?? ''] ?? null;
		    $class = $def ? $om->getObjectClass($def['objectName']) : null;
		    if ($class && method_exists($class, 'tokenData') && is_string($n['config']['token'] ?? null))
			$n['config'] = self::tokenConfig($class, $n['config'], (string)$wf->_id, $tokens, $here[(string)($n['wfid'] ?? '') . '|' . $n['fqname']] ?? null);
		}
		$nodes[$key] = $n;
	    }
	    $wf->nodes = $nodes;
	    if (!empty($bundle['workflows'][$i]['icon']))
		$wf->icon = (string)$bundle['workflows'][$i]['icon'];
	    if (!$wf->validatesDraft()) {
		$warnings[] = "Workflow `{$bundle['workflows'][$i]['name']}` not imported: " . self::errorText($wf);
		continue;
	    }
	    //the steps as in the file (normalized when saved on their server; validatesDraft casts selects by the choices of the user's current project: a value not among them was lost)
	    $wf->nodes = $nodes;
	    $wf->save();
	    $done[] = ($a === 'replace' ? 'Workflow replaced (draft)' : 'Workflow created') . ": {$wf->name}" . ($wf->stepIssues ? ' (' . \util\Format::plural(count($wf->stepIssues), 'step issue') . ': see the designer)' : '');
	}

	return [ 'done' => $done, 'warnings' => $warnings ];
    }

    //$name, else `$name (2)`, `$name (3)`, ... (tables, whose names are identifiers: name_2, ...) the first not used in the project
    private static function freeName($objectName, $name, array $inProject, $sep) {
	$om = \V::app()->om;
	$n = 1;
	$try = $name;
	while ($om->count($objectName, [ 'name' => $try ] + $inProject) > 0)
	    $try = $sep === '_' ? $name . '_' . ++$n : $name . ' (' . ++$n . ')';
	return $try;
    }

    private static function errorText($model) {
	$list = [];
	foreach((array)$model->errors() as $key => $messages)
	    $list[] = "{$key}: " . implode(', ', (array)$messages);
	return implode('; ', $list);
    }

    //the trigger tokens of account $aid: [ token => [ workflow id => true ] ] (drafts and published versions)
    private static function tokensInUse($aid) {
	$om = \V::app()->om;
	$tokens = [];
	foreach($om->find('Workflow', [ 'aid' => $aid ], [ 'projection' => [ '_id' => 1, 'aid' => 1, 'nodes' => 1 ] ]) as $w) {
	    foreach(self::configsOf($w->nodes) as $config) {
		if (is_string($config['token'] ?? null))
		    $tokens[$config['token']][(string)$w->_id] = true;
	    }
	}
	foreach($om->find('TriggerToken', [ 'aid' => $aid ]) as $t)
	    $tokens[(string)$t->token][(string)$t->wf] = true;
	return $tokens;
    }

    //a trigger's settings in workflow $wfId: the token it has here ($here, a replaced workflow's), else the file's, unless another workflow has it (then a new one); the
    //values made with the token (e.g. its URL) are this server's (the class's initConfig, with the kept token)
    private static function tokenConfig($class, array $config, $wfId, array &$tokens, $here = null) {
	$fresh = $class::initConfig();
	$new = is_string($fresh['token'] ?? null) ? $fresh['token'] : null;
	if ($new === null)
	    return $config;
	$keep = $new;
	foreach(array_filter([ $here, $config['token'] ], 'is_string') as $candidate) {
	    if (empty(array_diff_key($tokens[$candidate] ?? [], [ $wfId => true ]))) {
		$keep = $candidate;
		break;
	    }
	}
	foreach($fresh as $key => $value) {
	    if (is_string($value) && str_contains($value, $new))
		$config[$key] = str_replace($new, $keep, $value);
	}
	$tokens[$keep][$wfId] = true;
	return $config;
    }
}
