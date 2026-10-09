<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Overthink\DbSnapshot\Analysis\Analysis;
use Overthink\DbSnapshot\Analysis\DateColumn;
use Overthink\DbSnapshot\Analysis\TableInfo;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;

function prepareConfigure(string $workspace): void
{
    Process::fake();
    $mb = 1024 * 1024;

    (new Analysis('production', now()->toImmutable(), [
        'event_logs' => new TableInfo('event_logs', false, 1000, 500 * $mb, 0, [new DateColumn('created_at', 'timestamp', false)]),
        'users' => new TableInfo('users', false, 10, 1 * $mb, 0),
    ]))->save($workspace.'/analysis.json');

    app()->useDatabasePath($workspace.'/database');
}

function configureEventLogs($command, string $saveAs)
{
    return $command
        ->expectsChoice('Large tables that should NOT be copied in full', ['event_logs'], ['event_logs' => 'event_logs · 500 MB · ~1.0K rows'])
        ->expectsQuestion('Any smaller tables to filter, empty or skip? (type to search, Enter for none)', 'users')
        ->expectsChoice('Any smaller tables to filter, empty or skip? (type to search, Enter for none)', ['users'], ['users' => 'users · 1.0 MB · ~10 rows'])
        ->expectsChoice('event_logs (500 MB, ~1.0K rows)', 'recent', modeOptions(TableMode::cases()))
        ->expectsChoice('How much of event_logs?', '3', [
            '1' => 'Last 1 months', '3' => 'Last 3 months', '6' => 'Last 6 months', '12' => 'Last 12 months', '24' => 'Last 24 months', 'since' => 'Since a fixed date…',
        ])
        ->expectsChoice('users (1.0 MB, ~10 rows)', 'schema', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Where, TableMode::Skip]))
        ->expectsQuestion('Save as profile', $saveAs);
}

it('saves under the name given at the end and offers an index migration', function () {
    prepareConfigure($this->workspace);

    configureEventLogs($this->artisan('snapshot:configure'), 'nightly')
        ->expectsConfirmation('Generate a migration that adds these indexes?', 'yes')
        ->assertSuccessful();

    expect(Profile::load($this->workspace.'/profiles', 'nightly')->ruleFor('event_logs')->describe())->toBe('last 3 months by created_at')
        ->and(Profile::load($this->workspace.'/profiles', 'nightly')->ruleFor('users')->describe())->toBe('schema')
        ->and(File::exists($this->workspace.'/profiles/default.json'))->toBeFalse()
        ->and(File::glob($this->workspace.'/database/migrations/*_add_snapshot_date_indexes.php'))->toHaveCount(1);
});

it('does not overwrite another existing profile without asking', function () {
    prepareConfigure($this->workspace);
    File::ensureDirectoryExists($this->workspace.'/profiles');
    File::put($this->workspace.'/profiles/nightly.json', '{"default_mode":"schema","tables":{}}');

    configureEventLogs($this->artisan('snapshot:configure'), 'nightly')
        ->expectsConfirmation('Profile [nightly] already exists. Overwrite it?', 'no')
        ->assertFailed();

    expect(File::get($this->workspace.'/profiles/nightly.json'))->toBe('{"default_mode":"schema","tables":{}}');
});

it('skips the large tables question when no table is large', function () {
    Process::fake();
    app()->useDatabasePath($this->workspace.'/database');
    (new Analysis('production', now()->toImmutable(), [
        'users' => new TableInfo('users', false, 10, 1024 * 1024, 0),
    ]))->save($this->workspace.'/analysis.json');

    $this->artisan('snapshot:configure')
        ->expectsOutputToContain('No table is 100 MB or larger.')
        ->expectsQuestion('Any smaller tables to filter, empty or skip? (type to search, Enter for none)', 'users')
        ->expectsChoice('Any smaller tables to filter, empty or skip? (type to search, Enter for none)', ['users'], ['users' => 'users · 1.0 MB · ~10 rows'])
        ->expectsChoice('users (1.0 MB, ~10 rows)', 'schema', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Where, TableMode::Skip]))
        ->expectsQuestion('Save as profile', 'default')
        ->assertSuccessful();

    expect(Profile::load($this->workspace.'/profiles', 'default')->ruleFor('users')->describe())->toBe('schema');
});
