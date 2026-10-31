<?php

/*
    CLD: 2026-11-01T10:00:00+02:00: new step: Sophos Firewall Rules - the firewall rules (FirewallRule). Operations (setting `operation`):
	list      the rules (name containing a text)
	get       a rule, by its name
	enable    a rule turned on (read, Status Enable, sent back whole)
	disable   a rule turned off (Status Disable)
*/

namespace models\node;

class NodeAppSfosRules extends NodeAppSophos {
    public static $name = 'sfos-rules';
    public static $label = 'Sophos Firewall Rules';
    public static $icon = 'bi-shield-lock';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'rules';

    const OPERATIONS = [
	'list' => 'List rules',
	'get' => 'Get a rule',
	'enable' => 'Enable a rule',
	'disable' => 'Disable a rule',
    ];
    const LISTS = [ 'list' ];

    protected static function operationFields() {
	return [
	    self::field('contains', 'Name contains', [ 'list' ], [ 'help' => 'Empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('rule', 'Rule', [ 'get', 'enable', 'disable' ], [ 'required' => true, 'help' => 'The firewall rule\'s name', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'list':
		return $this->listOf('FirewallRule', $text('contains'), 'rule');
	    case 'get':
		$n = self::objName($value('rule'), 'Rule');
		return [ $this->need('FirewallRule', $n, 'the rule'), "rule {$n}" ];
	    case 'enable':
	    case 'disable':
		$n = self::objName($value('rule'), 'Rule');
		$rule = $this->need('FirewallRule', $n, 'the rule');
		$status = $operation === 'enable' ? 'Enable' : 'Disable';
		if ((string)($rule['Status'] ?? '') === $status)
		    return [ [ 'name' => $n, 'status' => $status, 'message' => "{$operation}d already" ], "rule {$n} {$operation}d already" ];
		$rule['Status'] = $status;
		return [ [ 'name' => $n, 'status' => $status, 'message' => $this->change('update', 'FirewallRule', $rule) ], "rule {$n} {$operation}d" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
