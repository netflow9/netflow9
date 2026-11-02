<?php

/*
    CLD: 2026-11-01T21:50:00+02:00: new class: NetFlow9's own steps and workflows as the tools of an AI step (models\node\NodeAppAi::collectTools), so one AI step can look into and act
    on the integrations.
	Integrations (FAMILIES): a family is the steps of one product (GravityZone, FortiGate, ..., a database, Gmail, Outlook Mail, ...). The AI step's settings name one credential
	    per family (`<family>Credential`) and the operations the model may use (`<family>Tools`: the tools' names; default: the operations that only read, each step's WRITES).
	    Each operation of a step is a tool named <prefix>_<step>_<operation> (gz_endpoints_list, fgt_addresses_block; one step per family: <prefix>_<operation>, gmail_send),
	    its input schema made from the step's settings shown for that operation (configSchema(): types, required, choices as enums, defaults; the credential, operation, Other
	    parameters, timeout, variable and On error are not arguments). Running it runs the step itself in the AI step's execution (receive(): the tier check, its execute(): the
	    same code, log lines and errors), with the arguments as its settings, taken as they are (no {{ }}: models\node\AgentToolTrait); the result is the step's variable value.
	Workflows: those of the project that start with Called by a workflow (setting `workflows`), each a tool named wf_<its name>: the description its trigger's Description for
	    agents, the input schema its inputs (types, required, defaults), the result { ok, state, execution, outputs, error }. It runs nested, as Run a workflow runs it
	    (NodeAppWorkflow::startChild: no loops, at most MAX_DEPTH deep); a workflow that pauses (Wait, Human task) goes on by itself, its outputs are not given to the model.
	Wrong arguments (unknown, missing, of another type, not one of the choices) are tool errors, as is a step that fails; the credentials stay on the server (resolved when a
	tool runs; never in a schema, an argument or a result).
    CLD: 2026-11-01T23:30:00+02:00: approvals - per tool: not offered (never used), run without approval, or ask for approval (`<family>ApprovalMode`: writes - the write operations,
	the default -, all, none, chosen - those of `<family>Approval`; `workflowsApproval`: the workflows that need it, default none). A tool that asks gets an optional argument `reason` (the model's one line for the approver;
	`approval_reason` when the tool has a `reason` of its own) and `approval` (what, on what, its main arguments: the task's title) and `check` (its arguments checked without
	running: a wrong call is not sent for approval). Who approves: `approvalUsers`, `approvalRoles` (as a Human task), `approvalTimeout` / `approvalTimeoutUnit` (none: no time limit).
	The AI step asks and resumes (models\node\NodeAppAi: askApproval, decided)
*/

namespace util;

class AgentTools {
    //the families: key => [ label, prefix (of the tools' names), capability (the credential's: the steps' own when null), base (the registered steps extending it) or steps (these
    //classes), alt: the steps are alternatives - one per type of credential (Database: the credential's type picks the step; the tools are named without the step) ]
    const FAMILIES = [
	'gravityzone' => [ 'label' => 'Bitdefender GravityZone', 'prefix' => 'gz', 'capability' => 'gravityzone', 'base' => \models\node\NodeAppGravityZone::class ],
	'fortigate' => [ 'label' => 'FortiGate', 'prefix' => 'fgt', 'capability' => 'fortigate', 'base' => \models\node\NodeAppFortiGate::class ],
	'sophos' => [ 'label' => 'Sophos Firewall', 'prefix' => 'sfos', 'capability' => 'sophos', 'base' => \models\node\NodeAppSophos::class ],
	'veeam' => [ 'label' => 'Veeam Backup & Replication', 'prefix' => 'veeam', 'capability' => 'veeam', 'base' => \models\node\NodeAppVeeam::class ],
	'vmware' => [ 'label' => 'VMware vCenter', 'prefix' => 'vmware', 'capability' => 'vmware', 'base' => \models\node\NodeAppVmware::class ],
	'proxmox' => [ 'label' => 'Proxmox VE', 'prefix' => 'proxmox', 'capability' => 'proxmox', 'base' => \models\node\NodeAppProxmox::class ],
	'hyperv' => [ 'label' => 'Hyper-V', 'prefix' => 'hyperv', 'capability' => 'hyperv', 'base' => \models\node\NodeAppHyperV::class ],
	'checkmk' => [ 'label' => 'Checkmk', 'prefix' => 'cmk', 'capability' => 'checkmk', 'base' => \models\node\NodeAppCheckmk::class ],
	'zabbix' => [ 'label' => 'Zabbix', 'prefix' => 'zbx', 'capability' => 'zabbix', 'base' => \models\node\NodeAppZabbix::class ],
	'database' => [ 'label' => 'Database', 'prefix' => 'db', 'capability' => null, 'base' => \models\node\NodeAppDatabase::class, 'alt' => true ],
	'outlookMail' => [ 'label' => 'Outlook Mail', 'prefix' => 'outlook_mail', 'capability' => null, 'steps' => [ \models\node\NodeAppOutlookMail::class ] ],
	'outlookCalendar' => [ 'label' => 'Outlook Calendar', 'prefix' => 'outlook_calendar', 'capability' => null, 'steps' => [ \models\node\NodeAppOutlookCalendar::class ] ],
	'onedrive' => [ 'label' => 'OneDrive / SharePoint', 'prefix' => 'onedrive', 'capability' => null, 'steps' => [ \models\node\NodeAppOneDrive::class ] ],
	'excel' => [ 'label' => 'Excel', 'prefix' => 'excel', 'capability' => null, 'steps' => [ \models\node\NodeAppExcel::class ] ],
	'teams' => [ 'label' => 'Microsoft Teams', 'prefix' => 'teams', 'capability' => null, 'steps' => [ \models\node\NodeAppTeams::class ] ],
	'gmail' => [ 'label' => 'Gmail', 'prefix' => 'gmail', 'capability' => null, 'steps' => [ \models\node\NodeAppGmail::class ] ],
	'googleDrive' => [ 'label' => 'Google Drive', 'prefix' => 'gdrive', 'capability' => null, 'steps' => [ \models\node\NodeAppGoogleDrive::class ] ],
	'googleSheets' => [ 'label' => 'Google Sheets', 'prefix' => 'gsheets', 'capability' => null, 'steps' => [ \models\node\NodeAppGoogleSheets::class ] ],
	'googleCalendar' => [ 'label' => 'Google Calendar', 'prefix' => 'gcalendar', 'capability' => null, 'steps' => [ \models\node\NodeAppGoogleCalendar::class ] ],
	'googleDocs' => [ 'label' => 'Google Docs', 'prefix' => 'gdocs', 'capability' => null, 'steps' => [ \models\node\NodeAppGoogleDocs::class ] ],
    ];

