<?php

declare(strict_types=1);

namespace Rezos;

use Rezos\Model\Account;

/**
 * Accès typé aux options du plugin (stockées dans une seule option WP).
 */
final class Settings
{
    public const OPTION = 'rezos_settings';

    private const DEFAULTS = [
        'app_id'             => '',
        'app_secret'         => '',
        'graph_version'      => 'v25.0',
        // Pages connectées : list<array{id, name, token, ig_id, ig_username}>
        'accounts'           => [],
        'cache_ttl'          => 3600,
        'default_limit'      => 9,
    ];

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->values === null) {
            $stored = get_option(self::OPTION, []);
            $stored = is_array($stored) ? self::migrate($stored) : [];
            $this->values = array_merge(self::DEFAULTS, $stored);
        }

        return $this->values;
    }

    /** @param array<string, mixed> $values */
    public function update(array $values): void
    {
        $merged = array_merge($this->all(), array_intersect_key($values, self::DEFAULTS));
        update_option(self::OPTION, $merged, false);
        $this->values = $merged;
    }

    public function isConnected(): bool
    {
        return $this->accounts() !== [];
    }

    /** @return list<Account> */
    public function accounts(): array
    {
        $accounts = [];
        foreach ((array) $this->get('accounts') as $data) {
            if (is_array($data)) {
                $account = Account::fromArray($data);
                if ($account->id !== '' && $account->token !== '') {
                    $accounts[] = $account;
                }
            }
        }

        return $accounts;
    }

    public function account(string $id): ?Account
    {
        foreach ($this->accounts() as $account) {
            if ($account->id === $id) {
                return $account;
            }
        }

        return null;
    }

    /** @param list<Account> $accounts */
    public function saveAccounts(array $accounts): void
    {
        $unique = [];
        foreach ($accounts as $account) {
            $unique[$account->id] = $account->toArray();
        }
        $this->update(['accounts' => array_values($unique)]);
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * Version 0.1 : une seule Page (page_id, page_token…) → liste « accounts ».
     *
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private static function migrate(array $stored): array
    {
        if (!isset($stored['accounts']) && !empty($stored['page_id']) && !empty($stored['page_token'])) {
            $stored['accounts'] = [[
                'id'          => (string) $stored['page_id'],
                'name'        => (string) ($stored['page_name'] ?? ''),
                'token'       => (string) $stored['page_token'],
                'ig_id'       => (string) ($stored['ig_user_id'] ?? ''),
                'ig_username' => (string) ($stored['ig_username'] ?? ''),
            ]];
        }

        // Les anciennes clés disparaîtront au prochain enregistrement (update() filtre sur DEFAULTS).
        return array_intersect_key($stored, self::DEFAULTS);
    }
}
