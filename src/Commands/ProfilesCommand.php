<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Console\Command;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Snapshot\SnapshotRepository;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;

class ProfilesCommand extends Command
{
    use ChoosesProfile;

    protected $signature = 'snapshot:profiles';

    protected $description = 'List the saved profiles with their rules and last pull';

    public function handle(SnapshotRepository $snapshots): int
    {
        $directory = config('db-snapshot.profile_path');
        $names = Profile::names($directory);

        if ($names === []) {
            info("No profiles in {$directory} yet. Create one with: php artisan snapshot:configure");

            return self::SUCCESS;
        }

        $lastPulled = [];

        foreach ($snapshots->all() as $snapshot) {
            $lastPulled[$snapshot->profile()] ??= $snapshot;
        }

        table(['Profile', 'Details'], array_map(
            fn (string $name): array => [$name, implode(' · ', $this->profileDetails($name, $lastPulled[$name] ?? null))],
            $names,
        ));

        info("Profiles live in {$directory}. Edit with php artisan snapshot:configure <name>; remove or rename them like any file.");

        return self::SUCCESS;
    }
}
