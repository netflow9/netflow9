<?php

/*
    CLD: 2026-10-20T16:00:00+03:00: new step: FTP / SFTP - lists or downloads files from an FTP, FTPS or SFTP server (a FTP / SFTP server credential of the project:
    models\credential\CredentialFtp, util\FtpClient).
	Which files: a folder (+ its subfolders, to a depth), name patterns to take and to leave (globs, comma separated, `dir/*.csv` matches the path under the folder;
	or /regex/), modified after / before (a date, ISO 8601, or relative: -24 hours, yesterday; {{ }} expressions), a minimum age (files still being uploaded are left),
	smallest / largest size, only new files (not taken by an earlier run of this step, or changed since: models\RemoteFileState), the order and the most files per run.
	Download: each file saved in the project's Files (models\File; only the types that may be uploaded: the name after decryption / decompression counts), optionally
	    decrypted first (a Decryption key credential: PGP / GPG, OpenSSL enc, S/MIME; all files, or only those with the method's ending, e.g. .gpg) and decompressed
	    (.gz / .bz2); afterwards the remote file is left, deleted or moved to a folder (only when it was saved).
	CLD: 2026-10-20T20:00:00+03:00: Check signature (a Verification key credential: the sender's PGP public key, trusted S/MIME certificates, a plain public key): in the file
	    (PGP signed / signed + encrypted, S/MIME .p7m: checked while or after decrypting, the content kept) or a separate signature file next to it (<name>.sig / .asc /
	    .p7s: of the file as it is on the server; not downloaded as a file itself; none yet: the file waits for the next run, or an error). A bad signature, or one made
	    by another key: the file is not saved (an error). Afterwards, the signature file is deleted / moved with its file
	The variable (default `ftp`): { files: [ { id, filename, name, path, folder, size, savedSize, modified, decrypted, decompressed, signed, signer, signature, contentType } ], count,
	    skipped: [ { path, reason } ], errors: [ { path, error } ], error }; list: files without id / filename / savedSize.
	On error (no connection, the folder cannot be read, or a file failed - the other files are still taken): fail the step, or continue on the `error` output
*/

namespace models\node;

class NodeAppFtp extends \models\WfNode {
    //static data/structure
    public static $type = 'app';
    public static $name = 'ftp';
    public static $label = 'FTP / SFTP';
    public static $icon = 'bi-hdd-network';
    public static $color = 'var(--wf-color-app)';
    //CLD: 2026-10-30T15:00:00+02:00: the step picker: an advanced step, in Web & files
    public static $audience = 'advanced';
    public static $category = 'web';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    const OPERATIONS = [ 'download' => 'Download files', 'list' => 'List files (no download)' ];
    const ORDER = [ 'oldest' => 'Oldest first', 'newest' => 'Newest first', 'name' => 'By name' ];
    const AFTER = [ 'none' => 'Leave them on the server', 'delete' => 'Delete them from the server', 'move' => 'Move them to a folder on the server' ];
    const DECRYPT = [ 'none' => 'No', 'ext' => 'Files with an encrypted ending (.gpg, .pgp, .asc, .enc, .p7m, ...)', 'all' => 'All files' ];
    const ON_ERROR = [ 'fail' => 'Fail the step', 'output' => 'Continue on the `error` output' ];
    //CLD: 2026-10-20T20:00:00+03:00: signature checks
    const VERIFY = [ 'none' => 'No', 'attached' => 'In the file (PGP signed, S/MIME .p7m)', 'detached' => 'A separate signature file next to it (.sig, .asc, .p7s)' ];
    const MISSING_SIGNATURE = [ 'skip' => 'Leave the file for the next run (its signature may still be coming)', 'error' => 'An error' ];
    //the largest signature file read
    const MAX_SIGNATURE = 1048576;

    const MAX_FILES = 500;
    const MAX_SIZE_MB = 200;
    const MAX_TIMEOUT = 600;
    const MAX_DEPTH = 10;
    //the most entries read from the folders, and folders opened, in one run
    const MAX_ENTRIES = 20000;
    const MAX_FOLDERS = 500;
    //a time from a listing without seconds (ls -l: minutes or days, the server's time zone) is checked to the second (stat) when it is this close to a limit
    const FUZZ = 2 * 86400;

