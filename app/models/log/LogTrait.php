<?php

/*
    what the audit log (models\AuditLog: people) and the execution log (models\ExecutionLog: triggers, workflow runs, daemon, console) share:
    the common fields, entries that are never changed, access (global: the account, project: member of the entry's project), the
    changes of an object (values before / after, secrets masked, long values shortened)
    Using classes define: OBJECT (registry name), ACTIONS (the action choices besides create / update / delete)
*/
namespace models\log;

trait LogTrait {

    //longest value kept (characters of its text / JSON form)
    const MAX_VALUE = 2000;

    //keys never listed as changes (bookkeeping)
    const SKIP_KEYS = [ '_id', 'aid', 'created_at', 'updated_at', '__variant' ];

    //the fields both logs have; $extra (the log's own fields) goes after `project`
    protected function logFields(array $extra) {
	$before = [
	    [
		'key' => 'action',
		'type' => 'select',
		'label' => 'Action',
		'help' => false,
		'required' => true,
		'perm:update' => 'nobody',
		'choices' => [
		    'create' => 'Create',
		    'update' => 'Update',
		    'delete' => 'Delete'
		] + static::ACTIONS,
	    ],
	    //registry name of the object (e.g. Workflow, a data table's iname) and its display name (e.g. "Data table: Orders")
	    [
		'key' => 'object',
		'type' => 'text',
		'label' => 'Object',
		'help' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false
	    ],
	    [
		'key' => 'type',
		'type' => 'text',
		'label' => 'Type',
		'help' => false,
		'perm:update' => 'nobody'
	    ],
	    [
		'key' => 'oid',
		'type' => 'text',
		'label' => 'Object id',
		'help' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false
	    ],
	    //the object's name when the action happened
	    [
		'key' => 'name',
		'type' => 'text',
		'label' => 'Name',
		'help' => false,
		'perm:update' => 'nobody'
	    ],
	    [
		'key' => 'project',
		'type' => 'object',
		'object' => 'Project',
		'label' => 'Project',
		'help' => false,
		'perm:update' => 'nobody'
	    ],
	];
	$after = [
	    //[ [ key, label, before, after ] | [ key, label, masked: true ] ]: before only (delete), after only (create); inner: the shown values read the same
	    [
		'key' => 'changes',
		'type' => 'array',
		'label' => 'Changes',
		'help' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false
	    ],
	    [
		'key' => 'details',
		'type' => 'text',
		'label' => 'Details',
		'help' => false,
		'perm:update' => 'nobody'
	    ],
	];
	return array_merge($before, $extra, $after, $this->getSystemSchema());
    }

    //entries are never changed
    public function beforeSave() {
	if ($this->exists())
	    throw new \LogicException('Log entries cannot be changed');
	parent::beforeSave();
    }

    public function beforeDelete() {
	throw new \LogicException('Log entries cannot be deleted');
    }

    public function __toString() {
	return (string)$this->action . ' ' . (string)$this->name;
    }

    //global scope = the account, project scope = member of the entry's project
    public function checkAccess($userId, $permName, $params = []) {
	$role = \V::app()->auth->getUserRole();

	if (strpos($permName, '::') === false)
	    $permName = $this->getObjectName() . '::' . $permName;

	$scope = $role->getPermissionScope($permName);
	if ($scope == 'global')
	    return true;

	if ($scope == 'project')
	    return \models\Project::isMember($this->project, $this->aid, $userId);

	return false;
    }

    // ------------------------------------------------------------------------------------
    //writing

    //saves an entry: the common values of $o (or $data, when $o no longer has them: deleted) + $fields;
    //Never stops the action itself (errors are logged)
    protected static function write($action, $o, $data, array $fields) {
	try {
	    $data = $data ?? ($o ? $o->data() : []);
	    $objectName = $o ? $o->getObjectName() : null;
	    $def = $objectName ? \V::app()->om->getObjectDef($objectName) : null;

	    $fields += [
		'action' => $action,
		'object' => $objectName,
		'type' => $objectName ? (is_array($def) && !empty($def['name']) ? $def['name'] : $objectName) : null,
		'oid' => isset($data['_id']) ? (string)$data['_id'] : null,
		'name' => $o ? static::nameOf($o, $data) : null,
		'project' => $o ? static::projectOf($o, $data) : null,
	    ];
	    if (empty($fields['aid']))
		$fields['aid'] = $data['aid'] ?? (($o instanceof \models\Account) ? ($data['_id'] ?? null) : (\V::app()->var('rt.account')->_id ?? null));
	    if (isset($fields['details']))
		$fields['details'] = mb_substr((string)$fields['details'], 0, self::MAX_VALUE);

	    //CLD: 2026-10-22T00:00:00+03:00: a sealed log (SEALED, e.g. AuditLog): the account's next number before saving, the MAC of the stored entry after
	    $sealed = defined(static::class . '::SEALED') && static::SEALED && !empty($fields['aid']);
	    if ($sealed)
		$fields['seq'] = static::nextSeq($fields['aid']);

	    $entry = \V::app()->om->create(static::OBJECT, $fields);
	    $entry->save();

	    if ($sealed)
		static::seal($entry->_id);
	} catch (\Throwable $e) {
	    \V::app()->log->error(static::OBJECT . ': ' . $action . ': ' . $e->getMessage());
	}
    }

