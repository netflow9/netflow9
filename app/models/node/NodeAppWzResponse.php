<?php

/*
    Wazuh Active Response - runs a response on agents (PUT /active-response). Operations (setting `operation`):
	block  an IP blocked on agents: a built-in script (`!` + its name) with the address as the alert's data.srcip
	run    a command defined in the manager's ossec.conf, or `!script` from the agents' active-response/bin, with arguments and the alert's data
    Wazuh answers when the command is sent, not when it has run; it has no request that undoes a block. An empty Agents is an error (Wazuh would take it as every agent); `all` is asked for
*/

namespace models\node;

class NodeAppWzResponse extends NodeAppWazuh {
    public static $name = 'wazuh-response';
    public static $label = 'Wazuh Active Response';
    public static $icon = 'bi-shield-exclamation';
    public static $defaultOperation = 'block';
    public static $defaultVariable = 'response';

    const OPERATIONS = [ 'block' => 'Block an IP', 'run' => 'Run a command' ];
    const WRITES = [ 'block', 'run' ];
    const SCRIPTS = [ 'firewall-drop' => 'firewall-drop (Linux, iptables)', 'host-deny' => 'host-deny (Linux, /etc/hosts.deny)', 'route-null' => 'route-null (Linux)', 'netsh.exe' => 'netsh (Windows)', 'route-null.exe' => 'route-null (Windows)' ];

    protected static function operationFields() {
	return [
	    self::field('agents', 'Agents', [ 'block', 'run' ], [ 'required' => true, 'help' => 'Their ids, separated by commas, or a list of agents or alerts. Or all, for every agent', 'ui' => [ 'expression' => true ] ]),
	    self::field('ip', 'IP address', [ 'block' ], [ 'required' => true, 'help' => 'To block on those agents', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('script', 'Script', [ 'block' ], [ 'type' => 'select', 'default' => 'firewall-drop', 'choices' => self::SCRIPTS, 'requiredIf' => [ 'if' => 'operation', 'in' => [ 'block' ] ], 'ui' => [ 'width' => 6 ] ]),
	    self::field('command', 'Command', [ 'run' ], [ 'required' => true, 'help' => 'A command of the manager\'s ossec.conf, or !script for a script on the agent', 'ui' => [ 'expression' => 'text', 'width' => 6, 'placeholder' => '!restart-wazuh' ] ]),
	    self::field('arguments', 'Arguments', [ 'run' ], [ 'type' => 'textarea', 'help' => 'One per line, or a list', 'ui' => [ 'rows' => 2, 'expression' => true ] ]),
	    self::field('alert', 'Alert data', [ 'run' ], [ 'type' => 'textarea', 'help' => 'A JSON object the script reads as the alert\'s data, e.g. {"srcip": "203.0.113.5"}', 'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$ids = self::agentList($value('agents'));
	$query = $ids === null ? [] : [ 'agents_list' => $ids ];
	$where = $ids === null ? 'every agent' : \util\Format::plural(count($ids), 'agent');
	if ($operation === 'block') {
	    $ip = $text('ip');
	    if (filter_var($ip, FILTER_VALIDATE_IP) === false)
		throw new \RuntimeException('IP address: not an address: `' . mb_substr($ip, 0, 64) . '`');
	    $script = (string)$this->cfg('script', 'firewall-drop');
	    if (!isset(self::SCRIPTS[$script]))
		throw new \RuntimeException("Script: unknown script `{$script}`");
	    return $this->change('PUT', '/active-response', $query, [ 'command' => "!{$script}", 'arguments' => [], 'alert' => [ 'data' => [ 'srcip' => $ip ] ] ], "{$script} {$ip} sent to {$where}, accepted for");
	}
	if ($operation !== 'run')
	    throw new \RuntimeException("unknown operation `{$operation}`");
	$command = $text('command');
	if (!preg_match('/^!?[\w.-]{1,128}$/', $command))
	    throw new \RuntimeException('Command: not a command\'s name: `' . mb_substr($command, 0, 64) . '`');
	$args = $value('arguments');
	$args = is_array($args) ? array_values($args) : preg_split('/\r?\n/', trim(\util\Expression::toText($args)));
	$args = array_values(array_filter(array_map(fn($a) => trim(\util\Expression::toText($a)), $args), fn($a) => $a !== ''));
	$body = [ 'command' => $command, 'arguments' => $args ];
	$alert = $value('alert');
	if (is_string($alert) && trim($alert) !== '') {
	    $alert = json_decode($alert, true);
	    if (!is_array($alert) || array_is_list($alert))
		throw new \RuntimeException('Alert data: not a JSON object');
	}
	if (is_array($alert) && $alert)
	    $body['alert'] = [ 'data' => $alert ];
	return $this->change('PUT', '/active-response', $query, $body, "{$command} sent to {$where}, accepted for");
    }
}
