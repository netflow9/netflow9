<?php

declare(strict_types=1);

namespace sys\J\Runtime;

/**
 * JavaScript value semantics on top of PHP values.
 *
 *   undefined -> Undefined::value()      null    -> null
 *   boolean   -> bool                    number  -> int|float
 *   string    -> string (UTF-8)          array   -> JsArray
 *   object    -> JsObject                function-> JsFunction|NativeFunction
 *   Date      -> JsDate
 *   RegExp    -> JsRegExp
 */
final class Values
{
    /** JS trims Unicode whitespace; PHP's trim() is byte-based and would corrupt UTF-8, so use PCRE. */
    public static function trim(string $s, bool $start = true, bool $end = true): string
    {
        $ws = '[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+';
        $pattern = match (true) {
            $start && $end => "/^$ws|$ws\$/u",
            $start => "/^$ws/u",
            default => "/$ws\$/u",
        };
        $r = preg_replace($pattern, '', $s);
        if ($r === null) { // invalid UTF-8: fall back to ASCII whitespace
            $chars = " \t\n\r\v\f";
            return $start && $end ? trim($s, $chars) : ($start ? ltrim($s, $chars) : rtrim($s, $chars));
        }
        return $r;
    }

    //CLD: 2026-10-20T22:45:00+03:00: J's JSON text of a PHP value (strings, keys, numbers): util\JSON, the app's escaping (< > & ' " as \u00XX, invalid UTF-8 dropped),
    //with non-ASCII characters and / as they are
    public static function json(mixed $v): string
    {
        return (string) \util\JSON::encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @var array<int, true> objects currently being stringified (cycle guard) */
    private static array $joinStack = [];

    public static function isNullish(mixed $v): bool
    {
        return $v === null || $v instanceof Undefined;
    }

    public static function isCallable(mixed $v): bool
    {
        return $v instanceof JsFunction || $v instanceof NativeFunction;
    }

    public static function isNumber(mixed $v): bool
    {
        return is_int($v) || is_float($v);
    }

    public static function typeOf(mixed $v): string
    {
        return match (true) {
            $v instanceof Undefined => 'undefined',
            $v === null => 'object',
            is_bool($v) => 'boolean',
            is_int($v), is_float($v) => 'number',
            is_string($v) => 'string',
            $v instanceof JsFunction, $v instanceof NativeFunction => 'function',
            default => 'object',
        };
    }

    /** Normalises integral floats in the safe range back to int. */
    public static function num(int|float $n): int|float
    {
        if (is_float($n) && is_finite($n) && floor($n) === $n && abs($n) <= 9007199254740991.0
            && !($n === 0.0 && fdiv(1.0, $n) < 0)) {
            return (int) $n;
        }
        return $n;
    }

    public static function toBoolean(mixed $v): bool
    {
        return match (true) {
            is_bool($v) => $v,
            $v === null, $v instanceof Undefined => false,
            is_int($v) => $v !== 0,
            is_float($v) => !($v == 0.0 || is_nan($v)),
            is_string($v) => $v !== '',
            default => true,
        };
    }

    public static function toNumber(mixed $v): int|float
    {
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if ($v instanceof Undefined) {
            return NAN;
        }
        if ($v === null || $v === false) {
            return 0;
        }
        if ($v === true) {
            return 1;
        }
        if (is_string($v)) {
            return self::stringToNumber($v);
        }
        if ($v instanceof JsArray) {
            return self::stringToNumber(self::toString($v));
        }
        //CLD: 2026-10-20T21:01:44+03:00: a date is its time in ms
        if ($v instanceof JsDate) {
            return self::num($v->time);
        }
        return NAN;
    }

    public static function stringToNumber(string $s): int|float
    {
        $s = self::trim($s);
        if ($s === '') {
            return 0;
        }
        $lower = strtolower($s);
        if (strlen($s) > 2 && $s[0] === '0') {
            $digits = substr($s, 2);
            switch ($lower[1]) {
                case 'x':
                    return ctype_xdigit($digits) ? hexdec($digits) : NAN;
                case 'b':
                    return strspn($digits, '01') === strlen($digits) ? bindec($digits) : NAN;
                case 'o':
                    return strspn($digits, '01234567') === strlen($digits) ? octdec($digits) : NAN;
            }
        }
        if ($s === 'Infinity' || $s === '+Infinity') {
            return INF;
        }
        if ($s === '-Infinity') {
            return -INF;
        }
        if (is_numeric($s) && preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $s)) {
            return self::num($s + 0);
        }
        return NAN;
    }

