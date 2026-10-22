<?php

declare(strict_types=1);

namespace sys\J;

final class Token
{
    public const EOF = 'eof';
    public const NUM = 'num';
    public const STR = 'str';
    public const TPL = 'tpl';
    //CLD: 2026-10-20T22:10:00+03:00: a regular expression literal: value ['pattern' => ..., 'flags' => ...]
    public const REGEX = 'regex';
    public const IDENT = 'ident';
    public const KW = 'kw';
    public const PUNCT = 'punct';
    public const INVALID = 'invalid';

    /**
     * @param mixed $value keyword/punctuator/identifier text, literal value,
     *                     or ['quasis' => list<string>, 'exprs' => list<array{source:string, offset:int}>] for templates
     */
    public function __construct(
        public readonly string $type,
        public readonly mixed $value,
        public readonly int $start,
        public readonly int $end,
        public readonly bool $nlBefore,
        public readonly string $raw = '',
    ) {
    }
}
