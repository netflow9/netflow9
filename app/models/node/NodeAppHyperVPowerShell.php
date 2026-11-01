<?php

/*
    CLD: 2026-11-01T16:00:00+02:00: new step: Hyper-V PowerShell - a PowerShell script run on the Hyper-V host (Windows PowerShell 5.1, the Hyper-V module there), for what the other
    Hyper-V steps do not do (new VMs, exports, VM settings, failover clusters, ...): its value written as JSON is the result (ConvertTo-Json, depth 8). The parameters (a JSON
    object, or an expression giving one) are $P in the script: values of the workflow go there, never into the script's text. Errors stop the script; nf9find and nf9vm are there
*/

namespace models\node;

class NodeAppHyperVPowerShell extends NodeAppHyperV {
    public static $name = 'hyperv-powershell';
    public static $label = 'Hyper-V PowerShell';
    public static $icon = 'bi-terminal';
    public static $audience = 'advanced';
    public static $defaultOperation = 'run';
    public static $defaultVariable = 'result';

    const OPERATIONS = [ 'run' => 'Run a script' ];

    protected static function operationFields() {
	return [
	    self::field('script', 'Script', [ 'run' ], [ 'type' => 'textarea', 'required' => true, 'default' => "Get-VM | Select-Object Name, @{ n = 'State'; e = { \"\$(\$_.State)\" } }",
		'help' => 'Its value is the result; the parameters are $P (e.g. $P.name)', 'ui' => [ 'rows' => 8 ] ]),
	    self::field('parameters', 'Parameters', [ 'run' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"name": "{{ vm.name }}"}', 'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$script = trim((string)$this->cfg('script', ''));
	if ($script === '')
	    throw new \RuntimeException('Script: empty');
	$params = trim((string)$this->cfg('parameters', '')) === '' ? [] : $value('parameters');
	if (is_string($params)) {
	    $params = json_decode($params, true);
	    if (!is_array($params))
		throw new \RuntimeException('Parameters: not a JSON object');
	}
	if (!is_array($params) || ($params && array_is_list($params)))
	    throw new \RuntimeException('Parameters: not a JSON object');
	$r = $this->ps('run', $script, $params);
	return [ $r, 'script run' . (is_array($r) && array_is_list($r) ? ': ' . \util\Format::plural(count($r), 'item') : '') ];
    }
}
