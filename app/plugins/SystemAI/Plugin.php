<?php

/*
    the System AI provider: the model server this installation offers to every account (app.env SYSTEM_AI_*: config ai.system), paid in
    credits (as a prepaid SIM: a monthly allowance without rollover, top-ups that last 12 months). Was the Assistant's System provider; now used by
	the Assistant (core: models\AssistantSettings, provider System) and
	the AI steps of the same provider (Claude, OpenAI, Gemini, Ollama, Lemonade: Credential System AI; models\node\NodeAppAi, hook ai:system-provider).
    Enabled in app/plugins.php; without it the Assistant (core) offers no System provider.
      util/SystemAi.php                  the System AI (config), the known prices, a step's credential
      util/StepSession.php               an AI step's run: credits reserved, counted, settled
      models/SystemAiWallet.php          an account's credits (was plugins\Assistant\models\AssistantWallet)
      models/SystemAiLedger.php          their changes (was plugins\Assistant\models\AssistantCreditLedger)
      credit_rates.php                   the credits per model and the margin (credit_rates.local.php: the installation's own)
      controllers/CreditsController      /SystemAI/credits/index: the account's credits (Settings menu: AI credits)
      controllers/AdminController        the administration (the Admin plugin's account page): the plan, a top-up
      commands/CreditsCommand.php        ./v SystemAI::credits::<show | add | plan | rates>
*/

namespace plugins\SystemAI;

class Plugin {

    public function __construct() {
	\V::app()->om->registerObjects([
	    'SystemAiWallet' => [
		'class' => __NAMESPACE__ . '\\models\\SystemAiWallet',
	    ],
	    'SystemAiLedger' => [
		'class' => __NAMESPACE__ . '\\models\\SystemAiLedger',
	    ],
	]);

	//the System AI for the AI steps of its provider (models\node\NodeAppAi::systemProvider): also in the console (the daemon runs the executions)
	\V::app()->hooks->addHook('ai:system-provider', function(\ArrayObject $box, $aid) {
	    $system = util\SystemAi::config();
	    if ($system === null)
		return;
	    $box['provider'] = [
		'provider' => $system->provider,
		'model' => $system->model,
		'label' => 'System AI (this installation: ' . $system->model . ', paid in credits)',
		'credential' => fn() => util\SystemAi::stepCredential($system),
		'start' => fn($model, array $info) => util\StepSession::start($aid, $system, $model, $info),
	    ];
	});

	//the administration (the Admin plugin): an account's credits (plan, top-ups, ledger: controllers/AdminController), the System AI and the credit rates; read across the
	//accounts (the super-admin's own account is not the one shown) (were the Assistant's)
	\V::app()->hooks->addHook('admin:account-summary', function($account, \ArrayObject $items) {
	    $w = \V::app()->om->acrossAccounts(fn() => models\SystemAiWallet::current($account->_id));
	    if ($w['v'] > 0)
		//CLD: 2026-10-24T16:30:00+03:00: util\Format::number (was number_format)
		$items[] = \util\Format::number(models\SystemAiWallet::balance($w)) . ' credits';
	});
	\V::app()->hooks->addHook('admin:account-panels', function($account) {
	    $data = \V::app()->om->acrossAccounts(fn() => [
		'account' => $account,
		'wallet' => models\SystemAiWallet::current($account->_id),
		'ledger' => iterator_to_array(models\SystemAiLedger::recent($account->_id, 10), false),
		'system' => util\SystemAi::config(),
	    ]);
	    echo \V::app()->view->renderFile('/plugins/SystemAI/views/admin/account_panel', $data);
	});
	//CLD: 2026-10-24T09:45:36+03:00: the tiers (util\Tiers): the credits used this month on the usage page; a new tier gives this month's allowance at once (when the account has no plan of its own)
	\V::app()->hooks->addHook('tiers:usage', function($aid, \ArrayObject $extra) {
	    if (!util\SystemAi::config())
		return;
	    $w = \V::app()->om->acrossAccounts(fn() => models\SystemAiWallet::current($aid));
	    $extra['ai_monthly_credits'] = [ (int)$w['month_used'], (int)$w['allowance'] ];
	});
	\V::app()->hooks->addHook('tiers:changed', function($account) {
	    \V::app()->om->acrossAccounts(function() use ($account) {
		if (models\SystemAiWallet::current($account->_id)['plan'] === null)
		    models\SystemAiWallet::setPlan($account->_id, null);
	    });
	});
	\V::app()->hooks->addHook('admin:installation-panels', function() {
	    echo \V::app()->view->renderFile('/plugins/SystemAI/views/admin/installation_panel', [ 'system' => util\SystemAi::config(),
		'table' => models\SystemAiWallet::rateTable() ]);
	});

	if (\V::app()->config('app.mode') != 'web')
	    return;

	//CLD: 2026-10-31T12:04:10+02:00: only when the System AI is configured (util\SystemAi::available: SYSTEM_AI_* in app.env)
	\V::app()->hooks->addHook('layout:settings-menu', function() {
	    if (\V::app()->user->can('Account::update') && util\SystemAi::available())
		echo '<a href="' . \V::app()->createUrl('/SystemAI/credits/index') . '" class="nav-link"><i class="bi bi-coin"></i> AI credits</a>';
	});
    }
}
