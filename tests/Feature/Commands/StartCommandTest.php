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

it('checks the connection before saving, and saves typed settings on request when it fails', function () {
    Process::fake(['*' => Process::result(errorOutput: 'Permission denied (publickey).', exitCode: 255)]);
    config()->set('db-snapshot.ssh.host', null);
    config()->set('db-snapshot.remote.database', null);
    config()->set('db-snapshot.remote.password', null);
    app()->useEnvironmentPath($this->workspace);
    File::put($this->workspace.'/.env', "APP_NAME=App\n");
    $key = snapshotKey($this->workspace);

    $this->artisan('snapshot')
        ->expectsChoice('Database driver', 'mysql', ['mysql' => 'mysql', 'pgsql' => 'pgsql'])
        ->expectsQuestion('SSH host', '203.0.113.10')
        ->expectsQuestion('SSH user', 'root')
        ->expectsQuestion('SSH port', '22')
        ->expectsQuestion('Path to the SSH private key (as seen by this machine)', $key)
        ->expectsQuestion('Database host, as seen from the server', '127.0.0.1')
        ->expectsQuestion('Database port', '3306')
        ->expectsQuestion('Database user', 'root')
        ->expectsQuestion('Database to snapshot', 'production')
        ->expectsQuestion('Database password', 'pa ss')
        ->expectsOutputToContain('Permission denied (publickey).')
        ->expectsChoice('The connection failed. What now?', 'quit', ['edit' => 'Edit the settings', 'retry' => 'Try again', 'quit' => 'Quit'])
        ->expectsConfirmation("Save these settings to {$this->workspace}/.env anyway?", 'yes')
        ->assertFailed();

    expect(File::get($this->workspace.'/.env'))
        ->toContain("SNAPSHOT_DRIVER=mysql\n")
        ->toContain("SNAPSHOT_SSH_HOST=203.0.113.10\n")
        ->toContain("SNAPSHOT_REMOTE_DB_DATABASE=production\n")
        ->toContain("SNAPSHOT_REMOTE_DB_PASSWORD='pa ss'\n");

    Process::assertRan(fn ($process) => str_contains(commandLine($process), "'root@203.0.113.10'") && $process->input === "pa ss\n");
});

it('lets the settings be edited until the connection works, then saves them', function () {
    Process::fake(fn ($process) => str_contains(commandLine($process), 'wrong.example')
        ? Process::result(errorOutput: 'Could not resolve hostname', exitCode: 255)
        : Process::result('8.4.8'));
    config()->set('db-snapshot.ssh', ['host' => 'wrong.example', 'user' => 'deploy', 'port' => 22, 'key' => null]);
    config()->set('db-snapshot.remote.database', 'production');
    config()->set('db-snapshot.remote.password', 'secret');
    app()->useEnvironmentPath($this->workspace);
    File::put($this->workspace.'/.env', "APP_NAME=App\n");
    $key = snapshotKey($this->workspace);
    (new Profile('default'))->save($this->workspace.'/profiles');
    (new Analysis('production', now()->toImmutable(), []))->save($this->workspace.'/analysis.json');

    $this->artisan('snapshot')
        ->expectsOutputToContain('Could not resolve hostname')
        ->expectsChoice('The connection failed. What now?', 'edit', ['edit' => 'Edit the settings', 'retry' => 'Try again', 'quit' => 'Quit'])
        ->expectsChoice('Database driver', 'mysql', ['mysql' => 'mysql', 'pgsql' => 'pgsql'])
        ->expectsQuestion('SSH host', '203.0.113.10')
        ->expectsQuestion('SSH user', 'deploy')
        ->expectsQuestion('SSH port', '22')
        ->expectsQuestion('Path to the SSH private key (as seen by this machine)', $key)
        ->expectsQuestion('Database host, as seen from the server', '127.0.0.1')
        ->expectsQuestion('Database port', '3306')
        ->expectsQuestion('Database user', 'root')
        ->expectsQuestion('Database to snapshot', 'production')
        ->expectsConfirmation('Keep the current database password?', 'yes')
        ->expectsOutputToContain('Connected: mysql 8.4.8')
        ->expectsConfirmation("Save these settings to {$this->workspace}/.env?", 'yes')
        ->expectsChoice('Profile [default] exists. What now?', 'use', ['use' => 'Use it as it is', 'edit' => 'Edit it first'])
        ->expectsChoice('Profile [default] is ready. What now?', 'later', nextStepOptions())
        ->assertSuccessful();

    expect(File::get($this->workspace.'/.env'))
        ->toContain("SNAPSHOT_SSH_HOST=203.0.113.10\n")
        ->toContain("SNAPSHOT_SSH_KEY={$key}\n")
        ->not->toContain('SNAPSHOT_REMOTE_DB_PASSWORD');
    expect(config('db-snapshot.remote.password'))->toBe('secret');
});

