<?php

use Overthink\DbSnapshot\Support\DatabaseName;

it('fills the placeholders from the snapshot and the local database', function () {
    $snapshot = makeSnapshot($this->workspace.'/snap', ['orders' => 1], createdAt: '2026-10-08T13:45:10+00:00', profile: 'nightly');

    expect(DatabaseName::resolve('{source}_{date}', $snapshot, 'dev_app'))->toBe('production_2026_10_08')
        ->and(DatabaseName::resolve('{database}_{profile}_{date}_{time}', $snapshot, 'dev_app'))->toBe('dev_app_nightly_2026_10_08_134510');
});

it('rejects names a database would not accept', function (string $template) {
    DatabaseName::resolve($template, makeSnapshot($this->workspace.'/snap', ['orders' => 1]), 'dev_app');
})->with([
    'quote' => ['x`; DROP DATABASE prod; --'],
    'space' => ['my db'],
    'too long' => [str_repeat('a', 64)],
])->throws(InvalidArgumentException::class);
