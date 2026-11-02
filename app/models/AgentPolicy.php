<?php

/*
    CLD: 2026-11-02T10:00:00+02:00: new: what the agents (AI steps with tools) of an account may do, one document per account, set by its owners (Account settings > AI: AccountController::settings);
    changes are in the audit log (a saved model). No document: nothing is restricted (the defaults).
	- denied_families: the integration families no agent may use (util\AgentTools::FAMILIES)
	- write_mode: the tools that change something: all, none, or chosen (allowed_writes: the tools by name; @workflows: workflows run as tools; @mcp: tools of MCP servers and tool servers)
	- writes_need_approval: a tool that changes something always waits for a person's approval
	- budget: the most the AI steps with the account's own keys may spend per calendar month (USD; estimated from the tokens: models\AgentUsage), price_input / price_output: the prices (USD per
	  1M tokens) of the models with no known price (util\AiPrices) and no prices on the step
	//CLD: 2026-11-02T14:00:00+02:00: - price_table: the owners' prices per provider and model (rows: provider, '' for any; model, a name or its start; input; output): before the known ones (priceOf), for
	  the budget and the cost a trace shows (models\AgentTrace); the System AI uses credits instead
    Checked on the server: when a step is saved (stepErrors: the message says why) and when it runs (allowsTool: util\AgentGuard::govern, so a policy made after the step was saved holds too).
    A tool that changes something: an integration operation in the step's WRITES, a workflow run as a tool, a project tool or MCP tool not marked read-only (annotation readOnlyHint)
*/

namespace models;

class AgentPolicy extends \sys\BaseModel {
    protected static $collection = 'agent_policy';
    public static $indexes = [
	[ [ 'aid' => 1 ], [ 'unique' => true ] ],
    ];

    const WRITE_MODES = [ 'all' => 'All of them', 'none' => 'None', 'chosen' => 'Only those chosen' ];
    //the entries of allowed_writes that stand for tools with no fixed name
    const WORKFLOWS = '@workflows';
    const MCP = '@mcp';
    //CLD: 2026-11-02T14:00:00+02:00: the price table: its providers (the AI steps' CAPABILITY), its most rows
    const PRICE_PROVIDERS = [ 'anthropic' => 'Claude', 'openai' => 'OpenAI', 'gemini' => 'Gemini', 'lemonade' => 'Lemonade', 'ollama' => 'Ollama' ];
    const MAX_PRICES = 20;

    public function getSchema() {
	return array_merge([
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
	    //CLD: 2026-11-02T14:00:00+02:00: the price table (rows from the view, AccountController::settings: priceRows)
	    [
		'key' => 'price_table',
		'type' => 'array',
		'label' => 'Prices',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => [],
	    ],
	    [
		'key' => 'price_input',
		'type' => 'number',
		'label' => 'Input price',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => '',
		'validate' => [ [ 'inRange', 'lower' => 0, 'skipEmpty' => true, 'message' => 'must be 0 or more' ] ],
	    ],
	    [
		'key' => 'price_output',
		'type' => 'number',
		'label' => 'Output price',
		'help' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'everybody',
		'default' => '',
		'validate' => [ [ 'inRange', 'lower' => 0, 'skipEmpty' => true, 'message' => 'must be 0 or more' ] ],
	    ],
	], $this->getSystemSchema());
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
	    'allowed_writes' => [], 'writes_need_approval' => false, 'budget' => '', 'price_input' => '', 'price_output' => '', 'price_table' => [] ]);
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

    //the prices of the models with no known price: [ input, output, 0 ], null: none set
    public function prices() {
	return (float)$this->price_input > 0 || (float)$this->price_output > 0 ? [ (float)$this->price_input, (float)$this->price_output, 0 ] : null;
    }

    //CLD: 2026-11-02T14:00:00+02:00: new: the table's prices of $model of provider $capability: [ input, output, cache read (10% of input, as util\AiPrices for set prices) ]; the row with the longest matching
    //model name, one of that provider before one of any provider; null: no row
    public function priceOf($capability, $model) {
	$best = null;
	$bestScore = -1;
	foreach((array)$this->price_table as $row) {
	    $name = (string)($row['model'] ?? '');
	    $provider = (string)($row['provider'] ?? '');
	    if ($name === '' || !str_starts_with((string)$model, $name) || ($provider !== '' && $provider !== (string)$capability))
		continue;
	    $score = strlen($name) * 2 + ($provider !== '' ? 1 : 0);
	    if ($score > $bestScore) {
		[ $best, $bestScore ] = [ $row, $score ];
	    }
	}
	return $best === null ? null : [ (float)$best['input'], (float)$best['output'], (float)$best['input'] * 0.1 ];
    }

    //CLD: 2026-11-02T14:00:00+02:00: new: the rows posted, as they are stored: without those with no model, [ provider, model, input, output ], in order; `input` / `output` as posted when they are not
    //numbers of 0 or more (validates() refuses them)
    public static function priceRows($posted) {
	$rows = [];
	foreach((array)$posted as $row) {
	    $model = trim((string)($row['model'] ?? ''));
	    if ($model === '')
		continue;
	    $provider = (string)($row['provider'] ?? '');
	    $number = fn($v) => is_numeric($v) && (float)$v >= 0 ? (float)$v : (string)$v;
	    $rows[] = [ 'provider' => array_key_exists($provider, self::PRICE_PROVIDERS) ? $provider : '', 'model' => mb_substr($model, 0, 100), 'input' => $number($row['input'] ?? 0), 'output' => $number($row['output'] ?? 0) ];
	}
	return $rows;
    }

    //CLD: 2026-11-02T14:00:00+02:00: new: + the price table's rows
    public function validates($options = []) {
	$valid = parent::validates($options);
	$rows = (array)$this->price_table;
	if (count($rows) > self::MAX_PRICES) {
	    $this->errors('price_table', 'Prices: at most ' . self::MAX_PRICES . ' rows');
	    $valid = false;
	}
	foreach($rows as $row) {
	    if (!is_float($row['input'] ?? null) && !is_int($row['input'] ?? null) || !is_float($row['output'] ?? null) && !is_int($row['output'] ?? null)) {
		$this->errors('price_table', 'Prices: `' . ($row['model'] ?? '') . '`: the prices must be numbers of 0 or more');
		$valid = false;
		break;
	    }
	}
	return $valid;
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
