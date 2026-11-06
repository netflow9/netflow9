<?php

/*
    a Windows Remote Management client (WS-Management over HTTPS, port 5986) that runs PowerShell scripts on a Windows server, for the Hyper-V credential,
    steps and trigger. A cmd shell (http://schemas.microsoft.com/wbem/wsman/1/windows/shell/cmd) is created, powershell.exe run in it with -EncodedCommand (UTF-16LE, base64; no
    cmd.exe: up to about 12000 characters of script), its output received until the command is done, then the shell deleted. Signed in by curl: NTLM (domain or local accounts) or
    Basic (local accounts, when the service allows it). Public addresses only, unless the account allows private network addresses (Account allow_private_addresses: a Hyper-V
    host is on-premises; never this server itself); the certificate checked unless the credential trusts it (a self-signed one).

    The script runs in Windows PowerShell 5.1 wrapped by ps(): errors stop it ($ErrorActionPreference Stop), the parameters given are $P (JSON, read with ConvertFrom-Json from a
    quoted string: nothing of them is run), the script's value is written as JSON { ok, data } or { ok: false, error } on stdout (UTF-8). Its first line names it: # r22:<name>

    $w = new WinRM($cred, $aid, $timeout);
    $w->ps($name, $script, $params)      the script's value (decoded JSON); \RuntimeException with PowerShell's or WinRM's message
    $w->run($command, $arguments)       [ stdout, stderr, exit code ] of a command

    WinRM::$testing: the check suite's local server (http://127.0.0.1): no https, no address guard
*/

namespace util;

class WinRM {
    const NS = [
	's' => 'http://www.w3.org/2003/05/soap-envelope',
	'a' => 'http://schemas.xmlsoap.org/ws/2004/08/addressing',
	'w' => 'http://schemas.dmtf.org/wbem/wsman/1/wsman.xsd',
	'p' => 'http://schemas.microsoft.com/wbem/wsman/1/wsman.xsd',
	'rsp' => 'http://schemas.microsoft.com/wbem/wsman/1/windows/shell',
    ];
    const SHELL = 'http://schemas.microsoft.com/wbem/wsman/1/windows/shell/cmd';
    const ACTION = [
	'create' => 'http://schemas.xmlsoap.org/ws/2004/09/transfer/Create',
	'delete' => 'http://schemas.xmlsoap.org/ws/2004/09/transfer/Delete',
	'command' => 'http://schemas.microsoft.com/wbem/wsman/1/windows/shell/Command',
	'receive' => 'http://schemas.microsoft.com/wbem/wsman/1/windows/shell/Receive',
	'signal' => 'http://schemas.microsoft.com/wbem/wsman/1/windows/shell/Signal',
    ];
    const DONE = 'http://schemas.microsoft.com/wbem/wsman/1/windows/shell/CommandState/Done';
    const TERMINATE = 'http://schemas.microsoft.com/wbem/wsman/1/windows/shell/signal/terminate';
    //WinRM's code of a Receive that waited OperationTimeout without output (receive again)
    const TIMED_OUT = '2150858793';
    //seconds a Receive waits for output
    const WAIT = 20;
    const MAX_OUTPUT = 20 * 1024 * 1024;
    //the longest script (characters), as the command line of powershell.exe allows it
    const MAX_SCRIPT = 12000;

    public static $testing = false;

    protected $cred;
    protected $aid;
    protected $timeout;
    protected $start;

