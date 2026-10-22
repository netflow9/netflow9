<?php

namespace models;

class File extends \sys\BaseModel {
    //CLD: 2026-10-12T12:18:11+03:00: files of a project / an account (stored in metadata; GridFS files collection) (created by `v setup::indexes`)
    public static $indexes = [
	[ [ 'metadata.project' => 1 ], [] ],
	[ [ 'metadata.aid' => 1 ], [] ],
    ];
    protected static $bucketName = 'filestore';
    protected static $chunkSizeBytes = 128 * 1024;

    protected static $bucket = null;

    public static function _collection() {
	return static::$bucketName . '.files';
    }

    //CLD: 2026-10-06T21:33:37+03:00: also the allow-list of uploadable files (isAllowedName / acceptAttribute): images, documents, presentations,
    //spreadsheets, text files and PDFs; anything else (archives, executables, scripts, unknown types) is refused
    //extension => type shown in the list
    public static $extensions = [
	//images
	'png' => 'Image',
	'jpg' => 'Image',
	'jpeg' => 'Image',
	'gif' => 'Image',
	'webp' => 'Image',
	'bmp' => 'Image',
	'tif' => 'Image',
	'tiff' => 'Image',
	'svg' => 'Image',
	'heic' => 'Image',
	//pdf
	'pdf' => 'PDF',
	//documents, presentations
	'doc' => 'Word',
	'docx' => 'Word',
	'odt' => 'Document',
	'rtf' => 'Document',
	'ppt' => 'PowerPoint',
	'pptx' => 'PowerPoint',
	'odp' => 'Presentation',
	//spreadsheets
	'xls' => 'Excel',
	'xlsx' => 'Excel',
	'ods' => 'Spreadsheet',
	'csv' => 'CSV',
	'tsv' => 'CSV',
	//text
	'txt' => 'Text',
	'log' => 'Text',
	'md' => 'Markdown',
	'html' => 'HTML',
	'htm' => 'HTML',
	'json' => 'JSON',
	'xml' => 'XML',
	'yaml' => 'YAML',
	'yml' => 'YAML',
    ];

    public static $imageMimeTypes = [
	'png' => 'image/png',
	'jpg' => 'image/jpeg',
	'jpeg' => 'image/jpeg',
	'gif' => 'image/gif',
	'webp' => 'image/webp',
    ];


    //CLD: 2026-10-22T00:35:40+03:00: the content type of a file, by extension (else application/octet-stream): uploads, attachments, files saved by steps (was
    //models\node\NodeAppGoogle::UPLOAD_TYPES, now in the GoogleIntegration plugin)
    const CONTENT_TYPES = [
	'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
	'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'svg' => 'image/svg+xml', 'heic' => 'image/heic',
	'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'odt' => 'application/vnd.oasis.opendocument.text',
	'rtf' => 'application/rtf', 'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
	'odp' => 'application/vnd.oasis.opendocument.presentation', 'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'csv' => 'text/csv', 'tsv' => 'text/tab-separated-values', 'txt' => 'text/plain', 'log' => 'text/plain',
	'md' => 'text/markdown', 'html' => 'text/html', 'htm' => 'text/html', 'json' => 'application/json', 'xml' => 'application/xml', 'yaml' => 'application/yaml', 'yml' => 'application/yaml',
    ];

    //CLD: 2026-10-22T00:35:40+03:00: project file ids of a setting's value: one id, a list, or ids separated by commas; files of objects ({ id } of Download File / Google Drive) too
    //(was models\node\NodeAppGmail::fileIds, now in the GoogleIntegration plugin)
    public static function idsOf($value) {
	$list = is_array($value) ? (array_is_list($value) ? $value : [ $value ]) : explode(',', \util\Expression::toText($value));
	$ids = [];
	foreach($list as $v) {
	    $id = trim(is_array($v) ? (string)($v['id'] ?? $v['file'] ?? '') : \util\Expression::toText($v));
	    if ($id !== '')
		$ids[] = $id;
	}
	return array_values(array_unique($ids));
    }


    //CLD: 2026-10-06T09:09:26+03:00: metadata keys also kept at the top level of the file document (beforeSave, createFromStream)
    const TOP_LEVEL_METADATA = [ 'aid', 'project' ];


    //CLD: 2026-10-06T21:33:37+03:00: true if a file with this name may be uploaded (its extension is in $extensions)
    public static function isAllowedName($filename) {
	$ext = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
	return $ext !== '' && isset(self::$extensions[$ext]);
    }

    //CLD: 2026-10-06T21:33:37+03:00: value for <input type=file accept="...">: the allowed extensions (".png,.jpg,...")
    public static function acceptAttribute() {
	return implode(',', array_map(fn($ext) => '.' . $ext, array_keys(self::$extensions)));
    }


    //CLD: 2026-10-06T21:29:27+03:00: lower-case extension of the file name ('' if none)
    public function extension() {
	return strtolower(pathinfo((string)$this->filename, PATHINFO_EXTENSION));
    }

    //CLD: 2026-10-06T21:29:27+03:00: the image MIME type when the file can be previewed, else null
    public function imageMimeType() {
	return self::$imageMimeTypes[$this->extension()] ?? null;
    }

    public function _init() {
	parent::_init();
	$this->_config['global'] = true;

	if (!empty($this->filename)) {
	    //CLD: 2026-10-06T21:33:37+03:00: case-insensitive extension (was: "PHOTO.PNG" -> Unknown)
	    $this->type = self::$extensions[$this->extension()] ?? 'Unknown';
	}

	$this->owner = $this->metadata['owner'] ?? null;
	$this->created_at = $this->metadata['created_at'] ?? null;
	$this->updated_at = $this->metadata['updated_at'] ?? null;
    }

