<?php

/*
    CLD: 2026-10-30T23:30:00+02:00: new step: Knowledge base - documents a workflow adds to a knowledge base of the project (models\KnowledgeBase), searched by meaning and by words.
    Operations:
	- Search: the chunks that best answer a query: { results: [ { text, score, match, document, title, page, meta } ], count, context } - context: the results as one text for a
	  prompt ([n] title (page), then the text); a filter on the documents' metadata, a least similarity
	- Add files: project files (images left out: no text), their text (PDF, Office, text files; util\TextChunks cuts it, a PDF page by page); the document's key is the file id
	- Add a web page: its text (as Read a web page: GET, public addresses only unless HTTP_STEPS_ALLOW_PRIVATE); the key is the address
	- Add a text: a title and a text; the key is given, else the title
	  added: { knowledgeBase, created, documents: [ { key, title, status: added | replaced | unchanged, chunks } ], chunks }; a key already there is replaced (left as it is when
	  nothing changed); metadata (an object) kept with the document, for Search's filter
	- Remove a document: by its key or file id: { removed }
	- List the documents: { documents: [ { key, title, source, file, url, chunks, chars, meta, updated_at } ], count }
    The knowledge base: one of the project's, or a new name - made by the first Add.
    CLD: 2026-10-31T02:10:00+02:00: with the default chunk size / overlap (no longer settings of the step: a knowledge base's own, on the Knowledge bases page, under Advanced…)
    CLD: 2026-10-31T01:00:00+02:00: the embedding model is the account's (Settings: Knowledge bases; models\EmbeddingSettings): no Embedding credential / model here any more
    On error: fail the step, or continue on the `error` output (the variable then { error }).
*/

namespace models\node;

class NodeAppKnowledge extends \models\WfNode {
    public static $type = 'app';
    public static $category = 'ai';
    public static $name = 'knowledge';
    public static $label = 'Knowledge base';
    public static $icon = 'bi-journal-bookmark';
    public static $color = 'var(--wf-color-app)';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    const OPERATIONS = [
	'search' => 'Search',
	'addFiles' => 'Add files',
	'addUrl' => 'Add a web page',
	'addText' => 'Add a text',
	'remove' => 'Remove a document',
	'documents' => 'List the documents',
    ];
    const ADD = [ 'addFiles', 'addUrl', 'addText' ];
    const ON_ERROR = [ 'fail' => 'Fail the step', 'output' => 'Continue on the `error` output' ];
    const MAX_FILES = 50;
    const MAX_LIST = 1000;
    const DEFAULT_TIMEOUT = 120;
    const MAX_TIMEOUT = 900;

