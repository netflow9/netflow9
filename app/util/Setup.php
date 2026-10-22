<?php

/*
    the initial setup (controllers\SetupController): the system prerequisites, and the app.env file it writes
    the needs of the steps added since (FTP / SFTP: zlib, bz2, curl FTP/FTPS/SFTP, proc_open, gpg, openssl - recommended; Google: curl HTTPS)
    and the zip extension (XLSX table import)
    //CLD: 2026-10-20T23:05:00+03:00: J's needs (sys\J: expressions, Code step) in the web PHP and in the console PHP (the daemon runs them): J_EXTENSIONS, PCRE with Unicode
//  Setup::checks()                 [ [ 'name', 'ok' => bool, 'value' => what was found, 'need' => what is needed, 'required' => bool ], ... ]
//  Setup::ready($checks)           true when every required check passes
//  Setup::envContent($values)      the text of app.env (values single-quoted: literal, see V::parse_env)
//  Setup::writeEnv($content)       writes BASE/app.env (a temporary file renamed into place); false when it cannot
*/
namespace util;

class Setup {

    const PHP_MIN = '8.1.0';
    //the mongo-php-library in app/pkgs needs this driver
    //CLD: 2026-10-20T23:20:00+03:00: the library's folder is no longer checked (MONGO_LIBRARY): it ships with the app; the mongodb extension is (driverCheck)
    const MONGODB_MIN = '2.3.0';
    //the PHP extensions the app uses (mongodb is checked with its version)
    const EXTENSIONS = [
	'openssl' => 'encrypted credentials, TLS',
	'mbstring' => 'text handling',
	'curl' => 'HTTP and AI steps, OAuth credentials',
	'json' => 'JSON',
	'iconv' => 'email and table import encodings',
	'simplexml' => 'XLSX table import',
	'ctype' => 'tokens',
	'session' => 'sign-in',
	'zip' => 'XLSX table import',
    ];
    //CLD: 2026-10-20T23:05:00+03:00: the PHP extensions J needs (sys\J: the expressions and the Code step; see app/sys/J/README.md), required in both PHPs
    const J_EXTENSIONS = [
	'mbstring' => 'J (expressions, Code step): strings',
	'json' => 'J: JSON',
	'ctype' => 'J: parsing',
    ];
    //J's regular expressions run on PCRE (always in PHP), with Unicode (the u modifier, \p{...})
    const PCRE_MIN = '10.30';
    //CLD: 2026-10-20T17:10:00+03:00: what only some steps need (recommended, not required: the app runs without them, those steps fail when used)
    //the PHP extensions of the FTP / SFTP steps (util\FileDecrypt::decompress, util\FileEncrypt compression)
    const STEP_EXTENSIONS = [
	'zlib' => 'FTP / SFTP steps: gzip (.gz) compression',
	'bz2' => 'FTP / SFTP step: bzip2 (.bz2) decompression',
    ];
    //the curl protocols of the FTP / SFTP steps (util\FtpClient; SFTP needs curl built with libssh)
    const STEP_CURL_PROTOCOLS = [ 'ftp', 'ftps', 'sftp' ];
    //the programs the FTP / SFTP steps run for encryption, decryption and signatures (util\FileDecrypt, FileEncrypt, FileSign, FileVerify)
    const STEP_TOOLS = [
	'gpg' => 'FTP / SFTP steps: PGP / GPG encryption and signatures',
	'openssl' => 'FTP / SFTP steps: OpenSSL and S/MIME encryption and signatures',
    ];
    //CLD: 2026-10-19T13:42:19+03:00: the extensions the daemon needs in the command-line PHP (app/v runs it; it may have other extensions than the web one)
    //CLD: 2026-10-19T13:44:01+03:00: only the daemon's; the command-line PHP is also checked for PHP_MIN, the driver and EXTENSIONS (the daemon runs the app's code)
    //CLD: 2026-10-19T14:17:24+03:00: the command-line PHP is checked only for these and for the same PHP version as the web one (no longer PHP_MIN, the driver, EXTENSIONS)
    const CLI_PHP = '/usr/bin/php';
    const CLI_EXTENSIONS = [
	'pcntl' => 'the daemon: stop signals (SIGTERM, SIGINT)',
	'posix' => 'the daemon: stopping its workers',
    ];
    //folders the app writes to (BASE itself: app.env)
    const WRITABLE = [
	'' => 'app.env is written here',
	'logs' => 'logs',
	'tmp' => 'temporary files',
	'run' => 'the daemon socket',
	'var' => 'stored files'
    ];

