<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Overthink\DbSnapshot\Facades\DbSnapshot;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Profile\TableRule;
use Overthink\DbSnapshot\Snapshot\Puller;
use Overthink\DbSnapshot\Tests\Fixtures\FakeDriver;

function fakeRemoteTables(array $dumpResults = []): void
{
    Sleep::fake();
    Process::preventStrayProcesses();
    Process::fake([
        '*information_schema.TABLES*' => Process::result(implode("\n", [
            "event_logs\tBASE TABLE\t100\t5000\t0",
            "activities\tBASE TABLE\t100\t4000\t0",
            "jobs\tBASE TABLE\t100\t3000\t0",
            "orders\tBASE TABLE\t100\t2000\t0",
            "order_totals\tVIEW\t0\t0\t0",
        ])),
        ...$dumpResults,
        '*mysqldump*' => Process::result(),
    ]);
}

function defaultProfile(): Profile
{
    return new Profile('default', TableMode::Full, [
        'event_logs' => new TableRule(TableMode::Recent, 'created_at', months: 3),
        'activities' => new TableRule(TableMode::Schema),
        'jobs' => new TableRule(TableMode::Skip),
    ]);
}

it('dumps each table according to its rule', function () {
    $this->travelTo('2026-10-08 12:00:00');
    fakeRemoteTables();

    $snapshot = app(Puller::class)->pull(defaultProfile(), 2);

    expect($snapshot->manifest['tables'])->toBe([
        'event_logs' => ['mode' => 'recent', 'where' => "`created_at` >= '2026-07-08 00:00:00'", 'bytes' => 0],
        'activities' => ['mode' => 'schema', 'where' => null, 'bytes' => 0],
        'orders' => ['mode' => 'full', 'where' => null, 'bytes' => 0],
    ]);

    $dumpOf = fn (string $table) => fn ($process) => str_contains(commandLine($process), "/tables/{$table}.sql.gz");

    Process::assertRan(fn ($process) => $dumpOf('event_logs')($process) && str_contains(commandLine($process), '--where=`created_at` >= '));
    Process::assertRan(fn ($process) => $dumpOf('activities')($process) && str_contains(commandLine($process), '--no-data'));
    Process::assertRan(fn ($process) => $dumpOf('orders')($process) && ! str_contains(commandLine($process), '--where') && ! str_contains(commandLine($process), '--no-data'));
    Process::assertNotRan(fn ($process) => str_contains(commandLine($process), 'jobs'));
});

it('dumps views and routines separately so they restore after the tables', function () {
    fakeRemoteTables();

    $snapshot = app(Puller::class)->pull(defaultProfile(), 4);

    Process::assertRan(fn ($process) => str_ends_with(commandLine($process), '_views.sql.gz\'') && str_contains(commandLine($process), 'order_totals'));
    Process::assertRan(fn ($process) => str_contains(commandLine($process), '--routines') && str_ends_with(commandLine($process), '_routines.sql.gz\''));
    expect($snapshot->manifest['tables'])->not->toHaveKey('order_totals');
});

it('names the snapshot after the time and profile and leaves no partial directory', function () {
    $this->travelTo('2026-10-08 12:34:56');
    fakeRemoteTables();

    $snapshot = app(Puller::class)->pull(defaultProfile(), 4);

    expect($snapshot->name())->toBe('2026-10-08_123456_default')
        ->and(File::directories($this->workspace.'/snapshots'))->toBe([$snapshot->path]);
});

it('throws with the failing tables and keeps nothing when a dump fails', function () {
    fakeRemoteTables(['*tables/orders.sql.gz*' => Process::result(errorOutput: 'mysqldump: Got error: 1146', exitCode: 2)]);

    expect(fn () => app(Puller::class)->pull(defaultProfile(), 4))
        ->toThrow(RuntimeException::class, 'orders: mysqldump: Got error: 1146');

    expect(File::directories($this->workspace.'/snapshots'))->toBe([]);
});

