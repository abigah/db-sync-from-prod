<?php

namespace Abigah\DbSyncFromProd\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

class RefreshFromProdCommand extends Command
{
    protected $signature = 'db:refresh-from-prod
                            {--dump= : Path to an existing prod dump file to import (skips SSH tunnel and mysqldump)}
                            {--skip-local-backup : Skip backing up the local database before import}';

    protected $description = 'Replace the local database with a copy of the production database via SSH tunnel';

    private ?int $tunnelPid = null;

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('This command can only be run in the local environment.');

            return Command::FAILURE;
        }

        $connectionName = config('db-sync-from-prod.local_connection');
        $localConfig = config("database.connections.{$connectionName}");
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

        $backupDir = config('db-sync-from-prod.backup_dir');
        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

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
