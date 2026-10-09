<?php

namespace Overthink\DbSnapshot\Analysis;

use Carbon\CarbonImmutable;
use Overthink\DbSnapshot\Remote\RemoteMysql;

/**
 * Reads table sizes and date columns from the source database's information_schema.
 */
final class Analyzer
{
    public function __construct(private readonly RemoteMysql $mysql) {}

    /**
     * Ranges (MIN/MAX) are only read for indexed date columns of tables at
     * least $rangeThresholdMb big, so they stay index lookups on the server.
     */
    public function analyze(int $rangeThresholdMb = 200): Analysis
    {
        $tables = $this->tables();
        $dateColumns = $this->dateColumns();
        $ranges = $this->ranges($tables, $dateColumns, $rangeThresholdMb * 1024 * 1024);

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

        return new Analysis($this->mysql->database, CarbonImmutable::now(), $result);
    }

    /**
     * Tables and views of the source database, largest first.
     *
     * @return array<string, TableInfo>
     */
    public function tables(): array
    {
        $rows = $this->mysql->select(
            'SELECT TABLE_NAME, TABLE_TYPE, IFNULL(TABLE_ROWS, 0), IFNULL(DATA_LENGTH, 0), IFNULL(INDEX_LENGTH, 0)'
            .' FROM information_schema.TABLES WHERE TABLE_SCHEMA = '.$this->mysql->quote($this->mysql->database)
            .' ORDER BY IFNULL(DATA_LENGTH, 0) + IFNULL(INDEX_LENGTH, 0) DESC, TABLE_NAME'
        );

        $tables = [];

        foreach ($rows as [$name, $type, $tableRows, $dataBytes, $indexBytes]) {
            $tables[$name] = new TableInfo($name, $type === 'VIEW', (int) $tableRows, (int) $dataBytes, (int) $indexBytes);
        }

        return $tables;
    }

    /**
     * @return array<string, list<DateColumn>>
     */
    private function dateColumns(): array
    {
        $schema = $this->mysql->quote($this->mysql->database);

        $rows = $this->mysql->select(
            'SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE, EXISTS('
            .'SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA = c.TABLE_SCHEMA'
            .' AND s.TABLE_NAME = c.TABLE_NAME AND s.COLUMN_NAME = c.COLUMN_NAME AND s.SEQ_IN_INDEX = 1)'
            .' FROM information_schema.COLUMNS c'
            ." WHERE c.TABLE_SCHEMA = {$schema} AND c.DATA_TYPE IN ('date', 'datetime', 'timestamp')"
            .' ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION'
        );

        $columns = [];

        foreach ($rows as [$table, $column, $type, $indexed]) {
            $columns[$table][] = new DateColumn($column, $type, $indexed === '1');
        }

        return $columns;
    }

    /**
     * @param  array<string, TableInfo>  $tables
     * @param  array<string, list<DateColumn>>  $dateColumns
     * @return array<string, array<string, array{0: ?string, 1: ?string}>>
     */
    private function ranges(array $tables, array $dateColumns, int $thresholdBytes): array
    {
        $selects = [];

        foreach ($tables as $name => $table) {
            if ($table->isView || $table->bytes() < $thresholdBytes) {
                continue;
            }

            foreach ($dateColumns[$name] ?? [] as $column) {
                if (! $column->indexed) {
                    continue;
                }

                $selects[] = sprintf(
                    'SELECT %s, %s, MIN(`%s`), MAX(`%s`) FROM `%s`.`%s`',
                    $this->mysql->quote($name),
                    $this->mysql->quote($column->name),
                    $column->name,
                    $column->name,
                    $this->mysql->database,
                    $name,
                );
            }
        }

        if ($selects === []) {
            return [];
        }

        $ranges = [];

        foreach ($this->mysql->select(implode(' UNION ALL ', $selects)) as [$table, $column, $min, $max]) {
            $ranges[$table][$column] = [$min, $max];
        }

        return $ranges;
    }
}
