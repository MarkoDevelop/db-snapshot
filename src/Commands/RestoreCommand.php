<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Overthink\DbSnapshot\Snapshot\Restorer;
use Overthink\DbSnapshot\Snapshot\Snapshot;
use Overthink\DbSnapshot\Snapshot\SnapshotRepository;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

class RestoreCommand extends Command
{
    use Formats;

    protected $signature = 'snapshot:restore
        {snapshot? : Snapshot directory name, or "latest" (asked when omitted; the newest is preselected)}
        {--profile= : Only snapshots of this profile}
        {--database= : Restore into this database instead of the connection\'s own}
        {--parallel= : Parallel database clients (default: config db-snapshot.parallel)}
        {--force : Do not ask before dropping the database}';

    protected $description = 'Drop the local database and recreate it from a snapshot';

    public function handle(SnapshotRepository $snapshots, Restorer $restorer): int
    {
        if ($this->laravel->isProduction()) {
            error('snapshot:restore never runs in production.');

            return self::FAILURE;
        }

        $connectionName = config('db-snapshot.connection') ?? config('database.default');
        $connection = config("database.connections.{$connectionName}");
        $connection['database'] = $this->option('database') ?: $connection['database'];
        $parallel = (int) ($this->option('parallel') ?: config('db-snapshot.parallel'));

        try {
            $snapshot = $this->chooseSnapshot($snapshots, $this->option('profile') ?: null);
        } catch (Throwable $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

        note(sprintf(
            'Snapshot %s (profile %s, %s, pulled %s)',
            $snapshot->name(),
            $snapshot->profile(),
            $this->formatBytes($snapshot->bytes()),
            $snapshot->createdAt()->diffForHumans(),
        ));

        $target = "{$connection['database']} on {$connection['host']}:{$connection['port']}";

        if (! $this->option('force') && ! confirm("Drop and recreate {$target}?", default: false)) {
            return self::FAILURE;
        }

        $startedAt = microtime(true);

        try {
            $warnings = $restorer->restore($snapshot, $connection, $parallel, function (string $table, ProcessResult $result, float $seconds): void {
                $this->line(sprintf('  %s %-40s %6.1fs', $result->successful() ? '<info>✓</info>' : '<error>✗</error>', $table, $seconds));
            });
        } catch (Throwable $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($warnings as $message) {
            warning($message);
        }

        info(sprintf('Restored %s into %s in %ds.', $snapshot->name(), $target, (int) (microtime(true) - $startedAt)));

        return self::SUCCESS;
    }

    /**
     * The named snapshot, or a choice among all (newest first, preselected)
     * when none is named and the command is interactive.
     */
    private function chooseSnapshot(SnapshotRepository $snapshots, ?string $profile): Snapshot
    {
        $name = $this->argument('snapshot');

        if ($name !== null || ! $this->input->isInteractive()) {
            return $snapshots->find($name ?? 'latest', $profile);
        }

        $available = array_values(array_filter(
            $snapshots->all(),
            fn (Snapshot $snapshot): bool => $profile === null || $snapshot->profile() === $profile,
        ));

        if (count($available) <= 1) {
            return $snapshots->find('latest', $profile);
        }

        $options = [];

        foreach ($available as $snapshot) {
            $options[$snapshot->name()] = sprintf(
                '%s · %s · %s · %s',
                $snapshot->createdAt()->diffForHumans(),
                $snapshot->profile(),
                $this->formatBytes($snapshot->bytes()),
                $snapshot->name(),
            );
        }

        return $snapshots->find((string) select(
            label: 'Which snapshot?',
            options: $options,
            default: $available[0]->name(),
            scroll: 10,
        ));
    }
}
