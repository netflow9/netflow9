<?php

/*
    CLD: 2026-10-24T09:45:36+03:00: new: the accounts' tiers (app/tiers.php; tiers.local.php, when present, instead): an account's tier (Account `tier`; empty: the default),
    its limits and features, with the account's own changes (Account `tier_overrides`: { limits: { key: n | null (no limit) }, features: { key: bool } }, set by a super-admin).
	limits      for the whole account: LIMITS; check() refuses what goes over one (LimitException, a message for the user)
	features    the tier's (the plugins name theirs: hook tiers:features ($features: \ArrayObject, key => label)); has(), requireFeature()
	usage       what the account uses (usage(): also per project), for the owners' usage page (AccountController::usage) and the administration
    $account: an Account, or its id (read again after 30 s: the daemon runs for days)
    CLD: 2026-10-31T11:53:38+02:00: the tiers are the Admin plugin's (plugins/MultiTenant/tiers.php, hook tiers:table): without it (self-hosting) there are none: no limits, every feature on,
    the accounts' own changes ignored
*/
namespace util;

class Tiers {
    //the limits: key => [ label, unit (count | bytes | credits) ]
    const LIMITS = [
	'datatables' => [ 'Data tables', 'count' ],
	'datatable_records' => [ 'Rows in a data table', 'count' ],
	'files_bytes' => [ 'Files', 'bytes' ],
	'ai_monthly_credits' => [ 'System AI credits per month', 'credits' ],
    ];

    //the message of what goes over a limit, or of a feature not in the tier (fixed for now)
    const CONTACT = 'Contact your administrator to raise the limit.';

    private static $table = null;
    private static $accounts = [];
    private static $features = null;

    // ------------------------------------------------------------------------------------
    //the tiers

    //[ default, tiers ]: the plugins' (hook tiers:table ($table: \ArrayObject): default, tiers; the MultiTenant plugin's plugins/MultiTenant/tiers.php); none: no tiers
    //CLD: 2026-10-31T11:53:38+02:00: from the hook tiers:table (was app/tiers.php, or tiers.local.php)
    public static function table() {
	if (self::$table === null) {
	    $h = new \ArrayObject();
	    \V::app()->hooks->applyHook('tiers:table', [ $h ]);
	    $t = $h->getArrayCopy() + [ 'default' => '', 'tiers' => [] ];
	    foreach($t['tiers'] as $name => $def)
		$t['tiers'][$name] = (array)$def + [ 'label' => ucfirst($name), 'limits' => [], 'features' => [] ];
	    if (!isset($t['tiers'][$t['default']]))
		$t['default'] = (string)array_key_first($t['tiers']);
	    self::$table = $t;
	}
	return self::$table;
    }

    //[ name => label ]
    public static function names() {
	return array_map(fn($def) => (string)$def['label'], self::table()['tiers']);
    }

    //the features the tiers may have: [ key => label ] (hook tiers:features)
    public static function features() {
	if (self::$features === null) {
	    $features = new \ArrayObject();
	    \V::app()->hooks->applyHook('tiers:features', [ $features ]);
	    self::$features = $features->getArrayCopy();
	}
	return self::$features;
    }

    //forget what was read (the check suite; an account changed)
    public static function reset() {
	self::$table = null;
	self::$accounts = [];
	self::$features = null;
    }

    // ------------------------------------------------------------------------------------
    //an account's

    //the Account of $account (an Account or its id); the current one (rt.account) when it is that one; null: none
    public static function account($account) {
	if (is_object($account) && $account instanceof \models\Account)
	    return $account;
	$aid = (string)$account;
	if ($aid === '')
	    return null;
	$current = \V::app()->var('rt.account');
	if ($current && (string)$current->_id === $aid)
	    return $current;
	$c = self::$accounts[$aid] ?? null;
	if ($c === null || time() - $c[1] >= 30) {
	    $om = \V::app()->om;
	    $c = self::$accounts[$aid] = [ preg_match('/^[a-f0-9]{24}$/', $aid) ? $om->acrossAccounts(fn() => $om->findById('Account', $aid)) : null, time() ];
	}
	return $c[0];
    }

    //the tier's name (Account `tier`, else the default)
    public static function tierOf($account) {
	$t = self::table();
	$name = (string)(self::account($account)->tier ?? '');
	return isset($t['tiers'][$name]) ? $name : $t['default'];
    }

