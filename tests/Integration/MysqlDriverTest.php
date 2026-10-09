<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Profile\TableRule;
use Overthink\DbSnapshot\Snapshot\Puller;
use Overthink\DbSnapshot\Snapshot\Restorer;

/*
 * Runs against a real MySQL server (DB_SNAPSHOT_MYSQL_HOST, _PORT, _USERNAME,
 * _PASSWORD) with mysqldump and mysql on PATH. "ssh" is a stub that runs the
 * remote script locally, so the server plays both the remote and the local database.
 */

beforeEach(function () {
    if (! getenv('DB_SNAPSHOT_MYSQL_HOST')) {
        $this->markTestSkipped('Set DB_SNAPSHOT_MYSQL_HOST to run the MySQL integration tests.');
    }

    $this->my = [
        'host' => getenv('DB_SNAPSHOT_MYSQL_HOST'),
        'port' => (int) (getenv('DB_SNAPSHOT_MYSQL_PORT') ?: 3306),
        'username' => getenv('DB_SNAPSHOT_MYSQL_USERNAME') ?: 'root',
        'password' => getenv('DB_SNAPSHOT_MYSQL_PASSWORD') ?: '',
    ];

    $bin = $this->workspace.'/bin';
    File::ensureDirectoryExists($bin);
    File::put($bin.'/ssh', "#!/bin/bash\nexec sh -c \"\${@: -1}\"\n");
    chmod($bin.'/ssh', 0755);
    $this->originalPath = [getenv('PATH'), $_ENV['PATH'] ?? null, $_SERVER['PATH'] ?? null];
    putenv("PATH={$bin}:{$this->originalPath[0]}");
    $_ENV['PATH'] = $_SERVER['PATH'] = "{$bin}:{$this->originalPath[0]}";

    config()->set('db-snapshot.driver', 'mysql');
    config()->set('db-snapshot.ssh', ['host' => 'source-server', 'options' => []]);
    config()->set('db-snapshot.remote', [...$this->my, 'database' => 'snapshot_source', 'nice' => '']);
    config()->set('db-snapshot.anonymize.salt', 'pepper');

    myExec($this->my, null, 'DROP DATABASE IF EXISTS snapshot_source; CREATE DATABASE snapshot_source');
    myExec($this->my, 'snapshot_source', <<<'SQL'
        CREATE TABLE users (
            id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email varchar(191) NOT NULL UNIQUE,
            name varchar(191) NULL,
            phone varchar(30) NOT NULL DEFAULT '',
            nickname varchar(8) NULL,
            avatar blob NULL,
            email_domain varchar(191) GENERATED ALWAYS AS (SUBSTRING_INDEX(email, '@', -1)) VIRTUAL,
            updated_count int NOT NULL DEFAULT 0
        );
        CREATE TABLE orders (
            id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
            number varchar(20) NOT NULL,
            user_id int unsigned NOT NULL,
            notes text NULL,
            created_at datetime NOT NULL,
            CONSTRAINT orders_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id)
        );
        CREATE TRIGGER users_count BEFORE INSERT ON users FOR EACH ROW SET NEW.updated_count = NEW.updated_count + 1;

        INSERT INTO users (email, name, phone, nickname, avatar) VALUES
            ('ana@example.com', 'Ana Novak', '+386 40 123 456', NULL, 0x00FF10),
            ('bor@example.com', 'Bor O''Hara', '+386 41 999 000', 'bor', NULL);
        INSERT INTO orders (number, user_id, notes, created_at) VALUES
            ('SO-0001', 1, 'Leave at the "door"\nthanks', NOW() - INTERVAL 2 YEAR),
            ('SO-0002', 2, NULL, NOW());
        SQL);
});

afterEach(function () {
    if (! getenv('DB_SNAPSHOT_MYSQL_HOST')) {
        return;
    }

    putenv("PATH={$this->originalPath[0]}");
    [, $_ENV['PATH'], $_SERVER['PATH']] = $this->originalPath;

    myExec($this->my, null, 'DROP DATABASE IF EXISTS snapshot_source; DROP DATABASE IF EXISTS snapshot_target');
});

