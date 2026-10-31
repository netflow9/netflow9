<?php

namespace plugins\MultiTenant\commands;

//CLD: 2026-10-22T23:00:00+03:00: new: the super-admins (the users' `superadmin` flag; never set by a form)
//  ../v MultiTenant::admins::list
//  ../v MultiTenant::admins::grant --email=<email> [--account=<id>]     (--account: when the address has users in several accounts)
//  ../v MultiTenant::admins::revoke --email=<email> [--account=<id>]
//A super-admin needs 2FA to open the administration (set it up in the profile)
class AdminsCommand extends \sys\BaseController {

    public function list($req = null, $resp = null) {
	$n = 0;
	foreach(\V::app()->om->find('User', [ 'superadmin' => true ]) as $u) {
	    $acct = \V::app()->om->findById('Account', (string)$u->aid);
	    echo "{$u->email} ({$u}), account " . ($acct->name ?? '?') . " [{$u->aid}]" . (empty($u->tfa_secret) ? ' - no 2FA yet: cannot open the administration' : '') . ($u->state !== 'active' ? " - {$u->state}" : '') . "\n";
	    $n++;
	}
	if (!$n)
	    echo "no super-admins: ./v MultiTenant::admins::grant --email=<email>\n";
    }

    public function grant($req = null, $resp = null) {
	$u = $this->user($req);
	\V::app()->om->updateOne('User', [ '_id' => $u->_id ], [ '$set' => [ 'superadmin' => true ] ]);
	echo "{$u->email} ({$u}) is a super-admin" . (empty($u->tfa_secret) ? '; set up 2FA in the profile to open the administration' : '') . "\n";
    }

    public function revoke($req = null, $resp = null) {
	$u = $this->user($req);
	\V::app()->om->updateOne('User', [ '_id' => $u->_id ], [ '$unset' => [ 'superadmin' => '' ] ]);
	echo "{$u->email} ({$u}) is no longer a super-admin\n";
    }

    //the user of --email (and --account when the address is in several accounts)
    private function user($req) {
	$email = strtolower(trim((string)($req->params['email'] ?? '')));
	if ($email === '')
	    $this->fail('--email=<email>');
	$q = [ 'email' => $email ];
	if (!empty($req->params['account']))
	    $q['aid'] = \V::app()->om->to_oid((string)$req->params['account']);
	$users = iterator_to_array(\V::app()->om->find('User', $q), false);
	if (!$users)
	    $this->fail("no user {$email}" . (isset($q['aid']) ? ' in that account' : ''));
	if (count($users) > 1) {
	    echo "{$email} is in several accounts: add --account=<id>\n";
	    foreach($users as $u)
		echo "  " . (string)$u->aid . "\n";
	    exit(1);
	}
	return $users[0];
    }

    private function fail($message) {
	echo $message . "\n";
	exit(1);
    }
}
