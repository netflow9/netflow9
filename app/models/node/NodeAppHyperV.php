<?php

/*
    the base of the Hyper-V steps (VMs, Checkpoints, Host, PowerShell; not registered itself), through a Hyper-V credential of the project
    (CredentialHyperV, provides `hyperv`; util\WinRM: PowerShell over WinRM HTTPS, the Hyper-V module of the host). As NodeAppHyperV:
	- the shape: one input, `success` (+ `error` when On error is "Continue on the `error` output")
	- the settings every Hyper-V step has: credential, operation (static::OPERATIONS), the operation's own settings (operationFields(): each shown for its operations),
	    At most (the lists), timeout, result variable, on error
	- execute(): runOperation() (the subclass); the result in the variable, logged as "<label> <operation>: <summary> (<n> ms)"; a \RuntimeException (PowerShell's errors,
	    WinRM's, no connection, a value that cannot be used) follows On error; other errors fail the step
	- each operation is one PowerShell script (ps()), the step's settings given to it as $P (data, never run); PRELUDE: r22find (a VM by name or ID), r22vm (a VM as JSON)
	- a list's result: { items, count, total, truncated } (the first At most kept)
*/

namespace models\node;

abstract class NodeAppHyperV extends \models\wf\WfNode {
    //its operations can be an AI step's tools (util\AgentTools: settingValue, setResult)
    use AgentToolTrait;

    //static data/structure
    public static $type = 'app';
    public static $icon = 'bi-windows';
    public static $color = 'var(--wf-color-app)';
    public static $category = 'virtualization';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    public static $defaultOperation = '';
    public static $defaultVariable = 'hyperv';

    const OPERATIONS = [];
    const LISTS = [];
    const MAX_ITEMS = 10000;
    const MAX_TIMEOUT = 3600;

    //the client of the run
    protected $win = null;

    //the operation: [ result, summary for the log ]; $text($key): a setting as trimmed text, $value($key): its value ({{ }} evaluated)
    abstract protected function runOperation($operation, \Closure $text, \Closure $value);

    protected static function operationFields() {
	return [];
    }

    // ------------------------------------------------------------------------------------
    //settings

    public static function configSchema() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor('hyperv', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	$fields = [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Hyper-V credential',
		'choices' => $choices,
		'help' => 'A Hyper-V host of the project',
		'required' => true,
	    ],
	    [
		'key' => 'operation',
		'type' => 'select',
		'label' => 'Operation',
		'required' => true,
		'default' => static::$defaultOperation,
		'choices' => static::OPERATIONS,
	    ],
	];
	foreach(static::operationFields() as $f) {
	    $ops = $f['ops'] ?? null;
	    unset($f['ops']);
	    $f += [ 'default' => '' ];
	    if ($ops !== null) {
		$f['ui'] = ($f['ui'] ?? []) + [ 'showIf' => [ 'key' => 'operation', 'operator' => 'in', 'value' => $ops ] ];
		if (!empty($f['required'])) {
		    unset($f['required']);
		    $f['requiredIf'] = [ 'if' => 'operation', 'in' => $ops ];
		}
	    }
	    $fields[] = $f;
	}
	if (static::LISTS)
	    $fields[] = [
		'key' => 'maxItems',
		'type' => 'number',
		'label' => 'At most (items)',
		'required' => true,
		'default' => 1000,
		'help' => 'Of a list: more are left out (truncated)',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_ITEMS, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_ITEMS ] ],
		'ui' => [ 'limit' => true, 'step' => 1, 'showIf' => [ 'key' => 'operation', 'operator' => 'in', 'value' => static::LISTS ] ],
	    ];
	$fields[] = [
	    'key' => 'timeout',
	    'type' => 'number',
	    'label' => 'Timeout (s)',
	    'required' => true,
	    'default' => 120,
	    'help' => 'The PowerShell script\'s, waiting included',
	    'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_TIMEOUT, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_TIMEOUT ] ],
	    'ui' => [ 'step' => 1 ],
	];
	$fields[] = [
	    'key' => 'variable',
	    'type' => 'text',
	    'label' => 'Result variable',
	    'required' => true,
	    'default' => static::$defaultVariable,
	    'help' => 'A list: { items, count, total, truncated }',
	    'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
	    'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	];
	$fields[] = [
	    'key' => 'onError',
	    'type' => 'select',
	    'label' => 'On error',
	    'required' => true,
	    'default' => 'fail',
	    'choices' => self::ON_ERROR,
	    'help' => 'PowerShell\'s errors, or no connection',
	    'ui' => [ 'width' => 6 ],
	];
	return $fields;
    }

    protected static function field($key, $label, array $ops, array $more = []) {
	return array_merge([ 'key' => $key, 'type' => 'text', 'label' => $label, 'ops' => $ops ], $more);
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	if (in_array($op, static::LISTS, true))
	    return [ $name => [ 'type' => 'object', 'info' => '{ items, count, total, truncated }', 'fields' => [
		'items' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => static::itemInfo($op) ] ],
		'count' => [ 'type' => 'number', 'info' => 'the items kept' ],
		'total' => [ 'type' => 'number', 'info' => 'the items read' ],
		'truncated' => [ 'type' => 'boolean', 'info' => 'more than At most' ],
	    ] ] ];
	return [ $name => static::resultInfo($op) ];
    }

    //what an item of list $op is (the variables' help)
    protected static function itemInfo($op) {
	return 'an item';
    }

    //the variable of operation $op (not a list)
    protected static function resultInfo($op) {
	return [ 'type' => 'any', 'info' => 'the result (null on error)' ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'hyperv')))) {
	    $this->errors('config', static::$label . ': choose a Hyper-V credential of this project');
	    $valid = false;
	}
	return $valid;
    }

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	//the settings through settingValue() (as they are when the step runs as a tool: AgentToolTrait)
	$this->settingVars = $this->varsForSettings();
	$operation = (string)$this->cfg('operation', static::$defaultOperation);
	if (!isset(static::OPERATIONS[$operation]))
	    throw new \ErrorException(static::$label . ": unknown operation `{$operation}`");
	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'hyperv');
	if (empty($cred))
	    throw new \ErrorException(static::$label . ': the credential is no longer available');
	$this->win = $cred->client($this->exec->aid, max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 120))));

	$name = (string)$this->cfg('variable', static::$defaultVariable);
	try {
	    $start = microtime(true);
	    [ $result, $summary ] = $this->runOperation($operation, $this->settingText(...), $this->settingOf(...));
	} catch (\RuntimeException $e) {
	    $this->setResult($name, null);
	    $this->exec->log($this->wfid, static::$label . " {$operation}: " . $e->getMessage());
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException(static::$label . ": {$operation}: " . $e->getMessage());
	}
	//setResult() (a tool keeps it)
	$this->setResult($name, $result);
	$this->exec->log($this->wfid, static::$label . " {$operation}: {$summary} (" . (int)round((microtime(true) - $start) * 1000) . ' ms)');
	return [ 0 ];
    }

    //the PowerShell helpers every script has: r22find <name or ID> (the VM; an error when none, or several of that name), r22vm <VM> (its fields as JSON),
    //r22done <VM> <action> <already> <forced> (an action's result, the VM read again)
    const PRELUDE = <<<'PS'
