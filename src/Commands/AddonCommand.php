<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Overthink\DbSnapshot\Snapshot\Snapshot;
use Overthink\DbSnapshot\Snapshot\SnapshotRepository;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class AddonCommand extends Command
{
    use PicksAddons;
    use TargetsLocalDatabase;

    private const NEW_ADDON = "\0new";

    protected $signature = 'snapshot:addon
        {name? : The add-on to work with (asked when omitted)}
        {tables?* : Pull these tables into it right away}
        {--profile= : Profile whose rules the pulled tables start from}
        {--force : Do not ask before replacing local tables}';

    protected $description = 'Create, grow, load or delete an add-on: a few tables kept apart from the snapshots';

    public function handle(SnapshotRepository $snapshots): int
    {
        if ($this->refusesProduction()) {
            return self::FAILURE;
        }

        $name = $this->argument('name');

        if ($name === null && ! $this->input->isInteractive()) {
            error('Name the add-on when not interactive: snapshot:addon <name> <tables...>.');

            return self::FAILURE;
        }

        if ($name !== null && ! $this->isValidAddonName($name)) {
            error("Invalid add-on name [{$name}]: use letters, digits, - and _.");

            return self::FAILURE;
        }

        $name ??= $this->askAddon($snapshots);
        $existing = current(array_filter($snapshots->addons(), fn (Snapshot $addon): bool => $addon->profile() === $name)) ?: null;

        if ($this->argument('tables') !== []) {
            return $this->pullInto($name);
        }

        if (! $this->input->isInteractive()) {
            error("Name the tables to pull into {$name}, or use snapshot:refresh-table --from={$name} to load it.");

            return self::FAILURE;
        }

        if ($existing === null) {
            return $this->pullInto($name);
        }

        $action = select(
            label: "What should happen with {$name}?",
            options: [
                'pull' => 'Pull tables into it (replaces those, keeps the rest)',
                'load' => 'Load it into the database',
                'delete' => 'Delete it',
            ],
        );

        return match ($action) {
            'pull' => $this->pullInto($name),
            'load' => $this->call('snapshot:refresh-table', ['--from' => $existing->name(), '--force' => (bool) $this->option('force')]),
            default => $this->delete($existing),
        };
    }

    /**
     * An existing add-on, or a name for a new one.
     */
    private function askAddon(SnapshotRepository $snapshots): string
    {
        $options = [];

        foreach ($snapshots->addons() as $addon) {
            if ($addon->driver() === config('db-snapshot.driver')) {
                $options[$addon->profile()] ??= $this->addonLabel($addon);
            }
        }

        if ($options !== []) {
            $choice = (string) select(label: 'Which add-on?', options: [...$options, self::NEW_ADDON => 'New add-on…'], scroll: 10);

            if ($choice !== self::NEW_ADDON) {
                return $choice;
            }
        }

        return text(
            label: 'Name of the new add-on',
            placeholder: 'e.g. orders',
            required: true,
            validate: fn (string $name): ?string => match (true) {
                ! $this->isValidAddonName($name) => 'Use letters, digits, - and _.',
                isset($options[$name]) => "Add-on {$name} exists; pick it from the list.",
                default => null,
            },
        );
    }

    private function pullInto(string $name): int
    {
        return $this->call('snapshot:refresh-table', [
            'tables' => $this->argument('tables'),
            '--save' => $name,
            '--force' => (bool) $this->option('force'),
            ...(filled($this->option('profile')) ? ['--profile' => $this->option('profile')] : []),
        ]);
    }

    private function delete(Snapshot $addon): int
    {
        if (! confirm("Delete add-on {$addon->profile()} ({$this->formatBytes($addon->bytes())})?", default: false)) {
            info('Nothing changed.');

            return self::FAILURE;
        }

        File::deleteDirectory($addon->path);
        info("Deleted add-on {$addon->profile()}.");

        return self::SUCCESS;
    }
}
