<?php

declare(strict_types=1);

namespace sys\J\Runtime;

/** A JavaScript array (reference semantics, unlike PHP arrays). Holes are stored as Undefined. */
final class JsArray
{
    //CLD: 2026-10-20T22:10:00+03:00: + $props: named properties besides the items (read-only for scripts; e.g. index, input, groups of a regular expression match)
    /**
     * @param list<mixed> $items
     * @param array<string, mixed> $props
     */
    public function __construct(public array $items = [], public array $props = [])
    {
    }
}
