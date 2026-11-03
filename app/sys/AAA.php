<?php

namespace sys;

//Authentication, Authorization and AccessControl
class AAA {
    public $allPerms = [];
    public $userRole = null;

    public function getUserRole() {
	if (empty($this->userRole))
	    $this->userRole = \V::app()->om->findById('Role', \V::app()->user->role);
	return $this->userRole;
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
	$userRole = $this->getUserRole();

	$enabled = $userRole->permissions[$permName]['enabled'] ?? false;
	$scope = $userRole->permissions[$permName]['scope'] ?? '---';

	if ($enabled && $scope == 'global')
	    return true;

	//CLD: 2026-11-03T12:00:00+02:00: no object, a permission with a project scope: it is asked of the current project (was: the account role alone, whatever the user's role there)
	if (empty($params['object'])) {
	    if (in_array('project', $this->allPerms[$permName]['scopes'] ?? [], true))
		return \models\Project::can(\V::app()->user->crtProject, \V::app()->config('rt.account')->_id ?? null, $userId, $permName);
	    return $enabled;
	}

	//delegate to object
	$object = $params['object'];
	return $object->checkAccess($userId, $permName, $params);
    }
}
