<?php

use Illuminate\Support\Facades\File;
use Overthink\DbSnapshot\Remote\RemoteMysql;
use Overthink\DbSnapshot\Remote\SshConnection;

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

    $mysql = new RemoteMysql(
        new SshConnection('db.example.test', 'deploy', 2222, '/keys/snap key'),
        'production',
        username: 'reader',
        password: " pa ss'\"\$word",
        dumpOptions: ['--single-transaction'],
    );

    $command = $mysql->dumpCommand(['event_logs'], ["--where=`created_at` >= '2026-01-01 00:00:00'"], $target);

    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => $bin.':'.getenv('PATH')]);
    fwrite($pipes[0], $mysql->stdin());
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

    $mysql = new RemoteMysql(new SshConnection('db.example.test'), 'production');
    $command = $mysql->dumpCommand(['orders'], [], $this->workspace.'/orders.sql.gz');

    exec('PATH='.escapeshellarg($bin.':'.getenv('PATH')).' '.$command.' 2>/dev/null', result_code: $exitCode);

    expect($exitCode)->not->toBe(0);
});

it('does not send a password line when none is configured', function () {
    $mysql = new RemoteMysql(new SshConnection('db.example.test'), 'production');

    expect($mysql->stdin())->toBe('')
        ->and($mysql->dumpCommand(['orders'], [], '/tmp/x.sql.gz'))->not->toContain('MYSQL_PWD');
});

it('rejects a database name that could break out of quoting', function () {
    new RemoteMysql(new SshConnection('db.example.test'), 'prod`; drop');
})->throws(InvalidArgumentException::class);
