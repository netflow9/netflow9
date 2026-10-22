<?php

declare(strict_types=1);

namespace sys\J;

use sys\J\Runtime\ChainShortCircuit;
use sys\J\Runtime\JsArray;
use sys\J\Runtime\JsDate;
use sys\J\Runtime\JsRegExp;
use sys\J\Runtime\JsFunction;
use sys\J\Runtime\JsObject;
use sys\J\Runtime\JsThrow;
use sys\J\Runtime\NativeFunction;
use sys\J\Runtime\Scope;
use sys\J\Runtime\Undefined;
use sys\J\Runtime\Values;

/**
 * Tree-walking VM for the AST produced by Parser.
 *
 * Usage:
 *   $vm = new Interpreter(['maxSteps' => 100_000]);
 *   $result = $vm->run(Parser::parse($code), ['user' => $user, 'log' => fn ($m) => ...]);
 *
 * Sandboxing:
 *  - Scripts can only reach what you put in $context plus the built-ins (see Builtins).
 *  - Only \Closure values become callable; string/array callables are rejected, so
 *    'system' or ['Foo', 'bar'] can never be invoked from a script.
 *  - No prototypes, no `this`, no `new`, no classes, no modules, no eval.
 *  - Step, time, call-depth, string-length and array-length limits.
 */
final class Interpreter
{
    private const NORMAL = 0;
    private const BREAK = 1;
    private const CONTINUE = 2;
    private const RETURN = 3;

    private int $maxSteps;
    private int $maxCallDepth;
    private float $timeLimit;
    private int $maxStringLength;
    private int $maxArrayLength;
    private bool $wrapHostExceptions;
    //CLD: 2026-10-13T15:30:33+03:00: host names the script may change (writable; except readOnly), assignments to undeclared names create top-level ones (implicitGlobals); globals() after a run
    private bool $writable;
    /** @var array<string, true> */
    private array $readOnly;
    private bool $implicitGlobals;
    //CLD: 2026-10-20T21:40:00+03:00: dates given to PHP (toPhp) and put in {{ }} text: a PHP date() format in local time, or null for the ISO text of toJSON()
    private ?string $dateFormat;
    private ?Scope $globalScope = null;

    private int $steps = 0;
    private int $callDepth = 0;
    private float $deadline = 0.0;
    private mixed $returnValue = null;
    private mixed $completion = null;
    private int $offset = 0;
    /** @var list<string> */
    private array $output = [];

    /**
     * @param array{
     *   maxSteps?: int,            // statements + calls per run (default 1,000,000)
     *   maxCallDepth?: int,        // default 200
     *   timeLimit?: float,         // seconds, 0 = none (default 5.0)
     *   maxStringLength?: int,     // bytes (default 10 MB)
     *   maxArrayLength?: int,      // default 1,000,000
     *   wrapHostExceptions?: bool, // PHP exceptions from host functions become catchable JS Errors (default true)
     *   writable?: bool,           // the context's names can be assigned (default false: read-only bindings)
     *   readOnly?: list<string>,   // with writable: these stay read-only
     *   implicitGlobals?: bool,    // assigning an undeclared name creates it beside the context's names (default false: ReferenceError)
     *   dateFormat?: ?string,      // dates as PHP values and in {{ }} text: a date() format in local time, e.g. DATE_ATOM (default null: `2026-10-20T18:05:03.000Z`)
     * } $options
     */
    public function __construct(array $options = [])
    {
        $this->maxSteps = $options['maxSteps'] ?? 1_000_000;
        $this->maxCallDepth = $options['maxCallDepth'] ?? 200;
        $this->timeLimit = (float) ($options['timeLimit'] ?? 5.0);
        $this->maxStringLength = $options['maxStringLength'] ?? 10 * 1024 * 1024;
        $this->maxArrayLength = $options['maxArrayLength'] ?? 1_000_000;
        $this->wrapHostExceptions = $options['wrapHostExceptions'] ?? true;
        $this->writable = (bool) ($options['writable'] ?? false);
        $this->readOnly = array_fill_keys(array_map('strval', $options['readOnly'] ?? []), true);
        $this->implicitGlobals = (bool) ($options['implicitGlobals'] ?? false);
        $this->dateFormat = $options['dateFormat'] ?? null;
    }

    /**
     * CLD: 2026-10-13T15:30:33+03:00: the top-level names after the last run (the context's and, with implicitGlobals, those the script created by assignment), as PHP values;
     * the script's own declarations (let, const, var, functions) are not among them
     *
     * @return array<string, mixed>
     */
    public function globals(): array
    {
        $out = [];
        foreach ($this->globalScope?->vars ?? [] as $name => $value) {
            $out[$name] = $this->toPhp($value);
        }
        return $out;
    }

    // ================================================================ public API

    /**
     * Executes a program and returns its result converted to PHP values.
     * The result is the argument of a top-level `return`, otherwise the value
     * of the last expression statement executed (like eval()).
     *
     * @param array<string, mixed> $context host variables visible to the script (read-only bindings)
     * @throws ScriptError   uncaught script exception or syntax errors
     * @throws LimitExceeded step/time limit hit
     */
    public function run(ParseResult|array $program, array $context = []): mixed
    {
        return $this->toPhp($this->execute($program, $context));
    }

