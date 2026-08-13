<?php

use Abigah\DbSyncFromProd\Commands\RefreshFromProdCommand;

beforeEach(function () {
    $this->tempBackupDir = sys_get_temp_dir().'/db-sync-tests-'.uniqid();
    $this->localDbPath = sys_get_temp_dir().'/db-sync-local-'.uniqid().'.sqlite';
    file_put_contents($this->localDbPath, 'local-data');

    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => $this->localDbPath,
    ]);

    config()->set('db-sync-from-prod.local_connection', 'sqlite');
    config()->set('db-sync-from-prod.backup_dir', $this->tempBackupDir);
    config()->set('db-sync-from-prod.prod_ssh', [
        'host' => 'prod.example.com',
        'user' => 'deploy',
        'port' => '22',
        'remote_db_path' => '/var/www/app/database/database.sqlite',
    ]);
});

afterEach(function () {
    if (isset($this->tempBackupDir) && is_dir($this->tempBackupDir)) {
        array_map('unlink', glob($this->tempBackupDir.'/*') ?: []);
        @rmdir($this->tempBackupDir);
    }

    @unlink($this->localDbPath);
});

/**
 * Install a stub that replaces the real sqlite3/ssh/scp shell work so the
 * orchestration can be tested without touching a server or external binaries.
 */
function stubSqliteCommand(): object
{
    $stub = new class extends RefreshFromProdCommand
    {
        public bool $backupShouldFail = false;

        public bool $pullShouldFail = false;

        public bool $replaceShouldFail = false;

        public int $backupCallCount = 0;

        public int $pullCallCount = 0;

        public int $replaceCallCount = 0;

        protected function backupSqliteDatabase(string $sourcePath, string $destPath): bool
        {
            $this->backupCallCount++;

            if ($this->backupShouldFail) {
                $this->error('SQLite backup failed: stubbed');

                return false;
            }

            file_put_contents($destPath, 'stub-backup');
            $this->info('  Done.');

            return true;
        }

        protected function pullProdSqlite(string $destPath): bool
        {
            $this->pullCallCount++;

            if ($this->pullShouldFail) {
                $this->error('Remote snapshot failed: stubbed');

                return false;
            }

            file_put_contents($destPath, 'stub-snapshot');
            $this->info('  Done.');

            return true;
        }

        protected function replaceSqliteDatabase(string $connectionName, string $localPath, string $snapshotPath): bool
        {
            $this->replaceCallCount++;

            if ($this->replaceShouldFail) {
                $this->error('Failed to copy snapshot into place');

                return false;
            }

            $this->info('  Local database replaced.');

            return true;
        }
    };

    app()->instance(RefreshFromProdCommand::class, $stub);

    return $stub;
}

/**
 * Install a stub that skips the shell work but keeps the real file swap, so the
 * snapshot is genuinely copied into place over the local database.
 */
function stubSqliteCommandWithRealReplace(): object
{
    $stub = new class extends RefreshFromProdCommand
    {
        protected function backupSqliteDatabase(string $sourcePath, string $destPath): bool
        {
            file_put_contents($destPath, 'stub-backup');
            $this->info('  Done.');

            return true;
        }
    };

    app()->instance(RefreshFromProdCommand::class, $stub);

    return $stub;
}

it('completes a full sqlite refresh on the happy path', function () {
    $stub = stubSqliteCommand();

    $this->artisan('db:refresh-from-prod')
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsOutputToContain('Database refresh complete!')
        ->assertExitCode(0);

    expect($stub->backupCallCount)->toBe(1)
        ->and($stub->pullCallCount)->toBe(1)
        ->and($stub->replaceCallCount)->toBe(1);
});

it('fails when the remote database path is not configured', function () {
    config()->set('db-sync-from-prod.prod_ssh.remote_db_path', null);

    $this->artisan('db:refresh-from-prod')
        ->expectsOutputToContain('Remote database path is not configured.')
        ->assertExitCode(1);
});

it('fails when the production ssh host is not configured for sqlite', function () {
    config()->set('db-sync-from-prod.prod_ssh.host', null);

    $this->artisan('db:refresh-from-prod')
        ->expectsOutputToContain('Production SSH connection is not configured.')
        ->assertExitCode(1);
});

it('fails when the local sqlite backup fails', function () {
    $stub = stubSqliteCommand();
    $stub->backupShouldFail = true;

    $this->artisan('db:refresh-from-prod')
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsOutputToContain('SQLite backup failed: stubbed')
        ->assertExitCode(1);

    expect($stub->pullCallCount)->toBe(0);
});

it('fails when the production snapshot cannot be pulled', function () {
    $stub = stubSqliteCommand();
    $stub->pullShouldFail = true;

    $this->artisan('db:refresh-from-prod')
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsOutputToContain('Remote snapshot failed: stubbed')
        ->assertExitCode(1);

    expect($stub->replaceCallCount)->toBe(0);
});

