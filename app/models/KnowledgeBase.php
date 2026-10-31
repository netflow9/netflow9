<?php

/*
    CLD: 2026-10-30T23:30:00+02:00: new: a knowledge base of a project - documents cut into chunks (util\TextChunks), each chunk's embedding (util\Embeddings) kept with its text, searched
    by meaning (the cosine similarity of the query's embedding, computed by MongoDB: $similarityCosine, exact, no vector index) and by words (the chunks' text index), the two lists
    merged (reciprocal rank fusion). Searched by the Knowledge base step and by the AI steps (their Knowledge bases setting: the search_knowledge_base tool).
	- chunkSize / overlap: how the documents are cut (characters)
	- documents / chunks: the counts (updated after each change)
    The documents: models\KbDocument (one per key: a file, a web page, a text), the chunks: models\KbChunk (written and read in bulk through the collection: never one by one).
    Deleted with its documents and chunks (beforeDelete).
    CLD: 2026-10-31T01:00:00+02:00: the embedding model is the account's (models\EmbeddingSettings; was a credential + model of the knowledge base): `model` / dims are those it was
    indexed with; once the account uses another model it must be re-indexed (reindex(): a job re-embeds every chunk, then switches) before it is searched or added to again.
    Made on the Knowledge bases page (controllers\KnowledgeController) or by the Knowledge base step's first Add to a new name (open()). Documents added on the page are indexed
    by the daemon (queue(): a pending document, then indexJob); `state`: ready | reindexing, `state_message`: the last re-index's error. Permissions KnowledgeBase::* (checkAccess)
*/

namespace models;

class KnowledgeBase extends \sys\BaseModel {
    protected static $collection = 'knowledge-bases';
    public static $indexes = [
	[ [ 'aid' => 1, 'project' => 1, 'name' => 1 ], [] ],
    ];

    //the text of one document read at most (characters)
    const MAX_TEXT = 1000000;
    //the chunks of a knowledge base at most (exact search reads them all: ~65 ms per 1000)
    const MAX_CHUNKS = 25000;
    const MAX_NAME = 80;
    const DEFAULT_CHUNK_SIZE = 1000;
    const DEFAULT_OVERLAP = 150;
    //the results of a search at most
    const MAX_RESULTS = 50;
    //reciprocal rank fusion: 1 / (RRF_K + rank)
    const RRF_K = 60;
    //chunks re-embedded per batch (reindexJob)
    const REINDEX_BATCH = 64;

