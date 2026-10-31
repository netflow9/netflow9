<?php

/*
    CLD: 2026-10-31T22:00:00+02:00: new: the MultiTenant plugin's checks, run by the core's check suite (commands\CheckCommand, hook check:suites: Plugin): each a closure run as a method of
    the check command (->check(), ->account, ->project, the flows' helpers). Were in the core's suite (systemai, and parts of email, tiers, assistant), so the core
    is checked without this plugin.
      systemai        the AI steps on the System AI, the credits (rates, the wallet, reserve and settle), the tier's allowance
      registration    the sign-up (a link's token, the email, the form's rules)
*/

return [
    //CLD: 2026-10-23T10:00:00+03:00: new: the AI steps on the System AI (plugins\MultiTenant; Credential `system`): the step's credential per provider (URLs, keys), offered only to the
    //steps of its provider, refused by the others; a run against the fake AI server (as a Lemonade System AI): the installation's key sent, the credits charged (the ledger: the step,
    //its execution, the model), logged; a model not priced, the reserved credits used up, no credits left: the `error` output. The check account's credits are put back after
    'systemai' => function() {
	//CLD: 2026-10-31T21:00:00+02:00: the MultiTenant plugin's option system_ai (was: the SystemAI plugin's classes)
	if (!\plugins\MultiTenant\Plugin::systemAi()) {
	    $this->fail('System AI: the MultiTenant plugin\'s option system_ai is off');
	    return;
	}
	//CLD: 2026-10-31T22:00:00+02:00: the credits (were in the core's suite assistant)
	//CLD: 2026-10-22T15:00:00+03:00: System's credits (AssistantWallet): per model (its own rates, or the provider's price + the margin), the month's allowance without rollover,
	//top-ups that expire, used allowance first then the top-up that expires first, the account's monthly limit, reserved then settled; the loop stops at the reserved credits
	//CLD: 2026-10-23T10:00:00+03:00: the System AI's (plugins\MultiTenant: SystemAiWallet, SystemAiLedger; were AssistantWallet, AssistantCreditLedger)
	$W = \plugins\MultiTenant\models\SystemAiWallet::class;
	$table = [ 'credit_usd' => 0.01, 'default_margin' => 0.30, 'models' => [ 'claude-opus-5-5' => [ 'margin' => 0.25 ], 'my-model' => [ 'in' => 100, 'out' => 400 ] ] ];
	$this->check('credits per model: price + margin, the default margin, own rates, unknown', [ [ 500.0, 2500.0, 25.0 ], [ 260.0, 1300.0, 26.0 ], [ 100.0, 400.0, 10.0 ], null ],
	    [ $W::ratesOf((object)[ 'model' => 'claude-opus-5-5' ], $table), $W::ratesOf((object)[ 'model' => 'claude-sonnet-5-5' ], $table),
	    $W::ratesOf((object)[ 'model' => 'my-model' ], $table), $W::ratesOf((object)[ 'provider' => 'openai', 'model' => 'gpt-x' ], $table) ]);
	$this->check('credits of a request: tokens x rates (cache write 1.25 x in), charged whole, at least 1', [ 0.775, 1, 1, 3, 2 ],
	    [ $W::creditsOf([ 500, 2500, 25 ], [ 'input_tokens' => 1000, 'output_tokens' => 100, 'cache_read_input_tokens' => 1000, 'cache_creation_input_tokens' => 0 ]),
	    $W::charge(0.775), $W::charge(0), $W::charge(2.001), $W::charge(2.0000001) ]);
	$t0 = gmmktime(12, 0, 0, 3, 15, 2026);
	$w = $W::blank();
	$w['plan'] = 100;
	[ $w, $e1 ] = $W::refresh($w, $t0);
	$w['topups'] = [ [ 'id' => 'late', 'granted' => 50, 'left' => 50, 'at' => $t0, 'expires' => $t0 + 86400 * 300, 'note' => '' ],
	    [ 'id' => 'soon', 'granted' => 30, 'left' => 30, 'at' => $t0, 'expires' => $t0 + 86400 * 5, 'note' => '' ] ];
	[ $w, $unpaid ] = $W::debit($w, 120);
	$left = array_column($w['topups'], 'left', 'id');
	$this->check('wallet: the allowance first, then the top-up that expires first', [ 0, 10, 50, 0, 60, 120 ], [ $w['allowance_left'], $left['soon'], $left['late'], $unpaid, $W::balance($w), $w['month_used'] ]);
	$this->check('wallet: a monthly limit counts the month\'s use and the reservations', [ 60, 30, 0 ],
	    [ $W::available($w), $W::available($w, 150), $W::available(array_merge($w, [ 'holds' => [ [ 'id' => 'h', 'credits' => 40, 'until' => $t0 + 60 ] ] ]), 150) ]);
	$w['allowance_left'] = 40;
	[ $w2, $e2 ] = $W::refresh($w, $t0 + 86400 * 20);
	$this->check('wallet: next month the allowance is renewed, not carried over; an expired top-up is emptied', [ '2026-04', 100, 0, 0, [ 'allowance', 'expire' ], true ],
	    [ $w2['month'], $w2['allowance_left'], $w2['month_used'], array_column($w2['topups'], 'left', 'id')['soon'], array_column($e2, 'type'), str_contains($e2[0]['note'], '40 left of the last one lost') ]);
	//the database: a test account's wallet (removed after)
	$aid = (string)new \MongoDB\BSON\ObjectId();
	try {
	    $W::setPlan($aid, 20);
	    $t = $W::grant($aid, 30, 'check');
	    $h1 = $W::reserve($aid, 25);
	    $h2 = $W::reserve($aid, 0, 40);
	    $h3 = $W::reserve($aid, 0, 40);
	    $after = $W::settle($aid, $h1[0], 22, [ 'wfid' => 'w1' ]);
	    $W::settle($aid, $h2[0], 0);
	    $cur = $W::current($aid);
	    $types = array_map(fn($e) => $e->type, iterator_to_array(\plugins\MultiTenant\models\SystemAiLedger::recent($aid, 10), false));
	    $this->check('wallet (db): reserve at most the limit, within the monthly limit, none left; settled from the allowance then the top-up; the ledger',
		[ 12, 25, 15, null, 28, 0, 28, 22, [ 'debit', 'topup', 'adjust', 'allowance' ] ],
		[ (int)round(($t['expires'] - $t['at']) / 86400 / 30.4), $h1[1], $h2[1], $h3, $after, $cur['allowance_left'], array_sum(array_column($cur['topups'], 'left')), $cur['month_used'], array_values(array_unique($types)) ]);
	} finally {
	    \V::app()->om->deleteOne('SystemAiWallet', [ '_id' => $aid, 'aid' => \V::app()->om->to_oid($aid) ]);
	    $L = \plugins\MultiTenant\models\SystemAiLedger::class;
	    \V::app()->mongo->selectCollection($L::_database(), $L::_collection())->deleteMany([ 'aid' => \V::app()->om->to_oid($aid) ]);
	}

	//CLD: 2026-10-31T22:00:00+02:00: the System AI's allowance: the account's plan, else the tier's, else the installation's (was in the core's suite tiers), on an account made for it
	$W = \plugins\MultiTenant\models\SystemAiWallet::class;
	$restore = \V::app()->config('rt.account');
	$account = \models\Account::create([ 'state' => 'active', 'name' => 'Check System AI ' . uniqid() ]);
	$account->save();
	try {
	    \V::app()->var('rt.account', $account);
	    $set = function($tier, array $limits = []) use ($account) {
		$account->tier = $tier;
		$account->tier_overrides = [ 'limits' => $limits, 'features' => [] ];
		\util\Tiers::reset();
	    };
	    if (\util\Tiers::enabled()) {
		$set('free', [ 'ai_monthly_credits' => 123 ]);
		$tierPlan = $W::planOf($W::blank(), $account->_id);
		$own = $W::planOf([ 'plan' => 50 ] + $W::blank(), $account->_id);
		$set('free', [ 'ai_monthly_credits' => null ]);
		$this->check('System AI: the tier\'s allowance, the account\'s plan before it, the installation\'s without either', [ 123, 50, \plugins\MultiTenant\util\SystemAi::monthlyCredits() ],
		    [ $tierPlan, $own, $W::planOf($W::blank(), $account->_id) ]);
	    }
	} finally {
	    \V::app()->var('rt.account', $restore);
	    \util\Tiers::reset();
	    $account->delete();
	}

	//the AI steps on the System AI (a fake AI server)
	$n = [ static::class, 'node' ];
	$e = [ static::class, 'edge' ];
	$SA = \plugins\MultiTenant\util\SystemAi::class;
	$W = \plugins\MultiTenant\models\SystemAiWallet::class;
	$L = \plugins\MultiTenant\models\SystemAiLedger::class;
	$sys = fn($provider, $url = '', $key = 'k') => (object)[ 'provider' => $provider, 'url' => $url, 'api_key' => $key, 'model' => 'm' ];
	$c = [ $SA::stepCredential($sys('anthropic')), $SA::stepCredential($sys('gemini', 'https://generativelanguage.googleapis.com/v1beta/openai')), $SA::stepCredential($sys('gemini')),
	    $SA::stepCredential($sys('ollama', 'http://localhost:11434/v1', '')), $SA::stepCredential($sys('lemonade')), $SA::stepCredential($sys('openai', 'http://x/v1/')) ];
	$this->check('System AI: a step\'s credential per provider (Gemini\'s own API, Ollama\'s without /v1; the keys as the providers want them)', [ 'https://api.anthropic.com', 'k',
	    'https://generativelanguage.googleapis.com/v1beta', 'https://generativelanguage.googleapis.com/v1beta', [ 'x-goog-api-key' => 'k' ], 'http://localhost:11434', [],
	    'http://localhost:8000/api/v1', 'http://x/v1', [ 'Authorization' => 'Bearer k' ] ],
	    [ $c[0]->baseUrl, $c[0]->httpAuth()['headers']['x-api-key'] ?? null, $c[1]->baseUrl, $c[2]->baseUrl, $c[2]->httpAuth()['headers'], $c[3]->apiBase(), $c[3]->httpAuth()['headers'],
	    $c[4]->baseUrl, $c[5]->baseUrl, $c[5]->httpAuth()['headers'] ]);

	$aid = (string)$this->account->_id;
	$savedConfig = \V::app()->config('ai.system');
	$wallets = \V::app()->mongo->selectCollection($W::_database(), $W::_collection());
	//the wallet's _id: the account's id as stored (ObjectId: the object manager's)
	$savedWallet = $wallets->findOne([ '_id' => \V::app()->om->to_oid($aid) ]);
	$since = new \MongoDB\BSON\UTCDateTime();
	$server = null;
	try {
	    \V::app()->config('ai.system', [ 'provider' => '', 'model' => '' ]);
	    $none = \models\node\NodeAppLemonade::systemProvider($aid);
	    \V::app()->config('ai.system', [ 'provider' => 'lemonade', 'url' => 'http://127.0.0.1:1/api/v1', 'api_key' => 'check-key', 'model' => 'Qwen3-8B-GGUF' ]);
	    $this->check('System AI: offered to the steps of its provider only; none without one', [ null, 'Qwen3-8B-GGUF', null, null, null ],
		[ $none, \models\node\NodeAppLemonade::systemProvider($aid)['model'] ?? null, \models\node\NodeAppOpenAI::systemProvider($aid), \models\node\NodeAppClaude::systemProvider($aid),
		\models\node\NodeAppLemonade::systemProvider(null) ]);
	    $errors = $this->errorsOf('system ai ollama', [ $n('m', 'trigger::manual'), $n('s', 'app::ollama', [ 'credential' => 'system', 'model' => 'x', 'prompt' => 'hi' ]), $e('e1', 'm', 's') ]);
	    $this->check('System AI: a step of another provider is refused; one of its provider is valid', [ true, [] ],
		[ str_contains(json_encode($errors), 'System AI does not serve Ollama steps'),
		$this->errorsOf('system ai lemonade', [ $n('m', 'trigger::manual'), $n('s', 'app::lemonade', [ 'credential' => 'system', 'model' => 'x', 'prompt' => 'hi' ]), $e('e1', 'm', 's') ]) ]);

	    $server = $this->startAiServer('System AI');
	    if (!$server)
		return;
	    $url = "http://127.0.0.1:{$server['port']}/api/v1";
	    //1 USD per 1M input tokens, 2 output: a few credits per 1M, a request costs 1
	    //as an OpenAI System AI (a local one prices every model at 0)
	    \V::app()->config('ai.system', [ 'provider' => 'openai', 'url' => $url, 'api_key' => 'check-key', 'model' => 'Qwen3-8B-GGUF', 'price_input' => 1, 'price_output' => 2,
		'monthly_credits' => 0, 'max_credits_per_request' => 0 ]);
	    $W::setPlan($aid, 50);
	    $start = $W::balance($W::current($aid));
	    $withTool = isset(\util\MCPTools::all()['list_tables']);
	    $flow = fn($name, $config) => $this->runFlow("system ai {$name}", [ $n('m', 'trigger::manual'), $n('s', 'app::openai', $config + [ 'credential' => 'system',
		'model' => 'Qwen3-8B-GGUF', 'prompt' => 'Hi', 'tools' => [], 'mcp' => [], 'onError' => 'output', 'timeout' => 15 ]),
		$n('ok', 'app::set', [ 'variable' => 'route', 'value' => 'success' ]), $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]),
		$e('e1', 'm', 's'), $e('e2', 's', 'ok', 0), $e('e3', 's', 'err', 1) ]);

	    $exe = $flow('run', []);
	    $last = iterator_to_array($L::recent($aid, 1), false)[0] ?? null;
	    $this->check('System AI: a run with the installation\'s key (the server asks for it); 1 credit charged; the ledger: the step, its execution and workflow, the model; logged',
		[ 'success', 'Hello from Qwen3-8B-GGUF', $start - 1, 'debit', -1, 'step', (string)$exe?->_id, (string)$exe?->wf, 'Qwen3-8B-GGUF', 11, 'AI step', true ],
		[ $exe?->getVariable('route'), $exe?->getVariable('ai')['text'] ?? null, $W::balance($W::current($aid)), $last?->type, (int)$last?->credits, $last?->source, $last?->exid,
		$last?->wfid, $last?->model, (int)$last?->input_tokens, $last?->note, str_starts_with((string)static::logLine($exe, 'OpenAI: System AI'), 'OpenAI: System AI, 1 credit(s), ' . ($start - 1) . ' left') ]);

	    $exe = $flow('not priced', [ 'model' => 'other-model' ]);
	    $this->check('System AI: a model this installation does not price: the `error` output, nothing charged', [ 'error', true, $start - 1 ],
		[ $exe?->getVariable('route'), str_contains((string)($exe?->getVariable('ai')['error'] ?? ''), 'the model `other-model` is not priced'), $W::balance($W::current($aid)) ]);

	    if ($withTool) {
		//a price that makes the first call cost more than the 2 credits a request may reserve: no second call
		\V::app()->config('ai.system', [ 'max_credits_per_request' => 2, 'price_input' => 100000 ] + \V::app()->config('ai.system'));
		$calls = count($server['requests']('/api/v1/chat/completions'));
		$exe = $flow('used up', [ 'tools' => [ 'list_tables' ] ]);
		$this->check('System AI: the credits reserved used up after a call: no more calls, the `error` output; what was used is charged', [ 'error', true, 1, true ],
		    [ $exe?->getVariable('route'), str_contains((string)($exe?->getVariable('ai')['error'] ?? ''), 'credits reserved for this step (2) are used up'),
		    count($server['requests']('/api/v1/chat/completions')) - $calls, $W::balance($W::current($aid)) < $start - 1 ]);
	    }

	    $W::setPlan($aid, 0);
	    $wallets->updateOne([ '_id' => \V::app()->om->to_oid($aid) ], [ '$set' => [ 'topups' => [] ] ]);
	    $exe = $flow('no credits', []);
	    $this->check('System AI: no credits left: the `error` output', [ 'error', true ],
		[ $exe?->getVariable('route'), str_contains((string)($exe?->getVariable('ai')['error'] ?? ''), 'credits of this account are used up') ]);
	} catch (\ErrorException $ex) {
	    $this->fail('System AI: ' . $ex->getMessage());
	} finally {
	    \V::app()->config('ai.system', $savedConfig);
	    if ($savedWallet)
		$wallets->replaceOne([ '_id' => \V::app()->om->to_oid($aid) ], $savedWallet);
	    else
		$wallets->deleteOne([ '_id' => \V::app()->om->to_oid($aid) ]);
	    \V::app()->mongo->selectCollection($L::_database(), $L::_collection())->deleteMany([ 'aid' => \V::app()->om->to_oid($aid), 'created_at' => [ '$gte' => $since ] ]);
	    if ($server)
		$this->stopAiServer($server);
	}
    },

    //CLD: 2026-10-16T12:19:22+03:00: sign-up: a link's token finds its sign-up once (only its hash is stored), the system email (data\SysEmails) queued with the link, the form's
    //password rules (CLD: 2026-10-31T22:00:00+02:00: was in the core's suite email, only with the option registration: now whatever the option, its emails are always registered)
    'registration' => function() {
	//CLD: 2026-10-16T12:19:22+03:00: sign-up: a link's token finds its sign-up once (only its hash is stored), the system email (data\SysEmails) queued with the link, the form's password rules
	[ $signup, $token ] = \plugins\MultiTenant\models\Signup::start('zz-check@example.com');
	//CLD: 2026-10-18T23:35:30+03:00: the link as SiteController builds it (V::createAbsoluteUrl; was https://x.ro/...)
	$job = \util\EmailSend::system('signup_confirm', 'zz-check@example.com', [ 'link' => \V::app()->createAbsoluteUrl('/MultiTenant/register/confirm', [ 't' => $token ]) ]);
	$jobDoc = \V::app()->om->findById('Job', (string)$job);
	$found = \plugins\MultiTenant\models\Signup::findByToken($token);
	$this->check('signup: the token finds it, the hash is stored (not the token), the email queued with the link', [ true, false, true, 'Confirm your email for ' . \V::app()->config('app.name') ],
	    [ $found && (string)$found->_id === (string)$signup->_id, $signup->token_hash === $token, str_contains((string)($jobDoc->job_data['html'] ?? ''), $token), $jobDoc->job_data['subject'] ?? null ]);
	$jobDoc?->delete();
	$signup->delete();
	$form = \plugins\MultiTenant\models\RegisterForm::create([], [ 'validateCurrentPassword' => false ]);
	$form->assign([ 'account_name' => 'Co', 'firstname' => 'A', 'lastname' => 'B', 'new_password' => 'weak', 'cfm_password' => 'other' ]);
	$form->validates();
	$this->check('signup: the form refuses a weak password and a different confirmation', [ 'cfm_password', 'new_password' ], array_values(array_intersect([ 'cfm_password', 'new_password' ], array_keys($form->errors()))));
    },
];
