<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

it('refuses to restore in production', function () {
    Process::fake();
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('snapshot:restore', ['--force' => true])
        ->expectsOutputToContain('never runs in production')
        ->assertFailed();

    Process::assertNothingRan();
});

it('does not drop the database when the confirmation is declined', function () {
    Process::fake();
    config()->set('database.connections.target', ['driver' => 'mysql', 'host' => 'mysql', 'port' => 3306, 'username' => 'root', 'password' => '', 'database' => 'dev_wt']);
    config()->set('db-snapshot.connection', 'target');
    makeSnapshot($this->workspace.'/snapshots/2026-10-08_120000_default', ['orders' => 1]);

    $this->artisan('snapshot:restore')
        ->expectsConfirmation('Drop and recreate dev_wt on mysql:3306?', 'no')
        ->assertFailed();

    Process::assertNothingRan();
});

function prepareSnapshotChoice(string $workspace): void
{
    Sleep::fake();
    Process::fake();
    config()->set('database.connections.target', ['driver' => 'mysql', 'host' => 'mysql', 'port' => 3306, 'username' => 'root', 'password' => '', 'database' => 'dev_wt']);
    config()->set('db-snapshot.connection', 'target');

    makeSnapshot($workspace.'/snapshots/2026-10-01_080000_default', ['orders' => 1], createdAt: '2026-10-01T08:00:00+00:00');
    makeSnapshot($workspace.'/snapshots/2026-10-05_080000_nightly', ['orders' => 1], createdAt: '2026-10-05T08:00:00+00:00', profile: 'nightly');
    makeSnapshot($workspace.'/snapshots/2026-10-08_080000_default', ['orders' => 1], createdAt: '2026-10-08T08:00:00+00:00');
}

function restoredFrom(string $name): Closure
{
    return fn ($process) => str_contains(commandLine($process), "/snapshots/{$name}/tables/orders.sql.gz");
}

it('lets you pick a snapshot, newest first, when none is named', function () {
    $this->travelTo('2026-10-09 08:00:00');
    prepareSnapshotChoice($this->workspace);

    $this->artisan('snapshot:restore', ['--force' => true])
        ->expectsChoice('Which snapshot?', '2026-10-01_080000_default', [
            '2026-10-08_080000_default' => '1 day ago · default · 1 B · 2026-10-08_080000_default',
            '2026-10-05_080000_nightly' => '4 days ago · nightly · 1 B · 2026-10-05_080000_nightly',
            '2026-10-01_080000_default' => '1 week ago · default · 1 B · 2026-10-01_080000_default',
        ])
        ->assertSuccessful();

    Process::assertRan(restoredFrom('2026-10-01_080000_default'));
    Process::assertNotRan(restoredFrom('2026-10-08_080000_default'));
});

it('only offers snapshots of the given profile', function () {
    $this->travelTo('2026-10-09 08:00:00');
    prepareSnapshotChoice($this->workspace);

    $this->artisan('snapshot:restore', ['--profile' => 'default', '--force' => true])
        ->expectsChoice('Which snapshot?', '2026-10-08_080000_default', [
            '2026-10-08_080000_default' => '1 day ago · default · 1 B · 2026-10-08_080000_default',
            '2026-10-01_080000_default' => '1 week ago · default · 1 B · 2026-10-01_080000_default',
        ])
        ->assertSuccessful();

    Process::assertRan(restoredFrom('2026-10-08_080000_default'));
});

it('restores the newest without asking when told to or not interactive', function (array $arguments) {
    prepareSnapshotChoice($this->workspace);

    $this->artisan('snapshot:restore', [...$arguments, '--force' => true])->assertSuccessful();

    Process::assertRan(restoredFrom('2026-10-08_080000_default'));
})->with([
    'latest named' => [['snapshot' => 'latest']],
    'not interactive' => [['--no-interaction' => true]],
]);
