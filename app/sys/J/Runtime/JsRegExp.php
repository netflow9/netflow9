<?php
//CLD: 2026-10-20T22:10:00+03:00: regular expressions (literals /a+/g, RegExp(); methods in Builtins::regexpMethod, string methods match / matchAll / search / replace / replaceAll / split)

declare(strict_types=1);

namespace sys\J\Runtime;

use sys\J\Interpreter;
use sys\J\LimitExceeded;

/**
 * A JavaScript RegExp, run by PCRE. The pattern is translated from JS syntax (only JS syntax is accepted: no PCRE-only groups, verbs or
 * escapes) and always matched on code points (PCRE `u`), as J strings count code points. `$` without the m flag matches only at the end (PCRE `D`).
 * Each PCRE call runs with a lowered backtracking limit (catastrophic patterns stop with LimitExceeded) and checks the run's time limit.
 */
final class JsRegExp
{
    /** the flags in the order JS lists them (the `flags` property) */
    public const FLAGS = 'dgimsuy';

    /** backtracking limit of one PCRE call (pcre.backtrack_limit) */
    public const BACKTRACK_LIMIT = 200000;

    /** @var array<string, array{0: string, 1: list<string>, 2: int}> compiled patterns: "flags/source" => [pcre, group names, group count] */
    private static array $cache = [];

    public int|float $lastIndex = 0;
    /** the flags in the order of FLAGS (as JS gives them: /a/ig.flags is "gi") */
    public readonly string $flags;
    public readonly string $pcre;
    /** @var list<string> */
    public readonly array $names;
    public readonly int $groups;

    /** @throws \InvalidArgumentException a bad pattern or flags (the message says why) */
    public function __construct(public readonly string $source, string $flags = '')
    {
        $chars = str_split($flags);
        usort($chars, fn ($a, $b) => (strpos(self::FLAGS, $a) === false ? 99 : strpos(self::FLAGS, $a)) <=> (strpos(self::FLAGS, $b) === false ? 99 : strpos(self::FLAGS, $b)));
        $this->flags = $flags = implode('', $chars);
        $key = $flags . '/' . $source;
        if (!isset(self::$cache[$key])) {
            if (count(self::$cache) > 500) {
                self::$cache = [];
            }
            self::$cache[$key] = self::compile($source, $flags);
        }
        [$this->pcre, $this->names, $this->groups] = self::$cache[$key];
    }

    public function has(string $flag): bool
    {
        return str_contains($this->flags, $flag);
    }

    /** `/source/flags`, with the source as JS shows it (`/` escaped, empty as `(?:)`) */
    public function toString(): string
    {
        return '/' . self::displaySource($this->source) . '/' . $this->flags;
    }

    public static function displaySource(string $source): string
    {
        if ($source === '') {
            return '(?:)';
        }
        $out = '';
        $inClass = false;
        $n = strlen($source);
        for ($i = 0; $i < $n; $i++) {
            $c = $source[$i];
            if ($c === '\\' && $i + 1 < $n) {
                $out .= $c . $source[++$i];
            } elseif ($c === '/' && !$inClass) {
                $out .= '\/';
            } elseif ($c === "\n") {
                $out .= '\n';
            } elseif ($c === "\r") {
                $out .= '\r';
            } else {
                $inClass = $c === '[' ? true : ($c === ']' ? false : $inClass);
                $out .= $c;
            }
        }
        return $out;
    }

    // ============================================================ compiling

    private const WORD = 'A-Za-z0-9_';

    /** the ASCII escapes of JS as PCRE: [outside a class, inside one]; \b in a class is a backspace */
    private const ASCII_CLASSES = [
        'd' => ['[0-9]', '0-9'],
        'D' => ['[^0-9]', '\x{0}-\/:-\x{10FFFF}'],
        'w' => ['[' . self::WORD . ']', self::WORD],
        'W' => ['[^' . self::WORD . ']', '\x{0}-\/:-@\[-\^`\{-\x{10FFFF}'],
        'b' => ['(?:(?<=[' . self::WORD . '])(?![' . self::WORD . '])|(?<![' . self::WORD . '])(?=[' . self::WORD . ']))', '\x{8}'],
        'B' => ['(?:(?<=[' . self::WORD . '])(?=[' . self::WORD . '])|(?<![' . self::WORD . '])(?![' . self::WORD . ']))', 'B'],
    ];

