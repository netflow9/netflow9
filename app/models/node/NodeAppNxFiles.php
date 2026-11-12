<?php

/*
    CLD: 2026-11-12T12:00:00+02:00: Nextcloud Files - the user's files, through WebDAV. Operations (setting `operation`):
	list          a folder's items (PROPFIND Depth 1), its subfolders' too to a depth; files, folders or both; a name pattern
	search        the files whose name contains a text, under a folder and its subfolders (SEARCH), the newest first
	get           an item's details (PROPFIND Depth 0)
	download      a file saved in the project's Files (as Download File: allowed types only, largest size): { id, filename, size, contentType, isImage, ..., item }
	upload        a project file (e.g. {{ file.id }}) or a text, into a folder; a name that exists: renamed (name (2).ext), replaced or refused
	createFolder  a folder, its parents too (MKCOL each); one that exists is fine
	move          moved / renamed (MOVE), copied (COPY): to a folder (ending in /) or a full path; overwriting or not
	copy
	delete        an item deleted (DELETE: to the trash bin when the app is on)
    Items: { name, path, folder, isFolder, size, modified (ISO 8601), contentType, etag, fileId, permissions, favorite }; paths in the user's files (/Documents/a.pdf)
*/

namespace models\node;

class NodeAppNxFiles extends NodeAppNextcloud {
    public static $name = 'nextcloud-files';
    public static $label = 'Nextcloud Files';
    public static $icon = 'bi-folder2-open';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'files';

    const OPERATIONS = [
	'list' => 'List a folder',
	'search' => 'Search files by name',
	'get' => 'Get an item',
	'download' => 'Download a file (into the project Files)',
	'upload' => 'Upload a file',
	'createFolder' => 'Create a folder',
	'move' => 'Move / rename',
	'copy' => 'Copy',
	'delete' => 'Delete',
    ];
    const WRITES = [ 'download', 'upload', 'createFolder', 'move', 'copy', 'delete' ];
    const LISTS = [ 'list', 'search' ];
    const ON_ITEM = [ 'get', 'download', 'move', 'copy', 'delete' ];
    const INCLUDE = [ 'all' => 'Files and folders', 'files' => 'Files', 'folders' => 'Folders' ];
    const CONFLICT = [ 'rename' => 'Keep both (the new one renamed)', 'replace' => 'Replace the existing file', 'fail' => 'Fail' ];
    const MAX_SIZE_MB = 200;
    const MAX_DEPTH = 5;
    const DEFAULT_TIMEOUT = 120;

