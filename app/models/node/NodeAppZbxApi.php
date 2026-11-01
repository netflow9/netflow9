<?php

/*
    CLD: 2026-11-01T14:00:00+02:00: new step: Zabbix API - any method of the Zabbix API (what the other Zabbix steps do not have: triggers, templates, users, actions, history,
    SLA, services, ...): the method (e.g. trigger.get) and its parameters (a JSON object or list, or an expression giving one; Other parameters is not used here). The token is
    sent as the other steps send it. Its result: Zabbix's `result`
*/

namespace models\node;

class NodeAppZbxApi extends NodeAppZabbix {
    public static $name = 'zbx-api';
    public static $label = 'Zabbix API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'call';
    public static $defaultVariable = 'zbx';

    const OPERATIONS = [ 'call' => 'Call a method' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'call' ], [ 'required' => true, 'help' => 'e.g. trigger.get, history.get, template.get ({{ }} allowed)',
		'ui' => [ 'expression' => 'text', 'width' => 6, 'placeholder' => 'trigger.get' ] ]),
	    self::field('apiParams', 'Parameters', [ 'call' ], [ 'type' => 'textarea', 'help' => 'A JSON object or list (empty: none), e.g. {"output": ["description"], "only_true": true}',
		'ui' => [ 'rows' => 5, 'expression' => true, 'code' => true ] ]),
	];
    }

    public static function configSchema() {
	return array_values(array_filter(parent::configSchema(), fn($f) => $f['key'] !== 'params'));
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$method = $text('method');
	if (!preg_match('/^[a-z]+\.[a-zA-Z]+$/', $method))
	    throw new \RuntimeException("Method: not a Zabbix API method: `" . mb_substr($method, 0, 64) . '`');
	if ($method === 'apiinfo.version')
	    return [ $this->zbx->version(), $method ];
	$p = $value('apiParams');
	if (is_string($p)) {
	    $p = trim($p) === '' ? [] : json_decode($p, true);
	    if (!is_array($p))
		throw new \RuntimeException('Parameters: not a JSON object or list');
	}
	if (!is_array($p))
	    throw new \RuntimeException('Parameters: not a JSON object or list');
	$r = $this->zbx->call($method, $p);
	return [ $r, $method . (is_array($r) && array_is_list($r) ? ': ' . \util\Format::plural(count($r), 'item') : '') ];
    }
}
