<?php

namespace Abigah\DbSyncFromProd\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

class RefreshFromProdCommand extends Command
{
    protected $signature = 'db:refresh-from-prod
                            {--dump= : Path to an existing dump/snapshot file to import (skips the production download)}
                            {--skip-local-backup : Skip backing up the local database before import}';

    protected $description = 'Replace the local database with a copy of the production database via SSH';

    private ?int $tunnelPid = null;

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('This command can only be run in the local environment.');

            return Command::FAILURE;
        }

        $connectionName = config('db-sync-from-prod.local_connection');
        $localConfig = config("database.connections.{$connectionName}");
        $driver = $localConfig['driver'] ?? null;

        return match ($driver) {
            'sqlite' => $this->syncSqlite($connectionName, $localConfig),
            'mysql', 'mariadb' => $this->syncMysql($connectionName, $localConfig),
            default => $this->unsupportedDriver($driver),
        };
    }

    private function unsupportedDriver(?string $driver): int
    {
        $this->error('Unsupported database driver: '.($driver ?? 'null').'. Only sqlite and mysql are supported.');

        return Command::FAILURE;
    }

    /**
     * Ensure the backup directory exists and is ignored by git.
     */
    private function ensureBackupDir(): string
    {
        $backupDir = config('db-sync-from-prod.backup_dir');

        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $gitignorePath = "{$backupDir}/.gitignore";
        if (! file_exists($gitignorePath)) {
            file_put_contents($gitignorePath, "*\n!.gitignore\n");
        }

        return $backupDir;
    }

    /*
    |--------------------------------------------------------------------------
    | SQLite
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{database: string}  $localConfig
     */
    private function syncSqlite(string $connectionName, array $localConfig): int
    {
        $localPath = $localConfig['database'];
        $existingDump = $this->option('dump');

        if ($existingDump) {
            if (! is_file($existingDump)) {
                $this->error("Dump file not found: {$existingDump}");

                return Command::FAILURE;
            }

            $this->warn("This will replace your local database ({$localPath}) with the dump at {$existingDump}.");
        } else {
            $sshHost = config('db-sync-from-prod.prod_ssh.host');
            $sshUser = config('db-sync-from-prod.prod_ssh.user');

            if (! $sshHost || ! $sshUser) {
                $this->error('Production SSH connection is not configured. Set PROD_SSH_HOST and PROD_SSH_USER in your .env file.');

                return Command::FAILURE;
            }

            $remotePath = config('db-sync-from-prod.prod_ssh.remote_db_path');

            if (! $remotePath) {
                $this->error('Remote database path is not configured. Set PROD_DB_PATH to the absolute path of the production SQLite file.');

                return Command::FAILURE;
            }

            $this->warn("This will replace your local database ({$localPath}) with the production database ({$sshUser}@{$sshHost}:{$remotePath}).");
        }

        if (! $this->confirm('Are you sure you want to continue?')) {
            $this->info('Aborted.');

            return Command::SUCCESS;
        }

        $backupDir = $this->ensureBackupDir();
        $timestamp = now()->format('Y-m-d_His');

        // Step 1: Backup local database
        $localBackupPath = "{$backupDir}/local-backup-{$timestamp}.sqlite";
        if ($this->option('skip-local-backup')) {
            $this->info('Skipping local database backup.');
            $localBackupPath = null;
        } elseif (! is_file($localPath)) {
            $this->info('No local database file found; skipping local backup.');
            $localBackupPath = null;
        } else {
            $this->info("Backing up local database to {$localBackupPath}...");
            if (! $this->backupSqliteDatabase($localPath, $localBackupPath)) {
                return Command::FAILURE;
            }
        }

        // Step 2: Snapshot production over SSH (unless a dump was provided)
        if ($existingDump) {
            $prodSnapshotPath = $existingDump;
        } else {
            $prodSnapshotPath = "{$backupDir}/prod-dump-{$timestamp}.sqlite";

            $this->info('Snapshotting production database over SSH...');
            if (! $this->pullProdSqlite($prodSnapshotPath)) {
                return Command::FAILURE;
            }
        }

        // Step 3: Swap the snapshot into place
        $this->info('Replacing local database...');
        if (! $this->replaceSqliteDatabase($connectionName, $localPath, $prodSnapshotPath)) {
            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('Database refresh complete!');
        if ($localBackupPath) {
            $this->line("  Local backup: {$localBackupPath}");
        }
        $this->line("  Prod dump:    {$prodSnapshotPath}");

        return Command::SUCCESS;
    }

    /**
     * Create a consistent (WAL-safe) snapshot of a local SQLite file.
     */
    protected function backupSqliteDatabase(string $sourcePath, string $destPath): bool
    {
        $result = Process::timeout(300)->run([
            'sqlite3', $sourcePath, ".backup '{$destPath}'",
        ]);

        if (! $result->successful()) {
            $this->error('SQLite backup failed: '.$result->errorOutput());
            @unlink($destPath);

            return false;
        }

        $this->info(sprintf('  Done. %s written.', $this->formatBytes((int) (@filesize($destPath) ?: 0))));

        return true;
    }

    /**
     * Snapshot the production SQLite file over SSH and download it.
     */
    protected function pullProdSqlite(string $destPath): bool
    {
        $sshUser = config('db-sync-from-prod.prod_ssh.user');
        $sshHost = config('db-sync-from-prod.prod_ssh.host');
        $sshPort = config('db-sync-from-prod.prod_ssh.port');
        $remotePath = config('db-sync-from-prod.prod_ssh.remote_db_path');
        $remoteTmp = '/tmp/db-sync-'.now()->format('Ymd_His').'-'.getmypid().'.sqlite';

        // Take a consistent snapshot on the remote host so an active WAL is captured.
        $remoteSnapshot = sprintf("sqlite3 %s \".backup '%s'\"", escapeshellarg($remotePath), $remoteTmp);
        $snapshot = Process::timeout(600)->run(sprintf(
            'ssh -o StrictHostKeyChecking=accept-new -p %s %s@%s %s',
            escapeshellarg($sshPort),
            escapeshellarg($sshUser),
            escapeshellarg($sshHost),
            escapeshellarg($remoteSnapshot),
        ));

        if (! $snapshot->successful()) {
            $this->error('Remote snapshot failed: '.$snapshot->errorOutput());

            return false;
        }

        // Download the snapshot.
        $scp = Process::timeout(600)->run(sprintf(
            'scp -o StrictHostKeyChecking=accept-new -P %s %s:%s %s',
            escapeshellarg($sshPort),
            escapeshellarg("{$sshUser}@{$sshHost}"),
            escapeshellarg($remoteTmp),
            escapeshellarg($destPath),
        ));

        // Always clean up the remote snapshot, regardless of the download outcome.
        Process::run(sprintf(
            'ssh -o StrictHostKeyChecking=accept-new -p %s %s@%s %s',
            escapeshellarg($sshPort),
            escapeshellarg($sshUser),
            escapeshellarg($sshHost),
            escapeshellarg("rm -f {$remoteTmp}"),
        ));

        if (! $scp->successful()) {
            $this->error('Failed to download production snapshot: '.$scp->errorOutput());
            @unlink($destPath);

            return false;
        }

        $this->info(sprintf('  Done. %s downloaded.', $this->formatBytes((int) (@filesize($destPath) ?: 0))));

        return true;
    }

    /**
     * Swap a SQLite snapshot in as the local database file.
     */
    protected function replaceSqliteDatabase(string $connectionName, string $localPath, string $snapshotPath): bool
    {
        if (! is_file($snapshotPath)) {
            $this->error("Snapshot file not found: {$snapshotPath}");

            return false;
        }

        // Drop open handles so the file can be replaced cleanly.
        DB::purge($connectionName);

        // Remove stale WAL/SHM sidecars left by the previous database.
        @unlink($localPath.'-wal');
        @unlink($localPath.'-shm');

        $dir = dirname($localPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (! @copy($snapshotPath, $localPath)) {
            $this->error("Failed to copy snapshot into place at {$localPath}.");

            return false;
        }

        $this->info('  Local database replaced.');

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | MySQL
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{database: string, charset?: string, collation?: string}  $localConfig
     */
    private function syncMysql(string $connectionName, array $localConfig): int
    {
        $existingDump = $this->option('dump');

        if ($existingDump) {
            if (! is_file($existingDump)) {
                $this->error("Dump file not found: {$existingDump}");

                return Command::FAILURE;
            }

            $prodDumpPath = $existingDump;
            $this->warn("This will replace your local database ({$localConfig['database']}) with the dump at {$prodDumpPath}.");
        } else {
            $sshHost = config('db-sync-from-prod.prod_ssh.host');
            $sshUser = config('db-sync-from-prod.prod_ssh.user');

            if (! $sshHost || ! $sshUser) {
                $this->error('Production SSH connection is not configured. Set PROD_SSH_HOST and PROD_SSH_USER in your .env file.');

                return Command::FAILURE;
            }

            $prodDatabase = config('db-sync-from-prod.prod_ssh.database');
            $this->warn("This will replace your local database ({$localConfig['database']}) with the production database ({$prodDatabase}).");
        }

        if (! $this->confirm('Are you sure you want to continue?')) {
            $this->info('Aborted.');

            return Command::SUCCESS;
        }

        $backupDir = $this->ensureBackupDir();

        $timestamp = now()->format('Y-m-d_His');
        $localDumpPath = "{$backupDir}/local-backup-{$timestamp}.sql";

        // Step 1: Backup local database
        if ($this->option('skip-local-backup')) {
            $this->info('Skipping local database backup.');
            $localDumpPath = null;
        } else {
            $this->info("Backing up local database to {$localDumpPath}...");
            if (! $this->dumpDatabase($localConfig, $localDumpPath)) {
                return Command::FAILURE;
            }
        }

        // Step 2: Open SSH tunnel and dump production database (unless a dump was provided)
        if (! $existingDump) {
            $prodDumpPath = "{$backupDir}/prod-dump-{$timestamp}.sql";

            $this->info('Opening SSH tunnel to production...');
            $localPort = $this->openSshTunnel();
            if (! $localPort) {
                return Command::FAILURE;
            }

            try {
                $this->info("Dumping production database to {$prodDumpPath}...");
                $prodConfig = [
                    'host' => '127.0.0.1',
                    'port' => (string) $localPort,
                    'username' => config('db-sync-from-prod.prod_ssh.db_username'),
                    'password' => config('db-sync-from-prod.prod_ssh.db_password'),
                    'database' => config('db-sync-from-prod.prod_ssh.database'),
                ];

                if (! $this->dumpDatabase($prodConfig, $prodDumpPath)) {
                    return Command::FAILURE;
                }
            } finally {
                $this->closeSshTunnel();
            }
        }

        // Step 3: Drop and recreate the local database
        $this->info('Dropping and recreating local database...');
        if (! $this->recreateDatabase($connectionName, $localConfig)) {
            return Command::FAILURE;
        }

        // Step 4: Import production dump
        $this->info('Importing production database...');
        if (! $this->importDatabase($localConfig, $prodDumpPath)) {
            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('Database refresh complete!');
        if ($localDumpPath) {
            $this->line("  Local backup: {$localDumpPath}");
        }
        $this->line("  Prod dump:    {$prodDumpPath}");

        return Command::SUCCESS;
    }

    private function openSshTunnel(): ?int
    {
        $localPort = random_int(10000, 60000);
        $sshUser = config('db-sync-from-prod.prod_ssh.user');
        $sshHost = config('db-sync-from-prod.prod_ssh.host');
        $sshPort = config('db-sync-from-prod.prod_ssh.port');
        $remoteDbHost = config('db-sync-from-prod.prod_ssh.db_host');
        $remoteDbPort = config('db-sync-from-prod.prod_ssh.db_port');

        $command = sprintf(
            'ssh -f -N -o StrictHostKeyChecking=accept-new -L %d:%s:%s -p %s %s@%s',
            $localPort,
            escapeshellarg($remoteDbHost),
            escapeshellarg($remoteDbPort),
            escapeshellarg($sshPort),
            escapeshellarg($sshUser),
            escapeshellarg($sshHost),
        );

        $result = Process::timeout(30)->run($command);

        if (! $result->successful()) {
            $this->error('SSH tunnel failed: '.$result->errorOutput());

            return null;
        }

        $pidResult = Process::run("lsof -ti tcp:{$localPort} -sTCP:LISTEN");
        $this->tunnelPid = (int) trim($pidResult->output());

        $this->info("  Tunnel open on port {$localPort} (PID: {$this->tunnelPid})");

        sleep(1);

        return $localPort;
    }

    private function closeSshTunnel(): void
    {
        if ($this->tunnelPid) {
            Process::run("kill {$this->tunnelPid}");
            $this->info('  SSH tunnel closed.');
            $this->tunnelPid = null;
        }
    }

    /**
     * @param  array{host: string, port: string, username: string, password: string, database: string}  $config
     */
    protected function dumpDatabase(array $config, string $outputPath): bool
    {
        $estimatedSize = $this->estimateDatabaseSize($config);

        if ($estimatedSize) {
            $this->info(sprintf('  Estimated size: ~%s', $this->formatBytes($estimatedSize)));
        }

        $command = [
            'mysqldump',
            '-h', $config['host'],
            '-P', $config['port'],
            '-u', $config['username'],
            '--set-gtid-purged=OFF',
            $config['database'],
        ];

        if (! empty($config['password'])) {
            array_splice($command, 5, 0, ['-p'.$config['password']]);
        }

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (! is_resource($process)) {
            $this->error('Failed to start mysqldump process.');

            return false;
        }

        fclose($pipes[0]);

        $handle = fopen($outputPath, 'w');
        $bar = $estimatedSize ? $this->output->createProgressBar($estimatedSize) : null;
        $bar?->start();

        $bytesWritten = 0;
        $chunkSize = 65536;

        while (! feof($pipes[1])) {
            $chunk = fread($pipes[1], $chunkSize);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            fwrite($handle, $chunk);
            $bytesWritten += strlen($chunk);
            if ($bar) {
                // Cap at estimate - 1 until completion so we don't hit 100% prematurely
                $bar->setProgress(min($bytesWritten, $estimatedSize - 1));
            }
        }

        fclose($handle);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($bar) {
            $bar->finish();
            $this->newLine();
        }

        if ($exitCode !== 0) {
            $this->error('mysqldump failed: '.$stderr);
            @unlink($outputPath);

            return false;
        }

        $this->info(sprintf('  Done. %s written.', $this->formatBytes($bytesWritten)));

        return true;
    }

    /**
     * @param  array{host: string, port: string, username: string, password?: string, database: string}  $config
     */
    private function estimateDatabaseSize(array $config): ?int
    {
        try {
            $dsn = sprintf('mysql:host=%s;port=%s', $config['host'], $config['port']);
            $pdo = new \PDO($dsn, $config['username'], $config['password'] ?? '', [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 10,
            ]);
            $stmt = $pdo->prepare('SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.tables WHERE TABLE_SCHEMA = ?');
            $stmt->execute([$config['database']]);
            $size = $stmt->fetchColumn();

            return $size ? (int) $size : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array{database: string, charset?: string, collation?: string}  $config
     */
    private function recreateDatabase(string $connectionName, array $config): bool
    {
        try {
            $database = $config['database'];
            $charset = $config['charset'] ?? 'utf8mb4';
            $collation = $config['collation'] ?? 'utf8mb4_unicode_ci';

            $connection = DB::connection($connectionName);
            $connection->statement("DROP DATABASE IF EXISTS `{$database}`");
            $connection->statement("CREATE DATABASE `{$database}` CHARACTER SET {$charset} COLLATE {$collation}");
            $connection->statement("USE `{$database}`");

            $this->info('  Database recreated.');

            return true;
        } catch (\Exception $e) {
            $this->error('Failed to recreate database: '.$e->getMessage());

            return false;
        }
    }

    /**
     * @param  array{host: string, port: string, username: string, password: string, database: string}  $config
     */
    protected function importDatabase(array $config, string $dumpPath): bool
    {
        $fileSize = filesize($dumpPath);
        $this->info(sprintf('  Dump file size: %s', $this->formatBytes($fileSize)));

        $command = sprintf(
            'mysql -h %s -P %s -u %s %s %s',
            escapeshellarg($config['host']),
            escapeshellarg($config['port']),
            escapeshellarg($config['username']),
            ! empty($config['password']) ? '-p'.escapeshellarg($config['password']) : '',
            escapeshellarg($config['database']),
        );

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (! is_resource($process)) {
            $this->error('Failed to start mysql process.');

            return false;
        }

        $handle = fopen($dumpPath, 'r');
        $bar = $this->output->createProgressBar($fileSize);
        $bar->start();

        $chunkSize = 65536;

        while (! feof($handle)) {
            $chunk = fread($handle, $chunkSize);
            fwrite($pipes[0], $chunk);
            $bar->advance(strlen($chunk));
        }

        fclose($handle);
        fclose($pipes[0]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $bar->finish();
        $this->newLine();

        if ($exitCode !== 0) {
            $this->error('Import failed: '.$stderr);

            return false;
        }

        $this->info('  Done.');

        return true;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
