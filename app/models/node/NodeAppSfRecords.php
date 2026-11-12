<?php

/*
    CLD: 2026-11-12T18:00:00+02:00: Salesforce Records - the org's records. Operations (setting `operation`):
	query     a SOQL query, as written (any object, relationships, subqueries, aggregates), following nextRecordsUrl
	find      the records of the object a condition finds: the fields to read (the object's usual ones), WHERE, ORDER BY - the SOQL made from them
	search    a text in all fields of the object (SOSL: FIND … RETURNING)
	get       a record with the fields named (empty: all)
	create    a record of the fields (a JSON object, as their API names: LastName, Company, StageName, Amount, Custom__c)
	update    a record's fields changed
	upsert    created or updated by an external ID field (PATCH sobjects/<object>/<field>/<value>): { id, created }
	delete    a record deleted (to the recycle bin)
	activity  a completed task logged on the record (WhoId for a contact or a lead, WhatId otherwise)
    A record: its fields as Salesforce gives them (Id, Name, …; related records as objects, e.g. Account.Name), plus type and url (in Lightning)
*/

namespace models\node;

class NodeAppSfRecords extends NodeAppSalesforce {
    public static $name = 'salesforce-records';
    public static $label = 'Salesforce Records';
    public static $icon = 'bi-cloud-fill';
    public static $defaultOperation = 'find';
    public static $defaultVariable = 'records';

    const OPERATIONS = [
	'query' => 'Run a SOQL query',
	'find' => 'Find records',
	'search' => 'Search by text',
	'get' => 'Get a record',
	'create' => 'Create a record',
	'update' => 'Update a record',
	'upsert' => 'Create or update by external ID',
	'delete' => 'Delete a record',
	'activity' => 'Log an activity',
    ];
    const WRITES = [ 'create', 'update', 'upsert', 'delete', 'activity' ];
    const LISTS = [ 'query', 'find', 'search' ];
    const ON_OBJECT = [ 'find', 'search', 'get', 'create', 'update', 'upsert', 'delete' ];
    const ONE = [ 'get', 'update', 'delete', 'activity' ];
    const OBJECTS = [ 'Account' => 'Accounts', 'Contact' => 'Contacts', 'Lead' => 'Leads', 'Opportunity' => 'Opportunities', 'Case' => 'Cases', 'custom' => 'Another object' ];
    const FIELDS = [
	'Account' => [ 'Id', 'Name', 'Type', 'Industry', 'Website', 'Phone', 'BillingCity', 'BillingCountry', 'OwnerId', 'CreatedDate', 'LastModifiedDate' ],
	'Contact' => [ 'Id', 'Name', 'FirstName', 'LastName', 'Email', 'Phone', 'Title', 'AccountId', 'OwnerId', 'CreatedDate', 'LastModifiedDate' ],
	'Lead' => [ 'Id', 'Name', 'FirstName', 'LastName', 'Company', 'Email', 'Phone', 'Status', 'LeadSource', 'OwnerId', 'CreatedDate', 'LastModifiedDate' ],
	'Opportunity' => [ 'Id', 'Name', 'Amount', 'StageName', 'CloseDate', 'Probability', 'AccountId', 'OwnerId', 'CreatedDate', 'LastModifiedDate' ],
	'Case' => [ 'Id', 'CaseNumber', 'Subject', 'Status', 'Priority', 'Origin', 'AccountId', 'ContactId', 'OwnerId', 'CreatedDate', 'LastModifiedDate' ],
    ];
    //the ID prefixes of the records a task's WhoId takes (contacts, leads)
    const WHO = [ '003', '00Q' ];

