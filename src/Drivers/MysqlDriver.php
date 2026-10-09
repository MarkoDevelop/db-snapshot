<?php

namespace Overthink\DbSnapshot\Drivers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Overthink\DbSnapshot\Analysis\ColumnInfo;
use Overthink\DbSnapshot\Analysis\DateColumn;
use Overthink\DbSnapshot\Analysis\TableInfo;
use Overthink\DbSnapshot\Contracts\AnonymizesColumns;
use Overthink\DbSnapshot\Contracts\RetriesConflictingImports;
use Overthink\DbSnapshot\Profile\AnonymizeStrategy;
use Overthink\DbSnapshot\Profile\ColumnRule;

/**
 * MySQL and MariaDB: information_schema, mysqldump on the server and the
 * mysql client locally.
 */
class MysqlDriver extends SshDriver implements AnonymizesColumns, RetriesConflictingImports
{
    /**
     * Dumps from a GTID-enabled server start with SET @@GLOBAL.GTID_PURGED.
     * Only the first per-table import could apply it, so it's dropped. The
     * statement can span several lines and ends with a semicolon.
     */
    private const STRIP_GTID_PURGED = "awk '/^SET @@GLOBAL\\.GTID_PURGED/ { skip = 1 } !skip { print } skip && /;[[:space:]]*$/ { skip = 0 }'";

    /**
     * @param  array{host?: ?string, user?: ?string, port?: int, key?: ?string, options?: list<string>}  $ssh
     * @param  array{host?: ?string, port?: ?int, username?: ?string, password?: ?string, database?: ?string, nice?: ?string}  $remote
     * @param  list<string>  $dumpOptions
     * @param  bool|'auto'  $skipGtidPurged  add --set-gtid-purged=OFF; "auto" does so unless the server's mysqldump is MariaDB's, which has no such option
     */
    public function __construct(
        array $ssh,
        array $remote,
        protected readonly array $dumpOptions = [],
        protected readonly bool|string $skipGtidPurged = 'auto',
    ) {
        parent::__construct($ssh, $remote);
    }

    private ?bool $remoteDumpIsMariaDb = null;

    public function name(): string
    {
        return 'mysql';
    }

    public function localConnectionDrivers(): array
    {
        return ['mysql', 'mariadb'];
    }

    public function defaults(): array
    {
        return ['port' => 3306, 'username' => 'root'];
    }

    public function serverVersion(): string
    {
        return (string) ($this->select('SELECT VERSION()')[0][0] ?? '');
    }

    public function tables(): array
    {
        $rows = $this->select(
            'SELECT TABLE_NAME, TABLE_TYPE, IFNULL(TABLE_ROWS, 0), IFNULL(DATA_LENGTH, 0), IFNULL(INDEX_LENGTH, 0)'
            .' FROM information_schema.TABLES WHERE TABLE_SCHEMA = '.$this->quote($this->database())
            .' ORDER BY IFNULL(DATA_LENGTH, 0) + IFNULL(INDEX_LENGTH, 0) DESC, TABLE_NAME'
        );

        $tables = [];

        foreach ($rows as [$name, $type, $tableRows, $dataBytes, $indexBytes]) {
            $tables[$name] = new TableInfo($name, $type === 'VIEW', (int) $tableRows, (int) $dataBytes, (int) $indexBytes);
        }

        return $tables;
    }

    public function dateColumns(): array
    {
        $rows = $this->select(
            'SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE, EXISTS('
            .'SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA = c.TABLE_SCHEMA'
            .' AND s.TABLE_NAME = c.TABLE_NAME AND s.COLUMN_NAME = c.COLUMN_NAME AND s.SEQ_IN_INDEX = 1)'
            .' FROM information_schema.COLUMNS c'
            .' WHERE c.TABLE_SCHEMA = '.$this->quote($this->database())." AND c.DATA_TYPE IN ('date', 'datetime', 'timestamp')"
            .' ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION'
        );

        $columns = [];

        foreach ($rows as [$table, $column, $type, $indexed]) {
            $columns[$table][] = new DateColumn($column, $type, $indexed === '1');
        }

        return $columns;
    }

    public function dateRanges(array $columns): array
    {
        $selects = [];

        foreach ($columns as $table => $tableColumns) {
            foreach ($tableColumns as $column) {
                $selects[] = sprintf(
                    'SELECT %s, %s, MIN(%s), MAX(%s) FROM %s.%s',
                    $this->quote($table),
                    $this->quote($column),
                    $this->identifier($column),
                    $this->identifier($column),
                    $this->identifier($this->database()),
                    $this->identifier($table),
                );
            }
        }

        if ($selects === []) {
            return [];
        }

        $ranges = [];

        foreach ($this->select(implode(' UNION ALL ', $selects)) as [$table, $column, $min, $max]) {
            $ranges[$table][$column] = [$min, $max];
        }

        return $ranges;
    }

