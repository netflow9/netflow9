<?php

/*
    the prices of AI models, for the cost of a request (USD per 1M tokens): the list prices are in data\AiProviders, owners only choose their budgets. The core's: the Assistant and the
    AI steps with the account's own key count their budgets with them (models\assistant\AssistantSettings::pricesOf, util\AgentGuard); the System AI (the MultiTenant plugin) its credits.
*/

namespace util;

class AiPrices {
    //a cache write is 1.25 x input
    const CACHE_WRITE = 1.25;

    //the suites' own models: [ model => [ input, output, cached input ] ]
    public static $test = [];

    //the prices for $settings (resolved: provider, model; the System AI's own price_input, price_output: SYSTEM_AI_PRICE_*): [ input, output, cached input ] USD per 1M tokens;
    //those given, else nothing for a local server, else the model's (data\AiProviders::PRICES); null: not known (it runs, not counted)
    public static function of($settings) {
	$in = (float)($settings->price_input ?? 0);
	$out = (float)($settings->price_output ?? 0);
	$known = self::known((string)($settings->model ?? ''));
	if ($in > 0 || $out > 0)
	    return [ $in, $out, $known && $known[0] == $in ? $known[2] : $in * 0.1 ];
	if (\data\AiProviders::isLocal((string)($settings->provider ?? '')))
	    return [ 0, 0, 0 ];
	return $known;
    }

    //the list price of model $model, whichever provider serves it (a proxy too); the longest id that matches, whole or followed by `-`:
    //gpt-5 is also gpt-5-2025-08-07, but gpt-5.9 is not known
    private static function known($model) {
	$model = preg_replace('~^models/~', '', trim($model));
	$prices = array_merge(self::$test, ...array_values(\data\AiProviders::PRICES));
	$ids = array_keys($prices);
	usort($ids, fn($a, $b) => strlen($b) <=> strlen($a));
	foreach($ids as $id) {
	    if ($model === $id || str_starts_with($model, $id . '-'))
		return $prices[$id];
	}
	return null;
    }
}
