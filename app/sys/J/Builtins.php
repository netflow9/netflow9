<?php

declare(strict_types=1);

namespace sys\J;

use sys\J\Runtime\JsArray;
use sys\J\Runtime\JsDate;
use sys\J\Runtime\JsRegExp;
use sys\J\Runtime\JsFunction;
use sys\J\Runtime\JsObject;
use sys\J\Runtime\NativeFunction;
use sys\J\Runtime\Scope;
use sys\J\Runtime\Undefined;
use sys\J\Runtime\Values;

/**
 * Standard library. Deliberately small and side-effect free: no I/O, no eval,
 * no access to PHP functions by name. Add your own by passing closures in the
 * run() context, or extend install().
 */
final class Builtins
{
    public static function install(Scope $scope, Interpreter $vm): void
    {
        $define = function (string $name, mixed $value) use ($scope): void {
            $scope->vars[$name] = $value;
            $scope->consts[$name] = true;
        };

        $define('undefined', Undefined::value());
        $define('NaN', NAN);
        $define('Infinity', INF);
        $define('Math', self::math());
        $define('JSON', self::json());
        $define('Object', self::objectCtor());
        $define('Array', self::arrayCtor());
        $define('console', self::console());
        //CLD: 2026-10-20T21:01:44+03:00: Date (called without `new`, like the Error functions)
        $define('Date', self::dateCtor());
        //CLD: 2026-10-20T22:10:00+03:00: RegExp (called without `new`; literals /a+/g too)
        $define('RegExp', self::regexpCtor());

        $define('String', self::fn('String', fn (array $a) => array_key_exists(0, $a) ? Values::toString($a[0]) : '', [
            'fromCharCode' => self::fn('fromCharCode', fn (array $a) => implode('', array_map(
                fn ($c) => mb_chr(Values::toInteger($c) & 0x1FFFFF, 'UTF-8') ?: "\u{FFFD}",
                $a,
            ))),
        ]));

        $parseInt = self::fn('parseInt', fn (array $a) => self::parseInt(Values::toString(self::arg($a, 0)), self::arg($a, 1)));
        $parseFloat = self::fn('parseFloat', fn (array $a) => self::parseFloat(Values::toString(self::arg($a, 0))));
        $define('parseInt', $parseInt);
        $define('parseFloat', $parseFloat);
        $define('isNaN', self::fn('isNaN', fn (array $a) => is_nan((float) Values::toNumber(self::arg($a, 0)))));
        $define('isFinite', self::fn('isFinite', fn (array $a) => is_finite((float) Values::toNumber(self::arg($a, 0)))));

        $define('Number', self::fn('Number', fn (array $a) => array_key_exists(0, $a) ? Values::toNumber($a[0]) : 0, [
            'isInteger' => self::fn('isInteger', fn (array $a) => is_int($a[0] ?? null) || (is_float($a[0] ?? null) && is_finite($a[0]) && floor($a[0]) === $a[0])),
            'isFinite' => self::fn('isFinite', fn (array $a) => is_int($a[0] ?? null) || (is_float($a[0] ?? null) && is_finite($a[0]))),
            'isNaN' => self::fn('isNaN', fn (array $a) => is_float($a[0] ?? null) && is_nan($a[0])),
            'parseInt' => $parseInt,
            'parseFloat' => $parseFloat,
            'MAX_SAFE_INTEGER' => 9007199254740991,
            'MIN_SAFE_INTEGER' => -9007199254740991,
            'EPSILON' => 2.220446049250313e-16,
        ]));
        $define('Boolean', self::fn('Boolean', fn (array $a) => Values::toBoolean(self::arg($a, 0))));

        // Error "constructors" (called without `new`): Error('msg') -> { name, message }
        foreach (['Error', 'TypeError', 'RangeError', 'SyntaxError', 'ReferenceError'] as $type) {
            $define($type, self::fn($type, fn (array $a) => new JsObject([
                'name' => $type,
                'message' => Values::isNullish(self::arg($a, 0)) ? '' : Values::toString($a[0]),
            ])));
        }
    }

    //CLD: 2026-10-20T21:40:00+03:00: the methods of each kind of value, by name: the *Method functions answer only these (so the lists stay complete);
    //hosts read them too (util\Expression offers them as suggestions)
    public const METHODS = [
        'object' => ['hasOwnProperty', 'toString'],
        'array' => ['at', 'concat', 'every', 'fill', 'filter', 'find', 'findIndex', 'findLast', 'findLastIndex', 'flat', 'flatMap', 'forEach',
            'includes', 'indexOf', 'join', 'lastIndexOf', 'map', 'pop', 'push', 'reduce', 'reduceRight', 'reverse', 'shift', 'slice', 'some',
            'sort', 'splice', 'toString', 'unshift'],
        'string' => ['at', 'charAt', 'charCodeAt', 'codePointAt', 'concat', 'endsWith', 'includes', 'indexOf', 'lastIndexOf', 'localeCompare',
            'match', 'matchAll', 'padEnd', 'padStart', 'repeat', 'replace', 'replaceAll', 'search', 'slice', 'split', 'startsWith', 'substring', 'toLocaleLowerCase',
            'toLocaleUpperCase', 'toLowerCase', 'toString', 'toUpperCase', 'trim', 'trimEnd', 'trimStart', 'valueOf'],
        'number' => ['toFixed', 'toString', 'valueOf'],
        'regexp' => ['exec', 'test', 'toString'],
        'date' => ['getDate', 'getDay', 'getFullYear', 'getHours', 'getMilliseconds', 'getMinutes', 'getMonth', 'getSeconds', 'getTime',
            'getTimezoneOffset', 'getUTCDate', 'getUTCDay', 'getUTCFullYear', 'getUTCHours', 'getUTCMilliseconds', 'getUTCMinutes', 'getUTCMonth',
            'getUTCSeconds', 'setDate', 'setFullYear', 'setHours', 'setMilliseconds', 'setMinutes', 'setMonth', 'setSeconds', 'setTime',
            'setUTCDate', 'setUTCFullYear', 'setUTCHours', 'setUTCMilliseconds', 'setUTCMinutes', 'setUTCMonth', 'setUTCSeconds',
            'toDateString', 'toGMTString', 'toISOString', 'toJSON', 'toLocaleDateString', 'toLocaleString', 'toLocaleTimeString', 'toString',
            'toTimeString', 'toUTCString', 'valueOf'],
    ];

    /** Whether $name is one of the METHODS of $kind. */
    private static function isMethod(string $kind, string $name): bool
    {
        static $sets = null;
        $sets ??= array_map('array_flip', self::METHODS);
        return isset($sets[$kind][$name]);
    }

    // ================================================================ helpers

