<?php

namespace Overthink\DbSnapshot\Snapshot;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Overthink\DbSnapshot\Analysis\Analyzer;
use Overthink\DbSnapshot\Analysis\ColumnInfo;
use Overthink\DbSnapshot\Analysis\TableInfo;
use Overthink\DbSnapshot\Contracts\AnonymizesColumns;
use Overthink\DbSnapshot\Contracts\Driver;
use Overthink\DbSnapshot\Profile\AnonymizeStrategy;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Profile\TableRule;
use RuntimeException;

/**
 * Streams per-table gzipped dumps from the server into a new snapshot directory.
 */
final class Puller
{
    public function __construct(
        private readonly Driver $driver,
        private readonly Analyzer $analyzer,
        private readonly ParallelRunner $runner,
        private readonly string $basePath,
        private readonly ?string $anonymizeSalt = null,
        private readonly bool $anonymize = true,
    ) {}

    /**
     * @param  (Closure(string, ProcessResult, float): void)|null  $onFinished
     * @param  (Closure(list<string>, string): void)|null  $onTick  receives running keys and the snapshot directory
     * @param  bool  $partial  only the profile's tables, without views and routines (see snapshot:refresh-table)
     */
    public function pull(Profile $profile, int $parallel, ?Closure $onFinished = null, ?Closure $onTick = null, bool $partial = false): Snapshot
    {
        $now = CarbonImmutable::now();
        $tables = $this->analyzer->tables();
        $name = $now->format('Y-m-d_His').'_'.$profile->name;
        $directory = rtrim($this->basePath, '/').'/'.$name.'.partial';

        File::ensureDirectoryExists($directory.'/'.Snapshot::TABLES_DIRECTORY);
        $this->keepOutOfGit();

        $jobs = [];
        $manifestTables = [];
        $views = [];
        $anonymizer = $this->anonymizer($profile, $tables);

        foreach ($tables as $table) {
            $rule = $profile->ruleFor($table->name);

            if ($rule->mode === TableMode::Skip) {
                continue;
            }

            if ($table->isView) {
                $views[] = $table->name;

                continue;
            }

            $where = match ($rule->mode) {
                TableMode::Recent => $this->driver->recentCondition((string) $rule->column, $rule->sinceDate($now)),
                TableMode::Where => $rule->where,
                default => null,
            };

            $file = $directory.'/'.Snapshot::TABLES_DIRECTORY."/{$table->name}.sql.gz";
            $jobs[$table->name] = $this->job($rule->anonymize !== [] && $anonymizer !== null
                ? $anonymizer($table->name, $rule, $where, $file)
                : $this->driver->dumpTableCommand($table->name, $rule->mode === TableMode::Schema, $where, $file));
            $manifestTables[$table->name] = ['mode' => $rule->mode->value, 'where' => $where, 'file' => $file, 'anonymized' => $anonymizer !== null ? array_keys($rule->anonymize) : []];
        }

        $dataTables = array_keys($manifestTables);
        $extras = [
            '_post-data' => $dataTables !== [] ? $this->driver->dumpPostDataCommand($dataTables, $directory.'/'.Snapshot::POST_DATA_FILE) : null,
        ];

        if (! $partial) {
            $extras['_views'] = $views !== [] ? $this->driver->dumpViewsCommand($views, $directory.'/'.Snapshot::VIEWS_FILE) : null;
            $extras['_routines'] = $this->driver->dumpRoutinesCommand($directory.'/'.Snapshot::ROUTINES_FILE);
        }

        foreach (array_filter($extras) as $key => $command) {
            $jobs[$key] = $this->job($command);
        }

        $results = $this->runner->run(
            $jobs,
            $parallel,
            $onFinished,
            $onTick !== null ? fn (array $running) => $onTick($running, $directory) : null,
        );

        $failed = array_filter($results, fn (ProcessResult $result): bool => $result->failed());

        if ($failed !== []) {
            File::deleteDirectory($directory);

            throw new RuntimeException("Pull failed, nothing was kept:\n".implode("\n", array_map(
                fn (string $key, ProcessResult $result): string => "  {$key}: ".trim($result->errorOutput() ?: 'exit code '.$result->exitCode()),
                array_keys($failed),
                $failed,
            )));
        }

        $manifest = [
            'profile' => $profile->name,
            'driver' => $this->driver->name(),
            'database' => $this->driver->database(),
            'created_at' => $now->toIso8601String(),
            'partial' => $partial,
            'tables' => array_map(fn (array $table): array => array_filter([
                'mode' => $table['mode'],
                'where' => $table['where'],
                'anonymized' => $table['anonymized'] === [] ? false : $table['anonymized'],
                'bytes' => File::exists($table['file']) ? File::size($table['file']) : 0,
            ], fn (mixed $value): bool => $value !== false), $manifestTables),
        ];

        File::put($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $finalDirectory = substr($directory, 0, -strlen('.partial'));
        File::moveDirectory($directory, $finalDirectory);

        return Snapshot::load($finalDirectory);
    }

    /**
     * Snapshots hold production data, so the directory never ends up in a
     * commit, even when it lives inside the project (the default).
     */
    private function keepOutOfGit(): void
    {
        $gitignore = rtrim($this->basePath, '/').'/.gitignore';

        if (! File::exists($gitignore)) {
            File::put($gitignore, "*\n!.gitignore\n");
        }
    }

    /**
     * A function building anonymized dump commands, or null when the profile
     * anonymizes nothing. Every rule is checked against the real columns
     * first, so a typo fails the pull before anything is dumped.
     *
     * @param  array<string, TableInfo>  $tables
     * @return (Closure(string, TableRule, ?string, string): string)|null
     */
    private function anonymizer(Profile $profile, array $tables): ?Closure
    {
        if (! $this->anonymize) {
            return null;
        }

        $anonymizing = array_filter(
            array_map(fn (TableInfo $table): TableRule => $profile->ruleFor($table->name), $tables),
            fn (TableRule $rule): bool => $rule->anonymize !== [] && ! in_array($rule->mode, [TableMode::Schema, TableMode::Skip], true),
        );

        if ($anonymizing === []) {
            return null;
        }

        if (! $this->driver instanceof AnonymizesColumns) {
            throw new RuntimeException("The {$this->driver->name()} driver can't anonymize columns, but profile [{$profile->name}] asks for it.");
        }

        $driver = $this->driver;
        $columns = $driver->columns();
        $salt = $this->anonymizeSalt ?: bin2hex(random_bytes(16));
        $errors = [];

        foreach ($anonymizing as $table => $rule) {
            $tableColumns = $columns[$table] ?? [];
            $primaryKeys = array_values(array_filter($tableColumns, fn (ColumnInfo $column): bool => $column->primaryKey));

            foreach ($rule->anonymize as $name => $columnRule) {
                $column = current(array_filter($tableColumns, fn (ColumnInfo $column): bool => $column->name === $name)) ?: null;

                $errors[] = match (true) {
                    $column === null => "{$table}.{$name} does not exist",
                    $columnRule->strategy->needsTextColumn() && ! $column->isText() => "{$table}.{$name} is {$column->type}; \"{$columnRule->strategy->value}\" needs a text column (use \"null\" or \"fixed\")",
                    $columnRule->strategy === AnonymizeStrategy::Null && ! $column->nullable => "{$table}.{$name} is NOT NULL; \"null\" would fail (use \"empty\" or \"fixed\")",
                    $columnRule->usesId() && count($primaryKeys) !== 1 => "{$table}.{$name} uses {id}, but {$table} has no single-column primary key",
                    default => null,
                };
            }
        }

        $errors = array_filter($errors);

        if ($errors !== []) {
            throw new RuntimeException("Profile [{$profile->name}] can't anonymize:\n  ".implode("\n  ", $errors));
        }

        return function (string $table, TableRule $rule, ?string $where, string $file) use ($driver, $columns, $salt): string {
            $primaryKeys = array_values(array_filter($columns[$table], fn (ColumnInfo $column): bool => $column->primaryKey));
            $primaryKey = count($primaryKeys) === 1 ? $primaryKeys[0]->name : null;
            $expressions = [];

            foreach ($columns[$table] as $column) {
                if (isset($rule->anonymize[$column->name])) {
                    $expressions[$column->name] = $driver->anonymizedExpression($column, $rule->anonymize[$column->name], $salt, $primaryKey);
                }
            }

            return $driver->dumpAnonymizedTableCommand($table, $where, $file, $columns[$table], $expressions);
        };
    }

    /**
     * @return array{command: string, process: PendingProcess}
     */
    private function job(string $command): array
    {
        return [
            'command' => $command,
            'process' => Process::input($this->driver->remoteInput())->forever(),
        ];
    }
}
