<?php

/*
    a step whose operations an AI step may use as tools (util\AgentTools). Used by the bases of the integration steps (GravityZone, FortiGate,
    Sophos, Veeam, VMware, Proxmox, Hyper-V, Checkmk, Zabbix, Microsoft, Google, Database): the step runs as it does in a workflow (its execute(): the same code, log lines and
    errors), with two differences while it is a tool ($toolCall):
	- the settings are the model's arguments, taken as they are: never {{ }} expressions (settingValue(), varsForSettings())
	- the result is kept for the tool (toolResult), not put in a variable (setResult())
    Each step class says which operations change something (WRITES: the others only read); null (the default): all of them do
*/

namespace models\node;

trait AgentToolTrait {
    //set by util\AgentTools: the step runs as an AI step's tool
    public $toolCall = false;
    //the operation's result, after a tool call
    public $toolResult = null;

    //a setting's value: its {{ }} evaluated against $vars (a step of the workflow); as it is (a tool call)
    protected function settingValue($raw, array $vars) {
	if ($this->toolCall)
	    return $raw;
	return \util\Expression::render((string)$raw, $vars);
    }

    //the variables the settings' {{ }} are evaluated against: none in a tool call (an expression in an argument does not reach the workflow's variables)
    protected function varsForSettings() {
	return $this->toolCall ? [] : $this->exec->expressionVariables();
    }

    //the variables of settingOf() / settingText(), set by the step's execute() (varsForSettings)
    protected $settingVars = [];

    //setting $key of the config as the step runs it (settingValue, with settingVars); passed to runOperation() as $value
    protected function settingOf($key) {
	return $this->settingValue($this->cfg($key, ''), $this->settingVars);
    }

    //setting $key as text, trimmed; passed to runOperation() as $text
    protected function settingText($key) {
	return trim(\util\Expression::toText($this->settingOf($key)));
    }

    //the operation's result: in the variable $name (a step of the workflow), or kept for the tool
    protected function setResult($name, $result) {
	if ($this->toolCall) {
	    $this->toolResult = $result;
	    return;
	}
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $result);
    }

    //whether operation $op changes something (WRITES; none declared: yes)
    public static function writes($op) {
	return !defined(static::class . '::WRITES') || !is_array(static::WRITES) || in_array($op, static::WRITES, true);
    }
}
