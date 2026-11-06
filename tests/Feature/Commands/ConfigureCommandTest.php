<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Overthink\DbSnapshot\Analysis\Analysis;
use Overthink\DbSnapshot\Analysis\ColumnInfo;
use Overthink\DbSnapshot\Analysis\DateColumn;
use Overthink\DbSnapshot\Analysis\Relation;
use Overthink\DbSnapshot\Analysis\TableInfo;
use Overthink\DbSnapshot\Profile\AnonymizeStrategy;
use Overthink\DbSnapshot\Profile\ColumnRule;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Profile\TableRule;

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
        ->expectsQuestion('Which tables need their own rule?', 's')
        ->expectsChoice('Which tables need their own rule?', ['event_logs', 'users'], ['event_logs' => 'event_logs · 500 MB · ~1.0K rows', 'users' => 'users · 1.0 MB · ~10 rows'])
        ->expectsChoice('event_logs (500 MB, ~1.0K rows)', 'recent', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Recent, TableMode::Where, TableMode::Skip]))
        ->expectsChoice('How much of event_logs?', '3', [
            '1' => 'Last 1 months', '3' => 'Last 3 months', '6' => 'Last 6 months', '12' => 'Last 12 months', '24' => 'Last 24 months', 'since' => 'Since a fixed date…',
        ])
        ->expectsChoice('users (1.0 MB, ~10 rows)', 'schema', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Where, TableMode::Skip]))
        ->expectsConfirmation("Save profile [{$saveAs}]?", 'yes');
}

it('saves the profile it edits and offers an index migration', function () {
    prepareConfigure($this->workspace);

    configureEventLogs($this->artisan('snapshot:configure', ['profile' => 'nightly']), 'nightly')
        ->expectsConfirmation('Generate a migration that adds these indexes?', 'yes')
        ->assertSuccessful();

    expect(Profile::load($this->workspace.'/profiles', 'nightly')->ruleFor('event_logs')->describe())->toBe('last 3 months by created_at')
        ->and(Profile::load($this->workspace.'/profiles', 'nightly')->ruleFor('users')->describe())->toBe('schema')
        ->and(File::exists($this->workspace.'/profiles/default.json'))->toBeFalse()
        ->and(File::glob($this->workspace.'/database/migrations/*_add_snapshot_indexes.php'))->toHaveCount(1);
});

it('edits a copy and leaves the original profile alone', function () {
    prepareConfigure($this->workspace);
    File::ensureDirectoryExists($this->workspace.'/profiles');
    File::put($this->workspace.'/profiles/nightly.json', '{"default_mode":"schema","tables":{}}');

    configureEventLogs($this->artisan('snapshot:configure', ['profile' => 'weekly', '--from' => 'nightly']), 'weekly')
        ->expectsOutputToContain('Starting from a copy of [nightly]')
        ->expectsConfirmation('Generate a migration that adds these indexes?', 'no')
        ->assertSuccessful();

    $copy = Profile::load($this->workspace.'/profiles', 'weekly');

    expect($copy->defaultMode)->toBe(TableMode::Schema)
        ->and($copy->ruleFor('event_logs')->mode)->toBe(TableMode::Recent)
        ->and(File::get($this->workspace.'/profiles/nightly.json'))->toBe('{"default_mode":"schema","tables":{}}');
});

it('lists every table before typing when no table is large', function () {
    Process::fake();
    app()->useDatabasePath($this->workspace.'/database');
    (new Analysis('production', now()->toImmutable(), [
        'users' => new TableInfo('users', false, 10, 1024 * 1024, 0),
    ]))->save($this->workspace.'/analysis.json');

    $this->artisan('snapshot:configure')
        ->expectsQuestion('Which tables need their own rule?', '')
        ->expectsChoice('Which tables need their own rule?', ['users'], ['users' => 'users · 1.0 MB · ~10 rows'])
        ->expectsChoice('users (1.0 MB, ~10 rows)', 'schema', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Where, TableMode::Skip]))
        ->expectsConfirmation('Save profile [default]?', 'yes')
        ->assertSuccessful();

    expect(Profile::load($this->workspace.'/profiles', 'default')->ruleFor('users')->describe())->toBe('schema');
});