    public static function checks() {
	//CLD: 2026-10-19T13:44:01+03:00: the PHP version, the driver and the extensions: phpChecks (shared with the command-line PHP's)
	$loaded = [];
	foreach(get_loaded_extensions() as $ext)
	    $loaded[strtolower($ext)] = phpversion($ext) ?: 'loaded';
	//CLD: 2026-10-20T23:05:00+03:00: + J_EXTENSIONS (one check an extension, both needs named) and PCRE
	$extensions = self::EXTENSIONS;
	foreach(self::J_EXTENSIONS as $ext => $why)
	    $extensions[$ext] = isset($extensions[$ext]) ? "{$extensions[$ext]}; {$why}" : $why;
	$checks = self::phpChecks('', PHP_VERSION, $loaded, $extensions);
	$checks[] = self::pcreCheck('', PCRE_VERSION, self::pcreUnicode());

	//CLD: 2026-10-20T17:10:00+03:00: the steps' own needs: curl's HTTPS (required: HTTP, AI and Google steps, OAuth), then the FTP / SFTP steps' extensions, curl protocols,
	//proc_open and programs (recommended)
	$protocols = function_exists('curl_version') ? array_map('strtolower', (array)(curl_version()['protocols'] ?? [])) : [];
	$checks[] = [ 'name' => 'curl HTTPS', 'ok' => in_array('https', $protocols, true), 'value' => in_array('https', $protocols, true) ? 'yes' : 'no',
	    'need' => 'HTTP Request, AI and Google steps, OAuth credentials', 'required' => true ];
	foreach(self::STEP_EXTENSIONS as $ext => $why)
	    $checks[] = [ 'name' => "{$ext} extension", 'ok' => isset($loaded[$ext]), 'value' => $loaded[$ext] ?? 'not installed', 'need' => $why, 'required' => false ];
	$missing = array_values(array_diff(self::STEP_CURL_PROTOCOLS, $protocols));
	$checks[] = [ 'name' => 'curl ' . strtoupper(implode(', ', self::STEP_CURL_PROTOCOLS)), 'ok' => !$missing, 'value' => $missing ? 'missing: ' . implode(', ', $missing) : 'yes',
	    'need' => 'FTP / SFTP steps (SFTP: curl built with libssh)', 'required' => false ];
	//a function listed in disable_functions does not exist
	$checks[] = [ 'name' => 'proc_open', 'ok' => function_exists('proc_open'), 'value' => function_exists('proc_open') ? 'enabled' : 'disabled',
	    'need' => 'FTP / SFTP steps: runs gpg and openssl (encryption, signatures)', 'required' => false ];
	foreach(self::STEP_TOOLS as $tool => $why) {
	    $path = FileDecrypt::tool($tool, false);
	    $checks[] = [ 'name' => "Program: {$tool}", 'ok' => $path !== null, 'value' => $path ?? 'not installed', 'need' => $why, 'required' => false ];
	}

	foreach(self::WRITABLE as $dir => $why) {
	    $path = rtrim(BASE . '/' . $dir, '/');
	    $checks[] = [ 'name' => 'Writable: ' . ($dir === '' ? BASE : $dir . '/'), 'ok' => is_dir($path) && is_writable($path), 'value' => !is_dir($path) ? 'missing' : (is_writable($path) ? 'writable' : 'not writable'),
		'need' => $why, 'required' => true ];
	}

	//CLD: 2026-10-19T13:42:19+03:00: pcntl and posix in the command-line PHP (the daemon); when that PHP cannot be run from here, a warning (not required)
	//CLD: 2026-10-19T13:44:01+03:00: + its PHP version, driver and the app's extensions (the same checks as the web PHP's); one warning when it cannot be run
	$cli = self::cliInfo();
	if ($cli === null)
	    $checks[] = [ 'name' => 'Console PHP (' . self::CLI_PHP . ')', 'ok' => false, 'value' => 'cannot check',
		'need' => 'the daemon; run ' . self::CLI_PHP . ' -v and ' . self::CLI_PHP . ' -m to see it', 'required' => false ];
	//CLD: 2026-10-19T14:17:24+03:00: only the same PHP version as the web one, and CLI_EXTENSIONS (was phpChecks: PHP_MIN, the driver, EXTENSIONS + CLI_EXTENSIONS)
	else {
	    $checks[] = [ 'name' => 'PHP version (console PHP)', 'ok' => $cli['php'] === PHP_VERSION, 'value' => $cli['php'], 'need' => 'the same as the web PHP: ' . PHP_VERSION, 'required' => true ];
	    //CLD: 2026-10-20T23:20:00+03:00: the mongodb extension (the daemon uses the database)
	    $checks[] = self::driverCheck(' (console PHP)', $cli['ext']['mongodb'] ?? null);
	    //CLD: 2026-10-20T23:05:00+03:00: + J_EXTENSIONS and PCRE (the daemon runs the expressions and the Code steps)
	    foreach(self::CLI_EXTENSIONS + self::J_EXTENSIONS as $ext => $why)
		$checks[] = [ 'name' => "{$ext} extension (console PHP)", 'ok' => isset($cli['ext'][$ext]), 'value' => $cli['ext'][$ext] ?? 'not installed', 'need' => $why, 'required' => true ];
	    $checks[] = self::pcreCheck(' (console PHP)', (string)($cli['pcre'][0] ?? ''), (bool)($cli['pcre'][1] ?? false));
	}

	$checks[] = [ 'name' => 'HTTPS', 'ok' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'value' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'yes' : 'no',
	    'need' => 'the links the app sends are https://', 'required' => false ];
	return $checks;
    }

