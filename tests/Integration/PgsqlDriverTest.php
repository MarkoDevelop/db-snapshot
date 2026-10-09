<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Profile\TableRule;
use Overthink\DbSnapshot\Snapshot\Puller;
use Overthink\DbSnapshot\Snapshot\Restorer;

/*
 * Runs against a real PostgreSQL server (DB_SNAPSHOT_PGSQL_HOST, _PORT, _USERNAME,
 * _PASSWORD) with pg_dump and psql on PATH. "ssh" is a stub that runs the remote
 * script locally, so the server plays both the remote and the local database.
 */

beforeEach(function () {
    if (! getenv('DB_SNAPSHOT_PGSQL_HOST')) {
        $this->markTestSkipped('Set DB_SNAPSHOT_PGSQL_HOST to run the PostgreSQL integration tests.');
    }

    $this->pg = [
        'host' => getenv('DB_SNAPSHOT_PGSQL_HOST'),
        'port' => (int) (getenv('DB_SNAPSHOT_PGSQL_PORT') ?: 5432),
        'username' => getenv('DB_SNAPSHOT_PGSQL_USERNAME') ?: 'postgres',
        'password' => getenv('DB_SNAPSHOT_PGSQL_PASSWORD') ?: '',
    ];

    $bin = $this->workspace.'/bin';
    File::ensureDirectoryExists($bin);
    File::put($bin.'/ssh', "#!/bin/bash\nexec sh -c \"\${@: -1}\"\n");
    chmod($bin.'/ssh', 0755);
    $this->originalPath = [getenv('PATH'), $_ENV['PATH'] ?? null, $_SERVER['PATH'] ?? null];
    putenv("PATH={$bin}:{$this->originalPath[0]}");
    $_ENV['PATH'] = $_SERVER['PATH'] = "{$bin}:{$this->originalPath[0]}";

    config()->set('db-snapshot.driver', 'pgsql');
    config()->set('db-snapshot.ssh', ['host' => 'source-server', 'options' => []]);
    config()->set('db-snapshot.remote', [...$this->pg, 'database' => 'snapshot_source', 'nice' => '']);

    pgExec($this->pg, 'postgres', 'DROP DATABASE IF EXISTS snapshot_source WITH (FORCE)');
    pgExec($this->pg, 'postgres', 'CREATE DATABASE snapshot_source');
    pgExec($this->pg, 'snapshot_source', <<<'SQL'
        CREATE EXTENSION citext;
        CREATE TABLE users (id bigserial PRIMARY KEY, email citext NOT NULL UNIQUE, created_at timestamptz NOT NULL DEFAULT now());
        CREATE TABLE orders (id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES users (id), total numeric(10, 2) NOT NULL, created_at timestamptz NOT NULL, updated_at timestamptz);
        CREATE INDEX orders_created_at_index ON orders (created_at);
        CREATE TABLE order_items (id bigserial PRIMARY KEY, order_id bigint NOT NULL REFERENCES orders (id) ON DELETE CASCADE, sku text NOT NULL);
        CREATE TABLE audit_logs (id bigserial PRIMARY KEY, message text, created_at timestamptz);
        CREATE FUNCTION touch_updated_at() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN NEW.updated_at = now(); RETURN NEW; END $$;
        CREATE TRIGGER orders_touch BEFORE UPDATE ON orders FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
        CREATE VIEW big_orders AS SELECT * FROM orders WHERE total > 100;

        INSERT INTO users (email) VALUES ('Ana@example.test'), ('bor@example.test');
        INSERT INTO orders (user_id, total, created_at) VALUES
            (1, 50, now() - interval '2 years'), (1, 150, now() - interval '3 days'), (2, 300, now() - interval '1 day');
        INSERT INTO order_items (order_id, sku) VALUES (1, 'old'), (2, 'new-a'), (3, 'new-b');
        INSERT INTO audit_logs (message, created_at) SELECT 'log ' || n, now() FROM generate_series(1, 50) n;
        SQL);
});

afterEach(function () {
    if (! getenv('DB_SNAPSHOT_PGSQL_HOST')) {
        return;
    }

    putenv("PATH={$this->originalPath[0]}");
    [, $_ENV['PATH'], $_SERVER['PATH']] = $this->originalPath;

    foreach (['snapshot_source', 'snapshot_target'] as $database) {
        pgExec($this->pg, 'postgres', "DROP DATABASE IF EXISTS {$database} WITH (FORCE)");
    }
});

/**
 * @param  array{host: string, port: int, username: string, password: string}  $pg
 */
function pgExec(array $pg, string $database, string $sql): string
{
    $result = Process::env(['PGPASSWORD' => $pg['password'], 'PGOPTIONS' => '-c client_min_messages=warning'])->input($sql)->run([
        'psql', '--host='.$pg['host'], '--port='.$pg['port'], '--username='.$pg['username'], '--dbname='.$database,
        '-X', '-q', '-A', '-t', '-v', 'ON_ERROR_STOP=1',
    ]);

    if ($result->failed()) {
        throw new RuntimeException($result->errorOutput());
    }

    return trim($result->output());
}

function pullAndRestore(array $pg): array
{
    $snapshot = app(Puller::class)->pull(new Profile('default', TableMode::Full, [
        'orders' => new TableRule(TableMode::Recent, 'created_at', months: 1),
        'audit_logs' => new TableRule(TableMode::Schema),
    ]), 4);

    return app(Restorer::class)->restore($snapshot, [...$pg, 'driver' => 'pgsql', 'database' => 'snapshot_target', 'charset' => 'utf8'], 4);
}

