<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Console\Command;
use Overthink\DbSnapshot\Snapshot\Restorer;
use Overthink\DbSnapshot\Snapshot\Snapshot;
use Overthink\DbSnapshot\Snapshot\SnapshotRepository;
use Overthink\DbSnapshot\Support\DatabaseName;
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
    use TargetsLocalDatabase;

    protected $signature = 'snapshot:restore
        {snapshot? : Snapshot directory name, or "latest" (asked when omitted; the newest is preselected)}
        {--profile= : Only snapshots of this profile}
        {--database= : Restore into this database instead of the connection\'s own (placeholders like source, profile and date in braces work, see the README)}
        {--parallel= : How many tables to import at a time (default: SNAPSHOT_PARALLEL)}
        {--switch-app : When restoring into another database, set DB_DATABASE to it}
        {--force : Ask nothing: no confirmation before dropping the database}';

    protected $description = 'Drop the local database and recreate it from a snapshot';

    public function handle(SnapshotRepository $snapshots, Restorer $restorer): int
    {
        if ($this->refusesProduction()) {
            return self::FAILURE;
        }

        $connection = $this->localConnection();
        $appDatabase = (string) $connection['database'];
        $parallel = $this->parallel();

        try {
            $snapshot = $this->chooseSnapshot($snapshots, $this->option('profile') ?: null);
            $connection['database'] = $this->chooseDatabase($snapshot, $appDatabase);
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
            info('Nothing changed.');

            return self::FAILURE;
        }

        $startedAt = microtime(true);

        try {
            $warnings = $restorer->restore($snapshot, $connection, $parallel, $this->progressReporter());
        } catch (Throwable $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($warnings as $message) {
            warning($message);
        }

        info(sprintf('Restored %s into %s in %ds.', $snapshot->name(), $target, (int) (microtime(true) - $startedAt)));

        $this->offerToSwitch($connection['database'], $appDatabase);

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

    /**
     * --database (with placeholders), or, when interactive, the app's own
     * database, a new one named from the template, or another name.
     */
    private function chooseDatabase(Snapshot $snapshot, string $appDatabase): string
    {
        $template = (string) config('db-snapshot.database_name');

        if (filled($this->option('database'))) {
            return DatabaseName::resolve((string) $this->option('database'), $snapshot, $appDatabase);
        }

        if (! $this->input->isInteractive() || $this->option('force')) {
            return $appDatabase;
        }

        return $this->askRestoreDatabase(
            $appDatabase,
            DatabaseName::resolve($template, $snapshot, $appDatabase),
            fn (string $typed): string => DatabaseName::resolve($typed, $snapshot, $appDatabase),
        );
    }

    /**
     * After restoring into another database, offer to point the app at it.
     */
    private function offerToSwitch(string $restored, string $appDatabase): void
    {
        if ($restored === $appDatabase) {
            return;
        }

        if ($this->option('switch-app') || ($this->input->isInteractive() && ! $this->option('force') && $this->askToSwitchApp($restored))) {
            $this->switchApp($restored);
        }
    }
}
