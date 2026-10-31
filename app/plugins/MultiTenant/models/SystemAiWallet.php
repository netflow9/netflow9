<?php

/*
    the System provider's credits of an account (as a prepaid SIM): one document per account, _id = the account's id.
    CLD: 2026-10-23T10:00:00+03:00: the System AI's (plugins\MultiTenant: the Assistant and the AI steps use them; was plugins\Assistant\models\AssistantWallet, collection assistant_wallets)
	allowance        the plan's credits of the month: granted on the first request of each calendar month (UTC), no rollover (what is left of the month before is lost).
			 The plan: `plan` (set per account: CreditsCommand plan), else config ai.system.monthly_credits (util\SystemAi::monthlyCredits)
			 CLD: 2026-10-24T09:45:36+03:00: the plan: `plan`, else the account's tier's (util\Tiers: ai_monthly_credits), else the installation's
	topups           the credits granted on top (CreditsCommand add): [ { id, granted, left, at, expires (unix s: at + TOPUP_MONTHS), note } ]; used after the allowance,
			 the one that expires first first; what is left at its expiry is lost
	holds            the credits reserved by running requests (reserve): [ { id, credits, until (unix s) } ]; settled by settle(), else freed after HOLD_LEASE
	month_used       the credits spent this month (the Assistant's cap: models\AssistantSettings credit_cap)
    A model's credits per 1M tokens: credit_rates.php (ratesOf: its own, or the provider's price + a margin); a request costs at least 1 (charge).
    Every change is a new version of the document (`v`): written only when nobody changed it meanwhile, else done again (change()); each change is also written to
    the ledger (SystemAiLedger)
*/

namespace plugins\MultiTenant\models;

use plugins\MultiTenant\util\SystemAi;

class SystemAiWallet extends \sys\BaseModel {
    //CLD: 2026-10-23T10:00:00+03:00: systemai_wallets (was assistant_wallets)
    protected static $collection = 'systemai_wallets';

    const TOPUP_MONTHS = 12;
    //a running request's reservation is freed after this (s), when it was not settled (the request died)
    const HOLD_LEASE = 7200;
    //how many times a change is tried when other requests change the wallet meanwhile
    const TRIES = 8;

    public function getSchema() {
	return array_merge([
	    [ 'key' => 'v', 'type' => 'number', 'label' => 'Version' ],
	    [ 'key' => 'month', 'type' => 'text', 'label' => 'Month' ],
	    [ 'key' => 'plan', 'type' => 'number', 'label' => 'Monthly credits (plan)' ],
	    [ 'key' => 'allowance', 'type' => 'number', 'label' => 'Allowance' ],
	    [ 'key' => 'allowance_left', 'type' => 'number', 'label' => 'Allowance left' ],
	    [ 'key' => 'month_used', 'type' => 'number', 'label' => 'Used this month' ],
	    [ 'key' => 'topups', 'type' => 'array', 'label' => 'Top-ups' ],
	    [ 'key' => 'holds', 'type' => 'array', 'label' => 'Reserved' ],
	], $this->getSystemSchema());
    }

    // ------------------------------------------------------------------------------------
    //the wallet as an array (pure: the checks use these without a database)

    //an account's wallet before its first change
    public static function blank() {
	return [ 'v' => 0, 'month' => '', 'plan' => null, 'allowance' => 0, 'allowance_left' => 0, 'month_used' => 0, 'topups' => [], 'holds' => [] ];
    }

    //the plan's credits per month of wallet $w
    //CLD: 2026-10-24T09:45:36+03:00: + $aid: the account's, whose tier may set them (util\Tiers: ai_monthly_credits), before the installation's
    public static function planOf(array $w, $aid = null) {
	//CLD: 2026-10-23T10:00:00+03:00: SystemAi::monthlyCredits (was config assistant.system)
	if ($w['plan'] !== null)
	    return (int)$w['plan'];
	$tier = $aid !== null ? \util\Tiers::limit((string)$aid, 'ai_monthly_credits') : null;
	return $tier !== null ? (int)$tier : SystemAi::monthlyCredits();
    }