    /** @param array<string, mixed> $props */
    public static function fn(string $name, \Closure $impl, array $props = []): NativeFunction
    {
        return new NativeFunction($impl, $name, $props);
    }

    public static function arg(array $a, int $i): mixed
    {
        return array_key_exists($i, $a) ? $a[$i] : Undefined::value();
    }

    private static function callback(array $a, Interpreter $vm, string $method): JsFunction|NativeFunction
    {
        $cb = self::arg($a, 0);
        if (!Values::isCallable($cb)) {
            throw $vm->error('TypeError', Values::inspect($cb) . " is not a function (in $method)");
        }
        return $cb;
    }

    /** Resolves a relative index (negative counts from the end) clamped to [0, len]. */
    private static function relIndex(mixed $v, int $len, int $default): int
    {
        if ($v instanceof Undefined) {
            return $default;
        }
        $i = Values::toInteger($v);
        return $i < 0 ? max($len + $i, 0) : min($i, $len);
    }

    private static function console(): JsObject
    {
        $log = fn (string $prefix) => self::fn('log', function (array $a, Interpreter $vm) use ($prefix) {
            $vm->log($prefix . implode(' ', array_map(fn ($v) => Values::inspect($v), $a)));
            return Undefined::value();
        });
        return new JsObject([
            'log' => $log(''),
            'info' => $log(''),
            'warn' => $log('[warn] '),
            'error' => $log('[error] '),
        ]);
    }

    // =================================================================== Math

    private static function math(): JsObject
    {
        $unary = [
            'abs' => 'abs', 'floor' => 'floor', 'ceil' => 'ceil', 'sqrt' => 'sqrt', 'exp' => 'exp',
            'log' => 'log', 'log10' => 'log10', 'log2' => null, 'sin' => 'sin', 'cos' => 'cos',
            'tan' => 'tan', 'asin' => 'asin', 'acos' => 'acos', 'atan' => 'atan', 'trunc' => null,
            'sign' => null, 'cbrt' => null, 'round' => null,
        ];
        $props = [];
        foreach ($unary as $name => $php) {
            $props[$name] = self::fn($name, function (array $a) use ($name, $php) {
                $x = (float) Values::toNumber(self::arg($a, 0));
                $r = match ($name) {
                    'log2' => log($x, 2),
                    'trunc' => $x < 0 ? ceil($x) : floor($x),
                    'sign' => is_nan($x) ? NAN : ($x <=> 0),
                    'cbrt' => $x < 0 ? -((-$x) ** (1 / 3)) : $x ** (1 / 3),
                    'round' => floor($x + 0.5),
                    'log', 'log10' => $x < 0 ? NAN : ($x == 0.0 ? -INF : $php($x)),
                    'sqrt' => $x < 0 ? NAN : sqrt($x),
                    default => $php($x),
                };
                return Values::num($r);
            });
        }
        $props['pow'] = self::fn('pow', fn (array $a) => Values::num(
            (float) Values::toNumber(self::arg($a, 0)) ** (float) Values::toNumber(self::arg($a, 1))
        ));
        $props['atan2'] = self::fn('atan2', fn (array $a) => atan2((float) Values::toNumber(self::arg($a, 0)), (float) Values::toNumber(self::arg($a, 1))));
        $props['hypot'] = self::fn('hypot', fn (array $a) => Values::num(sqrt(array_sum(array_map(fn ($v) => ((float) Values::toNumber($v)) ** 2, $a)))));
        $props['random'] = self::fn('random', fn () => mt_rand() / (mt_getrandmax() + 1));
        foreach (['min' => INF, 'max' => -INF] as $name => $init) {
            $props[$name] = self::fn($name, function (array $a) use ($name, $init) {
                $best = $init;
                foreach ($a as $v) {
                    $n = Values::toNumber($v);
                    if (is_float($n) && is_nan($n)) {
                        return NAN;
                    }
                    if ($name === 'min' ? $n < $best : $n > $best) {
                        $best = $n;
                    }
                }
                return $best;
            });
        }
        $props += ['PI' => M_PI, 'E' => M_E, 'LN2' => M_LN2, 'LN10' => M_LN10, 'SQRT2' => M_SQRT2, 'LOG2E' => M_LOG2E, 'LOG10E' => M_LOG10E];
        return new JsObject($props);
    }

    // =================================================================== JSON

    private static function json(): JsObject
    {
        return new JsObject([
            'stringify' => self::fn('stringify', function (array $a, Interpreter $vm) {
                $space = self::arg($a, 2);
                $indent = '';
                if (is_int($space) || is_float($space)) {
                    $indent = str_repeat(' ', max(0, min(10, (int) $space)));
                } elseif (is_string($space)) {
                    $indent = substr($space, 0, 10);
                }
                $stack = [];
                $out = self::jsonEncode(self::arg($a, 0), $indent, '', $stack, $vm);
                return $out === null ? Undefined::value() : $vm->checkString($out);
            }),
            'parse' => self::fn('parse', function (array $a, Interpreter $vm) {
                $text = Values::toString(self::arg($a, 0));
                //CLD: 2026-10-20T22:45:00+03:00: util\JSON (the app's tolerant parser: comments, single quotes, keys without quotes, a last comma; was json_decode)
                try {
                    $decoded = \util\JSON::json_decode($text, true, true);
                } catch (\ErrorException $e) {
                    throw $vm->error('SyntaxError', 'JSON.parse: ' . preg_replace('/^JSON: /', '', $e->getMessage()));
                }
                return self::fromJson($decoded);
            }),
        ]);
    }

    private static function fromJson(mixed $v): mixed
    {
        if (is_array($v)) {
            return new JsArray(array_map(fn ($x) => self::fromJson($x), $v));
        }
        if ($v instanceof \stdClass) {
            $props = [];
            foreach (get_object_vars($v) as $k => $x) {
                $props[(string) $k] = self::fromJson($x);
            }
            return new JsObject($props);
        }
        return is_float($v) ? Values::num($v) : $v;
    }

