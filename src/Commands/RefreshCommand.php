<?php

namespace Overthink\DbSnapshot\Commands;

use Illuminate\Console\Command;

use function Laravel\Prompts\select;

class RefreshCommand extends Command
{
    use ChoosesProfile;

    protected $signature = 'snapshot:refresh
        {tables?* : Refresh only these tables (skips the question)}
        {--profile= : Profile to use; with no tables given, refreshes the whole database from it}
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
                '--profile' => $profile,
                '--force' => $this->option('force'),
            ]));
        }

        $profile = $this->chooseProfile($profile);

        if ($this->call('snapshot:pull', ['--profile' => $profile]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        return $this->call('snapshot:restore', array_filter([
            'snapshot' => 'latest',
            '--profile' => $profile,
            '--force' => $this->option('force'),
        ]));
    }
}