function r22find($n) {
  $n = "$n".Trim()
  if ($n -eq '') { throw 'VM: empty' }
  if ($n -match '^[0-9a-fA-F]{8}-([0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}$') { $f = @(Get-VM | Where-Object { "$($_.Id)" -eq $n }) }
  else { $f = @(Get-VM | Where-Object { $_.Name -eq $n }) }
  if ($f.Count -eq 0) { throw "VM: none named '$n'" }
  if ($f.Count -gt 1) { throw "VM: several are named '$n': give its ID" }
  $f[0]
}
function r22vm($v) {
  [ordered]@{
    name = $v.Name; id = "$($v.Id)"; state = "$($v.State)"; status = $v.Status
    cpuUsage = $v.CPUUsage; processorCount = $v.ProcessorCount; memoryAssigned = $v.MemoryAssigned; memoryStartup = $v.MemoryStartup
    dynamicMemory = $v.DynamicMemoryEnabled; uptimeSeconds = [int64]$v.Uptime.TotalSeconds; generation = $v.Generation; version = $v.Version
    heartbeat = "$($v.Heartbeat)"; replicationState = "$($v.ReplicationState)"; replicationHealth = "$($v.ReplicationHealth)"
    checkpoint = $v.ParentSnapshotName; path = $v.Path; notes = $v.Notes; host = $v.ComputerName
  }
}
function r22done($v, $action, $already, $forced) {
  $v = Get-VM -Id $v.Id
  [ordered]@{ name = $v.Name; id = "$($v.Id)"; action = $action; state = "$($v.State)"; already = $already; forced = $forced }
}

PS;

    //an operation's script run with the step's values ($P): its value
    protected function ps($operation, $script, array $params = []) {
	return $this->win->ps(static::$name . ':' . $operation, self::PRELUDE . $script, $params);
    }

    //a list's result of items read: [ { items, count, total, truncated }, summary ]
    protected function listResult($all, $what, ?\Closure $map = null) {
	$all = is_array($all) ? array_values($all) : ($all === null ? [] : [ $all ]);
	$max = max(1, min(self::MAX_ITEMS, (int)$this->cfg('maxItems', 1000)));
	$items = array_slice($all, 0, $max);
	if ($map)
	    $items = array_map($map, $items);
	$truncated = count($all) > count($items);
	return [ [ 'items' => $items, 'count' => count($items), 'total' => count($all), 'truncated' => $truncated ],
	    \util\Format::plural(count($items), $what) . ($truncated ? ' of ' . count($all) . ' (truncated)' : '') ];
    }

    //a VM setting: its name or ID (or an item of a list) as text
    protected static function vmRef($value) {
	if (is_array($value))
	    $value = $value['id'] ?? $value['name'] ?? '';
	$v = trim(\util\Expression::toText($value));
	if ($v === '')
	    throw new \RuntimeException('VM: empty');
	return $v;
    }

    //names (one per line or comma separated; or a list) as a list
    protected static function names($value) {
	$list = is_array($value) && array_is_list($value) ? $value : preg_split('/[\r\n,]+/', \util\Expression::toText($value));
	return array_values(array_filter(array_map(fn($x) => trim(\util\Expression::toText($x)), $list), fn($x) => $x !== ''));
    }

    //a whole number setting (empty: $default)
    public static function number($value, $label, $min, $max, $default = null) {
	$v = trim(\util\Expression::toText($value));
	if ($v === '' && $default !== null)
	    return $default;
	if (!preg_match('/^-?\d+$/', $v) || (int)$v < $min || (int)$v > $max)
	    throw new \RuntimeException("{$label}: a whole number from {$min} to {$max}, not `" . mb_substr($v, 0, 32) . '`');
	return (int)$v;
    }
}