    //the AI step's settings of the families and of the workflows
    const SECTION = 'Integrations';
    const WORKFLOWS = 'workflows';
    //the settings of a step that are never arguments (the AI step's credential, the step's own handling)
    const NOT_ARGUMENTS = [ 'credential', 'operation', 'params', 'timeout', 'variable', 'onError' ];
    //the field types that cannot be arguments
    const NOT_ARGUMENT_TYPES = [ 'formdesigner', 'emaildesigner', 'password' ];
    //a step's limit (`limit` fields: At most, ...) larger than this has this default as a tool (the result is cut at NodeAppAi::MAX_TOOL_RESULT characters anyway)
    const LIMIT_DEFAULT = 100;
    //CLD: 2026-11-01T23:30:00+02:00: the AI step's settings of the approvals (section Approvals), the argument the model gives its reason in
    const APPROVALS = 'Approvals';
    const WORKFLOWS_APPROVAL = 'workflowsApproval';
    //CLD: 2026-11-02T11:00:00+02:00: the project's tools, MCP servers' and tool servers' tools: those that change something ask (default), or none do
    const TOOLS_APPROVAL = 'toolsApprovalMode';
    const TOOLS_APPROVAL_MODES = [ 'writes' => 'Those that change something', 'none' => 'None: run without' ];
    const REASON = 'reason';
    const REASON_DESCRIPTION = 'Why you do this, in one line: shown to the person who approves it';
    const APPROVAL_MODES = [ 'writes' => 'Write operations', 'all' => 'Every operation', 'none' => 'None: run without', 'chosen' => 'Those chosen' ];
    //the input types of Called by a workflow as JSON schema types (null: any)
    const INPUT_TYPES = [ 'text' => 'string', 'number' => 'number', 'boolean' => 'boolean', 'list' => 'array', 'object' => 'object', 'any' => null ];

    private static $steps = null;

    // ------------------------------------------------------------------------------------
    //the families

    //the steps of family $key: [ class => [ object name, part of the tools' names (the step's name after its first `-`; '' for one step or alternatives) ] ], the registered ones
    public static function steps($key) {
	if (self::$steps === null) {
	    self::$steps = [];
	    $registered = [];
	    foreach(\models\WfNode::nodeClasses() as $objectName) {
		$class = ltrim(\V::app()->om->getObjectClass($objectName), '\\');
		$registered[$class] = $objectName;
	    }
	    foreach(self::FAMILIES as $k => $fam) {
		$list = [];
		foreach($registered as $class => $objectName) {
		    $in = isset($fam['steps']) ? in_array($class, $fam['steps'], true) : is_subclass_of($class, $fam['base']);
		    if ($in && defined("{$class}::OPERATIONS") && $class::OPERATIONS)
			$list[$class] = $objectName;
		}
		$one = count($list) === 1 || !empty($fam['alt']);
		self::$steps[$k] = [];
		foreach($list as $class => $objectName)
		    self::$steps[$k][$class] = [ $objectName, $one ? '' : self::snake(preg_replace('/^[^-]*-/', '', $class::$name)) ];
	    }
	}
	return self::$steps[$key] ?? [];
    }

    //the capability a credential of step $class provides: the family's, else the step's own (Microsoft / Google: $capability; Database: capability())
    public static function capabilityOf($key, $class) {
	$cap = self::FAMILIES[$key]['capability'] ?? null;
	if ($cap !== null)
	    return $cap;
	return method_exists($class, 'capability') ? $class::capability() : (string)($class::$capability ?? '');
    }

    //the capabilities of family $key's credentials
    public static function capabilities($key) {
	return array_values(array_unique(array_map(fn($class) => self::capabilityOf($key, $class), array_keys(self::steps($key)))));
    }

    //the operations of family $key as tools: [ tool name => [ class, op, label (<step>: <operation>), short (without the words the family's steps start with: the settings'
    //choices), writes ] ] (alternatives: those of the first step, the same for all)
    public static function operations($key) {
	$fam = self::FAMILIES[$key];
	$steps = self::steps($key);
	if (!empty($fam['alt']))
	    $steps = array_slice($steps, 0, 1, true);
	//the first words all the steps' labels share (GravityZone Endpoints, GravityZone Incidents: GravityZone)
	$common = null;
	foreach(array_keys($steps) as $class) {
	    $words = explode(' ', (string)$class::$label);
	    $common = $common === null ? $words : array_slice($common, 0, count(array_intersect_assoc($common, array_slice($words, 0, count($common)))));
	}
	$ops = [];
	foreach($steps as $class => [ , $part ]) {
	    $step = empty($fam['alt']) ? (string)$class::$label : $fam['label'];
	    $short = count($steps) > 1 ? trim(implode(' ', array_slice(explode(' ', $step), count((array)$common)))) : '';
	    foreach($class::OPERATIONS as $op => $label)
		$ops[self::toolName($fam['prefix'], $part, $op)] = [ 'class' => $class, 'op' => (string)$op, 'label' => "{$step}: {$label}", 'short' => ($short !== '' ? "{$short}: " : '') . $label,
		    'writes' => $class::writes((string)$op) ];
	}
	return $ops;
    }

