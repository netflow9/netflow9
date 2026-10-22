<?php

declare(strict_types=1);

namespace sys\J\Runtime;

/** @internal Used to short-circuit an optional chain (a?.b.c). Never escapes the VM. */
final class ChainShortCircuit extends \Exception
{
    private static ?self $instance = null;

    public static function get(): self
    {
        return self::$instance ??= new self();
    }
}
