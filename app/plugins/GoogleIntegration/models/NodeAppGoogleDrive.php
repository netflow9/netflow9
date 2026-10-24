<?php

/*
    CLD: 2026-10-20T10:00:00+03:00: new step: Google Drive - works with the files of a Google Drive account (Drive API v3), through a Google Drive (OAuth) credential of the project
    (plugins\GoogleIntegration\models\CredentialGoogleDrive, provides `gdrive`). Inspired by sim.ai's Google Drive block. Operations (setting `operation`):
	list          files of a folder / of the whole Drive, searched by name, by type, or with a Drive query (q); paged up to the largest number of results
	get           the information of one file
	download      the content saved in the project's Files (as Download File: allowed types only, largest size); Google Docs / Sheets / Slides / Drawings exported
	                (default: docx / xlsx / pptx / png; or a chosen format)
	upload        a project file (e.g. {{ file.id }} of Download File / Read emails attachments) or a text, into a folder; optionally converted to a Google file
	createFolder  a folder in a folder
	move          a file to another folder (out of all its current folders)
	share         a permission on a file: a user / group (by address), a domain, or anyone with the link; reader, commenter or writer
	delete        to the trash, or permanently
    Files are given by id or by their Drive link (the id is taken from it); shared drives are included. The answer goes into one variable (default `drive`): a list of
    file objects (list), the saved project file (download: { id, filename, size, contentType, isImage, ..., drive: <the Drive file> }), else the Drive file.
    On error (Drive API errors, not found, too large, a type that may not be saved) - fail the step, or continue on a second output `error` (as HTTP Request: $outPorts)
*/

//CLD: 2026-10-22T00:35:40+03:00: moved to the GoogleIntegration plugin (was models\node\NodeAppGoogleDrive); core classes fully qualified
namespace plugins\GoogleIntegration\models;

class NodeAppGoogleDrive extends NodeAppGoogle {
    //static data/structure
    //CLD: 2026-10-20T14:00:00+03:00: the shape, the credential, the operation run and the API calls are NodeAppGoogle's
    public static $name = 'google-drive';
    public static $label = 'Google Drive';
    public static $capability = 'gdrive';
    public static $credentialLabel = 'Google Drive credential';
    public static $credentialKinds = 'Google Drive';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'drive';

    const OPERATIONS = [
	'list' => 'List / search files',
	'get' => 'Get file information',
	'download' => 'Download a file (into the project Files)',
	'upload' => 'Upload a file',
	'createFolder' => 'Create a folder',
	'move' => 'Move a file to a folder',
	'share' => 'Share a file',
	'delete' => 'Delete a file',
    ];

    //the operations working on one existing file (fileId)
    const ON_FILE = [ 'get', 'download', 'move', 'share', 'delete' ];

    const FILE_TYPES = [
	'any' => 'Any',
	'files' => 'Files (no folders)',
	'folder' => 'Folders',
	'document' => 'Google Docs',
	'spreadsheet' => 'Google Sheets',
	'presentation' => 'Google Slides',
	'pdf' => 'PDF',
	'image' => 'Images',
    ];

    const ORDER = [
	'modifiedTime desc' => 'Last modified first',
	'modifiedTime' => 'Last modified last',
	'name' => 'Name',
	'createdTime desc' => 'Newest first',
	'folder,name' => 'Folders first, then by name',
    ];

    //export of the Google files: format => [ MIME type, extension ]
    const EXPORT_FORMATS = [
	'auto' => 'Office format (Docs: docx, Sheets: xlsx, Slides: pptx, Drawings: png)',
	'pdf' => 'PDF',
	'docx' => 'Word (docx)',
	'xlsx' => 'Excel (xlsx)',
	'pptx' => 'PowerPoint (pptx)',
	'odt' => 'OpenDocument text (odt)',
	'ods' => 'OpenDocument spreadsheet (ods)',
	'csv' => 'CSV (first sheet)',
	'txt' => 'Plain text',
	'html' => 'HTML',
	'png' => 'PNG image',
    ];
    const EXPORT_MIME = [
	'pdf' => 'application/pdf',
	'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
	'odt' => 'application/vnd.oasis.opendocument.text',
	'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
	'csv' => 'text/csv',
	'txt' => 'text/plain',
	'html' => 'text/html',
	'png' => 'image/png',
    ];
    //auto: Google type => format
    const EXPORT_AUTO = [
	'application/vnd.google-apps.document' => 'docx',
	'application/vnd.google-apps.spreadsheet' => 'xlsx',
	'application/vnd.google-apps.presentation' => 'pptx',
	'application/vnd.google-apps.drawing' => 'png',
    ];

