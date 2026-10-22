<?php

/*
    CLD: 2026-10-23T02:30:00+03:00: new: HTML of unknown origin (e.g. an AI step's `html`, a web page's) cleaned to formatting only, for an email (util\EmailHtml):
	\util\HtmlSafe::clean($html)
    Kept: the tags of FORMATTING (their text, nothing else of them); links' href only http(s) / mailto (and title), ol start, table cells' align / colspan / rowspan.
    Dropped with their content: DROPPED (scripts, styles, frames, forms, media...). Other tags: their content stays (the tag goes). Every other attribute goes (style, class, on*, ...);
    images go too (no remote images in an email: tracking)
*/
namespace util;

class HtmlSafe {
    const FORMATTING = [ 'p', 'br', 'div', 'span', 'strong', 'b', 'em', 'i', 'u', 'del', 's', 'code', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote',
	'a', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr' ];
    const DROPPED = [ 'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select', 'textarea', 'head', 'title', 'meta', 'link',
	'base', 'noscript', 'template', 'svg', 'math', 'video', 'audio', 'source', 'track', 'canvas', 'img', 'picture' ];
    //the attributes kept, per tag
    const ATTRIBUTES = [ 'a' => [ 'href', 'title' ], 'ol' => [ 'start' ], 'th' => [ 'align', 'colspan', 'rowspan' ], 'td' => [ 'align', 'colspan', 'rowspan' ] ];
    //the longest HTML cleaned (bytes); the rest is cut
    const MAX_LENGTH = 500000;

    //$html with formatting only
    public static function clean($html) {
	$html = substr(\util\Expression::toText($html), 0, self::MAX_LENGTH);
	if (trim($html) === '')
	    return '';
	//a < that starts no tag is text (the parser would drop what follows: "a < b")
	$html = preg_replace('/<(?![a-zA-Z\/!?])/', '&lt;', $html);
	$doc = new \DOMDocument();
	libxml_use_internal_errors(true);
	$doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
	libxml_clear_errors();
	$root = $doc->getElementById('__root');
	if (!$root)
	    return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	self::cleanChildren($root);
	$out = '';
	foreach($root->childNodes as $c)
	    $out .= $doc->saveHTML($c);
	return $out;
    }

    private static function cleanChildren(\DOMNode $node) {
	foreach(iterator_to_array($node->childNodes) as $c) {
	    if ($c instanceof \DOMText)
		continue;
	    if (!$c instanceof \DOMElement) {
		//comments, processing instructions, CDATA
		$node->removeChild($c);
		continue;
	    }
	    $tag = strtolower($c->tagName);
	    if (in_array($tag, self::DROPPED, true)) {
		$node->removeChild($c);
		continue;
	    }
	    self::cleanChildren($c);
	    if (!in_array($tag, self::FORMATTING, true)) {
		//the tag goes, its content stays
		while($c->firstChild)
		    $node->insertBefore($c->firstChild, $c);
		$node->removeChild($c);
		continue;
	    }
	    $keep = self::ATTRIBUTES[$tag] ?? [];
	    foreach(iterator_to_array($c->attributes) as $a) {
		$name = strtolower($a->name);
		if (!in_array($name, $keep, true))
		    $c->removeAttribute($a->name);
	    }
	    if ($tag === 'a' && $c->hasAttribute('href') && !preg_match('~^(https?://|mailto:)~i', trim($c->getAttribute('href'))))
		$c->removeAttribute('href');
	    foreach([ 'start', 'colspan', 'rowspan' ] as $n)
		if ($c->hasAttribute($n) && !ctype_digit($c->getAttribute($n)))
		    $c->removeAttribute($n);
	    if ($c->hasAttribute('align') && !in_array(strtolower($c->getAttribute('align')), [ 'left', 'center', 'right' ], true))
		$c->removeAttribute('align');
	}
    }
}
