<?php

/*
    CLD: 2026-10-31T22:00:00+02:00: new: the Assistant's System provider, as a plugin offers it (the MultiTenant plugin's System AI): the core asks only this, so it runs without
    the plugin (then there is no System provider). Hook assistant:system-provider ($box: \ArrayObject): $box['provider'] = an object with
	config()                                  the System AI: { provider, url, api_key, model, effort, price_input, price_output }; null: not configured
	rates($resolved)                          its credit rates for the resolved settings (WorkflowAssistant::resolve); null: not priced
	credits($rates, array $usage)             the credits a turn's usage (tokens) costs
	reserve($aid, $cap)                       credits reserved for a request: [ hold, credits left ], null when none are left (within the account's monthly limit $cap);
	                                          \ErrorException when it cannot
	settle($aid, $hold, $assistant, $wfid)    the reservation settled with what $assistant (util\WorkflowAssistant) used => [ credits charged, credits left (null: unknown) ]
	settingsHtml($settings)                   the System part of the Assistant's settings page (the model, the credits, the account's monthly limit)
*/

namespace util;

class AssistantSystem {

    //the check suite's own provider (an object, or null: none), used instead of the hook's while it is not false
    public static $override = false;

    //the provider object, or null when no plugin offers one (asked each time: a plugin may turn it on or off, e.g. the check suite)
    public static function provider() {
	if (self::$override !== false)
	    return self::$override;
	$box = new \ArrayObject();
	\V::app()->hooks->applyHook('assistant:system-provider', [ $box ]);
	return $box['provider'] ?? null;
    }
}
