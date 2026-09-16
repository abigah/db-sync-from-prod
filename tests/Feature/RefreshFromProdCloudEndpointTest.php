<?php

use Abigah\DbSyncFromProd\Commands\RefreshFromProdCommand;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const OPEN_QUESTION = "The production database's public endpoint is closed. Open it for this sync?";
const CLOSE_QUESTION = "Close the production database's public endpoint again? (say no if another sync is still using it)";

beforeEach(function () {
    $this->tempBackupDir = sys_get_temp_dir().'/db-sync-tests-'.uniqid();

    config()->set('db-sync-from-prod.local_connection', 'mysql');
    config()->set('db-sync-from-prod.backup_dir', $this->tempBackupDir);
    config()->set('db-sync-from-prod.source', 'cloud');
    config()->set('db-sync-from-prod.prod_cloud', [
        'host' => 'db-abc123.ca-central-1.public.db.laravel.cloud',
        'port' => '3306',
        'username' => 'app',
        'password' => 'secret',
        'database' => 'production',
        'ssl_mode' => 'REQUIRED',
        'ssl_ca' => null,
    ]);
    config()->set('db-sync-from-prod.cloud_endpoint', [
        'cluster_id' => 'db-1234',
        'api_url' => 'https://cloud.test/api',
        'token' => 'token-abc',
        'token_file' => null,
        'wait' => 0,
    ]);

    // The cluster as the API holds it: GETs read it, PATCHes replace its config.
    $this->steps = [];
    $this->cluster = ['is_public' => false, 'retention_days' => 1, 'size' => 'mysql-flex-512mb'];

    $this->api = function (Request $request) {
        if ($request->method() === 'PATCH') {
            $this->cluster = $request['config'];
            $this->steps[] = $this->cluster['is_public'] ? 'open' : 'close';
        }

        return Http::response(['data' => ['id' => 'db-1234', 'attributes' => ['config' => $this->cluster]]]);
    };

    // Http::fake stacks rather than replaces, so tests swap $this->api instead.
    Http::fake(fn (Request $request) => ($this->api)($request));

    $connection = Mockery::mock();
    $connection->shouldReceive('statement')->andReturnNull();
    DB::shouldReceive('connection')->with('mysql')->andReturn($connection);

    $this->command = new class extends RefreshFromProdCommand
    {
        public ?Closure $onDump = null;

        public bool $dumpShouldFail = false;

        public bool $endpointBecomesReady = true;

        protected function dumpDatabase(array $config, string $outputPath): bool
        {
            ($this->onDump)();

            if ($this->dumpShouldFail) {
                $this->error('mysqldump failed: stubbed');

                return false;
            }

            file_put_contents($outputPath, '-- stubbed dump');

            return true;
        }

        protected function importDatabase(array $config, string $dumpPath): bool
        {
            return true;
        }

        protected function awaitEndpoint(): bool
        {
            return $this->endpointBecomesReady;
        }
    };
    $this->command->onDump = function () {
        $this->steps[] = 'dump';
    };

    app()->instance(RefreshFromProdCommand::class, $this->command);
});

afterEach(function () {
    if (isset($this->tempBackupDir) && is_dir($this->tempBackupDir)) {
        array_map('unlink', glob($this->tempBackupDir.'/*') ?: []);
        @rmdir($this->tempBackupDir);
    }
});

it('opens a closed endpoint just for the production dump and closes it afterwards', function () {
    $this->artisan('db:refresh-from-prod', ['--skip-local-backup' => true])
        ->expectsConfirmation(OPEN_QUESTION, 'yes')
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsConfirmation(CLOSE_QUESTION, 'yes')
        ->expectsOutputToContain('Database refresh complete!')
        ->assertExitCode(0);

    expect($this->steps)->toBe(['open', 'dump', 'close'])
        // The API replaces the config block, so the rest must be sent back.
        ->and($this->cluster)->toBe(['is_public' => false, 'retention_days' => 1, 'size' => 'mysql-flex-512mb']);
});

it('aborts without touching anything when opening the endpoint is declined', function () {
    $this->artisan('db:refresh-from-prod')
        ->expectsConfirmation(OPEN_QUESTION, 'no')
        ->expectsOutputToContain('Aborted.')
        ->assertExitCode(0);

    expect($this->steps)->toBe([]);
});

