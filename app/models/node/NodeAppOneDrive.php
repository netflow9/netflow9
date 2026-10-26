<?php

/*
    CLD: 2026-10-22T00:41:28+03:00: new step: OneDrive / SharePoint - works with the files of a OneDrive or of a SharePoint document library over Microsoft Graph, through a
    OneDrive & SharePoint (Microsoft Graph) credential of the project (CredentialMicrosoftFiles, provides `msfiles`). As the Google Drive step.
    Operations (setting `operation`):
	list          the files of a folder, or the files found by a search (names and contents) in the whole drive; up to the largest number of results
	get           the information of one file / folder
	download      the content saved in the project's Files (as Download File: allowed types only, largest size)
	upload        a project file (e.g. {{ file.id }} of Download File) or a text, into a folder; an existing name replaced, renamed or refused; any size (an upload session
	                above 4 MB)
	createFolder  a folder in a folder
	move          a file / folder to another folder, optionally renamed
	share         a link (view / edit; anyone, or people in the organization), or an invitation of people by address (read / write)
	delete        to the recycle bin, or permanently (work / school accounts)
    The drive (setting `drive`): empty - the account's OneDrive; a SharePoint site's address (https://contoso.sharepoint.com/sites/Team: its document library); or a drive id.
    Files and folders are given by id, by path in the drive (/Reports/2026.xlsx) or by a sharing link. The answer goes into one variable (default `files`): a list of file
    objects (list), the saved project file (download: { id, filename, size, contentType, isImage, ..., item: <the drive item> }), else the drive item.
    On error (Graph errors, not found, too large, a type that may not be saved) - fail the step, or continue on a second output `error`
*/

//CLD: 2026-10-26T23:39:49+02:00: moved back to the core (was plugins\MicrosoftIntegration\models): the Microsoft credentials and steps are core, for every account (no plan feature)
namespace models\node;

class NodeAppOneDrive extends NodeAppMicrosoft {
    use DriveItemsTrait;

    //static data/structure
    public static $name = 'onedrive';
    public static $label = 'OneDrive / SharePoint';
    public static $icon = 'bi-cloud';
    public static $capability = 'msfiles';
    public static $credentialLabel = 'OneDrive & SharePoint credential';
    public static $credentialKinds = 'OneDrive & SharePoint';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'files';

    const OPERATIONS = [
	'list' => 'List / search files',
	'get' => 'Get file information',
	'download' => 'Download a file (into the project Files)',
	'upload' => 'Upload a file',
	'createFolder' => 'Create a folder',
	'move' => 'Move / rename a file',
	'share' => 'Share a file',
	'delete' => 'Delete a file',
    ];

    //the operations working on one existing item (itemId), the ones in a folder
    const ON_ITEM = [ 'get', 'download', 'move', 'share', 'delete' ];
    const IN_FOLDER = [ 'list', 'upload', 'createFolder' ];

    const CONFLICT = [ 'rename' => 'Keep both (the new one renamed)', 'replace' => 'Replace the existing file', 'fail' => 'Fail' ];
    const SHARE_WITH = [ 'link' => 'A link', 'people' => 'People (by address)' ];
    const LINK_TYPES = [ 'view' => 'View only', 'edit' => 'Edit' ];
    const LINK_SCOPES = [ 'organization' => 'People in the organization', 'anonymous' => 'Anyone with the link' ];
    const ROLES = [ 'read' => 'Read', 'write' => 'Write' ];

    const MAX_RESULTS = 1000;
    const MAX_SIZE_MB = 200;
    //transfers (download / upload) may take longer than the API calls (NodeAppHttp::MAX_TIMEOUT)
    const MAX_TIMEOUT = 600;
    //a simple upload (one PUT) up to this size, else an upload session in chunks (a multiple of 320 KiB)
    const SIMPLE_UPLOAD = 4 * 1024 * 1024;
    const CHUNK = 5 * 1024 * 1024;