    /** @param array<int, true> $stack */
    private static function jsonEncode(mixed $v, string $indent, string $cur, array &$stack, Interpreter $vm): ?string
    {
        if ($v instanceof Undefined || $v instanceof JsFunction || $v instanceof NativeFunction) {
            return null;
        }
        if ($v === null) {
            return 'null';
        }
        //CLD: 2026-10-20T21:01:44+03:00: a date as its toJSON() (the ISO text; null for an Invalid Date)
        if ($v instanceof JsDate) {
            return $v->isValid() ? '"' . $v->toIso() . '"' : 'null';
        }
        //CLD: 2026-10-20T22:10:00+03:00: a regular expression has no own enumerable properties
        if ($v instanceof JsRegExp) {
            return '{}';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return is_finite($v) ? Values::numberToString($v) : 'null';
        }
        if (is_string($v)) {
            //CLD: 2026-10-20T22:45:00+03:00: util\JSON (the app's escaping: < > & ' " as \u00XX, safe in HTML; invalid UTF-8 dropped; was json_encode)
            return Values::json($v);
        }

        $id = spl_object_id($v);
        if (isset($stack[$id])) {
            throw $vm->error('TypeError', 'Converting circular structure to JSON');
        }
        $stack[$id] = true;
        $inner = $cur . $indent;
        $nl = $indent === '' ? '' : "\n";
        $parts = [];
        try {
            if ($v instanceof JsArray) {
                foreach ($v->items as $item) {
                    $parts[] = $inner . (self::jsonEncode($item, $indent, $inner, $stack, $vm) ?? 'null');
                }
                return $parts === [] ? '[]' : '[' . $nl . implode(',' . $nl, $parts) . $nl . $cur . ']';
            }
            foreach ($v->props as $k => $item) {
                $enc = self::jsonEncode($item, $indent, $inner, $stack, $vm);
                if ($enc !== null) {
                    $key = Values::json((string) $k); //CLD: 2026-10-20T22:45:00+03:00: util\JSON (was json_encode)
                    $parts[] = $inner . $key . ($indent === '' ? ':' : ': ') . $enc;
                }
            }
            return $parts === [] ? '{}' : '{' . $nl . implode(',' . $nl, $parts) . $nl . $cur . '}';
        } finally {
            unset($stack[$id]);
        }
    }

    // ================================================================= Object

    private static function objectCtor(): NativeFunction
    {
        $keysOf = function (mixed $o): array {
            return match (true) {
                $o instanceof JsObject => array_map('strval', array_keys($o->props)),
                $o instanceof JsArray => array_map('strval', array_keys($o->items)),
                is_string($o) => array_map('strval', array_keys(mb_str_split($o, 1, 'UTF-8'))),
                default => [],
            };
        };
        $valuesOf = function (mixed $o): array {
            return match (true) {
                $o instanceof JsObject => array_values($o->props),
                $o instanceof JsArray => $o->items,
                is_string($o) => mb_str_split($o, 1, 'UTF-8'),
                default => [],
            };
        };

        return self::fn('Object', fn () => new JsObject(), [
            'keys' => self::fn('keys', fn (array $a) => new JsArray($keysOf(self::arg($a, 0)))),
            'values' => self::fn('values', fn (array $a) => new JsArray($valuesOf(self::arg($a, 0)))),
            'entries' => self::fn('entries', function (array $a) use ($keysOf, $valuesOf) {
                $o = self::arg($a, 0);
                return new JsArray(array_map(
                    fn ($k, $v) => new JsArray([$k, $v]),
                    $keysOf($o),
                    $valuesOf($o),
                ));
            }),
            'assign' => self::fn('assign', function (array $a, Interpreter $vm) {
                $target = self::arg($a, 0);
                if (!$target instanceof JsObject) {
                    throw $vm->error('TypeError', 'Object.assign target must be an object');
                }
                foreach (array_slice($a, 1) as $src) {
                    if ($src instanceof JsObject) {
                        foreach ($src->props as $k => $v) {
                            $target->props[$k] = $v;
                        }
                    } elseif ($src instanceof JsArray) {
                        foreach ($src->items as $k => $v) {
                            $target->props[$k] = $v;
                        }
                    }
                }
                return $target;
            }),
            'fromEntries' => self::fn('fromEntries', function (array $a, Interpreter $vm) {
                $src = self::arg($a, 0);
                if (!$src instanceof JsArray) {
                    throw $vm->error('TypeError', 'Object.fromEntries expects an array of [key, value] pairs');
                }
                $obj = new JsObject();
                foreach ($src->items as $entry) {
                    if (!$entry instanceof JsArray) {
                        throw $vm->error('TypeError', 'Iterator value is not an entry object');
                    }
                    $obj->props[Values::toPropertyKey(self::arg($entry->items, 0))] = self::arg($entry->items, 1);
                }
                return $obj;
            }),
        ]);
    }

    /** Fallback "prototype" methods for plain objects (own properties take precedence). */
    public static function objectMethod(string $name, JsObject $obj): ?NativeFunction
    {
        //CLD: 2026-10-20T21:40:00+03:00: only the listed methods (METHODS)
        if (!self::isMethod('object', $name)) {
            return null;
        }
        return match ($name) {
            'hasOwnProperty' => self::fn($name, fn (array $a) => array_key_exists(Values::toPropertyKey(self::arg($a, 0)), $obj->props)),
            'toString' => self::fn($name, fn () => '[object Object]'),
            default => null,
        };
    }

    // ================================================================== Array

    private static function arrayCtor(): NativeFunction
    {
        return self::fn('Array', function (array $a, Interpreter $vm) {
            if (count($a) === 1 && (is_int($a[0]) || is_float($a[0]))) {
                $n = $a[0];
                if (!is_int($n) || $n < 0) {
                    throw $vm->error('RangeError', 'Invalid array length');
                }
                $vm->checkArrayLength($n);
                return new JsArray($n === 0 ? [] : array_fill(0, $n, Undefined::value()));
            }
            return new JsArray($a);
        }, [
            'isArray' => self::fn('isArray', fn (array $a) => self::arg($a, 0) instanceof JsArray),
            'of' => self::fn('of', fn (array $a) => new JsArray($a)),
            'from' => self::fn('from', function (array $a, Interpreter $vm) {
                $src = self::arg($a, 0);
                $items = match (true) {
                    $src instanceof JsArray => $src->items,
                    is_string($src) => mb_str_split($src, 1, 'UTF-8'),
                    $src instanceof JsObject && isset($src->props['length']) => array_fill(0, min(
                        max(0, Values::toInteger($src->props['length'])),
                        1_000_000,
                    ), Undefined::value()),
                    default => [],
                };
                $map = self::arg($a, 1);
                if (Values::isCallable($map)) {
                    foreach ($items as $i => $v) {
                        $items[$i] = $vm->callFunction($map, [$v, $i]);
                    }
                }
                $vm->checkArrayLength(count($items));
                return new JsArray(array_values($items));
            }),
        ]);
    }

