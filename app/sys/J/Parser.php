<?php

declare(strict_types=1);

namespace sys\J;

/**
 * Tolerant recursive-descent parser for the J subset.
 *
 * - Never throws on bad input: it records diagnostics and always returns an AST.
 * - AST nodes are plain PHP arrays shaped like ESTree (json_encode-able),
 *   each with 'type', 'start' and 'end' (byte offsets).
 * - Missing pieces are represented by placeholder nodes (e.g. an Identifier
 *   with an empty name after "obj."), which is what an IDE needs for completion.
 * - Forbidden constructs (class, import, export, new, this, ...) are parsed far
 *   enough to recover, and reported as errors.
 *
 * Function / Program nodes carry an extra 'varNames' list (hoisted `var` names)
 * so the VM does not have to rescan bodies on every call.
 */
final class Parser
{
    private const BINARY_PRECEDENCE = [
        '??' => 1,
        '||' => 2,
        '&&' => 3, '|' => 4, '^' => 5, '&' => 6,
        '==' => 7, '!=' => 7, '===' => 7, '!==' => 7,
        '<' => 8, '>' => 8, '<=' => 8, '>=' => 8, 'in' => 8, 'instanceof' => 8,
        '<<' => 9, '>>' => 9, '>>>' => 9,
        '+' => 10, '-' => 10,
        '*' => 11, '/' => 11, '%' => 11,
        '**' => 12,
    ];

    private const ASSIGN_OPS = [
        '=' => true, '+=' => true, '-=' => true, '*=' => true, '/=' => true, '%=' => true,
        '**=' => true, '<<=' => true, '>>=' => true, '>>>=' => true, '&=' => true, '|=' => true,
        '^=' => true, '&&=' => true, '||=' => true, '??=' => true,
    ];

    /** Keywords at which expression error-recovery stops instead of consuming. */
    private const STATEMENT_KEYWORDS = [
        'var' => true, 'let' => true, 'const' => true, 'if' => true, 'else' => true, 'for' => true,
        'while' => true, 'do' => true, 'return' => true, 'break' => true, 'continue' => true,
        'switch' => true, 'case' => true, 'default' => true, 'try' => true, 'catch' => true,
        'finally' => true, 'throw' => true,
    ];

    private const MAX_DEPTH = 300;

    //CLD: 2026-10-13T12:17:47+03:00: the parse modes (option `mode`): script (statements, the default), expression (one expression), reference (a variable or one of its fields:
    //name, name.field, name["field"], name[0]), template (text with {{ }} parts; option `inner`: expression | reference, the mode of the parts; option `whole`: the text
    //must be a single {{ }} with nothing around it)
    public const MODES = ['script', 'expression', 'reference', 'template'];
    public const REFERENCE_ONLY = 'Only a variable or one of its fields is allowed here, e.g. mail.subject';
    public const EXPRESSION_ONLY = 'Only an expression is allowed here (no statements such as let, if or `;`)';

    /** @var list<Token> */
    private array $tokens;
    private int $i = 0;
    private int $lastEnd;
    /** @var list<array<string, mixed>> */
    private array $diagnostics;
    private int $depth;
    private bool $noIn = false;
    private int $functionDepth = 0;
    private int $loopDepth = 0;
    private int $breakableDepth = 0;
    /** @var list<array<string, true>> */
    private array $varScopes = [[]];
    private bool $aborted = false;

    /** @param array{allowReturnOutsideFunction?: bool} $options */
    private function __construct(string $code, int $base, private readonly array $options, int $depth = 0)
    {
        $lexer = new Lexer($code, $base);
        $this->tokens = $lexer->tokenize();
        $this->diagnostics = $lexer->diagnostics;
        $this->lastEnd = $base;
        $this->depth = $depth;
    }

    /**
     * @param array{allowReturnOutsideFunction?: bool, mode?: string, inner?: string, whole?: bool} $options
     *        allowReturnOutsideFunction (default true): lets a script `return` a result at top level.
     *        mode (default script): see MODES; expression / reference give a Program of one ExpressionStatement, template a Template node
     *        { parts: [ TemplateText { value } | TemplateExpression { expression } ] } (each with start / end in $source)
     */
    public static function parse(string $source, array $options = []): ParseResult
    {
        //CLD: 2026-10-13T12:17:47+03:00: the mode switch (was: scripts only)
        $mode = $options['mode'] ?? 'script';
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException("Unknown parse mode `{$mode}`");
        }
        if ($mode === 'template') {
            //the text is not code: only its {{ }} parts are lexed (each by a parser of its own, offsets in $source)
            $parser = new self('', 0, $options);
            $program = $parser->parseTemplateText($source, ($options['inner'] ?? 'expression') === 'reference', !empty($options['whole']));
        } else {
            $parser = new self($source, 0, $options);
            $program = $mode === 'script' ? $parser->parseProgram(strlen($source)) : $parser->parseSingleProgram(strlen($source), $mode === 'reference');
        }

        $result = new ParseResult($program, [], $source);
        $seen = [];
        $diagnostics = [];
        foreach ($parser->diagnostics as $d) {
            $key = $d['start'] . '|' . $d['message'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $from = $result->positionAt($d['start']);
            $to = $result->positionAt($d['end']);
            $diagnostics[] = $d + [
                'line' => $from['line'], 'column' => $from['column'],
                'endLine' => $to['line'], 'endColumn' => $to['column'],
            ];
        }
        usort($diagnostics, fn ($a, $b) => $a['start'] <=> $b['start']);

        return new ParseResult($program, $diagnostics, $source);
    }

    // ------------------------------------------------------------------ helpers

    private function peek(int $o = 0): Token
    {
        return $this->tokens[min($this->i + $o, count($this->tokens) - 1)];
    }

    private function advance(): Token
    {
        $t = $this->tokens[$this->i];
        if ($t->type !== Token::EOF) {
            $this->i++;
        }
        $this->lastEnd = $t->end;
        return $t;
    }

    private function eof(): bool
    {
        return $this->tokens[$this->i]->type === Token::EOF;
    }

    private function isP(string $v, int $o = 0): bool
    {
        $t = $this->peek($o);
        return $t->type === Token::PUNCT && $t->value === $v;
    }

    private function isK(string $v, int $o = 0): bool
    {
        $t = $this->peek($o);
        return $t->type === Token::KW && $t->value === $v;
    }

    private function isIdent(string $name, int $o = 0): bool
    {
        $t = $this->peek($o);
        return $t->type === Token::IDENT && $t->value === $name;
    }

    private function eatP(string $v): bool
    {
        if ($this->isP($v)) {
            $this->advance();
            return true;
        }
        return false;
    }

    private function expectP(string $v): bool
    {
        if ($this->eatP($v)) {
            return true;
        }
        $t = $this->peek();
        $this->error("Expected '$v' but found " . $this->describe($t), $t->start, $t->end);
        return false;
    }

    private function describe(Token $t): string
    {
        return $t->type === Token::EOF ? 'end of input' : "'" . $t->raw . "'";
    }