    public static function configSchema() {
	$op = fn($ops) => [ 'key' => 'operation', 'operator' => 'in', 'value' => $ops ];

	return [
	    static::credentialField(),
	    static::operationField(),
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide, on a new line; shorter help (was: Empty: your OneDrive; a SharePoint site (https://contoso.sharepoint.com/sites/Team: its documents) or a drive id)
		'key' => 'drive',
		'type' => 'text',
		'label' => 'Drive',
		'default' => '',
		'help' => 'Empty: your OneDrive; a SharePoint site link or a drive id',
		'ui' => [ 'width' => 6, 'newRow' => true, 'placeholder' => 'https://contoso.sharepoint.com/sites/Team', 'expression' => true ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide; shorter help (was: The file / folder id, its path in the drive (/Reports/2026.xlsx) or a sharing link; {{ ... }} for expressions, e.g. {{ files[0].id }})
		'key' => 'itemId',
		'type' => 'text',
		'label' => 'File',
		'default' => '',
		'help' => 'Id, path (/Reports/2026.xlsx) or sharing link, e.g. {{ files[0].id }}',
		'requiredIf' => [ 'if' => 'operation', 'in' => self::ON_ITEM ],
		'ui' => [ 'width' => 6, 'placeholder' => '/Reports/2026.xlsx', 'expression' => true, 'showIf' => $op(self::ON_ITEM) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide; shorter help (was: A folder id, path (/Reports) or sharing link; empty: the top of the drive)
		'key' => 'folder',
		'type' => 'text',
		'label' => 'Folder',
		'default' => '',
		'help' => 'Id, path (/Reports) or sharing link; empty: the top',
		'ui' => [ 'width' => 6, 'placeholder' => '/Reports', 'expression' => true, 'showIf' => $op(array_merge(self::IN_FOLDER, [ 'move' ])) ],
	    ],

	    //list
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide; shorter help (was: Words in the names and contents, in the whole drive (the folder is not used); empty: the files of the folder)
		'key' => 'search',
		'type' => 'text',
		'label' => 'Search',
		'default' => '',
		'help' => 'Words in names and contents (whole drive); empty: the folder\'s files',
		'ui' => [ 'width' => 6, 'expression' => true, 'showIf' => $op([ 'list' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: a limit (the last line)
		'key' => 'maxResults',
		'type' => 'number',
		'label' => 'Largest number of files',
		'required' => true,
		'default' => 100,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_RESULTS, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_RESULTS ] ],
		'ui' => [ 'limit' => true, 'step' => 1, 'width' => 6, 'showIf' => $op([ 'list' ]) ],
	    ],

	    //download / upload
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide, on a new line; shorter help (was: The name in the project Files; empty: the file's)
		'key' => 'filename',
		'type' => 'text',
		'label' => 'Save as',
		'default' => '',
		'help' => 'The name in the project Files; empty: the file\'s',
		'ui' => [ 'width' => 6, 'newRow' => true, 'expression' => true, 'showIf' => $op([ 'download' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6), on a new line
		'key' => 'source',
		'type' => 'select',
		'label' => 'Upload',
		'required' => true,
		'default' => 'file',
		'choices' => [ 'file' => 'A project file', 'text' => 'A text' ],
		'ui' => [ 'width' => 3, 'newRow' => true, 'showIf' => $op([ 'upload' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide; shorter help (was: upload: the name in the drive (empty: the project file's); create folder: the folder's; move: a new name (empty: kept))
		'key' => 'name',
		'type' => 'text',
		'label' => 'Name',
		'default' => '',
		'help' => 'Upload / folder: its name; move: a new name (empty: kept)',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'createFolder' ] ],
		'ui' => [ 'width' => 6, 'expression' => true, 'showIf' => $op([ 'upload', 'createFolder', 'move' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6)
		'key' => 'conflict',
		'type' => 'select',
		'label' => 'When the name exists',
		'required' => true,
		'default' => 'rename',
		'choices' => self::CONFLICT,
		'ui' => [ 'width' => 3, 'showIf' => $op([ 'upload', 'createFolder' ]) ],
	    ],
	    [
		'key' => 'sourceFile',
		'type' => 'text',
		'label' => 'Project file',
		'default' => '',
		'help' => 'The id of a file of the project, e.g. {{ file.id }} (Download File) or {{ mail.attachment_files[0] }}',
		'ui' => [ 'expression' => true, 'showIf' => [ 'key' => 'source', 'operator' => 'eq', 'value' => 'file' ] ],
	    ],
	    [
		'key' => 'content',
		'type' => 'textarea',
		'label' => 'Text',
		'default' => '',
		'help' => '{{ ... }} for expressions (a list / object is written as JSON)',
		'ui' => [ 'rows' => 4, 'expression' => true, 'showIf' => [ 'key' => 'source', 'operator' => 'eq', 'value' => 'text' ] ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: a limit (the last line)
		'key' => 'maxSize',
		'type' => 'number',
		'label' => 'Largest size (MB)',
		'required' => true,
		'default' => 25,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_SIZE_MB, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_SIZE_MB ] ],
		'ui' => [ 'limit' => true, 'step' => 1, 'width' => 6, 'showIf' => $op([ 'download', 'upload' ]) ],
	    ],

	    //share
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6), on a new line
		'key' => 'shareWith',
		'type' => 'select',
		'label' => 'Share with',
		'required' => true,
		'default' => 'link',
		'choices' => self::SHARE_WITH,
		'ui' => [ 'width' => 3, 'newRow' => true, 'showIf' => $op([ 'share' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6)
		'key' => 'linkType',
		'type' => 'select',
		'label' => 'Access',
		'required' => true,
		'default' => 'view',
		'choices' => self::LINK_TYPES,
		'ui' => [ 'width' => 3, 'showIf' => $op([ 'share' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide
		'key' => 'linkScope',
		'type' => 'select',
		'label' => 'Who can use the link',
		'required' => true,
		'default' => 'organization',
		'choices' => self::LINK_SCOPES,
		'help' => 'Anyone: when the organization allows it',
		'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'shareWith', 'operator' => 'eq', 'value' => 'link' ] ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide, on a new line
		'key' => 'people',
		'type' => 'text',
		'label' => 'People',
		'default' => '',
		'help' => 'Addresses separated by commas',
		'ui' => [ 'width' => 6, 'newRow' => true, 'expression' => true, 'showIf' => [ 'key' => 'shareWith', 'operator' => 'eq', 'value' => 'people' ] ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6)
		'key' => 'notify',
		'type' => 'switch',
		'label' => 'Send them an email',
		'default' => true,
		'ui' => [ 'width' => 3, 'showIf' => [ 'key' => 'shareWith', 'operator' => 'eq', 'value' => 'people' ] ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide, on a new line
		'key' => 'message',
		'type' => 'text',
		'label' => 'Message',
		'default' => '',
		'ui' => [ 'width' => 6, 'newRow' => true, 'expression' => true, 'showIf' => [ 'key' => 'shareWith', 'operator' => 'eq', 'value' => 'people' ] ],
	    ],

	    //delete
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide, on a new line; shorter help (was: Else to the recycle bin; permanently: work / school accounts only)
		'key' => 'permanent',
		'type' => 'switch',
		'label' => 'Delete permanently',
		'default' => false,
		'help' => 'Else to the recycle bin (permanently: work / school only)',
		'ui' => [ 'width' => 3, 'newRow' => true, 'showIf' => $op([ 'delete' ]) ],
	    ],

	    //CLD: 2026-10-24T12:46:54+03:00: shorter help (the narrow columns of the wide dialog; was: static::timeoutField(120, self::MAX_TIMEOUT, 'Of each request (API calls: ' . \models\node\NodeAppHttp::MAX_TIMEOUT . ' at most)'))
	    static::timeoutField(120, self::MAX_TIMEOUT, 'Of each request'),
	    static::variableField(),
	    //CLD: 2026-10-24T12:46:54+03:00: shorter help (the narrow columns of the wide dialog; was: An error from OneDrive / SharePoint, e.g. not found, no access, too large, a type that may not be saved)
	    static::onErrorField('OneDrive errors, too large, type not allowed'),
	];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$fail = function($message) use (&$valid) {
	    $this->errors('config', $message);
	    $valid = false;
	};
	$empty = fn($key) => trim((string)$this->cfg($key, '')) === '';
	$operation = $this->cfg('operation', 'list');
	if ($operation === 'upload') {
	    if ($this->cfg('source', 'file') === 'text' && $empty('name'))
		$fail('Name: required for a text');
	    if ($this->cfg('source', 'file') === 'file' && $empty('sourceFile'))
		$fail('Project file: required');
	}
	if ($operation === 'move' && $empty('folder') && $empty('name'))
	    $fail('Move: give a folder or a new name');
	if ($operation === 'share' && $this->cfg('shareWith', 'link') === 'people' && $empty('people'))
	    $fail('People: required');
	return $valid;
    }

    //the type of a file object (itemOf())
    public static function itemType() {
	return [ 'type' => 'object', 'info' => 'a file / folder: { id, name, size, isFolder, mimeType, path, parentId, driveId, url, childCount, created, modified, createdBy, modifiedBy }',
	    'fields' => [ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'size' => [ 'type' => 'number' ], 'isFolder' => [ 'type' => 'boolean' ],
	    'mimeType' => [ 'type' => 'string' ], 'path' => [ 'type' => 'string', 'info' => 'in the drive, e.g. /Reports/2026.xlsx' ], 'parentId' => [ 'type' => 'string' ],
	    'driveId' => [ 'type' => 'string' ], 'url' => [ 'type' => 'string', 'info' => 'opens it in OneDrive / SharePoint' ], 'childCount' => [ 'type' => 'number', 'info' => 'a folder\'s' ],
	    'created' => [ 'type' => 'string' ], 'modified' => [ 'type' => 'string' ], 'createdBy' => [ 'type' => 'string' ], 'modifiedBy' => [ 'type' => 'string' ] ] ];
    }

    //the variable, by operation
    public static function outputVariables($config) {
	$config = is_array($config) ? $config : [];
	$name = static::variableName($config);
	if ($name === null)
	    return [];
	return [ $name => match($config['operation'] ?? 'list') {
	    'list' => [ 'type' => 'array', 'items' => self::itemType(), 'info' => 'the files found' ],
	    'download' => [ 'type' => 'object', 'info' => 'the saved project file: { id, filename, size, contentType, isImage, ..., item }', 'fields' => [ 'id' => [ 'type' => 'string' ],
		'filename' => [ 'type' => 'string' ], 'size' => [ 'type' => 'number' ], 'contentType' => [ 'type' => 'string' ], 'isImage' => [ 'type' => 'boolean' ], 'item' => self::itemType() ] ],
	    'share' => [ 'type' => 'object', 'info' => '{ link, type, scope, people }', 'fields' => [ 'link' => [ 'type' => 'string', 'info' => 'the sharing link (a link)' ],
		'type' => [ 'type' => 'string' ], 'scope' => [ 'type' => 'string' ], 'people' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ] ] ],
	    'delete' => [ 'type' => 'object', 'info' => '{ id, deleted, permanent }', 'fields' => [ 'id' => [ 'type' => 'string' ], 'deleted' => [ 'type' => 'boolean' ],
		'permanent' => [ 'type' => 'boolean' ] ] ],
	    default => self::itemType(),
	} ];
    }

    // ------------------------------------------------------------------------------------
    //running

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$this->drivePath = $this->resolveDrive($text('drive'));
	return match($operation) {
	    'list' => $this->opList($text),
	    'get' => $this->opGet($text),
	    'download' => $this->opDownload($text),
	    'upload' => $this->opUpload($text, $value),
	    'createFolder' => $this->opCreateFolder($text),
	    'move' => $this->opMove($text),
	    'share' => $this->opShare($text, $value),
	    'delete' => $this->opDelete($text),
	};
    }

    protected function opList(\Closure $text) {
	$max = max(1, min(self::MAX_RESULTS, (int)$this->cfg('maxResults', 100)));
	$search = $text('search');
	if ($search !== '') {
	    $path = $this->drivePath . "/root/search(q='" . rawurlencode(str_replace("'", "''", $search)) . "')";
	    [ $items, $more ] = $this->pages($path, [ '$top' => min(200, $max) ], $max);
	} else {
	    [ $items, $more ] = $this->pages($this->folderRef($text('folder')) . '/children', [ '$top' => min(200, $max) ], $max);
	}
	$files = array_map([ self::class, 'itemOf' ], $items);
	return [ $files, count($files) . ' file(s)' . ($search !== '' ? " for `{$search}`" : '') . ($more ? ' (more not read)' : '') ];
    }

    protected function opGet(\Closure $text) {
	$item = self::itemOf($this->api('GET', $this->itemRef($text('itemId'))));
	return [ $item, "`{$item['name']}`" . ($item['isFolder'] ? ' (folder)' : " ({$item['size']} bytes)") ];
    }

    protected function opDownload(\Closure $text) {
	$raw = $this->api('GET', $this->itemRef($text('itemId')));
	$item = self::itemOf($raw);
	if ($item['isFolder'])
	    throw new \RuntimeException("`{$item['name']}` is a folder");
	$url = (string)($raw['@microsoft.graph.downloadUrl'] ?? '');
	if (!str_starts_with($url, 'https://'))
	    throw new \RuntimeException("`{$item['name']}` has no download address");

	$filename = trim($text('filename'));
	$filename = \models\node\NodeAppDownload::filename($filename !== '' ? $filename : $item['name'], '', '', $item['mimeType']);
	if (!\models\File::isAllowedName($filename))
	    throw new \RuntimeException("file type not allowed: `{$filename}` (allowed: " . implode(', ', array_keys(\models\File::$extensions)) . ')');
	$maxBytes = $this->maxBytes();
	if ($item['size'] > $maxBytes)
	    throw new \RuntimeException("`{$item['name']}` is larger than " . ($maxBytes >> 20) . ' MB');

	//the download address is signed: no Authorization header
	[ $stream, $size, $type ] = $this->fetch($url, $maxBytes);
	rewind($stream);
	$image = \models\node\NodeAppDownload::imageInfo((string)fread($stream, \models\node\NodeAppDownload::IMAGE_SNIFF));
	rewind($stream);
	$fid = \models\File::createFromStream($filename, $stream, [ 'metadata' => [
	    'project' => $this->exec->project,
	    'aid' => $this->exec->aid,
	    'owner' => null,
	    'source' => 'onedrive',
	    'wf' => $this->exec->wf,
	    'created_at' => new \MongoDB\BSON\UTCDateTime(),
	] ]);
	fclose($stream);

	$file = [ 'id' => (string)$fid, 'filename' => $filename, 'size' => $size, 'contentType' => $type ?: $item['mimeType'] ] + $image + [ 'item' => $item ];
	return [ $file, "`{$item['name']}` saved as `{$filename}` ({$size} bytes)" ];
    }

    protected function opUpload(\Closure $text, \Closure $value) {
	$maxBytes = $this->maxBytes();
	$name = trim($text('name'));
	if ($this->cfg('source', 'file') === 'text') {
	    $content = $value('content');
	    $content = is_array($content) ? json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) : \util\Expression::toText($content);
	    if ($name === '')
		throw new \ErrorException('OneDrive / SharePoint: upload: a text needs a name');
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
		throw new \RuntimeException("no project file `" . mb_substr($fileId, 0, 60) . '`');
	    $size = (int)$file->length;
	    if ($size > $maxBytes)
		throw new \RuntimeException("`{$file->filename}` is larger than " . ($maxBytes >> 20) . ' MB');
	    if ($name === '')
		$name = (string)$file->filename;
	    $stream = \models\File::_bucket()->openDownloadStream($file->_id);
	}
	if (preg_match('~[\\\\/:*?"<>|]~', $name) || trim($name, ' .') === '')
	    throw new \RuntimeException("not a file name: `" . mb_substr($name, 0, 100) . '`');

	$conflict = isset(self::CONFLICT[$this->cfg('conflict')]) ? (string)$this->cfg('conflict') : 'rename';
	try {
	    $parent = $this->folderId($text('folder'));
	    $target = '/drives/' . rawurlencode($parent['driveId']) . '/items/' . rawurlencode($parent['id']) . ':/' . rawurlencode($name) . ':';
	    if ($size <= self::SIMPLE_UPLOAD) {
		$data = $this->call('PUT', self::API . $target . '/content' . self::queryString([ '@microsoft.graph.conflictBehavior' => $conflict ]),
		    array_merge($this->authHeaders(), [ 'Content-Type: application/octet-stream' ]), (string)stream_get_contents($stream));
	    } else {
		$session = $this->api('POST', $target . '/createUploadSession', [], [ 'item' => [ '@microsoft.graph.conflictBehavior' => $conflict ] ]);
		$url = (string)($session['uploadUrl'] ?? '');
		if (!str_starts_with($url, 'https://'))
		    throw new \RuntimeException('no upload session');
		$data = $this->uploadChunks($url, $stream, $size);
	    }
	} finally {
	    fclose($stream);
	}
	$item = self::itemOf($data);
	return [ $item, "`{$item['name']}` uploaded ({$size} bytes)" ];
    }

    protected function opCreateFolder(\Closure $text) {
	$name = trim($text('name'));
	if ($name === '' || preg_match('~[\\\\/:*?"<>|]~', $name))
	    throw new \RuntimeException("not a folder name: `" . mb_substr($name, 0, 100) . '`');
	$parent = $this->folderId($text('folder'));
	$conflict = isset(self::CONFLICT[$this->cfg('conflict')]) ? (string)$this->cfg('conflict') : 'rename';
	$item = self::itemOf($this->api('POST', '/drives/' . rawurlencode($parent['driveId']) . '/items/' . rawurlencode($parent['id']) . '/children', [],
	    [ 'name' => $name, 'folder' => (object)[], '@microsoft.graph.conflictBehavior' => $conflict ]));
	return [ $item, "folder `{$item['name']}` created" ];
    }

    protected function opMove(\Closure $text) {
	$changes = [];
	if ($text('folder') !== '') {
	    $parent = $this->folderId($text('folder'));
	    $changes['parentReference'] = [ 'id' => $parent['id'], 'driveId' => $parent['driveId'] ];
	}
	if (($name = trim($text('name'))) !== '')
	    $changes['name'] = $name;
	if (!$changes)
	    throw new \ErrorException('OneDrive / SharePoint: move: give a folder or a new name');
	$item = self::itemOf($this->api('PATCH', $this->itemRef($text('itemId')), [], $changes));
	return [ $item, "`{$item['name']}` " . (isset($changes['parentReference']) ? "moved to {$item['path']}" : 'renamed') ];
    }

    protected function opShare(\Closure $text, \Closure $value) {
	$ref = $this->itemRef($text('itemId'));
	$type = isset(self::LINK_TYPES[$this->cfg('linkType')]) ? (string)$this->cfg('linkType') : 'view';
	if ($this->cfg('shareWith', 'link') === 'people') {
	    $people = self::addresses($value('people'));
	    if (!$people)
		throw new \RuntimeException('no people to share with');
	    $body = [ 'recipients' => array_map(fn($a) => [ 'email' => $a ], $people), 'roles' => [ $type === 'edit' ? 'write' : 'read' ], 'requireSignIn' => true,
		'sendInvitation' => (bool)$this->cfg('notify', true) ];
	    if (($message = $text('message')) !== '')
		$body['message'] = mb_substr($message, 0, 2000);
	    $this->api('POST', $ref . '/invite', [], $body);
	    return [ [ 'link' => null, 'type' => $type, 'scope' => 'users', 'people' => $people ], 'shared with ' . implode(', ', $people) . " ({$type})" ];
	}
	$scope = isset(self::LINK_SCOPES[$this->cfg('linkScope')]) ? (string)$this->cfg('linkScope') : 'organization';
	$answer = $this->api('POST', $ref . '/createLink', [], [ 'type' => $type, 'scope' => $scope ]);
	$link = (string)($answer['link']['webUrl'] ?? '');
	return [ [ 'link' => $link, 'type' => $type, 'scope' => $scope, 'people' => [] ], "{$type} link ({$scope})" ];
    }

    protected function opDelete(\Closure $text) {
	$ref = $this->itemRef($text('itemId'));
	$permanent = (bool)$this->cfg('permanent', false);
	$item = self::itemOf($this->api('GET', $ref, [ '$select' => 'id,name' ]));
	if ($permanent)
	    $this->api('POST', $ref . '/permanentDelete');
	else
	    $this->api('DELETE', $ref);
	return [ [ 'id' => $item['id'], 'deleted' => true, 'permanent' => $permanent ], "`{$item['name']}` " . ($permanent ? 'deleted permanently' : 'moved to the recycle bin') ];
    }

    // ------------------------------------------------------------------------------------
    //drives and items (resolveDrive, itemRef, folderRef, folderId, siteOf, encodePath, shareToken: DriveItemsTrait)

    //a file object of the step from Graph's drive item
    public static function itemOf($i) {
	$i = is_array($i) ? $i : [];
	$parentPath = (string)($i['parentReference']['path'] ?? '');
	$parentPath = preg_replace('~^/drives?/[^/]*/root:|^/drive/root:~', '', $parentPath);
	$parentPath = rawurldecode((string)$parentPath);
	return [
	    'id' => (string)($i['id'] ?? ''),
	    'name' => (string)($i['name'] ?? ''),
	    'size' => (int)($i['size'] ?? 0),
	    'isFolder' => isset($i['folder']),
	    'mimeType' => (string)($i['file']['mimeType'] ?? (isset($i['folder']) ? 'folder' : '')),
	    'path' => isset($i['parentReference']['path']) ? rtrim($parentPath, '/') . '/' . ($i['name'] ?? '') : '',
	    'parentId' => (string)($i['parentReference']['id'] ?? ''),
	    'driveId' => (string)($i['parentReference']['driveId'] ?? ''),
	    'url' => (string)($i['webUrl'] ?? ''),
	    'childCount' => isset($i['folder']['childCount']) ? (int)$i['folder']['childCount'] : null,
	    'created' => $i['createdDateTime'] ?? null,
	    'modified' => $i['lastModifiedDateTime'] ?? null,
	    'createdBy' => $i['createdBy']['user']['displayName'] ?? null,
	    'modifiedBy' => $i['lastModifiedBy']['user']['displayName'] ?? null,
	];
    }

    // ------------------------------------------------------------------------------------
    //transfers

    protected function maxBytes() {
	return max(1, min(self::MAX_SIZE_MB, (int)$this->cfg('maxSize', 25))) * 1024 * 1024;
    }

    protected function transferTimeout() {
	return max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 120)));
    }

    //GET of a signed download address into a temporary stream: [ stream, size, content type ]; \RuntimeException when larger than $maxBytes or refused
    protected function fetch($url, $maxBytes) {
	$stream = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
	//CLD: 2026-10-23T15:00:00+03:00: through util\Http (was curl here)
	$r = \util\Http::request('GET', $url, [ 'headers' => [], 'timeout' => $this->transferTimeout(), 'maxBytes' => $maxBytes, 'sink' => $stream, 'redirects' => 5, 'https' => true ]);
	[ $status, $size, $contentType, $tooLarge, $curlError ] = [ $r['status'], $r['size'], $r['mime'], $r['tooLarge'], $r['tooLarge'] ? '' : (string)$r['error'] ];

	if ($tooLarge || $curlError !== '' || $status >= 400) {
	    rewind($stream);
	    $answer = $status >= 400 ? (string)fread($stream, 8192) : '';
	    fclose($stream);
	    if ($tooLarge)
		throw new \RuntimeException('the file is larger than ' . ($maxBytes >> 20) . ' MB');
	    throw new \RuntimeException(self::errorText($status, json_decode($answer, true), $curlError !== '' ? $curlError : "HTTP status {$status}"));
	}
	return [ $stream, $size, $contentType ];
    }

    //the content of $stream ($size bytes) PUT to an upload session in chunks (Content-Range; the session's address is signed: no Authorization header): the drive item
    protected function uploadChunks($url, $stream, $size) {
	$offset = 0;
	$data = [];
	while ($offset < $size) {
	    $chunk = '';
	    while (strlen($chunk) < self::CHUNK && $offset + strlen($chunk) < $size) {
		$read = fread($stream, min(self::CHUNK - strlen($chunk), $size - $offset - strlen($chunk)));
		if ($read === false || $read === '')
		    break;
		$chunk .= $read;
	    }
	    if ($chunk === '')
		throw new \RuntimeException('the file could not be read');
	    $end = $offset + strlen($chunk) - 1;
	    //CLD: 2026-10-23T15:00:00+03:00: through util\Http (was curl here)
	    $r = \util\Http::request('PUT', $url, [ 'headers' => [ 'Content-Length: ' . strlen($chunk), "Content-Range: bytes {$offset}-{$end}/{$size}" ], 'body' => $chunk, 'timeout' => $this->transferTimeout(), 'https' => true ]);
	    [ $answer, $status, $curlError ] = [ $r['body'], $r['status'], (string)$r['error'] ];
	    $data = json_decode((string)$answer, true);
	    if ($curlError !== '' || $status >= 400) {
		//the session is left to expire; a DELETE would end it sooner
		throw new \RuntimeException(self::errorText($status, $data, $curlError !== '' ? $curlError : "HTTP status {$status}"));
	    }
	    $offset = $end + 1;
	}
	if (!is_array($data) || empty($data['id']))
	    throw new \RuntimeException('the upload did not finish');
	return $data;
    }
}
