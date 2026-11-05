<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Overthink\DbSnapshot\Snapshot\Snapshot;
use Overthink\DbSnapshot\Snapshot\SnapshotRepository;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;

class ListCommand extends Command
{
    use Formats;

    private const DONE = "\0done";

    protected $signature = 'snapshot:list';

    protected $description = 'List pulled snapshots, and restore or delete one';

    public function handle(SnapshotRepository $snapshots): int
    {
        if ($this->listed($snapshots) === []) {
            info("No snapshots in {$snapshots->path} yet.");

            return self::SUCCESS;
        }

        if (! $this->input->isInteractive()) {
            $this->printTable($snapshots->all());
            $this->printAddons($snapshots->addons());

            return self::SUCCESS;
        }

        while (($all = $this->listed($snapshots)) !== []) {
            $name = $this->pickSnapshot($all);

            if ($name === self::DONE) {
                return self::SUCCESS;
            }

            $snapshot = current(array_filter($all, fn (Snapshot $snapshot): bool => $snapshot->name() === $name));

            if ($snapshot->isAddon()) {
                $action = select(
                    label: "What should happen with add-on {$snapshot->profile()}?",
                    options: [
                        'load' => 'Load its tables into the database',
                        'delete' => 'Delete it',
                        'back' => 'Back to the list',
                    ],
                );

                if ($action === 'load') {
                    return $this->call('snapshot:refresh-table', ['--from' => $snapshot->name()]);
                }

                if ($action === 'delete') {
                    $this->delete($snapshot);
                }

                continue;
            }

            $action = select(
                label: "What should happen with {$snapshot->name()}?",
                options: [
                    'restore' => 'Restore it',
                    'delete' => 'Delete it',
                    'back' => 'Back to the list',
                ],
            );

            if ($action === 'restore') {
                return $this->call('snapshot:restore', ['snapshot' => $snapshot->name()]);
            }

            if ($action === 'delete') {
                $this->delete($snapshot);
            }
        }

        info("No snapshots left in {$snapshots->path}.");

        return self::SUCCESS;
    }

    /**
     * Snapshots, then add-ons, each newest first.
     *
     * @return list<Snapshot>
     */
    private function listed(SnapshotRepository $snapshots): array
    {
        return [...$snapshots->all(), ...$snapshots->addons()];
    }

    /**
     * A select with a few snapshots, a search with many; newest first.
     *
     * @param  list<Snapshot>  $all
     */
    private function pickSnapshot(array $all): string
    {
        $options = [];

        foreach ($all as $snapshot) {
            $options[$snapshot->name()] = $snapshot->isAddon() ? 'add-on · '.$this->addonLabel($snapshot) : $this->snapshotLabel($snapshot);
        }

        $options[self::DONE] = 'Done';

        if (count($all) < 6) {
            return (string) select(label: 'Which snapshot?', options: $options, default: $all[0]->name(), scroll: 10);
        }

        return (string) search(
            label: 'Which snapshot?',
            options: fn (string $value): array => array_filter(
                $options,
                fn (string $label): bool => $value === '' || str_contains(strtolower($label), strtolower($value)),
            ),
            placeholder: 'Type to filter by profile, add-on, table, date or name',
            scroll: 10,
        );
    }

    private function delete(Snapshot $snapshot): void
    {
        if (! confirm("Delete {$snapshot->name()} ({$this->formatBytes($snapshot->bytes())})?", default: false)) {
            return;
        }

        File::deleteDirectory($snapshot->path);

        info("Deleted {$snapshot->name()}.");
    }

    /**
     * @param  list<Snapshot>  $all
     */
    private function printTable(array $all): void
    {
        if ($all === []) {
            return;
        }

        table(['Snapshot', 'Profile', 'Tables', 'Size', 'Pulled'], array_map(fn (Snapshot $snapshot): array => [
            $snapshot->name(),
            $snapshot->profile(),
            (string) count($snapshot->manifest['tables']),
            $this->formatBytes($snapshot->bytes()),
            $snapshot->createdAt()->diffForHumans(),
        ], $all));
    }

    /**
     * @param  list<Snapshot>  $addons
     */
    private function printAddons(array $addons): void
    {
        if ($addons === []) {
            return;
        }

        table(['Add-on', 'Tables', 'Size', 'Pulled', 'Directory'], array_map(fn (Snapshot $addon): array => [
            $addon->profile(),
            implode(', ', $addon->tables()),
            $this->formatBytes($addon->bytes()),
            $addon->createdAt()->diffForHumans(),
            $addon->name(),
        ], $addons));
    }
}