it('checks the SSH key path while it is typed', function () {
    Process::fake();
    config()->set('db-snapshot.ssh.host', null);

    $this->artisan('snapshot')
        ->expectsChoice('Database driver', 'mysql', ['mysql' => 'mysql', 'pgsql' => 'pgsql'])
        ->expectsQuestion('SSH host', '203.0.113.10')
        ->expectsQuestion('SSH user', 'root')
        ->expectsQuestion('SSH port', '22')
        ->expectsQuestion('Path to the SSH private key (as seen by this machine)', '/nowhere/key')
        ->expectsOutputToContain('There is no file at /nowhere/key on this machine.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('pulls with an existing profile and stops before restoring when asked to', function () {
    Sleep::fake();
    Process::fake([
        '*SELECT VERSION()*' => Process::result('8.4.8'),
        '*information_schema.TABLES*' => Process::result("orders\tBASE TABLE\t10\t1000\t0"),
        '*mysqldump*' => Process::result(),
    ]);
    config()->set('database.connections.target', ['driver' => 'mysql', 'database' => 'dev_app']);
    config()->set('db-snapshot.connection', 'target');
    (new Profile('default'))->save($this->workspace.'/profiles');
    (new Analysis('production', now()->subMonths(2)->toImmutable(), []))->save($this->workspace.'/analysis.json');

    // The old analysis is asked about once, not again by snapshot:pull.
    $this->artisan('snapshot')
        ->expectsOutputToContain('Connected: mysql 8.4.8')
        ->expectsChoice('The analysis of the remote database is from 2 months ago. Refresh it?', 'continue', [
            'analyze' => 'Analyze now',
            'continue' => 'Continue with the current analysis',
        ])
        ->expectsChoice('Profile [default] exists. What now?', 'use', ['use' => 'Use it as it is', 'edit' => 'Edit it first'])
        ->expectsChoice('Profile [default] is ready. What now?', 'pull', nextStepOptions())
        ->expectsOutputToContain('Restore later with: php artisan snapshot:restore latest --profile=default')
        ->assertSuccessful();

    expect(app(SnapshotRepository::class)->find('latest', 'default')->manifest['tables'])->toHaveKey('orders');
    Process::assertNotRan(fn ($process) => is_array($process->command));
});

it('pulls and restores with the questions of snapshot:refresh', function () {
    prepareRefresh($this->workspace);
    Process::fake([
        '*SELECT VERSION()*' => Process::result('8.4.8'),
        '*information_schema.TABLES*' => Process::result("orders\tBASE TABLE\t10\t1000\t0"),
        '*mysqldump*' => function ($process) {
            if (preg_match("/> '([^']+)'$/", $process->command, $target)) {
                File::put($target[1], gzencode('-- dump'));
            }

            return Process::result();
        },
        '*' => Process::result(),
    ]);

    $this->artisan('snapshot', ['--profile' => 'default'])
        ->expectsChoice('Profile [default] exists. What now?', 'use', ['use' => 'Use it as it is', 'edit' => 'Edit it first'])
        ->expectsChoice('Profile [default] is ready. What now?', 'restore', nextStepOptions())
        ->expectsChoice('Restore into which database?', 'app', [
            'app' => "dev_app (the app's database)",
            'new' => 'production_'.now()->format('Y_m_d').' (a database for this snapshot)',
            'other' => 'Another name…',
        ])
        ->expectsConfirmation('Pull [default] and drop and recreate dev_app with it?', 'yes')
        ->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(commandLine($process), 'DROP DATABASE IF EXISTS `dev_app`'));
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
        ->expectsChoice('The connection failed. What now?', 'quit', ['edit' => 'Edit the settings', 'retry' => 'Try again', 'quit' => 'Quit'])
        ->expectsConfirmation("Save these settings to {$this->workspace}/.env anyway?", 'no')
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
        ->expectsQuestion('Any other tables that need their own rule? (type to search, Enter for none)', 'orders')
        ->expectsChoice('Any other tables that need their own rule? (type to search, Enter for none)', ['orders'], ['orders' => 'orders · 1000 B · ~10 rows'])
        ->expectsChoice('orders (1000 B, ~10 rows)', 'full', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Where, TableMode::Skip]))
        ->expectsConfirmation('Save profile [default]?', 'yes')
        ->expectsChoice('Profile [default] is ready. What now?', 'later', nextStepOptions())
        ->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(commandLine($process), 'information_schema.COLUMNS'));
});

