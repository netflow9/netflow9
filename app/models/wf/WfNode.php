<?php

namespace models\wf;

/*
    a sys\NestedModel: held inside a workflow's `nodes` (with the edges, keyed by wfid), never saved by itself;
    its own schema, no system fields
*/

class WfNode extends \sys\NestedModel {
    //static data/structure
    public static $type = ''; //app, core, trigger
    public static $name = ''; // 'manual-trigger'
    public static $label = ''; //eg 'Manual Trigger'
    public static $icon = '';
    public static $color = '';
    public static $inputs = 0;
    public static $outputs = 0;
    public static $defaultWidth = 120;
    public static $defaultHeight = 120;
    public static $minWidth = 120;
    public static $minHeight = 120;
    public static $isNote = false;
    public static $isContainer = false;
    //how the designer's step picker presents the step (every step is available to every user): $audience basic / advanced (advanced: listed when
    //the user shows the advanced steps, found by the search always), $category the picker's group (CATEGORIES; null: from $type - triggers, core: flow, app: data)
    public static $audience = 'basic';
    public static $category = null;

    const ON_ERROR = [ 'fail' => 'Fail the step', 'output' => 'Continue on the `error` output' ];

    const CATEGORIES = [
	'trigger' => 'Triggers',
	'flow' => 'Flow',
	'data' => 'Data',
	'email' => 'Email',
	'web' => 'Web & files',
	'google' => 'Google',
	'microsoft' => 'Microsoft',
	'database' => 'Databases',
	'security' => 'Security',
	'backup' => 'Backup',
	'monitoring' => 'Monitoring',
	'virtualization' => 'Virtualization',
	'domains' => 'Domains & DNS',
	'ai' => 'AI',
	'developer' => 'Developer',
    ];
    //a step started by the daemon's scheduler on its schedule (config of models\wf\WfSchedule); the class implements runScheduled()
    public static $scheduled = false;
    //outputs that follow the step's config (e.g. Switch: one per case): [ 'rows' => <config key of the rows>, 'label' => <row key of the
    //output's label>, 'after' => [ labels of the outputs after the rows ] ]; null = the class's $outputs / $outLabels. The designer applies the same rule (outPortsOf)
    public static $outPorts = null;


    public $exec = null;

    public $workflow = null;

    //a step has no `aid` of its own (the workflow has it): no aid validation in BaseModel::validates
    public function getSchema() {
	$fields = [
	    //wfid - the node workflow id
	    [
		'key' => 'wfid',
		'type' => 'text',
		'label' => 'Node ID',
		'help' => false,
		'required' => true,
	    ],
	    //parentId - the node parent id
	    [
		'key' => 'parentId',
		'type' => 'text',
		'label' => 'Node parent ID',
		'help' => false,
	    ],
	    //title
	    [
		'key' => 'title',
		'type' => 'text',
		'label' => 'Title',
		'help' => false,
		'required' => true,
	    ],
	    //geometry
	    [
		'key' => 'geometry',
		'type' => 'array',
		'label' => 'Geometry',
		'help' => false,
		'required' => false,
	    ],
	    //fqname - fully qualified name: type::name
	    [
		'key' => 'fqname',
		'type' => 'text',
		'label' => 'FQ name',
		'help' => false,
		'required' => true,
	    ],

	    //config - the node configuration: what it does
	    [
		'key' => 'config',
		'type' => 'array',
		'label' => 'Config',
		'help' => false,
		'required' => false,
	    ],

	    //state
	    [
		'key' => 'state',
		'label' => 'State',
		'type' => 'select',
		'default' => 'ready',
		'choices' => [
		    'ready' => 'Ready',
		    'run' => 'Run',
		    'wait' => 'Waiting for inputs',
		    'sleep' => 'Sleeping',
		    'done' => 'Done',
		    'fail' => 'Fail',
		],
	    ],
	    //statistics here
	    //start_at
	    [
		'key' => 'start_at',
		'type' => 'datetime',
		'label' => 'Start at',
	    ],
	    //end_at
	    [
		'key' => 'end_at',
		'type' => 'datetime',
		'label' => 'End at',
	    ],
	];
	return $fields;
    }