    //CLD: 2026-10-19T13:44:01+03:00: the checks of a PHP: its version, the MongoDB driver's version, $extensions ([ name => why ]); $loaded: [ lower case name => version ]; $suffix ends each name
    private static function phpChecks($suffix, $php, array $loaded, array $extensions) {
	$checks = [];
	$checks[] = [ 'name' => 'PHP version' . $suffix, 'ok' => version_compare($php, self::PHP_MIN, '>='), 'value' => $php, 'need' => self::PHP_MIN . ' or newer', 'required' => true ];
	$checks[] = self::driverCheck($suffix, $loaded['mongodb'] ?? null);
	foreach($extensions as $ext => $why)
	    $checks[] = [ 'name' => "{$ext} extension{$suffix}", 'ok' => isset($loaded[$ext]), 'value' => $loaded[$ext] ?? 'not installed', 'need' => $why, 'required' => true ];
	return $checks;
    }

    //CLD: 2026-10-20T23:20:00+03:00: the mongodb extension of a PHP: loaded ($version: its version, null when not loaded), MONGODB_MIN or newer (was in phpChecks)
    private static function driverCheck($suffix, $version) {
	return [ 'name' => 'MongoDB driver (mongodb extension)' . $suffix, 'ok' => $version !== null && version_compare($version, self::MONGODB_MIN, '>='),
	    'value' => $version ?? 'not loaded', 'need' => 'loaded, ' . self::MONGODB_MIN . ' or newer', 'required' => true ];
    }

    //CLD: 2026-10-20T23:05:00+03:00: the PCRE check of a PHP: the library's version ($version: PCRE_VERSION, e.g. "10.40 2022-04-14") and its Unicode support
    private static function pcreCheck($suffix, $version, $unicode) {
	$number = explode(' ', trim((string)$version))[0];
	return [ 'name' => 'PCRE' . $suffix, 'ok' => $number !== '' && version_compare($number, self::PCRE_MIN, '>=') && $unicode,
	    'value' => $number === '' ? 'unknown' : $number . ($unicode ? '' : ', no Unicode'), 'need' => self::PCRE_MIN . ' or newer, with Unicode: J regular expressions', 'required' => true ];
    }

    //whether this PHP's PCRE matches Unicode (a property of a non-ASCII letter)
    private static function pcreUnicode() {
	return @preg_match('/^\p{Lu}$/u', "\u{C9}") === 1;
    }