    /** Same as run() but returns the raw JS value (JsObject, JsArray, Undefined, ...). */
    public function execute(ParseResult|array $program, array $context = []): mixed
    {
        $parsed = $program instanceof ParseResult ? $program : null;
        $ast = $parsed?->ast ?? $program;

        if ($parsed !== null && $parsed->hasErrors()) {
            $first = $parsed->errors()[0];
            throw new ScriptError(
                sprintf('SyntaxError: %s (line %d, column %d)', $first['message'], $first['line'], $first['column']),
                null,
                $first['line'],
                $first['column'],
                $parsed->diagnostics,
            );
        }
        //CLD: 2026-10-13T12:17:47+03:00: also a Template (Parser::parse mode `template`)
        if (!in_array($ast['type'] ?? null, ['Program', 'Template'], true)) {
            throw new \InvalidArgumentException('Expected a Program or Template AST node');
        }

        $builtins = new Scope();
        Builtins::install($builtins, $this);
        $globals = new Scope($builtins);
        foreach ($context as $name => $value) {
            $name = (string) $name;
            $globals->vars[$name] = $value instanceof \Closure
                ? $this->wrapClosure($value, $name)
                : $this->toJs($value);
            if (!$this->writable || isset($this->readOnly[$name])) {
                $globals->consts[$name] = true;
            }
        }
        $scope = new Scope($globals);
        $this->globalScope = $globals;

        $this->steps = 0;
        $this->callDepth = 0;
        $this->output = [];
        $this->deadline = $this->timeLimit > 0 ? microtime(true) + $this->timeLimit : 0.0;
        $this->completion = Undefined::value();
        $this->offset = 0;

        try {
            if ($ast['type'] === 'Template') {
                return $this->evalTemplate($ast, $scope);
            }
            foreach ($ast['varNames'] ?? [] as $name) {
                $scope->vars[$name] = Undefined::value();
            }
            $c = $this->execStatements($ast['body'], $scope);
            return $c === self::RETURN ? $this->returnValue : $this->completion;
        } catch (JsThrow $e) {
            $pos = $parsed !== null && $e->offset >= 0 ? $parsed->positionAt($e->offset) : null;
            $where = $pos !== null ? sprintf(' (line %d, column %d)', $pos['line'], $pos['column']) : '';
            throw new ScriptError(
                'Uncaught ' . $e->getMessage() . $where,
                $this->toPhp($e->value),
                $pos['line'] ?? null,
                $pos['column'] ?? null,
            );
        }
    }

    //CLD: 2026-10-13T12:17:47+03:00: a template's value: a single {{ }} with only blanks around it gives the expression's value as it is (number, list, object, ...); else the text with each
    //part's value as text (lists and objects as JSON, null / undefined as nothing)
    private function evalTemplate(array $ast, Scope $scope): mixed
    {
        $parts = $ast['parts'] ?? [];
        $expressions = array_values(array_filter($parts, fn ($p) => $p['type'] === 'TemplateExpression'));
        $blanks = array_filter($parts, fn ($p) => $p['type'] === 'TemplateText' && trim($p['value']) !== '') === [];
        if (count($expressions) === 1 && $blanks) {
            $this->offset = $expressions[0]['expression']['start'];
            return $this->evalNode($expressions[0]['expression'], $scope);
        }
        $out = '';
        foreach ($parts as $p) {
            if ($p['type'] === 'TemplateText') {
                $out .= $p['value'];
                continue;
            }
            //errors are placed at the part (as statements are, exec())
            $this->offset = $p['expression']['start'];
            $value = $this->evalNode($p['expression'], $scope);
            if ($value instanceof Undefined || $value === null) {
                continue;
            }
            //CLD: 2026-10-20T21:01:44+03:00: a date as its ISO text (as in JSON)
            $out .= $value instanceof JsDate ? (string) $this->toPhp($value)
                : ($value instanceof JsArray || $value instanceof JsObject
                ? Values::json($this->toPhp($value)) //CLD: 2026-10-20T22:45:00+03:00: util\JSON (was json_encode)
                : Values::toString($value));
            $this->checkString($out);
        }
        return $out;
    }

    //CLD: 2026-10-20T22:10:00+03:00: for long native work (a regular expression): the time limit, checked now
    /** @throws LimitExceeded */
    public function checkTime(): void
    {
        if ($this->deadline > 0 && microtime(true) > $this->deadline) {
            throw new LimitExceeded("Time limit of {$this->timeLimit}s exceeded");
        }
    }

    /** Lines written with console.log() during the last run. */
    public function getOutput(): array
    {
        return $this->output;
    }

    public function log(string $line): void
    {
        $this->output[] = $line;
    }

    /** Calls a script or native function with JS-valued arguments. */
    public function callFunction(mixed $fn, array $args): mixed
    {
        if ($fn instanceof NativeFunction) {
            $this->tick();
            try {
                return ($fn->fn)($args, $this);
            } catch (JsThrow | LimitExceeded | ChainShortCircuit $e) {
                throw $e;
            } catch (\Throwable $e) {
                if (!$this->wrapHostExceptions) {
                    throw $e;
                }
                throw $this->error('Error', $e->getMessage());
            }
        }
        if (!$fn instanceof JsFunction) {
            throw $this->error('TypeError', Values::typeOf($fn) . ' is not a function');
        }

        if ($this->callDepth >= $this->maxCallDepth) {
            throw $this->error('RangeError', 'Maximum call stack size exceeded');
        }
        $this->tick();
        $this->callDepth++;
        $savedOffset = $this->offset;
        try {
            $node = $fn->node;
            $scope = new Scope($fn->scope);
            foreach ($node['params'] as $i => $p) {
                switch ($p['type']) {
                    case 'Identifier':
                        $scope->vars[$p['name']] = array_key_exists($i, $args) ? $args[$i] : Undefined::value();
                        break;
                    case 'AssignmentPattern':
                        $v = array_key_exists($i, $args) ? $args[$i] : Undefined::value();
                        if ($v instanceof Undefined) {
                            $v = $this->evalNode($p['right'], $scope);
                        }
                        $scope->vars[$p['left']['name']] = $v;
                        break;
                    case 'RestElement':
                        $scope->vars[$p['argument']['name']] = new JsArray(array_slice($args, $i));
                        break;
                    default:
                        throw $this->error('SyntaxError', 'Unsupported parameter');
                }
            }
            foreach ($node['varNames'] ?? [] as $name) {
                if (!array_key_exists($name, $scope->vars)) {
                    $scope->vars[$name] = Undefined::value();
                }
            }
            if (!empty($node['expression'])) {
                return $this->evalNode($node['body'], $scope);
            }
            $c = $this->execStatements($node['body']['body'], $scope);
            return $c === self::RETURN ? $this->returnValue : Undefined::value();
        } finally {
            $this->callDepth--;
            $this->offset = $savedOffset;
        }
    }