    public static function labelOf($account) {
	return self::names()[self::tierOf($account)] ?? self::tierOf($account);
    }

    //CLD: 2026-10-31T11:53:38+02:00: whether there are tiers (the Admin plugin): without them, no limits and every feature
    public static function enabled() {
	return !empty(self::table()['tiers']);
    }

    //the account's own changes: [ limits => [ key => n | null ], features => [ key => bool ] ]
    public static function overridesOf($account) {
	if (!self::enabled())
	    return [ 'limits' => [], 'features' => [] ];
	$o = self::account($account)->tier_overrides ?? null;
	$o = $o ? (json_decode(\util\JSON::encode($o), true) ?: []) : [];
	return [ 'limits' => (array)($o['limits'] ?? []), 'features' => (array)($o['features'] ?? []) ];
    }

    //the account's limits: [ key => n | null (no limit) ], the tier's with the account's own
    public static function limits($account) {
	$tier = self::table()['tiers'][self::tierOf($account)] ?? [ 'limits' => [] ];
	$o = self::overridesOf($account)['limits'];
	$limits = [];
	foreach(array_keys(self::LIMITS) as $key) {
	    $v = array_key_exists($key, $o) ? $o[$key] : ($tier['limits'][$key] ?? null);
	    $limits[$key] = $v === null ? null : max(0, (int)$v);
	}
	return $limits;
    }

    public static function limit($account, $key) {
	return self::limits($account)[$key] ?? null;
    }

    //whether the account has $feature: its own change, else the tier's
    public static function has($account, $feature) {
	if (!self::enabled())
	    return true;
	$o = self::overridesOf($account)['features'];
	if (array_key_exists($feature, $o))
	    return (bool)$o[$feature];
	return in_array($feature, (array)self::table()['tiers'][self::tierOf($account)]['features'], true);
    }

    //the current account has $feature, else 403 with the message
    public static function requireFeature($feature) {
	$account = \V::app()->var('rt.account');
	if (!self::has($account, $feature))
	    \V::app()->error(403, self::featureMessage($account, $feature));
    }

    //whether registry object $objectName may be used by the account (its `feature`, when it has one: e.g. a plugin's credential or step)
    public static function allows($account, $objectName) {
	$feature = \V::app()->om->registry[$objectName]['feature'] ?? null;
	return empty($feature) || self::has($account, $feature);
    }

    public static function featureMessage($account, $feature) {
	return (self::features()[$feature] ?? $feature) . ' is not included in your plan (' . self::labelOf($account) . '). Contact your administrator.';
    }

    // ------------------------------------------------------------------------------------
    //the limits

    //refuses (LimitException) adding $adding to $key: when it goes over the account's limit; $iname: the data table (datatable_records)
    public static function check($account, $key, $adding = 1, $iname = null) {
	$limit = self::limit($account, $key);
	if ($limit === null)
	    return;
	$used = self::used($account, $key, $iname);
	if ($used + max(0, (int)$adding) > $limit)
	    throw new LimitException(self::limitMessage($account, $key), $key, $limit, $used);
    }

    //what the account uses of $key ($iname: the data table of datatable_records)
    public static function used($account, $key, $iname = null) {
	$aid = self::oid($account);
	switch($key) {
	    case 'datatables':
		return \V::app()->om->acrossAccounts(fn() => \V::app()->om->count('DataTableDef', [ 'aid' => $aid ]));
	    case 'datatable_records':
		return $iname === null ? 0 : self::rowsOf($iname, $aid);
	    case 'files_bytes':
		return (int)(self::filesBytes($aid)[''] ?? 0);
	}
	return 0;
    }

    public static function limitMessage($account, $key, $limit = null) {
	$limit = $limit ?? self::limit($account, $key);
	$what = [
	    //CLD: 2026-10-24T16:30:00+03:00: util\Format::plural (was number_format and the plural inline)
	    'datatables' => 'at most ' . Format::plural((int)$limit, 'data table'),
	    'datatable_records' => 'at most ' . Format::plural((int)$limit, 'row') . ' in a data table',
	    'files_bytes' => 'at most ' . self::format($limit, 'bytes') . ' of files',
	][$key] ?? "at most {$limit} ({$key})";
	return 'Your plan (' . self::labelOf($account) . ") allows {$what}. " . self::CONTACT;
    }

