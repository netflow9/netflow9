<?php

/*
    a task of a Human task step (plugins\Forms\models\NodeAppTask):
	its form, who may complete it (users, roles of the project; none listed: any project member),
	the run waiting for it (execution + step), its state (open, done, expired) and, when done, the answers and who gave them.

    Completed in "My tasks" (plugins\Forms\controllers\TaskController); completing resumes the run
*/

namespace models;

class WfTask extends \sys\BaseModel {
    protected static $collection = 'wf_tasks';
    public static $indexes = [
	[ [ 'aid' => 1, 'project' => 1, 'state' => 1 ], [] ],
	[ [ 'execution' => 1 ], [] ],
	[ [ 'wf' => 1 ], [] ],
    ];

    const STATES = [ 'open' => 'Open', 'done' => 'Done', 'expired' => 'Expired' ];

    public function getSchema() {
	$fields = [
	    [
		'key' => 'title',
		'type' => 'text',
		'label' => 'Task',
		'required' => true
	    ],
	    [
		'key' => 'instructions',
		'type' => 'textarea',
		'label' => 'Instructions'
	    ],
	    [
		'key' => 'form',
		'type' => 'array',
		'label' => 'Form'
	    ],
	    [
		'key' => 'submitLabel',
		'type' => 'text',
		'label' => 'Button'
	    ],
	    [
		'key' => 'users',
		'type' => 'array',
		'label' => 'Assigned users'
	    ],
	    [
		'key' => 'roles',
		'type' => 'array',
		'label' => 'Assigned roles'
	    ],
	    [
		'key' => 'state',
		'type' => 'select',
		'label' => 'State',
		'default' => 'open',
		'choices' => self::STATES
	    ],
	    [
		'key' => 'answers',
		'type' => 'array',
		'label' => 'Answers'
	    ],
	    [
		'key' => 'completed_by',
		'type' => 'object',
		'object' => 'User',
		'label' => 'Completed by'
	    ],
	    [
		'key' => 'completed_at',
		'type' => 'datetime',
		'label' => 'Completed at'
	    ],
	    [
		'key' => 'due_at',
		'type' => 'datetime',
		'label' => 'Due'
	    ],
	    [
		'key' => 'wf',
		'type' => 'object',
		'object' => 'Workflow',
		'label' => 'Workflow'
	    ],
	    [
		'key' => 'execution',
		'type' => 'object',
		'object' => 'WfExecution',
		'label' => 'Execution'
	    ],
	    [
		'key' => 'node',
		'type' => 'text',
		'label' => 'Step'
	    ],
	    [
		'key' => 'project',
		'type' => 'object',
		'object' => 'Project',
		'label' => 'Project'
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    //whether user $user may complete it: a member of its project, and listed (by user, or by role: the project role or the account role); nobody listed = any member
    public function isFor($user) {
	if (empty($user) || empty($user->_id))
	    return false;
	$project = \V::app()->om->findOne('Project', [ '_id' => $this->project, 'aid' => $this->aid ]);
	$member = $project ? ($project->members[(string)$user->_id] ?? null) : null;
	if ($member === null)
	    return false;
	$users = array_map('strval', (array)($this->users ?? []));
	$roles = array_map('strval', (array)($this->roles ?? []));
	if (!$users && !$roles)
	    return true;
	return in_array((string)$user->_id, $users, true) || in_array((string)($member['role'] ?? ''), $roles, true) || in_array((string)($user->role ?? ''), $roles, true);
    }

    //the open tasks user $user may complete in project $project (newest first)
    public static function openFor($user, $aid, $project) {
	$tasks = [];
	$query = [ 'aid' => $aid, 'state' => 'open' ];
	if ($project !== null)
	    $query['project'] = $project;
	foreach(\V::app()->om->find('WfTask', $query, [ 'sort' => [ '_id' => -1 ], 'limit' => 500 ]) as $t) {
	    if ($t->isFor($user))
		$tasks[] = $t;
	}
	return $tasks;
    }

    //CLD: 2026-10-17T13:24:18+03:00: hook workflow:before-delete (sys\Boot): a deleted workflow's tasks go away (was plugins\BaseNodes\Plugin::beforeWorkflowDelete)
    public static function beforeWorkflowDelete($wf) {
	foreach(\V::app()->om->find('WfTask', [ 'wf' => $wf->_id ]) as $task)
	    $task->delete();
    }
}