    private function error(string $message, int $start, int $end): void
    {
        $this->diagnostics[] = ['severity' => 'error', 'message' => $message, 'start' => $start, 'end' => max($start, $end)];
    }

    /** @param array<string, mixed> $node */
    private function finish(array $node, int $start): array
    {
        $node['start'] = $start;
        $node['end'] = max($start, $this->lastEnd);
        return $node;
    }

    private function enter(): bool
    {
        if (++$this->depth > self::MAX_DEPTH) {
            if (!$this->aborted) {
                $t = $this->peek();
                $this->error('Code is nested too deeply', $t->start, $t->end);
                $this->aborted = true;
            }
            $this->i = count($this->tokens) - 1; // jump to EOF
            return false;
        }
        return true;
    }

    private function errorExpression(int $start): array
    {
        return ['type' => 'ErrorExpression', 'start' => $start, 'end' => max($start, $this->lastEnd)];
    }

    /** Consumes a balanced (), [] or {} group starting at the current opener. */
    private function skipBalancedTokens(): void
    {
        $depth = 0;
        do {
            $t = $this->advance();
            if ($t->type === Token::PUNCT) {
                if ($t->value === '(' || $t->value === '[' || $t->value === '{') {
                    $depth++;
                } elseif ($t->value === ')' || $t->value === ']' || $t->value === '}') {
                    $depth--;
                }
            }
        } while ($depth > 0 && !$this->eof());
    }

    /** Skips tokens to a plausible statement end (`;`, line break, or unbalanced closer). */
    private function skipToStatementEnd(): void
    {
        $depth = 0;
        $consumed = false;
        while (!$this->eof()) {
            $t = $this->peek();
            if ($depth === 0 && $consumed && $t->nlBefore) {
                return;
            }
            if ($t->type === Token::PUNCT) {
                if ($depth === 0 && $t->value === ';') {
                    $this->advance();
                    return;
                }
                if ($t->value === '(' || $t->value === '[' || $t->value === '{') {
                    $depth++;
                } elseif ($t->value === ')' || $t->value === ']' || $t->value === '}') {
                    if ($depth === 0) {
                        return;
                    }
                    $depth--;
                }
            }
            $this->advance();
            $consumed = true;
        }
    }

    private function consumeSemicolon(): void
    {
        if ($this->eatP(';')) {
            return;
        }
        $t = $this->peek();
        if ($t->type === Token::EOF || $t->nlBefore || $this->isP('}')) {
            return;
        }
        $this->error("Expected ';' but found " . $this->describe($t), $t->start, $t->end);
    }

    // --------------------------------------------------------------- statements

    private function parseProgram(int $length): array
    {
        $body = $this->parseStatements(fn () => false);
        return [
            'type' => 'Program',
            'body' => $body,
            'varNames' => array_keys($this->varScopes[0]),
            'start' => 0,
            'end' => $length,
        ];
    }

    /** @param callable():bool $stop */
    private function parseStatements(callable $stop): array
    {
        $body = [];
        while (!$this->eof() && !$stop()) {
            $before = $this->i;
            $stmt = $this->parseStatement();
            if ($stmt !== null) {
                $body[] = $stmt;
            }
            if ($this->i === $before && !$this->eof()) {
                $t = $this->advance();
                if ($t->type !== Token::INVALID) { // lexer already reported invalid chars
                    $this->error('Unexpected ' . $this->describe($t), $t->start, $t->end);
                }
            }
        }
        return $body;
    }

    private function parseStatement(): ?array
    {
        if (!$this->enter()) {
            $this->depth--;
            return null;
        }
        try {
            return $this->parseStatementInner();
        } finally {
            $this->depth--;
        }
    }

    private function parseStatementInner(): ?array
    {
        $t = $this->peek();
        $start = $t->start;

        if ($t->type === Token::PUNCT) {
            if ($t->value === '{') {
                return $this->parseBlock();
            }
            if ($t->value === ';') {
                $this->advance();
                return $this->finish(['type' => 'EmptyStatement'], $start);
            }
        }

        if ($t->type === Token::KW) {
            switch ($t->value) {
                case 'var':
                case 'let':
                case 'const':
                    $decl = $this->parseVarDecl(false);
                    $this->consumeSemicolon();
                    $decl['end'] = $this->lastEnd;
                    return $decl;
                case 'function':
                    return $this->parseFunction(true);
                case 'if':
                    return $this->parseIf();
                case 'for':
                    return $this->parseFor();
                case 'while':
                    $this->advance();
                    $this->expectP('(');
                    $test = $this->parseExpressionNoInReset();
                    $this->expectP(')');
                    $body = $this->parseLoopBody();
                    return $this->finish(['type' => 'WhileStatement', 'test' => $test, 'body' => $body], $start);
                case 'do':
                    return $this->parseDoWhile();
                case 'return':
                    return $this->parseReturn();
                case 'break':
                case 'continue':
                    return $this->parseBreakContinue();
                case 'throw':
                    $this->advance();
                    if ($this->peek()->nlBefore) {
                        $this->error('Illegal newline after throw', $t->start, $t->end);
                    }
                    $arg = $this->parseExpression();
                    $this->consumeSemicolon();
                    return $this->finish(['type' => 'ThrowStatement', 'argument' => $arg], $start);
                case 'try':
                    return $this->parseTry();
                case 'switch':
                    return $this->parseSwitch();
                case 'class':
                    return $this->parseClassDisallowed('DisallowedStatement');
                case 'import':
                    if (!$this->isP('(', 1) && !$this->isP('.', 1)) {
                        $this->error('Imports are not allowed', $t->start, $t->end);
                        $this->advance();
                        $this->skipToStatementEnd();
                        return $this->finish(['type' => 'DisallowedStatement', 'keyword' => 'import'], $start);
                    }
                    break; // dynamic import(): handled (and rejected) as an expression
                case 'export':
                    $this->error('Exports are not allowed', $t->start, $t->end);
                    $this->advance();
                    if ($this->isK('default')) {
                        $this->advance();
                    }
                    if ($this->isP('{') || $this->isP('*')) {
                        $this->skipToStatementEnd();
                        return $this->finish(['type' => 'DisallowedStatement', 'keyword' => 'export'], $start);
                    }
                    return $this->parseStatement(); // keep the declaration visible to the IDE
                case 'with':
                    $this->error("'with' statements are not allowed", $t->start, $t->end);
                    $this->advance();
                    $this->skipToStatementEnd();
                    return $this->finish(['type' => 'DisallowedStatement', 'keyword' => 'with'], $start);
                case 'debugger':
                    $this->advance();
                    $this->consumeSemicolon();
                    return $this->finish(['type' => 'DebuggerStatement'], $start);
                case 'else':
                case 'case':
                case 'default':
                case 'catch':
                case 'finally':
                    $this->error("Unexpected '{$t->value}'", $t->start, $t->end);
                    $this->advance();
                    return null;
            }
        }

        if ($t->type === Token::IDENT && $this->isP(':', 1)) {
            $this->error('Labels are not supported', $t->start, $t->end);
            $this->advance();
            $this->advance();
            return $this->parseStatement();
        }

        $expr = $this->parseExpression();
        $this->consumeSemicolon();
        return $this->finish(['type' => 'ExpressionStatement', 'expression' => $expr], $start);
    }

