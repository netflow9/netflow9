<?php

/*
    Wazuh Agents - the agents and their groups (the Server API). Operations (setting `operation`):
	list          the agents (GET /agents): of statuses, a group, a text
	get           an agent (GET /agents?agents_list=<id>)
	summary       how many per status (GET /agents/summary/status)
	outdated      the agents with an older version than the manager (GET /agents/outdated)
	create        an agent (POST /agents): its id and enrollment key
	key           an agent's enrollment key (GET /agents/<id>/key)
	restart       agents restarted (PUT /agents/restart)
	delete        agents deleted, named only (DELETE /agents: status=all, older_than=0s; never `all`)
	groups        the groups (GET /groups)
	groupAgents   a group's agents (GET /groups/<group>/agents)
	assign        agents added to a group (PUT /agents/group)
	unassign      agents removed from a group (DELETE /agents/group)
	groupCreate   a group (POST /groups)
	groupDelete   a group (DELETE /groups?groups_list=<group>)
    Reading a key is in WRITES although it changes nothing: an agent's key lets its holder enroll as that agent, so an AI step does not read it unless chosen
*/

namespace models\node;

class NodeAppWzAgents extends NodeAppWazuh {
    public static $name = 'wazuh-agents';
    public static $label = 'Wazuh Agents';
    public static $icon = 'bi-pc-display';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'agents';

    const OPERATIONS = [
	'list' => 'List agents',
	'get' => 'Get an agent',
	'summary' => 'Count per status',
	'outdated' => 'List outdated agents',
	'create' => 'Create an agent',
	'key' => 'Get an agent\'s key',
	'restart' => 'Restart agents',
	'delete' => 'Delete agents',
	'groups' => 'List groups',
	'groupAgents' => 'List a group\'s agents',
	'assign' => 'Add agents to a group',
	'unassign' => 'Remove agents from a group',
	'groupCreate' => 'Create a group',
	'groupDelete' => 'Delete a group',
    ];
    const WRITES = [ 'create', 'key', 'restart', 'delete', 'assign', 'unassign', 'groupCreate', 'groupDelete' ];
    const LISTS = [ 'list', 'outdated', 'groups', 'groupAgents' ];
    const STATUSES = [ 'active' => 'Active', 'disconnected' => 'Disconnected', 'pending' => 'Pending', 'never_connected' => 'Never connected' ];

