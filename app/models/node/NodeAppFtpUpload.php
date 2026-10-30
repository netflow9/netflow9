<?php

/*
    CLD: 2026-10-20T18:00:00+03:00: new step: FTP / SFTP Upload - sends project files (or a text made in the workflow) to an FTP, FTPS or SFTP server (a FTP / SFTP server
    credential: models\credential\CredentialFtp, util\FtpClient); the other way of the FTP / SFTP step (NodeAppFtp).
	What: project files (ids, comma separated, or {{ }} giving one, a list of ids or of file objects, e.g. {{ ftp.files }}, {{ file }}), or a text (CSV, XML, ...) with
	    its name.
	Where: a folder (made when missing, optionally), the remote name: empty = the file's own; else a name with {{ }} and the placeholders {name} {base} {ext}
	    {date} {time} {n} (for several files).
	Before: optionally gzip-compressed (.gz added), then encrypted (an Encryption key credential: PGP / GPG public key, passphrase, OpenSSL, S/MIME: its ending added,
	    e.g. .gpg); an ending the name already has is not added again.
	CLD: 2026-10-20T19:00:00+03:00: Signed (a Signing key credential: PGP, S/MIME / CMS, a plain private key signature): in the file (attached: signed, then encrypted;
	    PGP signing + PGP encryption: one signed and encrypted message) or as a separate signature file next to it (detached: `<name>.sig` / .asc / .p7s, a signature of
	    the file as it is on the server; sent before it, so a receiver that waits for the file finds its signature there)
	Safe upload: sent as `<name>.part`, renamed when complete (the receiver never picks up half a file). A file there already: replace it, leave it (skipped), add a
	    number (report_1.csv) or an error. Checked: the size on the server is the size sent.
	The variable (default `upload`): { files: [ { fileId, name, remoteName, path, size, compressed, encrypted, signed, signature, replaced } ], count, skipped: [ { file, path, reason } ],
	    errors: [ { file, error } ], error }
	On error (no connection, or a file that failed - the others are still sent): fail the step, or continue on the `error` output
*/

namespace models\node;

class NodeAppFtpUpload extends \models\WfNode {
    //static data/structure
    public static $type = 'app';
    public static $name = 'ftp-upload';
    public static $label = 'FTP / SFTP Upload';
    public static $icon = 'bi-cloud-upload';
    public static $color = 'var(--wf-color-app)';
    //CLD: 2026-10-30T15:00:00+02:00: the step picker: an advanced step, in Web & files
    public static $audience = 'advanced';
    public static $category = 'web';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    const SOURCES = [ 'files' => 'Project files', 'text' => 'A text (CSV, XML, ...)' ];
    const IF_EXISTS = [ 'overwrite' => 'Replace it', 'skip' => 'Leave it, do not send', 'unique' => 'Add a number to the name (report_1.csv)', 'fail' => 'An error' ];
    const ON_ERROR = [ 'fail' => 'Fail the step', 'output' => 'Continue on the `error` output' ];
    //CLD: 2026-10-20T19:00:00+03:00: where the signature goes
    const SIGN_MODES = [ 'attached' => 'In the file (signed, then encrypted)', 'detached' => 'A separate signature file next to it (.sig, .asc, .p7s)' ];

    const MAX_FILES = 100;
    const MAX_SIZE_MB = 200;
    const MAX_TEXT = 20 * 1024 * 1024;
    const MAX_TIMEOUT = 600;
    //the most names tried for "Add a number"
    const MAX_UNIQUE = 100;
    //the ending of a file being sent
    const PART = '.part';

