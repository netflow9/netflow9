<?php

declare(strict_types=1);

namespace sys\J;

/** Thrown to the host when a script throws an uncaught exception or has syntax errors. */
final class ScriptError extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly mixed $jsValue = null,
        public int $line = 0,
        public readonly ?int $column = null,
        public readonly array $diagnostics = [],
    ) {
        parent::__construct($message);
    }
}