    //runtime contract:
    //  receive($edgeId) is called by WfExecution when a token reaches the node; it calls execute(), which returns
    //   - an array of output port numbers to continue on (the node is done),
    //   - WfNode::SLEEP: not finished, keep the token and try again later (e.g. Wait),
    //   - null: the token is taken, nothing continues for now (e.g. Merge waiting for its other inputs)
    //  nodes may run several times (loops through edges); state / runs / start_at / end_at are kept in the node data
    const SLEEP = 'sleep';

    public function receive($edgeId = null) {
	if (empty($this->start_at))
	    $this->start_at = new \MongoDB\BSON\UTCDateTime();
	$this->state = 'run';

	//a step of a feature the account's tier does not have (util\Tiers: its registry `feature`, e.g. LDAP, Google, Microsoft) does not run
	if ($this->exec && !\util\Tiers::allows($this->exec->aid, $this->getObjectName()))
	    throw new \ErrorException(static::$label . ': ' . \util\Tiers::featureMessage($this->exec->aid, \V::app()->om->registry[$this->getObjectName()]['feature']));

	//a test case: only what has no effect outside the run runs (util\AgentEvalContext); an execution of a test that lost its context (loaded again by something) does not go on at all
	if ($this->exec && $this->exec->isTest()) {
	    if (!$this->exec->__test)
		throw new \ErrorException('a test case cannot go on outside its test run');
	    $result = $this->exec->__test->execute($this, $edgeId);
	} else {
	    $result = $this->execute($edgeId);
	}

	if (is_array($result)) {
	    $this->state = 'done';
	    $this->runs = (int)$this->runs + 1;
	    $this->end_at = new \MongoDB\BSON\UTCDateTime();
	} elseif ($result === self::SLEEP) {
	    $this->state = 'sleep';
	} else {
	    $this->state = 'wait';
	}
	return $result;
    }

    //the node's work; default: done, continue on all outputs. Override in node classes
    public function execute($edgeId = null) {
	return $this->outputPorts();
    }

    //all output port numbers: 0 .. outputs-1
    //the step's own outputs (outLabelsFor its config) when they follow the config
    public function outputPorts() {
	$n = static::outputCountFor($this->config);
	return $n > 0 ? range(0, $n - 1) : [];
    }

    //CLD: 2026-10-11T20:24:33+03:00: the output labels of a step of this class with config $config ($outPorts: the rows' labels, then `after`; rows missing = the field's
    //default); null = no labels (outputs numbered)
    //CLD: 2026-10-12T08:26:57+03:00: the number of outputs of a step of this class with config $config (its labels, else $outputs)
    public static function outputCountFor($config) {
	$labels = static::$outPorts ? static::outLabelsFor($config) : null;
	return $labels !== null ? count($labels) : (int)static::$outputs;
    }

    //CLD: 2026-10-12T08:26:57+03:00: or $outPorts = [ 'field' => <config key>, 'values' => [ <value> => [ labels ] ] ]: the outputs depend on a setting (e.g. HTTP Request: on error
    //continue on an `error` output); a value not listed = the class's $outputs / $outLabels
    public static function outLabelsFor($config) {
	$ports = static::$outPorts;
	if (empty($ports))
	    return static::$outLabels ?? null;
	if (isset($ports['field'])) {
	    $value = is_array($config) ? ($config[$ports['field']] ?? null) : null;
	    if ($value === null) {
		foreach(static::configSchema() as $f) {
		    if ($f['key'] == $ports['field'])
			$value = $f['default'] ?? null;
		}
	    }
	    return $ports['values'][(string)$value] ?? (static::$outLabels ?? null);
	}
	$rows = is_array($config) ? ($config[$ports['rows']] ?? null) : null;
	if (!is_array($rows)) {
	    foreach(static::configSchema() as $f) {
		if ($f['key'] == $ports['rows'])
		    $rows = $f['default'] ?? [];
	    }
	}
	$labels = [];
	foreach(array_values(is_array($rows) ? $rows : []) as $i => $row)
	    $labels[] = (string)(is_array($row) && ($row[$ports['label']] ?? '') !== '' ? $row[$ports['label']] : $i + 1);
	return array_merge($labels, $ports['after'] ?? []);
    }

