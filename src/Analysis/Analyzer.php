<?php

namespace Overthink\DbSnapshot\Analysis;

use Carbon\CarbonImmutable;
use Overthink\DbSnapshot\Contracts\Driver;

/**
 * Collects table sizes and date columns of the source database.
 */
final class Analyzer
{
    public function __construct(private readonly Driver $driver) {}

    /**
     * Ranges (MIN/MAX) are only read for indexed date columns of tables at
     * least $rangeThresholdMb big, so they stay index lookups on the server.
     */
    public function analyze(int $rangeThresholdMb = 200): Analysis
    {
        $tables = $this->driver->tables();
        $dateColumns = $this->driver->dateColumns();
        $ranges = $this->driver->dateRanges($this->rangeColumns($tables, $dateColumns, $rangeThresholdMb * 1024 * 1024));

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

            $result[$name] = new TableInfo($name, $table->isView, $table->rows, $table->dataBytes, $table->indexBytes, $columns);
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
