<?php

namespace controllers;

trait DataTrait {

    //CLD: 2026-10-05T20:56:43+03:00: the field `$key` of $o if it exists and the current user may read it, else null;
    //filtering/sorting/searching on unreadable fields (e.g. User password, tfa_secret) leaked their values through nRecords
    private function __readableField($o, $key) {
	if (!is_string($key) || !isset($o->schema[$key]))
	    return null;

	$f = $o->schema[$key];
	//CLD: 2026-10-10T09:14:41+03:00: an encrypted field's stored value is ciphertext: never searched, filtered or sorted on
	if (!empty($f->encrypt))
	    return null;
	return $f->checkAccess(\V::app()->user->_id, 'read') ? $f : null;
    }

    private function __filter2query($o, $filter = []) {
	$query = [];
	$conditions = [];

	//CLD: 2026-10-05T20:56:43+03:00: ignore malformed rows, unknown/unreadable fields, non-scalar values and unknown operators
	//(was: any schema field, and an unknown op or field raised an error)
	if (!is_array($filter))
	    return $query;

	foreach($filter as $fRow) {
	    if (!is_array($fRow))
		continue;

	    $key = $fRow['field'] ?? null;
	    $value = $fRow['value'] ?? null;
	    if (!is_scalar($value))
		continue;

	    $f = $this->__readableField($o, $key);
	    if (empty($f))
		continue;

	    //CLD: 2026-10-14T20:17:14+03:00: a condition of util\Conditions with the field's operators (was its own list: is / !is / before / after / ... here); an operator the field
	    //does not have is ignored, as an unknown one was
	    $op = (string)($fRow['op'] ?? '');
	    if (!isset($f->ops['eval'][$op]))
		continue;
	    $conditions[] = [ 'key' => $key, 'operator' => $op, 'value' => $value ];
	}
	$query = \util\Conditions::query($conditions, 'all', fn($k) => $o->schema[$k] ?? null);
	return $query;
    }

    private function __data($objectName, $sys_query = []) {
	$req = \V::app()->request;
	$account = \V::app()->config('rt.account');

	$response = [ 'nRecords' => 0, 'data' => [] ];

	$o = \V::app()->om->create($objectName, [ 'aid' => $account->_id ]);

	$options = [];
	$skip = $req->query['__skip'] ?? null;
	if ($skip !== null)
	    $options['skip'] = intval($skip);
	$limit = $req->query['__limit'] ?? null;
	if ($limit !== null)
	    $options['limit'] = intval($limit);
	$sort = [ '_id' => 1];
	$sortField = $req->query['__sortField'] ?? null;
	$sortDir = $req->query['__sortDir'] ?? null;
	//CLD: 2026-10-05T20:56:43+03:00: sort only by _id or a readable field (sort order also reveals values)
	if ($sortField !== null && $sortDir !== null && ($sortField === '_id' || $this->__readableField($o, $sortField)))
	    $options['sort'] = [ $sortField => $sortDir == 'asc' ? 1 : -1 ];

	$query = [];
	if (!$o->config('global'))
	    $query['aid'] = $account->_id;

	$search = $req->query['__search'] ?? '';
	//CLD: 2026-10-05T20:56:43+03:00: search only readable fields, as literal text (was every text field, e.g. tfa_secret, with a client-supplied regex)
	if (is_string($search) && $search !== '') {
	    //CLD: 2026-10-14T20:17:14+03:00: `contains` conditions on the fields, any of them (util\Conditions; was the regex written here); a field without `contains` is not searched
	    $sq = [];
	    foreach($o->schema as $f) {
		if (!in_array($f->type, [ 'text', 'textarea', 'email', 'select' ]) || !isset($f->ops['eval']['contains']))
		    continue;
		if (!$this->__readableField($o, $f->key))
		    continue;

		$sq[] = [ 'key' => $f->key, 'operator' => 'contains', 'value' => $search ];
	    }

	    //no searchable field: match nothing rather than everything
	    $query['$or'] = $sq ? \util\Conditions::query($sq, 'any', fn($k) => $o->schema[$k] ?? null)['$or'] : [ [ '_id' => null ] ];
	}

	$filter = $req->query['__filter'] ?? [];
	if (!empty($filter))
	    $query = array_merge($query, $this->__filter2query($o, $filter));

	//add system query
	$query = array_merge($query, $sys_query);

	//\V::app()->log->debug('DataTrait::query=' . print_r($query, true));

	$response['nRecords'] = \V::app()->om->count($objectName, $query);
	$objs = \V::app()->om->find($objectName, $query, $options);

	foreach($objs as $o) {
	    $record = [];
	    //CLD: 2026-10-10T09:14:41+03:00: only the readable fields (were all: e.g. Credential secrets went to the browser)
	    foreach($o->schema as $key => $field) {
		if ($field->checkAccess(\V::app()->user->_id, 'read'))
		    $record[$key] = $field->display();
	    }
	    $record['_id'] = (string)$o->_id;

	    $response['data'][] = $record;
	}

	return $response;
    }
}