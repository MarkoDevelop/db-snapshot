<?php

use Illuminate\Support\Facades\Process;
use Overthink\DbSnapshot\Analysis\Analysis;
use Overthink\DbSnapshot\Analysis\Analyzer;

function fakeRemoteSchema(): void
{
    $mb = 1024 * 1024;

    Process::preventStrayProcesses();
    Process::fake([
        '*KEY_COLUMN_USAGE*' => Process::result(''),
        '*SELECT DISTINCT TABLE_NAME, COLUMN_NAME*' => Process::result(''),
        '*information_schema.TABLES*' => Process::result(implode("\n", [
            "event_logs\tBASE TABLE\t8773897\t".(3000 * $mb)."\t".(276 * $mb),
            "users\tBASE TABLE\t163\t".(1 * $mb)."\t0",
            "order_totals\tVIEW\tNULL\t0\t0",
        ])),
        '*IS_NULLABLE*' => Process::result(implode("\n", [
            "event_logs\tid\tbigint\tNULL\tauto_increment\tPRI\tNO",
            "event_logs\tmessage\tvarchar\t191\t\t\tYES",
            "event_logs\tuser_email\tvarchar\t191\t\t\tYES",
            "event_logs\tcreated_at\ttimestamp\tNULL\t\tMUL\tYES",
        ])),
        '*information_schema.COLUMNS*' => Process::result(implode("\n", [
            "event_logs\tcreated_at\ttimestamp\t1",
            "event_logs\tprocessed_at\tdatetime\t0",
            "users\tcreated_at\ttimestamp\t1",
        ])),
        '*UNION ALL*' => Process::result("event_logs\tcreated_at\t2021-03-01 10:00:00\t2026-10-01 08:00:00"),
        '*MIN(*' => Process::result("event_logs\tcreated_at\t2021-03-01 10:00:00\t2026-10-01 08:00:00"),
    ]);
}

it('records sizes, date columns and the range of indexed columns on large tables', function () {
    fakeRemoteSchema();

    $analysis = app(Analyzer::class)->analyze(rangeThresholdMb: 100);
    $events = $analysis->table('event_logs');

    expect(array_keys($analysis->tables))->toBe(['event_logs', 'users', 'order_totals'])
        ->and($events->megabytes())->toBe(3276.0)
        ->and($events->rows)->toBe(8773897)
        ->and($events->dateColumn('created_at')->toArray())->toBe([
            'name' => 'created_at', 'type' => 'timestamp', 'indexed' => true, 'min' => '2021-03-01 10:00:00', 'max' => '2026-10-01 08:00:00',
        ])
        ->and($events->dateColumn('processed_at')->indexed)->toBeFalse()
        ->and($analysis->table('users')->dateColumn('created_at')->min)->toBeNull()
        ->and($analysis->table('order_totals')->isView)->toBeTrue();
});

it('reads ranges only for indexed columns of tables above the threshold', function () {
    fakeRemoteSchema();

    app(Analyzer::class)->analyze(rangeThresholdMb: 100);

    Process::assertRanTimes(fn ($process) => str_contains(commandLine($process), 'MIN(`created_at`), MAX(`created_at`) FROM `production`.`event_logs`')
        && ! str_contains(commandLine($process), 'processed_at')
        && ! str_contains(commandLine($process), '`users`'), 1);
});

it('sends the password on stdin instead of the command line', function () {
    fakeRemoteSchema();

    app(Analyzer::class)->tables();

    Process::assertRan(fn ($process) => $process->input === "s3cret\n" && ! str_contains(commandLine($process), 's3cret'));
});

it('survives a save and load of the analysis file', function () {
    fakeRemoteSchema();

    $path = $this->workspace.'/analysis.json';
    app(Analyzer::class)->analyze(100)->save($path);

    expect(Analysis::load($path)->table('event_logs')->dateColumn('created_at')->max)->toBe('2026-10-01 08:00:00');
});

it('records every column with what anonymizing needs to know', function () {
    fakeRemoteSchema();

    $events = app(Analyzer::class)->analyze(100)->table('event_logs');

    expect($events->column('id')->toArray())->toMatchArray(['kind' => 'other', 'primary_key' => true, 'nullable' => false])
        ->and($events->column('message')->toArray())->toMatchArray(['kind' => 'text', 'max_length' => 191, 'nullable' => true]);
});