it('skips the local backup when --skip-local-backup is passed', function () {
    $stub = stubSqliteCommand();

    $this->artisan('db:refresh-from-prod', ['--skip-local-backup' => true])
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsOutputToContain('Skipping local database backup.')
        ->assertExitCode(0);

    expect($stub->backupCallCount)->toBe(0)
        ->and($stub->pullCallCount)->toBe(1)
        ->and($stub->replaceCallCount)->toBe(1);
});

it('skips the local backup when no local database file exists', function () {
    @unlink($this->localDbPath);

    $stub = stubSqliteCommand();

    $this->artisan('db:refresh-from-prod')
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsOutputToContain('No local database file found; skipping local backup.')
        ->assertExitCode(0);

    expect($stub->backupCallCount)->toBe(0)
        ->and($stub->pullCallCount)->toBe(1);
});

it('uses an existing snapshot when --dump is passed', function () {
    $dumpFile = tempnam(sys_get_temp_dir(), 'snap-').'.sqlite';
    file_put_contents($dumpFile, 'existing-snapshot');

    $stub = stubSqliteCommand();

    $this->artisan('db:refresh-from-prod', ['--dump' => $dumpFile])
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsOutputToContain("dump at {$dumpFile}")
        ->doesntExpectOutputToContain('Snapshotting production database')
        ->assertExitCode(0);

    // Local backup runs, prod snapshot is skipped, replace still runs.
    expect($stub->backupCallCount)->toBe(1)
        ->and($stub->pullCallCount)->toBe(0)
        ->and($stub->replaceCallCount)->toBe(1);

    @unlink($dumpFile);
});

it('fails when the --dump file does not exist', function () {
    $missing = sys_get_temp_dir().'/does-not-exist-'.uniqid().'.sqlite';

    $this->artisan('db:refresh-from-prod', ['--dump' => $missing])
        ->expectsOutputToContain("Dump file not found: {$missing}")
        ->assertExitCode(1);
});

it('aborts when the user declines the confirmation', function () {
    stubSqliteCommand();

    $this->artisan('db:refresh-from-prod')
        ->expectsConfirmation('Are you sure you want to continue?', 'no')
        ->expectsOutputToContain('Aborted.')
        ->assertExitCode(0);
});

it('clears the stale wal and shm sidecars when swapping the snapshot in', function () {
    file_put_contents($this->localDbPath.'-wal', 'stale-wal');
    file_put_contents($this->localDbPath.'-shm', 'stale-shm');

    $dumpFile = sys_get_temp_dir().'/db-sync-snapshot-'.uniqid().'.sqlite';
    file_put_contents($dumpFile, 'snapshot-data');

    stubSqliteCommandWithRealReplace();

    $this->artisan('db:refresh-from-prod', ['--dump' => $dumpFile])
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->assertExitCode(0);

    expect(file_get_contents($this->localDbPath))->toBe('snapshot-data')
        ->and(file_exists($this->localDbPath.'-wal'))->toBeFalse()
        ->and(file_exists($this->localDbPath.'-shm'))->toBeFalse();

    @unlink($dumpFile);
});

it('clears the sidecars beside the real file when the configured path is a symlink', function () {
    // Mirrors the common Laravel layout where database/database.sqlite is a
    // symlink to the real file under storage/.
    $realDir = sys_get_temp_dir().'/db-sync-real-'.uniqid();
    mkdir($realDir, 0755, true);
    $realPath = $realDir.'/database.sqlite';
    file_put_contents($realPath, 'local-data');
    file_put_contents($realPath.'-wal', 'stale-wal');
    file_put_contents($realPath.'-shm', 'stale-shm');

    $linkPath = sys_get_temp_dir().'/db-sync-link-'.uniqid().'.sqlite';
    symlink($realPath, $linkPath);
    config()->set('database.connections.sqlite.database', $linkPath);

    $dumpFile = sys_get_temp_dir().'/db-sync-snapshot-'.uniqid().'.sqlite';
    file_put_contents($dumpFile, 'snapshot-data');

    stubSqliteCommandWithRealReplace();

    $this->artisan('db:refresh-from-prod', ['--dump' => $dumpFile])
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->assertExitCode(0);

    expect(file_get_contents($realPath))->toBe('snapshot-data')
        ->and(is_link($linkPath))->toBeTrue()
        ->and(file_exists($realPath.'-wal'))->toBeFalse()
        ->and(file_exists($realPath.'-shm'))->toBeFalse();

    @unlink($dumpFile);
    @unlink($linkPath);
    @unlink($realPath);
    @rmdir($realDir);
});

it('fails for an unsupported driver', function () {
    config()->set('database.connections.pgsql', [
        'driver' => 'pgsql',
        'database' => 'whatever',
    ]);
    config()->set('db-sync-from-prod.local_connection', 'pgsql');

    $this->artisan('db:refresh-from-prod')
        ->expectsOutputToContain('Unsupported database driver: pgsql')
        ->assertExitCode(1);
});