    public static function arrayMethod(string $name, JsArray $arr): ?NativeFunction
    {
        //CLD: 2026-10-20T21:40:00+03:00: only the listed methods (METHODS)
        if (!self::isMethod('array', $name)) {
            return null;
        }
        $impl = match ($name) {
            'push' => function (array $a, Interpreter $vm) use ($arr) {
                $vm->checkArrayLength(count($arr->items) + count($a));
                array_push($arr->items, ...$a);
                return count($arr->items);
            },
            'pop' => fn () => $arr->items === [] ? Undefined::value() : array_pop($arr->items),
            'shift' => fn () => $arr->items === [] ? Undefined::value() : array_shift($arr->items),
            'unshift' => function (array $a, Interpreter $vm) use ($arr) {
                $vm->checkArrayLength(count($arr->items) + count($a));
                array_unshift($arr->items, ...$a);
                return count($arr->items);
            },
            'slice' => function (array $a) use ($arr) {
                $len = count($arr->items);
                $s = self::relIndex(self::arg($a, 0), $len, 0);
                $e = self::relIndex(self::arg($a, 1), $len, $len);
                return new JsArray($e > $s ? array_slice($arr->items, $s, $e - $s) : []);
            },
            'splice' => function (array $a, Interpreter $vm) use ($arr) {
                $len = count($arr->items);
                $s = self::relIndex(self::arg($a, 0), $len, 0);
                $del = count($a) < 2 ? $len - $s : max(0, min(Values::toInteger($a[1]), $len - $s));
                $insert = array_slice($a, 2);
                $vm->checkArrayLength($len - $del + count($insert));
                return new JsArray(array_splice($arr->items, $s, $del, $insert));
            },
            'concat' => function (array $a, Interpreter $vm) use ($arr) {
                $items = $arr->items;
                foreach ($a as $v) {
                    if ($v instanceof JsArray) {
                        array_push($items, ...$v->items);
                    } else {
                        $items[] = $v;
                    }
                }
                $vm->checkArrayLength(count($items));
                return new JsArray($items);
            },
            'join' => function (array $a, Interpreter $vm) use ($arr) {
                $sep = self::arg($a, 0);
                return $vm->checkString(Values::join($arr, $sep instanceof Undefined ? ',' : Values::toString($sep)));
            },
            'toString' => fn () => Values::join($arr, ','),
            'reverse' => function () use ($arr) {
                $arr->items = array_reverse($arr->items);
                return $arr;
            },
            'indexOf' => function (array $a) use ($arr) {
                $from = self::relIndex(self::arg($a, 1), count($arr->items), 0);
                for ($i = $from, $n = count($arr->items); $i < $n; $i++) {
                    if (Values::strictEquals($arr->items[$i], self::arg($a, 0))) {
                        return $i;
                    }
                }
                return -1;
            },
            'lastIndexOf' => function (array $a) use ($arr) {
                for ($i = count($arr->items) - 1; $i >= 0; $i--) {
                    if (Values::strictEquals($arr->items[$i], self::arg($a, 0))) {
                        return $i;
                    }
                }
                return -1;
            },
            'includes' => function (array $a) use ($arr) {
                foreach ($arr->items as $v) {
                    if (Values::sameValueZero($v, self::arg($a, 0))) {
                        return true;
                    }
                }
                return false;
            },
            'at' => function (array $a) use ($arr) {
                $i = Values::toInteger(self::arg($a, 0));
                $i = $i < 0 ? count($arr->items) + $i : $i;
                return $i >= 0 && $i < count($arr->items) ? $arr->items[$i] : Undefined::value();
            },
            'fill' => function (array $a) use ($arr) {
                $len = count($arr->items);
                $s = self::relIndex(self::arg($a, 1), $len, 0);
                $e = self::relIndex(self::arg($a, 2), $len, $len);
                for ($i = $s; $i < $e; $i++) {
                    $arr->items[$i] = self::arg($a, 0);
                }
                return $arr;
            },
            'forEach', 'map', 'filter', 'find', 'findIndex', 'findLast', 'findLastIndex', 'some', 'every', 'flatMap'
                => fn (array $a, Interpreter $vm) => self::iterateArray($name, $arr, $a, $vm),
            'reduce', 'reduceRight' => function (array $a, Interpreter $vm) use ($arr, $name) {
                $cb = self::callback($a, $vm, $name);
                $indices = array_keys($arr->items);
                if ($name === 'reduceRight') {
                    $indices = array_reverse($indices);
                }
                if (count($a) >= 2) {
                    $acc = $a[1];
                } elseif ($indices === []) {
                    throw $vm->error('TypeError', 'Reduce of empty array with no initial value');
                } else {
                    $acc = $arr->items[array_shift($indices)];
                }
                foreach ($indices as $i) {
                    if ($i < count($arr->items)) {
                        $acc = $vm->callFunction($cb, [$acc, $arr->items[$i], $i, $arr]);
                    }
                }
                return $acc;
            },
            'sort' => function (array $a, Interpreter $vm) use ($arr) {
                $cmp = self::arg($a, 0);
                $defined = [];
                $undefined = [];
                foreach ($arr->items as $v) {
                    if ($v instanceof Undefined) {
                        $undefined[] = $v;
                    } else {
                        $defined[] = $v;
                    }
                }
                if (Values::isCallable($cmp)) {
                    usort($defined, function ($x, $y) use ($vm, $cmp) {
                        $r = Values::toNumber($vm->callFunction($cmp, [$x, $y]));
                        return $r < 0 ? -1 : ($r > 0 ? 1 : 0);
                    });
                } elseif ($cmp instanceof Undefined) {
                    usort($defined, fn ($x, $y) => strcmp(Values::toString($x), Values::toString($y)));
                } else {
                    throw $vm->error('TypeError', 'The comparison function must be either a function or undefined');
                }
                $arr->items = array_merge($defined, $undefined);
                return $arr;
            },
            'flat' => function (array $a, Interpreter $vm) use ($arr) {
                $depth = self::arg($a, 0) instanceof Undefined ? 1 : Values::toInteger($a[0]);
                $items = self::flatten($arr->items, $depth);
                $vm->checkArrayLength(count($items));
                return new JsArray($items);
            },
            default => null,
        };
        return $impl === null ? null : self::fn($name, $impl);
    }

    private static function flatten(array $items, int $depth): array
    {
        $out = [];
        foreach ($items as $v) {
            if ($v instanceof JsArray && $depth > 0) {
                array_push($out, ...self::flatten($v->items, $depth - 1));
            } else {
                $out[] = $v;
            }
        }
        return $out;
    }

