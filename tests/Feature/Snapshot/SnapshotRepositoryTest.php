<?php

use Illuminate\Support\Facades\File;
use Overthink\DbSnapshot\Snapshot\SnapshotRepository;

it('recovers tables an interrupted merge moved but never listed', function () {
    // The state a merge killed after moving users.sql.gz but before writing the manifest leaves.
    $oldest = makeAddon($this->workspace, 'orders', ['users' => 3, 'gone' => 1], createdAt: '2026-10-01T08:00:00+00:00');
    $half = makeAddon($this->workspace, 'orders', ['orders' => 2], createdAt: '2026-10-05T08:00:00+00:00');
    File::move($oldest->path.'/tables/users.sql.gz', $half->path.'/tables/users.sql.gz');
    File::delete($oldest->path.'/tables/gone.sql.gz');
    $kept = makeAddon($this->workspace, 'orders', ['orders' => 5], createdAt: '2026-10-10T08:00:00+00:00');

    $merged = app(SnapshotRepository::class)->mergeOlderAddons($kept);

    expect($merged->tables())->toBe(['orders', 'users'])
        ->and(array_keys($merged->tableFiles()))->toEqualCanonicalizing(['orders', 'users'])
        ->and(File::get($merged->tableFiles()['users']))->toBe('xxx')
        ->and(File::get($merged->tableFiles()['orders']))->toBe('xxxxx')
        ->and(File::exists($oldest->path))->toBeFalse()
        ->and(File::exists($half->path))->toBeFalse();
});

it('refuses to merge an older add-on pulled with another driver, and changes nothing', function () {
    $older = makeAddon($this->workspace, 'orders', ['users' => 3], createdAt: '2026-10-01T08:00:00+00:00');
    File::put($older->path.'/manifest.json', json_encode([...$older->manifest, 'driver' => 'pgsql']));
    $kept = makeAddon($this->workspace, 'orders', ['orders' => 5], createdAt: '2026-10-10T08:00:00+00:00');

    expect(fn () => app(SnapshotRepository::class)->mergeOlderAddons($kept))
        ->toThrow(RuntimeException::class, 'pulled with the pgsql driver');

    expect(File::exists($older->path.'/tables/users.sql.gz'))->toBeTrue()
        ->and(File::exists($kept->path.'/tables/users.sql.gz'))->toBeFalse();
});
