<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Console\Command;
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
    use ChoosesProfile;
    use TargetsLocalDatabase;
    use UsesAnalysis;

    protected $signature = 'snapshot:refresh-table
        {tables?* : Tables to refresh (asked when omitted)}
        {--profile= : Profile whose rules are the defaults (asked when there are several)}
        {--database= : Refresh tables in this database instead of the connection\'s own}
        {--parallel= : How many tables to dump and import at a time (default: SNAPSHOT_PARALLEL)}
        {--keep : Keep the pulled files in the snapshot directory}
        {--force : Do not ask before replacing the tables}
        {--analyze : Re-read the remote database first}';

    protected $description = 'Pull a few tables from the remote database and replace only them locally';

    public function handle(Analyzer $analyzer, Puller $puller, Restorer $restorer): int
    {
        if ($this->refusesProduction()) {
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

        $connection = $this->localConnection();
        $connection['database'] = $this->option('database') ?: $connection['database'];

        if (! $this->option('force') && ! confirm('Replace '.implode(', ', array_keys($rules))." in {$connection['database']}?", default: false)) {
            info('Nothing changed.');

            return self::FAILURE;
        }

        $parallel = $this->parallel();
        $report = $this->progressReporter();

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
        $name = $this->chooseProfile($this->option('profile'));

        if (File::exists(Profile::path(config('db-snapshot.profile_path'), $name))) {
            return Profile::load(config('db-snapshot.profile_path'), $name);
        }

        note("Profile [{$name}] doesn't exist, so tables start from a full copy.");

        return new Profile($name);
    }
}
