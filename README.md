# db-sync-from-prod

A Laravel package that adds a `db:refresh-from-prod` Artisan command, which replaces the local database with a copy of the production database over SSH. Both **MySQL** and **SQLite** local connections are supported; the command picks the strategy from the local connection's driver.

## What it does

### MySQL

1. Dumps the current local database to `storage/backups/` as a safety net.
2. Opens an SSH tunnel to the production server.
3. Dumps the production database through the tunnel, streaming to disk with a progress bar.
4. Drops and recreates the local database (honoring the connection's charset and collation).
5. Imports the production dump, streamed with a progress bar.

### SQLite

1. Snapshots the current local database file to `storage/backups/` as a safety net (via `sqlite3 .backup`, which is WAL-safe).
2. Takes a consistent snapshot of the production database file on the server with `sqlite3 .backup`.
3. Downloads the snapshot over `scp` and removes the remote temp file.
4. Swaps the snapshot in as the local database file, clearing any stale `-wal`/`-shm` sidecars.

The command refuses to run unless `APP_ENV=local`.

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12
- `ssh` on your PATH (plus `lsof` for the MySQL tunnel, `scp` for SQLite)
- For MySQL: `mysql` and `mysqldump` locally; for SQLite: `sqlite3` locally and on the production server
- SSH access to the production server (key-based auth recommended)

## Installation

```bash
composer require abigah/db-sync-from-prod --dev
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=db-sync-from-prod-config
```

## Configuration

SSH settings are shared by both drivers. The remaining variables depend on whether your local connection is MySQL or SQLite.

```env
# Shared
PROD_SSH_HOST=prod.example.com
PROD_SSH_USER=deploy
PROD_SSH_PORT=22

# MySQL
PROD_DB_HOST=127.0.0.1
PROD_DB_PORT=3306
PROD_DB_USERNAME=app
PROD_DB_PASSWORD=secret
PROD_DB_DATABASE=app_production

# SQLite
PROD_DB_PATH=/var/www/app/current/database/database.sqlite
```

| Variable                     | Driver | Default                      | Description                                                |
| ---------------------------- | ------ | ---------------------------- | ---------------------------------------------------------- |
| `PROD_SSH_HOST`              | both   | —                            | SSH host of the production server.                         |
| `PROD_SSH_USER`              | both   | —                            | SSH user on the production server.                         |
| `PROD_SSH_PORT`              | both   | `22`                         | SSH port.                                                  |
| `PROD_DB_HOST`               | mysql  | `127.0.0.1`                  | DB host as seen from the production server.                |
| `PROD_DB_PORT`               | mysql  | `3306`                       | DB port on the production server.                          |
| `PROD_DB_USERNAME`           | mysql  | `root`                       | DB username on production.                                 |
| `PROD_DB_PASSWORD`           | mysql  | `` (empty)                   | DB password on production.                                 |
| `PROD_DB_DATABASE`           | mysql  | —                            | Name of the production database.                           |
| `PROD_DB_PATH`               | sqlite | —                            | Absolute path to the production SQLite file on the server. |
| `DB_SYNC_LOCAL_CONNECTION`   | both   | `config('database.default')` | Local connection (from `config/database.php`) to replace.  |
| `DB_SYNC_BACKUP_DIR`         | both   | `storage/backups`            | Where local and production dumps are written.              |

The backup directory is created on first run and seeded with a `.gitignore` that ignores its own contents, so dumps don't accidentally end up in version control.

## Usage

```bash
php artisan db:refresh-from-prod
```

You will be shown which local database is about to be replaced and asked to confirm. The command aborts unless `APP_ENV=local`.

### Options

| Option                 | Description                                                                          |
| ---------------------- | ------------------------------------------------------------------------------------ |
| `--dump=PATH`          | Use an existing dump/snapshot file instead of pulling a fresh one (skips the production download). |
| `--skip-local-backup`  | Skip backing up the local database before replacing it.                              |

## Progress bar caveat (MySQL)

The mysqldump progress bar is driven by an estimate from `information_schema.tables` (`SUM(DATA_LENGTH + INDEX_LENGTH)`), which reports on-disk storage, not dump size. The SQL dump is usually smaller than storage for InnoDB tables — indexes aren't dumped, and text compresses differently than on-disk pages. Expect the bar to finish before reaching 100% and then jump to done. The reported final byte count is exact.

If `information_schema` isn't reachable (e.g. the DB user lacks SELECT on it, or the connection times out), the estimate is skipped and the dump runs without a progress bar.

## License

MIT
