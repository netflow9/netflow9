<?php

/*
    the Assistant of the workflow designer (right-side inspector):
	the person describes a workflow or a change in their own words, Claude builds it on the canvas.

    An agent loop on the Anthropic Messages API (raw HTTP, as models\node\NodeAppClaude; an Anthropic credential of the workflow's project):
//  the system prompt holds the rules and the step types (util\WorkflowSpec::stepTypes; cached: it is the same for every request of the project),
//  the last user turn holds the designer's current steps (unsaved changes included) as a spec (util\WorkflowSpec::toSpec), then the request;
//  tools: set_workflow (the whole new canvas: steps + connections, built by util\WorkflowSpec::nodesFrom and checked by Workflow::validates, as when saving in the designer;
//  refused settings go back to the model with the reasons, it fixes them) and some read-only project tools (util\McpTools, as the user: their permissions apply).
//CLD: 2026-10-20T23:40:00+03:00: the project tools come from the plugins (util\MCPTools; the SysMCPTools plugin's MCP tools): without them, set_workflow only
//Nothing is saved here: the designer shows the new steps, the person reviews and saves them (and publishes) as always.
//The conversation is kept by the designer and sent back as text turns ([ { role, text } ]); thinking blocks are kept only within one request's loop.
//ask() = { reply, nodes (the designer's data; null when the canvas was not changed), problems (Workflow::validateFlow of the new steps), rounds, usage }; \ErrorException on failure
//CLD: 2026-10-19T16:05:12+03:00: + an OpenAI-compatible model (OpenAI, or a local server: Lemonade, Ollama, vLLM, ...), with an OpenAI API credential of the project
//(models\credential\CredentialOpenAI): POST <base URL>/chat/completions, tools as functions, results as `tool` messages (as models\node\NodeAppOpenAI); the model from the
//account settings (assistant_openai_model), else the first one the server lists. The provider follows the credential; the loop is the same (turnAnthropic / turnOpenAI)
//CLD: 2026-10-20T10:00:00+03:00: only the Assistant's settings (models\AssistantSettings: provider, url, api_key, model, effort; credentialOf) are used, no longer a credential
//of the workflow's project; models(): the models of the settings' server (the settings page's Connect)
*/

namespace plugins\Assistant\util;

use plugins\Assistant\models\AssistantSettings;

class WorkflowAssistant {
    const MAX_TOKENS = 32000;
    //most calls to the model per request (each tool use is one more)
    const MAX_ROUNDS = 12;
    //CLD: 2026-10-19T17:14:20+03:00: how many times a model that stops with connection errors on the canvas is asked to fix them
    const MAX_REMINDERS = 2;
    //per call to the model (s)
    const TIMEOUT = 600;
    //CLD: 2026-10-20T10:00:00+03:00: for listing the server's models (Connect, s)
    const MODELS_TIMEOUT = 20;
    //the largest answer read (bytes)
    const MAX_RESPONSE = 10 * 1024 * 1024;
    //earlier turns sent back, and their length (characters)
    const MAX_HISTORY = 20;
    const MAX_TURN = 10000;
    //a tool result longer than this is cut (characters)
    const MAX_TOOL_RESULT = 30000;
    //the models server-side fallback is offered for (as NodeAppClaude::FALLBACK_MODELS)
    const FALLBACK_MODELS = '/^claude-(opus-5|sonnet-5-5|fable-5)/';

    //the read-only project tools the model may use (util\McpTools, as the user)
    const PROJECT_TOOLS = [
	'list_tables',
	'list_variables',
	'list_workflows',
	'get_workflow',
	'list_files'
    ];

    private $wf;
    private $cred;
    private $project;
    //the designer's steps while the model works: [ element ] (a list, as the designer sends them)
    private $nodes = [];
    private $changed = false;
    //CLD: 2026-10-19T17:02:48+03:00: the settings of the current steps that are still wrong (set_workflow applies the steps anyway; the person fills them in), and why the last
    //set_workflow was refused (told to the person when the canvas was not changed: models may say they built it anyway)
    private $toFix = [];
    private $lastError = null;

