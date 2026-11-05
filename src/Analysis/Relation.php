<?php

namespace Overthink\DbSnapshot\Analysis;

/**
 * A column of one table pointing at a row of another (its parent), either
 * a real foreign key or guessed from the column name.
 */
final class Relation
{
    public function __construct(
        public readonly string $column,
        public readonly string $parent,
        public readonly string $references = 'id',
        public readonly bool $guessed = false,
        public readonly bool $indexed = true,
    ) {}

    public function describe(): string
    {
        return "{$this->parent} by {$this->column}".($this->guessed ? ' (guessed from the name)' : ' (foreign key)');
    }

    /**
     * @param  array{column: string, parent: string, references?: string, guessed?: bool, indexed?: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['column'], $data['parent'], $data['references'] ?? 'id', $data['guessed'] ?? false, $data['indexed'] ?? true);
    }

    /**
     * @return array{column: string, parent: string, references: string, guessed: bool, indexed: bool}
     */
    public function toArray(): array
    {
        return [
            'column' => $this->column,
            'parent' => $this->parent,
            'references' => $this->references,
            'guessed' => $this->guessed,
            'indexed' => $this->indexed,
        ];
    }
}
