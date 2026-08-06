# db-sync-from-prod

A Laravel package that adds a `db:refresh-from-prod` Artisan command, which replaces the local database with a copy of the production database. Production can be reached over **SSH** (a server you can log into) or directly on a **Laravel Cloud** MySQL database's public endpoint. Both **MySQL** and **SQLite** local connections are supported; the command picks the strategy from the local connection's driver and the configured source.

## What it does

### MySQL over SSH

1. Dumps the current local database to `storage/backups/` as a safety net.
2. Opens an SSH tunnel to the production server.
3. Dumps the production database through the tunnel, streaming to disk with a progress bar.
4. Drops and recreates the local database (honoring the connection's charset and collation).
5. Imports the production dump, streamed with a progress bar.

### MySQL on Laravel Cloud

Same as above, minus the tunnel: `mysqldump` connects straight to the database's public endpoint over TLS. The dump runs with `--single-transaction` (consistent, no locking) and `--no-tablespaces` (Laravel Cloud database users are not granted the `PROCESS` privilege that `mysqldump` otherwise requires).

You must enable the cluster's public endpoint before syncing — "Org > Resources > Databases > ... > Edit settings > Enable public endpoint" — and you can disable it again afterwards. Credentials come from "View credentials" on the same menu.

### SQLite

1. Snapshots the current local database file to `storage/backups/` as a safety net (via `sqlite3 .backup`, which is WAL-safe).
2. Takes a consistent snapshot of the production database file on the server with `sqlite3 .backup`.
3. Downloads the snapshot over `scp` and removes the remote temp file.
4. Swaps the snapshot in as the local database file, clearing any stale `-wal`/`-shm` sidecars.

The command refuses to run unless `APP_ENV=local`.

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12
- For MySQL: `mysql` and `mysqldump` locally; for SQLite: `sqlite3` locally and on the production server
- For the `ssh` source: `ssh` on your PATH (plus `lsof` for the MySQL tunnel, `scp` for SQLite) and SSH access to the production server (key-based auth recommended)
- For the `cloud` source: a Laravel Cloud MySQL database with its public endpoint enabled, and a `mysqldump` that understands `--ssl-mode` (Oracle MySQL 5.7+; see the SSL note below for MariaDB)

## Installation

```bash
composer require abigah/db-sync-from-prod --dev
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=db-sync-from-prod-config
```

## Configuration

`DB_SYNC_SOURCE` selects how production is reached: `ssh` (default) or `cloud`. The rest of the variables depend on that choice and on whether your local connection is MySQL or SQLite.

### Source: `ssh`

SSH settings are shared by both drivers.

```env
DB_SYNC_SOURCE=ssh

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

### Source: `cloud` (Laravel Cloud)

Take these from "View credentials" in the Laravel Cloud dashboard. Note that `PROD_DB_HOST` here is the public endpoint you connect to directly, not a host as seen from a production server.

```env
DB_SYNC_SOURCE=cloud

PROD_DB_HOST=db-xxxxxxxx.ca-central-1.db.laravel.cloud
PROD_DB_PORT=3306
PROD_DB_USERNAME=xxxxxxxxxxxxxxxx
PROD_DB_PASSWORD=secret
PROD_DB_DATABASE=production
```

| Variable                     | Source | Driver | Default                      | Description                                                |
| ---------------------------- | ------ | ------ | ---------------------------- | ---------------------------------------------------------- |
| `DB_SYNC_SOURCE`             | —      | both   | `ssh`                        | Where production lives: `ssh` or `cloud`.                  |
| `PROD_SSH_HOST`              | ssh    | both   | —                            | SSH host of the production server.                         |
| `PROD_SSH_USER`              | ssh    | both   | —                            | SSH user on the production server.                         |
| `PROD_SSH_PORT`              | ssh    | both   | `22`                         | SSH port.                                                  |
| `PROD_DB_HOST`               | both   | mysql  | `127.0.0.1` (ssh only)       | DB host: as seen from the production server (`ssh`), or the public endpoint (`cloud`). |
| `PROD_DB_PORT`               | both   | mysql  | `3306`                       | DB port.                                                   |
| `PROD_DB_USERNAME`           | both   | mysql  | `root` (ssh only)            | DB username on production.                                 |
| `PROD_DB_PASSWORD`           | both   | mysql  | `` (empty)                   | DB password on production.                                 |
| `PROD_DB_DATABASE`           | both   | mysql  | —                            | Name of the production database.                           |
| `PROD_DB_SSL_MODE`           | cloud  | mysql  | `REQUIRED`                   | `--ssl-mode` passed to `mysqldump`. Leave empty to omit it. |
| `PROD_DB_SSL_CA`             | cloud  | mysql  | —                            | CA bundle to verify the server certificate against.        |
| `PROD_DB_PATH`               | ssh    | sqlite | —                            | Absolute path to the production SQLite file on the server. |
| `DB_SYNC_LOCAL_CONNECTION`   | both   | both   | `config('database.default')` | Local connection (from `config/database.php`) to replace.  |
| `DB_SYNC_BACKUP_DIR`         | both   | both   | `storage/backups`            | Where local and production dumps are written.              |

The `cloud` source requires a MySQL local connection; Laravel Cloud does not host SQLite databases.

#### SSL notes

Laravel Cloud requires TLS, so the dump runs with `--ssl-mode=REQUIRED` by default: the connection is encrypted, but the certificate is not verified. To verify it, point `PROD_DB_SSL_CA` at a CA bundle (`/etc/ssl/certs/ca-certificates.crt` on Linux, `$(brew --prefix)/etc/ca-certificates/cert.pem` on macOS). Avoid `VERIFY_IDENTITY` — the endpoint hostname does not match the certificate.

MariaDB's `mysqldump` does not understand `--ssl-mode`. If you're using it, set `PROD_DB_SSL_MODE=` (empty) to drop the flag; the client negotiates TLS on its own.

The backup directory is created on first run and seeded with a `.gitignore` that ignores its own contents, so dumps don't accidentally end up in version control.

## Usage

```bash
php artisan db:refresh-from-prod
```

You will be shown which local database is about to be replaced and asked to confirm. The command aborts unless `APP_ENV=local`.

### Options

| Option                 | Description                                                                          |
| ---------------------- | ------------------------------------------------------------------------------------ |
| `--source=ssh\|cloud`   | Override the configured source for this run.                                         |
| `--dump=PATH`          | Use an existing dump/snapshot file instead of pulling a fresh one (skips the production download). |
| `--skip-local-backup`  | Skip backing up the local database before replacing it.                              |

## Progress bar caveat (MySQL)

The mysqldump progress bar is driven by an estimate from `information_schema.tables` (`SUM(DATA_LENGTH + INDEX_LENGTH)`), which reports on-disk storage, not dump size. The SQL dump is usually smaller than storage for InnoDB tables — indexes aren't dumped, and text compresses differently than on-disk pages. Expect the bar to finish before reaching 100% and then jump to done. The reported final byte count is exact.

If `information_schema` isn't reachable (e.g. the DB user lacks SELECT on it, or the connection times out), the estimate is skipped and the dump runs without a progress bar.

## License

MIT
