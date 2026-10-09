<?php

use Illuminate\Support\Facades\Process;

it('refuses to restore in production', function () {
    Process::fake();
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('snapshot:restore', ['--force' => true])
        ->expectsOutputToContain('never runs in production')
        ->assertFailed();

    Process::assertNothingRan();
});

it('does not drop the database when the confirmation is declined', function () {
    Process::fake();
    config()->set('database.connections.target', ['driver' => 'mysql', 'host' => 'mysql', 'port' => 3306, 'username' => 'root', 'password' => '', 'database' => 'dev_wt']);
    config()->set('db-snapshot.connection', 'target');
    makeSnapshot($this->workspace.'/snapshots/2026-10-08_120000_default', ['orders' => 1]);

    $this->artisan('snapshot:restore')
        ->expectsConfirmation('Drop and recreate dev_wt on mysql:3306?', 'no')
        ->assertFailed();

    Process::assertNothingRan();
});
