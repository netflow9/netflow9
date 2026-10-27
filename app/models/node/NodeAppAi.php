<?php
/*
    what the AI steps share:
	Claude - NodeAppClaude,
	OpenAI - NodeAppOpenAI; not a step itself: not registered).
	Lemonade AI - NodeAppLemonade (the OpenAI requests),
	Ollama - NodeAppOllama
	Gemini - NodeAppGemini
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
	//CLD: 2026-10-27T23:11:38+02:00: images -> files (`files`; a saved step's `images` is still read, moved on save): images, PDF, text and Office files sent with the prompt
	//(promptFiles: parts image / pdf / text, mapped by each provider's startConversation). PDFs as (`pdfs`): auto (documents where the provider reads them: pdfAsDocuments),
	//documents (the PDF itself), text (its text and images: ddn\sapp\PDFContentExtractor); Office files as their text (util\OfficeText)
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

    const DEFAULT_TOOLS = [ 'list_tables', 'query_table', 'list_workflows', 'get_execution', 'list_files', 'read_file', 'read_image', 'list_variables' ];
    //CLD: 2026-10-27T23:11:38+02:00: MAX_FILES (was MAX_IMAGES): the files sent with the prompt
    const MAX_FILES = 20;
    const MAX_IMAGE = 5242880;
    const IMAGE_TYPES = [ 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ];
    //CLD: 2026-10-27T23:11:38+02:00: the files' limits: a PDF (bytes), a text file read (bytes), the text of a file (characters: cut after), all the files of a request (bytes sent),
    //the images taken from a PDF read as text
    const MAX_PDF = 20971520;
    const MAX_TEXT_FILE = 10485760;
    const MAX_TEXT = 200000;
    const MAX_SEND = 31457280;
    const MAX_PDF_IMAGES = 10;
    //CLD: 2026-10-27T23:11:38+02:00: the text files sent as text (by extension)
    const TEXT_TYPES = [ 'txt', 'csv', 'tsv', 'md', 'json', 'xml', 'yaml', 'yml', 'html', 'htm', 'log' ];
    //CLD: 2026-10-27T23:11:38+02:00: setting PDFs as; PDF_PARTS: the provider can be sent a PDF itself (false: as text only, `document` not offered)
    const PDFS = [ 'auto' => 'Auto', 'document' => 'Documents', 'text' => 'Text and images' ];
    const PDF_PARTS = true;

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

    //CLD: 2026-10-27T23:11:38+02:00: new: the text of file $f, at most $maxChars (cut, with a marker): a PDF (its pages' text: pdfRead), an Office document (util\OfficeText), a text
    //file (TEXT_TYPES: UTF-8); null when it is none of them; \ErrorException when it cannot be read (also used by the read_file tool)
    public static function fileText($f, $maxChars = self::MAX_TEXT) {
	$ext = $f->extension();
	if ($ext === 'pdf') {
	    if ((int)$f->length > self::MAX_PDF)
		throw new \ErrorException("`{$f->filename}` is too large (" . \util\Format::bytes($f->length) . ', at most ' . \util\Format::bytes(self::MAX_PDF) . ')');
	    return self::pdfRead($f->getContents(), (string)$f->filename, $maxChars, 0)['text'];
	}
	if (\util\OfficeText::isSupported($ext)) {
	    if ((int)$f->length > self::MAX_PDF)
		throw new \ErrorException("`{$f->filename}` is too large (" . \util\Format::bytes($f->length) . ', at most ' . \util\Format::bytes(self::MAX_PDF) . ')');
	    try {
		return \util\OfficeText::textOf($f, [ 'max_chars' => $maxChars ]);
	    } catch (\RuntimeException $e) {
		throw new \ErrorException("`{$f->filename}`: " . $e->getMessage());
	    }
	}
	if (!in_array($ext, self::TEXT_TYPES, true))
	    return null;
	if ((int)$f->length > self::MAX_TEXT_FILE)
	    throw new \ErrorException("`{$f->filename}` is too large (" . \util\Format::bytes($f->length) . ', at most ' . \util\Format::bytes(self::MAX_TEXT_FILE) . ')');
	$text = $f->getContents();
	if (str_starts_with($text, "\xEF\xBB\xBF"))
	    $text = substr($text, 3);
	if (!mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x08\x0E-\x1F]/', $text))
	    throw new \ErrorException("`{$f->filename}` is not a UTF-8 text file");
	return mb_strlen($text) > $maxChars ? mb_substr($text, 0, $maxChars) . "\n[... cut at {$maxChars} characters]" : $text;
    }

    //CLD: 2026-10-27T23:11:38+02:00: new: PDF $bytes read: [ text (its pages', "--- page N ---" before each), images (at most $maxImages, those the models read: [ [ mimeType, data
    //(base64) ] ]), pages ]; \ErrorException when it is not a PDF, is encrypted, or has neither text nor images (scanned: send it as a document)
    public static function pdfRead($bytes, $name, $maxChars = self::MAX_TEXT, $maxImages = self::MAX_PDF_IMAGES) {
	try {
	    $doc = \ddn\sapp\PDFDoc::from_string($bytes);
	} catch (\Throwable $e) {
	    $doc = false;
	}
	if ($doc === false)
	    throw new \ErrorException("`{$name}` is not a PDF that can be read");
	if ($doc->is_encrypted())
	    throw new \ErrorException("`{$name}` is encrypted: its text cannot be read (send it as a document: PDFs as Documents, with Claude or Gemini)");
	$x = new \ddn\sapp\PDFContentExtractor($doc);
	try {
	    $r = $x->extract([ 'text' => true, 'images' => $maxImages > 0, 'max_chars' => $maxChars, 'max_images' => $maxImages, 'min_width' => 64, 'min_height' => 64 ]);
	} catch (\Throwable $e) {
	    $r = false;
	}
	if ($r === false)
	    throw new \ErrorException("`{$name}`: its content cannot be read");
	$pages = [];
	foreach($r['pages'] as $n => $text)
	    if ($text !== '')
		$pages[] = "--- page {$n} ---\n{$text}";
	$images = [];
	foreach($r['images'] ?? [] as $im)
	    if (in_array($im['mime'], self::IMAGE_TYPES, true) && strlen($im['data']) <= self::MAX_IMAGE)
		$images[] = [ $im['mime'], base64_encode($im['data']) ];
	if (!$pages && !$images)
	    throw new \ErrorException("`{$name}` has no text to read (scanned?): send it as a document (PDFs as Documents, with Claude or Gemini)");
	return [ 'text' => implode("\n\n", $pages), 'images' => $images, 'pages' => $doc->get_page_count() ];
    }

    //CLD: 2026-10-27T23:11:38+02:00: new: whether PDFs as Auto sends the PDF itself with credential $cred (override: the providers that read PDFs); false: its text and images
    protected function pdfAsDocuments($cred) {
	return false;
    }

    //CLD: 2026-10-27T23:11:38+02:00: new: a text file part as the text sent: <file name="...">...</file>
    protected static function fileAsText(array $part) {
	return '<file name="' . str_replace('"', "'", (string)$part['name']) . "\">\n" . $part['text'] . "\n</file>";
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
		//CLD: 2026-10-23T10:00:00+03:00: + System AI
		//CLD: 2026-10-24T12:42:45+03:00: shorter (one line; was: ... credential of the project (menu: Credentials), or System AI: this installation's, paid with the account's credits (Settings menu: AI credits))
		//CLD: 2026-10-24T13:04:58+03:00: shorter (one line, 4 wide; was: ... credential of the project, or System AI (paid with the account's AI credits)); 4 wide: the model and the provider's
		//settings next to it on line 2
		'help' => static::PROVIDER . ' credential' . ($system ? ', or System AI (AI credits)' : ''),
		'ui' => [ 'width' => 4 ],
		//CLD: 2026-10-14T14:18:23+03:00: required by the model (was only in the browser: `required` in ui); validates() checks it is a credential of the project
		'required' => true,
	    ],
	    //CLD: 2026-10-22T01:58:24+03:00: in the form, a select of the models the chosen credential recorded (its Test: Credential::recordModels), a name not listed can be typed; on the server
	    //it stays text (any model name: workflows made by the MCP tools / the Assistant, a list not refreshed yet)
	    [
		//CLD: 2026-10-24T13:04:58+03:00: layout: 4 wide (was 6); shorter help (was: Recorded by the credential's Test; or type a name)
		'key' => 'model',
		'type' => 'text',
		'label' => 'Model',
		'required' => true,
		'default' => static::defaultModel(),
		//CLD: 2026-10-24T12:44:00+03:00: shorter (one line; was: The models of the credential (Test on the credential records them); or type a model name)
		'help' => 'Or type a name',
		//CLD: 2026-10-24T12:42:45+03:00: next to the credential (the designer: 6 wide)
		'ui' => [ 'width' => 4, 'type' => 'select', 'allowCustom' => true, 'placeholder' => 'Choose a model...', 'choicesBy' => [ 'key' => 'credential', 'choices' => (object)static::modelChoices(),
		    'empty' => 'No models recorded: Test the credential, or type a name' ] ],
	    ],
	], static::providerSchema(), [
	    [
		//CLD: 2026-10-24T13:04:58+03:00: layout: 4 lines
		'key' => 'system',
		'type' => 'textarea',
		'label' => 'System prompt',
		//CLD: 2026-10-24T12:42:45+03:00: next to the prompt, as tall (was full width, 3 lines; the prompt 5 lines, was 6); shorter help (was: Who the model is and how it should work (optional); {{ ... }} for expressions)
		'help' => 'Who the model is and how it works (optional)',
		'ui' => [ 'rows' => 4, 'expression' => true, 'width' => 6, 'newRow' => true ],
	    ],
	    [
		//CLD: 2026-10-24T13:04:58+03:00: layout: 4 lines
		'key' => 'prompt',
		'type' => 'textarea',
		'label' => 'Prompt',
		'required' => true,
		//CLD: 2026-10-13T08:15:00+03:00: the example after the Email trigger's message variable (mail.text; was mail_text)
		'help' => 'The request; {{ ... }} for expressions, e.g. {{ mail.text }}',
		//CLD: 2026-10-24T12:42:45+03:00: next to the system prompt
		'ui' => [ 'rows' => 4, 'expression' => true, 'width' => 6 ],
	    ],
	    [
		'key' => 'output',
		'type' => 'select',
		'label' => 'Answer',
		'required' => true,
		'default' => 'text',
		'choices' => self::OUTPUT,
		//CLD: 2026-10-18T14:40:00+03:00: removed the inList rule: the select field checks its choices (sys\fields\Field_select::bindTo)
		//CLD: 2026-10-24T13:04:58+03:00: not on a new line (line 2 is full: the credential, the model, the provider's settings)
		//CLD: 2026-10-24T12:42:45+03:00: before the images, 3 wide on a new line (was 6, after them); shorter help (was: JSON: the answer is parsed (variable .json); a schema makes its shape precise)
		'help' => 'JSON: parsed (variable .json)',
		'ui' => [ 'width' => 3 ],
	    ],
	    //CLD: 2026-10-17T21:00:54+03:00: image files sent with the prompt
	    //CLD: 2026-10-27T23:11:38+02:00: Files (was Images, key images): images, PDF, text and Office files; 6 wide (was 9), PDFs as next to it
	    [
		'key' => 'files',
		'type' => 'text',
		'label' => 'Files',
		//CLD: 2026-10-24T12:42:45+03:00: next to the answer; shorter help (was: Optional: image files of the project the model looks at with the prompt (PNG, JPEG, GIF, WebP; at most ...), e.g. {{ file.id }},
		//a list such as {{ mail.attachment_files }}, or file ids separated by commas)
		'help' => 'Optional: images, PDF, text or Office files (at most ' . self::MAX_FILES . '): {{ file.id }}, a list, or file ids',
		'ui' => [ 'expression' => true, 'width' => 6 ],
	    ],
	    //CLD: 2026-10-27T23:11:38+02:00: new: how the PDFs are sent (no Documents for a provider that cannot be sent a PDF: PDF_PARTS)
	    [
		'key' => 'pdfs',
		'type' => 'select',
		'label' => 'PDFs as',
		'required' => true,
		'default' => 'auto',
		'choices' => static::PDF_PARTS ? self::PDFS : array_diff_key(self::PDFS, [ 'document' => 1 ]),
		'help' => static::PDF_PARTS ? 'Auto: documents if the model reads them' : 'Their text and images',
		'ui' => [ 'width' => 3 ],
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
		'label' => 'Use these tools',
		'default' => array_values(array_intersect(self::DEFAULT_TOOLS, array_keys($tools))), //CLD: 2026-10-20T23:40:00+03:00: those there are (was DEFAULT_TOOLS)
		//CLD: 2026-10-24T12:42:45+03:00: the tools in a row (tools 6 wide, MCP servers and tool servers 3); shorter help (was: What the model may do in this project (run by the workflow, without the users' permission checks))
		'help' => 'What the model may do in this project',
		'ui' => [ 'placeholder' => 'None', 'width' => 6, 'newRow' => true ],
	    ],
	    [
		'key' => 'mcp',
		//CLD: 2026-10-14T14:27:53+03:00: a `select` field with `multiple` and its choices (was an `array` field shown as a select through `ui`)
		'type' => 'select',
		'multiple' => true,
		'choices' => $mcp,
		'label' => 'MCP servers',
		'default' => [],
		//CLD: 2026-10-24T12:42:45+03:00: 3 wide; shorter help (was: MCP server credentials of the project whose tools the model may use)
		'help' => 'The project\'s MCP credentials',
		'ui' => [ 'placeholder' => 'None', 'width' => 3 ],
	    ],
	    //CLD: 2026-10-22T22:00:00+03:00: new: the installation's tool servers (localServers)
	    [
		'key' => 'servers',
		'type' => 'select',
		'multiple' => true,
		'choices' => $servers,
		'label' => 'Tool servers',
		'default' => [],
		//CLD: 2026-10-24T12:42:45+03:00: 3 wide; shorter help (was: Tools this installation runs for the account (e.g. a web browser); each run gets its own session, closed after it)
		'help' => 'E.g. a web browser',
		'ui' => [ 'placeholder' => $servers ? 'None' : 'None enabled', 'width' => 3 ],
	    ],
	    [
		//CLD: 2026-10-24T13:06:25+03:00: shorter help (was: Most calls to the model (each tool use is one more))
		'key' => 'maxRounds',
		'type' => 'number',
		'label' => 'Rounds',
		'required' => true,
		'default' => 10,
		'help' => 'Calls to the model (tool uses too)',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_ROUNDS, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_ROUNDS ] ],
		//CLD: 2026-10-14T14:27:53+03:00: min / max from the validate rule (util\WidgetsUtil; was repeated here)
		//CLD: 2026-10-24T12:42:45+03:00: a limit: the designer puts it on the last line, before the timeout (was 4 wide)
		'ui' => [ 'limit' => true ],
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
		//CLD: 2026-10-24T12:42:45+03:00: a limit (was 4 wide)
		'ui' => [ 'limit' => true ],
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
		//CLD: 2026-10-24T12:42:45+03:00: shorter (one line in the narrow column; was: The model could not be reached, declined, was cut off, or a JSON answer did not parse)
		'help' => 'Unreachable, declined, cut off, bad JSON',
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
	//CLD: 2026-10-27T23:11:38+02:00: a step saved with Images (key images): its value moves to Files
	$config = is_array($this->config) ? $this->config : [];
	if (array_key_exists('images', $config)) {
	    if (trim(\util\Expression::toText($config['files'] ?? '')) === '')
		$config['files'] = $config['images'];
	    unset($config['images']);
	    $this->config = $config;
	}
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
		//CLD: 2026-10-26T23:56:07+02:00: the tools are core (util\McpServerTools): no longer "is SysMCPTools enabled?" when there are none
		$this->errors('config', "Use these tools: unknown tool `{$name}`");
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
    //CLD: 2026-10-27T23:11:38+02:00: $files (was $images): the parts sent before the prompt (promptFiles): [ type: image, mime, data (base64), name ], [ type: pdf, data (base64), name ],
    //[ type: text, text, name ] (fileAsText)
    abstract protected function startConversation($cred, $system, $prompt, $schema, array $files = []);

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
	//CLD: 2026-10-27T23:11:38+02:00: the files (promptFiles; was promptImages)
	$files = $this->promptFiles($project, $cred);

	//CLD: 2026-10-22T22:00:00+03:00: collectTools() (was here)
	$tools = $this->collectTools($clients, $project);

	$state = $this->startConversation($cred, $systemPrompt, $prompt, $json ? ($schema ?: true) : null, $files);
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
    //CLD: 2026-10-27T23:11:38+02:00: promptFiles (was promptImages): the files of setting Files (else of a saved step's Images), as the parts startConversation sends: images
    //(fileImage), PDFs (the PDF itself, or its text and images: setting PDFs as, pdfAsDocuments($cred)), Office and text files (fileText); \ErrorException for a file that is not
    //there, not of these types, too large, or more than MAX_SEND bytes in all
    protected function promptFiles($project, $cred) {
	$value = $this->expr('files', '');
	if ($value === '' || $value === null || $value === [])
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
	if (count($ids) > self::MAX_FILES)
	    throw new \ErrorException('Files: ' . count($ids) . ' files, at most ' . self::MAX_FILES);
	$mode = (string)$this->cfg('pdfs', 'auto');
	if ($mode === 'auto')
	    $mode = $this->pdfAsDocuments($cred) ? 'document' : 'text';
	$parts = [];
	$kinds = [];
	$size = 0;
	foreach(array_keys($ids) as $id) {
	    $f = \V::app()->om->findOne('File', [ '_id' => $id, 'metadata.aid' => $project->aid, 'metadata.project' => $project->_id ]);
	    if (!$f)
		throw new \ErrorException("Files: no file `{$id}` in this project");
	    $name = (string)$f->filename;
	    $before = count($parts);
	    if ($f->imageMimeType() !== null) {
		[ $mime, $data ] = self::fileImage($f); //CLD: 2026-10-20T23:40:00+03:00: (was util\McpTools::fileImage)
		$parts[] = [ 'type' => 'image', 'mime' => $mime, 'data' => $data, 'name' => $name ];
		$kinds[] = 'image';
	    } elseif ($f->extension() === 'pdf' && $mode === 'document') {
		if (!static::PDF_PARTS)
		    throw new \ErrorException("Files: `{$name}`: " . static::PROVIDER . ' reads PDFs as text only (PDFs as: Text and images)');
		if ((int)$f->length > self::MAX_PDF)
		    throw new \ErrorException("Files: `{$name}` is too large (" . \util\Format::bytes($f->length) . ', at most ' . \util\Format::bytes(self::MAX_PDF) . ')');
		$parts[] = [ 'type' => 'pdf', 'data' => base64_encode($f->getContents()), 'name' => $name ];
		$kinds[] = 'PDF';
	    } elseif ($f->extension() === 'pdf') {
		if ((int)$f->length > self::MAX_PDF)
		    throw new \ErrorException("Files: `{$name}` is too large (" . \util\Format::bytes($f->length) . ', at most ' . \util\Format::bytes(self::MAX_PDF) . ')');
		$pdf = self::pdfRead($f->getContents(), $name);
		if ($pdf['text'] !== '')
		    $parts[] = [ 'type' => 'text', 'text' => $pdf['text'], 'name' => $name ];
		foreach($pdf['images'] as $i => [ $mime, $data ])
		    $parts[] = [ 'type' => 'image', 'mime' => $mime, 'data' => $data, 'name' => "{$name}, image " . ($i + 1) ];
		$kinds[] = 'PDF as text' . ($pdf['images'] ? ' + ' . count($pdf['images']) . ' image(s)' : '');
	    } else {
		$text = self::fileText($f);
		if ($text === null)
		    throw new \ErrorException("Files: `{$name}` is not a file the model can read (images, PDF, text or Office documents)");
		$parts[] = [ 'type' => 'text', 'text' => $text, 'name' => $name ];
		$kinds[] = 'text';
	    }
	    foreach(array_slice($parts, $before) as $p)
		$size += strlen($p['data'] ?? $p['text']);
	    if ($size > self::MAX_SEND)
		throw new \ErrorException('Files: more than ' . \util\Format::bytes(self::MAX_SEND) . ' to send');
	}
	if ($parts) {
	    $counts = array_count_values($kinds);
	    $this->exec->log($this->wfid, static::$label . ': ' . count($kinds) . ' file(s) with the prompt (' . implode(', ', array_map(fn($k, $n) => "{$n} {$k}", array_keys($counts), $counts)) . ')');
	}
	return $parts;
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
    //CLD: 2026-10-23T15:00:00+03:00: through util\Http (was curl here; the body still util\JSON::encode)
    protected function postJson($url, array $headers, array $body, $assoc = true) {
	$timeout = max(5, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 300)));
	$r = \util\Http::json('POST', $url, [ 'headers' => $headers, 'json' => $body, 'timeout' => $timeout, 'maxBytes' => self::MAX_RESPONSE ]);
	if ($r['tooLarge'])
	    throw new \ErrorException('the answer is larger than ' . (self::MAX_RESPONSE / 1048576) . ' MB');
	if ($r['error'] !== null)
	    throw new \ErrorException('no answer from ' . \util\Http::shown($url) . ": {$r['error']}");
	$status = $r['status'];
	$raw = $r['body'];
	$data = $r['data'];
	if ($status >= 400) {
	    $m = is_array($data) ? ($data['error']['message'] ?? $data['message'] ?? (is_string($data['error'] ?? null) ? $data['error'] : null)) : null;
	    throw new \ErrorException(static::PROVIDER . " answered HTTP status {$status}" . (is_string($m) && $m !== '' ? ': ' . mb_substr($m, 0, 500) : ''));
	}
	if (!is_array($data))
	    throw new \ErrorException(static::PROVIDER . ' did not answer JSON (is the URL right?)');
	return $assoc ? $data : json_decode($raw);
    }
}
