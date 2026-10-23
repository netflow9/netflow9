<?php

/*
    the conversion of an email made of blocks (models\EmailBlocks) into what is sent
	the step renders, util\EmailSend sends).
    Readable in Outlook (Word engine), Gmail, Yahoo, Apple Mail and the iOS / Android apps:
	- tables for the layout (no flex / grid), one column, fluid up to EmailBlocks::WIDTH px (max-width), a fixed-width table for Outlook (<!--[if mso]>)
	- inline styles on every element (many clients drop <style>); <style> only adds the phone tweaks (@media) where it is kept
	- images embedded (cid:, util\EmailSend attaches them), with width, alt, display:block, border:0
	- a hidden preheader (the line shown after the subject in the inbox)
	- a plain-text version (multipart/alternative)

    $r = \util\EmailHtml::render($email, $vars, $subject);  ->  [ html, text, images: [ cid => file id ] ]
	{{ }} in the HTML blocks, image links and the preheader are evaluated with $vars (util\Expression) and HTML-escaped;
	links are checked again once filled (only http(s) / mailto).
	//CLD: 2026-10-23T01:00:00+03:00: except, in an HTML block, a {{ }} that is only a call of markdown(...): its text as HTML (util\Markdown: safe, everything of the text escaped),
	//styled as the block's own tags (headings, lists, tables, code, ...); its text version as plain text
	//CLD: 2026-10-23T02:30:00+03:00: and a {{ }} that is only a field named html (e.g. {{ ai.html }}: an AI step's answer as HTML): its HTML cleaned to formatting only
	//(util\HtmlSafe: whatever made it), then styled as the block's tags

    Throws on an expression that fails (as the steps' other settings)
*/
namespace util;

class EmailHtml {
    const FONT = 'Arial, Helvetica, sans-serif';
    const PADDING = 32;

    //inline styles of the HTML blocks' tags (a block's own style comes after: its color / alignment win)
    const TAG_STYLES = [
	'p' => 'margin: 0 0 16px 0;',
	'div' => 'margin: 0;',
	'h1' => 'margin: 0 0 16px 0; font-size: 28px; line-height: 1.25; font-weight: bold;',
	'h2' => 'margin: 0 0 14px 0; font-size: 22px; line-height: 1.3; font-weight: bold;',
	'h3' => 'margin: 0 0 12px 0; font-size: 18px; line-height: 1.35; font-weight: bold;',
	'ul' => 'margin: 0 0 16px 0; padding: 0 0 0 24px;',
	'ol' => 'margin: 0 0 16px 0; padding: 0 0 0 24px;',
	'li' => 'margin: 0 0 6px 0;',
	'blockquote' => 'margin: 0 0 16px 0; padding: 0 0 0 12px; border-left: 3px solid #d0d5dd; color: #555555;',
	'a' => 'color: #1a73e8; text-decoration: underline;',
	//CLD: 2026-10-23T01:00:00+03:00: + what markdown(...) gives (util\Markdown)
	'h4' => 'margin: 0 0 10px 0; font-size: 16px; line-height: 1.4; font-weight: bold;',
	'h5' => 'margin: 0 0 8px 0; font-size: 14px; line-height: 1.4; font-weight: bold;',
	'h6' => 'margin: 0 0 8px 0; font-size: 13px; line-height: 1.4; font-weight: bold; color: #555555;',
	'hr' => 'border: 0; border-top: 1px solid #d0d5dd; margin: 20px 0; height: 0;',
	'code' => 'font-family: Consolas, Menlo, Monaco, monospace; font-size: 14px; background-color: #f2f3f5; padding: 1px 4px;',
	'pre' => 'margin: 0 0 16px 0; padding: 12px; background-color: #f2f3f5; font-family: Consolas, Menlo, Monaco, monospace; font-size: 13px; line-height: 1.45; white-space: pre-wrap; word-wrap: break-word;',
	'table' => 'border-collapse: collapse; margin: 0 0 16px 0; width: 100%;',
	'th' => 'border: 1px solid #d0d5dd; padding: 6px 10px; background-color: #f2f3f5; font-weight: bold; text-align: left;',
	'td' => 'border: 1px solid #d0d5dd; padding: 6px 10px; vertical-align: top;',
	'del' => 'color: #777777;',
    ];