it('never runs more dumps at once than allowed', function () {
    Sleep::fake();
    Process::fake([
        '*information_schema.TABLES*' => Process::result(implode("\n", array_map(fn (int $i) => "t{$i}\tBASE TABLE\t1\t1\t0", range(1, 6)))),
        '*mysqldump*' => Process::describe()->iterations(3),
    ]);

    $maxRunning = 0;

    app(Puller::class)->pull(new Profile('default'), 2, onTick: function (array $running) use (&$maxRunning) {
        $maxRunning = max($maxRunning, count($running));
    });

    expect($maxRunning)->toBe(2);
});

it('keeps the snapshot directory out of git', function () {
    fakeRemoteTables();

    app(Puller::class)->pull(defaultProfile(), 1);

    expect(File::get($this->workspace.'/snapshots/.gitignore'))->toBe("*\n!.gitignore\n");
});

it('leaves an existing gitignore in the snapshot directory alone', function () {
    fakeRemoteTables();
    File::ensureDirectoryExists($this->workspace.'/snapshots');
    File::put($this->workspace.'/snapshots/.gitignore', "custom\n");

    app(Puller::class)->pull(defaultProfile(), 1);

    expect(File::get($this->workspace.'/snapshots/.gitignore'))->toBe("custom\n");
});

function fakeRemoteWithColumns(): void
{
    fakeRemoteTables([
        '*IS_NULLABLE*' => Process::result(implode("\n", [
            "orders\tid\tint\tNULL\tauto_increment\tPRI\tNO",
            "orders\tnumber\tvarchar\t20\t\t\tNO",
            "orders\tcustomer_email\tvarchar\t191\t\t\tYES",
            "orders\tcustomer_phone\tvarchar\t30\t\t\tNO",
            "orders\ttotal\tdecimal\tNULL\t\t\tNO",
            "event_logs\tmessage\ttext\t65535\t\t\tYES",
        ])),
    ]);
}

function anonymizingProfile(array $orders): Profile
{
    return new Profile('default', TableMode::Full, [
        'orders' => TableRule::fromArray(['mode' => 'full', 'anonymize' => $orders]),
        'activities' => new TableRule(TableMode::Skip),
        'event_logs' => new TableRule(TableMode::Skip),
        'jobs' => new TableRule(TableMode::Skip),
    ]);
}

it('dumps anonymized tables with the replacements and records them in the manifest', function () {
    fakeRemoteWithColumns();
    config()->set('db-snapshot.anonymize.salt', 'pepper');

    $snapshot = app(Puller::class)->pull(anonymizingProfile(['customer_email' => 'email', 'customer_phone' => 'empty']), 1);

    Process::assertRan(fn ($process) => str_contains(commandLine($process), 'tables/orders.sql.gz')
        && str_contains(commandLine($process), 'SHA2(')
        && str_contains(commandLine($process), 'pepper')
        && str_contains(commandLine($process), 'INSERT INTO'));
    expect($snapshot->manifest['tables']['orders']['anonymized'])->toBe(['customer_email', 'customer_phone'])
        ->and($snapshot->manifest['tables'])->not->toHaveKey('activities');
});

it('lists every rule that cannot work and pulls nothing', function () {
    fakeRemoteWithColumns();

    expect(fn () => app(Puller::class)->pull(anonymizingProfile([
        'customer_mail' => 'email',
        'customer_phone' => 'null',
        'total' => 'hash',
        'number' => ['template' => 'Order {id}'],
    ]), 1))->toThrow(RuntimeException::class, implode("\n  ", [
        "Profile [default] can't anonymize:",
        'orders.customer_mail does not exist',
        'orders.customer_phone is NOT NULL; "null" would fail (use "empty" or "fixed")',
        'orders.total is decimal; "hash" needs a text column (use "null" or "fixed")',
    ]));

    Process::assertNotRan(fn ($process) => str_contains(commandLine($process), 'tables/orders.sql.gz'));
});

it('refuses to anonymize with a driver that cannot', function () {
    Process::fake();
    DbSnapshot::extend('fake', fn () => new FakeDriver);
    config()->set('db-snapshot.driver', 'fake');

    app(Puller::class)->pull(new Profile('default', TableMode::Full, [
        'orders' => TableRule::fromArray(['mode' => 'full', 'anonymize' => ['email' => 'email']]),
    ]), 1);
})->throws(RuntimeException::class, "The fake driver can't anonymize columns");
