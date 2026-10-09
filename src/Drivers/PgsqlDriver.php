<?php

namespace Overthink\DbSnapshot\Drivers;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Overthink\DbSnapshot\Analysis\DateColumn;
use Overthink\DbSnapshot\Analysis\TableInfo;

/**
 * PostgreSQL: the pg_catalog, pg_dump and psql on the server, psql locally.
 *
 * pg_dump has no --where, so filtered tables are dumped as their definition
 * followed by COPY rows from a SELECT, in the same format pg_dump writes.
 * Indexes, constraints and triggers go into a separate post-data dump that
 * is restored after all rows are in; foreign keys are created NOT VALID, so
 * rows that point at filtered-out rows don't stop the restore.
 */
class PgsqlDriver extends SshDriver
{
    /**
     * @param  array{host?: ?string, user?: ?string, port?: int, key?: ?string, options?: list<string>}  $ssh
     * @param  array{host?: ?string, port?: ?int, username?: ?string, password?: ?string, database?: ?string, nice?: ?string}  $remote
     * @param  list<string>  $dumpOptions  extra pg_dump options
     */
    public function __construct(
        array $ssh,
        array $remote,
        protected readonly string $schema = 'public',
        protected readonly array $dumpOptions = [],
        protected readonly string $maintenanceDatabase = 'postgres',
    ) {
        parent::__construct($ssh, $remote);

        if (! preg_match('/^[A-Za-z0-9_$-]+$/', $schema)) {
            throw new InvalidArgumentException("Invalid schema name [{$schema}].");
        }
    }

    public function name(): string
    {
        return 'pgsql';
    }

    public function localConnectionDrivers(): array
    {
        return ['pgsql'];
    }

    public function defaults(): array
    {
        return ['port' => 5432, 'username' => 'postgres'];
    }

    public function serverVersion(): string
    {
        return (string) ($this->select('SHOW server_version')[0][0] ?? '');
    }

    public function tables(): array
    {
        $rows = $this->select(
            "SELECT c.relname, CASE WHEN c.relkind IN ('v', 'm') THEN 'VIEW' ELSE 'BASE TABLE' END,"
            .' GREATEST(c.reltuples, 0)::bigint,'
            ." CASE WHEN c.relkind = 'v' THEN 0 ELSE pg_table_size(c.oid) END,"
            ." CASE WHEN c.relkind = 'v' THEN 0 ELSE pg_indexes_size(c.oid) END"
            .' FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace'
            .' WHERE n.nspname = '.$this->quote($this->schema)." AND c.relkind IN ('r', 'p', 'v', 'm')"
            .' ORDER BY pg_total_relation_size(c.oid) DESC, c.relname'
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
            'SELECT c.relname, a.attname, format_type(a.atttypid, NULL),'
            .' EXISTS (SELECT 1 FROM pg_index i WHERE i.indrelid = c.oid AND i.indkey[0] = a.attnum)'
            .' FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace'
            .' WHERE n.nspname = '.$this->quote($this->schema)." AND c.relkind IN ('r', 'p')"
            .' AND a.attnum > 0 AND NOT a.attisdropped'
            ." AND a.atttypid IN ('date'::regtype, 'timestamp'::regtype, 'timestamptz'::regtype)"
            .' ORDER BY c.relname, a.attnum'
        );

        $columns = [];

        foreach ($rows as [$table, $column, $type, $indexed]) {
            $columns[$table][] = new DateColumn($column, $type, $indexed === 't');
        }

        return $columns;
    }