    //CLD: 2026-10-19T13:42:19+03:00: the extensions loaded by the command-line PHP (lower case), or null when it cannot be run from the web PHP (missing, or proc_open disabled)
    //CLD: 2026-10-19T13:44:01+03:00: renamed cliExtensions -> cliInfo: [ 'php' => version, 'ext' => [ lower case name => version ] ] (was the list of names)
    private static function cliInfo() {
	if (!is_executable(self::CLI_PHP) || !function_exists('proc_open'))
	    return null;
	//CLD: 2026-10-20T23:05:00+03:00: + pcre: [ PCRE_VERSION, Unicode support ] (as pcreUnicode)
	$code = '$e = []; foreach(get_loaded_extensions() as $x) $e[strtolower($x)] = phpversion($x) ?: "loaded"; '
	    . 'echo json_encode([ "php" => PHP_VERSION, "ext" => $e, "pcre" => [ PCRE_VERSION, @preg_match("/^\\p{Lu}\$/u", "\\u{C9}") === 1 ] ]);';
	$proc = @proc_open([ self::CLI_PHP, '-r', $code ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes);
	if (!is_resource($proc))
	    return null;
	$out = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$exit = proc_close($proc);
	$info = json_decode((string)$out, true);
	return $exit === 0 && is_string($info['php'] ?? null) && is_array($info['ext'] ?? null) ? $info : null;
    }

    public static function ready(array $checks) {
	foreach($checks as $c) {
	    if ($c['required'] && !$c['ok'])
		return false;
	}
	return true;
    }

    //$values: BASE_URL, MONGO_URI, MONGO_DB, SMTP_* , MAIL_FROM, ERRORS_EMAIL (empty ones are written empty; MAIL_FROM is left out when empty)
    //CLD: 2026-10-19T14:18:52+03:00: MAIL_INBOUND_DOMAIN is not asked: written commented out (the Email inbound trigger needs it; Haraka receives that domain)
    public static function envContent(array $v) {
	$q = fn($s) => "'" . (string)$s . "'";
	$b = fn($x) => $x ? 'true' : 'false';
	$lines = [
	    '#!/bin/bash',
	    '',
	    '#CLD: ' . date('c') . ': written by the initial setup (/setup)',
	    'BASE=' . $q(BASE),
	    '',
	    'BASE_URL=' . $q($v['BASE_URL']),
	    '',
	    '#mongo database',
	    '#mongodb://<username>:<password>@db1.example.net:27017,db2.example.net:2500/?replicaSet=test&connectTimeoutMS=300000',
	    '#see: https://docs.mongodb.com/manual/reference/connection-string/#connections-connection-options',
	    'MONGO_URI=' . $q($v['MONGO_URI']),
	    'MONGO_DB=' . $q($v['MONGO_DB']),
	    '',
	    '#outbound SMTP',
	    'SMTP_HOST=' . $q($v['SMTP_HOST']),
	    'SMTP_PORT=' . (int)$v['SMTP_PORT'],
	    'SMTP_USERNAME=' . $q($v['SMTP_USERNAME']),
	    'SMTP_PASSWORD=' . $q($v['SMTP_PASSWORD']),
	    'SMTP_AUTH=' . $b($v['SMTP_USERNAME'] !== ''),
	    "#false, 'ssl', or 'tls'",
	    'SMTP_SECURE=' . ($v['SMTP_SECURE'] ? $q($v['SMTP_SECURE']) : 'false'),
	    'SMTP_AUTOTLS=true',
	    '',
	    'SMTP_DOMAIN=' . $q($v['SMTP_DOMAIN']),
	];
	if ($v['MAIL_FROM'] !== '')
	    $lines[] = 'MAIL_FROM=' . $q($v['MAIL_FROM']);
	array_push($lines,
	    '',
	    '#where to report errors',
	    'ERRORS_EMAIL=' . $q($v['ERRORS_EMAIL']),
	    '',
	    '#application secret used by \sys\Security encrypt/decrypt',
	    'APP_SECRET=' . $q(bin2hex(random_bytes(32))),
	    '',
	    '#the domain of the inbound email addresses (Email inbound trigger; Haraka receives it: haraka/config/host_list)',
	    "#MAIL_INBOUND_DOMAIN='" . ((string)parse_url($v['BASE_URL'], PHP_URL_HOST) ?: 'example.com') . "'",
	    '',
	    '#executions run inside a web request at the same time, per account; 0 = no limit',
	    'MAX_SYNC_EXECUTIONS=0',
	    '',
	);
	return implode("\n", $lines);
    }

    public static function writeEnv($content) {
	$file = BASE . '/app.env';
	$tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
	if (@file_put_contents($tmp, $content, LOCK_EX) !== strlen($content)) {
	    @unlink($tmp);
	    return false;
	}
	//it holds secrets
	@chmod($tmp, 0640);
	if (!@rename($tmp, $file)) {
	    @unlink($tmp);
	    return false;
	}
	return true;
    }
}
