<?php

namespace models;

//CLD: 2026-10-18T13:30:00+03:00: a sys\NestedModel (was sys\BaseModel): held inside a workflow's `nodes` (with the steps, keyed by wfid), never saved by itself;
//global (no aid of its own: validates() skips the aid check); its own schema (no nestedSchema option), no system fields
class WfEdge extends \sys\NestedModel {

    public function getSchema() {
	$fields = [
	    //wfid
	    [
		'key' => 'wfid',
		'type' => 'text',
		'label' => 'Node ID',
		'help' => false,
		'required' => true,
	    ],
	    //fromNode
	    [
		'key' => 'fromNode',
		'type' => 'text',
		'label' => 'From node',
		'help' => false,
		'required' => true,
	    ],
	    //fromPort
	    [
		'key' => 'fromPort',
		'type' => 'text',
		'label' => 'From port',
		'help' => false,
	    ],
	    //toNode
	    [
		'key' => 'toNode',
		'type' => 'text',
		'label' => 'To node',
		'help' => false,
	    ],
	    //toPort
	    [
		'key' => 'toPort',
		'type' => 'text',
		'label' => 'To port',
		'help' => false,
	    ],
	    //branch
	    [
		'key' => 'branch',
		'type' => 'text',
		'label' => 'Branch',
		'help' => false,
	    ],

	    //state
	    [
		'key' => 'state',
		'label' => 'State',
		'type' => 'select',
		'default' => 'new',
		'choices' => [
		    'new' => 'new',
		    'passed' => 'Active',
		],
	    ],
	    //statistics here
	    //passed_at
	    [
		'key' => 'passed_at',
		'type' => 'datetime',
		'label' => 'Passed at',
	    ],
	];
	return $fields;
    }

}
