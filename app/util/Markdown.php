<?php

/*
    Markdown as safe HTML, and as plain text (what models answer, e.g. an AI step's text, for an email).
	\util\Markdown::toHtml($md)    HTML without styles (util\EmailHtml adds its inline styles in an email)
	\util\Markdown::toText($md)    readable plain text (no * # | marks; links as "text (address)")
    The text is untrusted (a model's answer may carry what a web page said): every < > & " ' of it is escaped, no HTML passes; links only to http(s) / mailto (others: their text);
    images become links to them (no remote images: no tracking pixels). The rest is plain text.
    Blocks: headings (# to ######, and text underlined with === / ---), paragraphs (a single line break is kept: <br>), lists (- * + and 1. 1), nested by indentation),
    quotes (>), fenced code (``` or ~~~), indented code (4 spaces), rules (--- *** ___), tables (| a | b | with a |---| line).
    Inline: **bold** __bold__, *italic* _italic_, ~~struck~~, `code`, [text](url "title"), ![alt](url), <https://...>, bare https:// addresses, \ escapes.
*/
namespace util;

class Markdown {
    //the longest text converted (characters); the rest is cut
    const MAX_LENGTH = 200000;
    //how deep quotes and lists nest
    const MAX_DEPTH = 8;

    //Markdown $md as safe HTML
    public static function toHtml($md) {
	return self::blocks(self::lines($md), 0);
    }

    //Markdown $md as plain text
    public static function toText($md) {
	$html = self::toHtml($md);
	$html = preg_replace_callback('~<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>~is', function($m) {
	    $label = trim(strip_tags($m[2]));
	    $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
	    return $label === '' || $label === $href ? $href : "{$label} ({$href})";
	}, $html);
	$html = preg_replace('~<li\b[^>]*>~i', "\n- ", $html);
	$html = preg_replace('~<br\s*/?>~i', "\n", $html);
	$html = preg_replace('~<hr\s*/?>~i', "\n----\n", $html);
	$html = preg_replace('~</t[hd]>\s*<t[hd]\b[^>]*>~i', ' | ', $html);
	$html = preg_replace('~</tr>~i', "\n", $html);
	$html = preg_replace('~</(p|div|h[1-6]|ul|ol|blockquote|pre|table)>~i', "\n\n", $html);
	$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = preg_replace("/[ \t]+/", ' ', $text);
	$text = preg_replace("/ *\n */", "\n", $text);
	return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    // ------------------------------------------------------------------------------------
    //blocks

    //the lines of $md: line ends normalized, tabs as 4 spaces
    private static function lines($md) {
	$md = mb_substr(\util\Expression::toText($md), 0, self::MAX_LENGTH);
	$md = str_replace([ "\r\n", "\r", "\t" ], [ "\n", "\n", '    ' ], $md);
	return explode("\n", $md);
    }

    //the blocks of $lines as HTML; $depth: quotes / lists inside quotes / lists
    private static function blocks(array $lines, $depth) {
	$out = [];
	$n = count($lines);
	$i = 0;
	while($i < $n) {
	    $line = $lines[$i];
	    $t = trim($line);
	    if ($t === '') {
		$i++;
		continue;
	    }
	    //fenced code
	    if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m)) {
		$fence = $m[1];
		$code = [];
		for($i++; $i < $n && !preg_match('/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\s*$/', $lines[$i]); $i++)
		    $code[] = $lines[$i];
		$i++;
		$out[] = '<pre><code>' . self::esc(implode("\n", $code)) . '</code></pre>';
		continue;
	    }
	    //a heading
	    if (preg_match('/^ {0,3}(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m)) {
		$level = strlen($m[1]);
		$out[] = "<h{$level}>" . self::inline($m[2]) . "</h{$level}>";
		$i++;
		continue;
	    }
	    //a rule
	    if (preg_match('/^ {0,3}([-*_])(\s*\1){2,}\s*$/', $line)) {
		$out[] = '<hr>';
		$i++;
		continue;
	    }
	    //a quote: its lines without `>`, as blocks
	    if (preg_match('/^ {0,3}>/', $line)) {
		$inner = [];
		for(; $i < $n && preg_match('/^ {0,3}>\s?(.*)$/', $lines[$i], $m); $i++)
		    $inner[] = $m[1];
		$out[] = '<blockquote>' . ($depth < self::MAX_DEPTH ? self::blocks($inner, $depth + 1) : self::esc(implode("\n", $inner))) . '</blockquote>';
		continue;
	    }
	    //a list
	    if (self::listItem($line) !== null) {
		$out[] = self::listBlock($lines, $i, $depth);
		continue;
	    }
	    //a table: a row, then the separator line
	    if (str_contains($t, '|') && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/', $lines[$i + 1])) {
		$out[] = self::table($lines, $i);
		continue;
	    }
	    //indented code
	    if (preg_match('/^ {4}/', $line)) {
		$code = [];
		for(; $i < $n && (preg_match('/^ {4}/', $lines[$i]) || trim($lines[$i]) === ''); $i++)
		    $code[] = substr($lines[$i], 4);
		$out[] = '<pre><code>' . self::esc(rtrim(implode("\n", $code))) . '</code></pre>';
		continue;
	    }
	    //a paragraph: until a blank line or another block; text underlined with === / --- is a heading
	    $para = [];
	    for(; $i < $n && trim($lines[$i]) !== ''; $i++) {
		//the underline first (--- is also a rule)
		if ($para && preg_match('/^ {0,3}(=+|-+)\s*$/', $lines[$i], $m)) {
		    $level = $m[1][0] === '=' ? 1 : 2;
		    $out[] = "<h{$level}>" . self::inline(implode(' ', array_map('trim', $para))) . "</h{$level}>";
		    $para = [];
		    $i++;
		    continue 2;
		}
		if ($para && self::startsBlock($lines[$i]))
		    break;
		$para[] = trim($lines[$i]);
	    }
	    $out[] = '<p>' . implode('<br>', array_map([ self::class, 'inline' ], $para)) . '</p>';
	}
	return implode("\n", $out);
    }

