<?php

declare(strict_types=1);

namespace sys\J\Runtime;

/** A plain JavaScript object. No prototype chain: properties are own properties only. */
final class JsObject
{
    /** @param array<string|int, mixed> $props */
    public function __construct(public array $props = [])
    {
    }
}