    /** Creates a catchable JS error (Error/TypeError/RangeError/...) at the current position. */
    public function error(string $type, string $message): JsThrow
    {
        $obj = new JsObject(['name' => $type, 'message' => $message]);
        return new JsThrow($obj, "$type: $message", $this->offset);
    }

    public function checkString(string $s): string
    {
        $this->checkLength(strlen($s));
        return $s;
    }

    /** Call BEFORE building a string of $bytes bytes. */
    public function checkLength(int|float $bytes): void
    {
        if ($bytes > $this->maxStringLength) {
            throw $this->error('RangeError', 'Invalid string length');
        }
    }

    public function checkArrayLength(int $n): void
    {
        if ($n > $this->maxArrayLength) {
            throw $this->error('RangeError', 'Invalid array length');
        }
    }

    // ============================================================ PHP <-> JS

    /** Converts a host (PHP) value into a JS value. */
    public function toJs(mixed $v, int $depth = 0): mixed
    {
        if ($depth > 64) {
            throw new \InvalidArgumentException('Host value is nested too deeply (or cyclic)');
        }
        if ($v === null || is_bool($v) || is_int($v) || is_float($v) || is_string($v)) {
            return $v;
        }
        if (is_array($v)) {
            if (array_is_list($v)) {
                return new JsArray(array_map(fn ($x) => $this->toJs($x, $depth + 1), $v));
            }
            $props = [];
            foreach ($v as $k => $x) {
                $props[(string) $k] = $this->toJs($x, $depth + 1);
            }
            return new JsObject($props);
        }
        if ($v instanceof JsObject || $v instanceof JsArray || $v instanceof JsFunction
            || $v instanceof NativeFunction || $v instanceof Undefined || $v instanceof JsDate || $v instanceof JsRegExp) {
            return $v;
        }
        if ($v instanceof \Closure) {
            return $this->wrapClosure($v);
        }
        if ($v instanceof \stdClass) {
            $props = [];
            foreach (get_object_vars($v) as $k => $x) {
                $props[(string) $k] = $this->toJs($x, $depth + 1);
            }
            return new JsObject($props);
        }
        if ($v instanceof \JsonSerializable) {
            return $this->toJs($v->jsonSerialize(), $depth + 1);
        }
        //CLD: 2026-10-20T21:01:44+03:00: a JS Date (was the ATOM text)
        if ($v instanceof \DateTimeInterface) {
            return new JsDate(JsDate::clip((float) $v->format('U') * 1000 + (int) $v->format('v')));
        }
        if ($v instanceof \BackedEnum) {
            return $v->value;
        }
        throw new \InvalidArgumentException('Unsupported host value of type ' . get_debug_type($v));
    }

    /** Converts a JS value into a plain PHP value (functions become \Closure). */
    public function toPhp(mixed $v, int $depth = 0): mixed
    {
        if ($depth > 64) {
            throw new \RuntimeException('Value is nested too deeply (or cyclic)');
        }
        if ($v instanceof Undefined) {
            return null;
        }
        //CLD: 2026-10-20T21:01:44+03:00: a date as its ISO text (toJSON(): `2026-10-20T18:05:03.000Z`; null for an Invalid Date)
        //CLD: 2026-10-20T22:10:00+03:00: a regular expression as its literal text (/a+/g)
        if ($v instanceof JsRegExp) {
            return $v->toString();
        }
        if ($v instanceof JsDate) {
            return $this->dateFormat === null ? $v->toIso() : $v->formatPhp($this->dateFormat); //CLD: 2026-10-20T21:40:00+03:00: or in the dateFormat
        }
        if ($v instanceof JsArray) {
            return array_map(fn ($x) => $this->toPhp($x, $depth + 1), $v->items);
        }
        if ($v instanceof JsObject) {
            $out = [];
            foreach ($v->props as $k => $x) {
                $out[$k] = $this->toPhp($x, $depth + 1);
            }
            return $out;
        }
        if ($v instanceof JsFunction || $v instanceof NativeFunction) {
            return function (mixed ...$args) use ($v) {
                $jsArgs = array_map(fn ($a) => $this->toJs($a), array_values($args));
                return $this->toPhp($this->callFunction($v, $jsArgs));
            };
        }
        return $v;
    }

    /** Wraps a host closure: arguments are converted to PHP, the result back to JS. */
    public function wrapClosure(\Closure $c, string $name = ''): NativeFunction
    {
        return new NativeFunction(function (array $args, Interpreter $vm) use ($c) {
            return $vm->toJs($c(...array_map(fn ($a) => $vm->toPhp($a), $args)));
        }, $name);
    }

    // ============================================================= statements

    private function tick(): void
    {
        if (++$this->steps > $this->maxSteps) {
            throw new LimitExceeded("Step limit of {$this->maxSteps} exceeded");
        }
        if ($this->deadline > 0 && ($this->steps & 1023) === 0 && microtime(true) > $this->deadline) {
            throw new LimitExceeded("Time limit of {$this->timeLimit}s exceeded");
        }
    }

    private function execStatements(array $list, Scope $scope): int
    {
        foreach ($list as $s) {
            if ($s['type'] === 'FunctionDeclaration' && $s['id'] !== null) {
                $name = $s['id']['name'];
                $scope->vars[$name] = new JsFunction($s, $scope, $name);
            }
        }
        foreach ($list as $s) {
            $c = $this->exec($s, $scope);
            if ($c !== self::NORMAL) {
                return $c;
            }
        }
        return self::NORMAL;
    }