    //whether $line starts another block (ends a paragraph)
    private static function startsBlock($line) {
	return preg_match('/^ {0,3}(#{1,6}\s|>|`{3}|~{3})/', $line) || preg_match('/^ {0,3}([-*_])(\s*\1){2,}\s*$/', $line) || self::listItem($line) !== null;
    }

    //a list item's line: [ indent, ordered, number, text ], or null
    private static function listItem($line) {
	if (preg_match('/^( *)([-*+])\s+(.*)$/', $line, $m))
	    return [ strlen($m[1]), false, null, $m[3] ];
	if (preg_match('/^( *)(\d{1,9})[.)]\s+(.*)$/', $line, $m))
	    return [ strlen($m[1]), true, (int)$m[2], $m[3] ];
	return null;
    }

    //the list starting at line $i (moved after it): its items, their continuation lines and the lists nested in them (more indented)
    private static function listBlock(array $lines, &$i, $depth) {
	$n = count($lines);
	[ $indent, $ordered, $start ] = self::listItem($lines[$i]);
	$items = [];
	while($i < $n) {
	    $it = self::listItem($lines[$i]);
	    if ($it === null || $it[0] < $indent || ($it[0] === $indent && $it[1] !== $ordered))
		break;
	    if ($it[0] > $indent) {
		//a nested list, inside the last item
		if (!$items)
		    break;
		$items[count($items) - 1]['nested'][] = $depth < self::MAX_DEPTH ? self::listBlock($lines, $i, $depth + 1) : '';
		continue;
	    }
	    $item = [ 'text' => [ $it[3] ], 'nested' => [] ];
	    $i++;
	    $ends = false;
	    //continuation lines (indented, not a new item), a blank line before the next item allowed
	    while($i < $n) {
		$next = $lines[$i];
		if (trim($next) === '') {
		    //a blank line, then an item (of this list, or of one around it: that one goes on): skipped
		    $na = self::listItem($lines[$i + 1] ?? '');
		    if ($na !== null)
			$i++;
		    else
			$ends = true;
		    break;
		}
		if (self::listItem($next) !== null || !preg_match('/^ {' . ($indent + 1) . ',}\S/', $next) && self::startsBlock($next))
		    break;
		if (!preg_match('/^ +/', $next) && $item['nested'])
		    break;
		$item['text'][] = trim($next);
		$i++;
	    }
	    $items[] = $item;
	    //a blank line not followed by an item: the list ends
	    if ($ends)
		break;
	}
	$tag = $ordered ? 'ol' : 'ul';
	$html = '<' . $tag . ($ordered && $start !== null && $start !== 1 ? ' start="' . (int)$start . '"' : '') . '>';
	foreach($items as $item)
	    $html .= '<li>' . implode('<br>', array_map([ self::class, 'inline' ], $item['text'])) . implode('', $item['nested']) . '</li>';
	return $html . "</{$tag}>";
    }