    /** Statement in a single-statement position (if/loop bodies). */
    private function parseSubStatement(): array
    {
        $t = $this->peek();
        if ($t->type === Token::EOF || $this->isP('}') || $this->isP(')')) {
            $this->error('Statement expected', $t->start, $t->end);
            return ['type' => 'EmptyStatement', 'start' => $t->start, 'end' => $t->start];
        }
        return $this->parseStatement() ?? ['type' => 'EmptyStatement', 'start' => $t->start, 'end' => $this->lastEnd];
    }

    private function parseLoopBody(): array
    {
        $this->loopDepth++;
        $this->breakableDepth++;
        try {
            return $this->parseSubStatement();
        } finally {
            $this->loopDepth--;
            $this->breakableDepth--;
        }
    }

    private function parseBlock(): array
    {
        $start = $this->peek()->start;
        if (!$this->expectP('{')) {
            return $this->finish(['type' => 'BlockStatement', 'body' => []], $start);
        }
        $body = $this->parseStatements(fn () => $this->isP('}'));
        $this->expectP('}');
        return $this->finish(['type' => 'BlockStatement', 'body' => $body], $start);
    }

    private function parseVarDecl(bool $inForHead): array
    {
        $start = $this->peek()->start;
        $kind = $this->advance()->value;
        $declarations = [];
        do {
            $dStart = $this->peek()->start;
            $id = $this->parseBindingIdentifier();
            if ($kind === 'var' && $id['type'] === 'Identifier' && $id['name'] !== '') {
                $this->varScopes[count($this->varScopes) - 1][$id['name']] = true;
            }
            $init = null;
            if ($this->eatP('=')) {
                $init = $this->parseAssignment();
            } elseif ($kind === 'const' && !($inForHead && ($this->isK('in') || $this->isIdent('of')))) {
                $this->error('Missing initializer in const declaration', $id['start'], $id['end']);
            }
            $declarations[] = $this->finish(['type' => 'VariableDeclarator', 'id' => $id, 'init' => $init], $dStart);
        } while ($this->eatP(','));

        return $this->finish(['type' => 'VariableDeclaration', 'kind' => $kind, 'declarations' => $declarations], $start);
    }

    private function parseBindingIdentifier(): array
    {
        $t = $this->peek();
        if ($t->type === Token::IDENT) {
            $this->advance();
            return $this->finish(['type' => 'Identifier', 'name' => $t->value], $t->start);
        }
        if ($this->isP('[') || $this->isP('{')) {
            $this->error('Destructuring is not supported', $t->start, $t->end);
            $this->skipBalancedTokens();
            return $this->finish(['type' => 'ErrorPattern'], $t->start);
        }
        if ($t->type === Token::KW) {
            $this->error("'{$t->value}' is a reserved word and cannot be used as a name", $t->start, $t->end);
            $this->advance();
            return $this->finish(['type' => 'Identifier', 'name' => $t->value], $t->start);
        }
        $this->error('Identifier expected but found ' . $this->describe($t), $t->start, $t->end);
        return ['type' => 'Identifier', 'name' => '', 'start' => $t->start, 'end' => $t->start];
    }

    private function parseFunction(bool $isDeclaration): array
    {
        $start = $this->peek()->start;
        $this->advance(); // 'function'
        if ($this->isP('*')) {
            $t = $this->advance();
            $this->error('Generator functions are not supported', $t->start, $t->end);
        }
        $id = null;
        if ($this->peek()->type === Token::IDENT) {
            $t = $this->advance();
            $id = $this->finish(['type' => 'Identifier', 'name' => $t->value], $t->start);
        } elseif ($isDeclaration) {
            $t = $this->peek();
            $this->error('Function name expected', $t->start, $t->end);
        }
        [$params, $body, $varNames] = $this->parseFunctionRest();
        return $this->finish([
            'type' => $isDeclaration ? 'FunctionDeclaration' : 'FunctionExpression',
            'id' => $id,
            'params' => $params,
            'body' => $body,
            'varNames' => $varNames,
        ], $start);
    }

    /** @return array{0: list<array>, 1: array, 2: list<string>} */
    private function parseFunctionRest(): array
    {
        $this->enterFunction($saved);
        try {
            $params = $this->parseParams();
            $body = $this->parseBlock();
        } finally {
            $varNames = $this->leaveFunction($saved);
        }
        return [$params, $body, $varNames];
    }

    private function enterFunction(?array &$saved): void
    {
        $saved = [$this->loopDepth, $this->breakableDepth, $this->noIn];
        $this->varScopes[] = [];
        $this->functionDepth++;
        $this->loopDepth = 0;
        $this->breakableDepth = 0;
        $this->noIn = false;
    }

    /** @return list<string> */
    private function leaveFunction(array $saved): array
    {
        [$this->loopDepth, $this->breakableDepth, $this->noIn] = $saved;
        $this->functionDepth--;
        return array_keys(array_pop($this->varScopes));
    }

    private function parseParams(): array
    {
        $params = [];
        if (!$this->expectP('(')) {
            return $params;
        }
        while (!$this->isP(')') && !$this->eof()) {
            $pStart = $this->peek()->start;
            if ($this->eatP('...')) {
                $arg = $this->parseBindingIdentifier();
                $params[] = $this->finish(['type' => 'RestElement', 'argument' => $arg], $pStart);
                if (!$this->isP(')')) {
                    $t = $this->peek();
                    $this->error('A rest parameter must be last in a parameter list', $t->start, $t->end);
                }
            } else {
                $id = $this->parseBindingIdentifier();
                if ($this->eatP('=')) {
                    $default = $this->parseAssignment();
                    $params[] = $this->finish(['type' => 'AssignmentPattern', 'left' => $id, 'right' => $default], $pStart);
                } else {
                    $params[] = $id;
                }
            }
            if (!$this->eatP(',')) {
                break;
            }
        }
        $this->expectP(')');
        return $params;
    }

    private function parseIf(): array
    {
        $start = $this->advance()->start;
        $this->expectP('(');
        $test = $this->parseExpressionNoInReset();
        $this->expectP(')');
        $consequent = $this->parseSubStatement();
        $alternate = null;
        if ($this->isK('else')) {
            $this->advance();
            $alternate = $this->parseSubStatement();
        }
        return $this->finish(['type' => 'IfStatement', 'test' => $test, 'consequent' => $consequent, 'alternate' => $alternate], $start);
    }

