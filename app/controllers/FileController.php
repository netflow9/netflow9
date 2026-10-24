<?php

namespace controllers;

class FileController extends \sys\BaseController {

    use DataTrait;

    public function _init() {
	parent::_init();

	if (\V::app()->user->isGuest)
	    \V::app()->response->redirect(\V::app()->user->getLoginUrl());

	$this->project = \V::app()->om->findById('Project', \V::app()->user->crtProject);

    }

    public function list($req, $resp) {
	if (!\V::app()->user->can('File::list', [ 'project' => $this->project ]))
	    \V::app()->error(403, 'Unauthorized');

	if (!empty($req->query['data'])) {
	    $response = [ 'nRecords' => 0, 'data' => [] ];
		$response = $this->__data('File', [ 'metadata.project' => $this->project->_id ]);
		$resp->sendJSON($response);
	}

	$this->render('list');
    }

    public function upload($req, $resp) {
	if (!$req->is('post'))
	    return;

	if (!\V::app()->user->can('File::create', [ 'project' => $this->project ]))
	    \V::app()->error(403, 'Unauthorized');

	//CLD: 2026-10-06T21:33:37+03:00: errors as JSON { error } with a 4xx status (were plain text with 200, so the uploader saw a success)
	$file = $req->files['file'] ?? null;
	if (empty($file))
	    $resp->sendJSON([ 'error' => 'No file uploaded' ], true, 400);

	if ($file['error'] > 0) {
	    $errors = [
		    0 => 'There is no error, the file uploaded with success',
		    1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
		    2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
		    3 => 'The uploaded file was only partially uploaded',
		    4 => 'No file was uploaded',
		    6 => 'Missing a temporary folder',
		    7 => 'Failed to write file to disk.',
		    8 => 'A PHP extension stopped the file upload.',
	    ];

	    $resp->sendJSON([ 'error' => 'File upload error: ' . ($errors[$file['error']] ?? 'unknown error') ], true, 400);
	}
	if (!is_uploaded_file($file['tmp_name']))
	    $resp->sendJSON([ 'error' => 'Not an uploaded file' ], true, 400);

	//CLD: 2026-10-06T21:33:37+03:00: only allowed file types (images, documents, spreadsheets, text, PDF; see File::$extensions)
	if (!\models\File::isAllowedName($file['name']))
	    $resp->sendJSON([ 'error' => 'File type not allowed' ], true, 415);

	$fp = fopen($file['tmp_name'], 'rb');
	$options = [
	    'metadata' => [
		'project' => $this->project->_id,
		'owner' => \V::app()->user->_id,
		'aid' => \V::app()->config('rt.account')->_id,
		'created_at' => new \MongoDB\BSON\UTCDateTime(),
	    ],
	];
	//CLD: 2026-10-24T09:45:36+03:00: the tier's limit of the account's files (util\Tiers; File::createFromStream checks it)
	try {
	    $file_id = \models\File::createFromStream($file['name'], $fp, $options);
	} catch (\util\LimitException $e) {
	    $resp->sendJSON([ 'error' => $e->getMessage() ], true, 413);
	}
	\models\node\NodeTriggerFile::uploaded($file_id);
	$resp->sendJSON([ 'id' => (string)$file_id ]);
    }

    //CLD: 2026-10-06T21:29:27+03:00: the file `id` of the current account and project (404 otherwise), if the user has $permName
    //(checked like list(): files have no checkAccess of their own yet)
    private function findFileOrError($permName) {
	$oid = $this->getOidOrError();
	if (empty($this->project))
	    \V::app()->error(404, 'Not found');

	$file = \V::app()->om->findOne('File', [
	    '_id' => $oid,
	    'metadata.aid' => \V::app()->var('rt.account')->_id,
	    'metadata.project' => $this->project->_id,
	]);
	if (empty($file))
	    \V::app()->error(404, 'Not found');

	if (!\V::app()->user->can($permName))
	    \V::app()->error(403, 'Unauthorized');

	return $file;
    }

    //CLD: 2026-10-06T21:29:27+03:00: file page: details, image preview for image files (no other previews for now), download button
    public function view($req, $resp) {
	$file = $this->findFileOrError('File::read');
	$this->render('view', compact('file'));
    }

    //CLD: 2026-10-06T21:29:27+03:00: the file's bytes, streamed from GridFS: previewable images inline with their own type, everything else (and
    //?download=1) as an attachment of type application/octet-stream, so uploaded HTML/SVG/... never run in the app's origin
    public function content($req, $resp) {
	$file = $this->findFileOrError('File::read');

	$mime = $file->imageMimeType();
	$inline = ($mime !== null && empty($req->query['download']));

	$name = (string)$file->filename;
	$asciiName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);

	$stream = \models\File::_bucket()->openDownloadStream($file->_id);

	//stream directly: no buffering of the whole file
	//CLD: 2026-10-18T18:34:20+03:00: output buffers are no longer used by the framework (was: ob_end_clean() of every level)

	header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
	header('Content-Length: ' . (int)$file->length);
	header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"{$asciiName}\"; filename*=UTF-8''" . rawurlencode($name));
	header('X-Content-Type-Options: nosniff');
	header('Cache-Control: private, max-age=3600');

	fpassthru($stream);
	exit(0);
    }

    public function delete($req, $resp) {
	if (!$req->is('post'))
	    return;

	$oid = $this->getOidOrError();
	$account = \V::app()->var('rt.account');

	//CLD: 2026-10-05T20:35:40+03:00: restrict to the current account (files keep it in metadata.aid); a global-scope role passes
	//File::delete without looking at the object, so without this a known file id from another account could be deleted
	$file = \V::app()->om->findOne('File', [ '_id' => $oid, 'metadata.aid' => $account->_id ]);
	if (empty($file))
	    \V::app()->error(404, 'Not found');

	if (!\V::app()->user->can('File::delete', [ 'object' => $file ]))
	    \V::app()->error(403, 'Unauthorized');

	$file->delete();
	$this->addToast('success', 'OK', 'File deleted!');
	$this->addEvent('object::deleted', [ 'object' => 'File', 'oid' => (string)$file->_id ]);
	$this->sendEvents();
    }
}