    public static function render(array $email, array $vars, $subject = '') {
	$email = \models\EmailBlocks::normalize($email);
	$images = [];
	$rows = '';
	$text = [];
	$inner = \models\EmailBlocks::WIDTH - 2 * self::PADDING;
	foreach($email['blocks'] as $b) {
	    if ($b['type'] === 'html') {
		$html = self::style(self::fill($b['html'], $vars));
		$rows .= '<tr><td class="netflow9-pad" style="padding: 16px ' . self::PADDING . 'px; font-family: ' . self::FONT . '; font-size: 16px; line-height: 1.5; color: #222222; '
		    . 'mso-line-height-rule: exactly;">' . $html . '</td></tr>' . "\n";
		$text[] = self::text($html);
	    } elseif ($b['type'] === 'image' && $b['file'] !== '') {
		$cid = 'img' . (count($images) + 1) . '.' . bin2hex(random_bytes(4)) . '@netflow9';
		$images[$cid] = $b['file'];
		$full = $b['width'] >= \models\EmailBlocks::WIDTH;
		$width = $full ? \models\EmailBlocks::WIDTH : min($b['width'], $inner);
		$alt = htmlspecialchars($b['alt'], ENT_QUOTES | ENT_HTML5);
		$img = '<img src="cid:' . $cid . '" width="' . $width . '" alt="' . $alt . '" class="netflow9-img" style="display: block; width: 100%; max-width: ' . $width . 'px; height: auto; '
		    . 'border: 0; outline: none; text-decoration: none;' . ($b['align'] === 'center' ? ' margin: 0 auto;' : ($b['align'] === 'right' ? ' margin: 0 0 0 auto;' : '')) . '">';
		$href = self::link(self::fillText($b['href'], $vars));
		if ($href !== '')
		    $img = '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5) . '" style="display: block;">' . $img . '</a>';
		$rows .= '<tr><td align="' . $b['align'] . '" class="' . ($full ? '' : 'netflow9-pad') . '" style="padding: ' . ($full ? '0' : '8px ' . self::PADDING . 'px') . ';">' . $img . '</td></tr>' . "\n";
		if ($b['alt'] !== '')
		    $text[] = '[' . $b['alt'] . ']' . ($href !== '' ? " ({$href})" : '');
	    }
	}

	$preheader = htmlspecialchars(self::fillText($email['preheader'], $vars), ENT_QUOTES | ENT_HTML5);
	$title = htmlspecialchars((string)$subject, ENT_QUOTES | ENT_HTML5);
	$w = \models\EmailBlocks::WIDTH;
	$html = <<<HTML
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="en">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
<title>{$title}</title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
<style type="text/css">
body { margin: 0; padding: 0; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
table, td { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
img { -ms-interpolation-mode: bicubic; }
@media only screen and (max-width: 620px) {
  .netflow9-pad { padding-left: 16px !important; padding-right: 16px !important; }
  .netflow9-img { width: 100% !important; height: auto !important; }
}
</style>
</head>
<body style="margin: 0; padding: 0; background-color: #f2f3f5;">
<div style="display: none; font-size: 1px; line-height: 1px; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all;">{$preheader}&#8203;&nbsp;&#8203;&nbsp;&#8203;&nbsp;&#8203;&nbsp;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f2f3f5;">
<tr><td align="center" style="padding: 24px 8px;">
<!--[if mso]><table role="presentation" width="{$w}" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: {$w}px; background-color: #ffffff; margin: 0 auto;">
{$rows}</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</body>
</html>
HTML;
	return [ 'html' => $html, 'text' => trim(implode("\n\n", array_filter($text, fn($t) => $t !== ''))) . "\n", 'images' => $images ];
    }

    //the {{ }} of HTML $html evaluated, each value HTML-escaped (the expression's own text is HTML: entities decoded first)
    public static function fill($html, array $vars) {
	$out = '';
	$at = 0;
	foreach(\sys\J\Template::parts($html) as $p) {
	    if (!$p['closed'])
		break;
	    $code = html_entity_decode(substr($html, $p['start'], $p['end'] - $p['start']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	    //CLD: 2026-10-23T01:00:00+03:00: {{ markdown(x) }}: x as HTML (util\Markdown makes it safe), not escaped
	    $md = array_key_exists('markdown', $vars) ? null : self::markdownArgument($code);
	    if ($md !== null) {
		$value = \util\Expression::render(\sys\J\Template::OPEN . $md . \sys\J\Template::CLOSE, $vars);
		$out .= substr($html, $at, $p['open'] - $at) . \util\Markdown::toHtml($value);
		$at = $p['end'] + strlen(\sys\J\Template::CLOSE);
		continue;
	    }
	    $value = \util\Expression::render(\sys\J\Template::OPEN . $code . \sys\J\Template::CLOSE, $vars);
	    //CLD: 2026-10-23T02:30:00+03:00: {{ x.html }}: HTML, cleaned (util\HtmlSafe), not escaped
	    if (self::isHtmlField($code)) {
		$out .= substr($html, $at, $p['open'] - $at) . \util\HtmlSafe::clean($value);
		$at = $p['end'] + strlen(\sys\J\Template::CLOSE);
		continue;
	    }
	    $out .= substr($html, $at, $p['open'] - $at) . htmlspecialchars(\util\Expression::toText($value), ENT_QUOTES | ENT_HTML5);
	    $at = $p['end'] + strlen(\sys\J\Template::CLOSE);
	}
	return $out . substr($html, $at);
    }

    //CLD: 2026-10-23T01:00:00+03:00: when expression $code is only a call of markdown() with one argument (the parsed expression, not the text: `markdown(a) + b` is not),
    //the argument's code; else null (fill() does not ask when a variable named markdown hides the function)
    public static function markdownArgument($code) {
	if (!str_contains($code, 'markdown'))
	    return null;
	$parsed = \sys\J\Parser::parse($code, [ 'mode' => 'expression' ]);
	if ($parsed->hasErrors())
	    return null;
	$e = $parsed->ast['body'][0]['expression'] ?? null;
	if (($e['type'] ?? '') !== 'CallExpression' || ($e['callee']['type'] ?? '') !== 'Identifier' || ($e['callee']['name'] ?? '') !== 'markdown' || count($e['arguments'] ?? []) !== 1)
	    return null;
	$arg = $e['arguments'][0];
	return substr($code, (int)$arg['start'], (int)$arg['end'] - (int)$arg['start']);
    }

    //CLD: 2026-10-23T02:30:00+03:00: whether expression $code is only a field named html (`ai.html`, `page.result.html`, `x?.html`; not a call, not `"<b>" + x.html`)
    public static function isHtmlField($code) {
	if (!str_contains($code, 'html'))
	    return false;
	$parsed = \sys\J\Parser::parse($code, [ 'mode' => 'expression' ]);
	if ($parsed->hasErrors())
	    return false;
	$e = $parsed->ast['body'][0]['expression'] ?? null;
	if (($e['type'] ?? '') === 'ChainExpression')
	    $e = $e['expression'] ?? null;
	return ($e['type'] ?? '') === 'MemberExpression' && empty($e['computed']) && ($e['property']['type'] ?? '') === 'Identifier' && ($e['property']['name'] ?? '') === 'html';
    }

    //plain text with {{ }} evaluated (not escaped: the caller escapes where it is HTML)
    public static function fillText($text, array $vars) {
	return trim(\util\Expression::toText(\util\Expression::render((string)$text, $vars)));
    }

    //a link once filled: http(s) / mailto only, else none
    private static function link($href) {
	return preg_match('~^(https?://|mailto:)~i', $href) ? $href : '';
    }

    //inline styles on the tags of an HTML block; links checked again (a filled {{ }} could have made anything)
    private static function style($html) {
	if (trim($html) === '')
	    return '';
	$doc = new \DOMDocument();
	libxml_use_internal_errors(true);
	$doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
	libxml_clear_errors();
	$root = $doc->getElementById('__root');
	foreach(iterator_to_array($root->getElementsByTagName('*')) as $el) {
	    $tag = strtolower($el->tagName);
	    if ($tag === 'a') {
		$href = self::link(trim($el->getAttribute('href')));
		if ($href === '')
		    $el->removeAttribute('href');
		else
		    $el->setAttribute('href', $href);
		$el->setAttribute('target', '_blank');
	    }
	    if (isset(self::TAG_STYLES[$tag]))
		$el->setAttribute('style', trim(self::TAG_STYLES[$tag] . ' ' . $el->getAttribute('style')));
	    //CLD: 2026-10-23T01:00:00+03:00: a table's cells: padding as an attribute too (Outlook)
	    if ($tag === 'table')
		$el->setAttribute('cellpadding', '6');
	}
	//CLD: 2026-10-23T01:00:00+03:00: paragraphs left empty (the editor's <p> around a {{ markdown(...) }}: its headings / lists end the paragraph) are dropped
	foreach(iterator_to_array($root->getElementsByTagName('p')) as $el) {
	    if (trim($el->textContent) === '' && !$el->getElementsByTagName('img')->length && !$el->getElementsByTagName('br')->length)
		$el->parentNode->removeChild($el);
	}
	$out = '';
	foreach($root->childNodes as $c)
	    $out .= $doc->saveHTML($c);
	return $out;
    }

    //the text version of an HTML block: paragraphs / headings / items on their own lines, links as "text (address)"
    private static function text($html) {
	$html = preg_replace_callback('~<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>~is', function($m) {
	    $label = trim(strip_tags($m[2]));
	    $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
	    return $label === '' || $label === $href ? $href : "{$label} ({$href})";
	}, $html);
	$html = preg_replace('~<li\b[^>]*>~i', "\n- ", $html);
	$html = preg_replace('~<br\s*/?>~i', "\n", $html);
	//CLD: 2026-10-23T01:00:00+03:00: + rules, table cells / rows, h4-h6, pre, table (markdown(...))
	$html = preg_replace('~<hr\b[^>]*>~i', "\n----\n", $html);
	$html = preg_replace('~</t[hd]>\s*<t[hd]\b[^>]*>~i', ' | ', $html);
	$html = preg_replace('~</tr>~i', "\n", $html);
	$html = preg_replace('~</(p|div|h[1-6]|ul|ol|blockquote|pre|table)>~i', "\n\n", $html);
	$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = preg_replace("/[ \t]+/", ' ', $text);
	$text = preg_replace("/ *\n */", "\n", $text);
	return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