    /** @return array{0: string, 1: list<string>, 2: int} */
    private static function compile(string $source, string $flags): array
    {
        $seen = [];
        foreach (str_split($flags) as $f) {
            if ($f === 'v') {
                throw new \InvalidArgumentException("The flag 'v' is not supported");
            }
            if (!str_contains(self::FLAGS, $f) || isset($seen[$f])) {
                throw new \InvalidArgumentException("Invalid flags '$flags'");
            }
            $seen[$f] = true;
        }
        $names = [];
        $groups = 0;
        $body = self::translate($source, isset($seen['u']), $names, $groups);
        $pcre = '/' . $body . '/uD' . (isset($seen['i']) ? 'i' : '') . (isset($seen['m']) ? 'm' : '') . (isset($seen['s']) ? 's' : '')
            . (isset($seen['y']) ? 'A' : '');

        $error = null;
        set_error_handler(function (int $no, string $message) use (&$error) {
            $error = $message;
            return true;
        });
        try {
            $ok = preg_match($pcre, '') !== false;
        } finally {
            restore_error_handler();
        }
        if (!$ok) {
            $error = preg_replace('/^preg_match\(\): (Compilation failed: )?/', '', (string) ($error ?? preg_last_error_msg()));
            throw new \InvalidArgumentException((string) $error);
        }
        return [$pcre, $names, $groups];
    }

    /**
     * The PCRE body of a JS pattern; $names / $groups get the capture groups.
     *
     * @param list<string> $names
     */
    private static function translate(string $src, bool $unicode, array &$names, int &$groups): string
    {
        $out = '';
        $n = strlen($src);
        $inClass = false;
        $i = 0;
        while ($i < $n) {
            $c = $src[$i];
            if ($c === '\\') {
                if ($i + 1 >= $n) {
                    throw new \InvalidArgumentException('\\ at end of pattern');
                }
                [$piece, $i] = self::escape($src, $i, $unicode, $inClass);
                $out .= $piece;
                continue;
            }
            if ($inClass) {
                $out .= match ($c) { '[' => '\[', '/' => '\/', default => $c };
                $inClass = $c !== ']';
                $i++;
                continue;
            }
            switch ($c) {
                case '[':
                    if (substr($src, $i, 3) === '[^]') {
                        $out .= '[\s\S]'; // any character
                        $i += 3;
                    } elseif (substr($src, $i, 2) === '[]') {
                        $out .= '(?!)';   // nothing
                        $i += 2;
                    } else {
                        $inClass = true;
                        $out .= '[';
                        $i++;
                        if (($src[$i] ?? '') === '^') {
                            $out .= '^';
                            $i++;
                        }
                    }
                    continue 2;
                case '(':
                    if (($src[$i + 1] ?? '') === '*') {
                        throw new \InvalidArgumentException('Nothing to repeat');
                    }
                    if (($src[$i + 1] ?? '') !== '?') {
                        $groups++;
                        $out .= '(';
                        $i++;
                        continue 2;
                    }
                    $two = substr($src, $i + 2, 2);
                    if (in_array($two[0] ?? '', [':', '=', '!'], true)) {
                        $out .= '(?' . $two[0];
                        $i += 3;
                    } elseif ($two === '<=' || $two === '<!') {
                        $out .= '(?' . $two;
                        $i += 4;
                    } elseif (($two[0] ?? '') === '<' && preg_match('/\G<([A-Za-z_][A-Za-z0-9_]*)>/', $src, $m, 0, $i + 2)) {
                        $names[] = $m[1];
                        $groups++;
                        $out .= '(?<' . $m[1] . '>';
                        $i += 2 + strlen($m[0]);
                    } else {
                        throw new \InvalidArgumentException('Invalid group');
                    }
                    continue 2;
                case ']':
                    $out .= '\]';
                    break;
                case '/':
                    $out .= '\/';
                    break;
                default:
                    $out .= $c;
            }
            $i++;
        }
        if ($inClass) {
            throw new \InvalidArgumentException('Unterminated character class');
        }
        return $out;
    }

