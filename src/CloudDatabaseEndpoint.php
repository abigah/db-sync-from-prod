<?php

namespace Abigah\DbSyncFromProd;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The public endpoint of a Laravel Cloud database cluster: the only way into a
 * Cloud database from a developer's machine, so best left closed between syncs.
 *
 * The `cloud` CLI cannot toggle it (every db-cluster command asks the API for
 * an include it now rejects), so this calls the REST API directly.
 */
class CloudDatabaseEndpoint
{
    private ?string $token = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $clusterConfig = null;

    /**
     * @param  list<string>  $tokens  Candidate API tokens, most specific first.
     */
    public function __construct(
        private string $apiUrl,
        private string $clusterId,
        private array $tokens,
    ) {}

    /**
     * @param  array{api_url?: ?string, cluster_id?: ?string, token?: ?string, token_file?: ?string}  $config
     */
    public static function fromConfig(array $config): ?self
    {
        $clusterId = (string) ($config['cluster_id'] ?? '');

        if ($clusterId === '') {
            return null;
        }

        return new self(
            (string) ($config['api_url'] ?? 'https://cloud.laravel.com/api'),
            $clusterId,
            self::candidateTokens($config),
        );
    }

    /**
     * @throws RuntimeException when the cluster cannot be read.
     */
    public function isPublic(): bool
    {
        return (bool) ($this->fetchConfig()['is_public'] ?? false);
    }

    /**
     * @throws RuntimeException when the cluster cannot be read or updated.
     */
    public function setPublic(bool $public): void
    {
        $config = $this->fetchConfig();

        if ((bool) ($config['is_public'] ?? false) === $public) {
            return;
        }

        // The API takes the config block as the cluster's whole configuration
        // rather than a patch, so sending is_public alone would wipe the rest.
        $response = Http::withToken((string) $this->token)
            ->acceptJson()
            ->patch($this->url(), ['config' => ['is_public' => $public] + $config]);

        if (! $response->successful()) {
            throw new RuntimeException('Could not update the cluster: HTTP '.$response->status().$this->reason($response));
        }

        $this->clusterConfig = ['is_public' => $public] + $config;
    }

    /**
     * Read the cluster's config, trying each token until one is accepted.
     *
     * @return array<string, mixed>
     */
    private function fetchConfig(): array
    {
        if ($this->token !== null) {
            return $this->clusterConfig = $this->read($this->token) ?? [];
        }

        if ($this->tokens === []) {
            throw new RuntimeException('No Laravel Cloud API token found. Set LARAVEL_CLOUD_TOKEN, or authenticate the CLI with `cloud auth`.');
        }

        foreach ($this->tokens as $token) {
            $config = $this->read($token);

            if ($config !== null) {
                $this->token = $token;

                return $this->clusterConfig = $config;
            }
        }

        throw new RuntimeException("No Laravel Cloud API token has access to database cluster [{$this->clusterId}].");
    }

    /**
     * @return array<string, mixed>|null Null when the token cannot see the cluster.
     */
    private function read(string $token): ?array
    {
        $response = Http::withToken($token)->acceptJson()->get($this->url());

        if ($response->successful()) {
            return (array) $response->json('data.attributes.config', []);
        }

        // A token belonging to another organisation is rejected rather than
        // being wrong about the cluster, so the next one may still work.
        if (in_array($response->status(), [401, 403, 404], true)) {
            return null;
        }

        throw new RuntimeException('Could not read the cluster: HTTP '.$response->status().$this->reason($response));
    }

    /**
     * The configured token, or else every token the `cloud` CLI has stored.
     *
     * @param  array{token?: ?string, token_file?: ?string}  $config
     * @return list<string>
     */
    private static function candidateTokens(array $config): array
    {
        $token = (string) ($config['token'] ?? '');

        if ($token !== '') {
            return [$token];
        }

        $file = (string) ($config['token_file'] ?? '');

        if ($file === '' || ! is_file($file)) {
            return [];
        }

        $stored = json_decode((string) file_get_contents($file), true);

        if (! is_array($stored) || ! is_array($stored['api_tokens'] ?? null)) {
            return [];
        }

        return array_values(array_filter($stored['api_tokens'], 'is_string'));
    }

    private function url(): string
    {
        return rtrim($this->apiUrl, '/')."/databases/clusters/{$this->clusterId}";
    }

    private function reason(Response $response): string
    {
        $message = (string) ($response->json('message') ?? '');

        return $message === '' ? '' : " {$message}";
    }
}