    //$w at time $now: a new month's allowance (the last one's left is lost), the expired top-ups emptied, the old reservations freed => [ $w, ledger entries ]
    //CLD: 2026-10-24T09:45:36+03:00: + $aid: the account's (planOf)
    public static function refresh(array $w, $now, $aid = null) {
	$entries = [];
	$month = gmdate('Y-m', $now);
	if ($w['month'] !== $month) {
	    $plan = self::planOf($w, $aid);
	    $lost = (int)$w['allowance_left'];
	    $w['month'] = $month;
	    $w['allowance'] = $w['allowance_left'] = $plan;
	    $w['month_used'] = 0;
	    $entries[] = [ 'type' => 'allowance', 'credits' => $plan, 'note' => "monthly allowance {$month}" . ($lost > 0 ? " ({$lost} left of the last one lost)" : '') ];
	}
	foreach($w['topups'] as &$t) {
	    if ($t['left'] > 0 && $t['expires'] <= $now) {
		$entries[] = [ 'type' => 'expire', 'credits' => -$t['left'], 'note' => "top-up {$t['id']} expired" ];
		$t['left'] = 0;
	    }
	}
	unset($t);
	//spent and expired top-ups are kept a while (the settings page lists them), then dropped
	$w['topups'] = array_values(array_filter($w['topups'], fn($t) => $t['left'] > 0 || $t['expires'] > $now - 86400 * 90));
	$w['holds'] = array_values(array_filter($w['holds'], fn($h) => $h['until'] > $now));
	return [ $w, $entries ];
    }

    //the credits of $w: allowance left + top-ups left
    public static function balance(array $w) {
	return (int)$w['allowance_left'] + array_sum(array_column($w['topups'], 'left'));
    }

    //what may still be reserved in $w: the balance less the reservations; with $cap (credits per month), not beyond it
    public static function available(array $w, $cap = null) {
	$held = array_sum(array_column($w['holds'], 'credits'));
	$n = self::balance($w) - $held;
	if ($cap !== null && $cap > 0)
	    $n = min($n, (int)$cap - (int)$w['month_used'] - $held);
	return max(0, $n);
    }

    //$credits taken from $w: the allowance first, then the top-ups that expire first => [ $w, unpaid (more than there was: not taken) ]
    public static function debit(array $w, $credits) {
	$credits = max(0, (int)$credits);
	$w['month_used'] += $credits;
	$take = min($credits, max(0, (int)$w['allowance_left']));
	$w['allowance_left'] -= $take;
	$credits -= $take;
	usort($w['topups'], fn($a, $b) => $a['expires'] <=> $b['expires']);
	foreach($w['topups'] as &$t) {
	    if ($credits <= 0)
		break;
	    $take = min($credits, (int)$t['left']);
	    $t['left'] -= $take;
	    $credits -= $take;
	}
	unset($t);
	return [ $w, $credits ];
    }

    //the credit rates (credit_rates.php, or credit_rates.local.php when present)
    public static function rateTable() {
	$f = dirname(__DIR__) . '/credit_rates.local.php';
	$t = require(is_file($f) ? $f : dirname(__DIR__) . '/credit_rates.php');
	return (is_array($t) ? $t : []) + [ 'credit_usd' => 0.01, 'default_margin' => 0.30, 'models' => [] ];
    }

