<?php
//CLD: 2026-10-13T12:17:47+03:00: new class: the {{ }} parts of a text (a J template: text with expressions between {{ and }}, e.g. "INVOICE-{{ mail.date }}"); moved here from
//util\Expression (parts, scan) so the parser (Parser::parse mode `template`) and the editors' help find them the same way

declare(strict_types=1);

namespace sys\J;

final class Template
{
    public const OPEN = '{{';
    public const CLOSE = '}}';

    /**
     * The {{ ... }} parts of $text: list of [open, start, end, closed] byte offsets: open = the `{{`, start..end = the code, closed = a `}}` ends it.
     * The `}}` is looked for outside strings, comments and nested braces (`{{ {a: {b: 1}} }}` is one expression).
     *
     * @return list<array{open:int, start:int, end:int, closed:bool}>
     */
    public static function parts(string $text): array
    {
        $parts = [];
        $len = strlen($text);
        $at = 0;
        while (($open = strpos($text, self::OPEN, $at)) !== false) {
            $start = $open + strlen(self::OPEN);
            [$end, $closed] = self::scan($text, $start, $len);
            $parts[] = ['open' => $open, 'start' => $start, 'end' => $end, 'closed' => $closed];
            if (!$closed) {
                break;
            }
            $at = $end + strlen(self::CLOSE);
        }
        return $parts;
    }

    /**
     * From $start: [offset of the closing `}}`, true], or [$limit, false] when there is none before $limit; $inside tells whether $limit falls inside a
     * 'string', 'template' or 'comment' (null: in code), for the suggestions at a cursor.
     *
     * @return array{0:int, 1:bool}
     */
    public static function scan(string $text, int $start, int $limit, ?string &$inside = null): array
    {
        //frames: ['code', brace depth] | ['str', quote] | ['tpl']
        $stack = [['code', 0]];
        $i = $start;
        while ($i < $limit) {
            $top = count($stack) - 1;
            $frame = $stack[$top];
            $c = $text[$i];
            if ($frame[0] === 'code') {
                if ($top === 0 && $frame[1] === 0 && substr($text, $i, 2) === self::CLOSE) {
                    return [$i, true];
                }
                if ($c === '"' || $c === "'") {
                    $stack[] = ['str', $c];
                } elseif ($c === '`') {
                    $stack[] = ['tpl'];
                } elseif ($c === '/' && !in_array($text[$i + 1] ?? '', ['/', '*'], true) && Lexer::regexAllowedAt($text, $start, $i)
                    && (($end = Lexer::regexEnd($text, $i, strlen($text))) !== null || strcspn($text, "\r\n", $i) >= $limit - $i)) {
                    //CLD: 2026-10-20T22:10:00+03:00: a regular expression: its } and quotes are not code; a cursor inside it (or in one not closed yet on its
                    //line) is in a 'regex'
                    if ($end === null || $end > $limit) {
                        $inside = 'regex';
                        return [$limit, false];
                    }
                    $i = $end - 1;
                } elseif ($c === '/' && ($text[$i + 1] ?? '') === '/') {
                    $nl = strpos($text, "\n", $i);
                    if ($nl === false || $nl >= $limit) {
                        $inside = 'comment';
                        return [$limit, false];
                    }
                    $i = $nl;
                } elseif ($c === '/' && ($text[$i + 1] ?? '') === '*') {
                    $close = strpos($text, '*/', $i + 2);
                    if ($close === false || $close + 2 > $limit) {
                        $inside = 'comment';
                        return [$limit, false];
                    }
                    $i = $close + 1;
                } elseif ($c === '{') {
                    $stack[$top][1]++;
                } elseif ($c === '}') {
                    if ($frame[1] > 0) {
                        $stack[$top][1]--;
                    } elseif ($top > 0) {
                        array_pop($stack);      //end of a template literal's ${ ... }
                    }
                }
            } elseif ($frame[0] === 'str') {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $frame[1] || $c === "\n") {
                    array_pop($stack);
                }
            } else {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === '`') {
                    array_pop($stack);
                } elseif ($c === '$' && ($text[$i + 1] ?? '') === '{') {
                    $stack[] = ['code', 0];
                    $i++;
                }
            }
            $i++;
        }
        $last = end($stack)[0];
        $inside = $last === 'code' ? null : ($last === 'str' ? 'string' : 'template');
        return [$limit, false];
    }
}
