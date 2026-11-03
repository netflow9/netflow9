<?php

/*
    CLD: 2026-11-03T10:00:00+02:00: new step: Wazuh API - any request of the Server API or of the indexer (what the other Wazuh steps do not have: MITRE, CDB lists, the ruleset's
    files, agent upgrades, other indices, ...): where it goes, the method, the path, the query and the body (JSON objects, or expressions giving them; Other parameters is not used
    here). Its result: the answer as it is
*/

namespace models\node;

class NodeAppWzApi extends NodeAppWazuh {
    public static $name = 'wazuh-api';
    public static $label = 'Wazuh API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'server';
    public static $defaultVariable = 'wazuh';

    const OPERATIONS = [ 'server' => 'Server API request', 'indexer' => 'Indexer request' ];
    const WRITES = [ 'server', 'indexer' ];
    const METHODS = [ 'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'DELETE' => 'DELETE' ];

    protected static function operationFields() {
	$all = [ 'server', 'indexer' ];
	return [
	    self::field('method', 'Method', $all, [ 'type' => 'select', 'required' => true, 'default' => 'GET', 'choices' => self::METHODS, 'ui' => [ 'width' => 3 ] ]),
	    self::field('path', 'Path', $all, [ 'required' => true, 'help' => 'e.g. /mitre/techniques, or to the indexer /wazuh-alerts-*/_search ({{ }} allowed)', 'ui' => [ 'expression' => 'text', 'width' => 9, 'placeholder' => '/...' ] ]),
	    self::field('query', 'Query', $all, [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"limit": 10}', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	    self::field('body', 'Body', $all, [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none)', 'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	];
    }

    public static function configSchema() {
	return array_values(array_filter(parent::configSchema(), fn($f) => $f['key'] !== 'params'));
    }

    //a JSON object of a setting ([] when empty)
    protected static function object($v, $label) {
	if (is_string($v)) {
	    $v = trim($v) === '' ? [] : json_decode($v, true);
	    if (!is_array($v))
		throw new \RuntimeException("{$label}: not a JSON object");
	}
	if (!is_array($v) || ($v && array_is_list($v)))
	    throw new \RuntimeException("{$label}: not a JSON object");
	return $v;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$method = isset(self::METHODS[(string)$this->cfg('method', 'GET')]) ? (string)$this->cfg('method', 'GET') : 'GET';
	$path = $text('path');
	$body = trim((string)$this->cfg('body', '')) === '' ? null : self::object($value('body'), 'Body');
	$query = self::object($value('query'), 'Query');
	$r = $operation === 'indexer' ? $this->wz->indexer($method, $path, $query, $body) : $this->wz->request($method, $path, $query, $body);
	return [ $r, "{$method} {$path}" ];
    }
}
