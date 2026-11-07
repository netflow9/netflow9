<?php

/*
    what the agents (AI steps with tools) of an account may do, one document per account, set by its owners (Account settings > AI: AccountController::settings);
    changes are in the audit log (a saved model). No document: nothing is restricted (the defaults).
	- denied_families: the integration families no agent may use (util\AgentTools::FAMILIES)
	- write_mode: the tools that change something: all, none, or chosen (allowed_writes: the tools by name; @workflows: workflows run as tools; @mcp: tools of MCP servers and tool servers)
	- writes_need_approval: a tool that changes something always waits for a person's approval
	- budget: the most the AI steps with the account's own keys may spend per calendar month (USD; estimated from the tokens: models\agent\AgentUsage), at the list prices (data\AiProviders); the System AI uses credits instead
    Checked on the server: when a step is saved (stepErrors: the message says why) and when it runs (allowsTool: util\AgentGuard::govern, so a policy made after the step was saved holds too).
    A tool that changes something: an integration operation in the step's WRITES, a workflow run as a tool, a project tool or MCP tool not marked read-only (annotation readOnlyHint)
*/

namespace models\agent;

class AgentPolicy extends \sys\BaseModel {
    protected static $collection = 'agent_policy';
    public static $indexes = [
	[ [ 'aid' => 1 ], [ 'unique' => true ] ],
    ];

    const WRITE_MODES = [
	'all' => 'All of them',
	'none' => 'None',
	'chosen' => 'Only those chosen'
    ];
    //the entries of allowed_writes that stand for tools with no fixed name
    const WORKFLOWS = '@workflows';
    const MCP = '@mcp';

