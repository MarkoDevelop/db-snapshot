<?php

namespace Overthink\DbSnapshot\Commands;

use Carbon\CarbonImmutable;
use Overthink\DbSnapshot\Analysis\DateColumn;
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

    protected function askRule(TableInfo $table, ?TableRule $current): TableRule
    {
        $dateColumns = $table->dateColumns;
        usort($dateColumns, fn (DateColumn $a, DateColumn $b): int => $b->indexed <=> $a->indexed);

        $modes = $dateColumns === [] ? array_filter(TableMode::cases(), fn (TableMode $mode): bool => $mode !== TableMode::Recent) : TableMode::cases();
        $suggested = $current->mode ?? ($table->indexedDateColumns() !== [] ? TableMode::Recent : TableMode::Schema);

        $mode = TableMode::from(select(
            label: "{$table->name} ({$this->formatBytes($table->bytes())}, ~{$this->formatRows($table->rows)} rows)",
            options: array_combine(
                array_map(fn (TableMode $mode): string => $mode->value, $modes),
                array_map(fn (TableMode $mode): string => $mode->label(), $modes),
            ),
            default: in_array($suggested, $modes, true) ? $suggested->value : TableMode::Full->value,
        ));

        return match ($mode) {
            TableMode::Recent => $this->askRecentRule($table, $dateColumns, $current),
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
            TableMode::Where => null,
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