    //a field's showIf: shown when the setting `operation` is one of $ops
    protected static function operationIn(array $ops) {
	return [ 'key' => 'operation', 'operator' => 'in', 'value' => $ops ];
    }

    //CLD: 2026-11-07T01:02:35+02:00: message $message as an error of the config (validates()); false, for the validity: $valid = $this->configError(...)
    protected function configError($message) {
	$this->errors('config', $message);
	return false;
    }

    //CLD: 2026-11-07T01:02:35+02:00: whether setting $key is empty (blank text)
    protected function cfgEmpty($key) {
	return trim((string)$this->cfg($key, '')) === '';
    }

    //the node's configuration (from the designer's inspector)
    public function cfg($key, $default = null) {
	$config = $this->config;
	return is_array($config) && array_key_exists($key, $config) ? $config[$key] : $default;
    }

    //CLD: 2026-10-11T22:53:04+03:00: config value `key` with its {{ }} expressions evaluated against the execution's variables (util\Expression::render: a single expression keeps
    //its type); \ErrorException when an expression fails (the step fails)
    public function expr($key, $default = null) {
	//CLD: 2026-10-13T15:24:28+03:00: + the project variables (vars)
	return \util\Expression::render($this->cfg($key, $default), $this->exec->expressionVariables());
    }

    //CLD: 2026-10-11T22:53:04+03:00: the syntax errors of the expression fields (ui `expression`) of form $form with values $values: [ "<label>: <message>" ] (unknown names are
    //only warnings in the designer: a variable may come from somewhere the check cannot see)
    public static function expressionErrors($form, array $values) {
	$errors = [];
	foreach($form->schema as $key => $f) {
	    //CLD: 2026-10-18T10:20:00+03:00: a nested field (Field_nested): the expressions of its object(s), prefixed "<label>: " / "<itemLabel> <n>: "
	    if ($f->type === 'nested') {
		$value = $values[$key] ?? null;
		if (!is_array($value))
		    continue;
		$items = $f->multiple ? (array_is_list($value) ? $value : []) : [ $value ];
		foreach($items as $i => $item) {
		    if (!is_array($item))
			continue;
		    foreach(static::expressionErrors($f->nestedObject(), $item) as $m)
			$errors[] = $f->itemPrefix($f->multiple ? $i : null) . ": {$m}";
		}
		continue;
	    }
	    if (empty($f->ui['expression']) || !is_string($values[$key] ?? null))
		continue;
	    //CLD: 2026-10-13T12:17:47+03:00: in the field's syntax (ui `expression`: true = text, or a syntax name of util\Expression::SYNTAX, e.g. reference)
	    foreach(\util\Expression::check($values[$key], [], is_string($f->ui['expression']) ? $f->ui['expression'] : 'text') as $p) {
		if ($p['severity'] === 'error')
		    $errors[] = (string)$f->label . ": {$p['message']} (column {$p['column']}" . ($p['line'] > 1 ? ", line {$p['line']}" : '') . ')';
	    }
	}
	return $errors;
    }

    public function __toString() {
	return $this->title ?? '';
    }