    private static function iterateArray(string $name, JsArray $arr, array $a, Interpreter $vm): mixed
    {
        $cb = self::callback($a, $vm, $name);
        $len = count($arr->items);
        $out = [];
        $reverse = $name === 'findLast' || $name === 'findLastIndex';
        for ($k = 0; $k < $len; $k++) {
            $i = $reverse ? $len - 1 - $k : $k;
            if ($i >= count($arr->items)) {
                continue; // array shrank during iteration
            }
            $v = $arr->items[$i];
            $r = $vm->callFunction($cb, [$v, $i, $arr]);
            switch ($name) {
                case 'map':
                    $out[] = $r;
                    break;
                case 'filter':
                    if (Values::toBoolean($r)) {
                        $out[] = $v;
                    }
                    break;
                case 'flatMap':
                    if ($r instanceof JsArray) {
                        array_push($out, ...$r->items);
                    } else {
                        $out[] = $r;
                    }
                    break;
                case 'find':
                case 'findLast':
                    if (Values::toBoolean($r)) {
                        return $v;
                    }
                    break;
                case 'findIndex':
                case 'findLastIndex':
                    if (Values::toBoolean($r)) {
                        return $i;
                    }
                    break;
                case 'some':
                    if (Values::toBoolean($r)) {
                        return true;
                    }
                    break;
                case 'every':
                    if (!Values::toBoolean($r)) {
                        return false;
                    }
                    break;
            }
        }
        return match ($name) {
            'map', 'filter', 'flatMap' => new JsArray($out),
            'find', 'findLast', 'forEach' => Undefined::value(),
            'findIndex', 'findLastIndex' => -1,
            'some' => false,
            'every' => true,
        };
    }

    // ================================================================= String

    public static function stringMethod(string $name, string $s): ?NativeFunction
    {
        //CLD: 2026-10-20T21:40:00+03:00: only the listed methods (METHODS)
        if (!self::isMethod('string', $name)) {
            return null;
        }
        $len = fn () => mb_strlen($s, 'UTF-8');
        $impl = match ($name) {
            'charAt' => function (array $a) use ($s) {
                $i = Values::toInteger(self::arg($a, 0));
                return $i < 0 ? '' : mb_substr($s, $i, 1, 'UTF-8');
            },
            'charCodeAt', 'codePointAt' => function (array $a) use ($s, $name) {
                $i = Values::toInteger(self::arg($a, 0));
                $ch = $i < 0 ? '' : mb_substr($s, $i, 1, 'UTF-8');
                if ($ch === '') {
                    return $name === 'charCodeAt' ? NAN : Undefined::value();
                }
                return mb_ord($ch, 'UTF-8');
            },
            'at' => function (array $a) use ($s, $len) {
                $i = Values::toInteger(self::arg($a, 0));
                $i = $i < 0 ? $len() + $i : $i;
                $ch = $i < 0 ? '' : mb_substr($s, $i, 1, 'UTF-8');
                return $ch === '' ? Undefined::value() : $ch;
            },
            'indexOf' => function (array $a) use ($s, $len) {
                $from = max(0, min(Values::toInteger(self::arg($a, 1)), $len()));
                $r = mb_strpos($s, Values::toString(self::arg($a, 0)), $from, 'UTF-8');
                return $r === false ? -1 : $r;
            },
            'lastIndexOf' => function (array $a) use ($s) {
                $r = mb_strrpos($s, Values::toString(self::arg($a, 0)), 0, 'UTF-8');
                return $r === false ? -1 : $r;
            },
            'includes' => fn (array $a) => str_contains(
                mb_substr($s, max(0, Values::toInteger(self::arg($a, 1))), null, 'UTF-8'),
                Values::toString(self::arg($a, 0)),
            ),
            'startsWith' => fn (array $a) => str_starts_with(
                mb_substr($s, max(0, Values::toInteger(self::arg($a, 1))), null, 'UTF-8'),
                Values::toString(self::arg($a, 0)),
            ),
            'endsWith' => function (array $a) use ($s, $len) {
                $end = self::arg($a, 1) instanceof Undefined ? $len() : max(0, min(Values::toInteger($a[1]), $len()));
                return str_ends_with(mb_substr($s, 0, $end, 'UTF-8'), Values::toString(self::arg($a, 0)));
            },
            'slice' => function (array $a) use ($s, $len) {
                $l = $len();
                $b = self::relIndex(self::arg($a, 0), $l, 0);
                $e = self::relIndex(self::arg($a, 1), $l, $l);
                return $e > $b ? mb_substr($s, $b, $e - $b, 'UTF-8') : '';
            },
            'substring' => function (array $a) use ($s, $len) {
                $l = $len();
                $b = max(0, min(Values::toInteger(self::arg($a, 0)), $l));
                $e = self::arg($a, 1) instanceof Undefined ? $l : max(0, min(Values::toInteger($a[1]), $l));
                if ($b > $e) {
                    [$b, $e] = [$e, $b];
                }
                return mb_substr($s, $b, $e - $b, 'UTF-8');
            },
            'toUpperCase', 'toLocaleUpperCase' => fn () => mb_strtoupper($s, 'UTF-8'),
            'toLowerCase', 'toLocaleLowerCase' => fn () => mb_strtolower($s, 'UTF-8'),
            'trim' => fn () => Values::trim($s),
            'trimStart' => fn () => Values::trim($s, true, false),
            'trimEnd' => fn () => Values::trim($s, false, true),
            'split' => function (array $a, Interpreter $vm) use ($s) {
                $sep = self::arg($a, 0);
                $limit = self::arg($a, 1) instanceof Undefined ? PHP_INT_MAX : max(0, Values::toInteger($a[1]));
                //CLD: 2026-10-20T22:10:00+03:00: by a regular expression
                if ($sep instanceof JsRegExp) {
                    return self::regexpSplit($sep, $s, $limit, $vm);
                }
                if ($sep instanceof Undefined) {
                    $parts = [$s];
                } else {
                    $sep = Values::toString($sep);
                    $parts = $sep === '' ? mb_str_split($s, 1, 'UTF-8') : explode($sep, $s);
                }
                $parts = array_slice($parts, 0, $limit);
                $vm->checkArrayLength(count($parts));
                return new JsArray($parts);
            },
            'replace', 'replaceAll' => function (array $a, Interpreter $vm) use ($s, $name) {
                //CLD: 2026-10-20T22:10:00+03:00: by a regular expression; a replacement text may use $& $1 $<name> $` $' $$ (self::substitute; was used as it is)
                if (self::arg($a, 0) instanceof JsRegExp) {
                    return self::regexpReplace($a[0], $s, self::arg($a, 1), $name === 'replaceAll', $vm);
                }
                $search = Values::toString(self::arg($a, 0));
                $replacement = self::arg($a, 1);
                $index = JsRegExp::indexer($s);
                $out = '';
                $pos = 0;
                while (true) {
                    $found = strpos($s, $search, $pos);
                    if ($found === false) {
                        break;
                    }
                    $out .= substr($s, $pos, $found - $pos);
                    $out .= self::replacement($replacement, $s, ['start' => $found, 'end' => $found + strlen($search), 'captures' => [$search], 'named' => []], $index, $vm, false);
                    $vm->checkString($out);
                    $pos = $found + strlen($search);
                    if ($name === 'replace') {
                        break;
                    }
                    if ($search === '') {
                        if ($pos >= strlen($s)) {
                            $pos = strlen($s) + 1;
                            break;
                        }
                        $char = mb_substr(substr($s, $pos), 0, 1, 'UTF-8');
                        $out .= $char;
                        $pos += strlen($char);
                    }
                }
                return $pos <= strlen($s) ? $out . substr($s, $pos) : $out;
            },
            //CLD: 2026-10-20T22:10:00+03:00: match, matchAll, search (a text is taken as a pattern, as in JS)
            'match' => function (array $a, Interpreter $vm) use ($s) {
                $re = self::toRegExp(self::arg($a, 0), '', $vm);
                if (!$re->has('g')) {
                    $m = self::regexpExec($re, $s, $vm);
                    return $m === null ? null : self::matchResult($re, $s, $m);
                }
                $all = $re->matchAll($s, $vm);
                $re->lastIndex = 0;
                return $all === [] ? null : new JsArray(array_map(fn ($m) => $m['captures'][0], $all));
            },
            'matchAll' => function (array $a, Interpreter $vm) use ($s) {
                $arg = self::arg($a, 0);
                if ($arg instanceof JsRegExp && !$arg->has('g')) {
                    throw $vm->error('TypeError', 'matchAll must be called with a global RegExp');
                }
                $re = self::toRegExp($arg, 'g', $vm);
                $index = JsRegExp::indexer($s);
                return new JsArray(array_map(fn ($m) => self::matchResult($re, $s, $m, $index), $re->matchAll($s, $vm)));
            },
            'search' => function (array $a, Interpreter $vm) use ($s) {
                $m = self::toRegExp(self::arg($a, 0), '', $vm)->matchAt($s, 0, $vm);
                return $m === null ? -1 : mb_strlen(substr($s, 0, $m['start']), 'UTF-8');
            },
            'repeat' => function (array $a, Interpreter $vm) use ($s) {
                $n = Values::toNumber(self::arg($a, 0));
                if ((is_float($n) && !is_finite($n)) || $n < 0) {
                    throw $vm->error('RangeError', 'Invalid count value');
                }
                $n = (int) $n;
                $vm->checkLength(strlen($s) * $n);
                return str_repeat($s, $n);
            },
            'padStart', 'padEnd' => function (array $a, Interpreter $vm) use ($s, $name, $len) {
                $target = Values::toInteger(self::arg($a, 0));
                $fill = self::arg($a, 1) instanceof Undefined ? ' ' : Values::toString($a[1]);
                $missing = $target - $len();
                if ($missing <= 0 || $fill === '') {
                    return $s;
                }
                $vm->checkLength(strlen($s) + $missing * 4);
                $pad = mb_substr(str_repeat($fill, (int) ceil($missing / mb_strlen($fill, 'UTF-8'))), 0, $missing, 'UTF-8');
                return $vm->checkString($name === 'padStart' ? $pad . $s : $s . $pad);
            },
            'concat' => fn (array $a, Interpreter $vm) => $vm->checkString($s . implode('', array_map([Values::class, 'toString'], $a))),
            'localeCompare' => fn (array $a) => strcmp($s, Values::toString(self::arg($a, 0))) <=> 0,
            'toString', 'valueOf' => fn () => $s,
            default => null,
        };
        return $impl === null ? null : self::fn($name, $impl);
    }

