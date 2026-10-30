<?php

/*
    Human task - the run waits until a person completes the step's form (assigned users / roles of the project, in "My tasks").
    The first visit creates a task (plugins\Forms\models\WfTask: title and instructions with their {{ }} rendered, the form,
    who may complete it, the due time) and sleeps;
    completing it (TaskController) resumes the run:
	the answers go into one variable (default `task`), who gave them into `<variable>_user`, and the run goes on at `done`.
	Optional time limit (Time limit unit not "none"): when it passes first, the task expires and the run goes on at `timeout`
*/

namespace models\node;

class NodeAppTask extends \models\WfNode {
    public static $type = 'app';
    public static $name = 'task';
    public static $label = 'Human task';
    public static $icon = 'bi-person-check';
    public static $color = 'var(--wf-color-app)';
    //CLD: 2026-10-30T15:00:00+02:00: the step picker: in Flow
    public static $category = 'flow';
    public static $inputs = 1;
    public static $outputs = 1;
    //with a time limit: done + timeout
    public static $outPorts = [
	'field' => 'timeoutUnit',
	'values' => [
	    'minutes' => [ 'done', 'timeout' ],
	    'hours' => [ 'done', 'timeout' ],
	    'days' => [ 'done', 'timeout' ]
	]
    ];

    const UNITS = [
	'none' => 'No time limit',
	'minutes' => 'Minutes',
	'hours' => 'Hours',
	'days' => 'Days'
    ];
    const UNIT_SECONDS = [ 'minutes' => 60, 'hours' => 3600, 'days' => 86400 ];

    //the choices of the current project (the designer's user): its members, the account's roles
    protected static function memberChoices() {
	if (\V::app()->config('app.mode') != 'web' || \V::app()->user->isGuest || empty(\V::app()->user->crtProject))
	    return [];
	$account = \V::app()->config('rt.account');
	$project = \V::app()->om->findOne('Project', [ '_id' => \V::app()->user->crtProject, 'aid' => $account->_id ?? null ]);
	$choices = [];
	foreach(array_keys((array)($project->members ?? [])) as $uid) {
	    $u = \V::app()->om->findById('User', (string)$uid);
	    if ($u)
		$choices[(string)$u->_id] = (string)$u;
	}
	asort($choices);
	return $choices;
    }

    protected static function roleChoices() {
	if (\V::app()->config('app.mode') != 'web' || \V::app()->user->isGuest)
	    return [];
	$choices = [];
	foreach(\V::app()->om->find('Role', [ 'aid' => \V::app()->config('rt.account')->_id ?? null ], [ 'sort' => [ 'name' => 1 ] ]) as $r)
	    $choices[(string)$r->_id] = (string)$r->name;
	return $choices;
    }

