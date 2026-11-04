<?php

declare(strict_types=1);

namespace Rezos;

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
        'page_id'            => '',
        'page_name'          => '',
        'page_token'         => '',
        'ig_user_id'         => '',
        'ig_username'        => '',
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
            $this->values = array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
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
        return $this->get('page_id') !== '' && $this->get('page_token') !== '';
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }
}
