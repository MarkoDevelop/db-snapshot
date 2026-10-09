<?php

return [

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
    | The password is sent over the SSH session's stdin and exported as
    | MYSQL_PWD, so it never appears in a command line or a file on the server.
    | Leave it empty to rely on the server user's ~/.my.cnf.
    |
    */
    'remote' => [
        'host' => env('SNAPSHOT_REMOTE_DB_HOST', '127.0.0.1'),
        'port' => (int) env('SNAPSHOT_REMOTE_DB_PORT', 3306),
        'username' => env('SNAPSHOT_REMOTE_DB_USERNAME', 'root'),
        'password' => env('SNAPSHOT_REMOTE_DB_PASSWORD'),
        'database' => env('SNAPSHOT_REMOTE_DB_DATABASE'),

        // Prepended to mysqldump on the server to keep the load off production.
        'nice' => env('SNAPSHOT_REMOTE_NICE', 'nice -n 19 ionice -c3'),

        'dump_options' => [
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--no-tablespaces',
            '--hex-blob',
            // MySQL only; remove this line when the server runs MariaDB.
            '--set-gtid-purged=OFF',
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

    // Parallel SSH sessions for pulling and mysql clients for restoring.
    'parallel' => (int) env('SNAPSHOT_PARALLEL', 4),

    // Tables at least this big get a mode prompt in snapshot:configure.
    'large_table_mb' => 200,

];
