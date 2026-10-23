<?php
/*
//CLD: 2026-10-18T12:30:00+03:00: NodeAppOpenAI (was NodeAppLemonade)
what the AI steps share (Claude - NodeAppClaude, OpenAI - NodeAppOpenAI; not a step itself: not registered).
//CLD: 2026-10-22T01:36:46+03:00: + Lemonade AI - NodeAppLemonade (the OpenAI requests), Ollama - NodeAppOllama
//CLD: 2026-10-22T12:00:00+03:00: + Gemini - NodeAppGemini
    Settings:
	a credential of the project (the step's provider: static::CAPABILITY),
	the model,
	a system prompt and the prompt ({{ }} expressions),
	the answer as text or JSON (an optional JSON schema),
	tools the model may use - this project's tools (util\McpTools, run directly, without permission checks: the workflow's own step) and the tools of MCP server credentials(util\McpClient) -,
	//CLD: 2026-10-20T23:40:00+03:00: this project's tools come from the plugins (util\MCPTools; the SysMCPTools plugin gives its MCP server's tools), none without them
	//CLD: 2026-10-22T22:00:00+03:00: + the installation's tool servers (`servers`: MCP servers the plugins run on this machine, e.g. a web browser - the LocalMCP plugin), those the
	//administrator enabled for the account (hook ai:mcp-servers, localServers()); one session each per run, closed after it
	rounds, max tokens, timeout, the variable, on error (fail | the `error` output).
	//CLD: 2026-10-17T21:00:54+03:00: + images: project image files sent with the prompt; images in tool results (read_image, MCP servers' image content) go to the model as images too
	The agent loop: the model is called, the tools it asks for are run and their results sent back, until it answers (at most `maxRounds` calls).
	    The provider classes build the requests: startConversation(), callModel(), addToolResults().
	The variable (default `ai`): { text, json, model, stop_reason, rounds, tool_calls: [ { tool, arguments, error } ], usage: { input_tokens, output_tokens } }; on error { error }
	//CLD: 2026-10-23T01:10:00+03:00: + html: the text as safe HTML (util\Markdown: models answer in Markdown), e.g. for a web page or an HTTP request
	//CLD: 2026-10-23T02:30:00+03:00: in an email {{ ai.html }} shows it formatted (util\EmailHtml; was escaped: {{ markdown(ai.text) }} only)
    //CLD: 2026-10-23T10:00:00+03:00: Credential `system` (SYSTEM): the installation's System AI instead of a credential of the project, when it is of the step's provider (a plugin gives
    //it: hook ai:system-provider, systemProvider(); the SystemAI plugin). A run reserves the account's credits, counts each call's tokens in credits, stops before a call once the
    //reserved ones are used up, and is charged what it used (its session: start, add, exhausted, finish)
*/

namespace models\node;

abstract class NodeAppAi extends \models\WfNode {
    public static $type = 'app';
    public static $color = 'var(--wf-color-app)';
    public static $inputs = 1;
    public static $outputs = 1;
    public static $outPorts = [ 'field' => 'onError', 'values' => [ 'output' => [ 'success', 'error' ] ] ];

    //the credential capability of the provider (models\Credential::$provides) and its name in messages
    const CAPABILITY = '';
    const PROVIDER = '';

    const ON_ERROR = [ 'fail' => 'Fail the step', 'output' => 'Continue on the `error` output' ];
    //CLD: 2026-10-23T10:00:00+03:00: the Credential value of the System AI
    const SYSTEM = 'system';
    const OUTPUT = [ 'text' => 'Text', 'json' => 'JSON' ];

    const MAX_ROUNDS = 25;
    const MAX_TOKENS = 64000;
    const MAX_TIMEOUT = 600;
    //a tool result longer than this is cut before it is sent to the model (characters)
    const MAX_TOOL_RESULT = 50000;
    //the largest model answer read (bytes)
    const MAX_RESPONSE = 10 * 1024 * 1024;

    //the read-only QSM tools: ticked for a new step
    //CLD: 2026-10-17T21:00:54+03:00: + read_image
    const DEFAULT_TOOLS = [ 'list_tables', 'query_table', 'list_workflows', 'get_execution', 'list_files', 'read_file', 'read_image', 'list_variables' ];
    //CLD: 2026-10-17T21:00:54+03:00: most images sent with the prompt
    const MAX_IMAGES = 20;
    //CLD: 2026-10-20T23:40:00+03:00: the largest image sent to a model (bytes; was util\McpTools::MAX_IMAGE)
    const MAX_IMAGE = 5242880;
    //the image types the models read
    const IMAGE_TYPES = [ 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ];

    //CLD: 2026-10-20T23:40:00+03:00: the project's own tools the model may use, given by the plugins (util\MCPTools; the SysMCPTools plugin gives its MCP server's tools)
    public static function projectTools() {
	return \util\MCPTools::all();
    }