    private function parseDoWhile(): array
    {
        $start = $this->advance()->start;
        $body = $this->parseLoopBody();
        if ($this->isK('while')) {
            $this->advance();
        } else {
            $t = $this->peek();
            $this->error("Expected 'while' but found " . $this->describe($t), $t->start, $t->end);
        }
        $this->expectP('(');
        $test = $this->parseExpressionNoInReset();
        $this->expectP(')');
        $this->eatP(';');
        return $this->finish(['type' => 'DoWhileStatement', 'body' => $body, 'test' => $test], $start);
    }

    private function parseFor(): array
    {
        $start = $this->advance()->start;
        if ($this->isK('await')) {
            $t = $this->advance();
            $this->error("'for await' is not supported", $t->start, $t->end);
        }
        $this->expectP('(');

        $init = null;
        if ($this->isP(';')) {
            // no init
        } elseif ($this->isK('var') || $this->isK('let') || $this->isK('const')) {
            $saved = $this->noIn;
            $this->noIn = true;
            $init = $this->parseVarDecl(true);
            $this->noIn = $saved;
            if ($this->isK('in') || $this->isIdent('of')) {
                if (count($init['declarations']) !== 1) {
                    $this->error('Only a single variable can be declared in a for-in/for-of head', $init['start'], $init['end']);
                } elseif ($init['declarations'][0]['init'] !== null) {
                    $this->error('for-in/for-of variable may not have an initializer', $init['start'], $init['end']);
                }
                return $this->parseForInOf($start, $init);
            }
        } else {
            $saved = $this->noIn;
            $this->noIn = true;
            $init = $this->parseExpression();
            $this->noIn = $saved;
            if ($this->isK('in') || $this->isIdent('of')) {
                if (!self::isAssignable($init)) {
                    $this->error('Invalid left-hand side in for loop', $init['start'], $init['end']);
                }
                return $this->parseForInOf($start, $init);
            }
        }

        $this->expectP(';');
        $test = $this->isP(';') ? null : $this->parseExpressionNoInReset();
        $this->expectP(';');
        $update = $this->isP(')') ? null : $this->parseExpressionNoInReset();
        $this->expectP(')');
        $body = $this->parseLoopBody();
        return $this->finish([
            'type' => 'ForStatement', 'init' => $init, 'test' => $test, 'update' => $update, 'body' => $body,
        ], $start);
    }

    private function parseForInOf(int $start, array $left): array
    {
        $isOf = $this->isIdent('of');
        $this->advance();
        $right = $isOf ? $this->parseAssignment() : $this->parseExpression();
        $this->expectP(')');
        $body = $this->parseLoopBody();
        return $this->finish([
            'type' => $isOf ? 'ForOfStatement' : 'ForInStatement', 'left' => $left, 'right' => $right, 'body' => $body,
        ], $start);
    }

    private function parseReturn(): array
    {
        $t = $this->advance();
        if ($this->functionDepth === 0 && !($this->options['allowReturnOutsideFunction'] ?? true)) {
            $this->error("'return' outside of function", $t->start, $t->end);
        }
        $arg = null;
        $n = $this->peek();
        if (!($n->type === Token::EOF || $n->nlBefore || $this->isP(';') || $this->isP('}'))) {
            $arg = $this->parseExpression();
        }
        $this->consumeSemicolon();
        return $this->finish(['type' => 'ReturnStatement', 'argument' => $arg], $t->start);
    }

    private function parseBreakContinue(): array
    {
        $t = $this->advance();
        $isBreak = $t->value === 'break';
        if ($isBreak && $this->breakableDepth === 0) {
            $this->error("'break' outside of a loop or switch", $t->start, $t->end);
        } elseif (!$isBreak && $this->loopDepth === 0) {
            $this->error("'continue' outside of a loop", $t->start, $t->end);
        }
        $n = $this->peek();
        if ($n->type === Token::IDENT && !$n->nlBefore) {
            $this->error('Labels are not supported', $n->start, $n->end);
            $this->advance();
        }
        $this->consumeSemicolon();
        return $this->finish(['type' => $isBreak ? 'BreakStatement' : 'ContinueStatement', 'label' => null], $t->start);
    }

    private function parseTry(): array
    {
        $t = $this->advance();
        $block = $this->parseBlock();
        $handler = null;
        $finalizer = null;
        if ($this->isK('catch')) {
            $cStart = $this->advance()->start;
            $param = null;
            if ($this->eatP('(')) {
                $param = $this->parseBindingIdentifier();
                $this->expectP(')');
            }
            $body = $this->parseBlock();
            $handler = $this->finish(['type' => 'CatchClause', 'param' => $param, 'body' => $body], $cStart);
        }
        if ($this->isK('finally')) {
            $this->advance();
            $finalizer = $this->parseBlock();
        }
        if ($handler === null && $finalizer === null) {
            $n = $this->peek();
            $this->error("Missing 'catch' or 'finally' after try block", $n->start, $n->end);
        }
        return $this->finish(['type' => 'TryStatement', 'block' => $block, 'handler' => $handler, 'finalizer' => $finalizer], $t->start);
    }

    private function parseSwitch(): array
    {
        $t = $this->advance();
        $this->expectP('(');
        $discriminant = $this->parseExpressionNoInReset();
        $this->expectP(')');
        $cases = [];
        if ($this->expectP('{')) {
            $this->breakableDepth++;
            $hasDefault = false;
            while (!$this->isP('}') && !$this->eof()) {
                $c = $this->peek();
                if ($this->isK('case')) {
                    $this->advance();
                    $test = $this->parseExpression();
                } elseif ($this->isK('default')) {
                    $this->advance();
                    $test = null;
                    if ($hasDefault) {
                        $this->error('More than one default clause in switch statement', $c->start, $c->end);
                    }
                    $hasDefault = true;
                } else {
                    $this->error("Expected 'case' or 'default' but found " . $this->describe($c), $c->start, $c->end);
                    $this->advance();
                    continue;
                }
                $this->expectP(':');
                $consequent = $this->parseStatements(fn () => $this->isK('case') || $this->isK('default') || $this->isP('}'));
                $cases[] = $this->finish(['type' => 'SwitchCase', 'test' => $test, 'consequent' => $consequent], $c->start);
            }
            $this->breakableDepth--;
            $this->expectP('}');
        }
        return $this->finish(['type' => 'SwitchStatement', 'discriminant' => $discriminant, 'cases' => $cases], $t->start);
    }

    private function parseClassDisallowed(string $nodeType): array
    {
        $t = $this->advance();
        $this->error('Classes are not allowed', $t->start, $t->end);
        while (!$this->eof() && !$this->isP('{') && !$this->isP(';') && !$this->isP('}')) {
            $this->advance();
        }
        if ($this->isP('{')) {
            $this->skipBalancedTokens();
        }
        return $this->finish(['type' => $nodeType, 'keyword' => 'class'], $t->start);
    }

    // -------------------------------------------------------------- expressions

    /** Parses a full expression with the "no `in`" restriction lifted (inside parens etc.). */
    private function parseExpressionNoInReset(): array
    {
        $saved = $this->noIn;
        $this->noIn = false;
        try {
            return $this->parseExpression();
        } finally {
            $this->noIn = $saved;
        }
    }

