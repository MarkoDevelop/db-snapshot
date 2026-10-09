<?php

namespace Overthink\DbSnapshot\Snapshot;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Overthink\DbSnapshot\Analysis\Analyzer;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Remote\RemoteMysql;
use RuntimeException;

/**
 * Streams per-table gzipped dumps from the server into a new snapshot directory.
 */
final class Puller
{
    public function __construct(
        private readonly RemoteMysql $mysql,
        private readonly Analyzer $analyzer,
        private readonly ParallelRunner $runner,
        private readonly string $basePath,
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

        $jobs = [];
        $manifestTables = [];
        $views = [];

        foreach ($tables as $table) {
            $rule = $profile->ruleFor($table->name);

            if ($rule->mode === TableMode::Skip) {
                continue;
            }

            if ($table->isView) {
                $views[] = $table->name;

                continue;
            }

            $where = $rule->whereClause($now);
            $options = match (true) {
                $rule->mode === TableMode::Schema => ['--no-data'],
                $where !== null => ['--where='.$where],
                default => [],
            };

            $file = $directory.'/'.Snapshot::TABLES_DIRECTORY."/{$table->name}.sql.gz";
            $jobs[$table->name] = $this->job([$table->name], $options, $file);
            $manifestTables[$table->name] = ['mode' => $rule->mode->value, 'where' => $where, 'file' => $file];
        }

        if ($views !== [] && ! $partial) {
            $jobs['_views'] = $this->job($views, ['--no-data', '--skip-triggers'], $directory.'/'.Snapshot::VIEWS_FILE);
        }

        if (! $partial) {
            $jobs['_routines'] = $this->job(
                [],
                ['--routines', '--no-create-info', '--no-data', '--no-create-db', '--skip-triggers'],
                $directory.'/'.Snapshot::ROUTINES_FILE,
            );
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
            'database' => $this->mysql->database,
            'created_at' => $now->toIso8601String(),
            'partial' => $partial,
            'tables' => array_map(fn (array $table): array => [
                'mode' => $table['mode'],
                'where' => $table['where'],
                'bytes' => File::exists($table['file']) ? File::size($table['file']) : 0,
            ], $manifestTables),
        ];

        File::put($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $finalDirectory = substr($directory, 0, -strlen('.partial'));
        File::moveDirectory($directory, $finalDirectory);

        return Snapshot::load($finalDirectory);
    }

    /**
     * @param  list<string>  $tables
     * @param  list<string>  $options
     * @return array{command: string, process: PendingProcess}
     */
    private function job(array $tables, array $options, string $file): array
    {
        return [
            'command' => $this->mysql->dumpCommand($tables, $options, $file),
            'process' => Process::input($this->mysql->stdin())->forever(),
        ];
    }
}
