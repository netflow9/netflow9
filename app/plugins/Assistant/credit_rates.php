<?php

/*
    CLD: 2026-10-22T15:00:00+03:00: new: what the System provider's models cost in credits (AssistantWallet::ratesOf). Read once per request; credit_rates.local.php, when
    present, is read instead (an installation's own rates, not overwritten by updates).

    credit_usd      what 1 credit is worth when sold (USD): top-ups are priced in it, the margins are counted against it
    default_margin  the margin over the provider's price for the models not listed
    models          by model id prefix (the first that matches; longer ones first):
			margin         over the provider's price (0.30 = 30%): the credits are counted from the price (AssistantSettings::PRICES, or ASSISTANT_PRICE_*)
			in, out, cache_read   or the credits per 1M tokens themselves (when the provider's price is not known, or to keep a model's credits fixed when it changes)
    A cache write is counted as 1.25 x in (as the providers charge it). `./v Assistant::credits::rates` shows the rates, their USD value and the margins
*/

return [
    'credit_usd' => 0.01,
    'default_margin' => 0.30,
    'models' => [
	//the cheap models: a higher share, the amounts are tiny
	'claude-haiku-5-5' => [ 'margin' => 0.50 ],
	'claude-haiku-4-5' => [ 'margin' => 0.50 ],
	'claude-sonnet-5-5' => [ 'margin' => 0.30 ],
	'claude-sonnet-5' => [ 'margin' => 0.30 ],
	'claude-sonnet-4' => [ 'margin' => 0.30 ],
	'claude-opus-5-5' => [ 'margin' => 0.25 ],
	'claude-opus-5' => [ 'margin' => 0.25 ],
	'claude-opus-4' => [ 'margin' => 0.25 ],
	//the most expensive: a lower share keeps them affordable
	'claude-fable-5' => [ 'margin' => 0.20 ],
	'claude-mythos-5' => [ 'margin' => 0.20 ],
	//e.g. a model whose price is not known here:
	//'gpt-5' => [ 'in' => 160, 'out' => 1300, 'cache_read' => 16 ],
    ],
];