function snapshotKey(string $workspace): string
{
    File::put($key = $workspace.'/snapshot_key', 'key');
    chmod($key, 0600);

    return $key;
}

/**
 * @return array<string, string>
 */
function nextStepOptions(): array
{
    return [
        'restore' => 'Pull a snapshot and restore it',
        'pull' => 'Only pull a snapshot',
        'later' => 'Nothing for now',
    ];
}

it('replaces the database password, or clears it to use the server user\'s client config', function () {
    Process::fake(['*' => Process::result(errorOutput: 'Access denied', exitCode: 1)]);
    config()->set('db-snapshot.ssh', ['host' => '203.0.113.10', 'user' => 'deploy', 'port' => 22, 'key' => null]);
    config()->set('db-snapshot.remote.database', 'production');
    config()->set('db-snapshot.remote.password', 'wrong');
    app()->useEnvironmentPath($this->workspace);
    File::put($this->workspace.'/.env', "SNAPSHOT_REMOTE_DB_PASSWORD=wrong\n");

    $this->artisan('snapshot')
        ->expectsChoice('The connection failed. What now?', 'edit', ['edit' => 'Edit the settings', 'retry' => 'Try again', 'quit' => 'Quit'])
        ->expectsChoice('Database driver', 'mysql', ['mysql' => 'mysql', 'pgsql' => 'pgsql'])
        ->expectsQuestion('SSH host', '203.0.113.10')
        ->expectsQuestion('SSH user', 'deploy')
        ->expectsQuestion('SSH port', '22')
        ->expectsQuestion('Path to the SSH private key (as seen by this machine)', '')
        ->expectsQuestion('Database host, as seen from the server', '127.0.0.1')
        ->expectsQuestion('Database port', '3306')
        ->expectsQuestion('Database user', 'root')
        ->expectsQuestion('Database to snapshot', 'production')
        ->expectsConfirmation('Keep the current database password?', 'no')
        ->expectsQuestion('Database password', '')
        ->expectsChoice('The connection failed. What now?', 'quit', ['edit' => 'Edit the settings', 'retry' => 'Try again', 'quit' => 'Quit'])
        ->expectsConfirmation("Save these settings to {$this->workspace}/.env anyway?", 'yes')
        ->assertFailed();

    expect(File::get($this->workspace.'/.env'))->toContain("SNAPSHOT_REMOTE_DB_PASSWORD=\n");
});