    private function parseExpression(): array
    {
        $start = $this->peek()->start;
        $expr = $this->parseAssignment();
        if (!$this->isP(',')) {
            return $expr;
        }
        $list = [$expr];
        while ($this->eatP(',')) {
            $list[] = $this->parseAssignment();
        }
        return $this->finish(['type' => 'SequenceExpression', 'expressions' => $list], $start);
    }

    private static function isAssignable(array $node): bool
    {
        return $node['type'] === 'Identifier'
            || ($node['type'] === 'MemberExpression' && !$node['optional']);
    }

    private function parseAssignment(): array
    {
        $start = $this->peek()->start;
        if (!$this->enter()) {
            $this->depth--;
            return $this->errorExpression($start);
        }
        try {
            $t = $this->peek();
            if (($t->type === Token::IDENT && $this->isP('=>', 1)) || ($this->isP('(') && $this->isArrowAhead())) {
                return $this->parseArrow();
            }

            $left = $this->parseConditional();
            $op = $this->peek();
            if ($op->type === Token::PUNCT && isset(self::ASSIGN_OPS[$op->value])) {
                $this->advance();
                if (!self::isAssignable($left)) {
                    $this->error('Invalid assignment target', $left['start'], $left['end']);
                }
                $right = $this->parseAssignment();
                return $this->finish(['type' => 'AssignmentExpression', 'operator' => $op->value, 'left' => $left, 'right' => $right], $start);
            }
            return $left;
        } finally {
            $this->depth--;
        }
    }

    private function isArrowAhead(): bool
    {
        $depth = 0;
        $n = count($this->tokens);
        for ($j = $this->i; $j < $n; $j++) {
            $t = $this->tokens[$j];
            if ($t->type === Token::EOF) {
                return false;
            }
            if ($t->type !== Token::PUNCT) {
                continue;
            }
            if ($t->value === '(' || $t->value === '[' || $t->value === '{') {
                $depth++;
            } elseif ($t->value === ')' || $t->value === ']' || $t->value === '}') {
                if (--$depth === 0) {
                    $next = $this->tokens[$j + 1] ?? null;
                    return $next !== null && $next->type === Token::PUNCT && $next->value === '=>';
                }
                if ($depth < 0) {
                    return false;
                }
            }
        }
        return false;
    }

    private function parseArrow(): array
    {
        $start = $this->peek()->start;
        $this->enterFunction($saved);
        try {
            if ($this->peek()->type === Token::IDENT) {
                $t = $this->advance();
                $params = [$this->finish(['type' => 'Identifier', 'name' => $t->value], $t->start)];
            } else {
                $params = $this->parseParams();
            }
            $arrow = $this->peek();
            if ($arrow->nlBefore && $this->isP('=>')) {
                $this->error("Line terminator not permitted before '=>'", $arrow->start, $arrow->end);
            }
            $this->expectP('=>');
            if ($this->isP('{')) {
                $body = $this->parseBlock();
                $expression = false;
            } else {
                $this->noIn = $saved[2];
                $body = $this->parseAssignment();
                $expression = true;
            }
        } finally {
            $varNames = $this->leaveFunction($saved);
        }
        return $this->finish([
            'type' => 'ArrowFunctionExpression',
            'id' => null,
            'params' => $params,
            'body' => $body,
            'expression' => $expression,
            'varNames' => $varNames,
        ], $start);
    }

    private function parseConditional(): array
    {
        $start = $this->peek()->start;
        $test = $this->parseBinary(0);
        if (!$this->isP('?')) {
            return $test;
        }
        $this->advance();
        $saved = $this->noIn;
        $this->noIn = false;
        $consequent = $this->parseAssignment();
        $this->noIn = $saved;
        $this->expectP(':');
        $alternate = $this->parseAssignment();
        return $this->finish(['type' => 'ConditionalExpression', 'test' => $test, 'consequent' => $consequent, 'alternate' => $alternate], $start);
    }

    private function parseBinary(int $minPrec): array
    {
        $start = $this->peek()->start;
        $left = $this->parseUnary();
        while (true) {
            $t = $this->peek();
            $op = null;
            if ($t->type === Token::PUNCT && isset(self::BINARY_PRECEDENCE[$t->value])) {
                $op = $t->value;
            } elseif ($t->type === Token::KW && (($t->value === 'in' && !$this->noIn) || $t->value === 'instanceof')) {
                $op = $t->value;
            }
            if ($op === null) {
                break;
            }
            $prec = self::BINARY_PRECEDENCE[$op];
            if ($prec <= $minPrec) {
                break;
            }
            $this->advance();
            if ($op === 'instanceof') {
                $this->error("'instanceof' is not supported", $t->start, $t->end);
            }
            $right = $op === '**' ? $this->parseBinary($prec - 1) : $this->parseBinary($prec);
            $type = ($op === '&&' || $op === '||' || $op === '??') ? 'LogicalExpression' : 'BinaryExpression';
            $left = $this->finish(['type' => $type, 'operator' => $op, 'left' => $left, 'right' => $right], $start);
        }
        return $left;
    }

    private function parseUnary(): array
    {
        $t = $this->peek();
        $start = $t->start;
        $isUnaryPunct = $t->type === Token::PUNCT && in_array($t->value, ['!', '-', '+', '~', '++', '--'], true);
        $isUnaryKw = $t->type === Token::KW && in_array($t->value, ['typeof', 'void', 'delete', 'await'], true);
        if (!$isUnaryPunct && !$isUnaryKw) {
            return $this->parsePostfix();
        }
        if (!$this->enter()) {
            $this->depth--;
            return $this->errorExpression($start);
        }
        try {
            $this->advance();
            if ($t->value === 'await') {
                $this->error("'await' is not supported", $t->start, $t->end);
                return $this->parseUnary();
            }
            $argument = $this->parseUnary();
            if ($t->value === '++' || $t->value === '--') {
                if (!self::isAssignable($argument)) {
                    $this->error('Invalid update target', $argument['start'], $argument['end']);
                }
                return $this->finish(['type' => 'UpdateExpression', 'operator' => $t->value, 'prefix' => true, 'argument' => $argument], $start);
            }
            return $this->finish(['type' => 'UnaryExpression', 'operator' => $t->value, 'prefix' => true, 'argument' => $argument], $start);
        } finally {
            $this->depth--;
        }
    }

    private function parsePostfix(): array
    {
        $start = $this->peek()->start;
        $expr = $this->parseLeftHandSide();
        $t = $this->peek();
        if ($t->type === Token::PUNCT && ($t->value === '++' || $t->value === '--') && !$t->nlBefore) {
            $this->advance();
            if (!self::isAssignable($expr)) {
                $this->error('Invalid update target', $expr['start'], $expr['end']);
            }
            return $this->finish(['type' => 'UpdateExpression', 'operator' => $t->value, 'prefix' => false, 'argument' => $expr], $start);
        }
        return $expr;
    }