    //upload with conversion: extension => the Google type it becomes
    const CONVERT_TO = [
	'doc' => 'application/vnd.google-apps.document', 'docx' => 'application/vnd.google-apps.document', 'odt' => 'application/vnd.google-apps.document',
	'rtf' => 'application/vnd.google-apps.document', 'txt' => 'application/vnd.google-apps.document', 'html' => 'application/vnd.google-apps.document',
	'htm' => 'application/vnd.google-apps.document', 'md' => 'application/vnd.google-apps.document',
	'xls' => 'application/vnd.google-apps.spreadsheet', 'xlsx' => 'application/vnd.google-apps.spreadsheet', 'ods' => 'application/vnd.google-apps.spreadsheet',
	'csv' => 'application/vnd.google-apps.spreadsheet', 'tsv' => 'application/vnd.google-apps.spreadsheet',
	'ppt' => 'application/vnd.google-apps.presentation', 'pptx' => 'application/vnd.google-apps.presentation', 'odp' => 'application/vnd.google-apps.presentation',
    ];

    const ROLES = [ 'reader' => 'Viewer', 'commenter' => 'Commenter', 'writer' => 'Editor' ];
    const SHARE_WITH = [ 'user' => 'A person (address)', 'group' => 'A Google group (address)', 'domain' => 'Everyone in a domain', 'anyone' => 'Anyone with the link' ];
    const SOURCES = [ 'file' => 'A project file', 'text' => 'A text' ];

    const FOLDER = 'application/vnd.google-apps.folder';
    const API = 'https://www.googleapis.com/drive/v3';
    const UPLOAD_API = 'https://www.googleapis.com/upload/drive/v3';
    //the fields of a file asked from the API
    const FIELDS = 'id,name,mimeType,size,createdTime,modifiedTime,parents,webViewLink,webContentLink,owners(emailAddress),trashed';

    const MAX_RESULTS = 1000;
    const MAX_SIZE_MB = 200;
    //transfers (download / upload) may take longer than the API calls (NodeAppHttp::MAX_TIMEOUT)
    const MAX_TIMEOUT = 600;

