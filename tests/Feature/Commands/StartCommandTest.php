<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Overthink\DbSnapshot\Analysis\Analysis;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Snapshot\SnapshotRepository;

it('explains which settings are missing when not interactive', function () {
    Process::fake();
    config()->set('db-snapshot.ssh.host', null);

    $this->artisan('snapshot', ['--no-interaction' => true])
        ->expectsOutputToContain('Set SNAPSHOT_SSH_HOST and SNAPSHOT_REMOTE_DB_DATABASE')
        ->assertFailed();

    Process::assertNothingRan();
});

it('asks for missing settings, saves them to .env and checks the connection', function () {
    Process::fake(['*' => Process::result(errorOutput: 'Permission denied (publickey).', exitCode: 255)]);
    config()->set('db-snapshot.ssh.host', null);
    config()->set('db-snapshot.remote.database', null);
    app()->useEnvironmentPath($this->workspace);
    File::put($this->workspace.'/.env', "APP_NAME=App\n");

    $this->artisan('snapshot')
        ->expectsQuestion('SSH host', '203.0.113.10')
        ->expectsQuestion('SSH user', 'root')
        ->expectsQuestion('SSH port', '22')
        ->expectsQuestion('Path to the SSH private key (as seen by this machine)', '/keys/id')
        ->expectsQuestion('MySQL host, as seen from the server', '127.0.0.1')
        ->expectsQuestion('MySQL port', '3306')
        ->expectsQuestion('MySQL user', 'root')
        ->expectsQuestion('Database to snapshot', 'production')
        ->expectsQuestion('MySQL password', 'pa ss')
        ->expectsConfirmation("Save these settings to {$this->workspace}/.env?", 'yes')
        ->expectsOutputToContain('Permission denied (publickey).')
        ->assertFailed();

    expect(File::get($this->workspace.'/.env'))
        ->toContain("SNAPSHOT_SSH_HOST=203.0.113.10\n")
        ->toContain("SNAPSHOT_REMOTE_DB_DATABASE=production\n")
        ->toContain("SNAPSHOT_REMOTE_DB_PASSWORD='pa ss'\n");

    Process::assertRan(fn ($process) => str_contains(commandLine($process), "'root@203.0.113.10'") && $process->input === "pa ss\n");
});

it('pulls with an existing profile and stops before restoring when declined', function () {
    Sleep::fake();
    Process::fake([
        '*SELECT VERSION()*' => Process::result('8.4.8'),
        '*information_schema.TABLES*' => Process::result("orders\tBASE TABLE\t10\t1000\t0"),
        '*mysqldump*' => Process::result(),
    ]);
    config()->set('database.connections.target', ['driver' => 'mysql', 'database' => 'dev_app']);
    config()->set('db-snapshot.connection', 'target');
    (new Profile('default'))->save($this->workspace.'/profiles');
    (new Analysis('production', now()->toImmutable(), []))->save($this->workspace.'/analysis.json');

    $this->artisan('snapshot')
        ->expectsOutputToContain('Connected: MySQL 8.4.8')
        ->expectsChoice('Profile [default] exists. What now?', 'use', ['use' => 'Use it as it is', 'edit' => 'Edit it first'])
        ->expectsConfirmation('Pull a snapshot with profile [default] now?', 'yes')
        ->expectsConfirmation('Restore it into dev_app now? This drops and recreates the database.', 'no')
        ->assertSuccessful();

    expect(app(SnapshotRepository::class)->find('latest', 'default')->manifest['tables'])->toHaveKey('orders');
    Process::assertNotRan(fn ($process) => is_array($process->command));
});
