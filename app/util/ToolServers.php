<?php

/*
    CLD: 2026-11-01T11:55:18+02:00: new: the installation's tool servers (MCP servers, Streamable HTTP, usually on this machine), offered to the AI steps (Tool servers) of the accounts that
    may use them; core (was the LocalMCP plugin: plugins\LocalMCP\Plugin::servers, its servers.php and its hook ai:mcp-servers).
    The servers are in mcp.json, next to app.env (not in git; the app's user's, 0640: headers may hold tokens), as other MCP clients write them:
	{ "mcpServers": { "<id>": {
	    "type": "http",                         the only one (a server the app would start itself, "command", is not supported)
	    "url": "http://localhost:8931/mcp",     the server's MCP endpoint
	    "headers": { "Authorization": "..." },  sent with each request
	    "label": "Web browser", "description": "...",   shown in the AI step (Tool servers)
	    "tools": [ "browser_navigate", ... ],   the tools the model may use (the server may list more; null or missing: all of them)
	    "maxSessions": 4,                       most sessions at once on the whole installation (each run of an AI step opens one)
	    "maxSessionsPerAccount": 2,             most at once per account
	    "service": "playwright",                its systemd unit, nfn-<service>-<the app's user>.service: on the Services page (sys\Boot, hook app:services)
	    "managedBy": "scripts/playwright-mcp.sh"   written by an installer (e.g. sudo scripts/playwright-mcp.sh install): shown, not edited or removed in the app
	} } }
    Written only through put() / remove(): Settings > Tool servers (controllers\ToolserversController: the account's owners, or the MultiTenant plugin's super-admins), and
    ./v mcpserver::add / remove (the installers)
    Test (test()): the server answers, its tools; a web browser (a browser_navigate tool) also opens a public page and must not open a private address
    The plugins may add servers or change them (hook toolservers:list ($servers: \ArrayObject, id => definition); `test`: fn(\util\McpClient $c, array $tools) => [ [ text, ok ] ],
    more checks run by Test)
    Which accounts may use them: models\ToolServerAccess (Settings > Tool servers, the MultiTenant plugin's account page, ./v mcpserver::enable); the sessions open at once:
    models\ToolServerSlot (each server's limits, per account and in all), held by util\ToolServerSession
*/

namespace util;

class ToolServers {
    const ID = '/^[a-z][a-z0-9_]{0,30}$/';
    const MAX_SESSIONS = 4;
    const MAX_SESSIONS_PER_ACCOUNT = 2;

    //the file (another one for the check suite)
    public static $file = null;
    //the file's servers as last read, and the file's state then (mtime, size)
    private static $cache = null;
    private static $cacheKey = null;

    public static function file() {
	//CLD: 2026-11-01T19:00:00+02:00: in V::configDir() (NFN_CONFIG_DIR, else BASE; was BASE)
	return self::$file ?? \V::configDir() . '/mcp.json';
    }

    //the servers: [ id => definition: label, description, url, headers, tools (null: all), max_sessions, max_sessions_per_account, service, managed_by ]; the file is read again
    //when it changed (the daemon runs for days), the plugins' additions at each call
    public static function all() {
	$servers = new \ArrayObject(self::fromFile());
	\V::app()->hooks->applyHook('toolservers:list', [ $servers ]);
	return array_filter($servers->getArrayCopy(), fn($s, $id) => is_array($s) && preg_match(self::ID, (string)$id) && !empty($s['url']), ARRAY_FILTER_USE_BOTH);
    }

    //server $id's definition, or null
    public static function get($id) {
	return self::all()[(string)$id] ?? null;
    }

