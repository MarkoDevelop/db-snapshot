<?php

namespace Overthink\DbSnapshot\Contracts;

/**
 * Optional for drivers: read how tables point at each other, so a table can
 * copy only the rows belonging to the copied rows of its parent ("follow").
 */
interface FollowsRelations
{
    /**
     * Single-column foreign keys between tables of the source database.
     *
     * @return array<string, list<array{column: string, parent: string, references: string}>> table => keys
     */
    public function foreignKeys(): array;

    /**
     * Columns that lead an index, per table.
     *
     * @return array<string, list<string>>
     */
    public function indexedColumns(): array;

    /**
     * SQL condition selecting rows whose $column points at a row of $parent
     * matching $parentWhere (every row when null).
     */
    public function followCondition(string $column, string $parent, string $references, ?string $parentWhere): string;
}
