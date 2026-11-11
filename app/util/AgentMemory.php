<?php

/*
    what an AI step remembers. The step's settings of the conversation (section Conversation: Continue the conversation, how much of it, summarizing; used by NodeAppAi::addConversation)
	and the long-term memory (section Memory): two tools over a data table or a knowledge base the step names:
	    remember { fact }        saves a fact (it changes something: limits, approval by the step's setting, the policy or a lockdown, the audit log, as every tool: NodeAppAi's loop)
	    recall { query, limit }  searches what was saved
	Whose memories (setting Whose memories, kept with each fact): each person (a signed-in user, a sender's address, a Teams person; a guest: the conversation), each conversation, or everyone who talks to the agent.
	What is saved stays a row of the table or a document of the knowledge base: who can see and delete memories follows their permissions (Data tables, Knowledge bases). What comes back is a tool's result:
	marked as untrusted, as any other.
	A table needs a text column `fact` and, unless everyone shares the memories, a text column `scope`; when it has them, also `conversation` and `source` (text) are filled
*/

namespace util;

class AgentMemory {
    const SECTION_CONVERSATION = 'Conversation';
    const SECTION_MEMORY = 'Long-term memory';
    const STORES = [ '' => 'None', 'table' => 'A data table', 'knowledge' => 'A knowledge base' ];
    const SCOPES = [ 'user' => 'Each person\'s own', 'conversation' => 'Each conversation\'s own', 'agent' => 'Shared by everyone' ];
    const REMEMBER = 'remember';
    const RECALL = 'recall';
    const MAX_FACT = 2000;
    const RECALL_DEFAULT = 5;
    const RECALL_MAX = 20;
    //the newest rows of a table a recall looks through; the words of a query it looks for
    const SCAN = 1000;
    const MAX_WORDS = 12;

