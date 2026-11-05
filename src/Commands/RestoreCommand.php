<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Console\Command;
use Overthink\DbSnapshot\Snapshot\Restorer;
use Overthink\DbSnapshot\Snapshot\Snapshot;
use Overthink\DbSnapshot\Snapshot\SnapshotRepository;
use Overthink\DbSnapshot\Support\DatabaseName;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

class RestoreCommand extends Command
{
    use PicksAddons;
    use TargetsLocalDatabase;

    protected $signature = 'snapshot:restore
        {snapshot? : Snapshot directory name, or "latest" (asked when omitted; the newest is preselected)}
        {--profile= : Only snapshots of this profile}
        {--database= : Restore into this database instead of the connection\'s own (placeholders like source, profile and date in braces work, see the README)}
        {--parallel= : How many tables to import at a time (default: SNAPSHOT_PARALLEL)}
        {--addon=* : Apply this saved add-on on top; without a name, pick from a list (asked when add-ons exist, unless --force)}
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
            $addons = $this->chooseAddons($snapshots, $snapshot);
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

        foreach ($addons as $addon) {
            note('Then add-on '.$this->addonLabel($addon));

            if (($gap = $this->addonAgeWarning($addon, $snapshot->createdAt())) !== null) {
                warning($gap);
            }
        }

        $target = "{$connection['database']} on {$connection['host']}:{$connection['port']}";

        if (! $this->confirmsDestructive("drop and recreate {$target}")) {
            return self::FAILURE;
        }

        $startedAt = microtime(true);

        try {
            $warnings = $restorer->restore(
                $snapshot,
                $connection,
                $parallel,
                $this->progressReporter(),
                $addons,
                fn (Snapshot $addon) => $this->line("  Add-on {$addon->profile()}:"),
            );
        } catch (Throwable $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($warnings as $message) {
            warning($message);
        }

        info(sprintf(
            'Restored %s%s into %s in %ds.',
            $snapshot->name(),
            $addons === [] ? '' : ' with '.implode(', ', array_map(fn (Snapshot $addon): string => $addon->profile(), $addons)),
            $target,
            (int) (microtime(true) - $startedAt),
        ));

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
            $options[$snapshot->name()] = $this->snapshotLabel($snapshot);
        }

        return $snapshots->find((string) select(
            label: 'Which snapshot?',
            options: $options,
            default: $available[0]->name(),
            scroll: 10,
        ));
    }

    /**
     * --addon, or, when interactive, a choice among the saved add-ons pulled
     * with the snapshot's driver. A bare --addon always asks, even with --force.
     * Oldest first, so newer tables win.
     *
     * @return list<Snapshot>
     */
    private function chooseAddons(SnapshotRepository $snapshots, Snapshot $snapshot): array
    {
        $named = array_values(array_filter($this->option('addon'), fn (?string $name): bool => filled($name)));
        $addons = array_map(fn (string $name): Snapshot => $snapshots->findAddon($name), $named);

        foreach ($addons as $addon) {
            if ($addon->driver() !== $snapshot->driver()) {
                throw new RuntimeException("Add-on {$addon->profile()} was pulled with the {$addon->driver()} driver and {$snapshot->name()} with {$snapshot->driver()}.");
            }
        }
        $picker = $this->wantsAddonPicker('addon');

        if ($picker && ! $this->input->isInteractive()) {
            throw new RuntimeException('--addon needs a name when not interactive.');
        }

        $available = array_values(array_filter($snapshots->addons(), fn (Snapshot $addon): bool => $addon->driver() === $snapshot->driver()));

        if ($picker && $available === []) {
            throw new RuntimeException("There are no add-ons pulled with the {$snapshot->driver()} driver, which {$snapshot->name()} was pulled with.");
        }

        if ($picker || ($addons === [] && $available !== [] && $this->input->isInteractive() && ! $this->option('force'))) {
            $addons = $this->pickAddons($available, 'Also apply add-ons?', array_map(fn (Snapshot $addon): string => $addon->name(), $addons));
        }

        usort($addons, fn (Snapshot $a, Snapshot $b): int => $a->createdAt() <=> $b->createdAt());

        return $addons;
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
