<?php

/*
    CLD: 2026-10-23T10:00:00+03:00: new: an AI step's run on the System AI (models\node\NodeAppAi with Credential: System AI), paid in credits:
	start()     the model's credit rates (SystemAiWallet::ratesOf), then credits reserved (at most SystemAi::maxCreditsPerRequest); fails when the model is not priced or no
		    credits are left
	add()       each call's tokens counted in credits; exhausted(): the reserved ones are used up (the step stops before its next call)
	finish()    the credits used taken (whole, at least 1 when a model answered), the reservation freed; once
*/

namespace plugins\MultiTenant\util;

use plugins\MultiTenant\models\SystemAiWallet;

class StepSession {
    public $credits = 0.0;
    public $usage = [ 'input_tokens' => 0, 'output_tokens' => 0 ];
    private $done = false;

    private function __construct(private $aid, private $hold, private $rates, private $prices, private array $info) {}

    //a run of account $aid on System AI $system (SystemAi::config) with $model (empty: the System's); $info: { wfid, exid, model } for the ledger
    public static function start($aid, $system, $model, array $info = []) {
	$model = trim((string)$model) !== '' ? trim((string)$model) : $system->model;
	//the System's prices are its model's; another model of the provider: its known prices, or its own rates (credit_rates.php)
	$settings = $model === $system->model ? $system : (object)[ 'provider' => $system->provider, 'model' => $model ];
	$rates = SystemAiWallet::ratesOf($settings);
	if ($rates === null)
	    throw new \ErrorException("the model `{$model}` is not priced on this installation's System AI: choose another model, or ask its administrator");
	$hold = SystemAiWallet::reserve($aid, SystemAi::maxCreditsPerRequest());
	if ($hold === null)
	    throw new \ErrorException('the System AI credits of this account are used up: the monthly allowance comes back on the 1st; ask who manages the account for a top-up');
	return new self((string)$aid, $hold, $rates, SystemAi::pricesOf($settings), [ 'model' => $model ] + $info);
    }

    //the credits reserved
    public function reserved() {
	return $this->hold[1];
    }

    //a call's tokens: [ input, output ]
    public function add(array $tokens) {
	$this->usage['input_tokens'] += (int)($tokens[0] ?? 0);
	$this->usage['output_tokens'] += (int)($tokens[1] ?? 0);
	$this->credits = SystemAiWallet::creditsOf($this->rates, $this->usage);
    }

    public function exhausted() {
	return $this->credits >= $this->hold[1];
    }

    //the credits taken => [ charged, left (null: not known) ]
    public function finish() {
	if ($this->done)
	    return [ 0, null ];
	$this->done = true;
	$used = array_sum($this->usage) ? SystemAiWallet::charge($this->credits) : 0;
	$cost = $this->prices ? ($this->usage['input_tokens'] * $this->prices[0] + $this->usage['output_tokens'] * $this->prices[1]) / 1000000 : null;
	try {
	    $left = SystemAiWallet::settle($this->aid, $this->hold[0], $used, $used ? [ 'source' => 'step', 'input_tokens' => $this->usage['input_tokens'],
		'output_tokens' => $this->usage['output_tokens'] ] + ($cost !== null ? [ 'cost_usd' => round($cost, 6) ] : []) + $this->info : []);
	} catch (\Exception $e) {
	    //the reservation frees itself (SystemAiWallet::HOLD_LEASE); the credits are not taken
	    \V::app()->log->error("System AI: {$used} credits of account {$this->aid} not taken: " . $e->getMessage());
	    $left = null;
	}
	return [ $used, $left ];
    }
}