    private function parseLeftHandSide(): array
    {
        $start = $this->peek()->start;
        $expr = $this->parsePrimary();
        $isChain = false;
        while (true) {
            if ($this->eatP('.')) {
                $property = $this->parsePropertyName();
                $expr = $this->finish(['type' => 'MemberExpression', 'object' => $expr, 'property' => $property, 'computed' => false, 'optional' => false], $start);
            } elseif ($this->isP('?.')) {
                $this->advance();
                $isChain = true;
                if ($this->isP('(')) {
                    $args = $this->parseArguments();
                    $expr = $this->finish(['type' => 'CallExpression', 'callee' => $expr, 'arguments' => $args, 'optional' => true], $start);
                } elseif ($this->eatP('[')) {
                    $property = $this->parseExpressionNoInReset();
                    $this->expectP(']');
                    $expr = $this->finish(['type' => 'MemberExpression', 'object' => $expr, 'property' => $property, 'computed' => true, 'optional' => true], $start);
                } else {
                    $property = $this->parsePropertyName();
                    $expr = $this->finish(['type' => 'MemberExpression', 'object' => $expr, 'property' => $property, 'computed' => false, 'optional' => true], $start);
                }
            } elseif ($this->eatP('[')) {
                $property = $this->parseExpressionNoInReset();
                $this->expectP(']');
                $expr = $this->finish(['type' => 'MemberExpression', 'object' => $expr, 'property' => $property, 'computed' => true, 'optional' => false], $start);
            } elseif ($this->isP('(')) {
                $args = $this->parseArguments();
                $expr = $this->finish(['type' => 'CallExpression', 'callee' => $expr, 'arguments' => $args, 'optional' => false], $start);
            } elseif ($this->peek()->type === Token::TPL && !$this->peek()->nlBefore) {
                $t = $this->advance();
                $this->error('Tagged templates are not supported', $t->start, $t->end);
            } else {
                break;
            }
        }
        if ($isChain) {
            $expr = $this->finish(['type' => 'ChainExpression', 'expression' => $expr], $start);
        }
        return $expr;
    }

    /** Property name after '.', keywords allowed. Returns an empty Identifier when missing (IDE completion). */
    private function parsePropertyName(): array
    {
        $t = $this->peek();
        // IDE tolerance: in "obj.<newline>if (...)" the user is still typing; don't eat the next statement.
        $looksLikeNextStatement = $t->nlBefore && $t->type === Token::KW
            && (isset(self::STATEMENT_KEYWORDS[$t->value]) || $t->value === 'function');
        if (($t->type === Token::IDENT || $t->type === Token::KW) && !$looksLikeNextStatement) {
            $this->advance();
            return $this->finish(['type' => 'Identifier', 'name' => $t->value], $t->start);
        }
        $this->error('Property name expected', $this->lastEnd, $t->type === Token::EOF ? $this->lastEnd : $t->end);
        return ['type' => 'Identifier', 'name' => '', 'start' => $this->lastEnd, 'end' => $this->lastEnd];
    }

    private function parseArguments(): array
    {
        $args = [];
        $this->expectP('(');
        $saved = $this->noIn;
        $this->noIn = false;
        while (!$this->isP(')') && !$this->eof()) {
            if ($this->isP('...')) {
                $s = $this->advance()->start;
                $arg = $this->parseAssignment();
                $args[] = $this->finish(['type' => 'SpreadElement', 'argument' => $arg], $s);
            } else {
                $args[] = $this->parseAssignment();
            }
            if (!$this->eatP(',')) {
                break;
            }
        }
        $this->noIn = $saved;
        $this->expectP(')');
        return $args;
    }

    private function parsePrimary(): array
    {
        $t = $this->peek();
        $start = $t->start;

        switch ($t->type) {
            case Token::NUM:
            case Token::STR:
                $this->advance();
                return $this->finish(['type' => 'Literal', 'value' => $t->value, 'raw' => $t->raw], $start);
            case Token::TPL:
                $this->advance();
                return $this->parseTemplate($t);
            //CLD: 2026-10-20T22:10:00+03:00: a regular expression literal (ESTree: a Literal with `regex`)
            case Token::REGEX:
                $this->advance();
                return $this->finish(['type' => 'Literal', 'value' => null, 'raw' => $t->raw, 'regex' => $t->value], $start);
            case Token::IDENT:
                $this->advance();
                return $this->finish(['type' => 'Identifier', 'name' => $t->value], $start);
            case Token::INVALID:
                $this->advance();
                return $this->errorExpression($start);
            case Token::KW:
                switch ($t->value) {
                    case 'true':
                    case 'false':
                        $this->advance();
                        return $this->finish(['type' => 'Literal', 'value' => $t->value === 'true', 'raw' => $t->value], $start);
                    case 'null':
                        $this->advance();
                        return $this->finish(['type' => 'Literal', 'value' => null, 'raw' => 'null'], $start);
                    case 'function':
                        return $this->parseFunction(false);
                    case 'this':
                        $this->advance();
                        $this->error("'this' is not supported", $t->start, $t->end);
                        return $this->finish(['type' => 'ThisExpression'], $start);
                    case 'super':
                        $this->advance();
                        $this->error("'super' is not supported", $t->start, $t->end);
                        return $this->errorExpression($start);
                    case 'new':
                        $this->advance();
                        //CLD: 2026-10-20T21:01:44+03:00: says what to write instead (users write `new Date()`)
                        $this->error("'new' is not allowed: call the function without it (Date(), Error('...'))", $t->start, $t->end);
                        $callee = $this->parseLeftHandSide();
                        return $this->finish(['type' => 'NewExpression', 'callee' => $callee, 'arguments' => []], $start);
                    case 'class':
                        return $this->parseClassDisallowed('DisallowedExpression');
                    case 'import':
                        $this->advance();
                        $this->error('Imports are not allowed', $t->start, $t->end);
                        return $this->finish(['type' => 'DisallowedExpression', 'keyword' => 'import'], $start);
                    case 'async':
                    case 'yield':
                        $this->advance();
                        $this->error("'{$t->value}' is not supported", $t->start, $t->end);
                        return $this->parseAssignment();
                }
                break;
            case Token::PUNCT:
                switch ($t->value) {
                    case '(':
                        $this->advance();
                        $expr = $this->parseExpressionNoInReset();
                        $this->expectP(')');
                        return $expr;
                    case '[':
                        return $this->parseArrayLiteral();
                    case '{':
                        return $this->parseObjectLiteral();
                    case '/':
                    case '/=':
                        $this->advance();
                        $this->error('Regular expressions are not supported', $t->start, $t->end);
                        // Skip the rest of the would-be regex on this line.
                        while (!$this->eof() && !$this->peek()->nlBefore && !$this->isP('/') && !$this->isP(';')) {
                            $this->advance();
                        }
                        $this->eatP('/');
                        if ($this->peek()->type === Token::IDENT && !$this->peek()->nlBefore) {
                            $this->advance(); // flags
                        }
                        return $this->errorExpression($start);
                }
                break;
        }

        $this->error('Expression expected but found ' . $this->describe($t), $t->start, $t->end);
        $isBoundary = $t->type === Token::EOF
            || ($t->type === Token::PUNCT && in_array($t->value, [')', ']', '}', ';', ',', ':'], true))
            || ($t->type === Token::KW && isset(self::STATEMENT_KEYWORDS[$t->value]));
        if (!$isBoundary) {
            $this->advance();
        }
        return $this->errorExpression($start);
    }

