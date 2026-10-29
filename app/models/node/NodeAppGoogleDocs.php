<?php

/*
    CLD: 2026-10-20T15:00:00+03:00: new step: Google Docs - reads, creates and fills Google documents (Docs API v1), through a Google Docs or Google Drive (OAuth) credential of
    the project (both provide `gdocs`); a NodeAppGoogle step. Inspired by sim.ai's Google Docs block. Operations (setting `operation`):
	read     the document's text, also as Markdown (headings, lists, bold / italic, links, tables)
	create   a document with a title, and optionally a first content
	append   content at the end of a document
	replace  texts replaced everywhere in a document (find / replace rows, and / or an object: each key's {{key}} replaced by its value) - fills a template
	copy     a copy of a document (e.g. of a template, before replace), through the Drive API: a Google Drive credential
    The content (create / append) is text, or Markdown turned into the document's formatting: # headings, - / * bullets, 1. numbered lists (nested by 2 spaces), **bold**,
    *italic*, `code`, [links](https://...). The answer goes into one variable (default `doc`): { id, title, url, ... }.
    On error (Docs / Drive API errors, e.g. not found, no access, a credential without the Drive scope for copy) - fail the step, or continue on the `error` output (NodeAppGoogle)
*/

//CLD: 2026-10-22T00:35:40+03:00: moved to the GoogleIntegration plugin (was models\node\NodeAppGoogleDocs); core classes fully qualified
//CLD: 2026-10-26T23:39:49+02:00: moved back to the core (was plugins\GoogleIntegration\models): the Google credentials and steps are core, for every account (no plan feature)
namespace models\node;

class NodeAppGoogleDocs extends NodeAppGoogle {
    //static data/structure
    public static $name = 'google-docs';
    public static $label = 'Google Docs';
    public static $icon = 'bi-file-earmark-text';
    public static $capability = 'gdocs';
    public static $credentialLabel = 'Google credential';
    public static $credentialKinds = 'Google Docs or Google Drive';
    public static $defaultOperation = 'read';
    public static $defaultVariable = 'doc';

    const OPERATIONS = [
	'read' => 'Read a document',
	'create' => 'Create a document',
	'append' => 'Append content',
	'replace' => 'Replace texts (fill a template)',
	'copy' => 'Copy a document (a template)',
    ];

    //the operations on an existing document, the ones writing content
    const ON_DOCUMENT = [ 'read', 'append', 'replace', 'copy' ];
    const WRITE = [ 'create', 'append' ];

    const FORMATS = [ 'markdown' => 'Markdown (headings, lists, bold, links)', 'text' => 'Plain text' ];

    const API = 'https://docs.googleapis.com/v1/documents';
    const DRIVE_API = 'https://www.googleapis.com/drive/v3';

    //the largest text kept of a document read (each of text, markdown), of the content written
    const MAX_TEXT = 1024 * 1024;
    const MAX_REPLACE = 100;

