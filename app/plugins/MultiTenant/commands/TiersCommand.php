<?php

namespace plugins\MultiTenant\commands;

//CLD: 2026-10-24T09:45:36+03:00: new: the accounts' tiers (util\Tiers, app/tiers.php)
//CLD: 2026-10-31T11:53:38+02:00: the Admin plugin's (was commands\TiersCommand, ./v tiers::...; the tiers: plugins/MultiTenant/tiers.php)
//  ../v MultiTenant::tiers::show                                  the tiers: their limits and features
//  ../v MultiTenant::tiers::show --account=<id>                   an account's tier, its limits and features (with its own changes) and what it uses
//  ../v MultiTenant::tiers::set --account=<id> [--tier=<name>]    the account's tier (without --tier: the default); its own changes are kept
class TiersCommand extends \sys\BaseController {

    public function show($req = null, $resp = null) {
	$t = \util\Tiers::table();
	if (empty($req->params['account'])) {
	    foreach($t['tiers'] as $name => $def) {
		echo "{$name} ({$def['label']})" . ($name === $t['default'] ? ', the default' : '') . "\n";
		foreach(\util\Tiers::LIMITS as $key => [ $label, $unit ])
		    echo sprintf("  %-20s %s\n", $key, $key === 'ai_monthly_credits' && ($def['limits'][$key] ?? null) === null ? "the installation's" : \util\Tiers::format($def['limits'][$key] ?? null, $unit));
		echo '  features: ' . (implode(', ', (array)$def['features']) ?: 'none') . "\n";
	    }
	    return;
	}
	$account = $this->account($req);
	$usage = \util\Tiers::usage($account);
	$limits = \util\Tiers::limits($account);
	echo "account {$account->_id}" . ((string)$account->name !== '' ? " ({$account->name})" : '') . ': ' . \util\Tiers::labelOf($account) . ((string)$account->tier === '' ? ' (the default)' : '') . "\n";
	foreach(\util\Tiers::LIMITS as $key => [ $label, $unit ]) {
	    $used = $usage['totals'][$key] ?? ($usage['extra'][$key][0] ?? null);
	    $limit = isset($usage['extra'][$key]) ? $usage['extra'][$key][1] : $limits[$key];
	    echo sprintf("  %-20s %12s of %s\n", $key, $used === null ? '-' : \util\Tiers::format($used, $unit), \util\Tiers::format($limit, $unit));
	}
	foreach(\util\Tiers::features() as $key => $label)
	    echo sprintf("  %-20s %s\n", $key, \util\Tiers::has($account, $key) ? 'on' : 'off');
    }

    public function set($req = null, $resp = null) {
	$account = $this->account($req);
	$tier = (string)($req->params['tier'] ?? '');
	if ($tier !== '' && !isset(\util\Tiers::names()[$tier]))
	    $this->fail("--tier=<name>: one of " . implode(', ', array_keys(\util\Tiers::names())));
	\V::app()->om->updateOne('Account', [ '_id' => $account->_id ], [ '$set' => [ 'tier' => $tier, 'updated_at' => new \MongoDB\BSON\UTCDateTime() ] ]);
	$account->tier = $tier;
	\util\Tiers::reset();
	\V::app()->hooks->applyHook('tiers:changed', [ $account ]);
	echo "account {$account->_id}: " . \util\Tiers::labelOf($account) . ($tier === '' ? ' (the default)' : '') . "\n";
    }

    private function account($req) {
	$id = (string)($req->params['account'] ?? '');
	$acct = preg_match('/^[a-f0-9]{24}$/', $id) ? \V::app()->om->findById('Account', $id) : null;
	if (!$acct)
	    $this->fail('--account=<id>: an account');
	return $acct;
    }

    private function fail($message) {
	echo $message . "\n";
	exit(1);
    }
}