    /**
     * The PCRE form of the escape at $i (a backslash) and the offset after it.
     *
     * @return array{0: string, 1: int}
     */
    private static function escape(string $src, int $i, bool $unicode, bool $inClass = false): array
    {
        $d = $src[$i + 1];
        //CLD: 2026-10-20T22:10:00+03:00: \d \w \b are ASCII in JS; PCRE's u makes them Unicode (UCP, no way to turn it off in PCRE 10.40), so they are spelled out
        if (isset(self::ASCII_CLASSES[$d])) {
            return [self::ASCII_CLASSES[$d][$inClass ? 1 : 0], $i + 2];
        }
        switch ($d) {
            case 's': case 'S': case 'f': case 'n': case 'r': case 't':
                return ['\\' . $d, $i + 2];
            case 'v':
                return ['\x0B', $i + 2]; // a vertical tab (PCRE's \v is a class)
            case '0':
                return ctype_digit($src[$i + 2] ?? '') ? ['0', $i + 2] : ['\x{0}', $i + 2];
            case 'x':
                $hex = substr($src, $i + 2, 2);
                if (strlen($hex) === 2 && ctype_xdigit($hex)) {
                    return ['\x{' . $hex . '}', $i + 4];
                }
                break;
            case 'c':
                if (ctype_alpha($src[$i + 2] ?? '')) {
                    return [sprintf('\x{%X}', ord($src[$i + 2]) % 32), $i + 3];
                }
                break;
            case 'u':
                if ($unicode && ($src[$i + 2] ?? '') === '{' && preg_match('/\G\{([0-9A-Fa-f]{1,6})\}/', $src, $m, 0, $i + 2) && hexdec($m[1]) <= 0x10FFFF) {
                    return [sprintf('\x{%X}', hexdec($m[1])), $i + 2 + strlen($m[0])];
                }
                $hex = substr($src, $i + 2, 4);
                if (strlen($hex) === 4 && ctype_xdigit($hex)) {
                    $cp = (int) hexdec($hex);
                    $low = substr($src, $i + 6, 6);
                    if ($cp >= 0xD800 && $cp <= 0xDBFF && preg_match('/^\\\\u(d[c-f][0-9a-f]{2})$/i', $low, $m)) {
                        return [sprintf('\x{%X}', 0x10000 + (($cp - 0xD800) << 10) + (hexdec($m[1]) - 0xDC00)), $i + 12];
                    }
                    return [sprintf('\x{%X}', $cp), $i + 6];
                }
                break;
            case 'k':
                if (preg_match('/\G<([A-Za-z_][A-Za-z0-9_]*)>/', $src, $m, 0, $i + 2)) {
                    return ['\k<' . $m[1] . '>', $i + 2 + strlen($m[0])];
                }
                break;
            case 'p':
            case 'P':
                if ($unicode) {
                    if (!preg_match('/\G\{([A-Za-z_]+)(?:=([A-Za-z_]+))?\}/', $src, $m, 0, $i + 2)) {
                        throw new \InvalidArgumentException('Invalid property name');
                    }
                    // \p{Script=Greek} / \p{gc=Lu} -> \p{Greek} / \p{Lu}; \p{scx=Greek} -> \p{scx:Greek}
                    $name = isset($m[2]) ? (in_array($m[1], ['Script_Extensions', 'scx'], true) ? 'scx:' . $m[2] : $m[2]) : $m[1];
                    return ['\\' . $d . '{' . $name . '}', $i + 2 + strlen($m[0])];
                }
                break;
            default:
                if (ctype_digit($d)) {
                    preg_match('/\G\d+/', $src, $m, 0, $i + 1);
                    return ['\g{' . $m[0] . '}', $i + 1 + strlen($m[0])]; // a back reference
                }
                if (ord($d) >= 0x80) {
                    return ['', $i + 1]; // an escaped non-ASCII character is itself
                }
                if (!ctype_alnum($d)) {
                    return ['\\' . $d, $i + 2]; // \. \/ \( ...
                }
        }
        if ($unicode) {
            throw new \InvalidArgumentException('Invalid escape');
        }
        return [$d, $i + 2]; // without the u flag, an unknown escape is the letter itself
    }

