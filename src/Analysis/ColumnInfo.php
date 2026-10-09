<?php

namespace Overthink\DbSnapshot\Analysis;

/**
 * One column of a source table, as far as anonymizing it is concerned.
 */
final class ColumnInfo
{
    public const TEXT = 'text';

    public const BINARY = 'binary';

    public const OTHER = 'other';

    /**
     * @param  'text'|'binary'|'other'  $kind  how the driver classified the type
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $kind = self::OTHER,
        public readonly ?int $maxLength = null,
        public readonly bool $generated = false,
        public readonly bool $primaryKey = false,
        public readonly bool $nullable = true,
    ) {}

    public function isText(): bool
    {
        return $this->kind === self::TEXT;
    }

    /**
     * @param  array{name: string, type: string, kind?: string, max_length?: ?int, generated?: bool, primary_key?: bool, nullable?: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        $kind = in_array($data['kind'] ?? null, [self::TEXT, self::BINARY], true) ? $data['kind'] : self::OTHER;

        return new self($data['name'], $data['type'], $kind, $data['max_length'] ?? null, $data['generated'] ?? false, $data['primary_key'] ?? false, $data['nullable'] ?? true);
    }

    /**
     * @return array{name: string, type: string, kind: string, max_length: ?int, generated: bool, primary_key: bool, nullable: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'kind' => $this->kind,
            'max_length' => $this->maxLength,
            'generated' => $this->generated,
            'primary_key' => $this->primaryKey,
            'nullable' => $this->nullable,
        ];
    }
}