    //the settings
    public static function fields($aid, $project) {
	$conv = [ 'section' => self::SECTION_CONVERSATION ];
	$mem = [ 'section' => self::SECTION_MEMORY ];
	$on = [ 'showIf' => [ 'key' => 'continueConversation', 'operator' => 'eq', 'value' => true ] ];
	$tables = [];
	if ($aid && $project)
	    foreach(\V::app()->om->find('DataTableDef', [ 'aid' => $aid, 'project' => \V::app()->om->to_oid($project) ], [ 'sort' => [ 'name' => 1 ] ]) as $t)
		$tables[(string)$t->_id] = (string)$t->name;
	return [
	    [
		'key' => 'continueConversation',
		'type' => 'switch',
		'label' => 'Continue the conversation',
		'default' => false,
		'help' => 'In a run started by a chat message: the earlier turns (messages, answers, tools used) go to the model',
		'ui' => $conv + [ 'width' => 12 ],
	    ],
	    [
		'key' => 'memoryTurns',
		'type' => 'number',
		'label' => 'Turns',
		'required' => true,
		'default' => 20,
		'help' => 'The newest turns sent',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => 200, 'skipEmpty' => true, 'message' => 'from 1 to 200' ] ],
		'ui' => $conv + $on + [ 'width' => 4, 'newRow' => true ],
	    ],
	    [
		'key' => 'memoryTokens',
		'type' => 'number',
		'label' => 'Tokens',
		'required' => true,
		'default' => 8000,
		'help' => 'At most, estimated (4 characters each)',
		'validate' => [ [ 'inRange', 'lower' => 100, 'upper' => 500000, 'skipEmpty' => true, 'message' => 'from 100 to 500000' ] ],
		'ui' => $conv + $on + [ 'width' => 4 ],
	    ],
	    [
		'key' => 'summarizeOlder',
		'type' => 'switch',
		'label' => 'Summarize older turns',
		'default' => true,
		'help' => 'The model summarizes what no longer fits; it costs a call. Off: dropped',
		'ui' => $conv + $on + [ 'width' => 4 ],
	    ],
	    [
		'key' => 'memoryStore',
		'type' => 'select',
		'label' => 'Remember in',
		'default' => '',
		'choices' => self::STORES,
		'help' => 'Gives the agent the tools remember and recall',
		'ui' => $mem + [ 'width' => 4 ],
	    ],
	    [
		'key' => 'memoryTable',
		'type' => 'select',
		'label' => 'Data table',
		'default' => '',
		'choices' => $tables,
		'help' => 'With text columns `fact` and `scope`',
		'ui' => $mem + [ 'width' => 4, 'showIf' => [ 'key' => 'memoryStore', 'operator' => 'eq', 'value' => 'table' ] ],
	    ],
	    [
		'key' => 'memoryKb',
		'type' => 'select',
		'label' => 'Knowledge base',
		'default' => '',
		'choices' => \models\kb\KnowledgeBase::namesFor($aid, $project),
		'ui' => $mem + [ 'width' => 4, 'showIf' => [ 'key' => 'memoryStore', 'operator' => 'eq', 'value' => 'knowledge' ] ],
	    ],
	    [
		'key' => 'memoryScope',
		'type' => 'select',
		'label' => 'Whose memories',
		'default' => 'user',
		'choices' => self::SCOPES,
		'help' => 'What recall may find',
		'ui' => $mem + [ 'width' => 4, 'showIf' => [ 'key' => 'memoryStore', 'operator' => 'ne', 'value' => '' ] ],
	    ],
	    [
		'key' => 'memoryApproval',
		'type' => 'switch',
		'label' => 'Remember asks for approval',
		'default' => false,
		'help' => 'A person approves each fact (Approvals: who)',
		'ui' => $mem + [ 'width' => 6, 'newRow' => true, 'showIf' => [ 'key' => 'memoryStore', 'operator' => 'ne', 'value' => '' ] ],
	    ],
	];
    }

    //the problems of the settings: [ message ]
    public static function errors($step) {
	$store = (string)$step->cfg('memoryStore', '');
	$wf = $step->workflow;
	if ($store === '' || !$wf)
	    return [];
	$errors = [];
	$scope = (string)$step->cfg('memoryScope', 'user');
	if ($store === 'table') {
	    $table = (string)$step->cfg('memoryTable', '') === '' ? null : \models\node\NodeAppTable::findTable((string)$step->cfg('memoryTable'), $wf->aid, $wf->project, true);
	    if (!$table) {
		$errors[] = 'Long-term memory: choose a data table of this project';
	    } else {
		$columns = $table->columns();
		foreach($scope === 'agent' ? [ 'fact' ] : [ 'fact', 'scope' ] as $c)
		    if (!isset($columns[$c]))
			$errors[] = "Long-term memory: the table `{$table->name}` needs a text column `{$c}`";
	    }
	} elseif ((string)$step->cfg('memoryKb', '') === '' || !\models\kb\KnowledgeBase::named((string)$step->cfg('memoryKb'), $wf->aid, $wf->project)) {
	    $errors[] = 'Long-term memory: choose a knowledge base of this project';
	}
	if (!\models\agent\AgentPolicy::ofAccount($wf->aid)->writeAllowed(self::REMEMBER, 'memory'))
	    $errors[] = 'Long-term memory: agents may not use `remember` (it changes something), by the account\'s AI policy';
	return $errors;
    }

    //whose memories run $ai reads and writes: the text kept with each fact; '' when everyone shares them
    public static function scopeKey($ai) {
	$exec = $ai->exec;
	$scope = (string)$ai->cfg('memoryScope', 'user');
	if ($scope === 'agent')
	    return '';
	$at = \models\agent\AgentConversation::ofExecution($exec);
	$conv = $at ? (string)$at[0]['_id'] : 'run-' . (string)($exec->_id ?? '');
	if ($scope === 'conversation')
	    return 'c:' . $conv;
	$input = is_array($exec->input) ? $exec->input : [];
	$who = (string)($input['who'] ?? '');
	return $who !== '' ? $who : ($at && (string)($at[0]['who'] ?? '') !== '' ? (string)$at[0]['who'] : 'c:' . $conv);
    }

    //the tools of AI step $ai (running): [ remember, recall ]; none when it remembers nowhere. \ErrorException when its store is gone
    public static function tools($ai, $project, $timeout) {
	$store = (string)$ai->cfg('memoryStore', '');
	if ($store === '')
	    return [];
	$scope = self::scopeKey($ai);
	$at = \models\agent\AgentConversation::ofExecution($ai->exec);
	$conv = $at ? (string)$at[0]['_id'] : '';
	if ($store === 'table') {
	    $table = \models\node\NodeAppTable::findTable((string)$ai->cfg('memoryTable', ''), $project->aid, $project->_id, true);
	    if (!$table)
		throw new \ErrorException('the data table of Long-term memory is no longer in the project');
	    $on = "data table {$table->name}";
	    $mem = [ 'table' => $table, 'scope' => $scope, 'conv' => $conv, 'ai' => $ai ];
	} else {
	    $kb = \models\kb\KnowledgeBase::named((string)$ai->cfg('memoryKb', ''), $project->aid, $project->_id);
	    if (!$kb)
		throw new \ErrorException('the knowledge base of Long-term memory is no longer in the project');
	    $on = "knowledge base {$kb->name}";
	    $mem = [ 'kb' => $kb, 'scope' => $scope, 'conv' => $conv, 'timeout' => $timeout ];
	}
	return [
	    self::REMEMBER => [ 'name' => self::REMEMBER, 'kind' => 'memory', 'writes' => true, 'title' => 'Remember', 'ask' => AgentGuard::on($ai->cfg('memoryApproval', false)),
		'askMeta' => [ 'what' => 'Remember', 'on' => $on, 'label' => 'Long-term memory', 'main' => [ 'fact' ] ],
		'description' => 'Saves a fact for later conversations: one short sentence that stands on its own (who, what). Save what will still matter later: not what is only about this message, and never passwords or keys.',
		'schema' => json_decode(JSON::encode([ 'type' => 'object', 'properties' => [ 'fact' => [ 'type' => 'string', 'description' => 'The fact, at most ' . self::MAX_FACT . ' characters' ] ], 'required' => [ 'fact' ] ])),
		'check' => fn(array $args) => self::factError($args),
		'run' => function(array $args) use ($mem) {
		    $wrong = self::factError($args);
		    if ($wrong !== null)
			return [ $wrong, true, [] ];
		    try {
			return [ self::toJson([ 'saved' => true, 'id' => self::saveFact($mem, trim((string)$args['fact'])) ]), false, [] ];
		    } catch (\ErrorException | \util\LimitException $e) {
			return [ $e->getMessage(), true, [] ];
		    }
		} ],
	    self::RECALL => [ 'name' => self::RECALL, 'kind' => 'memory', 'writes' => false, 'title' => 'Recall',
		'description' => 'Searches the facts saved with remember in earlier conversations. Use it when the person refers to something from before, or when what you know of them would help.',
		'schema' => json_decode(JSON::encode([ 'type' => 'object', 'properties' => [ 'query' => [ 'type' => 'string', 'description' => 'What to look for: keywords, in the language of the facts' ],
		    'limit' => [ 'type' => 'integer', 'description' => 'The most facts to return (default ' . self::RECALL_DEFAULT . ', at most ' . self::RECALL_MAX . ')' ] ], 'required' => [ 'query' ] ])),
		'run' => function(array $args) use ($mem) {
		    $query = trim(Expression::toText($args['query'] ?? ''));
		    if ($query === '')
			return [ 'query: what to look for', true, [] ];
		    try {
			return [ self::toJson([ 'facts' => self::findFacts($mem, $query, max(1, min(self::RECALL_MAX, (int)($args['limit'] ?? self::RECALL_DEFAULT)))) ]), false, [] ];
		    } catch (\ErrorException $e) {
			return [ $e->getMessage(), true, [] ];
		    }
		} ],
	];
    }

    //fact $fact saved in the memory $mem of tools() (a data table's row, or a knowledge base's document): its id
    private static function saveFact(array $mem, $fact) {
	return isset($mem['table']) ? self::saveRow($mem['table'], $fact, $mem['scope'], $mem['conv'], $mem['ai'])
	    : self::saveDocument($mem['kb'], $fact, $mem['scope'], $mem['conv'], $mem['timeout']);
    }

    //the facts of memory $mem (tools()) matching $query, at most $limit
    private static function findFacts(array $mem, $query, $limit) {
	return isset($mem['table']) ? self::findRows($mem['table'], $query, $limit, $mem['scope'])
	    : self::findDocuments($mem['kb'], $query, $limit, $mem['scope'], $mem['timeout']);
    }

    //$v as JSON for the model (slashes and Unicode as they are, invalid UTF-8 replaced)
    private static function toJson($v) {
	return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function factError(array $args) {
	$fact = $args['fact'] ?? null;
	if (!is_string($fact) || trim($fact) === '')
	    return 'fact: the fact to save, as text';
	return mb_strlen($fact) > self::MAX_FACT ? 'fact: at most ' . self::MAX_FACT . ' characters' : null;
    }

    //a fact as a row => its id. The tier's limit of rows holds (util\Tiers), the table's own column rules too
    private static function saveRow($table, $fact, $scope, $conv, $ai) {
	$columns = $table->columns();
	if (!isset($columns['fact']))
	    throw new \ErrorException("the table `{$table->name}` no longer has a column `fact`");
	$values = [ 'fact' => $fact ];
	if ($scope !== '') {
	    if (!isset($columns['scope']))
		throw new \ErrorException("the table `{$table->name}` no longer has a column `scope`");
	    $values['scope'] = $scope;
	}
	if (isset($columns['conversation']))
	    $values['conversation'] = $conv;
	if (isset($columns['source']))
	    $values['source'] = (string)($ai->workflow->name ?? $ai->exec->wf ?? '');
	$row = \V::app()->om->create($table->iname, [ 'aid' => $table->aid ]);
	\models\datatable\DataTableDef::assignRow($row, $values);
	Tiers::check($table->aid, 'datatable_records', 1, $table->iname);
	$row->save();
	return (string)$row->_id;
    }

    //the rows of $scope with the words of $query, those with most of them first, newest first among equals: [ { id, fact, saved_at } ]
    private static function findRows($table, $query, $limit, $scope) {
	$columns = $table->columns();
	if (!isset($columns['fact']))
	    throw new \ErrorException("the table `{$table->name}` no longer has a column `fact`");
	preg_match_all('/[\p{L}\p{N}]{2,}/u', mb_strtolower($query), $m);
	$words = array_slice(array_values(array_unique($m[0])), 0, self::MAX_WORDS);
	$filter = [ 'aid' => $table->aid ];
	if ($scope !== '') {
	    if (!isset($columns['scope']))
		throw new \ErrorException("the table `{$table->name}` no longer has a column `scope`");
	    $filter['scope'] = $scope;
	}
	if ($words)
	    $filter['$or'] = array_map(fn($w) => [ 'fact' => [ '$regex' => preg_quote($w), '$options' => 'i' ] ], $words);
	$scored = [];
	foreach(\V::app()->om->find($table->iname, $filter, [ 'sort' => [ '_id' => -1 ], 'limit' => self::SCAN ]) as $row) {
	    $text = mb_strtolower((string)$row->fact);
	    $hits = count(array_filter($words, fn($w) => str_contains($text, $w)));
	    $scored[] = [ $hits, [ 'id' => (string)$row->_id, 'fact' => (string)$row->fact, 'saved_at' => Expression::hostValue($row->created_at) ] ];
	}
	usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
	return array_column(array_slice($scored, 0, $limit), 1);
    }

    //a fact as a document, embedded now => its key. Marked `memory` in its metadata: recall does not read the other documents of the knowledge base
    private static function saveDocument($kb, $fact, $scope, $conv, $timeout) {
	$key = 'memory-' . bin2hex(random_bytes(8));
	$kb->addDocument($key, mb_substr($fact, 0, 80), $fact, [ 'memory' => true ] + ($scope !== '' ? [ 'scope' => $scope ] : []) + ($conv !== '' ? [ 'conversation' => $conv ] : []), [ 'type' => 'text' ], $timeout);
	return $key;
    }

    private static function findDocuments($kb, $query, $limit, $scope, $timeout) {
	return array_map(fn($r) => [ 'id' => (string)($r['document'] ?? ''), 'fact' => (string)$r['text'], 'score' => $r['score'] ?? null ],
	    $kb->search($query, $limit, [ 'memory' => true ] + ($scope !== '' ? [ 'scope' => $scope ] : []), null, $timeout));
    }
}
