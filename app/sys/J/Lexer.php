<?php

declare(strict_types=1);

namespace sys\J;

/**
 * Tolerant tokenizer. It never throws: problems are recorded in $diagnostics
 * and lexing continues. Offsets are byte offsets into the *full* source
 * (a base offset is added when lexing a fragment, e.g. a template expression).
 */
final class Lexer
{
    public const KEYWORDS = [
        'var', 'let', 'const', 'function', 'return', 'if', 'else', 'for', 'while', 'do',
        'break', 'continue', 'switch', 'case', 'default', 'try', 'catch', 'finally', 'throw',
        'typeof', 'void', 'delete', 'in', 'true', 'false', 'null',
        // Recognised only so the parser can reject them with a clear message:
        'new', 'class', 'import', 'export', 'extends', 'this', 'super', 'yield', 'async',
        'await', 'with', 'debugger', 'instanceof',
    ];

    private const PUNCTUATORS = [
        4 => ['>>>='],
        3 => ['...', '===', '!==', '**=', '<<=', '>>=', '>>>', '&&=', '||=', '??='],
        2 => ['=>', '==', '!=', '<=', '>=', '&&', '||', '??', '?.', '++', '--', '+=', '-=', '*=',
              '/=', '%=', '&=', '|=', '^=', '**', '<<', '>>'],
        1 => ['{', '}', '(', ')', '[', ']', ';', ',', '<', '>', '+', '-', '*', '/', '%', '&', '|',
              '^', '!', '~', '?', ':', '=', '.'],
    ];

    //CLD: 2026-10-20T22:10:00+03:00: a `/` after these words starts a regular expression (after other names it divides)
    public const REGEX_AFTER = ['return', 'typeof', 'case', 'do', 'else', 'in', 'of', 'void', 'delete', 'throw', 'instanceof', 'new', 'yield', 'await'];

    /** @var array<string, true> */
    private static array $keywordSet = [];
    /** @var array<int, array<string, true>> */
    private static array $punctSet = [];

    private int $pos = 0;
    private readonly int $len;

    /** @var list<array{severity:string, message:string, start:int, end:int}> */
    public array $diagnostics = [];

    public function __construct(private readonly string $src, private readonly int $base = 0)
    {
        $this->len = strlen($src);
        if (self::$keywordSet === []) {
            self::$keywordSet = array_fill_keys(self::KEYWORDS, true);
            foreach (self::PUNCTUATORS as $l => $list) {
                self::$punctSet[$l] = array_fill_keys($list, true);
            }
        }
    }

    /** @return list<Token> */
    public function tokenize(): array
    {
        if (str_starts_with($this->src, "\xEF\xBB\xBF")) {
            $this->pos = 3;
        }
        $tokens = [];
        $prev = null;
        while (true) {
            $nl = $this->skipTrivia();
            if ($this->pos >= $this->len) {
                $end = $this->base + $this->len;
                $tokens[] = new Token(Token::EOF, null, $end, $end, $nl);
                return $tokens;
            }
            //CLD: 2026-10-20T22:10:00+03:00: a `/` where a value can start is a regular expression (decided by the token before it)
            $tokens[] = $prev = $this->src[$this->pos] === '/' && self::regexAfter($prev) ? $this->scanRegex($nl) : $this->scanToken($nl);
        }
    }

    private function error(string $message, int $start, int $end): void
    {
        $this->diagnostics[] = [
            'severity' => 'error',
            'message' => $message,
            'start' => $this->base + $start,
            'end' => $this->base + max($end, $start),
        ];
    }

