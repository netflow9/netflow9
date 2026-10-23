<?php

//CLD: 2026-10-23T12:00:00+03:00: new: a user of an account who is an SSO user (signs in at the account's IdP only; util\SamlAuth): the user, the subject (the NameID, or the email
//with a transient NameID format) and its format, the last sign-in. Made on a first SAML sign-in (a user created, or an existing one linked); removed with the user, or by the
//settings page (Unlink: the user signs in with a password of this application again)

namespace plugins\Saml\models;

class SamlLink extends \sys\BaseModel {
    protected static $collection = 'saml_links';
    public static $indexes = [
	[ [ 'aid' => 1, 'user' => 1 ], [ 'unique' => true ] ],
	[ [ 'aid' => 1, 'subject' => 1 ], [] ],
    ];

    public function getSchema() {
	$fields = [
	    [ 'key' => 'user', 'type' => 'object', 'object' => 'User', 'label' => 'User', 'required' => true, 'perm:read' => 'everybody', 'perm:update' => 'nobody' ],
	    [ 'key' => 'subject', 'type' => 'text', 'label' => 'Subject (NameID)', 'required' => true, 'perm:read' => 'everybody', 'perm:update' => 'nobody' ],
	    [ 'key' => 'format', 'type' => 'text', 'label' => 'NameID format', 'perm:read' => 'everybody', 'perm:update' => 'nobody' ],
	    [ 'key' => 'synced_at', 'type' => 'datetime', 'label' => 'Last sign-in', 'perm:read' => 'everybody', 'perm:update' => 'nobody' ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    public static function forUser($user) {
	return \V::app()->om->findOne('SamlLink', [ 'aid' => $user->aid, 'user' => $user->_id ]);
    }

    //a deleted user takes its link with it (hook model:after-delete)
    public static function afterModelDelete($model, $data) {
	if (!($model instanceof \models\User))
	    return;
	foreach(\V::app()->om->find('SamlLink', [ 'aid' => $model->aid, 'user' => $model->_id ]) as $l)
	    $l->delete();
    }
}