it('suggests personal data columns to anonymize and saves the choice', function () {
    Process::fake();
    app()->useDatabasePath($this->workspace.'/database');
    (new Analysis('production', now()->toImmutable(), [
        'orders' => new TableInfo('orders', false, 10, 1024, 0, columns: [
            new ColumnInfo('id', 'int', primaryKey: true, nullable: false),
            new ColumnInfo('number', 'varchar', ColumnInfo::TEXT, 20, nullable: false),
            new ColumnInfo('customer_email', 'varchar', ColumnInfo::TEXT, 191),
            new ColumnInfo('customer_name', 'varchar', ColumnInfo::TEXT, 191),
        ]),
    ]))->save($this->workspace.'/analysis.json');

    $this->artisan('snapshot:configure')
        ->expectsQuestion('Which tables need their own rule?', 'orders')
        ->expectsChoice('Which tables need their own rule?', ['orders'], ['orders' => 'orders · 1.0 KB · ~10 rows'])
        ->expectsChoice('orders (1.0 KB, ~10 rows)', 'full', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Where, TableMode::Skip]))
        ->expectsChoice('Anonymize these columns (personal data)', ['orders.customer_email', 'orders.customer_name'], [
            'orders.customer_email' => 'orders.customer_email → email',
            'orders.customer_name' => 'orders.customer_name → "Name {hash}"',
        ])
        ->expectsQuestion('Any other columns to anonymize? (type to search, Enter for none)', 'number')
        ->expectsChoice('Any other columns to anonymize? (type to search, Enter for none)', ['orders.number'], ['orders.number' => 'orders.number (varchar)'])
        ->expectsChoice('Replace orders.number (varchar) with', 'template', [
            'email' => 'Email — user_<hash>@example.test',
            'hash' => 'Hash — 12 hex characters',
            'template' => 'Template — text with {hash} or {id}',
            'fixed' => 'Fixed — the same value for every row',
            'empty' => "Empty string ''",
        ])
        ->expectsQuestion('Template for orders.number', 'Order {id}')
        ->expectsConfirmation('Save profile [default]?', 'yes')
        ->assertSuccessful();

    expect(Profile::load($this->workspace.'/profiles', 'default')->ruleFor('orders')->toArray())->toBe([
        'mode' => 'full',
        'anonymize' => [
            'customer_email' => 'email',
            'customer_name' => ['template' => 'Name {hash}'],
            'number' => ['template' => 'Order {id}'],
        ],
    ]);
});

it('remembers unchecked suggestions as not personal and shows them unchecked next time', function () {
    Process::fake();
    app()->useDatabasePath($this->workspace.'/database');
    (new Analysis('production', now()->toImmutable(), [
        'companies' => new TableInfo('companies', false, 10, 1024, 0, columns: [
            new ColumnInfo('id', 'int', primaryKey: true, nullable: false),
            new ColumnInfo('email', 'varchar', ColumnInfo::TEXT, 191),
        ], personalData: ['email' => new ColumnRule(AnonymizeStrategy::Email)]),
    ]))->save($this->workspace.'/analysis.json');

    $run = fn (bool $again) => $this->artisan('snapshot:configure', ['profile' => 'default'])
        ->when(! $again, fn ($command) => $command
            ->expectsQuestion('Which tables need their own rule?', 'companies')
            ->expectsChoice('Which tables need their own rule?', ['companies'], ['companies' => 'companies · 1.0 KB · ~10 rows']))
        ->when($again, fn ($command) => $command
            ->expectsChoice('Keep the rules of these tables?', ['companies'], ['companies' => 'companies · 1.0 KB · ~10 rows · now: full'])
            ->expectsQuestion('Which tables need their own rule?', '')
            ->expectsChoice('Which tables need their own rule?', [], ['companies' => 'companies · 1.0 KB · ~10 rows · now: full'])
            ->expectsChoice('Change any of the current rules?', ['companies'], ['companies' => 'companies · full']))
        ->expectsChoice('companies (1.0 KB, ~10 rows)', 'full', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Where, TableMode::Skip]));

    $run(false)
        ->expectsChoice('Anonymize these columns (personal data)', [], ['companies.email' => 'companies.email → email'])
        ->expectsConfirmation('Save profile [default]?', 'yes')
        ->assertSuccessful();

    expect(Profile::load($this->workspace.'/profiles', 'default')->notPersonal)->toBe(['companies.email']);

    $run(true)
        ->expectsChoice('Anonymize these columns (personal data)', ['companies.email'], ['companies.email' => 'companies.email → email (marked not personal)'])
        ->expectsConfirmation('Save profile [default]?', 'yes')
        ->assertSuccessful();

    $profile = Profile::load($this->workspace.'/profiles', 'default');

    expect($profile->notPersonal)->toBe([])
        ->and($profile->ruleFor('companies')->anonymize['email']->strategy)->toBe(AnonymizeStrategy::Email);
});

