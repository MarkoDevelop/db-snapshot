<?php

namespace Overthink\DbSnapshot\Contracts;

use Overthink\DbSnapshot\Analysis\ColumnInfo;
use Overthink\DbSnapshot\Profile\ColumnRule;

/**
 * Optional for drivers: replace personal data on the server while dumping,
 * so the real values never leave it.
 */
interface AnonymizesColumns
{
    /**
     * All columns of every table, in table order.
     *
     * @return array<string, list<ColumnInfo>>
     */
    public function columns(): array;

    /**
     * SQL expression producing the anonymized value of $column (NULL stays NULL).
     *
     * @param  ?string  $primaryKey  the single-column primary key, for {id} in templates
     */
    public function anonymizedExpression(ColumnInfo $column, ColumnRule $rule, string $salt, ?string $primaryKey): string;

    /**
     * Local shell command that dumps $table into $file like dumpTableCommand(),
     * with the given columns replaced by SQL expressions.
     *
     * @param  list<ColumnInfo>  $columns  all columns of the table, in table order
     * @param  array<string, string>  $expressions  column => SQL expression
     */
    public function dumpAnonymizedTableCommand(string $table, ?string $where, string $file, array $columns, array $expressions): string;
}
