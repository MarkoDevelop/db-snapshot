<?php

namespace Overthink\DbSnapshot\Contracts;

use Carbon\CarbonImmutable;
use Overthink\DbSnapshot\Analysis\DateColumn;
use Overthink\DbSnapshot\Analysis\TableInfo;

/**
 * Everything database-specific: reading and dumping the source database over
 * SSH, and importing the dumps into a local database.
 *
 * Register your own with DbSnapshot::extend('name', fn ($app) => new MyDriver(...))
 * and select it with SNAPSHOT_DRIVER=name.
 */
interface Driver
{
    /**
     * Short name stored in snapshot manifests, e.g. "mysql".
     */
    public function name(): string;

    /**
     * Name of the source database.
     */
    public function database(): string;

    /**
     * Laravel connection drivers this driver can restore into, e.g. ['mysql', 'mariadb'].
     *
     * @return list<string>
     */
    public function localConnectionDrivers(): array;

    /**
     * Defaults for settings the user did not configure.
     *
     * @return array{port: int, username: string}
     */
    public function defaults(): array;

    public function serverVersion(): string;

    /**
     * Tables and views of the source database, largest first, without date columns.
     *
     * @return array<string, TableInfo>
     */
    public function tables(): array;

    /**
     * Date-like columns per table, with whether each leads an index.
     *
     * @return array<string, list<DateColumn>>
     */
    public function dateColumns(): array;

    /**
     * MIN and MAX of the given (indexed) columns.
     *
     * @param  array<string, list<string>>  $columns  table => columns
     * @return array<string, array<string, array{0: ?string, 1: ?string}>>
     */
    public function dateRanges(array $columns): array;

    /**
     * SQL condition selecting rows with $column at or after $since.
     */
    public function recentCondition(string $column, CarbonImmutable $since): string;

    /**
     * Local shell command that streams a gzipped dump of one table into $file.
     *
     * @param  ?string  $where  SQL condition, or null for all rows
     */
    public function dumpTableCommand(string $table, bool $schemaOnly, ?string $where, string $file): string;

    /**
     * Local shell command that dumps the definitions of $views into $file, or null if unsupported.
     *
     * @param  list<string>  $views
     */
    public function dumpViewsCommand(array $views, string $file): ?string;

    /**
     * Local shell command that dumps stored routines into $file, or null if unsupported.
     */
    public function dumpRoutinesCommand(string $file): ?string;

    /**
     * Standard input for every remote command (e.g. the password line).
     */
    public function remoteInput(): string;

    /**
     * Command that drops and recreates the local database.
     *
     * @param  array<string, mixed>  $connection  a Laravel database connection config
     * @return string|list<string>
     */
    public function recreateDatabaseCommand(array $connection): string|array;

    /**
     * Command that imports one gzipped dump into the local database.
     *
     * @param  array<string, mixed>  $connection
     * @param  bool  $continueOnError  keep going after failing statements (used for views and routines)
     * @return string|list<string>
     */
    public function importCommand(array $connection, string $file, bool $continueOnError = false): string|array;

    /**
     * Environment variables for the local client commands (e.g. the password).
     *
     * @param  array<string, mixed>  $connection
     * @return array<string, string>
     */
    public function localEnvironment(array $connection): array;
}
