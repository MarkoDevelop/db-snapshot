<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Console\Command;
use Overthink\DbSnapshot\Analysis\Analysis;
use Overthink\DbSnapshot\Analysis\Analyzer;
use Overthink\DbSnapshot\Analysis\DateColumn;
use Overthink\DbSnapshot\Analysis\TableInfo;
use Overthink\DbSnapshot\Profile\Profile;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class AnalyzeCommand extends Command
{
    use Formats;
    use UsesAnalysis;

    protected $signature = 'snapshot:analyze {--top=25 : How many of the largest tables to show}';

    protected $description = 'Read table sizes and date columns of the remote database (read-only)';

    public function handle(Analyzer $analyzer): int
    {
        try {
            $analysis = spin(
                fn (): Analysis => $analyzer->analyze((int) config('db-snapshot.large_table_mb')),
                'Reading information_schema over SSH…',
            );
        } catch (Throwable $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

        $path = config('db-snapshot.analysis_path');
        $analysis->save($path);

        $profileDirectory = config('db-snapshot.profile_path');

        foreach (Profile::names($profileDirectory) as $name) {
            $this->warnAboutUndecidedPersonalData($analysis, Profile::load($profileDirectory, $name));
        }

        $this->render($analysis, (int) $this->option('top'));

        info(sprintf(
            '%d tables, %s in total. Saved to %s',
            count($analysis->tables),
            $this->formatBytes(array_sum(array_map(fn (TableInfo $table): int => $table->bytes(), $analysis->tables))),
            $path,
        ));

        return self::SUCCESS;
    }

    private function render(Analysis $analysis, int $top): void
    {
        table(
            ['Table', 'Size', 'Rows', 'Date columns (✓ indexed)', 'Range'],
            array_map(fn (TableInfo $table): array => [
                $table->name.($table->isView ? ' (view)' : ''),
                $this->formatBytes($table->bytes()),
                '~'.$this->formatRows($table->rows),
                implode(', ', array_map(fn (DateColumn $column): string => $column->name.($column->indexed ? '✓' : ''), $table->dateColumns)),
                $this->range($table),
            ], array_slice(array_values($analysis->tables), 0, $top)),
        );
    }

    private function range(TableInfo $table): string
    {
        foreach ($table->indexedDateColumns() as $column) {
            if ($column->min !== null) {
                return substr($column->min, 0, 10).' → '.substr((string) $column->max, 0, 10);
            }
        }

        return '';
    }
}