    //SmartForm fields used by the designer's inspector to edit `config`; override in node classes
    //CLD: 2026-10-09T08:10:30+03:00: model field definitions of `config` (sys\NestedModel: cast by the Field_* classes, checked by validates() through the
    //rules: required, validate => [ requiredIf, inList, inRange, regex, identifier, cron, ... ]); browser-only settings of a field (showIf,
    //hideIf, required of a field shown only in some cases, pattern, placeholder, width, rows, ...) go in its `ui` array; the designer gets
    //them as app.smartform fields (util\SmartForm::modelFields)
    public static function configSchema() {
	return [];
    }

    //CLD: 2026-10-09T08:10:30+03:00: the config form of this step class (defaults bound)
    //CLD: 2026-10-18T13:00:00+03:00: a sys\NestedModel of configSchema()
    public static function configForm() {
	return \sys\NestedModel::forFields(static::configSchema());
    }

    //CLD: 2026-10-09T08:10:30+03:00: the step's own rules, then its `config` through the config form: errors on `config` as "<field label>: <message>"; the
    //config is normalized (cast values; missing fields get their default; keys outside the schema and fields nobody may update are kept)
    public function validates($options = []) {
	$valid = parent::validates($options);
	return $this->validateConfig() && $valid;
    }

    public function validateConfig() {
	if (empty(static::configSchema()))
	    return true;

	$config = is_array($this->config) ? $this->config : [];
	$form = static::configForm();
	//CLD: 2026-10-09T08:15:57+03:00: no permission check (it needs a logged-in user; steps are also checked by the daemon / console): fields nobody may update
	//are never written back below
	$form->assign($config);
	$valid = $form->validates();
	foreach((array)$form->errors() as $key => $messages) {
	    //CLD: 2026-10-18T10:20:00+03:00: the label of a nested path too ("Condition 1: Test"; BaseModel::errorLabel)
	    $label = $form->errorLabel($key);
	    foreach((array)$messages as $message)
		$this->errors('config', "{$label}: {$message}");
	}
	//CLD: 2026-10-11T22:53:04+03:00: expressions ({{ }}) that cannot run are refused on save
	foreach(static::expressionErrors($form, $config) as $message) {
	    $this->errors('config', $message);
	    $valid = false;
	}

	foreach($form->schema as $key => $f) {
	    if ($f->{'perm:update'} !== 'nobody')
		$config[$key] = $form->$key;
	}
	$this->config = $config;
	return $valid;
    }

    //CLD: 2026-10-08T22:57:28+03:00: what a scheduled step ($scheduled = true) does when its schedule matches (a worker of the daemon runs it): $wf the
    //workflow, $node the step's data, $at the scheduled minute (ISO 8601); override in the node classes
    public static function runScheduled($wf, array $node, $at) {
	throw new \Exception(static::class . ' is not a scheduled step');
    }

    //CLD: 2026-10-12T20:33:47+03:00: what Run in the designer does for a trigger step (WorkflowController::start): $wf the saved draft, $node the step's data;
    //returns [ 'executions' => saved executions not run yet (the controller runs them after answering), 'message' => text for the user or null ];
    //null = this trigger cannot be started from Run (the default); override in trigger classes
    public static function startNow($wf, array $node) {
	return null;
    }

    //CLD: 2026-10-11T22:38:36+03:00: the execution variables this step sets (for the steps after it: expression suggestions and checks, Workflow::variablesAt), from its config:
    //[ name => [ type => string|number|boolean|array|object|any, info => what it holds ] ]; override in node classes
    public static function outputVariables($config) {
	return [];
    }

    //CLD: 2026-10-11T22:38:36+03:00: a container's variables for the steps inside it (e.g. Loop: the current item), as outputVariables(); override in container classes
    public static function innerVariables($config) {
	return [];
    }

    //CLD: 2026-10-08T08:10:24+03:00: starting `config` of a step when it is added in the designer (e.g. a generated token); override in node
    //classes; the designer asks for it (WorkflowController::node_init) only for classes that override it (hasInitConfig)
    public static function initConfig() {
	return [];
    }

