<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Console\Command;
use Overthink\DbSnapshot\Profile\Profile;

use function Laravel\Prompts\error;
use function Laravel\Prompts\select;

class RefreshCommand extends Command
{
    protected $signature = 'snapshot:refresh
        {tables?* : Refresh only these tables (skips the question)}
        {--profile= : Refresh the whole database from this profile (skips the question)}
        {--force : Do not ask before replacing data}';

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
                '--profile' => $profile ?: 'default',
                '--force' => $this->option('force'),
            ]));
        }

        $profile = $profile ?: $this->chooseProfile();

        if ($profile === null) {
            return self::FAILURE;
        }

        if ($this->call('snapshot:pull', ['--profile' => $profile]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        return $this->call('snapshot:restore', array_filter([
            'snapshot' => 'latest',
            '--profile' => $profile,
            '--force' => $this->option('force'),
        ]));
    }

    private function chooseProfile(): ?string
    {
        $names = Profile::names(config('db-snapshot.profile_path'));

        if ($names === []) {
            error('There are no profiles yet. Create one with: php artisan snapshot:configure');

            return null;
        }

        if (count($names) === 1) {
            return $names[0];
        }

        return (string) select(
            label: 'Which profile?',
            options: array_combine($names, $names),
            default: in_array('default', $names, true) ? 'default' : $names[0],
        );
    }
}
