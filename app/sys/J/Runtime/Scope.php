<?php

declare(strict_types=1);

namespace sys\J\Runtime;

final class Scope
{
    /** @var array<string, mixed> */
    public array $vars = [];
    /** @var array<string, true> */
    public array $consts = [];

    public function __construct(public readonly ?Scope $parent = null)
    {
    }
}