    public function __construct($cred, $aid, $timeout = 60) {
	$this->cred = $cred;
	$this->aid = $aid;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    //the WS-Management address: the server as given, /wsman added
    public static function endpoint($url) {
	$url = rtrim(trim((string)$url), '/');
	return preg_match('~/wsman$~i', $url) ? $url : $url . '/wsman';
    }

    public function left() {
	return $this->timeout - (int)(microtime(true) - $this->start);
    }

    protected function options() {
	$account = $this->aid ? \V::app()->om->findById('Account', (string)$this->aid) : null;
	return [
	    'https' => !self::$testing,
	    'public' => !self::$testing,
	    'private' => (bool)($account->allow_private_addresses ?? false),
	    'insecure' => (bool)$this->cred->trustCertificate,
	    'auth' => [ (string)$this->cred->authMethod === 'basic' ? 'basic' : 'ntlm', (string)$this->cred->username, (string)$this->cred->password ],
	];
    }

    public static function uuid() {
	$b = random_bytes(16);
	$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
	$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
	return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    protected static function x($v) {
	return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    //a WS-Management message
    public static function envelope($to, $action, $body, $shellId = null, array $options = [], $wait = self::WAIT) {
	$ns = '';
	foreach(self::NS as $k => $v)
	    $ns .= " xmlns:{$k}=\"{$v}\"";
	$h = '<a:To>' . self::x($to) . '</a:To><w:ResourceURI s:mustUnderstand="true">' . self::SHELL . '</w:ResourceURI>'
	    . '<a:ReplyTo><a:Address s:mustUnderstand="true">http://schemas.xmlsoap.org/ws/2004/08/addressing/role/anonymous</a:Address></a:ReplyTo>'
	    . '<a:Action s:mustUnderstand="true">' . self::ACTION[$action] . '</a:Action><w:MaxEnvelopeSize s:mustUnderstand="true">512000</w:MaxEnvelopeSize>'
	    . '<a:MessageID>uuid:' . self::uuid() . '</a:MessageID><w:Locale xml:lang="en-US" s:mustUnderstand="false"/><p:DataLocale xml:lang="en-US" s:mustUnderstand="false"/>'
	    . '<w:OperationTimeout>PT' . (int)$wait . '.000S</w:OperationTimeout>';
	if ($shellId !== null)
	    $h .= '<w:SelectorSet><w:Selector Name="ShellId">' . self::x($shellId) . '</w:Selector></w:SelectorSet>';
	if ($options) {
	    $h .= '<w:OptionSet>';
	    foreach($options as $k => $v)
		$h .= '<w:Option Name="' . self::x($k) . '">' . self::x($v) . '</w:Option>';
	    $h .= '</w:OptionSet>';
	}
	return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<s:Envelope{$ns}><s:Header>{$h}</s:Header><s:Body>{$body}</s:Body></s:Envelope>";
    }

    //WinRM's message of a fault: [ code, text ]
    public static function fault(\DOMXPath $xp) {
	$code = (string)$xp->evaluate('string(//*[local-name()="WSManFault"]/@Code)');
	$text = trim((string)$xp->evaluate('string(//*[local-name()="WSManFault"]/*[local-name()="Message"])'));
	if ($text === '')
	    $text = trim((string)$xp->evaluate('string(//*[local-name()="Fault"]/*[local-name()="Reason"]/*[local-name()="Text"])'));
	return [ $code, preg_replace('/\s+/', ' ', $text) ];
    }

    //one message: its answer as an XPath; \RuntimeException on no answer, a refused sign-in, a fault (a Receive that timed out: null)
    protected function send($action, $body, $shellId = null, array $options = [], $wait = self::WAIT) {
	$left = $this->left();
	if ($left < 1)
	    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed");
	$url = self::endpoint($this->cred->serverUrl);
	$wait = max(1, min($wait, $left - 1));
	$r = Http::request('POST', $url, [ 'headers' => [ 'Content-Type' => 'application/soap+xml;charset=UTF-8' ], 'body' => self::envelope($url, $action, $body, $shellId, $options, $wait),
	    'timeout' => $left, 'maxBytes' => self::MAX_OUTPUT ] + $this->options());
	if ($r['error'] !== null)
	    throw new \RuntimeException('no answer from ' . Http::shown($url) . ": {$r['error']}");
	if ($r['status'] === 401)
	    throw new \RuntimeException('sign-in refused (HTTP status 401): check the user, its password and the authentication (' . ((string)$this->cred->authMethod === 'basic' ? 'Basic needs a local account and Basic allowed in WinRM' : 'NTLM') . ')');
	$xp = null;
	if ($r['body'] !== '') {
	    $doc = new \DOMDocument();
	    if (@$doc->loadXML($r['body'], LIBXML_NONET))
		$xp = new \DOMXPath($doc);
	}
	if ($r['status'] >= 400) {
	    [ $code, $text ] = $xp ? self::fault($xp) : [ '', '' ];
	    if ($action === 'receive' && $code === self::TIMED_OUT)
		return null;
	    throw new \RuntimeException("WinRM: HTTP status {$r['status']}" . ($text !== '' ? ": {$text}" : '') . ($r['status'] === 404 ? ' (is it the WinRM HTTPS listener, https://<host>:5986?)' : ''));
	}
	if (!$xp)
	    throw new \RuntimeException('WinRM: an answer that is not WS-Management (HTTP status ' . $r['status'] . ')');
	return $xp;
    }

    //a command run in a new shell: [ stdout, stderr, exit code ]
    public function run($command, $arguments) {
	$xp = $this->send('create', '<rsp:Shell><rsp:InputStreams>stdin</rsp:InputStreams><rsp:OutputStreams>stdout stderr</rsp:OutputStreams></rsp:Shell>', null,
	    [ 'WINRS_NOPROFILE' => 'TRUE', 'WINRS_CODEPAGE' => '65001' ]);
	$shell = (string)$xp->evaluate('string(//*[local-name()="Selector"][@Name="ShellId"])');
	if ($shell === '')
	    $shell = (string)$xp->evaluate('string(//*[local-name()="ShellId"])');
	if ($shell === '')
	    throw new \RuntimeException('WinRM: no shell in the answer');
	try {
	    $xp = $this->send('command', '<rsp:CommandLine><rsp:Command>' . self::x($command) . '</rsp:Command><rsp:Arguments>' . self::x($arguments) . '</rsp:Arguments></rsp:CommandLine>',
		$shell, [ 'WINRS_CONSOLEMODE_STDIN' => 'TRUE', 'WINRS_SKIP_CMD_SHELL' => 'TRUE' ]);
	    $id = (string)$xp->evaluate('string(//*[local-name()="CommandId"])');
	    if ($id === '')
		throw new \RuntimeException('WinRM: no command in the answer');
	    $out = $err = '';
	    $exit = null;
	    while(true) {
		$xp = $this->send('receive', '<rsp:Receive><rsp:DesiredStream CommandId="' . self::x($id) . '">stdout stderr</rsp:DesiredStream></rsp:Receive>', $shell);
		if ($xp !== null) {
		    foreach($xp->query('//*[local-name()="Stream"]') as $s) {
			$chunk = base64_decode(trim($s->textContent), true);
			if ($chunk === false)
			    continue;
			if ($s->getAttribute('Name') === 'stderr')
			    $err .= $chunk;
			else
			    $out .= $chunk;
			if (strlen($out) + strlen($err) > self::MAX_OUTPUT)
			    throw new \RuntimeException('the output is larger than ' . (self::MAX_OUTPUT >> 20) . ' MB');
		    }
		    $state = $xp->query('//*[local-name()="CommandState"]')->item(0);
		    if ($state && $state->getAttribute('State') === self::DONE) {
			$exit = (int)$xp->evaluate('string(//*[local-name()="ExitCode"])');
			break;
		    }
		}
		if ($this->left() < 2)
		    throw new \RuntimeException("the step's timeout ({$this->timeout} s) passed while the command was running");
	    }
	    try {
		$this->send('signal', '<rsp:Signal CommandId="' . self::x($id) . '"><rsp:Code>' . self::TERMINATE . '</rsp:Code></rsp:Signal>', $shell, [], 5);
	    } catch (\RuntimeException $e) {
		//ended already
	    }
	    return [ $out, $err, $exit ];
	} finally {
	    try {
		//the shell removed, even after an error or the timeout (a few seconds more)
		$this->timeout += 10;
		$this->send('delete', '', $shell, [], 5);
	    } catch (\RuntimeException $e) {
		//WinRM removes idle shells itself
	    } finally {
		$this->timeout -= 10;
	    }
	}
    }

    //a PowerShell string literal of a text (single quotes: nothing in it is run)
    public static function quote($text) {
	return "'" . str_replace([ "'", "\u{2018}", "\u{2019}", "\u{201A}", "\u{201B}" ], [ "''", "\u{2018}\u{2018}", "\u{2019}\u{2019}", "\u{201A}\u{201A}", "\u{201B}\u{201B}" ], (string)$text) . "'";
    }

    //the full script of ps(): the wrapper around $script
    public static function wrap($name, $script, array $params = []) {
	$json = json_encode((object)$params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	return "# r22:{$name}\n"
	    . "\$ProgressPreference = 'SilentlyContinue'; \$ErrorActionPreference = 'Stop'; \$WarningPreference = 'SilentlyContinue'\n"
	    . "[Console]::OutputEncoding = [System.Text.Encoding]::UTF8\n"
	    . '$P = ConvertFrom-Json ' . self::quote($json) . "\n"
	    . "try {\n\$r22 = & {\n" . trim($script) . "\n}\n\$r22out = @{ ok = \$true; data = \$r22 }\n"
	    . "} catch {\n\$r22out = @{ ok = \$false; error = \$_.Exception.Message }\n}\n"
	    . "[Console]::Out.Write((ConvertTo-Json -InputObject \$r22out -Depth 8 -Compress))\n";
    }

    //the messages of PowerShell's error stream (CLIXML) as text
    public static function errorText($stderr) {
	$stderr = (string)$stderr;
	if (!str_contains($stderr, '<Objs'))
	    return trim($stderr);
	$lines = [];
	if (preg_match_all('~<S S="Error">(.*?)</S>~s', $stderr, $m))
	    foreach($m[1] as $l)
		$lines[] = html_entity_decode(preg_replace_callback('/_x([0-9A-Fa-f]{4})_/', fn($x) => mb_chr(hexdec($x[1]), 'UTF-8'), $l), ENT_XML1 | ENT_QUOTES, 'UTF-8');
	return trim(preg_replace('/\s+/', ' ', implode(' ', $lines)));
    }

    //a PowerShell script run: its value; \RuntimeException with PowerShell's message
    public function ps($name, $script, array $params = []) {
	$full = self::wrap($name, $script, $params);
	if (mb_strlen($full) > self::MAX_SCRIPT)
	    throw new \RuntimeException('the PowerShell script is too long (' . mb_strlen($full) . ' characters; at most ' . self::MAX_SCRIPT . ')');
	$encoded = base64_encode(mb_convert_encoding($full, 'UTF-16LE', 'UTF-8'));
	[ $out, $err, $exit ] = $this->run('powershell.exe', "-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand {$encoded}");
	$out = preg_replace('/^\xEF\xBB\xBF/', '', trim($out));
	$d = $out !== '' ? json_decode($out, true) : null;
	if (!is_array($d) || !array_key_exists('ok', $d)) {
	    $text = self::errorText($err);
	    throw new \RuntimeException('PowerShell: ' . ($text !== '' ? mb_substr($text, 0, 500) : ($out !== '' ? 'an answer that is not JSON: ' . mb_substr($out, 0, 200) : "no answer (exit code {$exit})")));
	}
	if (!$d['ok'])
	    throw new \RuntimeException(mb_substr(trim(preg_replace('/\s+/', ' ', (string)($d['error'] ?? 'failed'))), 0, 500));
	return $d['data'] ?? null;
    }
}
