<?php

namespace commands;

//CLD: 2026-10-22T00:00:00+03:00: checks the audit log's seal (models\AuditLog::verify; SOC2.md gap 6): changed or forged entries, missing ones, entries without a MAC
//  ../v audit::verify                      every account
//  ../v audit::verify --account=<id>       one account
//exits with 1 when a problem is found
class AuditCommand extends \sys\BaseController {

    public function verify($req = null, $resp = null) {
	$id = $req->params['account'] ?? null;
	$accounts = \V::app()->om->find('Account');
	if ($id) {
	    $acct = preg_match('/^[a-f0-9]{24}$/', (string)$id) ? \V::app()->om->findById('Account', $id) : null;
	    if (!$acct) {
		echo "account not found: {$id}\n";
		exit(1);
	    }
	    $accounts = [ $acct ];
	}

	$problems = 0;
	foreach($accounts as $acct) {
	    $r = \models\AuditLog::verify($acct->_id);
	    echo "account {$acct->_id}" . ((string)$acct->name !== '' ? " ({$acct->name})" : '') . ": {$r['entries']} sealed entries, numbers "
		. ($r['pruned'] + 1) . " to {$r['last']}" . ($r['pruned'] ? " (up to {$r['pruned']} removed by the retention)" : '')
		. ($r['unsealed'] ? ", {$r['unsealed']} from before the seal" : '') . ': ' . (empty($r['problems']) ? 'OK' : count($r['problems']) . ' problem(s)') . "\n";
	    foreach($r['problems'] as $p)
		echo "  - {$p}\n";
	    $problems += count($r['problems']);
	}
	exit($problems ? 1 : 0);
    }
}