    //the credits per 1M tokens of the System's $settings (resolved: provider, model, prices): [ input, output, cache read ]: the model's own (credit_rates.php: in, out,
    //cache_read), else from the provider's price (SystemAi::pricesOf) and the margin, rounded up; null: neither is known. $table: rateTable()
    public static function ratesOf($settings, $table = null) {
	$table = $table ?? self::rateTable();
	$model = (string)($settings->model ?? '');
	$def = [];
	foreach((array)$table['models'] as $prefix => $d) {
	    if (str_starts_with($model, (string)$prefix)) {
		$def = (array)$d;
		break;
	    }
	}
	if (isset($def['in'], $def['out']))
	    return [ (float)$def['in'], (float)$def['out'], (float)($def['cache_read'] ?? $def['in'] * 0.1) ];
	$prices = SystemAi::pricesOf($settings); //CLD: 2026-10-23T10:00:00+03:00: (was AssistantSettings::pricesOf)
	$unit = (float)$table['credit_usd'];
	if ($prices === null || $unit <= 0)
	    return null;
	$k = (1 + (float)($def['margin'] ?? $table['default_margin'])) / $unit;
	return array_map(fn($p) => ceil(round($p * $k, 6) * 100) / 100, $prices);
    }

    //the credits of $usage (tokens: WorkflowAssistant's) at $rates (ratesOf), as a number (WorkflowAssistant counts with it)
    //CLD: 2026-10-23T10:00:00+03:00: SystemAi::CACHE_WRITE (was AssistantSettings::)
    public static function creditsOf(array $rates, array $usage) {
	[ $in, $out, $cacheRead ] = $rates;
	return ((int)($usage['input_tokens'] ?? 0) * $in + (int)($usage['output_tokens'] ?? 0) * $out
	    + (int)($usage['cache_read_input_tokens'] ?? 0) * $cacheRead + (int)($usage['cache_creation_input_tokens'] ?? 0) * $in * SystemAi::CACHE_WRITE) / 1000000;
    }

    //what a request charges for $credits counted: whole credits, at least 1
    public static function charge($credits) {
	return max(1, (int)ceil(round($credits, 6)));
    }

    // ------------------------------------------------------------------------------------
    //the account's wallet

    //the wallet of account $aid as it is now (refreshed, not written): the array of blank()
    public static function current($aid) {
	return self::refresh(self::load($aid), time(), $aid)[0];
    }

    private static function load($aid) {
	$doc = \V::app()->om->findOne('SystemAiWallet', [ '_id' => (string)$aid, 'aid' => \V::app()->om->to_oid($aid) ]);
	if (!$doc)
	    return self::blank();
	$w = self::blank();
	foreach(array_keys($w) as $k)
	    $w[$k] = $doc->$k ?? $w[$k];
	$w['topups'] = array_map(fn($t) => (array)$t, json_decode(\util\JSON::encode($w['topups']), true) ?: []);
	$w['holds'] = array_map(fn($h) => (array)$h, json_decode(\util\JSON::encode($w['holds']), true) ?: []);
	return $w;
    }

    //changes the wallet of account $aid with $fn(array $w, int $now) => [ $w, ledger entries, result ] (the wallet refreshed first); written when nobody changed it meanwhile,
    //else done again on the new one. Returns the result
    private static function change($aid, callable $fn) {
	$om = \V::app()->om;
	for($try = 1; ; $try++) {
	    $now = time();
	    $w = self::load($aid);
	    $v = (int)$w['v'];
	    [ $w, $entries ] = self::refresh($w, $now, $aid);
	    [ $w, $more, $result ] = $fn($w, $now);
	    $entries = array_merge($entries, $more);
	    $w['v'] = $v + 1;
	    $set = $w + [ 'aid' => $om->to_oid($aid), 'updated_at' => new \MongoDB\BSON\UTCDateTime($now * 1000) ];
	    try {
		//version $v: a new wallet is inserted (upsert); another one written meanwhile: no match, or a duplicate _id
		$written = $om->updateOne('SystemAiWallet', [ '_id' => (string)$aid, 'aid' => $om->to_oid($aid), 'v' => $v ], [ '$set' => $set ], [ 'upsert' => true ]);
	    } catch (\MongoDB\Driver\Exception\Exception $e) {
		$written = 0;
	    }
	    if ($written) {
		$balance = self::balance($w);
		foreach($entries as $e)
		    SystemAiLedger::add($aid, $e + [ 'balance' => $balance ]);
		return $result;
	    }
	    if ($try >= self::TRIES)
		throw new \ErrorException('the System AI credits are busy: try again');
	    usleep(random_int(5000, 50000));
	}
    }