    public static function _bucket() {
	if (static::$bucket)
	    return static::$bucket;

	$db = \V::app()->mongo->selectDatabase(self::_database());
	static::$bucket = $db->selectGridFSBucket([
	    'bucketName' => static::$bucketName,
	    'chunkSizeBytes' => static::$chunkSizeBytes,
	    'disableMD5' => true,
	    'writeConcern' => new \MongoDB\Driver\WriteConcern('majority', 0, true),
	]);

	return static::$bucket;
    }

    public function getSchema() {
	$fields = [
	    //filename
	    [
		'key' => 'filename',
		'type' => 'text',
		'label' => 'FileName',
		'help' => false,
		'required' => true,
		'perm:read' => 'everybody',
		'perm:update' => 'permission',
	    ],
	    //length
	    [
		'key' => 'length',
		'type' => 'number',
		'label' => 'Length',
		'help' => false,
		'format' => 'bytes-human',
		'required' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'nobody',
	    ],

	    //type
	    [
		'key' => 'type',
		'type' => 'text',
		'label' => 'Type',
		'help' => false,
		'required' => false,
		'perm:read' => 'everybody',
		'perm:update' => 'nobody',
	    ],

	    //project
	    [
		'key' => 'project',
		'type' => 'object',
		'label' => 'Project',
		'object' => 'Project',
		'help' => false,
		'required' => true,
		'perm:read' => 'nobody',
		'perm:update' => 'permission',
	    ],

	    //owner
	    [
		'key' => 'owner',
		'type' => 'object',
		'label' => 'Owner',
		'object' => 'User',
		'help' => false,
		'required' => true,
		'perm:read' => 'everybody',
		'perm:update' => 'permission',
	    ],

	    //metadata
	    [
		'key' => 'metadata',
		'type' => 'array',
		'label' => 'Metadata',
		'help' => false,
		'required' => true,
		'perm:read' => 'nobody',
		//CLD: 2026-10-10T19:00:56+03:00: perm:update (perm:edit is read by nothing)
		'perm:update' => 'nobody',
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    //override delete so that we let bucket do it's job
    public function delete($options = []) {
	if (!empty($this->_id)) {
	    self::_bucket()->delete($this->_id);

	    $this->_config['exists'] = false;
	    $this->_data = [];
	    return true;
	}
	return false;
    }

    function getSizeHuman($decimals = 2) {
        $suffix = [ 'B', 'K', 'M', 'G', 'T', 'P' ];
        $factor = floor((strlen($this->length) - 1) / 3);
        return number_format($this->length / pow(1024, $factor), $decimals) . $suffix[$factor];
    }

    //specific to File
    public function getContents() {
	if (!$this->exists())
	    throw new \ErrorException('Try to get contents on a non-existing file');

	return stream_get_contents(self::_bucket()->openDownloadStream($this->_id));
    }

    public static function createFromString($filename, $content = '', $options = []) {
	$stream = fopen("php://temp", 'r+');
	fputs($stream, $content);
	rewind($stream);

	$file_id = self::createFromStream($filename, $stream, $options);
	fclose($stream);

	return $file_id;
    }

    public static function createFromStream($filename, $stream, $options = []) {
	if (!is_resource($stream))
	    throw new \ErrorException('stream is not a resource');

	//CLD: 2026-10-06T09:07:47+03:00: GridFS only takes custom data in `metadata`: copy metadata.aid to a top-level `aid`
	//on the stored file document right after the upload (as File::beforeSave does on model saves)
	$file_id = self::_bucket()->uploadFromStream($filename, $stream, $options);

	//CLD: 2026-10-06T09:09:26+03:00: same for `project` (was aid only)
	$set = [];
	foreach(self::TOP_LEVEL_METADATA as $key) {
	    if (!empty($options['metadata'][$key]))
		$set[$key] = $options['metadata'][$key];
	}
	if (!empty($set))
	    \V::app()->mongo->selectCollection(self::_database(), self::_collection())->updateOne(
		[ '_id' => $file_id ],
		[ '$set' => $set ]
	    );

	return $file_id;
    }

    //CLD: 2026-10-06T09:05:51+03:00: keep a top-level `aid` (queried by generic code, e.g. Field_object::objectChoices),
    //taken from metadata.aid where GridFS uploads store the account
    public function beforeSave() {
	parent::beforeSave();

	//CLD: 2026-10-06T09:09:26+03:00: same for `project` (was aid only)
	foreach(self::TOP_LEVEL_METADATA as $key) {
	    if (empty($this->$key) && !empty($this->metadata[$key]))
		$this->$key = $this->metadata[$key];
	}
    }


    //CLD: 2026-10-06T21:53:44+03:00: access to a file (was BaseModel's default: always false, so project-scoped roles - Members - got 403 on delete):
    //role scope `global` -> yes; `project` -> only if the user is a member of the file's project (same account); else no.
    //AAA::checkAccess passes the full name (File::delete), field checks the short one (read, update)
    public function checkAccess($userId, $permName, $params = []) {
	if (strpos($permName, '::') === false)
	    $permName = 'File::' . $permName;

	$scope = \V::app()->auth->getUserRole()->getPermissionScope($permName);
	if ($scope == 'global')
	    return true;
	if ($scope != 'project')
	    return false;

	//CLD: 2026-10-06T23:07:56+03:00: shared Project::isMember
	return \models\Project::isMember($this->metadata['project'] ?? $this->project, $this->metadata['aid'] ?? $this->aid, $userId);
    }

    //CLD: 2026-10-06T08:53:22+03:00: label of a file (e.g. in object fields)
    public function __toString() {
	return (string)($this->filename ?? '');
    }

}