    //CLD: 2026-11-01T23:30:00+02:00: new: the tools of family $key that change something (those that ask for approval by default)
    public static function writeTools($key) {
	return array_keys(array_filter(self::operations($key), fn($o) => $o['writes']));
    }

    //the tools of family $key that only read (the default of `<family>Tools`)
    public static function readTools($key) {
	return array_keys(array_filter(self::operations($key), fn($o) => !$o['writes']));
    }

    //the credential choices of family $key in project $project of account $aid: [ id => label ]
    public static function credentialChoices($key, $aid, $project) {
	$choices = [];
	foreach(self::capabilities($key) as $cap)
	    foreach(\models\Credential::choicesFor($cap, $aid, $project) as $c)
		$choices[$c['value']] = $c['label'];
	return $choices;
    }

    //the credential of family $key with id $id, of $wf's project (a workflow or an execution); null when there is none
    public static function credential($key, $wf, $id) {
	foreach(self::capabilities($key) as $cap) {
	    $c = \models\Credential::findFor($wf, (string)$id, $cap);
	    if ($c)
		return $c;
	}
	return null;
    }

    //the step of family $key that uses credential $cred (alternatives: the one of its type; else any): its class, null when none
    protected static function stepFor($key, $cred, $class) {
	if (empty(self::FAMILIES[$key]['alt']))
	    return $class;
	foreach(array_keys(self::steps($key)) as $c) {
	    if ($cred->provides(self::capabilityOf($key, $c)) && $c::available())
		return $c;
	}
	return null;
    }

    public static function toolName($prefix, $part, $op) {
	return substr(implode('_', array_filter([ $prefix, $part, self::snake($op) ], fn($p) => $p !== '')), 0, 64);
    }

    //taskStatus => task_status, gz-endpoints => gz_endpoints
    protected static function snake($s) {
	return strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', (string)$s)), '_'));
    }

    // ------------------------------------------------------------------------------------
    //the AI step's settings

    //the AI step's settings of the families (in the section Integrations; in the designer - $aid given - only the families with a credential in the project): a credential, the
    //operations (the read ones by default)
    public static function familyFields($aid, $project) {
	$fields = [];
	foreach(self::FAMILIES as $key => $fam) {
	    $ops = self::operations($key);
	    if (!$ops)
		continue;
	    $creds = $aid ? self::credentialChoices($key, $aid, $project) : [];
	    if ($aid && !$creds)
		continue;
	    $fields[] = [
		'key' => "{$key}Credential",
		'type' => 'select',
		'label' => $fam['label'],
		'choices' => $creds,
		'default' => '',
		'help' => 'The credential the model uses',
		'ui' => [ 'section' => self::SECTION, 'width' => 4, 'newRow' => true, 'placeholder' => 'Not used' ],
	    ];
	    //CLD: 2026-11-01T23:30:00+02:00: 4 wide (was 8): the approvals next to it
	    $fields[] = [
		'key' => "{$key}Tools",
		'type' => 'select',
		'multiple' => true,
		'label' => 'Operations',
		'choices' => array_map(fn($o) => $o['short'] . ($o['writes'] ? ' (changes)' : ''), $ops),
		'default' => self::readTools($key),
		'help' => 'Those that read are ticked; those that change something are not',
		'ui' => [ 'section' => self::SECTION, 'width' => 4, 'placeholder' => 'None', 'showIf' => [ 'key' => "{$key}Credential", 'operator' => 'notEmpty' ] ],
	    ];
	    //CLD: 2026-11-01T23:30:00+02:00: new: the operations that wait for a person's approval (the others run without; those not offered are never used): the write ones, all,
	    //none, or those chosen
	    $fields[] = [
		'key' => "{$key}ApprovalMode",
		'type' => 'select',
		'label' => 'Ask for approval',
		'required' => true,
		'choices' => self::APPROVAL_MODES,
		'default' => 'writes',
		'help' => 'Of those offered; the others run without',
		'ui' => [ 'section' => self::SECTION, 'width' => 4, 'showIf' => [ 'key' => "{$key}Credential", 'operator' => 'notEmpty' ] ],
	    ];
	    $fields[] = [
		'key' => "{$key}Approval",
		'type' => 'select',
		'multiple' => true,
		'label' => 'Ask for approval: these',
		'choices' => array_map(fn($o) => $o['short'], $ops),
		'default' => [],
		'ui' => [ 'section' => self::SECTION, 'width' => 8, 'placeholder' => 'None', 'newRow' => true,
		    //both: a credential, Those chosen (a condition holds one key: an expression)
		    'showIf' => "!!data.{$key}Credential && data.{$key}ApprovalMode === 'chosen'" ],
	    ];
	}
	return $fields;
    }

    //the Workflows setting: the workflows of the project that start with Called by a workflow (designer)
    public static function workflowsField() {
	return [
	    'key' => self::WORKFLOWS,
	    'type' => 'select',
	    'multiple' => true,
	    'choices' => array_map(fn($w) => $w['name'], \models\node\NodeAppWorkflow::callable()),
	    'label' => 'Workflows',
	    'default' => [],
	    'help' => 'Workflows that start with Called by a workflow, run as tools',
	    'ui' => [ 'placeholder' => 'None', 'width' => 6 ],
	];
    }

