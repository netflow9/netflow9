<?php

namespace models;

class WfVersion extends \sys\BaseModel {
    protected static $collection = 'wf_versions';
    public static $indexes = [
	[ [ 'wf' => 1, 'number' => -1 ], [] ],
    ];

    public function getSchema() {
	$fields = [
	    [
		'key' => 'wf',
		'type' => 'object',
		'object' => 'Workflow',
		'label' => 'Workflow',
		'help' => false,
		'required' => true
	    ],
	    [
		'key' => 'project',
		'type' => 'object',
		'object' => 'Project',
		'label' => 'Project',
		'help' => false
	    ],
	    //v1, v2, ... per workflow (Workflow.version_count)
	    [
		'key' => 'number',
		'type' => 'number',
		'label' => 'Version',
		'help' => false,
		'required' => true
	    ],
	    //the workflow's name when it was published
	    [
		'key' => 'name',
		'type' => 'text',
		'label' => 'Name',
		'help' => false
	    ],
	    //the steps and connections, as in Workflow.nodes
	    [
		'key' => 'nodes',
		'type' => 'array',
		'label' => 'Nodes',
		'help' => false
	    ],
	    [
		'key' => 'note',
		'type' => 'textarea',
		'label' => 'Note',
		'help' => false
	    ],
	    //republished from an older version: its number
	    [
		'key' => 'from_number',
		'type' => 'number',
		'label' => 'Republished from',
		'help' => false
	    ],
	    [
		'key' => 'published_by',
		'type' => 'object',
		'object' => 'User',
		'label' => 'Published by',
		'help' => false
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    public function __toString() {
	return 'v' . (int)$this->number;
    }
}
