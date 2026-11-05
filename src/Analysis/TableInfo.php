<?php

namespace Overthink\DbSnapshot\Analysis;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Overthink\DbSnapshot\Profile\ColumnRule;

final class TableInfo
{
    /**
     * @param  list<DateColumn>  $dateColumns
     * @param  list<ColumnInfo>  $columns  all columns, in table order (empty when the driver doesn't report them)
     * @param  array<string, ColumnRule>  $personalData  columns that look personal, with a suggested replacement
     * @param  list<Relation>  $relations  columns pointing at other tables, foreign keys first
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $isView,
        public readonly int $rows,
        public readonly int $dataBytes,
        public readonly int $indexBytes,
        public readonly array $dateColumns = [],
        public readonly array $columns = [],
        public readonly array $personalData = [],
        public readonly array $relations = [],
    ) {}

    public function relation(string $parent, string $column): ?Relation
    {
        foreach ($this->relations as $relation) {
            if ($relation->parent === $parent && $relation->column === $column) {
                return $relation;
            }
        }

        return null;
    }

    public function column(string $name): ?ColumnInfo
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }

    public function bytes(): int
    {
        return $this->dataBytes + $this->indexBytes;
    }

    public function megabytes(): float
    {
        return $this->bytes() / 1024 / 1024;
    }

    public function dateColumn(string $name): ?DateColumn
    {
        foreach ($this->dateColumns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }

    /**
     * @return list<DateColumn>
     */
    public function indexedDateColumns(): array
    {
        return array_values(array_filter($this->dateColumns, fn (DateColumn $column): bool => $column->indexed));
    }

    /**
     * Rough share of rows at or after $since, assuming rows are spread evenly
     * between the column's min and max. Null when the range is unknown.
     */
    public function shareSince(string $column, CarbonImmutable $since): ?float
    {
        $dateColumn = $this->dateColumn($column);

        if ($dateColumn?->min === null || $dateColumn->max === null) {
            return null;
        }

        try {
            $min = CarbonImmutable::parse($dateColumn->min)->getTimestamp();
            $max = CarbonImmutable::parse($dateColumn->max)->getTimestamp();
        } catch (InvalidFormatException) {
            return null;
        }

        if ($max <= $min) {
            return $since->getTimestamp() <= $max ? 1.0 : 0.0;
        }

        return max(0.0, min(1.0, ($max - $since->getTimestamp()) / ($max - $min)));
    }

    /**
     * @param  array{name: string, is_view: bool, rows: int, data_bytes: int, index_bytes: int, date_columns: list<array{name: string, type: string, indexed: bool, min?: ?string, max?: ?string}>, columns?: list<array{name: string, type: string, kind?: string, max_length?: ?int, generated?: bool, primary_key?: bool, nullable?: bool}>, personal_data?: array<string, string|array<string, string>>, relations?: list<array{column: string, parent: string, references?: string, guessed?: bool, indexed?: bool}>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['is_view'],
            $data['rows'],
            $data['data_bytes'],
            $data['index_bytes'],
            array_map(DateColumn::fromArray(...), $data['date_columns']),
            array_map(ColumnInfo::fromArray(...), $data['columns'] ?? []),
            array_map(ColumnRule::fromJson(...), $data['personal_data'] ?? []),
            array_map(Relation::fromArray(...), $data['relations'] ?? []),
        );
    }

    /**
     * @return array{name: string, is_view: bool, rows: int, data_bytes: int, index_bytes: int, date_columns: list<array{name: string, type: string, indexed: bool, min: ?string, max: ?string}>, columns: list<array{name: string, type: string, kind: string, max_length: ?int, generated: bool, primary_key: bool, nullable: bool}>, personal_data: object, relations: list<array{column: string, parent: string, references: string, guessed: bool, indexed: bool}>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'is_view' => $this->isView,
            'rows' => $this->rows,
            'data_bytes' => $this->dataBytes,
            'index_bytes' => $this->indexBytes,
            'date_columns' => array_map(fn (DateColumn $column): array => $column->toArray(), $this->dateColumns),
            'columns' => array_map(fn (ColumnInfo $column): array => $column->toArray(), $this->columns),
            'personal_data' => (object) array_map(fn (ColumnRule $rule): string|array => $rule->toJson(), $this->personalData),
            'relations' => array_map(fn (Relation $relation): array => $relation->toArray(), $this->relations),
        ];
    }
}
