<?php

use Abigah\DbSyncFromProd\Commands\RefreshFromProdCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->tempBackupDir = sys_get_temp_dir().'/db-sync-tests-'.uniqid();

    config()->set('db-sync-from-prod.local_connection', 'mysql');
    config()->set('db-sync-from-prod.backup_dir', $this->tempBackupDir);
    config()->set('db-sync-from-prod.source', 'cloud');
    config()->set('db-sync-from-prod.prod_cloud', [
        'host' => 'db-abc123.ca-central-1.db.laravel.cloud',
        'port' => '3306',
        'username' => 'wjz4th7j2fvgj3xu',
        'password' => 'secret',
        'database' => 'production',
        'ssl_mode' => 'REQUIRED',
        'ssl_ca' => null,
    ]);
});

afterEach(function () {
    if (isset($this->tempBackupDir) && is_dir($this->tempBackupDir)) {
        array_map('unlink', glob($this->tempBackupDir.'/*') ?: []);
        @rmdir($this->tempBackupDir);
    }
});

/**
 * Install a stub that records the config each dump ran against, so the Laravel
 * Cloud credentials can be asserted without shelling out to mysqldump.
 */
function stubCloudCommand(): object
{
    $stub = new class extends RefreshFromProdCommand
    {
        /** @var array<int, array<string, mixed>> */
        public array $dumpConfigs = [];

        public int $importCallCount = 0;

        protected function dumpDatabase(array $config, string $outputPath): bool
        {
            $this->dumpConfigs[] = $config;

            file_put_contents($outputPath, '-- stubbed dump');
            $this->info('  Done.');

            return true;
        }

        protected function importDatabase(array $config, string $dumpPath): bool
        {
            $this->importCallCount++;

            $this->info('  [import stubbed]');

            return true;
        }
    };

    app()->instance(RefreshFromProdCommand::class, $stub);

    return $stub;
}

function mockLocalRecreate(): void
{
    $connection = Mockery::mock();
    $connection->shouldReceive('statement')->andReturnNull();

    DB::shouldReceive('connection')->with('mysql')->andReturn($connection);
}

it('completes a full refresh from Laravel Cloud without an ssh tunnel', function () {
    $stub = stubCloudCommand();
    mockLocalRecreate();

    Process::fake();

    $this->artisan('db:refresh-from-prod')
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsOutputToContain('Laravel Cloud database (production on db-abc123.ca-central-1.db.laravel.cloud)')
        ->expectsOutputToContain('Database refresh complete!')
        ->doesntExpectOutputToContain('Opening SSH tunnel')
        ->assertExitCode(0);

    expect($stub->dumpConfigs)->toHaveCount(2)   // local backup + cloud
        ->and($stub->importCallCount)->toBe(1);

    Process::assertNothingRan();
});

it('dumps production with the Laravel Cloud credentials', function () {
    $stub = stubCloudCommand();
    mockLocalRecreate();

    $this->artisan('db:refresh-from-prod', ['--skip-local-backup' => true])
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->assertExitCode(0);

    expect($stub->dumpConfigs)->toHaveCount(1);

    expect($stub->dumpConfigs[0])
        ->toMatchArray([
            'host' => 'db-abc123.ca-central-1.db.laravel.cloud',
            'port' => '3306',
            'username' => 'wjz4th7j2fvgj3xu',
            'password' => 'secret',
            'database' => 'production',
            'ssl_mode' => 'REQUIRED',
        ])
        ->and($stub->dumpConfigs[0]['options'])
        ->toContain('--single-transaction', '--no-tablespaces');
});

it('uses the cloud source when --source=cloud is passed', function () {
    config()->set('db-sync-from-prod.source', 'ssh');

    $stub = stubCloudCommand();
    mockLocalRecreate();

    Process::fake();

    $this->artisan('db:refresh-from-prod', ['--source' => 'cloud', '--skip-local-backup' => true])
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->assertExitCode(0);

    expect($stub->dumpConfigs[0]['host'])->toBe('db-abc123.ca-central-1.db.laravel.cloud');

    Process::assertNothingRan();
});

it('fails when the Laravel Cloud host is not configured', function () {
    config()->set('db-sync-from-prod.prod_cloud.host', null);

    $this->artisan('db:refresh-from-prod')
        ->expectsOutputToContain('Laravel Cloud connection is not configured.')
        ->assertExitCode(1);
});

it('fails when the Laravel Cloud database is not configured', function () {
    config()->set('db-sync-from-prod.prod_cloud.database', null);

    $this->artisan('db:refresh-from-prod')
        ->expectsOutputToContain('Laravel Cloud connection is not configured.')
        ->assertExitCode(1);
});

it('fails when the Laravel Cloud username is not configured', function () {
    config()->set('db-sync-from-prod.prod_cloud.username', null);

    $this->artisan('db:refresh-from-prod')
        ->expectsOutputToContain('Laravel Cloud connection is not configured.')
        ->assertExitCode(1);
});

it('still accepts an existing dump file when the source is cloud', function () {
    $dumpFile = tempnam(sys_get_temp_dir(), 'dump-').'.sql';
    file_put_contents($dumpFile, "-- test dump\n");

    $stub = stubCloudCommand();
    mockLocalRecreate();

    $this->artisan('db:refresh-from-prod', ['--dump' => $dumpFile, '--skip-local-backup' => true])
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsOutputToContain("dump at {$dumpFile}")
        ->assertExitCode(0);

    expect($stub->dumpConfigs)->toBeEmpty()
        ->and($stub->importCallCount)->toBe(1);

    @unlink($dumpFile);
});

it('refuses to sync a sqlite connection from Laravel Cloud', function () {
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => sys_get_temp_dir().'/db-sync-cloud-sqlite.sqlite',
    ]);
    config()->set('db-sync-from-prod.local_connection', 'sqlite');

    $this->artisan('db:refresh-from-prod')
        ->expectsOutputToContain('The cloud source only supports a MySQL local connection')
        ->assertExitCode(1);
});

it('fails for an unknown source', function () {
    $this->artisan('db:refresh-from-prod', ['--source' => 'ftp'])
        ->expectsOutputToContain("Unsupported source: ftp. Use 'ssh' or 'cloud'.")
        ->assertExitCode(1);
});
