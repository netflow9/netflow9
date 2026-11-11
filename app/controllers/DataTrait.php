<?php

namespace controllers;

trait DataTrait {

    //the field `$key` of $o if it exists and the current user may read it, else null;
    //filtering/sorting/searching on unreadable fields (e.g. User password, tfa_secret) leaked their values through nRecords
    private function __readableField($o, $key) {
	if (!is_string($key) || !isset($o->schema[$key]))
	    return null;

	$f = $o->schema[$key];
	//an encrypted field's stored value is ciphertext: never searched, filtered or sorted on
	if (!empty($f->encrypt))
	    return null;
	return $f->checkAccess(\V::app()->user->_id, 'read') ? $f : null;
    }

    private function __filter2query($o, $filter = []) {
	$query = [];
	$conditions = [];

	//ignore malformed rows, unknown/unreadable fields, non-scalar values and unknown operators
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

	    //a condition of util\Conditions with the field's operators; an operator the field
	    //does not have is ignored, as an unknown one was
	    $op = (string)($fRow['op'] ?? '');
	    if (!isset($f->ops['eval'][$op]))
		continue;
	    $conditions[] = [ 'key' => $key, 'operator' => $op, 'value' => $value ];
	}
	$query = \util\Conditions::query($conditions, 'all', fn($k) => $o->schema[$k] ?? null);
	return $query;
    }

    //the request's search, filter, sort and page as [ query, options ] for om->find on the objects of $o
    private function __listQuery($o, $sys_query = []) {
	$req = \V::app()->request;

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
	//sort only by _id or a readable field (sort order also reveals values)
	if ($sortField !== null && $sortDir !== null && ($sortField === '_id' || $this->__readableField($o, $sortField)))
	    $options['sort'] = [ $sortField => $sortDir == 'asc' ? 1 : -1 ];

	$query = [];
	if (!$o->config('global'))
	    $query['aid'] = $this->account->_id;

	$search = $req->query['__search'] ?? '';
	//search only readable fields, as literal text
	if (is_string($search) && $search !== '') {
	    //`contains` conditions on the fields, any of them (util\Conditions); a field without `contains` is not searched
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

	return [ $query, $options ];
    }

    private function __data($objectName, $sys_query = []) {

	$response = [ 'nRecords' => 0, 'data' => [] ];

	$o = \V::app()->om->create($objectName, [ 'aid' => $this->account->_id ]);
	[ $query, $options ] = $this->__listQuery($o, $sys_query);

	$response['nRecords'] = \V::app()->om->count($objectName, $query);
	$objs = \V::app()->om->find($objectName, $query, $options);

	foreach($objs as $o) {
	    $record = [];
	    //only the readable fields
	    foreach($o->schema as $key => $field) {
		if ($field->checkAccess(\V::app()->user->_id, 'read')) {
		    $record[$key] = $field->display();
		    //the dates also as ISO 8601 (_iso.<key>), for the browser to read (app.formatter.timeAgo): the shown text is in the account's format
		    if ($field instanceof \sys\fields\Field_datetime)
			$record['_iso'][$key] = \util\Format::datetime($o->$key, DATE_ATOM);
		}
	    }
	    $record['_id'] = (string)$o->_id;

	    $response['data'][] = $record;
	}

	return $response;
    }
}