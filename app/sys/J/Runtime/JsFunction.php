<?php

declare(strict_types=1);

namespace sys\J\Runtime;

/** A function defined in script code: an AST node plus the scope it closes over. */
final class JsFunction
{
    public function __construct(
        public readonly array $node,
        public readonly Scope $scope,
        public string $name = '',
    ) {
    }
}
