<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Overthink\DbSnapshot\Snapshot\SnapshotRepository;

it('pulls only the given tables with their profile rules and replaces only them', function () {
    prepareRefresh($this->workspace);

    $this->artisan('snapshot:refresh-table', ['tables' => ['event_logs', 'orders'], '--force' => true])
        ->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(commandLine($process), 'tables/event_logs.sql.gz') && str_contains(commandLine($process), '--where=`created_at` >= '));
    Process::assertRan(fn ($process) => str_contains(commandLine($process), 'tables/orders.sql.gz') && ! str_contains(commandLine($process), '--where'));
    Process::assertNotRan(fn ($process) => str_contains(commandLine($process), 'users'));
    Process::assertNotRan(fn ($process) => str_contains(commandLine($process), 'DROP DATABASE'));
    Process::assertNotRan(fn ($process) => is_string($process->command) && (str_contains(commandLine($process), '_views') || str_contains(commandLine($process), '--routines')));
    Process::assertRan(fn ($process) => is_array($process->command) && str_contains(commandLine($process), 'tables/event_logs.sql.gz') && str_contains(commandLine($process), "'dev_app'"));
});

it('removes the pulled files afterwards and keeps them out of snapshot:restore', function () {
    prepareRefresh($this->workspace);

    $this->artisan('snapshot:refresh-table', ['tables' => ['orders'], '--force' => true])->assertSuccessful();
    expect(File::directories($this->workspace.'/snapshots'))->toBe([]);

    $this->artisan('snapshot:refresh-table', ['tables' => ['orders'], '--force' => true, '--keep' => true])->assertSuccessful();
    expect(File::directories($this->workspace.'/snapshots'))->toHaveCount(1);

    $this->artisan('snapshot:restore', ['--force' => true])
        ->expectsOutputToContain('No snapshot [latest]')
        ->assertFailed();
});

it('rejects tables that do not exist on the server', function () {
    prepareRefresh($this->workspace);

    $this->artisan('snapshot:refresh-table', ['tables' => ['orderz'], '--force' => true])
        ->expectsOutputToContain('Unknown tables: orderz')
        ->assertFailed();

    Process::assertNothingRan();
});

it('does not touch the database when the confirmation is declined', function () {
    prepareRefresh($this->workspace);

    $this->artisan('snapshot:refresh-table', ['tables' => ['orders']])
        ->expectsConfirmation('Also save these tables as an add-on, to apply on top of later restores?', 'no')
        ->expectsConfirmation('Replace orders in dev_app?', 'no')
        ->assertFailed();

    Process::assertNothingRan();
});

it('keeps the pulled tables as an add-on with --save, apart from the snapshots', function () {
    prepareRefresh($this->workspace);

    $this->artisan('snapshot:refresh-table', ['tables' => ['orders'], '--save' => 'orders', '--force' => true])
        ->expectsOutputToContain('Saved to add-on orders (orders)')
        ->assertSuccessful();

    $snapshots = app(SnapshotRepository::class);
    expect($snapshots->all())->toBe([])
        ->and($snapshots->addons())->toHaveCount(1)
        ->and($snapshots->findAddon('orders')->tables())->toBe(['orders']);
});

it('replaces only the pulled tables when saving to an existing add-on', function () {
    $this->travelTo('2026-10-20 08:00:00');
    prepareRefresh($this->workspace);
    makeAddon($this->workspace, 'orders', ['orders' => 5, 'users' => 3], createdAt: '2026-10-10T08:00:00+00:00');

    $this->artisan('snapshot:refresh-table', ['tables' => ['orders'], '--save' => 'orders', '--force' => true])
        ->expectsOutputToContain('Saved to add-on orders (orders, users)')
        ->assertSuccessful();

    $addons = app(SnapshotRepository::class)->addons();
    expect($addons)->toHaveCount(1)
        ->and($addons[0]->tables())->toBe(['orders', 'users'])
        ->and(gzdecode(File::get($addons[0]->tableFiles()['orders'])))->toBe('-- dump')
        ->and(File::get($addons[0]->tableFiles()['users']))->toBe('xxx')
        ->and($addons[0]->oldestTableAt()->toDateString())->toBe('2026-10-10');
});

it('asks which add-on to save to when --save has no name', function () {
    prepareRefresh($this->workspace);
    makeAddon($this->workspace, 'orders', ['orders' => 5]);

    $this->artisan('snapshot:refresh-table', ['tables' => ['orders'], '--save' => null])
        ->expectsQuestion('Save to which add-on?', "\0new")
        ->expectsQuestion('Add-on name', 'big-orders')
        ->expectsConfirmation('Replace orders in dev_app?', 'yes')
        ->assertSuccessful();

    expect(array_map(fn ($addon) => $addon->profile(), app(SnapshotRepository::class)->addons()))
        ->toEqualCanonicalizing(['orders', 'big-orders']);
});

