<?php

declare(strict_types=1);

namespace sys\J\Runtime;

/** A JavaScript exception in flight (catchable by script try/catch). */
final class JsThrow extends \Exception
{
    public function __construct(
        public readonly mixed $value,
        string $message,
        public readonly int $offset = -1,
    ) {
        parent::__construct($message);
    }
}
