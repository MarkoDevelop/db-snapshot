<?php

namespace Overthink\DbSnapshot\Snapshot;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

/**
 * Recreates a local MySQL database from a snapshot, importing tables in parallel.
 */
final class Restorer
{
    /**
     * Dumps from a GTID-enabled server start with SET @@GLOBAL.GTID_PURGED.
     * Only the first per-table import could apply it, so it's dropped. The
     * statement can span several lines and ends with a semicolon.
     */
    private const STRIP_GTID_PURGED = "awk '/^SET @@GLOBAL\\.GTID_PURGED/ { skip = 1 } !skip { print } skip && /;[[:space:]]*$/ { skip = 0 }'";

    public function __construct(private readonly ParallelRunner $runner) {}

    /**
     * @param  array{host?: string, port?: int|string, username?: string, password?: ?string, database: string, charset?: string, collation?: string}  $connection
     * @param  (Closure(string, ProcessResult, float): void)|null  $onFinished
     * @return list<string> warnings about views or routines that could not be created
     */
    public function restore(Snapshot $snapshot, array $connection, int $parallel, ?Closure $onFinished = null): array
    {
        $database = $connection['database'];

        if (! preg_match('/^[A-Za-z0-9_$-]+$/', $database)) {
            throw new InvalidArgumentException("Invalid database name [{$database}].");
        }

        $charset = $connection['charset'] ?? 'utf8mb4';
        $collation = $connection['collation'] ?? 'utf8mb4_unicode_ci';

        $this->ensureSucceeded('recreate database', $this->process($connection)->run($this->mysql($connection, [
            '-e', "DROP DATABASE IF EXISTS `{$database}`; CREATE DATABASE `{$database}` CHARACTER SET {$charset} COLLATE {$collation}",
        ])));

        $this->importTables($snapshot, $connection, $parallel, $onFinished);

        $warnings = [];

        // Views and routines can reference other databases or users that only
        // exist on the server, so create what we can and report the rest.
        foreach (['routines' => $snapshot->routinesFile(), 'views' => $snapshot->viewsFile()] as $kind => $file) {
            if ($file === null) {
                continue;
            }

            $result = $this->process($connection)->run($this->import($connection, $file, force: true));

            if ($result->failed()) {
                $errors = preg_grep('/^ERROR /', preg_split('/\R/', $result->errorOutput()) ?: []) ?: [trim($result->errorOutput())];
                $warnings[] = "Some {$kind} could not be created:\n  ".implode("\n  ", $errors);
            }
        }

        return $warnings;
    }

    /**
     * Replace only the snapshot's tables in an existing database; every
     * other table is left as it is.
     *
     * @param  array{host?: string, port?: int|string, username?: string, password?: ?string, database: string}  $connection
     * @param  (Closure(string, ProcessResult, float): void)|null  $onFinished
     */
    public function importTables(Snapshot $snapshot, array $connection, int $parallel, ?Closure $onFinished = null): void
    {
        if (! preg_match('/^[A-Za-z0-9_$-]+$/', $connection['database'])) {
            throw new InvalidArgumentException("Invalid database name [{$connection['database']}].");
        }

        $jobs = [];

        foreach ($snapshot->tableFiles() as $table => $file) {
            $jobs[$table] = ['command' => $this->import($connection, $file), 'process' => $this->process($connection)];
        }

        $results = $this->runner->run($jobs, $parallel, $onFinished);

        $failed = array_filter($results, fn (ProcessResult $result): bool => $result->failed());

        if ($failed !== []) {
            throw new RuntimeException("Restore failed for:\n".implode("\n", array_map(
                fn (string $table, ProcessResult $result): string => "  {$table}: ".trim($result->errorOutput()),
                array_keys($failed),
                $failed,
            )));
        }
    }

    /**
     * @param  array{host?: string, port?: int|string, username?: string, password?: ?string, database: string}  $connection
     * @return list<string>
     */
    private function import(array $connection, string $file, bool $force = false): array
    {
        $pipeline = 'gzip -dc '.escapeshellarg($file).' | '.self::STRIP_GTID_PURGED.' | '.implode(' ', array_map(escapeshellarg(...), $this->mysql($connection, [
            '--init-command=SET SESSION sql_log_bin = 0, foreign_key_checks = 0, unique_checks = 0',
            ...($force ? ['--force'] : []),
            $connection['database'],
        ])));

        return ['bash', '-o', 'pipefail', '-c', $pipeline];
    }

    /**
     * @param  array{host?: string, port?: int|string, username?: string}  $connection
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function mysql(array $connection, array $arguments): array
    {
        return [
            'mysql',
            '--host='.($connection['host'] ?? '127.0.0.1'),
            '--port='.($connection['port'] ?? 3306),
            '--user='.($connection['username'] ?? 'root'),
            ...$arguments,
        ];
    }

    /**
     * @param  array{password?: ?string}  $connection
     */
    private function process(array $connection): PendingProcess
    {
        return Process::forever()->env(['MYSQL_PWD' => (string) ($connection['password'] ?? '')]);
    }

    private function ensureSucceeded(string $step, ProcessResult $result): void
    {
        if ($result->failed()) {
            throw new RuntimeException("{$step} failed: ".trim($result->errorOutput()));
        }
    }
}
