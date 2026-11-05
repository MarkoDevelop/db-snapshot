<?php

namespace Overthink\DbSnapshot\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Laravel\Prompts\Prompt;
use Overthink\DbSnapshot\Analysis\Analyzer;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Snapshot\Snapshot;
use Overthink\DbSnapshot\Snapshot\SnapshotRepository;
use Overthink\DbSnapshot\Support\DatabaseName;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\select;

class RefreshCommand extends Command
{
    use ChoosesProfile;
    use PicksAddons;
    use TargetsLocalDatabase;
    use UsesAnalysis;

    protected $signature = 'snapshot:refresh
        {tables?* : Refresh only these tables (skips the question)}
        {--profile= : Profile to use; with no tables given, refreshes the whole database from it}
        {--addon=* : Apply this saved add-on after the restore; without a name, pick from a list (asked when add-ons exist, unless --force)}
        {--analyze : Re-read the remote database first}
        {--force : Ask nothing: restore into the app\'s database without confirming}';

    protected $description = 'Refresh local data from the remote database: a whole profile, or just some tables';

    public function handle(SnapshotRepository $snapshots): int
    {
        $tables = $this->argument('tables');
        $profile = $this->option('profile');

        $what = match (true) {
            $tables !== [] => 'tables',
            filled($profile) => 'profile',
            default => select(
                label: 'What do you want to refresh?',
                options: [
                    'profile' => 'The whole database, from a profile (pull + restore)',
                    'tables' => 'Just some tables (the rest of the database stays as it is)',
                ],
            ),
        };

        if ($what === 'tables') {
            if ($this->input->hasParameterOption('--addon')) {
                error('--addon applies add-ons after a whole restore. To load an add-on\'s tables, use snapshot:refresh-table --from=<add-on>.');

                return self::FAILURE;
            }

            return $this->call('snapshot:refresh-table', array_filter([
                'tables' => $tables,
                '--profile' => $profile,
                '--force' => $this->option('force'),
                '--analyze' => $this->option('analyze'),
            ]));
        }

        $profile = $this->chooseProfile($profile);

        // Every decision first, so the pull and restore then run unattended.
        if (! $this->ensureProfileExists($profile)) {
            return self::FAILURE;
        }

        try {
            $this->analysis($this->laravel->make(Analyzer::class), required: false);
        } catch (Throwable $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

        // Named add-ons are checked before anything is pulled, driver included.
        try {
            $addons = array_map(
                fn (string $name): string => $this->usableAddon($snapshots, $name)->name(),
                array_values(array_filter($this->option('addon'), fn (?string $name): bool => filled($name))),
            );
        } catch (RuntimeException $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->wantsAddonPicker('addon') && ! $this->input->isInteractive()) {
            error('--addon needs a name when not interactive.');

            return self::FAILURE;
        }

        $appDatabase = (string) $this->localConnection()['database'];
        $database = $appDatabase;
        $switchApp = false;

        if ($this->input->isInteractive() && ! $this->option('force')) {
            $database = $this->askRestoreDatabase(
                $appDatabase,
                $this->plannedDatabaseName((string) config('db-snapshot.database_name'), $profile, $appDatabase),
                fn (string $typed): string => $this->plannedDatabaseName($typed, $profile, $appDatabase),
            );
        }

        if (! $this->confirmsDestructive("pull [{$profile}] and drop and recreate {$database} with it")) {
            return self::FAILURE;
        }

        if ($this->input->isInteractive() && ! $this->option('force')) {
            $switchApp = $database !== $appDatabase && $this->askToSwitchApp($database, beforeRestoring: true);
        }

        if ($this->wantsAddonPicker('addon') || ($addons === [] && $this->input->isInteractive() && ! $this->option('force'))) {
            $addons = $this->askAddons($snapshots, $addons);
        }

        $pulled = $this->call('snapshot:pull', ['--profile' => $profile, '--no-interaction' => true]);

        // The pull switched prompts to non-interactive for the whole process; switch them back.
        Prompt::interactive($this->input->isInteractive());

        if ($pulled !== self::SUCCESS) {
            return self::FAILURE;
        }

        return $this->call('snapshot:restore', array_filter([
            'snapshot' => 'latest',
            '--profile' => $profile,
            '--database' => $database !== $appDatabase ? $database : null,
            '--addon' => $addons,
            '--switch-app' => $switchApp,
            '--force' => true,
        ]));
    }

    /**
     * The profile file, created now (when interactive and wanted) if it's missing.
     */
    private function ensureProfileExists(string $profile): bool
    {
        if (file_exists(Profile::path(config('db-snapshot.profile_path'), $profile))) {
            return true;
        }

        if ($this->input->isInteractive() && ! $this->option('force') && confirm("Profile [{$profile}] doesn't exist yet. Create it now?")) {
            return $this->call('snapshot:configure', ['profile' => $profile]) === self::SUCCESS;
        }

        error("Profile [{$profile}] doesn't exist. Run snapshot:configure {$profile} first.");

        return false;
    }

    /**
     * --force asks nothing, so an old analysis is only warned about.
     */
    protected function mayAskAboutAnalysis(): bool
    {
        return $this->input->isInteractive() && ! $this->option('force');
    }

    private function usableAddon(SnapshotRepository $snapshots, string $name): Snapshot
    {
        $addon = $snapshots->findAddon($name);
        $driver = (string) config('db-snapshot.driver');

        if ($addon->driver() !== $driver) {
            throw new RuntimeException("Add-on {$addon->profile()} was pulled with the {$addon->driver()} driver, and this pull uses {$driver}.");
        }

        return $addon;
    }

    /**
     * Saved add-ons to apply after the restore.
     *
     * @param  list<string>  $named  directory names to preselect
     * @return list<string>
     */
    private function askAddons(SnapshotRepository $snapshots, array $named): array
    {
        $driver = (string) config('db-snapshot.driver');
        $available = array_values(array_filter($snapshots->addons(), fn (Snapshot $addon): bool => $addon->driver() === $driver));

        if ($available === []) {
            return $named;
        }

        // The restore warns about add-ons far from the snapshot it just pulled.
        return array_map(fn (Snapshot $addon): string => $addon->name(), $this->pickAddons($available, 'Also apply add-ons?', $named));
    }

    private function plannedDatabaseName(string $template, string $profile, string $appDatabase): string
    {
        return DatabaseName::resolveFor($template, (string) config('db-snapshot.remote.database'), $profile, CarbonImmutable::now(), $appDatabase);
    }
}
