<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

it('pulls only the given tables with their profile rules and replaces only them', function () {
    prepareRefresh($this->workspace);

    $this->artisan('snapshot:refresh-table', ['tables' => ['event_logs', 'orders'], '--force' => true])
        ->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains($process->command, 'tables/event_logs.sql.gz') && str_contains($process->command, '--where=`created_at` >= '));
    Process::assertRan(fn ($process) => str_contains($process->command, 'tables/orders.sql.gz') && ! str_contains($process->command, '--where'));
    Process::assertNotRan(fn ($process) => str_contains(is_array($process->command) ? implode(' ', $process->command) : $process->command, 'users'));
    Process::assertNotRan(fn ($process) => str_contains(is_array($process->command) ? implode(' ', $process->command) : $process->command, 'DROP DATABASE'));
    Process::assertNotRan(fn ($process) => is_string($process->command) && (str_contains($process->command, '_views') || str_contains($process->command, '--routines')));
    Process::assertRan(fn ($process) => is_array($process->command) && str_contains(end($process->command), 'tables/event_logs.sql.gz') && str_contains(end($process->command), "'dev_app'"));
});

it('removes the pulled files afterwards and keeps them out of snapshot:restore', function () {
    prepareRefresh($this->workspace);

    $this->artisan('snapshot:refresh-table', ['tables' => ['orders'], '--force' => true])->assertSuccessful();
    expect(File::directories($this->workspace.'/snapshots'))->toBe([]);

    $this->artisan('snapshot:refresh-table', ['tables' => ['orders'], '--force' => true, '--keep' => true])->assertSuccessful();
    expect(File::directories($this->workspace.'/snapshots'))->toHaveCount(1);

    $this->artisan('snapshot:restore', ['--force' => true])
        ->expectsOutputToContain('No snapshot [latest]')
        ->assertFailed();
});

it('rejects tables that do not exist on the server', function () {
    prepareRefresh($this->workspace);

    $this->artisan('snapshot:refresh-table', ['tables' => ['orderz'], '--force' => true])
        ->expectsOutputToContain('Unknown tables: orderz')
        ->assertFailed();

    Process::assertNothingRan();
});

it('does not touch the database when the confirmation is declined', function () {
    prepareRefresh($this->workspace);

    $this->artisan('snapshot:refresh-table', ['tables' => ['orders']])
        ->expectsConfirmation('Replace orders in dev_app?', 'no')
        ->assertFailed();

    Process::assertNothingRan();
});
