<?php

use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use Overthink\DbSnapshot\Support\EnvFile;

it('replaces existing keys in place and appends new ones', function () {
    $path = $this->workspace.'/.env';
    File::put($path, "APP_NAME=App\nSNAPSHOT_SSH_HOST=old\nDB_HOST=mysql\n");

    (new EnvFile($path))->set(['SNAPSHOT_SSH_HOST' => '203.0.113.10', 'SNAPSHOT_SSH_USER' => 'root']);

    expect(File::get($path))->toBe("APP_NAME=App\nSNAPSHOT_SSH_HOST=203.0.113.10\nDB_HOST=mysql\n\nSNAPSHOT_SSH_USER=root\n");
});

it('writes values that dotenv reads back unchanged', function (string $value) {
    $path = $this->workspace.'/.env';
    (new EnvFile($path))->set(['SNAPSHOT_REMOTE_DB_PASSWORD' => $value]);

    expect(Dotenv::parse(File::get($path))['SNAPSHOT_REMOTE_DB_PASSWORD'])->toBe($value);
})->with([
    'plain' => ['s3cret'],
    'empty' => [''],
    'spaces and hash' => ['pa ss #1'],
    'dollar' => ['pa$word${HOME}'],
    'single quote' => ["it's \$HOME \"quoted\" \\ back"],
]);