    private function exec(array $n, Scope $scope): int
    {
        $this->tick();
        $this->offset = $n['start'];

        switch ($n['type']) {
            case 'ExpressionStatement':
                $this->completion = $this->evalNode($n['expression'], $scope);
                return self::NORMAL;

            case 'VariableDeclaration':
                $this->execVarDecl($n, $scope);
                return self::NORMAL;

            case 'FunctionDeclaration': // hoisted in execStatements
            case 'EmptyStatement':
            case 'DebuggerStatement':
                return self::NORMAL;

            case 'ReturnStatement':
                $this->returnValue = $n['argument'] !== null ? $this->evalNode($n['argument'], $scope) : Undefined::value();
                return self::RETURN;

            case 'IfStatement':
                if (Values::toBoolean($this->evalNode($n['test'], $scope))) {
                    return $this->exec($n['consequent'], $scope);
                }
                return $n['alternate'] !== null ? $this->exec($n['alternate'], $scope) : self::NORMAL;

            case 'BlockStatement':
                return $this->execStatements($n['body'], new Scope($scope));

            case 'WhileStatement':
                while (Values::toBoolean($this->evalNode($n['test'], $scope))) {
                    $c = $this->exec($n['body'], $scope);
                    if ($c === self::BREAK) {
                        break;
                    }
                    if ($c === self::RETURN) {
                        return $c;
                    }
                }
                return self::NORMAL;

            case 'DoWhileStatement':
                do {
                    $c = $this->exec($n['body'], $scope);
                    if ($c === self::BREAK) {
                        break;
                    }
                    if ($c === self::RETURN) {
                        return $c;
                    }
                } while (Values::toBoolean($this->evalNode($n['test'], $scope)));
                return self::NORMAL;

            case 'ForStatement':
                return $this->execFor($n, $scope);

            case 'ForOfStatement':
            case 'ForInStatement':
                return $this->execForInOf($n, $scope);

            case 'BreakStatement':
                return self::BREAK;

            case 'ContinueStatement':
                return self::CONTINUE;

            case 'ThrowStatement':
                $value = $this->evalNode($n['argument'], $scope);
                $msg = $value instanceof JsObject && isset($value->props['message'])
                    ? Values::toString($value->props['name'] ?? 'Error') . ': ' . Values::toString($value->props['message'])
                    : Values::inspect($value);
                throw new JsThrow($value, $msg, $n['start']);

            case 'TryStatement':
                return $this->execTry($n, $scope);

            case 'SwitchStatement':
                return $this->execSwitch($n, $scope);

            default:
                throw $this->error('SyntaxError', "Unsupported statement '{$n['type']}'");
        }
    }

    private function execVarDecl(array $n, Scope $scope): void
    {
        $kind = $n['kind'];
        foreach ($n['declarations'] as $d) {
            $name = $d['id']['name'] ?? '';
            if ($name === '') {
                throw $this->error('SyntaxError', 'Invalid variable declaration');
            }
            if ($kind === 'var') {
                if ($d['init'] !== null) {
                    $this->assignVar($name, $this->evalNamed($d['init'], $scope, $name), $scope);
                }
                continue;
            }
            $scope->vars[$name] = $d['init'] !== null ? $this->evalNamed($d['init'], $scope, $name) : Undefined::value();
            if ($kind === 'const') {
                $scope->consts[$name] = true;
            }
        }
    }

    /** Evaluates an initializer, giving anonymous functions the binding name (const f = () => ...). */
    private function evalNamed(array $node, Scope $scope, string $name): mixed
    {
        $v = $this->evalNode($node, $scope);
        if ($v instanceof JsFunction && $v->name === '') {
            $v->name = $name;
        }
        return $v;
    }

    private function copyScope(Scope $from, Scope $parent): Scope
    {
        $s = new Scope($parent);
        $s->vars = $from->vars;
        $s->consts = $from->consts;
        return $s;
    }

    private function execFor(array $n, Scope $scope): int
    {
        $loopScope = new Scope($scope);
        $perIteration = false;
        $init = $n['init'];
        if ($init !== null) {
            if ($init['type'] === 'VariableDeclaration') {
                $this->execVarDecl($init, $loopScope);
                $perIteration = $init['kind'] !== 'var';
            } else {
                $this->evalNode($init, $loopScope);
            }
        }
        // `let` loop variables get a fresh binding per iteration so closures capture the right value.
        $iter = $perIteration ? $this->copyScope($loopScope, $scope) : $loopScope;
        while (true) {
            if ($n['test'] !== null && !Values::toBoolean($this->evalNode($n['test'], $iter))) {
                break;
            }
            $c = $this->exec($n['body'], $iter);
            if ($c === self::BREAK) {
                break;
            }
            if ($c === self::RETURN) {
                return $c;
            }
            if ($perIteration) {
                $iter = $this->copyScope($iter, $scope);
            }
            if ($n['update'] !== null) {
                $this->evalNode($n['update'], $iter);
            }
        }
        return self::NORMAL;
    }

    private function execForInOf(array $n, Scope $scope): int
    {
        $right = $this->evalNode($n['right'], $scope);
        $isOf = $n['type'] === 'ForOfStatement';

        $body = function (mixed $value) use ($n, $scope): int {
            $iter = new Scope($scope);
            $left = $n['left'];
            if ($left['type'] === 'VariableDeclaration') {
                $name = $left['declarations'][0]['id']['name'] ?? '';
                if ($left['kind'] === 'var') {
                    $this->assignVar($name, $value, $scope);
                } else {
                    $iter->vars[$name] = $value;
                    if ($left['kind'] === 'const') {
                        $iter->consts[$name] = true;
                    }
                }
            } else {
                $this->assignTo($left, $value, $scope);
            }
            return $this->exec($n['body'], $iter);
        };

        if ($isOf) {
            if ($right instanceof JsArray) {
                for ($i = 0; $i < count($right->items); $i++) { // live length, like JS
                    $c = $body($right->items[$i]);
                    if ($c === self::BREAK) {
                        break;
                    }
                    if ($c === self::RETURN) {
                        return $c;
                    }
                }
                return self::NORMAL;
            }
            if (is_string($right)) {
                $values = mb_str_split($right, 1, 'UTF-8');
            } else {
                throw $this->error('TypeError', Values::typeOf($right) . ' is not iterable');
            }
        } else {
            $values = match (true) {
                $right instanceof JsObject => array_map('strval', array_keys($right->props)),
                $right instanceof JsArray => array_map('strval', array_keys($right->items)),
                is_string($right) => array_map('strval', array_keys(mb_str_split($right, 1, 'UTF-8'))),
                default => [],
            };
        }

        foreach ($values as $value) {
            $c = $body($value);
            if ($c === self::BREAK) {
                break;
            }
            if ($c === self::RETURN) {
                return $c;
            }
        }
        return self::NORMAL;
    }