    //reserves credits for a request of account $aid: what is available, at most $max (0: no limit) and within $cap (credits per month) => [ hold id, credits ], or null when
    //none are available
    public static function reserve($aid, $max = 0, $cap = null) {
	return self::change($aid, function($w, $now) use ($max, $cap) {
	    $n = self::available($w, $cap);
	    if ($max > 0)
		$n = min($n, (int)$max);
	    if ($n <= 0)
		return [ $w, [], null ];
	    $id = bin2hex(random_bytes(8));
	    $w['holds'][] = [ 'id' => $id, 'credits' => $n, 'until' => $now + self::HOLD_LEASE ];
	    return [ $w, [], [ $id, $n ] ];
	});
    }

    //the request of reservation $holdId spent $credits: the reservation freed, the credits taken; $info: the request (workflow, user, tokens, cost) for the ledger.
    //=> the credits left
    //CLD: 2026-10-23T10:00:00+03:00: + $info['source'] (SystemAiLedger::SOURCES: assistant | step) names the request in the ledger (was always an Assistant request)
    public static function settle($aid, $holdId, $credits, array $info = []) {
	return self::change($aid, function($w, $now) use ($holdId, $credits, $info) {
	    $w['holds'] = array_values(array_filter($w['holds'], fn($h) => $h['id'] !== $holdId));
	    [ $w, $unpaid ] = self::debit($w, $credits);
	    $note = (SystemAiLedger::SOURCES[$info['source'] ?? ''] ?? 'Request') . ($unpaid > 0 ? " ({$unpaid} over the balance, not taken)" : '');
	    //nothing used (no model answered): only the reservation freed
	    return [ $w, $credits > 0 ? [ [ 'type' => 'debit', 'credits' => -($credits - $unpaid), 'note' => $note ] + $info ] : [], self::balance($w) ];
	});
    }

    //grants account $aid a top-up of $credits, valid TOPUP_MONTHS => the top-up
    public static function grant($aid, $credits, $note = '') {
	return self::change($aid, function($w, $now) use ($credits, $note) {
	    $t = [ 'id' => bin2hex(random_bytes(4)), 'granted' => (int)$credits, 'left' => (int)$credits, 'at' => $now,
		'expires' => strtotime('+' . self::TOPUP_MONTHS . ' months', $now), 'note' => (string)$note ];
	    $w['topups'][] = $t;
	    return [ $w, [ [ 'type' => 'topup', 'credits' => (int)$credits, 'note' => 'top-up ' . $t['id'] . ' until ' . gmdate('Y-m-d', $t['expires']) . ($note !== '' ? ": {$note}" : '') ] ], $t ];
	});
    }

    //sets the plan of account $aid: $credits per month (null: the installation's). This month's allowance changes by the difference (not below 0)
    //CLD: 2026-10-24T09:45:36+03:00: null: the account's tier's, else the installation's (planOf)
    public static function setPlan($aid, $credits) {
	return self::change($aid, function($w, $now) use ($aid, $credits) {
	    $w['plan'] = $credits === null ? null : max(0, (int)$credits);
	    $plan = self::planOf($w, $aid);
	    $diff = $plan - (int)$w['allowance'];
	    $w['allowance'] = $plan;
	    $w['allowance_left'] = max(0, (int)$w['allowance_left'] + $diff);
	    return [ $w, $diff ? [ [ 'type' => 'adjust', 'credits' => $diff, 'note' => "plan: {$plan} credits per month" ] ] : [], $plan ];
	});
    }
}
