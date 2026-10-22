<?php

namespace util;

class WidgetsUtil {

    //for interaction with JS-libraries
    public static function getSchema($objectName) {
	$om = \V::app()->om;
	if (empty($om->getObjectDef($objectName)))
	    throw new \ErrorException("WidgetUtil::getSchema: unknown object: `$objectName`");

	$account = \V::app()->var('rt.account');
	$o = $om->create($objectName, [ 'aid' => $account->_id ]);

	$filter = [];
	$schema = [
	    '_id' => [ 'label' => 'ID', 'type' => 'object', 'filter' => false, 'edit' => false, 'delete' => false, 'show' => false ]
	];
	foreach($o->schema as $f) {
	    if (!$f->checkAccess(\V::app()->user->_id, 'read', $f))
		continue;
	    $schema[$f->key] = [
		'label' => $f->label,
		'type' => $f->type,
		//CLD: 2026-10-06T21:22:22+03:00: a list of choice values (the grid filter does choices.forEach); select choices may be a newline
		//string or a [ value => label ] map, both normalized by Field_select::choicesToArray
		'choices' => ($f->type == 'select') ? array_map('strval', array_keys($f->choicesToArray())) : [],
		//CLD: 2026-10-10T09:14:41+03:00: not on encrypted fields (DataTrait ignores them)
		'filter' => empty($f->encrypt),
		'delete' => $f->{'perm:delete'} != 'nobody',
		'edit' => $f->{'perm:update'} != 'nobody',
		'show' => true,
	    ];

	    //filtering
	    if (empty($filter[$f->type])) {
		$filter[$f->type] = [];
		foreach($f->ops['eval'] as $op => $label)
		    $filter[$f->type][] = [ 'key' => $op, 'label' => $label ];
	    }
	}

	return [ 'schema' => $schema, 'filter' => $filter ];
    }
}
