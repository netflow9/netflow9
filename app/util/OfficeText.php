<?php

/*
    CLD: 2026-10-27T23:10:00+02:00: new: the text of Office documents (the AI models read text, not these files; ZipArchive + DOM, no Composer):
	docx    Word (word/document.xml): paragraphs, headings (# by the styles' outline level: Title, heading 1..6), list items (- ), tables (| a | b |), tabs and
		line breaks, text boxes, equations (their text); then the footnotes and endnotes. Deleted text (tracked changes), field codes and the fallbacks of alternate content are left out;
		headers, footers and comments too
	pptx    PowerPoint: each slide in the presentation's order (## Slide N): its paragraphs and tables, then its notes (option notes)
	xlsx    Excel: each sheet (## Sheet: name) as CSV rows (util\TableImport: at most its MAX_ROWS per sheet)
	odt     OpenDocument text (content.xml): headings, paragraphs, lists, tables, notes (in brackets)
	ods     OpenDocument spreadsheet: each sheet as CSV rows (the cells' shown text; repeated rows / columns expanded, trailing empty ones left out)
	odp     OpenDocument presentation: each slide (## Slide N): its paragraphs and tables, then its notes (option notes)

    $text = \util\OfficeText::text($path, 'docx', [ 'max_chars' => 200000 ]);
    $text = \util\OfficeText::textOf($file);   a models\File (by its extension)
    \RuntimeException with a message for the user when the file cannot be read (not a document of its type, encrypted, damaged, too large)
*/
namespace util;

class OfficeText {
    //the file types read: extension => label
    const TYPES = [ 'docx' => 'Word', 'pptx' => 'PowerPoint', 'xlsx' => 'Excel', 'odt' => 'OpenDocument text', 'ods' => 'OpenDocument spreadsheet',
	'odp' => 'OpenDocument presentation' ];

    const DEFAULTS = [
	'max_chars' => 200000,	//the text is cut after this many characters (a marker says so); null: not cut
	'notes' => true,	//pptx / odp: the slides' notes
	'footnotes' => true,	//docx: the footnotes and endnotes
    ];

    //the largest XML part read (uncompressed bytes: no zip bombs)
    const MAX_PART = 64 * 1024 * 1024;
    //ods: repeated rows / cells expanded at most this many times
    const MAX_REPEAT = 1000;

    const NS = [
	'w' => 'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
	'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
	'p' => 'http://schemas.openxmlformats.org/presentationml/2006/main',
	'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
	'rel' => 'http://schemas.openxmlformats.org/package/2006/relationships',
	'mc' => 'http://schemas.openxmlformats.org/markup-compatibility/2006',
	'm' => 'http://schemas.openxmlformats.org/officeDocument/2006/math',
	'office' => 'urn:oasis:names:tc:opendocument:xmlns:office:1.0',
	'text' => 'urn:oasis:names:tc:opendocument:xmlns:text:1.0',
	'table' => 'urn:oasis:names:tc:opendocument:xmlns:table:1.0',
	'draw' => 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0',
	'presentation' => 'urn:oasis:names:tc:opendocument:xmlns:presentation:1.0',
    ];

    public static function isSupported($ext) {
	return isset(self::TYPES[strtolower((string)$ext)]);
    }

    //the text of project file $file (models\File), by its extension
    public static function textOf($file, array $options = []) {
	$ext = $file->extension();
	if (!self::isSupported($ext))
	    throw new \RuntimeException("`{$file->filename}`: not an Office document (" . implode(', ', array_keys(self::TYPES)) . ')');
	$path = tempnam(sys_get_temp_dir(), 'netflow9-office-');
	try {
	    file_put_contents($path, $file->getContents());
	    return self::text($path, $ext, $options);
	} finally {
	    @unlink($path);
	}
    }

