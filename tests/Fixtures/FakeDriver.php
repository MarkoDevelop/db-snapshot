<?php

namespace Overthink\DbSnapshot\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Overthink\DbSnapshot\Analysis\TableInfo;
use Overthink\DbSnapshot\Contracts\Driver;

/**
 * A driver whose commands just name what they would do.
 */
class FakeDriver implements Driver
{
    public function name(): string
    {
        return 'fake';
    }

    public function database(): string
    {
        return 'source';
    }

    public function localConnectionDrivers(): array
    {
        return ['fakesql'];
    }

    public function defaults(): array
    {
        return ['port' => 1234, 'username' => 'fake'];
    }

    public function serverVersion(): string
    {
        return '1.0';
    }

    public function tables(): array
    {
        return ['orders' => new TableInfo('orders', false, 1, 1, 0)];
    }

    public function dateColumns(): array
    {
        return [];
    }

    public function dateRanges(array $columns): array
    {
        return [];
    }

    public function recentCondition(string $column, CarbonImmutable $since): string
    {
        return "\"{$column}\" >= '{$since->toDateString()}'";
    }

    public function dumpTableCommand(string $table, bool $schemaOnly, ?string $where, string $file): string
    {
        return "fake-dump {$table} > {$file}";
    }

    public function dumpViewsCommand(array $views, string $file): ?string
    {
        return null;
    }

    public function dumpRoutinesCommand(string $file): ?string
    {
        return null;
    }

    public function dumpPostDataCommand(array $tables, string $file): ?string
    {
        return null;
    }

    public function remoteInput(): string
    {
        return '';
    }

    public function recreateDatabaseCommand(array $connection): string
    {
        return "fake-recreate {$connection['database']}";
    }

    public function importCommand(array $connection, string $file, bool $continueOnError = false): string
    {
        return "fake-import {$file}";
    }

    public function localEnvironment(array $connection): array
    {
        return [];
    }
}
