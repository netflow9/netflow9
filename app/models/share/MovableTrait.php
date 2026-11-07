<?php

/*
    a resource that can move to another project of the account (the Move dialog, controllers\MoveController). Keeps its id, its rows or secrets and its shares;
    a share with the new project ends, as it is at home there. The workflows of its old project keep pointing to it but no longer find it (lookups are by project), so the dialog names them.
    CLD: 2026-11-04T15:00:00+02:00: + workflows: what is linked to it moves too (moveRelated)
    The class says who may move it: const MOVE_PERMS = [ 'from' => permission in its project, 'to' => permission in the new one ], and the audit action: const MOVE_ACTION.
    Needs models\share\ShareableTrait
*/

namespace models\share;

trait MovableTrait {

    //why it cannot move, null when it can; a class may say
    public function moveProblem() {
	return null;
    }

    //what the Move dialog warns of; a class may say
    public function moveWarnings() {
	return [];
    }

    //the workflows of its project whose draft or published version points to it: the names
    public function moveBreaks() {
	$names = [];
	foreach(\V::app()->om->find('Workflow', [ 'aid' => $this->aid, 'project' => $this->project ], [ 'sort' => [ 'name' => 1 ] ]) as $wf) {
	    if ((string)$wf->_id !== (string)$this->_id && \util\Transfer::references($wf, $this->_id))
		$names[] = (string)$wf->name;
	}
	return $names;
    }

    //the projects it can move to, for user $userId: of the account, not its own, not personal (a personal project takes part in no sharing and is one user's), where the user may create it
    public function moveTargets($userId) {
	$targets = [];
	foreach(\V::app()->om->find('Project', [ 'aid' => $this->aid ], [ 'sort' => [ 'name' => 1 ] ]) as $p) {
	    if (!$p->personal && !$this->isHome($p->_id) && $this->moveAllowedIn($p, $userId))
		$targets[(string)$p->_id] = $p;
	}
	return $targets;
    }

    //whether user $userId may have it in $project; a class may ask for more
    protected function moveAllowedIn($project, $userId) {
	return $project->checkAccess($userId, static::MOVE_PERMS['to']);
    }

    //the records that follow it to $project; a class may have some
    protected function moveRelated($project) {
    }

    //why it cannot have its name in $project, null when it can
    public function moveNameProblem($project) {
	$taken = \V::app()->om->count($this->moveFamily(), [ 'aid' => $this->aid, 'project' => $project->_id, 'name' => (string)$this->name, '_id' => [ '$ne' => $this->_id ] ]);
	return $taken > 0 ? 'Project ' . $project->name . ' already has a ' . strtolower(static::SHARE_WHAT) . ' named ' . $this->name . ': rename one of them first' : null;
    }

    //moves it to $project; saved directly (only these fields), recorded in the log of both projects. The web layer checks the permissions and the name
    public function moveTo($project) {
	$from = self::projectName($this->project);
	$shares = array_values(array_filter((array)$this->shares, fn($s) => (string)$s['project'] !== (string)$project->_id));
	$this->moveRelated($project);
	\V::app()->om->updateMany($this->getObjectName(), [ '_id' => $this->_id, 'aid' => $this->aid ], [ '$set' => [ 'project' => $project->_id, 'shares' => $shares ] ]);
	$this->project = $project->_id;
	$this->shares = $shares;

	$what = static::SHARE_WHAT . ' ' . $this->name;
	\models\AuditLog::record(static::MOVE_ACTION, $this, "{$what}: from project {$from} to project {$project->name}");
	\models\AuditLog::record(static::MOVE_ACTION, $project, "{$what}: received from project {$from}");
    }

    //the object name its records are counted under (a class with variants: the family)
    protected function moveFamily() {
	return $this->getObjectName();
    }
}
