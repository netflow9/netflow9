<?php

/*
    CLD: 2026-11-12T16:00:00+02:00: HubSpot Records - the CRM's records of one object type: contacts, companies, deals, tickets, or a custom object (its type id 2-…, or its
    name). Operations (setting `operation`):
	search        the records filter lines find (all to match: "lifecyclestage = lead", "amount > 1000", "email contains example.com", "phone has"), and / or a text in the
	              default searchable properties; sorted; with the properties to read (the type's usual ones + those named)
	get           a record by its ID or by a unique property (a contact's email): not found is null, not an error
	create        a record of the properties (a JSON object, as HubSpot names them: firstname, dealname, dealstage, …)
	update        a record's properties changed
	upsert        created, or updated when one has the unique property's value (searched first: the value of that property in the properties)
	delete        a record archived (to the recycling bin)
	associate     associated with another record (the default association of the two types)
	associations  the records of another type associated with it: [ { id, types } ]
	note          a note added to the record (its timeline): a note object, associated with it
    A record: { id, url (in HubSpot), createdAt, updatedAt, archived, <its properties>, properties }
*/

namespace models\node;

class NodeAppHsRecords extends NodeAppHubSpot {
    public static $name = 'hubspot-records';
    public static $label = 'HubSpot Records';
    public static $icon = 'bi-person-vcard';
    public static $defaultOperation = 'search';
    public static $defaultVariable = 'records';

    const OPERATIONS = [
	'search' => 'Search records',
	'get' => 'Get a record',
	'create' => 'Create a record',
	'update' => 'Update a record',
	'upsert' => 'Create or update a record',
	'delete' => 'Delete a record',
	'associate' => 'Associate with a record',
	'associations' => 'List associated records',
	'note' => 'Add a note',
    ];
    const WRITES = [ 'create', 'update', 'upsert', 'delete', 'associate', 'note' ];
    const LISTS = [ 'search', 'associations' ];
    const ONE = [ 'get', 'update', 'delete', 'associate', 'associations', 'note' ];
    const TYPES = [ 'contacts' => 'Contacts', 'companies' => 'Companies', 'deals' => 'Deals', 'tickets' => 'Tickets', 'custom' => 'A custom object' ];
    const TO_TYPES = [ 'contacts' => 'Contacts', 'companies' => 'Companies', 'deals' => 'Deals', 'tickets' => 'Tickets', 'custom' => 'A custom object' ];
    //the properties read unless others are named
    const PROPERTIES = [
	'contacts' => [ 'email', 'firstname', 'lastname', 'phone', 'company', 'jobtitle', 'lifecyclestage', 'hubspot_owner_id', 'createdate', 'lastmodifieddate' ],
	'companies' => [ 'name', 'domain', 'industry', 'phone', 'city', 'country', 'lifecyclestage', 'hubspot_owner_id', 'createdate', 'hs_lastmodifieddate' ],
	'deals' => [ 'dealname', 'amount', 'dealstage', 'pipeline', 'closedate', 'hubspot_owner_id', 'createdate', 'hs_lastmodifieddate' ],
	'tickets' => [ 'subject', 'content', 'hs_pipeline', 'hs_pipeline_stage', 'hs_ticket_priority', 'hubspot_owner_id', 'createdate', 'hs_lastmodifieddate' ],
    ];
    const TYPE_PATTERN = '/^(2-\d{1,12}|p\d+_[a-z0-9_]{1,64}|[a-z][a-z0-9_]{1,64})$/';

