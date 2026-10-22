# J — a sandboxed JavaScript subset for PHP

A tolerant parser (for IDE use) that produces an ESTree-style AST, plus a tree-walking VM that executes that AST against a context supplied by the calling PHP code.

Requires PHP 8.1+ with `mbstring`, `json`, `ctype`, and the app's `util\JSON` <!-- CLD: 2026-10-20T22:45:00+03:00: --> (JSON text and `JSON.parse`; the tests and the example load it themselves). PCRE 10.30+ with Unicode for regular expressions. <!-- CLD: 2026-10-20T23:05:00+03:00: --> The app's setup checks these in the web and the console PHP (`util\Setup::J_EXTENSIONS`, `PCRE_MIN`).

```php
use sys\J\Parser;
use sys\J\Interpreter;

$parsed = Parser::parse($code);              // never throws
$parsed->diagnostics;                        // errors with line/column, for editor markers
$parsed->ast;                                // plain arrays, json_encode-able, cacheable

$vm = new Interpreter(['maxSteps' => 100_000, 'timeLimit' => 1.0]);
$result = $vm->run($parsed, [                // context = the script's globals
    'user'   => ['name' => 'Ada', 'roles' => ['admin']],
    'notify' => fn (string $msg) => $mailer->send($msg),
]);
```

`run()` returns the value of a top-level `return`, or else the value of the last expression statement, converted to PHP (objects → assoc arrays, arrays → lists, functions → `Closure`, `undefined` → `null`). Use `execute()` to get raw JS values instead.

## Supported language

`var` / `let` / `const`, function declarations and expressions, arrow functions, default and rest parameters, closures; `if`, `for`, `for…of`, `for…in`, `while`, `do…while`, `switch`, `break`, `continue`, `return`, `throw`, `try/catch/finally`; all arithmetic, comparison, logical, bitwise and assignment operators (including `**`, `??`, `??=`, `&&=`, `||=`); `typeof`, `delete`, `void`, `in`; optional chaining `?.`; template literals; array/object literals with spread, shorthand, computed keys and methods.

Built-ins: `Math`, `JSON`, `Date`, `RegExp` (see below), `Object.keys/values/entries/assign/fromEntries`, `Array.isArray/from/of`, `String`, `Number` (+ `isInteger`, …), `Boolean`, `parseInt`, `parseFloat`, `isNaN`, `isFinite`, `Error`/`TypeError`/… (called without `new`), `console.log` (captured, read with `$vm->getOutput()`), and the usual string, array and number methods.

## Rejected on purpose (reported as parse errors)

`class`, `import` / `export` / dynamic `import()`, `new`, `this`, `super`, `async`/`await`, generators/`yield`, `with`, getters/setters, `instanceof`. Programs with parse errors are refused by the VM (`ScriptError`). Also not implemented: destructuring, labels, tagged templates.

<!-- CLD: 2026-10-20T21:01:44+03:00: Date -->
## Date

`Date` is called without `new` (like `Error`) and always gives a Date object: `Date()` is now, `Date(ms)`, `Date("2026-10-20T10:00")`, `Date(otherDate)` (a copy), `Date(year, month, day?, hours?, minutes?, seconds?, ms?)` in local time (months 0-11, years 0-99 mean 1900-1999, values overflow as in JS: month 12 is January of the next year). Also `Date.now()`, `Date.parse(text)`, `Date.UTC(...)`.