    public function getSchema() {
	$fields = [
	    [
		'key' => 'denied_families',
		'type' => 'select',
		'multiple' => true,
		'label' => 'Integrations agents may not use',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'choices' => array_map(fn($f) => $f['label'], \util\AgentTools::FAMILIES),
		'default' => [],
	    ],
	    [
		'key' => 'write_mode',
		'type' => 'select',
		'label' => 'Agents may use',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'required' => true,
		'default' => 'all',
		'choices' => self::WRITE_MODES,
	    ],
	    [
		'key' => 'allowed_writes',
		'type' => 'select',
		'multiple' => true,
		'label' => 'Those chosen',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'choices' => self::writeChoices(),
		'default' => [],
	    ],
	    [
		'key' => 'writes_need_approval',
		'type' => 'checkbox',
		'label' => 'A person always approves them first',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => false,
	    ],
	    [
		'key' => 'budget',
		'type' => 'number',
		'label' => 'Monthly AI budget (USD)',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => '',
		'validate' => [ [ 'inRange', 'lower' => 0, 'skipEmpty' => true, 'message' => 'must be 0 or more' ] ],
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    public function __toString() {
	return 'AI policy';
    }

    //the tools allowed_writes may name: [ name => label ], the integrations' write operations, the project's tools that change something, workflows, MCP
    public static function writeChoices() {
	$choices = [ self::WORKFLOWS => 'Workflows run as tools', self::MCP => 'Tools of MCP servers and tool servers' ];
	foreach(\util\MCPTools::all() as $t)
	    if (($t['annotations']['readOnlyHint'] ?? false) !== true)
		$choices[$t['name']] = 'Project: ' . ($t['title'] ?? $t['name']);
	foreach(\util\AgentTools::FAMILIES as $key => $fam)
	    foreach(\util\AgentTools::operations($key) as $name => $o)
		if ($o['writes'])
		    $choices[$name] = $fam['label'] . ': ' . $o['short'];
	return $choices;
    }

    //the policy of account $aid: its saved one, else one with the defaults (not saved)
    public static function ofAccount($aid) {
	$oid = $aid ? \V::app()->om->to_oid((string)$aid) : null;
	return ($oid ? \V::app()->om->findOne('AgentPolicy', [ 'aid' => $oid ]) : null) ?: \V::app()->om->create('AgentPolicy', [ 'aid' => $oid, 'denied_families' => [], 'write_mode' => 'all',
	    'allowed_writes' => [], 'writes_need_approval' => false, 'budget' => '' ]);
    }

    public function familyAllowed($key) {
	return !in_array((string)$key, array_map('strval', (array)$this->denied_families), true);
    }

    //whether write tool $name (of kind integration | project | workflow | mcp) is allowed
    public function writeAllowed($name, $kind) {
	$mode = (string)($this->write_mode ?: 'all');
	if ($mode !== 'chosen')
	    return $mode === 'all';
	$chosen = array_map('strval', (array)$this->allowed_writes);
	return in_array(match($kind) { 'workflow' => self::WORKFLOWS, 'mcp' => self::MCP, default => (string)$name }, $chosen, true);
    }

    //whether an agent may have tool $t (of collectTools: name, kind, family, writes)
    public function allowsTool(array $t) {
	if (($t['kind'] ?? '') === 'integration' && !$this->familyAllowed($t['family'] ?? ''))
	    return false;
	return empty($t['writes']) || $this->writeAllowed($t['name'], $t['kind'] ?? '');
    }

    public function needsApproval() {
	return !empty($this->writes_need_approval) && (string)$this->writes_need_approval !== '0';
    }

    //the monthly budget (USD), null: none
    public function budget() {
	return (float)$this->budget > 0 ? (float)$this->budget : null;
    }

    //where AI step $step goes beyond the policy of its account: [ message ] (saving is refused with them; its tools of MCP servers are only known when it runs)
    public static function stepErrors($step) {
	$wf = $step->workflow;
	if (!$wf || empty($wf->aid))
	    return [];
	$policy = self::ofAccount($wf->aid);
	$why = 'by the account\'s AI policy (Account settings > AI)';
	$errors = [];
	foreach(\util\AgentTools::FAMILIES as $key => $fam) {
	    if ((string)$step->cfg("{$key}Credential", '') === '')
		continue;
	    if (!$policy->familyAllowed($key)) {
		$errors[] = "{$fam['label']}: agents may not use this integration, {$why}";
		continue;
	    }
	    $ops = \util\AgentTools::operations($key);
	    $offered = array_map('strval', (array)($step->cfg("{$key}Tools", null) ?? \util\AgentTools::readTools($key)));
	    $writes = array_values(array_filter($offered, fn($name) => !empty($ops[$name]['writes'])));
	    foreach($writes as $name) {
		if (!$policy->writeAllowed($name, 'integration')) {
		    $errors[] = "{$fam['label']}: agents may not use `{$ops[$name]['short']}` (it changes something), {$why}";
		    break;
		}
	    }
	    if ($policy->needsApproval() && $writes) {
		$mode = (string)$step->cfg("{$key}ApprovalMode", 'writes');
		$chosen = array_map('strval', (array)$step->cfg("{$key}Approval", []));
		if ($mode === 'none' || ($mode === 'chosen' && array_diff($writes, $chosen)))
		    $errors[] = "{$fam['label']}: Ask for approval must cover the operations that change something: they always need approval, {$why}";
	    }
	}
	foreach((array)$step->cfg('tools', []) as $name) {
	    $t = \util\MCPTools::all()[(string)$name] ?? null;
	    if ($t && ($t['annotations']['readOnlyHint'] ?? false) !== true && !$policy->writeAllowed((string)$name, 'project'))
		$errors[] = "Use these tools: agents may not use `{$name}` (it changes something), {$why}";
	}
	//CLD: 2026-11-02T11:00:00+02:00: the setting of the project's and MCP tools
	if ($policy->needsApproval() && (string)$step->cfg(\util\AgentTools::TOOLS_APPROVAL, 'writes') === 'none')
	    $errors[] = "Project and MCP tools: ask for approval: those that change something always need approval, {$why}";
	$workflows = array_map('strval', (array)$step->cfg(\util\AgentTools::WORKFLOWS, []));
	if ($workflows) {
	    if (!$policy->writeAllowed('', 'workflow'))
		$errors[] = "Workflows: agents may not run workflows as tools, {$why}";
	    elseif ($policy->needsApproval() && array_diff($workflows, array_map('strval', (array)$step->cfg(\util\AgentTools::WORKFLOWS_APPROVAL, []))))
		$errors[] = "Workflows that ask for approval: all of Workflows must ask: they always need approval, {$why}";
	}
	return $errors;
    }
}