    private function execTry(array $n, Scope $scope): int
    {
        $pending = null;
        $c = self::NORMAL;
        $savedDepth = $this->callDepth;
        try {
            $c = $this->exec($n['block'], $scope);
        } catch (JsThrow $e) {
            $this->callDepth = $savedDepth;
            if ($n['handler'] !== null) {
                $handlerScope = new Scope($scope);
                if ($n['handler']['param'] !== null) {
                    $handlerScope->vars[$n['handler']['param']['name']] = $e->value;
                }
                try {
                    $c = $this->exec($n['handler']['body'], $handlerScope);
                } catch (JsThrow $e2) {
                    $pending = $e2;
                }
            } else {
                $pending = $e;
            }
        }

        if ($n['finalizer'] !== null) {
            $savedReturn = $this->returnValue;
            $fc = $this->exec($n['finalizer'], $scope);
            if ($fc !== self::NORMAL) {
                return $fc; // an abrupt finally overrides everything
            }
            $this->returnValue = $savedReturn;
        }
        if ($pending !== null) {
            throw $pending;
        }
        return $c;
    }

    private function execSwitch(array $n, Scope $scope): int
    {
        $d = $this->evalNode($n['discriminant'], $scope);
        $cases = $n['cases'];
        $startAt = -1;
        foreach ($cases as $i => $case) {
            if ($case['test'] !== null && Values::strictEquals($d, $this->evalNode($case['test'], $scope))) {
                $startAt = $i;
                break;
            }
        }
        if ($startAt < 0) {
            foreach ($cases as $i => $case) {
                if ($case['test'] === null) {
                    $startAt = $i;
                    break;
                }
            }
        }
        if ($startAt < 0) {
            return self::NORMAL;
        }
        $inner = new Scope($scope);
        for ($i = $startAt, $count = count($cases); $i < $count; $i++) {
            $c = $this->execStatements($cases[$i]['consequent'], $inner);
            if ($c === self::BREAK) {
                return self::NORMAL;
            }
            if ($c !== self::NORMAL) {
                return $c;
            }
        }
        return self::NORMAL;
    }

    // ============================================================ expressions

    private function evalNode(array $n, Scope $scope): mixed
    {
        switch ($n['type']) {
            case 'Literal':
                //CLD: 2026-10-20T22:10:00+03:00: a regular expression literal: a new RegExp each time it is evaluated (as in JS)
                if (isset($n['regex'])) {
                    try {
                        return new JsRegExp($n['regex']['pattern'], $n['regex']['flags']);
                    } catch (\InvalidArgumentException $e) {
                        throw $this->error('SyntaxError', 'Invalid regular expression: ' . $e->getMessage());
                    }
                }
                return $n['value'];

            case 'Identifier':
                return $this->lookup($n['name'], $scope);

            case 'TemplateLiteral':
                $s = $n['quasis'][0]['value']['cooked'];
                foreach ($n['expressions'] as $i => $e) {
                    $s .= Values::toString(Values::toPrimitive($this->evalNode($e, $scope))) . $n['quasis'][$i + 1]['value']['cooked'];
                }
                return $this->checkString($s);

            case 'BinaryExpression':
                return $this->binary($n['operator'], $this->evalNode($n['left'], $scope), $this->evalNode($n['right'], $scope));

            case 'LogicalExpression':
                $l = $this->evalNode($n['left'], $scope);
                return match ($n['operator']) {
                    '&&' => Values::toBoolean($l) ? $this->evalNode($n['right'], $scope) : $l,
                    '||' => Values::toBoolean($l) ? $l : $this->evalNode($n['right'], $scope),
                    default => Values::isNullish($l) ? $this->evalNode($n['right'], $scope) : $l,
                };

            case 'UnaryExpression':
                return $this->unary($n, $scope);

            case 'UpdateExpression':
                return $this->update($n, $scope);

            case 'AssignmentExpression':
                return $this->assign($n, $scope);

            case 'ConditionalExpression':
                return Values::toBoolean($this->evalNode($n['test'], $scope))
                    ? $this->evalNode($n['consequent'], $scope)
                    : $this->evalNode($n['alternate'], $scope);

            case 'CallExpression':
                return $this->call($n, $scope);

            case 'MemberExpression':
                $obj = $this->evalNode($n['object'], $scope);
                if ($n['optional'] && Values::isNullish($obj)) {
                    throw ChainShortCircuit::get();
                }
                return $this->getProperty($obj, $this->memberKey($n, $scope));

            case 'ChainExpression':
                try {
                    return $this->evalNode($n['expression'], $scope);
                } catch (ChainShortCircuit) {
                    return Undefined::value();
                }

            case 'ArrayExpression':
                $items = [];
                foreach ($n['elements'] as $el) {
                    if ($el === null) {
                        $items[] = Undefined::value();
                    } elseif ($el['type'] === 'SpreadElement') {
                        array_push($items, ...$this->iterate($this->evalNode($el['argument'], $scope)));
                    } else {
                        $items[] = $this->evalNode($el, $scope);
                    }
                }
                $this->checkArrayLength(count($items));
                return new JsArray($items);

            case 'ObjectExpression':
                return $this->objectLiteral($n, $scope);

            case 'FunctionExpression':
                if ($n['id'] !== null) {
                    $fnScope = new Scope($scope);
                    $fn = new JsFunction($n, $fnScope, $n['id']['name']);
                    $fnScope->vars[$n['id']['name']] = $fn;
                    return $fn;
                }
                return new JsFunction($n, $scope);

            case 'ArrowFunctionExpression':
                return new JsFunction($n, $scope);

            case 'SequenceExpression':
                $v = Undefined::value();
                foreach ($n['expressions'] as $e) {
                    $v = $this->evalNode($e, $scope);
                }
                return $v;

            default:
                throw $this->error('SyntaxError', "Unsupported expression '{$n['type']}'");
        }
    }

