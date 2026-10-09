<?php

namespace Overthink\DbSnapshot\Snapshot;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Overthink\DbSnapshot\Contracts\Driver;
use Overthink\DbSnapshot\Contracts\RetriesConflictingImports;
use Overthink\DbSnapshot\DriverManager;
use RuntimeException;

/**
 * Recreates a local database from a snapshot, importing tables in parallel,
 * with the driver the snapshot was pulled with.
 */
final class Restorer
{
    private const CONFLICT_RETRIES = 3;

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

        // Routines (and, for some drivers, extensions) come first because
        // tables can use them; views come last because they read tables.
        $warnings = $this->importLeniently($driver, $connection, 'routines', $snapshot->routinesFile());

        $warnings = [...$warnings, ...$this->importTables($snapshot, $connection, $parallel, $onFinished)];

        return [
            ...$warnings,
            ...$this->importLeniently($driver, $connection, 'views', $snapshot->viewsFile()),
        ];
    }

    /**
     * Replace only the snapshot's tables in an existing database; every
     * other table is left as it is.
     *
     * @param  array<string, mixed>  $connection
     * @param  (Closure(string, ProcessResult, float): void)|null  $onFinished
     * @return list<string> warnings about indexes or constraints that could not be created
     */
    public function importTables(Snapshot $snapshot, array $connection, int $parallel, ?Closure $onFinished = null): array
    {
        $driver = $this->driverFor($snapshot, $connection);
        $jobs = [];

        foreach ($snapshot->tableFiles() as $table => $file) {
            $jobs[$table] = ['command' => $driver->importCommand($connection, $file), 'process' => $this->process($driver, $connection)];
        }

        $isConflict = fn (ProcessResult $result): bool => $result->failed()
            && $driver instanceof RetriesConflictingImports
            && $driver->isLockConflict($result->errorOutput());

        $results = $this->runner->run($jobs, $parallel, $this->labelled($onFinished, $isConflict, null));

        // Imports that only collided with each other are retried one at a time.
        for ($attempt = 1; $attempt <= self::CONFLICT_RETRIES; $attempt++) {
            $conflicts = array_filter($results, $isConflict);

            if ($conflicts === []) {
                break;
            }

            $results = [...$results, ...$this->runner->run(array_intersect_key($jobs, $conflicts), 1, $this->labelled($onFinished, $isConflict, $attempt))];
        }

        $failed = array_filter($results, fn (ProcessResult $result): bool => $result->failed());

        if ($failed !== []) {
            throw new RuntimeException("Restore failed for:\n".implode("\n", array_map(
                fn (string $table, ProcessResult $result): string => "  {$table}: ".trim($result->errorOutput()),
                array_keys($failed),
                $failed,
            )));
        }

        // Indexes, constraints and triggers of drivers that keep them apart.
        return $this->importLeniently($driver, $connection, 'indexes or constraints', $snapshot->postDataFile());
    }

    /**
     * Tell the reporter which attempts collided and which are retries.
     *
     * @param  (Closure(string, ProcessResult, float): void)|null  $onFinished
     * @param  Closure(ProcessResult): bool  $isConflict
     * @return (Closure(string, ProcessResult, float): void)|null
     */
    private function labelled(?Closure $onFinished, Closure $isConflict, ?int $attempt): ?Closure
    {
        if ($onFinished === null) {
            return null;
        }

        return function (string $table, ProcessResult $result, float $seconds) use ($onFinished, $isConflict, $attempt): void {
            $notes = array_filter([
                $attempt !== null ? "retry {$attempt}" : null,
                $isConflict($result) && $attempt !== self::CONFLICT_RETRIES ? 'lock conflict, retrying' : null,
            ]);

            $onFinished($notes === [] ? $table : $table.' ('.implode(', ', $notes).')', $result, $seconds);
        };
    }

    /**
     * Views, routines and constraints can reference databases, users or tables
     * that only exist on the server, so create what we can and report the rest.
     *
     * @param  array<string, mixed>  $connection
     * @return list<string>
     */
    private function importLeniently(Driver $driver, array $connection, string $kind, ?string $file): array
    {
        if ($file === null) {
            return [];
        }

        $result = $this->process($driver, $connection)->run($driver->importCommand($connection, $file, continueOnError: true));

        // Some clients (psql without ON_ERROR_STOP) exit 0 after failed statements.
        if ($result->successful() && ! str_contains($result->errorOutput(), 'ERROR')) {
            return [];
        }

        $errors = preg_grep('/ERROR/', preg_split('/\R/', $result->errorOutput()) ?: []) ?: [trim($result->errorOutput())];

        return ["Some {$kind} could not be created:\n  ".implode("\n  ", $errors)];
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
