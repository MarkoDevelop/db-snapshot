<?php

namespace Overthink\DbSnapshot\Analysis;

use Carbon\CarbonImmutable;
use Overthink\DbSnapshot\Contracts\AnonymizesColumns;
use Overthink\DbSnapshot\Contracts\Driver;
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

            $result[$name] = new TableInfo($name, $table->isView, $table->rows, $table->dataBytes, $table->indexBytes, $columns, $allColumns[$name] ?? [], $personalData);
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