    //the table starting at line $i (moved after it): the header, the alignment line, the rows
    private static function table(array $lines, &$i) {
	$cells = function($line) {
	    $line = trim($line);
	    $line = preg_replace('/^\||\|$/', '', $line);
	    //\| is a | inside a cell
	    return array_map(fn($c) => trim(str_replace('\\|', '|', $c)), preg_split('/(?<!\\\\)\|/', $line));
	};
	$head = $cells($lines[$i]);
	$align = array_map(function($c) {
	    $l = str_starts_with($c, ':');
	    $r = str_ends_with($c, ':');
	    return $l && $r ? 'center' : ($r ? 'right' : ($l ? 'left' : ''));
	}, $cells($lines[$i + 1]));
	$i += 2;
	$attr = fn($k) => !empty($align[$k]) ? ' align="' . $align[$k] . '"' : '';
	$html = '<table><thead><tr>';
	foreach($head as $k => $c)
	    $html .= '<th' . $attr($k) . '>' . self::inline($c) . '</th>';
	$html .= '</tr></thead><tbody>';
	for($n = count($lines); $i < $n && trim($lines[$i]) !== '' && str_contains($lines[$i], '|'); $i++) {
	    $row = $cells($lines[$i]);
	    $html .= '<tr>';
	    foreach($head as $k => $unused)
		$html .= '<td' . $attr($k) . '>' . self::inline($row[$k] ?? '') . '</td>';
	    $html .= '</tr>';
	}
	return $html . '</tbody></table>';
    }

    // ------------------------------------------------------------------------------------
    //inline

    //the inline marks of $text as HTML (the text escaped first; code spans and links kept apart so their content is not marked again)
    private static function inline($text) {
	$keep = [];
	$hold = function($html) use (&$keep) {
	    $keep[] = $html;
	    return "\u{E000}" . (count($keep) - 1) . "\u{E001}";
	};
	//backslash escapes: the character as it is
	$text = preg_replace_callback('/\\\\([\\\\`*_{}\[\]()#+\-.!|~<>])/', fn($m) => $hold(self::esc($m[1])), $text);
	//code spans
	$text = preg_replace_callback('/(`+)(.+?)\1(?!`)/s', fn($m) => $hold('<code>' . self::esc(trim($m[2])) . '</code>'), $text);
	//images: a link to the image (no remote images)
	$text = preg_replace_callback('/!\[([^\]]*)\]\(\s*<?((?:[^()\s<>]|\([^()\s<>]*\))+)>?(?:\s+"[^"]*")?\s*\)/', function($m) use ($hold) {
	    $label = trim($m[1]) !== '' ? trim($m[1]) : 'image';
	    $href = self::href($m[2]);
	    return $hold($href !== null ? '<a href="' . self::esc($href) . '">' . self::esc($label) . '</a>' : self::esc($label));
	}, $text);
	//links: [text](url "title"), one level of ( ) allowed in the address; their text marked (bold etc.), not linked again
	$text = preg_replace_callback('/\[((?:[^\[\]]|\[[^\]]*\])*)\]\(\s*<?((?:[^()\s<>]|\([^()\s<>]*\))+)>?(?:\s+"([^"]*)")?\s*\)/', function($m) use ($hold) {
	    $href = self::href($m[2]);
	    $label = self::marks(self::esc($m[1]));
	    if ($href === null)
		return $hold($label);
	    return $hold('<a href="' . self::esc($href) . '"' . (isset($m[3]) && $m[3] !== '' ? ' title="' . self::esc($m[3]) . '"' : '') . '>' . $label . '</a>');
	}, $text);
	//autolinks <https://...>, then bare addresses
	$text = preg_replace_callback('/<((?:https?:\/\/|mailto:)[^\s<>]+)>/i', fn($m) => $hold('<a href="' . self::esc($m[1]) . '">' . self::esc($m[1]) . '</a>'), $text);
	$text = preg_replace_callback('/\bhttps?:\/\/[^\s<>()\x{E000}\x{E001}]+[^\s<>().,;:!?"\'\x{E000}\x{E001}]/iu', fn($m) => $hold('<a href="' . self::esc($m[0]) . '">' . self::esc($m[0]) . '</a>'), $text);

	$html = self::marks(self::esc($text));
	//what was kept, back (nested ones too)
	for($k = 0; $k < 3 && str_contains($html, "\u{E000}"); $k++)
	    $html = preg_replace_callback('/\x{E000}(\d+)\x{E001}/u', fn($m) => $keep[(int)$m[1]] ?? '', $html);
	return $html;
    }

    //bold, italic, struck in escaped text
    private static function marks($html) {
	//not closed right before another * (**a *b***: the bold closes after the italic)
	$html = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*(?!\*)/s', '<strong>$1</strong>', $html);
	$html = preg_replace('/(?<![\w])__(?=\S)(.+?)(?<=\S)__(?![\w])/su', '<strong>$1</strong>', $html);
	$html = preg_replace('/(?<![*\w])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![*\w])/su', '<em>$1</em>', $html);
	$html = preg_replace('/(?<![\w])_(?=\S)(.+?)(?<=\S)_(?![\w])/su', '<em>$1</em>', $html);
	return preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/s', '<del>$1</del>', $html);
    }

    //a link's address when allowed (http(s) / mailto), else null
    private static function href($url) {
	$url = trim(html_entity_decode((string)$url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	return preg_match('~^(https?://[^\s]+|mailto:[^\s]+)$~i', $url) ? $url : null;
    }

    private static function esc($s) {
	return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
