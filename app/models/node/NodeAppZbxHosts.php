<?php

/*
    Zabbix Hosts - the hosts Zabbix monitors and their latest values. Operations:
	list      hosts (host.get): a name containing a text, of host groups, enabled / disabled; with their interfaces' addresses and groups
	get       a host by its technical name: with interfaces, groups, templates, tags, macros' names and its current problems' count (none: an error)
	create    a host (host.create): technical and visible name, host groups and templates (by name), an agent or SNMP interface (address: an IP or a DNS name), tags
	enable / disable   a host's monitoring turned on / off (host.update status)
	delete    a host removed (host.delete)
	items     a host's latest values (item.get): the items whose key or name contains a text: { itemid, name, key, value, units, lastclock (ISO 8601), state }
	groups    the host groups (hostgroup.get), a name containing a text
    6.2 and later: selectHostGroups (groups before)
*/

namespace models\node;

use util\Zabbix;

class NodeAppZbxHosts extends NodeAppZabbix {
    public static $name = 'zbx-hosts';
    public static $label = 'Zabbix Hosts';
    public static $icon = 'bi-hdd-network';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'hosts';

    const OPERATIONS = [
	'list' => 'List hosts',
	'get' => 'Get a host',
	'create' => 'Create a host',
	'enable' => 'Enable a host',
	'disable' => 'Disable a host',
	'delete' => 'Delete a host',
	'items' => 'Latest values of a host',
	'groups' => 'List host groups',
    ];
    //the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'create', 'enable', 'disable', 'delete' ];
    const LISTS = [ 'list', 'items', 'groups' ];
    const STATUS = [ 'all' => 'All', 'enabled' => 'Enabled', 'disabled' => 'Disabled' ];
    const INTERFACES = [ '1' => 'Zabbix agent (10050)', '2' => 'SNMP (161)', 'none' => 'None' ];
    const PORTS = [ '1' => '10050', '2' => '161' ];

