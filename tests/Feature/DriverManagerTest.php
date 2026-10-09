<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Overthink\DbSnapshot\Facades\DbSnapshot;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Snapshot\Puller;
use Overthink\DbSnapshot\Snapshot\Restorer;
use Overthink\DbSnapshot\Snapshot\Snapshot;
use Overthink\DbSnapshot\Tests\Fixtures\FakeDriver;

it('pulls with a registered driver when it is configured', function () {
    Sleep::fake();
    Process::fake();
    DbSnapshot::extend('fake', fn () => new FakeDriver);
    config()->set('db-snapshot.driver', 'fake');

    $snapshot = app(Puller::class)->pull(new Profile('default'), 1);

    expect($snapshot->driver())->toBe('fake')
        ->and($snapshot->manifest['database'])->toBe('source');
    Process::assertRan(fn ($process) => str_starts_with(commandLine($process), 'fake-dump orders > '));
});

it('lists registered drivers next to the built-in ones', function () {
    DbSnapshot::extend('fake', fn () => new FakeDriver);

    expect(DbSnapshot::available())->toBe(['mysql', 'fake']);
});

it('restores with the driver the snapshot was pulled with', function () {
    Sleep::fake();
    Process::fake();
    DbSnapshot::extend('fake', fn () => new FakeDriver);
    $snapshot = makeSnapshot($this->workspace.'/snap', ['orders' => 1], withRoutines: false);
    $manifest = json_decode(file_get_contents($snapshot->path.'/manifest.json'), true);
    file_put_contents($snapshot->path.'/manifest.json', json_encode([...$manifest, 'driver' => 'fake']));

    app(Restorer::class)->restore(Snapshot::load($snapshot->path), [...localConnection(), 'driver' => 'fakesql'], 1);

    Process::assertRan(fn ($process) => commandLine($process) === 'fake-recreate dev_wt');
    Process::assertRan(fn ($process) => str_starts_with(commandLine($process), 'fake-import '));
});

it('refuses to restore into a connection of another kind', function () {
    Process::fake();
    $snapshot = makeSnapshot($this->workspace.'/snap', ['orders' => 1]);

    app(Restorer::class)->restore($snapshot, [...localConnection(), 'driver' => 'pgsql'], 1);
})->throws(InvalidArgumentException::class, 'pulled with the mysql driver and cannot be restored into a pgsql connection');
