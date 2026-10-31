<?php

/*
    a document's text cut into chunks for a knowledge base (models\KnowledgeBase): at most $size characters each, the last $overlap characters
    of a chunk repeated at the start of the next (a sentence cut at the edge is still found whole). Cut at paragraphs, else at sentences, else at a space, else anywhere.
    A PDF's text (models\node\NodeAppAi::pdfRead: "--- page N ---" before each page) is cut page by page: each chunk knows its page.
    TextChunks::split($text, $size, $overlap)   [ [ text, page (null: no pages) ], ... ]
*/

namespace util;

class TextChunks {
    const PAGE = '/^--- page (\d+) ---$/m';

    public static function split($text, $size = 1000, $overlap = 150) {
	$size = max(100, (int)$size);
	$overlap = max(0, min((int)$overlap, intdiv($size, 2)));
	$text = str_replace([ "\r\n", "\r" ], "\n", (string)$text);

	//the pages: [ page => text ] (null: the whole text)
	$parts = preg_split(self::PAGE, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
	$pages = [];
	if (count($parts) > 1) {
	    if (trim($parts[0]) !== '')
		$pages[] = [ null, $parts[0] ];
	    for($i = 1; $i + 1 < count($parts); $i += 2)
		$pages[] = [ (int)$parts[$i], $parts[$i + 1] ];
	} else {
	    $pages[] = [ null, $text ];
	}

	$chunks = [];
	foreach($pages as [ $page, $body ]) {
	    foreach(self::splitText($body, $size, $overlap) as $t)
		$chunks[] = [ 'text' => $t, 'page' => $page ];
	}
	return $chunks;
    }

    //the chunks of one text
    private static function splitText($text, $size, $overlap) {
	$text = trim(preg_replace([ '/[ \t]+\n/', '/\n{3,}/', '/[ \t]{2,}/' ], [ "\n", "\n\n", ' ' ], $text));
	if ($text === '')
	    return [];
	$pieces = [];
	foreach(preg_split('/\n\s*\n/', $text) as $para)
	    foreach(self::pieces(trim($para), $size) as $p)
		$pieces[] = $p;

	$chunks = [];
	$current = '';
	foreach($pieces as $p) {
	    if ($current !== '' && mb_strlen($current) + 2 + mb_strlen($p) > $size) {
		$chunks[] = $current;
		$tail = self::tail($current, $overlap);
		$current = $tail !== '' && mb_strlen($tail) + 1 + mb_strlen($p) <= $size ? $tail . ' ' . $p : $p;
		continue;
	    }
	    $current = $current === '' ? $p : $current . "\n\n" . $p;
	}
	if ($current !== '')
	    $chunks[] = $current;
	return $chunks;
    }

    //a paragraph as pieces of at most $size characters: itself, its sentences, or cuts at spaces
    private static function pieces($para, $size) {
	if ($para === '')
	    return [];
	if (mb_strlen($para) <= $size)
	    return [ $para ];
	$out = [];
	$current = '';
	foreach(preg_split('/(?<=[.!?;])\s+/u', $para) as $sentence) {
	    foreach(self::cut($sentence, $size) as $s) {
		if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($s) > $size) {
		    $out[] = $current;
		    $current = $s;
		} else {
		    $current = $current === '' ? $s : $current . ' ' . $s;
		}
	    }
	}
	if ($current !== '')
	    $out[] = $current;
	return $out;
    }

    //a sentence longer than $size cut at the last space before the limit (anywhere, when there is none)
    private static function cut($s, $size) {
	$out = [];
	while (mb_strlen($s) > $size) {
	    $head = mb_substr($s, 0, $size);
	    $at = mb_strrpos($head, ' ');
	    if ($at === false || $at < $size / 2)
		$at = $size;
	    $out[] = rtrim(mb_substr($s, 0, $at));
	    $s = ltrim(mb_substr($s, $at));
	}
	if ($s !== '')
	    $out[] = $s;
	return $out;
    }

    //the last $n characters of $text, from the start of a word
    private static function tail($text, $n) {
	if ($n <= 0)
	    return '';
	if (mb_strlen($text) <= $n)
	    return $text;
	$t = mb_substr($text, -$n);
	$at = mb_strpos($t, ' ');
	return $at === false ? $t : ltrim(mb_substr($t, $at));
    }
}