    //the text of document $path of type $ext
    public static function text($path, $ext, array $options = []) {
	$o = $options + self::DEFAULTS;
	$ext = strtolower((string)$ext);
	if (!self::isSupported($ext))
	    throw new \RuntimeException("Not an Office document: .{$ext}");
	if ($ext === 'xlsx')
	    $text = self::xlsx($path);
	else {
	    $zip = new \ZipArchive();
	    if ($zip->open($path, \ZipArchive::RDONLY) !== true)
		throw new \RuntimeException('This is not a ' . self::TYPES[$ext] . ' document (or it is encrypted)');
	    try {
		$text = match($ext) {
		    'docx' => self::docx($zip, $o),
		    'pptx' => self::pptx($zip, $o),
		    'odt', 'ods', 'odp' => self::odf($zip, $ext, $o),
		};
	    } finally {
		$zip->close();
	    }
	}

	$text = preg_replace('/[ \t]+$/m', '', str_replace([ "\r\n", "\r", "\u{00A0}" ], [ "\n", "\n", ' ' ], $text));
	$text = trim(preg_replace('/\n{3,}/', "\n\n", $text));
	if ($o['max_chars'] !== null && mb_strlen($text) > $o['max_chars'])
	    $text = rtrim(mb_substr($text, 0, $o['max_chars'])) . "\n[... cut at {$o['max_chars']} characters]";
	return $text;
    }

    // ------------------------------------------------------------------------------------
    //the parts