    // ============================================================ matching

    /**
     * Runs a PCRE call with the backtracking limit, then checks the run's time limit.
     *
     * @throws LimitExceeded the pattern backtracks too much (or the time is up)
     */
    public static function guard(Interpreter $vm, \Closure $call): mixed
    {
        $old = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);
        try {
            $r = $call();
        } finally {
            ini_set('pcre.backtrack_limit', (string) $old);
        }
        if ($r === false || $r === null) {
            $error = preg_last_error();
            if (in_array($error, [PREG_BACKTRACK_LIMIT_ERROR, PREG_RECURSION_LIMIT_ERROR, PREG_JIT_STACKLIMIT_ERROR], true)) {
                throw new LimitExceeded('Regular expression too complex (backtracking limit exceeded)');
            }
            if ($error !== PREG_NO_ERROR) {
                throw $vm->error('TypeError', 'Regular expression failed: ' . preg_last_error_msg());
            }
        }
        $vm->checkTime();
        return $r;
    }

    /**
     * One match from byte offset $at (anchored there with the y flag), or null.
     *
     * @return ?array{start: int, end: int, captures: list<?string>, spans: list<?array{0: int, 1: int}>, named: array<string, ?string>}
     */
    public function matchAt(string $s, int $at, Interpreter $vm): ?array
    {
        $m = [];
        $found = self::guard($vm, function () use ($s, $at, &$m) {
            return preg_match($this->pcre, $s, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $at);
        });
        return $found ? $this->raw($m) : null;
    }

    /**
     * All matches from the start (the g flag's way, each anchored at the end of the previous with the y flag).
     *
     * @return list<array{start: int, end: int, captures: list<?string>, spans: list<?array{0: int, 1: int}>, named: array<string, ?string>}>
     */
    public function matchAll(string $s, Interpreter $vm): array
    {
        $all = [];
        self::guard($vm, function () use ($s, &$all) {
            return preg_match_all($this->pcre, $s, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        });
        $vm->checkArrayLength(count($all));
        return array_map(fn ($m) => $this->raw($m), $all);
    }

    /** @return array{start: int, end: int, captures: list<?string>, spans: list<?array{0: int, 1: int}>, named: array<string, ?string>} */
    private function raw(array $m): array
    {
        $captures = [];
        $spans = [];
        for ($g = 0; $g <= $this->groups; $g++) {
            [$text, $offset] = $m[$g] ?? [null, -1];
            $captures[] = $text;
            $spans[] = $text === null ? null : [$offset, $offset + strlen($text)];
        }
        $named = [];
        foreach ($this->names as $name) {
            $named[$name] = $m[$name][0] ?? null;
        }
        return ['start' => $spans[0][0], 'end' => $spans[0][1], 'captures' => $captures, 'spans' => $spans, 'named' => $named];
    }

    /** A converter of byte offsets of $s to code point indexes (fastest for growing offsets). */
    public static function indexer(string $s): \Closure
    {
        $byte = 0;
        $index = 0;
        return function (int $to) use ($s, &$byte, &$index): int {
            if ($to < $byte) {
                $byte = $index = 0;
            }
            $index += mb_strlen(substr($s, $byte, $to - $byte), 'UTF-8');
            $byte = $to;
            return $index;
        };
    }

    /** The byte offset of code point index $index of $s. */
    public static function byteOffset(string $s, int $index): int
    {
        return strlen(mb_substr($s, 0, $index, 'UTF-8'));
    }
}