    /** Skips whitespace and comments; returns true if a line break was crossed. */
    private function skipTrivia(): bool
    {
        $nl = false;
        while ($this->pos < $this->len) {
            $c = $this->src[$this->pos];
            if ($c === "\n") {
                $nl = true;
                $this->pos++;
            } elseif ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\f" || $c === "\v") {
                $this->pos++;
            } elseif ($c === "\xC2" && ($this->src[$this->pos + 1] ?? '') === "\xA0") { // NBSP
                $this->pos += 2;
            } elseif ($c === '/' && ($this->src[$this->pos + 1] ?? '') === '/') {
                $end = strpos($this->src, "\n", $this->pos);
                $this->pos = $end === false ? $this->len : $end;
            } elseif ($c === '/' && ($this->src[$this->pos + 1] ?? '') === '*') {
                $end = strpos($this->src, '*/', $this->pos + 2);
                if ($end === false) {
                    $this->error('Unterminated comment', $this->pos, $this->len);
                    $end = $this->len;
                } else {
                    $end += 2;
                }
                if (strpos(substr($this->src, $this->pos, $end - $this->pos), "\n") !== false) {
                    $nl = true;
                }
                $this->pos = $end;
            } else {
                break;
            }
        }
        return $nl;
    }

    private function make(string $type, mixed $value, int $start, bool $nl): Token
    {
        return new Token(
            $type,
            $value,
            $this->base + $start,
            $this->base + $this->pos,
            $nl,
            substr($this->src, $start, $this->pos - $start),
        );
    }

    private static function isIdentStart(string $c): bool
    {
        return ctype_alpha($c) || $c === '_' || $c === '$' || ord($c) >= 0x80;
    }

    private static function isIdentPart(string $c): bool
    {
        return ctype_alnum($c) || $c === '_' || $c === '$' || ord($c) >= 0x80;
    }

    private function scanToken(bool $nl): Token
    {
        $start = $this->pos;
        $c = $this->src[$start];
        $next = $this->src[$start + 1] ?? '';

        if (ctype_digit($c) || ($c === '.' && ctype_digit($next))) {
            return $this->scanNumber($nl);
        }
        if ($c === '"' || $c === "'") {
            return $this->scanString($c, $nl);
        }
        if ($c === '`') {
            return $this->scanTemplate($nl);
        }
        if (self::isIdentStart($c)) {
            $this->pos++;
            while ($this->pos < $this->len && self::isIdentPart($this->src[$this->pos])) {
                $this->pos++;
            }
            $word = substr($this->src, $start, $this->pos - $start);
            return $this->make(isset(self::$keywordSet[$word]) ? Token::KW : Token::IDENT, $word, $start, $nl);
        }
        for ($l = 4; $l >= 1; $l--) {
            $p = substr($this->src, $start, $l);
            if (isset(self::$punctSet[$l][$p])) {
                // "a?.5:b" is a conditional, not optional chaining
                if ($p === '?.' && ctype_digit($this->src[$start + 2] ?? '')) {
                    continue;
                }
                $this->pos += $l;
                return $this->make(Token::PUNCT, $p, $start, $nl);
            }
        }

        // Unknown character: consume one UTF-8 sequence.
        $o = ord($c);
        $width = $o >= 0xF0 ? 4 : ($o >= 0xE0 ? 3 : ($o >= 0xC0 ? 2 : 1));
        $this->pos = min($this->len, $start + $width);
        $this->error("Unexpected character '" . substr($this->src, $start, $width) . "'", $start, $this->pos);
        return $this->make(Token::INVALID, substr($this->src, $start, $width), $start, $nl);
    }

    //CLD: 2026-10-20T22:10:00+03:00: whether a `/` after token $prev starts a regular expression: not after a value (a name, literal, `)`, `]`, `}`, postfix ++ / --)
    private static function regexAfter(?Token $prev): bool
    {
        if ($prev === null) {
            return true;
        }
        return match ($prev->type) {
            Token::NUM, Token::STR, Token::TPL, Token::IDENT, Token::REGEX => false,
            Token::KW => !in_array($prev->value, ['true', 'false', 'null', 'this', 'super'], true),
            Token::PUNCT => !in_array($prev->value, [')', ']', '}', '++', '--'], true),
            default => true,
        };
    }

    /**
     * The same decision from the text alone (for the scanners of {{ }} and ${ }, which do not make tokens): whether a `/` at $p, in code
     * starting at $start, starts a regular expression.
     */
    public static function regexAllowedAt(string $src, int $start, int $p): bool
    {
        $i = $p - 1;
        while ($i >= $start && ctype_space($src[$i])) {
            $i--;
        }
        if ($i < $start) {
            return true;
        }
        $c = $src[$i];
        if (in_array($c, [')', ']', '}', '"', "'", '`'], true)) {
            return false;
        }
        if (($c === '+' || $c === '-') && $i > $start && $src[$i - 1] === $c) {
            return false;
        }
        if (self::isIdentPart($c)) {
            $end = $i;
            while ($i >= $start && self::isIdentPart($src[$i])) {
                $i--;
            }
            return in_array(substr($src, $i + 1, $end - $i), self::REGEX_AFTER, true);
        }
        return true;
    }

    /** The offset after the regular expression literal whose `/` is at $p (flags included), or null when it is not closed on its line before $limit. */
    public static function regexEnd(string $src, int $p, int $limit): ?int
    {
        $inClass = false;
        for ($i = $p + 1; $i < $limit; $i++) {
            $c = $src[$i];
            if ($c === "\n" || $c === "\r") {
                return null;
            }
            if ($c === '\\') {
                $i++;
            } elseif ($inClass) {
                $inClass = $c !== ']';
            } elseif ($c === '[') {
                $inClass = true;
            } elseif ($c === '/') {
                for ($i++; $i < $limit && self::isIdentPart($src[$i]); $i++);
                return $i;
            }
        }
        return null;
    }

    private function scanRegex(bool $nl): Token
    {
        $start = $this->pos;
        $end = self::regexEnd($this->src, $start, $this->len);
        if ($end === null) {
            $eol = strcspn($this->src, "\r\n", $start);
            $this->pos = $start + $eol;
            $this->error('Unterminated regular expression', $start, $this->pos);
            return $this->make(Token::REGEX, ['pattern' => substr($this->src, $start + 1, $eol - 1), 'flags' => ''], $start, $nl);
        }
        $this->pos = $end;
        $close = strrpos(substr($this->src, $start, $end - $start), '/');
        $value = ['pattern' => substr($this->src, $start + 1, $close - 1), 'flags' => substr($this->src, $start + $close + 1, $end - $start - $close - 1)];
        try {
            new Runtime\JsRegExp($value['pattern'], $value['flags']);
        } catch (\InvalidArgumentException $e) {
            $this->error('Invalid regular expression: ' . $e->getMessage(), $start, $end);
        }
        return $this->make(Token::REGEX, $value, $start, $nl);
    }

    private function scanNumber(bool $nl): Token
    {
        $start = $this->pos;
        $src = $this->src;
        $value = 0;

        $prefix = strtolower(substr($src, $start, 2));
        if (in_array($prefix, ['0x', '0b', '0o'], true)) {
            $this->pos += 2;
            $digitsStart = $this->pos;
            while ($this->pos < $this->len && (ctype_xdigit($src[$this->pos]) || $src[$this->pos] === '_')) {
                $this->pos++;
            }
            $digits = str_replace('_', '', substr($src, $digitsStart, $this->pos - $digitsStart));
            $valid = match ($prefix) {
                '0x' => $digits !== '' && ctype_xdigit($digits),
                '0b' => $digits !== '' && strspn($digits, '01') === strlen($digits),
                default => $digits !== '' && strspn($digits, '01234567') === strlen($digits),
            };
            if (!$valid) {
                $this->error('Invalid numeric literal', $start, $this->pos);
            } else {
                $value = match ($prefix) {
                    '0x' => hexdec($digits),
                    '0b' => bindec($digits),
                    default => octdec($digits),
                };
            }
        } else {
            $this->skipDigits();
            if (($src[$this->pos] ?? '') === '.') {
                $this->pos++;
                $this->skipDigits();
            }
            $e = $src[$this->pos] ?? '';
            if ($e === 'e' || $e === 'E') {
                $this->pos++;
                $sign = $src[$this->pos] ?? '';
                if ($sign === '+' || $sign === '-') {
                    $this->pos++;
                }
                if (!ctype_digit($src[$this->pos] ?? '')) {
                    $this->error('Invalid exponent in numeric literal', $start, $this->pos);
                }
                $this->skipDigits();
            }
            $clean = str_replace('_', '', substr($src, $start, $this->pos - $start));
            if (strpbrk($clean, '.eE') === false && strlen($clean) <= 18) {
                $value = (int) $clean;
            } else {
                $value = (float) $clean;
            }
        }

        if ($this->pos < $this->len && self::isIdentStart($src[$this->pos])) {
            $badStart = $this->pos;
            while ($this->pos < $this->len && self::isIdentPart($src[$this->pos])) {
                $this->pos++;
            }
            $this->error('An identifier cannot immediately follow a numeric literal', $badStart, $this->pos);
        }

        return $this->make(Token::NUM, $value, $start, $nl);
    }

    private function skipDigits(): void
    {
        while ($this->pos < $this->len && (ctype_digit($this->src[$this->pos]) || $this->src[$this->pos] === '_')) {
            $this->pos++;
        }
    }

    private function scanString(string $quote, bool $nl): Token
    {
        $start = $this->pos++;
        $out = '';
        $terminated = false;
        while ($this->pos < $this->len) {
            $n = strcspn($this->src, $quote . "\\\n", $this->pos);
            $out .= substr($this->src, $this->pos, $n);
            $this->pos += $n;
            if ($this->pos >= $this->len) {
                break;
            }
            $c = $this->src[$this->pos];
            if ($c === $quote) {
                $this->pos++;
                $terminated = true;
                break;
            }
            if ($c === "\n") {
                break;
            }
            $this->pos++; // backslash
            $out .= $this->readEscape();
        }
        if (!$terminated) {
            $this->error('Unterminated string literal', $start, $this->pos);
        }
        return $this->make(Token::STR, $out, $start, $nl);
    }

    /** Called with $pos just after a backslash. */
    private function readEscape(): string
    {
        if ($this->pos >= $this->len) {
            return '';
        }
        $escStart = $this->pos - 1;
        $c = $this->src[$this->pos++];
        switch ($c) {
            case 'n': return "\n";
            case 't': return "\t";
            case 'r': return "\r";
            case 'b': return "\x08";
            case 'f': return "\f";
            case 'v': return "\v";
            case '0':
                return ctype_digit($this->src[$this->pos] ?? '') ? '0' : "\0";
            case "\r":
                if (($this->src[$this->pos] ?? '') === "\n") {
                    $this->pos++;
                }
                return '';
            case "\n":
                return '';
            case 'x':
                $hex = substr($this->src, $this->pos, 2);
                if (strlen($hex) === 2 && ctype_xdigit($hex)) {
                    $this->pos += 2;
                    return mb_chr(hexdec($hex), 'UTF-8');
                }
                $this->error('Invalid hexadecimal escape sequence', $escStart, $this->pos);
                return 'x';
            case 'u':
                $cp = $this->readUnicodeEscape($escStart);
                if ($cp === null) {
                    return 'u';
                }
                // Combine UTF-16 surrogate pairs: \uD83D\uDE00
                if ($cp >= 0xD800 && $cp <= 0xDBFF && substr($this->src, $this->pos, 2) === '\\u') {
                    $save = $this->pos;
                    $this->pos += 2;
                    $low = $this->readUnicodeEscape($save);
                    if ($low !== null && $low >= 0xDC00 && $low <= 0xDFFF) {
                        $cp = 0x10000 + (($cp - 0xD800) << 10) + ($low - 0xDC00);
                    } else {
                        $this->pos = $save;
                    }
                }
                $ch = mb_chr($cp, 'UTF-8');
                return $ch === false ? "\u{FFFD}" : $ch;
            default:
                return $c;
        }
    }

    private function readUnicodeEscape(int $escStart): ?int
    {
        if (($this->src[$this->pos] ?? '') === '{') {
            $close = strpos($this->src, '}', $this->pos);
            $hex = $close === false ? '' : substr($this->src, $this->pos + 1, $close - $this->pos - 1);
            if ($hex !== '' && ctype_xdigit($hex) && hexdec($hex) <= 0x10FFFF) {
                $this->pos = $close + 1;
                return (int) hexdec($hex);
            }
        } else {
            $hex = substr($this->src, $this->pos, 4);
            if (strlen($hex) === 4 && ctype_xdigit($hex)) {
                $this->pos += 4;
                return (int) hexdec($hex);
            }
        }
        $this->error('Invalid Unicode escape sequence', $escStart, $this->pos);
        return null;
    }

    private function scanTemplate(bool $nl): Token
    {
        $start = $this->pos++;
        $quasis = [];
        $exprs = [];
        $cur = '';
        $terminated = false;
        while ($this->pos < $this->len) {
            $n = strcspn($this->src, "`\\$", $this->pos);
            $cur .= substr($this->src, $this->pos, $n);
            $this->pos += $n;
            if ($this->pos >= $this->len) {
                break;
            }
            $c = $this->src[$this->pos];
            if ($c === '`') {
                $this->pos++;
                $terminated = true;
                break;
            }
            if ($c === '\\') {
                $this->pos++;
                $cur .= $this->readEscape();
                continue;
            }
            if (($this->src[$this->pos + 1] ?? '') === '{') {
                $quasis[] = $cur;
                $cur = '';
                $exprStart = $this->pos + 2;
                $close = $this->skipBalanced($exprStart);
                $exprs[] = [
                    'source' => substr($this->src, $exprStart, $close - $exprStart),
                    'offset' => $this->base + $exprStart,
                ];
                if ($close >= $this->len) {
                    $this->error("Unterminated template expression, expected '}'", $exprStart - 2, $this->len);
                    $this->pos = $this->len;
                    break;
                }
                $this->pos = $close + 1;
                continue;
            }
            $cur .= '$';
            $this->pos++;
        }
        $quasis[] = $cur;
        if (!$terminated) {
            $this->error('Unterminated template literal', $start, $this->pos);
        }
        return $this->make(Token::TPL, ['quasis' => $quasis, 'exprs' => $exprs], $start, $nl);
    }

    /** Returns the offset of the '}' closing a template expression, or $len. */
    private function skipBalanced(int $p): int
    {
        $depth = 0;
        $src = $this->src;
        while ($p < $this->len) {
            $c = $src[$p];
            if ($c === '{') {
                $depth++;
                $p++;
            } elseif ($c === '}') {
                if ($depth === 0) {
                    return $p;
                }
                $depth--;
                $p++;
            } elseif ($c === '"' || $c === "'") {
                $p++;
                while ($p < $this->len && $src[$p] !== $c && $src[$p] !== "\n") {
                    $p += $src[$p] === '\\' ? 2 : 1;
                }
                $p++;
            } elseif ($c === '`') {
                $p = $this->skipTemplateRaw($p);
            } elseif ($c === '/' && !in_array($src[$p + 1] ?? '', ['/', '*'], true) && self::regexAllowedAt($src, 0, $p)
                && ($e = self::regexEnd($src, $p, $this->len)) !== null) {
                $p = $e; //CLD: 2026-10-20T22:10:00+03:00: a regular expression (its } and quotes are not code)
            } elseif ($c === '/' && ($src[$p + 1] ?? '') === '/') {
                $e = strpos($src, "\n", $p);
                $p = $e === false ? $this->len : $e;
            } elseif ($c === '/' && ($src[$p + 1] ?? '') === '*') {
                $e = strpos($src, '*/', $p + 2);
                $p = $e === false ? $this->len : $e + 2;
            } else {
                $p++;
            }
        }
        return $this->len;
    }

    private function skipTemplateRaw(int $p): int
    {
        $p++;
        while ($p < $this->len) {
            $c = $this->src[$p];
            if ($c === '\\') {
                $p += 2;
            } elseif ($c === '`') {
                return $p + 1;
            } elseif ($c === '$' && ($this->src[$p + 1] ?? '') === '{') {
                $p = $this->skipBalanced($p + 2) + 1;
            } else {
                $p++;
            }
        }
        return $this->len;
    }
}
