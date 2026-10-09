<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Overthink\DbSnapshot\Drivers\MysqlDriver;

/**
 * Puts stub `ssh` and `mysqldump` executables first on PATH: the ssh stub runs
 * the remote command through a shell like a real server would, and the
 * mysqldump stub prints its arguments and the password it received.
 */
function stubRemoteBinaries(string $directory): void
{
    File::ensureDirectoryExists($directory);
    File::put($directory.'/ssh', "#!/bin/bash\necho \"\$@\" > \"\$(dirname \"\$0\")/ssh-args\"\nexec sh -c \"\${@: -1}\"\n");
    File::put($directory.'/mysqldump', "#!/bin/bash\necho \"PWD=\$MYSQL_PWD\"\nprintf '%s\\n' \"\$@\"\n");
    chmod($directory.'/ssh', 0755);
    chmod($directory.'/mysqldump', 0755);
}

it('streams a gzipped dump with arguments surviving both shells intact', function () {
    stubRemoteBinaries($bin = $this->workspace.'/bin');
    $target = $this->workspace."/out file's.sql.gz";

    $mysql = new MysqlDriver(
        ['host' => 'db.example.test', 'user' => 'deploy', 'port' => 2222, 'key' => '/keys/snap key'],
        ['database' => 'production', 'username' => 'reader', 'password' => " pa ss'\"\$word"],
        ['--single-transaction'],
        skipGtidPurged: false,
    );

    $command = $mysql->dumpTableCommand('event_logs', false, "`created_at` >= '2026-01-01 00:00:00'", $target);

    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => $bin.':'.getenv('PATH')]);
    fwrite($pipes[0], $mysql->remoteInput());
    fclose($pipes[0]);
    $exitCode = proc_close($process);

    expect($exitCode)->toBe(0)
        ->and(explode("\n", trim(gzdecode(File::get($target)))))->toBe([
            "PWD= pa ss'\"\$word",
            '--host=127.0.0.1',
            '--port=3306',
            '--user=reader',
            '--single-transaction',
            "--where=`created_at` >= '2026-01-01 00:00:00'",
            'production',
            'event_logs',
        ])
        ->and(File::get($bin.'/ssh-args'))->toStartWith('-p 2222 -i /keys/snap key deploy@db.example.test bash -c');
});

it('fails the dump when mysqldump fails on the server', function () {
    stubRemoteBinaries($bin = $this->workspace.'/bin');
    File::put($bin.'/mysqldump', "#!/bin/bash\necho 'Got error' >&2\nexit 2\n");

    $mysql = new MysqlDriver(['host' => 'db.example.test'], ['database' => 'production'], skipGtidPurged: false);
    $command = $mysql->dumpTableCommand('orders', false, null, $this->workspace.'/orders.sql.gz');

    exec('PATH='.escapeshellarg($bin.':'.getenv('PATH')).' '.$command.' 2>/dev/null', result_code: $exitCode);

    expect($exitCode)->not->toBe(0);
});

it('does not send a password line when none is configured', function () {
    $mysql = new MysqlDriver(['host' => 'db.example.test'], ['database' => 'production'], skipGtidPurged: false);

    expect($mysql->remoteInput())->toBe('')
        ->and($mysql->dumpTableCommand('orders', false, null, '/tmp/x.sql.gz'))->not->toContain('MYSQL_PWD');
});

it('rejects a database name that could break out of quoting', function () {
    (new MysqlDriver(['host' => 'db.example.test'], ['database' => 'prod`; drop'], skipGtidPurged: false))->dumpTableCommand('orders', false, null, '/tmp/x.sql.gz');
})->throws(InvalidArgumentException::class);

it('dumps only the structure for schema rules', function () {
    $mysql = new MysqlDriver(['host' => 'db.example.test'], ['database' => 'production'], skipGtidPurged: false);

    expect($mysql->dumpTableCommand('activities', true, null, '/tmp/x.sql.gz'))->toContain('--no-data');
});

it('quotes the column in recent conditions', function () {
    $mysql = new MysqlDriver([], ['database' => 'production']);

    expect($mysql->recentCondition('created_at', CarbonImmutable::parse('2026-02-28')))
        ->toBe("`created_at` >= '2026-02-28 00:00:00'");
});

it('uses the default port and user when none are configured', function () {
    stubRemoteBinaries($bin = $this->workspace.'/bin');
    $target = $this->workspace.'/orders.sql.gz';

    $command = (new MysqlDriver(['host' => 'db.example.test'], ['database' => 'production', 'port' => null, 'username' => null], skipGtidPurged: false))
        ->dumpTableCommand('orders', false, null, $target);
    exec('PATH='.escapeshellarg($bin.':'.getenv('PATH')).' '.$command, result_code: $exitCode);

    expect(gzdecode(File::get($target)))->toContain("--port=3306\n--user=root\n");
});

it('turns off GTID_PURGED only when the server runs MySQL\'s mysqldump', function (string $version, bool $expected) {
    Process::fake(['*mysqldump --version*' => Process::result($version)]);

    $command = (new MysqlDriver(['host' => 'db.example.test'], ['database' => 'production']))
        ->dumpTableCommand('orders', false, null, '/tmp/x.sql.gz');

    expect(str_contains($command, '--set-gtid-purged=OFF'))->toBe($expected);
})->with([
    'mysql' => ['mysqldump  Ver 8.4.8 for Linux on x86_64 (MySQL Community Server - GPL)', true],
    'mariadb' => ['mysqldump from 11.8.3-MariaDB, client 10.19 for debian-linux-gnu (aarch64)', false],
]);

it('checks the mysqldump flavour once per pull, not once per table', function () {
    Process::fake(['*' => Process::result('mysqldump  Ver 8.4.8')]);
    $mysql = new MysqlDriver(['host' => 'db.example.test'], ['database' => 'production']);

    $mysql->dumpTableCommand('orders', false, null, '/tmp/a.sql.gz');
    $mysql->dumpTableCommand('users', false, null, '/tmp/b.sql.gz');

    Process::assertRanTimes(fn ($process) => str_contains(commandLine($process), 'mysqldump --version'), 1);
});

it('lets the config force GTID_PURGED handling either way', function () {
    Process::fake();

    $forced = (new MysqlDriver(['host' => 'db.example.test'], ['database' => 'production'], skipGtidPurged: true))->dumpTableCommand('orders', false, null, '/tmp/x.sql.gz');
    $disabled = (new MysqlDriver(['host' => 'db.example.test'], ['database' => 'production'], skipGtidPurged: false))->dumpTableCommand('orders', false, null, '/tmp/x.sql.gz');

    expect($forced)->toContain('--set-gtid-purged=OFF')
        ->and($disabled)->not->toContain('--set-gtid-purged');
    Process::assertNothingRan();
});