    private $model;
    private $effort;
    private $provider;

    //the call to the API: fn(array $headers, ?array $body (null: GET), string $path) => object (the decoded answer); null = HTTP (the checks give their own)
    //CLD: 2026-10-19T16:05:12+03:00: + $path (/v1/messages, /chat/completions, /models), GET
    public $transport = null;

    //CLD: 2026-10-19T20:40:00+03:00: + $settings: the Assistant's settings (models\AssistantSettings, or an object with the same fields); default: those of the workflow's account (were
    //the account's assistant_* fields)
    //CLD: 2026-10-20T10:00:00+03:00: no $cred: the server and its key are the settings' (credentialOf); the provider is the settings' (was the credential's)
    public function __construct($wf, $project, $settings = null) {
	$this->wf = $wf;
	$this->project = $project;
	$settings = $settings ?? \V::app()->om->findOne('AssistantSettings', [ 'aid' => $wf->aid ]);

	$this->provider = self::providerOf($settings);
	$this->cred = self::credentialOf($settings);
	//CLD: 2026-10-19T16:05:12+03:00: OpenAI-compatible: the model ('' = the server's first, see turnOpenAI); effort is Claude's only
	if ($this->provider === 'openai') {
	    $this->model = trim((string)($settings->model ?? ''));
	    return;
	}
	$model = trim((string)($settings->model ?? ''));
	$this->model = $model !== '' ? $model : AssistantSettings::DEFAULT_MODEL;
	$this->effort = $settings->effort ?? AssistantSettings::DEFAULT_EFFORT;
    }

    //CLD: 2026-10-20T10:00:00+03:00: the server of the Assistant's settings $settings (provider, url, api_key) as a credential: baseUrl, httpAuth (Anthropic: x-api-key and
    //anthropic-version, as models\credential\CredentialAnthropic; OpenAI-compatible: Bearer, when there is a key), provides (the provider). Was accountServer (OpenAI-compatible only)
    public static function credentialOf($settings) {
	$provider = self::providerOf($settings);
	$url = trim((string)($settings->url ?? ''));
	//CLD: 2026-10-20T10:45:00+03:00: AssistantSettings::DEFAULT_URLS (was self::)
	return new class($provider, $url !== '' ? $url : AssistantSettings::DEFAULT_URLS[$provider], (string)($settings->api_key ?? '')) {
	    public function __construct(private $provider, public $baseUrl, private $apiKey) {}
	    public function httpAuth() {
		if ($this->provider === 'anthropic')
		    return [ 'headers' => [ 'x-api-key' => $this->apiKey, 'anthropic-version' => \models\credential\CredentialAnthropic::API_VERSION ], 'query' => [] ];
		return [ 'headers' => $this->apiKey !== '' ? [ 'Authorization' => 'Bearer ' . $this->apiKey ] : [], 'query' => [] ];
	    }
	    public function provides($capability) { return $capability === $this->provider; }
	};
    }

    //CLD: 2026-10-20T10:00:00+03:00: whether the Assistant can work with $settings: a model was chosen (after a Connect, on the settings page)
    public static function isReady($settings) {
	return trim((string)($settings->model ?? '')) !== '';
    }

    //CLD: 2026-10-20T10:00:00+03:00: the models the server of $settings lists (Anthropic: GET /v1/models, OpenAI-compatible: GET /models): [ { id, label } ], in the server's
    //order; \ErrorException when it cannot be reached or refuses the key. $transport: as the instances' (the checks)
    public static function models($settings, $transport = null) {
	$provider = self::providerOf($settings);
	$cred = self::credentialOf($settings);
	$path = $provider === 'anthropic' ? '/v1/models?limit=1000' : '/models';
	$headers = $cred->httpAuth()['headers'];
	$r = $transport !== null ? $transport($headers, null, $path)
	    : self::http(rtrim((string)$cred->baseUrl, '/') . $path, $headers, null, $provider === 'anthropic' ? 'Anthropic' : 'The server', self::MODELS_TIMEOUT);
	$r = json_decode(\util\JSON::encode($r), true);
	$models = [];
	foreach((array)($r['data'] ?? []) as $m) {
	    $id = is_array($m) ? trim((string)($m['id'] ?? '')) : '';
	    if ($id === '' || isset($models[$id]))
		continue;
	    $name = trim((string)($m['display_name'] ?? ''));
	    $models[$id] = [ 'id' => $id, 'label' => $name !== '' && $name !== $id ? "{$name} ({$id})" : $id ];
	}
	return array_values($models);
    }