    // ================================================================= Number

    public static function numberMethod(string $name, int|float $n): ?NativeFunction
    {
        //CLD: 2026-10-20T21:40:00+03:00: only the listed methods (METHODS)
        if (!self::isMethod('number', $name)) {
            return null;
        }
        $impl = match ($name) {
            'toFixed' => function (array $a, Interpreter $vm) use ($n) {
                $d = Values::toInteger(self::arg($a, 0));
                if ($d < 0 || $d > 100) {
                    throw $vm->error('RangeError', 'toFixed() digits argument must be between 0 and 100');
                }
                if (!is_finite((float) $n) || abs($n) >= 1e21) {
                    return Values::numberToString($n);
                }
                return number_format((float) $n, $d, '.', '');
            },
            'toString' => function (array $a, Interpreter $vm) use ($n) {
                $radix = self::arg($a, 0) instanceof Undefined ? 10 : Values::toInteger($a[0]);
                if ($radix < 2 || $radix > 36) {
                    throw $vm->error('RangeError', 'toString() radix must be between 2 and 36');
                }
                if ($radix === 10 || !is_int(Values::num($n))) {
                    return Values::numberToString($n);
                }
                $i = (int) Values::num($n);
                return ($i < 0 ? '-' : '') . base_convert((string) abs($i), 10, $radix);
            },
            'valueOf' => fn () => $n,
            default => null,
        };
        return $impl === null ? null : self::fn($name, $impl);
    }

    // =================================================================== Date

    //CLD: 2026-10-20T21:01:44+03:00: Date: Date() is now, Date(ms), Date("2026-10-20T10:00"), Date(date) a copy, Date(y, m, d?, h?, mi?, s?, ms?) in local time
    //(months 0-11, years 0-99 are 1900-1999); always a Date object (JS's Date() without `new` gives a string)
    private static function dateCtor(): NativeFunction
    {
        $num = fn (array $a, int $i, float $default) => array_key_exists($i, $a) ? (float) Values::toNumber($a[$i]) : $default;
        // the time of year, month, ... from the arguments (local or UTC fields)
        $fromFields = function (array $a) use ($num): float {
            $y = $num($a, 0, NAN);
            if (is_finite($y) && self::trunc($y) >= 0 && self::trunc($y) <= 99) {
                $y = 1900 + self::trunc($y);
            }
            return JsDate::makeDate(
                JsDate::makeDay($y, $num($a, 1, 0), $num($a, 2, 1)),
                JsDate::makeTime($num($a, 3, 0), $num($a, 4, 0), $num($a, 5, 0), $num($a, 6, 0)),
            );
        };

        return self::fn('Date', function (array $a) use ($fromFields) {
            if ($a === []) {
                return new JsDate(self::now());
            }
            if (count($a) === 1) {
                $v = $a[0];
                if ($v instanceof JsDate) {
                    return new JsDate($v->time);
                }
                $v = Values::toPrimitive($v);
                return new JsDate(is_string($v) ? JsDate::parse($v) : JsDate::clip((float) Values::toNumber($v)));
            }
            return new JsDate(JsDate::clip(JsDate::utc($fromFields($a))));
        }, [
            'now' => self::fn('now', fn () => Values::num(self::now())),
            'parse' => self::fn('parse', fn (array $a) => Values::num(JsDate::parse(Values::toString(self::arg($a, 0))))),
            'UTC' => self::fn('UTC', fn (array $a) => Values::num(JsDate::clip($fromFields($a)))),
        ]);
    }

