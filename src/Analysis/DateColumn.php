<?php

namespace Overthink\DbSnapshot\Analysis;

final class DateColumn
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $indexed,
        public readonly ?string $min = null,
        public readonly ?string $max = null,
    ) {}

    /**
     * @param  array{name: string, type: string, indexed: bool, min?: ?string, max?: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['type'], $data['indexed'], $data['min'] ?? null, $data['max'] ?? null);
    }

    /**
     * @return array{name: string, type: string, indexed: bool, min: ?string, max: ?string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'type' => $this->type, 'indexed' => $this->indexed, 'min' => $this->min, 'max' => $this->max];
    }
}