    //CLD: 2026-10-22T22:00:00+03:00: new: the installation's tool servers account $aid may use, given by the plugins (hook `ai:mcp-servers`: its arguments are an \ArrayObject the
    //plugin adds them to, and $aid): [ id => [ id, label, description, open: fn(int $timeout) => an MCP client (initialize, listTools, callTool, close: as util\McpClient),
    //tools: the tool names the model may use (null: all the server lists) ] ]. $aid null: none (outside an account)
    public static function localServers($aid) {
	if (empty($aid))
	    return [];
	$servers = new \ArrayObject();
	\V::app()->hooks->applyHook('ai:mcp-servers', [ $servers, (string)$aid ]);
	return $servers->getArrayCopy();
    }

    //CLD: 2026-10-23T10:00:00+03:00: new: the installation's System AI for account $aid, when it is of this step's provider (static::CAPABILITY), given by a plugin (hook
    //`ai:system-provider`: its arguments are an \ArrayObject the plugin sets `provider` of, and $aid): [ provider, model (the System's), label, credential: fn() => the credential
    //(baseUrl, httpAuth, ...: as the provider's), start: fn(string $model, array $info) => the run's session (add([ input, output ]), exhausted(), reserved(), finish() =>
    //[ credits charged, left ]; \ErrorException when the model is not priced or no credits are left) ]; null: none, another provider, or no account
    public static function systemProvider($aid) {
	if (empty($aid) || static::CAPABILITY === '')
	    return null;
	$box = new \ArrayObject();
	\V::app()->hooks->applyHook('ai:system-provider', [ $box, (string)$aid ]);
	$p = $box['provider'] ?? null;
	return is_array($p) && ($p['provider'] ?? '') === static::CAPABILITY ? $p : null;
    }

    //CLD: 2026-10-23T10:00:00+03:00: new: the designer's account (null outside the designer: a web request of a person with a project), as the choices of the credentials
    protected static function designerAccount() {
	if (\V::app()->config('app.mode') != 'web' || \V::app()->user->isGuest || empty(\V::app()->user->crtProject))
	    return null;
	return \V::app()->config('rt.account')->_id ?? null;
    }

    //CLD: 2026-10-17T21:00:54+03:00: image file $f as [ mimeType, data (base64) ]; \ErrorException when it is not an image the models read (File::$imageMimeTypes) or is too large
    //CLD: 2026-10-20T23:40:00+03:00: here (was util\McpTools::fileImage: the AI steps send images without the SysMCPTools plugin)
    public static function fileImage($f) {
	$mime = $f->imageMimeType();
	if ($mime === null)
	    throw new \ErrorException("`{$f->filename}` is not an image (PNG, JPEG, GIF or WebP)");
	if ((int)$f->length > self::MAX_IMAGE)
	    throw new \ErrorException("`{$f->filename}` is too large to send (" . (int)$f->length . ' bytes, at most ' . self::MAX_IMAGE . ')');
	return [ $mime, base64_encode($f->getContents()) ];
    }

    //the provider's own settings, placed after the model (override)
    protected static function providerSchema() {
	return [];
    }

    //the default model (override)
    protected static function defaultModel() {
	return '';
    }

