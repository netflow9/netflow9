<?php
//CLD: 2026-10-14T16:01:34+03:00: util\FormSchema and WidgetsUtil::smartFormFields / formValues are util\SmartForm (::showIf, ::modelFields, ::modelValues, ...)

//CLD: 2026-10-13T13:49:22+03:00: new command: the checks of the workflow engine and its expressions, as assertions (were scratch scripts): each prints OK / FAIL, the exit code is
//the number of failures (0 = all passed). Workflows needed are created in a project, run, and deleted with their executions (through ObjectManager)
//  ../v check::run                        all suites except the slow one
//  ../v check::run --only=loop,if         some suites: j, expr, types, if, switch, loop, mcp, vars, code, forms, email, urls, steps (slow: big loops and endless cycles, ~1 min)
//CLD: 2026-10-21T10:00:00+03:00: + ldap (the Ldap plugin; its test LDAP server is started for the checks)
//CLD: 2026-10-23T15:00:00+03:00: + http
//  ../v check::run --project=<id>         where the workflows are created (default: the first project of the first account)
//  ../v check::run --verbose              also the passed checks

namespace commands;

class CheckCommand extends \sys\BaseController {
    //CLD: 2026-10-13T15:17:12+03:00: + mcp (the MCP tools that build workflows: util\McpTools, util\WorkflowSpec)
    //CLD: 2026-10-20T23:40:00+03:00: the MCP tools are the SysMCPTools plugin's (plugins\SysMCPTools\util\McpTools): mcp is skipped without it
    //CLD: 2026-10-13T15:24:28+03:00: + vars (the project variables in expressions)
    //CLD: 2026-10-13T15:34:38+03:00: + code (the Code step)
    //CLD: 2026-10-13T18:34:40+03:00: + forms (form designer schemas, Form trigger, Human task)
    //CLD: 2026-10-16T12:02:51+03:00: + email (models\EmailBlocks, util\EmailHtml, util\EmailSend::compose, the Send Email step)
    //CLD: 2026-10-17T20:40:00+03:00: + file (the File upload trigger)
    //CLD: 2026-10-17T21:00:54+03:00: + images (images for the AI steps)
    //CLD: 2026-10-18T12:00:00+03:00: + select (sys\fields\Field_select accepts only values among its choices)
    //CLD: 2026-10-18T14:00:00+03:00: + json (util\JSON::json_decode, tolerant of people's typos)
    //CLD: 2026-10-18T09:42:21+03:00: + nested (sys\fields\Field_nested: cast by the nested fields, validated with the model)
    //CLD: 2026-10-18T23:35:30+03:00: + urls (V::createAbsoluteUrl: app.env BASE_URL, always https://; the links built with it)
    //CLD: 2026-10-19T15:26:28+03:00: + assistant (the designer's Assistant, util\WorkflowAssistant, with a scripted model: no API call)
    //CLD: 2026-10-20T10:00:00+03:00: + gdrive (the Google Drive step)
    //CLD: 2026-10-20T11:00:00+03:00: + gsheets (the Google Sheets step)
    //CLD: 2026-10-20T12:00:00+03:00: + gmail (the Gmail step)
    //CLD: 2026-10-20T13:00:00+03:00: + gcalendar (the Google Calendar step)
    //CLD: 2026-10-20T15:00:00+03:00: + gdocs (the Google Docs step)
    //CLD: 2026-10-20T16:00:00+03:00: + ftp (the FTP / SFTP step, util\FtpClient, util\FileDecrypt; real servers with CHECK_FTP_SERVERS: see suiteFtp)
    //CLD: 2026-10-21T10:00:00+03:00: + ldap (the Ldap plugin: its clients against a test server, the LDAP sign-in, the LDAP step; skipped without the plugin)
    //CLD: 2026-10-23T12:00:00+03:00: + saml (the Saml plugin: the SP's request and metadata, the responses checked and refused, the SAML sign-in; skipped without the plugin)
    //CLD: 2026-10-21T20:10:00+03:00: + tenant (the account guard of sys\ObjectManager; the sign-in of a name used in two accounts)
    //CLD: 2026-10-22T00:41:28+03:00: + microsoft (the MicrosoftIntegration plugin: its credentials and steps; skipped without the plugin)
    //CLD: 2026-10-22T01:36:46+03:00: + localai (the Lemonade AI and Ollama steps and credentials, against a fake server: commands/tests/AiTestServer.php)
    //CLD: 2026-10-23T10:00:00+03:00: + systemai (the AI steps on the System AI: plugins\SystemAI, its credits)
    //CLD: 2026-10-23T15:00:00+03:00: + http (util\Http against a local server; the HTTP steps' private-address option)
    //CLD: 2026-10-24T09:45:36+03:00: + tiers (util\Tiers: the tier, the account's own changes, the limits of data tables / rows / files refused at the limit, SAML / LDAP off without the
    //feature, the System AI allowance of the tier, the usage)
    //CLD: 2026-10-24T10:28:12+03:00: + Google / Microsoft by tier
    const SUITES = [ 'j', 'expr', 'types', 'if', 'switch', 'loop', 'mcp', 'vars', 'code', 'forms', 'email', 'file', 'images', 'invite', 'urls', 'select', 'json', 'nested', 'assistant', 'gdrive', 'gsheets', 'gmail', 'gcalendar', 'gdocs', 'microsoft', 'localai', 'systemai', 'localmcp', 'http', 'suspend', 'markdown', 'ftp', 'ldap', 'saml', 'tenant', 'audit', 'signin', 'tiers', 'steps' ];
    //not run unless named in --only
    const SLOW = [ 'steps' ];

    private $passed = 0;
    private $failed = 0;
    private $verbose = false;
    private $account = null;
    private $project = null;
    //workflows created (deleted at the end)
    private $created = [];

    public function run($req = null, $resp = null) {
	$params = is_array($req->params ?? null) ? $req->params : [];
	$this->verbose = isset($params['verbose']);
	$only = isset($params['only']) ? array_filter(array_map('trim', explode(',', (string)$params['only']))) : array_diff(self::SUITES, self::SLOW);
	foreach($only as $s) {
	    if (!in_array($s, self::SUITES, true)) {
		echo "unknown suite `{$s}` (suites: " . implode(', ', self::SUITES) . ")\n";
		exit(1);
	    }
	}

	$this->project = isset($params['project']) ? \V::app()->om->findById('Project', (string)$params['project'])
	    : \V::app()->om->findOne('Project', [ 'aid' => \V::app()->om->findOne('Account', [])->_id ?? null ]);
	if (empty($this->project)) {
	    echo "no project to create the workflows in (--project=<id>)\n";
	    exit(1);
	}
	$this->account = \V::app()->om->findById('Account', $this->project->aid);
	\V::app()->var('rt.account', $this->account);

	try {
	    foreach($only as $suite) {
		echo "== {$suite}\n";
		try {
		    $this->{'suite' . ucfirst($suite)}();
		} catch (\Throwable $e) {
		    $this->fail("{$suite}: stopped by " . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
		}
	    }
	} finally {
	    foreach($this->created as $id) {
		foreach(\V::app()->om->find('WfExecution', [ 'wf' => $id ]) as $e)
		    $e->delete();
		$wf = \V::app()->om->findById('Workflow', (string)$id);
		if ($wf)
		    $wf->delete();
	    }
	}

	echo "\n" . ($this->failed ? "{$this->failed} of " . ($this->passed + $this->failed) . ' checks failed' : "all {$this->passed} checks passed") . "\n";
	exit(min($this->failed, 255));
    }

    // ------------------------------------------------------------------------------------
    //helpers

    //CLD: 2026-10-20T23:40:00+03:00: the class of the MCP tools when the SysMCPTools plugin is enabled (app/plugins.php), else null
    private static function mcpTools() {
	return in_array('SysMCPTools', (array)\V::app()->config('modules'), true) ? \plugins\SysMCPTools\util\McpTools::class : null;
    }

    private function check($name, $expected, $actual) {
	if ($expected === $actual) {
	    $this->passed++;
	    if ($this->verbose)
		echo "  OK    {$name}\n";
	    return true;
	}
	$this->fail($name . "\n        expected: " . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n        actual:   " . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	return false;
    }

    private function fail($message) {
	$this->failed++;
	echo "  FAIL  {$message}\n";
    }

    private static function node($id, $fq, $config = [], $parent = null) {
	return [ 'wfid' => $id, 'isNode' => true, 'parentId' => $parent, 'fqname' => $fq, 'title' => $id, 'geometry' => [ 'x' => 0, 'y' => 0, 'w' => 120, 'h' => 120 ], 'config' => $config ];
    }

    private static function edge($id, $from, $to, $port = 0) {
	return [ 'wfid' => $id, 'isEdge' => true, 'fromNode' => $from, 'fromPort' => $port, 'toNode' => $to, 'toPort' => 0, 'branch' => null ];
    }

    //a workflow of $elements (not saved)
    private function workflow($name, array $elements) {
	$map = [];
	foreach($elements as $e)
	    $map[$e['wfid']] = $e;
	return \V::app()->om->create('Workflow', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: ' . $name, 'nodes' => $map ]);
    }

    //saves and runs a workflow of $elements with first variables $vars: the execution; null when the workflow is not valid (a failed check)
    private function runFlow($name, array $elements, array $vars = []) {
	$wf = $this->workflow($name, $elements);
	$valid = $wf->validates();
	$flow = array_column(array_filter($wf->validateFlow(), fn($i) => $i['level'] === 'error'), 'message');
	if (!$valid || $flow) {
	    $this->fail("{$name}: the workflow is not valid: " . json_encode([ $wf->errors(), $flow ], JSON_UNESCAPED_UNICODE));
	    return null;
	}
	$wf->save();
	$this->created[] = $wf->_id;
	$exe = \models\WfExecution::startFor($wf, 'console', [ 'vars' => $vars ]);
	$exe->run();
	return $exe;
    }

    //the validation errors of a workflow of $elements (not saved): [] when valid
    private function errorsOf($name, array $elements) {
	$wf = $this->workflow($name, $elements);
	return $wf->validates() ? [] : array_values((array)($wf->errors()['nodes'] ?? $wf->errors()));
    }

    //the last log line of $exe starting with $prefix
    private static function logLine($exe, $prefix) {
	$line = null;
	foreach((array)$exe?->log as $l) {
	    if (str_starts_with((string)$l['message'], $prefix))
		$line = (string)$l['message'];
	}
	return $line;
    }

    // ------------------------------------------------------------------------------------
    //suites

    //J's own smoke tests (sys/J/tests/smoke.php: parser, interpreter, parse modes, templates)
    private function suiteJ() {
	$out = [];
	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/sys/J/tests/smoke.php') . ' 2>&1', $out, $code);
	foreach($out as $line) {
	    if (str_starts_with($line, 'FAIL') || str_starts_with($line, '  '))
		echo "  {$line}\n";
	}
	$this->check('J smoke tests: ' . end($out), 0, $code);
    }

    //util\Expression: check / complete / render in the syntaxes text, reference, value
    private function suiteExpr() {
	$E = \util\Expression::class;
	$vars = [ 'mail' => [ 'type' => 'object', 'fields' => [ 'subject' => [ 'type' => 'string' ], 'from' => [ 'type' => 'string' ], 'to' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ] ] ],
	    'n' => [ 'type' => 'number' ], 'name' => [ 'type' => 'string' ] ];
	$messages = fn($text, $syntax = 'text') => array_map(fn($p) => $p['severity'] . ': ' . $p['message'], $E::check($text, $vars, $syntax));

	$this->check('reference: a field', [], $messages('{{ mail.subject }}', 'reference'));
	$this->check('reference: plain text', [ 'error: Write the variable between {{ and }}, e.g. {{ mail.subject }}' ], $messages('mail.subject', 'reference'));
	$this->check('reference: text around', [ 'error: Only one {{ }} and nothing around it is allowed here, e.g. {{ mail.subject }}' ], $messages('Re: {{ mail.subject }}', 'reference'));
	$this->check('reference: a call', [ 'error: ' . \sys\J\Parser::REFERENCE_ONLY ], $messages('{{ mail.subject.trim() }}', 'reference'));
	$this->check('reference: unknown variable', [ 'warning: Unknown variable `foo`' ], $messages('{{ foo.bar }}', 'reference'));
	$this->check('value: plain text', [ 'error: Write the expression between {{ and }}, e.g. {{ mail.attachments }}' ], $messages('mail.to', 'value'));
	$this->check('value: an expression', [], $messages('{{ mail.to.filter(t => t.endsWith(".ro")) }}', 'value'));
	$this->check('text: parts', [], $messages('INVOICE-{{ mail.subject }}'));
	$this->check('text: a statement', [ 'error: ' . \sys\J\Parser::EXPRESSION_ONLY ], $messages('{{ x }} and {{ let a = 1 }}'));
	$this->check('text: unclosed', [ 'error: `{{` is not closed with `}}`' ], $messages('a {{ b'));
	$this->check('text: }} in a string', [], $messages('{{ "}}" + name }}'));
	$this->check('text: unknown field', [ 'warning: `subjct` is not a field of `mail`' ], $messages('{{ mail.subjct }}'));
	$this->check('text: problem position in characters', 7, $E::check('ăîș {{ nope }}', $vars)[0]['start'] ?? null);

	$labels = fn($before, $syntax = 'text') => array_column($E::complete($before, $before, $vars, $syntax)['items'], 'label');
	$this->check('complete: variables (reference)', [ 'mail' ], $labels('{{ ma', 'reference'));
	$this->check('complete: fields', [ 'from', 'subject', 'to' ], $labels('{{ mail.'));
	$this->check('complete: after a name, nothing (reference)', [], $labels('{{ mail ', 'reference'));
	$this->check('complete: text methods', [ 'toLocaleLowerCase', 'toLocaleUpperCase', 'toLowerCase', 'toString', 'toUpperCase' ], $labels('{{ name.to'));
	$this->check('complete: no methods (reference)', [], $labels('{{ name.to', 'reference'));
	$this->check('complete: inside a string', [], $labels('x {{ "a'));

	$data = [ 'mail' => [ 'subject' => 'Invoice 42', 'to' => [ 'a', 'b' ], 'date' => '2026-10-13' ], 'n' => 5 ];
	$this->check('render: reference keeps the type', [ 'a', 'b' ], $E::render(' {{ mail.to }} ', $data, 'reference'));
	$this->check('render: index', 'b', $E::render('{{ mail.to[1] }}', $data, 'reference'));
	$this->check('render: text', 'INVOICE-2026-10-13', $E::render('INVOICE-{{ mail.date }}', $data));
	$this->check('render: number', 10, $E::render('{{ n * 2 }}', $data));
	$this->check('render: lists as JSON in text', 'n=5 to=["a","b"]', $E::render('n={{ n }} to={{ mail.to }}', $data));
	$this->check('render: no braces', 'plain', $E::render('plain', $data));
	foreach([ [ '{{ missing }}', 'text', 'ReferenceError' ], [ '{{ n + }}', 'text', 'Expression expected' ], [ 'mail.subject', 'reference', 'Write the variable' ] ] as [ $text, $syntax, $expect ]) {
	    try {
		$E::render($text, $data, $syntax);
		$this->fail("render {$text}: no error");
	    } catch (\ErrorException $e) {
		$this->check("render {$text}: error", true, str_contains($e->getMessage(), $expect));
	    }
	}

	//CLD: 2026-10-20T21:40:00+03:00: Mongo dates are J Dates; what an expression gives back is ISO 8601 text in local time (as before); the methods offered come from J
	$d = new \MongoDB\BSON\UTCDateTime(1792469103000);
	$atom = $d->toDateTime()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format(DATE_ATOM);
	$this->check('date: a Date in J', [ 2026, true ], [ $E::render('{{ d.getFullYear() }}', [ 'd' => $d ]), $E::render('{{ d > Date(0) }}', [ 'd' => $d ]) ]);
	$this->check('date: given back as ISO 8601 local text', [ $atom, "at {$atom}", [ $atom ] ], [ $E::render('{{ d }}', [ 'd' => $d ]), $E::render('at {{ d }}', [ 'd' => $d ]), $E::render('{{ [d] }}', [ 'd' => $d ]) ]);
	$this->check('date: hostValue without dates is text', $atom, $E::hostValue($d));
	$dateLabels = array_column($E::complete('{{ d.', '{{ d.', [ 'd' => [ 'type' => 'date' ] ])['items'], 'label');
	$this->check('complete: date methods (from J)', [ true, false ], [ in_array('getFullYear', $dateLabels, true), in_array('length', $dateLabels, true) ]);
	$this->check('complete: text methods are J\'s', \sys\J\Builtins::METHODS['string'], array_values(array_filter(array_column($E::complete('{{ name.', '{{ name.', $vars)['items'], 'label'), fn($l) => $l !== 'length')));
    }

    //variable types: the steps' declared fields, Set / Loop inference, suggestions along paths
    private function suiteTypes() {
	//CLD: 2026-10-19T21:10:00+03:00: built on the Email (inbound) step's message: only with the InboundMail plugin active (Haraka found)
	if (!isset(\models\WfNode::getAll()['trigger::email'])) {
	    echo "  (skipped: the Email (inbound) step needs the InboundMail plugin, active when Haraka is found)\n";
	    return;
	}
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	//CLD: 2026-10-17T15:35:00+03:00: trigger::email (was trigger::inbound)
	//CLD: 2026-10-17T14:20:00+03:00: the message object comes from the Email inbound trigger (the IMAP trigger is the Read emails step, a list)
	$nodes = [ $n('t', 'trigger::email', [ 'variable' => 'mail' ]), $n('s1', 'app::set', [ 'variable' => 'atts', 'value' => '{{ mail.attachments }}' ]),
	    $n('s2', 'app::set', [ 'variable' => 'first', 'value' => '{{ atts[0] }}' ]), $n('s3', 'app::set', [ 'variable' => 'label', 'value' => 'Hi {{ mail.subject }}' ]),
	    $n('s4', 'app::set', [ 'variable' => 'big', 'value' => '{{ mail.attachments.filter(a => a.size > 10) }}' ]), $n('s5', 'app::set', [ 'variable' => 'cnt', 'value' => '{{ 3 }}' ]),
	    $n('L', 'core::loop', [ 'list' => '{{ big }}', 'itemVariable' => 'att' ]), $n('in', 'app::set', [ 'variable' => 'q', 'value' => '' ], 'L'),
	    $n('i', 'core::if', []),
	    $e('e1', 't', 's1'), $e('e2', 's1', 's2'), $e('e3', 's2', 's3'), $e('e4', 's3', 's4'), $e('e5', 's4', 's5'), $e('e6', 's5', 'L'), $e('e7', 'L', 'i') ];
	$vars = \models\Workflow::variablesAt($nodes, 'i');
	$type = fn($name) => \util\Expression::typeLabel($vars[$name] ?? null);
	$attFields = [ 'filename', 'contentType', 'size', 'disposition', 'file' ];
	$this->check('mail is an object', 'object', $type('mail'));
	$this->check('Set {{ mail.attachments }}: list of attachments', [ 'array of object', $attFields ], [ $type('atts'), array_keys($vars['atts']['items']['fields'] ?? []) ]);
	$this->check('Set {{ atts[0] }}: an attachment', $attFields, array_keys($vars['first']['fields'] ?? []));
	$this->check('Set text: string', 'string', $type('label'));
	$this->check('Set filter(): the same list', 'array of object', $type('big'));
	$this->check('Set {{ 3 }}: number', 'number', $type('cnt'));
	$this->check('Loop result fields', [ 'count', 'results', 'errors' ], array_keys($vars['loop']['fields'] ?? []));
	$this->check('no item variable after the loop', false, isset($vars['att']));
	$inside = \models\Workflow::variablesAt($nodes, 'in');
	$this->check('Loop item: the list\'s item type', $attFields, array_keys($inside['att']['fields'] ?? []));
	$this->check('Loop position: number', 'number', $inside['index']['type'] ?? null);

	$labels = fn($before) => array_column(\util\Expression::complete($before, $before, $vars)['items'], 'label');
	$this->check('complete mail.attachments[0].', [ 'contentType', 'disposition', 'file', 'filename', 'size' ], $labels('{{ mail.attachments[0].'));
	$this->check("complete mail['attachments'][1].si", [ 'size', 'disposition' ], $labels("{{ mail['attachments'][1].si"));
	$this->check('complete cnt.', [ 'toFixed', 'toString', 'valueOf' ], $labels('{{ cnt.'));
	$this->check('complete mail.headers["x"]. (unknown)', [], $labels('{{ mail.headers["x"].'));
	$this->check('warning for a field of an attachment', [ '`nam` is not a field of `mail.attachments[0]`' ], array_column(\util\Expression::check('{{ mail.attachments[0].nam }}', $vars), 'message'));
	$this->check('no warning in free objects (headers)', [], \util\Expression::check('{{ mail.headers.anything }}', $vars));
    }

    //the If step: conditions (reference on the left, text on the right), all / any, lists
    private function suiteIf() {
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$mail = [ 'subject' => 'Invoice 42', 'from' => 'ana@qsol.ro', 'to' => [ 'bob@x.ro', 'carl@x.ro' ], 'attachment_count' => 2, 'cc' => [], 'headers' => [ 'x-mailer' => 'QM' ] ];
	$flow = fn($match, $conditions) => [ $n('m', 'trigger::manual'), $n('i', 'core::if', [ 'match' => $match, 'conditions' => $conditions ]),
	    $n('t', 'app::set', [ 'variable' => 'route', 'value' => 'true' ]), $n('f', 'app::set', [ 'variable' => 'route', 'value' => 'false' ]),
	    $e('e1', 'm', 'i'), $e('e2', 'i', 't', 0), $e('e3', 'i', 'f', 1) ];
	$c = fn($l, $op, $v = '') => [ 'left' => $l, 'operator' => $op, 'value' => $v ];
	foreach([
	    [ 'all hold', 'all', [ $c('{{ mail.subject }}', 'contains', 'Invoice'), $c('{{ mail.from }}', 'contains', '@qsol.ro') ], 'true' ],
	    [ 'all: one fails', 'all', [ $c('{{ mail.subject }}', 'contains', 'Invoice'), $c('{{ mail.from }}', 'eq', 'x@y') ], 'false' ],
	    [ 'any: numbers', 'any', [ $c('{{ mail.from }}', 'eq', 'x@y'), $c('{{ mail.attachment_count }}', 'gte', '2') ], 'true' ],
	    [ 'list contains / empty', 'all', [ $c('{{ mail.to }}', 'contains', 'carl@x.ro'), $c('{{ mail.cc }}', 'empty') ], 'true' ],
	    [ 'length vs expression value', 'all', [ $c('{{ mail.to.length }}', 'gt', '{{ mail.attachment_count - 1 }}') ], 'true' ],
	    [ 'header key, text value', 'all', [ $c('{{ mail.headers["x-mailer"] }}', 'eq', 'Q{{ "M" }}') ], 'true' ],
	    [ 'missing field is empty', 'all', [ $c('{{ mail.to }}', 'notEmpty'), $c('{{ mail.bcc }}', 'empty') ], 'true' ],
	] as [ $name, $match, $conditions, $want ]) {
	    $exe = $this->runFlow("if {$name}", $flow($match, $conditions), [ 'mail' => $mail ]);
	    $this->check("If: {$name}", $want, $exe?->getVariable('route'));
	}
	foreach([
	    [ 'no conditions', [], [ 'i: Conditions: add at least one' ] ],
	    [ 'plain left', [ $c('mail.subject', 'eq', 'x') ], [ 'i: Condition 1: Test: Write the variable between {{ and }}, e.g. {{ mail.subject }} (column 1)' ] ],
	    [ 'a call on the left', [ $c('{{ mail.subject.trim() }}', 'eq', 'x') ], [ 'i: Condition 1: Test: ' . \sys\J\Parser::REFERENCE_ONLY . ' (column 21)' ] ],
	    //CLD: 2026-10-18T11:05:00+03:00: a value not among the choices is dropped by the select field's cast (sys\fields\Field_select): the operator is then missing (was "Not one of the choices")
	    [ 'bad operator', [ $c('{{ mail.subject }}', 'zz', 'x') ], [ 'i: Condition 1: Operator: This field is required' ] ],
	    [ 'empty left', [ $c('', 'eq', 'x') ], [ 'i: Condition 1: Test: This field is required' ] ],
	] as [ $name, $conditions, $want ])
	    $this->check("If refused: {$name}", $want, $this->errorsOf("if {$name}", $flow('all', $conditions)));
	//CLD: 2026-10-18T10:20:00+03:00: the conditions are a nested field: the designer's editgrid made from conditionSchema(), the rows normalized on save, the n-th row named
	$grid = array_column(\util\SmartForm::modelFields(\models\node\NodeCoreIf::configForm()), null, 'key')['conditions'] ?? [];
	$this->check('If: the conditions editgrid (rows layout, the condition fields, 1 - 20 rows)', [ 'editgrid', 'rows', [ 'left', 'operator', 'value' ], 1, 20 ],
	    [ $grid['type'] ?? null, $grid['layout'] ?? null, array_column($grid['components'] ?? [], 'key'), $grid['minRows'] ?? null, $grid['maxRows'] ?? null ]);
	$this->check('If refused: the second condition is named', [ 'i: Condition 2: Test: This field is required' ],
	    $this->errorsOf('if second', $flow('all', [ $c('{{ mail.subject }}', 'eq', 'x'), $c('', 'eq', 'x') ])));
	$wf = $this->workflow('if normalized', $flow('all', [ $c('{{ mail.subject }}', 'eq', 'x') + [ '__readOnly' => true, 'junk' => 1 ] ]));
	$valid = $wf->validates();
	$rows = array_map(function($r) { ksort($r); return $r; }, (array)($wf->nodes['i']['config']['conditions'] ?? []));
	$this->check('If: a condition is normalized on save (unknown keys dropped)', [ true, [ [ 'left' => '{{ mail.subject }}', 'operator' => 'eq', 'value' => 'x' ] ] ], [ $valid, $rows ]);
	//a value set without a cast (null: an operator not among the choices) is not replaced by the field's default when validated
	//CLD: 2026-10-18T13:00:00+03:00: sys\NestedModel (was models\WfNodeConfig; option nestedSchema, was configSchema)
	$raw = \sys\NestedModel::create([ 'c' => [ [ 'left' => '{{ a }}', 'operator' => null ] ] ], [ 'objectName' => 'NestedModel', 'nestedSchema' => [
	    [ 'key' => 'c', 'type' => 'nested', 'multiple' => true, 'label' => 'C', 'fields' => \models\node\NodeCoreIf::conditionSchema() ] ] ]);
	$this->check('If: a null operator is refused (not validated as its default)', [ false, [ 'c.0.operator' => [ 'This field is required' ] ] ], [ $raw->validates(), $raw->errors() ]);
    }

    //the Switch step: a reference variable, first matching case
    private function suiteSwitch() {
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$cases = [ [ 'label' => 'invoice', 'operator' => 'contains', 'value' => 'Invoice' ], [ 'label' => 'bob', 'operator' => 'contains', 'value' => 'bob@x.ro' ],
	    [ 'label' => 'nocc', 'operator' => 'empty', 'value' => '' ] ];
	$flow = fn($variable) => [ $n('m', 'trigger::manual'), $n('w', 'core::switch', [ 'variable' => $variable, 'cases' => $cases ]),
	    $n('a', 'app::set', [ 'variable' => 'route', 'value' => 'invoice' ]), $n('b', 'app::set', [ 'variable' => 'route', 'value' => 'bob' ]),
	    $n('c', 'app::set', [ 'variable' => 'route', 'value' => 'nocc' ]), $n('o', 'app::set', [ 'variable' => 'route', 'value' => 'otherwise' ]),
	    $e('e1', 'm', 'w'), $e('e2', 'w', 'a', 0), $e('e3', 'w', 'b', 1), $e('e4', 'w', 'c', 2), $e('e5', 'w', 'o', 3) ];
	$mail = [ 'subject' => 'Invoice 42', 'from' => 'ana@qsol.ro', 'to' => [ 'bob@x.ro' ], 'cc' => [] ];
	foreach([ [ '{{ mail.subject }}', 'invoice' ], [ '{{ mail.to }}', 'bob' ], [ '{{ mail.cc }}', 'nocc' ], [ '{{ mail.from }}', 'otherwise' ], [ ' {{mail["subject"]}} ', 'invoice' ] ] as [ $variable, $want ]) {
	    $exe = $this->runFlow("switch {$variable}", $flow($variable), [ 'mail' => $mail ]);
	    $this->check("Switch {$variable}", $want, $exe?->getVariable('route'));
	}
	foreach([ 'mail' => 'Write the variable between', 'Re {{ mail.subject }}' => 'Only one {{ }}', '{{ mail.subject.trim() }}' => 'Only a variable', '' => 'This field is required' ] as $variable => $want) {
	    $errors = $this->errorsOf('switch', $flow($variable));
	    $this->check("Switch refused: " . json_encode($variable), true, count($errors) == 1 && str_contains($errors[0], $want));
	}

	//CLD: 2026-10-18T10:45:00+03:00: the cases are a nested field: the editgrid made from caseSchema(), labels trimmed, errors of the n-th case, labels used twice
	$grid = array_column(\util\SmartForm::modelFields(\models\node\NodeCoreSwitch::configForm()), null, 'key')['cases'] ?? [];
	$this->check('Switch: the cases editgrid (the case fields, 1 - 20 rows)', [ 'editgrid', [ 'label', 'operator', 'value' ], 1, 20 ],
	    [ $grid['type'] ?? null, array_column($grid['components'] ?? [], 'key'), $grid['minRows'] ?? null, $grid['maxRows'] ?? null ]);
	$bad = [ $n('m', 'trigger::manual'), $n('w', 'core::switch', [ 'variable' => '{{ mail.subject }}', 'cases' => [ [ 'label' => ' a ', 'operator' => 'eq', 'value' => 'x', 'junk' => 1 ],
	    [ 'label' => 'A', 'operator' => 'eq', 'value' => 'y' ], [ 'label' => 'bad!', 'operator' => 'eq', 'value' => 'z' ] ] ]), $e('e1', 'm', 'w') ];
	$this->check('Switch refused: a bad label (named by its case), a label used twice', [ 'w: Case 3: Output: Up to 30 letters, digits, spaces or _ . -', 'w: Case 2: the output `A` is used twice (`otherwise` is the last output)' ],
	    array_values(array_filter($this->errorsOf('switch bad', $bad), fn($m) => str_starts_with($m, 'w: Case'))));
	$wf = $this->workflow('switch normalized', $bad);
	$wf->validates();
	$this->check('Switch: a case normalized (label trimmed, unknown keys dropped)', [ 'label' => 'a', 'operator' => 'eq', 'value' => 'x' ],
	    (function($r) { ksort($r); return $r; })((array)($wf->nodes['w']['config']['cases'][0] ?? [])));
    }

    //the Loop step: passes, nesting, Collect, skip / stop, If + Merge inside, Wait inside, list errors, settings
    private function suiteLoop() {
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$var = fn($exe, $name) => $exe?->getVariable($name);

	$exe = $this->runFlow('loop simple', [ $n('m', 'trigger::manual'), $n('L', 'core::loop', [ 'list' => '{{ nums }}', 'collect' => '{{ item * 2 }}' ]),
	    $n('s', 'app::set', [ 'variable' => 'last', 'value' => '{{ index }}:{{ item }}' ], 'L'), $n('a', 'app::set', [ 'variable' => 'after', 'value' => '{{ loop.count }}' ]),
	    $e('e1', 'm', 'L'), $e('e2', 'L', 'a') ], [ 'nums' => [ 1, 2, 3 ] ]);
	$this->check('simple: result', [ 'count' => 3, 'results' => [ 2, 4, 6 ], 'errors' => [] ], $var($exe, 'loop'));
	$this->check('simple: last pass, after, item/index removed', [ '2:3', 3, false ], [ $var($exe, 'last'), $var($exe, 'after'), array_key_exists('item', (array)$exe?->variables) ]);

	//CLD: 2026-10-14T21:06:38+03:00: a loop inside another loop is refused (was run: nested loops)
	$nested = $this->workflow('loop nested', [ $n('m', 'trigger::manual'),
	    $n('O', 'core::loop', [ 'list' => '{{ rows }}', 'itemVariable' => 'row', 'indexVariable' => 'i', 'collect' => '{{ inner.results }}', 'variable' => 'outer' ]),
	    $n('I', 'core::loop', [ 'list' => '{{ row.cells }}', 'itemVariable' => 'cell', 'indexVariable' => 'j', 'collect' => '{{ i }}.{{ j }}={{ cell }}', 'variable' => 'inner' ], 'O'),
	    $n('x', 'app::set', [ 'variable' => 'seen', 'value' => '{{ cell }}' ], 'I'), $e('e1', 'm', 'O') ]);
	$this->check('nested: a loop inside a loop is an error', [ 'I: A loop cannot be inside another loop' ],
	    array_values(array_map(fn($i) => "{$i['node']}: {$i['message']}", array_filter($nested->validateFlow(), fn($i) => $i['level'] == 'error' && str_contains($i['message'], 'loop cannot')))));

	$errorFlow = fn($onError) => [ $n('m', 'trigger::manual'), $n('L', 'core::loop', [ 'list' => '{{ objs }}', 'collect' => '{{ v }}', 'onError' => $onError ]),
	    $n('s', 'app::set', [ 'variable' => 'v', 'value' => '{{ item.a.b }}' ], 'L'), $n('t', 'app::set', [ 'variable' => 'w', 'value' => 'x' ], 'L'), $e('ei', 's', 't'), $e('e1', 'm', 'L') ];
	$objs = [ 'objs' => [ [ 'a' => [ 'b' => 1 ] ], [ 'a' => null ], [ 'a' => [ 'b' => 3 ] ] ] ];
	$exe = $this->runFlow('loop skip', $errorFlow('skip'), $objs);
	$loop = $var($exe, 'loop');
	$this->check('skip: results and the skipped item', [ 'end', [ 1, 3 ], 1, 's' ], [ $exe?->state, $loop['results'] ?? null, $loop['errors'][0]['index'] ?? null, $loop['errors'][0]['step'] ?? null ]);
	$exe = $this->runFlow('loop stop', $errorFlow('stop'), $objs);
	$this->check('stop: the execution fails', [ 'error', true ], [ $exe?->state, str_contains((string)$exe?->error, "reading 'b'") ]);

	$exe = $this->runFlow('loop if merge', [ $n('m', 'trigger::manual'), $n('L', 'core::loop', [ 'list' => '{{ nums }}', 'collect' => '{{ kind }}' ]),
	    $n('i', 'core::if', [ 'match' => 'all', 'conditions' => [ [ 'left' => '{{ item }}', 'operator' => 'gt', 'value' => '1' ] ] ], 'L'),
	    $n('big', 'app::set', [ 'variable' => 'kind', 'value' => 'big' ], 'L'), $n('small', 'app::set', [ 'variable' => 'kind', 'value' => 'small' ], 'L'),
	    $n('mg', 'core::merge', [], 'L'), $n('z', 'app::set', [ 'variable' => 'z', 'value' => '{{ kind }}!' ], 'L'),
	    $e('a', 'i', 'big', 0), $e('b', 'i', 'small', 1), $e('c', 'big', 'mg'), $e('d', 'small', 'mg'), $e('f', 'mg', 'z'), $e('e1', 'm', 'L') ], [ 'nums' => [ 1, 2, 3 ] ]);
	$this->check('If + Merge inside', [ [ 'small', 'big', 'big' ], 'big!' ], [ $var($exe, 'loop')['results'] ?? null, $var($exe, 'z') ]);

	$one = fn($list) => [ $n('m', 'trigger::manual'), $n('L', 'core::loop', [ 'list' => $list ]), $n('s', 'app::set', [ 'variable' => 'in', 'value' => 'x' ], 'L'), $e('e1', 'm', 'L') ];
	$exe = $this->runFlow('loop empty list', $one('{{ [] }}'));
	$this->check('empty list: no pass', [ 'end', 0, null ], [ $exe?->state, $var($exe, 'loop')['count'] ?? null, $var($exe, 'in') ]);
	foreach([ [ '{{ "abc" }}', [], 'Loop: List does not give a list (it gives string)' ], [ '{{ ({a: 1}) }}', [], 'Loop: List does not give a list (it gives an object)' ],
	    [ '{{ big }}', [ 'big' => range(1, 1001) ], 'Loop: the list has 1001 items, at most 1000' ] ] as [ $list, $vars, $want ]) {
	    $exe = $this->runFlow("loop {$list}", $one($list), $vars);
	    $this->check("list error {$list}", [ 'error', $want ], [ $exe?->state, $exe?->error ]);
	}
	$exe = $this->runFlow('loop no steps', [ $n('m', 'trigger::manual'), $n('L', 'core::loop', [ 'list' => '{{ [1, 2] }}' ]), $n('a', 'app::set', [ 'variable' => 'after', 'value' => 'y' ]),
	    $e('e1', 'm', 'L'), $e('e2', 'L', 'a') ]);
	$this->check('a loop without steps', [ 2, 'y' ], [ $var($exe, 'loop')['count'] ?? null, $var($exe, 'after') ]);

	//Wait inside: sleeps per item, resumed (as util\Scheduler::resume does) once the time has come
	$exe = $this->runFlow('loop wait', [ $n('m', 'trigger::manual'), $n('L', 'core::loop', [ 'list' => '{{ [10, 20] }}', 'collect' => '{{ item }}' ]),
	    $n('w', 'app::wait', [ 'amount' => 1, 'unit' => 'seconds' ], 'L'), $n('s', 'app::set', [ 'variable' => 'got', 'value' => '{{ item }}' ], 'L'), $e('x', 'w', 's'), $e('e1', 'm', 'L') ]);
	$sleeps = 0;
	while (is_object($exe) && $exe->state === 'sleep' && $sleeps < 5) {
	    $sleeps++;
	    sleep(1);
	    $exe = \V::app()->om->findById('WfExecution', (string)$exe->_id);
	    $exe->run();
	}
	$this->check('Wait inside: one sleep per item', [ 2, 'end', [ 10, 20 ] ], [ $sleeps, $exe?->state, $var($exe, 'loop')['results'] ?? null ]);

	$this->check('settings refused', [ 'L: List: Write the expression between {{ and }}, e.g. {{ mail.attachments }} (column 1)', 'L: Position variable: must differ from the item variable' ],
	    $this->errorsOf('loop settings', [ $n('m', 'trigger::manual'), $n('L', 'core::loop', [ 'list' => 'abc', 'itemVariable' => 'x', 'indexVariable' => 'x' ]),
		$n('s', 'app::set', [ 'variable' => 'in', 'value' => 'x' ], 'L'), $e('e1', 'm', 'L') ]));
    }

    //CLD: 2026-10-13T15:17:12+03:00: the MCP tools that build workflows (called as an AI step does: no user permissions; util\McpTools::call): step types, create / get / update, layout, refusals
    private function suiteMcp() {
	//CLD: 2026-10-20T23:40:00+03:00: the tools are the SysMCPTools plugin's (was util\McpTools): nothing to check without it
	if (!($tools = self::mcpTools())) {
	    echo "  skipped: the SysMCPTools plugin is not enabled\n";
	    return;
	}
	$call = fn($name, $args) => $tools::call($name, $args, $this->project, false);
	$refused = function($name, $args) use ($call) {
	    try {
		$call($name, $args);
		return 'not refused';
	    } catch (\ErrorException $e) {
		return $e->getMessage();
	    }
	};
	$types = array_column($call('list_step_types', [])['step_types'], null, 'type');
	$this->check('step types: loop is a container, If outputs', [ true, [ 'true', 'false' ] ], [ $types['core::loop']['container'] ?? null, $types['core::if']['outputs'] ?? null ]);
	$listSetting = array_column($types['core::loop']['settings'] ?? [], null, 'key')['list'] ?? [];
	$this->check('step types: a setting\'s syntax', [ 'value', true ], [ $listSetting['syntax'] ?? null, $listSetting['required'] ?? null ]);
	$this->check('step types: Switch outputs follow its cases', true, str_contains($types['core::switch']['outputs_from'] ?? '', 'cases'));
	//CLD: 2026-10-17T14:20:00+03:00: the Read emails step (was the IMAP trigger, `mail`)
	//CLD: 2026-10-17T15:00:00+03:00: app::read-email (was app::imap)
	$this->check('step types: Read emails variables', 'mails', $types['app::read-email']['variables'][0]['name'] ?? null);

	$steps = [
	    [ 'id' => 'start', 'type' => 'trigger::manual' ],
	    [ 'id' => 'check', 'type' => 'core::if', 'title' => 'Many?', 'config' => [ 'conditions' => [ [ 'left' => '{{ nums.length }}', 'operator' => 'gt', 'value' => '2' ] ] ] ],
	    [ 'id' => 'few', 'type' => 'app::set', 'config' => [ 'variable' => 'size', 'value' => 'few' ] ],
	    [ 'id' => 'each', 'type' => 'core::loop', 'config' => [ 'list' => '{{ nums }}', 'collect' => '{{ item * 10 }}' ] ],
	    [ 'id' => 'mark', 'type' => 'app::set', 'parent' => 'each', 'config' => [ 'variable' => 'last', 'value' => '{{ item }}' ] ],
	    [ 'id' => 'done', 'type' => 'app::set', 'config' => [ 'variable' => 'size', 'value' => 'many {{ loop.results }}' ] ],
	];
	$connections = [ [ 'from' => 'start', 'to' => 'check' ], [ 'from' => 'check', 'to' => 'each', 'output' => 'true' ], [ 'from' => 'check', 'to' => 'few', 'output' => 'false' ],
	    [ 'from' => 'each', 'to' => 'done' ] ];
	$made = $call('create_workflow', [ 'name' => 'Check: mcp built', 'steps' => $steps, 'connections' => $connections ]);
	$this->created[] = \V::app()->om->to_oid($made['id']);
	$this->check('create: a draft without problems', [ 'draft', [] ], [ $made['status'], $made['problems'] ]);
	$wf = \V::app()->om->findById('Workflow', $made['id']);
	$g = array_map(fn($n) => $n['geometry'] ?? null, $wf->nodes);
	$inside = fn($c, $p) => $c['x'] >= $p['x'] && $c['y'] >= $p['y'] && $c['x'] + $c['w'] <= $p['x'] + $p['w'] && $c['y'] + $c['h'] <= $p['y'] + $p['h'];
	$this->check('layout: left to right, the loop around its step', [ true, true, true ],
	    [ $g['start']['x'] < $g['check']['x'], $g['check']['x'] < $g['each']['x'], $inside($g['mark'], $g['each']) ]);
	$this->check('connections: If output labels kept as branch', [ 'true', 'false' ], [ $wf->nodes['e2-check-each']['branch'] ?? null, $wf->nodes['e3-check-few']['branch'] ?? null ]);
	$this->check('defaults filled', [ 'item', 'all' ], [ $wf->nodes['each']['config']['itemVariable'] ?? null, $wf->nodes['check']['config']['match'] ?? null ]);
	$run = $call('run_workflow', [ 'workflow' => $made['id'], 'variables' => [ 'nums' => [ 1, 2, 3 ] ] ]);
	$this->check('the built workflow runs', [ 'end', 'many [10,20,30]', 3 ], [ $run['state'], $run['variables']['size'] ?? null, $run['variables']['last'] ?? null ]);

	$spec = $call('get_workflow', [ 'workflow' => 'Check: mcp built' ]);
	$this->check('get: steps and connections as given', [ array_column($steps, 'id'), [ 'true', 'false' ], 'each' ],
	    [ array_column($spec['steps'], 'id'), [ $spec['connections'][1]['output'], $spec['connections'][2]['output'] ], array_column($spec['steps'], 'parent', 'id')['mark'] ?? null ]);

	//update: one step added after `few`, the others keep their place; renamed
	$spec['steps'][] = [ 'id' => 'note', 'type' => 'app::set', 'config' => [ 'variable' => 'n', 'value' => 'x' ] ];
	$spec['connections'][] = [ 'from' => 'few', 'to' => 'note' ];
	$upd = $call('update_workflow', [ 'workflow' => $made['id'], 'name' => 'Check: mcp built 2', 'steps' => $spec['steps'], 'connections' => $spec['connections'] ]);
	$wf = \V::app()->om->findById('Workflow', $made['id']);
	$g2 = array_map(fn($n) => $n['geometry'] ?? null, $wf->nodes);
	$this->check('update: kept positions, the new step right of its predecessor, renamed', [ true, true, true, 'Check: mcp built 2' ],
	    [ $g2['check'] == $g['check'], $g2['mark'] == $g['mark'], $g2['note']['x'] > $g2['few']['x'] && $g2['note']['y'] == $g2['few']['y'], $upd['name'] ]);
	$rel = $call('update_workflow', [ 'workflow' => $made['id'], 'steps' => $spec['steps'], 'connections' => $spec['connections'], 'relayout' => true ]);
	$this->check('update: relayout', [], $rel['problems']);

	//webhook trigger: the token is the server's (new step: a new one; kept step: kept; an agent's value ignored)
	$hook = $call('create_workflow', [ 'name' => 'Check: mcp hook', 'steps' => [ [ 'id' => 'h', 'type' => 'trigger::webhook', 'config' => [ 'token' => str_repeat('a', 32) ] ],
	    [ 'id' => 's', 'type' => 'app::set', 'config' => [ 'variable' => 'x', 'value' => '1' ] ] ], 'connections' => [ [ 'from' => 'h', 'to' => 's' ] ] ]);
	$this->created[] = \V::app()->om->to_oid($hook['id']);
	$token = \V::app()->om->findById('Workflow', $hook['id'])->nodes['h']['config']['token'] ?? '';
	$call('update_workflow', [ 'workflow' => $hook['id'], 'steps' => [ [ 'id' => 'h', 'type' => 'trigger::webhook', 'config' => [ 'token' => str_repeat('b', 32) ] ] ], 'connections' => [] ]);
	$this->check('webhook token: the server\'s, kept on update', [ true, $token ], [ $token !== str_repeat('a', 32) && strlen($token) == 32, \V::app()->om->findById('Workflow', $hook['id'])->nodes['h']['config']['token'] ?? null ]);

	//refusals
	$one = fn($extra = [], $conn = []) => [ 'name' => 'Check: mcp refused', 'steps' => array_merge([ [ 'id' => 'start', 'type' => 'trigger::manual' ] ], $extra), 'connections' => $conn ];
	$this->check('refused: unknown type', 'steps[1]: unknown type `app::nope` (list_step_types)', $refused('create_workflow', $one([ [ 'id' => 'x', 'type' => 'app::nope' ] ])));
	//CLD: 2026-10-14T21:17:55+03:00: a loop inside another loop
	$this->check('refused: a loop inside a loop', 'step `B`: a loop cannot be inside another loop', $refused('create_workflow', $one([ [ 'id' => 'A', 'type' => 'core::loop', 'config' => [ 'list' => '{{ [1] }}' ] ],
	    [ 'id' => 'B', 'type' => 'core::loop', 'config' => [ 'list' => '{{ [1] }}' ], 'parent' => 'A' ] ], [ [ 'from' => 'start', 'to' => 'A' ] ])));
	$this->check('refused: a missing output', true, str_starts_with($refused('create_workflow', $one([ [ 'id' => 'i', 'type' => 'core::if', 'config' => [ 'conditions' => [ [ 'left' => '{{ a }}', 'operator' => 'empty' ] ] ] ],
	    [ 'id' => 's', 'type' => 'app::set', 'config' => [ 'variable' => 'x', 'value' => '1' ] ] ], [ [ 'from' => 'start', 'to' => 'i' ], [ 'from' => 'i', 'to' => 's', 'output' => 'maybe' ] ])),
	    'connections[1]: step `i` has no output `maybe` (outputs: true, false)'));
	//CLD: 2026-10-19T17:25:00+03:00: wrong settings are saved (was refused), the reason in `problems`; such a draft is not run (Workflow::issues)
	$wrong = $call('create_workflow', [ 'name' => 'Check: mcp wrong settings' ] + $one([ [ 'id' => 'i', 'type' => 'core::if', 'config' => [ 'conditions' => [ [ 'left' => 'a', 'operator' => 'eq', 'value' => '1' ] ] ] ] ],
	    [ [ 'from' => 'start', 'to' => 'i' ] ]));
	if (!empty($wrong['id']))
	    $this->created[] = \V::app()->om->to_oid($wrong['id']);
	$this->check('wrong settings: saved, the reason in problems', [ true, true ], [ !empty($wrong['id']),
	    (bool)array_filter($wrong['problems'] ?? [], fn($p) => $p['level'] === 'error' && ($p['step'] ?? null) === 'i' && str_contains($p['message'], 'Condition 1: Test: Write the variable between {{ and }}')) ]);
	$this->check('wrong settings: not run', true, str_contains($refused('run_workflow', [ 'workflow' => $wrong['id'] ?? '' ]), 'has errors'));
	$this->check('refused: parent not a loop', 'step `s`: parent `start` is not a loop of this workflow', $refused('create_workflow', $one([ [ 'id' => 's', 'type' => 'app::set', 'parent' => 'start', 'config' => [ 'variable' => 'x' ] ] ])));
	$this->check('refused: duplicate id', 'steps[1]: the id `start` is used twice', $refused('create_workflow', $one([ [ 'id' => 'start', 'type' => 'app::set' ] ])));
	$this->check('refused: steps without connections on update', 'give both steps and connections (the whole new draft), or neither', $refused('update_workflow', [ 'workflow' => $made['id'], 'steps' => [] ]));
	$this->check('nothing saved when refused', 0, \V::app()->om->count('Workflow', [ 'name' => 'Check: mcp refused' ]));
	$saved = $call('create_workflow', $one([ [ 'id' => 's', 'type' => 'app::set', 'config' => [ 'variable' => 'x', 'value' => '1' ] ] ]));
	$this->created[] = \V::app()->om->to_oid($saved['id']);
	$this->check('connection problems are saved and returned', true, (bool)array_filter($saved['problems'], fn($p) => $p['level'] === 'error'));
	//CLD: 2026-10-22T01:11:17+03:00: + the structure of the data tables
	$this->mcpTableChecks($tools, $call, $refused);
    }

    //CLD: 2026-10-22T01:11:17+03:00: the data table structure tools of SysMCPTools (create_table, update_table, add_column, update_column, delete_column): made, refused (nothing saved),
    //used by the row tools right away, a column's values gone with it (the permissions are not checked here: the console has no auth, user->can allows); the tables made are
    //deleted at the end
    private function mcpTableChecks($tools, \Closure $call, \Closure $refused) {
	$om = \V::app()->om;
	$name = 'check_mcp_' . substr(uniqid(), -6);
	$made = [];
	try {
	    $a = $call('create_table', [ 'name' => $name, 'columns' => [
		[ 'key' => 'title', 'label' => 'Title', 'required' => true ],
		[ 'key' => 'qty', 'type' => 'number' ],
		[ 'key' => 'status', 'type' => 'select', 'choices' => [ 'new', 'done', 'new', ' ' ] ],
	    ] ])['table'];
	    $made[] = $a['id'];
	    $this->check('tables: created with its columns (label from the key, choices without doubles / blanks)', [ $name, [ 'title', 'qty', 'status' ], [ 'Title', 'qty' ], true, [ 'new', 'done' ] ],
		[ $a['name'], array_column($a['columns'], 'key'), [ $a['columns'][0]['label'], $a['columns'][1]['label'] ], $a['columns'][0]['required'], $a['columns'][2]['choices'] ?? null ]);
	    $b = $call('create_table', [ 'name' => $name . '_b', 'columns' => [ [ 'key' => 'parent', 'type' => 'object', 'points_to' => $name ] ] ])['table'];
	    $made[] = $b['id'];
	    $this->check('tables: an object column points to a table of the project (by name)', $name, $b['columns'][0]['points_to'] ?? null);

	    $ins = $call('insert_rows', [ 'table' => $name, 'rows' => [ [ 'title' => 'a', 'qty' => 2, 'status' => 'new' ] ] ]);
	    $this->check('tables: the new table takes rows right away; its rules apply', [ 1, true ],
		[ $ins['inserted'], str_contains($refused('insert_rows', [ 'table' => $name, 'rows' => [ [ 'qty' => 1 ] ] ]), 'title') ]);

	    $count = fn($n) => $om->count('DataTableDef', [ 'name' => $n, 'aid' => $this->account->_id ]);
	    $bad = fn($cols, $n = 'check_mcp_refused') => $refused('create_table', [ 'name' => $n, 'columns' => $cols ]);
	    $this->check('tables refused: a name with spaces, a name used, no columns', [ true, true, true ], [
		str_starts_with($bad([ [ 'key' => 'x' ] ], 'my table'), 'name: '), str_contains($bad([ [ 'key' => 'x' ] ], $name), 'already exists'),
		str_contains($bad([]), 'at least one column') ]);
	    $this->check('tables refused: reserved / taken keys, a key twice, a select without choices, an object without a target, an unknown type / setting', [ true, true, true, true, true, true, true ], [
		str_contains($bad([ [ 'key' => 'created_at' ] ]), 'reserved'), str_contains($bad([ [ 'key' => 'id' ] ]), 'taken'), str_contains($bad([ [ 'key' => 'x' ], [ 'key' => 'x' ] ]), 'used by another column'),
		str_contains($bad([ [ 'key' => 'x', 'type' => 'select' ] ]), 'Enter at least one choice'), str_contains($bad([ [ 'key' => 'x', 'type' => 'object' ] ]), 'Choose what this column points to'),
		str_starts_with($bad([ [ 'key' => 'x', 'type' => 'nope' ] ]), 'columns[0]: Type: '), str_contains($bad([ [ 'key' => 'x', 'size' => 3 ] ]), 'unknown setting `size`') ]);
	    $this->check('tables refused: nothing saved', 0, $count('check_mcp_refused'));

	    $c = $call('add_column', [ 'table' => $name, 'column' => [ 'key' => 'note', 'label' => 'Note' ] ])['table'];
	    $this->check('columns: added; a key used refused', [ [ 'title', 'qty', 'status', 'note' ], true ],
		[ array_column($c['columns'], 'key'), str_contains($refused('add_column', [ 'table' => $name, 'column' => [ 'key' => 'qty' ] ]), 'already exists') ]);
	    $u = $call('update_column', [ 'table' => $name, 'key' => 'status', 'label' => 'State', 'required' => true, 'choices' => [ 'new', 'done', 'late' ] ])['table'];
	    $st = array_column($u['columns'], null, 'key')['status'];
	    $this->check('columns: label, required, choices changed', [ 'State', true, [ 'new', 'done', 'late' ] ], [ $st['label'], $st['required'], $st['choices'] ?? null ]);
	    $this->check('columns refused: a type change, nothing to change, a select left without choices, a column that does not exist', [ true, true, true, true ], [
		str_contains($refused('update_column', [ 'table' => $name, 'key' => 'qty', 'type' => 'text' ]), 'cannot be changed'),
		str_contains($refused('update_column', [ 'table' => $name, 'key' => 'qty' ]), 'give what to change'),
		str_contains($refused('update_column', [ 'table' => $name, 'key' => 'status', 'choices' => [] ]), 'Enter at least one choice'),
		str_contains($refused('update_column', [ 'table' => $name, 'key' => 'nope', 'label' => 'x' ]), 'has no column `nope`') ]);

	    $d = $call('delete_column', [ 'table' => $name, 'key' => 'qty' ]);
	    $row = $call('query_table', [ 'table' => $name ])['rows'][0] ?? [];
	    $this->check('columns: deleted, its values gone from the rows', [ 'qty', [ 'title', 'status', 'note' ], false, 'a' ],
		[ $d['deleted'], array_column($d['table']['columns'], 'key'), array_key_exists('qty', $row), $row['title'] ?? null ]);

	    $r = $call('update_table', [ 'table' => $a['id'], 'name' => $name . '_r' ])['table'];
	    $this->check('tables: renamed (the rows stay); a name used refused', [ $name . '_r', 1, true ], [ $r['name'], count($call('query_table', [ 'table' => $name . '_r' ])['rows']),
		str_contains($refused('update_table', [ 'table' => $a['id'], 'name' => $name . '_b' ]), 'already exists') ]);

	} finally {
	    foreach($made as $id)
		$om->findOne('DataTableDef', [ '_id' => $om->to_oid($id) ])?->delete();
	}
    }

    //CLD: 2026-10-13T15:24:28+03:00: the project variables as {{ vars.<name> }}: at run time (Set, If, Loop; not saved with the execution), the editors' help, the reserved name
    private function suiteVars() {
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$made = [];
	try {
	    foreach([ 'check_greeting' => [ 'Hello', 'how to greet' ], 'check_limit' => [ '2', '' ] ] as $name => [ $value, $description ]) {
		//CLD: 2026-10-22T00:28:13+03:00: a credential of type Variable (was the Variable object)
		$v = \V::app()->om->create('CredentialVariable', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => $name, 'value' => $value, 'description' => $description ]);
		if (!$v->validates()) {
		    $this->fail("variable {$name}: " . json_encode($v->errors()));
		    return;
		}
		$v->save();
		$made[] = $v;
	    }
	    $exe = $this->runFlow('vars run', [ $n('m', 'trigger::manual'), $n('s', 'app::set', [ 'variable' => 'msg', 'value' => '{{ vars.check_greeting }}, {{ who }}!' ]),
		$n('i', 'core::if', [ 'conditions' => [ [ 'left' => '{{ vars.check_limit }}', 'operator' => 'lt', 'value' => '{{ nums.length }}' ] ] ]),
		$n('L', 'core::loop', [ 'list' => '{{ nums.filter(x => x > Number(vars.check_limit)) }}', 'collect' => '{{ item }}' ]), $n('in', 'app::set', [ 'variable' => 'q', 'value' => '1' ], 'L'),
		$n('no', 'app::set', [ 'variable' => 'msg', 'value' => 'no' ]),
		$e('e1', 'm', 's'), $e('e2', 's', 'i'), $e('e3', 'i', 'L', 0), $e('e4', 'i', 'no', 1) ], [ 'who' => 'Ana', 'nums' => [ 1, 2, 3, 4 ] ]);
	    $this->check('run: Set, If and Loop see vars', [ 'end', 'Hello, Ana!', [ 3, 4 ] ], [ $exe?->state, $exe?->getVariable('msg'), $exe?->getVariable('loop')['results'] ?? null ]);
	    $this->check('run: vars are not saved with the execution', false, array_key_exists('vars', (array)\V::app()->om->findById('WfExecution', (string)$exe?->_id)?->variables));

	    //CLD: 2026-10-22T00:28:13+03:00: CredentialVariable::expressionType (was models\Variable's)
	    $vars = [ 'vars' => \models\credential\CredentialVariable::expressionType($this->account->_id, $this->project->_id) ];
	    $this->check('help: the project variables suggested', [ 'check_greeting', 'check_limit' ],
		array_values(array_filter(array_column(\util\Expression::complete('{{ vars.check', '{{ vars.check', $vars)['items'], 'label'), fn($l) => str_starts_with($l, 'check_'))));
	    $this->check('help: description shown', true, str_contains(array_column(\util\Expression::complete('{{ vars.check_g', '{{ vars.check_g', $vars)['items'], 'detail', 'label')['check_greeting'] ?? '', 'how to greet'));
	    $this->check('help: a name the project does not have', [ '`check_nope` is not a field of `vars`' ], array_column(\util\Expression::check('{{ vars.check_nope }}', $vars), 'message'));

	    $this->check('reserved: a step setting `vars` is refused', true, (bool)array_filter($this->errorsOf('vars reserved', [ $n('m', 'trigger::manual'),
		$n('s', 'app::set', [ 'variable' => 'vars', 'value' => 'x' ]), $e('e1', 'm', 's') ]), fn($m) => str_contains($m, '`vars` is the name of the project variables')));
	    //CLD: 2026-10-20T23:40:00+03:00: with the SysMCPTools plugin (was util\McpTools)
	    if ($tools = self::mcpTools()) {
		try {
		    $tools::call('run_workflow', [ 'workflow' => 'Check: vars run', 'variables' => [ 'vars' => 1 ] ], $this->project, false);
		    $this->fail('reserved: run_workflow with `vars` not refused');
		} catch (\ErrorException $ex) {
		    $this->check('reserved: run_workflow refuses a variable `vars`', 'variables: `vars` is the name of the project variables', $ex->getMessage());
		}
	    }
	} finally {
	    foreach($made as $v)
		$v->delete();
	}
    }

    //CLD: 2026-10-13T15:34:38+03:00: the Code step: variables read / set / created, locals, in-place changes, console, vars read-only, errors (fail / error output), time limit, saving
    //checks, the variables it gives later steps, inside a loop
    private function suiteCode() {
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$flow = fn($code, $config = []) => [ $n('m', 'trigger::manual'), $n('c', 'app::code', [ 'code' => $code ] + $config), $n('ok', 'app::set', [ 'variable' => 'route', 'value' => 'ok' ]),
	    $n('bad', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]), $e('e1', 'm', 'c'), $e('e2', 'c', 'ok', 0) ]
	    + (($config['onError'] ?? '') === 'output' ? [ 99 => $e('e3', 'c', 'bad', 1) ] : [ 99 => $e('e3', 'ok', 'bad', 0) ]);
	$items = [ 'items' => [ [ 'name' => 'a', 'qty' => 2 ], [ 'name' => 'b', 'qty' => 5 ] ] ];
	$code = "let tmp = items.map(i => i.qty);\ntotal = tmp.reduce((a, b) => a + b, 0);\nstatus = total > 5 ? 'big' : 'small';\nitems.push({name: 'z', qty: 0});\n"
	    . "function helper() { return 1; }\nconsole.log('total', total);";
	$exe = $this->runFlow('code basic', $flow($code), $items);
	$this->check('sets, creates, changes in place; locals stay local', [ 'end', 7, 'big', 3, false, false ],
	    [ $exe?->state, $exe?->getVariable('total'), $exe?->getVariable('status'), count((array)$exe?->getVariable('items')), array_key_exists('tmp', (array)$exe?->variables), array_key_exists('helper', (array)$exe?->variables) ]);
	$this->check('console.log in the log, the variables set listed', [ 'console: total 7', 'code: set items, total, status' ], [ self::logLine($exe, 'console:'), self::logLine($exe, 'code:') ]);

	$exe = $this->runFlow('code vars', $flow('vars = 1;'), []);
	$this->check('vars is read-only', [ 'error', true ], [ $exe?->state, str_contains((string)$exe?->error, "Assignment to constant variable 'vars'") ]);
	$exe = $this->runFlow('code error output', $flow('x = nothing.y;', [ 'onError' => 'output' ]), []);
	$this->check('error output: the error variable, the error route', [ 'end', 'error', true ], [ $exe?->state, $exe?->getVariable('route'), str_contains((string)$exe?->getVariable('error'), 'nothing is not defined') ]);
	$t = microtime(true);
	$exe = $this->runFlow('code timeout', $flow('while (true) { n = 1; }', [ 'timeout' => 1 ]), []);
	$this->check('the time limit stops it', [ 'error', true, true ], [ $exe?->state, str_contains((string)$exe?->error, 'Code:'), microtime(true) - $t < 5 ]);

	$this->check('save refuses a syntax error', true, (bool)array_filter($this->errorsOf('code syntax', $flow('let x = ;')), fn($m) => str_starts_with($m, 'c: Code:')));
	$this->check('save refuses an empty code', [ 'c: Code: This field is required' ], $this->errorsOf('code empty', $flow('')));
	$vars = \models\Workflow::variablesAt($flow("total = 1;\ncount++;\nlet local = 2;", [ 'onError' => 'output' ]), 'ok');
	$this->check('later steps see the names it assigns (+ error)', [ 'count', 'error', 'total' ], array_values(array_intersect(array_keys($vars), [ 'total', 'count', 'error', 'local' ])));

	$exe = $this->runFlow('code in a loop', [ $n('m', 'trigger::manual'), $n('L', 'core::loop', [ 'list' => '{{ [1, 2, 3] }}', 'collect' => '{{ sq }}' ]),
	    $n('c', 'app::code', [ 'code' => "sq = item * item;\nsum = (typeof sum === 'undefined' ? 0 : sum) + sq;" ], 'L'), $e('e1', 'm', 'L') ]);
	$this->check('inside a loop', [ 'end', [ 1, 4, 9 ] ], [ $exe?->state, $exe?->getVariable('loop')['results'] ?? null ]);
	$this->check('a script\'s assigned names are not unknown (help)', [], \util\Expression::check("total = 1;\ntotal += 2;", [], 'script'));

	//CLD: 2026-10-20T21:40:00+03:00: a Mongo date is a J Date; an unchanged one is not set again, a new one is set as ISO 8601 local text
	$exe = $this->runFlow('code dates', $flow("year = when.getFullYear();\nlater = Date(when.getTime() + 86400000);"), [ 'when' => new \MongoDB\BSON\UTCDateTime(1792469103000) ]);
	$this->check('dates: a Date in the script, given back as text', [ 'end', 2026, date(DATE_ATOM, 1792469103 + 86400), 'code: set year, later' ],
	    [ $exe?->state, $exe?->getVariable('year'), $exe?->getVariable('later'), self::logLine($exe, 'code:') ]);
    }

    //CLD: 2026-10-13T18:34:40+03:00: forms (util\FormSchema), the Form trigger's answers in a run, the Human task (waits, completed -> resumes; expired -> timeout output)
    private function suiteForms() {
	$F = \util\SmartForm::class;
	$form = $F::normalize([ 'direction' => 'sideways', 'fields' => [
	    //CLD: 2026-10-14T20:17:14+03:00: the condition is the node's showIf (an array); a showIf / hideIf / requiredIf with JS is dropped (were `condition` + a generated JS showIf)
	    [ 'type' => 'title', 'label' => 'T', 'onclick' => 'x', 'showIf' => 'alert(1)' ],
	    [ 'type' => 'number', 'key' => 'amount', 'label' => 'Amount', 'required' => true, 'min' => 1, 'ajaxUrl' => '/evil' ],
	    [ 'type' => 'select', 'key' => 'cat', 'required' => true, 'choices' => [ [ 'value' => 'a', 'label' => 'A' ], 'b', [ 'value' => '' ] ] ],
	    [ 'type' => 'textarea', 'key' => 'why', 'required' => true, 'showIf' => [ 'key' => 'cat', 'operator' => 'eq', 'value' => 'b' ], 'hideIf' => 'x', 'requiredIf' => 'alert(1)' ],
	    [ 'type' => 'columns', 'columns' => [ [ 'width' => 6, 'fields' => [ [ 'type' => 'switch', 'key' => 'ok' ], [ 'type' => 'columns' ] ] ], [ 'width' => 6, 'fields' => [] ] ] ],
	    [ 'type' => 'select', 'key' => 'tags', 'multiple' => true, 'choices' => [ 'x', 'y' ] ],
	    [ 'type' => 'email', 'key' => 'mail', 'pattern' => 'ignored' ],
	    [ 'type' => 'hidden', 'key' => 'src', 'default' => 'web' ],
	    [ 'type' => 'script', 'key' => 'bad' ],
	] ]);
	$why = $F::inputs($form)[2];
	$this->check('normalize: unknown types / properties dropped, the condition kept, JS dropped', [ 'vertical', false, false, false, [ 'key' => 'cat', 'operator' => 'eq', 'value' => 'b' ], false, false, 2, 1 ],
	    [ $form['direction'], isset($form['fields'][0]['onclick']), isset($form['fields'][1]['ajaxUrl']), isset($form['fields'][0]['showIf']), $why['showIf'], isset($why['hideIf']), isset($why['requiredIf']),
		count($form['fields'][2]['choices']), count($form['fields'][4]['columns'][0]['fields']) ]);
	$this->check('errors: none', [], $F::errors($form));
	$this->check('e-mail is its own type (no pattern); a text field loses inputType email', [ false, false ],
	    [ isset($F::inputs($form)[6]['pattern']), isset($F::normalize([ 'fields' => [ [ 'type' => 'text', 'key' => 'x', 'inputType' => 'email' ] ] ])['fields'][0]['inputType']) ]);
	$this->check('errors: duplicate key, no choices, unknown condition key, operator not of the field', [ 'the key `a` is used twice', '"S": add at least one choice',
	    '"Z": its condition uses `nope`, which is not in the form', '"Y": its condition\'s operator `gt` does not apply to `s`' ],
	    $F::errors($F::normalize([ 'fields' => [ [ 'type' => 'text', 'key' => 'a' ], [ 'type' => 'text', 'key' => 'a' ], [ 'type' => 'select', 'key' => 's', 'label' => 'S' ],
		[ 'type' => 'text', 'key' => 'z', 'label' => 'Z', 'showIf' => [ 'key' => 'nope', 'operator' => 'empty' ] ],
		[ 'type' => 'text', 'key' => 'y', 'label' => 'Y', 'showIf' => [ 'key' => 's', 'operator' => 'gt', 'value' => '1' ] ] ] ])));
	[ $answers, $errors ] = $F::answers($form, [ 'amount' => '0', 'cat' => 'b', 'tags' => [ 'x', 'z' ], 'mail' => 'not-an-address', 'src' => 'hacked' ]);
	$this->check('answers: errors per field (min, required by condition, choices, e-mail)', [ 'amount', 'why', 'tags', 'mail' ], array_keys($errors));
	[ $answers, $errors ] = $F::answers($form, [ 'amount' => '12.5', 'cat' => 'a', 'why' => 'ignored', 'ok' => 'on', 'tags' => [ 'y' ], 'mail' => 'x@q.ro', 'src' => 'hacked' ]);
	$this->check('answers: cast, hidden by condition -> null, hidden value from the form', [ [], 12.5, null, true, [ 'y' ], 'web' ],
	    [ $errors, $answers['amount'], $answers['why'], $answers['ok'], $answers['tags'], $answers['src'] ]);
	$this->check('type: suggestions of the answers', [ 'number', 'string', 'boolean', 'array' ],
	    array_map(fn($k) => $F::type($form)['fields'][$k]['type'], [ 'amount', 'cat', 'ok', 'tags' ]));
	//CLD: 2026-10-14T14:08:12+03:00: the `in` operator (a list: blanks / repeats dropped; required only when the value is one of them) and the row scope of showIf()
	$inForm = $F::normalize([ 'fields' => [ [ 'type' => 'select', 'key' => 'cat', 'choices' => [ 'a', 'b', 'c' ] ],
	    [ 'type' => 'text', 'key' => 'why', 'required' => true, 'showIf' => [ 'key' => 'cat', 'operator' => 'in', 'value' => [ 'a', 'b', '', 'a' ] ] ] ] ]);
	$this->check('in: the value is a list, the field required only for one of them', [ [ 'a', 'b' ], [ 'why' ], [] ],
	    [ $inForm['fields'][1]['showIf']['value'], array_keys($F::answers($inForm, [ 'cat' => 'b' ])[1]), $F::answers($inForm, [ 'cat' => 'c' ])[1] ]);
	//CLD: 2026-10-14T20:17:14+03:00: util\Conditions (the fields' operators): one comparison, a set tested against values, a set as a MongoDB query (was the JS of SmartForm::showIf)
	$C = \util\Conditions::class;
	$this->check('conditions: holds (numbers, case, lists, empty)', [ true, false, true, true, true, true, false, true, true, true ],
	    [ $C::holds('10', 'gt', '9'), $C::holds('abc', 'gt', 'b'), $C::holds('Abc', 'contains', 'B'), $C::holds('Abc', 'startsWith', 'a'), $C::holds('b', 'in', 'a, b'),
		$C::holds([ 'x', 'Y' ], 'contains', 'y'), $C::holds([ 'x' ], 'in', [ 'z' ]), $C::holds(false, 'empty', ''), $C::holds('  ', 'empty', ''), $C::holds([], 'empty', '') ]);
	$this->check('conditions: test all / any against values', [ true, false, true ],
	    [ $C::test([ [ 'key' => 'a', 'operator' => 'eq', 'value' => '1' ], [ 'key' => 'b', 'operator' => 'notEmpty' ] ], 'all', [ 'a' => 1, 'b' => 'x' ]),
		$C::test([ [ 'key' => 'a', 'operator' => 'eq', 'value' => '2' ], [ 'key' => 'b', 'operator' => 'empty' ] ], 'all', [ 'a' => 2, 'b' => 'x' ]),
		$C::test([ [ 'key' => 'a', 'operator' => 'eq', 'value' => '2' ], [ 'key' => 'b', 'operator' => 'empty' ] ], 'any', [ 'a' => 2, 'b' => 'x' ]) ]);
	//CLD: 2026-10-18T11:10:00+03:00: `s` has choices: a select keeps only values among them (sys\fields\Field_select::cast; without choices `a` / `b` were cast to null)
	$fields = [ 'n' => \sys\fields\Field::create([ 'type' => 'number', 'key' => 'n' ]), 's' => \sys\fields\Field::create([ 'type' => 'select', 'key' => 's', 'choices' => [ 'a' => 'A', 'b' => 'B' ] ]) ];
	$query = $C::query([ [ 'key' => 'n', 'operator' => 'gte', 'value' => '5' ], [ 'key' => 's', 'operator' => 'in', 'value' => 'a, b' ] ], 'any', fn($k) => $fields[$k] ?? null);
	$refused = null;
	try { $C::query([ [ 'key' => 's', 'operator' => 'gt', 'value' => '1' ] ], 'all', fn($k) => $fields[$k] ?? null); } catch (\ErrorException $e) { $refused = $e->getMessage(); }
	$this->check('conditions: query (cast by the field, `in` from text, the field\'s operators only)', [ [ '$or' => [ [ 'n' => [ '$gte' => 5 ] ], [ 's' => [ '$in' => [ 'a', 'b' ] ] ] ] ], 'Conditions: `gt` is not an operator of `s`' ],
	    [ $query, $refused ]);

	//CLD: 2026-10-14T19:54:21+03:00: the url type (sys\fields\Field_url's check); no inputType on text fields any more
	$urlForm = $F::normalize([ 'fields' => [ [ 'type' => 'url', 'key' => 'site', 'placeholder' => 'https://' ], [ 'type' => 'text', 'key' => 'phone', 'inputType' => 'tel' ] ] ]);
	//CLD: 2026-10-14T19:59:06+03:00: the url rule only (any scheme it accepts)
	$this->check('url: its own type; text loses inputType; addresses only', [ 'url', false, [], [], [ 'site' ] ],
	    [ $urlForm['fields'][0]['type'], isset($urlForm['fields'][1]['inputType']), $F::answers($urlForm, [ 'site' => 'https://netflow9.io/x' ])[1],
		$F::answers($urlForm, [ 'site' => 'ftp://netflow9.io' ])[1], array_keys($F::answers($urlForm, [ 'site' => 'netflow9.io' ])[1]) ]);
	//CLD: 2026-10-18T14:00:00+03:00: answers checked and cast by a sys\NestedModel (util\SmartForm::fieldDef): the form's messages first, real dates only, a required checkbox
	//checked, the pattern anchored, an unknown single choice refused, blank text null, dates cast
	$more = $F::normalize([ 'fields' => [ [ 'type' => 'number', 'key' => 'n', 'min' => 1 ], [ 'type' => 'date', 'key' => 'd' ], [ 'type' => 'datetime', 'key' => 'dt' ],
	    [ 'type' => 'checkbox', 'key' => 'agree', 'required' => true ], [ 'type' => 'text', 'key' => 'code', 'pattern' => '[A-Z]{3}', 'patternMessage' => 'Three capitals' ],
	    [ 'type' => 'select', 'key' => 'one', 'choices' => [ 'a', 'b' ] ], [ 'type' => 'text', 'key' => 'note' ] ] ]);
	$this->check('answers: the form\'s messages (number, real date, checked, anchored pattern, choices)',
	    [ 'n' => 'Enter a number.', 'd' => 'Enter a date (YYYY-MM-DD).', 'dt' => 'Enter a date and time.', 'agree' => 'This must be checked.', 'code' => 'Three capitals', 'one' => 'Not one of the choices' ],
	    $F::answers($more, [ 'n' => 'abc', 'd' => '2026-02-30', 'dt' => 'soon', 'agree' => 'off', 'code' => 'ABCD', 'one' => 'z' ])[1]);
	[ $answers, $errors ] = $F::answers($more, [ 'n' => ' 3 ', 'd' => '2026-10-18', 'dt' => '2026-10-18T09:30:15', 'agree' => 'on', 'code' => 'ABC', 'one' => 'b', 'note' => '  ' ]);
	$this->check('answers: cast (number, dates, checkbox, choice, blank text -> null)', [ [], 3, true, true, true, 'b', null ],
	    [ $errors, $answers['n'], $answers['d'] instanceof \MongoDB\BSON\UTCDateTime, $answers['dt'] instanceof \MongoDB\BSON\UTCDateTime, $answers['agree'], $answers['one'], $answers['note'] ]);
	//CLD: 2026-10-18T14:40:00+03:00: the token steps' shared methods (models\TriggerToken): newToken in the step's format, checkToken (well formed, not shared with another step)
	$T = \models\TriggerToken::class;
	$tokenErrors = function($tokens) {
	    $wf = \V::app()->om->create('Workflow', [ 'nodes' => [] ]);
	    $nodes = [];
	    foreach($tokens as $i => $t)
		$nodes["h{$i}"] = [ 'wfid' => "h{$i}", 'isNode' => true, 'fqname' => 'trigger::webhook', 'config' => [ 'token' => $t ] ];
	    $wf->nodes = $nodes;
	    $node = \V::app()->om->create('NodeTriggerWebhook', $nodes['h0']);
	    $node->workflow = $wf;
	    //CLD: 2026-10-18T23:45:00+03:00: checkToken takes the format (was a label for the messages)
	    return \models\TriggerToken::checkToken($node, 'hex') ? [] : (array)$node->errors()['config'];
	};
	$hookToken = $T::newToken('hex');
	$this->check('trigger tokens: each step\'s format (32 hex, 8 letters / digits), checkToken: valid, invalid, shared', [ true, true, [],
	    //CLD: 2026-10-18T23:45:00+03:00: the messages no longer name the step (checkToken)
	    [ 'missing or invalid token (remove the step and add it again)' ], [ 'shared with another step' ] ],
	    [ $T::isToken($hookToken, 'hex'), $T::isToken($T::newToken('code'), 'code'),
		$tokenErrors([ $hookToken ]), $tokenErrors([ 'nope' ]), $tokenErrors([ $hookToken, $hookToken ]) ]);
	//CLD: 2026-10-18T15:40:00+03:00: a prefix before the token; the account mail prefix in the `code` format
	$prefixed = $T::newToken('hex', 'wh_');
	$this->check('trigger tokens: with a prefix (kept, checked)', [ true, true, false, false ],
	    [ str_starts_with($prefixed, 'wh_'), $T::isToken($prefixed, 'hex', 'wh_'), $T::isToken($prefixed, 'hex'), $T::isToken($prefixed, 'hex', 'xx_') ]);
	//CLD: 2026-10-18T23:45:00+03:00: the account mail prefix is a field (default `inbound`, lower-cased, letters / digits, at most 30); was Account::newMailPrefix() (removed), a random `code`
	//CLD: 2026-10-19T21:10:00+03:00: the prefix is the InboundMail plugin's setting (InboundMailSettings: prefix; was the account field mail_prefix): only with the plugin active
	if (!empty(\V::app()->om->registry['InboundMailSettings'])) {
	    $prefixError = function($prefix) {
		//set as the settings form does (assign: the field's transform), the default when not set
		$a = \V::app()->om->create('InboundMailSettings', [ 'aid' => $this->account->_id ]);
		if ($prefix !== null)
		    $a->assign([ 'prefix' => $prefix ]);
		$a->validates();
		return [ (string)$a->prefix, isset($a->errors()['prefix']) ];
	    };
	    $this->check('mail prefix: the default, lower-cased, refused (not letters / digits, too long)', [ [ 'inbound', false ], [ 'sales2', false ], true, true ],
		[ $prefixError(null), $prefixError('Sales2'), $prefixError('sales-2')[1], $prefixError(str_repeat('a', 31))[1] ]);
	}
	//CLD: 2026-10-18T13:00:00+03:00: sys\NestedModel (was models\WfNodeConfig; option nestedSchema, was configSchema)
	$urlModel = \sys\NestedModel::create([], [ 'objectName' => 'NestedModel', 'nestedSchema' => [ [ 'key' => 'site', 'type' => 'url', 'label' => 'Site' ] ] ]);
	//CLD: 2026-10-14T19:59:06+03:00: refuses what is not an address (was: refuses other schemes than http(s))
	$urlModel->assign([ 'site' => 'netflow9.io' ]);
	$this->check('url: the model field refuses a non-address, its form field is url', [ false, 'url' ],
	    [ $urlModel->validates(), $F::modelFields($urlModel)[0]['type'] ]);

	//a run: the answers (as FormController starts it), a Human task, completed -> resumes
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$taskForm = [ 'fields' => [ [ 'type' => 'switch', 'key' => 'approve', 'label' => 'Approve' ] ] ];
	//CLD: 2026-10-14T07:50:50+03:00: NodeTriggerForm is in BaseNodes (the Forms plugin was merged)
	//CLD: 2026-10-17T13:24:18+03:00: NodeTriggerForm is in models\node (the BaseNodes plugin was removed)
	$flow = fn($task) => [ $n('f', 'trigger::form', [ 'form' => [ 'fields' => [ [ 'type' => 'number', 'key' => 'amount', 'label' => 'Amount' ] ] ] ] + \models\node\NodeTriggerForm::initConfig()),
	    $n('t', 'app::task', $task + [ 'title' => 'Approve {{ form.amount }}', 'form' => $taskForm ]),
	    $n('s', 'app::set', [ 'variable' => 'summary', 'value' => '{{ form.amount }}/{{ task.approve }}' ]), $n('x', 'app::set', [ 'variable' => 'summary', 'value' => 'expired' ]),
	    $e('e1', 'f', 't'), $e('e2', 't', 's', 0) ] + (isset($task['timeoutUnit']) ? [ 99 => $e('e3', 't', 'x', 1) ] : []);
	$wf = $this->workflow('forms run', $flow([]));
	$this->check('the workflow is valid', true, $wf->validates());
	$wf->save();
	$this->created[] = $wf->_id;
	$exe = \models\WfExecution::startFor($wf, 'form', [ 'vars' => [ 'form' => [ 'amount' => 120 ], 'form_user' => null ] ], 'f');
	$exe->run();
	$task = \V::app()->om->findOne('WfTask', [ 'execution' => $exe->_id ]);
	$this->check('the run waits for the task', [ 'sleep', 'open', 'Approve 120' ], [ $exe->state, $task?->state, $task?->title ]);
	if ($task) {
	    \V::app()->om->updateMany('WfTask', [ '_id' => $task->_id, 'state' => 'open' ], [ '$set' => [ 'state' => 'done', 'answers' => [ 'approve' => true ] ] ]);
	    $exe = \V::app()->om->findById('WfExecution', (string)$exe->_id);
	    $exe->run();
	    $this->check('completed: the run resumes with the answers', [ 'end', '120/true', [ 'approve' => true ] ], [ $exe->state, $exe->getVariable('summary'), $exe->getVariable('task') ]);
	}
	//a time limit passed first: expired -> timeout output
	$wf = $this->workflow('forms timeout', $flow([ 'timeoutUnit' => 'minutes', 'timeout' => 5 ]));
	$wf->validates();
	$wf->save();
	$this->created[] = $wf->_id;
	$exe = \models\WfExecution::startFor($wf, 'form', [ 'vars' => [ 'form' => [ 'amount' => 5 ] ] ], 'f');
	$exe->run();
	$task = \V::app()->om->findOne('WfTask', [ 'execution' => $exe->_id ]);
	$this->check('with a time limit: due, the run wakes then', [ 'sleep', true, true ], [ $exe->state, $task?->due_at instanceof \MongoDB\BSON\UTCDateTime, $exe->wake_at instanceof \MongoDB\BSON\UTCDateTime ]);
	if ($task) {
	    $past = new \MongoDB\BSON\UTCDateTime((time() - 60) * 1000);
	    \V::app()->om->updateMany('WfTask', [ '_id' => $task->_id ], [ '$set' => [ 'due_at' => $past ] ]);
	    $exe = \V::app()->om->findById('WfExecution', (string)$exe->_id);
	    $exe->__nodes['t']->resume_at = $past;
	    $exe->run();
	    $this->check('expired: the timeout output, the task expired', [ 'end', 'expired', 'expired' ],
		[ $exe->state, $exe->getVariable('summary'), \V::app()->om->findById('WfTask', (string)$task->_id)?->state ]);
	}
	//deleting the workflow takes its tasks and form index entries with it (models\WfTask::beforeWorkflowDelete, hooked by sys\Boot; CLD: 2026-10-17T13:24:18+03:00: was plugins\BaseNodes\Plugin::beforeWorkflowDelete; CLD: 2026-10-14T07:50:50+03:00: was plugins\Forms\Plugin)
	$id = $wf->_id;
	\V::app()->om->findById('Workflow', (string)$id)->delete();
	$this->check('a deleted workflow takes its tasks with it', 0, \V::app()->om->count('WfTask', [ 'wf' => $id ]));
    }

    //CLD: 2026-10-17T20:40:00+03:00: the File upload trigger: an upload queues one job per published workflow of the project whose step accepts the file's type; the job starts the run
    private function suiteFile() {
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$om = \V::app()->om;
	$wfs = [];
	foreach([ 'pdf' => [ 'PDF' ], 'any' => [] ] as $name => $types) {
	    $wf = $this->workflow('file ' . $name, [ $n('f', 'trigger::file', [ 'types' => $types, 'variable' => 'up' ]),
		$n('s', 'app::set', [ 'variable' => 'got', 'value' => '{{ up.filename }}/{{ up.type }}/{{ up.size }}' ]), $e('e1', 'f', 's') ]);
	    $this->check("file {$name}: the workflow is valid", true, $wf->validates());
	    $wf->save();
	    $this->created[] = $wf->_id;
	    $version = $om->create('WfVersion', [ 'aid' => $wf->aid, 'project' => $wf->project, 'wf' => $wf->_id, 'number' => 1, 'name' => (string)$wf->name, 'nodes' => $wf->nodes ]);
	    $version->save();
	    $wf->version = $version->_id;
	    $wf->status = \models\Workflow::PUBLISHED;
	    $wf->save();
	    $wfs[$name] = $wf;
	}
	//CLD: 2026-10-18T13:30:00+03:00: refused by the select field's `choices` rule (was NodeTriggerFile::validates); the message is checked too
	//CLD: 2026-10-18T15:00:00+03:00: dropped by the select field's cast (the step then takes any file)
	$bad = $this->workflow('file bad type', [ $n('f', 'trigger::file', [ 'types' => [ 'EXE', 'PDF' ], 'variable' => 'up' ]) ]);
	$this->check('a type not in the list is dropped', [ true, [ 'PDF' ] ], [ $bad->validates(), $bad->nodes['f']['config']['types'] ?? null ]);

	$ids = array_map(fn($w) => (string)$w->_id, $wfs);
	$jobsFor = fn($fid) => $om->find('Job', [ 'job_spec' => '\models\node\NodeTriggerFile::deliver', 'job_data.file' => (string)$fid ]);
	$files = [];
	try {
	    foreach([ 'check.pdf' => [ 'any', 'pdf' ], 'check.txt' => [ 'any' ] ] as $filename => $expected) {
		$fid = \models\File::createFromString($filename, 'hello', [ 'metadata' => [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'owner' => null, 'source' => 'check' ] ]);
		$files[] = $fid;
		//CLD: 2026-10-17T21:00:54+03:00: the steps found and run here, as uploaded() / the job would (the jobs uploaded() queues are taken by a running daemon first)
		$file = $om->findOne('File', [ '_id' => $fid ]);
		$steps = new \ReflectionMethod(\models\node\NodeTriggerFile::class, 'stepsFor');
		$steps->setAccessible(true);
		$started = [];
		foreach($steps->invoke(null, $this->account->_id, $this->project->_id, $file->type) as [ $wf, $node ]) {
		    if (!in_array((string)$wf->_id, $ids, true))
			continue;
		    \models\node\NodeTriggerFile::deliver((object)[ 'wf' => (string)$wf->_id, 'node' => (string)$node['wfid'], 'file' => (string)$fid, 'user' => '', 'aid' => $this->account->_id ]);
		    $exe = $om->findOne('WfExecution', [ 'wf' => $wf->_id, 'input.file' => (string)$fid ]);
		    $started[array_search((string)$wf->_id, $ids, true)] = [ $exe?->state, $exe?->trigger, $exe?->getVariable('got') ];
		}
		ksort($started);
		$type = $filename === 'check.pdf' ? 'PDF' : 'Text';
		$want = [];
		foreach($expected as $name)
		    $want[$name] = [ 'end', 'file', "{$filename}/{$type}/5" ];
		ksort($want);
		$this->check("{$filename}: the workflows whose step accepts it run, with the file", $want, $started);
	    }
	} finally {
	    foreach($files as $fid) {
		foreach($jobsFor($fid) as $job)
		    $job->delete();
		$om->findOne('File', [ '_id' => $fid ])?->delete();
	    }
	    foreach($wfs as $wf) {
		foreach($om->find('WfVersion', [ 'wf' => $wf->_id ]) as $v)
		    $v->delete();
	    }
	}
    }

    //CLD: 2026-10-17T21:00:54+03:00: images for the AI steps: read_image (NetFlow9 tool), the step's Images setting, the requests of Claude and OpenAI (no model is called)
    //CLD: 2026-10-18T12:30:00+03:00: NodeAppOpenAI (was NodeAppLemonade)
    private function suiteImages() {
	$n = [ self::class, 'node' ];
	$om = \V::app()->om;
	$meta = [ 'metadata' => [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'owner' => null, 'source' => 'check' ] ];
	$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
	$files = [ \models\File::createFromString('check-a.png', $png, $meta), \models\File::createFromString('check-b.png', $png, $meta), \models\File::createFromString('check.txt', 'hi', $meta) ];
	[ $a, $b, $txt ] = array_map('strval', $files);
	try {
	    //CLD: 2026-10-20T23:40:00+03:00: read_image: with the SysMCPTools plugin, through util\MCPTools as the AI steps call it (was util\McpTools)
	    if ($readImage = \util\MCPTools::all()['read_image'] ?? null) {
		[ $result, $images ] = ($readImage['run'])([ 'id' => $a ], $this->project);
		$this->check('read_image: the image apart from the result', [ 'check-a.png', false, [ [ 'image/png', base64_encode($png) ] ] ],
		    [ $result['filename'] ?? null, isset($result[self::mcpTools()::IMAGES]), $images ]);
		$error = null;
		try {
		    ($readImage['run'])([ 'id' => $txt ], $this->project);
		} catch (\ErrorException $e) {
		    $error = $e->getMessage();
		}
		$this->check('read_image: a text file is refused', true, is_string($error) && str_contains($error, 'not an image'));
	    }

	    //the Images setting: a list with an id and an attachment object, ids in text
	    $prompt = function($value, $vars) use ($n) {
		$wf = $this->workflow('images', [ $n('m', 'trigger::manual'), $n('c', 'app::claude', [ 'images' => $value ]) ]);
		$exe = \models\WfExecution::startFor($wf, 'console', [ 'vars' => $vars ]);
		$exe->__nodes['c']->exec = $exe;
		$m = new \ReflectionMethod($exe->__nodes['c'], 'promptImages');
		$m->setAccessible(true);
		try {
		    return count($m->invoke($exe->__nodes['c'], $this->project));
		} catch (\ErrorException $e) {
		    return $e->getMessage();
		}
	    };
	    $this->check('Images: a list of ids / attachments', 2, $prompt('{{ pics }}', [ 'pics' => [ $a, [ 'filename' => 'x', 'file' => $b ] ] ]));
	    $this->check('Images: ids in text', 2, $prompt("{$a}, {$b}", []));
	    $this->check('Images: empty', 0, $prompt('', []));
	    $this->check('Images: a text file is refused', true, str_contains((string)$prompt($txt, []), 'not an image'));

	    //the requests
	    $img = [ 'image/png', 'QUJD' ];
	    $call = function($class, $method, ...$args) {
		$o = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
		$m = new \ReflectionMethod($o, $method);
		$m->setAccessible(true);
		return $m->invokeArgs($o, $args);
	    };
	    $state = $call(\models\node\NodeAppClaude::class, 'startConversation', null, '', 'what is it?', null, [ $img ]);
	    $this->check('Claude: the prompt with its image', [ [ 'type' => 'image', 'source' => [ 'type' => 'base64', 'media_type' => 'image/png', 'data' => 'QUJD' ] ], [ 'type' => 'text', 'text' => 'what is it?' ] ],
		$state['messages'][0]['content']);
	    $this->check('Claude: no images, the prompt as text', 'hi', $call(\models\node\NodeAppClaude::class, 'startConversation', null, '', 'hi', null)['messages'][0]['content']);
	    $o = (new \ReflectionClass(\models\node\NodeAppClaude::class))->newInstanceWithoutConstructor();
	    $m = new \ReflectionMethod($o, 'addToolResults');
	    $m->setAccessible(true);
	    $state = [ 'messages' => [] ];
	    $m->invokeArgs($o, [ &$state, [ [ 't1', '{"id":1}', false, [ $img ] ], [ 't2', 'x', false, [] ] ] ]);
	    $this->check('Claude: a tool result with its image', [ [ [ 'type' => 'text', 'text' => '{"id":1}' ], [ 'type' => 'image', 'source' => [ 'type' => 'base64', 'media_type' => 'image/png', 'data' => 'QUJD' ] ] ], 'x' ],
		array_column($state['messages'][0]['content'], 'content'));

	    $state = $call(\models\node\NodeAppOpenAI::class, 'startConversation', null, 'sys', 'what is it?', null, [ $img ]);
	    $this->check('OpenAI: the prompt with its image', [ [ 'type' => 'image_url', 'image_url' => [ 'url' => 'data:image/png;base64,QUJD' ] ], [ 'type' => 'text', 'text' => 'what is it?' ] ],
		$state['messages'][1]['content']);
	    $o = (new \ReflectionClass(\models\node\NodeAppOpenAI::class))->newInstanceWithoutConstructor();
	    $m = new \ReflectionMethod($o, 'addToolResults');
	    $m->setAccessible(true);
	    $state = [ 'messages' => [] ];
	    $m->invokeArgs($o, [ &$state, [ [ 't1', '{"id":1}', false, [ $img ] ] ] ]);
	    $this->check('OpenAI: the tool result, then its image in a user message', [ [ 'tool', 'user' ], 'data:image/png;base64,QUJD' ],
		[ array_column($state['messages'], 'role'), $state['messages'][1]['content'][1]['image_url']['url'] ?? null ]);

	    //CLD: 2026-10-22T12:00:00+03:00: Gemini: the prompt's images, a tool call and its result (the model's turn sent back as it came, the call's id and name), JSON mode, a blocked answer;
	    //the HTTP call replaced (the requests it got, the answers it gives)
	    $state = $call(\models\node\NodeAppGemini::class, 'startConversation', null, 'sys', 'what is it?', null, [ $img ]);
	    $this->check('Gemini: the prompt with its image, the system prompt apart', [ [ [ 'inlineData' => [ 'mimeType' => 'image/png', 'data' => 'QUJD' ] ], [ 'text' => 'what is it?' ] ], 'sys' ],
		[ $state['contents'][0]['parts'], $state['system'] ]);
	    $gem = new class extends \models\node\NodeAppGemini {
		public $sent = [];
		public $answers = [];
		public function __construct() {
		}
		protected function postJson($url, array $headers, array $body, $assoc = true) {
		    $this->sent[] = [ $url, $headers, json_decode(\util\JSON::encode($body), true) ];
		    return json_decode(array_shift($this->answers), $assoc);
		}
		public function turn($cred, array &$state, array $tools, $schema) {
		    return $this->callModel($cred, $state, $tools, $schema);
		}
		public function results(array &$state, array $results) {
		    $this->addToolResults($state, $results);
		}
	    };
	    $gem->config = [ 'model' => 'gemini-test', 'maxTokens' => 100, 'thinking' => 'low' ];
	    $gcred = new class('k') {
		public $baseUrl = 'https://gemini.invalid/v1beta/';
		public function __construct(public $apiKey) {
		}
		public function httpAuth() {
		    return [ 'headers' => [ 'x-goog-api-key' => $this->apiKey ], 'query' => [] ];
		}
	    };
	    $gtools = [ 'list_tables' => [ 'name' => 'list_tables', 'description' => 'tables', 'schema' => (object)[ 'type' => 'object', 'properties' => new \stdClass ] ] ];
	    $gem->answers = [
		'{"candidates":[{"content":{"role":"model","parts":[{"functionCall":{"name":"list_tables","args":{}},"thoughtSignature":"SIG"}]},"finishReason":"STOP"}],"usageMetadata":{"promptTokenCount":5,"candidatesTokenCount":2,"thoughtsTokenCount":3},"modelVersion":"gemini-test-001"}',
		'{"candidates":[{"content":{"role":"model","parts":[{"text":"thinking...","thought":true},{"text":"{\"n\":"},{"text":"1}"}]},"finishReason":"STOP"}],"usageMetadata":{"promptTokenCount":9,"candidatesTokenCount":4}}',
		'{"candidates":[{"finishReason":"SAFETY"}]}',
	    ];
	    $state = $call(\models\node\NodeAppGemini::class, 'startConversation', null, 'sys', 'hi', null);
	    $a = $gem->turn($gcred, $state, $gtools, true);
	    [ $url, $headers, $body ] = $gem->sent[0];
	    $this->check('Gemini: the request (URL, key, tools, thinking; JSON asked in the system prompt with tools)', [ 'https://gemini.invalid/v1beta/models/gemini-test:generateContent', 'k', 'list_tables',
		'low', 100, false, true ], [ $url, $headers['x-goog-api-key'] ?? null, $body['tools'][0]['functionDeclarations'][0]['name'] ?? null, $body['generationConfig']['thinkingConfig']['thinkingLevel'] ?? null,
		$body['generationConfig']['maxOutputTokens'] ?? null, isset($body['generationConfig']['responseMimeType']), str_contains($body['systemInstruction']['parts'][0]['text'] ?? '', 'JSON only') ]);
	    $this->check('Gemini: the call (our id, its arguments), the usage with the thoughts, the model', [ [ [ 'call_0', 'list_tables', [] ] ], [ 5, 5 ], 'gemini-test-001' ], [ $a['calls'], $a['usage'], $a['model'] ]);
	    $gem->results($state, [ [ 'call_0', '["t"]', false, [ $img ] ] ]);
	    $a = $gem->turn($gcred, $state, [], (object)[ 'type' => 'object' ]);
	    $body = $gem->sent[1][2];
	    $this->check('Gemini: the model\'s turn sent back with its signature, the result by name, the image after it', [ [ 'user', 'model', 'user' ], 'SIG', [ 'name' => 'list_tables', 'response' => [ 'result' => '["t"] [1 image(s): after the results]' ] ],
		'image/png' ], [ array_column($body['contents'], 'role'), $body['contents'][1]['parts'][0]['thoughtSignature'] ?? null, $body['contents'][2]['parts'][0]['functionResponse'] ?? null,
		$body['contents'][2]['parts'][2]['inlineData']['mimeType'] ?? null ]);
	    $this->check('Gemini: JSON without tools: structured output with the schema; the answer without the thoughts', [ 'application/json', [ 'type' => 'object' ], '{"n":1}' ],
		[ $body['generationConfig']['responseMimeType'] ?? null, $body['generationConfig']['responseJsonSchema'] ?? null, $a['text'] ]);
	    try {
		$gem->turn($gcred, $state, [], null);
		$blocked = 'not refused';
	    } catch (\ErrorException $e) {
		$blocked = $e->getMessage();
	    }
	    $this->check('Gemini: a blocked answer is an error', 'Gemini declined the answer (SAFETY)', $blocked);

	    //CLD: 2026-10-17T21:08:58+03:00: Download File: data: URLs, the image info of the variable
	    $e = [ self::class, 'edge' ];
	    $download = function($name, $url, $config = []) use ($n, $e, $om, &$files) {
		$exe = $this->runFlow('download ' . $name, [ $n('m', 'trigger::manual'), $n('d', 'app::download', [ 'url' => '{{ src }}', 'variable' => 'file', 'onError' => 'output' ] + $config),
		    $e('e1', 'm', 'd') ], [ 'src' => $url ]);
		$file = $exe?->getVariable('file');
		if (!empty($file['id']))
		    $files[] = $om->to_oid($file['id']);
		return [ $exe?->state, $file ];
	    };
	    [ $state, $file ] = $download('png', 'data:image/png;base64,' . base64_encode($png));
	    $this->check('Download: a data: URL image, its size read', [ 'end', 'download.png', 'image/png', true, 1, 1, 'image/png' ],
		[ $state, $file['filename'] ?? null, $file['contentType'] ?? null, $file['isImage'] ?? null, $file['width'] ?? null, $file['height'] ?? null, $file['imageType'] ?? null ]);
	    [ $state, $file ] = $download('named', 'data:application/octet-stream;base64,' . base64_encode($png), [ 'filename' => 'logo.png' ]);
	    $this->check('Download: an image by its content, not its type', [ 'logo.png', true, 'image/png' ], [ $file['filename'] ?? null, $file['isImage'] ?? null, $file['imageType'] ?? null ]);
	    [ $state, $file ] = $download('csv', 'data:text/csv,a%2Cb%0A1%2C2');
	    $this->check('Download: a data: URL text file, not an image', [ 'download.csv', 7, false, null ], [ $file['filename'] ?? null, $file['size'] ?? null, $file['isImage'] ?? null, array_key_exists('width', (array)$file) ? $file['width'] : 'missing' ]);
	    [ $state, $file ] = $download('bad', 'data:image/png;base64,***');
	    $this->check('Download: bad base64 -> the error output', [ 'end', null ], [ $state, $file ]);
	    $this->check('Download: SVG recognized, no size', [ true, null, 'image/svg+xml' ],
		array_values(array_intersect_key(\models\node\NodeAppDownload::imageInfo('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>'), [ 'isImage' => 1, 'width' => 1, 'imageType' => 1 ])));
	} finally {
	    foreach($files as $fid)
		$om->findOne('File', [ '_id' => $fid ])?->delete();
	}
    }

    //CLD: 2026-10-20T10:00:00+03:00: the Google Drive step: outputs, ids from links, settings refused, variable types, file objects; a run with a credential Google refuses
    //(a made-up token: the `error` output, whether Drive answers 401 or cannot be reached)
    private function suiteGdrive() {
	//CLD: 2026-10-22T00:35:40+03:00: the GoogleIntegration plugin's (skipped without it)
	if (!in_array('GoogleIntegration', (array)\V::app()->config('modules'), true)) {
	    echo "  skipped: the GoogleIntegration plugin is not enabled\n";
	    return;
	}
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$D = \plugins\GoogleIntegration\models\NodeAppGoogleDrive::class;
	$this->check('Google Drive: a step type, an `error` output when chosen', [ true, [ 'success', 'error' ], 1 ],
	    [ isset(\models\WfNode::getAll()['app::google-drive']), $D::outLabelsFor([ 'onError' => 'output' ]), $D::outputCountFor([]) ]);
	$this->check('Google Drive: ids from links', [ '1AbCdEfGhIjK_lm-no', '0BxFolder123456789', '1AbCdEfGhIjK_lm-no', '1DocId_abcdefghij', 'root' ], [
	    $D::driveId('https://drive.google.com/file/d/1AbCdEfGhIjK_lm-no/view?usp=sharing'), $D::driveId('https://drive.google.com/drive/folders/0BxFolder123456789'),
	    $D::driveId('https://drive.google.com/open?id=1AbCdEfGhIjK_lm-no'), $D::driveId('https://docs.google.com/document/d/1DocId_abcdefghij/edit'), $D::driveId(' root ') ]);
	$this->check('Google Drive: a text quoted in a query', "it\\'s a \\\\ b", $D::quote("it's a \\ b"));
	$this->check('Google Drive: the API\'s message in errors', [ 'HTTP status 404: File not found: x.', 'no answer' ],
	    [ $D::errorText(404, [ 'error' => [ 'message' => 'File not found: x.' ] ], 'HTTP status 404'), $D::errorText(0, null, '') ]);

	$folder = $D::fileInfo([ 'id' => 'f', 'name' => 'F', 'mimeType' => $D::FOLDER ]);
	$doc = $D::fileInfo([ 'id' => 'd', 'name' => 'D', 'mimeType' => 'application/vnd.google-apps.document', 'parents' => [ 'f' ], 'owners' => [ [ 'emailAddress' => 'a@b.ro' ] ] ]);
	$pdf = $D::fileInfo([ 'id' => 'p', 'name' => 'P.pdf', 'mimeType' => 'application/pdf', 'size' => '1234' ]);
	$this->check('Google Drive: file objects (folder, Google file, file with its size)', [ [ true, false, null ], [ false, true, [ 'f' ], 'a@b.ro' ], [ false, false, 1234 ] ],
	    [ [ $folder['isFolder'], $folder['isGoogleFile'], $folder['size'] ], [ $doc['isFolder'], $doc['isGoogleFile'], $doc['parents'], $doc['owner'] ], [ $pdf['isFolder'], $pdf['isGoogleFile'], $pdf['size'] ] ]);
	$this->check('Google Drive: the variable by operation (list: files, download: the project file + drive, delete)', [ 'array', 'object', true, [ 'id', 'permanent' ], true ], [
	    $D::outputVariables([ 'operation' => 'list' ])['drive']['type'] ?? null, $D::outputVariables([ 'operation' => 'get', 'variable' => 'f' ])['f']['type'] ?? null,
	    isset($D::outputVariables([ 'operation' => 'download' ])['drive']['fields']['drive']['fields']['webViewLink']),
	    array_keys($D::outputVariables([ 'operation' => 'delete' ])['drive']['fields'] ?? []), isset($D::outputVariables([ 'operation' => 'share' ])['drive']['fields']['permission']) ]);

	$om = \V::app()->om;
	$cred = $om->create('CredentialGoogleDrive', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: Google Drive ' . uniqid(), 'clientId' => 'x', 'clientSecret' => 'y' ]);
	$cred->access_token = 'made-up';
	$cred->refresh_token = 'made-up';
	$cred->expires_at = time() + 3600;
	if (!$cred->save()) {
	    $this->fail('Google Drive: the credential was not saved: ' . json_encode($cred->errors()));
	    return;
	}
	try {
	    $id = (string)$cred->_id;
	    $refused = function($name, $config) use ($n, $e, $id) {
		return implode(' | ', $this->errorsOf("gdrive {$name}", [ $n('m', 'trigger::manual'), $n('g', 'app::google-drive', $config + [ 'credential' => $id ]), $e('e1', 'm', 'g') ]));
	    };
	    $this->check('Google Drive: valid (list, defaults)', '', $refused('list', []));
	    $this->check('Google Drive refused: no credential', true, str_contains($this->errorsOf('gdrive nocred', [ $n('m', 'trigger::manual'), $n('g', 'app::google-drive', []), $e('e1', 'm', 'g') ])[0] ?? '', 'Google Drive credential'));
	    $this->check('Google Drive refused: get without a file', true, str_contains($refused('get', [ 'operation' => 'get' ]), 'File'));
	    $this->check('Google Drive refused: move without a folder', true, str_contains($refused('move', [ 'operation' => 'move', 'fileId' => 'abc' ]), 'Folder'));
	    $this->check('Google Drive refused: upload of a text without a name, of a file without the file', [ true, true ],
		[ str_contains($refused('upload text', [ 'operation' => 'upload', 'source' => 'text', 'content' => 'hi' ]), 'Name: required'),
		    str_contains($refused('upload file', [ 'operation' => 'upload', 'source' => 'file' ]), 'Project file: required') ]);
	    $this->check('Google Drive refused: share with a person without the address, with a domain without it', [ true, true ],
		[ str_contains($refused('share user', [ 'operation' => 'share', 'fileId' => 'abc' ]), 'Address: required'),
		    str_contains($refused('share domain', [ 'operation' => 'share', 'fileId' => 'abc', 'shareWith' => 'domain' ]), 'Domain: required') ]);
	    $this->check('Google Drive refused: an unknown operation', true, $refused('bad op', [ 'operation' => 'rename' ]) !== '');

	    $exe = $this->runFlow('gdrive refused token', [ $n('m', 'trigger::manual'), $n('g', 'app::google-drive', [ 'credential' => $id, 'operation' => 'get', 'fileId' => '{{ link }}',
		'onError' => 'output', 'timeout' => 15 ]), $n('ok', 'app::set', [ 'variable' => 'route', 'value' => 'success' ]), $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]),
		$e('e1', 'm', 'g'), $e('e2', 'g', 'ok', 0), $e('e3', 'g', 'err', 1) ], [ 'link' => 'https://drive.google.com/file/d/1AbCdEfGhIjK_lm-no/view' ]);
	    $this->check('Google Drive: a refused token -> the `error` output, the variable null, the error logged', [ 'end', 'error', null, true ],
		[ $exe?->state, $exe?->getVariable('route'), $exe?->getVariable('drive', 'unset'), self::logLine($exe, 'Google Drive get: ') !== null ]);
	} finally {
	    $cred->delete();
	}
    }

    //CLD: 2026-10-20T11:00:00+03:00: the Google Sheets step: rows to write (lists, objects, one row), objects matched to the header, rows read as objects, cells, ranges,
    //settings refused, variable types; a run with a credential Google refuses (the `error` output); a Drive credential is accepted
    private function suiteGsheets() {
	//CLD: 2026-10-22T00:35:40+03:00: the GoogleIntegration plugin's (skipped without it)
	if (!in_array('GoogleIntegration', (array)\V::app()->config('modules'), true)) {
	    echo "  skipped: the GoogleIntegration plugin is not enabled\n";
	    return;
	}
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$S = \plugins\GoogleIntegration\models\NodeAppGoogleSheets::class;
	$this->check('Google Sheets: a step type, an `error` output when chosen', [ true, [ 'success', 'error' ] ],
	    [ isset(\models\WfNode::getAll()['app::google-sheets']), $S::outLabelsFor([ 'onError' => 'output' ]) ]);
	$this->check('Google Sheets: column names', [ 'A', 'Z', 'AA', 'AZ', 'BA', 'ZZ', 'AAA' ], array_map([ $S, 'columnName' ], [ 0, 25, 26, 51, 52, 701, 702 ]));
	$this->check('Google Sheets: a sheet name quoted', "'Bob''s sheet'", $S::quoteSheet("Bob's sheet"));
	$this->check('Google Sheets: rows from JSON, a list of lists, one object, one row of cells', [ [ [ 'a', 1 ], [ 'b', 2 ] ], [ [ 'x' => 1 ] ], [ [ 'a', 'b' ] ], [] ],
	    [ $S::rowsOf('[["a", 1], ["b", 2]]'), $S::rowsOf([ 'x' => 1 ]), $S::rowsOf([ 'a', 'b' ]), $S::rowsOf('  ') ]);
	$refusedRows = function($value) use ($S) {
	    try {
		$S::rowsOf($value);
		return 'accepted';
	    } catch (\ErrorException $ex) {
		return 'refused';
	    }
	};
	$this->check('Google Sheets: rows refused (not JSON, a number, objects mixed with lists)', [ 'refused', 'refused', 'refused' ],
	    [ $refusedRows('a,b'), $refusedRows(5), $refusedRows([ [ 'a' => 1 ], [ 1, 2 ] ]) ]);
	$this->check('Google Sheets: objects matched to the header, new keys added as columns', [ [ 'Name', 'Email', 'Qty' ], [ [ 'Ana', 'a@x.ro', null ], [ null, 'b@x.ro', 3 ] ] ],
	    $S::matrixFor([ [ 'Email' => 'a@x.ro', 'Name' => 'Ana' ], [ 'Qty' => 3, 'Email' => 'b@x.ro' ] ], [ 'Name', 'Email' ], true));
	$this->check('Google Sheets: new keys left out when not added; an empty sheet gets the keys as header', [ [ [ 'Name' ], [ [ 'Ana' ] ] ], [ 'a', 'b' ] ],
	    [ $S::matrixFor([ [ 'Name' => 'Ana', 'X' => 1 ] ], [ 'Name' ], false), $S::matrixFor([ [ 'a' => 1, 'b' => 2 ] ], [], true)[0] ]);
	$this->check('Google Sheets: the header (empty / repeated names -> column names), rows as objects (missing cells, empty rows left out, extra cells)',
	    [ [ 'Name', 'B', 'Qty', 'D' ], [ [ 'Name' => 'Ana', 'B' => '', 'Qty' => '', 'D' => '' ], [ 'Name' => 'Bob', 'B' => 'x', 'Qty' => '2', 'D' => '', 'E' => 'extra' ] ] ],
	    [ $S::headerOf([ 'Name', '', 'Qty', 'Name' ]), $S::objectsOf([ [ 'Ana' ], [ '', '' ], [ 'Bob', 'x', '2', '', 'extra' ] ], [ 'Name', 'B', 'Qty', 'D' ]) ]);
	$this->check('Google Sheets: cell values (numbers, booleans kept; null empty; objects as JSON)', [ 1, 2.5, true, '', '{"a":1}', 'x' ],
	    array_map([ $S, 'cellValue' ], [ 1, 2.5, true, null, [ 'a' => 1 ], 'x' ]));
	$this->check('Google Sheets: the variable by operation', [ 'rows', 'cells', 'range', 'sheets', 'rowCount' ], [
	    array_keys($S::outputVariables([ 'operation' => 'read' ])['sheet']['fields'])[3] ?? null, array_keys($S::outputVariables([ 'operation' => 'append' ])['sheet']['fields'])[3] ?? null,
	    array_keys($S::outputVariables([ 'operation' => 'clear' ])['sheet']['fields'])[0] ?? null, array_keys($S::outputVariables([ 'operation' => 'info' ])['sheet']['fields'])[3] ?? null,
	    array_keys($S::outputVariables([ 'operation' => 'addSheet', 'variable' => 's' ])['s']['fields'])[3] ?? null ]);

	$om = \V::app()->om;
	$creds = [];
	foreach([ 'CredentialGoogleSheets', 'CredentialGoogleDrive' ] as $variant) {
	    $c = $om->create($variant, [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => "Check: {$variant} " . uniqid(), 'clientId' => 'x', 'clientSecret' => 'y' ]);
	    $c->access_token = 'made-up';
	    $c->refresh_token = 'made-up';
	    $c->expires_at = time() + 3600;
	    if (!$c->save()) {
		$this->fail("Google Sheets: the credential {$variant} was not saved: " . json_encode($c->errors()));
		foreach($creds as $saved)
		    $saved->delete();
		return;
	    }
	    $creds[] = $c;
	}
	try {
	    [ $sheetsId, $driveId ] = array_map(fn($c) => (string)$c->_id, $creds);
	    $refused = function($name, $config, $cred = null) use ($n, $e, $sheetsId) {
		return implode(' | ', $this->errorsOf("gsheets {$name}", [ $n('m', 'trigger::manual'), $n('g', 'app::google-sheets', $config + [ 'credential' => $cred ?? $sheetsId ]), $e('e1', 'm', 'g') ]));
	    };
	    $this->check('Google Sheets: valid (read; a Drive credential too)', [ '', '' ], [ $refused('read', [ 'spreadsheetId' => 'abc' ]), $refused('read drive', [ 'spreadsheetId' => 'abc' ], $driveId) ]);
	    $this->check('Google Sheets refused: no spreadsheet, append without rows, create without a title, add a sheet without its name, bad cells', [ true, true, true, true, true ], [
		str_contains($refused('nospread', []), 'Spreadsheet'), str_contains($refused('append', [ 'operation' => 'append', 'spreadsheetId' => 'abc' ]), 'Rows'),
		str_contains($refused('create', [ 'operation' => 'create' ]), 'Title'), str_contains($refused('addSheet', [ 'operation' => 'addSheet', 'spreadsheetId' => 'abc' ]), 'Sheet'),
		str_contains($refused('cells', [ 'spreadsheetId' => 'abc', 'range' => 'A1:B2:C3' ]), 'Cells') ]);
	    $this->check('Google Sheets refused: no credential', true, str_contains($this->errorsOf('gsheets nocred', [ $n('m', 'trigger::manual'), $n('g', 'app::google-sheets', [ 'spreadsheetId' => 'abc' ]), $e('e1', 'm', 'g') ])[0] ?? '', 'Google credential'));

	    $exe = $this->runFlow('gsheets refused token', [ $n('m', 'trigger::manual'), $n('g', 'app::google-sheets', [ 'credential' => $sheetsId, 'operation' => 'read', 'spreadsheetId' => '{{ link }}',
		'sheet' => 'Orders', 'range' => 'A1:C10', 'onError' => 'output', 'timeout' => 15 ]), $n('ok', 'app::set', [ 'variable' => 'route', 'value' => 'success' ]),
		$n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]), $e('e1', 'm', 'g'), $e('e2', 'g', 'ok', 0), $e('e3', 'g', 'err', 1) ],
		[ 'link' => 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjK_lm-no/edit#gid=0' ]);
	    $this->check('Google Sheets: a refused token -> the `error` output, the variable null, the error logged', [ 'end', 'error', null, true ],
		[ $exe?->state, $exe?->getVariable('route'), $exe?->getVariable('sheet', 'unset'), self::logLine($exe, 'Google Sheets read: ') !== null ]);
	} finally {
	    foreach($creds as $c)
		$c->delete();
	}
    }

    //CLD: 2026-10-20T12:00:00+03:00: the Gmail step: the message composed (read back by util\MailParser: headers, reply headers, Bcc, text + HTML, an attachment), addresses, file
    //ids, labels, the query string, settings refused, variable types; a run with a credential Google refuses (the `error` output)
    private function suiteGmail() {
	//CLD: 2026-10-22T00:35:40+03:00: the GoogleIntegration plugin's (skipped without it)
	if (!in_array('GoogleIntegration', (array)\V::app()->config('modules'), true)) {
	    echo "  skipped: the GoogleIntegration plugin is not enabled\n";
	    return;
	}
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$G = \plugins\GoogleIntegration\models\NodeAppGmail::class;
	$om = \V::app()->om;
	$this->check('Gmail: a step type, an `error` output when chosen; the Google Mail credential provides it (and still IMAP)', [ true, [ 'success', 'error' ], [ 'imap', 'gmail' ] ],
	    [ isset(\models\WfNode::getAll()['app::gmail']), $G::outLabelsFor([ 'onError' => 'output' ]), \plugins\GoogleIntegration\models\CredentialGoogleMail::$provides ]);
	$this->check('Gmail: addresses (text, names, semicolons, a list, objects; doubles once)', [ 'a@x.ro', 'b@y.com', 'c@z.org' ],
	    $G::addresses('a@x.ro; "B, Jr." <b@y.com>, a@x.ro;c@z.org')) && $this->check('Gmail: addresses from a list', [ 'a@x.ro', 'c@z.org' ], $G::addresses([ 'a@x.ro', [ 'email' => 'c@z.org' ] ]));
	$bad = function($value) use ($G) {
	    try {
		$G::addresses($value);
		return 'accepted';
	    } catch (\RuntimeException $ex) {
		return 'refused';
	    }
	};
	$this->check('Gmail: addresses refused (no @, a bad address)', [ 'refused', 'refused', 'accepted' ], [ $bad('nobody'), $bad('a@@x'), $bad('') ]);
	$this->check('Gmail: file ids (one, commas, a list, file objects)', [ [ 'a' ], [ 'a', 'b' ], [ 'a', 'b' ], [ 'f1' ] ],
	    [ $G::fileIds('a'), $G::fileIds(' a, b ,a'), $G::fileIds([ 'a', 'b' ]), $G::fileIds([ 'id' => 'f1', 'filename' => 'x.pdf' ]) ]);
	$this->check('Gmail: the query string (lists repeated)', '?q=from%3Aa%20b&labelIds=INBOX&labelIds=Label_1', $G::queryString([ 'q' => 'from:a b', 'labelIds' => [ 'INBOX', 'Label_1' ] ]));
	$this->check('Gmail: HTML to text (links, paragraphs, scripts out)', "Hi Ana\n\nSee site (https://x.ro) & more", $G::htmlToText('<style>p{}</style><p>Hi <b>Ana</b></p><p>See <a href="https://x.ro">site</a> &amp; more</p>'));

	$fid = \models\File::createFromString('raport ș.csv', "a,b\n1,2\n", [ 'metadata' => [ 'project' => $this->project->_id, 'aid' => $this->account->_id, 'source' => 'check' ] ]);
	try {
	    $raw = $G::compose([ 'from' => 'me@gmail.com', 'from_name' => 'Ana Pop', 'to' => [ 'b@y.com' ], 'cc' => [ 'c@z.org' ], 'bcc' => [ 'd@w.net' ], 'subject' => "Re: Factură\r\nBcc: evil@x",
		'text' => 'Bună', 'html' => '<p>Bună</p>', 'in_reply_to' => '<m1@x>', 'references' => '<m0@x> <m1@x>', 'attachments' => [ $om->findOne('File', [ '_id' => $fid ]) ] ]);
	    $p = new \util\MailParser($raw);
	    $att = $p->attachments()[0] ?? [];
	    $this->check('Gmail: the message read back (from, to, cc, bcc, subject without the injected header, reply headers, text, HTML, the attachment)',
		[ 'me@gmail.com', 'Ana Pop', [ 'b@y.com' ], [ 'c@z.org' ], [ 'd@w.net' ], 'Re: Factură Bcc: evil@x', 'm1@x', 'Bună', true, 'raport ș.csv', "a,b\n1,2\n" ],
		[ $p->from()['email'] ?? null, $p->from()['name'] ?? null, array_column($p->to(), 'email'), array_column($p->cc(), 'email'), array_column($p->bcc(), 'email'), $p->subject(),
		    $p->inReplyTo(), trim($p->text()), str_contains($p->html(), '<p>Bună</p>'), $att['filename'] ?? null, $att['content'] ?? null ]);
	    $plain = new \util\MailParser($G::compose([ 'from' => '', 'to' => [ 'b@y.com' ], 'subject' => 'Hi', 'text' => "line 1\nline 2" ]));
	    $this->check('Gmail: a text message (no From: Gmail sets it; no HTML, no attachments)', [ null, "line 1\nline 2", '', 0 ],
		[ $plain->from()['email'] ?? null, trim($plain->text()), (string)$plain->html(), count($plain->attachments()) ]);
	} finally {
	    $om->findOne('File', [ '_id' => $fid ])?->delete();
	}
	$this->check('Gmail: the variable by operation', [ 'array', [ 'id', 'thread_id', 'labels', 'snippet', 'account' ], 'draft_id', 'array', false ], [
	    $G::outputVariables([ 'operation' => 'search' ])['gmail']['type'] ?? null, array_slice(array_keys($G::outputVariables([ 'operation' => 'get' ])['gmail']['fields'] ?? []), 0, 5),
	    array_keys($G::outputVariables([ 'operation' => 'draft' ])['gmail']['fields'] ?? [])[0] ?? null, $G::outputVariables([ 'operation' => 'labels' ])['gmail']['type'] ?? null,
	    isset($G::outputVariables([ 'operation' => 'get' ])['gmail']['fields']['uid']) ]);

	$cred = $om->create('CredentialGoogleMail', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: Gmail ' . uniqid(), 'clientId' => 'x', 'clientSecret' => 'y' ]);
	$cred->access_token = 'made-up';
	$cred->refresh_token = 'made-up';
	$cred->expires_at = time() + 3600;
	if (!$cred->save()) {
	    $this->fail('Gmail: the credential was not saved: ' . json_encode($cred->errors()));
	    return;
	}
	try {
	    $id = (string)$cred->_id;
	    $refused = fn($name, $config) => implode(' | ', $this->errorsOf("gmail {$name}", [ $n('m', 'trigger::manual'), $n('g', 'app::gmail', $config + [ 'credential' => $id ]), $e('e1', 'm', 'g') ]));
	    $this->check('Gmail: valid (send; a reply needs no recipient or subject; a draft no recipient; search)', [ '', '', '', '' ], [
		$refused('send', [ 'to' => 'a@x.ro', 'subject' => 'Hi' ]), $refused('reply', [ 'replyTo' => '{{ msg.id }}' ]), $refused('draft', [ 'operation' => 'draft', 'subject' => 'Hi' ]),
		$refused('search', [ 'operation' => 'search' ]) ]);
	    $this->check('Gmail refused: send without recipients / subject, get without a message, modify changing nothing', [ true, true, true, true ], [
		str_contains($refused('noto', [ 'subject' => 'Hi' ]), 'To: required'), str_contains($refused('nosubject', [ 'to' => 'a@x.ro' ]), 'Subject: required'),
		str_contains($refused('get', [ 'operation' => 'get' ]), 'Message'), str_contains($refused('modify', [ 'operation' => 'modify', 'messageId' => 'abc' ]), 'choose what to change') ]);
	    $this->check('Gmail refused: no credential', true,
		str_contains($this->errorsOf('gmail nocred', [ $n('m', 'trigger::manual'), $n('g', 'app::gmail', [ 'operation' => 'labels' ]), $e('e1', 'm', 'g') ])[0] ?? '', 'Google Mail credential'));

	    $exe = $this->runFlow('gmail refused token', [ $n('m', 'trigger::manual'), $n('g', 'app::gmail', [ 'credential' => $id, 'operation' => 'search', 'query' => 'from:{{ who }}',
		'onError' => 'output', 'timeout' => 15 ]), $n('ok', 'app::set', [ 'variable' => 'route', 'value' => 'success' ]), $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]),
		$e('e1', 'm', 'g'), $e('e2', 'g', 'ok', 0), $e('e3', 'g', 'err', 1) ], [ 'who' => 'ana@x.ro' ]);
	    $this->check('Gmail: a refused token -> the `error` output, the variable null, the error logged', [ 'end', 'error', null, true ],
		[ $exe?->state, $exe?->getVariable('route'), $exe?->getVariable('gmail', 'unset'), self::logLine($exe, 'Gmail search: ') !== null ]);
	} finally {
	    $cred->delete();
	}
    }

    //CLD: 2026-10-20T13:00:00+03:00: the Google Calendar step: times read (offsets, the time zone, dates), event start / end (duration, all day), periods, event objects, settings
    //refused, variable types; a run with a credential Google refuses (the `error` output)
    private function suiteGcalendar() {
	//CLD: 2026-10-22T00:35:40+03:00: the GoogleIntegration plugin's (skipped without it)
	if (!in_array('GoogleIntegration', (array)\V::app()->config('modules'), true)) {
	    echo "  skipped: the GoogleIntegration plugin is not enabled\n";
	    return;
	}
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$C = \plugins\GoogleIntegration\models\NodeAppGoogleCalendar::class;
	$tz = new \DateTimeZone('Europe/Bucharest');
	$this->check('Google Calendar: a step type, an `error` output when chosen', [ true, [ 'success', 'error' ] ],
	    [ isset(\models\WfNode::getAll()['app::google-calendar']), $C::outLabelsFor([ 'onError' => 'output' ]) ]);
	$this->check('Google Calendar: times read (local, with an offset kept as the instant, ISO)', [ '2026-10-21T14:00:00+03:00', '2026-10-21T15:00:00+03:00', '2026-12-01T09:30:00+02:00' ],
	    [ $C::parseTime('2026-10-21 14:00', $tz)->format(DATE_RFC3339), $C::parseTime('2026-10-21T12:00:00Z', $tz)->format(DATE_RFC3339), $C::parseTime('2026-12-01T09:30', $tz)->format(DATE_RFC3339) ]);
	$refused = function(\Closure $f) {
	    try {
		$f();
		return 'accepted';
	    } catch (\RuntimeException $ex) {
		return 'refused';
	    }
	};
	$this->check('Google Calendar: times refused (words, empty), an end before the start, a period backwards', [ 'refused', 'refused', 'refused', 'refused' ], [
	    $refused(fn() => $C::parseTime('next blursday', $tz)), $refused(fn() => $C::parseTime('', $tz)),
	    $refused(fn() => $C::times('2026-10-21 14:00', '2026-10-21 13:00', false, 60, $tz)), $refused(fn() => $C::period('2026-10-21', '2026-10-20', $tz)) ]);
	$this->check('Google Calendar: start + the duration; an end given', [
	    [ [ 'dateTime' => '2026-10-21T14:00:00+03:00', 'timeZone' => 'Europe/Bucharest' ], [ 'dateTime' => '2026-10-21T15:30:00+03:00', 'timeZone' => 'Europe/Bucharest' ] ],
	    '2026-10-21T16:00:00+03:00' ],
	    [ $C::times('2026-10-21 14:00', '', false, 90, $tz), $C::times('2026-10-21 14:00', '2026-10-21 16:00', false, 60, $tz)[1]['dateTime'] ]);
	$this->check('Google Calendar: all day (one day; the end day included)', [ [ [ 'date' => '2026-10-21' ], [ 'date' => '2026-10-22' ] ], [ [ 'date' => '2026-10-21' ], [ 'date' => '2026-10-24' ] ] ],
	    [ $C::times('2026-10-21', '', true, 60, $tz), $C::times('2026-10-21', '2026-10-23', true, 60, $tz) ]);
	[ $from, $until ] = $C::period('', '', $tz);
	$this->check('Google Calendar: the default period (now, 7 days)', [ true, 7 ], [ abs($from->getTimestamp() - time()) < 5, (int)$from->diff($until)->days ]);
	$ev = $C::eventInfo([ 'id' => 'ev1', 'summary' => 'Demo', 'start' => [ 'dateTime' => '2026-10-21T14:00:00+03:00', 'timeZone' => 'Europe/Bucharest' ], 'end' => [ 'dateTime' => '2026-10-21T15:00:00+03:00' ],
	    'conferenceData' => [ 'entryPoints' => [ [ 'entryPointType' => 'phone', 'uri' => 'tel:1' ], [ 'entryPointType' => 'video', 'uri' => 'https://meet.google.com/abc' ] ] ],
	    'attendees' => [ [ 'email' => 'a@x.ro', 'responseStatus' => 'accepted', 'organizer' => true ] ], 'organizer' => [ 'email' => 'a@x.ro' ] ]);
	$day = $C::eventInfo([ 'id' => 'ev2', 'start' => [ 'date' => '2026-10-21' ], 'end' => [ 'date' => '2026-10-22' ] ]);
	$this->check('Google Calendar: event objects (times, Meet link, guests; all day)', [ [ '2026-10-21T14:00:00+03:00', false, 'https://meet.google.com/abc', 'accepted', 'a@x.ro' ], [ '2026-10-21', true, null ] ],
	    [ [ $ev['start'], $ev['allDay'], $ev['meetLink'], $ev['attendees'][0]['response'] ?? null, $ev['organizer'] ], [ $day['start'], $day['allDay'], $day['meetLink'] ] ]);
	$this->check('Google Calendar: the variable by operation', [ 'array', 'meetLink', 'busy', 'array', [ 'id' ] ], [
	    $C::outputVariables([ 'operation' => 'list' ])['calendar']['type'] ?? null, array_keys($C::outputVariables([ 'operation' => 'create' ])['calendar']['fields'] ?? [])[10] ?? null,
	    array_keys($C::outputVariables([ 'operation' => 'freeBusy' ])['calendar']['fields'] ?? [])[2] ?? null, $C::outputVariables([ 'operation' => 'calendars' ])['calendar']['type'] ?? null,
	    array_keys($C::outputVariables([ 'operation' => 'delete' ])['calendar']['fields'] ?? []) ]);

	$om = \V::app()->om;
	$cred = $om->create('CredentialGoogleCalendar', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: Google Calendar ' . uniqid(), 'clientId' => 'x', 'clientSecret' => 'y' ]);
	$cred->access_token = 'made-up';
	$cred->refresh_token = 'made-up';
	$cred->expires_at = time() + 3600;
	if (!$cred->save()) {
	    $this->fail('Google Calendar: the credential was not saved: ' . json_encode($cred->errors()));
	    return;
	}
	try {
	    $id = (string)$cred->_id;
	    $errors = fn($name, $config) => implode(' | ', $this->errorsOf("gcalendar {$name}", [ $n('m', 'trigger::manual'), $n('g', 'app::google-calendar', $config + [ 'credential' => $id ]), $e('e1', 'm', 'g') ]));
	    $this->check('Google Calendar: valid (list; create; update of the title)', [ '', '', '' ], [ $errors('list', []),
		$errors('create', [ 'operation' => 'create', 'summary' => 'Hi', 'start' => '{{ when }}' ]), $errors('update', [ 'operation' => 'update', 'eventId' => 'ev1', 'summary' => 'New' ]) ]);
	    $this->check('Google Calendar refused: create without title / start, update changing nothing, an unknown time zone, a bad RRULE, quick add without a sentence', [ true, true, true, true, true, true ], [
		str_contains($errors('create t', [ 'operation' => 'create', 'start' => '2026-10-21' ]), 'Title'), str_contains($errors('create s', [ 'operation' => 'create', 'summary' => 'x' ]), 'Start'),
		str_contains($errors('update', [ 'operation' => 'update', 'eventId' => 'ev1' ]), 'at least one setting'), str_contains($errors('tz', [ 'timeZone' => 'Mars/Olympus' ]), 'Time zone'),
		str_contains($errors('rrule', [ 'operation' => 'create', 'summary' => 'x', 'start' => '2026-10-21', 'recurrence' => 'weekly' ]), 'Repeat'),
		str_contains($errors('quick', [ 'operation' => 'quickAdd' ]), 'Sentence') ]);
	    $this->check('Google Calendar refused: no credential', true,
		str_contains($this->errorsOf('gcalendar nocred', [ $n('m', 'trigger::manual'), $n('g', 'app::google-calendar', []), $e('e1', 'm', 'g') ])[0] ?? '', 'Google Calendar credential'));

	    $flow = fn($config) => [ $n('m', 'trigger::manual'), $n('g', 'app::google-calendar', $config + [ 'credential' => $id, 'onError' => 'output', 'timeout' => 15 ]),
		$n('ok', 'app::set', [ 'variable' => 'route', 'value' => 'success' ]), $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]),
		$e('e1', 'm', 'g'), $e('e2', 'g', 'ok', 0), $e('e3', 'g', 'err', 1) ];
	    $exe = $this->runFlow('gcalendar refused token', $flow([ 'operation' => 'list', 'search' => '{{ q }}' ]), [ 'q' => 'demo' ]);
	    $this->check('Google Calendar: a refused token -> the `error` output, the variable null, the error logged', [ 'end', 'error', null, true ],
		[ $exe?->state, $exe?->getVariable('route'), $exe?->getVariable('calendar', 'unset'), self::logLine($exe, 'Google Calendar list: ') !== null ]);
	    $exe = $this->runFlow('gcalendar bad time', $flow([ 'operation' => 'create', 'summary' => 'x', 'start' => '{{ when }}' ]), [ 'when' => 'someday' ]);
	    $this->check('Google Calendar: a time that cannot be read -> the `error` output, before any call', [ 'error', true ],
		[ $exe?->getVariable('route'), str_contains((string)self::logLine($exe, 'Google Calendar create: '), 'not a time: `someday`') ]);
	} finally {
	    $cred->delete();
	}
    }

    //CLD: 2026-10-20T15:00:00+03:00: the Google Docs step: Markdown -> text, paragraphs and spans (UTF-16 indexes), the batchUpdate requests (append / an empty document),
    //a document read as text and Markdown, settings refused, variable types; a run with a credential Google refuses (the `error` output); the Google steps share NodeAppGoogle
    private function suiteGdocs() {
	//CLD: 2026-10-22T00:35:40+03:00: the GoogleIntegration plugin's (skipped without it)
	if (!in_array('GoogleIntegration', (array)\V::app()->config('modules'), true)) {
	    echo "  skipped: the GoogleIntegration plugin is not enabled\n";
	    return;
	}
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$D = \plugins\GoogleIntegration\models\NodeAppGoogleDocs::class;
	$this->check('Google Docs: a step type, an `error` output when chosen; every Google step is a NodeAppGoogle', [ true, [ 'success', 'error' ], [ true, true, true, true, true ] ],
	    [ isset(\models\WfNode::getAll()['app::google-docs']), $D::outLabelsFor([ 'onError' => 'output' ]), array_map(fn($c) => is_subclass_of($c, \plugins\GoogleIntegration\models\NodeAppGoogle::class),
		[ $D, \plugins\GoogleIntegration\models\NodeAppGoogleDrive::class, \plugins\GoogleIntegration\models\NodeAppGoogleSheets::class, \plugins\GoogleIntegration\models\NodeAppGmail::class, \plugins\GoogleIntegration\models\NodeAppGoogleCalendar::class ]) ]);
	$this->check('Google Docs: lengths in UTF-16 (ă: 1, an emoji: 2)', [ 1, 2, 5 ], [ $D::len('ă'), $D::len('😀'), $D::len('a😀bc') ]);
	$this->check('Google Docs: inline Markdown (bold, italic, code, a link; snake_case left alone)', [ 'Hi Ana, see site and x a_b_c', [ [ 3, 6, [ 'bold' => true ] ], [ 8, 11, [ 'italic' => true ] ],
	    [ 12, 16, [ 'link' => [ 'url' => 'https://x.ro' ] ] ], [ 21, 22, [ 'weightedFontFamily' => [ 'fontFamily' => 'Courier New' ] ] ] ] ],
	    $D::inline('Hi **Ana**, *see* [site](https://x.ro) and `x` a_b_c'));
	[ $text, $paragraphs, $spans ] = $D::fromMarkdown("# Raport 😀\nText **ăla**\n- one\n  - two\n1. first");
	$this->check('Google Docs: Markdown paragraphs (heading, text, bullets nested by a tab, numbered) and spans after an emoji line', [
	    "Raport 😀\nText ăla\none\n\ttwo\nfirst", [ [ 0, 9, 'HEADING_1' ], [ 10, 18, 'normal' ], [ 19, 22, 'bullet' ], [ 23, 27, 'bullet' ], [ 28, 33, 'numbered' ] ], [ [ 15, 18, [ 'bold' => true ] ] ] ],
	    [ $text, $paragraphs, $spans ]);
	$req = $D::requests("# T\n- a\n- b\n**x**", 41, true, true);
	$kinds = array_map(fn($r) => array_key_first($r), $req);
	$this->check('Google Docs: requests appending (a new paragraph after index 41: insert, reset, heading, bold, one list of 2 items)', [
	    [ 'insertText', 'updateParagraphStyle', 'deleteParagraphBullets', 'updateTextStyle', 'updateParagraphStyle', 'updateTextStyle', 'createParagraphBullets' ],
	    "\nT\na\nb\nx", [ 42, 44 ], [ 48, 49 ], [ 44, 48, 'BULLET_DISC_CIRCLE_SQUARE' ] ],
	    [ $kinds, $req[0]['insertText']['text'], array_values($req[4]['updateParagraphStyle']['range']), array_values($req[5]['updateTextStyle']['range']),
		array_values($req[6]['createParagraphBullets']['range'] + [ 'p' => $req[6]['createParagraphBullets']['bulletPreset'] ]) ]);
	$plain = $D::requests('plain *text*', 1, false, false);
	$this->check('Google Docs: requests into an empty document, plain text (no Markdown)', [ 'plain *text*', 1, [ 1, 13 ] ],
	    [ $plain[0]['insertText']['text'], $plain[0]['insertText']['location']['index'], array_values($plain[3]['updateTextStyle']['range']) ]);

	$run = fn($t, $style = []) => [ 'textRun' => [ 'content' => $t, 'textStyle' => $style ] ];
	$doc = [ 'title' => 'D', 'lists' => [ 'L1' => [ 'listProperties' => [ 'nestingLevels' => [ [ 'glyphSymbol' => '●' ] ] ] ], 'L2' => [ 'listProperties' => [ 'nestingLevels' => [ [ 'glyphType' => 'DECIMAL' ] ] ] ] ],
	    'body' => [ 'content' => [ [ 'sectionBreak' => [] ],
		[ 'paragraph' => [ 'elements' => [ $run("Title\n") ], 'paragraphStyle' => [ 'namedStyleType' => 'HEADING_2' ] ] ],
		[ 'paragraph' => [ 'elements' => [ $run('Hi '), $run('Ana', [ 'bold' => true ]), $run(' see '), $run('site', [ 'link' => [ 'url' => 'https://x.ro' ] ]), $run("\n") ], 'paragraphStyle' => [ 'namedStyleType' => 'NORMAL_TEXT' ] ] ],
		[ 'paragraph' => [ 'elements' => [ $run("dot\n") ], 'bullet' => [ 'listId' => 'L1' ] ] ],
		[ 'paragraph' => [ 'elements' => [ $run("num\n") ], 'bullet' => [ 'listId' => 'L2' ] ] ],
		[ 'table' => [ 'tableRows' => [ [ 'tableCells' => [ [ 'content' => [ [ 'paragraph' => [ 'elements' => [ $run("A\n") ] ] ] ] ], [ 'content' => [ [ 'paragraph' => [ 'elements' => [ $run("B|\n") ] ] ] ] ] ] ],
		    [ 'tableCells' => [ [ 'content' => [ [ 'paragraph' => [ 'elements' => [ $run("1\n") ] ] ] ] ], [ 'content' => [ [ 'paragraph' => [ 'elements' => [ $run("2\n") ] ] ] ] ] ] ] ] ] ] ] ] ];
	$this->check('Google Docs: a document read as text and as Markdown', [ "Title\nHi Ana see site\ndot\nnum\nA\tB|\n1\t2\n", "## Title\nHi **Ana** see [site](https://x.ro)\n- dot\n1. num\n| A | B\\| |\n| --- | --- |\n| 1 | 2 |\n" ],
	    [ $D::plainText($doc['body']['content']), $D::markdown($doc) ]);
	$this->check('Google Docs: the variable by operation', [ [ 'id', 'title', 'url', 'text', 'markdown', 'revisionId' ], 'replaced', [ 'id', 'title', 'url' ] ], [
	    array_keys($D::outputVariables([ 'operation' => 'read' ])['doc']['fields'] ?? []), array_keys($D::outputVariables([ 'operation' => 'replace' ])['doc']['fields'] ?? [])[2] ?? null,
	    array_keys($D::outputVariables([ 'operation' => 'copy', 'variable' => 'd' ])['d']['fields'] ?? []) ]);

	$om = \V::app()->om;
	$cred = $om->create('CredentialGoogleDocs', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: Google Docs ' . uniqid(), 'clientId' => 'x', 'clientSecret' => 'y' ]);
	$cred->access_token = 'made-up';
	$cred->refresh_token = 'made-up';
	$cred->expires_at = time() + 3600;
	if (!$cred->save()) {
	    $this->fail('Google Docs: the credential was not saved: ' . json_encode($cred->errors()));
	    return;
	}
	try {
	    $id = (string)$cred->_id;
	    $errors = fn($name, $config) => implode(' | ', $this->errorsOf("gdocs {$name}", [ $n('m', 'trigger::manual'), $n('g', 'app::google-docs', $config + [ 'credential' => $id ]), $e('e1', 'm', 'g') ]));
	    $this->check('Google Docs: valid (read; replace by rows; replace by placeholders; create)', [ '', '', '', '' ], [ $errors('read', [ 'documentId' => 'abc' ]),
		$errors('replace rows', [ 'operation' => 'replace', 'documentId' => 'abc', 'replacements' => [ [ 'find' => '{{name}}', 'replace' => '{{ client.name }}' ] ] ]),
		$errors('replace object', [ 'operation' => 'replace', 'documentId' => 'abc', 'placeholders' => '{{ row }}' ]), $errors('create', [ 'operation' => 'create', 'title' => 'T' ]) ]);
	    $this->check('Google Docs refused: no document, create without a title, append without content, replace without anything, a replacement without Find', [ true, true, true, true, true ], [
		str_contains($errors('nodoc', []), 'Document'), str_contains($errors('create', [ 'operation' => 'create' ]), 'Title'),
		str_contains($errors('append', [ 'operation' => 'append', 'documentId' => 'abc' ]), 'Content'), str_contains($errors('replace', [ 'operation' => 'replace', 'documentId' => 'abc' ]), 'add a replacement'),
		str_contains($errors('replace row', [ 'operation' => 'replace', 'documentId' => 'abc', 'replacements' => [ [ 'replace' => 'x' ] ] ]), 'Find') ]);
	    $this->check('Google Docs refused: no credential (the base class\'s check, the step\'s label)', true,
		str_contains($this->errorsOf('gdocs nocred', [ $n('m', 'trigger::manual'), $n('g', 'app::google-docs', [ 'documentId' => 'abc' ]), $e('e1', 'm', 'g') ])[0] ?? '', 'Google credential'));

	    $exe = $this->runFlow('gdocs refused token', [ $n('m', 'trigger::manual'), $n('g', 'app::google-docs', [ 'credential' => $id, 'operation' => 'read', 'documentId' => '{{ link }}',
		'onError' => 'output', 'timeout' => 15 ]), $n('ok', 'app::set', [ 'variable' => 'route', 'value' => 'success' ]), $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]),
		$e('e1', 'm', 'g'), $e('e2', 'g', 'ok', 0), $e('e3', 'g', 'err', 1) ], [ 'link' => 'https://docs.google.com/document/d/1AbCdEfGhIjK_lm-no/edit' ]);
	    $this->check('Google Docs: a refused token -> the `error` output, the variable null, the error logged', [ 'end', 'error', null, true ],
		[ $exe?->state, $exe?->getVariable('route'), $exe?->getVariable('doc', 'unset'), self::logLine($exe, 'Google Docs read: ') !== null ]);
	} finally {
	    $cred->delete();
	}
    }

    //CLD: 2026-10-22T00:41:28+03:00: the MicrosoftIntegration plugin (skipped without it): the steps and credentials registered, the helpers (Graph queries and errors, addresses, ids, drives
    //and items, times, cells), the settings refused, and a call of each step with a made-up token (Graph refuses it: the `error` output, the variable null, the error logged)
    private function suiteMicrosoft() {
	if (!in_array('MicrosoftIntegration', (array)\V::app()->config('modules'), true)) {
	    echo "  skipped: the MicrosoftIntegration plugin is not enabled\n";
	    return;
	}
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$om = \V::app()->om;
	$ns = '\\plugins\\MicrosoftIntegration\\models\\';
	$M = $ns . 'NodeAppMicrosoft';
	$mail = $ns . 'NodeAppOutlookMail';
	$cal = $ns . 'NodeAppOutlookCalendar';
	$drive = $ns . 'NodeAppOneDrive';
	$xl = $ns . 'NodeAppExcel';
	$teams = $ns . 'NodeAppTeams';
	$all = \models\WfNode::getAll();
	$this->check('Microsoft: the step types, an `error` output when chosen', [ true, true, true, true, true, [ 'success', 'error' ] ],
	    [ isset($all['app::outlook-mail']), isset($all['app::outlook-calendar']), isset($all['app::onedrive']), isset($all['app::excel']), isset($all['app::teams']),
		$mail::outLabelsFor([ 'onError' => 'output' ]) ]);
	$this->check('Microsoft: the credentials and what they provide (Microsoft 365 Mail: IMAP, still)', [ [ 'imap' ], [ 'msmail', 'http' ], [ 'mscalendar', 'http' ],
	    [ 'msfiles', 'msexcel', 'http' ], [ 'msteams', 'http' ] ], array_map(fn($c) => \models\Credential::classFor($c)::$provides ?? null,
	    [ 'CredentialMicrosoftMail', 'CredentialMicrosoftOutlook', 'CredentialMicrosoftCalendar', 'CredentialMicrosoftFiles', 'CredentialMicrosoftTeams' ]));
	$this->check('Microsoft: the Graph scopes (User.Read + the permissions, on graph.microsoft.com)', 'https://graph.microsoft.com/User.Read https://graph.microsoft.com/Mail.ReadWrite https://graph.microsoft.com/Mail.Send',
	    ($ns . 'CredentialMicrosoftOutlook')::serviceScope());
	$this->check('Microsoft: every step has its settings and a variable for each operation', [ true, true, true, true, true ], array_map(function($c) {
	    $keys = array_column($c::configSchema(), 'key');
	    foreach(array_keys($c::OPERATIONS) as $op) {
		if (empty($c::outputVariables([ 'operation' => $op ])[$c::$defaultVariable]['type']))
		    return "{$op}: no variable";
	    }
	    return in_array('credential', $keys, true) && in_array('operation', $keys, true) && in_array('onError', $keys, true);
	}, [ $mail, $cal, $drive, $xl, $teams ]));

	//helpers
	$this->check('Microsoft: the query string ($ names kept, empty left out, booleans)', '?$select=id%2Cname&$top=5&x=true', $M::queryString([ '$select' => 'id,name', '$top' => 5, '$filter' => '', 'x' => true ]));
	$this->check('Microsoft: Graph\'s errors (message and code; code only; none)', [ 'HTTP status 401: Access token is empty. (InvalidAuthenticationToken)', 'HTTP status 404: itemNotFound', 'HTTP status 500' ], [
	    $M::errorText(401, [ 'error' => [ 'code' => 'InvalidAuthenticationToken', 'message' => 'Access token is empty.' ] ], 'HTTP status 401'),
	    $M::errorText(404, [ 'error' => [ 'code' => 'itemNotFound' ] ], 'HTTP status 404'), $M::errorText(500, null, 'HTTP status 500') ]);
	$this->check('Microsoft: recipients (text, names, a list of objects; doubles once)', [ [ 'emailAddress' => [ 'address' => 'a@x.ro' ] ], [ 'emailAddress' => [ 'address' => 'b@y.com' ] ] ],
	    $M::recipients('a@x.ro; "B, Jr." <b@y.com>, a@x.ro'));
	$this->check('Microsoft: addresses of recipients, ids (base64 encoded in a path; refused)', [ [ 'a@x.ro' ], 'AAMk%2Fx%2B%3D', 'refused' ], [ $M::addressesOf([ [ 'emailAddress' => [ 'address' => 'a@x.ro' ] ], [] ]),
	    $M::graphId('AAMk/x+=', 'message'), (function() use ($M) { try { $M::graphId('a b', 'message'); return 'accepted'; } catch (\RuntimeException $ex) { return 'refused'; } })() ]);

	$this->check('OneDrive: SharePoint sites (a site, a team site with a library path, the root site; not SharePoint refused)', [ [ 'contoso.sharepoint.com', '/sites/Team' ],
	    [ 'contoso.sharepoint.com', '/teams/Sales%20EU' ], [ 'contoso.sharepoint.com', '/' ], 'refused' ], [ $drive::siteOf('https://contoso.sharepoint.com/sites/Team'),
	    $drive::siteOf('https://Contoso.sharepoint.com/teams/Sales%20EU/Shared%20Documents/x.xlsx'), $drive::siteOf('https://contoso.sharepoint.com/'),
	    (function() use ($drive) { try { $drive::siteOf('https://example.com/sites/x'); return 'accepted'; } catch (\RuntimeException $ex) { return 'refused'; } })() ]);
	$this->check('OneDrive: paths (names encoded, the top, .. refused), the token of a sharing link', [ '/Rapoarte%202026/a%2Bb.xlsx', '', 'refused', 'u!aHR0cHM6Ly8xZHJ2Lm1zL3gvcw' ], [
	    $drive::encodePath('/Rapoarte 2026//a+b.xlsx'), $drive::encodePath('/'), (function() use ($drive) { try { $drive::encodePath('/a/../b'); return 'accepted'; } catch (\RuntimeException $ex) { return 'refused'; } })(),
	    $drive::shareToken('https://1drv.ms/x/s') ]);
	$this->check('OneDrive: a drive item (its path in the drive, a folder)', [ '/Reports/2026.xlsx', false, true, 3 ], [
	    $drive::itemOf([ 'id' => '1', 'name' => '2026.xlsx', 'file' => [ 'mimeType' => 'x' ], 'parentReference' => [ 'path' => '/drive/root:/Reports' ] ])['path'],
	    $drive::itemOf([ 'name' => 'a', 'file' => [] ])['isFolder'], $drive::itemOf([ 'name' => 'b', 'folder' => [ 'childCount' => 3 ] ])['isFolder'],
	    $drive::itemOf([ 'name' => 'b', 'folder' => [ 'childCount' => 3 ] ])['childCount'] ]);

	$tz = new \DateTimeZone('Europe/Bucharest');
	$this->check('Outlook Calendar: times (a duration; all day: midnights, the end day included; an end before the start refused)', [
	    [ [ 'dateTime' => '2026-10-21T14:00:00', 'timeZone' => 'Europe/Bucharest' ], [ 'dateTime' => '2026-10-21T15:30:00', 'timeZone' => 'Europe/Bucharest' ] ],
	    [ [ 'dateTime' => '2026-10-21T00:00:00', 'timeZone' => 'Europe/Bucharest' ], [ 'dateTime' => '2026-10-23T00:00:00', 'timeZone' => 'Europe/Bucharest' ] ], 'refused' ], [
	    $cal::times('2026-10-21 14:00', '', false, 90, $tz), $cal::times('2026-10-21 10:00', '2026-10-22', true, 60, $tz),
	    (function() use ($cal, $tz) { try { $cal::times('2026-10-21 14:00', '2026-10-21 13:00', false, 60, $tz); return 'accepted'; } catch (\RuntimeException $ex) { return 'refused'; } })() ]);
	$this->check('Outlook Calendar: Graph\'s times read (UTC -> the step\'s zone; fractions of a second)', '2026-10-21T17:00:00+03:00',
	    $cal::isoOf([ 'dateTime' => '2026-10-21T14:00:00.0000000', 'timeZone' => 'UTC' ], $tz));
	$ev = $cal::eventOf([ 'id' => 'e1', 'subject' => 'Off', 'isAllDay' => true, 'start' => [ 'dateTime' => '2026-10-21T00:00:00.0000000', 'timeZone' => 'Europe/Bucharest' ],
	    'end' => [ 'dateTime' => '2026-10-23T00:00:00.0000000', 'timeZone' => 'Europe/Bucharest' ], 'body' => [ 'contentType' => 'html', 'content' => '<p>Hi <b>all</b></p>' ],
	    'onlineMeeting' => [ 'joinUrl' => 'https://teams.microsoft.com/l/x' ], 'attendees' => [ [ 'emailAddress' => [ 'address' => 'a@x.ro', 'name' => 'A' ], 'status' => [ 'response' => 'accepted' ], 'type' => 'required' ] ] ], $tz);
	$this->check('Outlook Calendar: an all-day event (dates, the last day), its description as text, the Teams link, the attendees', [ '2026-10-21', '2026-10-22', 'Hi all', 'https://teams.microsoft.com/l/x', 'accepted' ],
	    [ $ev['start'], $ev['end'], $ev['description'], $ev['teamsLink'], $ev['attendees'][0]['response'] ?? null ]);

	$m = $mail::messageOf([ 'id' => 'm1', 'subject' => 'Factură', 'from' => [ 'emailAddress' => [ 'address' => 'a@x.ro', 'name' => 'Ana' ] ], 'toRecipients' => [ [ 'emailAddress' => [ 'address' => 'b@y.com' ] ] ],
	    'internetMessageId' => '<abc@x>', 'body' => [ 'contentType' => 'html', 'content' => '<style>p{}</style><p>See <a href="https://x.ro">site</a> &amp; more</p>' ], 'isRead' => false, 'categories' => [ 'Red' ] ]);
	$this->check('Outlook Mail: a message (sender, recipients, Message-ID without < >, the HTML body as text, unread, categories)', [ 'a@x.ro', 'Ana', [ 'b@y.com' ], 'abc@x', 'See site (https://x.ro) & more', false, [ 'Red' ] ],
	    [ $m['from'], $m['from_name'], $m['to'], $m['message_id'], $m['text'], $m['is_read'], $m['categories'] ]);
	$this->check('Teams: a message sent (its HTML as text)', [ 'x1', "Line 1\nLine 2" ], array_values(array_intersect_key($teams::messageOf([ 'id' => 'x1', 'body' => [ 'contentType' => 'html', 'content' => 'Line 1<br>Line 2' ] ]), [ 'id' => 1, 'text' => 1 ])));

	$this->check('Excel: addresses (the cells of an address, bounds; a single cell)', [ 'A1:D20', [ 0, 1, 3, 20 ], [ 1, 2, 1, 2 ], 'B2' ],
	    [ $xl::cellsOf("'My sheet'!A1:D20"), $xl::bounds('A1:D20'), $xl::bounds('b2'), $xl::cellsOf('B2') ]);
	$this->check('Excel: an empty worksheet, empty rows at the end left out', [ true, false, [ [ 'a' ] ] ], [ $xl::isEmptyRange([ 'values' => [ [ '' ] ] ]), $xl::isEmptyRange([ 'values' => [ [ 'x' ] ] ]),
	    $xl::trimEmpty([ [ 'a' ], [ '', null ], [ '' ] ]) ]);
	$this->check('SheetRows: column names and positions (A, Z, AA, XFD; not a column)', [ 0, 25, 26, 16383, 'XFD', -1 ], [ \util\SheetRows::columnIndex('A'), \util\SheetRows::columnIndex('z'),
	    \util\SheetRows::columnIndex('AA'), \util\SheetRows::columnIndex('XFD'), \util\SheetRows::columnName(16383), \util\SheetRows::columnIndex('A1') ]);
	$this->check('SheetRows: objects matched to a header (a new column added)', [ [ 'Name', 'Qty', 'Note' ], [ [ 'a', 1, null ], [ 'b', null, 'x' ] ] ],
	    \util\SheetRows::matrixFor([ [ 'Name' => 'a', 'Qty' => 1 ], [ 'Name' => 'b', 'Note' => 'x' ] ], [ 'Name', 'Qty' ], true));

	//settings and runs: one credential of each kind with a made-up token (Graph refuses it)
	$creds = [];
	try {
	    foreach([ 'CredentialMicrosoftOutlook', 'CredentialMicrosoftCalendar', 'CredentialMicrosoftFiles', 'CredentialMicrosoftTeams' ] as $variant) {
		$c = $om->create($variant, [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => "Check: {$variant} " . uniqid(), 'clientId' => 'x', 'clientSecret' => 'y', 'tenant' => 'common' ]);
		$c->access_token = 'made-up';
		$c->refresh_token = 'made-up';
		$c->expires_at = time() + 3600;
		if (!$c->save()) {
		    $this->fail("Microsoft: the credential {$variant} was not saved: " . json_encode($c->errors()));
		    return;
		}
		$creds[$variant] = (string)$c->_id;
	    }
	    $refused = fn($type, $name, $config, $cred) => implode(' | ', $this->errorsOf("ms {$name}", [ $n('m', 'trigger::manual'), $n('s', $type, $config + [ 'credential' => $cred ]), $e('e1', 'm', 's') ]));
	    $mc = $creds['CredentialMicrosoftOutlook'];
	    $this->check('Outlook Mail: valid (send; a reply needs no recipient or subject; a draft no recipient; search)', [ '', '', '', '' ], [
		$refused('app::outlook-mail', 'send', [ 'to' => 'a@x.ro', 'subject' => 'Hi' ], $mc), $refused('app::outlook-mail', 'reply', [ 'replyTo' => '{{ m.id }}' ], $mc),
		$refused('app::outlook-mail', 'draft', [ 'operation' => 'draft', 'subject' => 'Hi' ], $mc), $refused('app::outlook-mail', 'search', [ 'operation' => 'search' ], $mc) ]);
	    $this->check('Outlook Mail refused: no recipients / subject, modify changing nothing, another kind of credential', [ true, true, true, true ], [
		str_contains($refused('app::outlook-mail', 'noto', [ 'subject' => 'Hi' ], $mc), 'To: required'), str_contains($refused('app::outlook-mail', 'nosubject', [ 'to' => 'a@x.ro' ], $mc), 'Subject: required'),
		str_contains($refused('app::outlook-mail', 'modify', [ 'operation' => 'modify', 'messageId' => 'abc' ], $mc), 'choose what to change'),
		str_contains($refused('app::outlook-mail', 'cred', [ 'operation' => 'folders' ], $creds['CredentialMicrosoftTeams']), 'choose an Outlook Mail credential') ]);
	    $this->check('Outlook Calendar refused: an unknown time zone, an update changing nothing', [ true, true ], [
		str_contains($refused('app::outlook-calendar', 'tz', [ 'timeZone' => 'Mars/Base' ], $creds['CredentialMicrosoftCalendar']), 'Time zone: unknown'),
		str_contains($refused('app::outlook-calendar', 'upd', [ 'operation' => 'update', 'eventId' => 'e1' ], $creds['CredentialMicrosoftCalendar']), 'at least one setting') ]);
	    $this->check('OneDrive refused: a text without a name, a move without a folder or name, sharing with nobody; Excel: no workbook', [ true, true, true, true ], [
		str_contains($refused('app::onedrive', 'up', [ 'operation' => 'upload', 'source' => 'text' ], $creds['CredentialMicrosoftFiles']), 'Name: required'),
		str_contains($refused('app::onedrive', 'mv', [ 'operation' => 'move', 'itemId' => '/a.txt' ], $creds['CredentialMicrosoftFiles']), 'give a folder or a new name'),
		str_contains($refused('app::onedrive', 'sh', [ 'operation' => 'share', 'itemId' => '/a.txt', 'shareWith' => 'people' ], $creds['CredentialMicrosoftFiles']), 'People: required'),
		$refused('app::excel', 'wb', [ 'operation' => 'read' ], $creds['CredentialMicrosoftFiles']) !== '' ]);

	    $runs = [
		[ 'app::outlook-mail', 'Outlook Mail search: ', [ 'operation' => 'search', 'query' => 'from:{{ who }}' ], 'CredentialMicrosoftOutlook', 'outlook' ],
		[ 'app::outlook-calendar', 'Outlook Calendar list: ', [ 'operation' => 'list', 'timeZone' => 'Europe/Bucharest' ], 'CredentialMicrosoftCalendar', 'calendar' ],
		[ 'app::onedrive', 'OneDrive / SharePoint list: ', [ 'operation' => 'list', 'folder' => '/Reports' ], 'CredentialMicrosoftFiles', 'files' ],
		[ 'app::excel', 'Excel read: ', [ 'operation' => 'read', 'workbook' => '/Reports/2026.xlsx' ], 'CredentialMicrosoftFiles', 'excel' ],
		[ 'app::teams', 'Microsoft Teams teams: ', [ 'operation' => 'teams' ], 'CredentialMicrosoftTeams', 'teams' ],
	    ];
	    foreach($runs as [ $type, $prefix, $config, $variant, $var ]) {
		$exe = $this->runFlow("ms {$type} refused token", [ $n('m', 'trigger::manual'), $n('s', $type, $config + [ 'credential' => $creds[$variant], 'onError' => 'output', 'timeout' => 15 ]),
		    $n('ok', 'app::set', [ 'variable' => 'route', 'value' => 'success' ]), $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]),
		    $e('e1', 'm', 's'), $e('e2', 's', 'ok', 0), $e('e3', 's', 'err', 1) ], [ 'who' => 'ana@x.ro' ]);
		$line = (string)self::logLine($exe, $prefix);
		$this->check("{$type}: a refused token -> the `error` output, the variable null, Graph's error logged", [ 'end', 'error', null, true ],
		    [ $exe?->state, $exe?->getVariable('route'), $exe?->getVariable($var, 'unset'), str_contains($line, 'HTTP status 401') ]);
		if ($this->verbose)
		    echo "    {$line}\n";
	    }
	} finally {
	    foreach($creds as $id)
		$om->findOne('Credential', [ '_id' => $om->to_oid($id) ])?->delete();
	}
    }


    //CLD: 2026-10-22T01:36:46+03:00: the Lemonade AI and Ollama steps and their credentials, against a fake server (commands/tests/AiTestServer.php, PHP's built-in server on a free port, an API key
    //asked for): the credentials' tests, a run with a tool call (the tool's result sent back as each API wants it, the answer without <think>, the usage added up), the step's
    //settings in the request, a JSON answer with a schema (Ollama's format), an unknown model (the `error` output, the server's message), the prompt's images
    private function suiteLocalAi() {
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$om = \V::app()->om;
	$all = \models\WfNode::getAll();
	$this->check('local AI: the step types; the credentials provide them', [ true, true, [ 'lemonade', 'http' ], [ 'ollama', 'http' ], [ 'success', 'error' ] ], [ isset($all['app::lemonade']),
	    isset($all['app::ollama']), \models\credential\CredentialLemonade::$provides, \models\credential\CredentialOllama::$provides,
	    \models\node\NodeAppOllama::outLabelsFor([ 'onError' => 'output' ]) ]);
	$ollamaKeys = array_column(\models\node\NodeAppOllama::configSchema(), 'key');
	//CLD: 2026-10-24T13:06:54+03:00: temperature and thinking first (on the dialog's line 2, with the credential and the model; was temperature, contextLength, think, keepAlive)
	$this->check('local AI: Ollama\'s own settings after the model; Lemonade has the OpenAI step\'s', [ [ 'model', 'temperature', 'think', 'contextLength', 'keepAlive' ], true ],
	    [ array_slice($ollamaKeys, 1, 5), in_array('temperature', array_column(\models\node\NodeAppLemonade::configSchema(), 'key'), true) ]);

	//the fake server
	//CLD: 2026-10-23T10:00:00+03:00: startAiServer() (was here: the systemai suite uses it too)
	$server = $this->startAiServer('local AI');
	if (!$server)
	    return;
	[ $port, $log, $requests ] = [ $server['port'], $server['log'], $server['requests'] ];
	$creds = [];
	try {
	    $make = function($variant, $url, $key) use ($om, &$creds) {
		$c = $om->create($variant, [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => "Check: {$variant} " . uniqid(), 'baseUrl' => $url, 'apiKey' => $key ]);
		if (!$c->validates() || !$c->save())
		    throw new \ErrorException("{$variant}: " . json_encode($c->errors()));
		$creds[] = $c;
		return $c;
	    };
	    $lem = $make('CredentialLemonade', "http://127.0.0.1:{$port}/api/v1", 'check-key');
	    $oll = $make('CredentialOllama', "http://127.0.0.1:{$port}/api/", 'check-key');
	    $bad = $make('CredentialOllama', "http://127.0.0.1:{$port}", 'wrong');
	    $this->check('local AI: the defaults of the credentials (Lemonade\'s address; Ollama\'s)', [ 'http://localhost:8000/api/v1', 'http://localhost:11434' ],
		[ $om->create('CredentialLemonade', [ 'aid' => $this->account->_id ])->baseUrl, $om->create('CredentialOllama', [ 'aid' => $this->account->_id ])->baseUrl ]);
	    $refused = function($c) {
		try {
		    return $c->test();
		} catch (\Exception $ex) {
		    return 'refused: ' . $ex->getMessage();
		}
	    };
	    $this->check('local AI: the credentials\' tests (the models; Ollama: its version, the URL without /api); a wrong key refused', [ '2 model(s): Qwen3-8B-GGUF, Gemma-3-4b-it-GGUF',
		'Ollama 0.0-check: 2 model(s): qwen3:8b, gemma3:4b', "http://127.0.0.1:{$port}", true ], [ $refused($lem), $refused($oll), $oll->apiBase(), str_contains($refused($bad), '401') ]);
	    //CLD: 2026-10-22T01:58:24+03:00: the Test records the models (saved; the AI steps' Model choices): sorted, the embedding / speech / image ones left out
	    $saved = fn($c) => $om->findOne('Credential', [ '_id' => $c->_id ])?->models;
	    $this->check('local AI: the Test records the models on the credential (sorted)', [ [ 'Gemma-3-4b-it-GGUF', 'Qwen3-8B-GGUF' ], [ 'gemma3:4b', 'qwen3:8b' ] ], [ $saved($lem), $saved($oll) ]);
	    $this->check('local AI: the chat models (embeddings, speech, images, moderation out; doubles once)', [ 'gpt-5', 'gpt-5-mini', 'llama3.2:3b' ],
		\models\Credential::chatModels([ 'gpt-5-mini', 'text-embedding-3-small', 'tts-1', 'whisper-1', 'dall-e-3', 'omni-moderation-latest', 'nomic-embed-text:latest', 'gpt-5', 'llama3.2:3b', 'gpt-5' ]));
	    $modelField = array_column(\util\SmartForm::modelFields(\models\node\NodeAppOllama::configForm()), null, 'key')['model'] ?? [];
	    $this->check('local AI: the Model field: a select by credential, typed names allowed (form); text for agents', [ 'select', true, 'credential', 'text' ],
		[ $modelField['type'] ?? null, $modelField['allowCustom'] ?? null, $modelField['choicesBy']['key'] ?? null,
		array_column(array_column(\util\WorkflowSpec::stepTypes(), null, 'type')['app::ollama']['settings'] ?? [], 'type', 'key')['model'] ?? null ]);

	    $withTool = \util\MCPTools::all()['list_tables'] ?? null;
	    $flow = fn($name, $type, $config, $cred) => $this->runFlow("local ai {$name}", [ $n('m', 'trigger::manual'), $n('s', $type, $config + [ 'credential' => (string)$cred->_id,
		'prompt' => 'Hi {{ who }}', 'tools' => $withTool ? [ 'list_tables' ] : [], 'mcp' => [], 'onError' => 'output', 'timeout' => 15 ]),
		$n('ok', 'app::set', [ 'variable' => 'route', 'value' => 'success' ]), $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]),
		$e('e1', 'm', 's'), $e('e2', 's', 'ok', 0), $e('e3', 's', 'err', 1) ], [ 'who' => 'Ana' ]);

	    //Lemonade: the OpenAI requests
	    @unlink($log);
	    $exe = $flow('lemonade', 'app::lemonade', [ 'model' => 'Qwen3-8B-GGUF', 'temperature' => '0.3' ], $lem);
	    $ai = $exe?->getVariable('ai');
	    $sent = $requests('/api/v1/chat/completions');
	    if ($withTool) {
		$tool = array_values(array_filter($sent[1]['messages'] ?? [], fn($m) => ($m['role'] ?? '') === 'tool'))[0] ?? [];
		$this->check('Lemonade AI: a tool call, its result sent back (tool_call_id), the answer without <think>, the usage added up', [ 'success', 2, 'list_tables', 'call_1', true, false,
		    [ 'input_tokens' => 22, 'output_tokens' => 14 ] ], [ $exe?->getVariable('route'), $ai['rounds'] ?? null, $ai['tool_calls'][0]['tool'] ?? null, $tool['tool_call_id'] ?? null,
		    str_starts_with((string)($ai['text'] ?? ''), 'The tool said: '), str_contains((string)($ai['text'] ?? ''), 'think'), $ai['usage'] ?? null ]);
	    } else {
		$this->check('Lemonade AI: the answer without <think>', [ 'success', 'Hello from Qwen3-8B-GGUF' ], [ $exe?->getVariable('route'), $ai['text'] ?? null ]);
	    }
	    $this->check('Lemonade AI: the request (the model, the prompt, the temperature)', [ 'Qwen3-8B-GGUF', 'Hi Ana', 0.3 ],
		[ $sent[0]['model'] ?? null, $sent[0]['messages'][0]['content'] ?? null, $sent[0]['temperature'] ?? null ]);

	    //Ollama: its own API
	    @unlink($log);
	    $exe = $flow('ollama', 'app::ollama', [ 'model' => 'qwen3:8b', 'temperature' => '0.2', 'contextLength' => '8192', 'think' => 'off', 'keepAlive' => '10m', 'maxTokens' => 500 ], $oll);
	    $ai = $exe?->getVariable('ai');
	    $sent = $requests('/api/chat');
	    $this->check('Ollama: the request (not streamed; temperature, num_ctx, num_predict, think, keep_alive)', [ false, [ 'num_predict' => 500, 'temperature' => 0.2, 'num_ctx' => 8192 ], false, '10m' ],
		[ $sent[0]['stream'] ?? null, $sent[0]['options'] ?? null, $sent[0]['think'] ?? null, $sent[0]['keep_alive'] ?? null ]);
	    if ($withTool) {
		$back = $sent[1]['messages'] ?? [];
		$this->check('Ollama: a tool call (no id), the assistant turn and the result (tool_name) sent back, the usage added up', [ 'success', 2, 'list_tables', [ 'user', 'assistant', 'tool' ],
		    'list_tables', true, [ 'input_tokens' => 26, 'output_tokens' => 10 ] ], [ $exe?->getVariable('route'), $ai['rounds'] ?? null, $ai['tool_calls'][0]['tool'] ?? null,
		    array_column($back, 'role'), $back[2]['tool_name'] ?? null, str_starts_with((string)($ai['text'] ?? ''), 'The tool said: '), $ai['usage'] ?? null ]);
	    }
	    @unlink($log);
	    $exe = $flow('ollama json', 'app::ollama', [ 'model' => 'qwen3:8b', 'output' => 'json', 'jsonSchema' => '{"type":"object","properties":{"answer":{"type":"string"}}}', 'tools' => [] ], $oll);
	    $sent = $requests('/api/chat');
	    $this->check('Ollama: a JSON answer with a schema (format: the schema; the instruction in the system prompt too)', [ 'success', 'schema', 'object', true ], [ $exe?->getVariable('route'),
		$exe?->getVariable('ai')['json']['format'] ?? null, $sent[0]['format']['type'] ?? null, str_contains((string)($sent[0]['messages'][0]['content'] ?? ''), 'JSON') ]);

	    @unlink($log);
	    $o = $flow('ollama unknown model', 'app::ollama', [ 'model' => 'slow-model', 'tools' => [] ], $oll);
	    $l = $flow('lemonade unknown model', 'app::lemonade', [ 'model' => 'slow-model', 'tools' => [] ], $lem);
	    $this->check('local AI: an unknown model -> the `error` output, the server\'s message', [ 'error', "Ollama answered HTTP status 404: model 'slow-model' not found", 'error',
		'Lemonade answered HTTP status 404: model slow-model not found' ], [ $o?->getVariable('route'), $o?->getVariable('ai')['error'] ?? null, $l?->getVariable('route'),
		$l?->getVariable('ai')['error'] ?? null ]);

	    $call = function($class, $method, ...$args) {
		$obj = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
		$m = new \ReflectionMethod($obj, $method);
		$m->setAccessible(true);
		return $m->invokeArgs($obj, $args);
	    };
	    $state = $call(\models\node\NodeAppOllama::class, 'startConversation', null, '', 'what is it?', null, [ [ 'image/png', 'QUJD' ] ]);
	    $this->check('Ollama: the prompt\'s images (base64, in the message)', [ 'what is it?', [ 'QUJD' ] ], [ $state['messages'][0]['content'] ?? null, $state['messages'][0]['images'] ?? null ]);
	} catch (\ErrorException $ex) {
	    $this->fail('local AI: ' . $ex->getMessage());
	} finally {
	    foreach($creds as $c)
		$c->delete();
	    $this->stopAiServer($server);
	}
    }

    //CLD: 2026-10-23T10:00:00+03:00: new: the AI steps on the System AI (plugins\SystemAI; Credential `system`): the step's credential per provider (URLs, keys), offered only to the
    //steps of its provider, refused by the others; a run against the fake AI server (as a Lemonade System AI): the installation's key sent, the credits charged (the ledger: the step,
    //its execution, the model), logged; a model not priced, the reserved credits used up, no credits left: the `error` output. The check account's credits are put back after
    private function suiteSystemAi() {
	if (!class_exists(\plugins\SystemAI\util\SystemAi::class)) {
	    $this->fail('System AI: the SystemAI plugin is not enabled');
	    return;
	}
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$SA = \plugins\SystemAI\util\SystemAi::class;
	$W = \plugins\SystemAI\models\SystemAiWallet::class;
	$L = \plugins\SystemAI\models\SystemAiLedger::class;
	$sys = fn($provider, $url = '', $key = 'k') => (object)[ 'provider' => $provider, 'url' => $url, 'api_key' => $key, 'model' => 'm' ];
	$c = [ $SA::stepCredential($sys('anthropic')), $SA::stepCredential($sys('gemini', 'https://generativelanguage.googleapis.com/v1beta/openai')), $SA::stepCredential($sys('gemini')),
	    $SA::stepCredential($sys('ollama', 'http://localhost:11434/v1', '')), $SA::stepCredential($sys('lemonade')), $SA::stepCredential($sys('openai', 'http://x/v1/')) ];
	$this->check('System AI: a step\'s credential per provider (Gemini\'s own API, Ollama\'s without /v1; the keys as the providers want them)', [ 'https://api.anthropic.com', 'k',
	    'https://generativelanguage.googleapis.com/v1beta', 'https://generativelanguage.googleapis.com/v1beta', [ 'x-goog-api-key' => 'k' ], 'http://localhost:11434', [],
	    'http://localhost:8000/api/v1', 'http://x/v1', [ 'Authorization' => 'Bearer k' ] ],
	    [ $c[0]->baseUrl, $c[0]->httpAuth()['headers']['x-api-key'] ?? null, $c[1]->baseUrl, $c[2]->baseUrl, $c[2]->httpAuth()['headers'], $c[3]->apiBase(), $c[3]->httpAuth()['headers'],
	    $c[4]->baseUrl, $c[5]->baseUrl, $c[5]->httpAuth()['headers'] ]);

	$aid = (string)$this->account->_id;
	$savedConfig = \V::app()->config('ai.system');
	$wallets = \V::app()->mongo->selectCollection($W::_database(), $W::_collection());
	//the wallet's _id: the account's id as stored (ObjectId: the object manager's)
	$savedWallet = $wallets->findOne([ '_id' => \V::app()->om->to_oid($aid) ]);
	$since = new \MongoDB\BSON\UTCDateTime();
	$server = null;
	try {
	    \V::app()->config('ai.system', [ 'provider' => '', 'model' => '' ]);
	    $none = \models\node\NodeAppLemonade::systemProvider($aid);
	    \V::app()->config('ai.system', [ 'provider' => 'lemonade', 'url' => 'http://127.0.0.1:1/api/v1', 'api_key' => 'check-key', 'model' => 'Qwen3-8B-GGUF' ]);
	    $this->check('System AI: offered to the steps of its provider only; none without one', [ null, 'Qwen3-8B-GGUF', null, null, null ],
		[ $none, \models\node\NodeAppLemonade::systemProvider($aid)['model'] ?? null, \models\node\NodeAppOpenAI::systemProvider($aid), \models\node\NodeAppClaude::systemProvider($aid),
		\models\node\NodeAppLemonade::systemProvider(null) ]);
	    $errors = $this->errorsOf('system ai ollama', [ $n('m', 'trigger::manual'), $n('s', 'app::ollama', [ 'credential' => 'system', 'model' => 'x', 'prompt' => 'hi' ]), $e('e1', 'm', 's') ]);
	    $this->check('System AI: a step of another provider is refused; one of its provider is valid', [ true, [] ],
		[ str_contains(json_encode($errors), 'System AI does not serve Ollama steps'),
		$this->errorsOf('system ai lemonade', [ $n('m', 'trigger::manual'), $n('s', 'app::lemonade', [ 'credential' => 'system', 'model' => 'x', 'prompt' => 'hi' ]), $e('e1', 'm', 's') ]) ]);

	    $server = $this->startAiServer('System AI');
	    if (!$server)
		return;
	    $url = "http://127.0.0.1:{$server['port']}/api/v1";
	    //1 USD per 1M input tokens, 2 output: a few credits per 1M, a request costs 1
	    //as an OpenAI System AI (a local one prices every model at 0)
	    \V::app()->config('ai.system', [ 'provider' => 'openai', 'url' => $url, 'api_key' => 'check-key', 'model' => 'Qwen3-8B-GGUF', 'price_input' => 1, 'price_output' => 2,
		'monthly_credits' => 0, 'max_credits_per_request' => 0 ]);
	    $W::setPlan($aid, 50);
	    $start = $W::balance($W::current($aid));
	    $withTool = isset(\util\MCPTools::all()['list_tables']);
	    $flow = fn($name, $config) => $this->runFlow("system ai {$name}", [ $n('m', 'trigger::manual'), $n('s', 'app::openai', $config + [ 'credential' => 'system',
		'model' => 'Qwen3-8B-GGUF', 'prompt' => 'Hi', 'tools' => [], 'mcp' => [], 'onError' => 'output', 'timeout' => 15 ]),
		$n('ok', 'app::set', [ 'variable' => 'route', 'value' => 'success' ]), $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]),
		$e('e1', 'm', 's'), $e('e2', 's', 'ok', 0), $e('e3', 's', 'err', 1) ]);

	    $exe = $flow('run', []);
	    $last = iterator_to_array($L::recent($aid, 1), false)[0] ?? null;
	    $this->check('System AI: a run with the installation\'s key (the server asks for it); 1 credit charged; the ledger: the step, its execution and workflow, the model; logged',
		[ 'success', 'Hello from Qwen3-8B-GGUF', $start - 1, 'debit', -1, 'step', (string)$exe?->_id, (string)$exe?->wf, 'Qwen3-8B-GGUF', 11, 'AI step', true ],
		[ $exe?->getVariable('route'), $exe?->getVariable('ai')['text'] ?? null, $W::balance($W::current($aid)), $last?->type, (int)$last?->credits, $last?->source, $last?->exid,
		$last?->wfid, $last?->model, (int)$last?->input_tokens, $last?->note, str_starts_with((string)self::logLine($exe, 'OpenAI: System AI'), 'OpenAI: System AI, 1 credit(s), ' . ($start - 1) . ' left') ]);

	    $exe = $flow('not priced', [ 'model' => 'other-model' ]);
	    $this->check('System AI: a model this installation does not price: the `error` output, nothing charged', [ 'error', true, $start - 1 ],
		[ $exe?->getVariable('route'), str_contains((string)($exe?->getVariable('ai')['error'] ?? ''), 'the model `other-model` is not priced'), $W::balance($W::current($aid)) ]);

	    if ($withTool) {
		//a price that makes the first call cost more than the 2 credits a request may reserve: no second call
		\V::app()->config('ai.system', [ 'max_credits_per_request' => 2, 'price_input' => 100000 ] + \V::app()->config('ai.system'));
		$calls = count($server['requests']('/api/v1/chat/completions'));
		$exe = $flow('used up', [ 'tools' => [ 'list_tables' ] ]);
		$this->check('System AI: the credits reserved used up after a call: no more calls, the `error` output; what was used is charged', [ 'error', true, 1, true ],
		    [ $exe?->getVariable('route'), str_contains((string)($exe?->getVariable('ai')['error'] ?? ''), 'credits reserved for this step (2) are used up'),
		    count($server['requests']('/api/v1/chat/completions')) - $calls, $W::balance($W::current($aid)) < $start - 1 ]);
	    }

	    $W::setPlan($aid, 0);
	    $wallets->updateOne([ '_id' => \V::app()->om->to_oid($aid) ], [ '$set' => [ 'topups' => [] ] ]);
	    $exe = $flow('no credits', []);
	    $this->check('System AI: no credits left: the `error` output', [ 'error', true ],
		[ $exe?->getVariable('route'), str_contains((string)($exe?->getVariable('ai')['error'] ?? ''), 'credits of this account are used up') ]);
	} catch (\ErrorException $ex) {
	    $this->fail('System AI: ' . $ex->getMessage());
	} finally {
	    \V::app()->config('ai.system', $savedConfig);
	    if ($savedWallet)
		$wallets->replaceOne([ '_id' => \V::app()->om->to_oid($aid) ], $savedWallet);
	    else
		$wallets->deleteOne([ '_id' => \V::app()->om->to_oid($aid) ]);
	    \V::app()->mongo->selectCollection($L::_database(), $L::_collection())->deleteMany([ 'aid' => \V::app()->om->to_oid($aid), 'created_at' => [ '$gte' => $since ] ]);
	    if ($server)
		$this->stopAiServer($server);
	}
    }

    //CLD: 2026-10-23T15:00:00+03:00: new: util\Http against a local server (commands/tests/HttpTestServer.php): headers, JSON / form bodies, redirects (the method kept or changed,
    //the limit), the largest answer, an answer to a stream, an upload from a stream, https only, the public-address guard; the HTTP Request and Download File steps refused
    //a private address unless http.steps.allow_private (app.env HTTP_STEPS_ALLOW_PRIVATE)
    private function suiteHttp() {
	$H = \util\Http::class;
	$this->check('http: public addresses', [ true, true, false, false, false, false, false, false, false, false ], array_map([ $H, 'isPublicIp' ],
	    [ '8.8.8.8', '2606:4700::1111', '127.0.0.1', '10.1.2.3', '192.168.0.1', '100.64.0.1', '169.254.169.254', '::1', '::ffff:127.0.0.1', '64:ff9b::7f00:1' ]));
	$this->check('http: a host of this machine is not public', [ [], 'the host localhost is not a public address' ], $H::publicAddresses('localhost'));
	$this->check('http: the guard before any connection; https only', [ 'the host 127.0.0.1 is not a public address', 'not an https:// address' ],
	    [ $H::request('GET', 'http://127.0.0.1:1/', [ 'public' => true ])['error'], $H::request('GET', 'http://example.com/', [ 'https' => true ])['error'] ]);

	$dir = sys_get_temp_dir() . '/netflow9-check-http-' . uniqid();
	@mkdir($dir, 0700, true);
	$server = $this->startServer('http', 'HttpTestServer.php', $dir);
	if (!$server)
	    return;
	[ $port, $proc ] = $server;
	$base = "http://127.0.0.1:{$port}";
	$om = \V::app()->om;
	$files = [];
	$allow = \V::app()->config('http.steps.allow_private');
	try {
	    $r = $H::json('POST', "{$base}/echo?a=1", [ 'headers' => [ 'X-One' => "a\r\nb", 'X-Two: 2' ], 'json' => [ 'k' => 'v/ă' ] ]);
	    $this->check('http: a JSON POST: the body, Content-Type, the User-Agent, the headers (CR / LF replaced); the answer decoded, its headers', [ true, 200, 'POST', '{"k":"v/ă"}',
		'application/json', 'NetFlow9-Workflow', 'a  b', '2', 'application/json', [ 'a' => '1' ], 'two', [ [ 'X-Check', 'one' ], [ 'X-Check', 'two' ] ] ],
		[ $r['ok'], $r['status'], $r['data']['method'] ?? null, $r['data']['body'] ?? null, $r['data']['headers']['content-type'] ?? null, $r['data']['headers']['user-agent'] ?? null,
		$r['data']['headers']['x-one'] ?? null, $r['data']['headers']['x-two'] ?? null, $r['data']['headers']['accept'] ?? null, $r['data']['query'] ?? null, $r['headers']['x-check'] ?? null,
		array_values(array_filter($r['headerList'], fn($h) => $h[0] === 'X-Check')) ]);
	    $r = $H::json('PUT', "{$base}/echo", [ 'form' => [ 'a' => 'x y', 'b' => '&' ], 'headers' => [ 'User-Agent' => 'Mine' ] ]);
	    $this->check('http: a form body; a User-Agent given is kept', [ 'PUT', 'a=x+y&b=%26', 'application/x-www-form-urlencoded', 'Mine' ],
		[ $r['data']['method'] ?? null, $r['data']['body'] ?? null, $r['data']['headers']['content-type'] ?? null, $r['data']['headers']['user-agent'] ?? null ]);

	    //redirects
	    $r = $H::request('GET', "{$base}/redirect", []);
	    $this->check('http: a redirect not followed by default', [ 302, '/echo', 'moved' ], [ $r['status'], $r['headers']['location'] ?? null, $r['body'] ]);
	    $r = $H::json('POST', "{$base}/redirect?status=302&to=" . rawurlencode('echo?x=1'), [ 'body' => 'b', 'redirects' => 3, 'headers' => [ 'Authorization' => 'Bearer t' ] ]);
	    $this->check('http: 302 of a POST followed as GET without the body (a relative Location; the same host: Authorization kept), the last address', [ true, 'GET', '', 'Bearer t', "{$base}/echo?x=1" ],
		[ $r['ok'], $r['data']['method'] ?? null, $r['data']['body'] ?? null, $r['data']['headers']['authorization'] ?? null, $r['url'] ]);
	    $r = $H::json('POST', "{$base}/redirect?status=307", [ 'body' => 'b', 'redirects' => 3 ]);
	    $this->check('http: 307 keeps the method and the body', [ 'POST', 'b' ], [ $r['data']['method'] ?? null, $r['data']['body'] ?? null ]);
	    $r = $H::json('GET', "{$base}/redirect?to=" . rawurlencode("http://localhost:{$port}/echo"), [ 'redirects' => 3, 'headers' => [ 'Authorization' => 'Bearer t', 'Cookie' => 'c=1', 'X-Keep' => 'k' ] ]);
	    $this->check('http: a redirect to another host: Authorization and Cookie dropped', [ true, null, null, 'k' ],
		[ $r['ok'], $r['data']['headers']['authorization'] ?? null, $r['data']['headers']['cookie'] ?? null, $r['data']['headers']['x-keep'] ?? null ]);
	    $r = $H::request('GET', "{$base}/loop", [ 'redirects' => 2 ]);
	    $this->check('http: more redirects than allowed', [ false, 'more than 2 redirects' ], [ $r['ok'], $r['error'] ]);
	    $r = $H::request('GET', "{$base}/redirect?to=" . rawurlencode('ftp://x/'), [ 'redirects' => 2 ]);
	    $this->check('http: a redirect to another scheme refused', 'a redirect to an address that is not http:// or https://', $r['error']);

	    //sizes, streams, statuses
	    $r = $H::request('GET', "{$base}/bytes?n=3000", [ 'maxBytes' => 2048 ]);
	    $this->check('http: an answer larger than maxBytes', [ false, true, 'the answer is larger than 2 KB' ], [ $r['ok'], $r['tooLarge'], $r['error'] ]);
	    $sink = fopen('php://temp', 'w+b');
	    $r = $H::request('GET', "{$base}/redirect?to=" . rawurlencode('/bytes?n=1500'), [ 'sink' => $sink, 'redirects' => 1 ]);
	    rewind($sink);
	    $this->check('http: the answer into a stream (not the redirect\'s body)', [ true, 1500, '', 'text/plain', str_repeat('x', 1500) ], [ $r['ok'], $r['size'], $r['body'], $r['mime'], stream_get_contents($sink) ]);
	    fclose($sink);
	    $up = fopen('php://temp', 'w+b');
	    fwrite($up, 'uploaded content');
	    rewind($up);
	    $r = $H::json('PUT', "{$base}/echo", [ 'upload' => [ $up, 16 ], 'headers' => [ 'Content-Type: text/plain' ] ]);
	    fclose($up);
	    $this->check('http: an upload from a stream', [ 'PUT', 'uploaded content', 'text/plain' ], [ $r['data']['method'] ?? null, $r['data']['body'] ?? null, $r['data']['headers']['content-type'] ?? null ]);
	    $r = $H::json('GET', "{$base}/status?code=404");
	    $this->check('http: an error status is an answer (error null, not ok)', [ false, 404, null, 'check error' ], [ $r['ok'], $r['status'], $r['error'], $r['data']['error']['message'] ?? null ]);
	    $r = $H::request('GET', "http://127.0.0.1:1/");
	    $this->check('http: no answer', [ false, 0, true ], [ $r['ok'], $r['status'], is_string($r['error']) && $r['error'] !== '' ]);

	    //the HTTP steps: a private address only when allowed
	    $n = [ self::class, 'node' ];
	    $e = [ self::class, 'edge' ];
	    $step = function($type, $url, $variable) use ($n, $e, $om, &$files) {
		$exe = $this->runFlow("http {$type}", [ $n('m', 'trigger::manual'), $n('h', "app::{$type}", [ 'method' => 'GET', 'url' => $url, 'variable' => $variable, 'onError' => 'output', 'timeout' => 10 ]),
		    $n('ok', 'app::set', [ 'variable' => 'route', 'value' => 'success' ]), $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]),
		    $e('e1', 'm', 'h'), $e('e2', 'h', 'ok', 0), $e('e3', 'h', 'err', 1) ]);
		$v = $exe?->getVariable($variable);
		if (!empty($v['id']))
		    $files[] = $om->to_oid($v['id']);
		return [ $exe?->getVariable('route'), $v, self::logLine($exe, 'download') ];
	    };
	    \V::app()->config('http.steps.allow_private', false);
	    [ $route, $v ] = $step('http', "{$base}/echo", 'response');
	    $this->check('HTTP Request: a private address refused (not allowed)', [ 'error', 'the host 127.0.0.1 is not a public address' ], [ $route, $v['error'] ?? null ]);
	    [ $route, $v, $log ] = $step('download', "{$base}/file", 'file');
	    $this->check('Download File: a private address refused (not allowed)', [ 'error', null, true ], [ $route, $v, str_contains((string)$log, 'not a public address') ]);
	    \V::app()->config('http.steps.allow_private', true);
	    [ $route, $v ] = $step('http', "{$base}/redirect", 'response');
	    $this->check('HTTP Request: allowed: redirects followed, the headers (a list when repeated), the JSON body', [ 'success', 200, [ 'one', 'two' ], 'GET', true ],
		[ $route, $v['status'] ?? null, $v['headers']['x-check'] ?? null, $v['body']['method'] ?? null, is_int($v['time_ms'] ?? null) ]);
	    [ $route, $v ] = $step('download', "{$base}/file", 'file');
	    $this->check('Download File: allowed: the name from Content-Disposition, the size', [ 'success', 'report.csv', 8 ], [ $route, $v['filename'] ?? null, $v['size'] ?? null ]);
	} finally {
	    \V::app()->config('http.steps.allow_private', $allow);
	    foreach($files as $fid)
		$om->findOne('File', [ '_id' => $fid ])?->delete();
	    proc_terminate($proc);
	    @unlink("{$dir}/server.err");
	    @rmdir($dir);
	}
    }

    //CLD: 2026-10-23T10:00:00+03:00: new (was in suiteLocalAi): the fake AI server (commands/tests/AiTestServer.php, PHP's built-in server on a free port, API key check-key) =>
    //{ port, log (the requests' file), requests: fn($path) => the bodies sent to $path, proc, dir }; null when it did not start (a failure named $what)
    //CLD: 2026-10-23T15:00:00+03:00: the server started by startServer() (shared with the http suite)
    private function startAiServer($what) {
	$dir = sys_get_temp_dir() . '/netflow9-check-ai-' . uniqid();
	@mkdir($dir, 0700, true);
	$log = "{$dir}/requests.log";
	$server = $this->startServer($what, 'AiTestServer.php', $dir, [ 'AI_TEST_LOG' => $log, 'AI_TEST_KEY' => 'check-key' ]);
	if (!$server)
	    return null;
	[ $port, $proc ] = $server;
	$requests = function($path) use ($log) {
	    $list = [];
	    foreach(file($log, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
		$r = json_decode($line, true);
		if (($r['path'] ?? '') === $path)
		    $list[] = $r['body'];
	    }
	    return $list;
	};
	return [ 'port' => $port, 'log' => $log, 'requests' => $requests, 'proc' => $proc, 'dir' => $dir ];
    }

    //CLD: 2026-10-23T15:00:00+03:00: new (was in startAiServer): a test server of commands/tests ($script, PHP's built-in server on a free port of 127.0.0.1, its errors in $dir/server.err,
    //$env added to the environment) => [ port, proc ]; null when it did not start (a failure named $what)
    private function startServer($what, $script, $dir, array $env = []) {
	$port = 0;
	for ($i = 0; $i < 20 && !$port; $i++) {
	    $try = random_int(20000, 40000);
	    $s = @fsockopen('127.0.0.1', $try, $errno, $errstr, 0.2);
	    if ($s)
		fclose($s);
	    else
		$port = $try;
	}
	$proc = proc_open([ PHP_BINARY, '-S', "127.0.0.1:{$port}", BASE . '/app/commands/tests/' . $script ], [ 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', "{$dir}/server.err", 'w' ] ],
	    $pipes, null, array_merge(getenv(), $env));
	$up = false;
	for ($i = 0; $i < 50 && !$up; $i++) {
	    usleep(100000);
	    $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
	    if ($s) {
		fclose($s);
		$up = true;
	    }
	}
	if (!$up) {
	    $this->fail("{$what}: the test server did not start: " . trim((string)@file_get_contents("{$dir}/server.err")));
	    proc_terminate($proc);
	    return null;
	}
	return [ $port, $proc ];
    }

    private function stopAiServer(array $server) {
	proc_terminate($server['proc']);
	proc_close($server['proc']);
	@unlink($server['log']);
	@unlink("{$server['dir']}/server.err");
	@rmdir($server['dir']);
    }


    //slow: the step limits (steps inside loops count per pass, not per run)
    //CLD: 2026-10-16T12:02:51+03:00: emails of blocks: cleaning, conversion (escaping, links, Outlook, text), the MIME message read back, the Send Email step (queues the job)
    //CLD: 2026-10-20T16:00:00+03:00: the FTP / SFTP step: listings (MLSD, Unix, Windows), name patterns, times, saved names, decryption (gpg, openssl enc, CMS: made here
    //with the system's tools), settings refused, the variable; with CHECK_FTP_SERVERS=<a JSON file> also runs against real servers: [ { protocol, host, port, username,
    //password | privateKeyFile + passphrase, folder (on the server), local (the same folder on this machine: the files are made there) } ]
    private function suiteFtp() {
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$F = \util\FtpClient::class;
	$S = \models\node\NodeAppFtp::class;
	$D = \util\FileDecrypt::class;
	$this->check('FTP: a step type, an `error` output when chosen', [ true, [ 'success', 'error' ], 1 ],
	    [ isset(\models\WfNode::getAll()['app::ftp']), $S::outLabelsFor([ 'onError' => 'output' ]), $S::outputCountFor([]) ]);
	$this->check('FTP: paths normalized and joined', [ '/a/b', 'a/b', '', '/', '/x', 'in/f.csv' ],
	    [ $F::normalize(' /a//./b/ '), $F::normalize('a\\b'), $F::normalize('.'), $F::normalize('/'), $F::join('/', 'x'), $F::join('in/', 'f.csv') ]);
	$this->check('FTP: fingerprints', [ true, true, false, '65:86:4b:44:35:a1:df:66:da:3a:6d:3b:e5:0f:ab:48' ],
	    [ $F::isFingerprint('MD5:65:86:4b:44:35:a1:df:66:da:3a:6d:3b:e5:0f:ab:48'), $F::isFingerprint('65864B4435A1DF66DA3A6D3BE50FAB48'), $F::isFingerprint('abc'),
		$F::fingerprint('65864b4435a1df66da3a6d3be50fab48', true) ]);

	$mlsd = $F::parseMlsd("type=cdir;modify=20261020074736; .\r\nmodify=20261020074703;perm=r;size=4;type=file;unique=x; my report.csv\r\ntype=dir;modify=20261020074736; sub\r\n", '/in');
	$this->check('FTP: MLSD listing (exact UTC times, the current folder left out, names with spaces)', [ [ 'my report.csv', '/in/my report.csv', 'file', 4, gmmktime(7, 47, 3, 10, 20, 2026), true ], [ 'sub', 'dir' ] ],
	    [ array_values($mlsd[0]), [ $mlsd[1]['name'], $mlsd[1]['type'] ] ]);
	$this->check('FTP: not MLSD -> null', null, $F::parseMlsd("-rw-r--r-- 1 a a 4 Oct 20 10:47 x\n", ''));
	$now = gmmktime(12, 0, 0, 10, 20, 2026);
	$unix = $F::parseList("total 3\ndrwxr-xr-x    3 app      app            51 Oct 20 10:47 .\n-rw-r--r--    1 app      app          1234 Oct 20 10:47 a b.csv\n-rw-r--r--    1 app      app             2 Jan  1  2025 old.txt\n"
	    . "lrwxrwxrwx    1 app      app             7 Dec 31 23:59 link -> target\n-rw-r--r-- 1 1000 1000 5 Nov  2 08:00 noowner.txt\n", 'in', $now);
	$this->check('FTP: Unix listing (sizes, times to the minute, a date without a year in the past, links)', [
	    [ 'a b.csv', 'in/a b.csv', 'file', 1234, gmmktime(10, 47, 0, 10, 20, 2026), false ], [ 'old.txt', gmmktime(0, 0, 0, 1, 1, 2025) ], [ 'link', 'link', gmmktime(23, 59, 0, 12, 31, 2025) ], [ 'noowner.txt', gmmktime(8, 0, 0, 11, 2, 2025) ] ], [
	    [ $unix[0]['name'], $unix[0]['path'], $unix[0]['type'], $unix[0]['size'], $unix[0]['modified'], $unix[0]['exact'] ], [ $unix[1]['name'], $unix[1]['modified'] ],
	    [ $unix[2]['name'], $unix[2]['type'], $unix[2]['modified'] ], [ $unix[3]['name'], $unix[3]['modified'] ] ]);
	$win = $F::parseList("10-20-26  10:47AM       <DIR>          Sub Folder\r\n10-19-26  01:05PM                 4 r.csv\r\n", '', $now);
	$this->check('FTP: Windows listing', [ [ 'Sub Folder', 'dir', null ], [ 'r.csv', 'file', 4, gmmktime(13, 5, 0, 10, 19, 2026) ] ],
	    [ [ $win[0]['name'], $win[0]['type'], $win[0]['size'] ], [ $win[1]['name'], $win[1]['type'], $win[1]['size'], $win[1]['modified'] ] ]);

	$this->check('FTP: name patterns (globs, case, paths, a regular expression, empty)', [ true, true, false, true, false, true, false, true, false ], [
	    $S::matches('*.csv, *.xlsx', 'A.CSV', 'A.CSV'), $S::matches('report_????.xlsx', 'report_2026.xlsx', 'x'), $S::matches('report_????.xlsx', 'report_26.xlsx', 'x'),
	    $S::matches('2026/*.csv', 'a.csv', '2026/a.csv'), $S::matches('2026/*.csv', 'a.csv', '2026/x/a.csv'), $S::matches('/^inv_\d+\.xml$/i', 'INV_12.xml', ''),
	    $S::matches('/^inv_\d+\.xml$/', 'INV_12.xml', ''), $S::matches('', 'x', 'x', true), $S::matches('', 'x', 'x') ]);
	$this->check('FTP: patterns refused', [ 'not a valid regular expression', null, null ], [ $S::patternError('/(/'), $S::patternError('*.csv'), $S::patternError('/ok/i') ]);
	$this->check('FTP: times (relative, dates, Unix seconds / milliseconds, refused)', [ $now - 86400, gmmktime(0, 0, 0, 10, 1, 2026), 1700000000, 1700000000, null, null ], [
	    $S::parseTime('-24 hours', $now), $S::parseTime('2026-10-01T00:00:00Z', $now), $S::parseTime('1700000000'), $S::parseTime(1700000000000), $S::parseTime('', $now), $S::parseTime('not a date', $now) ]);
	$this->check('FTP: saved names (decrypted, decompressed, not changed)', [ 'report.csv', 'report.csv', 'data.csv', 'a.csv.gz', 'x.p7m', 'data.csv' ], [
	    $S::savedName('report.csv.gpg', 'pgp', false), $S::savedName('report.csv.gz.pgp', 'pgp-symmetric', true), $S::savedName('data.csv.p7m', 'cms', false),
	    $S::savedName('a.csv.gz', null, false), $S::savedName('x.p7m', 'pgp', false), $S::savedName('data.csv.enc', 'openssl-enc', false) ]);
	$this->check('FTP: the variable (download: saved file fields; list: none)', [ true, false, 'array' ], [
	    isset($S::outputVariables([])['ftp']['fields']['files']['items']['fields']['id']), isset($S::outputVariables([ 'operation' => 'list', 'variable' => 'l' ])['l']['fields']['files']['items']['fields']['id']),
	    $S::outputVariables([])['ftp']['fields']['errors']['type'] ?? null ]);

	//decryption, with files and keys made here
	$work = $D::workDir();
	$om = \V::app()->om;
	$creds = [];
	$cred = function($variant, array $values) use ($om, &$creds) {
	    $c = $om->create($variant, [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: ' . $variant . ' ' . uniqid() ] + $values);
	    if (!$c->save()) {
		$this->fail("FTP: the {$variant} credential was not saved: " . json_encode($c->errors()));
		return null;
	    }
	    $creds[] = $c;
	    return $c;
	};
	try {
	    $plain = "id,amount\n1,10\n2,20\n";
	    file_put_contents("{$work}/plain.csv", $plain);
	    $sh = fn($cmd) => exec($cmd . ' 2>/dev/null', $o, $code) !== false && $code === 0;
	    $home = "{$work}/gpghome";
	    mkdir($home, 0700);
	    $g = 'gpg --homedir ' . escapeshellarg($home) . ' --batch --quiet --pinentry-mode loopback';
	    $made = $sh("{$g} --passphrase kp --quick-gen-key 'Check <check@example.com>' rsa2048 encrypt,sign never")
		&& $sh("{$g} --passphrase kp --armor --export-secret-keys > " . escapeshellarg("{$work}/priv.asc"))
		&& $sh("{$g} --armor --export > " . escapeshellarg("{$work}/pub.asc"))
		&& $sh("{$g} --trust-model always -r check@example.com -o " . escapeshellarg("{$work}/data.csv.gpg") . ' -e ' . escapeshellarg("{$work}/plain.csv"))
		&& $sh("{$g} --passphrase sym -c -o " . escapeshellarg("{$work}/sym.csv.pgp") . ' ' . escapeshellarg("{$work}/plain.csv"))
		&& $sh('gzip -c ' . escapeshellarg("{$work}/plain.csv") . ' > ' . escapeshellarg("{$work}/plain.csv.gz"))
		&& $sh('openssl enc -aes-256-cbc -pbkdf2 -salt -a -in ' . escapeshellarg("{$work}/plain.csv") . ' -out ' . escapeshellarg("{$work}/data.csv.enc") . ' -pass pass:osecret')
		&& $sh('openssl req -x509 -newkey rsa:2048 -keyout ' . escapeshellarg("{$work}/cms.key") . ' -out ' . escapeshellarg("{$work}/cms.crt") . ' -days 2 -subj /CN=check -passout pass:cp')
		&& $sh('openssl req -x509 -newkey rsa:2048 -nodes -keyout ' . escapeshellarg("{$work}/c2.key") . ' -out ' . escapeshellarg("{$work}/c2.crt") . ' -days 2 -subj /CN=other')
		&& $sh('openssl cms -encrypt -binary -aes256 -in ' . escapeshellarg("{$work}/plain.csv") . ' -outform DER -out ' . escapeshellarg("{$work}/data.csv.p7m") . ' ' . escapeshellarg("{$work}/cms.crt"));
	    exec('gpgconf --homedir ' . escapeshellarg($home) . ' --kill all 2>/dev/null');
	    if (!$made) {
		$this->fail('FTP: the test files could not be made (gpg / openssl / gzip)');
		return;
	    }
	    $pgp = $cred('CredentialDecryption', [ 'method' => 'pgp', 'privateKey' => file_get_contents("{$work}/priv.asc"), 'passphrase' => 'kp' ]);
	    $sym = $cred('CredentialDecryption', [ 'method' => 'pgp-symmetric', 'passphrase' => 'sym' ]);
	    $enc = $cred('CredentialDecryption', [ 'method' => 'openssl-enc', 'passphrase' => 'osecret', 'cipher' => 'aes-256-cbc', 'kdf' => 'pbkdf2', 'iterations' => 10000 ]);
	    $cms = $cred('CredentialDecryption', [ 'method' => 'cms', 'privateKey' => file_get_contents("{$work}/cms.key"), 'passphrase' => 'cp', 'certificate' => file_get_contents("{$work}/cms.crt") ]);
	    $badPgp = $cred('CredentialDecryption', [ 'method' => 'pgp', 'privateKey' => file_get_contents("{$work}/priv.asc"), 'passphrase' => 'wrong' ]);
	    if (!$pgp || !$sym || !$enc || !$cms || !$badPgp)
		return;
	    $dec = function($c, $file, $max = 1048576) use ($D, $work) {
		@unlink("{$work}/out");
		try {
		    $D::decrypt($c, "{$work}/{$file}", "{$work}/out", $max);
		    return file_get_contents("{$work}/out");
		} catch (\RuntimeException $e) {
		    return 'error: ' . $e->getMessage();
		}
	    };
	    $this->check('FTP: decrypted: PGP key, PGP passphrase, openssl enc (base64), CMS', [ $plain, $plain, $plain, $plain ],
		[ $dec($pgp, 'data.csv.gpg'), $dec($sym, 'sym.csv.pgp'), $dec($enc, 'data.csv.enc'), $dec($cms, 'data.csv.p7m') ]);
	    $wrongEnc = clone $enc;
	    $wrongEnc->passphrase = 'nope';
	    $this->check('FTP: decryption refused: wrong key passphrase, wrong password, too large', [ true, 'error: openssl: wrong password or settings (cipher, key derivation)', true ],
		[ str_starts_with($dec($badPgp, 'data.csv.gpg'), 'error: gpg: '), $dec($wrongEnc, 'data.csv.enc'), str_contains($dec($pgp, 'data.csv.gpg', 5), 'larger than') ]);
	    $this->check('FTP: the credentials\' test', [ true, true ], [ str_contains($pgp->test(), 'check@example.com'), str_contains($cms->test(), '2048 bits') ]);
	    $this->check('FTP: decompressed (gzip), too large refused', [ $plain, true ], [
		($D::decompress("{$work}/plain.csv.gz", "{$work}/unz", 'gzip', 1048576) ? file_get_contents("{$work}/unz") : null),
		(function() use ($D, $work) { try { $D::decompress("{$work}/plain.csv.gz", "{$work}/unz2", 'gzip', 5); return false; } catch (\RuntimeException $e) { return str_contains($e->getMessage(), 'larger'); } })() ]);
	    $badKey = $om->create('CredentialDecryption', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: bad ' . uniqid(), 'method' => 'pgp',
		'privateKey' => "-----BEGIN PGP PUBLIC KEY BLOCK-----\nx\n-----END PGP PUBLIC KEY BLOCK-----" ]);
	    $this->check('FTP: a public key refused as a decryption key', [ false, true ], [ $badKey->validates(), str_contains(json_encode($badKey->errors()), 'public key') ]);

	    //CLD: 2026-10-20T18:00:00+03:00: the Upload step: names, endings, encryption (decrypted again with the keys above), the Encryption key credentials
	    $U = \models\node\NodeAppFtpUpload::class;
	    $E = \util\FileEncrypt::class;
	    $noon = mktime(12, 0, 0, 10, 20, 2026);
	    $this->check('FTP upload: remote names (placeholders, a path removed, a text\'s name, no dot left)', [ 'r_2026-10-20.csv', 'a_b.csv', 'orders_2', 'x_120000_3.csv' ], [
		$U::remoteName('{base}_{date}.{ext}', 'r.csv', 1, $noon), $U::remoteName('', 'a/b.csv', 1), $U::remoteName('orders_{n}.{ext}', '', 2), $U::remoteName('x_{time}_{n}.csv', 'y', 3, $noon) ]);
	    $this->check('FTP upload: endings, numbered names', [ 'a.csv.gz', 'a.CSV.GZ', 'report_1.csv.gpg', 'README_2', '.hidden_1', '.gpg', '.asc', '.p7m' ], [
		$U::withEnding('a.csv', '.gz'), $U::withEnding('a.CSV.GZ', '.gz'), $U::numbered('report.csv.gpg', 1), $U::numbered('README', 2), $U::numbered('.hidden', 1),
		$E::ending('pgp'), $E::ending('pgp-symmetric', true), $E::ending('cms') ]);
	    $pgpPub = $cred('CredentialEncryption', [ 'method' => 'pgp', 'publicKey' => file_get_contents("{$work}/pub.asc") ]);
	    $symEnc = $cred('CredentialEncryption', [ 'method' => 'pgp-symmetric', 'passphrase' => 'sym', 'armor' => true ]);
	    $osslEnc = $cred('CredentialEncryption', [ 'method' => 'openssl-enc', 'passphrase' => 'osecret', 'cipher' => 'aes-256-cbc', 'kdf' => 'pbkdf2', 'iterations' => 10000, 'armor' => true ]);
	    $cmsEnc = $cred('CredentialEncryption', [ 'method' => 'cms', 'certificate' => file_get_contents("{$work}/cms.crt") ]);
	    if (!$pgpPub || !$symEnc || !$osslEnc || !$cmsEnc)
		return;
	    $round = function($encC, $decC) use ($E, $D, $work) {
		@unlink("{$work}/rt");
		@unlink("{$work}/rt2");
		try {
		    $E::encrypt($encC, "{$work}/plain.csv", "{$work}/rt");
		    $D::decrypt($decC, "{$work}/rt", "{$work}/rt2", 1048576);
		    return file_get_contents("{$work}/rt2");
		} catch (\RuntimeException $e) {
		    return 'error: ' . $e->getMessage();
		}
	    };
	    $this->check('FTP upload: encrypted and decrypted again: PGP public key, PGP passphrase (armored), openssl enc (base64), CMS', [ $plain, $plain, $plain, $plain ],
		[ $round($pgpPub, $pgp), $round($symEnc, $sym), $round($osslEnc, $enc), $round($cmsEnc, $cms) ]);
	    $E::encrypt($symEnc, "{$work}/plain.csv", "{$work}/arm");
	    $this->check('FTP upload: armored output is text', true, str_starts_with((string)file_get_contents("{$work}/arm"), '-----BEGIN PGP MESSAGE-----'));
	    $privAsPub = $om->create('CredentialEncryption', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: bad ' . uniqid(), 'method' => 'pgp',
		'publicKey' => file_get_contents("{$work}/priv.asc") ]);
	    $this->check('FTP upload: the Encryption key test, a private key refused as a public one', [ true, true, false ],
		[ str_contains($pgpPub->test(), 'check@example.com'), str_contains($cmsEnc->test(), 'check'), $privAsPub->validates() ]);

	    //CLD: 2026-10-20T19:00:00+03:00: signing: each kind checked by the tool that verifies it (gpg --verify, openssl cms -verify, dgst / pkeyutl -verify)
	    $made = $sh('openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -aes256 -pass pass:rp -out ' . escapeshellarg("{$work}/rsa.pem"))
		&& $sh('openssl pkey -in ' . escapeshellarg("{$work}/rsa.pem") . ' -passin pass:rp -pubout -out ' . escapeshellarg("{$work}/rsapub.pem"))
		&& $sh('openssl genpkey -algorithm ED25519 -out ' . escapeshellarg("{$work}/ed.pem"))
		&& $sh('openssl pkey -in ' . escapeshellarg("{$work}/ed.pem") . ' -pubout -out ' . escapeshellarg("{$work}/edpub.pem"));
	    if (!$made) {
		$this->fail('FTP sign: the test keys could not be made');
		return;
	    }
	    $pgpSign = $cred('CredentialSigning', [ 'method' => 'pgp', 'privateKey' => file_get_contents("{$work}/priv.asc"), 'passphrase' => 'kp' ]);
	    $pgpSignArmor = $cred('CredentialSigning', [ 'method' => 'pgp', 'privateKey' => file_get_contents("{$work}/priv.asc"), 'passphrase' => 'kp', 'armor' => true, 'digest' => 'sha512' ]);
	    $cmsSign = $cred('CredentialSigning', [ 'method' => 'cms', 'privateKey' => file_get_contents("{$work}/cms.key"), 'passphrase' => 'cp', 'certificate' => file_get_contents("{$work}/cms.crt") ]);
	    $rawRsa = $cred('CredentialSigning', [ 'method' => 'raw', 'privateKey' => file_get_contents("{$work}/rsa.pem"), 'passphrase' => 'rp' ]);
	    $rawEd = $cred('CredentialSigning', [ 'method' => 'raw', 'privateKey' => file_get_contents("{$work}/ed.pem"), 'armor' => true ]);
	    if (!$pgpSign || !$pgpSignArmor || !$cmsSign || !$rawRsa || !$rawEd)
		return;
	    $G = \util\FileSign::class;
	    $q = fn($f) => escapeshellarg("{$work}/{$f}");
	    $tool = function($cmd) {
		exec($cmd . ' 2>&1', $o, $code);
		return [ $code, implode("\n", $o) ];
	    };
	    $gv = 'gpg --homedir ' . escapeshellarg($home) . ' --batch --no-tty';
	    $G::sign($pgpSign, "{$work}/plain.csv", "{$work}/p.sig", 'detached');
	    $G::sign($pgpSignArmor, "{$work}/plain.csv", "{$work}/p.asc", 'detached');
	    $G::sign($pgpSign, "{$work}/plain.csv", "{$work}/p.gpg", 'attached');
	    $G::sign($pgpSign, "{$work}/plain.csv", "{$work}/pe.gpg", 'attached', $pgpPub);
	    [ $c1, $o1 ] = $tool("{$gv} --verify " . $q('p.sig') . ' ' . $q('plain.csv'));
	    [ $c2 ] = $tool("{$gv} --verify " . $q('p.asc') . ' ' . $q('plain.csv'));
	    [ , $o2 ] = $tool("{$gv} --list-packets " . $q('p.asc'));
	    [ $c3, $o3 ] = $tool("{$gv} --yes -o " . $q('p.out') . ' --decrypt ' . $q('p.gpg'));
	    [ $c4, $o4 ] = $tool("{$gv} --pinentry-mode loopback --passphrase kp --yes -o " . $q('pe.out') . ' --decrypt ' . $q('pe.gpg'));
	    exec('gpgconf --homedir ' . escapeshellarg($home) . ' --kill all 2>/dev/null');
	    $this->check('FTP sign: PGP detached (binary, armored SHA-512), attached, signed + encrypted in one message: verified by gpg', [ [ 0, true ], [ 0, true, true ], [ 0, $plain ], [ 0, $plain, true, true ] ], [
		[ $c1, str_contains($o1, 'Good signature') ], [ $c2, str_starts_with((string)file_get_contents("{$work}/p.asc"), '-----BEGIN PGP SIGNATURE-----'), str_contains($o2, 'digest algo 10') ],
		[ $c3, @file_get_contents("{$work}/p.out") ], [ $c4, @file_get_contents("{$work}/pe.out"), str_contains($o4, 'encrypted with'), str_contains($o4, 'Good signature') ] ]);
	    $this->check('FTP sign: a signed + encrypted message decrypted by the Decryption key; a signed file of an unknown signer read by a passphrase credential', [ $plain, $plain ],
		[ $dec($pgp, 'pe.gpg'), $dec($sym, 'p.gpg') ]);
	    $G::sign($cmsSign, "{$work}/plain.csv", "{$work}/s.p7m", 'attached');
	    $G::sign($cmsSign, "{$work}/plain.csv", "{$work}/s.p7s", 'detached');
	    [ $c5 ] = $tool('openssl cms -verify -binary -inform DER -in ' . $q('s.p7m') . ' -CAfile ' . $q('cms.crt') . ' -out ' . $q('s.out'));
	    [ $c6 ] = $tool('openssl cms -verify -binary -inform DER -in ' . $q('s.p7s') . ' -content ' . $q('plain.csv') . ' -CAfile ' . $q('cms.crt') . ' -out /dev/null');
	    $G::sign($rawRsa, "{$work}/plain.csv", "{$work}/r.sig", 'detached');
	    $G::sign($rawEd, "{$work}/plain.csv", "{$work}/e.sig64", 'detached');
	    file_put_contents("{$work}/e.sig", base64_decode((string)file_get_contents("{$work}/e.sig64")));
	    [ $c7, $o7 ] = $tool('openssl dgst -sha256 -verify ' . $q('rsapub.pem') . ' -signature ' . $q('r.sig') . ' ' . $q('plain.csv'));
	    [ $c8, $o8 ] = $tool('openssl pkeyutl -verify -pubin -inkey ' . $q('edpub.pem') . ' -rawin -in ' . $q('plain.csv') . ' -sigfile ' . $q('e.sig'));
	    $this->check('FTP sign: CMS attached / detached, plain RSA (SHA-256) / Ed25519 (base64): verified by openssl', [ 0, $plain, 0, [ 0, 'Verified OK' ], [ 0, 'Signature Verified Successfully' ] ],
		[ $c5, @file_get_contents("{$work}/s.out"), $c6, [ $c7, trim($o7) ], [ $c8, trim($o8) ] ]);
	    $wrongSign = clone $pgpSign;
	    $wrongSign->passphrase = 'nope';
	    $signError = function($c, $mode) use ($G, $work) {
		try {
		    $G::sign($c, "{$work}/plain.csv", "{$work}/x.sig", $mode);
		    return 'signed';
		} catch (\RuntimeException $e) {
			return $e->getMessage();
		}
	    };
	    $this->check('FTP sign: refused: a wrong passphrase, a plain signature in the file', [ 'gpg: wrong passphrase for the signing key', 'a plain signature is always a separate file (detached)' ],
		[ $signError($wrongSign, 'detached'), $signError($rawRsa, 'attached') ]);
	    $this->check('FTP sign: the Signing key test (PGP, CMS, RSA, Ed25519)', [ true, true, 'RSA key of 2048 bits', 'Ed25519 key' ],
		[ str_contains($pgpSign->test(), 'signs as Check'), str_contains($cmsSign->test(), 'signs as check'), $rawRsa->test(), preg_replace('/ of \d+ bits$/', '', $rawEd->test()) ]);
	    //CLD: 2026-10-20T20:00:00+03:00: signature checks (util\FileVerify) on the files signed above: valid, changed, by a key not trusted, not signed
	    $other = "{$work}/otherhome";
	    mkdir($other, 0700);
	    $go = 'gpg --homedir ' . escapeshellarg($other) . ' --batch --quiet --pinentry-mode loopback --passphrase ""';
	    $made = $sh("{$go} --quick-gen-key 'Other <other@example.com>' ed25519 sign never") && $sh("{$go} --armor --export > " . $q('otherpub.asc'))
		&& $sh("{$go} --detach-sign -o " . $q('other.sig') . ' ' . $q('plain.csv'));
	    exec('gpgconf --homedir ' . escapeshellarg($other) . ' --kill all 2>/dev/null');
	    if (!$made) {
		$this->fail('FTP verify: the other key could not be made');
		return;
	    }
	    $verPgp = $cred('CredentialVerification', [ 'method' => 'pgp', 'publicKey' => file_get_contents("{$work}/pub.asc") ]);
	    $verOther = $cred('CredentialVerification', [ 'method' => 'pgp', 'publicKey' => file_get_contents("{$work}/otherpub.asc") ]);
	    $verCms = $cred('CredentialVerification', [ 'method' => 'cms', 'certificate' => file_get_contents("{$work}/cms.crt") ]);
	    $verRsa = $cred('CredentialVerification', [ 'method' => 'raw', 'publicKey' => file_get_contents("{$work}/rsapub.pem"), 'digest' => 'sha256' ]);
	    $verEd = $cred('CredentialVerification', [ 'method' => 'raw', 'publicKey' => file_get_contents("{$work}/edpub.pem") ]);
	    if (!$verPgp || !$verOther || !$verCms || !$verRsa || !$verEd)
		return;
	    $V = \util\FileVerify::class;
	    $vd = function($c, $data, $sig) use ($V, $work) {
		try {
		    return $V::detached($c, "{$work}/{$data}", "{$work}/{$sig}")['signer'];
		} catch (\RuntimeException $e) {
		    return 'error: ' . $e->getMessage();
		}
	    };
	    $va = function($c, $file) use ($V, $work) {
		@unlink("{$work}/va.out");
		try {
		    return [ $V::attached($c, "{$work}/{$file}", "{$work}/va.out")['signer'], file_get_contents("{$work}/va.out") ];
		} catch (\RuntimeException $e) {
		    return 'error: ' . $e->getMessage();
		}
	    };
	    $this->check('FTP verify: detached signatures: PGP (binary, armored), CMS, plain RSA, plain Ed25519 (base64)',
		[ 'Check <check@example.com>', 'Check <check@example.com>', 'check', 'RSA key', 'Ed25519 key' ],
		[ $vd($verPgp, 'plain.csv', 'p.sig'), $vd($verPgp, 'plain.csv', 'p.asc'), $vd($verCms, 'plain.csv', 's.p7s'), $vd($verRsa, 'plain.csv', 'r.sig'), $vd($verEd, 'plain.csv', 'e.sig64') ]);
	    $this->check('FTP verify: attached: PGP signed, CMS .p7m (the content kept)', [ [ 'Check <check@example.com>', $plain ], [ 'check', $plain ] ], [ $va($verPgp, 'p.gpg'), $va($verCms, 's.p7m') ]);
	    file_put_contents("{$work}/changed.csv", $plain . "3,30\n");
	    $this->check('FTP verify refused: a changed file (PGP, CMS, RSA, Ed25519)', [ true, true, true, true ], [
		str_contains($vd($verPgp, 'changed.csv', 'p.sig'), 'bad signature'), str_contains($vd($verCms, 'changed.csv', 's.p7s'), 'bad signature'),
		str_contains($vd($verRsa, 'changed.csv', 'r.sig'), 'bad signature'), str_contains($vd($verEd, 'changed.csv', 'e.sig64'), 'bad signature') ]);
	    $this->check('FTP verify refused: signed by a key not trusted, a plain signature with another key, a self-signed certificate not trusted', [ true, true, true, true ], [
		str_contains($vd($verPgp, 'plain.csv', 'other.sig'), 'not one of the trusted keys'), str_contains($vd($verOther, 'plain.csv', 'p.sig'), 'not one of the trusted keys'),
		str_contains($vd($verEd, 'plain.csv', 'r.sig'), 'bad signature'),
		str_contains($vd($cred('CredentialVerification', [ 'method' => 'cms', 'certificate' => file_get_contents("{$work}/c2.crt") ?: '' ]) ?? $verCms, 'plain.csv', 's.p7s'), 'not trusted') ]);
	    $this->check('FTP verify refused: not signed, encrypted (not decrypted first)', [ true, true ], [
		str_starts_with((string)(is_string($x = $va($verPgp, 'plain.csv')) ? $x : ''), 'error: '), str_contains((string)(is_string($x = $va($verPgp, 'data.csv.gpg')) ? $x : ''), 'encrypted') ]);
	    //decrypted and checked in one gpg pass: our own private key is in that keyring, yet only the trusted keys count
	    $dv = function($verifier) use ($D, $pgp, $work) {
		@unlink("{$work}/dv.out");
		try {
		    $r = $D::decrypt($pgp, "{$work}/pe.gpg", "{$work}/dv.out", 1048576, $verifier);
		    return [ $r['signer'] ?? null, file_get_contents("{$work}/dv.out") ];
		} catch (\RuntimeException $e) {
		    return 'error: ' . $e->getMessage() . (is_file("{$work}/dv.out") ? ' (output left)' : '');
		}
	    };
	    $this->check('FTP verify: signed + encrypted: checked while decrypting; another trusted key: refused, nothing left; encrypted only: null', [
		[ 'Check <check@example.com>', $plain ], true, null ], [
		$dv($verPgp), (bool)preg_match('/^error: signed by a key that is not one of the trusted keys \([0-9A-F]{16}\)$/', (string)(is_string($x = $dv($verOther)) ? $x : '')),
		$D::decrypt($pgp, "{$work}/data.csv.gpg", "{$work}/dv2.out", 1048576, $verPgp) ]);
	    //the keys of the text only, never the keyring's (here: the Check key is in it already)
	    $only = (function() use ($home, $work) {
		$tmp = \util\FileDecrypt::workDir();
		try {
		    $keys = \util\FileEncrypt::importPublicKeys([ 'gpg', '--homedir', $home, '--batch', '--no-tty', '--no-permission-warning' ], $tmp, file_get_contents("{$work}/otherpub.asc"), 'S');
		    return array_merge(...array_column($keys, 'uids'));
		} finally {
		    exec('gpgconf --homedir ' . escapeshellarg($home) . ' --kill all 2>/dev/null');
		    \util\FileDecrypt::removeDir($tmp);
		}
	    })();
	    $this->check('FTP verify: the trusted keys are those of the text, not the keyring\'s', [ 'Other <other@example.com>' ], $only);
	    $badVer = $om->create('CredentialVerification', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => 'Check: bad ' . uniqid(), 'method' => 'raw',
		'publicKey' => file_get_contents("{$work}/rsa.pem") ]);
	    $this->check('FTP verify: the Verification key test; a private key refused', [ true, true, 'Ed25519 public key', false ],
		[ str_contains($verPgp->test(), 'check@example.com'), str_contains($verCms->test(), 'trusted'), preg_replace('/ of \d+ bits$/', '', $verEd->test()), $badVer->validates() ]);
	    $this->check('FTP verify: saved names (an attached signature\'s ending removed after decrypting)', [ 'x.csv', 'x.csv', 'x.csv', 'x.csv.sig' ], [
		$S::savedName('x.csv.gpg', null, false, 'pgp'), $S::savedName('x.csv.gpg.enc', 'openssl-enc', false, 'pgp'), $S::savedName('x.csv.p7m', 'cms', false, 'cms'), $S::savedName('x.csv.sig', null, false, 'raw') ]);

	    $o = fn($sign, $enc, $mode = 'attached', $gz = false) => [ 'compress' => $gz, 'encCred' => $enc, 'signCred' => $sign, 'signMode' => $mode ];
	    $this->check('FTP sign: the endings (PGP sign + encrypt: one message; CMS in CMS: one .p7m added by each; detached: none on the file)', [
		[ '.gz', '.gpg' ], [ '.gpg', '.p7m' ], [ '.p7m', '.p7m' ], [ '.gpg' ], [ '.asc' ], [ '.gz', '.enc' ] ], [
		$U::endings($o($pgpSign, $pgpPub, 'attached', true)), $U::endings($o($pgpSign, $cmsEnc)), $U::endings($o($cmsSign, $cmsEnc)), $U::endings($o($pgpSign, null)),
		$U::endings($o($pgpSignArmor, null)), $U::endings($o($rawEd, $osslEnc, 'detached', true)) ]);

	    //the step's settings
	    $ftp = $cred('CredentialFtp', [ 'protocol' => 'sftp', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'y' ]);
	    if (!$ftp)
		return;
	    $refused = fn($name, $config) => implode(' | ', $this->errorsOf("ftp {$name}", [ $n('m', 'trigger::manual'), $n('f', 'app::ftp', $config + [ 'credential' => (string)$ftp->_id ]), $e('e1', 'm', 'f') ]));
	    $this->check('FTP: valid (defaults)', '', $refused('defaults', []));
	    //the credential forms: keys are write-only textareas (their lines kept), a stored one is not asked again
	    $fields = array_column(\util\SmartForm::modelFields($pgp), null, 'key');
	    $fresh = array_column(\util\SmartForm::modelFields($om->create('CredentialFtp', [])), null, 'key');
	    $this->check('FTP: credential forms (key: a write-only textarea, stored; password: a password input)', [ 'textarea', true, 'password', 'textarea', false ],
		[ $fields['privateKey']['type'] ?? null, !empty($fields['privateKey']['stored']), $fields['passphrase']['type'] ?? null, $fresh['privateKey']['type'] ?? null, !empty($fresh['privateKey']['stored']) ]);
	    $this->check('FTP refused: a bad regular expression, a bad date, decrypt without a key, move without a folder, a decryption key as server', [ true, true, true, true, true ], [
		str_contains($refused('regex', [ 'pattern' => '/(/' ]), 'regular expression'), str_contains($refused('date', [ 'modifiedAfter' => 'whenever' ]), 'Modified after'),
		str_contains($refused('decrypt', [ 'decrypt' => 'ext' ]), 'Decryption key'), str_contains($refused('move', [ 'after' => 'move' ]), 'Move to folder'),
		str_contains($refused('server', [ 'credential' => (string)$pgp->_id ]), 'Server credential') ]);
	    $this->check('FTP: an expression in a date is checked at run time', '', $refused('date expr', [ 'modifiedAfter' => '{{ since }}' ]));
	    $upRefused = fn($name, $config) => implode(' | ', $this->errorsOf("ftp upload {$name}", [ $n('m', 'trigger::manual'), $n('u', 'app::ftp-upload', $config + [ 'credential' => (string)$ftp->_id ]), $e('e1', 'm', 'u') ]));
	    $this->check('FTP upload: valid (files), refused: no files, a text without a name, a path as name, encrypt without a key, a decryption key to encrypt', [ '', true, true, true, true, true ], [
		$upRefused('ok', [ 'files' => '{{ file.id }}' ]), str_contains($upRefused('nofiles', []), 'Project files'), str_contains($upRefused('text', [ 'source' => 'text', 'content' => 'x' ]), 'Name on the server'),
		str_contains($upRefused('path', [ 'files' => 'x', 'filename' => 'a/b.csv' ]), 'not a path'), str_contains($upRefused('nokey', [ 'files' => 'x', 'encrypt' => true ]), 'Encryption key'),
		str_contains($upRefused('deckey', [ 'files' => 'x', 'encrypt' => true, 'encryptCredential' => (string)$pgp->_id ]), 'Encryption key') ]);
	    $this->check('FTP upload refused: a plain signature in the file, a decryption key to sign; valid: a plain signature as a file', [ true, true, '' ], [
		str_contains($upRefused('rawatt', [ 'files' => 'x', 'sign' => true, 'signCredential' => (string)$rawRsa->_id ]), 'always a separate file'),
		str_contains($upRefused('signdec', [ 'files' => 'x', 'sign' => true, 'signCredential' => (string)$pgp->_id ]), 'Signing key'),
		$upRefused('rawdet', [ 'files' => 'x', 'sign' => true, 'signCredential' => (string)$rawRsa->_id, 'signMode' => 'detached' ]) ]);
	    $this->check('FTP verify refused in the step: a plain signature in the file, a signing key as verification key; valid: a separate file', [ true, true, '' ], [
		str_contains($refused('rawatt', [ 'verifySignature' => 'attached', 'verifyCredential' => (string)$verRsa->_id ]), 'always a separate file'),
		str_contains($refused('signver', [ 'verifySignature' => 'detached', 'verifyCredential' => (string)$rawRsa->_id ]), 'Verification key'),
		$refused('rawdet', [ 'verifySignature' => 'detached', 'verifyCredential' => (string)$verRsa->_id ]) ]);
	    $this->check('FTP upload: a step type with its variable', [ true, true ], [ isset(\models\WfNode::getAll()['app::ftp-upload']), isset($U::outputVariables([])['upload']['fields']['files']['items']['fields']['remoteName']) ]);
	    $exe = $this->runFlow('ftp no server', [ $n('m', 'trigger::manual'), $n('f', 'app::ftp', [ 'credential' => (string)$ftp->_id, 'onError' => 'output', 'timeout' => 5 ]),
		$n('ok', 'app::set', [ 'variable' => 'route', 'value' => 'success' ]), $n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]),
		$e('e1', 'm', 'f'), $e('e2', 'f', 'ok', 0), $e('e3', 'f', 'err', 1) ]);
	    $this->check('FTP: no server -> the `error` output, the error in the variable', [ 'end', 'error', 0, true ],
		[ $exe?->state, $exe?->getVariable('route'), $exe?->getVariable('ftp')['count'] ?? null, (string)($exe?->getVariable('ftp')['error'] ?? '') !== '' ]);

	    //real servers
	    $list = getenv('CHECK_FTP_SERVERS');
	    if (!$list) {
		echo "  (real servers not checked: CHECK_FTP_SERVERS=<a JSON file>)\n";
		return;
	    }
	    foreach((array)json_decode((string)file_get_contents($list), true) as $srv)
		$this->ftpServer($srv, $cred, $work, $plain, [ 'pgp' => $pgp, 'enc' => $enc, 'pgpPub' => $pgpPub, 'cmsEnc' => $cmsEnc, 'cms' => $cms, 'pgpSign' => $pgpSign, 'rawEd' => $rawEd, 'edPub' => "{$work}/edpub.pem", 'verEd' => $verEd, 'verPgp' => $verPgp, 'verOther' => $verOther ]);
	} finally {
	    foreach($creds as $c)
		$c->delete();
	    $D::removeDir($work);
	}
    }

    //the FTP / SFTP step against a real server $srv (see suiteFtp): list, filters, download + decrypt + decompress, only new, move / delete afterwards
    private function ftpServer(array $srv, \Closure $cred, $work, $plain, array $keys) {
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$label = 'FTP ' . $srv['protocol'] . ':' . $srv['port'];
	$local = rtrim($srv['local'], '/') . '/check-' . uniqid();
	$remote = rtrim($srv['folder'], '/') . '/' . basename($local);
	mkdir("{$local}/sub", 0755, true);
	try {
	    file_put_contents("{$local}/a.csv", $plain);
	    file_put_contents("{$local}/b.CSV", 'b');
	    file_put_contents("{$local}/c.txt", 'c');
	    file_put_contents("{$local}/up.csv.part", 'p');
	    file_put_contents("{$local}/sub/deep.csv", 'deep');
	    copy("{$work}/data.csv.gpg", "{$local}/enc.csv.gpg");
	    copy("{$work}/data.csv.enc", "{$local}/enc2.csv.enc");
	    copy("{$work}/plain.csv.gz", "{$local}/z.csv.gz");
	    copy("{$work}/data.csv.enc", "{$local}/sub/bad.csv.gpg");
	    file_put_contents("{$local}/app.exe", 'x');
	    touch("{$local}/b.CSV", time() - 10 * 86400);
	    touch("{$local}/c.txt", time() - 30);
	    touch("{$local}/a.csv", time() - 3600);
	    $values = [ 'protocol' => $srv['protocol'], 'host' => $srv['host'], 'port' => $srv['port'], 'username' => $srv['username'] ];
	    if (!empty($srv['privateKeyFile']))
		$values += [ 'auth' => 'key', 'privateKey' => file_get_contents($srv['privateKeyFile']), 'passphrase' => (string)($srv['passphrase'] ?? '') ];
	    else
		$values += [ 'auth' => 'password', 'password' => (string)$srv['password'] ];
	    if (isset($srv['verifyTls']))
		$values['verifyTls'] = (bool)$srv['verifyTls'];
	    $c = $cred('CredentialFtp', $values);
	    if (!$c)
		return;
	    $tested = $c->test();
	    $this->check("{$label}: the credential's test (SFTP: the server key learned)", [ true, $srv['protocol'] === 'sftp' ],
		[ str_starts_with($tested, 'connected to '), $srv['protocol'] === 'sftp' && \util\FtpClient::isFingerprint(\V::app()->om->findById('Credential', (string)$c->_id)->hostKey) ]);

	    if ($srv['protocol'] === 'sftp') {
		$changed = clone $c;
		$changed->hostKey = str_repeat('ab:', 15) . 'ab';
		try {
		    $changed->test();
		    $refusedKey = 'connected';
		} catch (\RuntimeException $ex) {
		    $refusedKey = $ex->getMessage();
		}
		$this->check("{$label}: a server whose key changed is refused", true, str_starts_with($refusedKey, 'the server\'s key changed'));
	    }

	    $run = function($name, array $config) use ($n, $e, $c, $remote) {
		$exe = $this->runFlow($name, [ $n('m', 'trigger::manual'), $n('f', 'app::ftp', $config + [ 'credential' => (string)$c->_id, 'folder' => $remote, 'onError' => 'output' ]),
		    $e('e1', 'm', 'f') ]);
		$v = $exe?->getVariable('ftp') ?? [];
		$names = array_column($v['files'] ?? [], 'filename') ?: array_column($v['files'] ?? [], 'name');
		sort($names);
		return [ $exe, $v, $names ];
	    };
	    [ , $v, $names ] = $run("{$label} list", [ 'operation' => 'list' ]);
	    $this->check("{$label}: list (the default exclusions: .part)", [ [ 'a.csv', 'app.exe', 'b.CSV', 'c.txt', 'enc.csv.gpg', 'enc2.csv.enc', 'z.csv.gz' ], null ], [ $names, array_key_exists('error', $v) ? $v['error'] : 'unset' ]);
	    [ , , $names ] = $run("{$label} pattern", [ 'operation' => 'list', 'pattern' => '*.csv', 'subfolders' => true ]);
	    $this->check("{$label}: *.csv with subfolders (case insensitive)", [ 'a.csv', 'b.CSV', 'deep.csv' ], $names);
	    [ , $v, $names ] = $run("{$label} dates", [ 'operation' => 'list', 'pattern' => '*.csv, *.txt', 'modifiedAfter' => '-2 days', 'minAge' => 1 ]);
	    $this->check("{$label}: modified after -2 days, unchanged for a minute (exact times)", [ [ 'a.csv' ], true ], [ $names, str_ends_with((string)($v['files'][0]['modified'] ?? ''), 'Z') ]);
	    [ , , $names ] = $run("{$label} regex", [ 'operation' => 'list', 'pattern' => '/^enc\d?\./', 'order' => 'name', 'maxFiles' => 1 ]);
	    $this->check("{$label}: a regular expression, by name, one file per run", [ 'enc.csv.gpg' ], $names);

	    [ $exe, $v, $names ] = $run("{$label} download", [ 'pattern' => '*.csv, *.gpg, *.gz, *.exe', 'decrypt' => 'ext', 'decryptCredential' => (string)$keys['pgp']->_id, 'decompress' => true, 'onlyNew' => true ]);
	    $saved = [];
	    $flags = [];
	    foreach($v['files'] ?? [] as $f) {
		$saved[$f['filename']] = \V::app()->om->findById('File', $f['id'])?->getContents();
		$flags[$f['filename']] = [ $f['decrypted'], $f['decompressed'] ];
	    }
	    ksort($flags);
	    $this->check("{$label}: download, decrypted (.gpg), decompressed (.gz), an .exe skipped", [ [ 'a.csv', 'b.CSV', 'enc.csv', 'z.csv' ], $plain, 'b', $plain, $plain, [ 'app.exe' ],
		[ 'a.csv' => [ false, false ], 'b.CSV' => [ false, false ], 'enc.csv' => [ true, false ], 'z.csv' => [ false, true ] ] ],
		[ $names, $saved['a.csv'] ?? null, $saved['b.CSV'] ?? null, $saved['enc.csv'] ?? null, $saved['z.csv'] ?? null, array_map('basename', array_column($v['skipped'] ?? [], 'path')), $flags ]);
	    //the same workflow again (the state is per workflow and step): nothing new; a changed file is new again
	    $again = function() use ($exe) {
		$x = \models\WfExecution::startFor(\V::app()->om->findById('Workflow', (string)$exe->wf), 'console', [ 'vars' => [] ]);
		$x->run();
		return $x->getVariable('ftp') ?? [];
	    };
	    $second = $again();
	    file_put_contents("{$local}/b.CSV", 'b2');
	    touch("{$local}/b.CSV", time() - 9 * 86400);
	    $third = $again();
	    $this->check("{$label}: only new files: none the second time, a changed file the third", [ 0, [ 'b.CSV' ] ], [ $second['count'] ?? null, array_column($third['files'] ?? [], 'filename') ]);
	    foreach(\V::app()->om->find('File', [ 'metadata.source' => 'ftp', 'metadata.remote' => [ '$regex' => '^' . preg_quote($remote) ] ]) as $f)
		$f->delete();

	    [ , $v ] = $run("{$label} move", [ 'pattern' => '*.enc', 'decrypt' => 'all', 'decryptCredential' => (string)$keys['enc']->_id, 'after' => 'move', 'moveTo' => $remote . '/done' ]);
	    $this->check("{$label}: openssl decrypt, moved afterwards (folder made)", [ 1, 'enc2.csv', true, false ],
		[ $v['count'] ?? null, $v['files'][0]['filename'] ?? null, is_file("{$local}/done/enc2.csv.enc"), is_file("{$local}/enc2.csv.enc") ]);
	    [ , $v ] = $run("{$label} delete", [ 'pattern' => 'a.csv', 'after' => 'delete' ]);
	    $this->check("{$label}: deleted afterwards", [ 1, false ], [ $v['count'] ?? null, is_file("{$local}/a.csv") ]);
	    [ $exe, $v ] = $run("{$label} not decrypted", [ 'folder' => $remote . '/sub', 'pattern' => '*.gpg', 'decrypt' => 'ext', 'decryptCredential' => (string)$keys['pgp']->_id ]);
	    $this->check("{$label}: a file that cannot be decrypted -> an error, nothing saved", [ 0, true, true ],
		[ $v['count'] ?? null, str_contains((string)($v['error'] ?? ''), 'bad.csv.gpg: gpg: '), self::logLine($exe, 'ftp download ') !== null ]);
	    //CLD: 2026-10-20T18:00:00+03:00: the Upload step: compressed + encrypted into a folder made for it, the name there already (skip, a number, an error, replace),
	    //a text, without .part; then downloaded back by the FTP / SFTP step (decrypted, decompressed)
	    $fid = (string)\models\File::createFromString('orders.csv', $plain, [ 'metadata' => [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'source' => 'check' ] ]);
	    $up = function($name, array $config) use ($n, $e, $c, $remote) {
		$exe = $this->runFlow($name, [ $n('m', 'trigger::manual'), $n('u', 'app::ftp-upload', $config + [ 'credential' => (string)$c->_id, 'folder' => $remote . '/out/new', 'onError' => 'output' ]),
		    $e('e1', 'm', 'u') ]);
		return $exe?->getVariable('upload') ?? [];
	    };
	    try {
		$base = [ 'files' => $fid, 'compress' => true, 'encrypt' => true, 'encryptCredential' => (string)$keys['pgpPub']->_id ];
		$v = $up("{$label} upload", $base);
		$there = "{$local}/out/new/orders.csv.gz.gpg";
		$back = null;
		if (is_file($there)) {
		    \util\FileDecrypt::decrypt($keys['pgp'], $there, "{$work}/up1", 1048576);
		    \util\FileDecrypt::decompress("{$work}/up1", "{$work}/up2", 'gzip', 1048576);
		    $back = file_get_contents("{$work}/up2");
		}
		$this->check("{$label}: upload: compressed + encrypted, folders made, no .part left, the size checked", [ 1, 'orders.csv.gz.gpg', $plain, [], filesize($there) ?: -1, null ],
		    [ $v['count'] ?? null, $v['files'][0]['remoteName'] ?? null, $back, glob("{$local}/out/new/*.part"), $v['files'][0]['size'] ?? null, array_key_exists('error', $v) ? $v['error'] : 'unset' ]);
		$skip = $up("{$label} upload skip", $base + [ 'ifExists' => 'skip' ]);
		$uniq = $up("{$label} upload unique", $base + [ 'ifExists' => 'unique' ]);
		$fail = $up("{$label} upload fail", $base + [ 'ifExists' => 'fail' ]);
		$over = $up("{$label} upload overwrite", $base + [ 'ifExists' => 'overwrite', 'safeUpload' => false ]);
		$overSafe = $up("{$label} upload overwrite safe", $base);
		$this->check("{$label}: upload: a file there already: skipped, numbered, an error, replaced (direct and as .part)", [ [ 0, 1 ], 'orders_1.csv.gz.gpg', true, [ true, true ], [] ], [
		    [ $skip['count'] ?? null, count($skip['skipped'] ?? []) ], $uniq['files'][0]['remoteName'] ?? null, str_contains((string)($fail['error'] ?? ''), 'is there already'),
		    [ $over['files'][0]['replaced'] ?? null, $overSafe['files'][0]['replaced'] ?? null ], glob("{$local}/out/new/*.part") ]);
		$t = $up("{$label} upload text", [ 'source' => 'text', 'content' => "a;b
1;2
", 'filename' => 't_{n}.csv', 'encrypt' => true, 'encryptCredential' => (string)$keys['cmsEnc']->_id ]);
		$tBack = null;
		if (is_file("{$local}/out/new/t_1.csv.p7m")) {
		    \util\FileDecrypt::decrypt($keys['cms'], "{$local}/out/new/t_1.csv.p7m", "{$work}/up3", 1048576);
		    $tBack = file_get_contents("{$work}/up3");
		}
		$this->check("{$label}: upload: a text, S/MIME encrypted", [ 't_1.csv.p7m', null, "a;b
1;2
" ], [ $t['files'][0]['remoteName'] ?? null, array_key_exists('fileId', $t['files'][0] ?? []) ? $t['files'][0]['fileId'] : 'unset', $tBack ]);
		//signed: a detached Ed25519 signature next to the file (sent before it), checked against the file on the server; PGP signed + encrypted, read back below
		$sd = $up("{$label} upload signed detached", [ 'files' => $fid, 'filename' => 'signed.csv', 'sign' => true, 'signCredential' => (string)$keys['rawEd']->_id, 'signMode' => 'detached' ]);
		$sigOk = null;
		if (is_file("{$local}/out/new/signed.csv.sig")) {
		    file_put_contents("{$work}/remote.sig", base64_decode((string)file_get_contents("{$local}/out/new/signed.csv.sig")));
		    exec('openssl pkeyutl -verify -pubin -inkey ' . escapeshellarg($keys['edPub']) . ' -rawin -in ' . escapeshellarg("{$local}/out/new/signed.csv") . ' -sigfile ' . escapeshellarg("{$work}/remote.sig") . ' 2>&1', $vo, $vc);
		    $sigOk = $vc === 0;
		}
		$this->check("{$label}: upload: a detached signature next to the file, verified on the server's copy", [ true, true, $remote . '/out/new/signed.csv.sig', [] ],
		    [ $sd['files'][0]['signed'] ?? null, $sigOk, $sd['files'][0]['signature'] ?? null, glob("{$local}/out/new/*.part") ]);
		$se = $up("{$label} upload signed encrypted", [ 'files' => $fid, 'filename' => 'se.csv', 'sign' => true, 'signCredential' => (string)$keys['pgpSign']->_id,
		    'encrypt' => true, 'encryptCredential' => (string)$keys['pgpPub']->_id ]);
		[ , $sv ] = $run("{$label} download signed", [ 'folder' => $remote . '/out/new', 'pattern' => 'se.csv.gpg', 'decrypt' => 'ext', 'decryptCredential' => (string)$keys['pgp']->_id ]);
		$this->check("{$label}: upload: PGP signed + encrypted (one .gpg), downloaded and decrypted by the FTP / SFTP step", [ 'se.csv.gpg', null, 'se.csv', $plain ],
		    [ $se['files'][0]['remoteName'] ?? null, array_key_exists('signature', $se['files'][0] ?? []) ? $se['files'][0]['signature'] : 'unset', $sv['files'][0]['filename'] ?? null,
			isset($sv['files'][0]['id']) ? \V::app()->om->findById('File', $sv['files'][0]['id'])?->getContents() : null ]);

		//CLD: 2026-10-20T20:00:00+03:00: signatures checked by the FTP / SFTP step: a separate one (the other files wait for theirs, the .sig is not a file), a changed
		//file refused, moved with its signature; signed + encrypted by a trusted key, by a key not trusted
		$newDir = $remote . '/out/new';
		$vdet = [ 'folder' => $newDir, 'verifySignature' => 'detached', 'verifyCredential' => (string)$keys['verEd']->_id ];
		file_put_contents("{$local}/out/new/unsigned.csv", 'u');
		[ , $v ] = $run("{$label} verify detached", $vdet);
		$this->check("{$label}: verify: a separate signature checked; files without one wait; the signature not taken as a file", [ [ 'signed.csv' ], true, 'Ed25519 key', $newDir . '/signed.csv.sig', true, false, null ], [
		    array_column($v['files'] ?? [], 'filename'), $v['files'][0]['signed'] ?? null, $v['files'][0]['signer'] ?? null, $v['files'][0]['signature'] ?? null,
		    in_array([ 'path' => $newDir . '/unsigned.csv', 'reason' => 'no signature file yet' ], $v['skipped'] ?? [], true), in_array($newDir . '/signed.csv.sig', array_column($v['skipped'] ?? [], 'path'), true),
		    array_key_exists('error', $v) ? $v['error'] : 'unset' ]);
		[ , $v ] = $run("{$label} verify missing as error", $vdet + [ 'pattern' => 'unsigned.csv', 'missingSignature' => 'error' ]);
		$this->check("{$label}: verify: no signature file as an error", [ 0, true ], [ $v['count'] ?? null, str_contains((string)($v['error'] ?? ''), 'no signature file') ]);
		$orig = file_get_contents("{$local}/out/new/signed.csv");
		file_put_contents("{$local}/out/new/signed.csv", $orig . 'x');
		[ , $v ] = $run("{$label} verify changed", $vdet + [ 'pattern' => 'signed.csv*' ]);
		file_put_contents("{$local}/out/new/signed.csv", $orig);
		$this->check("{$label}: verify: a changed file refused, nothing saved", [ 0, true ], [ $v['count'] ?? null, str_contains((string)($v['error'] ?? ''), 'signed.csv: bad signature') ]);
		[ , $v ] = $run("{$label} verify moved", $vdet + [ 'pattern' => 'signed.csv*', 'after' => 'move', 'moveTo' => $newDir . '/done' ]);
		$this->check("{$label}: verify: moved afterwards with its signature", [ 1, true, true, false ], [ $v['count'] ?? null, is_file("{$local}/out/new/done/signed.csv"),
		    is_file("{$local}/out/new/done/signed.csv.sig"), is_file("{$local}/out/new/signed.csv.sig") ]);
		$vatt = [ 'folder' => $newDir, 'pattern' => 'se.csv.gpg', 'decrypt' => 'ext', 'decryptCredential' => (string)$keys['pgp']->_id, 'verifySignature' => 'attached' ];
		[ , $v ] = $run("{$label} verify attached", $vatt + [ 'verifyCredential' => (string)$keys['verPgp']->_id ]);
		[ , $w ] = $run("{$label} verify attached other", $vatt + [ 'verifyCredential' => (string)$keys['verOther']->_id ]);
		$this->check("{$label}: verify: signed + encrypted by a trusted key (decrypted and checked); by a key not trusted: refused", [ [ 'se.csv', true, 'Check <check@example.com>' ], [ 0, true ] ], [
		    [ $v['files'][0]['filename'] ?? null, $v['files'][0]['signed'] ?? null, $v['files'][0]['signer'] ?? null ], [ $w['count'] ?? null, str_contains((string)($w['error'] ?? ''), 'not one of the trusted keys') ] ]);

		[ , $v ] = $run("{$label} download uploaded", [ 'folder' => $remote . '/out/new', 'pattern' => 'orders.csv.gz.gpg', 'decrypt' => 'ext', 'decryptCredential' => (string)$keys['pgp']->_id, 'decompress' => true ]);
		$this->check("{$label}: uploaded, then downloaded by the FTP / SFTP step", [ 'orders.csv', $plain ],
		    [ $v['files'][0]['filename'] ?? null, isset($v['files'][0]['id']) ? \V::app()->om->findById('File', $v['files'][0]['id'])?->getContents() : null ]);
	    } finally {
		\V::app()->om->findById('File', $fid)?->delete();
	    }

	    [ , $v ] = $run("{$label} missing folder", [ 'folder' => $remote . '/nope' ]);
	    $this->check("{$label}: a folder that does not exist -> an error", true, str_contains((string)($v['error'] ?? ''), 'cannot be read'));
	    foreach(\V::app()->om->find('File', [ 'metadata.source' => 'ftp', 'metadata.remote' => [ '$regex' => '^' . preg_quote($remote) ] ]) as $f)
		$f->delete();
	} catch (\Throwable $ex) {
	    $this->fail("{$label}: " . get_class($ex) . ': ' . $ex->getMessage() . ' (' . basename($ex->getFile()) . ':' . $ex->getLine() . ')');
	} finally {
	    \util\FileDecrypt::removeDir($local);
	}
    }

    private function suiteEmail() {
	$B = \models\EmailBlocks::class;
	$clean = $B::clean('<p style="color: red; position: absolute" onclick="x()">Hi <script>alert(1)</script><a href="javascript:x()">a</a> <a href="{{ link }}">b</a></p><table><tr><td>t</td></tr></table>');
	$this->check('blocks: the HTML cleaned (no script / handler / javascript link; style filtered; {{ }} kept in links; unknown tags unwrapped)',
	    '<p style="color: red">Hi <a>a</a> <a href="{{ link }}">b</a></p>t', $clean);
	$this->check('blocks: errors (no blocks; an image without a file)', [ [ 'add at least one block' ], [ 'block 1: choose an image' ] ],
	    [ $B::errors($B::normalize([])), $B::errors($B::normalize([ 'blocks' => [ [ 'type' => 'image' ] ] ])) ]);

	$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
	$fid = (string)\models\File::createFromString('check-logo.png', $png, [ 'metadata' => [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'source' => 'check' ] ]);
	$webp = (string)\models\File::createFromString('check.webp', 'x', [ 'metadata' => [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'source' => 'check' ] ]);
	try {
	    $email = [ 'preheader' => 'Order {{ order }}', 'blocks' => [
		[ 'type' => 'image', 'file' => $fid, 'alt' => 'Logo', 'width' => 200, 'href' => 'https://netflow9.io/{{ order }}' ],
		[ 'type' => 'html', 'html' => '<p>Hello {{ name }} &amp; <a href="{{ bad }}">x</a> <a href="{{ good }}">y</a></p>' ] ] ];
	    $r = \util\EmailHtml::render($email, [ 'name' => '<b>Ana</b>', 'order' => 'A-1', 'bad' => 'javascript:alert(1)', 'good' => 'https://q.ro/?a=1&b=2' ], 'S');
	    $this->check('html: values escaped, a javascript link dropped, Outlook table, preheader, the image by cid, the text version', [ true, false, true, true, true, true ],
		[ str_contains($r['html'], 'Hello &lt;b&gt;Ana&lt;/b&gt;'), str_contains($r['html'], 'javascript:'), str_contains($r['html'], '<!--[if mso]><table role="presentation" width="600"'),
		  str_contains($r['html'], 'Order A-1'), (bool)preg_match('~src="cid:([^"]+)"~', $r['html']) && array_values($r['images']) === [ $fid ],
		  str_contains($r['text'], 'Hello <b>Ana</b> & x y (https://q.ro/?a=1&b=2)') && str_contains($r['text'], '[Logo] (https://netflow9.io/A-1)') ]);

	    $file = \V::app()->om->findById('File', $fid);
	    [ $h, $body ] = \util\EmailSend::compose([ 'from' => 'noreply@x.ro', 'from_name' => "NetFlow9\r\nBcc: e@x.ro", 'to' => [ 'a@x.ro' ], 'subject' => "Bună ziua\r\nX-Evil: 1",
		'html' => $r['html'], 'text' => $r['text'], 'images' => array_map(fn($f) => $file, $r['images']) ]);
	    $back = new \util\MailParser($h . "\r\n\r\n" . $body);
	    $att = $back->attachments()[0] ?? [];
	    $this->check('mime: read back (subject, name kept on one line, html, the image inline, no injected header)', [ 'Bună ziua  X-Evil: 1', 'NetFlow9  Bcc: e@x.ro', true, 'inline', true, false ],
		[ $back->subject(), $back->from()['name'] ?? null, str_contains($back->html(), 'cid:'), $att['disposition'] ?? null, ($att['content'] ?? null) === $png, (bool)preg_match('/^(Bcc|X-Evil):/mi', $h) ]);

	    $n = [ self::class, 'node' ];
	    $e = [ self::class, 'edge' ];
	    $cfg = [ 'to' => '{{ who }}', 'cc' => '', 'bcc' => '', 'subject' => 'Hi {{ name }}', 'fromName' => 'NetFlow9', 'replyTo' => '', 'email' => $email ];
	    $before = \V::app()->om->count('Job', [ 'job_spec' => '\util\EmailSend::deliver' ]);
	    //CLD: 2026-10-17T15:10:00+03:00: the Send email step is app::send-email (was app::email)
	    $exe = $this->runFlow('email sent', [ $n('m', 'trigger::manual'), $n('s', 'app::send-email', $cfg), $e('e1', 'm', 's') ], [ 'who' => 'x@example.com, y@example.com', 'name' => 'Ana', 'order' => 'A-1', 'bad' => '', 'good' => 'https://q.ro' ]);
	    $job = \V::app()->om->findOne('Job', [ 'job_spec' => '\util\EmailSend::deliver' ], [ 'sort' => [ '_id' => -1 ] ]);
	    $data = $job ? json_decode(\util\JSON::encode($job->job_data), true) : [];
	    $this->check('step: the run goes on, one job queued with the rendered email', [ 'end', $before + 1, [ 'x@example.com', 'y@example.com' ], 'Hi Ana', true ],
		[ $exe?->state, \V::app()->om->count('Job', [ 'job_spec' => '\util\EmailSend::deliver' ]), $data['to'] ?? null, $data['subject'] ?? null, str_contains($data['html'] ?? '', 'Hello Ana') ]);
	    if ($job)
		$job->delete();
	    $exe = $this->runFlow('email bad address', [ $n('m', 'trigger::manual'), $n('s', 'app::send-email', [ 'to' => 'not-an-address' ] + $cfg), $e('e1', 'm', 's') ], [ 'name' => 'A', 'order' => 1, 'bad' => '', 'good' => '' ]);
	    $this->check('step: a wrong address fails the run', [ 'error', true ], [ $exe?->state, str_contains((string)$exe?->error, '`not-an-address` (to) is not an e-mail address') ]);
	    $email['blocks'][0]['file'] = $webp;
	    $this->check('step: a WebP image is refused when saved', true, (bool)array_filter($this->errorsOf('email webp', [ $n('m', 'trigger::manual'), $n('s', 'app::send-email', [ 'email' => $email ] + $cfg), $e('e1', 'm', 's') ]),
		fn($m) => str_contains($m, 'use a PNG, JPEG or GIF image')));
	} finally {
	    foreach([ $fid, $webp ] as $id)
		\V::app()->om->findById('File', $id)?->delete();
	}
	//CLD: 2026-10-19T11:21:19+03:00: the sign-up is the Registration plugin (its classes; checked only when the plugin is enabled in app/plugins.php)
	if (isset(\V::app()->Registration)) {
		//CLD: 2026-10-16T12:19:22+03:00: sign-up: a link's token finds its sign-up once (only its hash is stored), the system email (data\SysEmails) queued with the link, the form's password rules
		[ $signup, $token ] = \plugins\Registration\models\Signup::start('zz-check@example.com');
		//CLD: 2026-10-18T23:35:30+03:00: the link as SiteController builds it (V::createAbsoluteUrl; was https://x.ro/...)
		$job = \util\EmailSend::system('signup_confirm', 'zz-check@example.com', [ 'link' => \V::app()->createAbsoluteUrl('/Registration/register/confirm', [ 't' => $token ]) ]);
		$jobDoc = \V::app()->om->findById('Job', (string)$job);
		$found = \plugins\Registration\models\Signup::findByToken($token);
		$this->check('signup: the token finds it, the hash is stored (not the token), the email queued with the link', [ true, false, true, 'Confirm your email for ' . \V::app()->config('app.name') ],
		    [ $found && (string)$found->_id === (string)$signup->_id, $signup->token_hash === $token, str_contains((string)($jobDoc->job_data['html'] ?? ''), $token), $jobDoc->job_data['subject'] ?? null ]);
		$jobDoc?->delete();
		$signup->delete();
		$form = \plugins\Registration\models\RegisterForm::create([], [ 'validateCurrentPassword' => false ]);
		$form->assign([ 'account_name' => 'Co', 'firstname' => 'A', 'lastname' => 'B', 'new_password' => 'weak', 'cfm_password' => 'other' ]);
		$form->validates();
		$this->check('signup: the form refuses a weak password and a different confirmation', [ 'cfm_password', 'new_password' ], array_values(array_intersect([ 'cfm_password', 'new_password' ], array_keys($form->errors()))));
	}
	//CLD: 2026-10-16T13:22:53+03:00: forgot username or password: a reset link's token finds it (only its hash stored), a new link replaces the old one, an expired one is refused, the email holds the
	//username and the link
	//CLD: 2026-10-24T08:20:00+03:00: the link is the user's (User::startPasswordReset / findByResetToken / clearPasswordReset; was the PasswordReset model)
	$user = \V::app()->om->findOne('User', [ 'state' => 'active' ]);
	$oldToken = $user->startPasswordReset();
	$token = $user->startPasswordReset('203.0.113.9');
	$found = \models\User::findByResetToken($token);
	$raw = \V::app()->mongo->selectCollection(\models\User::_database(), \models\User::_collection())->findOne([ '_id' => $user->_id ]);

	//CLD: 2026-10-18T23:35:30+03:00: the link as SiteController builds it (V::createAbsoluteUrl; was https://x.ro/...)
	$job = \util\EmailSend::system('password_reset', 'zz-check@example.com', [ 'username' => 'zz-user', 'email' => 'zz-check@example.com', 'account' => 'Co',
	    'link' => \V::app()->createAbsoluteUrl('/site/reset', [ 't' => $token ]) ]);
	$jobDoc = \V::app()->om->findById('Job', (string)$job);
	$html = (string)($jobDoc->job_data['html'] ?? '');
	$this->check('reset: the token finds its user, only the hash is stored (with the address), the older link is gone, the email has the username and the link', [ true, true, '203.0.113.9', null, true, true ],
	    [ $found && (string)$found->_id === (string)$user->_id, ($raw['reset_token_hash'] ?? null) === hash('sha256', $token), $raw['reset_ip'] ?? null, \models\User::findByResetToken($oldToken), str_contains($html, 'zz-user'), str_contains($html, $token) ]);
	\V::app()->om->updateOne('User', [ '_id' => $user->_id, 'aid' => $user->aid ], [ '$set' => [ 'reset_expires_at' => new \MongoDB\BSON\UTCDateTime((time() - 1) * 1000) ] ]);
	$expired = \models\User::findByResetToken($token);
	$token = $user->startPasswordReset();
	$user->clearPasswordReset();
	$raw = \V::app()->mongo->selectCollection(\models\User::_database(), \models\User::_collection())->findOne([ '_id' => $user->_id ]);
	$this->check('reset: an expired link is refused; a used one (cleared) too, and the user keeps no reset field', [ null, null, false ],
	    [ $expired, \models\User::findByResetToken($token), isset($raw['reset_token_hash']) || isset($raw['reset_expires_at']) || isset($raw['reset_ip']) ]);
	$jobDoc?->delete();
    }

    //CLD: 2026-10-17T22:00:00+03:00: project invitations: a link's token finds it (only its hash stored), a new one replaces the address's older one, pending lists it, the email has the link
    //and the project's name escaped, accepting adds the user with the invitation's role (to each project invited to) and uses the invitations up, the form's password rules
    private function suiteInvite() {
	$om = \V::app()->om;
	$role = $om->findOne('Role', [ 'aid' => $this->account->_id, 'iname' => 'member' ]);
	$projects = [];
	foreach([ 'a', 'b' ] as $k) {
	    $p = $om->create('Project', [ 'aid' => $this->account->_id, 'name' => "Check <invite> {$k}", 'owner' => $this->project->owner, 'members' => [] ]);
	    $p->save();
	    $projects[$k] = $p;
	}
	$user = null;
	try {
	    [ , $oldToken ] = \models\user\ProjectInvite::start($projects['a'], 'ZZ-Invite@example.com', $role->_id);
	    [ $invite, $token ] = \models\user\ProjectInvite::start($projects['a'], 'zz-invite@example.com', $role->_id);
	    \models\user\ProjectInvite::start($projects['b'], 'zz-invite@example.com', $role->_id);
	    $found = \models\user\ProjectInvite::findByToken($token);
	    $this->check('invite: the token finds it, the hash is stored, the older link is gone, pending lists one', [ true, false, null, 1, 'zz-invite@example.com' ],
		[ $found && (string)$found->_id === (string)$invite->_id, $invite->token_hash === $token, \models\user\ProjectInvite::findByToken($oldToken),
		count(iterator_to_array(\models\user\ProjectInvite::pending($projects['a']), false)), $invite->email ]);

	    $job = \util\EmailSend::system('project_invite', 'zz-invite@example.com', [ 'inviter' => 'A B', 'project' => (string)$projects['a']->name, 'account' => 'Co',
		//CLD: 2026-10-18T23:35:30+03:00: the link as ProjectController builds it (V::createAbsoluteUrl; was https://x.ro/...)
		'link' => \V::app()->createAbsoluteUrl('/site/invite', [ 't' => $token ]), 'days' => 7 ]);
	    $jobDoc = $om->findById('Job', (string)$job);
	    $html = (string)($jobDoc->job_data['html'] ?? '');
	    $this->check('invite: the email has the link and the project escaped', [ true, true, false ], [ str_contains($html, $token), str_contains($html, 'Check &lt;invite&gt; a'), str_contains($html, '<invite>') ]);
	    $jobDoc?->delete();

	    $user = $om->create('User', [ 'aid' => $this->account->_id, 'email' => 'zz-invite@example.com', 'firstname' => 'Zz', 'lastname' => 'Invite', 'state' => 'active', 'role' => $role->_id ]);
	    $user->save();
	    $joined = $invite->accept($user);
	    $reload = fn($k) => $om->findById('Project', (string)$projects[$k]->_id);
	    $this->check('invite: accepting joins both projects with the role, the invitations are used up', [ 2, (string)$role->_id, (string)$role->_id, 0 ],
		[ count($joined), (string)($reload('a')->members[(string)$user->_id]['role'] ?? ''), (string)($reload('b')->members[(string)$user->_id]['role'] ?? ''),
		$om->count('ProjectInvite', [ 'email' => 'zz-invite@example.com' ]) ]);

	    $form = \models\user\InviteForm::create([], [ 'validateCurrentPassword' => false ]);
	    $form->assign([ 'firstname' => '', 'lastname' => 'B', 'new_password' => 'weak', 'cfm_password' => 'other' ]);
	    $form->validates();
	    $this->check('invite: the form refuses an empty name, a weak password and a different confirmation', [ 'cfm_password', 'firstname', 'new_password' ],
		array_values(array_intersect([ 'cfm_password', 'firstname', 'new_password' ], array_keys($form->errors()))));
	} finally {
	    foreach($om->find('ProjectInvite', [ 'email' => 'zz-invite@example.com' ]) as $i)
		$i->delete();
	    $user?->delete();
	    foreach($projects as $p)
		$p->delete();
	}
    }

    //CLD: 2026-10-18T23:35:30+03:00: V::createAbsoluteUrl: the base is app.base_url (app.env BASE_URL), else the request host; always https:// (an http:// or bare base too); the links built with it
    private function suiteUrls() {
	$app = \V::app();
	$keep = [ $app->config('app.base_url'), $app->request->httpHost ];
	try {
	    $bases = [ 'https://example.com', 'http://example.com/', 'example.com:8443/', '//example.com' ];
	    $urls = [];
	    foreach($bases as $b) {
		$app->config('app.base_url', $b);
		$urls[] = $app->createAbsoluteUrl('/site/login');
	    }
	    $this->check('createAbsoluteUrl: any base becomes https://', [ 'https://example.com/site/login', 'https://example.com/site/login', 'https://example.com:8443/site/login', 'https://example.com/site/login' ], $urls);

	    $app->config('app.base_url', 'https://example.com');
	    $this->check('createAbsoluteUrl: params as a query', 'https://example.com/site/invite?t=abc', $app->createAbsoluteUrl('/site/invite', [ 't' => 'abc' ]));

	    //no BASE_URL: the request host (config() does not unset with null, so the key is emptied)
	    $app->config('app.base_url', '');
	    $app->request->httpHost = 'host.example';
	    $this->check('createAbsoluteUrl: without BASE_URL, the request host', 'https://host.example/mcp', $app->createAbsoluteUrl('/mcp'));

	    $app->config('app.base_url', 'https://example.com');
	    $form = \models\node\NodeTriggerForm::initConfig();
	    $hook = \models\node\NodeTriggerWebhook::initConfig();
	    $this->check('links: a Form / Webhook step and the OAuth callback on BASE_URL', [ 'https://example.com/form/' . $form['token'], 'https://example.com/hook/' . $hook['token'], 'https://example.com/credential/oauth_callback' ],
		[ $form['url'], $hook['url'], \models\credential\CredentialOAuth2::redirectUri() ]);
	} finally {
	    $app->config('app.base_url', (string)$keep[0]);
	    $app->request->httpHost = $keep[1];
	}
    }

    //CLD: 2026-10-18T12:00:00+03:00: sys\fields\Field_select: a value not among the choices is not kept (single) / refused (multiple; choices as text, as value => label, as a plain list)
    private function suiteSelect() {
	//CLD: 2026-10-18T13:00:00+03:00: sys\NestedModel (was models\WfNodeConfig; option nestedSchema, was configSchema)
	$model = \sys\NestedModel::create([], [ 'objectName' => 'NestedModel', 'nestedSchema' => [
	    [ 'key' => 'text', 'type' => 'select', 'label' => 'Text', 'choices' => "a\nb\n" ],
	    [ 'key' => 'map', 'type' => 'select', 'label' => 'Map', 'choices' => [ 'x' => 'Ex', 'y' => 'Why' ] ],
	    [ 'key' => 'list', 'type' => 'select', 'label' => 'List', 'choices' => [ 'p', 'q' ], 'choicesAreValues' => true ],
	    [ 'key' => 'many', 'type' => 'select', 'label' => 'Many', 'choices' => [ 'a' => 'A', 'b' => 'B', 'c' => 'C' ], 'multiple' => true ],
	] ]);
	$cast = function($key, $value) use ($model) {
	    $model->assign([ $key => $value ]);
	    return $model->$key;
	};

	$this->check('select: a choice is kept (text, value => label, plain list)', [ 'b', 'y', 'q' ],
	    [ $cast('text', 'b'), $cast('map', 'y'), $cast('list', 'q') ]);
	$this->check('select: a value not among the choices is not kept (text, value => label, plain list)', [ null, null, null ],
	    [ $cast('text', 'zz'), $cast('map', 'zz'), $cast('list', 'zz') ]);
	$this->check('select: a label is not a value', null, $cast('map', 'Why'));
	$this->check('select: the index of a plain list is not a value', null, $cast('list', '1'));
	$this->check('select: empty is null', [ null, null ], [ $cast('text', ''), $cast('text', null) ]);

	$this->check('select multiple: choices kept, blanks and repeats dropped', [ 'a', 'c' ], $cast('many', [ 'a', '', 'c', 'a' ]));
	//CLD: 2026-10-18T15:00:00+03:00: values not among the choices are dropped (were kept and refused by validation)
	$this->check('select multiple: values not among the choices are dropped', [ 'b' ], $cast('many', [ 'b', 'zz', 'B' ]));
	$this->check('select multiple: from JSON text', [ 'a', 'b' ], $cast('many', '["a","zz","b"]'));

	//CLD: 2026-10-18T13:10:00+03:00: a value not among the choices is refused by validation (multiple: kept by the cast, so a filter is not widened)
	//CLD: 2026-10-18T15:00:00+03:00: the cast drops them: validation refuses values not cast (e.g. given to create())
	$errorsFor = function($values) use ($model) {
	    $model->assign([ 'text' => 'a', 'map' => 'x', 'list' => 'p', 'many' => [ 'a' ] ]);
	    $model->assign($values);
	    $model->validates();
	    return array_intersect_key($model->errors(), [ 'text' => 1, 'map' => 1, 'list' => 1, 'many' => 1 ]);
	};
	$this->check('select: choices validate', [], $errorsFor([ 'many' => [ 'a', 'c' ] ]));
	//CLD: 2026-10-18T13:00:00+03:00: sys\NestedModel (was models\WfNodeConfig; option nestedSchema, was configSchema)
	$uncast = \sys\NestedModel::create([ 'many' => [ 'a', 'zz' ] ], [ 'objectName' => 'NestedModel', 'nestedSchema' => [ [ 'key' => 'many', 'type' => 'select', 'label' => 'Many', 'choices' => [ 'a' => 'A' ], 'multiple' => true ] ] ]);
	$this->check('select multiple: a value not cast, not among the choices, is refused', [ false, [ 'Not one of the choices' ] ], [ $uncast->validates(), $uncast->errors()['many'] ?? null ]);
	$this->check('select: empty passes (not required)', [], $errorsFor([ 'text' => '', 'many' => [] ]));
	//CLD: 2026-10-18T13:00:00+03:00: sys\NestedModel (was models\WfNodeConfig; option nestedSchema, was configSchema)
	$dynamic = \sys\NestedModel::create([], [ 'objectName' => 'NestedModel', 'nestedSchema' => [ [ 'key' => 'who', 'type' => 'select', 'label' => 'Who', 'multiple' => true, 'choices' => fn() => [] ] ] ]);
	$dynamic->assign([ 'who' => [ 'u1' ] ]);
	$dynamic->validates();
	//CLD: 2026-10-18T14:40:00+03:00: `choicesOn`: the choices checked on these events only (models\datatable\FieldForm's `object`: on create)
	//CLD: 2026-10-18T13:00:00+03:00: sys\NestedModel (was models\WfNodeConfig; option nestedSchema, was configSchema)
	$locked = fn($exists) => \sys\NestedModel::create([ 'k' => 'gone' ], [ 'objectName' => 'NestedModel', 'exists' => $exists,
	    'nestedSchema' => [ [ 'key' => 'k', 'type' => 'select', 'label' => 'K', 'choices' => [ 'a' => 'A' ], 'choicesOn' => 'create' ] ] ]);
	$this->check('select: choicesOn create: refused on create, not on update', [ false, true ], [ $locked(false)->validates(), $locked(true)->validates() ]);
	$this->check('select: no choices (e.g. depending on the user): not checked', [ [ 'u1' ], false ], [ $dynamic->who, isset($dynamic->errors()['who']) ]);
	//CLD: 2026-10-18T11:20:00+03:00: a single select keeps its value too when there are no choices (as the multiple select)
	//CLD: 2026-10-18T13:00:00+03:00: sys\NestedModel (was models\WfNodeConfig; option nestedSchema, was configSchema)
	$single = \sys\NestedModel::create([], [ 'objectName' => 'NestedModel', 'nestedSchema' => [ [ 'key' => 'who', 'type' => 'select', 'label' => 'Who', 'choices' => fn() => [] ] ] ]);
	$single->assign([ 'who' => 'u1' ]);
	$this->check('select single: no choices: the value kept, not checked', [ 'u1', true ], [ $single->who, $single->validates() ]);
    }

    //CLD: 2026-10-18T09:42:21+03:00: sys\fields\Field_nested: one object and a list of them, cast by the nested fields (unknown keys dropped, defaults set), the
    //nested objects validated by the model's validates() (errors keyed by path), nested in nested, values that are not objects refused
    //CLD: 2026-10-18T09:58:40+03:00: + `object`: the nested fields are that object's schema
    private function suiteNested() {
	$address = [
	    [ 'key' => 'city', 'type' => 'text', 'label' => 'City', 'required' => true ],
	    [ 'key' => 'kind', 'type' => 'select', 'label' => 'Kind', 'choices' => [ 'home' => 'Home', 'work' => 'Work' ], 'default' => 'home' ],
	    [ 'key' => 'floor', 'type' => 'number', 'label' => 'Floor' ],
	];
	$schema = [
	    [ 'key' => 'name', 'type' => 'text', 'label' => 'Name' ],
	    [ 'key' => 'main', 'type' => 'nested', 'label' => 'Main address', 'fields' => $address ],
	    [ 'key' => 'others', 'type' => 'nested', 'label' => 'Other addresses', 'fields' => $address, 'multiple' => true ],
	    [ 'key' => 'deep', 'type' => 'nested', 'label' => 'Deep', 'fields' => [
		[ 'key' => 'title', 'type' => 'text', 'label' => 'Title' ],
		[ 'key' => 'where', 'type' => 'nested', 'label' => 'Where', 'fields' => $address, 'required' => true ],
	    ] ],
	];
	//CLD: 2026-10-18T13:00:00+03:00: sys\NestedModel (was models\WfNodeConfig; option nestedSchema, was configSchema)
	$model = fn($data = [], $options = []) => \sys\NestedModel::create($data, $options + [ 'objectName' => 'NestedModel', 'nestedSchema' => $schema ]);
	$errorsOf = function($values) use ($model) {
	    $m = $model();
	    $m->assign($values);
	    return [ $m->validates(), $m->errors() ];
	};

	$m = $model();
	$this->check('nested: empty: single null, multiple an empty list', [ null, [] ], [ $m->main, $m->others ]);
	$m->assign([ 'main' => [ 'city' => 'Iasi', 'floor' => '3', 'junk' => 1 ] ]);
	$this->check('nested: cast by the nested fields (default set, unknown key dropped)', [ 'kind' => 'home', 'city' => 'Iasi', 'floor' => 3 ], $m->main);
	$m->assign([ 'others' => '[{"city": "Cluj", "kind": "work"}, {"city": "Arad", "kind": "zz"}]' ]);
	$this->check('nested multiple: from JSON text, each item cast', [ [ 'kind' => 'work', 'city' => 'Cluj' ], [ 'kind' => null, 'city' => 'Arad' ] ],
	    array_map(fn($a) => array_intersect_key($a, [ 'kind' => 1, 'city' => 1 ]), $m->others));

	$this->check('nested: valid', [ true, [] ], $errorsOf([ 'main' => [ 'city' => 'Iasi' ], 'others' => [ [ 'city' => 'Cluj' ] ], 'deep' => [ 'where' => [ 'city' => 'X' ] ] ]));
	$this->check('nested: not given: nothing checked', [ true, [] ], $errorsOf([ 'name' => 'n' ]));
	$this->check('nested: a nested field is validated', [ false, [ 'main.city' => [ 'This field is required', 'only letters, numbers and symbols' ] ] ], $errorsOf([ 'main' => [ 'floor' => 1 ] ]));
	$this->check('nested multiple: the errors of each item, by index', [ false, [ 'others.1.city' => [ 'This field is required', 'only letters, numbers and symbols' ] ] ],
	    $errorsOf([ 'others' => [ [ 'city' => 'a' ], [ 'floor' => 2 ], [ 'city' => 'c' ] ] ]));
	//a value not cast (given to create()): validated as it is
	$raw = $model([ 'others' => [ [ 'city' => 'c', 'kind' => 'zz' ] ] ]);
	$this->check('nested multiple: a value not cast is validated as it is', [ false, [ 'others.0.kind' => [ 'Not one of the choices' ] ] ], [ $raw->validates(), $raw->errors() ]);
	$this->check('nested in nested: required, then its own fields', [ [ false, [ 'deep.where' => [ 'This field is required' ] ] ], [ false, [ 'deep.where.city' => [ 'This field is required', 'only letters, numbers and symbols' ] ] ] ],
	    [ $errorsOf([ 'deep' => [ 'title' => 't' ] ]), $errorsOf([ 'deep' => [ 'where' => [ 'floor' => 1 ] ] ]) ]);
	$this->check('nested: not an object / not a list of objects / bad JSON text is refused',
	    [ [ 'main' => [ 'This value is not an object' ] ], [ 'others.0' => [ 'This value is not an object' ] ], [ 'others' => [ 'This value is not a list of objects' ] ] ],
	    [ $errorsOf([ 'main' => '{bad' ])[1], $errorsOf([ 'others' => [ 'x' ] ])[1], $errorsOf([ 'others' => [ 'a' => [ 'city' => 'c' ] ] ])[1] ]);

	//CLD: 2026-10-18T10:20:00+03:00: the labels of error paths (BaseModel::errorLabel: WfNode::validateConfig's messages); the app.smartform fields (SmartForm::modelFields)
	$m = $model();
	$this->check('nested: error labels (a list item numbered from 1, nested in nested, a whole item, a plain field)',
	    [ 'Other addresses 2: City', 'Deep: Where: City', 'Other addresses 1', 'Name', 'nope' ],
	    [ $m->errorLabel('others.1.city'), $m->errorLabel('deep.where.city'), $m->errorLabel('others.0'), $m->errorLabel('name'), $m->errorLabel('nope') ]);
	$sf = array_column(\util\SmartForm::modelFields($m), null, 'key');
	$this->check('nested: smartform: a list is an editgrid, one object `nested`, their fields the components',
	    [ 'editgrid', [ 'city', 'kind', 'floor' ], 'nested', [ 'city', 'kind', 'floor' ], 'nested', [ 'title', 'where' ] ],
	    [ $sf['others']['type'], array_column($sf['others']['components'], 'key'), $sf['main']['type'], array_column($sf['main']['components'], 'key'),
	      $sf['deep']['type'], array_column($sf['deep']['components'], 'key') ]);
	$this->check('nested: smartform: a component keeps its own settings (required, choices, default)', [ true, [ 'home', 'work' ], 'home' ],
	    [ $sf['main']['components'][0]['required'] ?? null, array_column($sf['main']['components'][1]['choices'], 'value'), $sf['main']['components'][1]['default'] ?? null ]);

	$this->nestedSteps();

	//`object`: the schema of that object (models\WfTask: title required, state default `open`), its system fields left out; `object` wins over `fields`
	//CLD: 2026-10-18T13:00:00+03:00: sys\NestedModel (was models\WfNodeConfig; option nestedSchema, was configSchema)
	$byObject = \sys\NestedModel::create([], [ 'objectName' => 'NestedModel', 'nestedSchema' => [
	    [ 'key' => 'task', 'type' => 'nested', 'label' => 'Task', 'object' => 'WfTask', 'fields' => $address ],
	    [ 'key' => 'tasks', 'type' => 'nested', 'label' => 'Tasks', 'object' => 'WfTask', 'multiple' => true ],
	] ]);
	$byObject->assign([ 'task' => [ 'title' => 'Approve', 'city' => 'x', 'created_at' => 'x' ] ]);
	$this->check('nested object: cast by the object\'s fields (default set, system and unknown keys dropped)', [ 'Approve', 'open', false, false ],
	    [ $byObject->task['title'] ?? null, $byObject->task['state'] ?? null, array_key_exists('city', $byObject->task), array_key_exists('created_at', $byObject->task) ]);
	$byObject->assign([ 'tasks' => [ [ 'title' => 'a' ], [ 'instructions' => 'no title' ] ] ]);
	$this->check('nested object: validated by the object\'s fields', [ false, [ 'tasks.1.title' ] ], [ $byObject->validates(), array_keys($byObject->errors()) ]);
	$unknown = function() {
	    try {
		//CLD: 2026-10-18T13:00:00+03:00: sys\NestedModel (was models\WfNodeConfig; option nestedSchema, was configSchema)
		\sys\NestedModel::create([], [ 'objectName' => 'NestedModel', 'nestedSchema' => [ [ 'key' => 'x', 'type' => 'nested', 'label' => 'X', 'object' => 'NoSuchObject' ] ] ])->assign([ 'x' => [ 'a' => 1 ] ]);
		return null;
	    } catch (\ErrorException $e) {
		return $e->getMessage();
	    }
	};
	$this->check('nested object: an unknown object is refused', 'Field `x`: unknown object `NoSuchObject`', $unknown());
    }

    //CLD: 2026-10-18T10:45:00+03:00: the steps whose lists are nested fields: HTTP (query / headers / form: header names, names trimmed), Data Table (filter / data: validated
    //when the operation uses them, columns trimmed); errors named "<itemLabel> <n>: <field>: <message>"
    private function nestedSteps() {
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$sorted = function($r) { ksort($r); return $r; };
	$errors = fn($name, $id, $elements) => array_values(array_filter($this->errorsOf($name, $elements), fn($m) => str_starts_with($m, "{$id}: ")));

	$http = [ $n('m', 'trigger::manual'), $n('h', 'app::http', [ 'method' => 'GET', 'url' => 'https://example.com', 'query' => [ [ 'name' => '', 'value' => '{{ x' ] ],
	    'headers' => [ [ 'name' => ' X-A ', 'value' => '1' ], [ 'name' => 'bad name', 'value' => '' ] ] ]), $e('e1', 'm', 'h') ];
	$got = $errors('http', 'h', $http);
	$this->check('nested HTTP: a header name, a required name, an expression, each named by its row',
	    [ true, true, true, 3 ],
	    [ in_array('h: Header 2: Name: not a valid header name', $got, true), in_array('h: Query parameter 1: Name: This field is required', $got, true),
	      count(array_filter($got, fn($m) => str_starts_with($m, 'h: Query parameter 1: Value: '))) === 1, count($got) ]);
	$wf = $this->workflow('http normalized', $http);
	$wf->validates();
	$this->check('nested HTTP: a row normalized (name trimmed)', [ 'name' => 'X-A', 'value' => '1' ], $sorted((array)($wf->nodes['h']['config']['headers'][0] ?? [])));
	$this->check('nested HTTP: a header name in the browser (the same pattern)', \models\node\NodeAppHttp::HEADER_NAME,
	    array_column(array_column(\util\SmartForm::modelFields(\models\node\NodeAppHttp::configForm()), null, 'key')['headers']['components'] ?? [], 'pattern', 'key')['name'] ?? null);

	$table = fn($op) => [ $n('m', 'trigger::manual'), $n('t', 'app::table', [ 'table' => '', 'operation' => $op, 'filter' => [ [ 'column' => ' name ', 'operator' => 'zz', 'value' => '' ] ],
	    'data' => [ [ 'column' => '', 'value' => 'v' ] ] ]), $e('e1', 'm', 't') ];
	$rowErrors = fn($op) => array_values(array_filter($errors("table {$op}", 't', $table($op)), fn($m) => preg_match('/^t: (Condition|Value) \d/', $m)));
	$this->check('nested Table: the lists validated when the operation uses them (query: the filter, insert: the data)',
	    [ [ 't: Condition 1: Operator: This field is required' ], [ 't: Value 1: Column: This field is required' ] ], [ $rowErrors('query'), $rowErrors('insert') ]);
	$wf = $this->workflow('table normalized', $table('query'));
	$wf->validates();
	$this->check('nested Table: a row normalized (column trimmed), the unused list kept', [ 'name', [ [ 'column' => '', 'value' => 'v' ] ] ],
	    [ $wf->nodes['t']['config']['filter'][0]['column'] ?? null, array_map($sorted, (array)($wf->nodes['t']['config']['data'] ?? [])) ]);
    }

    //CLD: 2026-10-18T14:00:00+03:00: util\JSON::json_decode: strict JSON, the tolerated typos (keys without quotes, single quotes, trailing commas, comments, True / None), the refusals
    private function suiteJson() {
	$J = \util\JSON::class;
	$this->check('json: strict JSON', [ 'a' => 1, 'b' => [ true, null, 'x' ] ], $J::json_decode('{"a": 1, "b": [true, null, "x"]}'));
	$this->check('json: keys without quotes', [ 'a' => 1, 'b_2' => [ 'c' => 2 ], '$id' => 3 ], $J::json_decode('{a: 1, b_2: {c: 2}, $id: 3}'));
	$this->check('json: single quotes (an escaped \' and a " inside)', [ 'k' => "it's", 'q' => 'say "hi"', 'n' => "a\nb" ],
	    $J::json_decode("{'k': 'it\\'s', 'q': 'say \"hi\"', 'n': 'a\\nb'}"));
	$this->check('json: a comma after the last item', [ 'a' => [ 1, 2 ], 'b' => [ 'c' => 1 ] ], $J::json_decode("{a: [1, 2,], b: {c: 1, },\n}"));
	$this->check('json: comments', [ 'a' => 1, 'b' => 2 ], $J::json_decode("{\n  // the a\n  a: 1, /* the b */ b: 2\n}"));
	$this->check('json: True / False / None', [ true, false, null, null ], $J::json_decode('[True, FALSE, None, null]'));
	$this->check('json: quotes and commas inside strings are kept', [ 'a' => "x, ]", 'b' => "it's // not a comment", 'c' => 'ă' ],
	    $J::json_decode("{a: 'x, ]', b: \"it's // not a comment\", c: 'ă'}"));
	$this->check('json: a list', [ 1, 'two' ], $J::json_decode("[1, 'two',]"));
	//CLD: 2026-10-18T14:20:00+03:00: the parser of its own: escapes, numbers, nesting, empty containers, the same results as PHP's json_decode on strict JSON
	$this->check('json: escapes (\n \t \" \\\\ \/ é, a surrogate pair)', [ "a\n\tb\"\\/é😀" ], $J::json_decode('["a\n\tb\"\\\\\/é😀"]'));
	$this->check('json: 128 levels deep', 128, (function($v) { $d = 0; while (is_array($v)) { $d++; $v = $v[0] ?? null; } return $d; })($J::json_decode(str_repeat('[', 128) . str_repeat(']', 128))));
	$this->check('json: numbers (int, negative, float, exponent, big)', [ 0, -12, 1.5, 1000.0, -0.025, 1.0E+30 ], $J::json_decode('[0, -12, 1.5, 1e3, -2.5E-2, 1000000000000000000000000000000]'));
	$this->check('json: empty object / list, nested, a key that is a number', [ 'o' => [], 'l' => [], 'n' => [ [ [ 'x' => [ 1 ] ] ] ], 5 => 'five' ],
	    $J::json_decode("{o: {}, l: [], n: [[{x: [1]}]], 5: 'five'}"));
	$samples = [ '{"a":{"b":[1,2.5,-3e2,"x\\u00e9\\ud83d\\ude00",true,false,null,{}]},"":"empty key","1":[[]]}', '[ ]', '{ "k" : "a \\"q\\" \\/ \\\\" }' ];
	$this->check('json: strict JSON: the same as PHP\'s json_decode', array_map(fn($t) => json_decode($t, true), $samples), array_map([ $J, 'json_decode' ], $samples));

	$refused = function($text) use ($J) {
	    try {
		$J::json_decode($text);
		return null;
	    } catch (\ErrorException $e) {
		return $e->getMessage();
	    }
	};
	$this->check('json: refused (empty, a scalar, a word not in quotes, a string / comment not closed)', [
		'JSON: empty text', 'JSON: not an object or a list', 'JSON: not an object or a list', 'JSON: `abc` is not a value (text in quotes?) at line 2, column 4',
		'JSON: a string not closed, opened at line 1, column 5', 'JSON: a comment not closed, opened at line 1, column 7' ],
	    [ $refused(' '), $refused('12'), $refused('"x"'), $refused("{\na: abc}"), $refused("{a: 'x}"), $refused('{a: 1 /* x}') ]);
	$this->check('json: refused (missing brace / bracket / comma / colon / value, text after the end)', [
		'JSON: the text ends before `}` at line 1, column 6', 'JSON: the text ends before `]` at line 1, column 3', 'JSON: `,` or `}` is expected at line 1, column 7',
		'JSON: `,` or `]` is expected at line 1, column 4', 'JSON: `:` is expected after the key `a` at line 1, column 4', 'JSON: a value is expected at line 1, column 5',
		'JSON: a key or `}` is expected at line 1, column 2', 'JSON: text after the end at line 1, column 5' ],
	    [ $refused('{a: 1'), $refused('[1'), $refused('{a: 1 b: 2}'), $refused('[1 2]'), $refused('{a 1}'), $refused('{a: }'), $refused('{,}'), $refused('[1] x') ]);
	$this->check('json: refused (a bad escape, a lone surrogate, too deep, not UTF-8)', [
		'JSON: `\x` is not an escape at line 1, column 4', 'JSON: a lone surrogate `\uD83D` at line 1, column 3', 'JSON: nested deeper than 128 at line 1, column 129', 'JSON: not UTF-8 text' ],
	    [ $refused('["a\x41"]'), $refused('["\ud83d"]'), $refused(str_repeat('[', 129) . str_repeat(']', 129)), $refused("[\"\xff\"]") ]);
    }

    //CLD: 2026-10-23T01:10:00+03:00: Markdown (util\Markdown): the blocks and marks as HTML, nothing of the text as HTML (escaped), links only http(s) / mailto, images as links,
    //the plain text; markdown() in expressions; in an email {{ markdown(x) }} alone is HTML, anything else escaped; the AI steps' `html`
    private function suiteMarkdown() {
	$M = \util\Markdown::class;
	$this->check('blocks: headings, paragraph with a line break, rule', [ '<h1>T</h1>', '<h2>S</h2>', '<p>a<br>b</p>', '<hr>' ],
	    [ $M::toHtml('# T'), $M::toHtml("S\n---"), $M::toHtml("a\nb"), $M::toHtml('***') ]);
	$this->check('lists: ordered with a start, nested, a blank line between items', [ '<ol start="3"><li>a</li><li>b</li></ol>', '<ul><li>a<ul><li>b</li></ul></li><li>c</li></ul>',
	    '<ul><li>a</li><li>b</li></ul>' . "\n" . '<p>after</p>' ],
	    [ $M::toHtml("3. a\n4. b"), $M::toHtml("- a\n  - b\n- c"), $M::toHtml("- a\n\n- b\n\nafter") ]);
	$this->check('quote, code, table with alignment', [ '<blockquote><p><strong>q</strong></p></blockquote>', '<pre><code>&lt;x&gt;</code></pre>',
	    '<table><thead><tr><th>A</th><th align="right">B</th></tr></thead><tbody><tr><td>1</td><td align="right">a | b</td></tr></tbody></table>' ],
	    [ $M::toHtml('> **q**'), $M::toHtml("```\n<x>\n```"), $M::toHtml("| A | B |\n|---|--:|\n| 1 | a \\| b |") ]);
	$this->check('inline: bold, italic, struck, code, snake_case and 2*3*4 left alone', [ '<p><strong>a <em>b</em></strong> <del>c</del> <code>&lt;d&gt;</code> x_y_z 2*3*4</p>' ],
	    [ $M::toHtml('**a *b*** ~~c~~ `<d>` x_y_z 2*3*4') ]);
	$this->check('safe: HTML escaped, links only http(s) / mailto (with parentheses), images as links, bare addresses linked', [
	    '<p>&lt;script&gt;x&lt;/script&gt; &lt;img src=x onerror=y&gt;</p>', '<p>bad</p>', '<p><a href="https://w.org/a_(b)">w</a></p>', '<p><a href="mailto:a@b.c">m</a></p>',
	    '<p><a href="https://i.org/p.png">pic</a></p>', '<p>see <a href="https://e.org/x">https://e.org/x</a>.</p>' ], [
	    $M::toHtml('<script>x</script> <img src=x onerror=y>'), $M::toHtml('[bad](javascript:alert(1))'), $M::toHtml('[w](https://w.org/a_(b))'), $M::toHtml('[m](mailto:a@b.c)'),
	    $M::toHtml('![pic](https://i.org/p.png)'), $M::toHtml('see https://e.org/x.') ]);
	$this->check('plain text: no marks, links as text (address), table rows', "Title\n\n- a (https://e.org)\n- b\n\nA | B\n1 | 2",
	    $M::toText("## Title\n\n- [a](https://e.org)\n- **b**\n\n| A | B |\n|---|---|\n| 1 | 2 |"));

	$this->check('expressions: markdown() known, suggested, gives HTML; a variable of that name wins', [ [], true, '<p><em>x</em></p>', 'mine' ], [
	    \util\Expression::check('{{ markdown(t) }}', [ 't' => [ 'type' => 'string' ] ]),
	    in_array('markdown', array_column(\util\Expression::complete('{{ mark', '{{ mark', [])['items'], 'label'), true),
	    \util\Expression::render('{{ markdown(t) }}', [ 't' => '*x*' ]), \util\Expression::render('{{ markdown }}', [ 'markdown' => 'mine' ]) ]);

	$fill = fn($html, $vars = []) => \util\EmailHtml::fill($html, $vars + [ 'ai' => [ 'text' => '**b** <i>', 'html' => '<p><b>x</b></p>' ] ]);
	//CLD: 2026-10-23T02:30:00+03:00: {{ ai.html }} is HTML too, cleaned (was escaped)
	$this->check('email: {{ markdown(x) }} and {{ x.html }} alone are HTML; other expressions and markdown(..) + text are escaped', [ '<p><strong>b</strong> &lt;i&gt;</p>',
	    '**b** &lt;i&gt;', '<p><b>x</b></p>', '&lt;p&gt;&lt;strong&gt;b&lt;/strong&gt; &amp;lt;i&amp;gt;&lt;/p&gt;!', '&lt;b&gt;&lt;p&gt;&lt;b&gt;x&lt;/b&gt;&lt;/p&gt;' ],
	    [ $fill('{{ markdown(ai.text) }}'), $fill('{{ ai.text }}'), $fill('{{ ai.html }}'), $fill('{{ markdown(ai.text) + "!" }}'), $fill('{{ "<b>" + ai.html }}') ]);
	$H = \util\HtmlSafe::class;
	$this->check('email: an html field cleaned to formatting: scripts / styles / frames / images out, attributes out, links http(s) / mailto, stray < kept', [
	    '<h2>T</h2><p>a <b>b</b></p>', 'ok', '<a>l</a> <a href="https://e.org">m</a>', 'pic', 'text', '<table><tr><td align="center">c</td></tr></table>', 'a &lt; b' ], [
	    $H::clean('<h2 style="x" onclick="y">T</h2><p>a <b>b</b></p>'), $H::clean('<script>alert(1)</script><style>p{}</style>ok'),
	    $H::clean('<a href="javascript:x" onmouseover="y">l</a> <a href="https://e.org" target="_blank">m</a>'), $H::clean('<img src=https://t.org/p.gif>pic<iframe src=x></iframe>'),
	    $H::clean('<font color=red>text</font><!-- c -->'), $H::clean('<table><tr><td align="center" colspan="2x" onclick=1>c</td></tr></table>'), $H::clean('a < b') ]);
	$r = \util\EmailHtml::render([ 'preheader' => '', 'blocks' => [ [ 'type' => 'html', 'html' => '<p>{{ markdown(ai.text) }}</p>' ] ] ], [ 'ai' => [ 'text' => "## News\n\n- one\n- two" ] ], 'x');
	$this->check('email: styled as the block (no empty paragraph around it), its text version', [ true, true, false, "News\n\n- one\n- two\n" ],
	    [ str_contains($r['html'], '<h2 style="'), str_contains($r['html'], '<ul style="'), (bool)preg_match('~<p[^>]*>\s*</p>~', $r['html']), $r['text'] ]);
	$this->check('AI steps: `html` among the variable\'s fields', true, isset(\models\node\NodeAppClaude::outputVariables([])['ai']['fields']['html']));
    }

    //CLD: 2026-10-22T23:00:00+03:00: a suspended account (state `disabled`, set by a super-admin: the Admin plugin): its users cannot sign in (unless a super-admin views it), it
    //starts no execution, the daemon leaves its jobs queued; reactivated, all of it works again. In an account made for it (removed after)
    private function suiteSuspend() {
	$om = \V::app()->om;
	$restore = \V::app()->config('rt.account');
	$account = \models\Account::create([ 'state' => 'active', 'name' => 'Check suspend ' . uniqid() ]);
	$account->save();
	\V::app()->var('rt.account', $account);
	try {
	    $roles = $account->setupRoles();
	    $user = $om->create('User', [ 'aid' => $account->_id, 'username' => 'chk_susp_' . uniqid(), 'email' => 'chk_susp_' . uniqid() . '@example.invalid', 'firstname' => 'C',
		'lastname' => 'S', 'password' => \V::app()->security->generatePasswordHash('x'), 'state' => 'active', 'role' => $roles['owner']->_id ]);
	    $user->save();
	    $signIn = function($allow = false) use ($user) {
		$i = new \sys\Identity();
		$i->allowSuspended = $allow;
		return $i->authenticateById((string)$user->_id) ? 'signs in' : $i->reason;
	    };
	    $starts = function() use ($account) {
		try {
		    \models\WfExecution::startFor((object)[ 'aid' => $account->_id, 'project' => null, '_id' => null, 'nodes' => [], 'runVersion' => null ]);
		    return 'starts';
		} catch (\ErrorException $e) {
		    return $e->getMessage();
		}
	    };
	    $daemon = (new \ReflectionClass(\commands\DaemonCommand::class))->newInstanceWithoutConstructor();
	    $daemon->config = [ 'max-per-account' => 0, 'max-per-project' => 0 ];
	    $query = new \ReflectionMethod($daemon, 'allowed_jobs_query');
	    $waits = fn() => in_array((string)$account->_id, array_map('strval', (array)($query->invoke($daemon)['aid']['$nin'] ?? [])), true);
	    $state = function($s) use ($om, $account) {
		$om->updateOne('Account', [ '_id' => $account->_id ], [ '$set' => [ 'state' => $s ] ]);
		\models\Account::suspendedIds(true);
	    };

	    $this->check('active: signs in, starts executions, its jobs run', [ false, 'signs in', 'starts', false ],
		[ \models\Account::isSuspended($account->_id), $signIn(), $starts(), $waits() ]);
	    $state('disabled');
	    $this->check('suspended: no sign-in (a super-admin viewing it may), no execution, its jobs wait', [ true, 'This account is suspended: contact the administrator', 'signs in',
		'the account is suspended', true ], [ \models\Account::isSuspended($account->_id), $signIn(), $signIn(true), $starts(), $waits() ]);
	    $state('active');
	    $this->check('reactivated: all of it again', [ false, 'signs in', 'starts', false ], [ \models\Account::isSuspended($account->_id), $signIn(), $starts(), $waits() ]);
	} finally {
	    \V::app()->var('rt.account', $restore);
	    \models\Account::suspendedIds(true);
	    $om->acrossAccounts(function() use ($om, $account) {
		foreach([ 'User', 'Role' ] as $name) {
		    $class = $om->getObjectClass($name);
		    \V::app()->mongo->selectCollection($class::_database(), $class::_collection())->deleteMany([ 'aid' => $account->_id ]);
		}
		\V::app()->mongo->selectCollection(\models\Account::_database(), \models\Account::_collection())->deleteOne([ '_id' => $account->_id ]);
	    });
	}
    }

    //CLD: 2026-10-24T09:45:36+03:00: new: the accounts' tiers (util\Tiers), on an account made for the checks: its tier (the default, set, the account's own changes), the limits refused at the
    //limit (data tables, rows of a table: one and many, files: a file that does not fit is not kept), SAML / LDAP off without the feature, the System AI allowance, the usage
    private function suiteTiers() {
	$om = \V::app()->om;
	$T = \util\Tiers::class;
	$restore = \V::app()->config('rt.account');
	$account = \models\Account::create([ 'state' => 'active', 'name' => 'Check tiers ' . uniqid() ]);
	$account->save();
	\V::app()->var('rt.account', $account);
	$db = \sys\BaseModel::_database();
	$iname = 'data-table-chk-tiers-' . uniqid();
	$files = [];
	//the account's tier and its own changes (in memory: Tiers reads the current account)
	$set = function($tier, array $limits = [], array $features = []) use ($account, $T) {
	    $account->tier = $tier;
	    $account->tier_overrides = [ 'limits' => $limits, 'features' => $features ];
	    $T::reset();
	};
	$refused = function(callable $fn) {
	    try {
		$fn();
		return 'ok';
	    } catch (\util\LimitException $e) {
		return $e->key;
	    }
	};
	try {
	    $t = $T::table();
	    $this->check('the default tier: an account without one', [ $t['default'], $t['default'] ], [ $T::tierOf($account), $T::tierOf((string)$account->_id) ]);
	    $set('free');
	    $free = $t['tiers']['free'];
	    $this->check('free: its limits, no SAML / LDAP', [ $free['limits']['datatables'], $free['limits']['files_bytes'], false, false ],
		[ $T::limit($account, 'datatables'), $T::limit($account, 'files_bytes'), $T::has($account, 'saml'), $T::has($account, 'ldap') ]);
	    $set('free', [ 'datatables' => null, 'datatable_records' => 7 ], [ 'saml' => true ]);
	    $this->check('the account\'s own: no limit, another limit, a feature on', [ null, 7, true, false ],
		[ $T::limit($account, 'datatables'), $T::limit($account, 'datatable_records'), $T::has($account, 'saml'), $T::has($account, 'ldap') ]);
	    $set('enterprise', [], [ 'ldap' => false ]);
	    $this->check('enterprise: no limits; a feature off', [ null, true, false ], [ $T::limit($account, 'files_bytes'), $T::has($account, 'saml'), $T::has($account, 'ldap') ]);

	    //data tables: 2 of the account's, the limit 2 refuses a third
	    $defs = \V::app()->mongo->selectCollection($db, \models\DataTableDef::_collection());
	    $defs->insertMany([ [ 'aid' => $account->_id, 'iname' => $iname, 'name' => 'chk_a', 'project' => null ], [ 'aid' => $account->_id, 'iname' => $iname . '-b', 'name' => 'chk_b', 'project' => null ] ]);
	    $set('free', [ 'datatables' => 3 ]);
	    $a = $refused(fn() => $T::check($account, 'datatables'));
	    $set('free', [ 'datatables' => 2 ]);
	    $this->check('data tables: one more within the limit, refused at it', [ 'ok', 'datatables' ], [ $a, $refused(fn() => $T::check($account, 'datatables')) ]);

	    //rows of a table: 3 rows, the limit 5
	    $rows = \V::app()->mongo->selectCollection($db, $iname);
	    $rows->insertMany([ [ 'aid' => $account->_id ], [ 'aid' => $account->_id ], [ 'aid' => $account->_id ] ]);
	    $set('free', [ 'datatable_records' => 5 ]);
	    $this->check('rows: 2 more fit, 3 more refused (all or none), another table is its own', [ 'ok', 'datatable_records', 'ok' ],
		[ $refused(fn() => $T::check($account, 'datatable_records', 2, $iname)), $refused(fn() => $T::check($account, 'datatable_records', 3, $iname)),
		$refused(fn() => $T::check($account, 'datatable_records', 5, $iname . '-b')) ]);
	    try {
		$T::check($account, 'datatable_records', 3, $iname);
		$message = '';
	    } catch (\util\LimitException $e) {
		$message = $e->getMessage();
	    }
	    $this->check('the message: the plan, the limit, whom to ask', true, str_contains($message, '(Free)') && str_contains($message, 'at most 5 rows in a data table') && str_contains($message, 'administrator'));

	    //files: the limit 100 bytes
	    $meta = [ 'metadata' => [ 'aid' => $account->_id, 'project' => null, 'owner' => null, 'source' => 'check' ] ];
	    $set('free', [ 'files_bytes' => 100 ]);
	    $a = $refused(function() use (&$files, $meta) {
		$files[] = \models\File::createFromString('chk-a.txt', str_repeat('a', 60), $meta);
	    });
	    $b = $refused(function() use (&$files, $meta) {
		$files[] = \models\File::createFromString('chk-b.txt', str_repeat('b', 60), $meta);
	    });
	    //a stream that does not say its size: written, then removed (over the limit)
	    $c = $refused(function() use (&$files, $meta) {
		$s = popen('head -c 60 /dev/zero', 'r');
		try {
		    $files[] = \models\File::createFromStream('chk-c.txt', $s, $meta);
		} finally {
		    pclose($s);
		}
	    });
	    $this->check('files: one fits, the next refused, a stream of unknown size removed once written; the account keeps 1 file, 60 bytes', [ 'ok', 'files_bytes', 'files_bytes', 1, 60 ],
		[ $a, $b, $c, \V::app()->mongo->selectCollection(\models\File::_database(), \models\File::_collection())->countDocuments([ 'metadata.aid' => $account->_id ]),
		$T::used($account, 'files_bytes') ]);

	    //SAML / LDAP: their sign-in off without the feature (settings kept)
	    if (class_exists(\plugins\Saml\util\SamlAuth::class)) {
		$set('free');
		\plugins\Saml\util\SamlAuth::reset();
		$this->check('SAML: no sign-in without the feature', null, \plugins\Saml\util\SamlAuth::enabledFor($account->_id));
	    }
	    if (class_exists(\plugins\Ldap\util\LdapAuth::class)) {
		$set('free');
		\plugins\Ldap\util\LdapAuth::reset();
		$this->check('LDAP: no sign-in, the step and its credential not offered without the feature', [ null, false, false ],
		    [ \plugins\Ldap\util\LdapAuth::enabledFor($account->_id), $T::allows($account, 'NodeAppLdap'), $T::allows($account, 'CredentialLdap') ]);
	    }

	    //CLD: 2026-10-24T10:28:12+03:00: Google / Microsoft: on team and enterprise, off on free; their credentials and steps follow; a step does not run without it
	    foreach([ 'google' => [ 'CredentialGoogleMail', 'NodeAppGoogleDrive' ], 'microsoft' => [ 'CredentialMicrosoftMail', 'NodeAppOneDrive' ] ] as $feature => [ $cred, $step ]) {
		if (!$om->isDefined($step))
		    continue;
		$on = [];
		foreach([ 'free', 'team', 'enterprise' ] as $tier) {
		    $set($tier);
		    $on[] = [ $T::has($account, $feature), $T::allows($account, $cred), $T::allows($account, $step) ];
		}
		$set('free');
		$node = $om->create($step, []);
		$node->exec = (object)[ 'aid' => $account->_id ];
		try {
		    $node->receive();
		    $runs = 'runs';
		} catch (\ErrorException $e) {
		    $runs = str_contains($e->getMessage(), 'not included in your plan (Free)') ? 'refused' : $e->getMessage();
		}
		$this->check("{$feature}: off on free, on on team and enterprise (its credentials, its steps); a step refused without it",
		    [ [ false, false, false ], [ true, true, true ], [ true, true, true ], 'refused' ], [ $on[0], $on[1], $on[2], $runs ]);
	    }

	    //the System AI's allowance: the account's plan, else the tier's, else the installation's
	    if (class_exists(\plugins\SystemAI\models\SystemAiWallet::class)) {
		$W = \plugins\SystemAI\models\SystemAiWallet::class;
		$set('free', [ 'ai_monthly_credits' => 123 ]);
		$tierPlan = $W::planOf($W::blank(), $account->_id);
		$own = $W::planOf([ 'plan' => 50 ] + $W::blank(), $account->_id);
		$set('free', [ 'ai_monthly_credits' => null ]);
		$this->check('System AI: the tier\'s allowance, the account\'s plan before it, the installation\'s without either', [ 123, 50, \plugins\SystemAI\util\SystemAi::monthlyCredits() ],
		    [ $tierPlan, $own, $W::planOf($W::blank(), $account->_id) ]);
	    }

	    $u = $T::usage($account);
	    $this->check('usage: tables, the largest table\'s rows, files; per project', [ 2, 3, 60, 2 ],
		[ $u['totals']['datatables'], $u['totals']['datatable_records'], $u['totals']['files_bytes'], $u['projects']['']['datatables'] ?? null ]);
	} finally {
	    \V::app()->var('rt.account', $restore);
	    $T::reset();
	    foreach($files as $fid)
		$om->acrossAccounts(fn() => $om->findOne('File', [ '_id' => $fid ]))?->delete();
	    \V::app()->mongo->selectCollection(\models\File::_database(), \models\File::_collection())->deleteMany([ 'metadata.aid' => $account->_id ]);
	    \V::app()->mongo->selectCollection($db, \models\DataTableDef::_collection())->deleteMany([ 'aid' => $account->_id ]);
	    \V::app()->mongo->selectCollection($db, $iname)->drop();
	    \V::app()->mongo->selectCollection(\models\Account::_database(), \models\Account::_collection())->deleteOne([ '_id' => $account->_id ]);
	}
    }

    //CLD: 2026-10-24T00:20:00+03:00: new: a 2FA code is accepted once (User::useTfaCode: the same code again, an older window's code, a wrong one refused; the next window's accepted);
    //the "forgot password" limits (LoginThrottle reset_email: 3, reset_ip: 10 in an hour, then locked)
    private function suiteSignin() {
	$om = \V::app()->om;
	$restore = \V::app()->config('rt.account');
	$account = \models\Account::create([ 'state' => 'active', 'name' => 'Check signin ' . uniqid() ]);
	$account->save();
	\V::app()->var('rt.account', $account);
	$T = \models\user\LoginThrottle::class;
	$server = $_SERVER;
	$keys = [];
	try {
	    $roles = $account->setupRoles();
	    $totp = new \util\TOTP();
	    $secret = $totp->createSecret();
	    $user = $om->create('User', [ 'aid' => $account->_id, 'username' => 'chk_tfa_' . uniqid(), 'email' => 'chk_tfa_' . uniqid() . '@example.invalid', 'firstname' => 'C',
		'lastname' => 'T', 'password' => \V::app()->security->generatePasswordHash('x'), 'state' => 'active', 'role' => $roles['owner']->_id, 'tfa_secret' => $secret ]);
	    $user->save();
	    $user = $om->findById('User', (string)$user->_id);
	    $now = time();
	    $code = $totp->getCode($secret, $now);
	    $first = $user->useTfaCode($code);
	    $again = $user->useTfaCode($code);
	    $older = $user->useTfaCode($totp->getCode($secret, $now - 30));
	    $wrong = $user->useTfaCode($code === '000000' ? '111111' : '000000');
	    $next = $user->useTfaCode($totp->getCode($secret, $now + 30));
	    $nextAgain = $user->useTfaCode($totp->getCode($secret, $now + 30));
	    $this->check('signin: a 2FA code is accepted once; the same code again, an older window\'s code and a wrong one are refused; the next window\'s code once',
		[ true, false, false, false, true, false ], [ $first, $again, $older, $wrong, $next, $nextAgain ]);
	    $fresh = $om->findById('User', (string)$user->_id);
	    $setup = $fresh->useTfaCode($totp->getCode($secret, $now + 30), $secret);
	    $this->check('signin: the window is kept in the database (a code used in one request is refused in another)', [ false, true ],
		[ $setup, (int)$fresh->tfa_last_slice === (int)floor(($now + 30) / 30) ]);

	    //"forgot password": 3 requests per email, 10 per address, in an hour
	    $email = 'chk_reset_' . uniqid() . '@example.invalid';
	    $keys[] = $emailKey = $T::resetEmailKey(strtoupper($email));
	    $locks = [];
	    foreach(range(1, 3) as $i)
		$locks[] = [ $T::lockedFor($emailKey) > 0, $T::fail($emailKey) ];
	    $this->check('signin: "forgot password" per email: 3 requests, the third locks it; the key is the lower-case email', [
		[ [ false, false ], [ false, false ], [ false, true ] ], true, 'reset_email:' . $email ], [ $locks, $T::lockedFor($emailKey) > 3500, $emailKey ]);
	    $_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int(1, 254);
	    $keys[] = $ipKey = $T::resetIpKey();
	    $locked = [];
	    foreach(range(1, 10) as $i)
		$locked[] = $T::fail($ipKey);
	    $this->check('signin: "forgot password" per address: the tenth request locks it for an hour', [ array_merge(array_fill(0, 9, false), [ true ]), true ],
		[ $locked, $T::lockedFor($ipKey) > 3500 ]);
	} finally {
	    $_SERVER = $server;
	    foreach($keys as $k)
		$T::clear($k);
	    \V::app()->var('rt.account', $restore);
	    $om->acrossAccounts(function() use ($om, $account) {
		foreach([ 'User', 'Role' ] as $name) {
		    $class = $om->getObjectClass($name);
		    \V::app()->mongo->selectCollection($class::_database(), $class::_collection())->deleteMany([ 'aid' => $account->_id ]);
		}
		\V::app()->mongo->selectCollection(\models\Account::_database(), \models\Account::_collection())->deleteOne([ '_id' => $account->_id ]);
	    });
	}
    }

    //CLD: 2026-10-22T22:00:00+03:00: the LocalMCP plugin: its servers (the browser's unsafe tools left out), the accounts enabled, the sessions' limits (per account, in all), the AI step's
    //tools of a tool server (only those allowed, named <server>_<tool>, images passed on; a server not enabled refused); with the browser service running: a page opened, a private
    //address refused. Skipped when the plugin is not enabled (app/plugins.php)
    private function suiteLocalmcp() {
	if (!class_exists(\plugins\LocalMCP\Plugin::class) || !isset(\plugins\LocalMCP\Plugin::servers()['playwright'])) {
	    echo "  (skipped: the LocalMCP plugin is not enabled)\n";
	    return;
	}
	$P = \plugins\LocalMCP\Plugin::class;
	$A = \plugins\LocalMCP\models\LocalMcpAccess::class;
	$pw = $P::servers()['playwright'];
	$this->check('servers: the browser, without the tools that run code or read files', [ true, false, false, false, false ],
	    [ in_array('browser_navigate', $pw['tools'], true), in_array('browser_run_code_unsafe', $pw['tools'], true), in_array('browser_evaluate', $pw['tools'], true),
	    in_array('browser_file_upload', $pw['tools'], true), !in_array(parse_url($pw['url'], PHP_URL_HOST), [ 'localhost', '127.0.0.1' ], true) ]);

	$aid = (string)new \MongoDB\BSON\ObjectId();
	$other = (string)new \MongoDB\BSON\ObjectId();
	$slots = [];
	try {
	    $before = array_keys(\models\node\NodeAppAi::localServers($aid));
	    $A::set($aid, 'playwright', true);
	    $A::set($aid, 'playwright', true);
	    $on = \models\node\NodeAppAi::localServers($aid);
	    $A::set($aid, 'playwright', false);
	    $this->check('access: none, enabled (once), offered to the AI steps with its tools; disabled', [ [], [ 'playwright' ], [ 'playwright' ], 'Web browser', $pw['tools'], [], [] ],
		[ $before, $A::serversOf($aid) ?: [ 'playwright' ], array_keys($on), $on['playwright']['label'] ?? null, $on['playwright']['tools'] ?? null, $A::serversOf($aid),
		array_keys(\models\node\NodeAppAi::localServers($other)) ]);

	    //the limits: 2 per account, 3 in all (a server of the check: the real one's slots untouched)
	    $def = [ 'url' => 'http://127.0.0.1:1/mcp', 'max_sessions' => 3, 'max_sessions_per_account' => 2 ];
	    $open = function($acct) use ($def, &$slots) {
		try {
		    return $slots[] = \plugins\LocalMCP\util\SessionClient::open('chk', $def, $acct, 5);
		} catch (\ErrorException $e) {
		    return $e->getMessage();
		}
	    };
	    $r = [ $open($aid), $open($aid), $open($aid), $open($other), $open($other) ];
	    $this->check('sessions: 2 per account, then refused; 3 in all, then busy', [ true, true, 'per account', true, 'busy' ],
		[ is_object($r[0]), is_object($r[1]), is_string($r[2]) && str_contains($r[2], 'the most at once') ? 'per account' : $r[2], is_object($r[3]),
		is_string($r[4]) && str_contains($r[4], 'busy') ? 'busy' : $r[4] ]);
	    $r[0]->close();
	    $again = $open($other);
	    $this->check('sessions: a closed one frees its slots', true, is_object($again));
	} finally {
	    foreach($slots as $c)
		if (is_object($c))
		    $c->close();
	    \V::app()->om->deleteOne('LocalMcpAccess', [ '_id' => $aid, 'aid' => \V::app()->om->to_oid($aid) ]);
	}
	$left = \V::app()->om->count('LocalMcpSlot', [ '_id' => [ '$regex' => '^chk:' ] ]);
	$this->check('sessions: none left after closing', 0, $left);

	//the AI step's tools of a tool server: a server of the check (for this account only) answering in memory
	$fake = new class {
	    public $calls = [];
	    public $closed = false;
	    public function initialize() { return [ 'serverInfo' => [ 'name' => 'fake' ] ]; }
	    public function listTools() {
		return [ [ 'name' => 'look', 'description' => 'looks', 'inputSchema' => (object)[ 'type' => 'object' ] ], [ 'name' => 'run.code', 'description' => 'runs', 'inputSchema' => (object)[ 'type' => 'object' ] ] ];
	    }
	    public function callTool($name, array $args = []) {
		$this->calls[] = [ $name, $args ];
		return [ 'content' => [ [ 'type' => 'text', 'text' => 'seen' ], [ 'type' => 'image', 'mimeType' => 'image/png', 'data' => 'QUJD' ] ] ];
	    }
	    public function close() { $this->closed = true; }
	};
	$aiAid = (string)new \MongoDB\BSON\ObjectId();
	\V::app()->hooks->addHook('ai:mcp-servers', function(\ArrayObject $servers, $a) use ($fake, $aiAid) {
	    if ($a === $aiAid)
		$servers['chk'] = [ 'id' => 'chk', 'label' => 'Check server', 'description' => '', 'tools' => [ 'look' ], 'open' => fn($timeout) => $fake ];
	});
	$step = new class extends \models\node\NodeAppOpenAI {
	    public function __construct() {
	    }
	    public function tools(array &$clients) {
		return $this->collectTools($clients, null);
	    }
	};
	$step->config = [ 'tools' => [], 'mcp' => [], 'servers' => [ 'chk' ], 'timeout' => 20 ];
	$step->exec = (object)[ 'aid' => $aiAid ];
	$clients = [];
	$tools = $step->tools($clients);
	[ $text, $isError, $images ] = ($tools['chk_look']['run'])([ 'q' => 1 ]);
	$this->check('AI step: only the tools the server allows, named <server>_<tool>, described with its label; a call, its image passed on; the session kept to be closed',
	    [ [ 'chk_look' ], '[Check server] looks', 'seen', false, [ [ 'image/png', 'QUJD' ] ], [ [ 'look', [ 'q' => 1 ] ] ], 1 ],
	    [ array_keys($tools), $tools['chk_look']['description'], $text, $isError, $images, $fake->calls, count($clients) ]);
	$step->exec = (object)[ 'aid' => (string)new \MongoDB\BSON\ObjectId() ];
	try {
	    $clients = [];
	    $step->tools($clients);
	    $refused = 'not refused';
	} catch (\ErrorException $e) {
	    $refused = $e->getMessage();
	}
	$this->check('AI step: a tool server not enabled for the account is refused', 'the tool server `chk` is not enabled for this account', $refused);

	//the browser service, when it runs (scripts/playwright-mcp.sh install)
	$c = new \util\McpClient($pw['url'], (array)($pw['headers'] ?? []), 90);
	try {
	    $c->initialize();
	} catch (\Exception $e) {
	    echo "  (the browser service is not running: scripts/playwright-mcp.sh install)\n";
	    return;
	}
	try {
	    $r = $c->callTool('browser_navigate', [ 'url' => 'https://example.com' ]);
	    $p = $c->callTool('browser_navigate', [ 'url' => 'http://10.255.255.1/' ]);
	    $this->check('browser service: a public page opens; a private address does not', [ true, false ],
		[ str_contains(json_encode($r), 'Example Domain'), empty($p['isError']) && str_contains(json_encode($p), 'HTTP status: 200') ]);
	} finally {
	    $c->close();
	}
    }

    //CLD: 2026-10-19T15:26:28+03:00: the designer's Assistant with a scripted model (util\WorkflowAssistant::$transport): the request it sends, set_workflow refused then fixed,
    //the steps that keep their place, the conversation sent back, failures
    //CLD: 2026-10-19T20:10:00+03:00: plugins\Assistant\util\WorkflowAssistant (was util\WorkflowAssistant); skipped when the Assistant plugin is not enabled (app/plugins.php)
    private function suiteAssistant() {
	if (!isset(\V::app()->Assistant)) {
	    echo "  (skipped: the Assistant plugin is not enabled)\n";
	    return;
	}
	//CLD: 2026-10-20T10:00:00+03:00: the Assistant's settings (its server, key and model; was a credential)
	$cred = (object)[ 'provider' => 'anthropic', 'url' => 'https://api.invalid', 'api_key' => 'test', 'model' => 'claude-opus-5-5' ];
	//a model answering $turns in order (each: [ stop_reason, content blocks ]); the requests are kept in $sent
	$scripted = function(array $turns, &$sent) {
	    $sent = [];
	    //CLD: 2026-10-19T16:05:12+03:00: + the path
	    return function($headers, $body, $path = '') use (&$turns, &$sent) {
		$sent[] = [ $headers, json_decode(\util\JSON::encode($body), true), $path ];
		[ $stop, $content ] = array_shift($turns) ?? [ 'end_turn', [ [ 'type' => 'text', 'text' => '(no more turns)' ] ] ];
		return json_decode(\util\JSON::encode([ 'stop_reason' => $stop, 'content' => $content, 'usage' => [ 'input_tokens' => 10, 'output_tokens' => 5 ] ]));
	    };
	};
	$tool = fn($id, $input) => [ 'type' => 'tool_use', 'id' => $id, 'name' => 'set_workflow', 'input' => $input ];
	$wf = $this->workflow('assistant', []);
	$existing = [ self::node('start', 'trigger::manual'), self::node('old', 'app::set', [ 'variable' => 'x', 'value' => '1' ]), self::edge('e0', 'start', 'old') ];
	$existing[0]['geometry'] = [ 'x' => 500, 'y' => 400, 'w' => 120, 'h' => 120 ];

	$steps = [ [ 'id' => 'start', 'type' => 'trigger::manual' ], [ 'id' => 'check', 'type' => 'core::if', 'title' => 'Big?',
	    'config' => [ 'conditions' => [ [ 'left' => 'amount', 'operator' => 'gt', 'value' => '10' ] ] ] ], [ 'id' => 'big', 'type' => 'app::set', 'config' => [ 'variable' => 'size', 'value' => 'big' ] ] ];
	$conn = [ [ 'from' => 'start', 'to' => 'check' ], [ 'from' => 'check', 'to' => 'big', 'output' => 'true' ] ];
	$fixed = $steps;
	$fixed[1]['config']['conditions'][0]['left'] = '{{ amount }}';
	$a = new \plugins\Assistant\util\WorkflowAssistant($wf, $this->project, $cred);
	$a->transport = $scripted([
	    [ 'tool_use', [ [ 'type' => 'text', 'text' => 'Building.' ], $tool('t1', [ 'steps' => $steps, 'connections' => $conn ]) ] ],
	    [ 'tool_use', [ $tool('t2', [ 'steps' => $fixed, 'connections' => $conn ]) ] ],
	    [ 'end_turn', [ [ 'type' => 'text', 'text' => 'Built: a check of the amount.' ] ] ],
	], $sent);
	$r = $a->ask([ [ 'role' => 'assistant', 'text' => 'ignored: before the first request' ], [ 'role' => 'user', 'text' => 'hello' ], [ 'role' => 'assistant', 'text' => 'Hi' ] ],
	    'When the amount is above 10, mark it big', $existing);
	$this->check('3 rounds, the last answer, the new steps', [ 3, 'Built: a check of the amount.', [ 'start', 'check', 'big' ] ],
	    [ $r['rounds'], $r['reply'], array_values(array_map(fn($n) => $n['wfid'], array_filter($r['nodes'] ?? [], fn($n) => !empty($n['isNode'])))) ]);
	$byId = array_column($r['nodes'] ?? [], null, 'wfid');
	$this->check('a kept step keeps its place; the If output label is the branch', [ 500, 'true' ],
	    [ $byId['start']['geometry']['x'] ?? null, array_values(array_filter($r['nodes'] ?? [], fn($n) => !empty($n['isEdge']) && $n['toNode'] === 'big'))[0]['branch'] ?? null ]);
	$this->check('connection problems returned (output false not connected)', [ 'warning' ], array_values(array_unique(array_column($r['problems'], 'level'))));
	$body = $sent[0][1];
	$this->check('request: model, effort, cached step types, tools', [ 'claude-opus-5-5', 'high', 'ephemeral', true, true ],
	    [ $body['model'], $body['output_config']['effort'] ?? null, $body['system'][1]['cache_control']['type'] ?? null, str_contains($body['system'][1]['text'], '"core::if"'),
	    in_array('set_workflow', array_column($body['tools'], 'name'), true) ]);
	$this->check('request: fallback on refusal', [ 'default', 'server-side-fallback-2026-07-01' ], [ $body['fallbacks'] ?? null, $sent[0][0]['anthropic-beta'] ?? null ]);
	$this->check('request: the history from the first request of the person, the current steps in the last turn', [ [ 'user', 'assistant', 'user' ], true ],
	    [ array_column($body['messages'], 'role'), str_contains($body['messages'][2]['content'], '"id": "old"') ]);
	$second = $sent[1][1]['messages'];
	$result = end($second)['content'][0];
	//CLD: 2026-10-19T17:02:48+03:00: wrong settings: applied, returned as to_fix (were refused)
	$this->check('wrong settings: applied, returned as to_fix with the reason', [ 't1', false, true ],
	    [ $result['tool_use_id'], $result['is_error'], str_contains($result['content'], 'Write the variable between {{ and }}') && str_contains($result['content'], '"to_fix"') ]);
	$this->check('fixed by the next call: nothing left to fix', [], $r['to_fix']);
	$this->check('the assistant turn goes back as it came', 'Building.', $second[count($second) - 2]['content'][0]['text'] ?? null);

	//CLD: 2026-10-19T15:48:10+03:00: the model and effort of the account settings; an unknown effort: the default
	//CLD: 2026-10-19T20:40:00+03:00: the Assistant's settings given (models\AssistantSettings fields; were the account's assistant_* fields, changed here in memory)
	$b = new \plugins\Assistant\util\WorkflowAssistant($wf, $this->project, (object)[ 'model' => 'claude-sonnet-5-5', 'effort' => 'low' ]);
	$c = new \plugins\Assistant\util\WorkflowAssistant($wf, $this->project, (object)[ 'model' => 'claude-sonnet-5-5', 'effort' => 'extreme' ]);
	$b->transport = $scripted([ [ 'end_turn', [ [ 'type' => 'text', 'text' => 'ok' ] ] ] ], $sentB);
	$b->ask([], 'x', $existing);
	$c->transport = $scripted([ [ 'end_turn', [ [ 'type' => 'text', 'text' => 'ok' ] ] ] ], $sentC);
	$c->ask([], 'x', $existing);
	$this->check('account settings: model and effort; an unknown effort is the default', [ 'claude-sonnet-5-5', 'low', 'high' ],
	    [ $sentB[0][1]['model'], $sentB[0][1]['output_config']['effort'], $sentC[0][1]['output_config']['effort'] ]);

	//a question: no change
	$a->transport = $scripted([ [ 'end_turn', [ [ 'type' => 'text', 'text' => 'Which mailbox?' ] ] ] ], $sent);
	$r = $a->ask([], 'read my mail', $existing);
	$this->check('an answer without set_workflow leaves the canvas', [ null, 'Which mailbox?' ], [ $r['nodes'], $r['reply'] ]);

	//CLD: 2026-10-19T16:05:12+03:00: an OpenAI-compatible model (e.g. local): the model from the server when the account has none, tools as functions, arguments that are not
	//JSON refused, results as `tool` messages, <think> left out
	//CLD: 2026-10-19T20:40:00+03:00: settings without a model (were the account's, changed here in memory)
	//CLD: 2026-10-20T10:00:00+03:00: the server in the settings (was a credential)
	$o = new \plugins\Assistant\util\WorkflowAssistant($wf, $this->project, (object)[ 'provider' => 'openai', 'url' => 'http://localhost:8000/api/v1', 'model' => '' ]);
	$call = fn($id, $args) => [ 'id' => $id, 'type' => 'function', 'function' => [ 'name' => 'set_workflow', 'arguments' => $args ] ];
	$oaSent = [];
	$oaTurns = [
	    [ 'data' => [ [ 'id' => 'Qwen3-8B-GGUF' ] ] ],
	    [ 'choices' => [ [ 'finish_reason' => 'tool_calls', 'message' => [ 'role' => 'assistant', 'content' => null, 'tool_calls' => [ $call('c1', '{"steps": [') ] ] ] ] ],
	    [ 'choices' => [ [ 'finish_reason' => 'tool_calls', 'message' => [ 'role' => 'assistant', 'content' => '', 'tool_calls' => [ $call('c2', \util\JSON::encode([ 'steps' => $fixed, 'connections' => $conn ])) ] ] ] ] ],
	    [ 'choices' => [ [ 'finish_reason' => 'stop', 'message' => [ 'role' => 'assistant', 'content' => "<think>\nhmm\n</think>\nBuilt it." ] ] ], 'usage' => [ 'prompt_tokens' => 7, 'completion_tokens' => 3 ] ],
	];
	$o->transport = function($headers, $body, $path) use (&$oaTurns, &$oaSent) {
	    $oaSent[] = [ $path, $body === null ? null : json_decode(\util\JSON::encode($body), true) ];
	    return json_decode(\util\JSON::encode(array_shift($oaTurns)));
	};
	$r = $o->ask([], 'When the amount is above 10, mark it big', $existing);
	$this->check('openai: the server\'s model, then chat completions', [ '/models', '/chat/completions', 'Qwen3-8B-GGUF' ], [ $oaSent[0][0], $oaSent[1][0], $oaSent[1][1]['model'] ?? null ]);
	$first = $oaSent[1][1];
	$this->check('openai: the rules and step types in the system message, tools as functions, no Claude settings', [ 'system', true, 'function', 'set_workflow', false, false ],
	    [ $first['messages'][0]['role'], str_contains($first['messages'][0]['content'], '"core::if"'), $first['tools'][0]['type'] ?? null, $first['tools'][0]['function']['name'] ?? null,
	    isset($first['output_config']), isset($first['max_tokens']) ]);
	$toolMsg = end($oaSent[2][1]['messages']);
	$this->check('openai: arguments that are not JSON go back as an error tool message', [ 'tool', 'c1', true ],
	    [ $toolMsg['role'], $toolMsg['tool_call_id'], str_starts_with($toolMsg['content'], 'ERROR: the arguments are not a JSON object') ]);
	$this->check('openai: the canvas built, the answer without <think>, the usage', [ 'Built it.', 3, 7 ],
	    [ $r['reply'], count(array_filter($r['nodes'] ?? [], fn($n) => !empty($n['isNode']))), $r['usage']['input_tokens'] ]);

	//CLD: 2026-10-19T16:24:37+03:00: the provider of the account settings (the designer offers its credentials only); missing or unknown: Anthropic
	//CLD: 2026-10-22T14:00:00+03:00: gemini is a provider now (was the unknown one)
	$this->check('account provider: openai, gemini; unknown and missing are anthropic', [ 'openai', 'gemini', 'anthropic', 'anthropic' ], [ \plugins\Assistant\util\WorkflowAssistant::providerOf((object)[ 'provider' => 'openai' ]),
	    \plugins\Assistant\util\WorkflowAssistant::providerOf((object)[ 'provider' => 'gemini' ]), \plugins\Assistant\util\WorkflowAssistant::providerOf((object)[ 'provider' => 'nope' ]),
	    \plugins\Assistant\util\WorkflowAssistant::providerOf(null) ]);

	//CLD: 2026-10-20T10:00:00+03:00: the server of the settings (credentialOf; was accountServer, OpenAI-compatible only): its URL or the provider's, the key as the provider
	//wants it; ready with a model; the models it lists (Connect)
	$WA = \plugins\Assistant\util\WorkflowAssistant::class;
	$srv = $WA::credentialOf((object)[ 'provider' => 'openai', 'url' => ' http://localhost:8000/api/v1 ', 'api_key' => 'k1' ]);
	$this->check('settings server: URL, Bearer key, provides openai', [ 'http://localhost:8000/api/v1', [ 'Authorization' => 'Bearer k1' ], true, false ],
	    [ $srv->baseUrl, $srv->httpAuth()['headers'], $srv->provides('openai'), $srv->provides('anthropic') ]);
	$ant = $WA::credentialOf((object)[ 'provider' => 'anthropic', 'api_key' => 'sk' ]);
	$this->check('settings server: the provider\'s own URL, Anthropic headers, no key header without a key', [ 'https://api.anthropic.com', 'sk', '2023-06-01', 'https://api.openai.com/v1', [] ], [
	    $ant->baseUrl, $ant->httpAuth()['headers']['x-api-key'] ?? null, $ant->httpAuth()['headers']['anthropic-version'] ?? null,
	    $WA::credentialOf((object)[ 'provider' => 'openai', 'url' => '' ])->baseUrl, $WA::credentialOf((object)[ 'provider' => 'openai' ])->httpAuth()['headers'] ]);
	$this->check('ready only with a model', [ false, false, true ], [ $WA::isReady(null), $WA::isReady((object)[ 'model' => ' ' ]), $WA::isReady((object)[ 'model' => 'm' ]) ]);
	$listed = [];
	$models = $WA::models((object)[ 'provider' => 'anthropic', 'api_key' => 'sk' ], function($headers, $body, $path) use (&$listed) {
	    $listed = [ $path, $body, $headers['x-api-key'] ?? null ];
	    return json_decode(\util\JSON::encode([ 'data' => [ [ 'id' => 'claude-opus-5-5', 'display_name' => 'Claude Opus 5.5' ], [ 'id' => '' ], [ 'id' => 'claude-opus-5-5' ], [ 'id' => 'x' ] ] ]));
	});
	$this->check('models: GET /v1/models with the key, named, blanks and repeats dropped', [ [ '/v1/models?limit=1000', null, 'sk' ],
	    [ [ 'id' => 'claude-opus-5-5', 'label' => 'Claude Opus 5.5 (claude-opus-5-5)' ], [ 'id' => 'x', 'label' => 'x' ] ] ], [ $listed, $models ]);
	$WA::models((object)[ 'provider' => 'openai' ], function($headers, $body, $path) use (&$listed) { $listed = $path; return (object)[ 'data' => [] ]; });
	$this->check('models: OpenAI-compatible: GET /models', '/models', $listed);

	//CLD: 2026-10-22T14:00:00+03:00: bring your own key: Gemini, Ollama, Lemonade speak OpenAI chat completions at their own URLs (Bearer key); Gemini's model ids without models/;
	//System is the installation's server (config assistant.system), refused when there is none; the prices, the budget (the loop stops once it is spent)
	//CLD: 2026-10-23T10:00:00+03:00: the System AI (config ai.system, plugins\SystemAI; was assistant.system)
	$AS = \plugins\Assistant\models\AssistantSettings::class;
	$gem = $WA::credentialOf((object)[ 'provider' => 'gemini', 'api_key' => 'g1' ]);
	$this->check('gemini, ollama, lemonade: their own URLs, Bearer key', [ 'https://generativelanguage.googleapis.com/v1beta/openai', [ 'Authorization' => 'Bearer g1' ], 'http://localhost:11434/v1', 'http://localhost:8000/api/v1', [] ],
	    [ $gem->baseUrl, $gem->httpAuth()['headers'], $WA::credentialOf((object)[ 'provider' => 'ollama' ])->baseUrl, $WA::credentialOf((object)[ 'provider' => 'lemonade' ])->baseUrl,
	    $WA::credentialOf((object)[ 'provider' => 'ollama' ])->httpAuth()['headers'] ]);
	$models = $WA::models((object)[ 'provider' => 'gemini', 'api_key' => 'g1' ], function($headers, $body, $path) use (&$listed) {
	    $listed = $path;
	    return json_decode(\util\JSON::encode([ 'data' => [ [ 'id' => 'models/gemini-x' ] ] ]));
	});
	$this->check('models: gemini: GET /models, ids without models/', [ '/models', 'gemini-x' ], [ $listed, $models[0]['id'] ?? null ]);
	$savedSystem = \V::app()->config('ai.system');
	\V::app()->config('ai.system', [ 'provider' => '', 'model' => '' ]);
	$noSystem = [ $WA::isReady((object)[ 'provider' => 'system' ]), $AS::systemAvailable() ];
	try {
	    $WA::credentialOf((object)[ 'provider' => 'system' ]);
	    $noSystem[] = 'not refused';
	} catch (\ErrorException $e) {
	    $noSystem[] = str_contains($e->getMessage(), 'provides no System AI');
	}
	\V::app()->config('ai.system', [ 'provider' => 'anthropic', 'url' => '', 'api_key' => 'sys-key', 'model' => 'claude-sonnet-5-5', 'effort' => 'low', 'monthly_credits' => 100 ]);
	$sysCred = $WA::credentialOf((object)[ 'provider' => 'system', 'api_key' => 'mine', 'url' => 'http://evil.invalid' ]);
	$this->check('system: none: not ready, refused; else the installation\'s server and key (not the account\'s)', [ false, false, true, true, 'https://api.anthropic.com', 'sys-key' ],
	    array_merge($noSystem, [ $WA::isReady((object)[ 'provider' => 'system' ]), $sysCred->baseUrl, $sysCred->httpAuth()['headers']['x-api-key'] ?? null ]));
	//CLD: 2026-10-22T15:00:00+03:00: System: no USD budget (credits instead; was the smaller of the account's and the installation's)
	$this->check('budget: the account\'s; none with system (credits); 0 none', [ 10.0, null, null ],
	    [ $AS::budgetOf((object)[ 'provider' => 'anthropic', 'budget' => 10 ]), $AS::budgetOf((object)[ 'provider' => 'system', 'budget' => 10 ]),
	    $AS::budgetOf((object)[ 'provider' => 'anthropic', 'budget' => 0 ]) ]);
	$s = new \plugins\Assistant\util\WorkflowAssistant($wf, $this->project, (object)[ 'provider' => 'system', 'model' => 'ignored' ]);
	$s->transport = $scripted([ [ 'end_turn', [ [ 'type' => 'text', 'text' => 'ok' ] ] ] ], $sentS);
	$s->ask([], 'x', $existing);
	$this->check('system: the installation\'s model and effort', [ 'claude-sonnet-5-5', 'low', 'sys-key' ], [ $sentS[0][1]['model'], $sentS[0][1]['output_config']['effort'], $sentS[0][0]['x-api-key'] ?? null ]);
	\V::app()->config('ai.system', $savedSystem);
	$this->check('prices: known Claude models, set ones, local free, else unknown', [ [ 4.0, 20.0, 0.2 ], [ 1.0, 2.0, 0.1 ], [ 0, 0, 0 ], null ],
	    [ $AS::pricesOf((object)[ 'model' => 'claude-opus-5-5' ]), $AS::pricesOf((object)[ 'model' => 'gpt-x', 'price_input' => 1, 'price_output' => 2 ]),
	    $AS::pricesOf((object)[ 'provider' => 'ollama', 'model' => 'qwen' ]), $AS::pricesOf((object)[ 'provider' => 'openai', 'model' => 'gpt-x' ]) ]);
	//10 input + 5 output tokens per turn at 4 / 20 USD per 1M: 0.00014 USD a turn; a budget of 0.0002 is spent after 2 turns
	$a->budget = 0.0002;
	$a->transport = $scripted([ [ 'tool_use', [ $tool('t1', [ 'steps' => $fixed, 'connections' => $conn ]) ] ], [ 'tool_use', [ $tool('t2', [ 'steps' => $fixed, 'connections' => $conn ]) ] ],
	    [ 'end_turn', [ [ 'type' => 'text', 'text' => 'never' ] ] ] ], $sent);
	$r = $a->ask([], 'x', $existing);
	$a->budget = null;
	$this->check('budget spent: stopped after 2 turns, the canvas kept, said, the cost', [ 2, 2, true, 'the monthly budget of the Assistant is spent', true, 0.00028 ],
	    [ count($sent), $r['rounds'], $r['nodes'] !== null, $r['stopped'], str_contains($r['reply'], 'Stopped before finishing'), $r['cost'] ]);

	//CLD: 2026-10-22T15:00:00+03:00: System's credits (AssistantWallet): per model (its own rates, or the provider's price + the margin), the month's allowance without rollover,
	//top-ups that expire, used allowance first then the top-up that expires first, the account's monthly limit, reserved then settled; the loop stops at the reserved credits
	//CLD: 2026-10-23T10:00:00+03:00: the System AI's (plugins\SystemAI: SystemAiWallet, SystemAiLedger; were AssistantWallet, AssistantCreditLedger)
	$W = \plugins\SystemAI\models\SystemAiWallet::class;
	$table = [ 'credit_usd' => 0.01, 'default_margin' => 0.30, 'models' => [ 'claude-opus-5-5' => [ 'margin' => 0.25 ], 'my-model' => [ 'in' => 100, 'out' => 400 ] ] ];
	$this->check('credits per model: price + margin, the default margin, own rates, unknown', [ [ 500.0, 2500.0, 25.0 ], [ 260.0, 1300.0, 26.0 ], [ 100.0, 400.0, 10.0 ], null ],
	    [ $W::ratesOf((object)[ 'model' => 'claude-opus-5-5' ], $table), $W::ratesOf((object)[ 'model' => 'claude-sonnet-5-5' ], $table),
	    $W::ratesOf((object)[ 'model' => 'my-model' ], $table), $W::ratesOf((object)[ 'provider' => 'openai', 'model' => 'gpt-x' ], $table) ]);
	$this->check('credits of a request: tokens x rates (cache write 1.25 x in), charged whole, at least 1', [ 0.775, 1, 1, 3, 2 ],
	    [ $W::creditsOf([ 500, 2500, 25 ], [ 'input_tokens' => 1000, 'output_tokens' => 100, 'cache_read_input_tokens' => 1000, 'cache_creation_input_tokens' => 0 ]),
	    $W::charge(0.775), $W::charge(0), $W::charge(2.001), $W::charge(2.0000001) ]);
	$t0 = gmmktime(12, 0, 0, 3, 15, 2026);
	$w = $W::blank();
	$w['plan'] = 100;
	[ $w, $e1 ] = $W::refresh($w, $t0);
	$w['topups'] = [ [ 'id' => 'late', 'granted' => 50, 'left' => 50, 'at' => $t0, 'expires' => $t0 + 86400 * 300, 'note' => '' ],
	    [ 'id' => 'soon', 'granted' => 30, 'left' => 30, 'at' => $t0, 'expires' => $t0 + 86400 * 5, 'note' => '' ] ];
	[ $w, $unpaid ] = $W::debit($w, 120);
	$left = array_column($w['topups'], 'left', 'id');
	$this->check('wallet: the allowance first, then the top-up that expires first', [ 0, 10, 50, 0, 60, 120 ], [ $w['allowance_left'], $left['soon'], $left['late'], $unpaid, $W::balance($w), $w['month_used'] ]);
	$this->check('wallet: a monthly limit counts the month\'s use and the reservations', [ 60, 30, 0 ],
	    [ $W::available($w), $W::available($w, 150), $W::available(array_merge($w, [ 'holds' => [ [ 'id' => 'h', 'credits' => 40, 'until' => $t0 + 60 ] ] ]), 150) ]);
	$w['allowance_left'] = 40;
	[ $w2, $e2 ] = $W::refresh($w, $t0 + 86400 * 20);
	$this->check('wallet: next month the allowance is renewed, not carried over; an expired top-up is emptied', [ '2026-04', 100, 0, 0, [ 'allowance', 'expire' ], true ],
	    [ $w2['month'], $w2['allowance_left'], $w2['month_used'], array_column($w2['topups'], 'left', 'id')['soon'], array_column($e2, 'type'), str_contains($e2[0]['note'], '40 left of the last one lost') ]);
	//the database: a test account's wallet (removed after)
	$aid = (string)new \MongoDB\BSON\ObjectId();
	try {
	    $W::setPlan($aid, 20);
	    $t = $W::grant($aid, 30, 'check');
	    $h1 = $W::reserve($aid, 25);
	    $h2 = $W::reserve($aid, 0, 40);
	    $h3 = $W::reserve($aid, 0, 40);
	    $after = $W::settle($aid, $h1[0], 22, [ 'wfid' => 'w1' ]);
	    $W::settle($aid, $h2[0], 0);
	    $cur = $W::current($aid);
	    $types = array_map(fn($e) => $e->type, iterator_to_array(\plugins\SystemAI\models\SystemAiLedger::recent($aid, 10), false));
	    $this->check('wallet (db): reserve at most the limit, within the monthly limit, none left; settled from the allowance then the top-up; the ledger',
		[ 12, 25, 15, null, 28, 0, 28, 22, [ 'debit', 'topup', 'adjust', 'allowance' ] ],
		[ (int)round(($t['expires'] - $t['at']) / 86400 / 30.4), $h1[1], $h2[1], $h3, $after, $cur['allowance_left'], array_sum(array_column($cur['topups'], 'left')), $cur['month_used'], array_values(array_unique($types)) ]);
	} finally {
	    \V::app()->om->deleteOne('SystemAiWallet', [ '_id' => $aid, 'aid' => \V::app()->om->to_oid($aid) ]);
	    $L = \plugins\SystemAI\models\SystemAiLedger::class;
	    \V::app()->mongo->selectCollection($L::_database(), $L::_collection())->deleteMany([ 'aid' => \V::app()->om->to_oid($aid) ]);
	}
	//the loop limited by the reserved credits: 10 + 5 tokens a turn at 500 / 2500 credits per 1M = 0.0175 credits; 0.03 reserved: 2 turns
	$a->rates = [ 500, 2500, 25 ];
	$a->budget = 0.03;
	$a->transport = $scripted([ [ 'tool_use', [ $tool('t1', [ 'steps' => $fixed, 'connections' => $conn ]) ] ], [ 'tool_use', [ $tool('t2', [ 'steps' => $fixed, 'connections' => $conn ]) ] ],
	    [ 'end_turn', [ [ 'type' => 'text', 'text' => 'never' ] ] ] ], $sent);
	$r = $a->ask([], 'x', $existing);
	$credits = $a->credits;
	$a->rates = $a->budget = null;
	$this->check('credits reserved used up: stopped after 2 turns, the credits counted', [ 2, 'the credits reserved for this request are used up', 0.035 ], [ count($sent), $r['stopped'], round($credits, 6) ]);

	//CLD: 2026-10-19T17:02:48+03:00: a setting the model leaves wrong: the steps are applied, the setting is for the person; a refusal every time: said in the answer
	$a->transport = $scripted([ [ 'tool_use', [ $tool('t1', [ 'steps' => $steps, 'connections' => $conn ]) ] ], [ 'end_turn', [ [ 'type' => 'text', 'text' => 'Built it.' ] ] ] ], $sent);
	$r = $a->ask([], 'x', $existing);
	$this->check('settings left wrong: applied, listed for the person', [ 3, 1, true ],
	    [ count(array_filter($r['nodes'] ?? [], fn($n) => !empty($n['isNode']))), count($r['to_fix']), str_contains($r['to_fix'][0] ?? '', 'Big?') ]);
	$a->transport = $scripted([ [ 'tool_use', [ $tool('t1', [ 'steps' => [ [ 'id' => 'x', 'type' => 'app::nope' ] ], 'connections' => [] ]) ] ], [ 'end_turn', [ [ 'type' => 'text', 'text' => 'Built it.' ] ] ] ], $sent);
	$r = $a->ask([], 'x', $existing);
	$this->check('refused every time: the answer says the canvas was not changed', [ null, true ],
	    [ $r['nodes'], str_ends_with($r['reply'], '(The canvas was not changed: steps[0]: unknown type `app::nope` (list_step_types))') ]);
	//CLD: 2026-10-19T17:14:20+03:00: stopping with connection errors on the canvas: reminded with the errors, then the fixed steps
	$loose = [ 'steps' => $fixed, 'connections' => [ [ 'from' => 'start', 'to' => 'check' ] ] ];
	$a->transport = $scripted([ [ 'tool_use', [ $tool('t1', $loose) ] ], [ 'end_turn', [ [ 'type' => 'text', 'text' => 'Fixed everything.' ] ] ],
	    [ 'tool_use', [ $tool('t2', [ 'steps' => $fixed, 'connections' => $conn ]) ] ], [ 'end_turn', [ [ 'type' => 'text', 'text' => 'Connected.' ] ] ] ], $sent);
	$r = $a->ask([], 'x', $existing);
	$reminder = $sent[2][1]['messages'][count($sent[2][1]['messages']) - 1];
	$this->check('connection errors left: reminded, then fixed', [ 4, 'Connected.', 'user', true, [] ],
	    [ $r['rounds'], $r['reply'], $reminder['role'], str_contains((string)$reminder['content'], 'step `big`: Not connected to the trigger'), array_values(array_filter($r['problems'], fn($p) => $p['level'] === 'error')) ]);

	$email = array_column(array_column(\util\WorkflowSpec::stepTypes(), 'settings', 'type')['app::send-email'] ?? [], null, 'key')['email'] ?? [];
	$this->check('step types: the email designer\'s format is described', true, str_contains($email['format'] ?? '', 'blocks'));

	//failures
	$fails = function($turns, $message = 'x') use ($a, $scripted, $existing) {
	    $a->transport = $scripted($turns, $sent);
	    try {
		$a->ask([], $message, $existing);
		return 'not refused';
	    } catch (\ErrorException $e) {
		return $e->getMessage();
	    }
	};
	$this->check('empty request', 'write what the workflow should do', $fails([], '  '));
	$this->check('refusal', 'Claude declined the request', $fails([ [ 'refusal', [] ] ]));
	$this->check('cut off', 'the answer was cut off: ask for a smaller change', $fails([ [ 'max_tokens', [ [ 'type' => 'text', 'text' => '...' ] ] ] ]));
	$this->check('too many rounds', true, str_starts_with($fails(array_fill(0, 20, [ 'tool_use', [ $tool('t', [ 'steps' => [], 'connections' => 'no' ]) ] ])), 'the assistant did not finish'));
    }

    //CLD: 2026-10-21T10:00:00+03:00: the Ldap plugin (skipped without it): BER and filters, the clients (each driver PHP has here: the built-in one, and the ldap extension's
    //when it is loaded) against the plugin's test server (plugins/Ldap/tests/LdapTestServer.php: plain + StartTLS, LDAPS; a certificate made here with openssl), the LDAP
    //sign-in (in an account made for the checks, removed afterwards) and the LDAP step
    //CLD: 2026-10-21T20:10:00+03:00: the account guard of sys\ObjectManager (web requests: an account's objects only, a query for another account refused, acrossAccounts,
    //global models and the console not limited) and the sign-in of a name used in two accounts (sys\Identity::authenticate: the password picks the user, several fit: choices).
    //Account B and the users are written directly (no model hooks) and removed at the end
    private function suiteTenant() {
	$om = \V::app()->om;
	$mongo = \V::app()->mongo;
	$raw = fn($class) => $mongo->selectCollection($mongo->database, $class::_collection());
	$aId = $this->account->_id;
	$bId = new \MongoDB\BSON\ObjectId();
	$raw(\models\Account::class)->insertOne([ '_id' => $bId, 'name' => 'Check tenant B', 'state' => 'active' ]);
	$mode = \V::app()->config('app.mode');
	$projects = [];
	$users = [];
	try {
	    foreach([ 'A' => $aId, 'B' => $bId ] as $k => $aid) {
		$projects[$k] = $om->create('Project', [ 'aid' => $aid, 'name' => "Check tenant {$k}", 'owner' => $this->project->owner, 'members' => [] ]);
		$projects[$k]->save();
	    }
	    [ $pa, $pb ] = [ (string)$projects['A']->_id, (string)$projects['B']->_id ];

	    \V::app()->config('app.mode', 'web');
	    $this->check('tenant: by id, the own account\'s project only; another account\'s is not found', [ true, null ],
		[ $om->findById('Project', $pa) !== null, $om->findById('Project', $pb) ]);
	    $ids = array_map(fn($p) => (string)$p->_id, iterator_to_array($om->find('Project', [ 'name' => [ '$in' => [ 'Check tenant A', 'Check tenant B' ] ] ]), false));
	    $this->check('tenant: a list without aid holds the own account\'s only; count too', [ [ $pa ], 0 ], [ $ids, $om->count('Project', [ 'name' => 'Check tenant B' ]) ]);
	    $refused = [];
	    foreach([
		'findOne' => fn() => $om->findOne('Project', [ '_id' => $pb, 'aid' => $bId ]),
		'find' => fn() => $om->find('Project', [ 'aid' => [ '$ne' => $aId ] ]),
		'count' => fn() => $om->count('Project', [ 'aid' => (string)$bId ]),
		'updateMany' => fn() => $om->updateMany('Project', [ 'aid' => $bId ], [ '$set' => [ 'name' => 'x' ] ]),
	    ] as $name => $fn) {
		try {
		    $fn();
		    $refused[$name] = false;
		} catch (\ErrorException $e) {
		    $refused[$name] = str_contains($e->getMessage(), 'outside the current account');
		}
	    }
	    $this->check('tenant: a query naming another account (aid, an operator, a string id, an update) is refused',
		[ 'findOne' => true, 'find' => true, 'count' => true, 'updateMany' => true ], $refused);
	    $this->check('tenant: updateOne / deleteOne / findOneAndUpdate by id do not reach another account\'s project', [ 0, 0, null ], [
		$om->updateOne('Project', [ '_id' => $pb ], [ '$set' => [ 'name' => 'changed' ] ]),
		$om->deleteOne('Project', [ '_id' => $pb ]),
		$om->findOneAndUpdate('Project', [ '_id' => $pb ], [ '$set' => [ 'name' => 'changed' ] ]),
	    ]);
	    $this->check('tenant: acrossAccounts reaches it (unchanged), and the guard is back after it, also after an exception', [ 'Check tenant B', null, null ], [
		(string)($om->acrossAccounts(fn() => $om->findById('Project', $pb))->name ?? ''),
		(function() use ($om, $pb) { try { $om->acrossAccounts(function() { throw new \RuntimeException('x'); }); } catch (\RuntimeException $e) {} return $om->findById('Project', $pb); })(),
		$om->findById('Project', $pb),
	    ]);
	    $this->check('tenant: global models are not limited (Account B by id)', 'Check tenant B', (string)($om->findById('Account', (string)$bId)->name ?? ''));
	    \V::app()->config('app.mode', $mode);
	    $this->check('tenant: the console is not limited', 'Check tenant B', (string)($om->findById('Project', $pb)->name ?? ''));

	    //the sign-in: the same email in A and B
	    $sec = \V::app()->security;
	    foreach([ 'A' => $aId, 'B' => $bId ] as $k => $aid) {
		$users[$k] = new \MongoDB\BSON\ObjectId();
		$raw(\models\User::class)->insertOne([ '_id' => $users[$k], 'aid' => $aid, 'email' => 'zz-tenant@example.invalid', 'firstname' => 'Zz', 'lastname' => $k,
		    'state' => 'active', 'role' => new \MongoDB\BSON\ObjectId(), 'password' => $sec->generatePasswordHash('Same-pass-123!') ]);
	    }
	    $id = new \sys\Identity();
	    $this->check('tenant: sign-in, same name and password in two accounts: both offered', [ false, 2 ],
		[ $id->authenticate('zz-tenant@example.invalid', 'Same-pass-123!'), count($id->choices) ]);
	    $raw(\models\User::class)->updateOne([ '_id' => $users['B'] ], [ '$set' => [ 'password' => $sec->generatePasswordHash('Other-pass-456!') ] ]);
	    $signedIn = function($password) {
		$id = new \sys\Identity();
		return $id->authenticate('zz-tenant@example.invalid', $password) ? (string)$id->data()['aid'] : 'refused';
	    };
	    $this->check('tenant: sign-in, different passwords: each signs in to its own account (was: the first user found only); a wrong one is refused',
		[ (string)$aId, (string)$bId, 'refused' ], [ $signedIn('Same-pass-123!'), $signedIn('Other-pass-456!'), $signedIn('Wrong-pass-789!') ]);
	} finally {
	    \V::app()->config('app.mode', $mode);
	    foreach($projects as $p)
		$p->delete();
	    $raw(\models\User::class)->deleteMany([ '_id' => [ '$in' => array_values($users) ] ]);
	    $raw(\models\Account::class)->deleteOne([ '_id' => $bId ]);
	}
    }

    //CLD: 2026-10-22T00:00:00+03:00: the audit log's seal (models\AuditLog: numbers, MAC, verify, the retention's 1 year floor and its record of what it removed) and the
    //client's address (sys\http\Request: X-Forwarded-For only from a trusted proxy). Entries are written for an account id made up here (removed at the end, with its seal)
    private function suiteAudit() {
	$A = \models\AuditLog::class;
	$mongo = \V::app()->mongo;
	$coll = $mongo->selectCollection($A::_database(), $A::_collection());
	$seal = $mongo->selectCollection($A::_database(), $A::SEAL_COLLECTION);
	$aid = new \MongoDB\BSON\ObjectId();
	$typeMap = [ 'typeMap' => [ 'root' => 'array', 'document' => 'array', 'array' => 'array' ] ];
	//AuditLog::write / seal (protected; record() needs a web request)
	$write = \Closure::bind(fn($details) => static::write('update', null, null, [ 'aid' => $aid, 'user' => new \MongoDB\BSON\ObjectId(), 'user_name' => 'Check',
	    'ip' => '203.0.113.9', 'user_agent' => 'check', 'changes' => [ [ 'key' => 'name', 'label' => 'Name', 'before' => 'a', 'after' => 1.5 ], [ 'key' => 'x', 'label' => 'X', 'masked' => true ] ],
	    'details' => $details ]), null, $A);
	$reseal = \Closure::bind(fn($id) => static::seal($id), null, $A);
	$doc = fn($seq) => $coll->findOne([ 'aid' => $aid, 'seq' => $seq ], $typeMap);
	$problems = fn() => $A::verify($aid)['problems'];
	try {
	    foreach(range(1, 5) as $i)
		$write("check entry {$i}");
	    $r = $A::verify($aid);
	    $this->check('audit: 5 entries numbered 1 to 5, each with a MAC; verify finds no problem', [ [ 1, 2, 3, 4, 5 ], true, 5, 5, [] ],
		[ array_map(fn($i) => $doc($i)['seq'] ?? null, range(1, 5)), (bool)preg_match('/^[a-f0-9]{64}$/', $doc(3)['mac'] ?? ''), $r['entries'], $r['last'], $r['problems'] ]);

	    $two = $doc(2);
	    $coll->updateOne([ '_id' => $two['_id'] ], [ '$set' => [ 'ip' => '192.0.2.1' ] ]);
	    $changed = $problems();
	    $coll->replaceOne([ '_id' => $two['_id'] ], $two);
	    $three = $doc(3);
	    $coll->deleteOne([ '_id' => $three['_id'] ]);
	    $removed = $problems();
	    $coll->insertOne($three);
	    $five = $doc(5);
	    $coll->deleteOne([ '_id' => $five['_id'] ]);
	    $newest = $problems();
	    $coll->insertOne($five);
	    $coll->updateOne([ '_id' => $doc(4)['_id'] ], [ '$unset' => [ 'mac' => '' ] ]);
	    $noMac = $problems();
	    $reseal($doc(4)['_id']);
	    $copy = $doc(1);
	    $copy['_id'] = new \MongoDB\BSON\ObjectId();
	    $coll->insertOne($copy);
	    $twice = $problems();
	    $coll->deleteOne([ '_id' => $copy['_id'] ]);
	    $this->check('audit: verify finds a changed entry, a removed one, the newest removed, one without a MAC, a copy; and none once restored', [
		[ "entry 2 ({$two['_id']}) was changed or not written by the app (MAC)" ], [ 'entry 3 is missing' ], [ 'entry 5 is missing' ],
		[ 'entry 4 (' . $doc(4)['_id'] . ') has no MAC' ], [ "entry 1 ({$copy['_id']}) was changed or not written by the app (MAC)", "entry 1 ({$copy['_id']}) is there twice" ], [] ],
		[ $changed, $removed, $newest, $noMac, $twice, $problems() ]);

	    //the retention: 1 year at least; what it removes is not missing
	    $days = fn($v) => $A::retentionDays((object)[ 'audit_log_retention' => $v ]);
	    $this->check('audit: the retention is 1 year at least (30 days -> 365; forever and 2 years kept)', [ 365, 365, 0, 0, 730 ], [ $days('30'), $days('90'), $days('0'), $days(null), $days('730') ]);
	    foreach([ 1 => 400, 2 => 400, 3 => 100 ] as $seq => $age) {
		$coll->updateOne([ '_id' => $doc($seq)['_id'] ], [ '$set' => [ 'created_at' => new \MongoDB\BSON\UTCDateTime((time() - $age * 86400) * 1000) ] ]);
		$reseal($doc($seq)['_id']);
	    }
	    $A::prune((object)[ '_id' => $aid, 'audit_log_retention' => '30' ]);
	    $r = $A::verify($aid);
	    $kept = array_map(fn($d) => $d['seq'], iterator_to_array($coll->find([ 'aid' => $aid ], [ 'sort' => [ 'seq' => 1 ] ]), false));
	    $coll->deleteOne([ '_id' => $doc(3)['_id'] ]);
	    $this->check('audit: the retention set to 30 days removes the entries older than 1 year only (100 days kept); verify: removed by the retention, not missing; then one removed',
		[ [ 3, 4, 5 ], 2, [], [ 'entry 3 is missing' ] ], [ $kept, $r['pruned'], $r['problems'], $problems() ]);
	} finally {
	    $coll->deleteMany([ 'aid' => $aid ]);
	    $seal->deleteOne([ '_id' => $aid ]);
	}

	//the client's address
	$server = $_SERVER;
	try {
	    $ip = function($remote, $forwarded, $trusted) {
		$_SERVER = [ 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => $remote ] + ($forwarded === null ? [] : [ 'HTTP_X_FORWARDED_FOR' => $forwarded ]);
		return (new \sys\http\Request([ 'trusted_proxies' => $trusted ]))->userIP;
	    };
	    $this->check('audit: the client\'s address: the connection\'s; X-Forwarded-For only from a trusted proxy (its last address that is not a proxy)', [
		'198.51.100.7', '198.51.100.7', '203.0.113.5', '203.0.113.5', '198.51.100.7', '10.0.0.2' ], [
		$ip('198.51.100.7', null, []),
		$ip('198.51.100.7', '1.2.3.4', []),
		$ip('10.0.0.1', '1.2.3.4, 203.0.113.5', [ '10.0.0.0/8' ]),
		$ip('10.0.0.1', '1.2.3.4, 203.0.113.5, 10.0.0.2', [ '10.0.0.0/8' ]),
		$ip('198.51.100.7', '1.2.3.4', [ '10.0.0.0/8' ]),
		$ip('10.0.0.1', 'not-an-ip, 10.0.0.2', [ '10.0.0.0/8' ]),
	    ]);
	    $in = [ '2001:db8::/32', '192.0.2.0/25', '198.51.100.7' ];
	    $this->check('audit: address ranges (IPv4, IPv6, single address)', [ true, false, true, true, false, false ], array_map(fn($a) => \sys\http\Request::ipIn($a, $in),
		[ '192.0.2.127', '192.0.2.128', '2001:db8:1::5', '198.51.100.7', '198.51.100.8', 'x' ]));
	} finally {
	    $_SERVER = $server;
	}
    }

    private function suiteLdap() {
	if (!in_array('Ldap', (array)\V::app()->config('modules'), true)) {
	    echo "  skipped: the Ldap plugin is not enabled\n";
	    return;
	}
	$B = \plugins\Ldap\util\LdapBer::class;
	$C = \plugins\Ldap\util\LdapClient::class;
	$E = \plugins\Ldap\util\LdapException::class;

	//BER: integers (two's complement, fewest bytes), long lengths, a simple bind request as RFC 4511 encodes it
	$this->check('LDAP BER: integers 0, 127, 128, 256, -1, -129', [ '020100', '02017f', '02020080', '02020100', '0201ff', '0202ff7f' ],
	    array_map(fn($i) => bin2hex($B::int($i)), [ 0, 127, 128, 256, -1, -129 ]));
	$o = 0;
	$this->check('LDAP BER: lengths 127 / 200 / 300, read back', [ '7f', '81c8', '82012c', [ 300, -129 ] ],
	    [ bin2hex($B::length(127)), bin2hex($B::length(200)), bin2hex($B::length(300)), [ strlen($B::read($B::str(str_repeat('x', 300)), $o)[1]), $B::toInt(substr($B::int(-129), 2)) ] ]);
	$bind = $B::tlv(0x60, $B::int(3) . $B::str('cn=x') . $B::str('y', 0x80));
	$this->check('LDAP BER: a bind request (cn=x / y), the length of a partial / a complete message', [ '600c0201030404636e3d78800179', null, 14 ],
	    [ bin2hex($bind), $B::messageLength(substr($bind, 0, 10)), $B::messageLength($bind . 'more') ]);
	//CLD: 2026-10-21T11:00:00+03:00: Active Directory's lengths: always 0x84 + 4 bytes (leading zeros), nested ones too: a bind response { 1, [APPLICATION 1] { success, '', '' } }
	$ad = "\x30\x84\x00\x00\x00\x18" . "\x02\x84\x00\x00\x00\x01\x01" . "\x61\x84\x00\x00\x00\x0b" . "\x0a\x01\x00" . "\x04\x00" . "\x04\x84\x00\x00\x00\x00";
	$o = 0;
	[ $adTag, $adContent ] = $B::read($ad, $o);
	$adParts = $B::children($adContent);
	$adResult = array_map(fn($p) => $p[1], $B::children($adParts[1][1] ?? ''));
	$this->check('LDAP BER: Active Directory\'s 4-byte lengths (0x84 00 00 00 nn) read: the message\'s length, partial, its id, the bind result', [ 30, null, 0x30, 1, 0x61, [ "\x00", '', '' ] ],
	    [ $B::messageLength($ad . 'more'), $B::messageLength(substr($ad, 0, 5)), $adTag, $B::toInt($adParts[0][1] ?? ''), $adParts[1][0] ?? null, $adResult ]);

	//filters: parsed, encoded, decoded back
	$text = '(&(objectClass=person)(|(uid=a*b*c)(cn=\2a\28x\29))(!(mail=*))(age>=18)(sn~=pop)(member:1.2.840.113556.1.4.1941:=cn=G,dc=x)(:dn:2.5.13.5:=y))';
	$tree = $B::parseFilter($text);
	$this->check('LDAP filter: the tree', [ 'and', 7, [ 'sub', 'uid', 'a', [ 'b' ], 'c' ], [ 'eq', 'cn', '*(x)' ], [ 'not', [ 'present', 'mail' ] ], [ 'ge', 'age', '18' ],
	    [ 'ext', '1.2.840.113556.1.4.1941', 'member', 'cn=G,dc=x', false ], [ 'ext', '2.5.13.5', null, 'y', true ] ],
	    [ $tree[0], count($tree[1]), $tree[1][1][1][0], $tree[1][1][1][1], $tree[1][2], $tree[1][3], $tree[1][5], $tree[1][6] ]);
	$ber = $B::filter($text);
	$o = 0;
	[ $tag, $content ] = $B::read($ber, $o);
	$this->check('LDAP filter: (cn=x) in BER, a complex one decoded back to the same tree, no outer parentheses', [ 'a3070402636e040178', true, [ 'eq', 'uid', 'jdoe' ] ],
	    [ bin2hex($B::filter('(cn=x)')), $B::decodeFilter($tag, $content) === $tree, $B::parseFilter('uid=jdoe') ]);
	$bad = function($f) use ($B) {
	    try {
		$B::parseFilter($f);
		return 'accepted';
	    } catch (\InvalidArgumentException $e) {
		return $e->getMessage();
	    }
	};
	$this->check('LDAP filter refused: not closed, a bad escape, text after the end, no attribute', [ true, true, true, true ], [ str_contains($bad('(cn=a'), 'not closed'),
	    str_contains($bad('(cn=a\\zz)'), '2 hex digits'), str_contains($bad('(cn=a))'), 'after its end'), str_contains($bad('(=a)'), 'not a filter item') ]);

	//escaping, DNs, entries
	$this->check('LDAP escaping: a filter value, a DN value (comma, plus, leading #, trailing space)', [ 'a\\2a\\28b\\29\\5c', 'Doe\\, John\\+X', '\\#1', 'x\\ ' ],
	    [ $C::escapeFilter('a*(b)\\'), $C::escapeDn('Doe, John+X'), $C::escapeDn('#1'), $C::escapeDn('x ') ]);
	$this->check('LDAP DNs: compared in any case and spacing, an escaped comma, the first value, under a base', [ true, false, 'Doe, John', true, false, null ],
	    [ $C::dnEquals('CN=Ana Pop, OU=People,DC=Example,DC=com', 'cn=ana pop,ou=people,dc=example,dc=com'), $C::dnEquals('cn=a,dc=x', 'cn=b,dc=x'), $C::rdnValue('CN=Doe\\, John,OU=P,DC=x'),
		$C::dnUnder('uid=a,ou=P,dc=x', 'DC=X'), $C::dnUnder('uid=a,ou=P,dc=xy', 'dc=x'), $C::normalizeDn('not a dn') ]);
	$guid = hex2bin('67452301ab89efcd0123456789abcdef');
	$sid = hex2bin('010500000000000515000000010000000200000003000000f4010000');
	$this->check('LDAP entries for workflows: one value as text, several / memberOf as lists, objectGUID / objectSid as text, binary as base64, names in any case', [
	    [ 'dn' => 'cn=a', 'cn' => 'a', 'mail' => [ 'x@y', 'z@y' ], 'memberOf' => [ 'cn=g' ], 'objectGUID' => '01234567-89ab-cdef-0123-456789abcdef', 'objectSid' => 'S-1-5-21-1-2-3-500',
		'photo' => base64_encode("\x00\x01") ], [ 'x@y', 'z@y' ] ],
	    [ $C::plain([ 'dn' => 'cn=a', 'attributes' => [ 'cn' => [ 'a' ], 'mail' => [ 'x@y', 'z@y' ], 'memberOf' => [ 'cn=g' ], 'objectGUID' => [ $guid ], 'objectSid' => [ $sid ], 'photo' => [ "\x00\x01" ] ] ]),
		$C::values([ 'attributes' => [ 'MAIL' => [ 'x@y', 'z@y' ] ] ], 'mail') ]);
	$this->check('LDAP servers: hosts with ports, IPv6, ldap:// dropped; a bad one refused', [ [ [ 'a.example.com', 389 ], [ 'b', 3269 ], [ '2001:db8::1', 636 ], [ 'c', 389 ] ], true ],
	    [ $C::parseHosts('a.example.com, b:3269; [2001:db8::1]:636 ldap://c/', 389), str_contains((string)$C::hostsError('a b/c'), 'not a server') ]);
	$this->check('LDAP errors: the result code\'s text, Active Directory\'s reason', [ 'invalid credentials (49): account locked out', 'no such object (32): x', 'account locked out' ],
	    [ (new $E(49, '', '80090308: LdapErr: DSID-0C09042F, comment: AcceptSecurityContext error, data 775, v2580'))->getMessage(), (new $E(32, '', 'x'))->getMessage(),
		(new $E(49, '', 'data 775'))->adReason() ]);

	//the test servers
	$work = sys_get_temp_dir() . '/netflow9-check-ldap-' . bin2hex(random_bytes(4));
	mkdir($work, 0700);
	$servers = [];
	try {
	    $tls = self::ldapCertificate($work);
	    file_put_contents("{$work}/dir.json", json_encode(self::ldapDirectory()));
	    $servers['plain'] = self::ldapServer($work, $tls ? [ "--cert={$work}/cert.pem", "--key={$work}/key.pem" ] : []);
	    if ($tls)
		$servers['ldaps'] = self::ldapServer($work, [ "--cert={$work}/cert.pem", "--key={$work}/key.pem", '--ldaps' ]);
	    //CLD: 2026-10-21T11:00:00+03:00: answers with Active Directory's 4-byte lengths
	    $servers['long'] = self::ldapServer($work, array_merge($tls ? [ "--cert={$work}/cert.pem", "--key={$work}/key.pem" ] : [], [ '--long-lengths' ]));
	    if (!$tls)
		echo "  (no openssl: StartTLS and LDAPS not checked)\n";
	    $ca = $tls ? file_get_contents("{$work}/cert.pem") : '';
	    $port = $servers['plain'][1];

	    $drivers = [ 'php' ];
	    if (function_exists('ldap_connect'))
		$drivers[] = 'ext';
	    else
		echo "  (PHP's ldap extension is not loaded: its driver is not checked)\n";
	    foreach($drivers as $driver) {
		//CLD: 2026-10-21T11:00:00+03:00: + the server answering with 4-byte lengths (label `AD lengths`)
		$modes = [ [ 'none', $port, false, '', '' ], [ 'none', $servers['long'][1], false, '', ' AD lengths' ] ];
		if ($tls) {
		    $modes[] = [ 'starttls', $port, true, $ca, '' ];
		    $modes[] = [ 'ldaps', $servers['ldaps'][1], true, $ca, '' ];
		    $modes[] = [ 'starttls', $servers['long'][1], true, $ca, ' AD lengths' ];
		}
		foreach($modes as [ $enc, $p, $verify, $caCert, $label ])
		    $this->ldapClientChecks($driver, $enc, $p, $verify, $caCert, $label);
		if ($tls) {
		    $refused = 'connected';
		    try {
			$C::open([ 'hosts' => "127.0.0.1:{$port}", 'encryption' => 'starttls', 'verifyTls' => true, 'driver' => $driver, 'timeout' => 5 ])->close();
		    } catch (\plugins\Ldap\util\LdapException $e) {
			$refused = $e->isConnection() ? 'refused' : $e->getMessage();
		    }
		    $this->check("LDAP {$driver}: a certificate of no known authority is refused", 'refused', $refused);
		}
		$failover = $C::open([ 'hosts' => "127.0.0.1:1, 127.0.0.1:{$port}", 'encryption' => 'none', 'driver' => $driver, 'timeout' => 3 ]);
		$this->check("LDAP {$driver}: the next server when one does not answer", $port, $failover->port);
		$failover->close();
	    }

	    $this->ldapSignInChecks($port, $tls ? $ca : null);
	    $this->ldapStepChecks($port);
	} finally {
	    foreach($servers as [ $proc ]) {
		proc_terminate($proc);
		proc_close($proc);
	    }
	    foreach(glob("{$work}/*") as $f)
		unlink($f);
	    rmdir($work);
	}
    }

    //a self-signed certificate for 127.0.0.1 in $dir (cert.pem, key.pem); false without openssl
    private static function ldapCertificate($dir) {
	$p = proc_open([ 'openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', "{$dir}/key.pem", '-out', "{$dir}/cert.pem", '-days', '1', '-subj', '/CN=localhost',
	    '-addext', 'subjectAltName=DNS:localhost,IP:127.0.0.1' ], [ 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes);
	return $p && proc_close($p) === 0 && is_file("{$dir}/cert.pem");
    }

    //the test server started on dir.json of $dir: [ process, port ]
    private static function ldapServer($dir, array $args) {
	$cmd = array_merge([ PHP_BINARY, BASE . '/app/plugins/Ldap/tests/LdapTestServer.php', "{$dir}/dir.json" ], $args);
	$proc = proc_open($cmd, [ 1 => [ 'pipe', 'w' ], 2 => [ 'file', "{$dir}/server.err", 'a' ] ], $pipes);
	stream_set_timeout($pipes[1], 10);
	$line = (string)fgets($pipes[1]);
	if (!preg_match('/^PORT (\d+)/', $line, $m))
	    throw new \ErrorException('the LDAP test server did not start: ' . trim($line . ' ' . @file_get_contents("{$dir}/server.err")));
	return [ $proc, (int)$m[1] ];
    }

    //the test directory (Active Directory-like: memberOf, nested groups, its bind errors)
    private static function ldapDirectory() {
	$base = 'dc=example,dc=com';
	$person = fn($uid, $attrs) => [ 'dn' => "cn={$uid},ou=People,{$base}", 'attributes' => $attrs + [ 'objectClass' => [ 'top', 'person', 'user' ], 'objectCategory' => [ 'person' ],
	    'cn' => [ $uid ], 'sAMAccountName' => [ $uid ], 'userPassword' => [ "{$uid}pw" ], 'userAccountControl' => [ '512' ] ] ];
	return [ 'ad' => true, 'writers' => [ "cn=svc,{$base}" ], 'entries' => [
	    [ 'dn' => $base, 'attributes' => [ 'objectClass' => [ 'top', 'domain' ], 'dc' => [ 'example' ] ] ],
	    [ 'dn' => "ou=People,{$base}", 'attributes' => [ 'objectClass' => [ 'organizationalUnit' ], 'ou' => [ 'People' ] ] ],
	    [ 'dn' => "ou=Groups,{$base}", 'attributes' => [ 'objectClass' => [ 'organizationalUnit' ], 'ou' => [ 'Groups' ] ] ],
	    [ 'dn' => "cn=svc,{$base}", 'attributes' => [ 'objectClass' => [ 'person' ], 'cn' => [ 'svc' ], 'userPassword' => [ 'svcpw' ] ] ],
	    $person('ana', [ 'givenName' => [ 'Ana' ], 'sn' => [ 'Pop' ], 'mail' => [ 'ana@example.com' ], 'userPrincipalName' => [ 'ana@corp.example.com' ] ]),
	    $person('bob', [ 'givenName' => [ 'Bob' ], 'sn' => [ 'Ion' ], 'mail' => [ 'bob@example.com' ], 'x-bind-data' => [ '775' ] ]),
	    $person('carol', [ 'displayName' => [ 'Carol Maria Dan' ], 'mail' => [ 'Carol@Example.com' ] ]),
	    $person('dave', [ 'givenName' => [ 'Dave' ], 'sn' => [ 'Nobody' ], 'mail' => [ 'dave@example.com' ] ]),
	    $person('erin', [ 'givenName' => [ 'Erin' ], 'sn' => [ 'Local' ], 'mail' => [ 'erin@example.com' ] ]),
	    $person('frank', [ 'givenName' => [ 'Frank' ], 'sn' => [ 'Nomail' ] ]),
	    $person('gone', [ 'mail' => [ 'gone@example.com' ], 'userAccountControl' => [ '514' ] ]),
	    $person('dup1', [ 'mail' => [ 'dup@example.com' ] ]),
	    $person('dup2', [ 'mail' => [ 'dup@example.com' ] ]),
	    [ 'dn' => "cn=Devs,ou=Groups,{$base}", 'attributes' => [ 'objectClass' => [ 'group' ], 'cn' => [ 'Devs' ], 'member' => [ "cn=ana,ou=People,{$base}" ] ] ],
	    [ 'dn' => "cn=Ops,ou=Groups,{$base}", 'attributes' => [ 'objectClass' => [ 'group' ], 'cn' => [ 'Ops' ], 'member' => [ "cn=carol,ou=People,{$base}" ] ] ],
	    [ 'dn' => "cn=Staff,ou=Groups,{$base}", 'attributes' => [ 'objectClass' => [ 'group' ], 'cn' => [ 'Staff' ], 'member' => [ "cn=Ops,ou=Groups,{$base}", "cn=erin,ou=People,{$base}",
		"cn=frank,ou=People,{$base}", "cn=bob,ou=People,{$base}", "cn=dup1,ou=People,{$base}", "cn=dup2,ou=People,{$base}" ] ] ],
	] ];
    }

    //a client of $driver on the test server: binds, searches, limits, pages, writes
    private function ldapClientChecks($driver, $enc, $port, $verify, $caCert, $label = '') {
	$C = \plugins\Ldap\util\LdapClient::class;
	$E = \plugins\Ldap\util\LdapException::class;
	$at = "LDAP {$driver} {$enc}{$label}";
	$c = $C::open([ 'hosts' => "127.0.0.1:{$port}", 'encryption' => $enc, 'verifyTls' => $verify, 'caCert' => $caCert, 'driver' => $driver, 'timeout' => 5 ]);
	try {
	    $refusal = function($dn, $pw) use ($c, $E) {
		try {
		    $c->bind($dn, $pw);
		    return 'accepted';
		} catch (\plugins\Ldap\util\LdapException $e) {
		    return [ $e->ldapCode, $e->adReason() ];
		}
	    };
	    $this->check("{$at}: binds refused: an empty password (here, the server would accept it), a wrong one, a locked account", [ [ 49, null ], [ 49, 'invalid credentials' ], [ 49, 'account locked out' ] ],
		[ $refusal('cn=ana,ou=People,dc=example,dc=com', ''), $refusal('cn=ana,ou=People,dc=example,dc=com', 'x'), $refusal('cn=bob,ou=People,dc=example,dc=com', 'bobpw') ]);
	    $c->bind('cn=svc,dc=example,dc=com', 'svcpw');
	    $r = $c->search('dc=example,dc=com', '(&(objectCategory=person)(|(sAMAccountName=an*)(mail=bob@example.com)))', 'sub', [ 'cn', 'memberOf' ]);
	    $this->check("{$at}: a search (substrings, or), memberOf computed", [ [ 'cn=ana,ou=People,dc=example,dc=com', 'cn=bob,ou=People,dc=example,dc=com' ], [ 'cn=Devs,ou=Groups,dc=example,dc=com' ] ],
		[ array_column($r['entries'], 'dn'), $C::values($r['entries'][0] ?? [], 'memberOf') ]);
	    $nested = $c->search('dc=example,dc=com', '(member:1.2.840.113556.1.4.1941:=cn=carol,ou=People,dc=example,dc=com)', 'sub', [ '1.1' ]);
	    $enabled = $c->search('ou=People,dc=example,dc=com', '(&(objectClass=user)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))', 'one', [ '1.1' ]);
	    $this->check("{$at}: nested groups, disabled accounts left out, a one-level scope", [ [ 'Ops', 'Staff' ], 8 ],
		[ array_map(fn($e) => $C::rdnValue($e['dn']), $nested['entries']), count($enabled['entries']) ]);
	    $limited = $c->search('dc=example,dc=com', '(objectClass=*)', 'sub', [ '1.1' ], 3);
	    $paged = $c->search('dc=example,dc=com', '(objectClass=*)', 'sub', [ '1.1' ], 0, 4);
	    $pagedLimited = $c->search('dc=example,dc=com', '(objectClass=*)', 'sub', [ '1.1' ], 6, 4);
	    $this->check("{$at}: a size limit (truncated), pages of 4 (all), pages with a limit", [ [ 3, true ], [ 16, false ], [ 6, true ] ],
		[ [ count($limited['entries']), $limited['truncated'] ], [ count($paged['entries']), $paged['truncated'] ], [ count($pagedLimited['entries']), $pagedLimited['truncated'] ] ]);
	    $dn = 'cn=New\\, Person,ou=People,dc=example,dc=com';
	    $c->add($dn, [ 'objectClass' => [ 'person' ], 'cn' => [ 'New, Person' ], 'description' => [ 'a', 'b' ] ]);
	    $c->modify($dn, [ [ 'op' => 'replace', 'attr' => 'mail', 'values' => [ 'n@x.ro' ] ], [ 'op' => 'delete', 'attr' => 'description', 'values' => [ 'a' ] ], [ 'op' => 'add', 'attr' => 'title', 'values' => [ 'T' ] ] ]);
	    $read = $C::plain($c->read($dn, [ 'mail', 'description', 'title' ]));
	    $c->rename($dn, 'cn=Moved', 'ou=Groups,dc=example,dc=com');
	    $moved = [ $c->read($dn) === null, $c->read('cn=Moved,ou=Groups,dc=example,dc=com')['dn'] ?? null ];
	    $generated = $c->passwordModify('cn=Moved,ou=Groups,dc=example,dc=com', null, null);
	    $c->delete('cn=Moved,ou=Groups,dc=example,dc=com');
	    $code = function(\Closure $fn) use ($E) {
		try {
		    $fn();
		    return 0;
		} catch (\plugins\Ldap\util\LdapException $e) {
		    return $e->ldapCode;
		}
	    };
	    $this->check("{$at}: add, modify (replace, delete a value, add), rename + move, a generated password, delete; refusals: exists, non-leaf, no such entry", [
		[ 'dn' => $dn, 'mail' => 'n@x.ro', 'description' => 'b', 'title' => 'T' ], [ true, 'cn=Moved,ou=Groups,dc=example,dc=com' ], true, null, 68, 66, 32 ],
		[ $read, $moved, is_string($generated) && $generated !== '', $c->read('cn=Moved,ou=Groups,dc=example,dc=com'),
		    $code(fn() => $c->add('cn=ana,ou=People,dc=example,dc=com', [ 'cn' => [ 'ana' ] ])), $code(fn() => $c->delete('ou=People,dc=example,dc=com')),
		    $code(fn() => $c->modify('cn=none,dc=example,dc=com', [ [ 'op' => 'replace', 'attr' => 'cn', 'values' => [ 'x' ] ] ])) ]);
	    $c->bind('cn=ana,ou=People,dc=example,dc=com', 'anapw');
	    $this->check("{$at}: no write access for a person; the root DSE", [ 50, [ 'dc=example,dc=com' ] ],
		[ $code(fn() => $c->delete('cn=dave,ou=People,dc=example,dc=com')), $C::values([ 'attributes' => $c->rootDse() ], 'namingContexts') ]);
	} finally {
	    $c->close();
	}
    }

    //the LDAP sign-in, in an account made for it: provisioning, roles from (nested) groups, refusals, linking existing users, the owner kept, the directory not answering
    private function ldapSignInChecks($port, $ca) {
	$om = \V::app()->om;
	$Auth = \plugins\Ldap\util\LdapAuth::class;
	$account = \models\Account::create([ 'state' => 'active', 'name' => 'Check LDAP ' . uniqid() ]);
	$account->save();
	$restore = $this->account;
	\V::app()->var('rt.account', $account);
	$settings = null;
	try {
	    $roles = $account->setupRoles();
	    //CLD: 2026-10-21T11:30:00+03:00: om->create (LdapSettings::forAccount removed; a new account has no settings)
	    $settings = $om->create('LdapSettings', [ 'aid' => $account->_id ]);
	    $settings->assign([ 'enabled' => true, 'directory' => 'ad', 'hosts' => "127.0.0.1:{$port}", 'encryption' => $ca !== null ? 'starttls' : 'none', 'caCert' => (string)$ca,
		'bindDn' => 'cn=svc,dc=example,dc=com', 'bindPassword' => 'svcpw', 'baseDn' => 'DC=example,DC=com', 'groupSource' => 'nested', 'groupMap' => [
		    [ 'group' => 'CN=Devs,OU=Groups,DC=example,DC=com', 'role' => (string)$roles['member']->_id ], [ 'group' => 'staff', 'role' => (string)$roles['participant']->_id ] ],
		'defaultRole' => '', 'provision' => true, 'linkExisting' => false, 'domains' => 'example.com', 'bareLogins' => false ]);
	    $valid = $settings->validates();
	    $this->check('LDAP sign-in: the settings are valid', [ true, [] ], [ $valid, $settings->errors() ]);
	    if (!$valid)
		return;
	    $settings->save();
	    $Auth::reset();
	    //the attempt, then rt.account back to the account of the checks (a sign-in sets the account signed in to)
	    $try = function($login, $password) use ($Auth, $account) {
		$r = $Auth::attempt($login, $password);
		\V::app()->var('rt.account', $account);
		if ($r === null)
		    return null;
		if (!$r['user'])
		    return 'refused:' . $r['reason'];
		return 'user:' . $r['user']->email . ':' . (string)\V::app()->om->findById('Role', (string)$r['user']->role)?->iname;
	    };
	    $erin = \models\User::create([ 'aid' => $account->_id, 'email' => 'erin@example.com', 'firstname' => 'Erin', 'lastname' => 'Local', 'state' => 'active', 'role' => $roles['member']->_id,
		'password' => \V::app()->security->generatePasswordHash('localpw') ]);
	    $erin->save();

	    $this->check('LDAP sign-in: by email (domain), refusals: a wrong password, locked out, no group, several entries; not handled: disabled, unknown, another domain, a bare name', [
		'user:ana@example.com:member', 'refused:User or password is incorrect', 'refused:The directory refused the sign-in: account locked out',
		'refused:Your directory groups do not give you access to ' . \V::app()->config('app.name'),
		'refused:Several directory entries have this name: ask the account owner', null, null, null, null ],
		[ $try('ana@example.com', 'anapw'), $try('ana@example.com', 'wrong'), $try('bob@example.com', 'bobpw'), $try('dave@example.com', 'davepw'),
		    $try('dup@example.com', 'dup1pw'), $try('gone@example.com', 'gonepw'), $try('nobody@example.com', 'x'), $try('ana@other.com', 'anapw'), $try('carol', 'carolpw') ]);
	    $ana = $om->findOne('User', [ 'aid' => $account->_id, 'email' => 'ana@example.com' ]);
	    $link = $ana ? \plugins\Ldap\models\LdapLink::forUser($ana) : null;
	    $this->check('LDAP sign-in: the user created (names, no password), linked (DN, name), an LDAP user (no password here)', [ 'Ana Pop', '', 'cn=ana,ou=People,dc=example,dc=com', 'ana', 'LDAP' ],
		[ (string)$ana, (string)$ana?->password, $link?->dn, $link?->login, \sys\Identity::passwordSource($ana) ]);

	    $settings->bareLogins = true;
	    $settings->save();
	    $Auth::reset();
	    $this->check('LDAP sign-in: a bare name (bareLogins), nested groups (Ops in Staff: the second row, by name), the name from displayName, the email in lower case; ana by her directory name; frank: no email', [
		'user:carol@example.com:participant', [ 'Carol', 'Maria Dan' ], 'user:ana@example.com:member', 'refused:Your directory entry has no email address: ask the account owner' ],
		[ $try('carol', 'carolpw'), [ (string)$om->findOne('User', [ 'aid' => $account->_id, 'email' => 'carol@example.com' ])?->firstname,
		    (string)$om->findOne('User', [ 'aid' => $account->_id, 'email' => 'carol@example.com' ])?->lastname ], $try('ana', 'anapw'), $try('frank', 'frankpw') ]);

	    $this->check('LDAP sign-in: a local user of the account (Link existing users off): the local password, not the directory\'s', [ null, null ],
		[ $try('erin@example.com', 'localpw'), $try('erin@example.com', 'erinpw') ]);
	    $settings->linkExisting = true;
	    $settings->save();
	    $Auth::reset();
	    $identity = new \sys\Identity();
	    $this->check('LDAP sign-in: Link existing users on: the directory password links the user (role from the groups), the local one no longer works (Identity too)', [
		'user:erin@example.com:participant', 'refused:User or password is incorrect', false, 'User or password is incorrect', true ],
		[ $try('erin@example.com', 'erinpw'), $try('erin@example.com', 'localpw'), $identity->authenticate('erin@example.com', 'localpw'), $identity->reason,
		    (new \sys\Identity())->authenticate('erin@example.com', 'erinpw') ]);
	    \V::app()->var('rt.account', $account);

	    //the last owner keeps the role; a role from the groups otherwise
	    $ana = $om->findById('User', (string)$ana->_id);
	    $ana->role = $roles['owner']->_id;
	    $ana->save();
	    $kept = $try('ana', 'anapw');
	    $owner2 = \models\User::create([ 'aid' => $account->_id, 'email' => 'owner2@example.com', 'firstname' => 'O', 'lastname' => 'Two', 'state' => 'active', 'role' => $roles['owner']->_id ]);
	    $owner2->save();
	    $this->check('LDAP sign-in: the last owner keeps Owner; with another owner, the groups\' role', [ 'user:ana@example.com:owner', 'user:ana@example.com:member' ], [ $kept, $try('ana', 'anapw') ]);

	    $trial = $Auth::trial($settings, 'dave@example.com', 'davepw');
	    $users = $om->count('User', [ 'aid' => $account->_id ]);
	    $trialOk = $Auth::trial($settings, 'ana', 'anapw');
	    \V::app()->var('rt.account', $account);
	    $this->check('LDAP sign-in: Try (nothing saved): refused with the groups found; signed in with the role', [ false, true, true, true, $users ],
		[ $trial['ok'], in_array('Groups: none', $trial['lines'], true), $trialOk['ok'], in_array('Role: Member (group CN=Devs,OU=Groups,DC=example,DC=com)', $trialOk['lines'], true),
		    $om->count('User', [ 'aid' => $account->_id ]) ]);

	    $settings->hosts = '127.0.0.1:1';
	    $settings->timeout = 2;
	    $settings->save();
	    $Auth::reset();
	    $this->check('LDAP sign-in: the directory not answering: an LDAP user is refused, an unknown email is not handled', [ 'refused:The directory cannot be reached: try again later', null ],
		[ $try('ana', 'anapw'), $try('zed@example.com', 'x') ]);

	    $bad = \plugins\Ldap\models\LdapSettings::create([ 'aid' => $account->_id, 'enabled' => true, 'directory' => 'openldap', 'hosts' => 'a b/c', 'baseDn' => 'dc=x', 'bindDn' => 'cn=x',
		'userFilter' => '(uid=x)', 'groupSource' => 'nested', 'domains' => 'not_a_domain', 'groupFilter' => '(cn=a' ]);
	    $bad->validates();
	    $this->check('LDAP settings refused: a server, a bind DN without password, a filter without {login}, a broken filter, nested groups outside AD, a domain', [
		'hosts', 'bindPassword', 'userFilter', 'groupFilter', 'groupSource', 'domains' ], array_values(array_intersect([ 'hosts', 'bindPassword', 'userFilter', 'groupFilter', 'groupSource', 'domains' ],
		array_keys($bad->errors()))));
	} finally {
	    \V::app()->var('rt.account', $restore);
	    foreach($om->find('User', [ 'aid' => $account->_id ]) as $u)
		$u->delete();
	    foreach([ 'LdapLink', 'LdapSettings', 'Role', 'Project' ] as $name)
		foreach($om->find($name, [ 'aid' => $account->_id ]) as $o)
		    $o->delete();
	    $account->delete();
	    $Auth::reset();
	}
    }

    //the LDAP step: settings refused, the operations on the test server, the `error` output
    private function ldapStepChecks($port) {
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$om = \V::app()->om;
	$this->check('LDAP step: a step type, an `error` output when chosen', [ true, [ 'success', 'error' ] ],
	    [ isset(\models\WfNode::getAll()['app::ldap']), \plugins\Ldap\models\NodeAppLdap::outLabelsFor([ 'onError' => 'output' ]) ]);
	$creds = [];
	foreach([ 'ad', 'openldap' ] as $dir) {
	    $c = $om->create('CredentialLdap', [ 'aid' => $this->account->_id, 'project' => $this->project->_id, 'name' => "Check: LDAP {$dir} " . uniqid(), 'directory' => $dir,
		'hosts' => "127.0.0.1:{$port}", 'encryption' => 'none', 'bindDn' => 'cn=svc,dc=example,dc=com', 'bindPassword' => 'svcpw', 'baseDn' => 'dc=example,dc=com', 'timeout' => 5 ]);
	    if (!$c->validates() || !$c->save()) {
		$this->fail('LDAP step: the credential was not saved: ' . json_encode($c->errors()));
		return;
	    }
	    $creds[$dir] = $c;
	}
	try {
	    $id = (string)$creds['ad']->_id;
	    $this->check('LDAP step: the credential\'s test', true, str_contains($creds['ad']->test(), 'the Base DN was found'));
	    $errors = fn($name, $config) => implode(' | ', $this->errorsOf("ldap {$name}", [ $n('m', 'trigger::manual'), $n('l', 'app::ldap', $config + [ 'credential' => $id ]), $e('e1', 'm', 'l') ]));
	    $this->check('LDAP step: valid (search, get, modify)', [ '', '', '' ], [ $errors('search', [ 'filter' => '(cn={{ name }})' ]), $errors('get', [ 'operation' => 'get', 'dn' => '{{ x.dn }}' ]),
		$errors('modify', [ 'operation' => 'modify', 'dn' => 'cn=a,dc=x', 'changes' => [ [ 'action' => 'replace', 'name' => 'mail', 'value' => 'x' ] ] ]) ]);
	    $this->check('LDAP step refused: a broken filter, no DN, add without attributes, rename without anything, a member without a group, no credential', [ true, true, true, true, true, true ], [
		str_contains($errors('filter', [ 'filter' => '(cn=a' ]), 'not closed'), str_contains($errors('nodn', [ 'operation' => 'delete' ]), 'DN'),
		str_contains($errors('add', [ 'operation' => 'add', 'dn' => 'cn=a,dc=x' ]), 'Attributes'), str_contains($errors('rename', [ 'operation' => 'rename', 'dn' => 'cn=a,dc=x' ]), 'New RDN'),
		str_contains($errors('member', [ 'operation' => 'addMember', 'member' => 'x' ]), 'Group DN'),
		str_contains(implode(' ', $this->errorsOf('ldap nocred', [ $n('m', 'trigger::manual'), $n('l', 'app::ldap', []), $e('e1', 'm', 'l') ])), 'LDAP server') ]);

	    //a flow of steps: each one's result in its own variable
	    $step = fn($wfid, $config, $cred = null) => $n($wfid, 'app::ldap', $config + [ 'credential' => $cred ?? $id, 'variable' => $wfid ]);
	    $steps = [
		$step('s1', [ 'filter' => '(&(objectCategory=person)(sAMAccountName={{ name }}))', 'attributes' => 'mail, memberOf' ]),
		$step('s2', [ 'filter' => '(sAMAccountName={{ star }})' ]),
		$step('s3', [ 'operation' => 'get', 'dn' => '{{ s1.entries[0].dn }}', 'attributes' => 'mail' ]),
		$step('s4', [ 'operation' => 'add', 'dn' => 'cn={{ newName }},ou=People,dc=example,dc=com', 'entryAttributes' => [ [ 'name' => 'objectClass', 'value' => '{{ classes }}' ],
		    [ 'name' => 'cn', 'value' => '{{ newName }}' ], [ 'name' => 'mail', 'value' => 'new@example.com' ], [ 'name' => 'description', 'value' => '' ] ] ]),
		$step('s5', [ 'operation' => 'modify', 'dn' => '{{ s4.dn }}', 'changes' => [ [ 'action' => 'replace', 'name' => 'mail', 'value' => 'n2@example.com' ],
		    [ 'action' => 'add', 'name' => 'title', 'value' => '{{ true }}' ] ] ]),
		$step('s6', [ 'operation' => 'addMember', 'group' => 'cn=Devs,ou=Groups,dc=example,dc=com', 'member' => '{{ s4.dn }}' ]),
		$step('s7', [ 'operation' => 'addMember', 'group' => 'cn=Devs,ou=Groups,dc=example,dc=com', 'member' => '{{ s4.dn }}' ]),
		$step('s8', [ 'operation' => 'removeMember', 'group' => 'cn=Devs,ou=Groups,dc=example,dc=com', 'member' => '{{ s4.dn }}' ]),
		$step('s9', [ 'operation' => 'checkPassword', 'login' => 'ana', 'password' => '{{ pw }}' ]),
		$step('s10', [ 'operation' => 'checkPassword', 'login' => 'ana@example.com', 'password' => 'wrong' ]),
		$step('s11', [ 'operation' => 'setPassword', 'dn' => '{{ s4.dn }}' ], (string)$creds['openldap']->_id),
		$step('s12', [ 'operation' => 'rename', 'dn' => '{{ s4.dn }}', 'newParent' => 'ou=Groups,dc=example,dc=com' ]),
		$step('s13', [ 'operation' => 'get', 'dn' => '{{ s12.dn }}', 'attributes' => 'mail, title' ]),
		$step('s14', [ 'operation' => 'delete', 'dn' => '{{ s12.dn }}' ]),
		$step('s15', [ 'operation' => 'delete', 'dn' => '{{ s12.dn }}', 'onError' => 'output' ]),
		$n('err', 'app::set', [ 'variable' => 'route', 'value' => 'error' ]),
	    ];
	    $elements = array_merge([ $n('m', 'trigger::manual') ], $steps);
	    $prev = 'm';
	    foreach($steps as $i => $s) {
		if ($s['wfid'] === 'err')
		    continue;
		$elements[] = $e('e' . $i, $prev, $s['wfid']);
		$prev = $s['wfid'];
	    }
	    $elements[] = $e('eerr', 's15', 'err', 1);
	    $exe = $this->runFlow('ldap steps', $elements, [ 'name' => 'ana', 'star' => 'an*', 'newName' => 'Pop, Ion', 'classes' => [ 'top', 'person' ], 'pw' => 'anapw' ]);
	    $v = fn($name) => $exe?->getVariable($name);
	    $this->check('LDAP step: search (an escaped {{ }}: a* finds nothing), get, add (a list, an escaped DN, an empty value left out), modify', [
		'end', 1, [ 'cn=Devs,ou=Groups,dc=example,dc=com' ], 0, 'ana@example.com', 'cn=Pop\\, Ion,ou=People,dc=example,dc=com', 'cn=Pop\\, Ion,ou=People,dc=example,dc=com' ],
		[ $exe?->state, $v('s1')['count'] ?? null, $v('s1')['entries'][0]['memberOf'] ?? null, $v('s2')['count'] ?? null, $v('s3')['mail'] ?? null, $v('s4')['dn'] ?? null, $v('s5')['dn'] ?? null ]);
	    $this->check('LDAP step: members (added, already, removed), passwords (right, wrong, generated), rename + get (TRUE), delete, a second delete on the `error` output', [
		true, false, true, [ 'valid' => true, 'dn' => 'cn=ana,ou=People,dc=example,dc=com' ], false, true, 'cn=Pop\\, Ion,ou=Groups,dc=example,dc=com', [ 'n2@example.com', 'TRUE' ], 'error', null, true ],
		[ $v('s6')['changed'] ?? null, $v('s7')['changed'] ?? null, $v('s8')['changed'] ?? null, $v('s9'), $v('s10')['valid'] ?? null, is_string($v('s11')['generated'] ?? null),
		    $v('s12')['dn'] ?? null, [ $v('s13')['mail'] ?? null, $v('s13')['title'] ?? null ], $v('route'), $v('s15'), str_contains((string)self::logLine($exe, 'LDAP delete: '), 'no such object') ]);
	} finally {
	    foreach($creds as $c)
		$c->delete();
	}
    }

    //CLD: 2026-10-23T12:00:00+03:00: the Saml plugin (skipped without it), in an account made for the checks (removed afterwards), with a test IdP made here (its key pair):
    //the request and the metadata of the SP, the IdP metadata read, the responses checked (signed response / assertion, encrypted with RSA-OAEP SHA-1 / SHA-256) and refused
    //(not signed, changed, another key, signature wrapping, audience, recipient, times, InResponseTo, issuer, status, DOCTYPE, encryption required), a comment in the NameID,
    //the sign-in (provisioning, roles from groups, linking existing users, the last owner, the password of an SSO user refused), replays, the request's browser token
    private function suiteSaml() {
	if (!in_array('Saml', (array)\V::app()->config('modules'), true)) {
	    echo "  skipped: the Saml plugin is not enabled\n";
	    return;
	}
	$X = \plugins\Saml\util\SamlXml::class;
	$Sp = \plugins\Saml\util\SamlSp::class;
	$Auth = \plugins\Saml\util\SamlAuth::class;
	$om = \V::app()->om;

	//the test IdP's key pair
	$idpKey = openssl_pkey_new([ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ]);
	openssl_pkey_export($idpKey, $idpKeyPem);
	openssl_x509_export(openssl_csr_sign(openssl_csr_new([ 'commonName' => 'idp.example.test' ], $idpKey, [ 'digest_alg' => 'sha256' ]), null, $idpKey, 30, [ 'digest_alg' => 'sha256' ]), $idpCert);
	$otherKey = openssl_pkey_new([ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ]);
	openssl_pkey_export($otherKey, $otherKeyPem);
	openssl_x509_export(openssl_csr_sign(openssl_csr_new([ 'commonName' => 'evil.example.test' ], $otherKey, [ 'digest_alg' => 'sha256' ]), null, $otherKey, 30, [ 'digest_alg' => 'sha256' ]), $otherCert);

	$account = \models\Account::create([ 'state' => 'active', 'name' => 'Check SAML ' . uniqid() ]);
	$account->save();
	$restore = $this->account;
	\V::app()->var('rt.account', $account);
	try {
	    $roles = $account->setupRoles();
	    $s = $om->create('SamlSettings', [ 'aid' => $account->_id ]);
	    $s->keys();
	    $s->assign([ 'enabled' => true, 'idpEntityId' => 'https://idp.example.test/saml', 'idpSsoUrl' => 'https://idp.example.test/sso?tenant=1', 'idpCerts' => $idpCert,
		'groupMap' => [ [ 'group' => 'Devs', 'role' => (string)$roles['member']->_id ], [ 'group' => 'staff', 'role' => (string)$roles['participant']->_id ] ],
		'defaultRole' => '', 'provision' => true, 'linkExisting' => false, 'domains' => 'example.com' ]);
	    $valid = $s->validates();
	    $this->check('SAML: the settings are valid, the SP has a key pair', [ true, [], true ], [ $valid, $s->errors(), (bool)openssl_pkey_get_private((string)$s->spKey) ]);
	    if (!$valid)
		return;
	    $s->save();
	    $Auth::reset();

	    //the request: deflated, its fields, signed over the query with the SP's key
	    $url = $Sp::requestUrl($s, '_req1', 'r s');
	    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
	    $req = $X::load(gzinflate(base64_decode($q['SAMLRequest'])))->documentElement;
	    $signedPart = substr($url, strpos($url, 'SAMLRequest='), strpos($url, '&Signature=') - strpos($url, 'SAMLRequest='));
	    $this->check('SAML request: the IdP\'s URL (its query kept), ID, ACS, Issuer, NameID format, RelayState, signed with the SP\'s certificate', [
		'https://idp.example.test/sso?tenant=1&SAMLRequest=', '_req1', $s->acsUrl(), $s->spEntityId(), (string)$s->nameIdFormat, 'r s', 1 ],
		[ substr($url, 0, strpos($url, 'SAMLRequest=') + 12), $req->getAttribute('ID'), $req->getAttribute('AssertionConsumerServiceURL'),
		    $X::children($req, $X::SAML, 'Issuer')[0]->textContent ?? null, $X::children($req, $X::SAMLP, 'NameIDPolicy')[0]?->getAttribute('Format'), $q['RelayState'] ?? null,
		    openssl_verify($signedPart, base64_decode($q['Signature']), (string)$s->spCert, 'sha256') ]);

	    //the SP's metadata, read back as the IdP would
	    $md = $X::load($Sp::metadata($s));
	    $xp = $X::xpath($md);
	    $this->check('SAML SP metadata: entity ID, the ACS (HTTP-POST), the certificate for signing and encryption', [ $s->spEntityId(), $s->acsUrl(), 2, true ], [
		$md->documentElement->getAttribute('entityID'), $xp->query('//md:AssertionConsumerService[@Binding="' . $Sp::BINDING_POST . '"]')->item(0)?->getAttribute('Location'),
		$xp->query('//md:KeyDescriptor//ds:X509Certificate')->length, $xp->query('//ds:X509Certificate')->item(0)?->textContent === $X::certBody($s->spCert) ]);

	    //an IdP's metadata (an EntitiesDescriptor, a POST and a Redirect binding)
	    $idpMd = '<md:EntitiesDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><md:EntityDescriptor entityID="https://idp.example.test/saml">'
		. '<md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol"><md:KeyDescriptor use="signing"><ds:KeyInfo><ds:X509Data><ds:X509Certificate>'
		. $X::certBody($idpCert) . '</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor><md:KeyDescriptor use="encryption"><ds:KeyInfo><ds:X509Data><ds:X509Certificate>'
		. $X::certBody($otherCert) . '</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor><md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>'
		. '<md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="https://idp.example.test/post"/>'
		. '<md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://idp.example.test/redirect"/></md:IDPSSODescriptor></md:EntityDescriptor></md:EntitiesDescriptor>';
	    $read = $Sp::idpMetadata($idpMd);
	    $this->check('SAML IdP metadata: entity ID, the HTTP-Redirect sign-on URL, the signing certificate only', [ 'https://idp.example.test/saml', 'https://idp.example.test/redirect', $idpCert ],
		[ $read['idpEntityId'], $read['idpSsoUrl'], $read['idpCerts'] ]);

	    //a response of the test IdP; $o: what to change
	    $now = time();
	    $response = function(array $o = []) use ($s, $X, $Sp, $idpKeyPem, $idpCert, $now) {
		$o += [ 'irt' => '_req1', 'issuer' => 'https://idp.example.test/saml', 'audience' => $s->spEntityId(), 'recipient' => $s->acsUrl(), 'destination' => $s->acsUrl(),
		    'nameId' => 'ana@example.com', 'format' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress', 'notBefore' => $now - 60, 'notOnOrAfter' => $now + 300,
		    'attributes' => [ 'givenName' => [ 'Ana' ], 'sn' => [ 'Pop' ], 'groups' => [ 'Devs' ] ], 'status' => $Sp::SUCCESS, 'signAssertion' => true, 'signResponse' => false,
		    'encrypt' => null, 'key' => $idpKeyPem, 'cert' => $idpCert, 'change' => null ];
		$t = fn($ts) => gmdate('Y-m-d\TH:i:s\Z', $ts);
		$doc = new \DOMDocument();
		$el = function(\DOMNode $parent, $ns, $name, $attrs = [], $text = null) use ($doc) {
		    $e = $doc->createElementNS($ns, $name);
		    foreach($attrs as $k => $v)
			if ($v !== null)
			    $e->setAttribute($k, (string)$v);
		    if ($text !== null)
			$e->appendChild($doc->createTextNode((string)$text));
		    return $parent->appendChild($e);
		};
		$r = $el($doc, $X::SAMLP, 'samlp:Response', [ 'ID' => '_r' . bin2hex(random_bytes(8)), 'Version' => '2.0', 'IssueInstant' => $t($now), 'Destination' => $o['destination'],
		    'InResponseTo' => $o['irt'] ]);
		$r->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:saml', $X::SAML);
		$el($r, $X::SAML, 'saml:Issuer', [], $o['issuer']);
		$st = $el($r, $X::SAMLP, 'samlp:Status');
		$el($st, $X::SAMLP, 'samlp:StatusCode', [ 'Value' => $o['status'] ]);
		if ($o['status'] !== $Sp::SUCCESS)
		    $el($st, $X::SAMLP, 'samlp:StatusMessage', [], 'no such user');
		$a = $el($r, $X::SAML, 'saml:Assertion', [ 'ID' => '_a' . bin2hex(random_bytes(8)), 'Version' => '2.0', 'IssueInstant' => $t($now) ]);
		$el($a, $X::SAML, 'saml:Issuer', [], $o['issuer']);
		$sub = $el($a, $X::SAML, 'saml:Subject');
		$el($sub, $X::SAML, 'saml:NameID', [ 'Format' => $o['format'] ], $o['nameId']);
		$el($el($sub, $X::SAML, 'saml:SubjectConfirmation', [ 'Method' => $Sp::BEARER ]), $X::SAML, 'saml:SubjectConfirmationData',
		    [ 'NotOnOrAfter' => $t($o['notOnOrAfter']), 'Recipient' => $o['recipient'], 'InResponseTo' => $o['irt'] ]);
		$c = $el($a, $X::SAML, 'saml:Conditions', [ 'NotBefore' => $t($o['notBefore']), 'NotOnOrAfter' => $t($o['notOnOrAfter']) ]);
		$el($el($c, $X::SAML, 'saml:AudienceRestriction'), $X::SAML, 'saml:Audience', [], $o['audience']);
		$el($el($el($a, $X::SAML, 'saml:AuthnStatement', [ 'AuthnInstant' => $t($now), 'SessionIndex' => '_s1' ]), $X::SAML, 'saml:AuthnContext'), $X::SAML,
		    'saml:AuthnContextClassRef', [], 'urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport');
		$as = $el($a, $X::SAML, 'saml:AttributeStatement');
		foreach($o['attributes'] as $name => $values) {
		    $at = $el($as, $X::SAML, 'saml:Attribute', [ 'Name' => $name ]);
		    foreach($values as $v)
			$el($at, $X::SAML, 'saml:AttributeValue', [], $v);
		}
		if ($o['signAssertion'])
		    $X::sign($a, $o['key'], $o['cert']);
		if ($o['encrypt'] !== null)
		    $X::encrypt($a, (string)$s->spCert, $o['encrypt']);
		if ($o['signResponse'])
		    $X::sign($r, $o['key'], $o['cert']);
		if ($o['change'])
		    ($o['change'])($doc, $X::xpath($doc));
		return base64_encode($doc->saveXML());
	    };
	    $claims = function($b64, $expected = '_req1') use ($Sp, $s) {
		try {
		    return $Sp::response($s, $b64, $expected);
		} catch (\plugins\Saml\util\SamlException $e) {
		    return 'refused: ' . $e->getMessage();
		}
	    };
	    $refused = fn($b64, $part, $expected = '_req1') => is_string($r = $claims($b64, $expected)) && str_contains($r, $part) ? true : $r;

	    $c1 = $claims($response());
	    $this->check('SAML response: a signed assertion: the NameID, its format, the attributes, signed by the assertion, InResponseTo read before the check', [
		'ana@example.com', 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress', [ 'Ana' ], [ 'Devs' ], [ 'assertion' ], false, '_s1', '_req1' ],
		[ $c1['nameId'] ?? $c1, $c1['format'] ?? null, $c1['attributes']['givenName'] ?? null, $c1['attributes']['groups'] ?? null, $c1['signedBy'] ?? null, $c1['encrypted'] ?? null,
		    $c1['sessionIndex'] ?? null, $Sp::inResponseTo($response()) ]);
	    $c2 = $claims($response([ 'signAssertion' => false, 'signResponse' => true ]));
	    $c3 = $claims($response([ 'signResponse' => true, 'encrypt' => 'sha1' ]));
	    $c4 = $claims($response([ 'encrypt' => 'sha256' ]));
	    $this->check('SAML response: a signed response; both signed and the assertion encrypted (RSA-OAEP SHA-1, AES-256-GCM); encrypted with RSA-OAEP SHA-256', [
		[ 'ana@example.com', [ 'response' ] ], [ 'ana@example.com', [ 'response', 'assertion' ], true ], [ 'ana@example.com', true ] ],
		[ [ $c2['nameId'] ?? $c2, $c2['signedBy'] ?? null ], [ $c3['nameId'] ?? $c3, $c3['signedBy'] ?? null, $c3['encrypted'] ?? null ], [ $c4['nameId'] ?? $c4, $c4['encrypted'] ?? null ] ]);

	    //a comment in the signed NameID (the canonical form leaves it out): the whole text is the NameID, not the part before the comment
	    $c5 = $claims($response([ 'nameId' => 'ana@example.com.evil.test', 'change' => function($doc, $xp) {
		$t = $xp->query('//saml:NameID')->item(0)->firstChild;
		$t->splitText(strlen('ana@example.com'));
		$t->parentNode->insertBefore($doc->createComment(''), $t->nextSibling);
	    } ]));
	    $this->check('SAML response: a comment put in the signed NameID: still valid, the NameID is all of its text', 'ana@example.com.evil.test', $c5['nameId'] ?? $c5);

	    $nameId = fn($doc, $xp) => $xp->query('//saml:NameID')->item(0)->firstChild->data = 'admin@example.com';
	    $this->check('SAML response refused: not signed, changed after signing, signed with another key, the assertion changed under a signed response', [ true, true, true, true ], [
		$refused($response([ 'signAssertion' => false ]), 'neither the response nor the assertion is signed'),
		$refused($response([ 'change' => $nameId ]), 'does not match'),
		$refused($response([ 'key' => $otherKeyPem, 'cert' => $otherCert ]), 'is not the IdP\'s'),
		$refused($response([ 'signAssertion' => false, 'signResponse' => true, 'change' => $nameId ]), 'does not match') ]);

	    //signature wrapping: a forged assertion next to the signed one, the signed one hidden elsewhere, a second element with the signed ID, the signature moved
	    $forged = function($doc, $xp, $where) {
		$signed = $xp->query('//saml:Assertion')->item(0);
		$evil = $signed->cloneNode(true);
		foreach($xp->query('.//ds:Signature', $evil) as $sig)
		    $sig->parentNode->removeChild($sig);
		$xp->query('.//saml:NameID', $evil)->item(0)->firstChild->data = 'admin@example.com';
		if ($where === 'sibling') {
		    $signed->parentNode->insertBefore($evil, $signed);
		} elseif ($where === 'hidden') {
		    $evil->setAttribute('ID', '_evil');
		    $ext = $doc->createElementNS(\plugins\Saml\util\SamlXml::SAMLP, 'samlp:Extensions');
		    $signed->parentNode->insertBefore($ext, $xp->query('//samlp:Status')->item(0));
		    $signed->parentNode->replaceChild($evil, $signed);
		    $ext->appendChild($signed);
		} else {
		    $evil->setAttribute('ID', '_evil');
		    $evil->insertBefore($xp->query('./ds:Signature', $signed)->item(0), $xp->query('./saml:Subject', $evil)->item(0));
		    $signed->parentNode->replaceChild($evil, $signed);
		}
	    };
	    $this->check('SAML response refused, signature wrapping: a forged assertion next to the signed one, the signed one moved to Extensions, the signature moved to a forged one', [ true, true, true ], [
		$refused($response([ 'change' => fn($d, $x) => $forged($d, $x, 'sibling') ]), 'several assertions'),
		$refused($response([ 'change' => fn($d, $x) => $forged($d, $x, 'hidden') ]), 'assertions elsewhere'),
		$refused($response([ 'change' => fn($d, $x) => $forged($d, $x, 'moved') ]), 'the signature is not of the Assertion') ]);
	    $dupId = function($doc, $xp) {
		$a = $xp->query('//saml:Assertion')->item(0);
		$copy = $doc->createElementNS(\plugins\Saml\util\SamlXml::SAMLP, 'samlp:Extensions');
		$copy->setAttribute('ID', $a->getAttribute('ID'));
		$a->parentNode->insertBefore($copy, $xp->query('//samlp:Status')->item(0));
	    };
	    $this->check('SAML response refused: another element with the signed ID', true, $refused($response([ 'change' => $dupId ]), 'more than once'));

	    $this->check('SAML response refused: another audience, another recipient, another destination, expired, not valid yet, another request, unsolicited, another issuer', [
		true, true, true, true, true, true, true, true ], [
		$refused($response([ 'audience' => 'https://other.example.test' ]), 'for another service provider'),
		$refused($response([ 'recipient' => 'https://other.example.test/acs' ]), 'Recipient'),
		$refused($response([ 'destination' => 'https://other.example.test/acs' ]), 'Destination'),
		$refused($response([ 'notOnOrAfter' => $now - 600, 'notBefore' => $now - 900 ]), 'expired'),
		$refused($response([ 'notBefore' => $now + 900, 'notOnOrAfter' => $now + 1200 ]), 'not valid yet'),
		$refused($response(), 'not the answer to the request sent', '_req2'),
		$refused($response(), 'a request that is not waiting', null),
		$refused($response([ 'issuer' => 'https://evil.example.test' ]), 'another IdP') ]);
	    $s->requireEncryption = true;
	    $enc = [ $refused($response(), 'not encrypted'), $claims($response([ 'encrypt' => 'sha1' ]))['nameId'] ?? null ];
	    $s->requireEncryption = false;
	    $this->check('SAML response: the IdP\'s refusal (status, message), a DOCTYPE refused, encryption required: a plain assertion refused, an encrypted one taken', [
		true, true, true, 'ana@example.com' ], array_merge([ $refused($response([ 'status' => 'urn:oasis:names:tc:SAML:2.0:status:Responder' ]), 'Responder: no such user'),
		$refused(base64_encode('<!DOCTYPE x [<!ENTITY e "x">]><samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"/>'), 'DOCTYPE') ], $enc));

	    //the sign-in
	    $signIn = function(array $o = [], $dry = false) use ($response, $claims, $Auth, $s, $account, $om) {
		$c = $claims($response($o));
		if (is_string($c))
		    return $c;
		$r = $Auth::signIn($s, $c, [ 'dry' => $dry ]);
		\V::app()->var('rt.account', $account);
		if (!$r['user'])
		    return 'refused:' . $r['reason'];
		return 'user:' . $r['user']->email . ':' . (string)$om->findById('Role', (string)$r['user']->role)?->iname;
	    };
	    $users = $om->count('User', [ 'aid' => $account->_id ]);
	    $dry = $signIn([], true);
	    $this->check('SAML sign-in: a test (dry): signed in, nothing saved', [ 'user:ana@example.com:member', $users ], [ $dry, $om->count('User', [ 'aid' => $account->_id ]) ]);
	    $first = $signIn();
	    $ana = $om->findOne('User', [ 'aid' => $account->_id, 'email' => 'ana@example.com' ]);
	    $link = $ana ? \plugins\Saml\models\SamlLink::forUser($ana) : null;
	    $this->check('SAML sign-in: the user created (names, role from the group), linked by its NameID, an SSO user; again: the same user', [
		'user:ana@example.com:member', 'Ana Pop', 'ana@example.com', 'SSO', 'user:ana@example.com:member', 1 ],
		[ $first, (string)$ana, $link?->subject, \sys\Identity::passwordSource($ana), $signIn(), $om->count('User', [ 'aid' => $account->_id, 'email' => 'ana@example.com' ]) ]);
	    $this->check('SAML sign-in: a group as a DN (CN=Staff,...) matches the row `staff`; refused: in no group (no default role), a transient NameID without email', [
		'user:carol@example.com:participant', 'refused:Your groups at the identity provider do not give you access to ' . \V::app()->config('app.name'),
		'refused:The IdP sent no email address for you: ask the account owner' ], [
		$signIn([ 'nameId' => 'c-123', 'format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
		    'attributes' => [ 'email' => [ 'Carol@Example.com' ], 'groups' => [ 'CN=Staff, OU=Groups,DC=example,DC=com' ] ] ]),
		$signIn([ 'nameId' => 'dave@example.com', 'attributes' => [ 'groups' => [ 'Others' ] ] ]),
		$signIn([ 'nameId' => '_t1', 'format' => \plugins\Saml\models\SamlSettings::TRANSIENT, 'attributes' => [ 'groups' => [ 'Devs' ] ] ]) ]);

	    //an existing user with a password
	    $erin = \models\User::create([ 'aid' => $account->_id, 'email' => 'erin@example.com', 'firstname' => 'Erin', 'lastname' => 'Local', 'state' => 'active', 'role' => $roles['member']->_id,
		'password' => \V::app()->security->generatePasswordHash('localpw') ]);
	    $erin->save();
	    $erinIdp = [ 'nameId' => 'erin@example.com', 'attributes' => [ 'groups' => [ 'staff' ] ] ];
	    $off = $signIn($erinIdp);
	    $passwordBefore = (new \sys\Identity())->authenticate('erin@example.com', 'localpw');
	    $s->linkExisting = true;
	    $s->save();
	    $Auth::reset();
	    $on = $signIn($erinIdp);
	    $identity = new \sys\Identity();
	    $this->check('SAML sign-in: an existing user: refused with Link existing users off (its password works); on: linked, its password removed and refused (Identity says SSO)', [
		'refused:You have a user with a password here: sign in with it, or ask the account owner to link it to single sign-on', true, 'user:erin@example.com:participant', '',
		false, 'This user signs in with single sign-on: use "Sign in with SSO"' ],
		[ $off, $passwordBefore, $on, (string)$om->findOne('User', [ 'aid' => $account->_id, 'email' => 'erin@example.com' ])?->password,
		    $identity->authenticate('erin@example.com', 'localpw'), $identity->reason ]);
	    \V::app()->var('rt.account', $account);

	    //the last owner keeps the role
	    $ana = $om->findById('User', (string)$ana->_id);
	    $ana->role = $roles['owner']->_id;
	    $ana->save();
	    $this->check('SAML sign-in: the last owner keeps Owner', 'user:ana@example.com:owner', $signIn());

	    //replays, the request's browser token, "Sign in with SSO"
	    $once = $claims($response());
	    $replay = [ $Auth::useOnce($account->_id, $once), $Auth::useOnce($account->_id, $once) ];
	    [ $rq, $token ] = \plugins\Saml\models\SamlRequest::start($account->_id);
	    [ $rq2, ] = \plugins\Saml\models\SamlRequest::start($account->_id);
	    $taken = [ \plugins\Saml\models\SamlRequest::take($account->_id, $rq2->request_id, $token) === null, \plugins\Saml\models\SamlRequest::take($account->_id, $rq->request_id, $token)?->request_id,
		\plugins\Saml\models\SamlRequest::take($account->_id, $rq->request_id, $token) ];
	    $this->check('SAML: an assertion used once; a request taken with its browser\'s token only, once; Sign in with SSO: by the domain, an SSO user\'s email, not another domain', [
		[ true, false ], [ true, $rq->request_id, null ], (string)$account->_id, (string)$account->_id, null ],
		[ $replay, $taken, (string)$Auth::accountFor('Someone@Example.com')?->aid, (string)$Auth::accountFor('erin@example.com')?->aid, $Auth::accountFor('x@other.test') ]);

	    $bad = $om->create('SamlSettings', [ 'aid' => $account->_id ]);
	    $bad->assign([ 'enabled' => true, 'idpSsoUrl' => 'ftp://x', 'idpCerts' => '-----BEGIN CERTIFICATE-----x-----END CERTIFICATE-----', 'domains' => 'not_a_domain' ]);
	    $bad->validates();
	    $this->check('SAML settings refused: the sign-on URL, an unreadable certificate, a domain, no IdP entity ID', [ 'idpSsoUrl', 'idpCerts', 'domains', 'idpEntityId' ],
		array_values(array_intersect([ 'idpSsoUrl', 'idpCerts', 'domains', 'idpEntityId' ], array_keys($bad->errors()))));
	} finally {
	    \V::app()->var('rt.account', $restore);
	    foreach($om->find('User', [ 'aid' => $account->_id ]) as $u)
		$u->delete();
	    foreach([ 'SamlLink', 'SamlSettings', 'SamlRequest', 'SamlReplay', 'Role', 'Project' ] as $name)
		foreach($om->find($name, [ 'aid' => $account->_id ]) as $o)
		    $o->delete();
	    $account->delete();
	    $Auth::reset();
	}
    }

    private function suiteSteps() {
	$n = [ self::class, 'node' ];
	$e = [ self::class, 'edge' ];
	$inner = [];
	for($k = 0; $k < 12; $k++) {
	    $inner[] = $n("s{$k}", 'app::set', [ 'variable' => 'v', 'value' => '{{ item }}' ], 'L');
	    if ($k)
		$inner[] = $e("i{$k}", 's' . ($k - 1), "s{$k}");
	}
	$exe = $this->runFlow('steps big loop', array_merge([ $n('m', 'trigger::manual'), $n('L', 'core::loop', [ 'list' => '{{ Array.from({length: 1000}, (_, i) => i) }}' ]), $e('e1', 'm', 'L') ], $inner));
	$this->check('1000 items x 12 steps (more than the run limit)', [ 'end', 1000 ], [ $exe?->state, $exe?->getVariable('loop')['count'] ?? null ]);

	$exe = $this->runFlow('steps cycle in a pass', [ $n('m', 'trigger::manual'), $n('L', 'core::loop', [ 'list' => '{{ [1] }}' ]), $n('a', 'app::set', [ 'variable' => 'x', 'value' => '1' ], 'L'),
	    $n('b', 'app::set', [ 'variable' => 'y', 'value' => '1' ], 'L'), $n('c', 'app::set', [ 'variable' => 'z', 'value' => '1' ], 'L'),
	    $e('e1', 'm', 'L'), $e('x1', 'a', 'b'), $e('x2', 'b', 'c'), $e('x3', 'c', 'b') ]);
	$this->check('an endless cycle in a pass stops', [ 'error', 'too many steps in one pass of the loop `L` (endless cycle?)' ], [ $exe?->state, $exe?->error ]);

	$exe = $this->runFlow('steps top cycle', [ $n('m', 'trigger::manual'), $n('a', 'app::set', [ 'variable' => 'x', 'value' => '1' ]), $n('b', 'app::set', [ 'variable' => 'y', 'value' => '1' ]),
	    $e('e1', 'm', 'a'), $e('e2', 'a', 'b'), $e('e3', 'b', 'a') ]);
	$this->check('an endless cycle outside loops stops', [ 'error', 'too many steps (endless loop?)' ], [ $exe?->state, $exe?->error ]);
    }
}