    protected static function operationFields() {
	return [
	    self::field('folder', 'Folder', [ 'list', 'search', 'upload' ], [ 'help' => 'A path in the files, e.g. /Documents/Invoices; empty: the top', 'ui' => [ 'expression' => 'text', 'width' => 6, 'placeholder' => '/Documents' ] ]),
	    self::field('path', 'Path', array_merge(self::ON_ITEM, [ 'createFolder' ]), [ 'required' => true, 'help' => 'e.g. /Documents/report.pdf, or an item of a list ({{ item }})',
		'ui' => [ 'expression' => true, 'width' => 6, 'placeholder' => '/Documents/report.pdf' ] ]),
	    self::field('include', 'Items', [ 'list' ], [ 'type' => 'select', 'default' => 'all', 'choices' => self::INCLUDE, 'ui' => [ 'width' => 3 ] ]),
	    self::field('depth', 'Subfolders', [ 'list' ], [ 'type' => 'number', 'default' => 0, 'help' => 'Levels below the folder (0: only it)',
		'validate' => [ [ 'inRange', 'lower' => 0, 'upper' => self::MAX_DEPTH, 'skipEmpty' => true, 'message' => 'from 0 to ' . self::MAX_DEPTH ] ], 'ui' => [ 'step' => 1, 'width' => 3 ] ]),
	    self::field('pattern', 'Names', [ 'list' ], [ 'help' => 'Patterns, e.g. *.pdf, invoice-*; empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('search', 'Name contains', [ 'search' ], [ 'required' => true, 'help' => 'Any case', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('filename', 'Save as', [ 'download' ], [ 'help' => 'The name in the project Files; empty: the file\'s', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('source', 'Upload', [ 'upload' ], [ 'type' => 'select', 'required' => true, 'default' => 'file', 'choices' => [ 'file' => 'A project file', 'text' => 'A text' ], 'ui' => [ 'width' => 3, 'newRow' => true ] ]),
	    self::field('name', 'Name', [ 'upload' ], [ 'help' => 'Empty: the project file\'s name (a text needs one)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('conflict', 'When the name exists', [ 'upload' ], [ 'type' => 'select', 'required' => true, 'default' => 'rename', 'choices' => self::CONFLICT, 'ui' => [ 'width' => 3 ] ]),
	    self::field('sourceFile', 'Project file', [ 'upload' ], [ 'help' => 'The id of a file of the project, e.g. {{ file.id }} (Download File) or {{ mail.attachment_files[0] }}',
		'ui' => [ 'expression' => 'text', 'showIf' => [ 'key' => 'source', 'operator' => 'eq', 'value' => 'file' ] ] ]),
	    self::field('content', 'Text', [ 'upload' ], [ 'type' => 'textarea', 'help' => '{{ ... }} for expressions (a list / object is written as JSON)',
		'ui' => [ 'rows' => 4, 'expression' => true, 'showIf' => [ 'key' => 'source', 'operator' => 'eq', 'value' => 'text' ] ] ]),
	    self::field('target', 'To', [ 'move', 'copy' ], [ 'required' => true, 'help' => 'A folder ending in / (the name kept), or the new path, e.g. /Archive/2026/ or /Archive/report-old.pdf',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('overwrite', 'Overwrite', [ 'move', 'copy' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Replace an item already there', 'ui' => [ 'width' => 6 ] ]),
	    self::field('maxSize', 'Largest size (MB)', [ 'download', 'upload' ], [ 'type' => 'number', 'required' => true, 'default' => 25,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_SIZE_MB, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_SIZE_MB ] ], 'ui' => [ 'limit' => true, 'step' => 1, 'width' => 6 ] ]),
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? self::$defaultVariable) : self::$defaultVariable;
	if (\util\Validator::isIdentifier($name) && is_array($config) && ($config['operation'] ?? '') === 'download')
	    return [ $name => [ 'type' => 'object', 'info' => 'the saved project file: { id, filename, size, contentType, isImage, ..., item }', 'fields' => [ 'id' => [ 'type' => 'string' ],
		'filename' => [ 'type' => 'string' ], 'size' => [ 'type' => 'number' ], 'contentType' => [ 'type' => 'string' ], 'item' => [ 'type' => 'object', 'info' => 'the Nextcloud item' ] ] ] ];
	return parent::outputVariables($config);
    }

    //a name matches one of the patterns (globs separated by commas, any case); none: all
    public static function matches($name, $patterns) {
	$list = array_filter(array_map('trim', explode(',', (string)$patterns)), fn($p) => $p !== '');
	foreach($list as $p)
	    if (fnmatch(mb_strtolower($p), mb_strtolower((string)$name)))
		return true;
	return !$list;
    }

    protected function maxBytes() {
	return max(1, min(self::MAX_SIZE_MB, (int)$this->cfg('maxSize', 25))) * 1024 * 1024;
    }

    //the items of a folder (and its subfolders to $depth levels): files, folders or both ($include), names matching $pattern; at most $max: [ items, more left out ]
    public static function walk(\util\Nextcloud $nc, $folder, $depth, $include, $pattern, $max) {
	$depth = max(0, min(self::MAX_DEPTH, (int)$depth));
	$all = [];
	$queue = [ [ $folder, 0 ] ];
	while ($queue) {
	    [ $dir, $level ] = array_shift($queue);
	    $items = $nc->items($dir, 1);
	    if ($level === 0 && !($items[0]['isFolder'] ?? false))
		throw new \RuntimeException("{$dir} is a file, not a folder");
	    foreach(array_slice($items, 1) as $it) {
		if ($it['isFolder'] && $level < $depth)
		    $queue[] = [ $it['path'], $level + 1 ];
		if (($include === 'files' && $it['isFolder']) || ($include === 'folders' && !$it['isFolder']) || !self::matches($it['name'], $pattern))
		    continue;
		if (count($all) >= $max)
		    return [ $all, true ];
		$all[] = $it;
	    }
	}
	return [ $all, false ];
    }

    //the item at $path, or null when there is none
    protected function itemOrNull($path) {
	try {
	    return $this->nc->items($path, 0)[0] ?? null;
	} catch (\RuntimeException $e) {
	    if (str_starts_with($e->getMessage(), 'HTTP status 404'))
		return null;
	    throw $e;
	}
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$N = \util\Nextcloud::class;
	switch($operation) {
	    case 'list':
		[ $all, $more ] = self::walk($this->nc, $N::path($value('folder'), 'Folder'), (int)$this->cfg('depth', 0), (string)$this->cfg('include', 'all'), $text('pattern'), $this->maxItems());
		[ $res, $sum ] = $this->listResult($all, 'item');
		$res['truncated'] = $more;
		return [ $res, $sum . ($more ? ' (more left out)' : '') ];
	    case 'search':
		$max = $this->maxItems();
		$found = array_values(array_filter($this->nc->search($value('folder'), $text('search'), $max + 1), fn($i) => !$i['isFolder']));
		return $this->listResult($found, 'file');
	    case 'get':
		$it = $this->nc->items($N::path($value('path')), 0)[0] ?? null;
		if (!$it)
			throw new \RuntimeException('not found');
		return [ $it, $it['path'] . ($it['isFolder'] ? ' (folder)' : ', ' . \util\Format::bytes($it['size'])) ];
	    case 'download':
		return $this->download($text, $value);
	    case 'upload':
		return $this->upload($text, $value);
	    case 'createFolder':
		$path = $N::path($value('path'));
		if ($path === '/')
		    throw new \RuntimeException('Path: a folder below the top');
		$made = 0;
		$at = '';
		foreach(array_filter(explode('/', $path)) as $part) {
		    $at .= '/' . $part;
		    $it = $this->itemOrNull($at);
		    if ($it && !$it['isFolder'])
			throw new \RuntimeException("{$at} is a file");
		    if (!$it) {
			$this->nc->dav('MKCOL', $at);
			$made++;
		    }
		}
		return [ $this->nc->items($path, 0)[0] ?? [ 'path' => $path ], $made ? "{$path} created" : "{$path} exists already" ];
	    case 'move':
	    case 'copy':
		$from = $N::path($value('path'));
		$to = $text('target');
		$dest = str_ends_with($to, '/') ? rtrim($N::path($to, 'To'), '/') . '/' . basename($from) : $N::path($to, 'To');
		if ($from === '/' || $dest === '/' || $dest === $from)
		    throw new \RuntimeException('To: another path than the item\'s');
		$this->nc->dav($operation === 'move' ? 'MOVE' : 'COPY', $from, [ 'destination' => $dest, 'overwrite' => (bool)$this->cfg('overwrite', false) ]);
		return [ $this->nc->items($dest, 0)[0] ?? [ 'path' => $dest ], "{$from} " . ($operation === 'move' ? 'moved' : 'copied') . " to {$dest}" ];
	    case 'delete':
		$path = $N::path($value('path'));
		if ($path === '/')
		    throw new \RuntimeException('Path: not the top of the files');
		$this->nc->dav('DELETE', $path);
		return [ [ 'path' => $path, 'deleted' => true ], "{$path} deleted" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    protected function download(\Closure $text, \Closure $value) {
	$path = \util\Nextcloud::path($value('path'));
	$item = $this->nc->items($path, 0)[0] ?? null;
	if (!$item || $item['isFolder'])
	    throw new \RuntimeException("{$path} is not a file");
	$filename = trim($text('filename'));
	$filename = NodeAppDownload::filename($filename !== '' ? $filename : $item['name'], '', '', (string)$item['contentType']);
	if (!\models\File::isAllowedName($filename))
	    throw new \RuntimeException("file type not allowed: `{$filename}` (allowed: " . implode(', ', array_keys(\models\File::$extensions)) . ')');
	$maxBytes = $this->maxBytes();
	if ($item['size'] > $maxBytes)
	    throw new \RuntimeException("{$path} is larger than " . ($maxBytes >> 20) . ' MB');
	$stream = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
	try {
	    $r = $this->nc->dav('GET', $path, [ 'sink' => $stream, 'maxBytes' => $maxBytes ]);
	    rewind($stream);
	    $image = NodeAppDownload::imageInfo((string)fread($stream, NodeAppDownload::IMAGE_SNIFF));
	    rewind($stream);
	    $fid = \models\File::createFromStream($filename, $stream, [ 'metadata' => [
		'project' => $this->exec->project,
		'aid' => $this->exec->aid,
		'owner' => null,
		'source' => 'nextcloud',
		'wf' => $this->exec->wf,
		'created_at' => new \MongoDB\BSON\UTCDateTime(),
	    ] ]);
	} finally {
	    fclose($stream);
	}
	return [ [ 'id' => (string)$fid, 'filename' => $filename, 'size' => (int)$r['size'], 'contentType' => $r['mime'] ?: $item['contentType'] ] + $image + [ 'item' => $item ],
	    "{$path} saved as `{$filename}` ({$r['size']} bytes)" ];
    }

    protected function upload(\Closure $text, \Closure $value) {
	$maxBytes = $this->maxBytes();
	$name = trim($text('name'));
	if ($this->cfg('source', 'file') === 'text') {
	    $content = $value('content');
	    $content = is_array($content) ? json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) : \util\Expression::toText($content);
	    if ($name === '')
		throw new \RuntimeException('Name: a text needs one');
	    if (strlen($content) > $maxBytes)
		throw new \RuntimeException('the text is larger than ' . ($maxBytes >> 20) . ' MB');
	    $size = strlen($content);
	    $stream = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
	    fwrite($stream, $content);
	    rewind($stream);
	} else {
	    $fileId = trim($text('sourceFile'));
	    $file = \util\Validator::rule('oid', $fileId) ? \V::app()->om->findOne('File', [ '_id' => $fileId, 'metadata.aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]) : null;
	    if (empty($file))
		throw new \RuntimeException('no project file `' . mb_substr($fileId, 0, 60) . '`');
	    $size = (int)$file->length;
	    if ($size > $maxBytes)
		throw new \RuntimeException("`{$file->filename}` is larger than " . ($maxBytes >> 20) . ' MB');
	    if ($name === '')
		$name = (string)$file->filename;
	    $stream = \models\File::_bucket()->openDownloadStream($file->_id);
	}
	try {
	    if (str_contains($name, '/') || preg_match('/[\x00-\x1f\\\\]/', $name) || trim($name, ' .') === '')
		throw new \RuntimeException('not a file name: `' . mb_substr($name, 0, 100) . '`');
	    $folder = \util\Nextcloud::path($value('folder'), 'Folder');
	    $dest = rtrim($folder, '/') . '/' . $name;
	    $conflict = isset(self::CONFLICT[(string)$this->cfg('conflict', 'rename')]) ? (string)$this->cfg('conflict', 'rename') : 'rename';
	    $headers = [ 'Content-Type' => 'application/octet-stream' ];
	    if ($conflict === 'rename') {
		$dot = strrpos($name, '.');
		[ $base, $ext ] = $dot > 0 ? [ substr($name, 0, $dot), substr($name, $dot) ] : [ $name, '' ];
		for ($i = 2; $this->itemOrNull($dest) !== null; $i++) {
		    if ($i > 100)
			throw new \RuntimeException("too many files named {$name}");
		    $dest = rtrim($folder, '/') . "/{$base} ({$i}){$ext}";
		}
	    } elseif ($conflict === 'fail')
		$headers['If-None-Match'] = '*';
	    $this->nc->dav('PUT', $dest, [ 'headers' => $headers, 'upload' => [ $stream, $size ] ]);
	} finally {
	    fclose($stream);
	}
	$item = $this->nc->items($dest, 0)[0] ?? [ 'path' => $dest, 'name' => basename($dest), 'size' => $size ];
	return [ $item, "{$dest} uploaded ({$size} bytes)" ];
    }
}
