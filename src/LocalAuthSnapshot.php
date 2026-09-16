<?php

namespace Abigah\DbSyncFromProd;

use Illuminate\Database\Connection;

/**
 * The passkeys and two-factor settings local users have set up, captured before
 * the local database is replaced and put back onto the matching production
 * users afterwards.
 *
 * Passkeys are bound to the domain they were registered on, so one made on the
 * local site never exists in production; two-factor secrets are encrypted with
 * the app key, which usually differs between the two. Without this, every sync
 * would lock the developer out of their own local account.
 */
class LocalAuthSnapshot
{
    /**
     * @param  array{users_table: string, match_column: string, passkeys_table: string, two_factor_columns: list<string>}  $config
     * @param  list<array<string, mixed>>  $passkeys  Each carries the owner's match value under "_owner".
     * @param  list<array<string, mixed>>  $twoFactor  Each carries the owner's match value under "_owner".
     */
    private function __construct(
        private array $config,
        private array $passkeys,
        private array $twoFactor,
    ) {}

    /**
     * @param  array{users_table: string, match_column: string, passkeys_table: string, two_factor_columns: list<string>}  $config
     */
    public static function capture(Connection $connection, array $config): self
    {
        $schema = $connection->getSchemaBuilder();
        $users = $config['users_table'];
        $match = $config['match_column'];

        if (! $schema->hasTable($users) || ! $schema->hasColumn($users, $match)) {
            return new self($config, [], []);
        }

        $passkeys = [];

        if ($schema->hasTable($config['passkeys_table'])) {
            $passkeys = $connection->table($config['passkeys_table'].' as passkeys')
                ->join($users.' as users', 'users.id', '=', 'passkeys.user_id')
                ->get(['passkeys.*', "users.{$match} as _owner"])
                ->map(fn (object $row): array => (array) $row)
                ->all();
        }

        $twoFactorColumns = array_values(array_filter(
            $config['two_factor_columns'],
            fn (string $column): bool => $schema->hasColumn($users, $column),
        ));

        $twoFactor = [];

        // Only users who have two-factor set up locally: restoring everyone's
        // local state would hide two-factor that others enable in production.
        if ($twoFactorColumns !== []) {
            $twoFactor = $connection->table($users)
                ->whereNotNull($twoFactorColumns[0])
                ->get([...$twoFactorColumns, "{$match} as _owner"])
                ->map(fn (object $row): array => (array) $row)
                ->all();
        }

        return new self($config, $passkeys, $twoFactor);
    }

    public function isEmpty(): bool
    {
        return $this->passkeys === [] && $this->twoFactor === [];
    }

    /**
     * Put the captured settings back onto the users that match them.
     *
     * @return array{passkeys: int, two_factor: int, warnings: list<string>}
     */
    public function restore(Connection $connection): array
    {
        $result = ['passkeys' => 0, 'two_factor' => 0, 'warnings' => []];

        if ($this->isEmpty()) {
            return $result;
        }

        $schema = $connection->getSchemaBuilder();
        $users = $this->config['users_table'];
        $match = $this->config['match_column'];

        if (! $schema->hasTable($users)) {
            $result['warnings'][] = "Nothing restored: the {$users} table does not exist.";

            return $result;
        }

        $restorePasskeys = $schema->hasTable($this->config['passkeys_table']);

        foreach ($this->passkeys as $passkey) {
            $owner = $passkey['_owner'];
            $userId = $connection->table($users)->where($match, $owner)->value('id');
            $name = $passkey['name'] ?? "#{$passkey['id']}";

            if (! $restorePasskeys) {
                $result['warnings'][] = "Skipped passkey \"{$name}\": the {$this->config['passkeys_table']} table does not exist.";

                continue;
            }

            // Passkeys registered in production come back with the import.
            if (isset($passkey['credential_id'])
                && $connection->table($this->config['passkeys_table'])->where('credential_id', $passkey['credential_id'])->exists()) {
                continue;
            }

            if ($userId === null) {
                $result['warnings'][] = "Skipped passkey \"{$name}\": no user {$owner}.";

                continue;
            }

            // Libraries such as laravel/passkeys derive the WebAuthn user handle
            // from the user's id, so a passkey moved to a new id may not sign in.
            if ((string) $userId !== (string) $passkey['user_id']) {
                $result['warnings'][] = "Passkey \"{$name}\" moved from user #{$passkey['user_id']} to #{$userId} ({$owner}); it may need registering again.";
            }

            $row = $passkey;
            unset($row['_owner'], $row['id']);
            $row['user_id'] = $userId;

            $connection->table($this->config['passkeys_table'])->insert($row);
            $result['passkeys']++;
        }

        foreach ($this->twoFactor as $settings) {
            $owner = $settings['_owner'];
            unset($settings['_owner']);

            $updated = $connection->table($users)->where($match, $owner)->update($settings);

            if ($updated === 0 && ! $connection->table($users)->where($match, $owner)->exists()) {
                $result['warnings'][] = "Skipped two-factor settings: no user {$owner}.";

                continue;
            }

            $result['two_factor']++;
        }

        return $result;
    }
}
