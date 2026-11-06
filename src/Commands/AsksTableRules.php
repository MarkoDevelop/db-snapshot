<?php

namespace Overthink\DbSnapshot\Commands;

use Carbon\CarbonImmutable;
use Overthink\DbSnapshot\Analysis\DateColumn;
use Overthink\DbSnapshot\Analysis\Relation;
use Overthink\DbSnapshot\Analysis\TableInfo;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Profile\TableRule;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Prompts for how much of a table to copy.
 */
trait AsksTableRules
{
    use Formats;

    private const MONTH_CHOICES = [1, 3, 6, 12, 24];

    /**
     * @param  array<string, TableRule>  $otherRules  rules of the other tables, so Follow never closes a loop
     * @param  array<string, TableInfo>  $tables  all tables, to point out the ones that could follow $table
     */
    protected function askRule(TableInfo $table, ?TableRule $current, array $otherRules = [], array $tables = []): TableRule
    {
        $relations = $this->followableRelations($table, $current, $otherRules);

        $dateColumns = $table->dateColumns;
        usort($dateColumns, fn (DateColumn $a, DateColumn $b): int => $b->indexed <=> $a->indexed);

        $modes = array_values(array_filter(TableMode::cases(), fn (TableMode $mode): bool => match ($mode) {
            TableMode::Recent => $dateColumns !== [],
            TableMode::Follow => $relations !== [],
            default => true,
        }));
        $suggested = $current->mode ?? ($table->indexedDateColumns() !== [] ? TableMode::Recent : TableMode::Schema);

        $mode = TableMode::from(select(
            label: "{$table->name} ({$this->formatBytes($table->bytes())}, ~{$this->formatRows($table->rows)} rows)",
            options: array_combine(
                array_map(fn (TableMode $mode): string => $mode->value, $modes),
                array_map(fn (TableMode $mode): string => $mode->label(), $modes),
            ),
            default: in_array($suggested, $modes, true) ? $suggested->value : TableMode::Full->value,
            hint: $this->childrenHint($table, $tables, $otherRules),
        ));

        return match ($mode) {
            TableMode::Recent => $this->askRecentRule($table, $dateColumns, $current),
            TableMode::Follow => $this->askFollowRule($table, $relations, $current),
            TableMode::Where => new TableRule(TableMode::Where, where: text(
                label: "WHERE clause for {$table->name}",
                placeholder: 'id > 1000000',
                default: (string) $current?->where,
                required: true,
            )),
            default => new TableRule($mode),
        };
    }

    /**
     * Relations $table can follow: the analysed ones (plus its current
     * follow rule, should the analysis not know it) whose parent doesn't
     * already follow $table.
     *
     * @param  array<string, TableRule>  $otherRules
     * @return list<Relation>
     */
    private function followableRelations(TableInfo $table, ?TableRule $current, array $otherRules): array
    {
        $relations = $table->relations;

        if ($current?->mode === TableMode::Follow && $table->relation((string) $current->parent, (string) $current->column) === null) {
            $relations[] = new Relation((string) $current->column, (string) $current->parent, $current->referencedColumn(), guessed: true);
        }

        return array_values(array_filter($relations, function (Relation $relation) use ($table, $otherRules): bool {
            $parent = $relation->parent;
            $seen = [];

            while ($parent !== $table->name) {
                $rule = $otherRules[$parent] ?? null;

                if ($rule?->mode !== TableMode::Follow || isset($seen[$parent])) {
                    return true;
                }

                $seen[$parent] = true;
                $parent = (string) $rule->parent;
            }

            return false;
        }));
    }

    /**
     * @param  list<Relation>  $relations
     */
    private function askFollowRule(TableInfo $table, array $relations, ?TableRule $current): TableRule
    {
        $options = [];

        foreach ($relations as $relation) {
            $options["{$relation->parent}.{$relation->column}"] = $table->relation($relation->parent, $relation->column) === null
                ? "{$relation->parent} by {$relation->column} (the current rule; snapshot:analyze doesn't know it)"
                : $relation->describe().($relation->indexed ? '' : " — {$relation->column} has no index, slow on large tables");
        }

        $key = count($options) === 1 ? (string) array_key_first($options) : (string) select(
            label: "Which table should {$table->name} follow?",
            options: $options,
            default: $current?->mode === TableMode::Follow ? "{$current->parent}.{$current->column}" : null,
            scroll: 10,
            hint: "Copies only {$table->name} rows pointing at rows that table copies.",
        );

        [$parent, $column] = explode('.', $key, 2);

        $picked = array_values(array_filter($relations, fn (Relation $relation): bool => $relation->parent === $parent && $relation->column === $column));

        return $this->followRule($picked[0] ?? $relations[0]);
    }