    public static function toInt32(mixed $v): int
    {
        $n = self::toNumber($v);
        if (is_int($n)) {
            $n &= 0xFFFFFFFF;
        } else {
            if (!is_finite($n)) {
                return 0;
            }
            $n = (int) fmod(floor(abs($n)) * ($n < 0 ? -1 : 1), 4294967296.0);
            $n &= 0xFFFFFFFF;
        }
        return $n >= 0x80000000 ? $n - 0x100000000 : $n;
    }

    public static function toUint32(mixed $v): int
    {
        return self::toInt32($v) & 0xFFFFFFFF;
    }

    /** ToIntegerOrInfinity, clamped to PHP int range. */
    public static function toInteger(mixed $v): int
    {
        $n = self::toNumber($v);
        if (is_int($n)) {
            return $n;
        }
        if (is_nan($n)) {
            return 0;
        }
        if ($n >= 9.2e18) {
            return PHP_INT_MAX;
        }
        if ($n <= -9.2e18) {
            return PHP_INT_MIN;
        }
        return (int) $n;
    }

    public static function numberToString(int|float $n): string
    {
        if (is_int($n)) {
            return (string) $n;
        }
        if (is_nan($n)) {
            return 'NaN';
        }
        if (is_infinite($n)) {
            return $n > 0 ? 'Infinity' : '-Infinity';
        }
        if ($n == 0.0) {
            return '0';
        }
        if (floor($n) === $n && abs($n) < 1e21) {
            return sprintf('%.0f', $n);
        }
        // Shortest round-trip representation (uses serialize_precision = -1).
        $s = self::json($n); //CLD: 2026-10-20T22:45:00+03:00: util\JSON (was json_encode)
        if ($s === false) {
            return (string) $n;
        }
        if (stripos($s, 'e') !== false) {
            [$mantissa, $exp] = explode('e', strtolower($s));
            if (str_ends_with($mantissa, '.0')) {
                $mantissa = substr($mantissa, 0, -2);
            }
            $expN = (int) $exp;
            if ($expN >= -6 && $expN < 21) {
                // JS prints these without exponent.
                $digits = str_replace(['-', '.'], '', $mantissa);
                $neg = $mantissa[0] === '-';
                $intLen = strlen(explode('.', ltrim($mantissa, '-'))[0]) + $expN;
                if ($intLen <= 0) {
                    $s = '0.' . str_repeat('0', -$intLen) . $digits;
                } else {
                    $s = substr(str_pad($digits, $intLen, '0'), 0, $intLen);
                    $frac = substr($digits, $intLen);
                    if ($frac !== '' && $frac !== false) {
                        $s .= '.' . $frac;
                    }
                }
                return ($neg ? '-' : '') . $s;
            }
            return $mantissa . 'e' . ($expN >= 0 ? '+' : '-') . abs($expN);
        }
        return $s;
    }

