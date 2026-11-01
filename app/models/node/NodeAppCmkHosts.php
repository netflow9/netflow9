<?php

/*
    CLD: 2026-11-01T08:00:00+02:00: new step: Checkmk Hosts - the hosts Checkmk monitors (its configuration). Operations (setting `operation`):
	list       the hosts (GET domain-types/host_config/collections/all): { items: [ { id, title, folder, attributes } ] }
	get        a host's configuration (GET objects/host_config/<host>)
	create     a host (POST domain-types/host_config/collections/all: folder, host name, IP address, alias; Other parameters: more attributes, e.g. tags)
	delete     a host (DELETE objects/host_config/<host>)
	discover   its services found again (POST domain-types/service_discovery_run/actions/start/invoke: mode)
	activate   the pending changes activated (POST domain-types/activation_run/actions/activate-changes/invoke, If-Match: *): no pending change is fine
	pending    the pending changes (GET domain-types/activation_run/collections/pending_changes)
    Create, delete and discover: Activate changes (on by default) activates them afterwards; else they wait for someone to activate them in Checkmk
*/

namespace models\node;

class NodeAppCmkHosts extends NodeAppCheckmk {
    public static $name = 'cmk-hosts';
    public static $label = 'Checkmk Hosts';
    public static $icon = 'bi-hdd-network';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'hosts';

    const OPERATIONS = [
	'list' => 'List hosts',
	'get' => 'Get a host',
	'create' => 'Create a host',
	'delete' => 'Delete a host',
	'discover' => 'Discover services',
	'activate' => 'Activate pending changes',
	'pending' => 'List pending changes',
    ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'create', 'delete', 'discover', 'activate' ];
    const LISTS = [ 'list', 'pending' ];
    const MODES = [ 'fix_all' => 'Accept all (new and vanished services, labels)', 'new' => 'Add new services only', 'refresh' => 'Refresh (search again)', 'tabula_rasa' => 'Start over (remove all, then add)' ];
    const CHANGES = [ 'create', 'delete', 'discover' ];

    protected static function operationFields() {
	return [
	    self::field('host', 'Host', [ 'get', 'create', 'delete', 'discover' ], [ 'required' => true, 'help' => 'Its name in Checkmk', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('folder', 'Folder', [ 'create' ], [ 'default' => '/', 'help' => 'e.g. / or /servers/linux', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('ip', 'IP address', [ 'create' ], [ 'help' => 'Empty: Checkmk resolves the host name', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('alias', 'Alias', [ 'create' ], [ 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('mode', 'Discovery', [ 'discover' ], [ 'type' => 'select', 'default' => 'fix_all', 'choices' => self::MODES, 'ui' => [ 'width' => 6 ] ]),
	    self::field('activateAfter', 'Activate changes', self::CHANGES, [ 'type' => 'switch', 'default' => true, 'help' => 'Afterwards; off: they wait in Checkmk', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    //the pending changes activated; none pending: fine. [ answer, summary ]
    protected function activate() {
	try {
	    $r = $this->cmk->request('POST', '/domain-types/activation_run/actions/activate-changes/invoke', [], [ 'redirect' => false, 'sites' => [], 'force_foreign_changes' => false ], [ 'If-Match' => '*' ]);
	} catch (\RuntimeException $e) {
	    if (preg_match('/no pending changes|nothing to activate/i', $e->getMessage()))
		return [ null, 'no pending changes' ];
	    throw $e;
	}
	return [ $r, 'changes activated' ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'list':
		return $this->collection('/domain-types/host_config/collections/all', [], 'host');
	    case 'pending':
		return $this->collection('/domain-types/activation_run/collections/pending_changes', [], 'pending change');
	    case 'activate':
		return $this->activate();
	    case 'get':
		$h = self::host($value('host'));
		return [ $this->get('/objects/host_config/' . rawurlencode($h)), "host {$h}" ];
	}
	$h = self::host($value('host'));
	switch($operation) {
	    case 'create':
		$folder = $text('folder') !== '' ? $text('folder') : '/';
		if (!preg_match('~^(/|(/[A-Za-z0-9_-]+)+)$~', $folder))
		    throw new \RuntimeException("Folder: not a folder path: `" . mb_substr($folder, 0, 80) . '`');
		$attributes = array_filter([ 'alias' => $text('alias') ]);
		if ($text('ip') !== '') {
		    $ip = self::ips($value('ip'), 'IP address', 1)[0];
		    $attributes[str_contains($ip, ':') ? 'ipv6address' : 'ipaddress'] = $ip;
		}
		$r = $this->change('POST', '/domain-types/host_config/collections/all', [ 'folder' => $folder, 'host_name' => $h, 'attributes' => (object)$attributes ]);
		$summary = "host {$h} created in {$folder}";
		break;
	    case 'delete':
		$r = $this->change('DELETE', '/objects/host_config/' . rawurlencode($h));
		$summary = "host {$h} deleted";
		break;
	    case 'discover':
		$mode = isset(self::MODES[(string)$this->cfg('mode', 'fix_all')]) ? (string)$this->cfg('mode', 'fix_all') : 'fix_all';
		$r = $this->change('POST', '/domain-types/service_discovery_run/actions/start/invoke', [ 'host_name' => $h, 'mode' => $mode ]);
		$summary = "services of {$h} discovered ({$mode})";
		break;
	    default:
		throw new \RuntimeException("unknown operation `{$operation}`");
	}
	if ($this->cfg('activateAfter', true)) {
	    [ , $activated ] = $this->activate();
	    $summary .= ", {$activated}";
	}
	return [ $r, $summary ];
    }

    //IP addresses checked (as NodeAppFortiGate::ips)
    public static function ips($value, $label, $max = 1) {
	return NodeAppFortiGate::ips($value, $label, $max);
    }
}
