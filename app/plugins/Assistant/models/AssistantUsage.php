<?php

/*
    CLD: 2026-10-22T14:00:00+03:00: new: what the Assistant spent, per account and calendar month (UTC): one document `<aid>:<YYYY-MM>`, with the cost (USD, counted with the
    settings' prices: AssistantSettings::pricesOf), the tokens and the requests. Checked against the monthly budget before each request (AssistantController::ask) and by the
    agent loop (WorkflowAssistant::$budget); added to after each request, also a failed one (the tokens were spent).
    Written with an atomic upsert ($inc), not with save(): requests of the same account may end at the same time
*/

namespace plugins\Assistant\models;

class AssistantUsage extends \sys\BaseModel {
    protected static $collection = 'assistant_usage';
    public static $indexes = [
	[ [ 'aid' => 1, 'month' => -1 ], [] ],
    ];

    public function getSchema() {
	return array_merge([
	    //_id: `<aid>:<YYYY-MM>`
	    [
		'key' => 'month',
		'type' => 'text',
		'label' => 'Month',
	    ],
	    [
		'key' => 'cost',
		'type' => 'number',
		'label' => 'Cost (USD)',
	    ],
	    [
		'key' => 'input_tokens',
		'type' => 'number',
		'label' => 'Input tokens',
	    ],
	    [
		'key' => 'output_tokens',
		'type' => 'number',
		'label' => 'Output tokens',
	    ],
	    [
		'key' => 'requests',
		'type' => 'number',
		'label' => 'Requests',
	    ],
	], $this->getSystemSchema());
    }

    //the current month (UTC), as the documents' `month`
    public static function month() {
	return gmdate('Y-m');
    }

    //what account $aid spent this month: { cost, input_tokens, output_tokens, requests }
    public static function current($aid) {
	$u = \V::app()->om->findOne('AssistantUsage', [ '_id' => (string)$aid . ':' . self::month(), 'aid' => \V::app()->om->to_oid($aid) ]);
	return (object)[
	    'cost' => (float)($u->cost ?? 0),
	    'input_tokens' => (int)($u->input_tokens ?? 0),
	    'output_tokens' => (int)($u->output_tokens ?? 0),
	    'requests' => (int)($u->requests ?? 0),
	];
    }

    //adds one request of account $aid: $cost (USD), $usage (the tokens: WorkflowAssistant::ask()'s usage)
    public static function add($aid, $cost, array $usage) {
	$input = (int)($usage['input_tokens'] ?? 0) + (int)($usage['cache_read_input_tokens'] ?? 0) + (int)($usage['cache_creation_input_tokens'] ?? 0);
	$month = self::month();
	\V::app()->om->updateOne('AssistantUsage', [ '_id' => (string)$aid . ':' . $month, 'aid' => \V::app()->om->to_oid($aid) ], [
	    '$inc' => [ 'cost' => (float)$cost, 'input_tokens' => $input, 'output_tokens' => (int)($usage['output_tokens'] ?? 0), 'requests' => 1 ],
	    '$set' => [ 'month' => $month ],
	], [ 'upsert' => true ]);
    }
}