    public function dateRanges(array $columns): array
    {
        $selects = [];

        foreach ($columns as $table => $tableColumns) {
            foreach ($tableColumns as $column) {
                $selects[] = sprintf(
                    'SELECT %s, %s, MIN(%s)::text, MAX(%s)::text FROM %s',
                    $this->quote($table),
                    $this->quote($column),
                    $this->identifier($column),
                    $this->identifier($column),
                    $this->qualified($table),
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

    public function recentCondition(string $column, CarbonImmutable $since): string
    {
        return $this->identifier($column)." >= '".$since->format('Y-m-d H:i:s')."'";
    }

    public function dumpTableCommand(string $table, bool $schemaOnly, ?string $where, string $file): string
    {
        $qualified = $this->qualified($table);

        // Dropping with CASCADE lets snapshot:refresh-table replace a table
        // that other tables' foreign keys point at.
        $steps = [self::printLine("DROP TABLE IF EXISTS {$qualified} CASCADE;")];

        if ($schemaOnly || $where === null) {
            $steps[] = $this->pgDump([
                '--section=pre-data',
                ...($schemaOnly ? [] : ['--section=data']),
                '--table='.$qualified,
            ]);
        } else {
            $steps[] = $this->pgDump(['--section=pre-data', '--table='.$qualified]);
            $steps[] = self::printLine("COPY {$qualified} FROM stdin;");
            $steps[] = self::shellCommand(['psql', ...$this->remoteConnectionOptions(), '-X', '-q', '-v', 'ON_ERROR_STOP=1', '-c', "COPY (SELECT * FROM {$qualified} WHERE {$where}) TO STDOUT"]);
            $steps[] = self::printLine('\\.');
            $steps[] = self::printLine($this->resetSequencesSql($qualified));
        }

        return $this->dumpToFile(self::script($steps), $file);
    }

    public function dumpViewsCommand(array $views, string $file): ?string
    {
        return $this->dumpToFile($this->pgDump([
            '--schema-only',
            ...array_map(fn (string $view): string => '--table='.$this->qualified($view), $views),
        ]), $file);
    }

    public function dumpRoutinesCommand(string $file): ?string
    {
        $schema = $this->quote($this->schema);

        $sql = 'SELECT statement FROM ('
            ." SELECT 0 AS position, 'CREATE EXTENSION IF NOT EXISTS ' || quote_ident(e.extname) || ';' AS statement"
            ." FROM pg_extension e WHERE e.extname <> 'plpgsql'"
            .' UNION ALL'
            ." SELECT 1, pg_get_functiondef(p.oid) || ';'"
            .' FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace'
            ." WHERE n.nspname = {$schema} AND p.prokind IN ('f', 'p')"
            ." AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = p.oid AND d.deptype = 'e')"
            .') routines ORDER BY position';

        return $this->dumpToFile(self::script([
            self::printLine('SET check_function_bodies = false;'),
            self::shellCommand(['psql', ...$this->remoteConnectionOptions(), '-X', '-q', '-A', '-t', '-v', 'ON_ERROR_STOP=1', '-c', $sql]),
        ]), $file);
    }

    public function dumpPostDataCommand(array $tables, string $file): ?string
    {
        return $this->dumpToFile($this->pgDump([
            '--section=post-data',
            ...array_map(fn (string $table): string => '--table='.$this->qualified($table), $tables),
        ]), $file);
    }

    public function recreateDatabaseCommand(array $connection): array
    {
        $database = $this->identifier($this->localDatabase($connection));
        $encoding = strtoupper((string) ($connection['charset'] ?? 'utf8'));

        $commands = [self::shellCommand([
            ...$this->client($connection, $this->maintenanceDatabase),
            '-c', "DROP DATABASE IF EXISTS {$database} WITH (FORCE)",
            '-c', "CREATE DATABASE {$database} ENCODING '{$encoding}' TEMPLATE template0",
        ])];

        if ($this->schema !== 'public') {
            $commands[] = self::shellCommand([
                ...$this->client($connection, $this->localDatabase($connection)),
                '-c', 'CREATE SCHEMA IF NOT EXISTS '.$this->identifier($this->schema),
            ]);
        }

        return ['bash', '-o', 'pipefail', '-c', implode(' && ', $commands)];
    }

    public function importCommand(array $connection, string $file, bool $continueOnError = false): array
    {
        $filter = self::shellCommand([
            'sed', '-E',
            // Newer pg_dump versions write statements older psql clients reject.
            '-e', '/^SET transaction_timeout/d',
            '-e', '/^\\\\(un)?restrict /d',
            // Rows may point at rows a filter left out, so don't validate them.
            '-e', 's/^(    ADD CONSTRAINT .* FOREIGN KEY .*);$/\\1 NOT VALID;/',
        ]);

        $client = self::shellCommand([
            ...$this->client($connection, $this->localDatabase($connection), stopOnError: ! $continueOnError),
        ]);

        return ['bash', '-o', 'pipefail', '-c', 'gzip -dc '.escapeshellarg($file).' | '.$filter.' | '.$client];
    }

    public function localEnvironment(array $connection): array
    {
        return [
            'PGPASSWORD' => (string) ($connection['password'] ?? ''),
            'PGOPTIONS' => '-c client_min_messages=warning',
        ];
    }

    public function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    protected function passwordVariable(): string
    {
        return 'PGPASSWORD';
    }

    protected function queryScript(string $sql): string
    {
        return self::shellCommand([
            'psql', ...$this->remoteConnectionOptions(),
            '-X', '-q', '-A', '-t', '-F', "\t", '-P', 'null=NULL', '-v', 'ON_ERROR_STOP=1',
            '-c', $sql,
        ]);
    }

    /**
     * After COPY of filtered rows, move each sequence the table owns past
     * the highest value, so new rows don't collide.
     */
    private function resetSequencesSql(string $qualified): string
    {
        $table = $this->quote($qualified);

        return 'DO $$ DECLARE r record; BEGIN'
            ." FOR r IN SELECT a.attname, pg_get_serial_sequence({$table}, a.attname) AS seq"
            ." FROM pg_attribute a WHERE a.attrelid = {$table}::regclass AND a.attnum > 0 AND NOT a.attisdropped LOOP"
            ." IF r.seq IS NOT NULL THEN EXECUTE format('SELECT setval(%L, COALESCE((SELECT MAX(%I) FROM %s), 0) + 1, false)', r.seq, r.attname, {$table}::regclass); END IF;"
            .' END LOOP; END $$;';
    }

    /**
     * @param  list<string>  $options
     */
    private function pgDump(array $options): string
    {
        return self::shellCommand(['pg_dump', ...$this->remoteConnectionOptions(), '--no-owner', '--no-privileges', ...$this->dumpOptions, ...$options]);
    }

    /**
     * @return list<string>
     */
    private function remoteConnectionOptions(): array
    {
        return ['--host='.$this->host(), '--port='.$this->port(), '--username='.$this->username(), '--dbname='.$this->database()];
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return list<string>
     */
    private function client(array $connection, string $database, bool $stopOnError = true): array
    {
        return [
            'psql',
            '--host='.($connection['host'] ?? '127.0.0.1'),
            '--port='.($connection['port'] ?? 5432),
            '--username='.($connection['username'] ?? 'postgres'),
            '--dbname='.$database,
            '-X', '-q', '-v', 'ON_ERROR_STOP='.($stopOnError ? '1' : '0'),
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

    private function qualified(string $table): string
    {
        return $this->identifier($this->schema).'.'.$this->identifier($table);
    }

    private function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    /**
     * Runs $steps one after another, stopping at the first failure, as one command whose output can be piped.
     *
     * @param  list<string>  $steps
     */
    private static function script(array $steps): string
    {
        return 'bash -e -o pipefail -c '.escapeshellarg(implode('; ', $steps));
    }

    private static function printLine(string $line): string
    {
        return "printf '%s\\n' ".escapeshellarg($line);
    }
}