it('skips the anonymize step when anonymization is off and keeps existing rules', function () {
    Process::fake();
    config()->set('db-snapshot.anonymize.enabled', false);
    app()->useDatabasePath($this->workspace.'/database');
    (new Analysis('production', now()->toImmutable(), [
        'companies' => new TableInfo('companies', false, 10, 1024, 0, columns: [
            new ColumnInfo('email', 'varchar', ColumnInfo::TEXT, 191),
        ], personalData: ['email' => new ColumnRule(AnonymizeStrategy::Email)]),
    ]))->save($this->workspace.'/analysis.json');
    (new Profile('default', tables: ['companies' => TableRule::fromArray(['mode' => 'full', 'anonymize' => ['email' => 'email']])]))->save($this->workspace.'/profiles');

    $this->artisan('snapshot:configure', ['profile' => 'default'])
        ->expectsChoice('Keep the rules of these tables?', ['companies'], ['companies' => 'companies · 1.0 KB · ~10 rows · now: full, anonymizes email'])
        ->expectsQuestion('Which tables need their own rule?', '')
        ->expectsChoice('Which tables need their own rule?', [], ['companies' => 'companies · 1.0 KB · ~10 rows · now: full, anonymizes email'])
        ->expectsChoice('Change any of the current rules?', [], ['companies' => 'companies · full, anonymizes email'])
        ->expectsConfirmation('Save profile [default]?', 'yes')
        ->assertSuccessful();

    expect(Profile::load($this->workspace.'/profiles', 'default')->ruleFor('companies')->anonymize)->toHaveKey('email');
});

it('says everything is copied in full instead of showing an empty summary', function () {
    Process::fake();
    app()->useDatabasePath($this->workspace.'/database');
    (new Analysis('production', now()->toImmutable(), [
        'users' => new TableInfo('users', false, 10, 1024 * 1024, 0),
    ]))->save($this->workspace.'/analysis.json');

    $this->artisan('snapshot:configure')
        ->expectsQuestion('Which tables need their own rule?', 'users')
        ->expectsChoice('Which tables need their own rule?', ['users'], ['users' => 'users · 1.0 MB · ~10 rows'])
        ->expectsChoice('users (1.0 MB, ~10 rows)', 'full', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Where, TableMode::Skip]))
        ->expectsOutputToContain('All tables are copied in full.')
        ->doesntExpectOutputToContain('In snapshot')
        ->expectsConfirmation('Save profile [default]?', 'yes')
        ->assertSuccessful();
});

it('suggests tables pointing at filtered tables to follow them, child after child', function () {
    Process::fake();
    $mb = 1024 * 1024;

    (new Analysis('production', now()->toImmutable(), [
        'orders' => new TableInfo('orders', false, 1000, 500 * $mb, 0, [new DateColumn('created_at', 'timestamp', true)]),
        'order_items' => new TableInfo('order_items', false, 1000, 200 * $mb, 0, relations: [new Relation('order_id', 'orders')]),
        'order_serials' => new TableInfo('order_serials', false, 1000, 200 * $mb, 0, relations: [new Relation('order_item_id', 'order_items', guessed: true, indexed: false)]),
    ]))->save($this->workspace.'/analysis.json');
    app()->useDatabasePath($this->workspace.'/database');

    $this->artisan('snapshot:configure', ['profile' => 'default'])
        ->expectsQuestion('Which tables need their own rule?', '')
        ->expectsChoice('Which tables need their own rule?', ['orders'], [
            'orders' => 'orders · 500 MB · ~1.0K rows · created_at✓',
            'order_items' => 'order_items · 200 MB · ~1.0K rows',
            'order_serials' => 'order_serials · 200 MB · ~1.0K rows',
        ])
        ->expectsChoice('orders (500 MB, ~1.0K rows)', 'recent', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Recent, TableMode::Where, TableMode::Skip]))
        ->expectsChoice('How much of orders?', '3', [
            '1' => 'Last 1 months', '3' => 'Last 3 months', '6' => 'Last 6 months', '12' => 'Last 12 months', '24' => 'Last 24 months', 'since' => 'Since a fixed date…',
        ])
        ->expectsChoice('These tables point at tables you filter. Copy only their rows that belong to copied rows?', ['order_items'], [
            'order_items' => 'order_items · 200 MB → orders by order_id (foreign key)',
        ])
        ->expectsChoice('These tables point at tables you filter. Copy only their rows that belong to copied rows?', ['order_serials'], [
            'order_serials' => 'order_serials · 200 MB → order_items by order_item_id (guessed from the name), order_item_id not indexed',
        ])
        ->expectsConfirmation('Save profile [default]?', 'yes')
        ->expectsOutputToContain('order_serials.order_item_id')
        ->expectsConfirmation('Generate a migration that adds these indexes?', 'no')
        ->assertSuccessful();

    $profile = Profile::load($this->workspace.'/profiles', 'default');

    expect($profile->ruleFor('order_items')->toArray())->toBe(['mode' => 'follow', 'parent' => 'orders', 'column' => 'order_id'])
        ->and($profile->ruleFor('order_serials')->toArray())->toBe(['mode' => 'follow', 'parent' => 'order_items', 'column' => 'order_item_id'])
        ->and($profile->followers('orders'))->toBe(['order_items', 'order_serials']);
});