    public static function configSchema() {
	[ $aid, $project ] = static::designerProject();
	$names = [];
	foreach(\models\KnowledgeBase::namesFor($aid, $project) as $name)
	    $names[] = [ 'value' => $name, 'label' => $name ];
	$add = [ 'key' => 'operation', 'operator' => 'in', 'value' => self::ADD ];

	return [
	    [
		//what the step works on (`resource`): the designer puts it on line 2, before the operation
		'key' => 'knowledgeBase',
		'type' => 'text',
		'label' => 'Knowledge base',
		'required' => true,
		'transform' => 'trim',
		'help' => 'One of the project\'s, or type a new name',
		'validate' => [ [ 'regex', 'pattern' => '^.{1,' . \models\KnowledgeBase::MAX_NAME . '}$', 'skipEmpty' => true, 'message' => 'At most ' . \models\KnowledgeBase::MAX_NAME . ' characters' ] ],
		'ui' => [ 'resource' => true, 'width' => 6, 'type' => 'select', 'allowCustom' => true, 'choices' => $names, 'placeholder' => 'Choose or type a name...' ],
	    ],
	    [
		'key' => 'operation',
		'type' => 'select',
		'label' => 'Operation',
		'required' => true,
		'default' => 'search',
		'choices' => self::OPERATIONS,
	    ],
	    //Search
	    [
		'key' => 'query',
		'type' => 'text',
		'label' => 'Query',
		'help' => 'What to find, e.g. {{ mail.subject }}',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'search' ] ],
		'ui' => [ 'width' => 9, 'newRow' => true, 'expression' => true, 'showIf' => [ 'key' => 'operation', 'operator' => 'eq', 'value' => 'search' ] ],
	    ],
	    [
		'key' => 'results',
		'type' => 'number',
		'label' => 'Results',
		'required' => true,
		'default' => 5,
		'help' => 'The best chunks',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => \models\KnowledgeBase::MAX_RESULTS, 'skipEmpty' => true, 'message' => 'from 1 to ' . \models\KnowledgeBase::MAX_RESULTS ] ],
		'ui' => [ 'width' => 3, 'showIf' => [ 'key' => 'operation', 'operator' => 'eq', 'value' => 'search' ] ],
	    ],
	    [
		'key' => 'filter',
		'type' => 'text',
		'label' => 'Filter',
		'help' => 'Metadata the documents must have, e.g. {"category": "hr"}',
		'ui' => [ 'advanced' => true, 'width' => 9, 'expression' => true, 'showIf' => [ 'key' => 'operation', 'operator' => 'eq', 'value' => 'search' ] ],
	    ],
	    [
		'key' => 'minScore',
		'type' => 'text',
		'label' => 'Least similarity',
		'default' => '',
		'help' => '-1 to 1; empty: any',
		'validate' => [ [ 'regex', 'pattern' => '^-?(0(\.\d+)?|1(\.0+)?)$', 'skipEmpty' => true, 'message' => 'A number from -1 to 1' ] ],
		'ui' => [ 'advanced' => true, 'width' => 3, 'placeholder' => 'Any', 'showIf' => [ 'key' => 'operation', 'operator' => 'eq', 'value' => 'search' ] ],
	    ],
	    //Add
	    [
		'key' => 'files',
		'type' => 'text',
		'label' => 'Files',
		'help' => 'PDF, Office or text files (at most ' . self::MAX_FILES . '): {{ file.id }}, a list, or file ids',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'addFiles' ] ],
		'ui' => [ 'width' => 12, 'newRow' => true, 'expression' => true, 'showIf' => [ 'key' => 'operation', 'operator' => 'eq', 'value' => 'addFiles' ] ],
	    ],
	    [
		'key' => 'url',
		'type' => 'text',
		'label' => 'Page address',
		'help' => 'http:// or https://',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'addUrl' ] ],
		'ui' => [ 'width' => 12, 'newRow' => true, 'expression' => true, 'placeholder' => 'https://www.example.com/help', 'showIf' => [ 'key' => 'operation', 'operator' => 'eq', 'value' => 'addUrl' ] ],
	    ],
	    [
		'key' => 'title',
		'type' => 'text',
		'label' => 'Title',
		'help' => 'Shown with the results',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'addText' ] ],
		'ui' => [ 'width' => 8, 'newRow' => true, 'expression' => true, 'showIf' => [ 'key' => 'operation', 'operator' => 'eq', 'value' => 'addText' ] ],
	    ],
	    [
		'key' => 'docKey',
		'type' => 'text',
		'label' => 'Document key',
		'help' => 'Add a text: empty = the title; Remove: the key or file id',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'remove' ] ],
		'ui' => [ 'width' => 4, 'expression' => true, 'showIf' => [ 'key' => 'operation', 'operator' => 'in', 'value' => [ 'addText', 'remove' ] ] ],
	    ],
	    [
		'key' => 'text',
		'type' => 'textarea',
		'label' => 'Text',
		'help' => 'E.g. {{ page.content }}',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'addText' ] ],
		'ui' => [ 'rows' => 5, 'expression' => true, 'showIf' => [ 'key' => 'operation', 'operator' => 'eq', 'value' => 'addText' ] ],
	    ],
	    [
		'key' => 'meta',
		'type' => 'text',
		'label' => 'Metadata',
		'help' => 'An object kept with the documents, e.g. {"category": "hr"}',
		'ui' => [ 'advanced' => true, 'width' => 6, 'expression' => true, 'showIf' => $add ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Result variable',
		'required' => true,
		'default' => 'kb',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'variableName' => 'write' ],
	    ],
	    [
		'key' => 'onError',
		'type' => 'select',
		'label' => 'On error',
		'required' => true,
		'default' => 'fail',
		'choices' => self::ON_ERROR,
		'help' => 'Unreachable server, no text, unknown base',
	    ],
	    [
		'key' => 'timeout',
		'type' => 'number',
		'label' => 'Timeout (s)',
		'required' => true,
		'default' => self::DEFAULT_TIMEOUT,
		'help' => 'Per call to the server',
		'validate' => [ [ 'inRange', 'lower' => 5, 'upper' => self::MAX_TIMEOUT, 'skipEmpty' => true, 'message' => 'from 5 to ' . self::MAX_TIMEOUT ] ],
	    ],
	];
    }

    //the designer's account and project ([ null, null ] outside the designer)
    protected static function designerProject() {
	if (\V::app()->config('app.mode') != 'web' || \V::app()->user->isGuest || empty(\V::app()->user->crtProject))
	    return [ null, null ];
	return [ \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject ];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'kb') : 'kb';
	$op = is_array($config) ? (string)($config['operation'] ?? 'search') : 'search';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$str = [ 'type' => 'string' ];
	$num = [ 'type' => 'number' ];
	$meta = [ 'type' => 'object', 'info' => 'the document\'s metadata' ];
	$doc = [ 'type' => 'object', 'fields' => [ 'key' => $str, 'title' => $str, 'status' => [ 'type' => 'string', 'info' => 'added, replaced or unchanged' ], 'chunks' => $num ] ];
	$added = [ 'knowledgeBase' => $str, 'created' => [ 'type' => 'boolean', 'info' => 'the knowledge base was made by this step' ], 'documents' => [ 'type' => 'array', 'items' => $doc ],
	    'chunks' => [ 'type' => 'number', 'info' => 'the chunks of these documents' ] ];
	[ $shape, $fields ] = match($op) {
	    'addFiles', 'addUrl', 'addText' => [ '{ knowledgeBase, created, documents, chunks }', $added ],
	    'remove' => [ '{ removed }', [ 'removed' => [ 'type' => 'number', 'info' => 'the documents removed' ] ] ],
	    'documents' => [ '{ documents, count }', [ 'documents' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => [ 'key' => $str, 'title' => $str,
		'source' => [ 'type' => 'string', 'info' => 'file, url or text' ], 'file' => $str, 'url' => $str, 'chunks' => $num, 'chars' => $num, 'meta' => $meta,
		'updated_at' => [ 'type' => 'string', 'info' => 'ISO 8601' ] ] ] ], 'count' => $num ] ],
	    default => [ '{ results, count, context }', [ 'results' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => [ 'text' => $str,
		'score' => [ 'type' => 'number', 'info' => 'the similarity, -1 to 1' ], 'match' => [ 'type' => 'string', 'info' => 'both, meaning or words' ],
		'document' => [ 'type' => 'string', 'info' => 'the document\'s key' ], 'title' => $str, 'page' => [ 'type' => 'number', 'info' => 'PDFs' ], 'meta' => $meta ] ] ],
		'count' => $num, 'context' => [ 'type' => 'string', 'info' => 'the results as one text, for a prompt' ] ] ],
	};
	return [ $name => [ 'type' => 'object', 'info' => "the Knowledge base result: {$shape}", 'fields' => $fields + [ 'error' => [ 'type' => 'string', 'info' => 'on error only' ] ] ] ];
    }

    //CLD: 2026-10-31T01:00:00+02:00: validates() removed: it checked the Embedding credential (the embedding model is the account's)

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	$name = (string)$this->cfg('variable', 'kb');
	try {
	    $result = $this->run();
	} catch (\Exception $e) {
	    $message = $e->getMessage();
	    if (\util\Validator::isIdentifier($name))
		$this->exec->setVariable($name, [ 'error' => $message ]);
	    $this->exec->log($this->wfid, static::$label . ": {$message}");
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException(static::$label . ": {$message}");
	}
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $result);
	return [ 0 ];
    }

    protected function run() {
	$op = (string)$this->cfg('operation', 'search');
	$kbName = trim((string)$this->cfg('knowledgeBase', ''));
	$timeout = max(5, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', self::DEFAULT_TIMEOUT)));
	$aid = $this->exec->aid;
	$project = $this->exec->project;

	if (in_array($op, self::ADD, true)) {
	    //CLD: 2026-10-31T01:00:00+02:00: made with the account's embedding model (was the step's credential and model)
	    //CLD: 2026-10-31T02:10:00+02:00: and the default chunk size / overlap (were settings of the step: changed on the Knowledge bases page only)
	    [ $kb, $created ] = \models\KnowledgeBase::open($kbName, $aid, $project);
	    if ($created)
		$this->exec->log($this->wfid, static::$label . ": made `{$kb->name}` ({$kb->model})");
	    $meta = $this->expr('meta', '');
	    if (is_string($meta) && trim($meta) !== '') {
		$decoded = json_decode($meta, true);
		if (!is_array($decoded))
		    throw new \ErrorException('Metadata: not a JSON object');
		$meta = $decoded;
	    }
	    $meta = \models\KnowledgeBase::checkMeta($meta);
	    $docs = [];
	    foreach($this->sources($op, $timeout) as $s)
		$docs[] = $kb->addDocument($s['key'], $s['title'], $s['text'], $meta, $s['source'], $timeout);
	    $chunks = array_sum(array_column($docs, 'chunks'));
	    $counts = array_count_values(array_column($docs, 'status'));
	    $this->exec->log($this->wfid, static::$label . ": `{$kb->name}`: " . implode(', ', array_map(fn($s, $n) => "{$n} {$s}", array_keys($counts), $counts)) . " ({$chunks} chunks)");
	    return [ 'knowledgeBase' => (string)$kb->name, 'created' => $created, 'documents' => $docs, 'chunks' => $chunks ];
	}

	$kb = \models\KnowledgeBase::named($kbName, $aid, $project);
	if (!$kb)
	    throw new \ErrorException("there is no knowledge base `{$kbName}` in this project");

	switch($op) {
	    case 'remove':
		$key = trim(\util\Expression::toText($this->expr('docKey', '')));
		if ($key === '')
		    throw new \ErrorException('Document key: empty');
		$removed = $kb->removeDocument($key);
		$this->exec->log($this->wfid, static::$label . ": `{$kb->name}`: {$removed} document(s) removed");
		return [ 'removed' => $removed ];

	    case 'documents':
		$list = $kb->documentList(self::MAX_LIST);
		return [ 'documents' => $list, 'count' => count($list) ];

	    default:
		$query = trim(\util\Expression::toText($this->expr('query', '')));
		$filter = $this->expr('filter', '');
		if (is_string($filter) && trim($filter) !== '') {
		    $decoded = json_decode($filter, true);
		    if (!is_array($decoded))
			throw new \ErrorException('Filter: not a JSON object');
		    $filter = $decoded;
		}
		$results = $kb->search($query, (int)$this->cfg('results', 5), $filter ?: [], (string)$this->cfg('minScore', '') === '' ? null : (float)$this->cfg('minScore'), $timeout);
		$this->exec->log($this->wfid, static::$label . ": `{$kb->name}`: " . count($results) . ' result(s)' . ($results ? ', best ' . $results[0]['score'] : ''));
		return [ 'results' => $results, 'count' => count($results), 'context' => \models\KnowledgeBase::context($results) ];
	}
    }

    //the documents to add: [ [ key, title, text, source: [ type, file, url ] ] ]
    //CLD: 2026-10-31T01:00:00+02:00: through models\KnowledgeBase::textSource / urlSource / fileSource (were read here: the Knowledge bases page adds documents too)
    protected function sources($op, $timeout) {
	if ($op === 'addText') {
	    try {
		return [ \models\KnowledgeBase::textSource(\util\Expression::toText($this->expr('title', '')), \util\Expression::toText($this->expr('text', '')),
		    \util\Expression::toText($this->expr('docKey', ''))) ];
	    } catch (\ErrorException $e) {
		throw new \ErrorException('Text: ' . $e->getMessage());
	    }
	}

	if ($op === 'addUrl') {
	    try {
		return [ \models\KnowledgeBase::urlSource(\util\Expression::toText($this->expr('url', '')), $timeout) ];
	    } catch (\ErrorException $e) {
		throw new \ErrorException('Page address: ' . $e->getMessage());
	    }
	}

	$ids = NodeAppAi::fileIds($this->expr('files', ''));
	if (!$ids)
	    throw new \ErrorException('Files: no file given');
	if (count($ids) > self::MAX_FILES)
	    throw new \ErrorException('Files: ' . count($ids) . ' files, at most ' . self::MAX_FILES);
	$out = [];
	foreach($ids as $id) {
	    $f = \V::app()->om->findOne('File', [ '_id' => $id, 'metadata.aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]);
	    if (!$f)
		throw new \ErrorException("Files: no file `{$id}` in this project");
	    try {
		$out[] = \models\KnowledgeBase::fileSource($f);
	    } catch (\ErrorException $e) {
		throw new \ErrorException('Files: ' . $e->getMessage());
	    }
	}
	return $out;
    }
}
