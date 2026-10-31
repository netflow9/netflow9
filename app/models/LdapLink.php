<?php

//CLD: 2026-10-21T10:00:00+03:00: new: a user of an account who is an LDAP user (signs in with the directory's password only; util\LdapAuth): the user, the person's DN and
//directory name (`login`, lower case: a name typed without @domain finds the user), when the user was last refreshed from the directory. Made on a first LDAP sign-in
//(a user created, or an existing one linked); removed with the user, or by the settings page (Unlink: the user signs in with a password of this application again)

namespace models;

class LdapLink extends \sys\BaseModel {
    protected static $collection = 'ldap_links';
    public static $indexes = [
	[ [ 'aid' => 1, 'user' => 1 ], [ 'unique' => true ] ],
	[ [ 'login' => 1 ], [] ],
	[ [ 'aid' => 1, 'dn' => 1 ], [] ],
    ];

    public function getSchema() {
	$fields = [
	    [ 'key' => 'user', 'type' => 'object', 'object' => 'User', 'label' => 'User', 'required' => true, 'perm:read' => 'everybody', 'perm:update' => 'nobody' ],
	    [ 'key' => 'dn', 'type' => 'text', 'label' => 'DN', 'required' => true, 'perm:read' => 'everybody', 'perm:update' => 'nobody' ],
	    [ 'key' => 'login', 'type' => 'text', 'label' => 'Directory name', 'transform' => 'tolower', 'perm:read' => 'everybody', 'perm:update' => 'nobody' ],
	    [ 'key' => 'synced_at', 'type' => 'datetime', 'label' => 'Last sign-in', 'perm:read' => 'everybody', 'perm:update' => 'nobody' ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    public static function forUser($user) {
	return \V::app()->om->findOne('LdapLink', [ 'aid' => $user->aid, 'user' => $user->_id ]);
    }

    //a deleted user takes its link with it (hook model:after-delete)
    public static function afterModelDelete($model, $data) {
	if (!($model instanceof \models\User))
	    return;
	foreach(\V::app()->om->find('LdapLink', [ 'aid' => $model->aid, 'user' => $model->_id ]) as $l)
	    $l->delete();
    }
}
