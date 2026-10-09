<?php

use Illuminate\Support\Facades\File;
use Overthink\DbSnapshot\Analysis\Analysis;
use Overthink\DbSnapshot\Analysis\TableInfo;
use Overthink\DbSnapshot\Profile\AnonymizeStrategy;
use Overthink\DbSnapshot\Profile\ColumnRule;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Profile\TableRule;

it('loads the rules it saved', function () {
    $directory = $this->workspace.'/profiles';

    (new Profile('default', TableMode::Full, [
        'event_logs' => new TableRule(TableMode::Recent, 'created_at', months: 3),
        'activities' => new TableRule(TableMode::Schema),
    ]))->save($directory);

    $loaded = Profile::load($directory, 'default');

    expect($loaded->ruleFor('event_logs')->toArray())->toBe(['mode' => 'recent', 'column' => 'created_at', 'months' => 3])
        ->and($loaded->ruleFor('activities')->mode)->toBe(TableMode::Schema);
});

it('uses the default mode for tables the profile does not list', function () {
    $profile = new Profile('default', TableMode::Schema, ['orders' => new TableRule(TableMode::Full)]);

    expect($profile->ruleFor('brand_new_table')->mode)->toBe(TableMode::Schema);
});

it('names the file when a rule in it is invalid', function () {
    File::ensureDirectoryExists($this->workspace.'/profiles');
    File::put($this->workspace.'/profiles/broken.json', json_encode(['tables' => ['logs' => ['mode' => 'recent']]]));

    Profile::load($this->workspace.'/profiles', 'broken');
})->throws(RuntimeException::class, 'broken.json');

it('tells you to configure a profile that does not exist', function () {
    Profile::load($this->workspace.'/profiles', 'missing');
})->throws(RuntimeException::class, 'snapshot:configure missing');

it('remembers columns marked as not personal', function () {
    (new Profile('default', notPersonal: ['products.name', 'companies.email']))->save($this->workspace.'/profiles');

    expect(Profile::load($this->workspace.'/profiles', 'default')->notPersonal)->toBe(['companies.email', 'products.name']);
});

it('lists personal looking columns the profile has not decided on', function () {
    $analysis = new Analysis('production', now()->toImmutable(), [
        'orders' => new TableInfo('orders', false, 1, 1, 0, personalData: [
            'customer_email' => new ColumnRule(AnonymizeStrategy::Email),
            'customer_phone' => new ColumnRule(AnonymizeStrategy::Null),
            'customer_name' => new ColumnRule(AnonymizeStrategy::Template, 'Name {hash}'),
        ]),
        'audit_logs' => new TableInfo('audit_logs', false, 1, 1, 0, personalData: [
            'ip_address' => new ColumnRule(AnonymizeStrategy::Hash),
        ]),
    ]);

    $profile = new Profile('default', TableMode::Full, [
        'orders' => TableRule::fromArray(['mode' => 'full', 'anonymize' => ['customer_email' => 'email']]),
        'audit_logs' => new TableRule(TableMode::Schema),
    ], notPersonal: ['orders.customer_name']);

    expect(array_keys($profile->undecidedPersonalData($analysis)))->toBe(['orders.customer_phone']);
});
