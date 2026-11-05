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
use Overthink\DbSnapshot\Contracts\FollowsRelations;
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
     * Columns of every source table, read once per pull and only when needed.
     *
     * @var array<string, list<ColumnInfo>>|null
     */
    private ?array $columns = null;

    /**
     * @param  (Closure(string, ProcessResult, float, ?int, ?int): void)|null  $onFinished
     * @param  (Closure(list<string>, string): void)|null  $onTick  receives running keys and the snapshot directory
     * @param  bool  $partial  only the profile's tables, without views and routines (see snapshot:refresh-table)
     * @param  bool  $addon  a partial snapshot kept as an add-on named after the profile
     * @param  ?list<string>  $only  dump only these tables; the profile's other rules still decide what followers copy
     */
    public function pull(Profile $profile, int $parallel, ?Closure $onFinished = null, ?Closure $onTick = null, bool $partial = false, bool $addon = false, ?array $only = null): Snapshot
    {
        $partial = $partial || $addon || $only !== null;
        $this->columns = null;
        $now = CarbonImmutable::now();
        $allTables = $this->analyzer->tables();
        $tables = $only === null ? $allTables : array_intersect_key($allTables, array_flip($only));
        $this->checkFollowRules($profile, $tables, $allTables);
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

            $where = $rule->mode === TableMode::Schema ? null : $this->condition($profile, $table->name, $now);

            $file = $directory.'/'.Snapshot::TABLES_DIRECTORY."/{$table->name}.sql.gz";
            $jobs[$table->name] = $this->job($rule->anonymize !== [] && $anonymizer !== null
                ? $anonymizer($table->name, $rule, $where, $file)
                : $this->driver->dumpTableCommand($table->name, $rule->mode === TableMode::Schema, $where, $file));
            $manifestTables[$table->name] = ['mode' => $rule->mode->value, 'where' => $where, 'file' => $file, 'anonymized' => $anonymizer !== null ? array_keys($rule->anonymize) : []];
        }

        $dataTables = array_keys($manifestTables);
        $extras = [];

        if ($addon) {
            File::ensureDirectoryExists($directory.'/'.Snapshot::POST_DATA_DIRECTORY);

            foreach ($dataTables as $table) {
                $extras["_post-data:{$table}"] = $this->driver->dumpPostDataCommand([$table], $directory.'/'.Snapshot::POST_DATA_DIRECTORY."/{$table}.sql.gz");
            }
        } else {
            $extras['_post-data'] = $dataTables !== [] ? $this->driver->dumpPostDataCommand($dataTables, $directory.'/'.Snapshot::POST_DATA_FILE) : null;
        }

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
            ...($addon ? ['addon' => true] : []),
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
     * SQL condition for the rows $table copies, or null for all of them.
     * A follower takes the rows pointing at what its parent copies, so the
     * conditions nest along the chain (order_serials → order_items → orders).
     */
    private function condition(Profile $profile, string $table, CarbonImmutable $now): ?string
    {
        $rule = $profile->ruleFor($table);

        if ($rule->mode !== TableMode::Follow) {
            return match ($rule->mode) {
                TableMode::Recent => $this->driver->recentCondition((string) $rule->column, $rule->sinceDate($now)),
                TableMode::Where => $rule->where,
                TableMode::Schema, TableMode::Skip => '1 = 0',
                default => null,
            };
        }

        $parentWhere = $this->condition($profile, (string) $rule->parent, $now);

        if ($parentWhere === null || $parentWhere === '1 = 0') {
            return $parentWhere;
        }

        if (! $this->driver instanceof FollowsRelations) {
            throw new RuntimeException("The {$this->driver->name()} driver can't follow relations.");
        }

        return $this->driver->followCondition((string) $rule->column, (string) $rule->parent, $rule->referencedColumn(), $parentWhere);
    }

    /**
     * Why $table.$column can't follow $parent.$references (a missing column,
     * or types that can't be compared without a cast), or null when it can
     * or the driver doesn't list columns.
     */
    private function followColumnsError(string $table, string $column, string $parent, string $references): ?string
    {
        if (! $this->driver instanceof AnonymizesColumns) {
            return null;
        }

        $this->columns ??= $this->driver->columns();
        $find = fn (string $table, string $name): ?ColumnInfo => current(array_filter($this->columns[$table] ?? [], fn (ColumnInfo $info): bool => $info->name === $name)) ?: null;
        [$child, $referenced] = [$find($table, $column), $find($parent, $references)];

        $missing = array_filter([
            ($this->columns[$table] ?? []) !== [] && $child === null ? "{$table}.{$column}" : null,
            ($this->columns[$parent] ?? []) !== [] && $referenced === null ? "{$parent}.{$references}" : null,
        ]);

        return match (true) {
            $missing !== [] => implode(' and ', $missing).(count($missing) > 1 ? ' do' : ' does').' not exist',
            $child !== null && $referenced !== null && ! $child->isComparableWith($referenced) => "{$table}.{$column} ({$child->type}) can't be compared with {$parent}.{$references} ({$referenced->type})",
            default => null,
        };
    }

    /**
     * Fails before anything is dumped when a follower can't be resolved.
     *
     * @param  array<string, TableInfo>  $tables  the tables being dumped
     * @param  array<string, TableInfo>  $allTables
     */
    private function checkFollowRules(Profile $profile, array $tables, array $allTables): void
    {
        $errors = [];

        foreach ($tables as $name => $table) {
            if ($profile->ruleFor($name)->mode !== TableMode::Follow) {
                continue;
            }

            if (! $this->driver instanceof FollowsRelations) {
                throw new RuntimeException("The {$this->driver->name()} driver can't follow relations, but profile [{$profile->name}] has {$name} follow another table.");
            }

            $child = $name;

            foreach ($profile->followChain($name) as $parent) {
                if (! isset($allTables[$parent])) {
                    $errors[] = "{$child} follows {$parent}, which doesn't exist";

                    break;
                }

                $rule = $profile->ruleFor($child);
                $errors[] = $this->followColumnsError($child, (string) $rule->column, $parent, $rule->referencedColumn());
                $child = $parent;
            }
        }

        $errors = array_filter($errors);

        if ($errors !== []) {
            throw new RuntimeException("Profile [{$profile->name}] can't be pulled:\n  ".implode("\n  ", array_unique($errors)));
        }
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
        $columns = $this->columns ??= $driver->columns();
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
