<?php

namespace Overthink\DbSnapshot\Snapshot;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Overthink\DbSnapshot\Contracts\Driver;
use Overthink\DbSnapshot\DriverManager;
use RuntimeException;

/**
 * Recreates a local database from a snapshot, importing tables in parallel,
 * with the driver the snapshot was pulled with.
 */
final class Restorer
{
    public function __construct(
        private readonly ParallelRunner $runner,
        private readonly DriverManager $drivers,
    ) {}

    /**
     * @param  array<string, mixed>  $connection  a Laravel database connection config
     * @param  (Closure(string, ProcessResult, float): void)|null  $onFinished
     * @return list<string> warnings about views or routines that could not be created
     */
    public function restore(Snapshot $snapshot, array $connection, int $parallel, ?Closure $onFinished = null): array
    {
        $driver = $this->driverFor($snapshot, $connection);

        $this->ensureSucceeded('recreate database', $this->process($driver, $connection)->run($driver->recreateDatabaseCommand($connection)));

        $this->importTables($snapshot, $connection, $parallel, $onFinished);

        $warnings = [];

        // Views and routines can reference other databases or users that only
        // exist on the server, so create what we can and report the rest.
        foreach (['routines' => $snapshot->routinesFile(), 'views' => $snapshot->viewsFile()] as $kind => $file) {
            if ($file === null) {
                continue;
            }

            $result = $this->process($driver, $connection)->run($driver->importCommand($connection, $file, continueOnError: true));

            if ($result->failed()) {
                $errors = preg_grep('/ERROR/', preg_split('/\R/', $result->errorOutput()) ?: []) ?: [trim($result->errorOutput())];
                $warnings[] = "Some {$kind} could not be created:\n  ".implode("\n  ", $errors);
            }
        }

        return $warnings;
    }

    /**
     * Replace only the snapshot's tables in an existing database; every
     * other table is left as it is.
     *
     * @param  array<string, mixed>  $connection
     * @param  (Closure(string, ProcessResult, float): void)|null  $onFinished
     */
    public function importTables(Snapshot $snapshot, array $connection, int $parallel, ?Closure $onFinished = null): void
    {
        $driver = $this->driverFor($snapshot, $connection);
        $jobs = [];

        foreach ($snapshot->tableFiles() as $table => $file) {
            $jobs[$table] = ['command' => $driver->importCommand($connection, $file), 'process' => $this->process($driver, $connection)];
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
     * @param  array<string, mixed>  $connection
     */
    private function driverFor(Snapshot $snapshot, array $connection): Driver
    {
        $driver = $this->drivers->driver($snapshot->driver());
        $connectionDriver = $connection['driver'] ?? null;

        if ($connectionDriver !== null && ! in_array($connectionDriver, $driver->localConnectionDrivers(), true)) {
            throw new InvalidArgumentException(sprintf(
                'Snapshot %s was pulled with the %s driver and cannot be restored into a %s connection.',
                $snapshot->name(),
                $driver->name(),
                $connectionDriver,
            ));
        }

        return $driver;
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function process(Driver $driver, array $connection): PendingProcess
    {
        return Process::forever()->env($driver->localEnvironment($connection));
    }

    private function ensureSucceeded(string $step, ProcessResult $result): void
    {
        if ($result->failed()) {
            throw new RuntimeException("{$step} failed: ".trim($result->errorOutput()));
        }
    }
}