    public static function configSchema() {
	//CLD: 2026-10-24T12:47:49+03:00: the help texts without their "; {{ ... }} for expressions" ending (the expression inputs suggest as one types; less height in the wide dialog)
	$op = fn($ops) => [ 'key' => 'operation', 'operator' => 'in', 'value' => $ops ];
	return [
	    static::credentialField(),
	    static::operationField(),
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide, on a new line; shorter help (was: The document id or its link (https://docs.google.com/document/d/...); {{ ... }} for expressions, e.g. {{ doc.id }})
		'key' => 'documentId',
		'type' => 'text',
		'label' => 'Document',
		'default' => '',
		'help' => 'The document id or its link, e.g. {{ doc.id }}',
		'requiredIf' => [ 'if' => 'operation', 'in' => self::ON_DOCUMENT ],
		'ui' => [ 'width' => 6, 'newRow' => true, 'placeholder' => '1AbC... or https://docs.google.com/document/d/...', 'expression' => true, 'showIf' => $op(self::ON_DOCUMENT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide
		'key' => 'title',
		'type' => 'text',
		'label' => 'Title',
		'default' => '',
		'help' => 'Copy: empty gives "Copy of <title>"',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'create' ] ],
		'ui' => [ 'width' => 6, 'placeholder' => 'Offer {{ client.name }}', 'expression' => true, 'showIf' => $op([ 'create', 'copy' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide (was 6), on a new line
		'key' => 'format',
		'type' => 'select',
		'label' => 'Content is',
		'required' => true,
		'default' => 'markdown',
		'choices' => self::FORMATS,
		'ui' => [ 'width' => 3, 'newRow' => true, 'showIf' => $op(self::WRITE) ],
	    ],
	    [
		'key' => 'content',
		'type' => 'textarea',
		'label' => 'Content',
		'default' => '',
		'help' => 'Create: optional',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'append' ] ],
		'ui' => [ 'rows' => 8, 'expression' => true, 'placeholder' => "# Report {{ date }}\n\n- **Orders:** {{ orders.length }}", 'showIf' => $op(self::WRITE) ],
	    ],
	    [
		'key' => 'replacements',
		'type' => 'nested',
		'multiple' => true,
		'fields' => self::replaceSchema(),
		'label' => 'Replace',
		'itemLabel' => 'Replacement',
		'default' => [],
		'ui' => [ 'addLabel' => 'Add replacement', 'compact' => true, 'maxRows' => self::MAX_REPLACE, 'showIf' => $op([ 'replace' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 9 wide, on a new line; shorter help (was: Optional: {{ ... }} giving an object, e.g. {{ row }}: each key's placeholder {{key}} in the document is replaced by its value)
		'key' => 'placeholders',
		'type' => 'text',
		'label' => 'Placeholders',
		'default' => '',
		'help' => 'Optional: {{ ... }} giving an object: each {{key}} in the document gets its value',
		'ui' => [ 'width' => 9, 'newRow' => true, 'placeholder' => '{{ row }}', 'expression' => true, 'showIf' => $op([ 'replace' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide (was 6)
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'matchCase',
		'type' => 'switch',
		'label' => 'Match case',
		'default' => true,
		'ui' => [ 'advanced' => true, 'width' => 3, 'showIf' => $op([ 'replace' ]) ],
	    ],
	    static::timeoutField(60),
	    //CLD: 2026-10-24T12:46:54+03:00: shorter help (the narrow columns of the wide dialog; was: Read: { id, title, url, text, markdown }; replace: { id, url, replaced, counts }; else { id, title, url })
	    static::variableField('The document: { id, title, url, ... }'),
	    //CLD: 2026-10-24T12:46:54+03:00: shorter help (the narrow columns of the wide dialog; was: An error from Google Docs (e.g. not found, no access); copy: a credential without the Drive scope)
	    static::onErrorField('Docs errors; copy: no Drive scope'),
	];
    }

    //one row of Replace
    public static function replaceSchema() {
	return [
	    [ 'key' => 'find', 'type' => 'text', 'label' => 'Find', 'required' => true, 'help' => false, 'ui' => [ 'width' => 5, 'placeholder' => '[[name]]' ] ],
	    [ 'key' => 'replace', 'type' => 'text', 'label' => 'Replace with', 'help' => false, 'ui' => [ 'width' => 7, 'expression' => true, 'placeholder' => '{{ client.name }}' ] ],
	];
    }

    //+ replace needs something to replace
    public function validates($options = []) {
	$valid = parent::validates($options);
	if ($this->cfg('operation', 'read') === 'replace' && empty($this->cfg('replacements', [])) && trim((string)$this->cfg('placeholders', '')) === '') {
	    $this->errors('config', 'Replace: add a replacement or give Placeholders');
	    $valid = false;
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = static::variableName($config);
	if ($name === null)
	    return [];
	$doc = [ 'id' => [ 'type' => 'string', 'info' => 'the document id' ], 'title' => [ 'type' => 'string' ], 'url' => [ 'type' => 'string', 'info' => 'the link that opens it' ] ];
	return [ $name => match(is_array($config) ? ($config['operation'] ?? 'read') : 'read') {
	    'read' => [ 'type' => 'object', 'info' => 'the document: { id, title, url, text, markdown, revisionId }', 'fields' => $doc + [ 'text' => [ 'type' => 'string', 'info' => 'the plain text' ],
		'markdown' => [ 'type' => 'string', 'info' => 'the text as Markdown (headings, lists, bold / italic, links, tables)' ], 'revisionId' => [ 'type' => 'string' ] ] ],
	    'replace' => [ 'type' => 'object', 'info' => 'the replacements: { id, url, replaced, counts }', 'fields' => [ 'id' => $doc['id'], 'url' => $doc['url'],
		'replaced' => [ 'type' => 'number', 'info' => 'the number of texts replaced' ],
		'counts' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => [ 'find' => [ 'type' => 'string' ], 'count' => [ 'type' => 'number' ] ] ] ] ] ],
	    default => [ 'type' => 'object', 'info' => 'the document: { id, title, url }', 'fields' => $doc ],
	} ];
    }

    // ------------------------------------------------------------------------------------
    //running

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	return match($operation) {
	    'read' => $this->opRead($text),
	    'create' => $this->opCreate($text, $value),
	    'append' => $this->opAppend($text, $value),
	    'replace' => $this->opReplace($text, $value),
	    'copy' => $this->opCopy($text),
	};
    }

    protected function opRead(\Closure $text) {
	$id = $this->documentId($text('documentId'));
	$doc = $this->api('GET', "/{$id}", [], null, 50 * 1024 * 1024);
	$result = [ 'id' => $id, 'title' => (string)($doc['title'] ?? ''), 'url' => self::url($id), 'text' => self::cut(self::plainText($doc['body']['content'] ?? [])),
	    'markdown' => self::cut(self::markdown($doc)), 'revisionId' => (string)($doc['revisionId'] ?? '') ];
	return [ $result, "`{$result['title']}`: " . mb_strlen($result['text']) . ' characters' ];
    }

    protected function opCreate(\Closure $text, \Closure $value) {
	$title = $text('title');
	if ($title === '')
	    throw new \ErrorException('Google Docs: create: the document needs a title');
	$doc = $this->api('POST', '', [], [ 'title' => mb_substr($title, 0, 300) ]);
	$id = (string)($doc['documentId'] ?? '');
	$content = $this->content($value);
	if ($content !== '')
	    $this->write($id, $content, [ 'body' => [ 'content' => [ [ 'endIndex' => 2 ] ] ] ]);
	return [ [ 'id' => $id, 'title' => (string)($doc['title'] ?? $title), 'url' => self::url($id) ], "`{$title}` created ({$id})" . ($content !== '' ? ' with ' . mb_strlen($content) . ' characters' : '') ];
    }

    protected function opAppend(\Closure $text, \Closure $value) {
	$id = $this->documentId($text('documentId'));
	$content = $this->content($value);
	if ($content === '')
	    throw new \ErrorException('Google Docs: append: no content');
	$doc = $this->api('GET', "/{$id}", [ 'fields' => 'title,body.content(endIndex)' ]);
	$this->write($id, $content, $doc);
	return [ [ 'id' => $id, 'title' => (string)($doc['title'] ?? ''), 'url' => self::url($id) ], mb_strlen($content) . " characters appended to `" . ($doc['title'] ?? $id) . '`' ];
    }

    protected function opReplace(\Closure $text, \Closure $value) {
	$id = $this->documentId($text('documentId'));
	$pairs = [];
	foreach((array)$this->cfg('replacements', []) as $i => $row) {
	    $find = (string)($row['find'] ?? '');
	    if ($find === '')
		continue;
	    $replace = \util\Expression::render((string)($row['replace'] ?? ''), $this->exec->expressionVariables());
	    $pairs[$find] = self::cellText($replace);
	}
	$object = trim((string)$this->cfg('placeholders', '')) !== '' ? $value('placeholders') : null;
	if ($object !== null) {
	    if (!is_array($object) || ($object !== [] && array_is_list($object)))
		throw new \ErrorException('Google Docs: replace: Placeholders is not an object (got ' . get_debug_type($object) . ')');
	    foreach($object as $key => $v)
		$pairs['{{' . $key . '}}'] = self::cellText($v);
	}
	if (!$pairs)
	    throw new \ErrorException('Google Docs: replace: nothing to replace');
	$matchCase = (bool)$this->cfg('matchCase', true);
	$requests = [];
	foreach($pairs as $find => $replace)
	    $requests[] = [ 'replaceAllText' => [ 'containsText' => [ 'text' => (string)$find, 'matchCase' => $matchCase ], 'replaceText' => $replace ] ];
	$data = $this->api('POST', "/{$id}:batchUpdate", [], [ 'requests' => $requests ]);
	$counts = [];
	foreach(array_keys($pairs) as $i => $find)
	    $counts[] = [ 'find' => (string)$find, 'count' => (int)($data['replies'][$i]['replaceAllText']['occurrencesChanged'] ?? 0) ];
	$total = array_sum(array_column($counts, 'count'));
	return [ [ 'id' => $id, 'url' => self::url($id), 'replaced' => $total, 'counts' => $counts ], "{$total} text(s) replaced (" . count($pairs) . ' to find)' ];
    }

    //through the Drive API (files.copy): needs a credential with the Drive scope
    protected function opCopy(\Closure $text) {
	$id = $this->documentId($text('documentId'));
	$title = $text('title');
	$data = $this->call('POST', self::DRIVE_API . "/files/{$id}/copy" . self::queryString([ 'supportsAllDrives' => 'true', 'fields' => 'id,name' ]),
	    array_merge($this->authHeaders(), [ 'Accept: application/json', 'Content-Type: application/json; charset=UTF-8' ]), json_encode($title !== '' ? [ 'name' => $title ] : (object)[], JSON_UNESCAPED_UNICODE));
	$copy = (string)($data['id'] ?? '');
	return [ [ 'id' => $copy, 'title' => (string)($data['name'] ?? $title), 'url' => self::url($copy) ], "copied to `" . ($data['name'] ?? $title) . "` ({$copy})" ];
    }

    // ------------------------------------------------------------------------------------
    //writing

    //the content to write (create / append), as text
    protected function content(\Closure $value) {
	$v = $value('content');
	$content = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : \util\Expression::toText($v);
	$content = rtrim(str_replace([ "\r\n", "\r" ], "\n", $content), "\n");
	if (strlen($content) > self::MAX_TEXT)
	    throw new \RuntimeException('the content is larger than ' . (self::MAX_TEXT >> 20) . ' MB');
	return $content;
    }

    //$content written at the end of document $id ($doc: its body.content endIndex): one batchUpdate (requests())
    protected function write($id, $content, array $doc) {
	$last = end($doc['body']['content']) ?: [];
	$end = (int)($last['endIndex'] ?? 2);
	$this->api('POST', "/{$id}:batchUpdate", [], [ 'requests' => self::requests($content, $end - 1, $end > 2, $this->cfg('format', 'markdown') === 'markdown') ]);
    }

    //the length of $s in the Docs API's indexes (UTF-16 code units)
    public static function len($s) {
	return intdiv(strlen(mb_convert_encoding((string)$s, 'UTF-16LE', 'UTF-8')), 2);
    }

    //the batchUpdate requests writing $content at $at (the index before the body's last newline); $newParagraph: the document has content, ours starts on a new paragraph;
    //the inserted paragraphs reset to normal text without bullets (they would take the last paragraph's style), then the Markdown formatting; the bullets last, the last
    //list first (creating bullets removes the leading tabs, which moves what follows)
    public static function requests($content, $at, $newParagraph, $markdown) {
	[ $text, $paragraphs, $spans ] = $markdown ? self::fromMarkdown($content) : [ $content, [], [] ];
	$offset = $at + ($newParagraph ? 1 : 0);
	$length = self::len($text);
	$requests = [ [ 'insertText' => [ 'location' => [ 'index' => $at ], 'text' => ($newParagraph ? "\n" : '') . $text ] ] ];
	$all = [ 'startIndex' => $offset, 'endIndex' => $offset + $length + 1 ];
	$requests[] = [ 'updateParagraphStyle' => [ 'range' => $all, 'paragraphStyle' => [ 'namedStyleType' => 'NORMAL_TEXT' ], 'fields' => 'namedStyleType' ] ];
	$requests[] = [ 'deleteParagraphBullets' => [ 'range' => $all ] ];
	if ($length > 0)
	    $requests[] = [ 'updateTextStyle' => [ 'range' => [ 'startIndex' => $offset, 'endIndex' => $offset + $length ], 'textStyle' => (object)[], 'fields' => 'bold,italic,link,weightedFontFamily' ] ];

	$lists = [];
	foreach($paragraphs as [ $start, $end, $kind ]) {
	    $range = [ 'startIndex' => $offset + $start, 'endIndex' => $offset + $end + 1 ];
	    if (str_starts_with($kind, 'HEADING_') || $kind === 'TITLE')
		$requests[] = [ 'updateParagraphStyle' => [ 'range' => $range, 'paragraphStyle' => [ 'namedStyleType' => $kind ], 'fields' => 'namedStyleType' ] ];
	    elseif ($kind === 'bullet' || $kind === 'numbered') {
		$prev = $lists ? $lists[count($lists) - 1] : null;
		//one list for consecutive items of the same kind (numbering goes on)
		if ($prev && $prev['kind'] === $kind && $prev['end'] === $range['startIndex'])
		    $lists[count($lists) - 1]['end'] = $range['endIndex'];
		else
		    $lists[] = [ 'kind' => $kind, 'start' => $range['startIndex'], 'end' => $range['endIndex'] ];
	    }
	}
	foreach($spans as [ $start, $end, $style ]) {
	    if ($end <= $start)
		continue;
	    $requests[] = [ 'updateTextStyle' => [ 'range' => [ 'startIndex' => $offset + $start, 'endIndex' => $offset + $end ], 'textStyle' => $style, 'fields' => implode(',', array_keys($style)) ] ];
	}
	foreach(array_reverse($lists) as $l)
	    $requests[] = [ 'createParagraphBullets' => [ 'range' => [ 'startIndex' => $l['start'], 'endIndex' => $l['end'] ],
		'bulletPreset' => $l['kind'] === 'numbered' ? 'NUMBERED_DECIMAL_ALPHA_ROMAN' : 'BULLET_DISC_CIRCLE_SQUARE' ] ];
	return $requests;
    }

    //Markdown -> [ text, paragraphs: [ [ start, end, kind ] ], spans: [ [ start, end, textStyle ] ] ] (indexes in the text, UTF-16); kinds: HEADING_1..6, bullet, numbered, normal;
    //list items nested by 2 spaces get leading tabs (createParagraphBullets reads them as the nesting level)
    public static function fromMarkdown($md) {
	$text = '';
	$paragraphs = [];
	$spans = [];
	$at = 0;
	foreach(explode("\n", (string)$md) as $i => $line) {
	    $kind = 'normal';
	    $prefix = '';
	    if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
		$kind = 'HEADING_' . strlen($m[1]);
		$line = $m[2];
	    } elseif (preg_match('/^( *)([-*+]|\d+[.)])\s+(.*)$/', $line, $m)) {
		$kind = ctype_digit(rtrim($m[2], '.)')) ? 'numbered' : 'bullet';
		$prefix = str_repeat("\t", min(8, intdiv(strlen($m[1]), 2)));
		$line = $m[3];
	    }
	    [ $plain, $lineSpans ] = self::inline($line);
	    $plain = $prefix . $plain;
	    if ($i > 0) {
		$text .= "\n";
		$at++;
	    }
	    $start = $at;
	    foreach($lineSpans as [ $s, $e, $style ])
		$spans[] = [ $start + self::len($prefix) + $s, $start + self::len($prefix) + $e, $style ];
	    $text .= $plain;
	    $at += self::len($plain);
	    $paragraphs[] = [ $start, $at, $kind ];
	}
	return [ $text, $paragraphs, $spans ];
    }

    //inline Markdown of one line -> [ plain text, spans: [ [ start, end, textStyle ] ] ]: **bold** / __bold__, *italic* / _italic_, `code`, [text](url)
    public static function inline($line) {
	$pattern = '/\*\*(.+?)\*\*|(?<![\pL\pN])__(.+?)__(?![\pL\pN])|\*(?![\s*])(.+?)(?<!\s)\*|(?<![\pL\pN_])_(?![\s_])(.+?)(?<!\s)_(?![\pL\pN])|`([^`]+)`|\[([^\]]+)\]\((https?:\/\/[^)\s]+|mailto:[^)\s]+)\)/u';
	$plain = '';
	$spans = [];
	$pos = 0;
	preg_match_all($pattern, $line, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
	foreach($all as $m) {
	    $plain .= substr($line, $pos, $m[0][1] - $pos);
	    $pos = $m[0][1] + strlen($m[0][0]);
	    $start = self::len($plain);
	    if (($m[1][0] ?? '') !== '' || ($m[2][0] ?? '') !== '') {
		$inner = $m[1][0] !== '' ? $m[1][0] : $m[2][0];
		$style = [ 'bold' => true ];
	    } elseif (($m[3][0] ?? '') !== '' || ($m[4][0] ?? '') !== '') {
		$inner = ($m[3][0] ?? '') !== '' ? $m[3][0] : $m[4][0];
		$style = [ 'italic' => true ];
	    } elseif (($m[5][0] ?? '') !== '') {
		$inner = $m[5][0];
		$style = [ 'weightedFontFamily' => [ 'fontFamily' => 'Courier New' ] ];
	    } else {
		$inner = $m[6][0];
		$style = [ 'link' => [ 'url' => $m[7][0] ] ];
	    }
	    $plain .= $inner;
	    $spans[] = [ $start, self::len($plain), $style ];
	}
	$plain .= substr($line, $pos);
	return [ $plain, $spans ];
    }

    // ------------------------------------------------------------------------------------
    //reading

    //the plain text of structural elements (paragraphs, tables: cells by tabs, rows by lines)
    public static function plainText($content) {
	$out = '';
	foreach((array)$content as $el) {
	    if (isset($el['paragraph'])) {
		foreach((array)($el['paragraph']['elements'] ?? []) as $e)
		    $out .= (string)($e['textRun']['content'] ?? '');
	    } elseif (isset($el['table'])) {
		foreach((array)($el['table']['tableRows'] ?? []) as $row)
		    $out .= implode("\t", array_map(fn($cell) => trim(str_replace("\n", ' ', self::plainText($cell['content'] ?? []))), (array)($row['tableCells'] ?? []))) . "\n";
	    }
	}
	return $out;
    }

    //the document as Markdown: headings, list items (numbered by the list's glyph type, nested by 2 spaces), bold / italic / links, tables
    public static function markdown(array $doc) {
	$lines = [];
	$numbered = [];
	foreach((array)($doc['lists'] ?? []) as $listId => $list) {
	    foreach((array)($list['listProperties']['nestingLevels'] ?? []) as $level => $nl)
		$numbered[$listId][$level] = isset($nl['glyphType']) && !in_array($nl['glyphType'], [ 'GLYPH_TYPE_UNSPECIFIED', 'NONE' ], true);
	}
	foreach((array)($doc['body']['content'] ?? []) as $el) {
	    if (isset($el['paragraph'])) {
		$p = $el['paragraph'];
		$line = '';
		foreach((array)($p['elements'] ?? []) as $e) {
		    if (isset($e['textRun']))
			$line .= self::markdownRun(rtrim((string)$e['textRun']['content'], "\n"), (array)($e['textRun']['textStyle'] ?? []));
		}
		$style = (string)($p['paragraphStyle']['namedStyleType'] ?? 'NORMAL_TEXT');
		if (isset($p['bullet'])) {
		    $level = (int)($p['bullet']['nestingLevel'] ?? 0);
		    $line = str_repeat('  ', $level) . (!empty($numbered[$p['bullet']['listId'] ?? ''][$level]) ? '1. ' : '- ') . $line;
		} elseif (preg_match('/^HEADING_([1-6])$/', $style, $m)) {
		    $line = str_repeat('#', (int)$m[1]) . ' ' . $line;
		} elseif ($style === 'TITLE') {
		    $line = '# ' . $line;
		}
		$lines[] = $line;
	    } elseif (isset($el['table'])) {
		foreach(array_values((array)($el['table']['tableRows'] ?? [])) as $r => $row) {
		    $cells = array_map(fn($cell) => str_replace('|', '\|', trim(str_replace("\n", ' ', self::plainText($cell['content'] ?? [])))), (array)($row['tableCells'] ?? []));
		    $lines[] = '| ' . implode(' | ', $cells) . ' |';
		    if ($r === 0)
			$lines[] = '|' . str_repeat(' --- |', count($cells));
		}
	    }
	}
	return rtrim(implode("\n", $lines)) . "\n";
    }

    //one text run as Markdown (the marks around the text, its spaces outside them)
    protected static function markdownRun($t, array $style) {
	if (trim($t) === '')
	    return $t;
	preg_match('/^(\s*)(.*?)(\s*)$/su', $t, $m);
	$core = $m[2];
	if (!empty($style['weightedFontFamily']['fontFamily']) && preg_match('/mono|courier|consolas/i', $style['weightedFontFamily']['fontFamily']))
	    $core = '`' . $core . '`';
	if (!empty($style['bold']) && !empty($style['italic']))
	    $core = "***{$core}***";
	elseif (!empty($style['bold']))
	    $core = "**{$core}**";
	elseif (!empty($style['italic']))
	    $core = "*{$core}*";
	if (!empty($style['link']['url']))
	    $core = "[{$core}]({$style['link']['url']})";
	return $m[1] . $core . $m[3];
    }

    // ------------------------------------------------------------------------------------
    //helpers

    protected function documentId($value) {
	$id = self::driveId($value);
	if (!preg_match('/^[A-Za-z0-9_-]{10,200}$/', $id))
	    throw new \RuntimeException($id === '' ? 'no document given' : 'not a document id: `' . mb_substr($id, 0, 100) . '`');
	return $id;
    }

    public static function url($id) {
	return 'https://docs.google.com/document/d/' . $id . '/edit';
    }

    //a replacement's value as text (lists / objects as JSON)
    protected static function cellText($v) {
	if ($v === null)
	    return '';
	if (is_bool($v))
	    return $v ? 'true' : 'false';
	return is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : \util\Expression::toText($v);
    }

    protected static function cut($s) {
	return strlen($s) <= self::MAX_TEXT ? $s : mb_strcut($s, 0, self::MAX_TEXT, 'UTF-8');
    }
}
