<?php

namespace plugins\SystemAI\commands;

use plugins\SystemAI\util\SystemAi;
use plugins\SystemAI\models\SystemAiWallet;
use plugins\SystemAI\models\SystemAiLedger;

//CLD: 2026-10-22T15:00:00+03:00: new: the System provider's credits (models\AssistantWallet)
//CLD: 2026-10-23T10:00:00+03:00: the System AI's (models\SystemAiWallet; was Assistant::credits::<action>)
//  ../v SystemAI::credits::show --account=<id>                         the credits: allowance, top-ups, the last changes
//  ../v SystemAI::credits::add --account=<id> --credits=<n> [--note=..]  a top-up, valid SystemAiWallet::TOPUP_MONTHS months
//  ../v SystemAI::credits::plan --account=<id> [--credits=<n>]           the account's credits per month (without --credits: the installation's, SYSTEM_AI_MONTHLY_CREDITS)
//  ../v SystemAI::credits::rates                                       the credits per model (credit_rates.php): their value, the provider's price, the margin
class CreditsCommand extends \sys\BaseController {

    public function show($req = null, $resp = null) {
	$aid = $this->account($req);
	$w = SystemAiWallet::current($aid);
	echo "account {$aid}: " . SystemAiWallet::balance($w) . " credits\n";
	echo "  allowance {$w['month']}: {$w['allowance_left']} of {$w['allowance']} left (plan: " . ($w['plan'] === null ? 'the installation\'s, ' : '') . SystemAiWallet::planOf($w) . " per month)\n";
	echo "  used this month: {$w['month_used']}" . ($w['holds'] ? ', reserved: ' . array_sum(array_column($w['holds'], 'credits')) : '') . "\n";
	foreach($w['topups'] as $t)
	    echo "  top-up {$t['id']}: {$t['left']} of {$t['granted']}, " . gmdate('Y-m-d', $t['at']) . ' to ' . gmdate('Y-m-d', $t['expires']) . ($t['note'] !== '' ? " ({$t['note']})" : '') . "\n";
	echo "last changes:\n";
	foreach(SystemAiLedger::recent($aid, 15) as $e) {
	    $at = $e->created_at instanceof \MongoDB\BSON\UTCDateTime ? gmdate('Y-m-d H:i', $e->created_at->toDateTime()->getTimestamp()) : '';
	    printf("  %s  %-10s %+8d  = %8d  %s\n", $at, $e->type, $e->credits, $e->balance, $e->note);
	}
    }

    public function add($req = null, $resp = null) {
	$aid = $this->account($req);
	$n = (int)($req->params['credits'] ?? 0);
	if ($n <= 0)
	    $this->fail('--credits=<n>: more than 0');
	$t = SystemAiWallet::grant($aid, $n, (string)($req->params['note'] ?? ''));
	echo "account {$aid}: +{$n} credits until " . gmdate('Y-m-d', $t['expires']) . " (top-up {$t['id']}); " . SystemAiWallet::balance(SystemAiWallet::current($aid)) . " credits now\n";
    }

    public function plan($req = null, $resp = null) {
	$aid = $this->account($req);
	$n = $req->params['credits'] ?? null;
	if ($n !== null && (!is_numeric($n) || $n < 0))
	    $this->fail('--credits=<n>: 0 or more');
	$plan = SystemAiWallet::setPlan($aid, $n === null ? null : (int)$n);
	echo "account {$aid}: {$plan} credits per month" . ($n === null ? ' (the installation\'s)' : '') . "; this month's allowance changed by the difference\n";
    }

    //the System model first, then every model of credit_rates.php with its credits, what they are worth, the provider's price and the margin
    public function rates($req = null, $resp = null) {
	$table = SystemAiWallet::rateTable();
	$unit = (float)$table['credit_usd'];
	echo "1 credit = {$unit} USD; per 1M tokens: credits (USD) / provider USD / margin\n";
	$system = SystemAi::config();
	$models = array_keys((array)$table['models']);
	if ($system)
	    array_unshift($models, $system->model);
	foreach(array_unique($models) as $i => $model) {
	    $s = $system && $model === $system->model ? $system : (object)[ 'model' => $model ];
	    $r = SystemAiWallet::ratesOf($s, $table);
	    $p = SystemAi::pricesOf($s);
	    $line = sprintf('%-22s', $model . ($system && $i === 0 ? ' *' : ''));
	    if ($r === null) {
		echo "{$line} no rates: set them in credit_rates.php\n";
		continue;
	    }
	    foreach([ 'in' => 0, 'out' => 1, 'cache' => 2 ] as $k => $j)
		$line .= sprintf('  %s %7s (%6.2f) / %s', $k, rtrim(rtrim(number_format($r[$j], 2, '.', ''), '0'), '.'), $r[$j] * $unit, $p ? sprintf('%6.2f', $p[$j]) . ' / ' . ($p[$j] > 0 ? sprintf('%3d%%', round(($r[$j] * $unit / $p[$j] - 1) * 100)) : '   -') : '     ?');
	    echo $line . "\n";
	}
	if ($system)
	    echo "* the System AI's model (SYSTEM_AI_MODEL)\n";
    }

    private function account($req) {
	$id = (string)($req->params['account'] ?? '');
	$acct = preg_match('/^[a-f0-9]{24}$/', $id) ? \V::app()->om->findById('Account', $id) : null;
	if (!$acct)
	    $this->fail('--account=<id>: an account');
	return (string)$acct->_id;
    }

    private function fail($message) {
	echo $message . "\n";
	exit(1);
    }
}
