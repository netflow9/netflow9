<?php

namespace controllers;

//CLD: 2026-10-05T20:55:34+03:00: DEVELOPMENT ONLY: generic data endpoint without permission checks (see the commented `can()` in get());
//its filters can probe any field of any object (e.g. User password / tfa_secret). Available only while `app.debug` is on.
class DataController extends \sys\BaseController {

    //CLD: 2026-10-05T20:55:34+03:00: 404 unless app.debug; logged-in users only (guests crashed on the missing account)
    public function _init() {
	parent::_init();

	if (!\V::app()->config('app.debug'))
	    \V::app()->error(404, 'Controller not found');

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());
    }

    private function filter2query($o, $filter = []) {
	$query = [];

	foreach($filter as $fRow) {
	    $key = $fRow['field'];
	    $value = $fRow['value'];

	    $f = $o->schema[$key];

	    switch($fRow['op']) {
		case 'eq':
		case 'is':
		    $qPart = [ '$eq' => $f->cast($value) ];
		    break;
		case 'ne':
		case '!is':
		    $qPart = [ '$ne' => $f->cast($value) ];
		    break;
		case 'gt':
		case 'after':
		    $qPart = [ '$gt' => $f->cast($value) ];
		    break;
		case 'lt':
		case 'before':
		    $qPart = [ '$lt' => $f->cast($value) ];
		    break;
		case 'lte':
		case 'le':
		    $qPart = [ '$lte' => $f->cast($value) ];
		    break;
		case 'gte':
		case 'ge':
		    $qPart = [ '$gte' => $f->cast($value) ];
		    break;
		case 'contains':
		    $qPart = [ '$regex' => $f->cast($value), '$options' => 'i' ];
		    break;
	    }

	    if (empty($query[$key])) {
		$query[$key] = $qPart;
	    } else {
		$query[$key] = array_merge($query[$key], $qPart);
	    }
	}
	return $query;
    }



    public function get($req, $resp) {
	//CLD: 2026-10-18T12:49:28+03:00: the Content-Type is set by Response::sendJSON
	$response = [ 'nRecords' => 0, 'data' => [], 'schema' => [], 'filterDef' => [] ];

	$objectName = $req->query['objectName'] ?? null;
	if (empty($objectName))
	    //CLD: 2026-10-18T12:49:28+03:00: Response::sendJSON (was addHeader + write + end)
	    $resp->sendJSON($response);
/*
	//CLD: 2026-10-05T20:55:34+03:00: kept disabled on purpose: this controller is development only (see class comment)
	//COMMENTED FOR TESTING
	if (!\V::app()->user->can($objectName . '::list')) {
	    //CLD: 2026-10-14T16:35:21+03:00: util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing)
	    $resp->write(\util\JSON::encode($response));
	    \V::app()->end();
	}
*/
	$account = \V::app()->config('rt.account');

	$o = \V::app()->om->create($objectName, [ 'aid' => $account->_id ]);

	$query = [
	    'aid' => $account->_id,
	];
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
	if ($sortField !== null && $sortDir !== null)
	    $options['sort'] = [ $sortField => $sortDir == 'asc' ? 1 : -1 ];

	$search = $req->query['__search'] ?? '';
	if (!empty($search)) {
	    $sq = [];
	    foreach($o->schema as $f) {
		if (!in_array($f->type, [ 'text', 'textarea', 'email', 'select' ]))
		    continue;

		$sq[] = [ $f->key => [ '$regex' => $search, '$options' => 'i' ] ];
	    }

	    $query['$or'] = $sq;
	}

	$filter = $req->query['__filter'] ?? [];
	if (!empty($filter))
	    $query = array_merge($query, $this->filter2query($o, $filter));

	$response['nRecords'] = \V::app()->om->count($objectName, $query);

	$objs = \V::app()->om->find($objectName, $query, $options);
	foreach($objs as $o) {
	    $record = [];
	    foreach($o->schema as $key => $field)
		$record[$key] = $field->display();
	    $record['_id'] = (string)$o->_id;

	    $response['data'][] = $record;
	}

	//add schema
	$response['schema']['_id'] = [ 'label' => 'ID', 'type' => 'object', 'filterable' => false, 'choices' => [] ];
	foreach($o->schema as $f) {
	    if (!$f->checkAccess(\V::app()->user->_id, 'read', $f))
		continue;
	    $response['schema'][$f->key] = [
		'label' => $f->label,
		'type' => $f->type,
		'filterable' => true,
		'choices' => $f->choices ?? [],
	    ];

	    //filtering
	    if (empty($response['filterDef'][$f->type])) {
		$response['filterDef'][$f->type] = [];
		foreach($f->ops['eval'] as $op => $label) {
		    $response['filterDef'][$f->type][] = [ 'key' => $op, 'label' => $label ];
		}
	    }
	}

	//CLD: 2026-10-18T12:49:28+03:00: Response::sendJSON (was addHeader + write + end)
	$resp->sendJSON($response);
    }


}