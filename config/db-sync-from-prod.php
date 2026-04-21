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
    | Production SSH / Database Connection
    |--------------------------------------------------------------------------
    */

    'prod_ssh' => [
        'host' => env('PROD_SSH_HOST'),
        'user' => env('PROD_SSH_USER'),
        'port' => env('PROD_SSH_PORT', '22'),
        'db_host' => env('PROD_DB_HOST', '127.0.0.1'),
        'db_port' => env('PROD_DB_PORT', '3306'),
        'db_username' => env('PROD_DB_USERNAME', 'root'),
        'db_password' => env('PROD_DB_PASSWORD', ''),
        'database' => env('PROD_DB_DATABASE'),
    ],

];
