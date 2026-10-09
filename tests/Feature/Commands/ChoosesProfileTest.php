<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Overthink\DbSnapshot\Analysis\Analysis;
use Overthink\DbSnapshot\Analysis\TableInfo;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Snapshot\SnapshotRepository;

function prepareProfiles(string $workspace, array $names): void
{
    Sleep::fake();
    Process::fake([
        '*information_schema.TABLES*' => Process::result("orders\tBASE TABLE\t10\t1000\t0"),
        '*' => Process::result(),
    ]);

    foreach ($names as $name) {
        (new Profile($name))->save($workspace.'/profiles');
    }

    (new Analysis('production', now()->toImmutable(), [
        'orders' => new TableInfo('orders', false, 10, 1000, 0),
    ]))->save($workspace.'/analysis.json');
}

it('asks which profile to pull when there are several, with default preselected', function () {
    prepareProfiles($this->workspace, ['default', 'nightly']);

    $this->artisan('snapshot:pull')
        ->expectsChoice('Which profile?', 'nightly', ['default' => 'default', 'nightly' => 'nightly'])
        ->assertSuccessful();

    expect(app(SnapshotRepository::class)->find()->profile())->toBe('nightly');
});

it('uses the only profile without asking', function () {
    prepareProfiles($this->workspace, ['nightly']);

    $this->artisan('snapshot:pull')->assertSuccessful();

    expect(app(SnapshotRepository::class)->find()->profile())->toBe('nightly');
});

it('uses default without asking when not interactive', function () {
    prepareProfiles($this->workspace, ['default', 'nightly']);

    $this->artisan('snapshot:pull', ['--no-interaction' => true])->assertSuccessful();

    expect(app(SnapshotRepository::class)->find()->profile())->toBe('default');
});

it('offers a new profile where profiles are made', function () {
    prepareProfiles($this->workspace, ['default', 'nightly']);
    app()->useDatabasePath($this->workspace.'/database');

    $this->artisan('snapshot:configure')
        ->expectsChoice('Which profile?', "\0new", ['default' => 'default', 'nightly' => 'nightly', "\0new" => 'New profile…'])
        ->expectsQuestion('Name of the new profile', 'weekly')
        ->expectsQuestion('Any other tables to filter, empty or skip? (type to search, Enter for none)', 'orders')
        ->expectsChoice('Any other tables to filter, empty or skip? (type to search, Enter for none)', ['orders'], ['orders' => 'orders · 1000 B · ~10 rows'])
        ->expectsChoice('orders (1000 B, ~10 rows)', 'schema', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Where, TableMode::Skip]))
        ->expectsQuestion('Save as profile', 'weekly')
        ->assertSuccessful();

    expect(Profile::load($this->workspace.'/profiles', 'weekly')->ruleFor('orders')->mode)->toBe(TableMode::Schema);
});