    //CLD: 2026-11-01T23:30:00+02:00: new: the AI step's settings of the approvals (section Approvals): the workflows that ask, who approves (as a Human task), the time limit
    public static function approvalFields() {
	return [
	    //CLD: 2026-11-02T11:00:00+02:00: new: Use these tools, MCP servers, Tool servers: they have no choice per operation (util\AgentGuard::govern)
	    [
		'key' => self::TOOLS_APPROVAL,
		'type' => 'select',
		'label' => 'Project and MCP tools: ask for approval',
		'required' => true,
		'choices' => self::TOOLS_APPROVAL_MODES,
		'default' => 'writes',
		'help' => 'Those not marked read-only change something',
		'ui' => [ 'section' => self::APPROVALS, 'width' => 12 ],
	    ],
	    [
		'key' => self::WORKFLOWS_APPROVAL,
		'type' => 'select',
		'multiple' => true,
		'choices' => array_map(fn($w) => $w['name'], \models\node\NodeAppWorkflow::callable()),
		'label' => 'Workflows that ask for approval',
		'default' => [],
		'help' => 'Of Workflows; the others run without',
		'ui' => [ 'section' => self::APPROVALS, 'placeholder' => 'None', 'width' => 12 ],
	    ],
	    [
		'key' => 'approvalUsers',
		'type' => 'select',
		'multiple' => true,
		'choices' => \models\node\NodeAppTask::memberChoices(),
		'label' => 'Approvers: users',
		'default' => [],
		'ui' => [ 'section' => self::APPROVALS, 'placeholder' => 'None', 'width' => 4, 'newRow' => true ],
	    ],
	    [
		'key' => 'approvalRoles',
		'type' => 'select',
		'multiple' => true,
		'choices' => \models\node\NodeAppTask::roleChoices(),
		'label' => 'Approvers: roles',
		'default' => [],
		'help' => 'No user and no role: any project member',
		'ui' => [ 'section' => self::APPROVALS, 'placeholder' => 'None', 'width' => 4 ],
	    ],
	    [
		'key' => 'approvalTimeoutUnit',
		'type' => 'select',
		'label' => 'Time limit',
		'required' => true,
		'default' => 'none',
		'choices' => \models\node\NodeAppTask::UNITS,
		'help' => 'Then the action is refused',
		'ui' => [ 'section' => self::APPROVALS, 'width' => 2 ],
	    ],
	    [
		'key' => 'approvalTimeout',
		'type' => 'number',
		'label' => 'After',
		'default' => 1,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => 100000, 'skipEmpty' => true, 'message' => 'At least 1' ] ],
		'ui' => [ 'section' => self::APPROVALS, 'width' => 2, 'showIf' => [ 'key' => 'approvalTimeoutUnit', 'operator' => 'ne', 'value' => 'none' ] ],
	    ],
	];
    }

    //the problems of AI step $step's settings of the families and the workflows: [ message ]
    public static function errors($step) {
	$errors = [];
	$wf = $step->workflow;
	foreach(self::FAMILIES as $key => $fam) {
	    $id = (string)$step->cfg("{$key}Credential", '');
	    if ($id !== '' && (!$wf || !self::credential($key, $wf, $id)))
		$errors[] = "{$fam['label']}: choose a credential of this project";
	    $known = self::operations($key);
	    //CLD: 2026-11-01T23:30:00+02:00: + the operations that ask for approval
	    foreach(array_merge((array)$step->cfg("{$key}Tools", []), (array)$step->cfg("{$key}Approval", [])) as $name) {
		if (!isset($known[(string)$name])) {
		    $errors[] = "{$fam['label']}: unknown operation `{$name}`";
		    break;
		}
	    }
	}
	//CLD: 2026-11-01T23:30:00+02:00: the approvers: members of the project, roles of the account; the workflows that ask: among Workflows
	$project = $wf ? \V::app()->om->findOne('Project', [ '_id' => $wf->project, 'aid' => $wf->aid ]) : null;
	foreach((array)$step->cfg('approvalUsers', []) as $uid) {
	    if (!$project || !isset($project->members[(string)$uid])) {
		$errors[] = 'Approvers: users: one of them is not a member of the project';
		break;
	    }
	}
	foreach((array)$step->cfg('approvalRoles', []) as $rid) {
	    if (!$wf || !\util\Validator::rule('oid', (string)$rid) || !\V::app()->om->findOne('Role', [ '_id' => (string)$rid, 'aid' => $wf->aid ])) {
		$errors[] = 'Approvers: roles: one of them is not a role of the account';
		break;
	    }
	}
	if (array_diff(array_map('strval', (array)$step->cfg(self::WORKFLOWS_APPROVAL, [])), array_map('strval', (array)$step->cfg(self::WORKFLOWS, []))))
	    $errors[] = 'Workflows that ask for approval: choose among Workflows';
	foreach((array)$step->cfg(self::WORKFLOWS, []) as $id) {
	    if (!$wf)
		break;
	    $called = \models\node\NodeAppWorkflow::findWorkflow((string)$id, $wf->aid, $wf->project);
	    if (!$called || \models\node\NodeTriggerCall::contractOf(($called->liveCopy() ?: $called)->nodes) === null)
		$errors[] = 'Workflows: choose workflows of this project that start with Called by a workflow';
	    elseif ((string)$called->_id === (string)$wf->_id)
		$errors[] = "Workflows: a workflow cannot run itself ({$called->name})";
	}
	return $errors;
    }

    // ------------------------------------------------------------------------------------
    //the tools

    //the tools of AI step $ai (running: its exec) - the operations of its families, its workflows: [ name => [ name, description, schema (object), run: fn(args) => [ text, isError,
    //images ] ] ]; \ErrorException when a credential or a workflow is no longer there
    public static function tools($ai) {
	$tools = [];
	$exec = $ai->exec;
	foreach(self::FAMILIES as $key => $fam) {
	    $id = (string)$ai->cfg("{$key}Credential", '');
	    if ($id === '')
		continue;
	    $cred = self::credential($key, $exec, $id);
	    if (!$cred)
		throw new \ErrorException("the {$fam['label']} credential is no longer available");
	    $wanted = array_map('strval', (array)($ai->cfg("{$key}Tools", null) ?? self::readTools($key)));
	    foreach(self::operations($key) as $name => $o) {
		if (!in_array($name, $wanted, true))
		    continue;
		$class = self::stepFor($key, $cred, $o['class']);
		if ($class === null)
		    throw new \ErrorException("{$fam['label']}: no step for the credential `{$cred->name}` (its type is not available here)");
		[ $schema, $fields ] = self::inputSchema($class, $o['op']);
		$isList = defined("{$class}::LISTS") && in_array($o['op'], (array)$class::LISTS, true);
		//CLD: 2026-11-01T23:30:00+02:00: + approval (asks: those of `<family>Approval`, default the write ones), the reason argument, check
		$ask = match((string)$ai->cfg("{$key}ApprovalMode", 'writes')) { 'all' => true, 'none' => false, 'chosen' => in_array($name, array_map('strval', (array)$ai->cfg("{$key}Approval", [])), true),
		    default => $o['writes'] };
		[ $schema, $reason ] = $ask ? self::withReason($schema) : [ $schema, null ];
		//CLD: 2026-11-02T10:00:00+02:00: + kind, family, writes, askMeta (util\AgentGuard: the account's policy, the limits, approval when the policy or a lockdown asks for it)
		$meta = [ 'what' => trim(preg_replace('/\s*\([^)]*\)\s*$/', '', (string)$class::OPERATIONS[$o['op']])), 'on' => "{$fam['label']} {$cred->name}",
		    'label' => $o['label'], 'main' => array_keys(array_filter($fields, fn($f) => $f['required'])) ];
		$tools[$name] = [ 'name' => $name, 'kind' => 'integration', 'family' => $key, 'writes' => (bool)$o['writes'], 'askMeta' => $meta,
		    'description' => "[{$fam['label']}] {$o['label']}" . ($o['writes'] ? ' - changes it' : ' - reads only')
			. ($isList ? '. Result: { items, count, total, truncated } (truncated: more than maxItems)' : '') . ($ask ? '. A person approves it first' : '') . '.',
		    'schema' => json_decode(JSON::encode($schema)),
		    'approval' => $ask ? $meta + [ 'reason' => $reason ] : null,
		    'check' => fn(array $args) => self::arguments($fields, self::withoutReason($args, $reason))[1],
		    'run' => fn(array $args) => self::runStep($ai, self::steps($key)[$class][0], $class, $o['op'], $cred, $fields, self::withoutReason($args, $reason)) ];
	    }
	}
	$taken = [];
	foreach((array)$ai->cfg(self::WORKFLOWS, []) as $id) {
	    $wf = \models\node\NodeAppWorkflow::findWorkflow((string)$id, $exec->aid, $exec->project);
	    $contract = $wf ? \models\node\NodeTriggerCall::contractOf(($wf->liveCopy() ?: $wf)->nodes) : null;
	    if ($contract === null)
		throw new \ErrorException('a workflow of Workflows is no longer in the project, or no longer starts with Called by a workflow');
	    $name = self::workflowToolName((string)$wf->name, $taken, $tools);
	    [ $schema, $inputs ] = self::workflowSchema($contract);
	    $outputs = $contract['outputs'] ? implode(', ', $contract['outputs']) : 'none';
	    //CLD: 2026-11-01T23:30:00+02:00: + approval (asks: those of `workflowsApproval`), the reason argument, check
	    $ask = in_array((string)$wf->_id, array_map('strval', (array)$ai->cfg(self::WORKFLOWS_APPROVAL, [])), true);
	    [ $schema, $reason ] = $ask ? self::withReason($schema) : [ $schema, null ];
	    //CLD: 2026-11-02T10:00:00+02:00: + kind, writes (a workflow may do anything: it counts as a write), askMeta
	    $meta = [ 'what' => 'Run the workflow', 'on' => (string)$wf->name, 'label' => "Workflow {$wf->name}", 'main' => array_keys(array_filter($inputs, fn($i) => $i['required'])) ];
	    $tools[$name] = [ 'name' => $name, 'kind' => 'workflow', 'writes' => true, 'askMeta' => $meta,
		'description' => "[Workflow {$wf->name}] " . ($contract['description'] !== '' ? $contract['description'] : "Runs the workflow {$wf->name}.")
		    . " Result: { ok, state, execution, outputs: { {$outputs} }, error }." . ($ask ? ' A person approves it first.' : ''),
		'schema' => json_decode(JSON::encode($schema)),
		'approval' => $ask ? $meta + [ 'reason' => $reason ] : null,
		'check' => fn(array $args) => self::workflowInputs($inputs, self::withoutReason($args, $reason))[1],
		'run' => fn(array $args) => self::runWorkflow($ai, $wf, $inputs, self::withoutReason($args, $reason)) ];
	}
	return $tools;
    }

    //CLD: 2026-11-01T23:30:00+02:00: new: schema $schema (an array) with an optional argument for the reason (`reason`, else `approval_reason` when the tool has its own): [ schema, its name ]
    protected static function withReason(array $schema) {
	$props = (array)$schema['properties'];
	$name = isset($props[self::REASON]) ? 'approval_' . self::REASON : self::REASON;
	$props[$name] = [ 'type' => 'string', 'description' => self::REASON_DESCRIPTION ];
	$schema['properties'] = (object)$props;
	return [ $schema, $name ];
    }

    //CLD: 2026-11-01T23:30:00+02:00: new: the arguments without the reason (argument $reason; null: none)
    public static function withoutReason(array $args, $reason) {
	if ($reason !== null && !array_is_list($args))
	    unset($args[$reason]);
	return $args;
    }

    //wf_<the workflow's name>, unique among $taken and $tools
    protected static function workflowToolName($wfName, array &$taken, array $tools) {
	$base = substr('wf_' . (self::snake($wfName) ?: 'workflow'), 0, 60);
	$name = $base;
	for($i = 2; isset($taken[$name]) || isset($tools[$name]); $i++)
	    $name = "{$base}_{$i}";
	$taken[$name] = true;
	return $name;
    }

    //the input schema of operation $op of step $class: [ JSON schema, the arguments: [ key => [ type (of the field), required, multiple, choices (keys; null: any), min, max,
    //default (null: none), fields (nested rows: their keys) ] ] ]
    public static function inputSchema($class, $op) {
	$props = [];
	$required = [];
	$fields = [];
	foreach($class::configSchema() as $f) {
	    $key = (string)($f['key'] ?? '');
	    $type = (string)($f['type'] ?? 'text');
	    $ui = (array)($f['ui'] ?? []);
	    if ($key === '' || in_array($key, self::NOT_ARGUMENTS, true) || in_array($type, self::NOT_ARGUMENT_TYPES, true) || !empty($f['encrypt']))
		continue;
	    [ $shown, $byOthers ] = self::shownFor($ui['showIf'] ?? null, $ui['hideIf'] ?? null, $op);
	    if (!$shown)
		continue;
	    $default = $f['default'] ?? null;
	    if (is_string($default) && str_contains($default, '{{'))
		$default = null;
	    //required: for this operation, without a default (the step's own is used)
	    $req = (!empty($f['requiredIf']) ? (($f['requiredIf']['if'] ?? '') === 'operation' && in_array($op, (array)($f['requiredIf']['in'] ?? []), true))
		: (!empty($f['required']) && !$byOthers)) && in_array($default, [ null, '', [] ], true);
	    //ui `agent`: the field's type and help as a tool's argument, when they differ from the form's (a list or an object given by an expression in a workflow)
	    $agent = (array)($ui['agent'] ?? []);
	    if (isset($agent['type']))
		$type = (string)$agent['type'];
	    if (!empty($ui['limit']) && is_numeric($default) && $default > self::LIMIT_DEFAULT)
		$default = self::LIMIT_DEFAULT;
	    $arg = [ 'type' => $type, 'required' => $req, 'multiple' => !empty($f['multiple']), 'choices' => null, 'min' => null, 'max' => null, 'default' => $default, 'fields' => null ];
	    $p = [];
	    $description = trim((string)($f['label'] ?? $key));
	    $help = isset($agent['help']) ? (string)$agent['help'] : self::cleanHelp($f['help'] ?? '');
	    switch($type) {
		case 'list':
		    $p['type'] = 'array';
		    $p['items'] = (object)[];
		    break;
		case 'object':
		    $p['type'] = 'object';
		    break;
		case 'number':
		    $p['type'] = 'number';
		    foreach((array)($f['validate'] ?? []) as $rule) {
			if (is_array($rule) && ($rule[0] ?? null) === 'inRange') {
			    if (isset($rule['lower']))
				$p['minimum'] = $arg['min'] = $rule['lower'];
			    if (isset($rule['upper']))
				$p['maximum'] = $arg['max'] = $rule['upper'];
			}
		    }
		    break;
		case 'checkbox':
		case 'switch':
		    $p['type'] = 'boolean';
		    break;
		case 'select':
		    $choices = self::choices($f['choices'] ?? []);
		    if ($choices && empty($ui['allowCustom']) && empty($ui['choicesBy'])) {
			$arg['choices'] = array_map('strval', array_keys($choices));
			$item = [ 'type' => 'string', 'enum' => $arg['choices'] ];
			$named = array_filter($choices, fn($label, $value) => (string)$label !== (string)$value, ARRAY_FILTER_USE_BOTH);
			if ($named)
			    $help .= ($help !== '' ? '. ' : '') . 'Values: ' . implode(', ', array_map(fn($v, $l) => "{$v} ({$l})", array_keys($named), $named));
		    } else {
			$item = [ 'type' => 'string' ];
		    }
		    $p = !empty($f['multiple']) ? [ 'type' => 'array', 'items' => $item ] : $item;
		    break;
		case 'nested':
		    $sub = [];
		    $arg['fields'] = [];
		    foreach((array)($f['fields'] ?? []) as $nf) {
			$nk = (string)($nf['key'] ?? '');
			if ($nk === '')
			    continue;
			$arg['fields'][] = $nk;
			$nt = (string)($nf['type'] ?? 'text');
			$sub[$nk] = [ 'type' => $nt === 'number' ? 'number' : (in_array($nt, [ 'checkbox', 'switch' ], true) ? 'boolean' : 'string'),
			    'description' => trim((string)($nf['label'] ?? $nk)) ];
		    }
		    $object = [ 'type' => 'object', 'properties' => (object)$sub ];
		    $p = !empty($f['multiple']) ? [ 'type' => 'array', 'items' => $object ] : $object;
		    break;
		case 'array':
		    $p['type'] = 'array';
		    break;
		default:
		    $p['type'] = 'string';
	    }
	    $p['description'] = $description . ($help !== '' ? ': ' . $help : '');
	    if ($default !== null && $default !== '' && $default !== [])
		$p['default'] = $default;
	    $props[$key] = $p;
	    if ($req)
		$required[] = $key;
	    $fields[$key] = $arg;
	}
	$schema = [ 'type' => 'object', 'properties' => (object)$props ];
	if ($required)
	    $schema['required'] = $required;
	return [ $schema, $fields ];
    }

    //whether a field with conditions $showIf / $hideIf is shown for operation $op: [ shown, other settings decide too ]; the conditions on `operation` are checked, the others not
    protected static function shownFor($showIf, $hideIf, $op) {
	$list = fn($c) => is_array($c) ? (array_is_list($c) ? $c : [ $c ]) : [];
	$others = false;
	foreach($list($showIf) as $c) {
	    if (!is_array($c))
		continue;
	    if (($c['key'] ?? '') !== 'operation') {
		$others = true;
		continue;
	    }
	    if (!\util\Conditions::holds($op, (string)($c['operator'] ?? 'eq'), $c['value'] ?? ''))
		return [ false, $others ];
	}
	$hide = $list($hideIf);
	if ($hide && !array_filter($hide, fn($c) => !is_array($c) || ($c['key'] ?? '') !== 'operation')) {
	    $all = true;
	    foreach($hide as $c)
		$all = $all && \util\Conditions::holds($op, (string)($c['operator'] ?? 'eq'), $c['value'] ?? '');
	    if ($all)
		return [ false, $others ];
	}
	return [ true, $others || (bool)$hide ];
    }

    //a field's choices as [ value => label ] (a list: the values are their labels)
    protected static function choices($choices) {
	if (!is_array($choices))
	    return [];
	if (array_is_list($choices) && !array_filter($choices, 'is_array'))
	    return array_combine(array_map('strval', $choices), array_map('strval', $choices));
	$out = [];
	foreach($choices as $k => $v)
	    is_array($v) ? $out[(string)($v['value'] ?? '')] = (string)($v['label'] ?? $v['value'] ?? '') : $out[(string)$k] = (string)$v;
	return $out;
    }

    //a help text for a model: without the {{ }} examples (the arguments are values, not expressions)
    protected static function cleanHelp($help) {
	if (!is_string($help))
	    return '';
	$h = preg_replace('/[;,]?\s*\{\{\s*\.\.\.\s*\}\}\s*for expressions/i', '', $help);
	$h = preg_replace('/[;,(]?\s*(e\.g\.|such as|or|like)?\s*\{\{[^}]*\}\}\)?/i', '', $h);
	return trim(preg_replace('/\s+/', ' ', $h), " ,;:");
    }

    //the arguments $args checked against $fields (inputSchema): [ the settings (the arguments cast, the defaults of those not given), null ] or [ null, what is wrong ]
    public static function arguments(array $fields, $args) {
	if (!is_array($args) || ($args && array_is_list($args)))
	    return [ null, 'the arguments are not a JSON object' ];
	$unknown = array_diff(array_map('strval', array_keys($args)), array_keys($fields));
	if ($unknown)
	    return [ null, 'unknown argument' . (count($unknown) > 1 ? 's' : '') . ' ' . implode(', ', array_map(fn($k) => "`{$k}`", $unknown))
		. ($fields ? ' (the arguments: ' . implode(', ', array_keys($fields)) . ')' : ' (it takes none)') ];
	$values = [];
	foreach($fields as $key => $f) {
	    $given = array_key_exists($key, $args) && $args[$key] !== null && $args[$key] !== '' && $args[$key] !== [];
	    if (!$given) {
		if ($f['required'])
		    return [ null, "`{$key}` is required" ];
		$values[$key] = $f['default'] ?? ($f['multiple'] ? [] : '');
		continue;
	    }
	    $v = $args[$key];
	    switch($f['type']) {
		case 'number':
		    if (!is_numeric($v))
			return [ null, "`{$key}`: a number is expected" ];
		    $v += 0;
		    if (($f['min'] !== null && $v < $f['min']) || ($f['max'] !== null && $v > $f['max']))
			return [ null, "`{$key}`: from {$f['min']} to {$f['max']}" ];
		    break;
		case 'checkbox':
		case 'switch':
		    $b = is_bool($v) ? $v : filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		    if ($b === null)
			return [ null, "`{$key}`: true or false is expected" ];
		    $v = $b;
		    break;
		case 'select':
		    $list = $f['multiple'] ? (is_array($v) ? array_values($v) : [ $v ]) : [ $v ];
		    foreach($list as $item) {
			if (!is_scalar($item) || ($f['choices'] !== null && !in_array((string)$item, $f['choices'], true)))
			    return [ null, "`{$key}`: " . ($f['choices'] !== null ? 'one of ' . implode(', ', $f['choices']) : 'text is expected') ];
		    }
		    $v = $f['multiple'] ? array_map('strval', $list) : (string)$v;
		    break;
		case 'list':
		    if (is_string($v) && is_array($json = json_decode($v, true)))
			$v = $json;
		    if (!is_array($v) || !array_is_list($v))
			return [ null, "`{$key}`: a list is expected" ];
		    break;
		case 'object':
		    if (is_string($v) && is_array($json = json_decode($v, true)))
			$v = $json;
		    if (!is_array($v) || ($v && array_is_list($v)))
			return [ null, "`{$key}`: an object is expected" ];
		    break;
		case 'nested':
		    if (!is_array($v))
			return [ null, "`{$key}`: " . ($f['multiple'] ? 'a list of objects' : 'an object') . ' is expected' ];
		    break;
		default:
		    //text: a number or yes / no as text; a list as it is (the steps take lists, e.g. ids)
		    if (is_bool($v))
			$v = $v ? 'true' : 'false';
		    elseif (is_int($v) || is_float($v))
			$v = (string)$v;
		    elseif (!is_string($v) && !is_array($v))
			return [ null, "`{$key}`: text is expected" ];
	    }
	    $values[$key] = $v;
	}
	return [ $values, null ];
    }

    //runs operation $op of step $class (object $objectName) for AI step $ai with credential $cred: the step made with the arguments as its settings, run in $ai's execution
    //(logged under $ai) => [ the result as JSON, isError, images ]
    protected static function runStep($ai, $objectName, $class, $op, $cred, array $fields, array $args) {
	[ $values, $error ] = self::arguments($fields, $args);
	if ($error !== null)
	    return [ $error, true, [] ];
	$timeout = null;
	foreach($class::configSchema() as $f)
	    if (($f['key'] ?? '') === 'timeout')
		$timeout = $f['default'] ?? null;
	$step = \V::app()->om->create($objectName, [ 'wfid' => (string)$ai->wfid, 'fqname' => $class::$type . '::' . $class::$name, 'title' => (string)($ai->title ?? $class::$label),
	    'config' => $values + [ 'credential' => (string)$cred->_id, 'operation' => $op, 'params' => '', 'variable' => '', 'onError' => 'fail' ] + ($timeout !== null ? [ 'timeout' => $timeout ] : []) ]);
	$step->exec = $ai->exec;
	$step->workflow = $ai->workflow;
	$step->toolCall = true;
	try {
	    $step->receive(null);
	} catch (\Throwable $e) {
	    return [ $e->getMessage(), true, [] ];
	}
	return [ json_encode($step->toolResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), false, [] ];
    }

    //the input schema of a workflow of contract $contract (NodeTriggerCall::contractOf): [ JSON schema, the inputs: [ name => [ type, required, default ] ] ]
    public static function workflowSchema(array $contract) {
	$props = [];
	$required = [];
	$inputs = [];
	foreach($contract['inputs'] as $row) {
	    $name = (string)($row['name'] ?? '');
	    if (!\util\Validator::isIdentifier($name))
		continue;
	    $type = (string)($row['type'] ?? 'text');
	    $p = [];
	    if ((self::INPUT_TYPES[$type] ?? null) !== null)
		$p['type'] = self::INPUT_TYPES[$type];
	    if ($type === 'list')
		$p['items'] = (object)[];
	    $p['description'] = \models\node\NodeTriggerCall::TYPES[$type] ?? 'Any';
	    $default = (string)($row['default'] ?? '');
	    if ($default !== '')
		$p['description'] .= " (default: {$default})";
	    $props[$name] = $p;
	    if (!empty($row['required']))
		$required[] = $name;
	    $inputs[$name] = [ 'type' => $type, 'required' => !empty($row['required']) ];
	}
	$schema = [ 'type' => 'object', 'properties' => (object)$props ];
	if ($required)
	    $schema['required'] = $required;
	return [ $schema, $inputs ];
    }

    //CLD: 2026-11-01T23:30:00+02:00: new (was in runWorkflow): the arguments $args checked against $inputs, cast to their types: [ the inputs given, null ] or [ null, what is wrong ]
    public static function workflowInputs(array $inputs, array $args) {
	$given = [];
	if ($args && array_is_list($args))
	    return [ null, 'the arguments are not a JSON object' ];
	$unknown = array_diff(array_map('strval', array_keys($args)), array_keys($inputs));
	if ($unknown)
	    return [ null, 'unknown input' . (count($unknown) > 1 ? 's' : '') . ' ' . implode(', ', array_map(fn($k) => "`{$k}`", $unknown))
		. ($inputs ? ' (the inputs: ' . implode(', ', array_keys($inputs)) . ')' : ' (it has none)') ];
	foreach($inputs as $name => $in) {
	    $v = $args[$name] ?? null;
	    if ($v === null || $v === '') {
		if ($in['required'])
		    return [ null, "`{$name}` is required" ];
		continue;
	    }
	    [ $v, $error ] = \models\node\NodeTriggerCall::cast($in['type'], $v);
	    if ($error !== null)
		return [ null, "`{$name}`: {$error}" ];
	    $given[$name] = $v;
	}
	return [ $given, null ];
    }

    //runs workflow $wf for AI step $ai with the arguments $args (checked against $inputs, cast to their types): nested, as Run a workflow (NodeAppWorkflow::startChild) =>
    //[ { ok, state, execution, outputs, error } as JSON, isError, images ]
    protected static function runWorkflow($ai, $wf, array $inputs, array $args) {
	//CLD: 2026-11-01T23:30:00+02:00: workflowInputs() (was here: also the check of a call waiting for approval)
	[ $given, $error ] = self::workflowInputs($inputs, $args);
	if ($error !== null)
	    return [ $error, true, [] ];
	$exec = $ai->exec;
	$json = fn($r) => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	//not waited for: a workflow that pauses goes on by itself (its end does not queue the AI step's execution)
	[ $child, $error ] = \models\node\NodeAppWorkflow::startChild($exec, $wf, $given, false, (string)$ai->wfid);
	if ($child === null)
	    return [ $json([ 'ok' => false, 'state' => 'error', 'execution' => null, 'outputs' => null, 'error' => $error ]), true, [] ];
	$child->save();
	$exec->log($ai->wfid, $ai::$label . ": started {$wf->name}: execution {$child->_id}");
	$state = $child->run();
	if ($state === 'sleep')
	    return [ $json([ 'ok' => true, 'state' => 'sleep', 'execution' => (string)$child->_id, 'outputs' => null, 'error' => null,
		'note' => 'it is paused (a Wait or a Human task) and goes on by itself; its outputs are not available here' ]), false, [] ];
	$outputs = \models\node\NodeAppWorkflow::outputsOf($child);
	if ($child->state !== 'end')
	    return [ $json([ 'ok' => false, 'state' => (string)$child->state, 'execution' => (string)$child->_id, 'outputs' => $outputs, 'error' => (string)($child->error ?: $child->state) ]), true, [] ];
	return [ $json([ 'ok' => true, 'state' => 'end', 'execution' => (string)$child->_id, 'outputs' => $outputs, 'error' => null ]), false, [] ];
    }
}