    /**
     * Follow goes from a child to its parent, so for a parent like orders the
     * way to bring order_items along is a filter here and Follow on them.
     *
     * @param  array<string, TableInfo>  $tables
     * @param  array<string, TableRule>  $otherRules
     */
    private function childrenHint(TableInfo $table, array $tables, array $otherRules): string
    {
        $children = array_keys(array_filter(
            $tables,
            fn (TableInfo $other): bool => $other->name !== $table->name
                && ($otherRules[$other->name] ?? null)?->parent !== $table->name
                && array_filter($other->relations, fn (Relation $relation): bool => $relation->parent === $table->name) !== [],
        ));

        if ($children === []) {
            return '';
        }

        $names = count($children) > 3
            ? implode(', ', array_slice($children, 0, 3)).' and '.(count($children) - 3).' more'
            : implode(', ', $children);

        return "{$names} point at {$table->name}. To copy only their rows of copied {$table->name}, filter {$table->name} here (Recent or Where) and let them Follow it.";
    }

    /**
     * Whether $table's rule, or the rule its follow chain ends at, is "recent".
     *
     * @param  array<string, TableRule>  $rules
     */
    protected function isRecentChain(string $table, array $rules): bool
    {
        $seen = [];

        while (($rules[$table] ?? null)?->mode === TableMode::Follow && ! isset($seen[$table])) {
            $seen[$table] = true;
            $table = (string) $rules[$table]->parent;
        }

        return ($rules[$table] ?? null)?->mode === TableMode::Recent;
    }

    protected function followRule(Relation $relation): TableRule
    {
        return new TableRule(TableMode::Follow, $relation->column, parent: $relation->parent, references: $relation->references);
    }

    /**
     * @param  list<DateColumn>  $dateColumns
     */
    private function askRecentRule(TableInfo $table, array $dateColumns, ?TableRule $current): TableRule
    {
        $columnNames = array_map(fn (DateColumn $column): string => $column->name, $dateColumns);
        $column = count($dateColumns) === 1 ? $columnNames[0] : (string) select(
            label: "Date column for {$table->name}",
            options: array_combine($columnNames, array_map(
                fn (DateColumn $column): string => $column->name.($column->indexed ? ' (indexed)' : ' (not indexed — slow on large tables)'),
                $dateColumns,
            )),
            default: in_array($current?->column, $columnNames, true) ? $current->column : $columnNames[0],
        );

        $options = [];

        foreach (self::MONTH_CHOICES as $months) {
            $share = $table->shareSince($column, CarbonImmutable::now()->subMonthsNoOverflow($months));
            $options[(string) $months] = "Last {$months} months".($share !== null ? ' (~'.$this->formatBytes($table->bytes() * $share).')' : '');
        }

        $options['since'] = 'Since a fixed date…';

        $period = (string) select(
            label: "How much of {$table->name}?",
            options: $options,
            default: $current?->since !== null ? 'since' : (string) ($current->months ?? 3),
        );

        if ($period !== 'since') {
            return new TableRule(TableMode::Recent, $column, months: (int) $period);
        }

        return new TableRule(TableMode::Recent, $column, since: text(
            label: "{$column} from (Y-m-d)",
            default: (string) $current?->since,
            required: true,
            validate: fn (string $value): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? null : 'Use Y-m-d, e.g. 2025-01-01',
        ));
    }

    /**
     * Estimated bytes of $table in a snapshot, or null when unknown.
     */
    protected function estimate(TableInfo $table, TableRule $rule): ?float
    {
        return match ($rule->mode) {
            TableMode::Full => $table->bytes(),
            TableMode::Schema, TableMode::Skip => 0,
            TableMode::Recent => ($share = $table->shareSince((string) $rule->column, $rule->sinceDate())) !== null ? $table->bytes() * $share : null,
            TableMode::Where, TableMode::Follow => null,
        };
    }

    protected function tableLabel(TableInfo $table, ?TableRule $rule): string
    {
        $columns = array_map(fn (DateColumn $column): string => $column->name.'✓', $table->indexedDateColumns());

        return implode(' · ', array_filter([
            $table->name,
            $this->formatBytes($table->bytes()),
            '~'.$this->formatRows($table->rows).' rows',
            implode(' ', $columns),
            $rule !== null ? 'now: '.$rule->describe() : null,
        ]));
    }
}
