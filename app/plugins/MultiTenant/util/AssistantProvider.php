<?php

/*
    CLD: 2026-10-31T22:00:00+02:00: new: the System AI as the Assistant's System provider (the core's util\AssistantSystem: hook assistant:system-provider, Plugin), paid in
    credits (models\SystemAiWallet). Was done by the core itself (controllers\AssistantController, util\WorkflowAssistant, views/assistant/settings.php used SystemAi and
    SystemAiWallet): now the core runs without this plugin
*/

namespace plugins\MultiTenant\util;

use plugins\MultiTenant\models\SystemAiWallet;

class AssistantProvider {

    //the System AI (config ai.system), null when it is not configured
    public function config() {
	return SystemAi::config();
    }

    //the credit rates of the resolved settings (util\WorkflowAssistant::resolve); null: not priced (credit_rates.php)
    public function rates($resolved) {
	return SystemAiWallet::ratesOf($resolved);
    }

    //the credits a turn's usage (tokens) costs at $rates
    public function credits($rates, array $usage) {
	return SystemAiWallet::creditsOf($rates, $usage);
    }

    //credits reserved for a request of account $aid (at most the installation's per-request limit, within the account's monthly limit $cap): [ hold, credits left ], null when
    //none are left; \ErrorException when they cannot be reserved
    public function reserve($aid, $cap) {
	return SystemAiWallet::reserve($aid, SystemAi::maxCreditsPerRequest(), $cap);
    }

    //reservation $hold of account $aid settled with what $assistant (util\WorkflowAssistant; null: no model answered, the reservation is freed) used for workflow $wfid
    //=> [ credits charged, credits left (null: unknown) ] (was controllers\AssistantController::settle)
    public function settle($aid, $hold, $assistant, $wfid) {
	$used = $assistant !== null && array_sum($assistant->usage) ? SystemAiWallet::charge($assistant->credits) : 0;
	try {
	    $left = SystemAiWallet::settle($aid, $hold[0], $used, $used ? [
		'source' => 'assistant', 'model' => (string)(SystemAi::config()->model ?? ''), 'wfid' => (string)$wfid, 'uid' => (string)\V::app()->user->_id, 'cost_usd' => round($assistant->cost, 6),
		'input_tokens' => (int)$assistant->usage['input_tokens'] + (int)$assistant->usage['cache_read_input_tokens'] + (int)$assistant->usage['cache_creation_input_tokens'],
		'output_tokens' => (int)$assistant->usage['output_tokens'] ] : []);
	} catch (\Exception $e) {
	    //the reservation frees itself (SystemAiWallet::HOLD_LEASE); the credits are not taken
	    \V::app()->log->error("Assistant: {$used} credits of account {$aid} not taken: " . $e->getMessage());
	    $left = null;
	}
	return [ $used, $left ];
    }

    //the System part of the Assistant's settings page of $settings (views/assistant/_settings_system.php)
    public function settingsHtml($settings) {
	$system = SystemAi::config();
	if ($system === null)
	    return null;
	return \V::app()->view->renderFile('/plugins/MultiTenant/views/assistant/_settings_system', [ 'settings' => $settings, 'system' => $system,
	    'wallet' => SystemAiWallet::current($settings->aid), 'rates' => SystemAiWallet::ratesOf($system) ]);
    }
}