    //the servers account $aid may use, for the AI steps (models\node\NodeAppAi::localServers): [ id => [ id, label, description, tools, open: fn(int $timeout) => a session ] ]
    public static function ofAccount($aid) {
	if (empty($aid))
	    return [];
	$enabled = \models\ToolServerAccess::serversOf($aid);
	$r = [];
	foreach(self::all() as $id => $s) {
	    if (!in_array($id, $enabled, true))
		continue;
	    $r[$id] = [
		'id' => $id,
		'label' => (string)$s['label'],
		'description' => (string)($s['description'] ?? ''),
		'tools' => $s['tools'],
		'open' => fn($timeout) => ToolServerSession::open($id, $s, $aid, (int)$timeout),
	    ];
	}
	return $r;
    }

    //why the file cannot be used (not JSON, no mcpServers object), or null
    public static function fileError() {
	$f = self::file();
	if (!is_file($f))
	    return null;
	$d = json_decode((string)@file_get_contents($f), true);
	return is_array($d) && is_array($d['mcpServers'] ?? []) ? null : basename($f) . ' is not valid: ' . (json_last_error() ? json_last_error_msg() : 'no "mcpServers" object');
    }

    //checks definition $in (as in the file, or the form's: tools / headers as lines); [ the file's entry, null ] or [ null, the problem ]
    public static function validate($id, array $in) {
	if (!preg_match(self::ID, (string)$id))
	    return [ null, 'Id: a lowercase letter, then lowercase letters, digits or _ (at most 31)' ];
	if (isset($in['command']) || (isset($in['type']) && !in_array((string)$in['type'], [ 'http', 'streamable-http' ], true)))
	    return [ null, 'Only HTTP servers (Streamable HTTP: "type": "http", a url) are supported, not those started by a command' ];
	$url = trim((string)($in['url'] ?? ''));
	if (!preg_match('#^https?://[^\s/]+#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false)
	    return [ null, 'URL: the server\'s MCP endpoint (http://... or https://...)' ];
	$label = trim((string)($in['label'] ?? ''));
	if ($label === '' || mb_strlen($label) > 80)
	    return [ null, 'Label: up to 80 characters' ];
	$headers = $in['headers'] ?? [];
	if (is_string($headers)) {
	    $lines = $headers;
	    $headers = [];
	    foreach(preg_split('/\R/', $lines) as $line) {
		if (trim($line) === '')
		    continue;
		if (!preg_match('/^\s*([A-Za-z0-9-]{1,64})\s*:\s*(.*?)\s*$/', $line, $m))
		    return [ null, 'Headers: one per line, Name: value' ];
		$headers[$m[1]] = $m[2];
	    }
	}
	if (!is_array($headers))
	    return [ null, 'Headers: an object of names and values' ];
	foreach($headers as $k => $v)
	    if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', (string)$k) || !is_scalar($v) || preg_match('/[\r\n]/', (string)$v))
		return [ null, "Headers: `{$k}` is not a valid header" ];
	$tools = $in['tools'] ?? null;
	if (is_string($tools))
	    $tools = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $tools)), 'strlen'));
	if ($tools !== null) {
	    if (!is_array($tools))
		return [ null, 'Tools: a list of tool names' ];
	    foreach($tools as $t)
		if (!is_string($t) || !preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $t))
		    return [ null, 'Tools: names of letters, digits, _ . -' ];
	    $tools = $tools ? array_values(array_unique($tools)) : null;
	}
	$max = self::intIn($in, [ 'maxSessions', 'max_sessions' ], self::MAX_SESSIONS);
	$perAccount = self::intIn($in, [ 'maxSessionsPerAccount', 'max_sessions_per_account' ], self::MAX_SESSIONS_PER_ACCOUNT);
	if ($max < 1 || $max > 100 || $perAccount < 1 || $perAccount > $max)
	    return [ null, 'Sessions: at most 1 to 100 in all, 1 to that many per account' ];
	$entry = [ 'type' => 'http', 'url' => $url, 'headers' => array_map('strval', $headers), 'label' => $label, 'description' => trim((string)($in['description'] ?? '')),
	    'tools' => $tools, 'maxSessions' => $max, 'maxSessionsPerAccount' => $perAccount ];
	$service = trim((string)($in['service'] ?? ''));
	if ($service !== '') {
	    if (!preg_match('/^[a-z][a-z0-9]{0,30}$/', $service))
		return [ null, 'Service: the name in its unit nfn-<service>-<user>.service (lowercase letters and digits)' ];
	    $entry['service'] = $service;
	}
	$by = trim((string)($in['managedBy'] ?? $in['managed_by'] ?? ''));
	if ($by !== '')
	    $entry['managedBy'] = mb_substr($by, 0, 80);
	return [ $entry, null ];
    }

    //writes server $id ($entry: validate()'s), adding or replacing it
    public static function put($id, array $entry) {
	self::change(function(array &$servers) use ($id, $entry) {
	    $servers[$id] = $entry;
	});
    }

    //removes server $id; whether it was there
    public static function remove($id) {
	$found = false;
	self::change(function(array &$servers) use ($id, &$found) {
	    $found = isset($servers[$id]);
	    unset($servers[$id]);
	});
	return $found;
    }

    //whether the signed-in user manages the tool servers (util\Services::allowed: with the MultiTenant plugin its super-admins, without it the account's owners)
    public static function allowed() {
	return Services::allowed();
    }

    //where they are managed: with the MultiTenant plugin its super-admins' page (/toolservers/index), without it a tab of the account's settings
    public static function pageUrl() {
	return Services::adminPlugin() ? \V::app()->createUrl('/toolservers/index') : \V::app()->createUrl('/account/settings', [ '__tab' => 'tools' ]);
    }

    //whether server $s answers: [ ok, text ]
    public static function ping(array $s, $timeout = 10) {
	$c = new McpClient((string)$s['url'], (array)($s['headers'] ?? []), $timeout);
	try {
	    $info = $c->initialize();
	    $c->close();
	    return [ true, trim(($info['serverInfo']['name'] ?? '?') . ' ' . ($info['serverInfo']['version'] ?? '')) ];
	} catch (\Exception $e) {
	    return [ false, $e->getMessage() ];
	}
    }

    //connects to server $s, lists its tools, opens pages when it is a web browser, runs its own checks (its `test`, from a plugin): [ ok, [ lines ], the server's tools ]
    public static function test(array $s, $timeout = 90) {
	$lines = [];
	$failed = 0;
	$c = new McpClient((string)$s['url'], (array)($s['headers'] ?? []), $timeout);
	try {
	    $info = $c->initialize();
	    $tools = array_column($c->listTools(), 'name');
	    $offered = $s['tools'] ?? $tools;
	    $lines[] = trim(($info['serverInfo']['name'] ?? '?') . ' ' . ($info['serverInfo']['version'] ?? '')) . ', ' . count($tools) . ' tools, ' . count(array_intersect($tools, $offered)) . ' offered';
	    foreach(array_diff($offered, $tools) as $t) {
		$lines[] = "{$t}: offered, but the server does not have it";
		$failed++;
	    }
	    if (in_array('browser_navigate', $tools, true)) {
		foreach(self::browserTest($c) as [ $text, $ok ]) {
		    $lines[] = $text;
		    $failed += (int)!$ok;
		}
	    }
	    if (isset($s['test']) && is_callable($s['test'])) {
		foreach(($s['test'])($c, $tools) as [ $text, $ok ]) {
		    $lines[] = $text;
		    $failed += (int)!$ok;
		}
	    }
	} catch (\Exception $e) {
	    $lines[] = 'FAILED: ' . $e->getMessage();
	    $failed++;
	} finally {
	    try {
		$c->close();
	    } catch (\Exception $e) {
	    }
	}
	return [ $failed === 0, $lines, $tools ?? [] ];
    }

    //CLD: 2026-11-01T11:55:18+02:00: a web browser's checks (was the LocalMCP plugin's ServersCommand::test): a public page opens (https://example.com), the cloud metadata address
    //does not (the browser service's firewall, scripts/playwright-mcp.sh): [ [ text, ok ] ]
    public static function browserTest(McpClient $c) {
	$r = $c->callTool('browser_navigate', [ 'url' => 'https://example.com' ]);
	$ok = empty($r['isError']) && str_contains(json_encode($r), 'Example Domain');
	$p = $c->callTool('browser_navigate', [ 'url' => 'http://169.254.169.254/' ]);
	$blocked = !empty($p['isError']) || !str_contains(json_encode($p), 'HTTP status: 200');
	return [
	    [ 'open https://example.com: ' . ($ok ? 'OK' : 'FAILED ' . mb_substr(json_encode($r), 0, 300)), $ok ],
	    [ 'open http://169.254.169.254 (private, must fail): ' . ($blocked ? 'blocked' : 'NOT BLOCKED: is the firewall loaded (scripts/playwright-mcp.sh status)?'), $blocked ],
	];
    }

    // ------------------------------------------------------------------------------------

    //the file's servers, as definitions (read again when the file changed; an invalid file: none, logged)
    private static function fromFile() {
	$f = self::file();
	clearstatcache(true, $f);
	$key = is_file($f) ? $f . ':' . filemtime($f) . ':' . filesize($f) : $f . ':none';
	if (self::$cache !== null && self::$cacheKey === $key)
	    return self::$cache;
	$servers = [];
	if (is_file($f)) {
	    $d = json_decode((string)@file_get_contents($f), true);
	    if (!is_array($d) || !is_array($d['mcpServers'] ?? []))
		\V::app()->log->error('ToolServers: ' . (self::fileError() ?? "{$f}: not readable"));
	    else
		foreach((array)($d['mcpServers'] ?? []) as $id => $e) {
		    [ $entry, $problem ] = is_array($e) ? self::validate((string)$id, $e) : [ null, 'not an object' ];
		    if ($problem !== null) {
			\V::app()->log->error("ToolServers: {$f}: server `{$id}` left out: {$problem}");
			continue;
		    }
		    $servers[(string)$id] = [ 'label' => $entry['label'], 'description' => $entry['description'], 'url' => $entry['url'], 'headers' => $entry['headers'],
			'tools' => $entry['tools'], 'max_sessions' => $entry['maxSessions'], 'max_sessions_per_account' => $entry['maxSessionsPerAccount'],
			'service' => $entry['service'] ?? null, 'managed_by' => $entry['managedBy'] ?? null ];
		}
	}
	self::$cacheKey = $key;
	return self::$cache = $servers;
    }

    //changes the file's servers with $fn(array &$servers) (the file's entries), under a lock, written whole then moved over the old one; its other keys are kept
    private static function change(callable $fn) {
	$f = self::file();
	if (self::fileError() !== null)
	    throw new \ErrorException(self::fileError() . ': fix it by hand first');
	$lock = fopen($f . '.lock', 'c');
	if (!$lock || !flock($lock, LOCK_EX))
	    throw new \ErrorException('cannot lock ' . $f . '.lock');
	try {
	    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
	    $d = is_array($d) ? $d : [];
	    $servers = (array)($d['mcpServers'] ?? []);
	    $fn($servers);
	    //the headers an object also when there are none (as the other MCP clients' files)
	    foreach($servers as $id => $e)
		if (is_array($e) && is_array($e['headers'] ?? null))
		    $servers[$id]['headers'] = (object)$e['headers'];
	    $d['mcpServers'] = (object)$servers;
	    $tmp = $f . '.tmp' . getmypid();
	    if (file_put_contents($tmp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") === false)
		throw new \ErrorException('cannot write ' . $tmp);
	    chmod($tmp, 0640);
	    if (!rename($tmp, $f))
		throw new \ErrorException('cannot write ' . $f);
	    self::$cache = null;
	} finally {
	    flock($lock, LOCK_UN);
	    fclose($lock);
	}
    }

    private static function intIn(array $in, array $keys, $default) {
	foreach($keys as $k)
	    if (isset($in[$k]) && $in[$k] !== '')
		return is_numeric($in[$k]) ? (int)$in[$k] : -1;
	return $default;
    }
}