    public function columns(): array
    {
        $rows = $this->select(
            'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, EXTRA, COLUMN_KEY, IS_NULLABLE'
            .' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '.$this->quote($this->database())
            .' ORDER BY TABLE_NAME, ORDINAL_POSITION'
        );

        $columns = [];

        foreach ($rows as [$table, $column, $type, $maxLength, $extra, $key, $nullable]) {
            $type = strtolower((string) $type);

            $columns[$table][] = new ColumnInfo(
                $column,
                $type,
                match (true) {
                    in_array($type, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'], true) => ColumnInfo::TEXT,
                    in_array($type, ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit', 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection'], true) => ColumnInfo::BINARY,
                    default => ColumnInfo::OTHER,
                },
                in_array($type, ['char', 'varchar'], true) && $maxLength !== null ? (int) $maxLength : null,
                str_contains(strtoupper((string) $extra), 'GENERATED'),
                $key === 'PRI',
                $nullable === 'YES',
            );
        }

        return $columns;
    }

    public function anonymizedExpression(ColumnInfo $column, ColumnRule $rule, string $salt, ?string $primaryKey): string
    {
        $value = $this->identifier($column->name);
        $hash = 'LEFT(SHA2(CONCAT('.$this->quote($salt).', '.$value.'), 256), 12)';

        $expression = match ($rule->strategy) {
            AnonymizeStrategy::Null => 'NULL',
            AnonymizeStrategy::Empty => "''",
            AnonymizeStrategy::Fixed => $this->quote((string) $rule->value),
            AnonymizeStrategy::Hash => $hash,
            AnonymizeStrategy::Email => "CONCAT('user_', {$hash}, '@example.test')",
            AnonymizeStrategy::Template => 'CONCAT('.implode(', ', $this->templateParts((string) $rule->value, $hash, $primaryKey)).')',
        };

        if ($column->maxLength !== null && $rule->strategy !== AnonymizeStrategy::Null) {
            $expression = "LEFT({$expression}, {$column->maxLength})";
        }

        return $rule->strategy === AnonymizeStrategy::Null ? $expression : "IF({$value} IS NULL, NULL, {$expression})";
    }

    public function dumpAnonymizedTableCommand(string $table, ?string $where, string $file, array $columns, array $expressions): string
    {
        $columns = array_values(array_filter($columns, fn (ColumnInfo $column): bool => ! $column->generated));

        $values = array_map(function (ColumnInfo $column) use ($expressions): string {
            if (isset($expressions[$column->name])) {
                return 'QUOTE('.$expressions[$column->name].')';
            }

            $value = $this->identifier($column->name);

            return $column->kind === ColumnInfo::BINARY
                ? "IF({$value} IS NULL, 'NULL', CONCAT('0x', HEX({$value})))"
                : "QUOTE({$value})";
        }, $columns);

        $insert = 'INSERT INTO '.$this->identifier($table).' ('.implode(', ', array_map(fn (ColumnInfo $column): string => $this->identifier($column->name), $columns)).') VALUES (';
        $select = 'SELECT CONCAT('.$this->quote($insert).", CONCAT_WS(', ', ".implode(', ', $values)."), ');')"
            .' FROM '.$this->identifier($this->database()).'.'.$this->identifier($table)
            .($where !== null ? " WHERE {$where}" : '');

        $mysqldump = fn (array $options): string => self::shellCommand(['mysqldump', ...$this->remoteConnectionOptions(), ...$this->dumpOptions, ...$this->gtidOptions(), ...$options, $this->database(), $table]);

        return $this->dumpToFile(self::script([
            // Definition first, triggers after the rows, like mysqldump itself.
            $mysqldump(['--no-data', '--skip-triggers']),
            // The same session settings mysqldump uses for its rows.
            self::printLine("SET NAMES utf8mb4; SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'; SET autocommit = 0;"),
            self::shellCommand(['mysql', ...$this->remoteConnectionOptions(), '--default-character-set=utf8mb4', '--batch', '--raw', '--quick', '--skip-column-names', '-e', $select]),
            self::printLine('COMMIT;'),
            $mysqldump(['--no-create-info', '--no-data', '--triggers']),
        ]), $file);
    }

    public function recentCondition(string $column, CarbonImmutable $since): string
    {
        return $this->identifier($column)." >= '".$since->format('Y-m-d H:i:s')."'";
    }

    public function dumpTableCommand(string $table, bool $schemaOnly, ?string $where, string $file): string
    {
        $options = match (true) {
            $schemaOnly => ['--no-data'],
            $where !== null => ['--where='.$where],
            default => [],
        };

        return $this->mysqldump($options, [$table], $file);
    }

