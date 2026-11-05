<?php

namespace Overthink\DbSnapshot\Analysis;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Overthink\DbSnapshot\Contracts\AnonymizesColumns;
use Overthink\DbSnapshot\Contracts\Driver;
use Overthink\DbSnapshot\Contracts\FollowsRelations;
use Overthink\DbSnapshot\Support\PersonalDataColumns;

/**
 * Collects table sizes and date columns of the source database.
 */
final class Analyzer
{
    public function __construct(
        private readonly Driver $driver,
        private readonly bool $suggestPersonalData = false,
    ) {}

    /**
     * Ranges (MIN/MAX) are only read for indexed date columns of tables at
     * least $rangeThresholdMb big, so they stay index lookups on the server.
     */
    public function analyze(int $rangeThresholdMb = 200): Analysis
    {
        $tables = $this->driver->tables();
        $dateColumns = $this->driver->dateColumns();
        $ranges = $this->driver->dateRanges($this->rangeColumns($tables, $dateColumns, $rangeThresholdMb * 1024 * 1024));
        $allColumns = $this->driver instanceof AnonymizesColumns ? $this->driver->columns() : [];
        $relations = $this->relations($tables, $allColumns);

        $result = [];

        foreach ($tables as $name => $table) {
            $columns = array_map(
                fn (DateColumn $column): DateColumn => new DateColumn(
                    $column->name,
                    $column->type,
                    $column->indexed,
                    $ranges[$name][$column->name][0] ?? null,
                    $ranges[$name][$column->name][1] ?? null,
                ),
                $dateColumns[$name] ?? [],
            );

            $personalData = [];

            foreach ($table->isView || ! $this->suggestPersonalData ? [] : $allColumns[$name] ?? [] as $column) {
                if (($suggestion = PersonalDataColumns::suggest($name, $column)) !== null) {
                    $personalData[$column->name] = $suggestion;
                }
            }

            $result[$name] = new TableInfo($name, $table->isView, $table->rows, $table->dataBytes, $table->indexBytes, $columns, $allColumns[$name] ?? [], $personalData, $relations[$name] ?? []);
        }

        return new Analysis($this->driver->database(), CarbonImmutable::now(), $result);
    }

    /**
     * Tables and views of the source database, largest first.
     *
     * @return array<string, TableInfo>
     */
    public function tables(): array
    {
        return $this->driver->tables();
    }

    /**
     * Foreign keys, plus <name>_id columns whose name points at a table with
     * an id column of a comparable type. Polymorphic pairs (<name>_id next to <name>_type) and
     * columns pointing at their own table are left out.
     *
     * @param  array<string, TableInfo>  $tables
     * @param  array<string, list<ColumnInfo>>  $columns
     * @return array<string, list<Relation>>
     */
    private function relations(array $tables, array $columns): array
    {
        if (! $this->driver instanceof FollowsRelations) {
            return [];
        }

        $indexed = $this->driver->indexedColumns();
        $relations = [];

        foreach ($this->driver->foreignKeys() as $table => $keys) {
            foreach ($keys as $key) {
                if ($key['parent'] !== $table && isset($tables[$key['parent']])) {
                    $relations[$table][$key['column']] = new Relation($key['column'], $key['parent'], $key['references'], indexed: in_array($key['column'], $indexed[$table] ?? [], true));
                }
            }
        }

        foreach ($columns as $table => $tableColumns) {
            if (! isset($tables[$table]) || $tables[$table]->isView) {
                continue;
            }

            $names = array_map(fn (ColumnInfo $column): string => $column->name, $tableColumns);

            foreach ($tableColumns as $column) {
                if (isset($relations[$table][$column->name]) || ! preg_match('/^([a-z0-9_]+)_id$/', $column->name, $match)
                    || in_array($match[1].'_type', $names, true)) {
                    continue;
                }

                $parent = current(array_filter(
                    array_unique([Str::plural($match[1]), $match[1]]),
                    fn (string $candidate): bool => $candidate !== $table && isset($tables[$candidate]) && ! $tables[$candidate]->isView
                        && $this->idColumn($columns[$candidate] ?? [])?->isComparableWith($column) === true,
                ));

                if ($parent !== false) {
                    $relations[$table][$column->name] = new Relation($column->name, $parent, guessed: true, indexed: in_array($column->name, $indexed[$table] ?? [], true));
                }
            }
        }

        return array_map(array_values(...), $relations);
    }

    /**
     * @param  list<ColumnInfo>  $columns
     */
    private function idColumn(array $columns): ?ColumnInfo
    {
        foreach ($columns as $column) {
            if ($column->name === 'id') {
                return $column;
            }
        }

        return null;
    }

    /**
     * @param  array<string, TableInfo>  $tables
     * @param  array<string, list<DateColumn>>  $dateColumns
     * @return array<string, list<string>>
     */
    private function rangeColumns(array $tables, array $dateColumns, int $thresholdBytes): array
    {
        $columns = [];

        foreach ($tables as $name => $table) {
            if ($table->isView || $table->bytes() < $thresholdBytes) {
                continue;
            }

            foreach ($dateColumns[$name] ?? [] as $column) {
                if ($column->indexed) {
                    $columns[$name][] = $column->name;
                }
            }
        }

        return $columns;
    }
}
