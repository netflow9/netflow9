<?php

namespace commands;

//CLD: 2026-10-08T09:19:59+03:00: new command: try \util\ImapClient against a real mailbox, step by step (connect, sign in, folders, select,
//latest messages); read-only: messages are fetched with BODY.PEEK (not marked read), nothing is moved or deleted
//  ../v imap::test                                     uses the placeholders below
//  ../v imap::test --preset=gmail --user=me@gmail.com --token=ya29...
//  ../v imap::test --host=imap.example.com --user=me --password=secret --folder=INBOX --count=5 --debug
//options (override the placeholders):
//  --preset=gmail|outlook   host / port / security of the provider
//  --host= --port= --security=ssl|starttls|none
//  --user=                  username (password) or mailbox address (XOAUTH2)
//  --password=              sign in with LOGIN
//  --token=                 OAuth access token: sign in with AUTHENTICATE XOAUTH2 (used when set, else the password)
//  --folder=  --count=      folder to open, number of latest messages to show (0 = none)
//  --insecure               do not verify the server certificate (test servers only)
//  --debug                  print the protocol exchange (credentials are never printed)
class ImapCommand extends \sys\BaseController {

    //placeholders: fill in, or pass the options above
    const HOST = 'imap.gmail.com';
    const PORT = 993;
    const SECURITY = 'ssl';
    const USER = 'user@example.com';
    const PASSWORD = '';	//password or app password (LOGIN)
    const TOKEN = '';		//OAuth 2.0 access token (XOAUTH2); takes precedence over PASSWORD
    const FOLDER = 'INBOX';
    const COUNT = 5;

    const PRESETS = [
	'gmail' => [ 'host' => 'imap.gmail.com', 'port' => 993, 'security' => 'ssl' ],
	'outlook' => [ 'host' => 'outlook.office365.com', 'port' => 993, 'security' => 'ssl' ],
    ];

    public function test($req = null, $resp = null) {
	$p = $req->params ?? [];
	$opt = fn($key, $default) => (isset($p[$key]) && $p[$key] !== true) ? (string)$p[$key] : $default;

	$preset = self::PRESETS[$opt('preset', '')] ?? [];
	$host = $opt('host', $preset['host'] ?? self::HOST);
	$port = (int)$opt('port', (string)($preset['port'] ?? self::PORT));
	$security = $opt('security', $preset['security'] ?? self::SECURITY);
	$user = $opt('user', self::USER);
	$password = $opt('password', self::PASSWORD);
	$token = $opt('token', self::TOKEN);
	$folder = $opt('folder', self::FOLDER);
	$count = max(0, (int)$opt('count', (string)self::COUNT));

	if (isset($p['preset']) && empty($preset))
	    $this->fail('options', 'unknown preset (gmail, outlook)');
	if (!in_array($security, [ 'ssl', 'starttls', 'none' ], true))
	    $this->fail('options', 'security must be ssl, starttls or none');
	if ($token === '' && $password === '')
	    $this->fail('options', 'set a password or a token (placeholders in commands/ImapCommand.php, or --password= / --token=)');

	$options = [ 'timeout' => 30, 'verifyPeer' => empty($p['insecure']) ];
	if (!empty($p['debug']))
	    $options['debug'] = function($dir, $line) { echo '    ' . ($dir == 'C' ? '> ' : '< ') . mb_strimwidth($line, 0, 200, '...') . "\n"; };
	$imap = new \util\ImapClient($options);

	//1. connect
	$this->step("connect {$host}:{$port} ({$security})", function() use ($imap, $host, $port, $security) {
	    $imap->connect($host, $port, $security);
	    return 'capabilities: ' . implode(' ', $imap->capabilities);
	});

	//2. sign in
	if ($token !== '') {
	    $this->step("sign in as {$user} with XOAUTH2", function() use ($imap, $user, $token) {
		if (!$imap->hasCapability('AUTH=XOAUTH2'))
		    echo "    note: the server does not announce AUTH=XOAUTH2, trying anyway\n";
		$imap->authenticateXoauth2($user, $token);
		return 'ok';
	    });
	} else {
	    $this->step("sign in as {$user} with LOGIN (password)", function() use ($imap, $user, $password) {
		$imap->login($user, $password);
		return 'ok';
	    });
	}

	//3. folders
	$this->step('list folders', function() use ($imap) {
	    $names = array_column($imap->listFolders(), 'name');
	    return count($names) . ': ' . implode(', ', $names);
	});

	//4. open the folder
	$box = $this->step("open folder `{$folder}`", function() use ($imap, $folder) {
	    return $imap->select($folder);
	});
	echo "    messages: {$box['exists']}, UIDVALIDITY: {$box['uidvalidity']}, UIDNEXT: {$box['uidnext']}\n";

	//5. latest messages (headers, text start, attachments), not marked read
	if ($count > 0) {
	    $uids = $this->step('search', function() use ($imap) {
		return $imap->uidSearch('ALL');
	    });
	    $unread = $this->step('search unread', function() use ($imap) {
		return $imap->uidSearch('UNSEEN');
	    });
	    echo "    all: " . count($uids) . ", unread: " . count($unread) . "\n";

	    $latest = array_slice($uids, -$count);
	    $sizes = $imap->fetchSizes($latest);
	    foreach(array_reverse($latest) as $uid) {
		$this->step("fetch UID {$uid} (" . number_format($sizes[$uid] ?? 0) . ' bytes)', function() use ($imap, $uid) {
		    $raw = $imap->fetchMessage($uid);
		    if ($raw === null)
			return 'not found';
		    $m = new \util\MailParser($raw);
		    $from = $m->from();
		    $date = $m->date();
		    $lines = [
			'date:    ' . ($date ? $date->format('Y-m-d H:i:s P') : '-'),
			'from:    ' . trim(($from['name'] ?? '') . ' <' . ($from['email'] ?? '') . '>'),
			'subject: ' . $m->subject(),
			'text:    ' . mb_strimwidth(preg_replace('/\s+/u', ' ', trim($m->text() !== '' ? $m->text() : strip_tags($m->html()))), 0, 100, '...'),
		    ];
		    foreach($m->attachments() as $a)
			$lines[] = "attach:  {$a['filename']} ({$a['contentType']}, " . number_format($a['size']) . " bytes, {$a['disposition']})";
		    return implode("\n    ", $lines);
		});
	    }
	}

	//6. done
	$this->step('logout', function() use ($imap) {
	    $imap->logout();
	    return 'ok';
	});
	echo "all steps passed\n";
    }

    //run one step: prints its name, result and time; stops the command when it fails
    private function step($name, callable $fn) {
	echo "* {$name}\n";
	$t = microtime(true);
	try {
	    $result = $fn();
	} catch (\Exception $e) {
	    $this->fail($name, get_class($e) . ': ' . $e->getMessage());
	}
	$ms = round((microtime(true) - $t) * 1000);
	if (is_string($result))
	    echo "    {$result}\n";
	echo "    ({$ms} ms)\n";
	return $result;
    }

    private function fail($step, $message) {
	echo "FAILED at `{$step}`: {$message}\n";
	exit(1);
    }
}
