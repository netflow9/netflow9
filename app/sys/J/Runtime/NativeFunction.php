<?php

declare(strict_types=1);

namespace sys\J\Runtime;

/**
 * A function implemented in PHP.
 * $fn receives (list<mixed> $jsArgs, \sys\J\Interpreter $vm) and returns a JS value.
 * $props lets a function carry static members (e.g. Object.keys, Number.isInteger).
 */
final class NativeFunction
{
    /** @param array<string, mixed> $props */
    public function __construct(
        public readonly \Closure $fn,
        public readonly string $name = '',
        public array $props = [],
    ) {
    }
}
