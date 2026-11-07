<?php

/*
    what the AI steps with the account's own keys spent, per account and calendar month (UTC): one document `<aid>:<YYYY-MM>`, the estimated cost (USD, from the
    tokens: util\AgentGuard), the tokens, the calls. Checked against the account's monthly AI budget before each call to a model (models\agent\AgentPolicy budget); the System AI is paid in credits, not
    counted here. Written with an atomic upsert ($inc), as models\assistant\AssistantUsage: steps of an account run at the same time, so a budget may be passed by the calls under way
*/

namespace models\agent;

class AgentUsage extends \sys\BaseModel {
    protected static $collection = 'agent_usage';
    public static $indexes = [
	[ [ 'aid' => 1, 'month' => -1 ], [] ],
    ];

    public function getSchema() {
	return array_merge([
	    //_id: `<aid>:<YYYY-MM>`
	    [ 'key' => 'month', 'type' => 'text', 'label' => 'Month' ],
	    [ 'key' => 'cost', 'type' => 'number', 'label' => 'Cost (USD)' ],
	    [ 'key' => 'input_tokens', 'type' => 'number', 'label' => 'Input tokens' ],
	    [ 'key' => 'output_tokens', 'type' => 'number', 'label' => 'Output tokens' ],
	    [ 'key' => 'calls', 'type' => 'number', 'label' => 'Calls' ],
	], $this->getSystemSchema());
    }

    //the current month (UTC), as the documents' `month`
    public static function month() {
	return gmdate('Y-m');
    }

    //the estimated cost so far this month of account $aid (USD)
    public static function spent($aid) {
	$u = \V::app()->om->findOne('AgentUsage', [ '_id' => (string)$aid . ':' . self::month(), 'aid' => \V::app()->om->to_oid($aid) ]);
	return (float)($u->cost ?? 0);
    }

    //one call to a model of account $aid: its cost (USD), its tokens [ input, output ]
    public static function add($aid, $cost, array $tokens) {
	$month = self::month();
	\V::app()->om->updateOne('AgentUsage', [ '_id' => (string)$aid . ':' . $month, 'aid' => \V::app()->om->to_oid($aid) ], [
	    '$inc' => [ 'cost' => (float)$cost, 'input_tokens' => (int)($tokens[0] ?? 0), 'output_tokens' => (int)($tokens[1] ?? 0), 'calls' => 1 ],
	    '$set' => [ 'month' => $month ],
	], [ 'upsert' => true ]);
    }
}
