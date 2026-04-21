# db-sync-from-prod

A Laravel package that adds a `db:refresh-from-prod` Artisan command, which replaces the local database with a copy of the production database over an SSH tunnel.

## What it does

1. Dumps the current local database to `storage/backups/` as a safety net.
2. Opens an SSH tunnel to the production server.
3. Dumps the production database through the tunnel, streaming to disk with a progress bar.
4. Drops and recreates the local database (honoring the connection's charset and collation).
5. Imports the production dump, streamed with a progress bar.

The command refuses to run unless `APP_ENV=local`.

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12
- `mysql` and `mysqldump` on your PATH
- `ssh` and `lsof` on your PATH
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

Add these variables to your `.env`:

```env
PROD_SSH_HOST=prod.example.com
PROD_SSH_USER=deploy
PROD_SSH_PORT=22

PROD_DB_HOST=127.0.0.1
PROD_DB_PORT=3306
PROD_DB_USERNAME=app
PROD_DB_PASSWORD=secret
PROD_DB_DATABASE=app_production
```

| Variable                     | Default                   | Description                                           |
| ---------------------------- | ------------------------- | ----------------------------------------------------- |
| `PROD_SSH_HOST`              | —                         | SSH host of the production server.                    |
| `PROD_SSH_USER`              | —                         | SSH user on the production server.                    |
| `PROD_SSH_PORT`              | `22`                      | SSH port.                                             |
| `PROD_DB_HOST`               | `127.0.0.1`               | DB host as seen from the production server.           |
| `PROD_DB_PORT`               | `3306`                    | DB port on the production server.                     |
| `PROD_DB_USERNAME`           | `root`                    | DB username on production.                            |
| `PROD_DB_PASSWORD`           | `` (empty)                | DB password on production.                            |
| `PROD_DB_DATABASE`           | —                         | Name of the production database.                      |
| `DB_SYNC_LOCAL_CONNECTION`   | `config('database.default')` | Local connection (from `config/database.php`) to replace. |
| `DB_SYNC_BACKUP_DIR`         | `storage/backups`         | Where local and production dumps are written.         |

## Usage

```bash
php artisan db:refresh-from-prod
```

You will be shown which local database is about to be replaced and asked to confirm. The command aborts unless `APP_ENV=local`.

### Options

| Option                 | Description                                                                          |
| ---------------------- | ------------------------------------------------------------------------------------ |
| `--dump=PATH`          | Import an existing dump file instead of pulling a fresh one (skips SSH + mysqldump). |
| `--skip-local-backup`  | Skip dumping the local database before importing.                                    |

## Progress bar caveat

The mysqldump progress bar is driven by an estimate from `information_schema.tables` (`SUM(DATA_LENGTH + INDEX_LENGTH)`), which reports on-disk storage, not dump size. The SQL dump is usually smaller than storage for InnoDB tables — indexes aren't dumped, and text compresses differently than on-disk pages. Expect the bar to finish before reaching 100% and then jump to done. The reported final byte count is exact.

If `information_schema` isn't reachable (e.g. the DB user lacks SELECT on it, or the connection times out), the estimate is skipped and the dump runs without a progress bar.

## License

MIT
