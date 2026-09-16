<?php

use Abigah\DbSyncFromProd\Commands\RefreshFromProdCommand;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->tempBackupDir = sys_get_temp_dir().'/db-sync-tests-'.uniqid();
    $this->localDbPath = sys_get_temp_dir().'/db-sync-local-'.uniqid().'.sqlite';
    $this->prodDbPath = sys_get_temp_dir().'/db-sync-prod-'.uniqid().'.sqlite';

    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => $this->localDbPath,
    ]);

    config()->set('db-sync-from-prod.local_connection', 'sqlite');
    config()->set('db-sync-from-prod.backup_dir', $this->tempBackupDir);
    config()->set('db-sync-from-prod.preserve_local_auth.enabled', true);

    // The local site: the developer has a passkey production has never seen,
    // and two-factor set up with their local app key.
    createAuthDatabase($this->localDbPath, function (PDO $db) {
        $db->exec("INSERT INTO users (id, email, two_factor_secret, two_factor_confirmed_at) VALUES
            (1, 'dev@example.com', 'local-secret', '2026-01-01 00:00:00'),
            (2, 'colleague@example.com', NULL, NULL)");
        $db->exec("INSERT INTO passkeys (id, user_id, name, credential_id, credential) VALUES
            (1, 1, 'Prod key', 'prod-credential', '{}'),
            (2, 1, 'Local key', 'local-credential', '{\"k\":1}')");
    });

    // Production: the same people, but the colleague has since enabled
    // two-factor there and production has its own passkey ids.
    createAuthDatabase($this->prodDbPath, function (PDO $db) {
        $db->exec("INSERT INTO users (id, email, two_factor_secret, two_factor_confirmed_at) VALUES
            (1, 'dev@example.com', 'prod-secret', '2025-06-01 00:00:00'),
            (2, 'colleague@example.com', 'colleague-prod-secret', '2026-02-01 00:00:00')");
        $db->exec("INSERT INTO passkeys (id, user_id, name, credential_id, credential) VALUES
            (7, 1, 'Prod key', 'prod-credential', '{}')");
    });

    // Skip the shell work but keep the real file swap, so the refresh
    // genuinely replaces the local database.
    app()->instance(RefreshFromProdCommand::class, new class extends RefreshFromProdCommand
    {
        protected function backupSqliteDatabase(string $sourcePath, string $destPath): bool
        {
            return true;
        }
    });
});

afterEach(function () {
    DB::purge('sqlite');

    if (isset($this->tempBackupDir) && is_dir($this->tempBackupDir)) {
        array_map('unlink', glob($this->tempBackupDir.'/*') ?: []);
        @rmdir($this->tempBackupDir);
    }

    @unlink($this->localDbPath);
    @unlink($this->prodDbPath);
});

function createAuthDatabase(string $path, Closure $seed): void
{
    $db = new PDO('sqlite:'.$path);
    $db->exec('CREATE TABLE users (id integer primary key, email text unique, two_factor_secret text, two_factor_recovery_codes text, two_factor_confirmed_at text)');
    $db->exec('CREATE TABLE passkeys (id integer primary key autoincrement, user_id integer, name text, credential_id text unique, credential text, last_used_at text, created_at text, updated_at text)');
    $seed($db);
}

function refreshFromProdDump(object $test, string $keepPasskeys = 'yes', string $keepTwoFactor = 'yes'): object
{
    return $test->artisan('db:refresh-from-prod', ['--dump' => $test->prodDbPath])
        ->expectsConfirmation('Keep your local passkeys? (ones production already has are unaffected)', $keepPasskeys)
        ->expectsConfirmation("Keep your local two-factor settings? (otherwise production's are used)", $keepTwoFactor)
        ->expectsConfirmation('Are you sure you want to continue?', 'yes');
}

it('puts back passkeys registered locally that production lacks', function () {
    refreshFromProdDump($this)
        ->expectsOutputToContain('Restored 1 passkey(s)')
        ->assertExitCode(0);

    expect(DB::connection('sqlite')->table('passkeys')->orderBy('credential_id')->pluck('credential_id')->all())
        ->toBe(['local-credential', 'prod-credential']);

    expect(DB::connection('sqlite')->table('passkeys')->where('credential_id', 'local-credential')->value('credential'))
        ->toBe('{"k":1}');
});

it('puts back two-factor settings only for users who had them locally', function () {
    refreshFromProdDump($this)->assertExitCode(0);

    $users = DB::connection('sqlite')->table('users')->pluck('two_factor_secret', 'email');

    expect($users['dev@example.com'])->toBe('local-secret')
        ->and($users['colleague@example.com'])->toBe('colleague-prod-secret');
});

it('matches users by email and warns when a passkey changes owner id', function () {
    $prod = new PDO('sqlite:'.$this->prodDbPath);
    $prod->exec("UPDATE users SET id = 42 WHERE email = 'dev@example.com'");
    $prod->exec('UPDATE passkeys SET user_id = 42');

    refreshFromProdDump($this)
        ->expectsOutputToContain('Passkey "Local key" moved from user #1 to #42')
        ->assertExitCode(0);

    expect(DB::connection('sqlite')->table('passkeys')->where('credential_id', 'local-credential')->value('user_id'))
        ->toEqual(42);
});

it('skips auth for users production does not have', function () {
    $prod = new PDO('sqlite:'.$this->prodDbPath);
    $prod->exec('DELETE FROM passkeys');
    $prod->exec("DELETE FROM users WHERE email = 'dev@example.com'");

    refreshFromProdDump($this)
        ->expectsOutputToContain('Skipped passkey "Local key": no user dev@example.com.')
        ->expectsOutputToContain('Skipped two-factor settings: no user dev@example.com.')
        ->assertExitCode(0);

    expect(DB::connection('sqlite')->table('passkeys')->count())->toBe(0);
});

it('asks about passkeys and two-factor right away, naming what each covers', function () {
    refreshFromProdDump($this)
        ->expectsOutputToContain('Local passkeys: Prod key (dev@example.com), Local key (dev@example.com)')
        ->expectsOutputToContain('Local two-factor settings: dev@example.com')
        ->assertExitCode(0);
});

it('keeps only the local passkeys when two-factor is declined', function () {
    refreshFromProdDump($this, keepPasskeys: 'yes', keepTwoFactor: 'no')->assertExitCode(0);

    expect(DB::connection('sqlite')->table('passkeys')->count())->toBe(2)
        ->and(DB::connection('sqlite')->table('users')->where('id', 1)->value('two_factor_secret'))->toBe('prod-secret');
});

it('keeps only the local two-factor settings when passkeys are declined', function () {
    refreshFromProdDump($this, keepPasskeys: 'no', keepTwoFactor: 'yes')->assertExitCode(0);

    expect(DB::connection('sqlite')->table('passkeys')->pluck('credential_id')->all())->toBe(['prod-credential'])
        ->and(DB::connection('sqlite')->table('users')->where('id', 1)->value('two_factor_secret'))->toBe('local-secret');
});

it('leaves the production copy untouched when preserving local auth is disabled', function () {
    config()->set('db-sync-from-prod.preserve_local_auth.enabled', false);

    $this->artisan('db:refresh-from-prod', ['--dump' => $this->prodDbPath])
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->doesntExpectOutputToContain('Restoring local passkeys')
        ->assertExitCode(0);

    expect(DB::connection('sqlite')->table('passkeys')->pluck('credential_id')->all())->toBe(['prod-credential'])
        ->and(DB::connection('sqlite')->table('users')->where('id', 1)->value('two_factor_secret'))->toBe('prod-secret');
});

it('refreshes normally when the app has no passkeys or two-factor columns', function () {
    foreach ([$this->localDbPath, $this->prodDbPath] as $path) {
        @unlink($path);
        (new PDO('sqlite:'.$path))->exec('CREATE TABLE users (id integer primary key, email text)');
    }

    // Nothing to keep, so neither question is asked.
    $this->artisan('db:refresh-from-prod', ['--dump' => $this->prodDbPath])
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->doesntExpectOutputToContain('Restoring local passkeys')
        ->expectsOutputToContain('Database refresh complete!')
        ->assertExitCode(0);
});