- Local time is PHP's default timezone (`date_default_timezone_get()`); in a DST gap or overlap the offset from before the change is used, as V8 does.
- Methods: `get`/`set` + (`UTC`) + `FullYear`, `Month`, `Date`, `Hours`, `Minutes`, `Seconds`, `Milliseconds`, plus `getDay`, `getUTCDay`, `getTime`, `setTime`, `valueOf`, `getTimezoneOffset`, `toISOString`, `toJSON`, `toString`, `toDateString`, `toTimeString`, `toUTCString`, `toLocaleString`, `toLocaleDateString`, `toLocaleTimeString`. Setters change the date and return its new time.
- Text accepted by `Date(text)` / `Date.parse`: the ISO 8601 forms (a date alone is UTC midnight, a date and time without an offset is local time, a space may stand for the `T`), and the usual forms like `Oct 20 2026 10:00`, `2026/10/20` and what `toString` / `toUTCString` give. Relative text (`tomorrow`, `+1 day`) and anything else give an Invalid Date (`NaN` time, `"Invalid Date"` as text, `toISOString` throws a `RangeError`).
- Operators as in JS: `b - a` is the difference in ms, `<` / `>` compare the times, `+` joins the `toString()` text. `===` compares identity.
- `typeof` is `"object"`; `JSON.stringify` and `console.log` give the ISO text (`2026-10-20T18:05:03.000Z`, `null` for an Invalid Date). Host `DateTimeInterface` values become Dates.
- <!-- CLD: 2026-10-20T21:40:00+03:00: dateFormat --> `run()` (to PHP) and `{{ }}` templates give the ISO text too, or, with the option `dateFormat` (a PHP `date()` format, e.g. `DATE_ATOM`), the date in that format in local time.

<!-- CLD: 2026-10-20T21:40:00+03:00: METHODS -->
`Builtins::METHODS` lists the methods of each kind of value (`object`, `array`, `string`, `number`, `date`); a method is found only if it is listed there, so hosts can read the list (e.g. for suggestions in an editor) and add one to J by implementing it and listing it.

<!-- CLD: 2026-10-20T22:10:00+03:00: RegExp -->
## Regular expressions

Literals (`/(\d+)-(\d+)/g`) and `RegExp(pattern, flags?)` (called without `new`; a RegExp as pattern is copied). Flags `d g i m s u y` (`v` is refused).