/**
 * @param  array{host: string, port: int, username: string, password: string}  $my
 */
function myExec(array $my, ?string $database, string $sql): string
{
    $result = Process::env(['MYSQL_PWD' => $my['password']])->input($sql)->run(array_values(array_filter([
        'mysql', '--host='.$my['host'], '--port='.$my['port'], '--user='.$my['username'], '--batch', '--skip-column-names', $database,
    ])));

    if ($result->failed()) {
        throw new RuntimeException($result->errorOutput());
    }

    return trim($result->output());
}

it('replaces personal data on the server and restores everything else exactly', function () {
    $snapshot = app(Puller::class)->pull(new Profile('default', TableMode::Full, [
        'users' => TableRule::fromArray(['mode' => 'full', 'anonymize' => [
            'email' => 'email',
            'name' => ['template' => 'User {id} {hash}'],
            'phone' => 'empty',
            'nickname' => ['template' => 'Nick {hash}'],
        ]]),
        'orders' => TableRule::fromArray(['mode' => 'recent', 'column' => 'created_at', 'months' => 1, 'anonymize' => ['notes' => 'null']]),
    ]), 2);

    $warnings = app(Restorer::class)->restore($snapshot, [...$this->my, 'driver' => 'mysql', 'database' => 'snapshot_target'], 2);
    $dumps = gzdecode(File::get($snapshot->path.'/tables/users.sql.gz')).gzdecode(File::get($snapshot->path.'/tables/orders.sql.gz'));

    expect($warnings)->toBe([])
        ->and(myExec($this->my, 'snapshot_target', "SELECT CONCAT_WS('|', id, email, name, phone, IFNULL(nickname, 'NULL'), IFNULL(HEX(avatar), 'NULL'), email_domain, updated_count) FROM users ORDER BY id"))
        ->toMatch('/^1\|user_[0-9a-f]{12}@example\.test\|User 1 [0-9a-f]{12}\|\|NULL\|00FF10\|example\.test\|1\n2\|user_[0-9a-f]{12}@example\.test\|User 2 [0-9a-f]{12}\|\|Nick [0-9a-f]{3}\|NULL\|example\.test\|1$/')
        ->and(myExec($this->my, 'snapshot_target', "SELECT CONCAT_WS('|', number, user_id, IFNULL(notes, 'NULL')) FROM orders"))->toBe('SO-0002|2|NULL')
        ->and($dumps)->not->toContain('ana@example.com')
        ->and($dumps)->not->toContain('Novak')
        ->and($dumps)->not->toContain('+386')
        ->and($dumps)->not->toContain('door')
        ->and(myExec($this->my, 'snapshot_target', "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = 'snapshot_target'"))->toBe('1')
        ->and(myExec($this->my, 'snapshot_target', "INSERT INTO users (email) VALUES ('new@example.test'); SELECT LAST_INSERT_ID()"))->toBe('3');
});

it('keeps quotes, newlines and binary data intact in tables it does not anonymize', function () {
    myExec($this->my, 'snapshot_source', 'UPDATE orders SET created_at = NOW()');

    $snapshot = app(Puller::class)->pull(new Profile('default', TableMode::Full, [
        'users' => TableRule::fromArray(['mode' => 'full', 'anonymize' => ['email' => 'email']]),
    ]), 2);
    app(Restorer::class)->restore($snapshot, [...$this->my, 'driver' => 'mysql', 'database' => 'snapshot_target'], 2);

    expect(myExec($this->my, 'snapshot_target', "SELECT HEX(notes) FROM orders WHERE number = 'SO-0001'"))->toBe(strtoupper(bin2hex("Leave at the \"door\"\nthanks")))
        ->and(myExec($this->my, 'snapshot_target', 'SELECT name FROM users WHERE id = 2'))->toBe("Bor O'Hara")
        ->and(myExec($this->my, 'snapshot_target', 'SELECT HEX(avatar) FROM users WHERE id = 1'))->toBe('00FF10');
});
