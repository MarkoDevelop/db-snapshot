<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Profile\TableRule;
use Overthink\DbSnapshot\Snapshot\Puller;

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