    // ------------------------------------------------------------------------------------
    //the usage

    //what the account uses: [ totals => [ key => n ], extra => [ key => [ used, limit ] ], projects => [ project id => [ datatables, records, files_bytes ] ], tables => [ [ id, name, project, rows ] ] ]
    //(totals.datatable_records: the rows of its largest table; projects.*.records: the rows of the project's tables)
    public static function usage($account) {
	$aid = self::oid($account);
	$om = \V::app()->om;
	$tables = [];
	$projects = [];
	$largest = 0;
	foreach($om->acrossAccounts(fn() => iterator_to_array($om->find('DataTableDef', [ 'aid' => $aid ], [ 'modelOptions' => [ 'attachSchema' => false ] ]), false)) as $dt) {
	    $rows = self::rowsOf((string)$dt->iname, $aid);
	    $pid = (string)$dt->project;
	    $tables[] = [ 'id' => (string)$dt->_id, 'name' => (string)$dt->name, 'project' => $pid, 'rows' => $rows ];
	    $projects[$pid] = ($projects[$pid] ?? [ 'datatables' => 0, 'records' => 0, 'files_bytes' => 0 ]);
	    $projects[$pid]['datatables']++;
	    $projects[$pid]['records'] += $rows;
	    $largest = max($largest, $rows);
	}
	$files = self::filesBytes($aid, true);
	foreach($files as $pid => $bytes) {
	    if ($pid === '')
		continue;
	    $projects[$pid] = ($projects[$pid] ?? [ 'datatables' => 0, 'records' => 0, 'files_bytes' => 0 ]);
	    $projects[$pid]['files_bytes'] = $bytes;
	}
	usort($tables, fn($a, $b) => $b['rows'] <=> $a['rows']);
	$totals = [ 'datatables' => count($tables), 'datatable_records' => $largest, 'files_bytes' => (int)($files[''] ?? 0) ];
	//what the plugins count (hook tiers:usage ($aid, $extra: \ArrayObject): key => [ used, limit (null: none) ], of a key of LIMITS; e.g. the System AI's credits)
	$extra = new \ArrayObject();
	\V::app()->hooks->applyHook('tiers:usage', [ $aid, $extra ]);
	return [ 'totals' => $totals, 'extra' => $extra->getArrayCopy(), 'projects' => $projects, 'tables' => $tables ];
    }

    //the rows of data table $iname of account $aid (its collection directly: the tables of another account are not registered here)
    public static function rowsOf($iname, $aid) {
	return (int)\V::app()->mongo->selectCollection(\sys\BaseModel::_database(), (string)$iname)->countDocuments([ 'aid' => $aid ]);
    }

    //the size of the account's files (bytes): [ '' => all ], with $byProject also [ project id => bytes ]
    public static function filesBytes($aid, $byProject = false) {
	$coll = \V::app()->mongo->selectCollection(\models\File::_database(), \models\File::_collection());
	$match = [ 'metadata.aid' => [ '$in' => [ $aid, (string)$aid ] ] ];
	$out = [ '' => 0 ];
	foreach($coll->aggregate([ [ '$match' => $match ], [ '$group' => [ '_id' => $byProject ? '$metadata.project' : null, 'n' => [ '$sum' => '$length' ] ] ] ]) as $row) {
	    $out[''] += (int)$row['n'];
	    if ($byProject && $row['_id'] !== null)
		$out[(string)$row['_id']] = ($out[(string)$row['_id']] ?? 0) + (int)$row['n'];
	}
	return $out;
    }

    //$n as shown: bytes in KB / MB / GB, the others as numbers; null: no limit
    public static function format($n, $unit = 'count') {
	if ($n === null)
	    return 'no limit';
	//CLD: 2026-10-24T16:30:00+03:00: util\Format::number / bytes (was formatted here: KB / MB / GB)
	if ($unit !== 'bytes')
	    return Format::number((int)$n);
	return Format::bytes($n);
    }

    //the account's id as an ObjectId
    private static function oid($account) {
	if ($account instanceof \MongoDB\BSON\ObjectId)
	    return $account;
	$id = is_object($account) ? (string)($account->_id ?? '') : (string)$account;
	return \V::app()->om->to_oid($id);
    }
}

