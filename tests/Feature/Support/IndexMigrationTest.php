<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Overthink\DbSnapshot\Analysis\Analysis;
use Overthink\DbSnapshot\Analysis\DateColumn;
use Overthink\DbSnapshot\Analysis\TableInfo;
use Overthink\DbSnapshot\Profile\Profile;
use Overthink\DbSnapshot\Profile\TableMode;
use Overthink\DbSnapshot\Profile\TableRule;
use Overthink\DbSnapshot\Support\IndexMigration;

it('lists only non-indexed date columns that recent rules filter on', function () {
    $analysis = new Analysis('production', now()->toImmutable(), [
        'event_logs' => new TableInfo('event_logs', false, 10, 10, 0, [new DateColumn('created_at', 'timestamp', false)]),
        'orders' => new TableInfo('orders', false, 10, 10, 0, [new DateColumn('created_at', 'timestamp', true)]),
        'imports' => new TableInfo('imports', false, 10, 10, 0, [new DateColumn('imported_at', 'datetime', false)]),
    ]);

    $profile = new Profile('default', TableMode::Full, [
        'event_logs' => new TableRule(TableMode::Recent, 'created_at', months: 3),
        'orders' => new TableRule(TableMode::Recent, 'created_at', months: 3),
        'imports' => new TableRule(TableMode::Schema),
    ]);

    expect(IndexMigration::missingIndexes($profile, $analysis))->toBe(['event_logs' => ['created_at']]);
});

it('generates a migration that adds and drops the indexes', function () {
    Schema::create('event_logs', function (Blueprint $table) {
        $table->id();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('processed_at')->nullable();
    });

    $path = IndexMigration::write($this->workspace.'/migrations', ['event_logs' => ['created_at', 'processed_at']], now()->toImmutable()->setDate(2026, 10, 9)->setTime(8, 30));
    $migration = require $path;

    expect(basename($path))->toBe('2026_10_09_083000_add_snapshot_date_indexes.php');

    $migration->up();
    expect(Schema::hasIndex('event_logs', ['created_at']))->toBeTrue()
        ->and(Schema::hasIndex('event_logs', ['processed_at']))->toBeTrue();

    $migration->down();
    expect(Schema::hasIndex('event_logs', ['created_at']))->toBeFalse();
})->skip(! extension_loaded('pdo_sqlite'), 'Needs pdo_sqlite to run the migration.');