it('writes personal data suggestions only when anonymization is on', function (bool $enabled, array $expected) {
    fakeRemoteSchema();
    config()->set('db-snapshot.anonymize.enabled', $enabled);

    $events = app(Analyzer::class)->analyze(100)->table('event_logs');

    expect(array_map(fn ($rule) => $rule->toJson(), $events->personalData))->toBe($expected);
})->with([
    'on' => [true, ['user_email' => 'email']],
    'off' => [false, []],
]);

it('records foreign keys and guesses <name>_id columns, skipping polymorphic ones', function () {
    Process::preventStrayProcesses();
    Process::fake([
        '*KEY_COLUMN_USAGE*' => Process::result(implode("\n", [
            "order_items\torder_id\torders\tid",
            "orders\tcurrency_code\tcurrencies\tcode",
        ])),
        '*SELECT DISTINCT TABLE_NAME, COLUMN_NAME*' => Process::result(implode("\n", ["order_items\torder_id", "orders\tid"])),
        '*information_schema.TABLES*' => Process::result(implode("\n", [
            "order_items\tBASE TABLE\t10\t1000\t0",
            "orders\tBASE TABLE\t10\t1000\t0",
            "currencies\tBASE TABLE\t10\t1000\t0",
            "users\tBASE TABLE\t10\t1000\t0",
            "comments\tBASE TABLE\t10\t1000\t0",
            "roles\tBASE TABLE\t10\t1000\t0",
            "role_user\tBASE TABLE\t10\t1000\t0",
            "user_profiles\tBASE TABLE\t10\t1000\t0",
            "payments\tBASE TABLE\t10\t1000\t0",
        ])),
        '*IS_NULLABLE*' => Process::result(implode("\n", [
            "order_items\tid\tint\tNULL\t\tPRI\tNO",
            "order_items\torder_id\tint\tNULL\t\tMUL\tNO",
            "orders\tid\tint\tNULL\t\tPRI\tNO",
            "orders\tuser_id\tint\tNULL\t\t\tNO",
            "orders\tcurrency_code\tchar\t3\t\t\tNO",
            "orders\twarehouse_id\tint\tNULL\t\t\tYES",
            "users\tid\tint\tNULL\t\tPRI\tNO",
            "comments\tid\tint\tNULL\t\tPRI\tNO",
            "comments\tcommentable_id\tint\tNULL\t\t\tNO",
            "comments\tcommentable_type\tvarchar\t191\t\t\tNO",
            "comments\tuser_id\tint\tNULL\t\t\tNO",
            "roles\tid\tint\tNULL\t\tPRI\tNO",
            "role_user\trole_id\tint\tNULL\t\tPRI\tNO",
            "role_user\tuser_id\tint\tNULL\t\tPRI\tNO",
            "user_profiles\tuser_id\tint\tNULL\t\tPRI\tNO",
            "payments\tid\tbigint\tNULL\t\tPRI\tNO",
            "orders\tpayment_id\tvarchar\t64\t\t\tYES",
        ])),
        '*information_schema.COLUMNS*' => Process::result(''),
    ]);

    $analysis = app(Analyzer::class)->analyze(100);
    $relations = fn (string $table) => array_map(fn ($relation) => $relation->toArray(), $analysis->table($table)->relations);

    expect($relations('order_items'))->toBe([
        ['column' => 'order_id', 'parent' => 'orders', 'references' => 'id', 'guessed' => false, 'indexed' => true],
    ])
        ->and($relations('orders'))->toBe([
            ['column' => 'currency_code', 'parent' => 'currencies', 'references' => 'code', 'guessed' => false, 'indexed' => false],
            ['column' => 'user_id', 'parent' => 'users', 'references' => 'id', 'guessed' => true, 'indexed' => false],
        ])
        ->and($relations('comments'))->toBe([
            ['column' => 'user_id', 'parent' => 'users', 'references' => 'id', 'guessed' => true, 'indexed' => false],
        ])
        ->and($relations('role_user'))->toBe([
            ['column' => 'role_id', 'parent' => 'roles', 'references' => 'id', 'guessed' => true, 'indexed' => false],
            ['column' => 'user_id', 'parent' => 'users', 'references' => 'id', 'guessed' => true, 'indexed' => false],
        ])
        ->and($relations('roles'))->toBe([])
        ->and($relations('user_profiles'))->toBe([
            ['column' => 'user_id', 'parent' => 'users', 'references' => 'id', 'guessed' => true, 'indexed' => false],
        ]);
});
