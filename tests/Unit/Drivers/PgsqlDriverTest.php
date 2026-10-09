<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Overthink\DbSnapshot\Analysis\ColumnInfo;
use Overthink\DbSnapshot\Drivers\PgsqlDriver;
use Overthink\DbSnapshot\Profile\AnonymizeStrategy;
use Overthink\DbSnapshot\Profile\ColumnRule;

function pgsqlDriver(string $schema = 'public'): PgsqlDriver
{
    return new PgsqlDriver(['host' => 'db.example.test'], ['database' => 'production', 'password' => 'pw'], $schema);
}

it('reads indexed date columns from psql output', function () {
    Process::fake(['*' => Process::result("orders\tcreated_at\ttimestamp with time zone\tt\norders\tshipped_at\tdate\tf")]);

    $columns = pgsqlDriver()->dateColumns();

    expect($columns['orders'][0]->toArray())->toMatchArray(['name' => 'created_at', 'indexed' => true])
        ->and($columns['orders'][1]->toArray())->toMatchArray(['name' => 'shipped_at', 'indexed' => false]);
    Process::assertRan(fn ($process) => str_contains(commandLine($process), "'psql'") && $process->input === "pw\n");
});

it('quotes string literals the way PostgreSQL expects', function () {
    expect(pgsqlDriver()->quote("it's \\ fine"))->toBe("'it''s \\ fine'");
});

it('quotes the column in recent conditions', function () {
    expect(pgsqlDriver()->recentCondition('created_at', CarbonImmutable::parse('2026-02-28')))
        ->toBe("\"created_at\" >= '2026-02-28 00:00:00'");
});

it('dumps filtered tables as their definition plus COPY of the matching rows', function () {
    $command = pgsqlDriver()->dumpTableCommand('orders', false, "\"created_at\" >= '2026-01-01 00:00:00'", '/tmp/orders.sql.gz');

    expect($command)->toContain('--section=pre-data')
        ->not->toContain('--section=data')
        ->toContain('COPY (SELECT * FROM')
        ->toContain('FROM stdin;')
        ->toContain('setval');
});

it('dumps full tables with their data and schema rules without', function () {
    expect(pgsqlDriver()->dumpTableCommand('orders', false, null, '/tmp/x.sql.gz'))->toContain('--section=data')
        ->and(pgsqlDriver()->dumpTableCommand('orders', true, null, '/tmp/x.sql.gz'))->not->toContain('--section=data');
});

it('creates the schema locally when it is not public', function () {
    $connection = ['host' => 'pg', 'port' => 5432, 'username' => 'app', 'database' => 'dev_app'];

    expect(implode(' ', pgsqlDriver('crm')->recreateDatabaseCommand($connection)))->toContain('CREATE SCHEMA IF NOT EXISTS "crm"')
        ->and(implode(' ', pgsqlDriver()->recreateDatabaseCommand($connection)))->not->toContain('CREATE SCHEMA');
});

it('rejects a schema name that could break out of quoting', function () {
    pgsqlDriver('crm"; DROP');
})->throws(InvalidArgumentException::class);

it('drops statements older psql rejects and creates foreign keys NOT VALID', function () {
    $bin = $this->workspace.'/bin';
    File::ensureDirectoryExists($bin);
    File::put($bin.'/psql', "#!/bin/bash\ncat > \"\$(dirname \"\$0\")/received.sql\"\n");
    chmod($bin.'/psql', 0755);
    File::put($dump = $this->workspace.'/post-data.sql.gz', gzencode(implode("\n", [
        '\\restrict abc123',
        'SET transaction_timeout = 0;',
        'SET statement_timeout = 0;',
        'ALTER TABLE ONLY public.order_items',
        '    ADD CONSTRAINT order_items_order_id_fkey FOREIGN KEY (order_id) REFERENCES public.orders(id) ON DELETE CASCADE;',
        'ALTER TABLE ONLY public.orders',
        '    ADD CONSTRAINT orders_pkey PRIMARY KEY (id);',
        '\\unrestrict abc123',
        '',
    ])));

    $command = pgsqlDriver()->importCommand(['database' => 'dev_app'], $dump);
    exec('PATH='.escapeshellarg($bin.':'.getenv('PATH')).' '.implode(' ', array_map(escapeshellarg(...), $command)), result_code: $exitCode);

    expect($exitCode)->toBe(0)
        ->and(File::get($bin.'/received.sql'))->toBe(implode("\n", [
            'SET statement_timeout = 0;',
            'ALTER TABLE ONLY public.order_items',
            '    ADD CONSTRAINT order_items_order_id_fkey FOREIGN KEY (order_id) REFERENCES public.orders(id) ON DELETE CASCADE NOT VALID;',
            'ALTER TABLE ONLY public.orders',
            '    ADD CONSTRAINT orders_pkey PRIMARY KEY (id);',
            '',
        ]));
});

it('treats deadlocks between parallel imports as conflicts to retry', function () {
    expect(pgsqlDriver()->isLockConflict('psql:<stdin>:3: ERROR:  deadlock detected'))->toBeTrue()
        ->and(pgsqlDriver()->isLockConflict('psql:<stdin>:3: ERROR:  relation "users" does not exist'))->toBeFalse();
});

it('builds replacement expressions in PostgreSQL syntax', function () {
    $email = new ColumnInfo('email', 'citext', ColumnInfo::TEXT);

    expect(pgsqlDriver()->anonymizedExpression($email, new ColumnRule(AnonymizeStrategy::Email), "pe'pper", 'id'))
        ->toBe("CASE WHEN \"email\" IS NULL THEN NULL ELSE 'user_' || left(encode(sha256(convert_to('pe''pper' || \"email\"::text, 'UTF8')), 'hex'), 12) || '@example.test' END");
});

it('copies anonymized tables with an explicit column list', function () {
    $columns = [
        new ColumnInfo('id', 'bigint', primaryKey: true),
        new ColumnInfo('email', 'citext', ColumnInfo::TEXT),
        new ColumnInfo('search', 'tsvector', generated: true),
    ];
    $driver = new PgsqlDriver(['host' => 'db.example.test'], ['database' => 'production']);
    $command = $driver->dumpAnonymizedTableCommand('users', null, $this->workspace.'/users.sql.gz', $columns, ['email' => "'x'"]);

    $dump = runDumpWithStubs($command, [
        'pg_dump' => 'echo "-- pg_dump"',
        'psql' => 'echo "-- psql ${@: -1}"',
    ], $this->workspace);

    expect($dump)->toContain('COPY "public"."users" ("id", "email") FROM stdin;')
        ->toContain('-- psql COPY (SELECT "id", (\'x\')::text AS "email" FROM "public"."users") TO STDOUT')
        ->not->toContain('"search"');
});
