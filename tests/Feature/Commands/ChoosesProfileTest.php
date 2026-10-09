<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Overthink\DbSnapshot\Analysis\Analysis;
use Overthink\DbSnapshot\Analysis\TableInfo;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Profile\TableRule;
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
        ->expectsChoice('Which profile?', 'nightly', ['default' => 'default · 0 rules · never pulled', 'nightly' => 'nightly · 0 rules · never pulled'])
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
        ->expectsChoice('Which profile?', "\0new", ['default' => 'default · 0 rules · never pulled', 'nightly' => 'nightly · 0 rules · never pulled', "\0new" => 'New profile…', "\0copy" => 'Copy a profile…'])
        ->expectsQuestion('Name of the new profile', 'weekly')
        ->expectsQuestion('Any other tables to filter, empty or skip? (type to search, Enter for none)', 'orders')
        ->expectsChoice('Any other tables to filter, empty or skip? (type to search, Enter for none)', ['orders'], ['orders' => 'orders · 1000 B · ~10 rows'])
        ->expectsChoice('orders (1000 B, ~10 rows)', 'schema', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Where, TableMode::Skip]))
        ->expectsConfirmation('Save profile [weekly]?', 'yes')
        ->assertSuccessful();

    expect(Profile::load($this->workspace.'/profiles', 'weekly')->ruleFor('orders')->mode)->toBe(TableMode::Schema);
});

it('asks which profile to edit even when there is only one', function () {
    prepareProfiles($this->workspace, ['default']);
    app()->useDatabasePath($this->workspace.'/database');

    $this->artisan('snapshot:configure')
        ->expectsChoice('Which profile?', "\0copy", ['default' => 'default · 0 rules · never pulled', "\0new" => 'New profile…', "\0copy" => 'Copy a profile…'])
        ->expectsQuestion('Name of the new profile', 'default-copy')
        ->expectsOutputToContain('Starting from a copy of [default]')
        ->expectsQuestion('Any other tables to filter, empty or skip? (type to search, Enter for none)', 'orders')
        ->expectsChoice('Any other tables to filter, empty or skip? (type to search, Enter for none)', ['orders'], ['orders' => 'orders · 1000 B · ~10 rows'])
        ->expectsChoice('orders (1000 B, ~10 rows)', 'full', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Where, TableMode::Skip]))
        ->expectsConfirmation('Save profile [default-copy]?', 'yes')
        ->assertSuccessful();

    expect(Profile::names($this->workspace.'/profiles'))->toBe(['default', 'default-copy']);
});

it('shows rules, anonymized columns and the last pull per profile', function () {
    prepareProfiles($this->workspace, ['nightly']);
    config()->set('db-snapshot.anonymize.enabled', false);
    (new Profile('default', tables: ['orders' => TableRule::fromArray(['mode' => 'full', 'anonymize' => ['email' => 'email', 'name' => 'null']])]))->save($this->workspace.'/profiles');
    makeSnapshot($this->workspace.'/snapshots/2026-10-08_080000_default', ['orders' => 1], createdAt: now()->subHours(2)->toIso8601String());

    $this->artisan('snapshot:pull')
        ->expectsChoice('Which profile?', 'default', [
            'default' => 'default · 1 rule · anonymizes 2 columns · pulled 2 hours ago',
            'nightly' => 'nightly · 0 rules · never pulled',
        ])
        ->assertSuccessful();
});

it('lets you search the profiles when there are many', function () {
    $names = array_map(fn (int $i): string => "client{$i}", range(1, 8));
    prepareProfiles($this->workspace, $names);

    $this->artisan('snapshot:pull')
        ->expectsQuestion('Which profile?', 'client7')
        ->expectsChoice('Which profile?', 'client7', ['client7' => 'client7 · 0 rules · never pulled'])
        ->assertSuccessful();

    expect(app(SnapshotRepository::class)->find()->profile())->toBe('client7');
});