it('restores filtered rows, extensions, constraints, triggers, functions and views', function () {
    $warnings = pullAndRestore($this->pg);

    expect($warnings)->toBe([])
        ->and(pgExec($this->pg, 'snapshot_target', 'SELECT count(*) FROM users'))->toBe('2')
        ->and(pgExec($this->pg, 'snapshot_target', 'SELECT string_agg(id::text, \',\' ORDER BY id) FROM orders'))->toBe('2,3')
        ->and(pgExec($this->pg, 'snapshot_target', 'SELECT count(*) FROM order_items'))->toBe('3')
        ->and(pgExec($this->pg, 'snapshot_target', 'SELECT count(*) FROM audit_logs'))->toBe('0')
        ->and(pgExec($this->pg, 'snapshot_target', "SELECT id FROM users WHERE email = 'ana@EXAMPLE.test'"))->toBe('1')
        ->and(pgExec($this->pg, 'snapshot_target', 'SELECT count(*) FROM big_orders'))->toBe('2')
        ->and(pgExec($this->pg, 'snapshot_target', "SELECT count(*) FROM pg_trigger WHERE tgname = 'orders_touch'"))->toBe('1')
        ->and(pgExec($this->pg, 'snapshot_target', "SELECT count(*) FROM pg_indexes WHERE indexname = 'orders_created_at_index'"))->toBe('1');
});

it('keeps foreign keys, created NOT VALID so rows pointing at filtered rows survive', function () {
    pullAndRestore($this->pg);

    expect(pgExec($this->pg, 'snapshot_target', "SELECT convalidated FROM pg_constraint WHERE conname = 'order_items_order_id_fkey'"))->toBe('f');

    expect(fn () => pgExec($this->pg, 'snapshot_target', 'INSERT INTO order_items (order_id, sku) VALUES (999, \'x\')'))
        ->toThrow(RuntimeException::class, 'violates foreign key constraint');
});

it('moves sequences of filtered tables past the restored rows', function () {
    pullAndRestore($this->pg);

    expect(pgExec($this->pg, 'snapshot_target', 'INSERT INTO orders (user_id, total, created_at) VALUES (1, 1, now()) RETURNING id'))->toBe('4')
        ->and(pgExec($this->pg, 'snapshot_target', "INSERT INTO audit_logs (message) VALUES ('x') RETURNING id"))->toBe('1');
});

it('refreshes one table that other tables point at', function () {
    pullAndRestore($this->pg);
    pgExec($this->pg, 'snapshot_source', "INSERT INTO users (email) VALUES ('cene@example.test')");

    $snapshot = app(Puller::class)->pull(new Profile('refresh', TableMode::Skip, ['users' => new TableRule(TableMode::Full)]), 1, partial: true);
    $warnings = app(Restorer::class)->importTables($snapshot, [...$this->pg, 'driver' => 'pgsql', 'database' => 'snapshot_target'], 1);

    expect($warnings)->toBe([])
        ->and(pgExec($this->pg, 'snapshot_target', 'SELECT count(*) FROM users'))->toBe('3')
        ->and(pgExec($this->pg, 'snapshot_target', 'SELECT count(*) FROM orders'))->toBe('2');
});

it('replaces personal data on the server and keeps it consistent', function () {
    pgExec($this->pg, 'snapshot_source', "ALTER TABLE users ADD COLUMN name text, ADD COLUMN phone text NOT NULL DEFAULT '', ADD COLUMN nickname varchar(8);");
    pgExec($this->pg, 'snapshot_source', "UPDATE users SET name = 'Ana Novak', phone = '+386 40 123 456' WHERE id = 1; UPDATE users SET name = 'Bor O''Hara', phone = '+386 41 999 000', nickname = 'bor' WHERE id = 2;");
    config()->set('db-snapshot.anonymize.salt', 'pepper');

    $snapshot = app(Puller::class)->pull(new Profile('default', TableMode::Full, [
        'users' => TableRule::fromArray(['mode' => 'full', 'anonymize' => [
            'email' => 'email',
            'name' => ['template' => 'User {id} {hash}'],
            'phone' => 'empty',
            'nickname' => ['template' => 'Nick {hash}'],
        ]]),
    ]), 2);
    app(Restorer::class)->restore($snapshot, [...$this->pg, 'driver' => 'pgsql', 'database' => 'snapshot_target', 'charset' => 'utf8'], 2);

    $rows = pgExec($this->pg, 'snapshot_target', "SELECT id || '|' || email || '|' || name || '|' || phone || '|' || COALESCE(nickname, 'NULL') FROM users ORDER BY id");
    $gzipped = gzdecode(File::get($snapshot->path.'/tables/users.sql.gz'));

    expect($rows)->toMatch('/^1\|user_[0-9a-f]{12}@example\.test\|User 1 [0-9a-f]{12}\|\|NULL\n2\|user_[0-9a-f]{12}@example\.test\|User 2 [0-9a-f]{12}\|\|Nick [0-9a-f]{3}$/')
        ->and($gzipped)->not->toContain('Ana@example.test')
        ->and($gzipped)->not->toContain('Novak')
        ->and($gzipped)->not->toContain('+386')
        ->and(pgExec($this->pg, 'snapshot_target', 'SELECT count(*) FROM orders o JOIN users u ON u.id = o.user_id'))->toBe('3');

    $again = app(Puller::class)->pull(new Profile('again', TableMode::Skip, [
        'users' => TableRule::fromArray(['mode' => 'full', 'anonymize' => ['email' => 'email']]),
    ]), 1, partial: true);

    expect(gzdecode(File::get($again->path.'/tables/users.sql.gz')))->toContain(explode('|', explode("\n", $rows)[0])[1]);
});