    //part $name of the package as a DOM with the namespaces' XPath; null when it is not there
    private static function part(\ZipArchive $zip, $name) {
	$stat = $zip->statName($name);
	if ($stat === false)
	    return null;
	if ($stat['size'] > self::MAX_PART)
	    throw new \RuntimeException("The document is too large ({$name}: " . \util\Format::bytes($stat['size']) . ')');
	$xml = $zip->getFromName($name);
	$dom = new \DOMDocument();
	if ($xml === false || !@$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE))
	    throw new \RuntimeException("The document is damaged ({$name} cannot be read)");
	$xp = new \DOMXPath($dom);
	foreach(self::NS as $prefix => $uri)
	    $xp->registerNamespace($prefix, $uri);
	return $xp;
    }

    //[ id => target (a path in the package) ] of the relationships of part $name
    private static function rels(\ZipArchive $zip, $name) {
	$dir = dirname($name);
	$xp = self::part($zip, ($dir === '.' ? '' : $dir . '/') . '_rels/' . basename($name) . '.rels');
	$r = [];
	if (!$xp)
	    return $r;
	foreach($xp->query('/rel:Relationships/rel:Relationship') as $rel) {
	    $target = $rel->getAttribute('Target');
	    if ($rel->getAttribute('TargetMode') === 'External')
		continue;
	    $r[$rel->getAttribute('Id')] = str_starts_with($target, '/') ? ltrim($target, '/') : self::normalize(($dir === '.' ? '' : $dir . '/') . $target);
	}
	return $r;
    }

    //a/b/../c -> a/c
    private static function normalize($path) {
	$out = [];
	foreach(explode('/', $path) as $p) {
	    if ($p === '..')
		array_pop($out);
	    elseif ($p !== '.' && $p !== '')
		$out[] = $p;
	}
	return implode('/', $out);
    }

    //a table as rows | a | b |, a separator after the first one
    private static function table(array $rows) {
	$rows = array_values(array_filter($rows, fn($r) => implode('', $r) !== ''));
	if (!$rows)
	    return '';
	$lines = [];
	foreach($rows as $i => $cells) {
	    $lines[] = '| ' . implode(' | ', array_map(fn($c) => str_replace([ "\n", '|' ], [ ' ', '\\|' ], trim($c)), $cells)) . ' |';
	    if ($i === 0 && count($rows) > 1)
		$lines[] = '|' . str_repeat(' --- |', count($cells));
	}
	return implode("\n", $lines);
    }

    private static function csv(array $row) {
	return implode(',', array_map(function($v) {
	    $v = is_bool($v) ? ($v ? 'TRUE' : 'FALSE') : (string)$v;
	    return preg_match('/[",\n\r]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
	}, $row));
    }

    // ------------------------------------------------------------------------------------
    //Word

    private static function docx(\ZipArchive $zip, array $o) {
	$xp = self::part($zip, 'word/document.xml');
	if (!$xp)
	    throw new \RuntimeException('This is not a Word document');
	$levels = self::docxHeadingLevels($zip);
	$body = $xp->query('/w:document/w:body')->item(0);
	$out = $body ? self::docxBlocks($xp, $body, $levels) : [];

	if ($o['footnotes'])
	    foreach([ 'word/footnotes.xml' => 'footnote', 'word/endnotes.xml' => 'endnote' ] as $name => $tag) {
		$fx = self::part($zip, $name);
		if (!$fx)
		    continue;
		$notes = [];
		foreach($fx->query("/w:{$tag}s/w:{$tag}[not(@w:type) or @w:type='normal']") as $note) {
		    $text = trim(implode(' ', self::docxBlocks($fx, $note, $levels)));
		    if ($text !== '')
			$notes[] = '[' . $note->getAttributeNS(self::NS['w'], 'id') . '] ' . $text;
		}
		if ($notes)
		    $out[] = "\n" . ucfirst($tag) . "s:\n" . implode("\n", $notes);
	    }
	return implode("\n", $out);
    }

    //[ style id => heading level ] (Title: 1; heading N, or the style's outline level)
    private static function docxHeadingLevels(\ZipArchive $zip) {
	$xp = self::part($zip, 'word/styles.xml');
	$levels = [];
	if (!$xp)
	    return $levels;
	foreach($xp->query('/w:styles/w:style[@w:type="paragraph"]') as $s) {
	    $id = $s->getAttributeNS(self::NS['w'], 'styleId');
	    $name = strtolower((string)$xp->evaluate('string(w:name/@w:val)', $s));
	    $outline = (string)$xp->evaluate('string(w:pPr/w:outlineLvl/@w:val)', $s);
	    if ($name === 'title')
		$levels[$id] = 1;
	    elseif (preg_match('/^heading ([1-6])$/', $name, $m))
		$levels[$id] = (int)$m[1];
	    elseif ($outline !== '' && (int)$outline < 6)
		$levels[$id] = (int)$outline + 1;
	}
	return $levels;
    }

    //the paragraphs and tables in $node (body, a cell, a note, a content control): lines
    private static function docxBlocks(\DOMXPath $xp, \DOMNode $node, array $levels) {
	$out = [];
	foreach($node->childNodes as $c) {
	    if (!$c instanceof \DOMElement || $c->namespaceURI !== self::NS['w'])
		continue;
	    switch($c->localName) {
		case 'p':
		    $text = self::docxParagraph($xp, $c);
		    $style = (string)$xp->evaluate('string(w:pPr/w:pStyle/@w:val)', $c);
		    if ($text !== '' && isset($levels[$style]))
			$text = str_repeat('#', $levels[$style]) . ' ' . $text;
		    elseif ($text !== '' && $xp->query('w:pPr/w:numPr', $c)->length)
			$text = str_repeat('  ', (int)$xp->evaluate('string(w:pPr/w:numPr/w:ilvl/@w:val)', $c)) . '- ' . $text;
		    $out[] = $text;
		    break;
		case 'tbl':
		    $rows = [];
		    foreach($xp->query('w:tr', $c) as $tr) {
			$cells = [];
			foreach($xp->query('w:tc|w:sdt/w:sdtContent/w:tc', $tr) as $tc)
			    $cells[] = implode(' ', array_filter(self::docxBlocks($xp, $tc, []), fn($t) => $t !== ''));
			$rows[] = $cells;
		    }
		    $out[] = self::table($rows);
		    break;
		case 'sdt':
		    foreach($xp->query('w:sdtContent', $c) as $content)
			array_push($out, ...self::docxBlocks($xp, $content, $levels));
		    break;
		case 'customXml':
		case 'ins':
		    array_push($out, ...self::docxBlocks($xp, $c, $levels));
		    break;
	    }
	}
	return $out;
    }

    //the text of paragraph $p: its runs (text, tabs, breaks; equations' text), text boxes after it; not deleted text, field codes or alternate content's fallbacks
    private static function docxParagraph(\DOMXPath $xp, \DOMElement $p) {
	$text = '';
	$skip = 'not(ancestor::w:del) and not(ancestor::mc:Fallback)';
	foreach($xp->query(".//w:t[{$skip}] | .//m:t[{$skip}] | .//w:tab[{$skip} and not(ancestor::w:pPr)] | .//w:br[{$skip}] | .//w:cr[{$skip}] | .//w:noBreakHyphen[{$skip}]", $p) as $n) {
	    //the text of a text box comes after the paragraph's
	    if (self::inTextBox($n, $p))
		continue;
	    $text .= match($n->localName) {
		't' => $n->textContent,
		'tab' => "\t",
		'br', 'cr' => "\n",
		'noBreakHyphen' => '-',
	    };
	}
	//text boxes (not their fallbacks)
	foreach($xp->query('.//w:txbxContent[not(ancestor::mc:Fallback)]', $p) as $box) {
	    if (self::inTextBox($box, $p))
		continue;
	    $inner = trim(implode("\n", array_filter(self::docxBlocks($xp, $box, []), fn($t) => $t !== '')));
	    if ($inner !== '')
		$text .= ($text === '' ? '' : "\n") . $inner;
	}
	return trim($text, " ");
    }

    //whether $n is in a text box of paragraph $p (between them; not a text box $p is in)
    private static function inTextBox(\DOMNode $n, \DOMElement $p) {
	for ($a = $n->parentNode; $a && $a !== $p; $a = $a->parentNode)
	    if ($a->localName === 'txbxContent' && $a->namespaceURI === self::NS['w'])
		return true;
	return false;
    }

    // ------------------------------------------------------------------------------------
    //PowerPoint

    private static function pptx(\ZipArchive $zip, array $o) {
	$xp = self::part($zip, 'ppt/presentation.xml');
	if (!$xp)
	    throw new \RuntimeException('This is not a PowerPoint presentation');
	$rels = self::rels($zip, 'ppt/presentation.xml');
	$out = [];
	$n = 0;
	foreach($xp->query('/p:presentation/p:sldIdLst/p:sldId') as $sld) {
	    $target = $rels[$sld->getAttributeNS(self::NS['r'], 'id')] ?? null;
	    $sx = $target ? self::part($zip, $target) : null;
	    if (!$sx)
		continue;
	    $n++;
	    $lines = self::drawingText($sx, $sx->query('/p:sld/p:cSld/p:spTree')->item(0));
	    if ($o['notes']) {
		foreach(self::rels($zip, $target) as $t)
		    if (str_contains($t, 'notesSlide')) {
			$nx = self::part($zip, $t);
			//the notes' body (not the slide's picture or its number)
			$notes = $nx ? self::drawingText($nx, $nx->query('/p:notes/p:cSld/p:spTree')->item(0), true) : [];
			if ($notes)
			    $lines[] = "Notes:\n" . implode("\n", $notes);
		    }
	    }
	    $out[] = "## Slide {$n}\n" . implode("\n", $lines);
	}
	return implode("\n\n", $out);
    }

    //the lines of a slide's shapes (DrawingML): paragraphs, tables; $body: only the body placeholders (notes)
    private static function drawingText(\DOMXPath $xp, ?\DOMNode $tree, $body = false) {
	if (!$tree)
	    return [];
	$out = [];
	$shapes = $body ? $xp->query('.//p:sp[p:nvSpPr/p:nvPr/p:ph[@type="body"]]', $tree) : [ $tree ];
	foreach($shapes as $shape)
	    foreach($xp->query('.//a:p[not(ancestor::a:tbl) and not(ancestor::mc:Fallback)] | .//a:tbl[not(ancestor::mc:Fallback)]', $shape) as $n) {
		if ($n->localName === 'tbl') {
		    $rows = [];
		    foreach($xp->query('a:tr', $n) as $tr) {
			$cells = [];
			foreach($xp->query('a:tc', $tr) as $tc)
			    $cells[] = implode(' ', array_map(fn($p) => self::drawingParagraph($xp, $p), iterator_to_array($xp->query('.//a:p', $tc))));
			$rows[] = $cells;
		    }
		    $out[] = self::table($rows);
		    continue;
		}
		$text = self::drawingParagraph($xp, $n);
		if ($text !== '')
		    $out[] = ($xp->query('a:pPr[@lvl > 0]', $n)->length ? str_repeat('  ', (int)$xp->evaluate('number(a:pPr/@lvl)', $n)) : '') . $text;
	    }
	return $out;
    }

    private static function drawingParagraph(\DOMXPath $xp, \DOMElement $p) {
	$text = '';
	foreach($xp->query('a:r/a:t | a:fld/a:t | a:br', $p) as $n)
	    $text .= $n->localName === 'br' ? "\n" : $n->textContent;
	return trim($text);
    }

    // ------------------------------------------------------------------------------------
    //Excel

    private static function xlsx($path) {
	$t = new \util\TableImport($path, 'xlsx');
	$out = [];
	foreach($t->sheets() as $name) {
	    $rows = $t->rows($name, false);
	    //the rows without their trailing empty cells (TableImport makes them as long as the longest)
	    $lines = array_map(function($row) {
		while ($row && end($row) === '')
		    array_pop($row);
		return self::csv($row);
	    }, array_slice($rows, 0, \util\TableImport::MAX_ROWS));
	    if (count($rows) > \util\TableImport::MAX_ROWS)
		$lines[] = '[... more rows: the first ' . \util\TableImport::MAX_ROWS . ' are shown]';
	    $out[] = "## Sheet: {$name}\n" . implode("\n", $lines);
	}
	return implode("\n\n", $out);
    }

    // ------------------------------------------------------------------------------------
    //OpenDocument

    private static function odf(\ZipArchive $zip, $ext, array $o) {
	$xp = self::part($zip, 'content.xml');
	if (!$xp)
	    throw new \RuntimeException('This is not an ' . self::TYPES[$ext] . ' document (or it is encrypted)');
	switch($ext) {
	    case 'odt':
		$body = $xp->query('/office:document-content/office:body/office:text')->item(0);
		return $body ? implode("\n", self::odfBlocks($xp, $body)) : '';
	    case 'ods':
		$out = [];
		foreach($xp->query('/office:document-content/office:body/office:spreadsheet/table:table') as $table) {
		    $lines = array_map([ self::class, 'csv' ], self::odfRows($xp, $table, true));
		    $out[] = '## Sheet: ' . $table->getAttributeNS(self::NS['table'], 'name') . "\n" . implode("\n", $lines);
		}
		return implode("\n\n", $out);
	    case 'odp':
		$out = [];
		foreach($xp->query('/office:document-content/office:body/office:presentation/draw:page') as $i => $page) {
		    $lines = [];
		    foreach($xp->query('.//draw:text-box | .//table:table', $page) as $n) {
			if ($xp->query('ancestor::presentation:notes', $n)->length || $xp->query('ancestor::draw:text-box|ancestor::table:table', $n)->length)
			    continue;
			array_push($lines, ...($n->localName === 'table' ? [ self::table(self::odfRows($xp, $n, false)) ] : self::odfBlocks($xp, $n)));
		    }
		    if ($o['notes']) {
			$notes = [];
			foreach($xp->query('presentation:notes//draw:text-box', $page) as $box)
			    array_push($notes, ...array_filter(self::odfBlocks($xp, $box), fn($t) => $t !== ''));
			if ($notes)
			    $lines[] = "Notes:\n" . implode("\n", $notes);
		    }
		    $out[] = '## Slide ' . ($i + 1) . "\n" . implode("\n", $lines);
		}
		return implode("\n\n", $out);
	}
	return '';
    }

    //the blocks in $node: headings (#), paragraphs, list items (- ), tables, sections
    private static function odfBlocks(\DOMXPath $xp, \DOMNode $node, $depth = 0) {
	$out = [];
	foreach($node->childNodes as $c) {
	    if (!$c instanceof \DOMElement)
		continue;
	    $tag = $c->namespaceURI === self::NS['text'] ? 'text:' . $c->localName : ($c->namespaceURI === self::NS['table'] ? 'table:' . $c->localName : $c->localName);
	    switch($tag) {
		case 'text:h':
		    $text = self::odfInline($c);
		    if ($text !== '')
			$out[] = str_repeat('#', max(1, min(6, (int)($c->getAttributeNS(self::NS['text'], 'outline-level') ?: 1)))) . ' ' . $text;
		    break;
		case 'text:p':
		    $out[] = self::odfInline($c);
		    break;
		case 'text:list':
		    foreach($c->childNodes as $item) {
			if (!$item instanceof \DOMElement || $item->localName !== 'list-item' && $item->localName !== 'list-header')
			    continue;
			$lines = array_values(array_filter(self::odfBlocks($xp, $item, $depth + 1), fn($t) => $t !== ''));
			foreach($lines as $k => $line)
			    $out[] = ($k === 0 && !str_starts_with($line, '  ') && !str_starts_with($line, '- ') ? str_repeat('  ', $depth) . '- ' : '') . $line;
		    }
		    break;
		case 'table:table':
		    $out[] = self::table(self::odfRows($xp, $c, false));
		    break;
		case 'text:section':
		case 'text:index-body':
		case 'table:table-cell':
		    array_push($out, ...self::odfBlocks($xp, $c, $depth));
		    break;
		case 'text:tracked-changes':
		case 'text:sequence-decls':
		    break;
		default:
		    //frames and text boxes inside the body
		    if ($c->localName === 'frame' || $c->localName === 'text-box')
			array_push($out, ...self::odfBlocks($xp, $c, $depth));
	    }
	}
	return $out;
    }

    //the text of a paragraph: its spans and links, spaces (text:s), tabs, line breaks, notes in brackets; not annotations or deleted changes
    private static function odfInline(\DOMNode $node) {
	$text = '';
	foreach($node->childNodes as $c) {
	    if ($c instanceof \DOMText) {
		$text .= $c->data;
		continue;
	    }
	    if (!$c instanceof \DOMElement)
		continue;
	    switch($c->localName) {
		case 's':
		    $text .= str_repeat(' ', max(1, min(100, (int)($c->getAttributeNS(self::NS['text'], 'c') ?: 1))));
		    break;
		case 'tab':
		    $text .= "\t";
		    break;
		case 'line-break':
		    $text .= "\n";
		    break;
		case 'note':
		    foreach($c->childNodes as $part)
			if ($part instanceof \DOMElement && $part->localName === 'note-body')
			    $text .= ' [' . trim(implode(' ', array_map(fn($p) => self::odfInline($p), iterator_to_array($part->childNodes)))) . ']';
		    break;
		case 'annotation':
		case 'annotation-end':
		case 'change':
		case 'change-start':
		case 'change-end':
		case 'bookmark':
		case 'bookmark-start':
		case 'bookmark-end':
		    break;
		default:
		    $text .= self::odfInline($c);
	    }
	}
	return trim($text, ' ');
    }

    //the rows of a table: the cells' shown text (their paragraphs), repeated rows / cells expanded, trailing empty cells and rows left out; $values: booleans as booleans
    private static function odfRows(\DOMXPath $xp, \DOMElement $table, $values) {
	$rows = [];
	foreach($xp->query('table:table-row | table:table-header-rows/table:table-row | table:table-row-group/table:table-row | table:table-rows/table:table-row', $table) as $tr) {
	    $cells = [];
	    foreach($tr->childNodes as $tc) {
		if (!$tc instanceof \DOMElement || ($tc->localName !== 'table-cell' && $tc->localName !== 'covered-table-cell'))
		    continue;
		$parts = [];
		foreach($tc->childNodes as $p)
		    if ($p instanceof \DOMElement && ($p->localName === 'p' || $p->localName === 'h'))
			$parts[] = self::odfInline($p);
		$v = implode("\n", $parts);
		if ($v === '' && $tc->hasAttributeNS(self::NS['office'], 'value'))
		    $v = $tc->getAttributeNS(self::NS['office'], 'value');
		if ($values && $tc->getAttributeNS(self::NS['office'], 'value-type') === 'boolean')
		    $v = $tc->getAttributeNS(self::NS['office'], 'boolean-value') === 'true';
		$repeat = max(1, (int)($tc->getAttributeNS(self::NS['table'], 'number-columns-repeated') ?: 1));
		for ($k = 0; $k < min($repeat, self::MAX_REPEAT); $k++)
		    $cells[] = $v;
	    }
	    while ($cells && end($cells) === '')
		array_pop($cells);
	    $repeat = max(1, (int)($tr->getAttributeNS(self::NS['table'], 'number-rows-repeated') ?: 1));
	    for ($k = 0; $k < min($repeat, self::MAX_REPEAT); $k++)
		$rows[] = $cells;
	}
	while ($rows && end($rows) === [])
	    array_pop($rows);
	return $rows;
    }
}
