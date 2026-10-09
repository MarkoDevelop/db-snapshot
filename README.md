# DB Snapshot

[![Latest Version on Packagist](https://img.shields.io/packagist/v/overthink/db-snapshot.svg?style=flat-square)](https://packagist.org/packages/overthink/db-snapshot)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/MarkoDevelop/db-snapshot/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/MarkoDevelop/db-snapshot/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/overthink/db-snapshot.svg?style=flat-square)](https://packagist.org/packages/overthink/db-snapshot)

Pull a filtered copy of a remote database (MySQL, MariaDB and PostgreSQL built in, others via drivers) over SSH and restore it into your local one. Pick the tables you need, keep only the last few months of the big history tables, and store that choice in a profile your team commits.

```bash
composer require --dev overthink/db-snapshot
php artisan snapshot
```

`php artisan snapshot` walks you through everything: it asks for the SSH and database settings (and saves them to `.env`), checks the connection, analyzes the remote database, lets you build a profile, then pulls and restores. Run it again any time; it skips the steps that are already done.

To redo the analysis and the profile, run `php artisan snapshot --fresh`: it re-analyzes the remote database and opens the profile editor even when both already exist. The connection settings are kept.

The individual steps are commands too:

```bash
php artisan snapshot             # guided: setup → analyze → configure → pull → restore
php artisan snapshot:analyze     # sizes, rows and date columns of every remote table → database/snapshot-analysis.json
php artisan snapshot:configure   # choose what to copy (searchable TUI), saved as a profile
php artisan snapshot:pull        # stream per-table gzipped dumps over SSH, in parallel
php artisan snapshot:restore     # drop + recreate the local DB from the latest snapshot
php artisan snapshot:refresh     # later on: refresh a whole profile, or just some tables
php artisan snapshot:refresh-table orders users   # pull + replace only these tables
php artisan snapshot:list
```

## Why

The usual `mysqldump > dump.sql && gzip && scp` routine writes the whole dump to the server's disk, copies every row of every table, and often takes a global read lock (`--master-data`). This package:

- **streams** `mysqldump | gzip` straight over SSH into a local file, so nothing is written on the server;
- **dumps per table in parallel** (`--single-transaction --quick`, no global lock, wrapped in `nice`/`ionice`);
- **filters per table**: full, schema only, the last N months by a date column, a custom `WHERE`, or skip;
- **pulls once, restores many times**: snapshots live in a directory that several checkouts or worktrees can share, and restores import tables in parallel.

On the server the package only ever runs read-only queries against the schema catalog (plus `MIN`/`MAX` on indexed date columns) and the database's dump tool.

## Requirements

- PHP `^8.3`, Laravel `^11.0 || ^12.0 || ^13.0`
- Locally (where artisan runs): `ssh`, `bash`, `gzip`, and the database client (`mysql`, or `psql` and `sed` for pgsql)
- On the server: `bash`, `gzip`, and the dump tools (`mysqldump`, or `pg_dump` and `psql` for pgsql)

## Installation

```bash
composer require --dev overthink/db-snapshot
php artisan vendor:publish --tag="db-snapshot-config"   # optional
```

Running artisan inside Docker? Read [Running in Docker](#running-in-docker) first: the SSH key and snapshot directory need mounts.

`php artisan snapshot` asks for the connection and writes it to `.env`. To set it up by hand instead:

```dotenv
SNAPSHOT_SSH_HOST=203.0.113.10
SNAPSHOT_SSH_USER=deploy
SNAPSHOT_SSH_PORT=22
SNAPSHOT_SSH_KEY=/root/.ssh/snapshot_key

SNAPSHOT_DRIVER=mysql
SNAPSHOT_REMOTE_DB_HOST=127.0.0.1
SNAPSHOT_REMOTE_DB_PORT=3306          # default per driver
SNAPSHOT_REMOTE_DB_USERNAME=root      # default per driver
SNAPSHOT_REMOTE_DB_PASSWORD=
SNAPSHOT_REMOTE_DB_DATABASE=production

# Optional
SNAPSHOT_PATH=/var/snapshots        # default: storage/db-snapshots
SNAPSHOT_PARALLEL=4
SNAPSHOT_CONNECTION=                # restore target, default: the app's default connection
SNAPSHOT_DATABASE_NAME={source}_{date}   # name offered for a new database per snapshot
```

The remote password goes to the SSH session's stdin and is exported there (as `MYSQL_PWD` for the mysql driver), so it never shows up in a command line on either machine. Leave it empty to use the server user's `~/.my.cnf`.

## Profiles

`snapshot:configure [profile]` writes `database/snapshot-profiles/<profile>.json`:

```json
{
    "default_mode": "full",
    "tables": {
        "activities": { "mode": "schema" },
        "event_logs": { "mode": "recent", "column": "created_at", "months": 3 },
        "failed_jobs": { "mode": "schema" },
        "imports": { "mode": "recent", "column": "created_at", "since": "2025-01-01" },
        "telescope_entries": { "mode": "skip" },
        "users": { "mode": "where", "where": "deleted_at IS NULL" }
    }
}
```

| Mode | Result |
| --- | --- |
| `full` | all rows |
| `schema` | table structure only |
| `recent` | rows where `column >= now - months` (whole days) or `column >= since` |
| `where` | rows matching a custom `WHERE` clause |
| `skip` | the table is left out |

Tables the profile doesn't list use `default_mode`, so a new table on the server is never silently dropped. `months` is relative, so a profile stays current without editing.

In `snapshot:configure`, large tables (over `large_table_mb`, 200 MB by default) are listed first with their size, row count and indexed date columns. Any other table can be found by typing its name. For `recent` rules, each period option shows an estimated size, and a summary table shows the expected snapshot size before you save.

At the end you choose the name to save under, so one run can turn `default` into a new `nightly` profile. A different existing profile is only overwritten after you confirm.

If a `recent` rule filters on a date column without an index, the server has to scan the whole table to find the recent rows. `snapshot:configure` lists those columns and offers to generate a migration (`database/migrations/…_add_snapshot_date_indexes.php`) that adds the indexes. Review it and deploy it like any other migration. InnoDB builds indexes online, but on very large tables it still takes a while.

Filtering by date leaves rows in other tables that point at rows not copied. Restores run with foreign key checks off, so this is fine for development, but keep it in mind when you pick rules.

## Analysis

`snapshot:analyze` writes `database/snapshot-analysis.json`: every remote table with its size, row count and date columns, plus the date range of indexed date columns on large tables. Commit it, so teammates can run `snapshot:configure` without touching the server first.

`snapshot:configure` and `snapshot:pull` check how old the analysis is. If it's older than `analysis_max_age_days` (30 by default), they ask whether to **Analyze now** or continue with the current one. Without interaction they only warn. Pass `--analyze` to either command to refresh it without being asked.

## What to commit

| Path | Commit? |
| --- | --- |
| `database/snapshot-profiles/*.json` | Yes: the team shares which tables are copied and how much of them |
| `database/snapshot-analysis.json` | Yes: teammates can run `snapshot:configure` without touching the server |
| `database/migrations/…_add_snapshot_date_indexes.php` | Yes, after review, like any migration |
| the snapshot directory (`SNAPSHOT_PATH`, default `storage/db-snapshots`) | **Never**: it holds production data |

`snapshot:pull` writes a `.gitignore` (`*`, `!.gitignore`) into the snapshot directory, so the dumps stay out of commits even at the default location inside the project. An existing `.gitignore` there is left alone.

## Pulling and restoring

```bash
php artisan snapshot:pull --profile=default --parallel=6
php artisan snapshot:restore                      # pick a snapshot (newest preselected), asks before dropping
php artisan snapshot:restore latest --profile=default --force
php artisan snapshot:restore 2026-10-08_120000_default --database=dev_other
```

### Refreshing

`snapshot:refresh` asks what to refresh:

- **a profile:** pulls a new snapshot with it and restores the whole database (`--profile=nightly` skips the question);
- **some tables:** hands over to `snapshot:refresh-table`.

`snapshot:refresh-table [tables...]` pulls only the given tables (or the ones you pick in a search) and replaces just those tables in your local database. Nothing else is dropped. Each table starts from its rule in the profile (`--profile=`), and you can change the rule for this run. The pulled files are deleted afterwards unless you pass `--keep`, and such partial pulls are never used by `snapshot:restore`.

A snapshot is a directory of `tables/<table>.sql.gz` files plus `_views.sql.gz`, `_routines.sql.gz` and `manifest.json`. It's written as `<name>.partial` and renamed only when every dump succeeds. A failed pull leaves nothing behind.

Without a snapshot name, `snapshot:restore` lists the snapshots (filtered by `--profile=`), newest first and preselected, so Enter restores the latest. Pass a name or `latest`, or run it with `--no-interaction`, to skip the question.

### One database per snapshot

To keep several snapshots side by side (per source, per day, …), restore into a database named from a template:

```bash
php artisan snapshot:restore latest --database='{source}_{date}'    # e.g. production_2026_10_08
```

| Placeholder | Value |
| --- | --- |
| `{source}` | the remote database the snapshot came from |
| `{profile}` | the snapshot's profile |
| `{date}` / `{time}` | when it was pulled (`Y_m_d` / `His`) |
| `{database}` | the connection's own database (`DB_DATABASE`) |

Run interactively without `--database` or `--force`, `snapshot:restore` asks where to restore: the app's database, a new one named from `SNAPSHOT_DATABASE_NAME` (default `{source}_{date}`), or another name. After restoring into another database it offers to point the app at it by setting `DB_DATABASE` in `.env`.

`snapshot:restore` refuses to run in the `production` environment.

## Drivers

Everything database-specific lives in a driver: reading the schema catalog, building the dump commands that run on the server, and importing dumps locally. Select one with `SNAPSHOT_DRIVER`.

**`mysql`** (built in) covers MySQL and MariaDB. It uses `information_schema` and `mysqldump` on the server and the `mysql` client locally. Driver options live under `drivers.mysql` in the config:

- `dump_options`: extra `mysqldump` flags (defaults: `--single-transaction --quick --skip-lock-tables --no-tablespaces --hex-blob`).
- `skip_gtid_purged`: adds `--set-gtid-purged=OFF`, so dumps from GTID-enabled servers can be imported table by table. With `auto` (the default) it checks `mysqldump --version` on the server once per pull and leaves the flag out for MariaDB's `mysqldump`, which doesn't know it. Set `true` or `false` to force it. The restore also strips any `SET @@GLOBAL.GTID_PURGED` it finds, so older snapshots import fine.

**`pgsql`** (built in) covers PostgreSQL 13 and newer. It reads `pg_catalog` and uses `pg_dump` and `psql` on the server and `psql` locally (default port 5432, user `postgres`). Options under `drivers.pgsql`:

- `schema`: the schema to snapshot (`SNAPSHOT_PGSQL_SCHEMA`, default `public`). Other schemas are created locally.
- `dump_options`: extra `pg_dump` flags. `--no-owner --no-privileges` are always used, so the server's roles don't have to exist locally.
- `maintenance_database`: the local database `psql` connects to while dropping and recreating the target (default `postgres`).

How it differs from mysql, because `pg_dump` has no `--where` and Postgres has no "foreign key checks off" switch:

- Filtered tables (`recent`, `where`) are dumped as their definition plus `COPY` of a `SELECT … WHERE …`. Afterwards each sequence the table owns is moved past the highest restored id.
- Indexes, constraints and triggers of all tables go into one post-data file that is restored after every table is loaded. Foreign keys are created `NOT VALID`: rows that point at filtered-out rows stay, and new rows are still checked.
- Extensions and functions of the schema are restored before the tables, since columns and defaults may use them.
- The restore drops statements that newer `pg_dump` versions write but older `psql` clients reject (`SET transaction_timeout`, `\restrict`).
- `DROP DATABASE … WITH (FORCE)` disconnects anyone still connected to the local target database.

Limitations: one schema per snapshot; partitioned tables, materialized view data and sequences not owned by a table are not handled specially. `snapshot:refresh-table` drops the table with `CASCADE`, so foreign keys in *other* tables that point at it, and views on it, have to be restored again with a full `snapshot:restore`.

Snapshots record the driver that pulled them, and `snapshot:restore` always uses that driver. It refuses to restore into a connection of another kind (for example a MySQL snapshot into a `pgsql` connection).

### Writing a driver

Implement `Overthink\DbSnapshot\Contracts\Driver`. Extending `Overthink\DbSnapshot\Drivers\SshDriver` gives you the SSH plumbing: the password sent over stdin, running a query and parsing tab-separated rows, and gzipping a remote dump into a local file. Then register it in a service provider:

```php
use Overthink\DbSnapshot\Facades\DbSnapshot;

public function boot(): void
{
    DbSnapshot::extend('sqlsrv', fn ($app) => new SqlServerDriver(
        config('db-snapshot.ssh'),
        config('db-snapshot.remote'),
    ));
}
```

```dotenv
SNAPSHOT_DRIVER=sqlsrv
```

`MysqlDriver` is the reference implementation.

## Running in Docker

When artisan runs in a container (Sail, a custom `workspace` service, …), the SSH session and the `mysql` client run there too, so the container needs the tools, the key and a place for snapshots. The examples use the mysql driver's tools.

**Tools.** The image needs `ssh`, `bash`, `gzip` and a `mysql` client. Check with:

```bash
docker compose exec workspace sh -c 'which ssh bash gzip mysql'
```

For the mysql driver, the MariaDB `mysql` client restores into MySQL fine. For pgsql, check `which psql sed` instead; the local `psql` should be at least as new as the server.

**SSH key.** Mount only the key you need, read-only, into the home directory of the user the container runs as (`docker compose exec workspace id` shows it), and point `SNAPSHOT_SSH_KEY` at the path *inside* the container:

```yaml
services:
    workspace:
        volumes:
            - ../:/var/www
            - ${HOME}/.ssh/deploy_key.pem:/home/app/.ssh/snapshot_key:ro
            - ../../snapshots:/var/snapshots
```

```dotenv
SNAPSHOT_SSH_KEY=/home/app/.ssh/snapshot_key
SNAPSHOT_PATH=/var/snapshots
```

- Don't mount your whole `~/.ssh`. It exposes every key to the container, and macOS-only options in `~/.ssh/config` (such as `UseKeychain`) make Linux `ssh` fail.
- The key must not be readable by others (`chmod 600` on the host). OpenSSH refuses keys with looser permissions.
- On the first connection `StrictHostKeyChecking=accept-new` adds the server to the container's `~/.ssh/known_hosts`. If that directory isn't persisted, this happens again after the container is recreated, which is harmless. To pin the host key instead, also mount a `known_hosts` file there.

**Snapshot directory.** Point `SNAPSHOT_PATH` at a mounted directory, as above, so snapshots survive container rebuilds. Several checkouts of the same project (for example git worktrees) can mount the same directory: pull once, then restore in each checkout.

**Restore target.** `snapshot:restore` and `snapshot:refresh-table` restore into the app's own database connection (`DB_HOST`, `DB_DATABASE`, …), so inside the container that's usually the `mysql` service, e.g. `DB_HOST=mysql`. Use `SNAPSHOT_CONNECTION` to pick another connection, or `--database=` for one run.

**Run commands from the compose directory.** `docker compose` looks for a compose file in the current directory and then in its parents, so run `docker compose exec workspace php artisan snapshot` from the directory that holds your `docker-compose.yml`.

**Developing the package locally.** With a composer path repository (`"url": "/var/db-snapshot", "options": {"symlink": true}`), `vendor/overthink/db-snapshot` is a symlink to that path. Mount the package's source at that path in **every** container that boots the app (the CLI container *and* the web/PHP-FPM container), or the web app fails with *Failed to open stream: …/DbSnapshotServiceProvider.php*.

## Testing

```bash
composer test
composer analyse
```

The PostgreSQL integration tests run against a real server and are skipped unless `DB_SNAPSHOT_PGSQL_HOST` is set (`DB_SNAPSHOT_PGSQL_PORT`, `_USERNAME` and `_PASSWORD` are optional). `pg_dump` and `psql` must be on `PATH`:

```bash
DB_SNAPSHOT_PGSQL_HOST=127.0.0.1 DB_SNAPSHOT_PGSQL_PASSWORD=secret vendor/bin/pest tests/Integration
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