    //CLD: 2026-10-19T16:24:37+03:00: the provider account $account chose (a missing or unknown value: the default)
    //CLD: 2026-10-19T20:40:00+03:00: of the Assistant's settings $settings (models\AssistantSettings: provider; was the account's field)
    public static function providerOf($settings) {
	$p = (string)($settings->provider ?? '');
	//CLD: 2026-10-20T10:30:00+03:00: AssistantSettings' providers (were self::)
	return array_key_exists($p, AssistantSettings::PROVIDERS) ? $p : AssistantSettings::DEFAULT_PROVIDER;
    }

    //one request of the person: $message, after the earlier turns $history ([ { role: user | assistant, text } ]), on the designer's current $nodes (a list or a map)
    public function ask(array $history, $message, array $nodes) {
	$message = trim((string)$message);
	if ($message === '')
	    throw new \ErrorException('write what the workflow should do');
	$this->nodes = array_values(array_filter($nodes, 'is_array'));
	$this->changed = false;
	$this->toFix = [];
	$this->lastError = null;

	$this->wf->nodes = $this->nodes;
	$spec = \util\WorkflowSpec::toSpec($this->wf);
	unset($spec['id'], $spec['status']);
	$messages = self::historyMessages($history);
	$messages[] = [ 'role' => 'user', 'content' => "The workflow on the canvas now (unsaved changes included):\n```json\n"
	    . \util\JSON::encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n```\n\n" . mb_substr($message, 0, self::MAX_TURN) ];

	$tools = $this->tools();
	//CLD: 2026-10-19T16:05:12+03:00: the provider's turns (turnAnthropic / turnOpenAI: the model's turn added to $messages, as the provider wants them) and tool results
	//(addResults); was the Anthropic API only, here
	if ($this->provider === 'openai')
	    array_unshift($messages, [ 'role' => 'system', 'content' => self::systemPrompt() . "\n\n" . self::stepTypesText() ]);
	$usage = [ 'input_tokens' => 0, 'output_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0 ];
	$reply = '';
	$reminders = 0;
	for($round = 1; ; $round++) {
	    if ($round > self::MAX_ROUNDS)
		throw new \ErrorException('the assistant did not finish in ' . self::MAX_ROUNDS . ' rounds: ask for a smaller change');
	    $turn = $this->provider === 'openai' ? $this->turnOpenAI($messages, $tools) : $this->turnAnthropic($messages, $tools);
	    foreach($turn['usage'] as $k => $n)
		$usage[$k] += $n;
	    if ($turn['text'] !== '')
		$reply = $turn['text'];
	    $results = [];
	    foreach($turn['calls'] as [ $id, $name, $args ])
		$results[] = $this->runTool($id, $name, $args);
	    if ($turn['stop'] === 'cut' && !$results)
		throw new \ErrorException('the answer was cut off: ask for a smaller change');
	    if ($turn['stop'] === 'pause')
		continue;
	    //CLD: 2026-10-19T17:14:20+03:00: the model stops while the canvas it built has connection errors (small models do, and say they fixed them): reminded, with the errors
	    if (!$results && $this->changed && $reminders < self::MAX_REMINDERS && $round < self::MAX_ROUNDS) {
		$this->wf->nodes = $this->nodes;
		$errors = array_values(array_filter(\util\WorkflowSpec::problems($this->wf), fn($p) => $p['level'] === 'error'));
		if ($errors) {
		    $reminders++;
		    $messages[] = [ 'role' => 'user', 'content' => "The canvas still has these connection errors:\n" . implode("\n", array_map(fn($p) => '- ' . (isset($p['step']) ? "step `{$p['step']}`: " : '') . $p['message'], $errors))
			. "\nFix them now: call set_workflow with every step and every connection (each step except the trigger needs an incoming connection), then answer." ];
		    continue;
		}
	    }
	    if (!$results)
		break;
	    $this->addResults($messages, $results);
	}

	$problems = [];
	if ($this->changed) {
	    $this->wf->nodes = $this->nodes;
	    $problems = $this->wf->validateFlow();
	}
	//CLD: 2026-10-19T17:02:48+03:00: set_workflow refused every time: said, whatever the model's answer claims
	if (!$this->changed && $this->lastError !== null)
	    $reply = trim($reply . "\n\n(The canvas was not changed: " . $this->lastError . ')');
	return [
	    'reply' => $reply !== '' ? $reply : ($this->changed ? 'Done: the canvas is updated.' : ''),
	    'nodes' => $this->changed ? $this->nodes : null,
	    'problems' => $problems,
	    //CLD: 2026-10-19T17:02:48+03:00: the settings the person still has to fill in or correct (step title: message)
	    'to_fix' => $this->changed ? $this->toFix : [],
	    'rounds' => $round,
	    'usage' => $usage,
	];
    }

    // ------------------------------------------------------------------------------------
    //the conversation

    //the earlier turns as messages: text only, starting with the person, the roles alternating (turns of the same role joined), the last ones
    private static function historyMessages(array $history) {
	$messages = [];
	foreach(array_slice($history, -self::MAX_HISTORY) as $t) {
	    $role = is_array($t) ? (string)($t['role'] ?? '') : '';
	    $text = is_array($t) ? trim((string)($t['text'] ?? '')) : '';
	    if (!in_array($role, [ 'user', 'assistant' ], true) || $text === '')
		continue;
	    $text = mb_substr($text, 0, self::MAX_TURN);
	    if (!$messages && $role !== 'user')
		continue;
	    $last = count($messages) - 1;
	    if ($last >= 0 && $messages[$last]['role'] === $role)
		$messages[$last]['content'] .= "\n\n" . $text;
	    else
		$messages[] = [ 'role' => $role, 'content' => $text ];
	}
	//the request follows as a user turn
	if ($messages && end($messages)['role'] === 'user')
	    array_pop($messages);
	return $messages;
    }

    //the rules and the step types (the same for every request of the project: cached)
    private static function systemPrompt() {
	return <<<'TXT'
You are the Assistant of NetFlow9's workflow designer. You sit in a panel next to the person's canvas. They describe a workflow, or a change to the one on the canvas, in their own words; you build it on the canvas with the set_workflow tool.

How workflows work:
- A workflow is steps joined by connections, from one output of a step to an input of the next. Exactly one trigger (kind `trigger`) starts it; every other step must be reachable from the trigger.
- Steps with several outputs (If: true / false; Switch: one per case, then otherwise; steps with an error output) take the connection's `output` as the output's label. Merge has 2 inputs (`input` 0 and 1).
- A loop (container) runs the steps inside it (their `parent` is the loop's id) once per item of its list. Inside a loop, exactly one step has no incoming connection: each pass starts there. Connections never cross a loop's border: the loop itself is connected to the steps around it. A trigger or another loop cannot be inside a loop.
- Settings are given in `config` with the keys of the step type's settings; missing settings get their defaults. Syntax of settings: `text` = text with {{ expression }} parts; `reference` = only {{ variable }} or {{ variable.field }}; `value` = one {{ expression }}; `script` = J code (the Code step: no {{ }}; assigning a name sets that workflow variable); `variable name` = a plain name (letters, digits, _).
- Expressions see the variables the steps before have set (each step type lists the variables it provides; the names often follow a setting such as `variable`), and the project variables as {{ vars.<name> }}. No step may set a variable named vars.
- Settings whose choices are listed (e.g. credentials) take one of the listed keys. Values a step gets from the server (e.g. a webhook's token and URL) are filled in for you: leave them out.

How to work:
- The current workflow is in the person's last message. set_workflow replaces the whole canvas, so always send every step and connection that should remain. Keep the ids of steps you keep: they keep their place and their server-set values. New steps are laid out automatically; relayout = true lays out every step again (use it when you restructure the workflow).
- Give steps short, clear titles. Use the project tools (data tables, project variables, workflows, files) when the request refers to them, so the settings use real names.
- set_workflow refuses only steps it cannot build (an unknown type, a connection to a missing step, ...): fix what it reports and call it again. Steps with wrong or missing settings are applied, and those settings are returned as to_fix: correct the ones you can, the person fills in the others. It also returns connection problems: fix the errors.
- When the request is unclear in a way that changes what you would build, ask a short question instead of guessing. When something only the person can provide is missing (a credential that does not exist yet, an address, a URL), build the rest and say what to fill in.
- Nothing is saved by you: the person reviews the canvas, then saves it (the floppy icon) and publishes it themselves.
- Answer in the person's language, briefly, in plain text: what you built or changed, and what they still need to set. Do not repeat the steps as JSON.
TXT;
    }

    // ------------------------------------------------------------------------------------
    //tools

    //the tools as the API takes them: [ { name, description, input_schema } ]
    private function tools() {
	$tools = [ [
	    'name' => 'set_workflow',
	    'description' => 'Replaces the steps and connections on the canvas with these (the whole new workflow: every step and connection that should remain). '
		. 'Steps: { id (yours; keep the ids of existing steps), type (a step type), title, config { setting: value }, parent (a loop\'s id) }; '
		. 'connections: { from, to, output (an output label such as true / false / otherwise / error, or a number; default 0), input (default 0) }. '
		. 'Wrong settings are refused with the reasons (nothing changes); otherwise the canvas is updated and its connection problems are returned.',
	    'input_schema' => [ 'type' => 'object', 'properties' => \util\WorkflowSpec::schema() + [ //CLD: 2026-10-20T23:40:00+03:00: (was util\McpTools::specSchema)
		'relayout' => [ 'type' => 'boolean', 'default' => false, 'description' => 'Lay out every step again (else only the new ones)' ],
	    ], 'required' => [ 'steps', 'connections' ] ],
	] ];
	foreach(\util\MCPTools::all() as $t) { //CLD: 2026-10-20T23:40:00+03:00: (was util\McpTools::definitions)
	    if (in_array($t['name'], self::PROJECT_TOOLS, true))
		$tools[] = [ 'name' => $t['name'], 'description' => mb_substr($t['description'], 0, 1000), 'input_schema' => $t['inputSchema'] ];
	}
	return $tools;
    }

    //call $id of tool $name: [ id, text, isError ]
    //CLD: 2026-10-19T16:05:12+03:00: the result as [ id, text, isError ] (addResults makes the provider's message; was an Anthropic tool_result block); $args null: not JSON
    private function runTool($id, $name, $args) {
	try {
	    if (!is_array($args))
		throw new \ErrorException('the arguments are not a JSON object: send them again');
	    if ($name === 'set_workflow')
		$result = $this->setWorkflow($args);
	    //CLD: 2026-10-20T23:40:00+03:00: a project tool, as the user (util\MCPTools; was util\McpTools::call); none when no plugin gives it
	    elseif (in_array($name, self::PROJECT_TOOLS, true) && ($tool = \util\MCPTools::all()[$name] ?? null))
		$result = ($tool['run'])($args, $this->project, true)[0];
	    else
		throw new \ErrorException("unknown tool `{$name}`");
	    $text = \util\JSON::encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	    $isError = false;
	} catch (\ErrorException $e) {
	    $text = $e->getMessage();
	    $isError = true;
	    //CLD: 2026-10-19T17:02:48+03:00: kept for the answer when nothing gets applied
	    if ($name === 'set_workflow')
		$this->lastError = $text;
	}
	if (mb_strlen($text) > self::MAX_TOOL_RESULT)
	    $text = mb_substr($text, 0, self::MAX_TOOL_RESULT) . ' ... (cut)';
	return [ $id, $text === '' ? '(empty)' : $text, $isError ];
    }

    //CLD: 2026-10-19T16:05:12+03:00: the results of the calls of one turn added to $messages: Anthropic, one user turn of tool_result blocks; OpenAI, one `tool` message per call
    private function addResults(array &$messages, array $results) {
	if ($this->provider === 'openai') {
	    foreach($results as [ $id, $text, $isError ])
		$messages[] = [ 'role' => 'tool', 'tool_call_id' => $id, 'content' => ($isError ? 'ERROR: ' : '') . $text ];
	    return;
	}
	$messages[] = [ 'role' => 'user', 'content' => array_map(fn($r) => [ 'type' => 'tool_result', 'tool_use_id' => $r[0], 'content' => $r[1], 'is_error' => $r[2] ], $results) ];
    }

    //set_workflow: the new steps, checked as when saving; kept for the designer when valid
    //CLD: 2026-10-19T17:02:48+03:00: kept also when settings are wrong (was refused: a setting only the person can give, e.g. a data table that does not exist yet, refused the
    //whole canvas, and models then said they had built it): the wrong settings are returned (to_fix) for the model to correct, else for the person; only steps that cannot be
    //built at all (WorkflowSpec::nodesFrom: unknown type, missing step, ...) are refused
    public function setWorkflow(array $args) {
	foreach([ 'steps', 'connections' ] as $key) {
	    if (!is_array($args[$key] ?? null) || ($args[$key] && !array_is_list($args[$key])))
		throw new \ErrorException("{$key}: a list (the whole new workflow)");
	}
	$nodes = \util\WorkflowSpec::nodesFrom($args['steps'], $args['connections'], $this->nodes, !empty($args['relayout']));
	$this->wf->nodes = $nodes;
	$toFix = [];
	if (!$this->wf->validates()) {
	    foreach((array)$this->wf->errors() as $list)
		foreach((array)$list as $m)
		    $toFix[] = (string)$m;
	}
	//as normalized by the steps' config forms (cast values, defaults; also for the steps with wrong settings)
	$this->nodes = array_values($this->wf->nodes);
	$this->changed = true;
	$this->toFix = $toFix;
	$result = [ 'applied' => true, 'steps' => count(array_filter($this->nodes, fn($n) => !empty($n['isNode']))), 'problems' => \util\WorkflowSpec::problems($this->wf) ];
	if ($toFix) {
	    $result['to_fix'] = $toFix;
	    $result['note'] = 'Applied, but these settings are wrong: the workflow cannot be saved until they are fixed. Fix the ones you can with set_workflow; tell the person which ones they must fill in.';
	}
	return $result;
    }

    // ------------------------------------------------------------------------------------
    //the API

    //CLD: 2026-10-19T16:05:12+03:00: the step types for the system prompt (were written in call())
    private static function stepTypesText() {
	return "The step types (type, settings, outputs, variables):\n" . \util\JSON::encode(\util\WorkflowSpec::stepTypes(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    //one turn of Claude, added to $messages: { text, calls: [ [ id, name, args ] ], stop: tool_use | end | pause | cut, usage }
    //CLD: 2026-10-19T16:05:12+03:00: was call() (the answer itself) and the reading of it in ask()
    private function turnAnthropic(array &$messages, array $tools) {
	$body = [
	    //CLD: 2026-10-19T15:48:10+03:00: the account's model and effort (were constants)
	    'model' => $this->model,
	    'max_tokens' => self::MAX_TOKENS,
	    'system' => [
		[ 'type' => 'text', 'text' => self::systemPrompt() ],
		[ 'type' => 'text', 'text' => self::stepTypesText(), 'cache_control' => [ 'type' => 'ephemeral' ] ],
	    ],
	    'messages' => $messages,
	    'tools' => $tools,
	    'output_config' => [ 'effort' => $this->effort ],
	    //the conversation so far (rounds of this request) is cached too
	    'cache_control' => [ 'type' => 'ephemeral' ],
	];
	$headers = $this->cred->httpAuth()['headers'];
	//a declined request is retried on the model Anthropic recommends (as NodeAppClaude)
	if (preg_match(self::FALLBACK_MODELS, $this->model)) {
	    $body['fallbacks'] = 'default';
	    $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
	}
	$r = $this->request('/v1/messages', $headers, $body);

	$stop = (string)($r->stop_reason ?? '');
	if ($stop === 'refusal') {
	    $d = $r->stop_details ?? null;
	    throw new \ErrorException('Claude declined the request' . (!empty($d->explanation) ? ': ' . $d->explanation : ''));
	}
	$content = is_array($r->content ?? null) ? $r->content : [];
	//the whole turn goes back as it came (thinking blocks included)
	$messages[] = [ 'role' => 'assistant', 'content' => $content ];
	$text = [];
	$calls = [];
	foreach($content as $block) {
	    $type = (string)($block->type ?? '');
	    if ($type === 'text')
		$text[] = (string)$block->text;
	    elseif ($type === 'tool_use')
		$calls[] = [ (string)$block->id, (string)$block->name, is_object($block->input ?? null) ? json_decode(\util\JSON::encode($block->input), true) ?: [] : [] ];
	}
	$usage = [];
	foreach([ 'input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens' ] as $k)
	    $usage[$k] = (int)($r->usage->{$k} ?? 0);
	return [ 'text' => trim(implode("\n", $text)), 'calls' => $calls, 'usage' => $usage,
	    'stop' => [ 'max_tokens' => 'cut', 'pause_turn' => 'pause', 'tool_use' => 'tool_use' ][$stop] ?? 'end' ];
    }

    //CLD: 2026-10-19T16:05:12+03:00: one turn of an OpenAI-compatible model (as turnAnthropic). No max_tokens: the server's own limit (local models have small contexts); reasoning
    //between <think> tags is left out of the answer (as NodeAppOpenAI)
    private function turnOpenAI(array &$messages, array $tools) {
	if ($this->model === '')
	    $this->model = $this->serverModel();
	$body = [ 'model' => $this->model, 'messages' => $messages, 'tools' => [] ];
	foreach($tools as $t)
	    $body['tools'][] = [ 'type' => 'function', 'function' => [ 'name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['input_schema'] ] ];
	$r = json_decode(\util\JSON::encode($this->request('/chat/completions', $this->cred->httpAuth()['headers'], $body)), true);

	$choice = is_array($r['choices'][0] ?? null) ? $r['choices'][0] : null;
	if (!$choice || !is_array($choice['message'] ?? null))
	    throw new \ErrorException('the server answered without a message');
	$msg = $choice['message'];
	//the assistant turn back into the conversation, its tool calls with it
	$turn = [ 'role' => 'assistant', 'content' => isset($msg['content']) ? (string)$msg['content'] : null ];
	$calls = [];
	if (!empty($msg['tool_calls']) && is_array($msg['tool_calls'])) {
	    $turn['tool_calls'] = $msg['tool_calls'];
	    foreach($msg['tool_calls'] as $i => $tc) {
		$args = $tc['function']['arguments'] ?? '';
		$args = is_array($args) ? $args : (trim((string)$args) === '' ? [] : json_decode((string)$args, true));
		$calls[] = [ (string)($tc['id'] ?? 'call_' . $i), (string)($tc['function']['name'] ?? ''), is_array($args) ? $args : null ];
	    }
	}
	$messages[] = $turn;
	$finish = (string)($choice['finish_reason'] ?? '');
	return [ 'text' => trim(preg_replace('~<think>.*?</think>~s', '', (string)($msg['content'] ?? ''))), 'calls' => $calls,
	    'usage' => [ 'input_tokens' => (int)($r['usage']['prompt_tokens'] ?? 0), 'output_tokens' => (int)($r['usage']['completion_tokens'] ?? 0) ],
	    'stop' => $calls ? 'tool_use' : ($finish === 'length' ? 'cut' : 'end') ];
    }

    //CLD: 2026-10-19T16:05:12+03:00: no model in the account settings: the first one the server lists (GET <base URL>/models)
    private function serverModel() {
	$r = json_decode(\util\JSON::encode($this->request('/models', $this->cred->httpAuth()['headers'], null)), true);
	$id = is_array($r['data'][0] ?? null) ? trim((string)($r['data'][0]['id'] ?? '')) : '';
	if ($id === '')
	    //CLD: 2026-10-19T20:40:00+03:00: Assistant settings (was Account settings: the plugin's own page)
	    throw new \ErrorException('the server lists no model: choose one in Assistant settings (Settings menu)');
	return $id;
    }

    //CLD: 2026-10-19T16:05:12+03:00: a call to the credential's API ($body null: GET): the decoded answer (objects)
    private function request($path, array $headers, $body) {
	if ($this->transport !== null)
	    return ($this->transport)($headers, $body, $path);
	//CLD: 2026-10-20T10:00:00+03:00: http() (static: also for models(); was post())
	return self::http(rtrim((string)$this->cred->baseUrl, '/') . $path, $headers, $body, $this->provider === 'openai' ? 'The server' : 'Anthropic');
    }

    //CLD: 2026-10-19T16:52:30+03:00: ?array $body (null: GET, the server's models; was array: the GET of serverModel() failed with a TypeError)
    //CLD: 2026-10-20T10:00:00+03:00: static, + $name: who answers, in messages (was post(), the instance's provider), + $timeout (s)
    private static function http($url, array $headers, ?array $body, $name, $timeout = self::TIMEOUT) {
	$lines = [ 'Content-Type: application/json', 'Accept: application/json', 'User-Agent: QSM-Workflow' ];
	foreach($headers as $name => $value)
	    $lines[] = $name . ': ' . $value;
	$raw = '';
	$tooLarge = false;
	$ch = curl_init($url);
	//CLD: 2026-10-19T16:05:12+03:00: $body null: GET (the server's models)
	if ($body !== null)
	    curl_setopt_array($ch, [ CURLOPT_POST => true, CURLOPT_POSTFIELDS => \util\JSON::encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ]);
	curl_setopt_array($ch, [
	    CURLOPT_HTTPHEADER => $lines,
	    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
	    CURLOPT_CONNECTTIMEOUT => 10,
	    CURLOPT_TIMEOUT => $timeout,
	    CURLOPT_WRITEFUNCTION => function($ch, $chunk) use (&$raw, &$tooLarge) {
		if (strlen($raw) + strlen($chunk) > self::MAX_RESPONSE) {
		    $tooLarge = true;
		    return 0;
		}
		$raw .= $chunk;
		return strlen($chunk);
	    },
	]);
	$ok = curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$error = $ok === false && !$tooLarge ? curl_error($ch) : '';
	curl_close($ch);
	if ($tooLarge)
	    throw new \ErrorException('the answer is too large');
	//CLD: 2026-10-19T16:05:12+03:00: the provider's name in messages; the OpenAI-compatible servers' error shapes ({ error: { message } }, { error: text }, { message })
	if ($error !== '')
	    throw new \ErrorException("no answer from " . preg_replace('/[?#].*$/', '', $url) . ": {$error}");
	$data = json_decode($raw);
	if ($status >= 400) {
	    $m = is_object($data) ? ($data->error->message ?? (is_string($data->error ?? null) ? $data->error : ($data->message ?? null))) : null;
	    throw new \ErrorException("{$name} answered HTTP status {$status}" . (is_string($m) && $m !== '' ? ': ' . mb_substr($m, 0, 500) : ''));
	}
	if (!is_object($data))
	    //CLD: 2026-10-20T10:00:00+03:00: the Server URL of the settings (was the credential's base URL)
	    throw new \ErrorException("{$name} did not answer JSON (is the Server URL in Assistant settings right?)");
	return $data;
    }
}
