<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database driver
    |--------------------------------------------------------------------------
    |
    | Built in: "mysql" (MySQL and MariaDB) and "pgsql" (PostgreSQL 13+).
    | Register your own driver with
    | DbSnapshot::extend('name', fn ($app) => new MyDriver(...)).
    |
    */
    'driver' => env('SNAPSHOT_DRIVER', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | SSH connection to the server that hosts the source database
    |--------------------------------------------------------------------------
    */
    'ssh' => [
        'host' => env('SNAPSHOT_SSH_HOST'),
        'user' => env('SNAPSHOT_SSH_USER'),
        'port' => (int) env('SNAPSHOT_SSH_PORT', 22),
        'key' => env('SNAPSHOT_SSH_KEY'),
        'options' => [
            'BatchMode=yes',
            'IdentitiesOnly=yes',
            'StrictHostKeyChecking=accept-new',
            'ServerAliveInterval=30',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source database, as seen from the SSH server
    |--------------------------------------------------------------------------
    |
    | The password is sent over the SSH session's stdin and exported in the
    | session (MYSQL_PWD for mysql), so it never appears in a command line or
    | a file on the server. Leave it empty to rely on the server user's own
    | client config (e.g. ~/.my.cnf). Port and username default per driver.
    |
    */
    'remote' => [
        'host' => env('SNAPSHOT_REMOTE_DB_HOST', '127.0.0.1'),
        'port' => env('SNAPSHOT_REMOTE_DB_PORT'),
        'username' => env('SNAPSHOT_REMOTE_DB_USERNAME'),
        'password' => env('SNAPSHOT_REMOTE_DB_PASSWORD'),
        'database' => env('SNAPSHOT_REMOTE_DB_DATABASE'),

        // Prepended to the dump command on the server to keep the load off production.
        'nice' => env('SNAPSHOT_REMOTE_NICE', 'nice -n 19 ionice -c3'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Driver options
    |--------------------------------------------------------------------------
    */
    'drivers' => [
        'mysql' => [
            'dump_options' => [
                '--single-transaction',
                '--quick',
                '--skip-lock-tables',
                '--no-tablespaces',
                '--hex-blob',
            ],

            // Add --set-gtid-purged=OFF so dumps from GTID-enabled servers can be
            // imported table by table. "auto" adds it unless the server's
            // mysqldump is MariaDB's, which doesn't know the option.
            'skip_gtid_purged' => 'auto',
        ],

        'pgsql' => [
            // The schema to snapshot (and to create locally when it isn't "public").
            'schema' => env('SNAPSHOT_PGSQL_SCHEMA', 'public'),

            // Extra pg_dump options. --no-owner and --no-privileges are always used.
            'dump_options' => [],

            // Local database psql connects to while dropping and creating the target.
            'maintenance_database' => 'postgres',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Restore target
    |--------------------------------------------------------------------------
    |
    | The database connection snapshots are restored into. Null uses the
    | application's default connection.
    |
    */
    'connection' => env('SNAPSHOT_CONNECTION'),

    // Name offered by snapshot:restore for a new database per snapshot. Also
    // usable in --database=. Placeholders: {source}, {profile}, {date}, {time}
    // and {database} (the connection's own database).
    'database_name' => env('SNAPSHOT_DATABASE_NAME', '{source}_{date}'),

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | "path" holds pulled snapshots and can be shared between checkouts of the
    | same project. Profiles are meant to be committed.
    |
    */
    'path' => env('SNAPSHOT_PATH', storage_path('db-snapshots')),
    'profile_path' => env('SNAPSHOT_PROFILE_PATH', database_path('snapshot-profiles')),
    'analysis_path' => env('SNAPSHOT_ANALYSIS_PATH', database_path('snapshot-analysis.json')),

    // snapshot:configure and snapshot:pull offer to re-analyze older analyses.
    'analysis_max_age_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Anonymizing
    |--------------------------------------------------------------------------
    |
    | Off by default. When on, columns a profile anonymizes are replaced on
    | the server while dumping, so the real values never leave it.
    | Hashes are salted: with a fixed salt the same value gets the same fake
    | in every snapshot; without one, a random salt is used per pull.
    |
    */
    'anonymize' => [
        // Off: no anonymize step in snapshot:configure, no suggestions or
        // warnings, and anonymize rules in profiles are ignored when pulling.
        'enabled' => (bool) env('SNAPSHOT_ANONYMIZE', false),

        'salt' => env('SNAPSHOT_ANONYMIZE_SALT'),
    ],

    // Parallel SSH sessions for pulling and database clients for restoring.
    'parallel' => (int) env('SNAPSHOT_PARALLEL', 4),

    // Tables at least this big get a mode prompt in snapshot:configure.
    'large_table_mb' => 200,

];