    //CLD: 2026-10-13T12:17:47+03:00: modes expression / reference: a Program of one ExpressionStatement (no statements; for the interpreter as any program)
    private function parseSingleProgram(int $length, bool $reference): array
    {
        $start = $this->peek()->start;
        $expr = $reference ? $this->parseReferenceAll() : $this->parseExpressionAll();
        return [
            'type' => 'Program',
            'body' => [['type' => 'ExpressionStatement', 'expression' => $expr, 'start' => $start, 'end' => $expr['end']]],
            'varNames' => [],
            'start' => 0,
            'end' => $length,
        ];
    }

    //one expression and nothing after it (a statement keyword or `;` is reported as such)
    private function parseExpressionAll(): array
    {
        $t = $this->peek();
        if ($t->type === Token::EOF) {
            $this->error('Expression expected', $t->start, $t->end);
            return $this->errorExpression($t->start);
        }
        if (($t->type === Token::KW && (isset(self::STATEMENT_KEYWORDS[$t->value]) || in_array($t->value, ['class', 'import', 'export'], true))) || $this->isP(';')) {
            $this->error(self::EXPRESSION_ONLY, $t->start, $this->tokens[count($this->tokens) - 1]->start);
            $this->i = count($this->tokens) - 1;
            return $this->errorExpression($t->start);
        }
        $expr = $this->parseExpression();
        if (!$this->eof()) {
            $t = $this->peek();
            $end = $this->tokens[count($this->tokens) - 1]->start;
            $this->error($this->isP(';') || $t->type === Token::KW ? self::EXPRESSION_ONLY : 'Unexpected ' . $this->describe($t), $t->start, $t->type === Token::KW || $this->isP(';') ? $end : $t->end);
            $this->i = count($this->tokens) - 1;
        }
        return $expr;
    }

    //a reference: a name, then .field, ["field"] or [0] (no calls, operators or computed keys); anything else is reported once (REFERENCE_ONLY)
    private function parseReferenceAll(): array
    {
        $t = $this->peek();
        $last = $this->tokens[count($this->tokens) - 1]->start;
        if ($t->type !== Token::IDENT) {
            $this->error($t->type === Token::EOF ? 'A variable name is expected, e.g. mail' : self::REFERENCE_ONLY, $t->start, $t->type === Token::EOF ? $t->end : $last);
            $this->i = count($this->tokens) - 1;
            return $this->errorExpression($t->start);
        }
        $start = $t->start;
        $this->advance();
        $expr = $this->finish(['type' => 'Identifier', 'name' => $t->value], $start);
        while (true) {
            if ($this->eatP('.')) {
                $property = $this->parsePropertyName();
                $expr = $this->finish(['type' => 'MemberExpression', 'object' => $expr, 'property' => $property, 'computed' => false, 'optional' => false], $start);
            } elseif ($this->isP('[') && in_array($this->peek(1)->type, [Token::STR, Token::NUM], true) && $this->isP(']', 2)) {
                $this->advance();
                $k = $this->advance();
                $property = $this->finish(['type' => 'Literal', 'value' => $k->value, 'raw' => $k->raw], $k->start);
                $this->advance();
                $expr = $this->finish(['type' => 'MemberExpression', 'object' => $expr, 'property' => $property, 'computed' => true, 'optional' => false], $start);
            } else {
                break;
            }
        }
        if (!$this->eof()) {
            $t = $this->peek();
            $this->error(self::REFERENCE_ONLY, $t->start, max($t->end, $last));
            $this->i = count($this->tokens) - 1;
        }
        return $expr;
    }

    //mode template: the text parts and the {{ }} parts (each parsed by its own parser with offsets in $text); $whole: one {{ }} and only blanks around it
    private function parseTemplateText(string $text, bool $reference, bool $whole): array
    {
        $parts = [];
        $at = 0;
        $len = strlen($text);
        foreach (Template::parts($text) as $p) {
            if ($p['open'] > $at) {
                $parts[] = ['type' => 'TemplateText', 'value' => substr($text, $at, $p['open'] - $at), 'start' => $at, 'end' => $p['open']];
            }
            if (!$p['closed']) {
                $this->error('`{{` is not closed with `}}`', $p['open'], $p['open'] + strlen(Template::OPEN));
                $parts[] = ['type' => 'TemplateExpression', 'expression' => ['type' => 'ErrorExpression', 'start' => $p['start'], 'end' => $len], 'start' => $p['open'], 'end' => $len];
                $at = $len;
                break;
            }
            $code = substr($text, $p['start'], $p['end'] - $p['start']);
            $close = $p['end'] + strlen(Template::CLOSE);
            if (trim($code) === '') {
                $this->error('Empty expression', $p['open'], $close);
                $expr = ['type' => 'ErrorExpression', 'start' => $p['start'], 'end' => $p['end']];
            } else {
                $sub = new self($code, $p['start'], $this->options, $this->depth);
                $expr = $reference ? $sub->parseReferenceAll() : $sub->parseExpressionAll();
                array_push($this->diagnostics, ...$sub->diagnostics);
            }
            $parts[] = ['type' => 'TemplateExpression', 'expression' => $expr, 'start' => $p['open'], 'end' => $close];
            $at = $close;
        }
        if ($at < $len) {
            $parts[] = ['type' => 'TemplateText', 'value' => substr($text, $at), 'start' => $at, 'end' => $len];
        }

        if ($whole && trim($text) !== '') {
            //CLD: 2026-10-13T12:43:20+03:00: a plain text in a whole expression field: write it between {{ }} (was the "only one" message)
            $example = $reference ? '{{ mail.subject }}' : '{{ mail.attachments }}';
            $message = 'Only one {{ }} and nothing around it is allowed here, e.g. ' . $example;
            $seen = 0;
            foreach ($parts as $part) {
                if ($part['type'] === 'TemplateExpression' && ++$seen === 1) {
                    continue;
                }
                if ($part['type'] === 'TemplateText' && trim($part['value']) === '') {
                    continue;
                }
                $this->error($seen === 0 && count($parts) === 1 ? ($reference ? 'Write the variable between {{ and }}, e.g. ' . $example : 'Write the expression between {{ and }}, e.g. ' . $example) : $message, $part['start'], $part['end']);
                break;
            }
        }
        return ['type' => 'Template', 'parts' => $parts, 'start' => 0, 'end' => $len];
    }

