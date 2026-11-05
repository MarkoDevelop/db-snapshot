<?php

use Illuminate\Support\Facades\File;
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
        ->expectsChoice('Restore into which database?', 'app', [
            'app' => "dev_wt (the app's database)",
            'new' => 'production_2026_10_08 (a database for this snapshot)',
            'other' => 'Another name…',
        ])
        ->expectsConfirmation('Drop and recreate dev_wt on mysql:3306?', 'no')
        ->expectsOutputToContain('Nothing changed.')
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

it('restores into a new database named from the template and can point the app at it', function () {
    prepareSnapshotChoice($this->workspace);
    app()->useEnvironmentPath($this->workspace);
    File::put($this->workspace.'/.env', "APP_NAME=App\nDB_DATABASE=dev_wt\n");

    $this->artisan('snapshot:restore', ['snapshot' => 'latest'])
        ->expectsChoice('Restore into which database?', 'new', [
            'app' => "dev_wt (the app's database)",
            'new' => 'production_2026_10_08 (a database for this snapshot)',
            'other' => 'Another name…',
        ])
        ->expectsConfirmation('Drop and recreate production_2026_10_08 on mysql:3306?', 'yes')
        ->expectsConfirmation("Point the app at production_2026_10_08? (sets DB_DATABASE in {$this->workspace}/.env)", 'yes')
        ->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(commandLine($process), 'CREATE DATABASE `production_2026_10_08`'));
    expect(File::get($this->workspace.'/.env'))->toBe("APP_NAME=App\nDB_DATABASE=production_2026_10_08\n");
});

it('accepts placeholders in --database without asking', function () {
    prepareSnapshotChoice($this->workspace);

    $this->artisan('snapshot:restore', ['snapshot' => 'latest', '--database' => '{database}_{date}', '--no-interaction' => true, '--force' => true])
        ->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(commandLine($process), 'CREATE DATABASE `dev_wt_2026_10_08`'));
});

it('applies add-ons after the snapshot, oldest first', function () {
    $this->travelTo('2026-10-12 08:00:00');
    $imports = [];
    Process::fake(['*' => function ($process) use (&$imports) {
        if (preg_match('#snapshots/([^/]+)/tables/orders\.sql\.gz#', commandLine($process), $match)) {
            $imports[] = $match[1];
        }

        return Process::result();
    }]);
    config()->set('database.connections.target', ['driver' => 'mysql', 'host' => 'mysql', 'port' => 3306, 'username' => 'root', 'password' => '', 'database' => 'dev_wt']);
    config()->set('db-snapshot.connection', 'target');
    makeSnapshot($this->workspace.'/snapshots/2026-10-08_120000_default', ['orders' => 1]);
    makeAddon($this->workspace, 'recent-orders', ['orders' => 2], createdAt: '2026-10-11T08:00:00+00:00');
    makeAddon($this->workspace, 'all-orders', ['orders' => 3], createdAt: '2026-10-09T08:00:00+00:00');

    $this->artisan('snapshot:restore', ['snapshot' => 'latest', '--addon' => ['recent-orders', 'all-orders'], '--force' => true])
        ->expectsOutputToContain('with all-orders, recent-orders')
        ->assertSuccessful();

    expect($imports)->toBe(['2026-10-08_120000_default', '2026-10-09_080000_all-orders', '2026-10-11_080000_recent-orders']);
});

it('offers saved add-ons and warns when one is far from the snapshot', function () {
    $this->travelTo('2026-11-01 08:00:00');
    Process::fake();
    config()->set('database.connections.target', ['driver' => 'mysql', 'host' => 'mysql', 'port' => 3306, 'username' => 'root', 'password' => '', 'database' => 'dev_wt']);
    config()->set('db-snapshot.connection', 'target');
    makeSnapshot($this->workspace.'/snapshots/2026-10-08_120000_default', ['orders' => 1]);
    $addon = makeAddon($this->workspace, 'orders', ['orders' => 2], createdAt: '2026-10-30T08:00:00+00:00');

    $this->artisan('snapshot:restore', ['snapshot' => 'latest', '--database' => 'dev_wt'])
        ->expectsChoice('Also apply add-ons?', [$addon->name()], [$addon->name() => '2 days ago · orders · orders · 2 B'])
        ->expectsOutputToContain('Add-on orders has tables pulled 3 weeks after the snapshot')
        ->expectsConfirmation('Drop and recreate dev_wt on mysql:3306?', 'yes')
        ->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(commandLine($process), $addon->path.'/tables/orders.sql.gz'));
});