    private static function now(): float
    {
        return floor(microtime(true) * 1000);
    }

    private static function trunc(float $x): float
    {
        return $x < 0 ? ceil($x) : floor($x);
    }

    /** Date methods: getters, setters (they change the date and return its new time) and the string forms. */
    public static function dateMethod(string $name, JsDate $date): ?NativeFunction
    {
        //CLD: 2026-10-20T21:40:00+03:00: only the listed methods (METHODS)
        if (!self::isMethod('date', $name)) {
            return null;
        }
        static $fields = ['FullYear' => 'year', 'Month' => 'month', 'Date' => 'date', 'Day' => 'day',
            'Hours' => 'hours', 'Minutes' => 'minutes', 'Seconds' => 'seconds', 'Milliseconds' => 'ms'];
        // setter => [first field set, how many arguments it takes] (in the order of $order)
        static $setters = ['FullYear' => [0, 3], 'Month' => [1, 2], 'Date' => [2, 1], 'Hours' => [3, 4],
            'Minutes' => [4, 3], 'Seconds' => [5, 2], 'Milliseconds' => [6, 1]];
        static $order = ['year', 'month', 'date', 'hours', 'minutes', 'seconds', 'ms'];
        static $strings = ['toString' => 'string', 'toDateString' => 'date', 'toTimeString' => 'time', 'toUTCString' => 'utc',
            'toGMTString' => 'utc', 'toLocaleString' => 'locale', 'toLocaleDateString' => 'localeDate', 'toLocaleTimeString' => 'localeTime'];

        $impl = null;
        if (preg_match('/^(get|set)(UTC)?(\w+)$/', $name, $m) && isset($fields[$m[3]]) && ($m[1] === 'get' || isset($setters[$m[3]]))) {
            $utc = $m[2] !== '';
            $field = $fields[$m[3]];
            $impl = $m[1] === 'get'
                ? fn () => $date->isValid() ? JsDate::fields($utc ? $date->time : JsDate::localTime($date->time))[$field] : NAN
                : function (array $a) use ($date, $utc, $m, $setters, $order) {
                    [$first, $count] = $setters[$m[3]];
                    if (!$date->isValid() && $first !== 0) {
                        return NAN;
                    }
                    // setFullYear on an Invalid Date starts from +0 (taken as a local time)
                    $values = JsDate::fields($date->isValid() ? ($utc ? $date->time : JsDate::localTime($date->time)) : 0.0);
                    $values = array_map('floatval', array_intersect_key($values, array_flip($order)));
                    for ($i = 0; $i < $count; $i++) {
                        if ($i === 0 || array_key_exists($i, $a)) {
                            $values[$order[$first + $i]] = (float) Values::toNumber(self::arg($a, $i));
                        }
                    }
                    $new = JsDate::makeDate(
                        JsDate::makeDay($values['year'], $values['month'], $values['date']),
                        JsDate::makeTime($values['hours'], $values['minutes'], $values['seconds'], $values['ms']),
                    );
                    $date->time = JsDate::clip($utc ? $new : JsDate::utc($new));
                    return Values::num($date->time);
                };
        } elseif (isset($strings[$name])) {
            $impl = fn () => $date->format($strings[$name]);
        } else {
            $impl = match ($name) {
                'getTime', 'valueOf' => fn () => Values::num($date->time),
                'setTime' => function (array $a) use ($date) {
                    $date->time = JsDate::clip((float) Values::toNumber(self::arg($a, 0)));
                    return Values::num($date->time);
                },
                'getTimezoneOffset' => fn () => $date->isValid() ? Values::num(-JsDate::offsetAt($date->time) / 60000) : NAN,
                'toISOString' => function (array $a, Interpreter $vm) use ($date) {
                    return $date->toIso() ?? throw $vm->error('RangeError', 'Invalid time value');
                },
                'toJSON' => fn () => $date->toIso(),
                default => null,
            };
        }
        return $impl === null ? null : self::fn($name, $impl);
    }

    // ================================================================= RegExp

    //CLD: 2026-10-20T22:10:00+03:00: RegExp(pattern, flags?): pattern is a text or a RegExp (copied, with its flags unless flags are given)
    private static function regexpCtor(): NativeFunction
    {
        return self::fn('RegExp', function (array $a, Interpreter $vm) {
            $pattern = self::arg($a, 0);
            $flags = self::arg($a, 1);
            if ($pattern instanceof JsRegExp) {
                return self::regexp($pattern->source, $flags instanceof Undefined ? $pattern->flags : Values::toString($flags), $vm);
            }
            return self::regexp($pattern instanceof Undefined ? '' : Values::toString($pattern), $flags instanceof Undefined ? '' : Values::toString($flags), $vm);
        });
    }

    /** A new RegExp; a bad pattern or flags is a SyntaxError. */
    public static function regexp(string $source, string $flags, Interpreter $vm): JsRegExp
    {
        try {
            return new JsRegExp($source, $flags);
        } catch (\InvalidArgumentException $e) {
            throw $vm->error('SyntaxError', 'Invalid regular expression: /' . $source . '/' . $flags . ': ' . $e->getMessage());
        }
    }

    /** The argument of match / matchAll / search as a RegExp: a RegExp as it is, else a text as a pattern (with $flags). */
    private static function toRegExp(mixed $v, string $flags, Interpreter $vm): JsRegExp
    {
        if ($v instanceof JsRegExp) {
            return $v;
        }
        return self::regexp($v instanceof Undefined ? '' : Values::toString($v), $flags, $vm);
    }

    /** RegExp methods: exec, test, toString (the properties are in Interpreter::getProperty). */
    public static function regexpMethod(string $name, JsRegExp $re): ?NativeFunction
    {
        if (!self::isMethod('regexp', $name)) {
            return null;
        }
        return self::fn($name, match ($name) {
            'exec' => function (array $a, Interpreter $vm) use ($re) {
                $s = Values::toString(self::arg($a, 0));
                $m = self::regexpExec($re, $s, $vm);
                return $m === null ? null : self::matchResult($re, $s, $m);
            },
            'test' => fn (array $a, Interpreter $vm) => self::regexpExec($re, Values::toString(self::arg($a, 0)), $vm) !== null,
            default => fn () => $re->toString(),
        });
    }

    /** RegExpBuiltinExec: with the g or y flag from lastIndex (then moved past the match, or back to 0), else from the start. */
    private static function regexpExec(JsRegExp $re, string $s, Interpreter $vm): ?array
    {
        $moving = $re->has('g') || $re->has('y');
        $from = 0;
        if ($moving) {
            $from = max(0, Values::toInteger($re->lastIndex));
            if ($from > mb_strlen($s, 'UTF-8')) {
                $re->lastIndex = 0;
                return null;
            }
        }
        $m = $re->matchAt($s, $from === 0 ? 0 : JsRegExp::byteOffset($s, $from), $vm);
        if ($moving) {
            $re->lastIndex = $m === null ? 0 : mb_strlen(substr($s, 0, $m['end']), 'UTF-8');
        }
        return $m;
    }

