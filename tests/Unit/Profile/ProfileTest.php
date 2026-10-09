<?php

use Illuminate\Support\Facades\File;
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
