<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Overthink\DbSnapshot\Snapshot\SnapshotRepository;

it('pulls the given tables straight into the add-on', function () {
    prepareRefresh($this->workspace);

    $this->artisan('snapshot:addon', ['name' => 'orders', 'tables' => ['orders', 'users'], '--force' => true])
        ->expectsOutputToContain('Saved to add-on orders (orders, users)')
        ->assertSuccessful();

    expect(app(SnapshotRepository::class)->findAddon('orders')->tables())->toBe(['orders', 'users']);
});

it('creates a new add-on from the picker', function () {
    prepareRefresh($this->workspace);
    makeAddon($this->workspace, 'users', ['users' => 3]);

    $this->artisan('snapshot:addon')
        ->expectsQuestion('Which add-on?', "\0new")
        ->expectsQuestion('Name of the new add-on', 'orders')
        ->expectsQuestion('Tables to refresh (type to search)', 'orders')
        ->expectsChoice('Tables to refresh (type to search)', ['orders'], ['orders' => 'orders · 1000 B · ~10 rows'])
        ->expectsChoice('Change any rule for this run?', [], ['orders' => 'orders · full'])
        ->expectsConfirmation('Replace orders in dev_app?', 'yes')
        ->assertSuccessful();

    expect(app(SnapshotRepository::class)->findAddon('orders')->tables())->toBe(['orders']);
});

it('loads an existing add-on into the database', function () {
    prepareRefresh($this->workspace);
    $addon = makeAddon($this->workspace, 'orders', ['orders' => 5]);

    $this->artisan('snapshot:addon', ['name' => 'orders', '--force' => true])
        ->expectsChoice('What should happen with orders?', 'load', [
            'pull' => 'Pull tables into it (replaces those, keeps the rest)',
            'load' => 'Load it into the database',
            'delete' => 'Delete it',
        ])
        ->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(commandLine($process), $addon->path.'/tables/orders.sql.gz'));
    Process::assertNotRan(fn ($process) => str_contains(commandLine($process), 'mysqldump'));
});

it('deletes an add-on after confirming', function () {
    prepareRefresh($this->workspace);
    $addon = makeAddon($this->workspace, 'orders', ['orders' => 5]);

    $this->artisan('snapshot:addon', ['name' => 'orders'])
        ->expectsChoice('What should happen with orders?', 'delete', [
            'pull' => 'Pull tables into it (replaces those, keeps the rest)',
            'load' => 'Load it into the database',
            'delete' => 'Delete it',
        ])
        ->expectsConfirmation('Delete add-on orders (5 B)?', 'yes')
        ->assertSuccessful();

    expect(File::exists($addon->path))->toBeFalse();
});

it('needs a name and tables when not interactive', function () {
    prepareRefresh($this->workspace);

    $this->artisan('snapshot:addon', ['--no-interaction' => true])
        ->expectsOutputToContain('Name the add-on when not interactive')
        ->assertFailed();

    $this->artisan('snapshot:addon', ['name' => 'orders', '--no-interaction' => true])
        ->expectsOutputToContain('Name the tables to pull into orders')
        ->assertFailed();

    Process::assertNothingRan();
});

it('pulls into an existing add-on with the profile\'s rules', function () {
    prepareRefresh($this->workspace);
    makeAddon($this->workspace, 'logs', ['users' => 3]);

    $this->artisan('snapshot:addon', ['name' => 'logs', 'tables' => ['event_logs'], '--profile' => 'default', '--force' => true])
        ->expectsOutputToContain('Saved to add-on logs (event_logs, users)')
        ->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(commandLine($process), 'tables/event_logs.sql.gz') && str_contains(commandLine($process), '--where=`created_at` >= '));
});

it('pulls tables into an existing add-on from the menu', function () {
    prepareRefresh($this->workspace);
    makeAddon($this->workspace, 'all-orders', ['users' => 3]);

    $this->artisan('snapshot:addon', ['name' => 'all-orders'])
        ->expectsChoice('What should happen with all-orders?', 'pull', [
            'pull' => 'Pull tables into it (replaces those, keeps the rest)',
            'load' => 'Load it into the database',
            'delete' => 'Delete it',
        ])
        ->expectsQuestion('Tables to refresh (type to search)', 'orders')
        ->expectsChoice('Tables to refresh (type to search)', ['orders'], ['orders' => 'orders · 1000 B · ~10 rows'])
        ->expectsChoice('Change any rule for this run?', [], ['orders' => 'orders · full'])
        ->expectsConfirmation('Replace orders in dev_app?', 'yes')
        ->assertSuccessful();

    expect(app(SnapshotRepository::class)->findAddon('all-orders')->tables())->toBe(['orders', 'users']);
});
