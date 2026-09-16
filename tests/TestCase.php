<?php

namespace Abigah\DbSyncFromProd\Tests;

use Abigah\DbSyncFromProd\DbSyncFromProdServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DbSyncFromProdServiceProvider::class,
        ];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app->detectEnvironment(fn () => 'local');

        // Capturing local auth reads the local database, which most tests fake
        // or leave unreachable; the tests for it turn it back on.
        $app['config']->set('db-sync-from-prod.preserve_local_auth.enabled', false);

        // Never reach the real Laravel Cloud API from a developer's machine.
        $app['config']->set('db-sync-from-prod.cloud_endpoint.cluster_id', null);

        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'local_db',
            'username' => 'root',
            'password' => '',
        ]);
    }
}