    protected static function operationFields() {
	return [
	    [ 'key' => 'objectType', 'type' => 'select', 'label' => 'Records', 'required' => true, 'default' => 'contacts', 'choices' => self::TYPES, 'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'customType', 'type' => 'text', 'label' => 'Custom object', 'default' => '', 'help' => 'Its type ID (2-1234567) or name', 'requiredIf' => [ 'if' => 'objectType', 'in' => [ 'custom' ] ],
		'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'objectType', 'operator' => 'eq', 'value' => 'custom' ] ] ],
	    self::field('filters', 'Filters', [ 'search' ], [ 'type' => 'textarea', 'help' => 'One per line, all to match: <property> <operator> <value>; =, !=, >, >=, <, <=, contains, in (a, b), has, !has; '
		. 'dates as 2026-11-01. e.g. lifecyclestage = lead', 'ui' => [ 'rows' => 3, 'expression' => 'text', 'code' => true ] ]),
	    self::field('text', 'Text', [ 'search' ], [ 'help' => 'Words in the default searchable properties (a contact\'s name, email, phone, …); empty: none', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('sort', 'Sorted by', [ 'search' ], [ 'help' => 'A property, then asc or desc, e.g. createdate desc', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('read', 'Properties', [ 'search', 'get', 'associations' ], [ 'help' => 'More to read, separated by commas (the usual ones are read); associations: the other records\' properties are not read',
		'ui' => [ 'expression' => 'text', 'advanced' => true ] ]),
	    self::field('record', 'Record', self::ONE, [ 'required' => true, 'help' => 'Its ID, or a record of a search or a trigger', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('idProperty', 'Find by', [ 'get', 'upsert' ], [ 'help' => 'A unique property instead of the ID, e.g. email; upsert: required (its value is in the properties)',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'upsert' ] ], 'ui' => [ 'expression' => 'text', 'width' => 6, 'placeholder' => 'email' ] ]),
	    self::field('properties', 'Properties', [ 'create', 'update', 'upsert' ], [ 'type' => 'textarea', 'required' => true,
		'help' => 'A JSON object, as HubSpot names them, e.g. {"email": "{{ form.email }}", "firstname": "{{ form.name }}", "lifecyclestage": "lead"}', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	    self::field('toType', 'Other records', [ 'associate', 'associations' ], [ 'type' => 'select', 'required' => true, 'default' => 'companies', 'choices' => self::TO_TYPES, 'ui' => [ 'width' => 6 ] ]),
	    self::field('toCustomType', 'Other custom object', [ 'associate', 'associations' ], [ 'help' => 'Its type ID or name', 'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'toType', 'operator' => 'eq', 'value' => 'custom' ] ] ]),
	    self::field('toRecord', 'Other record', [ 'associate' ], [ 'required' => true, 'help' => 'Its ID, or a record of a search', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('body', 'Note', [ 'note' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'Text (or HTML); {{ ... }} for expressions', 'ui' => [ 'rows' => 4, 'expression' => true ] ]),
	];
    }

    //the API's name of the object type of $key / $customKey
    protected function typeOf($key = 'objectType', $customKey = 'customType') {
	$t = (string)$this->cfg($key, 'contacts');
	if ($t !== 'custom')
	    return isset(self::TYPES[$t]) ? $t : 'contacts';
	$c = strtolower(trim((string)$this->cfg($customKey, '')));
	if (!preg_match(self::TYPE_PATTERN, $c))
	    throw new \RuntimeException('Custom object: its type ID (2-1234567) or name');
	return $c;
    }

    //a record's ID: a number, or a record of a search / trigger ({ id })
    public static function recordId($value, $label = 'Record') {
	if (is_array($value))
	    $value = $value['id'] ?? $value['hs_object_id'] ?? '';
	$v = trim(\util\Expression::toText($value));
	if (!preg_match('/^\d{1,20}$/', $v))
	    throw new \RuntimeException("{$label}: not a record ID: `" . mb_substr($v, 0, 60) . '`');
	return $v;
    }

    //the properties to write: a JSON object of scalars (lists joined with ; as HubSpot's multiple checkboxes take them, true / false as text)
    protected function propertiesOf(\Closure $value) {
	$p = $value('properties');
	if (is_string($p))
	    $p = trim($p) === '' ? [] : json_decode($p, true);
	if (!is_array($p) || ($p && array_is_list($p)))
	    throw new \RuntimeException('Properties: not a JSON object');
	if (!$p)
	    throw new \RuntimeException('Properties: empty');
	$out = [];
	foreach($p as $k => $v) {
	    if (!preg_match('/^[A-Za-z0-9_]{1,100}$/', (string)$k))
		throw new \RuntimeException("Properties: not a property's name: `{$k}`");
	    $out[$k] = is_bool($v) ? ($v ? 'true' : 'false') : (is_array($v) ? implode(';', array_map(fn($x) => \util\Expression::toText($x), $v)) : $v);
	}
	return $out;
    }

    protected function readList($type) {
	return array_values(array_unique(array_merge(self::PROPERTIES[$type] ?? [], self::names($this->settingText('read')))));
    }

    protected function rec($type, array $raw) {
	return \util\HubSpot::record($raw, isset($raw['id']) ? $this->hs->recordUrl($type, $raw['id']) : null);
    }

    //the record of $id ($idProperty: a unique property's value), or null when there is none
    protected function getRecord($type, $id, $idProperty = '') {
	try {
	    $raw = (array)$this->hs->request('GET', "/crm/v3/objects/{$type}/" . rawurlencode((string)$id), [ 'properties' => implode(',', $this->readList($type)), 'idProperty' => $idProperty ?: null ]);
	} catch (\RuntimeException $e) {
	    if (str_starts_with($e->getMessage(), 'HTTP status 404'))
		return null;
	    throw $e;
	}
	return $this->rec($type, $raw);
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$H = $this->hs;
	$type = $this->typeOf();
	$base = "/crm/v3/objects/{$type}";
	switch($operation) {
	    case 'search':
		$sorts = [];
		if (($s = $text('sort')) !== '') {
		    if (!preg_match('/^([A-Za-z0-9_]+)(?:\s+(asc|desc))?$/i', $s, $m))
			throw new \RuntimeException('Sorted by: a property, then asc or desc');
		    $sorts[] = [ 'propertyName' => $m[1], 'direction' => strtolower($m[2] ?? 'asc') === 'desc' ? 'DESCENDING' : 'ASCENDING' ];
		}
		[ $raw, $total, $truncated ] = $H->search($type, \util\HubSpot::filters($text('filters')), $this->readList($type), $sorts, $this->maxItems(), $text('text'));
		$items = array_map(fn($r) => $this->rec($type, (array)$r), $raw);
		return [ [ 'items' => $items, 'count' => count($items), 'total' => $total, 'truncated' => $truncated ], \util\Format::plural(count($items), 'record') . ($truncated ? " of {$total}" : '') . " ({$type})" ];
	    case 'get':
		$idp = $text('idProperty');
		$v = $value('record');
		$id = $idp !== '' ? trim(is_array($v) ? (string)($v[$idp] ?? '') : \util\Expression::toText($v)) : self::recordId($v);
		if ($id === '')
		    throw new \RuntimeException('Record: empty');
		$r = $this->getRecord($type, $id, $idp);
		return [ $r, $r ? "{$type} {$r['id']}" : "no {$type} record " . ($idp !== '' ? "with {$idp} {$id}" : $id) ];
	    case 'create':
		$raw = (array)$H->request('POST', $base, [], [ 'properties' => $this->propertiesOf($value) ]);
		return [ $this->rec($type, $raw), "{$type} {$raw['id']} created" ];
	    case 'upsert':
		$p = $this->propertiesOf($value);
		$idp = $text('idProperty');
		if ($idp === '' || !isset($p[$idp]) || trim((string)$p[$idp]) === '')
		    throw new \RuntimeException("Find by: the properties have no value for `{$idp}`");
		[ $found ] = $H->search($type, [ [ 'propertyName' => $idp, 'operator' => 'EQ', 'value' => (string)$p[$idp] ] ], [ $idp ], [], 2);
		if (count($found) > 1)
		    throw new \RuntimeException("more than one {$type} record has {$idp} {$p[$idp]}");
		if ($found) {
		    $raw = (array)$H->request('PATCH', "{$base}/" . rawurlencode((string)$found[0]['id']), [], [ 'properties' => $p ]);
		    return [ $this->rec($type, $raw) + [ 'created' => false ], "{$type} {$raw['id']} updated" ];
		}
		$raw = (array)$H->request('POST', $base, [], [ 'properties' => $p ]);
		return [ $this->rec($type, $raw) + [ 'created' => true ], "{$type} {$raw['id']} created" ];
	}
	$id = self::recordId($value('record'));
	switch($operation) {
	    case 'update':
		$raw = (array)$H->request('PATCH', "{$base}/{$id}", [], [ 'properties' => $this->propertiesOf($value) ]);
		return [ $this->rec($type, $raw), "{$type} {$id} updated" ];
	    case 'delete':
		$H->request('DELETE', "{$base}/{$id}");
		return [ [ 'id' => $id, 'deleted' => true ], "{$type} {$id} deleted" ];
	    case 'associate':
		$to = $this->typeOf('toType', 'toCustomType');
		$toId = self::recordId($value('toRecord'), 'Other record');
		$H->request('PUT', "/crm/v4/objects/{$type}/{$id}/associations/default/{$to}/{$toId}");
		return [ [ 'id' => $id, 'type' => $type, 'toId' => $toId, 'toType' => $to ], "{$type} {$id} associated with {$to} {$toId}" ];
	    case 'associations':
		$to = $this->typeOf('toType', 'toCustomType');
		$all = [];
		$after = null;
		do {
		    $d = (array)$H->request('GET', "/crm/v4/objects/{$type}/{$id}/associations/{$to}", [ 'limit' => 500, 'after' => $after ]);
		    foreach((array)($d['results'] ?? []) as $a)
			$all[] = [ 'id' => (string)($a['toObjectId'] ?? ''), 'types' => array_values(array_filter(array_map(fn($t) => $t['label'] ?? null, (array)($a['associationTypes'] ?? [])))) ];
		    $after = $d['paging']['next']['after'] ?? null;
		} while ($after !== null && count($all) <= $this->maxItems());
		return $this->listResult($all, "associated {$to} record");
	    case 'note':
		$b = $value('body');
		$b = trim(is_array($b) ? json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : \util\Expression::toText($b));
		if ($b === '')
		    throw new \RuntimeException('Note: empty');
		$html = $b !== strip_tags($b) ? $b : nl2br(htmlspecialchars($b, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$note = (array)$H->request('POST', '/crm/v3/objects/notes', [], [ 'properties' => [ 'hs_note_body' => $html, 'hs_timestamp' => gmdate('Y-m-d\TH:i:s.v\Z') ] ]);
		$H->request('PUT', "/crm/v4/objects/notes/" . rawurlencode((string)$note['id']) . "/associations/default/{$type}/{$id}");
		return [ [ 'id' => (string)$note['id'], 'recordId' => $id, 'type' => $type ], "note {$note['id']} added to {$type} {$id}" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
