<?php

/*
    an email made of blocks, one column, stored as JSON in the config of the steps that send email;

    {
	preheader,
	blocks: [ { type: html, html } | { type: image, file, alt, width, align, href } ]
    }

    html    rich text (the designer's editor), cleaned to the tags / attributes / styles email clients render (TAGS, STYLES);
	may hold {{ }} expressions
    image   a project File (an image), embedded in the email (cid:);
	width in px (at most WIDTH, the email's width), align left / center / right, href: a link (may hold {{ }})

    schema($label)        the config field (for configSchema(); the designer is the smartform field type `emaildesigner`)
    normalize($email)     the email as stored: known blocks and properties only, the HTML cleaned
    errors($email)        what is wrong with it: [ message ]

    util\EmailHtml turns it into the HTML / text sent (responsive, inline styles, Outlook-safe)

*/
namespace models;

class EmailBlocks {
    //the email's width (px): the column of the HTML; images at most as wide
    const WIDTH = 600;
    const MAX_BLOCKS = 50;
    const MAX_HTML = 100000;
    const ALIGN = [ 'left', 'center', 'right' ];

    //tags kept in an HTML block => their attributes (others are unwrapped: their text stays)
    const TAGS = [
	'p' => [ 'style' ],
	'div' => [ 'style' ],
	'br' => [],
	'span' => [ 'style' ],
	'strong' => [],
	'b' => [],
	'em' => [],
	'i' => [],
	'u' => [],
	's' => [],
	'h1' => [ 'style' ],
	'h2' => [ 'style' ],
	'h3' => [ 'style' ],
	'ul' => [],
	'ol' => [],
	'li' => [ 'style' ],
	'blockquote' => [],
	'a' => [ 'href', 'title' ],
    ];

    //style properties kept (others dropped)
    const STYLES = [ 'color', 'background-color', 'text-align', 'font-weight', 'font-style', 'text-decoration' ];

    //the config field holding the email (the designer edits it)
    public static function schema(array $field = []) {
	return array_merge([
	    'key' => 'email',
	    'type' => 'array',
	    'label' => 'Message',
	    'required' => true,
	    'default' => [ 'preheader' => '', 'blocks' => [] ],
	    'ui' => [ 'type' => 'emaildesigner' ],
	], $field);
    }

    public static function normalize($email) {
	$email = json_decode(\util\JSON::encode($email), true);
	$email = is_array($email) ? $email : [];
	$out = [ 'preheader' => mb_substr(trim(is_scalar($email['preheader'] ?? null) ? (string)$email['preheader'] : ''), 0, 300), 'blocks' => [] ];
	foreach(array_slice(is_array($email['blocks'] ?? null) ? array_values($email['blocks']) : [], 0, self::MAX_BLOCKS) as $b) {
	    if (!is_array($b))
		continue;
	    switch($b['type'] ?? '') {
		case 'html':
		    $out['blocks'][] = [ 'type' => 'html', 'html' => self::clean(mb_substr(is_string($b['html'] ?? null) ? $b['html'] : '', 0, self::MAX_HTML)) ];
		    break;
		case 'image':
		    $file = is_string($b['file'] ?? null) && \util\Validator::rule('oid', $b['file']) ? $b['file'] : '';
		    $width = is_numeric($b['width'] ?? null) ? max(16, min(self::WIDTH, (int)$b['width'])) : self::WIDTH;
		    $out['blocks'][] = [ 'type' => 'image', 'file' => $file, 'alt' => mb_substr(trim((string)($b['alt'] ?? '')), 0, 300), 'width' => $width,
			'align' => in_array($b['align'] ?? '', self::ALIGN, true) ? $b['align'] : 'center', 'href' => mb_substr(trim((string)($b['href'] ?? '')), 0, 2000) ];
		    break;
	    }
	}
	return $out;
    }

    public static function errors(array $email) {
	$errors = [];
	if (empty($email['blocks']))
	    $errors[] = 'add at least one block';
	foreach($email['blocks'] ?? [] as $i => $b) {
	    $n = $i + 1;
	    if ($b['type'] === 'image' && $b['file'] === '')
		$errors[] = "block {$n}: choose an image";
	    if ($b['type'] === 'html' && trim(strip_tags($b['html'])) === '')
		$errors[] = "block {$n}: the text is empty";
	    if ($b['type'] === 'image' && $b['href'] !== '' && !str_contains($b['href'], \sys\J\Template::OPEN) && !preg_match('~^(https?://|mailto:)~i', $b['href']))
		$errors[] = "block {$n}: the link must start with https://, http:// or mailto:";
	}
	return $errors;
    }

    //an HTML block's markup reduced to TAGS / their attributes / STYLES; links only http(s), mailto or an expression; the text (and {{ }}) kept
    public static function clean($html) {
	if (trim($html) === '')
	    return '';
	$doc = new \DOMDocument();
	libxml_use_internal_errors(true);
	$doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
	libxml_clear_errors();
	$root = $doc->getElementById('__root');
	if (!$root)
	    return '';
	self::cleanChildren($root);
	$out = '';
	foreach($root->childNodes as $c)
	    $out .= $doc->saveHTML($c);
	//an expression in a link (href="{{ ... }}") comes back URL-encoded from saveHTML: restored
	$out = preg_replace_callback('/%7B%7B(.*?)%7D%7D/i', fn($m) => '{{' . rawurldecode($m[1]) . '}}', $out);
	return trim($out);
    }

    private static function cleanChildren(\DOMNode $node) {
	foreach(iterator_to_array($node->childNodes) as $c) {
	    if ($c instanceof \DOMText)
		continue;
	    if (!($c instanceof \DOMElement)) {
		$node->removeChild($c);
		continue;
	    }
	    $tag = strtolower($c->tagName);
	    if (in_array($tag, [ 'script', 'style', 'iframe', 'object', 'embed', 'head', 'title', 'meta', 'link' ], true)) {
		$node->removeChild($c);
		continue;
	    }
	    self::cleanChildren($c);
	    if (!isset(self::TAGS[$tag])) {
		//unwrapped: its children take its place
		while ($c->firstChild)
		    $node->insertBefore($c->firstChild, $c);
		$node->removeChild($c);
		continue;
	    }
	    foreach(iterator_to_array($c->attributes) as $a) {
		$name = strtolower($a->name);
		if (!in_array($name, self::TAGS[$tag], true)) {
		    $c->removeAttribute($a->name);
		} elseif ($name === 'style') {
		    $kept = [];
		    foreach(explode(';', $a->value) as $decl) {
			[ $prop, $val ] = array_map('trim', explode(':', $decl, 2) + [ 1 => '' ]);
			if (in_array(strtolower($prop), self::STYLES, true) && $val !== '' && !preg_match('/url\s*\(|expression\s*\(|[<>"]/i', $val))
			    $kept[] = strtolower($prop) . ': ' . $val;
		    }
		    if ($kept)
			$c->setAttribute('style', implode('; ', $kept));
		    else
			$c->removeAttribute('style');
		} elseif ($name === 'href') {
		    $href = trim($a->value);
		    if (!preg_match('~^(https?://|mailto:)~i', $href) && !str_starts_with($href, \sys\J\Template::OPEN))
			$c->removeAttribute('href');
		}
	    }
	}
    }
}
