<?php

//CLD: 2026-10-12T08:46:28+03:00: new step: Download File - downloads a file over HTTP (GET) and saves it in the project's Files (models\File, GridFS; metadata: project, aid,
//source `download`, workflow). Settings as the HTTP Request step it extends (URL, credential, query parameters, headers, timeout, on error) + the file name
//(an expression; empty: from the response's Content-Disposition, else from the URL; an extension from the content type when it has none) and the
//largest size. CLD: 2026-10-12T08:55:21+03:00: only file types that may be uploaded (File::$extensions), else an error. The variable (default `file`) gets { id, filename, size, contentType }. The download goes to a temporary stream (not kept in memory)

//CLD: 2026-10-17T13:24:18+03:00: moved from the BaseNodes plugin into the core (was plugins\BaseNodes\app)
//CLD: 2026-10-17T21:08:58+03:00: images: the URL may be a data: URL (data:image/png;base64,...: e.g. an image an API or another step returned; no request is made); the variable says whether the
//file is an image, read from its content (not its name): isImage, width, height, imageType (the real type, e.g. image/png; SVG: no size)
namespace models\node;

class NodeAppDownload extends NodeAppHttp {
    public static $name = 'download';
    public static $label = 'Download File';
    public static $icon = 'bi-cloud-download';

    //the largest size that can be chosen (MB)
    const MAX_SIZE_MB = 200;
    //CLD: 2026-10-17T21:08:58+03:00: the start of the file read to recognize an image and its size
    const IMAGE_SNIFF = 524288;

    //an extension for a name without one, from the content type
    const EXTENSIONS = [
	'application/pdf' => 'pdf',
	'image/png' => 'png',
	'image/jpeg' => 'jpg',
	'image/gif' => 'gif',
	'image/webp' => 'webp',
	'image/svg+xml' => 'svg',
	'text/csv' => 'csv',
	'text/plain' => 'txt',
	'application/json' => 'json',
	'application/zip' => 'zip',
	'text/html' => 'html',
	'application/xml' => 'xml',
	'text/xml' => 'xml',
	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
	'application/vnd.ms-excel' => 'xls',
	'application/msword' => 'doc'
    ];

