<?php

declare(strict_types=1);

namespace sys\J\Runtime;

/** The JavaScript `undefined` value (singleton; PHP null is JavaScript null). */
final class Undefined
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function value(): self
    {
        return self::$instance ??= new self();
    }
}
