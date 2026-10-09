# DB Snapshot

[![Latest Version on Packagist](https://img.shields.io/packagist/v/overthink/db-snapshot.svg?style=flat-square)](https://packagist.org/packages/overthink/db-snapshot)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/MarkoDevelop/db-snapshot/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/MarkoDevelop/db-snapshot/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/overthink/db-snapshot.svg?style=flat-square)](https://packagist.org/packages/overthink/db-snapshot)

Pull a filtered copy of a remote MySQL database over SSH and restore it into your local one. Pick the tables you need, keep only the last few months of the big history tables, and store that choice in a profile your team commits.

```bash
composer require --dev overthink/db-snapshot
php artisan snapshot
```

`php artisan snapshot` walks you through everything: it asks for the SSH and MySQL settings (and saves them to `.env`), checks the connection, analyzes the remote database, lets you build a profile, then pulls and restores. Run it again any time; it skips the steps that are already done.

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

The package only ever runs `SELECT`s against `information_schema` (plus `MIN`/`MAX` on indexed date columns) and `mysqldump` on the server.

## Requirements

- PHP `^8.3`, Laravel `^11.0 || ^12.0 || ^13.0`
- Locally (where artisan runs): `ssh`, `bash`, `gzip`, the `mysql` client
- On the server: `bash`, `gzip`, `mysqldump`

## Installation

```bash
composer require --dev overthink/db-snapshot
php artisan vendor:publish --tag="db-snapshot-config"   # optional
```

`php artisan snapshot` asks for the connection and writes it to `.env`. To set it up by hand instead:

```dotenv
SNAPSHOT_SSH_HOST=203.0.113.10
SNAPSHOT_SSH_USER=deploy
SNAPSHOT_SSH_PORT=22
SNAPSHOT_SSH_KEY=/root/.ssh/snapshot_key

SNAPSHOT_REMOTE_DB_HOST=127.0.0.1
SNAPSHOT_REMOTE_DB_PORT=3306
SNAPSHOT_REMOTE_DB_USERNAME=root
SNAPSHOT_REMOTE_DB_PASSWORD=
SNAPSHOT_REMOTE_DB_DATABASE=production

# Optional
SNAPSHOT_PATH=/var/snapshots        # default: storage/db-snapshots
SNAPSHOT_PARALLEL=4
SNAPSHOT_CONNECTION=                # restore target, default: the app's default connection
```

The remote password goes to the SSH session's stdin and is exported as `MYSQL_PWD` there, so it never shows up in a command line on either machine. Leave it empty to use the server user's `~/.my.cnf`.

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

## Pulling and restoring

```bash
php artisan snapshot:pull --profile=default --parallel=6
php artisan snapshot:restore                      # latest snapshot, asks before dropping
php artisan snapshot:restore latest --profile=default --force
php artisan snapshot:restore 2026-10-08_120000_default --database=dev_other
```

### Refreshing

`snapshot:refresh` asks what to refresh:

- **a profile:** pulls a new snapshot with it and restores the whole database (`--profile=nightly` skips the question);
- **some tables:** hands over to `snapshot:refresh-table`.

`snapshot:refresh-table [tables...]` pulls only the given tables (or the ones you pick in a search) and replaces just those tables in your local database. Nothing else is dropped. Each table starts from its rule in the profile (`--profile=`), and you can change the rule for this run. The pulled files are deleted afterwards unless you pass `--keep`, and such partial pulls are never used by `snapshot:restore`.

A snapshot is a directory of `tables/<table>.sql.gz` files plus `_views.sql.gz`, `_routines.sql.gz` and `manifest.json`. It's written as `<name>.partial` and renamed only when every dump succeeds. A failed pull leaves nothing behind.

`snapshot:restore` refuses to run in the `production` environment.

## Testing

```bash
composer test
composer analyse
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