    public function dumpViewsCommand(array $views, string $file): ?string
    {
        return $this->mysqldump(['--no-data', '--skip-triggers'], $views, $file);
    }

    public function dumpRoutinesCommand(string $file): ?string
    {
        return $this->mysqldump(['--routines', '--no-create-info', '--no-data', '--no-create-db', '--skip-triggers'], [], $file);
    }

    public function dumpPostDataCommand(array $tables, string $file): ?string
    {
        return null;
    }

    public function recreateDatabaseCommand(array $connection): array
    {
        $database = $this->localDatabase($connection);
        $charset = $connection['charset'] ?? 'utf8mb4';
        $collation = $connection['collation'] ?? 'utf8mb4_unicode_ci';

        return $this->client($connection, [
            '-e', "DROP DATABASE IF EXISTS `{$database}`; CREATE DATABASE `{$database}` CHARACTER SET {$charset} COLLATE {$collation}",
        ]);
    }

    public function importCommand(array $connection, string $file, bool $continueOnError = false): array
    {
        $client = self::shellCommand($this->client($connection, [
            '--init-command=SET SESSION sql_log_bin = 0, foreign_key_checks = 0, unique_checks = 0',
            ...($continueOnError ? ['--force'] : []),
            $this->localDatabase($connection),
        ]));

        return ['bash', '-o', 'pipefail', '-c', 'gzip -dc '.escapeshellarg($file).' | '.self::STRIP_GTID_PURGED.' | '.$client];
    }

    /**
     * Creating tables with foreign keys locks the referenced tables' metadata,
     * so parallel imports can deadlock (1213) or time out waiting (1205).
     */
    public function isLockConflict(string $errorOutput): bool
    {
        return (bool) preg_match('/ERROR (1213|1205) /', $errorOutput);
    }

    public function localEnvironment(array $connection): array
    {
        return ['MYSQL_PWD' => (string) ($connection['password'] ?? '')];
    }

    protected function passwordVariable(): string
    {
        return 'MYSQL_PWD';
    }

    protected function queryScript(string $sql): string
    {
        return self::shellCommand(['mysql', ...$this->remoteConnectionOptions(), '--batch', '--skip-column-names', '-e', $sql]);
    }

    /**
     * @param  list<string>  $options
     * @param  list<string>  $tables
     */
    private function mysqldump(array $options, array $tables, string $file): string
    {
        return $this->dumpToFile(
            self::shellCommand(['mysqldump', ...$this->remoteConnectionOptions(), ...$this->dumpOptions, ...$this->gtidOptions(), ...$options, $this->database(), ...$tables]),
            $file,
        );
    }

    /**
     * Dumps from GTID-enabled MySQL servers otherwise carry SET @@GLOBAL.GTID_PURGED.
     *
     * @return list<string>
     */
    private function gtidOptions(): array
    {
        $skip = $this->skipGtidPurged === 'auto' ? ! $this->remoteDumpIsMariaDb() : (bool) $this->skipGtidPurged;

        return $skip ? ['--set-gtid-purged=OFF'] : [];
    }

    private function remoteDumpIsMariaDb(): bool
    {
        return $this->remoteDumpIsMariaDb ??= str_contains(
            strtolower(Process::run($this->connection()->command('mysqldump --version'))->output()),
            'mariadb',
        );
    }

    /**
     * @return list<string>
     */
    private function remoteConnectionOptions(): array
    {
        return ['--host='.$this->host(), '--port='.$this->port(), '--user='.$this->username()];
    }

    /**
     * @param  array<string, mixed>  $connection
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function client(array $connection, array $arguments): array
    {
        return [
            'mysql',
            '--host='.($connection['host'] ?? '127.0.0.1'),
            '--port='.($connection['port'] ?? 3306),
            '--user='.($connection['username'] ?? 'root'),
            ...$arguments,
        ];
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function localDatabase(array $connection): string
    {
        $database = (string) ($connection['database'] ?? '');

        if (! preg_match('/^[A-Za-z0-9_$-]+$/', $database)) {
            throw new InvalidArgumentException("Invalid database name [{$database}].");
        }

        return $database;
    }

    /**
     * Template text split into SQL pieces for CONCAT(): quoted literals, the
     * hash for {hash} and the primary key for {id}.
     *
     * @return list<string>
     */
    private function templateParts(string $template, string $hash, ?string $primaryKey): array
    {
        $parts = [];

        foreach (preg_split('/(\{hash\}|\{id\})/', $template, flags: PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $parts[] = match ($part) {
                '{hash}' => $hash,
                '{id}' => 'CAST('.$this->identifier((string) $primaryKey).' AS CHAR)',
                default => $this->quote($part),
            };
        }

        return $parts;
    }

    private function identifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }
}
