<?php

use Illuminate\Support\Facades\Process;
use Overthink\DbSnapshot\Analysis\Analysis;
use Overthink\DbSnapshot\Analysis\Analyzer;

function fakeRemoteSchema(): void
{
    $mb = 1024 * 1024;

    Process::preventStrayProcesses();
    Process::fake([
        '*information_schema.TABLES*' => Process::result(implode("\n", [
            "event_logs\tBASE TABLE\t8773897\t".(3000 * $mb)."\t".(276 * $mb),
            "users\tBASE TABLE\t163\t".(1 * $mb)."\t0",
            "order_totals\tVIEW\tNULL\t0\t0",
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

    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'MIN(`created_at`), MAX(`created_at`) FROM `production`.`event_logs`')
        && ! str_contains($process->command, 'processed_at')
        && ! str_contains($process->command, '`users`'), 1);
});

it('sends the password on stdin instead of the command line', function () {
    fakeRemoteSchema();

    app(Analyzer::class)->tables();

    Process::assertRan(fn ($process) => $process->input === "s3cret\n" && ! str_contains($process->command, 's3cret'));
});

it('survives a save and load of the analysis file', function () {
    fakeRemoteSchema();

    $path = $this->workspace.'/analysis.json';
    app(Analyzer::class)->analyze(100)->save($path);

    expect(Analysis::load($path)->table('event_logs')->dateColumn('created_at')->max)->toBe('2026-10-01 08:00:00');
});
