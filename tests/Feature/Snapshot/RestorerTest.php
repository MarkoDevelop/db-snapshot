<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Overthink\DbSnapshot\Snapshot\Restorer;
use Overthink\DbSnapshot\Snapshot\Snapshot;

it('recreates the database, imports tables largest first, then routines and views', function () {
    Sleep::fake();
    Process::preventStrayProcesses();
    Process::fake();
    $snapshot = makeSnapshot($this->workspace.'/snap', ['orders' => 10, 'event_logs' => 50, 'users' => 1]);

    app(Restorer::class)->restore($snapshot, localConnection(), 1);

    $runs = fn (string $needle) => fn ($process) => str_contains(is_array($process->command) ? end($process->command) : $process->command, $needle);

    Process::assertRanInOrder([
        $runs('DROP DATABASE IF EXISTS `dev_wt`; CREATE DATABASE `dev_wt` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),
        $runs('tables/event_logs.sql.gz'),
        $runs('tables/orders.sql.gz'),
        $runs('tables/users.sql.gz'),
        $runs(Snapshot::ROUTINES_FILE),
        $runs(Snapshot::VIEWS_FILE),
    ]);
});

it('imports with pipefail so a corrupt file fails the table', function () {
    Sleep::fake();
    Process::fake();
    $snapshot = makeSnapshot($this->workspace.'/snap', ['orders' => 1], withRoutines: false);

    app(Restorer::class)->restore($snapshot, localConnection(), 1);

    Process::assertRan(fn ($process) => is_array($process->command)
        && array_slice($process->command, 0, 4) === ['bash', '-o', 'pipefail', '-c']
        && str_contains($process->command[4], "| 'mysql' '--host=mysql' '--port=3306' '--user=root'")
        && $process->environment['MYSQL_PWD'] === 'pw');
});

it('throws with the table that failed to import', function () {
    Sleep::fake();
    Process::fake([
        '*orders.sql.gz*' => Process::result(errorOutput: 'ERROR 1062: Duplicate entry', exitCode: 1),
        '*' => Process::result(),
    ]);
    $snapshot = makeSnapshot($this->workspace.'/snap', ['orders' => 1, 'users' => 1], withRoutines: false);

    app(Restorer::class)->restore($snapshot, localConnection(), 2);
})->throws(RuntimeException::class, 'orders: ERROR 1062: Duplicate entry');

it('refuses a database name that could break out of quoting', function () {
    Process::fake();
    $snapshot = makeSnapshot($this->workspace.'/snap', ['orders' => 1]);

    app(Restorer::class)->restore($snapshot, [...localConnection(), 'database' => 'x`; DROP DATABASE prod; --'], 1);
})->throws(InvalidArgumentException::class);

it('drops the GTID_PURGED statement of dumps from GTID-enabled servers', function () {
    $bin = $this->workspace.'/bin';
    File::ensureDirectoryExists($bin);
    File::put($bin.'/mysql', "#!/bin/bash\ncat >> \"\$(dirname \"\$0\")/received.sql\"\n");
    chmod($bin.'/mysql', 0755);

    $snapshot = makeSnapshot($this->workspace.'/snap', ['orders' => 1], withRoutines: false);
    File::put($snapshot->path.'/tables/orders.sql.gz', gzencode(implode("\n", [
        'SET @MYSQLDUMP_TEMP_LOG_BIN = @@SESSION.SQL_LOG_BIN;',
        "SET @@GLOBAL.GTID_PURGED=/*!80000 '+'*/ '33eea409-fbef-11ea-bb70-02120157c0e0:1-54440526603,",
        "69c29fbe-516b-11ee-ab65-96000288ef48:1-68509794487';",
        'CREATE TABLE `orders` (`id` int);',
        'INSERT INTO `orders` VALUES (1);',
        '',
    ])));

    $path = getenv('PATH');
    $environment = [$_ENV['PATH'] ?? null, $_SERVER['PATH'] ?? null];
    putenv("PATH={$bin}:{$path}");
    $_ENV['PATH'] = $_SERVER['PATH'] = "{$bin}:{$path}";

    try {
        app(Restorer::class)->restore($snapshot, localConnection(), 1);
    } finally {
        putenv("PATH={$path}");
        [$_ENV['PATH'], $_SERVER['PATH']] = $environment;
    }

    expect(File::get($bin.'/received.sql'))
        ->toContain('CREATE TABLE `orders`')
        ->toContain('INSERT INTO `orders` VALUES (1);')
        ->not->toContain('GTID_PURGED')
        ->not->toContain('69c29fbe');
});

it('reports views it cannot create as warnings and keeps the restored tables', function () {
    Sleep::fake();
    Process::fake([
        '*_views.sql.gz*' => Process::result(
            errorOutput: "--------------\n/*!50001 VIEW `old_orders` AS select ... */\n--------------\n\nERROR 1049 (42000) at line 60: Unknown database 'prod_crm'\n",
            exitCode: 1,
        ),
        '*' => Process::result(),
    ]);
    $snapshot = makeSnapshot($this->workspace.'/snap', ['orders' => 1]);

    $warnings = app(Restorer::class)->restore($snapshot, localConnection(), 1);

    expect($warnings)->toBe(["Some views could not be created:\n  ERROR 1049 (42000) at line 60: Unknown database 'prod_crm'"]);
    Process::assertRan(fn ($process) => str_contains(end($process->command), '_views.sql.gz') && str_contains(end($process->command), "'--force'"));
    Process::assertRan(fn ($process) => str_contains(end($process->command), 'tables/orders.sql.gz') && ! str_contains(end($process->command), '--force'));
});
