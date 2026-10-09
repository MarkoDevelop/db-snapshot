<?php

use Illuminate\Support\Facades\Artisan;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Profile\TableRule;

it('lists the profiles with their rules and last pull', function () {
    (new Profile('nightly'))->save($this->workspace.'/profiles');
    (new Profile('default', tables: ['orders' => new TableRule(TableMode::Schema)]))->save($this->workspace.'/profiles');
    makeSnapshot($this->workspace.'/snapshots/2026-10-08_080000_default', ['orders' => 1], createdAt: now()->subDay()->toIso8601String());

    expect(Artisan::call('snapshot:profiles'))->toBe(0)
        ->and(Artisan::output())
        ->toContain('default')
        ->toContain('1 rule · pulled 1 day ago')
        ->toContain('nightly')
        ->toContain('0 rules · never pulled');
});

it('explains how to create the first profile', function () {
    $this->artisan('snapshot:profiles')
        ->expectsOutputToContain('Create one with: php artisan snapshot:configure')
        ->assertSuccessful();
});