it('lets you pick add-ons with a bare --addon, even with --force', function () {
    $this->travelTo('2026-10-09 08:00:00');
    Process::fake();
    config()->set('database.connections.target', ['driver' => 'mysql', 'host' => 'mysql', 'port' => 3306, 'username' => 'root', 'password' => '', 'database' => 'dev_wt']);
    config()->set('db-snapshot.connection', 'target');
    makeSnapshot($this->workspace.'/snapshots/2026-10-08_120000_default', ['orders' => 1]);
    $addon = makeAddon($this->workspace, 'orders', ['orders' => 2], createdAt: '2026-10-08T13:00:00+00:00');

    $this->artisan('snapshot:restore', ['snapshot' => 'latest', '--addon' => [null], '--force' => true])
        ->expectsChoice('Also apply add-ons?', [$addon->name()], [$addon->name() => '19 hours ago · orders · orders · 2 B'])
        ->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(commandLine($process), $addon->path.'/tables/orders.sql.gz'));
});

it('refuses an add-on of another driver before dropping anything', function () {
    Process::fake();
    config()->set('database.connections.target', ['driver' => 'mysql', 'host' => 'mysql', 'port' => 3306, 'username' => 'root', 'password' => '', 'database' => 'dev_wt']);
    config()->set('db-snapshot.connection', 'target');
    makeSnapshot($this->workspace.'/snapshots/2026-10-08_120000_default', ['orders' => 1]);
    $addon = makeAddon($this->workspace, 'orders', ['orders' => 2]);
    File::put($addon->path.'/manifest.json', json_encode([...$addon->manifest, 'driver' => 'pgsql']));

    $this->artisan('snapshot:restore', ['snapshot' => 'latest', '--addon' => ['orders'], '--force' => true])
        ->expectsOutputToContain('Add-on orders was pulled with the pgsql driver')
        ->assertFailed();

    Process::assertNothingRan();
});

it('warns about a merged add-on whose older tables are far from the snapshot', function () {
    $this->travelTo('2026-10-09 08:00:00');
    Process::fake();
    config()->set('database.connections.target', ['driver' => 'mysql', 'host' => 'mysql', 'port' => 3306, 'username' => 'root', 'password' => '', 'database' => 'dev_wt']);
    config()->set('db-snapshot.connection', 'target');
    makeSnapshot($this->workspace.'/snapshots/2026-10-08_120000_default', ['orders' => 1]);
    $addon = makeAddon($this->workspace, 'orders', ['orders' => 2, 'users' => 1], createdAt: '2026-10-08T13:00:00+00:00');
    $manifest = $addon->manifest;
    $manifest['tables']['users']['pulled_at'] = '2026-09-01T08:00:00+00:00';
    File::put($addon->path.'/manifest.json', json_encode($manifest));

    $this->artisan('snapshot:restore', ['snapshot' => 'latest', '--addon' => ['orders'], '--force' => true])
        ->expectsOutputToContain('Add-on orders has tables pulled 1 month before the snapshot')
        ->assertSuccessful();
});

it('needs an add-on name for a bare --addon when not interactive', function () {
    Process::fake();
    makeSnapshot($this->workspace.'/snapshots/2026-10-08_120000_default', ['orders' => 1]);
    makeAddon($this->workspace, 'orders', ['orders' => 2]);

    $this->artisan('snapshot:restore', ['snapshot' => 'latest', '--addon' => [null], '--no-interaction' => true])
        ->expectsOutputToContain('--addon needs a name when not interactive.')
        ->assertFailed();

    Process::assertNothingRan();
});
