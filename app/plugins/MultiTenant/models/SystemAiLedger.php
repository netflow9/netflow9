<?php

/*
    every change of an account's System credits (AssistantWallet):
	the monthly allowance, a top-up, an expiry, a request's debit, a plan change;
	with the balance after it and, for a request, the workflow, the person, the tokens and the provider's cost. Written by AssistantWallet only; shown on the settings page
    the System AI's, written by
	SystemAiWallet; + source (who spent: the Assistant, an AI step) and, for a step, its execution (exid)
*/

namespace plugins\MultiTenant\models;

class SystemAiLedger extends \sys\BaseModel {
    protected static $collection = 'systemai_ledger';
    public static $indexes = [
	[ [ 'aid' => 1, 'created_at' => -1 ], [] ],
    ];

    const TYPES = [
	'allowance' => 'Monthly allowance',
	'topup' => 'Top-up',
	'expire' => 'Expired',
	'debit' => 'Used',
	'adjust' => 'Plan changed'
    ];
    const SOURCES = [
	'assistant' => 'Assistant request',
	'step' => 'AI step'
    ];
    const FIELDS = [ 'type', 'credits', 'balance', 'note', 'source', 'wfid', 'exid', 'uid', 'model', 'input_tokens', 'output_tokens', 'cost_usd' ];

    public function getSchema() {
	return array_merge([
	    [ 'key' => 'type', 'type' => 'select', 'label' => 'Type', 'choices' => self::TYPES ],
	    [ 'key' => 'credits', 'type' => 'number', 'label' => 'Credits' ],
	    [ 'key' => 'balance', 'type' => 'number', 'label' => 'Balance' ],
	    [ 'key' => 'note', 'type' => 'text', 'label' => 'Note' ],
	    [ 'key' => 'source', 'type' => 'select', 'label' => 'Used by', 'choices' => self::SOURCES ],
	    [ 'key' => 'wfid', 'type' => 'text', 'label' => 'Workflow' ],
	    [ 'key' => 'exid', 'type' => 'text', 'label' => 'Execution' ],
	    [ 'key' => 'model', 'type' => 'text', 'label' => 'Model' ],
	    [ 'key' => 'uid', 'type' => 'text', 'label' => 'User' ],
	    [ 'key' => 'input_tokens', 'type' => 'number', 'label' => 'Input tokens' ],
	    [ 'key' => 'output_tokens', 'type' => 'number', 'label' => 'Output tokens' ],
	    [ 'key' => 'cost_usd', 'type' => 'number', 'label' => 'Cost (USD)' ],
	], $this->getSystemSchema());
    }

    //an entry of account $aid: $e = { type, credits, balance, note, wfid?, uid?, input_tokens?, output_tokens?, cost_usd? }
    //CLD: 2026-10-23T10:00:00+03:00: + source?, exid?, model? (FIELDS)
    public static function add($aid, array $e) {
	$m = \V::app()->om->create('SystemAiLedger', [ 'aid' => \V::app()->om->to_oid($aid) ]);
	$m->assign(array_intersect_key($e, array_flip(self::FIELDS)));
	if (!$m->save())
	    \V::app()->log->error('SystemAiLedger: not saved: ' . json_encode($m->errors()));
    }

    //the last $n entries of account $aid, the newest first
    public static function recent($aid, $n = 10) {
	return \V::app()->om->find('SystemAiLedger', [ 'aid' => \V::app()->om->to_oid($aid) ], [ 'sort' => [ 'created_at' => -1 ], 'limit' => $n ]);
    }
}