it('never opens the endpoint when the sync is not confirmed', function () {
    $this->artisan('db:refresh-from-prod')
        ->expectsConfirmation(OPEN_QUESTION, 'yes')
        ->expectsConfirmation('Are you sure you want to continue?', 'no')
        ->assertExitCode(0);

    expect($this->steps)->toBe([]);
});

it('leaves an endpoint that was already open alone, as another sync may be using it', function () {
    $this->cluster['is_public'] = true;

    $this->artisan('db:refresh-from-prod', ['--skip-local-backup' => true])
        ->expectsOutputToContain('already open. It will be left open')
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->assertExitCode(0);

    expect($this->steps)->toBe(['dump'])
        ->and($this->cluster['is_public'])->toBeTrue();
});

it('leaves the endpoint open when closing it is declined', function () {
    $this->artisan('db:refresh-from-prod', ['--skip-local-backup' => true])
        ->expectsConfirmation(OPEN_QUESTION, 'yes')
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsConfirmation(CLOSE_QUESTION, 'no')
        ->expectsOutputToContain('The public endpoint was left open.')
        ->assertExitCode(0);

    expect($this->steps)->toBe(['open', 'dump'])
        ->and($this->cluster['is_public'])->toBeTrue();
});

it('still offers to close the endpoint when the sync fails after opening it', function () {
    $this->command->dumpShouldFail = true;

    $this->artisan('db:refresh-from-prod', ['--skip-local-backup' => true])
        ->expectsConfirmation(OPEN_QUESTION, 'yes')
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsConfirmation(CLOSE_QUESTION, 'yes')
        ->assertExitCode(1);

    expect($this->steps)->toBe(['open', 'dump', 'close']);
});

it('stops before dumping and offers to close when the opened endpoint never accepts a connection', function () {
    $this->command->endpointBecomesReady = false;

    $this->artisan('db:refresh-from-prod', ['--skip-local-backup' => true])
        ->expectsConfirmation(OPEN_QUESTION, 'yes')
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsOutputToContain('did not start accepting connections in time')
        ->expectsConfirmation(CLOSE_QUESTION, 'yes')
        ->assertExitCode(1);

    expect($this->steps)->toBe(['open', 'close']);
});

it('tries each token the cloud CLI has stored until one can see the cluster', function () {
    $tokenFile = tempnam(sys_get_temp_dir(), 'tok');
    file_put_contents($tokenFile, json_encode(['api_tokens' => ['other-org', 'right-org']]));
    config()->set('db-sync-from-prod.cloud_endpoint.token', null);
    config()->set('db-sync-from-prod.cloud_endpoint.token_file', $tokenFile);

    $cluster = $this->api;
    $this->api = fn (Request $request) => $request->hasHeader('Authorization', 'Bearer right-org')
        ? $cluster($request)
        : Http::response(['message' => 'Unauthenticated.'], 401);

    $this->artisan('db:refresh-from-prod', ['--skip-local-backup' => true])
        ->expectsConfirmation(OPEN_QUESTION, 'yes')
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsConfirmation(CLOSE_QUESTION, 'yes')
        ->assertExitCode(0);

    expect($this->steps)->toBe(['open', 'dump', 'close']);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer other-org'));
    Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH'
        && ! $request->hasHeader('Authorization', 'Bearer right-org'));

    unlink($tokenFile);
});

it('does not open the endpoint when the API rejects the change', function () {
    $cluster = $this->api;
    $this->api = fn (Request $request) => $request->method() === 'PATCH'
        ? Http::response(['message' => 'Cluster is busy.'], 422)
        : $cluster($request);

    $this->artisan('db:refresh-from-prod', ['--skip-local-backup' => true])
        ->expectsConfirmation(OPEN_QUESTION, 'yes')
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->expectsOutputToContain('Could not open the public endpoint: Could not update the cluster: HTTP 422 Cluster is busy.')
        ->doesntExpectOutputToContain('Close the production database')
        ->assertExitCode(1);

    expect($this->steps)->toBe([]);
});

it('leaves the endpoint alone when no cluster is configured', function () {
    config()->set('db-sync-from-prod.cloud_endpoint.cluster_id', null);

    $this->artisan('db:refresh-from-prod', ['--skip-local-backup' => true])
        ->expectsConfirmation('Are you sure you want to continue?', 'yes')
        ->assertExitCode(0);

    Http::assertNothingSent();
    expect($this->steps)->toBe(['dump']);
});
