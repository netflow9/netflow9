<?php

/*
    CLD: 2026-11-01T14:00:00+02:00: new step: Proxmox API - any request of the Proxmox VE API (what the other Proxmox steps do not have: a guest's configuration changed, firewall,
    replication, Ceph, ...): the method, the path (under /api2/json, e.g. /nodes/pve1/qemu/100/config), the parameters (a JSON object, or an expression giving one; Other parameters
    is not used here). A task it starts (its UPID) optionally waited for: its status. Its result: Proxmox's data as it is
*/

namespace models\node;

class NodeAppProxmoxApi extends NodeAppProxmox {
    public static $name = 'proxmox-api';
    public static $label = 'Proxmox API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'request';
    public static $defaultVariable = 'proxmox';

    const OPERATIONS = [ 'request' => 'Send a request' ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something (any request); the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'request' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'DELETE' => 'DELETE' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'request' ], [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', [ 'request' ], [ 'required' => true, 'help' => 'Under /api2/json, e.g. /nodes/pve1/qemu/100/config ({{ }} allowed)',
		'ui' => [ 'expression' => 'text', 'width' => 9, 'placeholder' => '/nodes/...' ] ]),
	    self::field('parameters', 'Parameters', [ 'request' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"memory": 4096}', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	    self::field('wait', 'Wait for the task it starts', [ 'request' ], [ 'type' => 'switch', 'default' => false, 'help' => 'An answer that is a task (UPID): its status when it stops', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    public static function configSchema() {
	return array_values(array_filter(parent::configSchema(), fn($f) => $f['key'] !== 'params'));
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$method = isset(self::METHODS[(string)$this->cfg('method', 'GET')]) ? (string)$this->cfg('method', 'GET') : 'GET';
	$path = $text('path');
	$params = $value('parameters');
	if (is_string($params)) {
	    $params = trim($params) === '' ? [] : json_decode($params, true);
	    if (!is_array($params))
		throw new \RuntimeException('Parameters: not a JSON object');
	}
	if (!is_array($params) || ($params && array_is_list($params)))
	    throw new \RuntimeException('Parameters: not a JSON object');
	$r = $this->pve->request($method, $path, $params);
	if (is_string($r) && \util\Proxmox::taskNode($r) !== null && $this->cfg('wait', false))
	    $r = $this->pve->waitTask($r, "{$method} {$path}");
	return [ $r, "{$method} {$path}" ];
    }
}