    protected static function operationFields() {
	return [
	    self::field('status', 'Status', [ 'list', 'groupAgents' ], [ 'type' => 'select', 'multiple' => true, 'default' => [], 'choices' => self::STATUSES, 'help' => 'Empty: every status', 'ui' => [ 'width' => 6 ] ]),
	    self::field('group', 'Group', [ 'list', 'groupAgents', 'assign', 'unassign', 'groupCreate', 'groupDelete' ], [ 'help' => 'Its name (List agents: empty: every group)',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'groupAgents', 'assign', 'unassign', 'groupCreate', 'groupDelete' ] ], 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('search', 'Containing', [ 'list', 'outdated', 'groups', 'groupAgents' ], [ 'help' => 'A text in any field; empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6, 'newRow' => true ] ]),
	    self::field('agent', 'Agent', [ 'get', 'key' ], [ 'required' => true, 'help' => 'Its id; an agent or an alert works too', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('agents', 'Agents', [ 'restart', 'delete', 'assign', 'unassign' ], [ 'required' => true, 'help' => 'Their ids, separated by commas, or a list of agents or alerts. Or all, for every agent (not to delete)', 'ui' => [ 'expression' => true ] ]),
	    self::field('name', 'Name', [ 'create' ], [ 'required' => true, 'help' => 'The new agent\'s name: letters, digits, _ - .', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('ip', 'IP address', [ 'create' ], [ 'help' => 'The address it connects from, a network or any; empty: found by Wazuh', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('purge', 'Remove from the key store', [ 'delete' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Else its key is only marked removed', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'list':
		return $this->listOf('/agents', $this->agentFilter() + ($text('group') !== '' ? [ 'group' => $this->groupSetting() ] : []), 'agent');
	    case 'get':
		$id = self::agentId($value('agent'));
		$a = $this->one('/agents', "agent {$id}", [ 'agents_list' => $id ]);
		return [ $a, "{$id}: " . ($a['name'] ?? '') . ' (' . ($a['status'] ?? '') . ')' ];
	    case 'summary':
		$r = $this->wz->request('GET', '/agents/summary/status', self::WAIT);
		$d = (array)($r['data'] ?? []);
		return [ $d, \util\Format::plural((int)($d['connection']['total'] ?? 0), 'agent') . ', ' . (int)($d['connection']['active'] ?? 0) . ' active' ];
	    case 'outdated':
		return $this->listOf('/agents/outdated', [ 'search' => $text('search') ], 'outdated agent');
	    case 'create':
		$name = $text('name');
		if (!preg_match('/^[\w.-]{1,128}$/', $name))
		    throw new \RuntimeException('Name: not an agent\'s name: `' . mb_substr($name, 0, 64) . '`');
		$body = [ 'name' => $name ] + ($text('ip') !== '' ? [ 'ip' => $text('ip') ] : []);
		$r = $this->wz->request('POST', '/agents', self::WAIT, array_replace($body, $this->extraParams()));
		$d = (array)($r['data'] ?? []);
		return [ $d, "agent {$name} created: " . ($d['id'] ?? '') ];
	    case 'key':
		$id = self::agentId($value('agent'));
		return [ $this->one("/agents/{$id}/key", "key of agent {$id}"), "the key of agent {$id}" ];
	    case 'restart':
		$ids = self::agentList($value('agents'));
		return $this->change('PUT', '/agents/restart', $ids === null ? [] : [ 'agents_list' => $ids ], null, 'restart sent to agents');
	    case 'delete':
		$ids = self::agentList($value('agents'), false);
		return $this->change('DELETE', '/agents', [ 'agents_list' => $ids, 'status' => 'all', 'older_than' => '0s', 'purge' => (bool)$this->cfg('purge', false) ], null, 'agents deleted');
	    case 'groups':
		return $this->listOf('/groups', [ 'search' => $text('search') ], 'group');
	    case 'groupAgents':
		return $this->listOf('/groups/' . $this->groupSetting() . '/agents', $this->agentFilter(), 'agent');
	    case 'assign':
	    case 'unassign':
		$ids = self::agentList($value('agents'));
		$g = $this->groupSetting();
		if ($ids === null && $operation === 'unassign')
		    $ids = [ 'all' ];
		return $this->change($operation === 'assign' ? 'PUT' : 'DELETE', '/agents/group', ($ids === null ? [] : [ 'agents_list' => $ids ]) + [ 'group_id' => $g ], null,
		    $operation === 'assign' ? "agents added to {$g}" : "agents removed from {$g}");
	    case 'groupCreate':
		$g = $this->groupSetting();
		$r = $this->wz->request('POST', '/groups', self::WAIT, array_replace([ 'group_id' => $g ], $this->extraParams()));
		return [ [ 'group' => $g, 'message' => (string)($r['message'] ?? '') ], "group {$g} created" ];
	    case 'groupDelete':
		$g = $this->groupSetting();
		return $this->change('DELETE', '/groups', [ 'groups_list' => $g ], null, "group {$g} deleted, agents moved");
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //the setting Group (a group name)
    private function groupSetting() {
	return self::group($this->settingOf('group'));
    }

    //the API's filters of the settings Search and Status
    private function agentFilter() {
	return [ 'search' => $this->settingText('search'), 'status' => array_values(array_intersect(array_map('strval', (array)$this->cfg('status', [])), array_keys(self::STATUSES))) ];
    }
}