    //an object the logs record: registered without 'auditLog' => false (internal objects: jobs, executions, indexes, the logs)
    protected static function isLoggable($o) {
	$def = \V::app()->om->getObjectDef($o->getObjectName());
	return !is_array($def) || ($def['auditLog'] ?? true) !== false;
    }

    //the changes of a save: $before = [ key => value before ] of the changed keys, null = created (its values)
    protected static function savedChanges($o, $before) {
	$changes = [];
	if ($before === null) {
	    foreach($o->data() as $key => $value) {
		if (!in_array($key, self::SKIP_KEYS, true) && $value !== null && $value !== '' && $value !== [])
		    $changes[] = static::change($o, $key, null, $value, false, true);
	    }
	    return $changes;
	}

	$data = $o->data();
	foreach($before as $key => $value) {
	    if (!in_array($key, self::SKIP_KEYS, true))
		$changes[] = static::change($o, $key, $value, $data[$key] ?? null, true, true);
	}
	return $changes;
    }

    //the values of a deleted object
    protected static function deletedChanges($o, $data) {
	$changes = [];
	foreach($data as $key => $value) {
	    if (!in_array($key, self::SKIP_KEYS, true) && $value !== null && $value !== '' && $value !== [])
		$changes[] = static::change($o, $key, $value, null, true, false);
	}
	return $changes;
    }

    //one change: a schema field that is not secret gets its values (shortened), anything else only "changed"
    protected static function change($o, $key, $before, $after, $hasBefore, $hasAfter) {
	$field = $o->schema[$key] ?? null;
	$change = [ 'key' => $key, 'label' => $field ? (string)$field->label : $key ];

	$secret = empty($field) || !empty($field->encrypt) || $field->type == 'password';
	if ($secret) {
	    $change['masked'] = true;
	    return $change;
	}

	if ($hasBefore)
	    $change['before'] = static::value($field, $o->auditValue($key, $before));
	if ($hasAfter)
	    $change['after'] = static::value($field, $o->auditValue($key, $after));
	//a summary (auditValue) or shortened value that reads the same: the change is inside it
	if ($hasBefore && $hasAfter && $change['before'] === $change['after'])
	    $change['inner'] = true;
	return $change;
    }

    //a value as kept in the log: ids of objects with their name, dates as text, long values shortened
    protected static function value($field, $value) {
	if ($value === null || is_bool($value) || is_int($value) || is_float($value))
	    return $value;

	if ($field->type == 'object' && !empty($field->object)) {
	    $names = [];
	    foreach((is_array($value) ? $value : [ $value ]) as $id) {
		$ref = $id ? \V::app()->om->findById($field->object, $id) : null;
		$names[] = $ref ? (string)$ref : (string)$id;
	    }
	    $value = implode(', ', $names);
	}

	$value = static::plain($value);
	$text = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE);
	if (mb_strlen($text) > self::MAX_VALUE)
	    return mb_substr($text, 0, self::MAX_VALUE) . '…';
	return $value;
    }

    //BSON values as plain values (ids as text, dates as ISO 8601)
    protected static function plain($value) {
	if ($value instanceof \MongoDB\BSON\ObjectId)
	    return (string)$value;
	if ($value instanceof \MongoDB\BSON\UTCDateTime)
	    return $value->toDateTime()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('c');
	if (is_object($value) && method_exists($value, 'getArrayCopy'))
	    $value = $value->getArrayCopy();
	if (is_array($value))
	    return array_map([ static::class, 'plain' ], $value);
	if (is_object($value))
	    return (string)json_encode($value);
	return $value;
    }

    protected static function nameOf($o, $data) {
	$name = $data['name'] ?? null;
	if (!is_scalar($name) || (string)$name === '')
	    $name = $o->exists() ? (string)$o : null;
	return $name === null ? null : mb_substr((string)$name, 0, 300);
    }

    //the project of $o: a project itself, its `project`, else (data table rows and columns) the project of its data table
    protected static function projectOf($o, $data) {
	if ($o instanceof \models\Project)
	    return $data['_id'] ?? null;
	if (!empty($data['project']))
	    return $data['project'];

	$objectName = ($o instanceof \sys\fields\Field) ? ($data['objectName'] ?? null) : $o->getObjectName();
	$def = $objectName ? \V::app()->om->getObjectDef($objectName) : null;
	if (empty($def['_id']))
	    return null;
	$dt = \V::app()->om->findById('DataTableDef', $def['_id']);
	return $dt ? $dt->project : null;
    }
}