    private function lookup(string $name, Scope $scope): mixed
    {
        for ($s = $scope; $s !== null; $s = $s->parent) {
            if (array_key_exists($name, $s->vars)) {
                return $s->vars[$name];
            }
        }
        throw $this->error('ReferenceError', "$name is not defined");
    }

    private function isDefined(string $name, Scope $scope): bool
    {
        for ($s = $scope; $s !== null; $s = $s->parent) {
            if (array_key_exists($name, $s->vars)) {
                return true;
            }
        }
        return false;
    }

    private function assignVar(string $name, mixed $value, Scope $scope): void
    {
        for ($s = $scope; $s !== null; $s = $s->parent) {
            if (array_key_exists($name, $s->vars)) {
                if (isset($s->consts[$name])) {
                    throw $this->error('TypeError', "Assignment to constant variable '$name'");
                }
                $s->vars[$name] = $value;
                return;
            }
        }
        //CLD: 2026-10-13T15:30:33+03:00: implicitGlobals: a new top-level name
        if ($this->implicitGlobals && $this->globalScope !== null) {
            $this->globalScope->vars[$name] = $value;
            return;
        }
        throw $this->error('ReferenceError', "$name is not defined");
    }

    private function assignTo(array $target, mixed $value, Scope $scope): void
    {
        if ($target['type'] === 'Identifier') {
            $this->assignVar($target['name'], $value, $scope);
        } elseif ($target['type'] === 'MemberExpression') {
            $obj = $this->evalNode($target['object'], $scope);
            $this->setProperty($obj, $this->memberKey($target, $scope), $value);
        } else {
            throw $this->error('SyntaxError', 'Invalid assignment target');
        }
    }

    private function memberKey(array $n, Scope $scope): mixed
    {
        return $n['computed'] ? $this->evalNode($n['property'], $scope) : $n['property']['name'];
    }

    private function objectLiteral(array $n, Scope $scope): JsObject
    {
        $obj = new JsObject();
        foreach ($n['properties'] as $p) {
            if ($p['type'] === 'SpreadElement') {
                $src = $this->evalNode($p['argument'], $scope);
                if ($src instanceof JsObject) {
                    foreach ($src->props as $k => $v) {
                        $obj->props[$k] = $v;
                    }
                } elseif ($src instanceof JsArray) {
                    foreach ($src->items as $k => $v) {
                        $obj->props[$k] = $v;
                    }
                } elseif (is_string($src)) {
                    foreach (mb_str_split($src, 1, 'UTF-8') as $k => $v) {
                        $obj->props[$k] = $v;
                    }
                }
                continue;
            }
            $key = $p['computed']
                ? Values::toPropertyKey($this->evalNode($p['key'], $scope))
                : ($p['key']['type'] === 'Identifier' ? $p['key']['name'] : Values::toPropertyKey($p['key']['value']));
            $value = $this->evalNode($p['value'], $scope);
            if ($value instanceof JsFunction && $value->name === '') {
                $value->name = $key;
            }
            $obj->props[$key] = $value;
        }
        return $obj;
    }

    /** Spreads an iterable (array or string) into a PHP list. */
    private function iterate(mixed $v): array
    {
        if ($v instanceof JsArray) {
            return $v->items;
        }
        if (is_string($v)) {
            return mb_str_split($v, 1, 'UTF-8');
        }
        throw $this->error('TypeError', Values::typeOf($v) . ' is not iterable');
    }

    private function call(array $n, Scope $scope): mixed
    {
        $callee = $n['callee'];
        if ($callee['type'] === 'MemberExpression') {
            $obj = $this->evalNode($callee['object'], $scope);
            if ($callee['optional'] && Values::isNullish($obj)) {
                throw ChainShortCircuit::get();
            }
            $fn = $this->getProperty($obj, $this->memberKey($callee, $scope));
        } else {
            $fn = $this->evalNode($callee, $scope);
        }
        if ($n['optional'] && Values::isNullish($fn)) {
            throw ChainShortCircuit::get();
        }

        $args = [];
        foreach ($n['arguments'] as $a) {
            if ($a['type'] === 'SpreadElement') {
                array_push($args, ...$this->iterate($this->evalNode($a['argument'], $scope)));
            } else {
                $args[] = $this->evalNode($a, $scope);
            }
        }

        if (!Values::isCallable($fn)) {
            $this->offset = $n['start'];
            throw $this->error('TypeError', self::describeNode($callee) . ' is not a function');
        }
        return $this->callFunction($fn, $args);
    }

    private static function describeNode(array $n): string
    {
        return match ($n['type']) {
            'Identifier' => $n['name'],
            'MemberExpression' => self::describeNode($n['object']) . ($n['computed'] ? '[...]' : '.' . $n['property']['name']),
            'CallExpression' => self::describeNode($n['callee']) . '(...)',
            default => 'expression',
        };
    }

