<?php

namespace Abigah\DbSyncFromProd;

use Abigah\DbSyncFromProd\Commands\RefreshFromProdCommand;
use Illuminate\Support\ServiceProvider;

class DbSyncFromProdServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/db-sync-from-prod.php',
            'db-sync-from-prod',
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/db-sync-from-prod.php' => config_path('db-sync-from-prod.php'),
            ], 'db-sync-from-prod-config');

            $this->commands([
                RefreshFromProdCommand::class,
            ]);
        }
    }
}
