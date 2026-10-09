<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Snapshot\SnapshotRepository;

it('pulls the chosen profile and then restores it', function () {
    prepareRefresh($this->workspace);
    (new Profile('nightly', TableMode::Schema))->save($this->workspace.'/profiles');

    $this->artisan('snapshot:refresh')
        ->expectsChoice('What do you want to refresh?', 'profile', [
            'profile' => 'The whole database, from a profile (pull + restore)',
            'tables' => 'Just some tables (the rest of the database stays as it is)',
        ])
        ->expectsChoice('Which profile?', 'nightly', ['default' => 'default · 1 rule · never pulled', 'nightly' => 'nightly · 0 rules · never pulled'])
        ->expectsChoice('Restore into which database?', 'app', [
            'app' => "dev_app (the app's database)",
            'new' => 'production_'.now()->format('Y_m_d').' (a database for this snapshot)',
            'other' => 'Another name…',
        ])
        ->expectsConfirmation('Drop and recreate dev_app on mysql:3306?', 'yes')
        ->assertSuccessful();

    expect(app(SnapshotRepository::class)->find()->profile())->toBe('nightly');
    Process::assertRan(fn ($process) => str_contains(commandLine($process), 'DROP DATABASE IF EXISTS `dev_app`'));
});

it('hands tables given as arguments to refresh-table', function () {
    prepareRefresh($this->workspace);

    $this->artisan('snapshot:refresh', ['tables' => ['orders'], '--force' => true])->assertSuccessful();

    Process::assertRan(fn ($process) => is_string($process->command) && str_contains(commandLine($process), 'tables/orders.sql.gz'));
    Process::assertNotRan(fn ($process) => str_contains(commandLine($process), 'DROP DATABASE'));
});

it('offers to create the default profile when there are none', function () {
    prepareRefresh($this->workspace);
    File::deleteDirectory($this->workspace.'/profiles');

    $this->artisan('snapshot:refresh')
        ->expectsChoice('What do you want to refresh?', 'profile', [
            'profile' => 'The whole database, from a profile (pull + restore)',
            'tables' => 'Just some tables (the rest of the database stays as it is)',
        ])
        ->expectsConfirmation("Profile [default] doesn't exist yet. Create it now?", 'no')
        ->expectsOutputToContain('Run snapshot:configure default first')
        ->assertFailed();

    Process::assertNotRan(fn ($process) => str_contains(commandLine($process), 'mysqldump'));
});