    //CLD: 2026-10-25T17:40:00+02:00: whether this PHP can run the step (e.g. Database: some PDO driver of its types is loaded); not available, it is not in the designer's palette
    public static function available() {
	return true;
    }

    //utilities
    //CLD: 2026-10-18T11:40:00+03:00: the step classes: [ fqname => object name ] (registry entries with `wfNode`), built once per process; for code that only needs the class of a
    //step (WfExecution builds its steps from it; was getAll(), which also builds every step's config form and its choices: ~30-100 ms, per execution loaded)
    private static $nodeClasses = null;
    public static function nodeClasses() {
	if (self::$nodeClasses === null) {
	    self::$nodeClasses = [];
	    foreach(\V::app()->om->registry as $objectName => $objDef) {
		if (!empty($objDef['wfNode']))
		    self::$nodeClasses[$objDef['class']::$type . '::' . $objDef['class']::$name] = $objectName;
	    }
	}
	return self::$nodeClasses;
    }

    //CLD: 2026-10-18T11:55:00+03:00: getAll() built once per request (web: each request is its own; daemon: reset before each job, resetCache); was built at each call (Workflow
    //checks call it several times, the designer, the agent spec)
    private static $nodeDefs = null;

    //forget the cached step definitions (a long-running process before work for another account / user: the daemon's jobs)
    public static function resetCache() {
	self::$nodeDefs = null;
	self::$choices = [];
    }

    //CLD: 2026-11-04T08:10:00+02:00: the database choices of the config forms, read once per web request (each AI step's form asks the same lists; a workflow with several AI steps
    //builds its form for each step). Not in the console: the daemon's jobs and the check suites change the data they read back
    private static $choices = [];
    public static $rememberChoices = PHP_SAPI !== 'cli';

    public static function rememberedChoices($key, $build) {
	if (!self::$rememberChoices)
	    return $build();
	return self::$choices[$key] ??= $build();
    }

    //a model was saved or deleted (Boot): the lists may have changed
    public static function forgetChoices() {
	self::$choices = [];
    }

    //CLD: 2026-11-04T08:10:00+02:00: the config form of step class $class as app.smartform fields, with the choices of the current project (was `configSchema` in getAll(), built for all
    //the step types: ~1.8 s and 430 queries on a project with many workflows, on each opening of the designer and each save)
    public static function schemaOf($class) {
	return \util\SmartForm::modelFields($class::configForm());
    }

    //we need to build a list of available nodes
    //CLD: 2026-11-04T08:10:00+02:00: what the classes declare: no config form, nothing read from the database (the form of one step: schemaOf)
    public static function getAll() {
	if (self::$nodeDefs !== null)
	    return self::$nodeDefs;

	$nodeDefs = [];
	foreach(\V::app()->om->registry as $objectName => $objDef) {
	    if (empty($objDef['wfNode']))
		continue;

	    $__class = $objDef['class'];

	    $fqNodeName = $__class::$type . '::' . $__class::$name;
	    //CLD: 2026-10-30T15:00:00+02:00: the picker's group (WfNode::$category, else from the type)
	    $category = $__class::$category ?? ([ 'trigger' => 'trigger', 'core' => 'flow' ][$__class::$type] ?? 'data');
	    if (!isset(self::CATEGORIES[$category]))
		$category = 'data';

	    $nodeDefs[$fqNodeName] = [
		'objectName' => $objectName,
		'type' => $__class::$type,
		'name' => $__class::$name,
		'label' => $__class::$label,
		'icon' => $__class::$icon,
		'color' => $__class::$color,
		'inputs' => $__class::$inputs,
		'outputs' => $__class::$outputs,
		'defaultWidth' => $__class::$defaultWidth,
		'defaultHeight' => $__class::$defaultHeight,
		'minWidth' => $__class::$minWidth,
		'minHeight' => $__class::$minHeight,
		'outLabels' => $__class::$outLabels ?? null,
		//outputs that follow the config (WfNode::$outPorts)
		'outPorts' => $__class::$outPorts,
		'isContainer' => $__class::$isContainer ?? false,
		'isNote' => $__class::$isNote ?? false,
		//CLD: 2026-10-30T15:00:00+02:00: the picker's presentation: audience (basic / advanced), group (key, label, position)
		'audience' => $__class::$audience === 'advanced' ? 'advanced' : 'basic',
		'category' => $category,
		'categoryLabel' => self::CATEGORIES[$category],
		'categoryOrder' => array_search($category, array_keys(self::CATEGORIES)),
		'hasInitConfig' => (new \ReflectionMethod($__class, 'initConfig'))->getDeclaringClass()->getName() !== self::class,
		//CLD: 2026-10-12T20:33:47+03:00: the class has its own startNow(): Run in the designer can start the workflow at this trigger
		'canRun' => (new \ReflectionMethod($__class, 'startNow'))->getDeclaringClass()->getName() !== self::class,
		//CLD: 2026-10-19T08:51:16+03:00: scheduled step (WfNode::$scheduled): util\Scheduler queues it when its schedule matches
		'scheduled' => !empty($__class::$scheduled),
	    ];
	}
	//CLD: 2026-10-18T11:55:00+03:00: cached for the request
	return self::$nodeDefs = $nodeDefs;
    }