    public function getSchema() {
	$fields = [
	    [
		'key' => 'name',
		'type' => 'text',
		'label' => 'Name',
		'help' => 'How the workflows name it',
		'required' => true,
		'transform' => 'trim',
		'validate' => [ [ 'regex', 'pattern' => '^.{1,' . self::MAX_NAME . '}$', 'skipEmpty' => true, 'message' => 'At most ' . self::MAX_NAME . ' characters' ] ],
	    ],
	    [
		'key' => 'description',
		'type' => 'textarea',
		'label' => 'Description',
		'help' => 'What its documents are about; the AI steps read it to decide when to search',
		'default' => '',
	    ],
	    [
		'key' => 'project',
		'type' => 'object',
		'label' => 'Project',
		'object' => 'Project',
		'help' => false,
		'required' => true,
		'perm:read' => 'everybody',
		'perm:update' => 'permission',
		'showInForms' => false,
	    ],
	    //the embedding model it was indexed with (the account's when it was made / re-indexed)
	    [
		'key' => 'model',
		'type' => 'text',
		'label' => 'Embedding model',
		'help' => false,
		'default' => '',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    [
		'key' => 'dims',
		'type' => 'number',
		'label' => 'Dimensions',
		'help' => false,
		'default' => 0,
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    [
		'key' => 'chunkSize',
		'type' => 'number',
		'label' => 'Chunk size',
		'help' => 'Characters per chunk (200 - 8000); a small embedding model needs small chunks (e.g. 512 tokens: about 1000 characters)',
		'required' => true,
		'default' => self::DEFAULT_CHUNK_SIZE,
		'validate' => [ [ 'inRange', 'lower' => 200, 'upper' => 8000, 'skipEmpty' => true, 'message' => 'from 200 to 8000' ] ],
	    ],
	    [
		'key' => 'overlap',
		'type' => 'number',
		'label' => 'Overlap',
		'help' => 'Characters repeated at the start of the next chunk (at most half the chunk size)',
		'required' => true,
		'default' => self::DEFAULT_OVERLAP,
		'validate' => [ [ 'inRange', 'lower' => 0, 'upper' => 4000, 'skipEmpty' => true, 'message' => 'from 0 to 4000' ] ],
	    ],
	    [
		'key' => 'documents',
		'type' => 'number',
		'label' => 'Documents',
		'help' => false,
		'default' => 0,
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    [
		'key' => 'chunks',
		'type' => 'number',
		'label' => 'Chunks',
		'help' => false,
		'default' => 0,
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    [
		'key' => 'state',
		'type' => 'select',
		'label' => 'State',
		'help' => false,
		'default' => 'ready',
		'choices' => [ 'ready' => 'Ready', 'reindexing' => 'Re-indexing' ],
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	    [
		'key' => 'state_message',
		'type' => 'text',
		'label' => 'Last message',
		'help' => false,
		'default' => '',
		'perm:update' => 'nobody',
		'showInForms' => false,
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    //the name: unique in the project; the overlap: at most half the chunk size
    public function validates($options = []) {
	$ok = parent::validates($options);
	$name = trim((string)$this->name);
	if ($name !== '' && !empty($this->project)) {
	    $other = self::named($name, $this->aid, $this->project);
	    if ($other && (string)$other->_id !== (string)$this->_id) {
		$this->errors('name', [ 'The project has a knowledge base of this name' ]);
		$ok = false;
	    }
	}
	if ((int)$this->overlap > intdiv((int)$this->chunkSize, 2)) {
	    $this->errors('overlap', [ 'At most half the chunk size' ]);
	    $ok = false;
	}
	return $ok;
    }

    //the documents and chunks go with it
    public function beforeDelete() {
	parent::beforeDelete();
	if (empty($this->_id))
	    return;
	self::chunkCollection()->deleteMany([ 'kb' => $this->_id ]);
	self::documentCollection()->deleteMany([ 'kb' => $this->_id ]);
    }

    //access as the data tables: global scope = the account's knowledge bases, project scope = those of the projects the user is a member of
    public function checkAccess($userId, $permName, $params = []) {
	if (strpos($permName, '::') === false)
	    $permName = 'KnowledgeBase::' . $permName;
	$scope = \V::app()->auth->getUserRole()->getPermissionScope($permName);
	if ($scope == 'global')
	    return true;
	if ($scope == 'project')
	    return \models\Project::isMember($this->project, $this->aid, $userId);
	return false;
    }

    public function __toString() {
	return (string)$this->name;
    }

    // ------------------------------------------------------------------------------------
    //finding, making

    public static function chunkCollection() {
	return \V::app()->mongo->selectCollection(KbChunk::_database(), KbChunk::_collection());
    }

    public static function documentCollection() {
	return \V::app()->mongo->selectCollection(KbDocument::_database(), KbDocument::_collection());
    }

    //knowledge base $name of project $project (account $aid), or null
    public static function named($name, $aid, $project) {
	$name = trim((string)$name);
	if ($name === '' || empty($aid) || empty($project))
	    return null;
	return \V::app()->om->findOne('KnowledgeBase', [ 'aid' => $aid, 'project' => \V::app()->om->to_oid($project), 'name' => $name ]);
    }

    //the names of the project's knowledge bases: [ name => name ] (the steps' choices)
    public static function namesFor($aid, $project) {
	if (empty($aid) || empty($project))
	    return [];
	$names = [];
	foreach(\V::app()->om->find('KnowledgeBase', [ 'aid' => $aid, 'project' => \V::app()->om->to_oid($project) ], [ 'sort' => [ 'name' => 1 ] ]) as $kb)
	    $names[(string)$kb->name] = (string)$kb->name;
	return $names;
    }

    //knowledge base $name of the project, made (with the account's embedding model) when there is none: [ the knowledge base, made (bool) ]; \ErrorException when it is
    //missing and cannot be made
    public static function open($name, $aid, $project, $chunkSize = self::DEFAULT_CHUNK_SIZE, $overlap = self::DEFAULT_OVERLAP) {
	$name = trim((string)$name);
	$kb = self::named($name, $aid, $project);
	if ($kb)
	    return [ $kb, false ];
	if ($name === '' || mb_strlen($name) > self::MAX_NAME)
	    throw new \ErrorException('the knowledge base\'s name must have 1 to ' . self::MAX_NAME . ' characters');
	$settings = EmbeddingSettings::of($aid);
	if (!$settings->ready())
	    throw new \ErrorException("there is no knowledge base `{$name}` in this project, and none can be made: no embedding model is set for the account (Settings: Knowledge bases)");
	$size = max(200, min(8000, (int)$chunkSize));
	$kb = \V::app()->om->create('KnowledgeBase', [ 'aid' => $aid, 'project' => \V::app()->om->to_oid($project), 'name' => $name, 'model' => trim((string)$settings->model),
	    'chunkSize' => $size, 'overlap' => max(0, min(intdiv($size, 2), (int)$overlap)), 'dims' => 0, 'documents' => 0, 'chunks' => 0, 'state' => 'ready' ]);
	if (!$kb->validates() || !$kb->save())
	    throw new \ErrorException('the knowledge base could not be made: ' . json_encode($kb->errors(), JSON_UNESCAPED_UNICODE));
	return [ $kb, true ];
    }

    //the account's embedding settings, checked against the model the knowledge base was indexed with; \ErrorException when it cannot be used now (being re-indexed, no model,
    //another model: re-index it). An empty knowledge base takes the account's model
    public function embeddingSettings() {
	if ((string)$this->state === 'reindexing')
	    throw new \ErrorException("knowledge base `{$this->name}` is being re-indexed: try again when it is done");
	$settings = EmbeddingSettings::of($this->aid);
	if (!$settings->ready())
	    throw new \ErrorException('no embedding model is set for the account (Settings: Knowledge bases)');
	$model = trim((string)$settings->model);
	if ($model !== (string)$this->model) {
	    if ((int)$this->chunks > 0)
		throw new \ErrorException("knowledge base `{$this->name}` was indexed with the model `{$this->model}`, the account now uses `{$model}`: re-index it (Knowledge bases page)");
	    $this->model = $model;
	    $this->dims = 0;
	    \V::app()->om->updateMany('KnowledgeBase', [ '_id' => $this->_id ], [ '$set' => [ 'model' => $model, 'dims' => 0 ] ]);
	}
	return $settings;
    }

    //whether the account's model is not the one it was indexed with (re-index it)
    public function needsReindex() {
	$settings = EmbeddingSettings::of($this->aid);
	return (int)$this->chunks > 0 && $settings->ready() && trim((string)$settings->model) !== (string)$this->model;
    }

    // ------------------------------------------------------------------------------------
    //the documents' sources: [ key, title, text, source: [ type: file | url | text, file, url ] ]

    //project file $f (PDF: its pages' text; Office; text files); \ErrorException when it has no text
    public static function fileSource($f) {
	$text = \models\node\NodeAppAi::fileText($f, self::MAX_TEXT);
	if ($text === null)
	    throw new \ErrorException("`{$f->filename}` has no text to add (PDF, Office or text files)");
	return [ 'key' => (string)$f->_id, 'title' => (string)$f->filename, 'text' => $text, 'source' => [ 'type' => 'file', 'file' => $f->_id ] ];
    }

    //web page $url: its text (as Read a web page: public addresses only unless HTTP_STEPS_ALLOW_PRIVATE), its title; the key is the address
    public static function urlSource($url, $timeout) {
	$url = trim((string)$url);
	if (!preg_match('~^https?://[^\s/?#]+~i', $url))
	    throw new \ErrorException('the page address must start with http:// or https:// (got `' . mb_substr($url, 0, 100) . '`)');
	$r = \models\node\NodeAppHttp::request('GET', $url, [], null, $timeout, \models\node\NodeAppHttp::guard());
	$shown = preg_replace('/[?#].*$/', '', $url);
	if (!$r['ok'])
	    throw new \ErrorException("{$shown}: " . ($r['error'] ?: 'HTTP status ' . $r['status']));
	$type = (string)(is_array($r['headers']['content-type'] ?? null) ? end($r['headers']['content-type']) : ($r['headers']['content-type'] ?? ''));
	$body = is_string($r['body']) ? $r['body'] : json_encode($r['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
	$title = '';
	if (preg_match('~html~i', $type)) {
	    if (preg_match('~<title\b[^>]*>(.*?)</title>~is', $body, $m))
		$title = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
	    $body = \models\node\NodeAppGmail::htmlToText(preg_replace('~<head\b.*?</head>~is', '', $body));
	}
	return [ 'key' => $url, 'title' => $title !== '' ? $title : $shown, 'text' => $body, 'source' => [ 'type' => 'url', 'url' => $url ] ];
    }

    //a text: the key given, else the title, else the text's hash
    public static function textSource($title, $text, $key = '') {
	$title = trim((string)$title);
	$text = (string)$text;
	if (trim($text) === '')
	    throw new \ErrorException('the text is empty');
	$key = trim((string)$key);
	return [ 'key' => $key !== '' ? $key : ($title !== '' ? $title : sha1($text)), 'title' => $title, 'text' => $text, 'source' => [ 'type' => 'text' ] ];
    }

    // ------------------------------------------------------------------------------------
    //documents

    //adds (or replaces) document $key: $text cut into chunks, embedded; $meta (object of names => text / number / yes-no / lists of them) kept with each chunk, for filters;
    //$source: [ type: file | url | text, file, url ]. A document whose text, title, meta and cutting did not change is left as it is.
    //Returns [ key, title, status: added | replaced | unchanged, chunks ]; \ErrorException when it fails
    public function addDocument($key, $title, $text, array $meta, array $source, $timeout = 120) {
	$key = trim((string)$key);
	$title = trim((string)$title);
	if ($key === '')
	    throw new \ErrorException('the document has no key');
	$settings = $this->embeddingSettings();
	$text = (string)$text;
	if (mb_strlen($text) > self::MAX_TEXT)
	    $text = mb_substr($text, 0, self::MAX_TEXT);
	$meta = self::checkMeta($meta);
	$hash = sha1(json_encode([ $title, $text, $meta, (int)$this->chunkSize, (int)$this->overlap, (string)$this->model ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
	$docs = self::documentCollection();
	$old = $docs->findOne([ 'kb' => $this->_id, 'key' => $key ]);
	if ($old && ($old['hash'] ?? '') === $hash && ($old['status'] ?? 'indexed') === 'indexed')
	    return [ 'key' => $key, 'title' => $title, 'status' => 'unchanged', 'chunks' => (int)($old['chunks'] ?? 0) ];

	$pieces = \util\TextChunks::split($text, (int)$this->chunkSize ?: self::DEFAULT_CHUNK_SIZE, (int)$this->overlap);
	if (!$pieces)
	    throw new \ErrorException("`{$title}` has no text");
	$total = self::chunkCollection()->countDocuments([ 'kb' => $this->_id ]) - (int)($old['chunks'] ?? 0) + count($pieces);
	if ($total > self::MAX_CHUNKS)
	    throw new \ErrorException("knowledge base `{$this->name}` would have {$total} chunks, at most " . self::MAX_CHUNKS);

	//the title before each chunk's text: what it is part of is embedded too
	$vectors = \util\Embeddings::embed($settings, array_map(fn($p) => self::embedText($title, $p['text']), $pieces), 'document', $timeout);
	$this->checkDims($vectors);

	$now = new \MongoDB\BSON\UTCDateTime();
	$docId = $old['_id'] ?? new \MongoDB\BSON\ObjectId();
	$doc = [ 'aid' => $this->aid, 'kb' => $this->_id, 'key' => $key, 'title' => $title, 'source' => (string)($source['type'] ?? 'text'), 'file' => $source['file'] ?? null,
	    'url' => $source['url'] ?? null, 'meta' => (object)$meta, 'hash' => $hash, 'chunks' => count($pieces), 'chars' => mb_strlen($text), 'status' => 'indexed', 'error' => null,
	    'updated_at' => $now ];
	$chunks = [];
	foreach($pieces as $i => $p)
	    $chunks[] = [ 'aid' => $this->aid, 'kb' => $this->_id, 'doc' => $docId, 'key' => $key, 'title' => $title, 'seq' => $i, 'page' => $p['page'], 'text' => $p['text'],
		'meta' => (object)$meta, 'embedding' => \MongoDB\BSON\Binary::fromVector($vectors[$i], \MongoDB\BSON\VectorType::Float32) ];

	//the old chunks out, the new ones in
	self::chunkCollection()->deleteMany([ 'kb' => $this->_id, 'doc' => $docId ]);
	foreach(array_chunk($chunks, 500) as $batch)
	    self::chunkCollection()->insertMany($batch);
	if ($old)
	    $docs->updateOne([ '_id' => $docId ], [ '$set' => $doc ]);
	else
	    $docs->insertOne([ '_id' => $docId ] + $doc + [ 'created_at' => $now ]);
	$this->recount();
	return [ 'key' => $key, 'title' => $title, 'status' => $old && ($old['status'] ?? 'indexed') === 'indexed' ? 'replaced' : 'added', 'chunks' => count($pieces) ];
    }

    //what is embedded for a chunk: its document's title, then its text
    private static function embedText($title, $text) {
	return ($title !== '' ? $title . "\n\n" : '') . $text;
    }

    //the vectors all of one length, the knowledge base's (its first ones set it)
    private function checkDims(array $vectors) {
	$dims = count($vectors[0]);
	foreach($vectors as $v) {
	    if (count($v) !== $dims)
		throw new \ErrorException('the embeddings have different lengths');
	}
	if ((int)$this->dims === 0) {
	    $this->dims = $dims;
	    \V::app()->om->updateMany('KnowledgeBase', [ '_id' => $this->_id ], [ '$set' => [ 'dims' => $dims ] ]);
	} elseif ((int)$this->dims !== $dims) {
	    throw new \ErrorException("the embeddings have {$dims} dimensions, knowledge base `{$this->name}` has " . (int)$this->dims . " (was the model `{$this->model}` changed on the server?)");
	}
    }

    //removes document $key (or the documents of file $key): the number of documents removed
    public function removeDocument($key) {
	$key = trim((string)$key);
	if ($key === '')
	    return 0;
	$or = [ [ 'key' => $key ] ];
	if (\util\Validator::rule('oid', $key))
	    $or[] = [ 'file' => \V::app()->om->to_oid($key) ];
	$ids = [];
	foreach(self::documentCollection()->find([ 'kb' => $this->_id, '$or' => $or ], [ 'projection' => [ '_id' => 1 ] ]) as $d)
	    $ids[] = $d['_id'];
	if (!$ids)
	    return 0;
	self::chunkCollection()->deleteMany([ 'kb' => $this->_id, 'doc' => [ '$in' => $ids ] ]);
	self::documentCollection()->deleteMany([ 'kb' => $this->_id, '_id' => [ '$in' => $ids ] ]);
	$this->recount();
	return count($ids);
    }

    //the documents, newest first: [ [ id, key, title, source, file, url, chunks, chars, meta, status: indexed | pending | error, error, updated_at ] ], at most $limit
    public function documentList($limit = 100) {
	$list = [];
	foreach(self::documentCollection()->find([ 'kb' => $this->_id ], [ 'sort' => [ 'updated_at' => -1 ], 'limit' => max(1, (int)$limit) ]) as $d)
	    $list[] = [ 'id' => (string)$d['_id'], 'key' => (string)$d['key'], 'title' => (string)($d['title'] ?? ''), 'source' => (string)($d['source'] ?? ''),
		'file' => isset($d['file']) ? (string)$d['file'] : null, 'url' => $d['url'] ?? null, 'chunks' => (int)($d['chunks'] ?? 0), 'chars' => (int)($d['chars'] ?? 0),
		'meta' => \util\Expression::hostValue($d['meta'] ?? []), 'status' => (string)($d['status'] ?? 'indexed'), 'error' => $d['error'] ?? null,
		'updated_at' => isset($d['updated_at']) ? \util\Expression::hostValue($d['updated_at']) : null ];
	return $list;
    }

    //the counts of documents and chunks saved again
    private function recount() {
	$this->documents = self::documentCollection()->countDocuments([ 'kb' => $this->_id ]);
	$this->chunks = self::chunkCollection()->countDocuments([ 'kb' => $this->_id ]);
	\V::app()->om->updateMany('KnowledgeBase', [ '_id' => $this->_id ], [ '$set' => [ 'documents' => $this->documents, 'chunks' => $this->chunks,
	    'updated_at' => new \MongoDB\BSON\UTCDateTime() ] ]);
    }

    //$meta checked: names (letters, digits, _), values text / number / yes-no / null or lists of them; \ErrorException otherwise
    public static function checkMeta($meta) {
	if (is_object($meta))
	    $meta = (array)$meta;
	if ($meta === null || $meta === '' || $meta === [])
	    return [];
	if (!is_array($meta) || array_is_list($meta))
	    throw new \ErrorException('Metadata: an object of names and values, e.g. {"category": "hr"}');
	foreach($meta as $k => $v) {
	    if (!\util\Validator::isIdentifier((string)$k))
		throw new \ErrorException("Metadata: `{$k}` is not a name (letters, digits, _)");
	    $values = is_array($v) && array_is_list($v) ? $v : [ $v ];
	    foreach($values as $x) {
		if ($x !== null && !is_scalar($x))
		    throw new \ErrorException("Metadata: `{$k}`: text, numbers, yes / no or lists of them");
	    }
	}
	return $meta;
    }

    // ------------------------------------------------------------------------------------
    //indexing in the daemon (the Knowledge bases page)

    //document $src (fileId | url | title + text + key) queued: a pending document now (an existing one: marked pending), indexed by the daemon (indexJob). Returns the
    //document's key
    public function queue(array $src) {
	$type = (string)($src['type'] ?? '');
	$now = new \MongoDB\BSON\UTCDateTime();
	switch($type) {
	    case 'file':
		$f = \V::app()->om->findOne('File', [ '_id' => (string)($src['file'] ?? ''), 'metadata.aid' => $this->aid, 'metadata.project' => $this->project ]);
		if (!$f)
		    throw new \ErrorException('no such file in this project');
		[ $key, $title, $extra ] = [ (string)$f->_id, (string)$f->filename, [ 'file' => $f->_id ] ];
		$src = [ 'type' => 'file', 'file' => (string)$f->_id ];
		break;
	    case 'url':
		$url = trim((string)($src['url'] ?? ''));
		if (!preg_match('~^https?://[^\s/?#]+~i', $url))
		    throw new \ErrorException('the page address must start with http:// or https://');
		[ $key, $title, $extra ] = [ $url, preg_replace('/[?#].*$/', '', $url), [ 'url' => $url ] ];
		$src = [ 'type' => 'url', 'url' => $url ];
		break;
	    case 'text':
		$s = self::textSource($src['title'] ?? '', $src['text'] ?? '', $src['key'] ?? '');
		if (mb_strlen($s['text']) > self::MAX_TEXT)
		    throw new \ErrorException('the text is longer than ' . \util\Format::number(self::MAX_TEXT) . ' characters');
		[ $key, $title, $extra ] = [ $s['key'], $s['title'], [] ];
		$src = [ 'type' => 'text', 'title' => $s['title'], 'text' => $s['text'], 'key' => $s['key'] ];
		break;
	    default:
		throw new \ErrorException('unknown source');
	}
	$docs = self::documentCollection();
	$old = $docs->findOne([ 'kb' => $this->_id, 'key' => $key ]);
	if ($old)
	    $docs->updateOne([ '_id' => $old['_id'] ], [ '$set' => [ 'status' => 'pending', 'error' => null, 'updated_at' => $now ] ]);
	else
	    $docs->insertOne([ 'aid' => $this->aid, 'kb' => $this->_id, 'key' => $key, 'title' => $title, 'source' => $type, 'file' => $extra['file'] ?? null, 'url' => $extra['url'] ?? null,
		'meta' => (object)[], 'hash' => '', 'chunks' => 0, 'chars' => 0, 'status' => 'pending', 'error' => null, 'created_at' => $now, 'updated_at' => $now ]);
	$this->recount();
	\models\Job::submit('\models\KnowledgeBase::indexJob', [ 'aid' => (string)$this->aid, 'project' => (string)$this->project, 'kb' => (string)$this->_id, 'key' => $key, 'src' => $src ],
	    [ 'delete_on_done' => true ]);
	return $key;
    }

    //a job's data as plain values: arrays, ids as text (the daemon reads it through the Job model: objects, ObjectIds)
    private static function jobData($v) {
	if ($v instanceof \MongoDB\BSON\ObjectId)
	    return (string)$v;
	if ($v instanceof \ArrayObject)
	    $v = $v->getArrayCopy();
	elseif (is_object($v))
	    $v = get_object_vars($v);
	return is_array($v) ? array_map([ self::class, 'jobData' ], $v) : $v;
    }

    //the daemon's job: a queued document indexed (its source read, cut, embedded); a failure is the document's error (status error)
    public static function indexJob($data) {
	$data = self::jobData($data);
	$kb = \V::app()->om->findOne('KnowledgeBase', [ '_id' => (string)($data['kb'] ?? ''), 'aid' => \V::app()->om->to_oid($data['aid'] ?? null) ]);
	if (!$kb)
	    return;
	$key = (string)($data['key'] ?? '');
	$doc = self::documentCollection()->findOne([ 'kb' => $kb->_id, 'key' => $key ]);
	//removed meanwhile
	if (!$doc || ($doc['status'] ?? '') !== 'pending')
	    return;
	$src = (array)($data['src'] ?? []);
	try {
	    switch($src['type'] ?? '') {
		case 'file':
		    $f = \V::app()->om->findOne('File', [ '_id' => (string)($src['file'] ?? ''), 'metadata.aid' => $kb->aid, 'metadata.project' => $kb->project ]);
		    if (!$f)
			throw new \ErrorException('the file is no longer in the project');
		    $s = self::fileSource($f);
		    break;
		case 'url':
		    $s = self::urlSource($src['url'] ?? '', 60);
		    break;
		default:
		    $s = self::textSource($src['title'] ?? '', $src['text'] ?? '', $src['key'] ?? '');
	    }
	    $meta = \util\Expression::hostValue($doc['meta'] ?? []);
	    $kb->addDocument($key, $s['title'], $s['text'], is_array($meta) ? $meta : [], $s['source'], 300);
	} catch (\Exception $e) {
	    self::documentCollection()->updateOne([ '_id' => $doc['_id'] ], [ '$set' => [ 'status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 500),
		'updated_at' => new \MongoDB\BSON\UTCDateTime() ] ]);
	}
    }

    //re-index with the account's model: state reindexing, the daemon re-embeds every chunk (reindexJob); \ErrorException when there is no model or it is already re-indexing
    public function reindex() {
	if ((string)$this->state === 'reindexing')
	    throw new \ErrorException('it is being re-indexed already');
	if (!EmbeddingSettings::of($this->aid)->ready())
	    throw new \ErrorException('no embedding model is set for the account (Settings: Knowledge bases)');
	$this->state = 'reindexing';
	$this->state_message = '';
	\V::app()->om->updateMany('KnowledgeBase', [ '_id' => $this->_id ], [ '$set' => [ 'state' => 'reindexing', 'state_message' => '' ] ]);
	\models\Job::submit('\models\KnowledgeBase::reindexJob', [ 'aid' => (string)$this->aid, 'project' => (string)$this->project, 'kb' => (string)$this->_id ], [ 'delete_on_done' => true ]);
    }

    //the daemon's job: every chunk embedded again with the account's model (the new vectors aside, then put in place at once: a failure leaves the old ones), then the
    //knowledge base's model / dims switched; state ready again, the error in state_message
    public static function reindexJob($data) {
	$data = self::jobData($data);
	$kb = \V::app()->om->findOne('KnowledgeBase', [ '_id' => (string)($data['kb'] ?? ''), 'aid' => \V::app()->om->to_oid($data['aid'] ?? null) ]);
	if (!$kb)
	    return;
	$chunks = self::chunkCollection();
	$set = fn(array $fields) => \V::app()->om->updateMany('KnowledgeBase', [ '_id' => $kb->_id ], [ '$set' => $fields ]);
	try {
	    $settings = EmbeddingSettings::of($kb->aid);
	    if (!$settings->ready())
		throw new \ErrorException('no embedding model is set for the account');
	    $dims = 0;
	    $last = null;
	    do {
		$batch = $chunks->find([ 'kb' => $kb->_id ] + ($last ? [ '_id' => [ '$gt' => $last ] ] : []), [ 'projection' => [ 'title' => 1, 'text' => 1 ], 'sort' => [ '_id' => 1 ],
		    'limit' => self::REINDEX_BATCH ])->toArray();
		if (!$batch)
		    break;
		$vectors = \util\Embeddings::embed($settings, array_map(fn($c) => self::embedText((string)($c['title'] ?? ''), (string)$c['text']), $batch), 'document', 300);
		foreach($batch as $i => $c) {
		    $dims = $dims ?: count($vectors[$i]);
		    if (count($vectors[$i]) !== $dims)
			throw new \ErrorException('the embeddings have different lengths');
		    $chunks->updateOne([ '_id' => $c['_id'] ], [ '$set' => [ 'embedding_new' => \MongoDB\BSON\Binary::fromVector($vectors[$i], \MongoDB\BSON\VectorType::Float32) ] ]);
		}
		$last = end($batch)['_id'];
	    } while (count($batch) === self::REINDEX_BATCH);
	    $chunks->updateMany([ 'kb' => $kb->_id, 'embedding_new' => [ '$exists' => true ] ], [ [ '$set' => [ 'embedding' => '$embedding_new' ] ], [ '$unset' => 'embedding_new' ] ]);
	    $set([ 'model' => trim((string)$settings->model), 'dims' => $dims, 'state' => 'ready', 'state_message' => '' ]);
	    //documents indexed with another model: their hash names it (added again: indexed again)
	    self::documentCollection()->updateMany([ 'kb' => $kb->_id ], [ '$set' => [ 'hash' => '' ] ]);
	} catch (\Exception $e) {
	    $chunks->updateMany([ 'kb' => $kb->_id ], [ '$unset' => [ 'embedding_new' => '' ] ]);
	    $set([ 'state' => 'ready', 'state_message' => 'Re-index failed: ' . mb_substr($e->getMessage(), 0, 400) ]);
	}
    }

    // ------------------------------------------------------------------------------------
    //search

    //the chunks that best answer $query: [ [ text, score (the cosine similarity, -1..1), match: both | meaning | words, document (its key), title, page, meta ] ], best first, at
    //most $k; $filter: { meta name: value or a list of values (any of them) } the chunks must have; $minScore: results less similar left out. \ErrorException when it fails
    public function search($query, $k = 5, $filter = [], $minScore = null, $timeout = 60) {
	$query = trim((string)$query);
	if ($query === '')
	    throw new \ErrorException('the query is empty');
	$k = max(1, min(self::MAX_RESULTS, (int)$k));
	$settings = $this->embeddingSettings();
	if ((int)$this->chunks === 0)
	    return [];
	$vector = \util\Embeddings::embed($settings, [ $query ], 'query', $timeout)[0];
	if ((int)$this->dims && count($vector) !== (int)$this->dims)
	    throw new \ErrorException("the query's embedding has " . count($vector) . " dimensions, knowledge base `{$this->name}` has " . (int)$this->dims . ' (was the model changed on the server?)');
	$qv = \MongoDB\BSON\Binary::fromVector($vector, \MongoDB\BSON\VectorType::Float32);
	$match = [ 'kb' => $this->_id ] + self::filterQuery($filter);
	$candidates = max(20, $k * 4);
	$fields = [ 'text' => 1, 'key' => 1, 'title' => 1, 'page' => 1, 'meta' => 1, 'seq' => 1 ];
	$coll = self::chunkCollection();

	//by meaning: every chunk's similarity, the best
	$byMeaning = $coll->aggregate([ [ '$match' => $match ], [ '$project' => $fields + [ 'score' => [ '$similarityCosine' => [ 'vectors' => [ '$embedding', $qv ] ] ] ] ],
	    [ '$sort' => [ 'score' => -1 ] ], [ '$limit' => $candidates ] ])->toArray();
	//by words: the text index (diacritics and case ignored); none when the index is missing (`v setup::indexes`)
	$byWords = [];
	try {
	    $byWords = $coll->find($match + [ '$text' => [ '$search' => $query ] ], [ 'projection' => $fields + [ 'embedding' => 1, 'ts' => [ '$meta' => 'textScore' ] ],
		'sort' => [ 'ts' => [ '$meta' => 'textScore' ] ], 'limit' => $candidates ])->toArray();
	} catch (\MongoDB\Driver\Exception\Exception $e) {
	    \V::app()->log->warn('KnowledgeBase::search: no search by words (' . $e->getMessage() . ')');
	}

	//merged: 1 / (RRF_K + rank) of each list a chunk is in
	$found = [];
	foreach([ 'meaning' => $byMeaning, 'words' => $byWords ] as $list => $rows) {
	    foreach(array_values($rows) as $rank => $c) {
		$id = (string)$c['_id'];
		if (!isset($found[$id]))
		    $found[$id] = [ 'chunk' => $c, 'rrf' => 0, 'lists' => [], 'score' => null ];
		$found[$id]['rrf'] += 1 / (self::RRF_K + $rank + 1);
		$found[$id]['lists'][] = $list;
		if ($list === 'meaning')
		    $found[$id]['score'] = (float)$c['score'];
	    }
	}
	//the similarity of the chunks found by words only
	foreach($found as &$f) {
	    if ($f['score'] === null)
		$f['score'] = isset($f['chunk']['embedding']) ? self::cosine($vector, $f['chunk']['embedding']->toArray()) : 0.0;
	}
	unset($f);
	if ($minScore !== null && $minScore !== '')
	    $found = array_filter($found, fn($f) => $f['score'] >= (float)$minScore);
	uasort($found, fn($a, $b) => $b['rrf'] <=> $a['rrf'] ?: $b['score'] <=> $a['score']);

	$results = [];
	foreach(array_slice($found, 0, $k) as $f) {
	    $c = $f['chunk'];
	    $results[] = [ 'text' => (string)$c['text'], 'score' => round($f['score'], 4), 'match' => count($f['lists']) > 1 ? 'both' : $f['lists'][0], 'document' => (string)$c['key'],
		'title' => (string)($c['title'] ?? ''), 'page' => isset($c['page']) ? (int)$c['page'] : null, 'meta' => \util\Expression::hostValue($c['meta'] ?? []) ];
	}
	return $results;
    }

    //the results as one text for a prompt: [n] the title (page), then the text
    public static function context(array $results) {
	$parts = [];
	foreach(array_values($results) as $i => $r)
	    $parts[] = '[' . ($i + 1) . '] ' . ($r['title'] !== '' ? $r['title'] : $r['document']) . ($r['page'] ? " (page {$r['page']})" : '') . "\n" . $r['text'];
	return implode("\n\n", $parts);
    }

    //the MongoDB conditions of filter $filter ({ name: value or [ values ] } on the chunks' meta); \ErrorException when it is not such an object
    public static function filterQuery($filter) {
	if (is_object($filter))
	    $filter = (array)$filter;
	if ($filter === null || $filter === '' || $filter === [])
	    return [];
	if (!is_array($filter) || array_is_list($filter))
	    throw new \ErrorException('Filter: an object of metadata names and values, e.g. {"category": "hr"}');
	$query = [];
	foreach($filter as $k => $v) {
	    if (!\util\Validator::isIdentifier((string)$k))
		throw new \ErrorException("Filter: `{$k}` is not a name (letters, digits, _)");
	    if (is_array($v) && array_is_list($v)) {
		foreach($v as $x)
		    if ($x !== null && !is_scalar($x))
			throw new \ErrorException("Filter: `{$k}`: text, numbers, yes / no or lists of them");
		$query['meta.' . $k] = [ '$in' => $v ];
	    } elseif ($v === null || is_scalar($v)) {
		$query['meta.' . $k] = $v;
	    } else {
		throw new \ErrorException("Filter: `{$k}`: text, numbers, yes / no or lists of them");
	    }
	}
	return $query;
    }

    private static function cosine(array $a, array $b) {
	$dot = $na = $nb = 0.0;
	foreach($a as $i => $x) {
	    $y = (float)($b[$i] ?? 0);
	    $dot += $x * $y;
	    $na += $x * $x;
	    $nb += $y * $y;
	}
	return $na > 0 && $nb > 0 ? $dot / sqrt($na * $nb) : 0.0;
    }
}