function prepareRelatedConfigure(string $workspace, array $tables): void
{
    Process::fake();
    (new Analysis('production', now()->toImmutable(), $tables))->save($workspace.'/analysis.json');
    app()->useDatabasePath($workspace.'/database');
}

it('does not offer Follow when it would close a loop', function () {
    $mb = 1024 * 1024;
    prepareRelatedConfigure($this->workspace, [
        'users' => new TableInfo('users', false, 1000, 500 * $mb, 0, relations: [new Relation('current_team_id', 'teams')]),
        'teams' => new TableInfo('teams', false, 1000, 500 * $mb, 0, relations: [new Relation('user_id', 'users')]),
    ]);

    $this->artisan('snapshot:configure', ['profile' => 'default'])
        ->expectsQuestion('Which tables need their own rule?', '')
        ->expectsChoice('Which tables need their own rule?', ['users', 'teams'], [
            'users' => 'users · 500 MB · ~1.0K rows',
            'teams' => 'teams · 500 MB · ~1.0K rows',
        ])
        ->expectsChoice('users (500 MB, ~1.0K rows)', 'follow', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Where, TableMode::Follow, TableMode::Skip]))
        ->expectsChoice('teams (500 MB, ~1.0K rows)', 'full', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Where, TableMode::Skip]))
        ->expectsConfirmation('Save profile [default]?', 'yes')
        ->assertSuccessful();

    expect(Profile::load($this->workspace.'/profiles', 'default')->ruleFor('users')->describe())->toBe('rows of copied teams by current_team_id');
});

it('keeps offering a follow rule the analysis does not know', function () {
    $mb = 1024 * 1024;
    prepareRelatedConfigure($this->workspace, [
        'order_items' => new TableInfo('order_items', false, 1000, 500 * $mb, 0),
        'orders' => new TableInfo('orders', false, 1000, 1 * $mb, 0),
    ]);
    (new Profile('default', TableMode::Full, [
        'order_items' => new TableRule(TableMode::Follow, 'order_number', parent: 'orders', references: 'number'),
    ]))->save($this->workspace.'/profiles');

    $this->artisan('snapshot:configure', ['profile' => 'default'])
        ->expectsChoice('Keep the rules of these tables?', ['order_items'], ['order_items' => 'order_items · 500 MB · ~1.0K rows · now: rows of copied orders by order_number'])
        ->expectsQuestion('Which tables need their own rule?', '')
        ->expectsChoice('Which tables need their own rule?', [], ['order_items' => 'order_items · 500 MB · ~1.0K rows · now: rows of copied orders by order_number'])
        ->expectsChoice('Change any of the current rules?', ['order_items'], ['order_items' => 'order_items · rows of copied orders by order_number'])
        ->expectsChoice('order_items (500 MB, ~1.0K rows)', 'follow', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Where, TableMode::Follow, TableMode::Skip]))
        ->expectsConfirmation('Save profile [default]?', 'yes')
        ->assertSuccessful();

    expect(Profile::load($this->workspace.'/profiles', 'default')->ruleFor('order_items')->toArray())
        ->toBe(['mode' => 'follow', 'parent' => 'orders', 'column' => 'order_number', 'references' => 'number']);
});