    public static function configSchema() {
	//CLD: 2026-10-24T12:47:49+03:00: the help texts without their "; {{ ... }} for expressions" ending (the expression inputs suggest as one types; less height in the wide dialog)
	$op = fn($ops) => [ 'key' => 'operation', 'operator' => 'in', 'value' => $ops ];

	return [
	    static::credentialField(),
	    static::operationField(),
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide, on a new line; shorter help (was: The file id or its Drive link (https://drive.google.com/file/d/...); {{ ... }} for expressions, e.g. {{ drive[0].id }})
		'key' => 'fileId',
		'type' => 'text',
		'label' => 'File',
		'default' => '',
		'help' => 'The file id or its Drive link, e.g. {{ drive[0].id }}',
		'requiredIf' => [ 'if' => 'operation', 'in' => self::ON_FILE ],
		'ui' => [ 'width' => 6, 'newRow' => true, 'placeholder' => '1AbC... or https://drive.google.com/...', 'expression' => true, 'showIf' => $op(self::ON_FILE) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide; shorter help (was: The folder id or its Drive link; empty: list: the whole Drive, upload / create: My Drive)
		'key' => 'folderId',
		'type' => 'text',
		'label' => 'Folder',
		'default' => '',
		'help' => 'Folder id or link; empty: list: all of Drive, upload / create: My Drive',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'move' ] ],
		'ui' => [ 'width' => 6, 'placeholder' => 'root, 1AbC... or https://drive.google.com/drive/folders/...', 'expression' => true, 'showIf' => $op([ 'list', 'upload', 'createFolder', 'move' ]) ],
	    ],

	    //list
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide, on a new line
		'key' => 'search',
		'type' => 'text',
		'label' => 'Name contains',
		'default' => '',
		'help' => 'Empty: any name',
		'ui' => [ 'width' => 6, 'newRow' => true, 'expression' => true, 'showIf' => $op([ 'list' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide (was 6)
		'key' => 'fileType',
		'type' => 'select',
		'label' => 'Type',
		'required' => true,
		'default' => 'any',
		'choices' => self::FILE_TYPES,
		'ui' => [ 'width' => 3, 'showIf' => $op([ 'list' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide (was 6)
		'key' => 'orderBy',
		'type' => 'select',
		'label' => 'Order',
		'required' => true,
		'default' => 'modifiedTime desc',
		'choices' => self::ORDER,
		'ui' => [ 'width' => 3, 'showIf' => $op([ 'list' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: shorter help (was: More conditions in the Drive search syntax (q), e.g. modifiedTime > '2026-01-01T00:00:00' and starred = true)
		'key' => 'query',
		'type' => 'text',
		'label' => 'Drive query',
		'default' => '',
		'help' => 'More conditions (Drive\'s q syntax), e.g. modifiedTime > \'2026-01-01T00:00:00\' and starred = true',
		'ui' => [ 'expression' => true, 'showIf' => $op([ 'list' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: a limit (the last line)
		'key' => 'maxResults',
		'type' => 'number',
		'label' => 'Largest number of results',
		'required' => true,
		'default' => 100,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_RESULTS, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_RESULTS ] ],
		'ui' => [ 'limit' => true, 'step' => 1, 'width' => 6, 'showIf' => $op([ 'list' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide, on a new line
		'key' => 'includeTrashed',
		'type' => 'switch',
		'label' => 'Include files in the trash',
		'default' => false,
		'ui' => [ 'width' => 3, 'newRow' => true, 'showIf' => $op([ 'list' ]) ],
	    ],

	    //download
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide, on a new line; shorter help (was: Docs, Sheets, Slides and Drawings have no file of their own: they are exported (10 MB at most); other files are downloaded as they are)
		'key' => 'exportFormat',
		'type' => 'select',
		'label' => 'Google files as',
		'required' => true,
		'default' => 'auto',
		'choices' => self::EXPORT_FORMATS,
		'help' => 'Docs, Sheets, Slides, Drawings are exported (10 MB at most)',
		'ui' => [ 'width' => 6, 'newRow' => true, 'showIf' => $op([ 'download' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide; shorter help (was: The name of the saved file; empty: the name in Drive (+ the extension of the export))
		'key' => 'filename',
		'type' => 'text',
		'label' => 'Save as',
		'default' => '',
		'help' => 'Empty: the name in Drive (+ the export\'s extension)',
		'ui' => [ 'width' => 6, 'placeholder' => 'report-{{ id }}.pdf', 'expression' => true, 'showIf' => $op([ 'download' ]) ],
	    ],

	    //upload
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide (was 6), on a new line
		'key' => 'source',
		'type' => 'select',
		'label' => 'Upload',
		'required' => true,
		'default' => 'file',
		'choices' => self::SOURCES,
		'ui' => [ 'width' => 3, 'newRow' => true, 'showIf' => $op([ 'upload' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide (was 6); shorter help (was: Word, text, HTML -> Docs; Excel, CSV -> Sheets; PowerPoint -> Slides)
		'key' => 'convert',
		'type' => 'switch',
		'label' => 'Convert to a Google file',
		'default' => false,
		'help' => 'To Docs / Sheets / Slides',
		'ui' => [ 'width' => 3, 'showIf' => $op([ 'upload' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide; shorter help (was: Upload: the name in Drive (empty: the project file's name); create folder: the folder's name)
		'key' => 'name',
		'type' => 'text',
		'label' => 'Name',
		'default' => '',
		'help' => 'Upload: the name in Drive (empty: the file\'s); folder: its name',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'createFolder' ] ],
		'ui' => [ 'width' => 6, 'placeholder' => 'notes-{{ id }}.txt', 'expression' => true, 'showIf' => $op([ 'upload', 'createFolder' ]) ],
	    ],
	    [
		'key' => 'sourceFile',
		'type' => 'text',
		'label' => 'Project file',
		'default' => '',
		'help' => 'The id of a file of the project, e.g. {{ file.id }} (Download File) or {{ mail.attachment_files[0] }}',
		'ui' => [ 'placeholder' => '{{ file.id }}', 'expression' => true, 'showIf' => $op([ 'upload' ]), 'hideIf' => [ 'key' => 'source', 'operator' => 'eq', 'value' => 'text' ] ],
	    ],
	    [
		'key' => 'content',
		'type' => 'textarea',
		'label' => 'Text',
		'default' => '',
		'help' => '{{ ... }} for expressions (an object / list is written as JSON)',
		'ui' => [ 'rows' => 6, 'expression' => true, 'showIf' => $op([ 'upload' ]), 'hideIf' => [ 'key' => 'source', 'operator' => 'eq', 'value' => 'file' ] ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: a limit (the last line)
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
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide (was 6), on a new line
		'key' => 'shareWith',
		'type' => 'select',
		'label' => 'Share with',
		'required' => true,
		'default' => 'user',
		'choices' => self::SHARE_WITH,
		'ui' => [ 'width' => 3, 'newRow' => true, 'showIf' => $op([ 'share' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide (was 6)
		'key' => 'role',
		'type' => 'select',
		'label' => 'As',
		'required' => true,
		'default' => 'reader',
		'choices' => self::ROLES,
		'ui' => [ 'width' => 3, 'showIf' => $op([ 'share' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide
		'key' => 'email',
		'type' => 'text',
		'label' => 'Address',
		'default' => '',
		'help' => '{{ ... }} for expressions',
		'ui' => [ 'width' => 6, 'placeholder' => 'someone@example.com', 'expression' => true, 'showIf' => $op([ 'share' ]), 'hideIf' => [ 'key' => 'shareWith', 'operator' => 'in', 'value' => [ 'domain', 'anyone' ] ] ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide
		'key' => 'domain',
		'type' => 'text',
		'label' => 'Domain',
		'default' => '',
		'ui' => [ 'width' => 6, 'placeholder' => 'example.com', 'expression' => true, 'showIf' => [ 'key' => 'shareWith', 'operator' => 'eq', 'value' => 'domain' ],
		    'hideIf' => [ 'key' => 'operation', 'operator' => 'ne', 'value' => 'share' ] ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide, on a new line
		'key' => 'notify',
		'type' => 'switch',
		'label' => 'Send a notification email',
		'default' => true,
		'ui' => [ 'width' => 3, 'newRow' => true, 'showIf' => $op([ 'share' ]), 'hideIf' => [ 'key' => 'shareWith', 'operator' => 'in', 'value' => [ 'domain', 'anyone' ] ] ],
	    ],
	    [
		'key' => 'message',
		'type' => 'textarea',
		'label' => 'Message in the email',
		'default' => '',
		'ui' => [ 'rows' => 3, 'expression' => true, 'showIf' => [ 'key' => 'notify', 'operator' => 'eq', 'value' => true ],
		    'hideIf' => [ 'key' => 'operation', 'operator' => 'ne', 'value' => 'share' ] ],
	    ],

	    //delete
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide, on a new line
		'key' => 'permanent',
		'type' => 'switch',
		'label' => 'Delete permanently (not to the trash)',
		'default' => false,
		'ui' => [ 'width' => 3, 'newRow' => true, 'showIf' => $op([ 'delete' ]) ],
	    ],

	    //CLD: 2026-10-24T12:46:54+03:00: shorter help (the narrow columns of the wide dialog; was: static::timeoutField(120, self::MAX_TIMEOUT, 'Of each request to Drive (API calls: ' . \models\node\NodeAppHttp::MAX_TIMEOUT . ' at most)'))
	    static::timeoutField(120, self::MAX_TIMEOUT, 'Of each request'),
	    //CLD: 2026-10-24T12:46:54+03:00: shorter help (the narrow columns of the wide dialog; was: List: a list of files; download: the saved project file (its Drive file in `drive`); else the Drive file)
	    static::variableField('Files, the saved file, or the Drive file'),
	    //CLD: 2026-10-24T12:46:54+03:00: shorter help (the narrow columns of the wide dialog; was: An error from Google Drive (e.g. not found, no access), a file larger than the limit, or a type that may not be saved in the project)
	    static::onErrorField('Drive errors, too large, type not allowed'),
	];
    }

    //the credential of the project; what one setting requires of another (the designer's conditions are on one setting each)
    public function validates($options = []) {
	$valid = parent::validates($options);
	$fail = function($message) use (&$valid) {
	    $this->errors('config', $message);
	    $valid = false;
	};

	$operation = $this->cfg('operation', 'list');
	$empty = fn($key) => trim((string)$this->cfg($key, '')) === '';
	if ($operation === 'upload') {
	    if ($this->cfg('source', 'file') === 'file' && $empty('sourceFile'))
		$fail('Project file: required');
	    if ($this->cfg('source', 'file') === 'text' && $empty('name'))
		$fail('Name: required for a text');
	}
	if ($operation === 'share') {
	    $with = $this->cfg('shareWith', 'user');
	    if (in_array($with, [ 'user', 'group' ], true) && $empty('email'))
		$fail('Address: required');
	    if ($with === 'domain' && $empty('domain'))
		$fail('Domain: required');
	}
	return $valid;
    }

    //the type of a Drive file object (fileInfo())
    public static function fileType() {
	return [
	    'type' => 'object',
	    'info' => 'a Drive file: { id, name, mimeType, size, createdTime, modifiedTime, parents, webViewLink, webContentLink, owner, isFolder, isGoogleFile, trashed }',
	    'fields' => [
		'id' => [ 'type' => 'string' ],
		'name' => [ 'type' => 'string' ],
		'mimeType' => [ 'type' => 'string', 'info' => 'e.g. application/pdf, application/vnd.google-apps.folder' ],
		'size' => [ 'type' => 'number', 'info' => 'bytes (null for Google files and folders)' ],
		'createdTime' => [ 'type' => 'string', 'info' => 'ISO 8601' ],
		'modifiedTime' => [ 'type' => 'string', 'info' => 'ISO 8601' ],
		'parents' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'info' => 'the folder ids' ],
		'webViewLink' => [ 'type' => 'string', 'info' => 'the link that opens it in Drive' ],
		'webContentLink' => [ 'type' => 'string', 'info' => 'the download link (files only)' ],
		'owner' => [ 'type' => 'string', 'info' => 'the owner\'s address' ],
		'isFolder' => [ 'type' => 'boolean' ],
		'isGoogleFile' => [ 'type' => 'boolean', 'info' => 'a Google Doc, Sheet, Slides, ... (exported on download)' ],
		'trashed' => [ 'type' => 'boolean' ],
	    ],
	];
    }

    //the variable, by operation
    public static function outputVariables($config) {
	$config = is_array($config) ? $config : [];
	$name = static::variableName($config);
	if ($name === null)
	    return [];
	$file = self::fileType();
	switch($config['operation'] ?? 'list') {
	    case 'list':
		return [ $name => [ 'type' => 'array', 'info' => 'the files found (empty when none): [ { id, name, mimeType, webViewLink, ... } ]', 'items' => $file ] ];
	    case 'download':
		return [ $name => [ 'type' => 'object', 'info' => 'the saved project file: { id, filename, size, contentType, isImage, width, height, imageType, drive }', 'fields' => [
		    'id' => [ 'type' => 'string', 'info' => 'the project file id' ], 'filename' => [ 'type' => 'string' ], 'size' => [ 'type' => 'number', 'info' => 'bytes' ],
		    'contentType' => [ 'type' => 'string' ], 'isImage' => [ 'type' => 'boolean', 'info' => 'an image, by its content' ],
		    'width' => [ 'type' => 'number', 'info' => 'pixels (images; null for SVG)' ], 'height' => [ 'type' => 'number', 'info' => 'pixels (images; null for SVG)' ],
		    'imageType' => [ 'type' => 'string', 'info' => 'the real image type, e.g. image/png' ], 'drive' => $file ] ] ];
	    case 'share':
		$file['info'] .= ' + permission { id, role, type }';
		$file['fields']['permission'] = [ 'type' => 'object', 'fields' => [ 'id' => [ 'type' => 'string' ], 'role' => [ 'type' => 'string' ], 'type' => [ 'type' => 'string' ] ] ];
		return [ $name => $file ];
	    case 'delete':
		return [ $name => [ 'type' => 'object', 'info' => 'the deleted file: { id, permanent }', 'fields' => [ 'id' => [ 'type' => 'string' ], 'permanent' => [ 'type' => 'boolean' ] ] ] ];
	}
	return [ $name => $file ];
    }

    // ------------------------------------------------------------------------------------
    //running

    //CLD: 2026-10-20T14:00:00+03:00: the operation (NodeAppGoogle::execute runs it: the variable, the log, On error)
    protected function runOperation($operation, \Closure $text, \Closure $value) {
	return match($operation) {
	    'list' => $this->opList($text),
	    'get' => $this->opGet($text),
	    'download' => $this->opDownload($text),
	    'upload' => $this->opUpload($text, $value),
	    'createFolder' => $this->opCreateFolder($text),
	    'move' => $this->opMove($text),
	    'share' => $this->opShare($text),
	    'delete' => $this->opDelete($text),
	};
    }

    protected function opList(\Closure $text) {
	$q = $this->cfg('includeTrashed', false) ? [] : [ 'trashed = false' ];
	$folder = self::driveId($text('folderId'));
	if ($folder !== '')
	    $q[] = "'" . self::quote($folder) . "' in parents";
	$search = trim($text('search'));
	if ($search !== '')
	    $q[] = "name contains '" . self::quote($search) . "'";
	$type = match((string)$this->cfg('fileType', 'any')) {
	    'files' => "mimeType != '" . self::FOLDER . "'",
	    'folder' => "mimeType = '" . self::FOLDER . "'",
	    'document', 'spreadsheet', 'presentation' => "mimeType = 'application/vnd.google-apps." . $this->cfg('fileType') . "'",
	    'pdf' => "mimeType = 'application/pdf'",
	    'image' => "mimeType contains 'image/'",
	    default => null,
	};
	if ($type !== null)
	    $q[] = $type;
	$more = trim($text('query'));
	if ($more !== '')
	    $q[] = "({$more})";

	$max = max(1, min(self::MAX_RESULTS, (int)$this->cfg('maxResults', 100)));
	$orderBy = (string)$this->cfg('orderBy', 'modifiedTime desc');
	$files = [];
	$token = null;
	do {
	    $page = $this->api('GET', '/files', [
		'q' => implode(' and ', $q),
		'pageSize' => min(1000, $max - count($files)),
		'orderBy' => isset(self::ORDER[$orderBy]) ? $orderBy : 'modifiedTime desc',
		'fields' => 'nextPageToken,files(' . self::FIELDS . ')',
		'includeItemsFromAllDrives' => 'true',
		'corpora' => 'allDrives',
	    ] + ($token ? [ 'pageToken' => $token ] : []));
	    foreach((array)($page['files'] ?? []) as $f)
		$files[] = self::fileInfo($f);
	    $token = $page['nextPageToken'] ?? null;
	} while ($token && count($files) < $max);

	$files = array_slice($files, 0, $max);
	return [ $files, count($files) . ' file(s)' . ($folder !== '' ? " in folder {$folder}" : '') ];
    }

    protected function opGet(\Closure $text) {
	$file = self::fileInfo($this->api('GET', '/files/' . $this->fileIdOf($text('fileId')), [ 'fields' => self::FIELDS ]));
	return [ $file, "`{$file['name']}` ({$file['mimeType']})" ];
    }

    protected function opDownload(\Closure $text) {
	$id = $this->fileIdOf($text('fileId'));
	$drive = self::fileInfo($this->api('GET', "/files/{$id}", [ 'fields' => self::FIELDS ]));
	if ($drive['isFolder'])
	    throw new \RuntimeException("`{$drive['name']}` is a folder");

	$filename = trim($text('filename'));
	if ($drive['isGoogleFile']) {
	    $format = (string)$this->cfg('exportFormat', 'auto');
	    if ($format === 'auto')
		$format = self::EXPORT_AUTO[$drive['mimeType']] ?? 'pdf';
	    if (!isset(self::EXPORT_MIME[$format]))
		throw new \RuntimeException("unknown export format `{$format}`");
	    $url = self::API . "/files/{$id}/export?" . http_build_query([ 'mimeType' => self::EXPORT_MIME[$format] ], '', '&', PHP_QUERY_RFC3986);
	    $contentType = self::EXPORT_MIME[$format];
	    if ($filename === '')
		$filename = str_ends_with(strtolower($drive['name']), ".{$format}") ? $drive['name'] : "{$drive['name']}.{$format}";
	} else {
	    $url = self::API . "/files/{$id}?alt=media&supportsAllDrives=true";
	    $contentType = $drive['mimeType'];
	    if ($filename === '')
		$filename = $drive['name'];
	}
	//sanitized, an extension from the type when it has none (as Download File)
	$filename = \models\node\NodeAppDownload::filename($filename, '', '', $contentType);
	if (!\models\File::isAllowedName($filename))
	    throw new \RuntimeException("file type not allowed: `{$filename}` (allowed: " . implode(', ', array_keys(\models\File::$extensions)) . ')');

	$maxBytes = $this->maxBytes();
	if ($drive['size'] !== null && $drive['size'] > $maxBytes)
	    throw new \RuntimeException("`{$drive['name']}` is larger than " . ($maxBytes >> 20) . ' MB');
	[ $stream, $size, $type ] = $this->fetch($url, $maxBytes);

	rewind($stream);
	$image = \models\node\NodeAppDownload::imageInfo((string)fread($stream, \models\node\NodeAppDownload::IMAGE_SNIFF));
	rewind($stream);
	$fid = \models\File::createFromStream($filename, $stream, [ 'metadata' => [
	    'project' => $this->exec->project,
	    'aid' => $this->exec->aid,
	    'owner' => null,
	    'source' => 'google-drive',
	    'wf' => $this->exec->wf,
	    'created_at' => new \MongoDB\BSON\UTCDateTime(),
	] ]);
	fclose($stream);

	$file = [ 'id' => (string)$fid, 'filename' => $filename, 'size' => $size, 'contentType' => $type ?: $contentType ] + $image + [ 'drive' => $drive ];
	return [ $file, "`{$drive['name']}` saved as `{$filename}` ({$size} bytes)" ];
    }

    protected function opUpload(\Closure $text, \Closure $value) {
	$maxBytes = $this->maxBytes();
	$name = trim($text('name'));
	if ($this->cfg('source', 'file') === 'text') {
	    $content = $value('content');
	    $content = is_array($content) ? json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) : \util\Expression::toText($content);
	    if ($name === '')
		throw new \ErrorException('Google Drive: upload: a text needs a name');
	    if (strlen($content) > $maxBytes)
		throw new \RuntimeException('the text is larger than ' . ($maxBytes >> 20) . ' MB');
	    $size = strlen($content);
	    $stream = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
	    fwrite($stream, $content);
	    rewind($stream);
	} else {
	    $fileId = trim($text('sourceFile'));
	    $om = \V::app()->om;
	    $file = \util\Validator::rule('oid', $fileId) ? $om->findOne('File', [ '_id' => $fileId, 'metadata.aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]) : null;
	    if (empty($file))
		throw new \RuntimeException("no project file `" . mb_substr($fileId, 0, 60) . '`');
	    $size = (int)$file->length;
	    if ($size > $maxBytes)
		throw new \RuntimeException("`{$file->filename}` is larger than " . ($maxBytes >> 20) . ' MB');
	    if ($name === '')
		$name = (string)$file->filename;
	    $stream = \models\File::_bucket()->openDownloadStream($file->_id);
	}

	$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
	$contentType = self::UPLOAD_TYPES[$ext] ?? 'application/octet-stream';
	$meta = [ 'name' => $name ];
	$folder = self::driveId($text('folderId'));
	if ($folder !== '')
	    $meta['parents'] = [ $this->checkId($folder) ];
	if ($this->cfg('convert', false) && isset(self::CONVERT_TO[$ext])) {
	    $meta['mimeType'] = self::CONVERT_TO[$ext];
	    //the Google file is named without the extension
	    $meta['name'] = pathinfo($name, PATHINFO_FILENAME) ?: $name;
	}

	try {
	    //a resumable upload: the session (metadata), then the content in one request (any size, unlike multipart: 5 MB)
	    $session = \models\node\NodeAppHttp::request('POST', self::UPLOAD_API . '/files?' . http_build_query([ 'uploadType' => 'resumable', 'supportsAllDrives' => 'true', 'fields' => self::FIELDS ], '', '&', PHP_QUERY_RFC3986),
		array_merge($this->authHeaders(), [ 'Content-Type: application/json; charset=UTF-8', "X-Upload-Content-Type: {$contentType}", "X-Upload-Content-Length: {$size}" ]),
		json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->apiTimeout());
	    $location = $session['headers']['location'] ?? null;
	    if (!$session['ok'] || !is_string($location) || !str_starts_with($location, self::UPLOAD_API))
		throw new \RuntimeException(self::errorText($session['status'], $session['body'], $session['error'] ?? 'no upload session'));
	    $drive = self::fileInfo($this->put($location, $stream, $size, $contentType));
	} finally {
	    fclose($stream);
	}
	return [ $drive, "`{$drive['name']}` uploaded ({$size} bytes" . (isset($meta['mimeType']) ? ', converted' : '') . ')' ];
    }

    protected function opCreateFolder(\Closure $text) {
	$name = trim($text('name'));
	if ($name === '')
	    throw new \ErrorException('Google Drive: create folder: the folder needs a name');
	$meta = [ 'name' => $name, 'mimeType' => self::FOLDER ];
	$parent = self::driveId($text('folderId'));
	if ($parent !== '')
	    $meta['parents'] = [ $this->checkId($parent) ];
	$folder = self::fileInfo($this->api('POST', '/files', [ 'fields' => self::FIELDS ], $meta));
	return [ $folder, "folder `{$folder['name']}` created ({$folder['id']})" ];
    }

    protected function opMove(\Closure $text) {
	$id = $this->fileIdOf($text('fileId'));
	$to = $this->checkId(self::driveId($text('folderId')));
	$current = (array)($this->api('GET', "/files/{$id}", [ 'fields' => 'parents' ])['parents'] ?? []);
	$remove = implode(',', array_diff($current, [ $to ]));
	$file = self::fileInfo($this->api('PATCH', "/files/{$id}", [ 'addParents' => $to, 'fields' => self::FIELDS ] + ($remove !== '' ? [ 'removeParents' => $remove ] : []), (object)[]));
	return [ $file, "`{$file['name']}` moved to folder {$to}" ];
    }

    protected function opShare(\Closure $text) {
	$id = $this->fileIdOf($text('fileId'));
	$with = (string)$this->cfg('shareWith', 'user');
	if (!isset(self::SHARE_WITH[$with]))
	    throw new \ErrorException("Google Drive: share: unknown `{$with}`");
	$role = isset(self::ROLES[$this->cfg('role')]) ? (string)$this->cfg('role') : 'reader';
	$permission = [ 'type' => $with, 'role' => $role ];
	$query = [ 'fields' => 'id,role,type' ];
	$to = 'anyone with the link';
	if (in_array($with, [ 'user', 'group' ], true)) {
	    $to = trim($text('email'));
	    if (!filter_var($to, FILTER_VALIDATE_EMAIL))
		throw new \RuntimeException('not an email address: `' . mb_substr($to, 0, 100) . '`');
	    $permission['emailAddress'] = $to;
	    $notify = (bool)$this->cfg('notify', true);
	    $query['sendNotificationEmail'] = $notify ? 'true' : 'false';
	    $message = trim($text('message'));
	    if ($notify && $message !== '')
		$query['emailMessage'] = $message;
	} elseif ($with === 'domain') {
	    $to = strtolower(trim($text('domain')));
	    if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $to))
		throw new \RuntimeException('not a domain: `' . mb_substr($to, 0, 100) . '`');
	    $permission['domain'] = $to;
	}
	$p = $this->api('POST', "/files/{$id}/permissions", $query, $permission);
	$file = self::fileInfo($this->api('GET', "/files/{$id}", [ 'fields' => self::FIELDS ]));
	$file['permission'] = [ 'id' => (string)($p['id'] ?? ''), 'role' => (string)($p['role'] ?? $role), 'type' => (string)($p['type'] ?? $with) ];
	return [ $file, "`{$file['name']}` shared with {$to} as " . self::ROLES[$role] ];
    }

    protected function opDelete(\Closure $text) {
	$id = $this->fileIdOf($text('fileId'));
	$permanent = (bool)$this->cfg('permanent', false);
	if ($permanent)
	    $this->api('DELETE', "/files/{$id}");
	else
	    $this->api('PATCH', "/files/{$id}", [ 'fields' => 'id' ], [ 'trashed' => true ]);
	return [ [ 'id' => $id, 'permanent' => $permanent ], "{$id} " . ($permanent ? 'deleted permanently' : 'moved to the trash') ];
    }

    // ------------------------------------------------------------------------------------
    //the Drive API

    protected function maxBytes() {
	return max(1, min(self::MAX_SIZE_MB, (int)$this->cfg('maxSize', 25))) * 1024 * 1024;
    }

    //CLD: 2026-10-20T14:00:00+03:00: NodeAppGoogle::api, shared drives included
    protected function api($method, $path, array $query = [], $json = null, $maxBytes = self::MAX_ANSWER) {
	return parent::api($method, $path, $query + [ 'supportsAllDrives' => 'true' ], $json, $maxBytes);
    }

    //GET $url into a temporary stream (not kept in memory): [ stream, size, content type ]
    protected function fetch($url, $maxBytes) {
	$stream = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
	//CLD: 2026-10-23T15:00:00+03:00: through util\Http (was curl here)
	$r = \util\Http::request('GET', $url, [ 'headers' => $this->authHeaders(), 'timeout' => max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 120))), 'maxBytes' => $maxBytes, 'sink' => $stream, 'redirects' => 5, 'https' => true ]);
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

    //PUT the $size bytes of $stream to the upload session $url: the decoded answer (the file)
    protected function put($url, $stream, $size, $contentType) {
	//CLD: 2026-10-23T15:00:00+03:00: through util\Http (was curl here)
	$r = \util\Http::request('PUT', $url, [ 'headers' => [ "Content-Type: {$contentType}" ], 'upload' => [ $stream, $size ], 'timeout' => max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 120))), 'https' => true ]);
	[ $answer, $status, $curlError ] = [ $r['body'], $r['status'], (string)$r['error'] ];
	$data = json_decode((string)$answer, true);
	if ($curlError !== '' || $status >= 400 || !is_array($data))
	    throw new \RuntimeException(self::errorText($status, $data, $curlError !== '' ? $curlError : "HTTP status {$status}"));
	return $data;
    }

    //a file object of the step from the API's file
    public static function fileInfo($f) {
	$f = is_array($f) ? $f : [];
	$mime = (string)($f['mimeType'] ?? '');
	return [
	    'id' => (string)($f['id'] ?? ''),
	    'name' => (string)($f['name'] ?? ''),
	    'mimeType' => $mime,
	    'size' => isset($f['size']) ? (int)$f['size'] : null,
	    'createdTime' => $f['createdTime'] ?? null,
	    'modifiedTime' => $f['modifiedTime'] ?? null,
	    'parents' => array_values(array_map('strval', (array)($f['parents'] ?? []))),
	    'webViewLink' => $f['webViewLink'] ?? null,
	    'webContentLink' => $f['webContentLink'] ?? null,
	    'owner' => $f['owners'][0]['emailAddress'] ?? null,
	    'isFolder' => $mime === self::FOLDER,
	    'isGoogleFile' => $mime !== self::FOLDER && str_starts_with($mime, 'application/vnd.google-apps.'),
	    'trashed' => !empty($f['trashed']),
	];
    }

    //$id when it can be a Drive id (it goes into a URL path and into queries)
    protected function checkId($id) {
	if (!preg_match('/^[A-Za-z0-9_-]{1,200}$/', (string)$id))
	    throw new \RuntimeException('not a Drive file id: `' . mb_substr((string)$id, 0, 100) . '`');
	return $id;
    }

    protected function fileIdOf($value) {
	$id = self::driveId($value);
	if ($id === '')
	    throw new \RuntimeException('no file given');
	return $this->checkId($id);
    }

    //a text inside '...' of a Drive query
    public static function quote($text) {
	return str_replace([ '\\', "'" ], [ '\\\\', "\\'" ], (string)$text);
    }
}