- Methods `exec`, `test`, `toString`; properties `source`, `flags`, `lastIndex` (settable), `global`, `ignoreCase`, `multiline`, `dotAll`, `unicode`, `sticky`, `hasIndices`. `exec` / `match` results are arrays with `index`, `input`, `groups` (and `indices` with `d`).
- String methods: `match`, `matchAll` (gives an array, not an iterator), `search`, `replace` / `replaceAll` (a replacement text may use `$&`, `$1`, `$<name>`, `` $` ``, `$'`, `$$` — also with a text to find; a function gets the match, the groups, the index, the text and, with named groups, `groups`), `split` (groups included).
- The lexer decides between a regular expression and a division from the token before the `/`, as JS tools do (after a name, number, string, `)`, `]`, `}` or postfix `++` / `--` it divides). The `{{ }}` and `${ }` scanners skip regular expressions, so a `}}`, `}` or quote inside one is not code.
- Run by PCRE: the JS pattern is translated, and only JS syntax is accepted (PCRE-only groups like `(?i)`, `(?>…)`, `(?R)`, verbs like `(*LIMIT_MATCH=…)` and, with `u`, unknown escapes are syntax errors). Patterns are checked when parsed, so the editor marks a bad one.
- Matching is on code points (always PCRE `u`, as J strings count code points); `$` without `m` matches only at the very end, `.` excludes only `\n`.
- Each match runs with a backtracking limit (`JsRegExp::BACKTRACK_LIMIT`); a catastrophic pattern stops the run with `LimitExceeded` (not catchable by the script), and the run's time limit is checked after each match.
- `typeof` is `"object"`; to PHP and in `console.log` a RegExp is its literal text (`/a/g`), in `JSON.stringify` `{}`.

## Sandbox model

- A script can reach only the built-ins and what you put in the context. Context bindings are read-only (`user = 1` throws), but the values are **copies**: mutating `user.name` in the script does not touch your PHP array. Return what you need, or pass closures to receive changes.
- Only `\Closure` values become callable. Strings like `'system'` and array callables stay plain data, so a script can never call a PHP function by name.
- Objects have no prototypes, so there is no prototype pollution and no route to anything you didn't expose.
- Allowed host values: scalars, arrays, `stdClass`, `Closure`, `JsonSerializable`, `DateTimeInterface` (as a `Date`), backed enums. Anything else is rejected with `InvalidArgumentException` — deliberately, so arbitrary PHP objects are never exposed.
- Limits: `maxSteps`, `timeLimit`, `maxCallDepth`, `maxStringLength`, `maxArrayLength`. Hitting step/time limits throws `LimitExceeded`, which script `try/catch` cannot intercept.
- Exceptions thrown by host closures become catchable JS `Error`s (set `wrapHostExceptions => false` to let them propagate to PHP).

## For the IDE

Every node has `start`/`end` byte offsets; `ParseResult::positionAt($offset)` converts to 1-based line/column (columns in characters). Diagnostics already include `line`, `column`, `endLine`, `endColumn`.

The parser recovers instead of stopping: missing tokens are reported and assumed, bad tokens are skipped, and incomplete input still yields nodes. For example `user.` at the end of a line yields a `MemberExpression` whose property is an `Identifier` with an empty name at the cursor position — which is what you need to drive autocompletion. Function/Program nodes carry `varNames` (hoisted `var` names) which is handy for outline views too.

## Parse modes and templates

<!-- CLD: 2026-10-13T12:17:47+03:00: the parse modes -->
`Parser::parse($source, ['mode' => ...])` takes one of `Parser::MODES`:

- `script` (default): statements, as above.
- `expression`: exactly one expression; statements, `;` and anything after the expression are errors (`Parser::EXPRESSION_ONLY`).
- `reference`: a variable or one of its fields only: `name`, `name.field`, `name["field"]`, `name[0]` (no calls, operators or computed keys; `Parser::REFERENCE_ONLY`).
- `template`: text with expressions between `{{` and `}}` (`"INVOICE-{{ mail.date }}"`). Options: `inner` (`expression` | `reference`: the mode of each part) and `whole` (the text must be a single `{{ }}` with only blanks around it). The `}}` is found outside strings, comments and nested braces (`sys\J\Template`). Each part is parsed in place, so diagnostics are offsets in the whole text. The AST is `Template { parts: [ TemplateText { value } | TemplateExpression { expression } ] }`.

`expression` and `reference` give a `Program` of one `ExpressionStatement`. The interpreter runs a `Template` this way: a single part with only blanks around it gives the expression's value as it is (number, list, object); otherwise the result is text, with lists and objects as JSON and `null` / `undefined` as nothing.

## Known differences from real JavaScript

- String indices and `length` count Unicode code points, not UTF-16 code units (`"😀".length` is 1).
- Numbers are PHP `int|float`; results are JS-compatible for normal use, but `toFixed` uses PHP rounding and `toString(radix)` only handles integers.
- No prototypes: methods like `hasOwnProperty` exist only as a fallback on plain objects; functions have `name`/`length` but no `call`/`apply`/`bind`.
- Object key order is insertion order (JS puts integer-like keys first).
- <!-- CLD: 2026-10-20T22:45:00+03:00: util\JSON --> JSON text (`JSON.stringify`, objects in `{{ }}` text, `console.log`) uses the app's escaping: `<`, `>`, `&`, `'` and `"` inside strings are written as `\u003C`… (safe in HTML; the same JSON), invalid UTF-8 is dropped. `JSON.parse` is the app's tolerant parser: it also takes comments, single quotes, keys without quotes, a comma after the last item and `True` / `False` / `None`, and nests 128 levels at most.
- Regular expressions match code points (`.` sees an emoji as one character even without `u`); `\d`, `\w`, `\b` are ASCII-only as in JS (spelled out for PCRE), `\s` is Unicode white space (JS adds U+FEFF); lookbehinds must have a bounded length (PCRE); `matchAll` gives an array.
- `Date(...)` without `new` gives a Date object (JS gives a string); `toString()` has no timezone name (`GMT+0300`, not `GMT+0300 (Eastern European Summer Time)`); the `toLocale*` methods ignore their arguments and always format like `en-US`.

## Running the tests

```
php tests/smoke.php
php examples/basic.php
```
