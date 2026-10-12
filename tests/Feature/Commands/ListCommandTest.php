<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

const LIST_ACTIONS = ['restore' => 'Restore it', 'delete' => 'Delete it', 'back' => 'Back to the list'];

/**
 * The picker's options for snapshots of these October days (pulled at 08:00, listed on Oct 3).
 *
 * @return array<string, string>
 */
function listedOptions(int ...$days): array
{
    $options = [];

    foreach ($days as $day) {
        $name = sprintf('2026-10-%02d_080000_default', $day);
        $options[$name] = (3 - $day === 1 ? '1 day ago' : (3 - $day).' days ago')." · default · 1 B · {$name}";
    }

    return $options + ["\0done" => 'Done'];
}

function prepareListedSnapshots(string $workspace, int $count = 2): void
{
    test()->travelTo('2026-10-03 08:00:00');

    foreach (range(1, $count) as $day) {
        $name = sprintf('2026-10-%02d_080000_default', $day);
        makeSnapshot("{$workspace}/snapshots/{$name}", ['orders' => 1], createdAt: sprintf('2026-10-%02dT08:00:00+00:00', $day));
    }
}

it('prints a table when not interactive', function () {
    prepareListedSnapshots($this->workspace);

    $this->artisan('snapshot:list', ['--no-interaction' => true])
        ->expectsOutputToContain('2026-10-02_080000_default')
        ->assertSuccessful();
});

it('restores the chosen snapshot', function () {
    Sleep::fake();
    Process::fake();
    config()->set('database.connections.target', ['driver' => 'mysql', 'host' => 'mysql', 'port' => 3306, 'username' => 'root', 'password' => '', 'database' => 'dev_wt']);
    config()->set('db-snapshot.connection', 'target');
    prepareListedSnapshots($this->workspace);

    $this->artisan('snapshot:list')
        ->expectsChoice('Which snapshot?', '2026-10-01_080000_default', [
            '2026-10-02_080000_default' => '1 day ago · default · 1 B · 2026-10-02_080000_default',
            '2026-10-01_080000_default' => '2 days ago · default · 1 B · 2026-10-01_080000_default',
            "\0done" => 'Done',
        ])
        ->expectsChoice('What should happen with 2026-10-01_080000_default?', 'restore', LIST_ACTIONS)
        ->expectsChoice('Restore into which database?', 'app', [
            'app' => "dev_wt (the app's database)",
            'new' => 'production_2026_10_01 (a database for this snapshot)',
            'other' => 'Another name…',
        ])
        ->expectsConfirmation('Drop and recreate dev_wt on mysql:3306?', 'yes')
        ->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(commandLine($process), '/snapshots/2026-10-01_080000_default/tables/orders.sql.gz'));
});

it('deletes the chosen snapshot after confirming, then offers the rest', function () {
    prepareListedSnapshots($this->workspace);

    $this->artisan('snapshot:list')
        ->expectsChoice('Which snapshot?', '2026-10-01_080000_default', listedOptions(2, 1))
        ->expectsChoice('What should happen with 2026-10-01_080000_default?', 'delete', LIST_ACTIONS)
        ->expectsConfirmation('Delete 2026-10-01_080000_default (1 B)?', 'yes')
        ->expectsChoice('Which snapshot?', "\0done", listedOptions(2))
        ->assertSuccessful();

    expect(File::isDirectory($this->workspace.'/snapshots/2026-10-01_080000_default'))->toBeFalse()
        ->and(File::isDirectory($this->workspace.'/snapshots/2026-10-02_080000_default'))->toBeTrue();
});

it('keeps the snapshot when the deletion is declined', function () {
    prepareListedSnapshots($this->workspace, 1);

    $this->artisan('snapshot:list')
        ->expectsChoice('Which snapshot?', '2026-10-01_080000_default', listedOptions(1))
        ->expectsChoice('What should happen with 2026-10-01_080000_default?', 'delete', LIST_ACTIONS)
        ->expectsConfirmation('Delete 2026-10-01_080000_default (1 B)?', 'no')
        ->expectsChoice('Which snapshot?', "\0done", listedOptions(1))
        ->assertSuccessful();

    expect(File::isDirectory($this->workspace.'/snapshots/2026-10-01_080000_default'))->toBeTrue();
});

it('lets you search the snapshots when there are six or more', function () {
    prepareListedSnapshots($this->workspace, 6);
    $this->travelTo('2026-10-05 08:00:00');

    $this->artisan('snapshot:list')
        ->expectsQuestion('Which snapshot?', '10-04')
        ->expectsChoice('Which snapshot?', '2026-10-04_080000_default', ['2026-10-04_080000_default' => '1 day ago · default · 1 B · 2026-10-04_080000_default'])
        ->expectsChoice('What should happen with 2026-10-04_080000_default?', 'back', LIST_ACTIONS)
        ->expectsQuestion('Which snapshot?', 'done')
        ->expectsChoice('Which snapshot?', "\0done", ["\0done" => 'Done'])
        ->assertSuccessful();
});