    public static function configSchema() {
	//CLD: 2026-10-24T12:47:49+03:00: the help texts without their "; {{ ... }} for expressions" ending (the expression inputs suggest as one types; less height in the wide dialog)
	$choices = fn($capability) => array_column(static::credentialChoices($capability), 'label', 'value');
	$download = [ 'key' => 'operation', 'operator' => 'eq', 'value' => 'download' ];

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
		'key' => 'operation',
		'type' => 'select',
		'label' => 'Action',
		'required' => true,
		'default' => 'download',
		'choices' => self::OPERATIONS,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 6 wide, on a new line; shorter help (was: /out/reports from the server's root, out/reports from the folder you start in; empty: that folder)
		'key' => 'folder',
		'type' => 'text',
		'label' => 'Folder',
		'default' => '',
		'help' => '/out/reports (from the root) or out/reports; empty: the start folder',
		'ui' => [ 'width' => 6, 'newRow' => true, 'placeholder' => '/outgoing', 'expression' => true ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 6 wide; shorter help (was: Comma separated: *.csv, report_????.xlsx, 2026/*.csv (a path under the folder); /^inv_\d+\.xml$/i for a regular expression; not case sensitive)
		'key' => 'pattern',
		'type' => 'text',
		'label' => 'File names',
		'default' => '*',
		'help' => '*.csv, report_????.xlsx, 2026/*.csv or /^inv_\\d+\\.xml$/i; any case',
		'ui' => [ 'width' => 6, 'placeholder' => '*.csv, *.csv.gpg', 'expression' => true ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 6 wide, on a new line; shorter help (was: Names not taken, as File names (e.g. files still being uploaded, hidden files))
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'exclude',
		'type' => 'text',
		'label' => 'Leave out',
		'default' => '*.part, *.tmp, *.filepart, .*',
		'help' => 'Names not taken (e.g. files still being uploaded)',
		'ui' => [ 'advanced' => true, 'width' => 6, 'newRow' => true, 'expression' => true ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide (was 6)
		'key' => 'subfolders',
		'type' => 'switch',
		'label' => 'Include subfolders',
		'default' => false,
		'ui' => [ 'width' => 3 ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide (was 6)
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'depth',
		'type' => 'number',
		'label' => 'Levels of subfolders',
		'default' => 3,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_DEPTH, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_DEPTH ] ],
		'ui' => [ 'advanced' => true, 'width' => 3, 'step' => 1, 'showIf' => [ 'key' => 'subfolders', 'operator' => 'eq', 'value' => true ] ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide (was 6), on a new line; shorter help (was: A date (2026-10-01, 2026-10-01T08:00:00Z) or relative (-24 hours, yesterday, -7 days); {{ ... }}; empty: any)
		'key' => 'modifiedAfter',
		'type' => 'text',
		'label' => 'Modified after',
		'default' => '',
		'help' => '2026-10-01, -24 hours, yesterday; empty: any',
		'ui' => [ 'width' => 3, 'newRow' => true, 'placeholder' => '-24 hours', 'expression' => true ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide (was 6)
		'key' => 'modifiedBefore',
		'type' => 'text',
		'label' => 'Modified before',
		'default' => '',
		'help' => 'As Modified after; empty: any',
		'ui' => [ 'width' => 3, 'placeholder' => 'today', 'expression' => true ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide (was 6); shorter help (was: Leaves files modified in the last minutes (still being written); 0: none left)
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'minAge',
		'type' => 'number',
		'label' => 'Unchanged for at least (minutes)',
		'default' => 0,
		'help' => 'Leaves files changed in the last minutes',
		'validate' => [ [ 'inRange', 'lower' => 0, 'upper' => 100000, 'skipEmpty' => true, 'message' => 'from 0 to 100000' ] ],
		'ui' => [ 'advanced' => true, 'width' => 3, 'step' => 1 ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide (was 6); shorter help (was: Files this step has not taken in an earlier run (or changed since); a file that failed is tried again)
		'key' => 'onlyNew',
		'type' => 'switch',
		'label' => 'Only new files',
		'default' => false,
		'help' => 'Not taken by an earlier run (or changed)',
		'ui' => [ 'width' => 3 ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide (was 6), on a new line
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'order',
		'type' => 'select',
		'label' => 'Order',
		'required' => true,
		'default' => 'oldest',
		'choices' => self::ORDER,
		'ui' => [ 'advanced' => true, 'width' => 3, 'newRow' => true ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide (was 6)
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'minSizeKb',
		'type' => 'number',
		'label' => 'Smallest size (KB)',
		'default' => 0,
		'help' => 'Smaller files are left; 0: also empty files',
		'validate' => [ [ 'inRange', 'lower' => 0, 'upper' => self::MAX_SIZE_MB * 1024, 'skipEmpty' => true, 'message' => 'from 0 to ' . self::MAX_SIZE_MB * 1024 ] ],
		'ui' => [ 'advanced' => true, 'width' => 3 ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: a limit (the last line)
		'key' => 'maxSize',
		'type' => 'number',
		'label' => 'Largest size (MB)',
		'required' => true,
		'default' => 25,
		'help' => 'Larger files are left (in `skipped`)',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_SIZE_MB, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_SIZE_MB ] ],
		'ui' => [ 'limit' => true, 'width' => 6 ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: a limit (the last line)
		'key' => 'maxFiles',
		'type' => 'number',
		'label' => 'Files per run',
		'required' => true,
		'default' => 20,
		'help' => 'The rest wait for the next run',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_FILES, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_FILES ] ],
		'ui' => [ 'limit' => true, 'width' => 6, 'step' => 1 ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide, on a new line; shorter help (was: The ending is removed from the saved name: report.csv.gpg -> report.csv)
		'key' => 'decrypt',
		'type' => 'select',
		'label' => 'Decrypt',
		'required' => true,
		'default' => 'none',
		'choices' => self::DECRYPT,
		'help' => 'The ending is removed: report.csv.gpg -> report.csv',
		'ui' => [ 'width' => 3, 'newRow' => true, 'showIf' => $download ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 6 wide; shorter help (was: A Decryption key credential of the project: PGP / GPG, OpenSSL or S/MIME)
		'key' => 'decryptCredential',
		'type' => 'select',
		'label' => 'Decryption key',
		'default' => '',
		'choices' => $choices('decrypt'),
		'requiredIf' => [ 'if' => 'decrypt', 'in' => [ 'ext', 'all' ] ],
		'help' => 'A Decryption key credential: PGP / GPG, OpenSSL or S/MIME',
		'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'decrypt', 'operator' => 'ne', 'value' => 'none' ] ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide
		'key' => 'decompress',
		'type' => 'switch',
		'label' => 'Decompress .gz / .bz2 files',
		'default' => false,
		'help' => 'After decrypting: report.csv.gz -> report.csv',
		'ui' => [ 'width' => 3, 'showIf' => $download ],
	    ],
	    //CLD: 2026-10-20T20:00:00+03:00: signature checks
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide, on a new line; shorter help (was: A file whose signature is bad, or made by another key, is not saved)
		'key' => 'verifySignature',
		'type' => 'select',
		'label' => 'Check signature',
		'required' => true,
		'default' => 'none',
		'choices' => self::VERIFY,
		'help' => 'Bad, or by another key: not saved',
		'ui' => [ 'width' => 3, 'newRow' => true, 'showIf' => $download ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 6 wide; shorter help (was: A Verification key credential of the project: the sender's PGP public key, trusted S/MIME certificates, or a public key)
		'key' => 'verifyCredential',
		'type' => 'select',
		'label' => 'Verification key',
		'default' => '',
		'choices' => $choices('verify'),
		'requiredIf' => [ 'if' => 'verifySignature', 'in' => [ 'attached', 'detached' ] ],
		'help' => 'A Verification key credential: the sender\'s key or certificate',
		'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'verifySignature', 'operator' => 'ne', 'value' => 'none' ] ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide (was 6)
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'missingSignature',
		'type' => 'select',
		'label' => 'No signature file',
		'required' => true,
		'default' => 'skip',
		'choices' => self::MISSING_SIGNATURE,
		'ui' => [ 'advanced' => true, 'width' => 3, 'showIf' => [ 'key' => 'verifySignature', 'operator' => 'eq', 'value' => 'detached' ] ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 3 wide (was 6), on a new line
		'key' => 'after',
		'type' => 'select',
		'label' => 'Afterwards',
		'required' => true,
		'default' => 'none',
		'choices' => self::AFTER,
		'help' => 'Only for the files saved',
		'ui' => [ 'width' => 3, 'newRow' => true, 'showIf' => $download ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: layout: 6 wide
		'key' => 'moveTo',
		'type' => 'text',
		'label' => 'Move to folder',
		'default' => '',
		'requiredIf' => [ 'if' => 'after', 'in' => [ 'move' ] ],
		'help' => 'Made when missing',
		'ui' => [ 'width' => 6, 'placeholder' => '/outgoing/done', 'expression' => true, 'showIf' => [ 'key' => 'after', 'operator' => 'eq', 'value' => 'move' ] ],
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
		//CLD: 2026-10-24T12:54:43+03:00: shorter help (was: e.g. a Loop over {{ ftp.files }}; {{ ftp.files[0].id }} is the saved file)
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Result variable',
		'required' => true,
		'default' => 'ftp',
		'help' => 'e.g. a Loop over {{ ftp.files }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	    [
		//CLD: 2026-10-24T12:54:43+03:00: shorter help (was: No connection, a folder that cannot be read, or a file that failed (the other files are still taken))
		'key' => 'onError',
		'type' => 'select',
		'label' => 'On error',
		'required' => true,
		'default' => 'fail',
		'choices' => self::ON_ERROR,
		'help' => 'No connection, unreadable folder, failed file',
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
	$name = is_array($config) ? (string)($config['variable'] ?? 'ftp') : 'ftp';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$list = is_array($config) && ($config['operation'] ?? 'download') === 'list';
	$file = [
	    'name' => [ 'type' => 'string', 'info' => 'the name on the server' ],
	    'path' => [ 'type' => 'string', 'info' => 'the path on the server' ],
	    'folder' => [ 'type' => 'string', 'info' => 'its folder on the server' ],
	    'size' => [ 'type' => 'number', 'info' => 'bytes, on the server' ],
	    'modified' => [ 'type' => 'string', 'info' => 'ISO 8601, UTC' ],
	];
	if (!$list)
	    $file = [ 'id' => [ 'type' => 'string', 'info' => 'the saved project file' ], 'filename' => [ 'type' => 'string', 'info' => 'the saved name (decrypted / decompressed)' ] ] + $file + [
		'savedSize' => [ 'type' => 'number', 'info' => 'bytes saved' ], 'decrypted' => [ 'type' => 'boolean' ], 'decompressed' => [ 'type' => 'boolean' ],
		'signed' => [ 'type' => 'boolean', 'info' => 'its signature was checked' ], 'signer' => [ 'type' => 'string', 'info' => 'who signed it (a user id, the certificate\'s name)' ],
		'signature' => [ 'type' => 'string', 'info' => 'the separate signature file on the server (null: none)' ], 'contentType' => [ 'type' => 'string' ] ];
	return [ $name => [ 'type' => 'object', 'info' => $list ? 'the files found: { files, count, skipped, errors, error }' : 'the files downloaded: { files, count, skipped, errors, error }', 'fields' => [
	    'files' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => $file ] ],
	    'count' => [ 'type' => 'number' ],
	    'skipped' => [ 'type' => 'array', 'info' => 'files matching but left: too large, a type that cannot be saved', 'items' => [ 'type' => 'object', 'fields' => [ 'path' => [ 'type' => 'string' ], 'reason' => [ 'type' => 'string' ] ] ] ],
	    'errors' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => [ 'path' => [ 'type' => 'string' ], 'error' => [ 'type' => 'string' ] ] ] ],
	    'error' => [ 'type' => 'string', 'info' => 'the first error, or null' ],
	] ] ];
    }

    //the credentials of the project, a valid regular expression (when not an expression)
    public function validates($options = []) {
	$valid = parent::validates($options);
	$wf = $this->workflow;
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$wf || empty(\models\Credential::findFor($wf, $cred, 'ftp')))) {
	    $this->errors('config', 'Server credential: choose an FTP / SFTP server credential of this project');
	    $valid = false;
	}
	$dec = (string)$this->cfg('decryptCredential', '');
	if ($this->cfg('operation', 'download') === 'download' && $this->cfg('decrypt', 'none') !== 'none' && $dec !== '' && (!$wf || empty(\models\Credential::findFor($wf, $dec, 'decrypt')))) {
	    $this->errors('config', 'Decryption key: choose a Decryption key credential of this project');
	    $valid = false;
	}
	//CLD: 2026-10-20T20:00:00+03:00: a verification key of the project; a plain signature is never in the file
	$ver = (string)$this->cfg('verifyCredential', '');
	if ($this->cfg('operation', 'download') === 'download' && $this->cfg('verifySignature', 'none') !== 'none' && $ver !== '') {
	    $verCred = $wf ? \models\Credential::findFor($wf, $ver, 'verify') : null;
	    if (empty($verCred)) {
		$this->errors('config', 'Verification key: choose a Verification key credential of this project');
		$valid = false;
	    } elseif ($this->cfg('verifySignature') === 'attached' && (string)$verCred->method === 'raw') {
		$this->errors('config', 'Check signature: a plain public key signature is always a separate file: choose "A separate signature file"');
		$valid = false;
	    }
	}
	foreach([ 'pattern' => 'File names', 'exclude' => 'Leave out' ] as $key => $label) {
	    $value = trim((string)$this->cfg($key, ''));
	    if (!str_contains($value, '{{') && ($error = self::patternError($value)) !== null) {
		$this->errors('config', "{$label}: {$error}");
		$valid = false;
	    }
	}
	foreach([ 'modifiedAfter' => 'Modified after', 'modifiedBefore' => 'Modified before' ] as $key => $label) {
	    $value = trim((string)$this->cfg($key, ''));
	    if ($value !== '' && !str_contains($value, '{{') && self::parseTime($value) === null) {
		$this->errors('config', "{$label}: not a date or a relative time (e.g. 2026-10-01, -24 hours)");
		$valid = false;
	    }
	}
	return $valid;
    }

    // ------------------------------------------------------------------------------------
    //filters

    //a regular expression (/.../flags) or comma separated globs
    public static function isRegex($pattern) {
	return preg_match('~^/.+/[a-zA-Z]*$~s', trim((string)$pattern)) === 1;
    }

    public static function patternError($pattern) {
	if (!self::isRegex($pattern))
	    return null;
	return @preg_match(trim($pattern), '') === false ? 'not a valid regular expression' : null;
    }

    //whether $name (path $rel under the folder) matches $pattern; an empty pattern matches nothing (as `exclude`), unless $emptyAll
    public static function matches($pattern, $name, $rel, $emptyAll = false) {
	$pattern = trim((string)$pattern);
	if ($pattern === '')
	    return $emptyAll;
	if (self::isRegex($pattern))
	    return @preg_match($pattern, $name) === 1;
	foreach(array_filter(array_map('trim', explode(',', $pattern)), fn($p) => $p !== '') as $glob) {
	    if (str_contains($glob, '/') ? fnmatch(ltrim($glob, '/'), $rel, FNM_PATHNAME | FNM_CASEFOLD) : fnmatch($glob, $name, FNM_CASEFOLD))
		return true;
	}
	return false;
    }

    //a time setting: Unix time, or null when it is not one (empty: null too). Numbers: Unix seconds (or milliseconds)
    public static function parseTime($value, $now = null) {
	if (is_int($value) || is_float($value))
	    return (int)(abs($value) > 1e11 ? $value / 1000 : $value);
	$value = trim((string)$value);
	if ($value === '')
	    return null;
	if (preg_match('/^-?\d+(\.\d+)?$/', $value))
	    return self::parseTime((float)$value);
	$t = strtotime($value, $now ?? time());
	return $t === false ? null : $t;
    }

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	$vars = $this->exec->expressionVariables();
	$text = fn($key, $default = '') => trim(\util\Expression::toText(\util\Expression::render((string)$this->cfg($key, $default), $vars)));
	$now = time();
	$start = microtime(true);
	$operation = $this->cfg('operation', 'download') === 'list' ? 'list' : 'download';

	//the settings (an error here fails the step: they are the workflow's)
	$folder = \util\FtpClient::normalize($text('folder'));
	$pattern = $text('pattern', '*');
	$exclude = $text('exclude', '');
	foreach([ 'File names' => $pattern, 'Leave out' => $exclude ] as $label => $p) {
	    if (($error = self::patternError($p)) !== null)
		throw new \ErrorException("FTP / SFTP: {$label}: {$error}");
	}
	//a single expression keeps its type: a number (Unix time) as it is, anything else (e.g. a date) as text
	$time = function($key) use ($now) {
	    $v = $this->expr($key, '');
	    return self::parseTime(is_int($v) || is_float($v) ? $v : \util\Expression::toText($v), $now);
	};
	$after = $time('modifiedAfter');
	$before = $time('modifiedBefore');
	foreach([ 'Modified after' => [ 'modifiedAfter', $after ], 'Modified before' => [ 'modifiedBefore', $before ] ] as $label => [ $key, $t ]) {
	    if ($t === null && $text($key) !== '')
		throw new \ErrorException("FTP / SFTP: {$label}: not a date or a relative time (got `" . mb_substr($text($key), 0, 60) . '`)');
	}
	$minAge = max(0, (int)$this->cfg('minAge', 0));
	$youngest = $minAge > 0 ? $now - $minAge * 60 : null;
	$minBytes = max(0, (int)$this->cfg('minSizeKb', 0)) * 1024;
	$maxBytes = max(1, min(self::MAX_SIZE_MB, (int)$this->cfg('maxSize', 25))) * 1024 * 1024;
	$maxFiles = max(1, min(self::MAX_FILES, (int)$this->cfg('maxFiles', 20)));
	$depth = $this->cfg('subfolders', false) ? max(1, min(self::MAX_DEPTH, (int)$this->cfg('depth', 3))) : 0;
	$timeout = max(5, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 120)));
	$afterAction = $operation === 'download' ? (string)$this->cfg('after', 'none') : 'none';
	$moveTo = $afterAction === 'move' ? \util\FtpClient::normalize($text('moveTo')) : '';
	if ($afterAction === 'move' && $moveTo === '')
	    throw new \ErrorException('FTP / SFTP: Move to folder: empty');

	$cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'ftp');
	if (empty($cred))
	    throw new \ErrorException('FTP / SFTP: the server credential is no longer available');
	$decryptMode = $operation === 'download' ? (string)$this->cfg('decrypt', 'none') : 'none';
	$decCred = null;
	if ($decryptMode !== 'none') {
	    $decCred = \models\Credential::findFor($this->exec, (string)$this->cfg('decryptCredential', ''), 'decrypt');
	    if (empty($decCred))
		throw new \ErrorException('FTP / SFTP: the decryption key credential is no longer available');
	}
	$decompress = $operation === 'download' && $this->cfg('decompress', false);
	//CLD: 2026-10-20T20:00:00+03:00: the verification key
	$verifyMode = $operation === 'download' ? (string)$this->cfg('verifySignature', 'none') : 'none';
	$verCred = null;
	if ($verifyMode !== 'none') {
	    $verCred = \models\Credential::findFor($this->exec, (string)$this->cfg('verifyCredential', ''), 'verify');
	    if (empty($verCred))
		throw new \ErrorException('FTP / SFTP: the verification key credential is no longer available');
	    if ($verifyMode === 'attached' && (string)$verCred->method === 'raw')
		throw new \ErrorException('FTP / SFTP: a plain public key signature is always a separate file (Check signature: a separate signature file)');
	}
	$missingSignature = (string)$this->cfg('missingSignature', 'skip');

	//only new files: the step's state, locked for the run
	$state = null;
	if ($this->cfg('onlyNew', false)) {
	    $state = \models\RemoteFileState::acquire($this->exec->wf, $this->wfid, $this->exec->aid, $this->exec->project);
	    if (empty($state))
		throw new \ErrorException('FTP / SFTP: another run of this step is reading the server now');
	}

	$result = [ 'files' => [], 'count' => 0, 'skipped' => [], 'errors' => [], 'error' => null ];
	$taken = [];
	$shown = $cred->protocol . '://' . $cred->host . '/' . ltrim($folder, '/');
	$stats = [ 'listed' => 0, 'matched' => 0, 'bytes' => 0 ];
	$work = null;
	try {
	    $cred->withClient(function($client) use (&$result, &$taken, &$stats, &$work, $folder, $pattern, $exclude, $after, $before, $youngest, $minBytes, $maxBytes, $maxFiles, $depth,
		    $afterAction, $moveTo, $decryptMode, $decCred, $decompress, $state, $operation, $verifyMode, $verCred, $missingSignature) {
		//1. the folders' files (breadth first, to the depth)
		$entries = [];
		$queue = [ [ $folder, 0 ] ];
		$opened = 0;
		while($queue && count($entries) < self::MAX_ENTRIES && $opened < self::MAX_FOLDERS) {
		    [ $dir, $level ] = array_shift($queue);
		    $opened++;
		    try {
			$list = $client->list($dir);
		    } catch (\RuntimeException $e) {
			//the first folder must be readable; a subfolder that is not is noted
			if ($level === 0)
			    throw new \RuntimeException("the folder `" . ($dir === '' ? '.' : $dir) . "` cannot be read: " . $e->getMessage());
			$result['errors'][] = [ 'path' => $dir, 'error' => 'cannot be read: ' . $e->getMessage() ];
			continue;
		    }
		    foreach($list as $entry) {
			if ($entry['type'] === 'dir') {
			    if ($level < $depth && !str_starts_with($entry['name'], '.') && !($moveTo !== '' && \util\FtpClient::normalize($entry['path']) === $moveTo))
				$queue[] = [ $entry['path'], $level + 1 ];
			    continue;
			}
			if ($entry['type'] === 'other')
			    continue;
			$entry['folder'] = $dir;
			//the time as the listing gives it: what "Only new files" compares (the same from run to run; a time made exact by stat would not be)
			$entry['listed'] = $entry['modified'];
			$entry['rel'] = ltrim(substr($entry['path'], strlen($folder)), '/');
			$entries[] = $entry;
		    }
		}
		$stats['listed'] = count($entries);
		//CLD: 2026-10-20T20:00:00+03:00: separate signatures: a file's signature is `<path><ending>`; signature files of a listed file are not files to take
		$paths = array_flip(array_column($entries, 'path'));
		$sigEndings = $verifyMode === 'detached' ? \util\FileVerify::signatureEndings((string)$verCred->method) : [];
		$signatureOf = function($path) use ($paths, $sigEndings) {
		    foreach($sigEndings as $ending)
			if (isset($paths[$path . $ending]))
			    return $path . $ending;
		    return null;
		};

		//2. the filters: names, then times (to the second when a listing's time is close to a limit), sizes, new
		$limits = array_filter([ $after, $before, $youngest ], fn($t) => $t !== null);
		$picked = [];
		foreach($entries as $entry) {
		    if (!self::matches($pattern, $entry['name'], $entry['rel'], true) || self::matches($exclude, $entry['name'], $entry['rel']))
			continue;
		    $isSignature = false;
		    foreach($sigEndings as $ending)
			$isSignature = $isSignature || (str_ends_with($entry['path'], $ending) && isset($paths[substr($entry['path'], 0, -strlen($ending))]));
		    if ($isSignature)
			continue;
		    if ($limits && (!$entry['exact'] || $entry['modified'] === null)) {
			$close = $entry['modified'] === null;
			foreach($limits as $t)
			    $close = $close || abs($entry['modified'] - $t) < self::FUZZ;
			if ($close)
			    $entry = $this->exactTimes($client, $entry);
		    }
		    $m = $entry['modified'];
		    if ($limits && $m === null) {
			$result['skipped'][] = [ 'path' => $entry['path'], 'reason' => 'the server does not tell when it was modified' ];
			continue;
		    }
		    if (($after !== null && $m <= $after) || ($before !== null && $m >= $before) || ($youngest !== null && $m > $youngest))
			continue;
		    if ($entry['size'] !== null && $entry['size'] < $minBytes)
			continue;
		    if ($state && !$state->isNew($entry))
			continue;
		    $picked[] = $entry;
		}
		$stats['matched'] = count($picked);
		usort($picked, match((string)$this->cfg('order', 'oldest')) {
		    'newest' => fn($a, $b) => [ $b['modified'] ?? 0, $a['path'] ] <=> [ $a['modified'] ?? 0, $b['path'] ],
		    'name' => fn($a, $b) => strnatcasecmp($a['path'], $b['path']),
		    default => fn($a, $b) => [ $a['modified'] ?? 0, $a['path'] ] <=> [ $b['modified'] ?? 0, $b['path'] ],
		});

		//3. each file, to the most per run: too large / not a type that can be saved: skipped (does not count)
		$done = 0;
		$madeFolder = false;
		foreach($picked as $entry) {
		    if ($done >= $maxFiles)
			break;
		    if ($entry['size'] !== null && $entry['size'] > $maxBytes) {
			$result['skipped'][] = [ 'path' => $entry['path'], 'reason' => 'larger than ' . (int)($maxBytes / 1048576) . ' MB' ];
			continue;
		    }
		    $decrypt = $decCred && ($decryptMode === 'all' || \util\FileDecrypt::looksEncrypted($entry['name'], (string)$decCred->method));
		    $filename = self::savedName($entry['name'], $decrypt ? (string)$decCred->method : null, $decompress, $verifyMode === 'attached' ? (string)$verCred->method : null);
		    if ($operation === 'download' && !\models\File::isAllowedName($filename)) {
			$result['skipped'][] = [ 'path' => $entry['path'], 'reason' => "a type that cannot be saved: `{$filename}`" ];
			continue;
		    }
		    //a separate signature: there, or the file waits (does not count, not taken) / an error
		    $sigPath = $verifyMode === 'detached' ? $signatureOf($entry['path']) : null;
		    if ($verifyMode === 'detached' && $sigPath === null) {
			if ($missingSignature === 'error')
			    $result['errors'][] = [ 'path' => $entry['path'], 'error' => 'no signature file (' . implode(', ', array_map(fn($e) => $entry['name'] . $e, $sigEndings)) . ')' ];
			else
			    $result['skipped'][] = [ 'path' => $entry['path'], 'reason' => 'no signature file yet' ];
			continue;
		    }
		    if (!$entry['exact'] && $entry['modified'] !== null)
			$entry = $this->exactTimes($client, $entry);
		    $done++;
		    $info = [ 'name' => $entry['name'], 'path' => $entry['path'], 'folder' => $entry['folder'], 'size' => $entry['size'],
			'modified' => $entry['modified'] !== null ? gmdate('Y-m-d\TH:i:s\Z', $entry['modified']) : null ];
		    if ($operation === 'list') {
			$result['files'][] = $info;
			$taken[] = $entry;
			continue;
		    }

		    try {
			$work = $work ?? \util\FileDecrypt::workDir();
			$saved = $this->take($client, $entry, $work, $filename, $decrypt ? $decCred : null, $decompress, $maxBytes, $verifyMode !== 'none' ? $verCred : null, $verifyMode, $sigPath);
		    } catch (\RuntimeException $e) {
			$result['errors'][] = [ 'path' => $entry['path'], 'error' => $e->getMessage() ];
			$this->exec->log($this->wfid, "ftp {$entry['path']}: " . $e->getMessage());
			continue;
		    }
		    $result['files'][] = [ 'id' => $saved['id'], 'filename' => $saved['filename'] ] + $info + [ 'savedSize' => $saved['size'], 'decrypted' => $decrypt,
			'decompressed' => $saved['decompressed'], 'signed' => $saved['signer'] !== null, 'signer' => $saved['signer'], 'signature' => $sigPath, 'contentType' => $saved['contentType'] ];
		    $stats['bytes'] += $saved['size'];
		    $taken[] = $entry;

		    //afterwards (the file is saved: a failure here is an error, the file stays taken)
		    try {
			//CLD: 2026-10-20T20:00:00+03:00: with its signature file
			foreach(array_filter([ $entry['path'], $sigPath ]) as $p) {
			    if ($afterAction === 'delete') {
				$client->delete($p);
			    } elseif ($afterAction === 'move') {
				if (!$madeFolder)
				    $client->mkdir($moveTo);
				$madeFolder = true;
				$client->rename($p, \util\FtpClient::join($moveTo, basename($p)));
			    }
			}
		    } catch (\RuntimeException $e) {
			$result['errors'][] = [ 'path' => $entry['path'], 'error' => ($afterAction === 'delete' ? 'saved, but not deleted: ' : 'saved, but not moved: ') . $e->getMessage() ];
		    }
		}
	    }, $timeout);
	} catch (\RuntimeException $e) {
	    $result['errors'][] = [ 'path' => $folder === '' ? '.' : $folder, 'error' => $e->getMessage() ];
	} finally {
	    if ($work)
		\util\FileDecrypt::removeDir($work);
	    if ($state)
		$state->finish($taken);
	}

	$result['count'] = count($result['files']);
	$result['error'] = $result['errors'] ? $result['errors'][0]['path'] . ': ' . $result['errors'][0]['error'] : null;
	$name = (string)$this->cfg('variable', 'ftp');
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $result);
	$ms = (int)round((microtime(true) - $start) * 1000);
	$this->exec->log($this->wfid, "ftp {$operation} {$shown}: {$stats['listed']} listed, {$stats['matched']} matched, " . ($operation === 'list' ? "{$result['count']} returned" : "{$result['count']} saved ({$stats['bytes']} bytes)")
	    . ($result['skipped'] ? ', ' . count($result['skipped']) . ' skipped' : '') . ($result['errors'] ? ', ' . count($result['errors']) . ' error(s): ' . $result['error'] : '') . " ({$ms} ms)");
	if ($result['errors']) {
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException('FTP / SFTP: ' . $result['error']);
	}
	return [ 0 ];
    }

    //the name a file is saved under: decrypted (the method's ending removed), decompressed (.gz / .bz2 removed)
    //CLD: 2026-10-20T20:00:00+03:00: + $unwrapMethod: an attached signature's ending removed after decrypting (.gpg, .p7m)
    public static function savedName($name, $decryptMethod, $decompress, $unwrapMethod = null) {
	if ($decryptMethod !== null)
	    $name = \util\FileDecrypt::decryptedName($name, $decryptMethod);
	if ($unwrapMethod !== null)
	    $name = \util\FileVerify::unwrappedName($name, $unwrapMethod);
	if ($decompress && \util\FileDecrypt::compression($name) !== null)
	    $name = \util\FileDecrypt::decompressedName($name);
	return \models\node\NodeAppDownload::filename($name, '', '', '');
    }

    //$entry with its size and time to the second (stat); as it was when the server does not tell
    protected function exactTimes($client, array $entry) {
	try {
	    [ $size, $modified ] = $client->stat($entry['path']);
	    $entry['size'] = $size ?? $entry['size'];
	    if ($modified !== null) {
		$entry['modified'] = $modified;
		$entry['exact'] = true;
	    }
	} catch (\RuntimeException $e) {
	}
	return $entry;
    }

    //one file: downloaded, decrypted, decompressed, saved in the project's Files: [ id, filename, size, decompressed, contentType, signer ]
    //CLD: 2026-10-20T20:00:00+03:00: + its signature checked ($verCred, $verifyMode; detached: $sigPath, of the file as downloaded, before decrypting; attached: while
    //decrypting with PGP, else after it, the content unwrapped); a bad / untrusted signature: an error, nothing saved
    protected function take($client, array $entry, $work, $filename, $decCred, $decompress, $maxBytes, $verCred = null, $verifyMode = 'none', $sigPath = null) {
	$raw = $work . '/raw';
	$fh = fopen($raw, 'w+b');
	try {
	    $client->download($entry['path'], $fh, $maxBytes);
	} finally {
	    fclose($fh);
	}
	$current = $raw;
	$name = $entry['name'];
	$signer = null;
	try {
	    if ($verCred && $verifyMode === 'detached') {
		$sh = fopen($work . '/sig', 'w+b');
		try {
		    $client->download($sigPath, $sh, self::MAX_SIGNATURE);
		} finally {
		    fclose($sh);
		}
		$signer = \util\FileVerify::detached($verCred, $current, $work . '/sig')['signer'];
	    }
	    if ($decCred) {
		$checked = \util\FileDecrypt::decrypt($decCred, $current, $work . '/dec', $maxBytes, $verifyMode === 'attached' ? $verCred : null);
		if ($checked !== null)
		    $signer = $checked['signer'];
		$current = $work . '/dec';
		$name = \util\FileDecrypt::decryptedName($name, (string)$decCred->method);
	    }
	    if ($verCred && $verifyMode === 'attached' && $signer === null) {
		$signer = \util\FileVerify::attached($verCred, $current, $work . '/ver')['signer'];
		$current = $work . '/ver';
		$name = \util\FileVerify::unwrappedName($name, (string)$verCred->method);
	    }
	    $decompressed = false;
	    if ($decompress && ($kind = \util\FileDecrypt::compression($name)) !== null) {
		\util\FileDecrypt::decompress($current, $work . '/unz', $kind, $maxBytes);
		$current = $work . '/unz';
		$decompressed = true;
	    }
	    clearstatcache(true, $current);
	    $size = (int)filesize($current);
	    $stream = fopen($current, 'rb');
	    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
	    //CLD: 2026-10-22T00:35:40+03:00: models\File::CONTENT_TYPES (was NodeAppGoogle's, now in the GoogleIntegration plugin)
	    $contentType = \models\File::CONTENT_TYPES[$ext] ?? 'application/octet-stream';
	    try {
		$id = \models\File::createFromStream($filename, $stream, [ 'metadata' => [
		    'project' => $this->exec->project,
		    'aid' => $this->exec->aid,
		    'owner' => null,
		    'source' => 'ftp',
		    'remote' => mb_substr($entry['path'], 0, 500),
		    'wf' => $this->exec->wf,
		    'created_at' => new \MongoDB\BSON\UTCDateTime(),
		] ]);
	    } finally {
		if (is_resource($stream))
		    fclose($stream);
	    }
	    return [ 'id' => (string)$id, 'filename' => $filename, 'size' => $size, 'decompressed' => $decompressed, 'contentType' => $contentType, 'signer' => $signer ];
	} finally {
	    foreach([ 'raw', 'dec', 'unz', 'sig', 'ver' ] as $f)
		@unlink($work . '/' . $f);
	}
    }
}
