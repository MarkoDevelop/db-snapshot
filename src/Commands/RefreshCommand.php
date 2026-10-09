<?php

namespace Overthink\DbSnapshot\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Overthink\DbSnapshot\Support\DatabaseName;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;

class RefreshCommand extends Command
{
    use ChoosesProfile;
    use TargetsLocalDatabase;

    protected $signature = 'snapshot:refresh
        {tables?* : Refresh only these tables (skips the question)}
        {--profile= : Profile to use; with no tables given, refreshes the whole database from it}
        {--force : Ask nothing: restore into the app\'s database without confirming}';

    protected $description = 'Refresh local data from the remote database: a whole profile, or just some tables';

    public function handle(): int
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
            return $this->call('snapshot:refresh-table', array_filter([
                'tables' => $tables,
                '--profile' => $profile,
                '--force' => $this->option('force'),
            ]));
        }

        $profile = $this->chooseProfile($profile);

        // Every decision first, so the pull and restore then run unattended.
        $appDatabase = (string) $this->localConnection()['database'];
        $database = $appDatabase;
        $switchApp = false;

        if ($this->input->isInteractive() && ! $this->option('force')) {
            $database = $this->askRestoreDatabase(
                $appDatabase,
                $this->plannedDatabaseName((string) config('db-snapshot.database_name'), $profile, $appDatabase),
                fn (string $typed): string => $this->plannedDatabaseName($typed, $profile, $appDatabase),
            );

            if (! confirm("Pull [{$profile}] and drop and recreate {$database} with it?", default: false)) {
                info('Nothing changed.');

                return self::FAILURE;
            }

            $switchApp = $database !== $appDatabase && $this->askToSwitchApp($database, beforeRestoring: true);
        }

        if ($this->call('snapshot:pull', ['--profile' => $profile]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        return $this->call('snapshot:restore', array_filter([
            'snapshot' => 'latest',
            '--profile' => $profile,
            '--database' => $database !== $appDatabase ? $database : null,
            '--switch-app' => $switchApp,
            '--force' => true,
        ]));
    }

    private function plannedDatabaseName(string $template, string $profile, string $appDatabase): string
    {
        return DatabaseName::resolveFor($template, (string) config('db-snapshot.remote.database'), $profile, CarbonImmutable::now(), $appDatabase);
    }
}