    public static function toString(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return self::numberToString($v);
        }
        return match (true) {
            $v instanceof Undefined => 'undefined',
            $v === null => 'null',
            $v === true => 'true',
            $v === false => 'false',
            $v instanceof JsArray => self::join($v, ','),
            $v instanceof JsRegExp => $v->toString(), //CLD: 2026-10-20T22:10:00+03:00: /source/flags
            $v instanceof JsDate => $v->format(), //CLD: 2026-10-20T21:01:44+03:00: the date's toString()
            $v instanceof JsFunction => 'function ' . $v->name . '() { [code] }',
            $v instanceof NativeFunction => 'function ' . $v->name . '() { [native code] }',
            default => '[object Object]',
        };
    }

    public static function join(JsArray $arr, string $sep): string
    {
        $id = spl_object_id($arr);
        if (isset(self::$joinStack[$id])) {
            return ''; // cyclic: JS also prints an empty string
        }
        self::$joinStack[$id] = true;
        try {
            $parts = [];
            foreach ($arr->items as $item) {
                $parts[] = self::isNullish($item) ? '' : self::toString($item);
            }
            return implode($sep, $parts);
        } finally {
            unset(self::$joinStack[$id]);
        }
    }

    /** Property keys are strings in JS. */
    public static function toPropertyKey(mixed $v): string
    {
        return is_string($v) ? $v : self::toString($v);
    }

    /** Returns a valid array index for $key or null if it is not one. */
    public static function toArrayIndex(mixed $key): ?int
    {
        if (is_int($key)) {
            return $key >= 0 ? $key : null;
        }
        if (is_string($key)) {
            $l = strlen($key);
            if ($l > 0 && $l < 16 && ctype_digit($key) && ($key === '0' || $key[0] !== '0')) {
                return (int) $key;
            }
            return null;
        }
        if (is_float($key) && is_finite($key) && floor($key) === $key && $key >= 0 && $key < 9007199254740992.0) {
            return (int) $key;
        }
        return null;
    }

    public static function toPrimitive(mixed $v): mixed
    {
        //CLD: 2026-10-20T21:01:44+03:00: a date too (its default hint is string, so `date + 1` is text, as in JS)
        if ($v instanceof JsArray || $v instanceof JsObject || $v instanceof JsFunction || $v instanceof NativeFunction || $v instanceof JsDate
            || $v instanceof JsRegExp) { //CLD: 2026-10-20T22:10:00+03:00: + a regular expression
            return self::toString($v);
        }
        return $v;
    }

    public static function strictEquals(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b; // NaN != NaN in PHP too
        }
        return $a === $b;
    }

    /** SameValueZero (used by includes): like === but NaN equals NaN. */
    public static function sameValueZero(mixed $a, mixed $b): bool
    {
        if (is_float($a) && is_float($b) && is_nan($a) && is_nan($b)) {
            return true;
        }
        return self::strictEquals($a, $b);
    }

    public static function looseEquals(mixed $a, mixed $b): bool
    {
        $an = self::isNullish($a);
        $bn = self::isNullish($b);
        if ($an || $bn) {
            return $an && $bn;
        }
        if (self::isNumber($a) && self::isNumber($b)) {
            return $a == $b;
        }
        if (gettype($a) === gettype($b) && !is_object($a)) {
            return $a === $b;
        }
        if (is_object($a) && is_object($b)) {
            return $a === $b;
        }
        if (is_bool($a)) {
            return self::looseEquals($a ? 1 : 0, $b);
        }
        if (is_bool($b)) {
            return self::looseEquals($a, $b ? 1 : 0);
        }
        if (is_object($a)) {
            return self::looseEquals(self::toPrimitive($a), $b);
        }
        if (is_object($b)) {
            return self::looseEquals($a, self::toPrimitive($b));
        }
        // number vs string
        return self::toNumber($a) == self::toNumber($b);
    }

    /** Abstract relational comparison: true / false / null (= undefined, i.e. NaN involved). */
    public static function lessThan(mixed $a, mixed $b): ?bool
    {
        //CLD: 2026-10-20T21:01:44+03:00: dates compare as numbers (hint number), not as their text
        $a = $a instanceof JsDate ? self::toNumber($a) : self::toPrimitive($a);
        $b = $b instanceof JsDate ? self::toNumber($b) : self::toPrimitive($b);
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) < 0;
        }
        $x = self::toNumber($a);
        $y = self::toNumber($b);
        if ((is_float($x) && is_nan($x)) || (is_float($y) && is_nan($y))) {
            return null;
        }
        return $x < $y;
    }

    /** Human-readable rendering used by console.log and error messages. */
    public static function inspect(mixed $v, int $depth = 0, bool $top = true): string
    {
        if (is_string($v)) {
            return $top ? $v : self::json($v); //CLD: 2026-10-20T22:45:00+03:00: util\JSON (was json_encode)
        }
        if ($v instanceof JsFunction || $v instanceof NativeFunction) {
            return '[Function' . ($v->name !== '' ? ': ' . $v->name : ' (anonymous)') . ']';
        }
        //CLD: 2026-10-20T21:01:44+03:00: a date as node prints it (the ISO text)
        if ($v instanceof JsDate) {
            return $v->format('iso');
        }
        //CLD: 2026-10-20T22:10:00+03:00: a regular expression as its literal
        if ($v instanceof JsRegExp) {
            return $v->toString();
        }
        if ($v instanceof JsArray) {
            if ($depth > 3) {
                return '[Array]';
            }
            $parts = array_map(fn ($x) => self::inspect($x, $depth + 1, false), $v->items);
            return '[' . implode(', ', $parts) . ']';
        }
        if ($v instanceof JsObject) {
            if ($depth > 3) {
                return '[Object]';
            }
            if ($v->props === []) {
                return '{}';
            }
            $parts = [];
            foreach ($v->props as $k => $x) {
                $k = (string) $k;
                $key = preg_match('/^[A-Za-z_$][\w$]*$/', $k) ? $k : self::json($k); //CLD: 2026-10-20T22:45:00+03:00: util\JSON (was json_encode)
                $parts[] = $key . ': ' . self::inspect($x, $depth + 1, false);
            }
            return '{ ' . implode(', ', $parts) . ' }';
        }
        return self::toString($v);
    }
}
