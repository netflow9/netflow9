<?php

namespace sys;

//Authentication, Authorization and AccessControl
class AAA {
    public $allPerms = [];
    //the signed-in user's account role, loaded once
    public $userRole = null;
    //CLD: 2026-11-07T12:00:00+02:00: account roles set by user id, for users with no record (the check suites' made-up users); read before the database
    public $userRoles = [];

    //CLD: 2026-11-07T12:00:00+02:00: the account role of $userId (default: the signed-in user); another user's is read from their record each time, not kept
    //(the daemon lives long and a changed role applies at once)
    public function getUserRole($userId = null) {
	if ($userId === null || (string)$userId === (string)(\V::app()->user->_id ?? '')) {
	    if (empty($this->userRole))
		$this->userRole = \V::app()->om->findById('Role', \V::app()->user->role);
	    return $this->userRole;
	}

	if (array_key_exists((string)$userId, $this->userRoles))
	    return $this->userRoles[(string)$userId];
	$user = \V::app()->om->findById('User', (string)$userId);
	return empty($user->role) ? null : \V::app()->om->findOne('Role', [ '_id' => $user->role, 'aid' => $user->aid ]);
    }

    /**
     * Performs access check for the specified user.
     * @param mixed $userId the user ID. This can be either an integer or a string representing
     * @param string $itemName the name of the operation that need access check
     * the unique identifier of a user. See [[User::id]].
     * @param array $params name-value pairs that would be passed to biz rules associated
     * with the tasks and roles assigned to the user. A param with name 'userId' is added to
     * this array, which holds the value of `$userId`.
     * @return boolean whether the operations can be performed by the user.
     */
    public function checkAccess($userId, $permName, $params = []) {
	if (\V::app()->config('app.mode') != 'web')
	    return true;

	if ($permName === true || $permName === false)
	    return $permName;

	if (empty($permName))
	    return false;

	//cache a list of all perms; initialize once
	if (empty($this->allPerms)) {
	    foreach(\data\SysPermissions::get() as $objectName => $permissions) {
		foreach($permissions as $p) {
		    $this->allPerms[$p['name']] = [];
		    if (!empty($p['alias-of']))
			$this->allPerms[$p['name']]['alias-of'] = $p['alias-of'];
		    if (!empty($p['scopes']))
			$this->allPerms[$p['name']]['scopes'] = $p['scopes'];
		}
	    }
	}

	//check whether permission exixts
	if (!array_key_exists($permName, $this->allPerms)) {
	    \V::app()->log->debug('AAA: unknown permName:' . $permName);
	    return false;
	}

	//recurse if alias
	if (!empty($this->allPerms[$permName]['alias-of']))
	    return $this->checkAccess($userId, $this->allPerms[$permName]['alias-of'], $params);

	//now: if there is no object, check in the user's role; else, delegate to object
	//CLD: 2026-11-07T12:00:00+02:00: $userId's account role, not the signed-in user's
	$userRole = $this->getUserRole($userId);
	if (empty($userRole))
	    return false;

	$enabled = $userRole->permissions[$permName]['enabled'] ?? false;
	$scope = $userRole->permissions[$permName]['scope'] ?? '---';

	if ($enabled && $scope == 'global')
	    return true;

	//no object, a permission with a project scope: it is asked of the current project
	if (empty($params['object'])) {
	    if (in_array('project', $this->allPerms[$permName]['scopes'] ?? [], true)) {
		//CLD: 2026-11-07T12:00:00+02:00: Boot sets the current project of every signed-in user: one not found is an error, not a refusal
		$project = \V::app()->om->findOne('Project', [ '_id' => \V::app()->user->crtProject, 'aid' => \V::app()->config('rt.account')->_id ?? null ]);
		if (empty($project))
		    throw new \ErrorException('AAA: ' . $permName . ': the current project ' . \V::app()->user->crtProject . ' is not found');
		return $project->checkAccess($userId, $permName);
	    }
	    return $enabled;
	}

	//delegate to object
	$object = $params['object'];
	return $object->checkAccess($userId, $permName, $params);
    }
}