it('loads tables from a saved add-on without touching the server', function () {
    prepareRefresh($this->workspace);
    $addon = makeAddon($this->workspace, 'orders', ['orders' => 5, 'users' => 3]);

    $this->artisan('snapshot:refresh-table', ['tables' => ['users'], '--from' => 'orders', '--force' => true])
        ->expectsOutputToContain('Loaded users from orders into dev_app.')
        ->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(commandLine($process), $addon->path.'/tables/users.sql.gz'));
    Process::assertNotRan(fn ($process) => str_contains(commandLine($process), 'tables/orders.sql.gz'));
    Process::assertNotRan(fn ($process) => str_contains(commandLine($process), 'ssh'));
});

it('rejects tables the add-on does not have', function () {
    prepareRefresh($this->workspace);
    makeAddon($this->workspace, 'orders', ['orders' => 5]);

    $this->artisan('snapshot:refresh-table', ['tables' => ['users'], '--from' => 'orders', '--force' => true])
        ->expectsOutputToContain('Add-on orders has no users. It has orders.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('lets you pick the add-on when --from has no name', function () {
    $this->travelTo('2026-10-09 08:00:00');
    prepareRefresh($this->workspace);
    $orders = makeAddon($this->workspace, 'orders', ['orders' => 5]);
    makeAddon($this->workspace, 'users', ['users' => 3], createdAt: '2026-10-07T12:00:00+00:00');

    $this->artisan('snapshot:refresh-table', ['--from' => null])
        ->expectsChoice('Load from which add-on?', $orders->name(), [
            $orders->name() => '20 hours ago · orders · orders · 5 B',
            '2026-10-07_120000_users' => '1 day ago · users · users · 3 B',
        ])
        ->expectsConfirmation('Replace orders in dev_app?', 'yes')
        ->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(commandLine($process), $orders->path.'/tables/orders.sql.gz'));
});

it('needs an add-on name for --from when not interactive', function () {
    prepareRefresh($this->workspace);
    makeAddon($this->workspace, 'orders', ['orders' => 5]);

    $this->artisan('snapshot:refresh-table', ['--from' => null, '--no-interaction' => true])
        ->expectsOutputToContain('--from needs an add-on name when not interactive.')
        ->assertFailed();
});

it('moves the post-data of kept tables when merging, and imports only the pulled tables', function () {
    $this->travelTo('2026-10-20 08:00:00');
    prepareRefresh($this->workspace);
    $older = makeAddon($this->workspace, 'orders', ['orders' => 5, 'users' => 3], createdAt: '2026-10-10T08:00:00+00:00');
    File::ensureDirectoryExists($older->path.'/post-data');
    File::put($older->path.'/post-data/users.sql.gz', 'users-indexes');
    File::put($older->path.'/post-data/orders.sql.gz', 'old-orders-indexes');

    $this->artisan('snapshot:refresh-table', ['tables' => ['orders'], '--save' => 'orders', '--force' => true])->assertSuccessful();

    $addon = app(SnapshotRepository::class)->findAddon('orders');
    expect(File::exists($older->path))->toBeFalse()
        ->and(File::get($addon->path.'/post-data/users.sql.gz'))->toBe('users-indexes')
        ->and(File::exists($addon->path.'/post-data/orders.sql.gz'))->toBeFalse();
    Process::assertNotRan(fn ($process) => is_array($process->command) && str_contains(commandLine($process), 'tables/users.sql.gz'));
});

it('refuses to save into an add-on pulled with another driver', function () {
    prepareRefresh($this->workspace);
    $addon = makeAddon($this->workspace, 'orders', ['orders' => 5]);
    File::put($addon->path.'/manifest.json', json_encode([...$addon->manifest, 'driver' => 'pgsql']));

    $this->artisan('snapshot:refresh-table', ['tables' => ['orders'], '--save' => 'orders', '--force' => true])
        ->expectsOutputToContain('Add-on orders was pulled with the pgsql driver')
        ->assertFailed();

    Process::assertNotRan(fn ($process) => str_contains(commandLine($process), 'mysqldump'));
});

it('rejects --save together with --from, and invalid add-on names', function () {
    prepareRefresh($this->workspace);

    $this->artisan('snapshot:refresh-table', ['--from' => 'orders', '--save' => 'x', '--force' => true])
        ->expectsOutputToContain("--save and --from don't go together")
        ->assertFailed();

    $this->artisan('snapshot:refresh-table', ['tables' => ['orders'], '--save' => 'my orders', '--force' => true])
        ->expectsOutputToContain('Invalid add-on name [my orders]')
        ->assertFailed();

    Process::assertNothingRan();
});

it('saves to a new add-on when you say yes before pulling', function () {
    prepareRefresh($this->workspace);

    $this->artisan('snapshot:refresh-table', ['tables' => ['orders']])
        ->expectsConfirmation('Also save these tables as an add-on, to apply on top of later restores?', 'yes')
        ->expectsQuestion('Add-on name', 'all-orders')
        ->expectsConfirmation('Replace orders in dev_app?', 'yes')
        ->assertSuccessful();

    expect(app(SnapshotRepository::class)->findAddon('all-orders')->tables())->toBe(['orders']);
});

it('needs an add-on name for a bare --save when not interactive', function () {
    prepareRefresh($this->workspace);

    $this->artisan('snapshot:refresh-table', ['tables' => ['orders'], '--save' => null, '--no-interaction' => true])
        ->expectsOutputToContain('--save needs an add-on name when not interactive.')
        ->assertFailed();

    Process::assertNothingRan();
});