    public static function configSchema() {
	$http = [];
	foreach(parent::configSchema() as $f)
	    $http[$f['key']] = $f;

	//CLD: 2026-10-17T21:08:58+03:00: + data: URLs
	//CLD: 2026-10-24T12:55:47+03:00: shorter (was: The file to download (GET), http:// or https://, or a data: URL (data:image/png;base64,...); {{ ... }} for expressions); 8 wide, next to the
	//credential (HTTP's: 4), on line 2; the query parameters on a new line
	$http['url']['help'] = 'The file (GET): http(s):// or a data: URL';
	$http['url']['ui']['width'] = 8;
	$http['query']['ui']['newRow'] = true;
	$http['timeout']['default'] = 120;
	$http['variable']['label'] = 'File variable';
	$http['variable']['default'] = 'file';
	//CLD: 2026-10-24T12:55:47+03:00: shorter (was: No answer, timeout, a status of 400 or more, or a file larger than the limit)
	$http['onError']['help'] = 'No answer, timeout, status 400+, too large';

	return [
	    $http['url'],
	    $http['credential'],
	    $http['query'],
	    $http['headers'],
	    [
		'key' => 'filename',
		'type' => 'text',
		'label' => 'File name',
		'help' => 'Empty: the name the server gives, else the last part of the URL',
		'ui' => [ 'placeholder' => 'report-{{ id }}.pdf', 'expression' => true ],
	    ],
	    [
		'key' => 'maxSize',
		'type' => 'number',
		'label' => 'Largest size (MB)',
		'required' => true,
		'default' => 25,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_SIZE_MB, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_SIZE_MB ] ],
		//CLD: 2026-10-14T14:27:53+03:00: min / max from the validate rule (util\WidgetsUtil; was repeated here)
		//CLD: 2026-10-24T12:55:47+03:00: a limit: the designer puts it on the last line (was 6 wide)
		'ui' => [ 'limit' => true ],
	    ],
	    $http['timeout'],
	    $http['variable'],
	    $http['onError'],
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'file') : 'file';
	//CLD: 2026-10-13T12:34:29+03:00: + the fields and their types (suggested after `file.`)
	//CLD: 2026-10-17T21:08:58+03:00: + isImage, width, height, imageType
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'object', 'info' => 'the saved file: { id, filename, size, contentType, isImage, width, height, imageType }',
	    'fields' => [ 'id' => [ 'type' => 'string' ], 'filename' => [ 'type' => 'string' ], 'size' => [ 'type' => 'number', 'info' => 'bytes' ], 'contentType' => [ 'type' => 'string' ],
		'isImage' => [ 'type' => 'boolean', 'info' => 'an image, by its content' ], 'width' => [ 'type' => 'number', 'info' => 'pixels (images; null for SVG)' ],
		'height' => [ 'type' => 'number', 'info' => 'pixels (images; null for SVG)' ], 'imageType' => [ 'type' => 'string', 'info' => 'the real image type, e.g. image/png' ] ] ] ] : [];
    }

    public function execute($edgeId = null) {
	//CLD: 2026-10-13T15:24:28+03:00: + the project variables (vars)
	$vars = $this->exec->expressionVariables();
	$text = fn($v) => \util\Expression::toText(\util\Expression::render((string)$v, $vars));

	$url = trim($text($this->cfg('url', '')));
	//CLD: 2026-10-17T21:08:58+03:00: a data: URL: no request
	if (stripos($url, 'data:') === 0)
	    return $this->saveDataUrl($url, $text);
	if (!preg_match('~^https?://[^\s/?#]+~i', $url))
	    throw new \ErrorException('Download File: the URL must start with http:// or https:// or be a data: URL (got `' . mb_substr($url, 0, 100) . '`)');
	$shownUrl = preg_replace('/[?#].*$/', '', $url);

	$query = [];
	foreach((array)$this->cfg('query', []) as $row) {
	    if (($row['name'] ?? '') !== '')
		$query[$row['name']] = $text($row['value'] ?? '');
	}
	$headers = [];
	foreach((array)$this->cfg('headers', []) as $row) {
	    if (($row['name'] ?? '') !== '')
		$headers[strtolower($row['name'])] = $row['name'] . ': ' . str_replace([ "\r", "\n" ], ' ', $text($row['value'] ?? ''));
	}
	$credId = (string)$this->cfg('credential', '');
	if ($credId !== '') {
	    $cred = \models\Credential::findFor($this->exec, $credId, 'http');
	    if (empty($cred))
		throw new \ErrorException('Download File: the credential is no longer available');
	    $auth = $cred->httpAuth();
	    foreach((array)($auth['headers'] ?? []) as $name => $value)
		$headers[strtolower($name)] = $name . ': ' . $value;
	    foreach((array)($auth['query'] ?? []) as $name => $value)
		$query[$name] = (string)$value;
	}
	if ($query)
	    $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

	$maxBytes = max(1, min(self::MAX_SIZE_MB, (int)$this->cfg('maxSize', 25))) * 1024 * 1024;
	$timeout = max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 120)));
	$stream = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
	//CLD: 2026-10-23T15:00:00+03:00: through util\Http (was curl here); public addresses only unless app.env HTTP_STEPS_ALLOW_PRIVATE
	$r = \util\Http::request('GET', $url, self::guard() + [ 'headers' => array_values($headers), 'timeout' => $timeout, 'maxBytes' => $maxBytes, 'sink' => $stream, 'redirects' => 5 ]);
	[ $status, $size, $contentType, $effective, $respHeaders, $ms ] = [ $r['status'], $r['size'], $r['mime'], $r['url'], $r['headers'], $r['time_ms'] ];

	$error = null;
	if ($r['tooLarge'])
	    $error = 'the file is larger than ' . (int)($maxBytes / 1048576) . ' MB';
	elseif ($r['error'] !== null)
	    $error = $r['error'];
	elseif ($status >= 400)
	    $error = "HTTP status {$status}";

	//CLD: 2026-10-12T08:55:21+03:00: only the file types that may be uploaded (File::$extensions; was any type)
	$filename = self::filename($text($this->cfg('filename', '')), $respHeaders['content-disposition'] ?? '', $effective ?: $shownUrl, $contentType);
	if ($error === null && !\models\File::isAllowedName($filename))
	    $error = "file type not allowed: `{$filename}` (allowed: " . implode(', ', array_keys(\models\File::$extensions)) . ')';

	$name = (string)$this->cfg('variable', 'file');
	if ($error !== null) {
	    fclose($stream);
	    if (\util\Validator::isIdentifier($name))
		$this->exec->setVariable($name, null);
	    $this->exec->log($this->wfid, "download {$shownUrl} -> " . ($status ?: 'no answer') . ": {$error}");
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException("Download File: {$shownUrl}: {$error}");
	}

	//CLD: 2026-10-17T21:08:58+03:00: saved by saveFile() (+ the image info)
	$file = $this->saveFile($stream, $filename, $size, $contentType);
	$this->exec->log($this->wfid, "download {$shownUrl} -> {$status}: saved `{$filename}` ({$size} bytes, {$ms} ms" . ($file['isImage'] ? ', image' . ($file['width'] ? " {$file['width']}x{$file['height']}" : '') : '') . ')');
	return [ 0 ];
    }

    //CLD: 2026-10-17T21:08:58+03:00: the file of $stream saved in the project's Files (the stream closed); the variable set: { id, filename, size, contentType, isImage, width, height, imageType }
    protected function saveFile($stream, $filename, $size, $contentType) {
	rewind($stream);
	$image = self::imageInfo((string)fread($stream, self::IMAGE_SNIFF));
	rewind($stream);
	$id = \models\File::createFromStream($filename, $stream, [ 'metadata' => [
	    'project' => $this->exec->project,
	    'aid' => $this->exec->aid,
	    'owner' => null,
	    'source' => 'download',
	    'wf' => $this->exec->wf,
	    'created_at' => new \MongoDB\BSON\UTCDateTime(),
	] ]);
	fclose($stream);

	$file = [ 'id' => (string)$id, 'filename' => $filename, 'size' => $size, 'contentType' => $contentType ] + $image;
	$name = (string)$this->cfg('variable', 'file');
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $file);
	return $file;
    }

    //CLD: 2026-10-17T21:08:58+03:00: whether $head (the start of a file) is an image: [ isImage, width, height, imageType ] (getimagesize: PNG, JPEG, GIF, WebP, BMP, TIFF, ...; SVG by its root element, no size)
    public static function imageInfo($head) {
	$info = $head !== '' ? @getimagesizefromstring($head) : false;
	if (is_array($info) && !empty($info['mime']) && str_starts_with($info['mime'], 'image/'))
	    return [ 'isImage' => true, 'width' => (int)$info[0] ?: null, 'height' => (int)$info[1] ?: null, 'imageType' => (string)$info['mime'] ];
	if (preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*|<!DOCTYPE[^>]*>\s*)*<svg[\s>]/is', substr($head, 0, 4096)))
	    return [ 'isImage' => true, 'width' => null, 'height' => null, 'imageType' => 'image/svg+xml' ];
	return [ 'isImage' => false, 'width' => null, 'height' => null, 'imageType' => null ];
    }

    //CLD: 2026-10-17T21:08:58+03:00: a data: URL (data:[<type>][;charset=...][;base64],<data>) saved as the file: the name as for a download (no Content-Disposition, no URL name: `download` + the
    //type's extension), the same limits (largest size, allowed types) and On error
    protected function saveDataUrl($url, \Closure $text) {
	$error = null;
	$contentType = '';
	$data = '';
	if (!preg_match('~^data:([^,]*?),~is', $url, $m)) {
	    $error = 'not a valid data: URL (data:<type>;base64,<data>)';
	} else {
	    $params = array_map('trim', explode(';', $m[1]));
	    $contentType = strtolower($params[0] !== '' ? $params[0] : 'text/plain');
	    $payload = substr($url, strlen($m[0]));
	    $data = in_array('base64', array_map('strtolower', array_slice($params, 1)), true) ? base64_decode(preg_replace('/\s+/', '', $payload), true) : rawurldecode($payload);
	    $maxBytes = max(1, min(self::MAX_SIZE_MB, (int)$this->cfg('maxSize', 25))) * 1024 * 1024;
	    if ($data === false)
		$error = 'the data: URL is not valid base64';
	    elseif (strlen($data) > $maxBytes)
		$error = 'the file is larger than ' . (int)($maxBytes / 1048576) . ' MB';
	}
	$filename = self::filename($text($this->cfg('filename', '')), '', '', $contentType);
	if ($error === null && !\models\File::isAllowedName($filename))
	    $error = "file type not allowed: `{$filename}` (allowed: " . implode(', ', array_keys(\models\File::$extensions)) . ')';
	$shown = 'data:' . ($contentType ?: '?');
	if ($error !== null) {
	    $name = (string)$this->cfg('variable', 'file');
	    if (\util\Validator::isIdentifier($name))
		$this->exec->setVariable($name, null);
	    $this->exec->log($this->wfid, "download {$shown}: {$error}");
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException("Download File: {$shown}: {$error}");
	}
	$stream = fopen('php://temp/maxmemory:' . (2 * 1024 * 1024), 'w+b');
	fwrite($stream, $data);
	$file = $this->saveFile($stream, $filename, strlen($data), $contentType);
	$this->exec->log($this->wfid, "download {$shown}: saved `{$filename}` ({$file['size']} bytes" . ($file['isImage'] ? ', image' . ($file['width'] ? " {$file['width']}x{$file['height']}" : '') : '') . ')');
	return [ 0 ];
    }

    //the saved file's name: the one set, else Content-Disposition's, else the URL's last part, else `download`; path parts and control characters removed;
    //an extension from the content type when it has none
    public static function filename($set, $disposition, $url, $contentType) {
	$name = trim((string)$set);
	if ($name === '' && $disposition !== '') {
	    if (preg_match("/filename\\*\\s*=\\s*[^']*'[^']*'([^;]+)/i", $disposition, $m))
		$name = rawurldecode(trim($m[1], " \"'"));
	    elseif (preg_match('/filename\s*=\s*"?([^";]+)"?/i', $disposition, $m))
		$name = trim($m[1]);
	}
	if ($name === '')
	    $name = rawurldecode(basename((string)parse_url($url, PHP_URL_PATH)));
	$name = trim(preg_replace('/[\x00-\x1F\x7F]+/', '', str_replace([ '/', '\\' ], '_', $name)), ' .');
	if ($name === '')
	    $name = 'download';
	if (pathinfo($name, PATHINFO_EXTENSION) === '' && isset(self::EXTENSIONS[$contentType]))
	    $name .= '.' . self::EXTENSIONS[$contentType];
	return mb_substr($name, 0, 200);
    }
}
