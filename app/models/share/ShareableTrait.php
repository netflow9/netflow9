<?php

/*
    a resource its home project shares with other projects of the account. The grants are on the resource, field `shares`: [ { project, access, by, at } ].
    The class says what each level gives: const SHARE_PERMS = [ level => [ permission, ... ] ], SHARE_LEVELS = [ level => label ] and SHARE_PERM (who may share it).
    CLD: 2026-11-03T18:00:00+02:00: a share can hold more that the class asks for (shareOptions: the hosts of a credential)
    A share is for a project, not a user: every run has its project and no user, so runs ask shareAllows(); users ask checkAccess(), through sharedCan()
*/

namespace models\share;

trait ShareableTrait {

    //why it cannot be shared now, null when it can; a class may say
    public function shareProblem() {
	return null;
    }

    //CLD: 2026-11-03T18:00:00+02:00: whether the Share dialog asks for hosts (a credential); a class may say
    public function shareUsesHosts() {
	return false;
    }

    //CLD: 2026-11-03T18:00:00+02:00: what the Share dialog's fields ($data) add to a share: [ options, error message or null ]; a class may ask for some
    public function shareOptions(array $data) {
	return [ [], null ];
    }

    //the `shares` field of the schema
    protected static function sharesField() {
	return [
	    'key' => 'shares',
	    'type' => 'array',
	    'label' => 'Shared with',
	    'help' => false,
	    'perm:read' => 'nobody',
	    'perm:update' => 'nobody',
	    'showInForms' => false,
	];
    }

    //query: the resources shared with project $projectId
    public static function sharedWithQuery($projectId) {
	return [ 'shares.project' => \V::app()->om->to_oid((string)$projectId) ];
    }

    //query: the resources project $projectId can use: its own and those shared with it
    public static function visibleInQuery($projectId) {
	$oid = \V::app()->om->to_oid((string)$projectId);
	return [ '$or' => [ [ 'project' => $oid ], [ 'shares.project' => $oid ] ] ];
    }

    public function isHome($projectId) {
	return (string)$this->project === (string)$projectId;
    }

    //the share with project $projectId as an array, null when there is none
    //CLD: 2026-11-03T18:00:00+02:00: all it holds
    public function shareOf($projectId) {
	foreach((array)$this->shares as $share) {
	    if ((string)$share['project'] === (string)$projectId)
		return self::shareArray($share);
	}
	return null;
    }

    private static function shareArray($share) {
	$array = [];
	foreach($share as $key => $value)
	    $array[$key] = $value instanceof \ArrayObject ? $value->getArrayCopy() : $value;
	return $array;
    }

    //whether project $projectId may do $permName on this resource: anything in its home, what its share's level gives elsewhere
    public function shareAllows($projectId, $permName) {
	if ($this->isHome($projectId))
	    return true;
	$share = $this->shareOf($projectId);
	return $share !== null && in_array($permName, static::SHARE_PERMS[$share['access']] ?? [], true);
    }

    //whether user $userId may do $permName through a share: their role in a project it is shared with allows it, and the share's level gives it
    public function sharedCan($userId, $permName) {
	foreach((array)$this->shares as $share) {
	    if (in_array($permName, static::SHARE_PERMS[(string)$share['access']] ?? [], true) && \models\Project::can($share['project'], $this->aid, $userId, $permName))
		return true;
	}
	return false;
    }

    //the project as "<name>" for messages and labels; '' when it no longer exists
    public static function projectName($projectId) {
	$project = \V::app()->om->findOne('Project', [ '_id' => \V::app()->om->to_oid($projectId) ]);
	return $project ? (string)$project->name : '';
    }

    //shares the resource with $project at $access (a new share, or another level); saved directly (only this field), recorded in the log of both projects. The web layer checks the permission
    //CLD: 2026-11-03T18:00:00+02:00: + $options (shareOptions), named in the logs
    public function setShare($project, $access, array $options = []) {
	$list = [];
	$found = false;
	foreach((array)$this->shares as $share) {
	    $same = (string)$share['project'] === (string)$project->_id;
	    $found = $found || $same;
	    $list[] = $same ? array_merge(self::shareArray($share), [ 'access' => $access ], $options) : $share;
	}
	if (!$found)
	    $list[] = [ 'project' => $project->_id, 'access' => $access, 'by' => \V::app()->user->_id ?? null, 'at' => new \MongoDB\BSON\UTCDateTime() ] + $options;
	$this->storeShares($list);
	$detail = !empty($options['any_host']) ? ', any host' : (!empty($options['hosts']) ? ', hosts: ' . implode(', ', $options['hosts']) : '');
	$this->recordShare($found ? 'share.change' : 'share.add', $project, (static::SHARE_LEVELS[$access] ?? $access) . $detail);
    }

    //the share with $project ended
    public function removeShare($project) {
	$this->storeShares(array_values(array_filter((array)$this->shares, fn($s) => (string)$s['project'] !== (string)$project->_id)));
	$this->recordShare('share.remove', $project);
    }

    private function storeShares(array $list) {
	$this->shares = $list;
	\V::app()->om->updateMany($this->getObjectName(), [ '_id' => $this->_id, 'aid' => $this->aid ], [ '$set' => [ 'shares' => $list ] ]);
    }

    private function recordShare($action, $project, $level = null) {
	$what = static::SHARE_WHAT . ' ' . $this->name;
	$home = self::projectName($this->project);
	$suffix = $level !== null ? ": {$level}" : '';
	\models\AuditLog::record($action, $this, "{$what}: project {$project->name}{$suffix}");
	\models\AuditLog::record($action, $project, "{$what} of project {$home}{$suffix}");
    }

    //the workflows of the projects it is shared with (only $projectId, when given) whose draft or published version points to it: [ [ 'project' => id, 'id' => id, 'name' => name ] ]
    public function consumers($projectId = null) {
	$list = [];
	foreach((array)$this->shares as $share) {
	    if ($projectId !== null && (string)$share['project'] !== (string)$projectId)
		continue;
	    foreach(\V::app()->om->find('Workflow', [ 'aid' => $this->aid, 'project' => $share['project'] ], [ 'sort' => [ 'name' => 1 ] ]) as $wf) {
		if (\util\Transfer::references($wf, $this->_id))
		    $list[] = [ 'project' => (string)$share['project'], 'id' => (string)$wf->_id, 'name' => (string)$wf->name ];
	    }
	}
	return $list;
    }
}