    private function unary(array $n, Scope $scope): mixed
    {
        $op = $n['operator'];
        $arg = $n['argument'];
        if ($op === 'typeof') {
            if ($arg['type'] === 'Identifier' && !$this->isDefined($arg['name'], $scope)) {
                return 'undefined';
            }
            return Values::typeOf($this->evalNode($arg, $scope));
        }
        if ($op === 'delete') {
            if ($arg['type'] === 'MemberExpression') {
                $obj = $this->evalNode($arg['object'], $scope);
                $this->deleteProperty($obj, $this->memberKey($arg, $scope));
            }
            return true;
        }
        $v = $this->evalNode($arg, $scope);
        switch ($op) {
            case '!':
                return !Values::toBoolean($v);
            case '-':
                $x = Values::toNumber($v);
                if ($x === 0) {
                    return -0.0;
                }
                return is_int($x) && $x !== PHP_INT_MIN ? -$x : -(float) $x;
            case '+':
                return Values::toNumber($v);
            case '~':
                return ~Values::toInt32($v);
            case 'void':
                return Undefined::value();
        }
        throw $this->error('SyntaxError', "Unsupported unary operator '$op'");
    }

    private function update(array $n, Scope $scope): mixed
    {
        $target = $n['argument'];
        $delta = $n['operator'] === '++' ? 1 : -1;
        if ($target['type'] === 'Identifier') {
            $old = Values::toNumber($this->lookup($target['name'], $scope));
            $new = $old + $delta;
            $this->assignVar($target['name'], $new, $scope);
        } elseif ($target['type'] === 'MemberExpression') {
            $obj = $this->evalNode($target['object'], $scope);
            $key = $this->memberKey($target, $scope);
            $old = Values::toNumber($this->getProperty($obj, $key));
            $new = $old + $delta;
            $this->setProperty($obj, $key, $new);
        } else {
            throw $this->error('SyntaxError', 'Invalid update target');
        }
        return $n['prefix'] ? $new : $old;
    }

    private function assign(array $n, Scope $scope): mixed
    {
        $op = $n['operator'];
        $target = $n['left'];

        if ($target['type'] === 'Identifier') {
            $name = $target['name'];
            if ($op === '=') {
                $v = $this->evalNamed($n['right'], $scope, $name);
                $this->assignVar($name, $v, $scope);
                return $v;
            }
            $cur = $this->lookup($name, $scope);
            [$done, $v] = $this->compound($op, $cur, $n['right'], $scope);
            if (!$done) {
                $this->assignVar($name, $v, $scope);
            }
            return $v;
        }

        if ($target['type'] === 'MemberExpression') {
            $obj = $this->evalNode($target['object'], $scope);
            $key = $this->memberKey($target, $scope);
            if ($op === '=') {
                $v = $this->evalNode($n['right'], $scope);
                $this->setProperty($obj, $key, $v);
                return $v;
            }
            $cur = $this->getProperty($obj, $key);
            [$done, $v] = $this->compound($op, $cur, $n['right'], $scope);
            if (!$done) {
                $this->setProperty($obj, $key, $v);
            }
            return $v;
        }

        throw $this->error('SyntaxError', 'Invalid assignment target');
    }

    /** @return array{0: bool, 1: mixed} [shortCircuited, value] */
    private function compound(string $op, mixed $cur, array $rightNode, Scope $scope): array
    {
        switch ($op) {
            case '&&=':
                return Values::toBoolean($cur) ? [false, $this->evalNode($rightNode, $scope)] : [true, $cur];
            case '||=':
                return Values::toBoolean($cur) ? [true, $cur] : [false, $this->evalNode($rightNode, $scope)];
            case '??=':
                return Values::isNullish($cur) ? [false, $this->evalNode($rightNode, $scope)] : [true, $cur];
        }
        return [false, $this->binary(substr($op, 0, -1), $cur, $this->evalNode($rightNode, $scope))];
    }

    public function binary(string $op, mixed $a, mixed $b): mixed
    {
        switch ($op) {
            case '+':
                if (is_int($a) && is_int($b)) {
                    return $a + $b;
                }
                $a = Values::toPrimitive($a);
                $b = Values::toPrimitive($b);
                if (is_string($a) || is_string($b)) {
                    return $this->checkString(Values::toString($a) . Values::toString($b));
                }
                return Values::toNumber($a) + Values::toNumber($b);
            case '-':
                return Values::toNumber($a) - Values::toNumber($b);
            case '*':
                return Values::toNumber($a) * Values::toNumber($b);
            case '/':
                $x = Values::toNumber($a);
                $y = Values::toNumber($b);
                if (is_int($x) && is_int($y) && $y !== 0 && $x % $y === 0) {
                    return intdiv($x, $y);
                }
                return fdiv((float) $x, (float) $y);
            case '%':
                $x = Values::toNumber($a);
                $y = Values::toNumber($b);
                if (is_int($x) && is_int($y) && $y !== 0 && $y !== -1) {
                    return $x % $y;
                }
                return fmod((float) $x, (float) $y);
            case '**':
                $x = Values::toNumber($a);
                $y = Values::toNumber($b);
                if (is_float($y) && is_nan($y)) {
                    return NAN;
                }
                return $x ** $y;
            case '==':
                return Values::looseEquals($a, $b);
            case '!=':
                return !Values::looseEquals($a, $b);
            case '===':
                return Values::strictEquals($a, $b);
            case '!==':
                return !Values::strictEquals($a, $b);
            case '<':
                return Values::lessThan($a, $b) === true;
            case '>':
                return Values::lessThan($b, $a) === true;
            case '<=':
                return Values::lessThan($b, $a) === false;
            case '>=':
                return Values::lessThan($a, $b) === false;
            case '&':
                return Values::toInt32($a) & Values::toInt32($b);
            case '|':
                return Values::toInt32($a) | Values::toInt32($b);
            case '^':
                return Values::toInt32($a) ^ Values::toInt32($b);
            case '<<':
                return Values::toInt32(Values::toInt32($a) << (Values::toUint32($b) & 31));
            case '>>':
                return Values::toInt32($a) >> (Values::toUint32($b) & 31);
            case '>>>':
                return Values::toUint32($a) >> (Values::toUint32($b) & 31);
            case 'in':
                if ($b instanceof JsObject) {
                    return array_key_exists(Values::toPropertyKey($a), $b->props);
                }
                if ($b instanceof JsArray) {
                    $idx = Values::toArrayIndex($a);
                    return $idx !== null ? $idx < count($b->items) : Values::toPropertyKey($a) === 'length';
                }
                throw $this->error('TypeError', "Cannot use 'in' operator on " . Values::typeOf($b));
        }
        throw $this->error('SyntaxError', "Unsupported operator '$op'");
    }