    public static function configSchema() {
	//CLD: 2026-10-24T12:47:49+03:00: the help texts without their "; {{ ... }} for expressions" ending (the expression inputs suggest as one types; less height in the wide dialog)
	$choices = fn($capability) => array_column(static::credentialChoices($capability), 'label', 'value');
	return [
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Server credential',
		'required' => true,
		'choices' => $choices('ftp'),
		'help' => 'An FTP / SFTP server credential of the project (menu: Credentials)',
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide (was 6)
		'key' => 'source',
		'type' => 'select',
		'label' => 'Send',
		'required' => true,
		'default' => 'files',
		'choices' => self::SOURCES,
		'ui' => [ 'width' => 3 ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide
		'key' => 'ifExists',
		'type' => 'select',
		'label' => 'A file with that name is there',
		'required' => true,
		'default' => 'overwrite',
		'choices' => self::IF_EXISTS,
		'ui' => [ 'width' => 3 ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 6 wide, on a new line; shorter help (was: /in/orders from the server's root, in/orders from the folder you start in; empty: that folder; {{ ... }})
		'key' => 'folder',
		'type' => 'text',
		'label' => 'Folder',
		'default' => '',
		'help' => '/in/orders (from the root) or in/orders; empty: the start folder',
		'ui' => [ 'width' => 6, 'newRow' => true, 'placeholder' => '/incoming', 'expression' => true ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 6 wide; shorter help (was: Empty: the file's own name. {name} {base} {ext} {date} {time} {n} for each file (e.g. {base}_{date}.{ext}))
		'key' => 'filename',
		'type' => 'text',
		'label' => 'Name on the server',
		'default' => '',
		'requiredIf' => [ 'if' => 'source', 'in' => [ 'text' ] ],
		'help' => 'Empty: the file\'s name; {name} {base} {ext} {date} {time} {n}',
		'ui' => [ 'width' => 6, 'placeholder' => 'orders_{date}.csv', 'expression' => true ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: shorter help (was: File ids separated by commas, or {{ ... }} giving one or a list, e.g. {{ file.id }}, {{ ftp.files }}, {{ mail.attachment_files }})
		'key' => 'files',
		'type' => 'text',
		'label' => 'Project files',
		'default' => '',
		'requiredIf' => [ 'if' => 'source', 'in' => [ 'files' ] ],
		'help' => 'File ids separated by commas, or {{ ... }}, e.g. {{ file.id }}, {{ ftp.files }}',
		'ui' => [ 'placeholder' => '{{ file.id }}', 'expression' => true, 'showIf' => [ 'key' => 'source', 'operator' => 'eq', 'value' => 'files' ] ],
	    ],
	    [
		'key' => 'content',
		'type' => 'textarea',
		'label' => 'Content',
		'default' => '',
		'help' => 'The file\'s text',
		'ui' => [ 'rows' => 6, 'expression' => true, 'showIf' => [ 'key' => 'source', 'operator' => 'eq', 'value' => 'text' ] ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide (was 6), on a new line
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'createFolders',
		'type' => 'switch',
		'label' => 'Make missing folders',
		'default' => true,
		'ui' => [ 'advanced' => true, 'width' => 3, 'newRow' => true ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide (was 6); shorter help (was: The receiver never sees half a file; off for servers that refuse renames)
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'safeUpload',
		'type' => 'switch',
		'label' => 'Send as .part, rename when complete',
		'default' => true,
		'help' => 'No half files seen; off: servers refusing renames',
		'ui' => [ 'advanced' => true, 'width' => 3 ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide (was 6)
		'key' => 'compress',
		'type' => 'switch',
		'label' => 'Compress (gzip, .gz)',
		'default' => false,
		'ui' => [ 'width' => 3 ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide (was 6)
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'verify',
		'type' => 'switch',
		'label' => 'Check the size on the server',
		'default' => true,
		'ui' => [ 'advanced' => true, 'width' => 3 ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide (was 6), on a new line; shorter help (was: After compressing; the ending is added: .gpg, .asc, .enc, .p7m)
		'key' => 'encrypt',
		'type' => 'switch',
		'label' => 'Encrypt',
		'default' => false,
		'help' => 'After compressing (.gpg, .asc, .enc, .p7m)',
		'ui' => [ 'width' => 3, 'newRow' => true ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 9 wide; shorter help (was: An Encryption key credential of the project: the recipient's PGP public key, a passphrase, an OpenSSL password or S/MIME certificate)
		'key' => 'encryptCredential',
		'type' => 'select',
		'label' => 'Encryption key',
		'default' => '',
		'choices' => $choices('encrypt'),
		'requiredIf' => [ 'if' => 'encrypt', 'in' => [ true ] ],
		'help' => 'An Encryption key credential: the recipient\'s PGP key, a passphrase, an OpenSSL password or S/MIME certificate',
		'ui' => [ 'width' => 9, 'showIf' => [ 'key' => 'encrypt', 'operator' => 'eq', 'value' => true ] ],
	    ],
	    //CLD: 2026-10-20T19:00:00+03:00: signing
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide, on a new line; shorter help (was: With your private key, so the receiver can check the file is from you and unchanged)
		'key' => 'sign',
		'type' => 'switch',
		'label' => 'Sign',
		'default' => false,
		'help' => 'With your private key: the receiver can check it',
		'ui' => [ 'width' => 3, 'newRow' => true ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 6 wide; shorter help (was: A Signing key credential of the project: PGP private key, S/MIME certificate + key, or a private key (plain signature))
		'key' => 'signCredential',
		'type' => 'select',
		'label' => 'Signing key',
		'default' => '',
		'choices' => $choices('sign'),
		'requiredIf' => [ 'if' => 'sign', 'in' => [ true ] ],
		'help' => 'A Signing key credential: PGP, S/MIME certificate + key, or a private key',
		'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'sign', 'operator' => 'eq', 'value' => true ] ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide (was 6); shorter help (was: A plain private key signature is always a separate file)
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'signMode',
		'type' => 'select',
		'label' => 'Signature',
		'required' => true,
		'default' => 'attached',
		'choices' => self::SIGN_MODES,
		'help' => 'A plain private key: always a separate file',
		'ui' => [ 'advanced' => true, 'width' => 3, 'showIf' => [ 'key' => 'sign', 'operator' => 'eq', 'value' => true ] ],
	    ],
	    [
		'key' => 'timeout',
		'type' => 'number',
		'label' => 'Timeout per file (s)',
		'required' => true,
		'default' => 120,
		'validate' => [ [ 'inRange', 'lower' => 5, 'upper' => self::MAX_TIMEOUT, 'skipEmpty' => true, 'message' => 'from 5 to ' . self::MAX_TIMEOUT ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: shorter help (was: e.g. {{ upload.files[0].path }}, {{ upload.count }})
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Result variable',
		'required' => true,
		'default' => 'upload',
		'help' => 'e.g. {{ upload.count }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: shorter help (was: No connection, or a file that failed (the other files are still sent))
		'key' => 'onError',
		'type' => 'select',
		'label' => 'On error',
		'required' => true,
		'default' => 'fail',
		'choices' => self::ON_ERROR,
		'help' => 'No connection, or a failed file',
	    ],
	];
    }

    //credentials of the current project providing $capability, for the designer
    protected static function credentialChoices($capability = 'ftp') {
	if (\V::app()->config('app.mode') != 'web' || \V::app()->user->isGuest || empty(\V::app()->user->crtProject))
	    return [];
	return \models\Credential::choicesFor($capability, \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject);
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'upload') : 'upload';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	return [ $name => [ 'type' => 'object', 'info' => 'the files sent: { files, count, skipped, errors, error }', 'fields' => [
	    'files' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => [
		'fileId' => [ 'type' => 'string', 'info' => 'the project file sent (null for a text)' ],
		'name' => [ 'type' => 'string', 'info' => 'the project file\'s name' ],
		'remoteName' => [ 'type' => 'string', 'info' => 'the name on the server' ],
		'path' => [ 'type' => 'string', 'info' => 'the path on the server' ],
		'size' => [ 'type' => 'number', 'info' => 'bytes sent' ],
		'compressed' => [ 'type' => 'boolean' ],
		'encrypted' => [ 'type' => 'boolean' ],
		'signed' => [ 'type' => 'boolean' ],
		'signature' => [ 'type' => 'string', 'info' => 'the path of the separate signature file on the server (null: none)' ],
		'replaced' => [ 'type' => 'boolean', 'info' => 'a file of that name was replaced' ],
	    ] ] ],
	    'count' => [ 'type' => 'number' ],
	    'skipped' => [ 'type' => 'array', 'info' => 'files not sent: one of that name is there', 'items' => [ 'type' => 'object', 'fields' => [ 'file' => [ 'type' => 'string' ], 'path' => [ 'type' => 'string' ], 'reason' => [ 'type' => 'string' ] ] ] ],
	    'errors' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => [ 'file' => [ 'type' => 'string' ], 'error' => [ 'type' => 'string' ] ] ] ],
	    'error' => [ 'type' => 'string', 'info' => 'the first error, or null' ],
	] ] ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$wf = $this->workflow;
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$wf || empty(\models\Credential::findFor($wf, $cred, 'ftp')))) {
	    $this->errors('config', 'Server credential: choose an FTP / SFTP server credential of this project');
	    $valid = false;
	}
	$enc = (string)$this->cfg('encryptCredential', '');
	if ($this->cfg('encrypt', false) && $enc !== '' && (!$wf || empty(\models\Credential::findFor($wf, $enc, 'encrypt')))) {
	    $this->errors('config', 'Encryption key: choose an Encryption key credential of this project');
	    $valid = false;
	}
	//CLD: 2026-10-20T19:00:00+03:00: a signing key of the project; a plain signature cannot be in the file
	$sig = (string)$this->cfg('signCredential', '');
	if ($this->cfg('sign', false) && $sig !== '') {
	    $signCred = $wf ? \models\Credential::findFor($wf, $sig, 'sign') : null;
	    if (empty($signCred)) {
		$this->errors('config', 'Signing key: choose a Signing key credential of this project');
		$valid = false;
	    } elseif ($this->cfg('signMode', 'attached') === 'attached' && !\util\FileSign::canAttach((string)$signCred->method)) {
		$this->errors('config', 'Signature: a plain private key signature is always a separate file: choose "A separate signature file"');
		$valid = false;
	    }
	}
	$name = trim((string)$this->cfg('filename', ''));
	if ($name !== '' && !str_contains($name, '{{') && preg_match('~[/\\\\]~', $name)) {
	    $this->errors('config', 'Name on the server: a name, not a path (the folder is set above)');
	    $valid = false;
	}
	return $valid;
    }

    // ------------------------------------------------------------------------------------
    //names

    //the remote name of local file $name ($n: its number in the run, from 1) by $template (empty: the name itself); placeholders replaced, path parts removed
    public static function remoteName($template, $name, $n, $now = null) {
	$now = $now ?? time();
	$template = trim((string)$template);
	$ext = pathinfo((string)$name, PATHINFO_EXTENSION);
	$base = $ext !== '' ? substr($name, 0, -(strlen($ext) + 1)) : (string)$name;
	$out = $template === '' ? (string)$name : strtr($template, [
	    '{name}' => $name, '{base}' => $base, '{ext}' => $ext, '{date}' => date('Y-m-d', $now), '{time}' => date('His', $now), '{n}' => (string)$n ]);
	//an empty {ext}: no dot left at the end
	$out = trim(preg_replace('/[\x00-\x1F\x7F]+/', '', str_replace([ '/', '\\' ], '_', $out)), ' ');
	$out = rtrim($out, '.');
	return mb_substr($out !== '' ? $out : 'upload', 0, 200);
    }

    //$name with $ending (".gz") unless it has it already
    public static function withEnding($name, $ending) {
	return str_ends_with(strtolower($name), strtolower($ending)) ? $name : $name . $ending;
    }

    //$name with a number added before its endings: report.csv.gpg -> report_1.csv.gpg
    public static function numbered($name, $i) {
	$p = strpos($name, '.', 1);
	return $p === false ? "{$name}_{$i}" : substr($name, 0, $p) . "_{$i}" . substr($name, $p);
    }

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	$vars = $this->exec->expressionVariables();
	$text = fn($key, $default = '') => trim(\util\Expression::toText(\util\Expression::render((string)$this->cfg($key, $default), $vars)));
	$start = microtime(true);
	$now = time();

	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'ftp');
	if (empty($cred))
	    throw new \ErrorException('FTP / SFTP Upload: the server credential is no longer available');
	$encCred = null;
	if ($this->cfg('encrypt', false)) {
	    $encCred = \models\Credential::findFor($this->exec, (string)$this->cfg('encryptCredential', ''), 'encrypt');
	    if (empty($encCred))
		throw new \ErrorException('FTP / SFTP Upload: the encryption key credential is no longer available');
	}
	//CLD: 2026-10-20T19:00:00+03:00: the signing key; the settings of each file in $opts (were sendOne()'s arguments)
	$signCred = null;
	if ($this->cfg('sign', false)) {
	    $signCred = \models\Credential::findFor($this->exec, (string)$this->cfg('signCredential', ''), 'sign');
	    if (empty($signCred))
		throw new \ErrorException('FTP / SFTP Upload: the signing key credential is no longer available');
	}
	$signMode = $this->cfg('signMode', 'attached') === 'detached' ? 'detached' : 'attached';
	if ($signCred && $signMode === 'attached' && !\util\FileSign::canAttach((string)$signCred->method))
	    throw new \ErrorException('FTP / SFTP Upload: a plain private key signature is always a separate file (Signature: a separate signature file)');
	$folder = \util\FtpClient::normalize($text('folder'));
	$template = $text('filename');
	$compress = (bool)$this->cfg('compress', false);
	$opts = [ 'compress' => $compress, 'encCred' => $encCred, 'signCred' => $signCred, 'signMode' => $signMode, 'safe' => (bool)$this->cfg('safeUpload', true),
	    'verify' => (bool)$this->cfg('verify', true), 'ifExists' => (string)$this->cfg('ifExists', 'overwrite'), 'createFolders' => (bool)$this->cfg('createFolders', true) ];
	$timeout = max(5, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 120)));
	$maxBytes = self::MAX_SIZE_MB * 1048576;

	//what to send: [ file (model or null), name, content (text) ]
	$items = [];
	if ($this->cfg('source', 'files') === 'text') {
	    if ($template === '')
		throw new \ErrorException('FTP / SFTP Upload: Name on the server: required for a text');
	    $content = \util\Expression::toText(\util\Expression::render((string)$this->cfg('content', ''), $vars));
	    if (strlen($content) > self::MAX_TEXT)
		throw new \ErrorException('FTP / SFTP Upload: the text is larger than ' . (self::MAX_TEXT / 1048576) . ' MB');
	    $items[] = [ null, $template, $content ];
	} else {
	    //CLD: 2026-10-22T00:35:40+03:00: models\File::idsOf (was NodeAppGmail::fileIds, now in the GoogleIntegration plugin)
	    $ids = \models\File::idsOf($this->expr('files', ''));
	    if (!$ids)
		throw new \ErrorException('FTP / SFTP Upload: Project files: no file given');
	    if (count($ids) > self::MAX_FILES)
		throw new \ErrorException('FTP / SFTP Upload: more than ' . self::MAX_FILES . ' files');
	    foreach($ids as $id) {
		$file = \util\Validator::rule('oid', $id) ? \V::app()->om->findOne('File', [ '_id' => $id, 'metadata.aid' => $this->exec->aid, 'metadata.project' => $this->exec->project ]) : null;
		if (empty($file))
		    throw new \ErrorException('FTP / SFTP Upload: no project file `' . mb_substr($id, 0, 60) . '`');
		if ((int)$file->length > $maxBytes)
		    throw new \ErrorException("FTP / SFTP Upload: `{$file->filename}` is larger than " . self::MAX_SIZE_MB . ' MB');
		$items[] = [ $file, (string)$file->filename, null ];
	    }
	}

	$result = [ 'files' => [], 'count' => 0, 'skipped' => [], 'errors' => [], 'error' => null ];
	$bytes = 0;
	$work = \util\FileDecrypt::workDir();
	try {
	    $cred->withClient(function($client) use (&$result, &$bytes, $items, $folder, $template, $opts, $work, $now) {
		$used = [];
		foreach($items as $i => [ $file, $localName, $content ]) {
		    //a text: the name's placeholders {date} {time} {n} ({name} {base} {ext} empty)
		    $remote = self::remoteName($template, $file ? $localName : '', $i + 1, $now);
		    foreach(self::endings($opts) as $ending)
			$remote = self::withEnding($remote, $ending);
		    $label = $file ? $localName : $remote;
		    //two files of the run with one name: the next ones numbered
		    $base = $remote;
		    for($k = 1; isset($used[strtolower($remote)]) && $k <= self::MAX_UNIQUE; $k++)
			$remote = self::numbered($base, $k);
		    try {
			$sent = $this->sendOne($client, $file, $content, $folder, $remote, $opts, $work);
		    } catch (\RuntimeException $e) {
			$result['errors'][] = [ 'file' => $label, 'error' => $e->getMessage() ];
			$this->exec->log($this->wfid, "ftp upload {$label}: " . $e->getMessage());
			continue;
		    }
		    if ($sent['skipped']) {
			$result['skipped'][] = [ 'file' => $label, 'path' => $sent['path'], 'reason' => 'a file of that name is there' ];
			continue;
		    }
		    $used[strtolower($sent['remoteName'])] = true;
		    $bytes += $sent['size'];
		    $result['files'][] = [ 'fileId' => $file ? (string)$file->_id : null, 'name' => $label, 'remoteName' => $sent['remoteName'], 'path' => $sent['path'], 'size' => $sent['size'],
			'compressed' => $opts['compress'], 'encrypted' => $opts['encCred'] !== null, 'signed' => $opts['signCred'] !== null, 'signature' => $sent['signature'], 'replaced' => $sent['replaced'] ];
		}
	    }, $timeout);
	} catch (\RuntimeException $e) {
	    $result['errors'][] = [ 'file' => $folder === '' ? '.' : $folder, 'error' => $e->getMessage() ];
	} finally {
	    \util\FileDecrypt::removeDir($work);
	}

	$result['count'] = count($result['files']);
	$result['error'] = $result['errors'] ? $result['errors'][0]['file'] . ': ' . $result['errors'][0]['error'] : null;
	$name = (string)$this->cfg('variable', 'upload');
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $result);
	$ms = (int)round((microtime(true) - $start) * 1000);
	$this->exec->log($this->wfid, "ftp upload {$cred->protocol}://{$cred->host}/" . ltrim($folder, '/') . ": {$result['count']} of " . count($items) . " sent ({$bytes} bytes)"
	    . ($result['skipped'] ? ', ' . count($result['skipped']) . ' skipped' : '') . ($result['errors'] ? ', ' . count($result['errors']) . ' error(s): ' . $result['error'] : '') . " ({$ms} ms)");
	if ($result['errors']) {
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException('FTP / SFTP Upload: ' . $result['error']);
	}
	return [ 0 ];
    }

    //CLD: 2026-10-20T19:00:00+03:00: the endings the name gets, in order: .gz, the attached signature's (unless PGP signs and encrypts in one message), the encryption's
    public static function endings(array $opts) {
	$endings = [];
	if (!empty($opts['compress']))
	    $endings[] = '.gz';
	$sign = $opts['signCred'] ?? null;
	$enc = $opts['encCred'] ?? null;
	if ($sign && ($opts['signMode'] ?? 'attached') === 'attached' && !self::signsAndEncrypts($opts))
	    $endings[] = \util\FileSign::ending((string)$sign->method, 'attached', !empty($sign->armor));
	if ($enc)
	    $endings[] = \util\FileEncrypt::ending((string)$enc->method, !empty($enc->armor));
	return $endings;
    }

    //PGP signing in the file + PGP public key encryption: one signed and encrypted message (gpg --sign --encrypt)
    protected static function signsAndEncrypts(array $opts) {
	return !empty($opts['signCred']) && !empty($opts['encCred']) && ($opts['signMode'] ?? 'attached') === 'attached'
	    && (string)$opts['signCred']->method === 'pgp' && (string)$opts['encCred']->method === 'pgp';
    }

    //one file: made ready (compressed, signed, encrypted), the name chosen (if it exists), its detached signature sent, the file sent (as .part, renamed), checked:
    //[ skipped, remoteName, path, size, replaced, signature ]
    //CLD: 2026-10-20T19:00:00+03:00: + signing; the settings in $opts (see execute); sending in putFile()
    protected function sendOne($client, $file, $content, $folder, $remote, array $opts, $work) {
	//the local copy
	$current = $work . '/src';
	$fh = fopen($current, 'w+b');
	try {
	    if ($file)
		\models\File::_bucket()->downloadToStream($file->_id, $fh);
	    else
		fwrite($fh, (string)$content);
	} finally {
	    fclose($fh);
	}
	$sign = $opts['signCred'];
	$enc = $opts['encCred'];
	try {
	    if ($opts['compress']) {
		\util\FileEncrypt::compress($current, $work . '/gz');
		$current = $work . '/gz';
	    }
	    if ($sign && $opts['signMode'] === 'attached') {
		$combined = self::signsAndEncrypts($opts);
		\util\FileSign::sign($sign, $current, $work . '/signed', 'attached', $combined ? $enc : null);
		$current = $work . '/signed';
		if ($combined)
		    $enc = null;
	    }
	    if ($enc) {
		\util\FileEncrypt::encrypt($enc, $current, $work . '/enc');
		$current = $work . '/enc';
	    }
	    clearstatcache(true, $current);
	    $size = (int)filesize($current);

	    //the name: a file there already
	    $path = \util\FtpClient::join($folder, $remote);
	    $replaced = false;
	    if ($client->exists($path) !== null) {
		switch($opts['ifExists']) {
		    case 'skip':
			return [ 'skipped' => true, 'remoteName' => $remote, 'path' => $path, 'size' => 0, 'replaced' => false, 'signature' => null ];
		    case 'fail':
			throw new \RuntimeException("`{$path}` is there already");
		    case 'unique':
			$base = $remote;
			for($i = 1; $i <= self::MAX_UNIQUE && $client->exists($path) !== null; $i++) {
			    $remote = self::numbered($base, $i);
			    $path = \util\FtpClient::join($folder, $remote);
			}
			if ($client->exists($path) !== null)
			    throw new \RuntimeException("no free name for `{$base}` after " . self::MAX_UNIQUE . ' tries');
			break;
		    default:
			$replaced = true;
		}
	    }

	    //the detached signature (of the file as sent) first: it follows the file's name, an old one replaced
	    $sigPath = null;
	    if ($sign && $opts['signMode'] === 'detached') {
		\util\FileSign::sign($sign, $current, $work . '/sig', 'detached');
		$sigPath = $path . \util\FileSign::ending((string)$sign->method, 'detached', !empty($sign->armor));
		$this->putFile($client, $work . '/sig', $sigPath, $client->exists($sigPath) !== null, $opts, 'signature ');
	    }
	    try {
		$this->putFile($client, $current, $path, $replaced, $opts);
	    } catch (\RuntimeException $e) {
		//no signature left without its file
		if ($sigPath !== null) {
		    try {
			$client->delete($sigPath);
		    } catch (\RuntimeException $ignored) {
		    }
		}
		throw $e;
	    }
	    return [ 'skipped' => false, 'remoteName' => $remote, 'path' => $path, 'size' => $size, 'replaced' => $replaced, 'signature' => $sigPath ];
	} finally {
	    foreach([ 'src', 'gz', 'signed', 'enc', 'sig' ] as $f)
		@unlink($work . '/' . $f);
	}
    }

    //CLD: 2026-10-20T19:00:00+03:00: local file $local sent to $path (was in sendOne()): as .part then renamed ($opts safe; a rename does not replace a file on every server,
    //so one there ($replace) is deleted first), its size checked ($opts verify); $what names it in the errors ("signature ")
    protected function putFile($client, $local, $path, $replace, array $opts, $what = '') {
	clearstatcache(true, $local);
	$size = (int)filesize($local);
	$target = $opts['safe'] ? $path . self::PART : $path;
	$stream = fopen($local, 'rb');
	try {
	    $client->upload($target, $stream, $size, $opts['createFolders']);
	} finally {
	    fclose($stream);
	}
	if ($opts['safe']) {
	    try {
		if ($replace)
		    $client->delete($path);
		$client->rename($target, $path);
	    } catch (\RuntimeException $e) {
		try {
		    $client->delete($target);
		} catch (\RuntimeException $ignored) {
		}
		throw new \RuntimeException("{$what}sent, but the rename from " . basename($target) . ' failed: ' . $e->getMessage());
	    }
	}
	if ($opts['verify']) {
	    $there = $client->exists($path);
	    if ($there === null)
		throw new \RuntimeException("{$what}sent, but the file is not on the server (taken by the receiver already?)");
	    if ($there[0] !== null && (int)$there[0] !== $size)
		throw new \RuntimeException("{$what}sent, but the server has {$there[0]} bytes of {$size}");
	}
    }
}
