<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Overthink\DbSnapshot\Analysis\Analysis;
use Overthink\DbSnapshot\Analysis\TableInfo;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Profile\TableRule;
use Overthink\DbSnapshot\Snapshot\Snapshot;
use Overthink\DbSnapshot\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');

/**
 * A faked process's command as one string (restore commands are argument lists).
 */
function commandLine(PendingProcess $process): string
{
    return is_array($process->command) ? implode(' ', $process->command) : $process->command;
}

function makeSnapshot(string $path, array $tableSizes, bool $withRoutines = true): Snapshot
{
    File::ensureDirectoryExists($path.'/tables');

    foreach ($tableSizes as $table => $size) {
        File::put("{$path}/tables/{$table}.sql.gz", str_repeat('x', $size));
    }

    if ($withRoutines) {
        File::put($path.'/'.Snapshot::ROUTINES_FILE, 'x');
        File::put($path.'/'.Snapshot::VIEWS_FILE, 'x');
    }

    File::put($path.'/manifest.json', json_encode([
        'profile' => 'default',
        'database' => 'production',
        'created_at' => '2026-10-08T12:00:00+00:00',
        'tables' => array_map(fn (int $size) => ['mode' => 'full', 'where' => null, 'bytes' => $size], $tableSizes),
    ]));

    return Snapshot::load($path);
}

function localConnection(): array
{
    return ['host' => 'mysql', 'port' => 3306, 'username' => 'root', 'password' => 'pw', 'database' => 'dev_wt', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'];
}

function prepareRefresh(string $workspace): void
{
    Sleep::fake();
    Process::preventStrayProcesses();
    Process::fake([
        '*information_schema.TABLES*' => Process::result("orders\tBASE TABLE\t10\t1000\t0\nevent_logs\tBASE TABLE\t10\t1000\t0\nusers\tBASE TABLE\t10\t1000\t0"),
        '*mysqldump*' => function ($process) {
            if (preg_match("/> '([^']+)'$/", $process->command, $target)) {
                File::put($target[1], gzencode('-- dump'));
            }

            return Process::result();
        },
        '*' => Process::result(),
    ]);

    config()->set('database.connections.target', ['driver' => 'mysql', 'host' => 'mysql', 'port' => 3306, 'username' => 'root', 'password' => '', 'database' => 'dev_app']);
    config()->set('db-snapshot.connection', 'target');

    (new Analysis('production', now()->toImmutable(), [
        'orders' => new TableInfo('orders', false, 10, 1000, 0),
        'event_logs' => new TableInfo('event_logs', false, 10, 1000, 0),
        'users' => new TableInfo('users', false, 10, 1000, 0),
    ]))->save($workspace.'/analysis.json');

    (new Profile('default', TableMode::Full, [
        'event_logs' => new TableRule(TableMode::Recent, 'created_at', months: 3),
    ]))->save($workspace.'/profiles');
}