    public static function configSchema() {
	return [
	    [
		'key' => 'title',
		'type' => 'text',
		'label' => 'Task',
		'required' => true,
		'default' => 'Please complete this task',
		'help' => 'Its name in My tasks; {{ }} for expressions, e.g. Approve {{ form.amount }} EUR',
		'ui' => [ 'expression' => true ],
	    ],
	    [
		'key' => 'instructions',
		'type' => 'textarea',
		'label' => 'Instructions',
		'help' => 'Shown above the form; {{ }} for expressions',
		'ui' => [ 'rows' => 2, 'expression' => true ],
	    ],
	    [
		'key' => 'users',
		'type' => 'select',
		'multiple' => true,
		'choices' => static::memberChoices(),
		'label' => 'Assigned users',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'roles',
		'type' => 'select',
		'multiple' => true,
		'choices' => static::roleChoices(),
		'label' => 'Assigned roles',
		'help' => 'No user and no role: any project member',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'assignTo',
		'type' => 'text',
		'label' => 'Also assigned to',
		'help' => 'Optional: an expression giving a user (id, username or e-mail), e.g. {{ form_user.id }}',
		'ui' => [ 'expression' => true ],
	    ],
	    [
		'key' => 'form',
		'type' => 'array',
		'label' => 'Form',
		'required' => true,
		'default' => [ 'direction' => 'vertical', 'fields' => [] ],
		'ui' => [ 'type' => 'formdesigner' ],
	    ],
	    [
		'key' => 'submitLabel',
		'type' => 'text',
		'label' => 'Button',
		'default' => 'Complete',
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'timeoutUnit',
		'type' => 'select',
		'label' => 'Time limit',
		'required' => true,
		'default' => 'none',
		'choices' => self::UNITS,
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		//CLD: 2026-10-29T10:30:00+02:00: not under "Advanced…" (the designer's section of the technical settings, where a step's timeout goes): the task's deadline, next to its unit
		'key' => 'timeout',
		'type' => 'number',
		'label' => 'After',
		'default' => 1,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => 100000, 'skipEmpty' => true, 'message' => 'At least 1' ] ],
		'ui' => [ 'advanced' => false, 'width' => 4, 'showIf' => [ 'key' => 'timeoutUnit', 'operator' => 'ne', 'value' => 'none' ] ],
	    ],
	    [
		//CLD: 2026-10-24T12:55:48+03:00: shorter help (was: The answers, by key; who gave them: <variable>_user)
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Answers variable',
		'required' => true,
		'default' => 'task',
		'help' => 'The answers by key; who gave them: <variable>_user',
		'validate' => [
		    [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ]
		],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	];
    }

    //the form stored normalized (util\SmartForm), with at least one field
    public function validates($options = []) {
	$valid = parent::validates($options);
	$config = is_array($this->config) ? $this->config : [];
	$config['form'] = \util\SmartForm::normalize($config['form'] ?? []);
	$this->config = $config;
	foreach(\util\SmartForm::errors($config['form']) as $m) {
	    $this->errors('config', "Form: {$m}");
	    $valid = false;
	}
	if (!\util\SmartForm::inputs($config['form'])) {
	    $this->errors('config', 'Form: add at least one field');
	    $valid = false;
	}
	//the users: members of the workflow's project; the roles: of its account
	$wf = $this->workflow;
	$project = $wf ? \V::app()->om->findOne('Project', [ '_id' => $wf->project, 'aid' => $wf->aid ]) : null;
	foreach((array)($config['users'] ?? []) as $uid) {
	    if (!$project || !isset($project->members[(string)$uid])) {
		$this->errors('config', 'Assigned users: one of them is not a member of the project');
		$valid = false;
		break;
	    }
	}
	foreach((array)($config['roles'] ?? []) as $rid) {
	    if (!$wf || !\util\Validator::rule('oid', (string)$rid) || !\V::app()->om->findOne('Role', [ '_id' => (string)$rid, 'aid' => $wf->aid ])) {
		$this->errors('config', 'Assigned roles: one of them is not a role of the account');
		$valid = false;
		break;
	    }
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$config = is_array($config) ? $config : [];
	$name = (string)($config['variable'] ?? 'task');
	if (!\util\Validator::isIdentifier($name))
	    return [];
	return [
	    $name => \util\SmartForm::type(\util\SmartForm::normalize($config['form'] ?? []), 'the answers of the task'),
	    $name . '_user' => [ 'type' => 'object', 'info' => 'who completed the task', 'fields' => [ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'email' => [ 'type' => 'string' ] ] ],
	];
    }

    public function execute($edgeId = null) {
	$task = !empty($this->task) ? \V::app()->om->findById('WfTask', (string)$this->task) : null;
	if ($task === null)
	    return $this->open();

	if ($task->state === 'done') {
	    $name = (string)$this->cfg('variable', 'task');
	    $user = $task->completed_by ? \V::app()->om->findById('User', (string)$task->completed_by) : null;
	    $this->exec->setVariable($name, is_array($task->answers) ? $task->answers : []);
	    $this->exec->setVariable($name . '_user', $user ? [ 'id' => (string)$user->_id, 'name' => (string)$user, 'email' => (string)$user->email ] : null);
	    $this->exec->log($this->wfid, 'task done by ' . ($user ? (string)$user : 'someone'));
	    $this->task = null;
	    $this->resume_at = null;
	    return [ 0 ];
	}
	//the time limit passed first: the task expires (if nobody completed it meanwhile)
	if ($task->state === 'open' && $task->due_at instanceof \MongoDB\BSON\UTCDateTime && (int)(string)$task->due_at <= (int)(microtime(true) * 1000)) {
	    if (\V::app()->om->updateMany('WfTask', [ '_id' => $task->_id, 'state' => 'open' ], [ '$set' => [ 'state' => 'expired' ] ]) > 0) {
		$this->exec->log($this->wfid, 'task expired');
		$this->task = null;
		$this->resume_at = null;
		return [ 1 ];
	    }
	    return $this->execute($edgeId);
	}
	if ($task->state === 'expired') {
	    $this->task = null;
	    $this->resume_at = null;
	    return [ 1 ];
	}
	return self::SLEEP;
    }

    //the task: created, the run sleeps until it is completed (or its time limit)
    private function open() {
	$vars = $this->exec->expressionVariables();
	$text = fn($key) => trim(\util\Expression::toText(\util\Expression::render((string)$this->cfg($key, ''), $vars)));
	$users = array_values(array_map('strval', (array)$this->cfg('users', [])));
	$also = $text('assignTo');
	if ($also !== '') {
	    $u = \util\Validator::rule('oid', $also) ? \V::app()->om->findOne('User', [ '_id' => $also, 'aid' => $this->exec->aid ]) : null;
	    $u = $u ?: \V::app()->om->findOne('User', [ 'aid' => $this->exec->aid, '$or' => [ [ 'username' => $also ], [ 'email' => $also ] ] ]);
	    if (!$u)
		throw new \ErrorException("Human task: no user `{$also}` (Also assigned to)");
	    $users[] = (string)$u->_id;
	}
	$unit = (string)$this->cfg('timeoutUnit', 'none');
	$due = isset(self::UNIT_SECONDS[$unit]) ? new \MongoDB\BSON\UTCDateTime((time() + max(1, (int)$this->cfg('timeout', 1)) * self::UNIT_SECONDS[$unit]) * 1000) : null;
	$task = \V::app()->om->create('WfTask', [
	    'aid' => $this->exec->aid,
	    'project' => $this->exec->project,
	    'wf' => $this->exec->wf,
	    'execution' => $this->exec->_id,
	    'node' => $this->wfid,
	    'title' => mb_substr($text('title') ?: (string)$this->title, 0, 300),
	    'instructions' => mb_substr($text('instructions'), 0, 10000),
	    'form' => \util\SmartForm::normalize($this->cfg('form', [])),
	    'submitLabel' => (string)$this->cfg('submitLabel', 'Complete'),
	    'users' => array_values(array_unique($users)),
	    'roles' => array_values(array_map('strval', (array)$this->cfg('roles', []))),
	    'state' => 'open',
	    'due_at' => $due,
	]);
	$task->save();
	$this->task = (string)$task->_id;
	$this->resume_at = $due;
	$this->exec->log($this->wfid, 'task created: ' . $task->title);
	return self::SLEEP;
    }
}