    /** A match as JS gives it: [match, ...groups] with index, input, groups (and indices with the d flag). */
    private static function matchResult(JsRegExp $re, string $s, array $m, ?\Closure $index = null): JsArray
    {
        $index ??= JsRegExp::indexer($s);
        $props = [
            'index' => $index($m['start']),
            'input' => $s,
            'groups' => $re->names === [] ? Undefined::value() : new JsObject(array_map(fn ($c) => $c ?? Undefined::value(), $m['named'])),
        ];
        if ($re->has('d')) {
            $props['indices'] = new JsArray(array_map(
                fn ($span) => $span === null ? Undefined::value() : new JsArray([$index($span[0]), $index($span[1])]),
                $m['spans'],
            ));
        }
        return new JsArray(array_map(fn ($c) => $c ?? Undefined::value(), $m['captures']), $props);
    }

    private static function regexpReplace(JsRegExp $re, string $s, mixed $replacement, bool $all, Interpreter $vm): string
    {
        if ($all && !$re->has('g')) {
            throw $vm->error('TypeError', 'replaceAll must be called with a global RegExp');
        }
        if ($re->has('g')) {
            $matches = $re->matchAll($s, $vm);
            $re->lastIndex = 0;
        } else {
            $m = self::regexpExec($re, $s, $vm);
            $matches = $m === null ? [] : [$m];
        }
        $index = JsRegExp::indexer($s);
        $out = '';
        $pos = 0;
        foreach ($matches as $m) {
            $out .= substr($s, $pos, $m['start'] - $pos) . self::replacement($replacement, $s, $m, $index, $vm, $re->names !== []);
            $vm->checkString($out);
            $pos = $m['end'];
        }
        return $vm->checkString($out . substr($s, $pos));
    }

    /** What replaces match $m: the function's result (called with the match, the groups, the index, the text and, with named groups, groups) or the text with its $ patterns. */
    private static function replacement(mixed $replacement, string $s, array $m, \Closure $index, Interpreter $vm, bool $named): string
    {
        if (!Values::isCallable($replacement)) {
            return self::substitute(Values::toString($replacement), $s, $m);
        }
        $args = array_map(fn ($c) => $c ?? Undefined::value(), $m['captures']);
        $args[] = $index($m['start']);
        $args[] = $s;
        if ($named) {
            $args[] = new JsObject(array_map(fn ($c) => $c ?? Undefined::value(), $m['named']));
        }
        return Values::toString($vm->callFunction($replacement, $args));
    }

    /** GetSubstitution: $$ is $, $& the match, $` / $' the text before / after it, $1..$99 a group, $<name> a named group. */
    private static function substitute(string $replacement, string $s, array $m): string
    {
        if (!str_contains($replacement, '$')) {
            return $replacement;
        }
        $count = count($m['captures']) - 1;
        return (string) preg_replace_callback('/\$(\$|&|`|\'|\d{1,2}|<([^>]*)>)/', function ($x) use ($s, $m, $count) {
            $t = $x[1];
            switch ($t[0]) {
                case '$': return '$';
                case '&': return $m['captures'][0];
                case '`': return substr($s, 0, $m['start']);
                case "'": return substr($s, $m['end']);
                case '<': return $m['named'] === [] ? $x[0] : (string) ($m['named'][$x[2]] ?? '');
            }
            if (strlen($t) === 2 && (int) $t >= 1 && (int) $t <= $count) {
                return (string) $m['captures'][(int) $t];
            }
            if ((int) $t[0] >= 1 && (int) $t[0] <= $count) {
                return $m['captures'][(int) $t[0]] . substr($t, 1);
            }
            return $x[0];
        }, $replacement);
    }

    /** split by a regular expression: the groups go into the result too; an empty match at the start of a piece or at the end splits nothing. */
    private static function regexpSplit(JsRegExp $re, string $s, int $limit, Interpreter $vm): JsArray
    {
        if ($limit === 0) {
            return new JsArray([]);
        }
        if ($re->has('y')) {
            $re = new JsRegExp($re->source, str_replace('y', '', $re->flags)); // split searches, it is not anchored
        }
        if ($s === '') {
            return new JsArray($re->matchAt($s, 0, $vm) === null ? [$s] : []);
        }
        $out = [];
        $p = 0;
        foreach ($re->matchAll($s, $vm) as $m) {
            if ($m['start'] >= strlen($s)) {
                break;
            }
            if ($m['end'] === $p) {
                continue;
            }
            foreach (array_merge([substr($s, $p, $m['start'] - $p)], array_slice($m['captures'], 1)) as $piece) {
                $out[] = $piece ?? Undefined::value();
                if (count($out) >= $limit) {
                    return new JsArray($out);
                }
            }
            $p = $m['end'];
        }
        $out[] = substr($s, $p);
        $vm->checkArrayLength(count($out));
        return new JsArray($out);
    }

    // ========================================================== parse helpers

    private static function parseInt(string $s, mixed $radixArg): int|float
    {
        $s = Values::trim($s, true, false);
        $sign = 1;
        if ($s !== '' && ($s[0] === '-' || $s[0] === '+')) {
            $sign = $s[0] === '-' ? -1 : 1;
            $s = substr($s, 1);
        }
        $radix = $radixArg instanceof Undefined ? 0 : Values::toInt32($radixArg);
        if ($radix === 0) {
            $radix = 10;
            if (stripos($s, '0x') === 0) {
                $radix = 16;
                $s = substr($s, 2);
            }
        } elseif ($radix === 16 && stripos($s, '0x') === 0) {
            $s = substr($s, 2);
        }
        if ($radix < 2 || $radix > 36) {
            return NAN;
        }
        $value = 0;
        $any = false;
        $l = strlen($s);
        for ($i = 0; $i < $l; $i++) {
            $c = strtolower($s[$i]);
            $d = ctype_digit($c) ? ord($c) - 48 : (ctype_lower($c) ? ord($c) - 87 : 99);
            if ($d >= $radix) {
                break;
            }
            $value = $value * $radix + $d;
            $any = true;
        }
        return $any ? Values::num($sign * $value) : NAN;
    }

    private static function parseFloat(string $s): int|float
    {
        $s = Values::trim($s, true, false);
        if (preg_match('/^[+-]?Infinity/', $s)) {
            return $s[0] === '-' ? -INF : INF;
        }
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/', $s, $m)) {
            return Values::num((float) $m[0]);
        }
        return NAN;
    }
}