    protected static function operationFields() {
	return [
	    self::field('object', 'Object', self::ON_OBJECT, [ 'type' => 'select', 'required' => true, 'default' => 'Account', 'choices' => self::OBJECTS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('customObject', 'API name', self::ON_OBJECT, [ 'help' => 'e.g. Invoice__c, Product2, Campaign', 'ui' => [ 'width' => 6,
		'showIf' => [ 'key' => 'object', 'operator' => 'eq', 'value' => 'custom' ] ] ]),
	    self::field('soql', 'SOQL', [ 'query' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'e.g. SELECT Id, Name, Account.Name FROM Contact WHERE Email = \'{{ form.email }}\'',
		'ui' => [ 'rows' => 3, 'expression' => 'text', 'code' => true ] ]),
	    self::field('fields', 'Fields', [ 'find', 'search', 'get' ], [ 'help' => 'API names separated by commas (Account.Name works); find / search: empty is the object\'s usual ones; get: empty is all',
		'ui' => [ 'expression' => 'text' ] ]),
	    self::field('where', 'Where', [ 'find' ], [ 'type' => 'textarea', 'help' => 'A SOQL condition, e.g. StageName = \'Prospecting\' AND Amount > 10000; empty: all',
		'ui' => [ 'rows' => 2, 'expression' => 'text', 'code' => true ] ]),
	    self::field('orderBy', 'Order by', [ 'find' ], [ 'help' => 'e.g. CreatedDate DESC', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('text', 'Text', [ 'search' ], [ 'required' => true, 'help' => 'Words to find in all the fields', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('record', 'Record', self::ONE, [ 'required' => true, 'help' => 'Its ID, or a record of a query or the trigger', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('values', 'Fields', [ 'create', 'update', 'upsert' ], [ 'type' => 'textarea', 'required' => true,
		'help' => 'A JSON object of API names, e.g. {"LastName": "{{ form.name }}", "Company": "{{ form.company }}", "LeadSource": "Web"}', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	    self::field('externalField', 'External ID field', [ 'upsert' ], [ 'required' => true, 'help' => 'A field marked External ID (or Id), e.g. ERP_Id__c', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('externalValue', 'Its value', [ 'upsert' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('subject', 'Subject', [ 'activity' ], [ 'required' => true, 'help' => 'e.g. Call, Email: order confirmed', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('description', 'Comments', [ 'activity' ], [ 'type' => 'textarea', 'ui' => [ 'rows' => 3, 'expression' => true ] ]),
	];
    }

    protected function objectName() {
	$o = (string)$this->cfg('object', 'Account');
	return $o === 'custom' ? \util\Salesforce::name($this->settingText('customObject'), 'API name') : (isset(self::OBJECTS[$o]) ? $o : 'Account');
    }

    protected function fieldList($object, $text, $usual = true) {
	$f = array_map(fn($x) => \util\Salesforce::name($x, 'Fields', true), self::names($text));
	return $f ?: ($usual ? (self::FIELDS[$object] ?? [ 'Id', 'Name', 'CreatedDate', 'LastModifiedDate' ]) : []);
    }

    //the fields to write: a JSON object of API names
    protected function valuesOf(\Closure $value) {
	$v = $value('values');
	if (is_string($v))
	    $v = trim($v) === '' ? [] : json_decode($v, true);
	if (!is_array($v) || ($v && array_is_list($v)))
	    throw new \RuntimeException('Fields: not a JSON object');
	if (!$v)
	    throw new \RuntimeException('Fields: empty');
	foreach(array_keys($v) as $k)
	    \util\Salesforce::name($k, 'Fields');
	return $v;
    }

    protected function rec($raw) {
	return \util\Salesforce::record($raw, $this->sf->instance());
    }

    protected function listOfRecords(array $raw, $total, $truncated, $what) {
	$items = array_map(fn($r) => $this->rec($r), $raw);
	return [ [ 'items' => $items, 'count' => count($items), 'total' => $total, 'truncated' => $truncated ], \util\Format::plural(count($items), $what) . ($truncated ? " of {$total}" : '') ];
    }

    //SOSL's reserved characters escaped
    public static function sosl($text) {
	return preg_replace('/([?&|!{}\[\]()^~*:\\\\"\'+\-])/', '\\\\$1', (string)$text);
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$S = $this->sf;
	$max = $this->maxItems();
	switch($operation) {
	    case 'query':
		$soql = $text('soql');
		if ($soql === '')
		    throw new \RuntimeException('SOQL: empty');
		[ $raw, $total, $truncated ] = $S->query($soql, $max);
		return $this->listOfRecords($raw, $total, $truncated, 'record');
	    case 'find':
		$o = $this->objectName();
		$where = $text('where');
		$order = $text('orderBy');
		if ($order !== '' && !preg_match('/^[A-Za-z0-9_.]+(\s+(ASC|DESC))?(\s+NULLS\s+(FIRST|LAST))?(\s*,\s*[A-Za-z0-9_.]+(\s+(ASC|DESC))?(\s+NULLS\s+(FIRST|LAST))?)*$/i', $order))
		    throw new \RuntimeException('Order by: fields, each ASC or DESC');
		$soql = 'SELECT ' . implode(', ', $this->fieldList($o, $text('fields'))) . " FROM {$o}" . ($where !== '' ? " WHERE {$where}" : '') . ($order !== '' ? " ORDER BY {$order}" : '') . ' LIMIT ' . ($max + 1);
		[ $raw, , ] = $S->query($soql, $max + 1);
		$truncated = count($raw) > $max;
		return $this->listOfRecords(array_slice($raw, 0, $max), $truncated ? null : count($raw), $truncated, strtolower($o) . ' record');
	    case 'search':
		$o = $this->objectName();
		$t = $text('text');
		if (mb_strlen($t) < 2)
		    throw new \RuntimeException('Text: at least 2 characters');
		$sosl = 'FIND {' . self::sosl($t) . '} IN ALL FIELDS RETURNING ' . $o . '(' . implode(', ', $this->fieldList($o, $text('fields'))) . ' LIMIT ' . min(2000, $max) . ')';
		$d = (array)$S->request('GET', '/search', [ 'q' => $sosl ]);
		$raw = (array)($d['searchRecords'] ?? []);
		return $this->listOfRecords($raw, count($raw), false, strtolower($o) . ' record');
	    case 'create':
		$o = $this->objectName();
		$r = (array)$S->request('POST', "/sobjects/{$o}", [], $this->valuesOf($value));
		return [ [ 'id' => $r['id'] ?? null, 'type' => $o, 'created' => true ], "{$o} {$r['id']} created" ];
	    case 'upsert':
		$o = $this->objectName();
		$f = \util\Salesforce::name($text('externalField'), 'External ID field');
		$v = $text('externalValue');
		if ($v === '')
		    throw new \RuntimeException('Its value: empty');
		$values = $this->valuesOf($value);
		unset($values[$f]);
		$r = (array)$S->request('PATCH', "/sobjects/{$o}/{$f}/" . rawurlencode($v), [], $values);
		$created = !empty($r['created']);
		$id = $r['id'] ?? null;
		if ($id === null) {
		    //an older API version answers an update with 204: the record looked up
		    [ $found ] = $S->query("SELECT Id FROM {$o} WHERE {$f} = " . \util\Salesforce::quote($v), 1);
		    $id = $found[0]['Id'] ?? null;
		}
		return [ [ 'id' => $id, 'type' => $o, 'created' => $created ], "{$o} {$id} " . ($created ? 'created' : 'updated') . " ({$f} {$v})" ];
	}
	$id = \util\Salesforce::recordId($value('record'));
	switch($operation) {
	    case 'get':
		$o = $this->objectName();
		$fields = $this->fieldList($o, $text('fields'), false);
		try {
		    $r = $S->request('GET', "/sobjects/{$o}/{$id}", [ 'fields' => $fields ? implode(',', $fields) : null ]);
		} catch (\RuntimeException $e) {
		    if (str_starts_with($e->getMessage(), 'HTTP status 404'))
			return [ null, "no {$o} {$id}" ];
		    throw $e;
		}
		$rec = $this->rec((array)$r);
		return [ $rec, "{$o} {$id}" . (isset($rec['Name']) && is_scalar($rec['Name']) ? ": {$rec['Name']}" : '') ];
	    case 'update':
		$o = $this->objectName();
		$values = $this->valuesOf($value);
		$S->request('PATCH', "/sobjects/{$o}/{$id}", [], $values);
		return [ [ 'id' => $id, 'type' => $o, 'updated' => array_keys($values) ], "{$o} {$id} updated: " . implode(', ', array_keys($values)) ];
	    case 'delete':
		$o = $this->objectName();
		$S->request('DELETE', "/sobjects/{$o}/{$id}");
		return [ [ 'id' => $id, 'type' => $o, 'deleted' => true ], "{$o} {$id} deleted" ];
	    case 'activity':
		$subject = $text('subject');
		if ($subject === '')
		    throw new \RuntimeException('Subject: empty');
		$d = $value('description');
		$d = is_array($d) ? json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : \util\Expression::toText($d);
		$task = [ 'Subject' => mb_substr($subject, 0, 255), 'Status' => 'Completed', 'ActivityDate' => gmdate('Y-m-d') ] + (trim($d) !== '' ? [ 'Description' => $d ] : [])
		    + (in_array(substr($id, 0, 3), self::WHO, true) ? [ 'WhoId' => $id ] : [ 'WhatId' => $id ]);
		$r = (array)$S->request('POST', '/sobjects/Task', [], $task);
		return [ [ 'id' => $r['id'] ?? null, 'recordId' => $id, 'subject' => $task['Subject'] ], "activity {$r['id']} logged on {$id}" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
