<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Overthink\DbSnapshot\Analysis\Analyzer;
use Overthink\DbSnapshot\Analysis\TableInfo;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Profile\TableRule;
use Overthink\DbSnapshot\Snapshot\Puller;
use Overthink\DbSnapshot\Snapshot\Restorer;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multisearch;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

class RefreshTableCommand extends Command
{
    use AsksTableRules;
    use UsesAnalysis;

    protected $signature = 'snapshot:refresh-table
        {tables?* : Tables to refresh (asked when omitted)}
        {--profile=default : Profile whose rules are the defaults}
        {--database= : Refresh tables in this database instead of the connection\'s own}
        {--parallel= : Parallel sessions (default: config db-snapshot.parallel)}
        {--keep : Keep the pulled files in the snapshot directory}
        {--force : Do not ask before replacing the tables}
        {--analyze : Re-read the remote database first}';

    protected $description = 'Pull a few tables from the remote database and replace only them locally';

    public function handle(Analyzer $analyzer, Puller $puller, Restorer $restorer): int
    {
        if ($this->laravel->isProduction()) {
            error('snapshot:refresh-table never runs in production.');

            return self::FAILURE;
        }

        try {
            $analysis = $this->analysis($analyzer, required: true);
            $profile = $this->profile();
        } catch (Throwable $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

        $tables = array_filter($analysis->tables, fn (TableInfo $table): bool => ! $table->isView);
        $names = $this->argument('tables') ?: multisearch(
            label: 'Tables to refresh (type to search)',
            options: fn (string $search): array => array_map(
                fn (TableInfo $table): string => $this->tableLabel($table, $profile->tables[$table->name] ?? null),
                array_filter($tables, fn (TableInfo $table): bool => $search === '' || str_contains($table->name, $search)),
            ),
            scroll: 15,
            required: true,
        );

        $unknown = array_diff($names, array_keys($tables));

        if ($unknown !== []) {
            error('Unknown tables: '.implode(', ', $unknown));

            return self::FAILURE;
        }

        $rules = [];

        foreach ($names as $name) {
            $rule = $profile->ruleFor($name);

            if ($rule->mode === TableMode::Skip) {
                $rule = new TableRule(TableMode::Full);
            }

            $rules[$name] = $this->input->isInteractive() && $this->argument('tables') === []
                ? $this->askRule($tables[$name], $rule)
                : $rule;

            note("{$name}: {$rules[$name]->describe()}");
        }

        $rules = array_filter($rules, fn (TableRule $rule): bool => $rule->mode !== TableMode::Skip);

        if ($rules === []) {
            info('Nothing to refresh.');

            return self::SUCCESS;
        }

        $connectionName = config('db-snapshot.connection') ?? config('database.default');
        $connection = config("database.connections.{$connectionName}");
        $connection['database'] = $this->option('database') ?: $connection['database'];

        if (! $this->option('force') && ! confirm('Replace '.implode(', ', array_keys($rules))." in {$connection['database']}?", default: false)) {
            return self::FAILURE;
        }

        $parallel = (int) ($this->option('parallel') ?: config('db-snapshot.parallel'));
        $report = function (string $key, ProcessResult $result, float $seconds): void {
            $this->line(sprintf('  %s %-40s %6.1fs', $result->successful() ? '<info>✓</info>' : '<error>✗</error>', $key, $seconds));
        };

        $snapshot = null;
        $warnings = [];

        try {
            $snapshot = $puller->pull(new Profile('refresh', TableMode::Skip, $rules), $parallel, $report, partial: true);
            $warnings = $restorer->importTables($snapshot, $connection, $parallel, $report);
        } catch (Throwable $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($snapshot !== null && ! $this->option('keep')) {
                File::deleteDirectory($snapshot->path);
            }
        }

        foreach ($warnings as $message) {
            warning($message);
        }

        info('Refreshed '.implode(', ', array_keys($rules))." in {$connection['database']}.");

        return self::SUCCESS;
    }

    private function profile(): Profile
    {
        $name = (string) $this->option('profile');

        return File::exists(Profile::path(config('db-snapshot.profile_path'), $name))
            ? Profile::load(config('db-snapshot.profile_path'), $name)
            : new Profile($name);
    }
}
