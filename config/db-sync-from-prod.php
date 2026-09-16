<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Local Database Connection
    |--------------------------------------------------------------------------
    |
    | The name of the connection (in config/database.php) that represents the
    | local database that will be replaced with production data.
    |
    */

    'local_connection' => env('DB_SYNC_LOCAL_CONNECTION', config('database.default')),

    /*
    |--------------------------------------------------------------------------
    | Backup Directory
    |--------------------------------------------------------------------------
    |
    | Directory where local backups and production dumps will be written.
    |
    */

    'backup_dir' => env('DB_SYNC_BACKUP_DIR', storage_path('backups')),

    /*
    |--------------------------------------------------------------------------
    | Preserve Local Auth
    |--------------------------------------------------------------------------
    |
    | Passkeys only work on the domain they were registered on, and two-factor
    | secrets are encrypted with the app key, so the ones production holds
    | rarely work locally. When enabled, the passkeys and two-factor settings
    | of local users are captured before the refresh and put back onto the
    | production users with the same `match_column` value afterwards.
    |
    | Passkeys production already has (by credential_id) are left alone. Only
    | users with two-factor set up locally have their two-factor columns put
    | back; the first column listed decides whether it is set up.
    |
    */

    'preserve_local_auth' => [
        'enabled' => env('DB_SYNC_PRESERVE_LOCAL_AUTH', true),
        'users_table' => 'users',
        'match_column' => 'email',
        'passkeys_table' => 'passkeys',
        'two_factor_columns' => [
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_confirmed_at',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Production Source
    |--------------------------------------------------------------------------
    |
    | Where the production data is pulled from:
    |
    |   ssh   - the production server is reachable over SSH; the command tunnels
    |           to it (mysql) or copies the database file off it (sqlite).
    |   cloud - the production database is a Laravel Cloud MySQL database with
    |           its public endpoint enabled; the command connects to it directly
    |           over TLS. No SSH access is involved.
    |
    | May be overridden per run with `--source=`.
    |
    */

    'source' => env('DB_SYNC_SOURCE', 'ssh'),

    /*
    |--------------------------------------------------------------------------
    | Production SSH / Database Connection
    |--------------------------------------------------------------------------
    |
    | SSH credentials are shared by both drivers. The remaining keys depend on
    | the local connection's driver:
    |
    |   mysql  - the command opens an SSH tunnel and uses the db_* credentials
    |            plus `database` (the production schema name) to run mysqldump.
    |   sqlite - the command snapshots the remote file at `remote_db_path` over
    |            SSH and downloads it; the db_* / database keys are unused.
    |
    */

    'prod_ssh' => [
        'host' => env('PROD_SSH_HOST'),
        'user' => env('PROD_SSH_USER'),
        'port' => env('PROD_SSH_PORT', '22'),

        // MySQL
        'db_host' => env('PROD_DB_HOST', '127.0.0.1'),
        'db_port' => env('PROD_DB_PORT', '3306'),
        'db_username' => env('PROD_DB_USERNAME', 'root'),
        'db_password' => env('PROD_DB_PASSWORD', ''),
        'database' => env('PROD_DB_DATABASE'),

        // SQLite — absolute path to the production database file on the server.
        'remote_db_path' => env('PROD_DB_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel Cloud Database Connection
    |--------------------------------------------------------------------------
    |
    | Used when the source is "cloud". These are the credentials shown under
    | "View credentials" for the database in the Laravel Cloud dashboard. The
    | cluster's public endpoint must be enabled for the duration of the sync.
    |
    | Laravel Cloud requires TLS, so `ssl_mode` defaults to REQUIRED (encrypt,
    | but don't verify the certificate). Set `ssl_ca` to a CA bundle if you want
    | verification, or set `ssl_mode` to an empty value to omit the flag
    | entirely — MariaDB's mysqldump does not understand --ssl-mode.
    |
    */

    'prod_cloud' => [
        'host' => env('PROD_DB_HOST'),
        'port' => env('PROD_DB_PORT', '3306'),
        'username' => env('PROD_DB_USERNAME'),
        'password' => env('PROD_DB_PASSWORD'),
        'database' => env('PROD_DB_DATABASE'),
        'ssl_mode' => env('PROD_DB_SSL_MODE', 'REQUIRED'),
        'ssl_ca' => env('PROD_DB_SSL_CA'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel Cloud Public Endpoint
    |--------------------------------------------------------------------------
    |
    | A Cloud database is only reachable from your machine while its cluster's
    | public endpoint is open, and it is safest left closed. Set a cluster id
    | and, when the endpoint is closed, the command asks whether to open it,
    | opens it just before the dump, and afterwards asks whether to close it
    | again. An endpoint that is already open is left open, as another sync
    | may be using it.
    |
    | The cluster id is the `db-...` id in the cluster's dashboard URL. The API
    | token is LARAVEL_CLOUD_TOKEN, or else each token the `cloud` CLI has
    | stored is tried in turn. `wait` is how many seconds to wait for an
    | opened endpoint to accept connections.
    |
    */

    'cloud_endpoint' => [
        'cluster_id' => env('LARAVEL_CLOUD_DB_CLUSTER_ID'),
        'api_url' => env('LARAVEL_CLOUD_API_URL', 'https://cloud.laravel.com/api'),
        'token' => env('LARAVEL_CLOUD_TOKEN'),
        'token_file' => env('LARAVEL_CLOUD_TOKEN_FILE', ($home = env('HOME')) ? $home.'/.config/cloud/config.json' : null),
        'wait' => 60,
    ],

];
