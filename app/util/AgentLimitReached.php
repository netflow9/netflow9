<?php

/*
    an AI step's agent reached a limit (util\AgentGuard): the step stops with this message, on its On error path, and its variable keeps what the
    agent did so far (models\node\NodeAppAi::execute: tool_calls, usage, rounds). $limit: tool_calls, write_calls, cost or budget
*/

namespace util;

class AgentLimitReached extends \ErrorException {
    public $limit;

    public function __construct($limit, $message) {
	parent::__construct($message);
	$this->limit = $limit;
    }
}