    private function parseTemplate(Token $t): array
    {
        $quasis = [];
        foreach ($t->value['quasis'] as $cooked) {
            $quasis[] = ['type' => 'TemplateElement', 'value' => ['cooked' => $cooked], 'start' => $t->start, 'end' => $t->end];
        }
        $expressions = [];
        foreach ($t->value['exprs'] as $part) {
            $sub = new self($part['source'], $part['offset'], $this->options, $this->depth);
            $sub->functionDepth = $this->functionDepth;
            $expressions[] = $sub->parseStandaloneExpression($part['offset']);
            array_push($this->diagnostics, ...$sub->diagnostics);
        }
        return $this->finish(['type' => 'TemplateLiteral', 'quasis' => $quasis, 'expressions' => $expressions], $t->start);
    }

    private function parseStandaloneExpression(int $offset): array
    {
        if ($this->eof()) {
            $this->error('Expression expected in template literal', $offset - 2, $offset + 1);
            return ['type' => 'ErrorExpression', 'start' => $offset, 'end' => $offset];
        }
        $expr = $this->parseExpression();
        if (!$this->eof()) {
            $t = $this->peek();
            $this->error('Unexpected ' . $this->describe($t) . ' in template expression', $t->start, $t->end);
        }
        return $expr;
    }

    private function parseArrayLiteral(): array
    {
        $start = $this->advance()->start;
        $saved = $this->noIn;
        $this->noIn = false;
        $elements = [];
        while (!$this->isP(']') && !$this->eof()) {
            if ($this->isP(',')) {
                $this->advance();
                $elements[] = null; // hole
                continue;
            }
            if ($this->isP('...')) {
                $s = $this->advance()->start;
                $arg = $this->parseAssignment();
                $elements[] = $this->finish(['type' => 'SpreadElement', 'argument' => $arg], $s);
            } else {
                $elements[] = $this->parseAssignment();
            }
            if (!$this->isP(']') && !$this->expectP(',')) {
                break;
            }
        }
        $this->noIn = $saved;
        $this->expectP(']');
        return $this->finish(['type' => 'ArrayExpression', 'elements' => $elements], $start);
    }

    private function isPropertyStart(): bool
    {
        $t = $this->peek();
        return in_array($t->type, [Token::IDENT, Token::KW, Token::STR, Token::NUM], true)
            || $this->isP('[') || $this->isP('...');
    }

    private function parseObjectLiteral(): array
    {
        $start = $this->advance()->start;
        $saved = $this->noIn;
        $this->noIn = false;
        $properties = [];

        while (!$this->isP('}') && !$this->eof()) {
            $before = $this->i;
            $pStart = $this->peek()->start;

            if ($this->isP('...')) {
                $this->advance();
                $arg = $this->parseAssignment();
                $properties[] = $this->finish(['type' => 'SpreadElement', 'argument' => $arg], $pStart);
            } else {
                $property = $this->parseProperty($pStart);
                if ($property !== null) {
                    $properties[] = $property;
                }
            }

            if ($this->isP('}')) {
                break;
            }
            if (!$this->eatP(',')) {
                $t = $this->peek();
                $this->error("Expected ',' but found " . $this->describe($t), $t->start, $t->end);
                if (!$this->isPropertyStart() || !$t->nlBefore) {
                    // resync to the next ',' or '}'
                    while (!$this->eof() && !$this->isP(',') && !$this->isP('}')) {
                        if ($this->isP('(') || $this->isP('[') || $this->isP('{')) {
                            $this->skipBalancedTokens();
                        } else {
                            $this->advance();
                        }
                    }
                    $this->eatP(',');
                }
            }
            if ($this->i === $before) {
                $this->advance();
            }
        }
        $this->noIn = $saved;
        $this->expectP('}');
        return $this->finish(['type' => 'ObjectExpression', 'properties' => $properties], $start);
    }

    private function parseProperty(int $pStart): ?array
    {
        $t = $this->peek();

        // get x() {} / set x(v) {} / async x() {} -- rejected, but parsed as a method.
        if (($t->type === Token::IDENT && ($t->value === 'get' || $t->value === 'set')) || $this->isK('async')) {
            $n = $this->peek(1);
            $nextIsKey = in_array($n->type, [Token::IDENT, Token::KW, Token::STR, Token::NUM], true) || $this->isP('[', 1);
            if ($nextIsKey) {
                $this->advance();
                $this->error($t->value === 'async' ? 'Async methods are not supported' : 'Getters and setters are not supported', $t->start, $t->end);
                $t = $this->peek();
            }
        }
        if ($this->isP('*')) {
            $star = $this->advance();
            $this->error('Generator methods are not supported', $star->start, $star->end);
            $t = $this->peek();
        }

        $computed = false;
        if ($this->eatP('[')) {
            $key = $this->parseAssignment();
            $this->expectP(']');
            $computed = true;
        } elseif ($t->type === Token::IDENT || $t->type === Token::KW) {
            $this->advance();
            $key = $this->finish(['type' => 'Identifier', 'name' => $t->value], $t->start);
        } elseif ($t->type === Token::STR || $t->type === Token::NUM) {
            $this->advance();
            $key = $this->finish(['type' => 'Literal', 'value' => $t->value, 'raw' => $t->raw], $t->start);
        } else {
            $this->error('Property name expected but found ' . $this->describe($t), $t->start, $t->end);
            return null;
        }

        if ($this->eatP(':')) {
            $value = $this->parseAssignment();
            return $this->finish(['type' => 'Property', 'key' => $key, 'value' => $value, 'computed' => $computed, 'shorthand' => false, 'method' => false, 'kind' => 'init'], $pStart);
        }
        if ($this->isP('(')) {
            $fStart = $this->peek()->start;
            [$params, $body, $varNames] = $this->parseFunctionRest();
            $value = $this->finish(['type' => 'FunctionExpression', 'id' => null, 'params' => $params, 'body' => $body, 'varNames' => $varNames], $fStart);
            return $this->finish(['type' => 'Property', 'key' => $key, 'value' => $value, 'computed' => $computed, 'shorthand' => false, 'method' => true, 'kind' => 'init'], $pStart);
        }
        if (!$computed && $key['type'] === 'Identifier') {
            if ($t->type === Token::KW) {
                $this->error("'{$t->value}' is a reserved word and cannot be used as a shorthand property", $t->start, $t->end);
            }
            if ($this->isP('=')) {
                $eq = $this->advance();
                $this->error('Shorthand property defaults are only valid in destructuring', $eq->start, $eq->end);
                $this->parseAssignment();
            }
            return $this->finish(['type' => 'Property', 'key' => $key, 'value' => $key, 'computed' => false, 'shorthand' => true, 'method' => false, 'kind' => 'init'], $pStart);
        }
        $n = $this->peek();
        $this->error("Expected ':' but found " . $this->describe($n), $n->start, $n->end);
        return $this->finish(['type' => 'Property', 'key' => $key, 'value' => $this->errorExpression($n->start), 'computed' => $computed, 'shorthand' => false, 'method' => false, 'kind' => 'init'], $pStart);
    }
}
