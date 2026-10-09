<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Overthink\DbSnapshot\Analysis\Analysis;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;
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
        ->expectsChoice('Database driver', 'mysql', ['mysql' => 'mysql', 'pgsql' => 'pgsql'])
        ->expectsQuestion('SSH host', '203.0.113.10')
        ->expectsQuestion('SSH user', 'root')
        ->expectsQuestion('SSH port', '22')
        ->expectsQuestion('Path to the SSH private key (as seen by this machine)', '/keys/id')
        ->expectsQuestion('Database host, as seen from the server', '127.0.0.1')
        ->expectsQuestion('Database port', '3306')
        ->expectsQuestion('Database user', 'root')
        ->expectsQuestion('Database to snapshot', 'production')
        ->expectsQuestion('Database password', 'pa ss')
        ->expectsConfirmation("Save these settings to {$this->workspace}/.env?", 'yes')
        ->expectsOutputToContain('Permission denied (publickey).')
        ->assertFailed();

    expect(File::get($this->workspace.'/.env'))
        ->toContain("SNAPSHOT_DRIVER=mysql\n")
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
        ->expectsOutputToContain('Connected: mysql 8.4.8')
        ->expectsChoice('Profile [default] exists. What now?', 'use', ['use' => 'Use it as it is', 'edit' => 'Edit it first'])
        ->expectsConfirmation('Pull a snapshot with profile [default] now?', 'yes')
        ->expectsConfirmation('Restore it into dev_app now? This drops and recreates the database.', 'no')
        ->assertSuccessful();

    expect(app(SnapshotRepository::class)->find('latest', 'default')->manifest['tables'])->toHaveKey('orders');
    Process::assertNotRan(fn ($process) => is_array($process->command));
});

it('offers the chosen driver\'s default port and user', function () {
    Process::fake(['*' => Process::result(errorOutput: 'Connection refused', exitCode: 255)]);
    config()->set('db-snapshot.ssh.host', null);
    config()->set('db-snapshot.remote', ['database' => null]);
    app()->useEnvironmentPath($this->workspace);

    $this->artisan('snapshot')
        ->expectsChoice('Database driver', 'pgsql', ['mysql' => 'mysql', 'pgsql' => 'pgsql'])
        ->expectsQuestion('SSH host', '203.0.113.10')
        ->expectsQuestion('SSH user', 'root')
        ->expectsQuestion('SSH port', '22')
        ->expectsQuestion('Path to the SSH private key (as seen by this machine)', '')
        ->expectsQuestion('Database host, as seen from the server', '127.0.0.1')
        ->expectsQuestion('Database port', '5432')
        ->expectsQuestion('Database user', 'postgres')
        ->expectsQuestion('Database to snapshot', 'production')
        ->expectsQuestion('Database password', '')
        ->expectsConfirmation("Save these settings to {$this->workspace}/.env?", 'no')
        ->assertFailed();

    Process::assertRan(fn ($process) => str_contains(commandLine($process), 'psql') && str_contains(commandLine($process), '--port=5432'));
});

it('re-analyzes and opens the profile editor with --fresh, keeping the connection settings', function () {
    Process::fake([
        '*SELECT VERSION()*' => Process::result('8.4.8'),
        '*information_schema.TABLES*' => Process::result("orders\tBASE TABLE\t10\t1000\t0"),
        '*' => Process::result(),
    ]);
    app()->useDatabasePath($this->workspace.'/database');
    (new Profile('default'))->save($this->workspace.'/profiles');
    (new Analysis('production', now()->toImmutable(), []))->save($this->workspace.'/analysis.json');

    $this->artisan('snapshot', ['--fresh' => true])
        ->expectsOutputToContain('Connected: mysql 8.4.8')
        ->expectsQuestion('Any other tables to filter, empty or skip? (type to search, Enter for none)', 'orders')
        ->expectsChoice('Any other tables to filter, empty or skip? (type to search, Enter for none)', ['orders'], ['orders' => 'orders · 1000 B · ~10 rows'])
        ->expectsChoice('orders (1000 B, ~10 rows)', 'full', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Where, TableMode::Skip]))
        ->expectsQuestion('Save as profile', 'default')
        ->expectsConfirmation('Pull a snapshot with profile [default] now?', 'no')
        ->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(commandLine($process), 'information_schema.COLUMNS'));
});
