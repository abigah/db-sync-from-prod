<?php

use Abigah\DbSyncFromProd\Commands\RefreshFromProdCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->tempBackupDir = sys_get_temp_dir().'/db-sync-tests-'.uniqid();

    config()->set('db-sync-from-prod.local_connection', 'mysql');
    config()->set('db-sync-from-prod.backup_dir', $this->tempBackupDir);
    config()->set('db-sync-from-prod.prod_ssh', [
        'host' => 'prod.example.com',
        'user' => 'deploy',
        'port' => '22',
        'db_host' => '127.0.0.1',
        'db_port' => '3306',
        'db_username' => 'app',
        'db_password' => 'secret',
        'database' => 'app_production',
    ]);
});

afterEach(function () {
    if (isset($this->tempBackupDir) && is_dir($this->tempBackupDir)) {
        array_map('unlink', glob($this->tempBackupDir.'/*') ?: []);
        @rmdir($this->tempBackupDir);
    }
});

/**
 * Install a stub command that replaces the real mysqldump/mysql shell work.
 *
 * Both dumpDatabase and importDatabase are stubbed because they use proc_open,
 * which bypasses Laravel's Process facade faking.
 */
function stubCommand(): object
{
    $stub = new class extends RefreshFromProdCommand
    {
        public bool $dumpShouldFail = false;

        public bool $importShouldFail = false;

        public int $dumpCallCount = 0;

        public int $importCallCount = 0;

        protected function dumpDatabase(array $config, string $outputPath): bool
        {
            $this->dumpCallCount++;

            if ($this->dumpShouldFail) {
                $this->error('mysqldump failed: Access denied');

                return false;
            }

            file_put_contents($outputPath, '-- stubbed dump');
            $this->info('  Done.');

            return true;
        }

        protected function importDatabase(array $config, string $dumpPath): bool
        {
            $this->importCallCount++;

            if ($this->importShouldFail) {
                $this->error('Import failed: stubbed');

                return false;
            }

            $this->info('  [import stubbed]');

            return true;
        }
    };

    app()->instance(RefreshFromProdCommand::class, $stub);

    return $stub;
}

it('refuses to run outside the local environment', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('db:refresh-from-prod')
        ->expectsOutputToContain('This command can only be run in the local environment.')
        ->assertExitCode(1);
});

it('fails when the production ssh host is not configured', function () {
    config()->set('db-sync-from-prod.prod_ssh.host', null);

    $this->artisan('db:refresh-from-prod')
        ->expectsOutputToContain('Production SSH connection is not configured.')
        ->assertExitCode(1);
});

it('fails when the production ssh user is not configured', function () {
    config()->set('db-sync-from-prod.prod_ssh.user', null);

    $this->artisan('db:refresh-from-prod')
        ->expectsOutputToContain('Production SSH connection is not configured.')
        ->assertExitCode(1);
});

it('fails when the --dump file does not exist', function () {
    $missing = sys_get_temp_dir().'/does-not-exist-'.uniqid().'.sql';

    $this->artisan('db:refresh-from-prod', ['--dump' => $missing])
        ->expectsOutputToContain("Dump file not found: {$missing}")
        ->assertExitCode(1);
});

it('aborts when the user declines the confirmation', function () {
    $this->artisan('db:refresh-from-prod')
        ->expectsConfirmation('Are you sure you want to continue?', 'no')
        ->expectsOutputToContain('Aborted.')
        ->assertExitCode(0);
});

it('fails when the local mysqldump fails', function () {
    $stub = stubCommand();
    $stub->dumpShouldFail = true;

    $this->artisan('db:refresh-from-prod')
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsOutputToContain('mysqldump failed: Access denied')
        ->assertExitCode(1);
});

it('fails when the ssh tunnel cannot be opened', function () {
    stubCommand();

    Process::fake([
        'ssh *' => Process::result(errorOutput: 'Permission denied (publickey).', exitCode: 255),
    ]);

    $this->artisan('db:refresh-from-prod')
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsOutputToContain('SSH tunnel failed: Permission denied (publickey).')
        ->assertExitCode(1);
});

it('fails when recreating the local database throws', function () {
    stubCommand();

    Process::fake([
        'lsof*' => Process::result(output: '54321'),
        'ssh *' => Process::result(),
        'kill *' => Process::result(),
    ]);

    $connection = Mockery::mock();
    $connection->shouldReceive('statement')->andThrow(new \RuntimeException('insufficient privileges'));

    DB::shouldReceive('connection')->with('mysql')->andReturn($connection);

    $this->artisan('db:refresh-from-prod')
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsOutputToContain('Failed to recreate database: insufficient privileges')
        ->assertExitCode(1);
});

it('completes a full refresh on the happy path', function () {
    $stub = stubCommand();

    Process::fake([
        'lsof*' => Process::result(output: '54321'),
        'ssh *' => Process::result(),
        'kill *' => Process::result(),
    ]);

    $connection = Mockery::mock();
    $connection->shouldReceive('statement')->with('DROP DATABASE IF EXISTS `local_db`')->once();
    $connection->shouldReceive('statement')->with('CREATE DATABASE `local_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')->once();
    $connection->shouldReceive('statement')->with('USE `local_db`')->once();

    DB::shouldReceive('connection')->with('mysql')->andReturn($connection);

    $this->artisan('db:refresh-from-prod')
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsOutputToContain('Database refresh complete!')
        ->assertExitCode(0);

    expect($stub->dumpCallCount)->toBe(2)   // local + prod
        ->and($stub->importCallCount)->toBe(1);

    Process::assertRan(fn ($process) => str_starts_with((string) $process->command, 'ssh '));
    Process::assertRan(fn ($process) => str_starts_with((string) $process->command, 'kill '));
});

it('skips the local backup when --skip-local-backup is passed', function () {
    $stub = stubCommand();

    Process::fake([
        'lsof*' => Process::result(output: '54321'),
        'ssh *' => Process::result(),
        'kill *' => Process::result(),
    ]);

    $connection = Mockery::mock();
    $connection->shouldReceive('statement')->andReturnNull();

    DB::shouldReceive('connection')->with('mysql')->andReturn($connection);

    $this->artisan('db:refresh-from-prod', ['--skip-local-backup' => true])
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsOutputToContain('Skipping local database backup.')
        ->doesntExpectOutputToContain('Backing up local database')
        ->assertExitCode(0);

    // Only one dump (prod), not two (no local backup)
    expect($stub->dumpCallCount)->toBe(1);
});

it('uses an existing dump file when --dump is passed', function () {
    $dumpFile = tempnam(sys_get_temp_dir(), 'dump-').'.sql';
    file_put_contents($dumpFile, "-- test dump\n");

    $stub = stubCommand();

    $connection = Mockery::mock();
    $connection->shouldReceive('statement')->andReturnNull();

    DB::shouldReceive('connection')->with('mysql')->andReturn($connection);

    $this->artisan('db:refresh-from-prod', ['--dump' => $dumpFile])
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsOutputToContain("dump at {$dumpFile}")
        ->doesntExpectOutputToContain('Opening SSH tunnel')
        ->assertExitCode(0);

    // Local dump runs (backup), but prod dump is skipped because --dump was given
    expect($stub->dumpCallCount)->toBe(1)
        ->and($stub->importCallCount)->toBe(1);

    @unlink($dumpFile);
});