it('copies an unchecked suggestion by its own rule and stops suggesting', function () {
    $mb = 1024 * 1024;
    prepareRelatedConfigure($this->workspace, [
        'orders' => new TableInfo('orders', false, 1000, 500 * $mb, 0),
        'order_items' => new TableInfo('order_items', false, 1000, 200 * $mb, 0, relations: [new Relation('order_id', 'orders')]),
        'order_serials' => new TableInfo('order_serials', false, 1000, 200 * $mb, 0, relations: [new Relation('order_item_id', 'order_items')]),
    ]);

    $this->artisan('snapshot:configure', ['profile' => 'default'])
        ->expectsQuestion('Which tables need their own rule?', '')
        ->expectsChoice('Which tables need their own rule?', ['orders'], [
            'orders' => 'orders · 500 MB · ~1.0K rows',
            'order_items' => 'order_items · 200 MB · ~1.0K rows',
            'order_serials' => 'order_serials · 200 MB · ~1.0K rows',
        ])
        ->expectsChoice('orders (500 MB, ~1.0K rows)', 'where', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Where, TableMode::Skip]))
        ->expectsQuestion('WHERE clause for orders', 'id > 5')
        ->expectsChoice('These tables point at tables you filter. Copy only their rows that belong to copied rows?', [], [
            'order_items' => 'order_items · 200 MB → orders by order_id (foreign key)',
        ])
        ->expectsConfirmation('Save profile [default]?', 'yes')
        ->assertSuccessful();

    expect(Profile::load($this->workspace.'/profiles', 'default')->tables)->toHaveKeys(['orders'])->not->toHaveKey('order_items');
});

it('does not suggest a follower that would close a loop', function () {
    $mb = 1024 * 1024;
    prepareRelatedConfigure($this->workspace, [
        'users' => new TableInfo('users', false, 1000, 500 * $mb, 0, relations: [new Relation('current_team_id', 'teams')]),
        'teams' => new TableInfo('teams', false, 1000, 500 * $mb, 0, relations: [new Relation('user_id', 'users')]),
    ]);

    $this->artisan('snapshot:configure', ['profile' => 'default'])
        ->expectsQuestion('Which tables need their own rule?', '')
        ->expectsChoice('Which tables need their own rule?', ['users'], [
            'users' => 'users · 500 MB · ~1.0K rows',
            'teams' => 'teams · 500 MB · ~1.0K rows',
        ])
        ->expectsChoice('users (500 MB, ~1.0K rows)', 'follow', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Where, TableMode::Follow, TableMode::Skip]))
        ->expectsConfirmation('Save profile [default]?', 'yes')
        ->assertSuccessful();

    expect(Profile::load($this->workspace.'/profiles', 'default')->tables)->toHaveKey('users')->not->toHaveKey('teams');
});

it('suggests following the date-filtered parent when a table points at several filtered ones', function () {
    $mb = 1024 * 1024;
    prepareRelatedConfigure($this->workspace, [
        'orders' => new TableInfo('orders', false, 1000, 500 * $mb, 0, [new DateColumn('created_at', 'timestamp', true)]),
        'carriers' => new TableInfo('carriers', false, 1000, 500 * $mb, 0),
        'shipments' => new TableInfo('shipments', false, 1000, 1 * $mb, 0, relations: [new Relation('carrier_id', 'carriers'), new Relation('order_id', 'orders')]),
    ]);

    $this->artisan('snapshot:configure', ['profile' => 'default'])
        ->expectsQuestion('Which tables need their own rule?', '')
        ->expectsChoice('Which tables need their own rule?', ['orders', 'carriers'], [
            'orders' => 'orders · 500 MB · ~1.0K rows · created_at✓',
            'carriers' => 'carriers · 500 MB · ~1.0K rows',
        ])
        ->expectsChoice('orders (500 MB, ~1.0K rows)', 'recent', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Recent, TableMode::Where, TableMode::Skip]))
        ->expectsChoice('How much of orders?', '3', [
            '1' => 'Last 1 months', '3' => 'Last 3 months', '6' => 'Last 6 months', '12' => 'Last 12 months', '24' => 'Last 24 months', 'since' => 'Since a fixed date…',
        ])
        ->expectsChoice('carriers (500 MB, ~1.0K rows)', 'where', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Where, TableMode::Skip]))
        ->expectsQuestion('WHERE clause for carriers', 'active = 1')
        ->expectsChoice('These tables point at tables you filter. Copy only their rows that belong to copied rows?', ['shipments'], [
            'shipments' => 'shipments · 1.0 MB → orders by order_id (foreign key)',
        ])
        ->expectsConfirmation('Save profile [default]?', 'yes')
        ->assertSuccessful();

    expect(Profile::load($this->workspace.'/profiles', 'default')->ruleFor('shipments')->parent)->toBe('orders');
});