    // ============================================================= properties

    public function getProperty(mixed $obj, mixed $key): mixed
    {
        if ($obj instanceof JsObject) {
            $k = Values::toPropertyKey($key);
            if (array_key_exists($k, $obj->props)) {
                return $obj->props[$k];
            }
            return Builtins::objectMethod($k, $obj) ?? Undefined::value();
        }
        if ($obj instanceof JsArray) {
            $idx = Values::toArrayIndex($key);
            if ($idx !== null) {
                return $idx < count($obj->items) ? $obj->items[$idx] : Undefined::value();
            }
            $k = Values::toPropertyKey($key);
            if ($k === 'length') {
                return count($obj->items);
            }
            //CLD: 2026-10-20T22:10:00+03:00: its named properties (a match's index, input, groups)
            if (array_key_exists($k, $obj->props)) {
                return $obj->props[$k];
            }
            return Builtins::arrayMethod($k, $obj) ?? Undefined::value();
        }
        if (is_string($obj)) {
            $idx = Values::toArrayIndex($key);
            if ($idx !== null) {
                $ch = mb_substr($obj, $idx, 1, 'UTF-8');
                return $ch === '' ? Undefined::value() : $ch;
            }
            $k = Values::toPropertyKey($key);
            if ($k === 'length') {
                return mb_strlen($obj, 'UTF-8');
            }
            return Builtins::stringMethod($k, $obj) ?? Undefined::value();
        }
        //CLD: 2026-10-20T21:01:44+03:00: date methods (no own properties)
        if ($obj instanceof JsDate) {
            return Builtins::dateMethod(Values::toPropertyKey($key), $obj) ?? Undefined::value();
        }
        //CLD: 2026-10-20T22:10:00+03:00: a regular expression's properties and methods
        if ($obj instanceof JsRegExp) {
            $k = Values::toPropertyKey($key);
            return match ($k) {
                'source' => JsRegExp::displaySource($obj->source),
                'flags' => $obj->flags,
                'lastIndex' => $obj->lastIndex,
                'global', 'ignoreCase', 'multiline', 'dotAll', 'unicode', 'sticky', 'hasIndices'
                    => $obj->has(['global' => 'g', 'ignoreCase' => 'i', 'multiline' => 'm', 'dotAll' => 's', 'unicode' => 'u', 'sticky' => 'y', 'hasIndices' => 'd'][$k]),
                default => Builtins::regexpMethod($k, $obj) ?? Undefined::value(),
            };
        }
        if (is_int($obj) || is_float($obj)) {
            return Builtins::numberMethod(Values::toPropertyKey($key), $obj) ?? Undefined::value();
        }
        if ($obj instanceof NativeFunction) {
            $k = Values::toPropertyKey($key);
            if (array_key_exists($k, $obj->props)) {
                return $obj->props[$k];
            }
            return $k === 'name' ? $obj->name : Undefined::value();
        }
        if ($obj instanceof JsFunction) {
            return match (Values::toPropertyKey($key)) {
                'name' => $obj->name,
                'length' => count($obj->node['params']),
                default => Undefined::value(),
            };
        }
        if (Values::isNullish($obj)) {
            throw $this->error('TypeError', sprintf(
                "Cannot read properties of %s (reading '%s')",
                Values::toString($obj),
                Values::toPropertyKey($key),
            ));
        }
        return Undefined::value(); // booleans
    }

    public function setProperty(mixed $obj, mixed $key, mixed $value): void
    {
        if ($obj instanceof JsObject) {
            $obj->props[Values::toPropertyKey($key)] = $value;
            return;
        }
        //CLD: 2026-10-20T22:10:00+03:00: a regular expression's lastIndex can be set
        if ($obj instanceof JsRegExp && Values::toPropertyKey($key) === 'lastIndex') {
            $obj->lastIndex = Values::toNumber($value);
            return;
        }
        if ($obj instanceof JsArray) {
            $idx = Values::toArrayIndex($key);
            if ($idx !== null) {
                $this->checkArrayLength($idx + 1);
                for ($n = count($obj->items); $n < $idx; $n++) {
                    $obj->items[] = Undefined::value();
                }
                $obj->items[$idx] = $value;
                return;
            }
            if (Values::toPropertyKey($key) === 'length') {
                $len = Values::toNumber($value);
                if (!is_int($len) || $len < 0) {
                    throw $this->error('RangeError', 'Invalid array length');
                }
                $this->checkArrayLength($len);
                if ($len < count($obj->items)) {
                    $obj->items = array_slice($obj->items, 0, $len);
                } else {
                    for ($n = count($obj->items); $n < $len; $n++) {
                        $obj->items[] = Undefined::value();
                    }
                }
                return;
            }
            throw $this->error('TypeError', "Cannot set property '" . Values::toPropertyKey($key) . "' on an array");
        }
        if (Values::isNullish($obj)) {
            throw $this->error('TypeError', sprintf(
                "Cannot set properties of %s (setting '%s')",
                Values::toString($obj),
                Values::toPropertyKey($key),
            ));
        }
        throw $this->error('TypeError', sprintf(
            "Cannot create property '%s' on %s",
            Values::toPropertyKey($key),
            Values::typeOf($obj),
        ));
    }

    private function deleteProperty(mixed $obj, mixed $key): void
    {
        if ($obj instanceof JsObject) {
            unset($obj->props[Values::toPropertyKey($key)]);
        } elseif ($obj instanceof JsArray) {
            $idx = Values::toArrayIndex($key);
            if ($idx !== null && $idx < count($obj->items)) {
                $obj->items[$idx] = Undefined::value();
            }
        } elseif (Values::isNullish($obj)) {
            throw $this->error('TypeError', 'Cannot convert undefined or null to object');
        }
    }
}