    public static function configSchema() {
	$credentials = [];
	//CLD: 2026-10-23T10:00:00+03:00: the System AI first, when it is of the step's provider
	$system = static::systemProvider(static::designerAccount());
	if ($system)
	    $credentials[self::SYSTEM] = $system['label'];
	foreach(static::choices(static::CAPABILITY) as $c)
	    $credentials[$c['value']] = $c['label'];
	//CLD: 2026-10-14T14:27:53+03:00: [ value => label ] (the choices of `select` fields; were smartform lists for `ui`)
	$mcp = [];
	foreach(static::choices('mcp') as $c)
	    $mcp[$c['value']] = $c['label'];
	$tools = [];
	foreach(static::projectTools() as $t)
	    $tools[$t['name']] = $t['title'] . ' (' . $t['name'] . ')';
	//CLD: 2026-10-22T22:00:00+03:00: the installation's tool servers of the account (designer)
	$servers = [];
	foreach(static::localServers(\V::app()->config('app.mode') == 'web' ? (\V::app()->config('rt.account')->_id ?? null) : null) as $id => $srv)
	    $servers[$id] = $srv['label'];

	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Credential',
		'choices' => $credentials,
		'help' => static::PROVIDER . ' credential of the project (menu: Credentials)' . ($system ? ', or System AI: this installation\'s, paid with the account\'s credits (Settings menu: AI credits)' : ''), //CLD: 2026-10-23T10:00:00+03:00: + System AI
		//CLD: 2026-10-14T14:18:23+03:00: required by the model (was only in the browser: `required` in ui); validates() checks it is a credential of the project
		'required' => true,
	    ],
	    //CLD: 2026-10-22T01:58:24+03:00: in the form, a select of the models the chosen credential recorded (its Test: Credential::recordModels), a name not listed can be typed; on the server
	    //it stays text (any model name: workflows made by the MCP tools / the Assistant, a list not refreshed yet)
	    [
		'key' => 'model',
		'type' => 'text',
		'label' => 'Model',
		'required' => true,
		'default' => static::defaultModel(),
		'help' => 'The models of the credential (Test on the credential records them); or type a model name',
		'ui' => [ 'type' => 'select', 'allowCustom' => true, 'placeholder' => 'Choose a model...', 'choicesBy' => [ 'key' => 'credential', 'choices' => (object)static::modelChoices(),
		    'empty' => 'No models recorded: Test the credential, or type a name' ] ],
	    ],
	], static::providerSchema(), [
	    [
		'key' => 'system',
		'type' => 'textarea',
		'label' => 'System prompt',
		'help' => 'Who the model is and how it should work (optional); {{ ... }} for expressions',
		'ui' => [ 'rows' => 3, 'expression' => true ],
	    ],
	    [
		'key' => 'prompt',
		'type' => 'textarea',
		'label' => 'Prompt',
		'required' => true,
		//CLD: 2026-10-13T08:15:00+03:00: the example after the Email trigger's message variable (mail.text; was mail_text)
		'help' => 'The request; {{ ... }} for expressions, e.g. {{ mail.text }}',
		'ui' => [ 'rows' => 6, 'expression' => true ],
	    ],
	    //CLD: 2026-10-17T21:00:54+03:00: image files sent with the prompt
	    [
		'key' => 'images',
		'type' => 'text',
		'label' => 'Images',
		'help' => 'Optional: image files of the project the model looks at with the prompt (PNG, JPEG, GIF, WebP; at most ' . self::MAX_IMAGES . '), e.g. {{ file.id }}, a list such as {{ mail.attachment_files }}, or file ids separated by commas',
		'ui' => [ 'expression' => true ],
	    ],
	    [
		'key' => 'output',
		'type' => 'select',
		'label' => 'Answer',
		'required' => true,
		'default' => 'text',
		'choices' => self::OUTPUT,
		'help' => 'JSON: the answer is parsed (variable .json); a schema makes its shape precise',
		//CLD: 2026-10-18T14:40:00+03:00: removed the inList rule: the select field checks its choices (sys\fields\Field_select::bindTo)
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Result variable',
		'required' => true,
		'default' => 'ai',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		//CLD: 2026-10-14T14:27:53+03:00: pattern from the validate rule (util\WidgetsUtil; was repeated here)
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	    [
		'key' => 'jsonSchema',
		'type' => 'textarea',
		'label' => 'JSON schema',
		'help' => 'Optional: the JSON schema the answer must follow, e.g. {"type": "object", "properties": {"category": {"type": "string"}}, "required": ["category"], "additionalProperties": false}',
		//CLD: 2026-10-14T14:08:12+03:00: condition made by util\FormSchema::showIf (was JS written here)
		//CLD: 2026-10-14T20:17:14+03:00: a condition array (app.smartform evaluates it; was JS made by util\SmartForm::showIf)
		'ui' => [ 'rows' => 4, 'showIf' => [ 'key' => 'output', 'operator' => 'eq', 'value' => 'json' ] ],
	    ],
	    [
		'key' => 'tools',
		//CLD: 2026-10-14T14:27:53+03:00: a `select` field with `multiple` and its choices (was an `array` field shown as a select through `ui`)
		'type' => 'select',
		'multiple' => true,
		'choices' => $tools,
		'label' => 'Use these tools', //CLD: 2026-10-22T12:30:00+03:00: was QSM tools
		'default' => array_values(array_intersect(self::DEFAULT_TOOLS, array_keys($tools))), //CLD: 2026-10-20T23:40:00+03:00: those there are (was DEFAULT_TOOLS)
		'help' => 'What the model may do in this project (run by the workflow, without the users\' permission checks)',
		'ui' => [ 'placeholder' => 'None' ],
	    ],
	    [
		'key' => 'mcp',
		//CLD: 2026-10-14T14:27:53+03:00: a `select` field with `multiple` and its choices (was an `array` field shown as a select through `ui`)
		'type' => 'select',
		'multiple' => true,
		'choices' => $mcp,
		'label' => 'MCP servers',
		'default' => [],
		'help' => 'MCP server credentials of the project whose tools the model may use',
		'ui' => [ 'placeholder' => 'None' ],
	    ],
	    //CLD: 2026-10-22T22:00:00+03:00: new: the installation's tool servers (localServers)
	    [
		'key' => 'servers',
		'type' => 'select',
		'multiple' => true,
		'choices' => $servers,
		'label' => 'Tool servers',
		'default' => [],
		'help' => 'Tools this installation runs for the account (e.g. a web browser); each run gets its own session, closed after it',
		'ui' => [ 'placeholder' => $servers ? 'None' : 'None enabled for this account' ],
	    ],
	    [
		'key' => 'maxRounds',
		'type' => 'number',
		'label' => 'Rounds',
		'required' => true,
		'default' => 10,
		'help' => 'Most calls to the model (each tool use is one more)',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_ROUNDS, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_ROUNDS ] ],
		//CLD: 2026-10-14T14:27:53+03:00: min / max from the validate rule (util\WidgetsUtil; was repeated here)
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'maxTokens',
		'type' => 'number',
		'label' => 'Max tokens',
		'required' => true,
		'default' => 16000,
		'help' => 'Longest answer per call',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_TOKENS, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_TOKENS ] ],
		//CLD: 2026-10-14T14:27:53+03:00: min / max from the validate rule (util\WidgetsUtil; was repeated here)
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'timeout',
		'type' => 'number',
		'label' => 'Timeout (s)',
		'required' => true,
		'default' => 300,
		'help' => 'Per call to the model',
		'validate' => [ [ 'inRange', 'lower' => 5, 'upper' => self::MAX_TIMEOUT, 'skipEmpty' => true, 'message' => 'from 5 to ' . self::MAX_TIMEOUT ] ],
		//CLD: 2026-10-14T14:27:53+03:00: min / max from the validate rule (util\WidgetsUtil; was repeated here)
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'onError',
		'type' => 'select',
		'label' => 'On error',
		'required' => true,
		'default' => 'fail',
		'choices' => self::ON_ERROR,
		'help' => 'The model could not be reached, declined, was cut off, or a JSON answer did not parse',
		//CLD: 2026-10-18T14:40:00+03:00: removed the inList rule: the select field checks its choices (sys\fields\Field_select::bindTo)
	    ],
	]);
    }

    //CLD: 2026-10-22T01:58:24+03:00: the models each credential of the current project (providing static::CAPABILITY) recorded: [ credential id => [ model ] ], for the designer
    //CLD: 2026-10-23T10:00:00+03:00: + the System AI's model (SYSTEM)
    protected static function modelChoices() {
	if (\V::app()->config('app.mode') != 'web' || \V::app()->user->isGuest || empty(\V::app()->user->crtProject))
	    return [];
	$map = [];
	$system = static::systemProvider(static::designerAccount());
	if ($system)
	    $map[self::SYSTEM] = [ (string)$system['model'] ];
	$variants = \models\Credential::variantsProviding(static::CAPABILITY);
	if (!$variants)
	    return $map;
	foreach(\V::app()->om->find(\models\Credential::FAMILY, [ 'aid' => \V::app()->config('rt.account')->_id ?? null, 'project' => \V::app()->om->to_oid(\V::app()->user->crtProject),
	    '__variant' => [ '$in' => $variants ] ]) as $c)
	    $map[(string)$c->_id] = array_values(array_map('strval', (array)($c->models ?? [])));
	return $map;
    }

    //credentials of the current project providing $capability, for the designer (as the HTTP Request step)
    protected static function choices($capability) {
	if (\V::app()->config('app.mode') != 'web' || \V::app()->user->isGuest || empty(\V::app()->user->crtProject))
	    return [];
	return \models\Credential::choicesFor($capability, \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject);
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'ai') : 'ai';
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'object',
	    'info' => 'the answer: { text, html, json, model, stop_reason, rounds, tool_calls, usage }; on error { error }',
	    //CLD: 2026-10-13T12:34:29+03:00: the fields and their types (suggested after `ai.`; was a list of names)
	    'fields' => [
		'text' => [ 'type' => 'string', 'info' => 'the answer' ],
		//CLD: 2026-10-23T01:10:00+03:00: the answer's Markdown as HTML
		'html' => [ 'type' => 'string', 'info' => 'the answer as safe HTML (its Markdown converted); in an email {{ ai.html }} shows it formatted' ], 'json' => [ 'type' => 'any', 'info' => 'the answer as JSON (answer: JSON)' ], 'model' => [ 'type' => 'string' ], 'stop_reason' => [ 'type' => 'string' ],
		'rounds' => [ 'type' => 'number' ], 'tool_calls' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => [ 'tool' => [ 'type' => 'string' ], 'arguments' => [ 'type' => 'object' ], 'error' => [ 'type' => 'boolean' ] ] ] ],
		'usage' => [ 'type' => 'object', 'fields' => [ 'input_tokens' => [ 'type' => 'number' ], 'output_tokens' => [ 'type' => 'number' ] ] ], 'error' => [ 'type' => 'string', 'info' => 'on error only' ],
	    ] ] ] : [];
    }

    //the credential and the MCP servers: of the workflow's project; the tools: known; the schema: a JSON object
    public function validates($options = []) {
	$valid = parent::validates($options);
	$cred = (string)$this->cfg('credential', '');
	//CLD: 2026-10-14T14:18:23+03:00: an empty one is reported by the field's `required` (was reported here too)
	//CLD: 2026-10-23T10:00:00+03:00: System AI: when the installation's is of this provider
	if ($cred === self::SYSTEM) {
	    if (!static::systemProvider($this->workflow->aid ?? null)) {
		$this->errors('config', 'Credential: this installation\'s System AI does not serve ' . static::PROVIDER . ' steps: choose a credential of this project');
		$valid = false;
	    }
	} elseif ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, static::CAPABILITY)))) {
	    $this->errors('config', 'Credential: choose ' . static::PROVIDER . ' credential of this project');
	    $valid = false;
	}
	foreach((array)$this->cfg('mcp', []) as $id) {
	    if (!$this->workflow || empty(\models\Credential::findFor($this->workflow, (string)$id, 'mcp'))) {
		$this->errors('config', 'MCP servers: one of them is not an MCP server credential of this project');
		$valid = false;
		break;
	    }
	}
	//CLD: 2026-10-22T22:00:00+03:00: the tool servers: enabled for the workflow's account
	$enabled = static::localServers($this->workflow->aid ?? null);
	foreach((array)$this->cfg('servers', []) as $id) {
	    if (!isset($enabled[(string)$id])) {
		$this->errors('config', "Tool servers: `{$id}` is not enabled for this account");
		$valid = false;
		break;
	    }
	}
	$known = array_keys(static::projectTools());
	foreach((array)$this->cfg('tools', []) as $name) {
	    if (!in_array($name, $known, true)) {
		//CLD: 2026-10-22T12:30:00+03:00: the field's label (was QSM tools)
		$this->errors('config', "Use these tools: unknown tool `{$name}`" . ($known ? '' : ' (no plugin gives tools: is SysMCPTools enabled?)'));
		$valid = false;
	    }
	}
	if ($this->cfg('output') === 'json' && trim((string)$this->cfg('jsonSchema', '')) !== '' && !is_object(json_decode((string)$this->cfg('jsonSchema'))))
	{
	    $this->errors('config', 'JSON schema: not a JSON object');
	    $valid = false;
	}
	return $valid;
    }

    // ------------------------------------------------------------------------------------
    //the provider (implemented by the step classes)

    //a new conversation: the provider's state (messages, ...)
    //CLD: 2026-10-17T21:00:54+03:00: + $images: sent with the prompt, [ [ mimeType, data (base64) ] ]
    abstract protected function startConversation($cred, $system, $prompt, $schema, array $images = []);

    //one call: [ text, calls: [ [ id, name, arguments (array) ] ], stop_reason, model, usage: [ input, output ], continue: bool (call again without tool results) ];
    //the model's turn is added to $state; \ErrorException when it fails
    abstract protected function callModel($cred, array &$state, array $tools, $schema);

    //the results of the calls: [ [ id, text, isError ] ], added to $state
    //CLD: 2026-10-17T21:00:54+03:00: [ [ id, text, isError, images: [ [ mimeType, data ] ] ] ]
    abstract protected function addToolResults(array &$state, array $results);

    // ------------------------------------------------------------------------------------
    //running

    public function execute($edgeId = null) {
	$name = (string)$this->cfg('variable', 'ai');
	$label = static::$label;
	$clients = [];
	try {
	    $result = $this->run($clients);
	} catch (\Exception $e) {
	    foreach($clients as $c)
		$this->closeClient($c);
	    $message = $e->getMessage();
	    if (\util\Validator::isIdentifier($name))
		$this->exec->setVariable($name, [ 'error' => $message ]);
	    $this->exec->log($this->wfid, "{$label}: {$message}");
	    if ($this->cfg('onError', 'fail') === 'output')
		return [ 1 ];
	    throw new \ErrorException("{$label}: {$message}");
	}
	foreach($clients as $c)
	    $this->closeClient($c);
	if (\util\Validator::isIdentifier($name))
	    $this->exec->setVariable($name, $result);
	return [ 0 ];
    }

    //the agent loop; $clients gets the MCP clients opened (closed by execute())
    protected function run(array &$clients) {
	//CLD: 2026-10-23T10:00:00+03:00: System AI: its credential (the session, with the credits: below)
	$system = null;
	if ((string)$this->cfg('credential', '') === self::SYSTEM) {
	    $system = static::systemProvider($this->exec->aid ?? null);
	    if ($system === null)
		throw new \ErrorException('this installation\'s System AI is no longer available for ' . static::PROVIDER . ' steps: choose a credential of the project');
	    $cred = ($system['credential'])();
	} else {
	    $cred = \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), static::CAPABILITY);
	}
	if (empty($cred))
	    throw new \ErrorException('the credential is no longer available');
	$project = \V::app()->om->findOne('Project', [ '_id' => $this->exec->project, 'aid' => $this->exec->aid ]);

	$systemPrompt = trim(\util\Expression::toText($this->expr('system', ''))); //CLD: 2026-10-23T10:00:00+03:00: $systemPrompt (was $system: now the System AI)
	$prompt = trim(\util\Expression::toText($this->expr('prompt', '')));
	if ($prompt === '')
	    throw new \ErrorException('the prompt is empty');
	$json = $this->cfg('output', 'text') === 'json';
	$schema = $json && trim((string)$this->cfg('jsonSchema', '')) !== '' ? json_decode((string)$this->cfg('jsonSchema')) : null;
	//CLD: 2026-10-17T21:00:54+03:00: the images sent with the prompt
	$images = $this->promptImages($project);

	//CLD: 2026-10-22T22:00:00+03:00: collectTools() (was here)
	$tools = $this->collectTools($clients, $project);

	$state = $this->startConversation($cred, $systemPrompt, $prompt, $json ? ($schema ?: true) : null, $images);
	//CLD: 2026-10-23T10:00:00+03:00: System AI: the credits reserved for the run (refused: not priced, none left); charged what it used when it ends, also on failure
	$session = $system ? ($system['start'])(trim((string)$this->cfg('model', '')), [ 'wfid' => (string)($this->exec->wf ?? ''), 'exid' => (string)($this->exec->_id ?? '') ]) : null;
	try {
	    return $this->loop($cred, $state, $tools, $json, $schema, $session);
	} finally {
	    if ($session) {
		[ $charged, $left ] = $session->finish();
		$this->exec->log($this->wfid, static::$label . ": System AI, {$charged} credit(s)" . ($left !== null ? ", {$left} left" : ''));
	    }
	}
    }

    //CLD: 2026-10-23T10:00:00+03:00: new (was in run()): the calls to the model and the tool calls, until it answers => the variable's value. $session: the System AI's (null: a credential
    //of the project): each call's tokens counted, no call once its credits are used up
    protected function loop($cred, array $state, array $tools, $json, $schema, $session) {
	$maxRounds = max(1, min(self::MAX_ROUNDS, (int)$this->cfg('maxRounds', 10)));
	$usage = [ 'input_tokens' => 0, 'output_tokens' => 0 ];
	$calls = [];
	$answer = null;
	for($round = 1; $round <= $maxRounds; $round++) {
	    if ($session && $round > 1 && $session->exhausted())
		throw new \ErrorException('the System AI credits reserved for this step (' . $session->reserved() . ') are used up before the model finished');
	    $answer = $this->callModel($cred, $state, $tools, $json ? ($schema ?: true) : null);
	    $usage['input_tokens'] += (int)$answer['usage'][0];
	    $usage['output_tokens'] += (int)$answer['usage'][1];
	    if ($session)
		$session->add($answer['usage']);
	    if (!empty($answer['continue']))
		continue;
	    if (!$answer['calls'])
		break;
	    if ($round === $maxRounds)
		throw new \ErrorException("the model still wanted to use tools after {$maxRounds} round(s) (raise Rounds)");
	    $results = [];
	    foreach($answer['calls'] as [ $id, $tool, $args ]) {
		//CLD: 2026-10-17T21:00:54+03:00: + the images of the result
		$toolImages = [];
		if (!isset($tools[$tool])) {
		    [ $text, $isError ] = [ "unknown tool `{$tool}`", true ];
		} elseif (!is_array($args)) {
		    [ $text, $isError ] = [ 'the arguments are not a JSON object', true ];
		} else {
		    try {
			[ $text, $isError, $toolImages ] = ($tools[$tool]['run'])($args);
		    } catch (\Exception $e) {
			[ $text, $isError ] = [ 'the tool failed: ' . $e->getMessage(), true ];
		    }
		}
		if (mb_strlen($text) > self::MAX_TOOL_RESULT)
		    $text = mb_substr($text, 0, self::MAX_TOOL_RESULT) . "\n[cut: the result was longer than " . self::MAX_TOOL_RESULT . ' characters]';
		$this->exec->log($this->wfid, static::$label . ": tool {$tool}" . ($isError ? ' -> error: ' . mb_substr($text, 0, 200) : '') . ($toolImages ? ', ' . count($toolImages) . ' image(s)' : ''));
		$calls[] = [ 'tool' => $tool, 'arguments' => $args, 'error' => $isError ];
		$results[] = [ $id, $text, $isError, $toolImages ];
	    }
	    $this->addToolResults($state, $results);
	}
	if (!empty($answer['continue']))
	    throw new \ErrorException("the model did not finish in {$maxRounds} rounds (raise Rounds)");

	$text = trim((string)$answer['text']);
	//CLD: 2026-10-23T01:10:00+03:00: + html (util\Markdown)
	$result = [ 'text' => $text, 'html' => \util\Markdown::toHtml($text), 'json' => null, 'model' => $answer['model'], 'stop_reason' => $answer['stop_reason'], 'rounds' => min($round, $maxRounds),
	    'tool_calls' => $calls, 'usage' => $usage ];
	if ($json) {
	    $clean = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
	    $parsed = json_decode($clean, true);
	    if ($parsed === null && strtolower(trim($clean)) !== 'null')
		throw new \ErrorException('the answer is not JSON: ' . mb_substr($text, 0, 200));
	    $result['json'] = $parsed;
	}
	$this->exec->log($this->wfid, static::$label . ": {$answer['model']}, {$result['rounds']} round(s), " . count($calls) . " tool call(s), {$usage['input_tokens']} + {$usage['output_tokens']} tokens");
	return $result;
    }

    //CLD: 2026-10-22T22:00:00+03:00: new (was in run()): the tools the model may use: [ name => [ name, description, schema (object), run: fn(args) => [ text, isError, images ] ] ];
    //$clients gets the MCP sessions opened (closed by execute()). The project's tools, the MCP server credentials' tools (mcp<n>_<tool>), the installation's tool servers' tools
    //(<server>_<tool>: only those the server allows)
    protected function collectTools(array &$clients, $project) {
	//the tools: [ name => [ name, description, schema (object), run: fn(args) => [ text, isError ] ] ]
	//CLD: 2026-10-17T21:00:54+03:00: run: fn(args) => [ text, isError, images: [ [ mimeType, data ] ] ]
	$tools = [];
	$wanted = array_map('strval', (array)$this->cfg('tools', []));
	foreach(static::projectTools() as $t) {
	    if (!in_array($t['name'], $wanted, true))
		continue;
	    //CLD: 2026-10-14T16:35:21+03:00: util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing)
	    $tools[$t['name']] = [ 'name' => $t['name'], 'description' => $t['description'], 'schema' => json_decode(\util\JSON::encode($t['inputSchema'])),
		'run' => function(array $args) use ($t, $project) {
		    try {
			//CLD: 2026-10-17T21:00:54+03:00: the images of the result (read_image) apart
			//CLD: 2026-10-20T23:40:00+03:00: the tool's run (projectTools; was util\McpTools::images(call()))
			[ $result, $images ] = ($t['run'])($args, $project);
			return [ json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), false, $images ];
		    } catch (\ErrorException $e) {
			return [ $e->getMessage(), true, [] ];
		    }
		} ];
	}
	$timeout = (int)$this->cfg('timeout', 300);
	foreach((array)$this->cfg('mcp', []) as $i => $id) {
	    $mcp = \models\Credential::findFor($this->exec, (string)$id, 'mcp');
	    if (empty($mcp))
		throw new \ErrorException('an MCP server credential is no longer available');
	    $client = $mcp->client($timeout);
	    $clients[] = $client;
	    //names unique and as the providers want them (letters, digits, _ -, at most 64): mcp<n>_<tool>
	    $tools += $this->mcpTools($client, 'mcp' . ($i + 1), "MCP server `{$mcp->name}`", (string)$mcp->name, null);
	}
	//CLD: 2026-10-22T22:00:00+03:00: the installation's tool servers enabled for the account: a session each (the server's open() may refuse: too many at once)
	$enabled = static::localServers($this->exec->aid ?? null);
	foreach((array)$this->cfg('servers', []) as $id) {
	    $srv = $enabled[(string)$id] ?? null;
	    if ($srv === null)
		throw new \ErrorException("the tool server `{$id}` is not enabled for this account");
	    try {
		$client = ($srv['open'])($timeout);
	    } catch (\Exception $e) {
		throw new \ErrorException("{$srv['label']}: " . $e->getMessage());
	    }
	    $clients[] = $client;
	    $tools += $this->mcpTools($client, (string)$id, (string)$srv['label'], (string)$srv['label'], $srv['tools'] ?? null);
	}
	return $tools;
    }

    //CLD: 2026-10-22T22:00:00+03:00: new (was in run(), for the MCP server credentials): the tools of MCP session $client (initialized here) named <$prefix>_<tool>, described with [$label];
    //$allow: the tool names offered (null: all); $what names the server in errors
    protected function mcpTools($client, $prefix, $what, $label, $allow) {
	try {
	    $client->initialize();
	    $list = $client->listTools();
	} catch (\Exception $e) {
	    throw new \ErrorException("{$what}: " . $e->getMessage());
	}
	$tools = [];
	foreach($list as $t) {
	    if ($allow !== null && !in_array($t['name'], $allow, true))
		continue;
	    //names unique and as the providers want them (letters, digits, _ -, at most 64)
	    $tn = substr(preg_replace('/[^A-Za-z0-9_-]/', '_', $prefix . '_' . $t['name']), 0, 64);
	    $tools[$tn] = [ 'name' => $tn, 'description' => "[{$label}] " . ($t['description'] ?: ($t['title'] ?? $t['name'])), 'schema' => $t['inputSchema'],
		'run' => function(array $args) use ($client, $t) {
		    $r = $client->callTool($t['name'], $args);
		    $parts = [];
		    $images = [];
		    foreach((array)($r['content'] ?? []) as $c) {
			//CLD: 2026-10-17T21:00:54+03:00: image content goes as an image (was its JSON, base64 included), when it is a type the models read and not too large
			if (($c['type'] ?? '') === 'image') {
			    $mime = strtolower((string)($c['mimeType'] ?? ''));
			    $size = (int)(strlen((string)($c['data'] ?? '')) * 3 / 4);
			    if (in_array($mime, self::IMAGE_TYPES, true) && $size <= self::MAX_IMAGE) //CLD: 2026-10-20T23:40:00+03:00: (was util\McpTools::MAX_IMAGE)
				$images[] = [ $mime, (string)$c['data'] ];
			    else
				$parts[] = "[an image ({$mime}, {$size} bytes) the model cannot be sent]";
			    continue;
			}
			$parts[] = ($c['type'] ?? '') === 'text' ? (string)$c['text'] : json_encode($c, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		    }
		    if (!$parts && !$images && isset($r['structuredContent']))
			$parts[] = json_encode($r['structuredContent'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		    return [ implode("\n", $parts), !empty($r['isError']), $images ];
		} ];
	}
	return $tools;
    }

    //CLD: 2026-10-17T21:00:54+03:00: the image files of setting Images (file ids: one, a list, objects with `id` / `file` - e.g. an attachment -, or text with ids) of project $project:
    //[ [ mimeType, data (base64) ] ]; \ErrorException for a file that is not there or not an image
    protected function promptImages($project) {
	$value = $this->expr('images', '');
	$ids = [];
	$collect = function($v) use (&$collect, &$ids) {
	    if (is_object($v))
		$v = (array)$v;
	    if (is_array($v)) {
		if (!array_is_list($v)) {
		    $id = $v['file'] ?? $v['id'] ?? null;
		    if ($id !== null)
			$collect($id);
		    return;
		}
		foreach($v as $item)
		    $collect($item);
		return;
	    }
	    if (is_string($v) && preg_match_all('/\b[a-f0-9]{24}\b/i', $v, $m))
		foreach($m[0] as $id)
		    $ids[strtolower($id)] = true;
	};
	$collect($value);
	if (count($ids) > self::MAX_IMAGES)
	    throw new \ErrorException('Images: ' . count($ids) . ' files, at most ' . self::MAX_IMAGES);
	$images = [];
	foreach(array_keys($ids) as $id) {
	    $f = \V::app()->om->findOne('File', [ '_id' => $id, 'metadata.aid' => $project->aid, 'metadata.project' => $project->_id ]);
	    if (!$f)
		throw new \ErrorException("Images: no file `{$id}` in this project");
	    $images[] = self::fileImage($f); //CLD: 2026-10-20T23:40:00+03:00: (was util\McpTools::fileImage)
	}
	if ($images)
	    $this->exec->log($this->wfid, static::$label . ': ' . count($images) . ' image(s) with the prompt');
	return $images;
    }

    protected function closeClient($client) {
	try {
	    $client->close();
	} catch (\Exception $e) {
	}
    }

    //the system prompt asking for JSON ($schema: an object, or true when there is none) - for providers without a native JSON mode
    protected static function jsonInstruction($schema) {
	$text = 'Answer with JSON only: one JSON value, with no other text and no code fences.';
	if (is_object($schema))
	    $text .= ' It must match this JSON schema: ' . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	return $text;
    }

    //POST $body as JSON to $url: the decoded answer ($assoc = false: as objects, to send parts of it back unchanged); \ErrorException when there is no answer, an HTTP
    //error (with the provider's message) or no JSON
    protected function postJson($url, array $headers, array $body, $assoc = true) {
	$lines = [ 'Content-Type: application/json', 'Accept: application/json', 'User-Agent: QSM-Workflow' ];
	foreach($headers as $name => $value)
	    $lines[] = $name . ': ' . $value;
	$timeout = max(5, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 300)));
	$raw = '';
	$tooLarge = false;
	$ch = curl_init($url);
	curl_setopt_array($ch, [
	    CURLOPT_POST => true,
	    //CLD: 2026-10-14T16:35:21+03:00: util\JSON::encode (was json_encode: same escaping everywhere, safe inside HTML / scripts, invalid UTF-8 dropped instead of failing)
	    CURLOPT_POSTFIELDS => \util\JSON::encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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
	    throw new \ErrorException('the answer is larger than ' . (self::MAX_RESPONSE / 1048576) . ' MB');
	if ($error !== '')
	    throw new \ErrorException('no answer from ' . preg_replace('/[?#].*$/', '', $url) . ": {$error}");
	$data = json_decode($raw, true);
	if ($status >= 400) {
	    $m = is_array($data) ? ($data['error']['message'] ?? $data['message'] ?? (is_string($data['error'] ?? null) ? $data['error'] : null)) : null;
	    throw new \ErrorException(static::PROVIDER . " answered HTTP status {$status}" . (is_string($m) && $m !== '' ? ': ' . mb_substr($m, 0, 500) : ''));
	}
	if (!is_array($data))
	    throw new \ErrorException(static::PROVIDER . ' did not answer JSON (is the URL right?)');
	return $assoc ? $data : json_decode($raw);
    }
}