    //CLD: 2026-10-25T20:00:00+02:00: the step definitions of the designer: getAll(), `unavailable` the steps of a feature $account's tier
    //does not have (not in the palette; those already in a workflow are still shown) and those this PHP cannot run (available(): e.g. Database without a PDO driver of its types);
    //[ 'defs' => ..., 'version' => a hash of them ]: the designer page sends the version only, the designer asks the definitions when it does not have them (WorkflowController::node_defs)
    public static function designerDefs($account) {
	$defs = self::getAll();
	foreach($defs as $fq => $def) {
	    if (!\util\Tiers::allows($account, $def['objectName']))
		$defs[$fq]['unavailable'] = true;
	    $class = \V::app()->om->getObjectClass($def['objectName']);
	    if (!$class::available())
		$defs[$fq]['unavailable'] = true;
	}
	//CLD: 2026-11-04T08:10:00+02:00: + the app's version (the forms are not in $defs: a deploy can change them). Depends on the code and the tier only: a new credential or workflow
	//no longer makes each tab ask the definitions again; the dialog of a step asks its form when it opens (WorkflowController::node_schema)
	return [ 'defs' => $defs, 'version' => md5(\data\Version::NUMBER . \util\JSON::encode($defs)) ];
    }

    //CLD: 2026-11-04T08:10:00+02:00: what the canvas needs from the config forms, for designerDefs' $defs: portDefault (the default of the setting the outputs follow), writeVariable (the setting
    //with the variable the step sets, shown in its box). Built as for nobody signed in: no database choices, they are not used here (the same forms as in the console)
    public static function withCanvasHints($defs) {
	$user = \V::app()->user;
	$isGuest = $user->isGuest;
	$user->isGuest = true;
	try {
	    foreach($defs as $fq => $def) {
		$class = \V::app()->om->getObjectClass($def['objectName']);
		$fields = array_column(self::schemaOf($class), null, 'key');
		$port = $def['outPorts']['field'] ?? $def['outPorts']['rows'] ?? null;
		if ($port !== null)
		    $defs[$fq]['portDefault'] = $fields[$port]['default'] ?? null;
		foreach($fields as $key => $f) {
		    if (($f['variableName'] ?? null) === 'write') {
			$defs[$fq]['writeVariable'] = [ 'key' => $key, 'default' => $f['default'] ?? null ];
			break;
		    }
		}
	    }
	} finally {
	    $user->isGuest = $isGuest;
	}
	return $defs;
    }
}
