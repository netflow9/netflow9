<?php

declare(strict_types=1);

namespace sys\J;

/**
 * Output of Parser::parse(). The AST is always produced, even for broken input.
 *
 * Every diagnostic has: severity, message, start, end (byte offsets),
 * line, column, endLine, endColumn (1-based, columns counted in characters).
 */
final class ParseResult implements \JsonSerializable
{
    /** @var list<int>|null */
    private ?array $lineStarts = null;

    /**
     * @param array<string, mixed> $ast
     * @param list<array<string, mixed>> $diagnostics
     */
    public function __construct(
        public readonly array $ast,
        public readonly array $diagnostics,
        public readonly string $source,
    ) {
    }

    public function hasErrors(): bool
    {
        foreach ($this->diagnostics as $d) {
            if ($d['severity'] === 'error') {
                return true;
            }
        }
        return false;
    }

    /** @return list<array<string, mixed>> */
    public function errors(): array
    {
        return array_values(array_filter($this->diagnostics, fn ($d) => $d['severity'] === 'error'));
    }

    /** @return array{line:int, column:int} 1-based */
    public function positionAt(int $offset): array
    {
        if ($this->lineStarts === null) {
            $this->lineStarts = [0];
            $p = -1;
            while (($p = strpos($this->source, "\n", $p + 1)) !== false) {
                $this->lineStarts[] = $p + 1;
            }
        }
        $offset = max(0, min($offset, strlen($this->source)));
        $lo = 0;
        $hi = count($this->lineStarts) - 1;
        while ($lo < $hi) {
            $mid = ($lo + $hi + 1) >> 1;
            if ($this->lineStarts[$mid] <= $offset) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        $lineStart = $this->lineStarts[$lo];
        $column = mb_strlen(substr($this->source, $lineStart, $offset - $lineStart), 'UTF-8') + 1;
        return ['line' => $lo + 1, 'column' => $column];
    }

    public function jsonSerialize(): array
    {
        return ['ast' => $this->ast, 'diagnostics' => $this->diagnostics];
    }
}