it('says follow needs a fresh analysis when it knows no relations', function () {
    prepareConfigure($this->workspace);

    configureEventLogs($this->artisan('snapshot:configure', ['profile' => 'nightly']), 'nightly')
        ->expectsOutputToContain('The analysis knows no relations between tables')
        ->expectsConfirmation('Generate a migration that adds these indexes?', 'no')
        ->assertSuccessful();
});

it('keeps existing rules that are not picked for changing, and asks about new tables', function () {
    prepareConfigure($this->workspace);
    (new Profile('default', TableMode::Full, [
        'event_logs' => new TableRule(TableMode::Recent, 'created_at', months: 6),
    ]))->save($this->workspace.'/profiles');

    $this->artisan('snapshot:configure', ['profile' => 'default'])
        ->expectsChoice('Keep the rules of these tables?', ['event_logs'], ['event_logs' => 'event_logs · 500 MB · ~1.0K rows · now: last 6 months by created_at'])
        ->expectsQuestion('Which tables need their own rule?', 'users')
        ->expectsChoice('Which tables need their own rule?', ['users'], ['users' => 'users · 1.0 MB · ~10 rows'])
        ->expectsChoice('Change any of the current rules?', [], ['event_logs' => 'event_logs · last 6 months by created_at'])
        ->expectsChoice('users (1.0 MB, ~10 rows)', 'schema', modeOptions([TableMode::Full, TableMode::Schema, TableMode::Where, TableMode::Skip]))
        ->expectsConfirmation('Save profile [default]?', 'yes')
        ->expectsConfirmation('Generate a migration that adds these indexes?', 'no')
        ->assertSuccessful();

    $profile = Profile::load($this->workspace.'/profiles', 'default');

    expect($profile->ruleFor('event_logs')->describe())->toBe('last 6 months by created_at')
        ->and($profile->ruleFor('users')->describe())->toBe('schema');
});

it('sets what happens to tables without a rule with --default-mode', function () {
    prepareConfigure($this->workspace);

    $this->artisan('snapshot:configure', ['profile' => 'default', '--default-mode' => 'full-ish'])
        ->expectsOutputToContain('--default-mode must be full, schema or skip.')
        ->assertFailed();

    $this->artisan('snapshot:configure', ['profile' => 'default', '--default-mode' => 'schema'])
        ->expectsQuestion('Which tables need their own rule?', '')
        ->expectsChoice('Which tables need their own rule?', [], ['event_logs' => 'event_logs · 500 MB · ~1.0K rows'])
        ->expectsOutputToContain('All tables are copied as schema only.')
        ->expectsConfirmation('Save profile [default]?', 'yes')
        ->assertSuccessful();

    expect(Profile::load($this->workspace.'/profiles', 'default')->defaultMode)->toBe(TableMode::Schema);
});

it('drops a rule unchecked under Keep the rules in the plain prompt', function () {
    Process::fake();
    app()->useDatabasePath($this->workspace.'/database');
    (new Analysis('production', now()->toImmutable(), [
        'users' => new TableInfo('users', false, 10, 1024 * 1024, 0),
    ]))->save($this->workspace.'/analysis.json');
    (new Profile('default', TableMode::Full, ['users' => new TableRule(TableMode::Schema)]))->save($this->workspace.'/profiles');

    $this->artisan('snapshot:configure', ['profile' => 'default'])
        ->expectsChoice('Keep the rules of these tables?', [], ['users' => 'users · 1.0 MB · ~10 rows · now: schema'])
        ->expectsQuestion('Which tables need their own rule?', null)
        ->expectsChoice('Which tables need their own rule?', [], ['users' => 'users · 1.0 MB · ~10 rows · now: schema'])
        ->expectsConfirmation('Save profile [default]?', 'yes')
        ->assertSuccessful();

    expect(Profile::load($this->workspace.'/profiles', 'default')->tables)->toBe([]);
});