    protected static function operationFields() {
	$one = [ 'get', 'enable', 'disable', 'delete', 'items' ];
	return [
	    self::field('host', 'Host', array_merge($one, [ 'create' ]), [ 'required' => true, 'help' => 'Its technical name (Host name in Zabbix)', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('visibleName', 'Visible name', [ 'create' ], [ 'help' => 'Empty: the technical name', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('search', 'Name contains', [ 'list', 'groups', 'items' ], [ 'help' => 'Empty: all (Latest values: the item\'s key or name)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('groups', 'Host groups', [ 'list', 'create' ], [ 'help' => 'Their names, separated by commas (Create: at least one)', 'requiredIf' => [ 'if' => 'operation', 'in' => [ 'create' ] ],
		'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('status', 'Status', [ 'list' ], [ 'type' => 'select', 'default' => 'all', 'choices' => self::STATUS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('templates', 'Templates', [ 'create' ], [ 'help' => 'Their names, separated by commas, e.g. Linux by Zabbix agent', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('interface', 'Interface', [ 'create' ], [ 'type' => 'select', 'default' => '1', 'choices' => self::INTERFACES, 'ui' => [ 'width' => 6 ] ]),
	    self::field('address', 'Address', [ 'create' ], [ 'help' => 'An IP address or a DNS name', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('tags', 'Tags', [ 'create' ], [ 'help' => 'tag=value, separated by commas', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	];
    }

    protected function groupsKey() {
	return $this->zbx->atLeast('6.2') ? 'selectHostGroups' : 'selectGroups';
    }

    //a host row as given: hostid, host, name, status (enabled / disabled), interfaces [ { ip, dns, port, type } ], groups [ { groupid, name } ], + what else was read
    protected static function hostRow(array $h) {
	$h['groups'] = array_values(array_map(fn($g) => [ 'groupid' => (string)($g['groupid'] ?? ''), 'name' => (string)($g['name'] ?? '') ], (array)($h['hostgroups'] ?? $h['groups'] ?? [])));
	unset($h['hostgroups']);
	$h['status'] = (string)($h['status'] ?? '0') === '0' ? 'enabled' : 'disabled';
	return $h;
    }

    //the host of a technical name (with $more of host.get): its row; none: an error
    protected function findHost($name, array $more = []) {
	$rows = (array)$this->call('host.get', [ 'output' => [ 'hostid', 'host', 'name', 'status', 'description' ], 'filter' => [ 'host' => [ $name ] ] ] + $more);
	if (!$rows)
	    throw new \RuntimeException("Host: Zabbix has no host `{$name}`");
	return $rows[0];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$limit = $this->maxItems() + 1;
	switch($operation) {
	    case 'list':
		$params = [ 'output' => [ 'hostid', 'host', 'name', 'status' ], 'selectInterfaces' => [ 'ip', 'dns', 'port', 'type', 'main' ], $this->groupsKey() => [ 'groupid', 'name' ],
		    'sortfield' => 'name', 'limit' => $limit ];
		if ($text('search') !== '')
		    $params['search'] = [ 'name' => $text('search') ];
		$groups = self::names($value('groups'));
		if ($groups)
		    $params['groupids'] = $this->zbx->groupIds($groups);
		$status = (string)$this->cfg('status', 'all');
		if ($status === 'enabled' || $status === 'disabled')
		    $params['filter'] = [ 'status' => $status === 'enabled' ? 0 : 1 ];
		return $this->listResult(array_map([ self::class, 'hostRow' ], (array)$this->call('host.get', $params)), 'host');
	    case 'get':
		$name = self::host($value('host'));
		$h = self::hostRow($this->findHost($name, [ 'selectInterfaces' => [ 'interfaceid', 'ip', 'dns', 'port', 'type', 'main', 'useip' ], $this->groupsKey() => [ 'groupid', 'name' ],
		    'selectParentTemplates' => [ 'templateid', 'name' ], 'selectTags' => [ 'tag', 'value' ], 'selectMacros' => [ 'macro' ] ]));
		$h['templates'] = $h['parentTemplates'] ?? [];
		unset($h['parentTemplates']);
		$h['problems'] = (int)$this->zbx->call('problem.get', [ 'hostids' => [ $h['hostid'] ], 'countOutput' => true ]);
		return [ $h, "{$name}: {$h['status']}, " . \util\Format::plural($h['problems'], 'problem') ];
	    case 'create':
		$name = self::host($value('host'));
		$groups = self::names($value('groups'));
		if (!$groups)
		    throw new \RuntimeException('Host groups: give at least one');
		$params = [ 'host' => $name, 'groups' => array_map(fn($id) => [ 'groupid' => $id ], $this->zbx->groupIds($groups)) ];
		if ($text('visibleName') !== '')
		    $params['name'] = mb_substr($text('visibleName'), 0, 128);
		$templates = self::names($value('templates'));
		if ($templates)
		    $params['templates'] = array_map(fn($id) => [ 'templateid' => $id ], $this->zbx->templateIds($templates));
		$type = (string)$this->cfg('interface', '1');
		if (isset(self::PORTS[$type])) {
		    $address = $text('address');
		    if ($address === '')
			throw new \RuntimeException('Address: empty (or choose no interface)');
		    $ip = filter_var($address, FILTER_VALIDATE_IP) !== false;
		    if (!$ip && !preg_match('/^[A-Za-z0-9.-]{1,253}$/', $address))
			throw new \RuntimeException("Address: not an IP address or a DNS name: `" . mb_substr($address, 0, 80) . '`');
		    $iface = [ 'type' => (int)$type, 'main' => 1, 'useip' => $ip ? 1 : 0, 'ip' => $ip ? $address : '', 'dns' => $ip ? '' : $address, 'port' => self::PORTS[$type] ];
		    if ($type === '2')
			$iface['details'] = [ 'version' => 2, 'bulk' => 1, 'community' => '{$SNMP_COMMUNITY}' ];
		    $params['interfaces'] = [ $iface ];
		}
		$tags = self::tags($value('tags'));
		if ($tags)
		    $params['tags'] = $tags;
		$r = (array)$this->call('host.create', $params);
		$id = (string)(((array)($r['hostids'] ?? []))[0] ?? '');
		return [ [ 'hostid' => $id, 'host' => $name ], "host {$name} created ({$id})" ];
	    case 'enable':
	    case 'disable':
		$name = self::host($value('host'));
		$h = $this->findHost($name);
		$this->call('host.update', [ 'hostid' => (string)$h['hostid'], 'status' => $operation === 'enable' ? 0 : 1 ]);
		return [ [ 'hostid' => (string)$h['hostid'], 'host' => $name, 'status' => $operation === 'enable' ? 'enabled' : 'disabled' ], "{$name} {$operation}d" ];
	    case 'delete':
		$name = self::host($value('host'));
		$h = $this->findHost($name);
		$this->call('host.delete', [ (string)$h['hostid'] ]);
		return [ [ 'hostid' => (string)$h['hostid'], 'host' => $name ], "{$name} deleted" ];
	    case 'items':
		$name = self::host($value('host'));
		$h = $this->findHost($name);
		$params = [ 'hostids' => [ (string)$h['hostid'] ], 'output' => [ 'itemid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'state', 'status' ], 'sortfield' => 'name', 'limit' => $limit ];
		if ($text('search') !== '')
		    $params += [ 'search' => [ 'key_' => $text('search'), 'name' => $text('search') ], 'searchByAny' => true ];
		$rows = array_map(fn($i) => [ 'itemid' => (string)($i['itemid'] ?? ''), 'name' => (string)($i['name'] ?? ''), 'key' => (string)($i['key_'] ?? ''),
		    'value' => (string)($i['lastvalue'] ?? ''), 'units' => (string)($i['units'] ?? ''), 'lastclock' => Zabbix::iso($i['lastclock'] ?? 0),
		    'state' => (string)($i['state'] ?? '0') === '0' ? 'normal' : 'not supported', 'enabled' => (string)($i['status'] ?? '0') === '0' ], (array)$this->call('item.get', $params));
		return $this->listResult($rows, 'item');
	    case 'groups':
		$params = [ 'output' => [ 'groupid', 'name' ], 'sortfield' => 'name', 'limit' => $limit ];
		if ($text('search') !== '')
		    $params['search'] = [ 'name' => $text('search') ];
		return $this->listResult((array)$this->call('hostgroup.get', $params), 'host group');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
