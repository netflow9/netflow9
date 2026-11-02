<?php

/*
    CLD: 2026-10-31T22:00:00+02:00: new: the known prices of AI models, for the cost of a request (USD per 1M tokens). The core's: the Assistant with the account's own key counts its
    monthly budget with them (models\AssistantSettings::pricesOf); the System AI (the MultiTenant plugin) its credits. Were plugins\MultiTenant\util\AiPrices::PRICES,
    CACHE_WRITE, LOCAL, pricesOf (before: plugins\Assistant\models\AssistantSettings)
*/

namespace util;

class AiPrices {
    //the prices of Claude models (Anthropic API, USD per 1M tokens: input, output, cache read), by model id prefix (the first that matches; longer ones first).
    //A cache write is 1.25 x input. Other providers' prices change too often to be kept here: they are set (SYSTEM_AI_PRICE_*, the Assistant's settings: price_input, price_output)
    //(were plugins\Assistant\models\AssistantSettings::PRICES, CACHE_WRITE)
    const PRICES = [
	'claude-fable-5' => [ 10.00, 50.00, 0.25 ],
	'claude-mythos-5' => [ 10.00, 50.00, 0.25 ],
	'claude-opus-5-5' => [ 4.00, 20.00, 0.20 ],
	'claude-opus-5' => [ 5.00, 25.00, 0.50 ],
	'claude-opus-4' => [ 5.00, 25.00, 0.50 ],
	'claude-sonnet-5-5' => [ 2.00, 10.00, 0.20 ],
	'claude-sonnet-5' => [ 2.00, 10.00, 0.20 ],
	'claude-sonnet-4' => [ 3.00, 15.00, 0.30 ],
	'claude-haiku-5-5' => [ 0.10, 0.50, 0.01 ],
	'claude-haiku-4-5' => [ 1.00, 5.00, 0.10 ],
    ];
    const CACHE_WRITE = 1.25;

    //the prices for $settings (resolved: provider, model, price_input, price_output): [ input, output, cache read ] USD per 1M tokens; the settings' when given, else the known
    //ones of the model (PRICES), else nothing for a local server; null: not known (was plugins\Assistant\models\AssistantSettings::pricesOf)
    public static function of($settings) {
	$in = (float)($settings->price_input ?? 0);
	$out = (float)($settings->price_output ?? 0);
	$model = (string)($settings->model ?? '');
	$known = null;
	foreach(self::PRICES as $prefix => $p) {
	    if (str_starts_with($model, $prefix)) {
		$known = $p;
		break;
	    }
	}
	if ($in > 0 || $out > 0)
	    return [ $in, $out, $known && $known[0] == $in ? $known[2] : $in * 0.1 ];
	if ($known)
	    return $known;
	if (\data\AiProviders::isLocal((string)($settings->provider ?? '')))
	    return [ 0, 0, 0 ];
	return null;
    }
}
