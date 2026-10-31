<?php

/*
    CLD: 2026-11-01T00:30:00+02:00: new step: GravityZone Endpoints - the endpoints and groups of a GravityZone console (Network API, Policies API). Operations (setting `operation`):
	list            the endpoints (getEndpointsList): of a group (empty: all the company's), managed / unmanaged, with the subgroups' (all pages up to At most)
	details         one managed endpoint (getManagedEndpointDetails)
	inventory       the network inventory: groups and endpoints (getNetworkInventoryItems)
	groups          the custom groups under a group (getCustomGroupsList)
	scan            a scan task (createScanTask): quick, full, memory or custom, on endpoints or groups
	scanTasks       the scan tasks (getScanTasksList)
	taskStatus      a task's status (getTaskStatus)
	move            endpoints moved into a group (moveEndpoints)
	label           an endpoint's label (setEndpointLabel)
	policies        the policies (getPoliciesList)
	assignPolicy    a policy assigned to endpoints or groups (assignPolicy)
    Other parameters (a JSON object) adds what the step has no setting for (e.g. filters). Ids: GravityZone's (a list of objects: their `id`)
*/

namespace models\node;

class NodeAppGzEndpoints extends NodeAppGravityZone {
    public static $name = 'gz-endpoints';
    public static $label = 'GravityZone Endpoints';
    public static $icon = 'bi-pc-display';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'endpoints';

    const OPERATIONS = [
	'list' => 'List endpoints',
	'details' => 'Get endpoint details',
	'inventory' => 'List network inventory',
	'groups' => 'List custom groups',
	'scan' => 'Scan endpoints',
	'scanTasks' => 'List scan tasks',
	'taskStatus' => 'Get task status',
	'move' => 'Move endpoints to a group',
	'label' => 'Set endpoint label',
	'policies' => 'List policies',
	'assignPolicy' => 'Assign a policy',
    ];
    const LISTS = [ 'list', 'inventory', 'scanTasks', 'policies' ];
    const MANAGED = [ 'all' => 'All', 'managed' => 'Managed only', 'unmanaged' => 'Unmanaged only' ];
    const SCAN_TYPES = [ '1' => 'Quick scan', '2' => 'Full scan', '3' => 'Memory scan', '4' => 'Custom scan' ];

    protected static function operationFields() {
	return [
	    self::field('groupId', 'Group', [ 'list', 'inventory', 'groups' ], [ 'help' => 'A group\'s id; empty: the company\'s root', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('managed', 'Endpoints', [ 'list' ], [ 'type' => 'select', 'default' => 'all', 'choices' => self::MANAGED, 'ui' => [ 'width' => 3 ] ]),
	    self::field('recursive', 'With subgroups', [ 'list', 'inventory' ], [ 'type' => 'switch', 'default' => true, 'ui' => [ 'width' => 3 ] ]),
	    self::field('endpointId', 'Endpoint', [ 'details', 'label' ], [ 'required' => true, 'help' => 'An endpoint\'s id', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('targetIds', 'Targets', [ 'scan', 'move', 'assignPolicy' ], [ 'type' => 'textarea', 'required' => true,
		'help' => 'Endpoint (or group, to scan and assign) ids: a list, or separated by commas', 'ui' => [ 'rows' => 2, 'expression' => true ] ]),
	    self::field('scanType', 'Scan', [ 'scan' ], [ 'type' => 'select', 'default' => '1', 'choices' => self::SCAN_TYPES, 'ui' => [ 'width' => 3 ] ]),
	    self::field('taskName', 'Task name', [ 'scan' ], [ 'help' => 'Empty: GravityZone\'s', 'ui' => [ 'expression' => true, 'width' => 9 ] ]),
	    self::field('taskId', 'Task', [ 'taskStatus' ], [ 'required' => true, 'help' => 'A task\'s id (e.g. of Scan endpoints)', 'ui' => [ 'expression' => true ] ]),
	    self::field('targetGroupId', 'Into group', [ 'move' ], [ 'required' => true, 'help' => 'The group\'s id', 'ui' => [ 'expression' => true ] ]),
	    self::field('label', 'Label', [ 'label' ], [ 'help' => 'Empty: removes the label', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('policyId', 'Policy', [ 'assignPolicy' ], [ 'required' => true, 'help' => 'The policy\'s id (List policies)', 'ui' => [ 'expression' => true ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$group = fn() => self::id($value('groupId'), 'Group', false);
	$depth = fn() => $this->cfg('recursive', true) ? [ 'filters' => [ 'depth' => [ 'allItemsRecursively' => true ] ] ] : [];
	switch($operation) {
	    case 'list':
		$p = array_filter([ 'parentId' => $group() ]) + $depth();
		$m = (string)$this->cfg('managed', 'all');
		if ($m !== 'all')
		    $p['isManaged'] = $m === 'managed';
		return $this->listOf('network', 'getEndpointsList', $p, 'endpoint');
	    case 'details':
		$r = $this->rpc('network', 'getManagedEndpointDetails', [ 'endpointId' => self::id($value('endpointId'), 'Endpoint') ]);
		return self::done($r, 'endpoint ' . ($r['name'] ?? $text('endpointId')));
	    case 'inventory':
		return $this->listOf('network', 'getNetworkInventoryItems', array_filter([ 'parentId' => $group() ]) + $depth(), 'item');
	    case 'groups':
		$r = $this->rpc('network', 'getCustomGroupsList', array_filter([ 'parentId' => $group() ]));
		return self::done($r, \util\Format::plural(is_array($r) ? count($r) : 0, 'group'));
	    case 'scan':
		$targets = self::ids($value('targetIds'), 'Targets');
		$r = $this->rpc('network', 'createScanTask', array_filter([ 'targetIds' => $targets, 'type' => (int)$this->cfg('scanType', '1'), 'name' => $text('taskName') ], fn($v) => $v !== ''));
		return self::done($r, (self::SCAN_TYPES[(string)$this->cfg('scanType', '1')] ?? 'scan') . ' of ' . \util\Format::plural(count($targets), 'target') . ' started');
	    case 'scanTasks':
		return $this->listOf('network', 'getScanTasksList', [], 'task');
	    case 'taskStatus':
		$r = $this->rpc('network', 'getTaskStatus', [ 'taskId' => self::id($value('taskId'), 'Task') ]);
		return self::done($r, 'task ' . $text('taskId'));
	    case 'move':
		$targets = self::ids($value('targetIds'), 'Targets');
		$r = $this->rpc('network', 'moveEndpoints', [ 'endpointIds' => $targets, 'groupId' => self::id($value('targetGroupId'), 'Into group') ]);
		return self::done($r, \util\Format::plural(count($targets), 'endpoint') . ' moved');
	    case 'label':
		$r = $this->rpc('network', 'setEndpointLabel', [ 'endpointId' => self::id($value('endpointId'), 'Endpoint'), 'label' => mb_substr($text('label'), 0, 64) ]);
		return self::done($r, 'label set');
	    case 'policies':
		return $this->listOf('policies', 'getPoliciesList', [], 'policy');
	    case 'assignPolicy':
		$targets = self::ids($value('targetIds'), 'Targets');
		$r = $this->rpc('network', 'assignPolicy', [ 'policyId' => self::id($value('policyId'), 'Policy'), 'targetIds' => $targets ]);
		return self::done($r, 'policy assigned to ' . \util\Format::plural(count($targets), 'target'));
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
