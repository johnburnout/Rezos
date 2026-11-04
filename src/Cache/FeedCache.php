<?php

declare(strict_types=1);

namespace Rezos\Cache;

use Rezos\Model\Post;

/**
 * Cache des flux via les transients WordPress (compatible object cache).
 * Conserve aussi une copie « de secours » sans expiration, servie si l'API tombe.
 */
final class FeedCache
{
    private const PREFIX = 'rezos_feed_';
    private const STALE_PREFIX = 'rezos_stale_';

    public function __construct(private readonly int $ttl)
    {
    }

    /** @return list<Post>|null */
    public function get(string $key): ?array
    {
        return $this->decode(get_transient(self::PREFIX . $key));
    }

    /** @return list<Post>|null */
    public function getStale(string $key): ?array
    {
        return $this->decode(get_option(self::STALE_PREFIX . $key, false));
    }

    /** @param list<Post> $posts */
    public function set(string $key, array $posts): void
    {
        $payload = array_map(static fn (Post $p): array => $p->toArray(), $posts);
        set_transient(self::PREFIX . $key, $payload, max(60, $this->ttl));
        update_option(self::STALE_PREFIX . $key, $payload, false);
    }

    /** Supprime une entrée de cache et sa copie de secours. */
    public static function forget(string $key): void
    {
        delete_transient(self::PREFIX . $key);
        delete_option(self::STALE_PREFIX . $key);
    }

    public static function flushAll(): void
    {
        global $wpdb;

        $like = static fn (string $p): string => $wpdb->esc_like($p) . '%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $like('_transient_' . self::PREFIX),
            $like('_transient_timeout_' . self::PREFIX),
            $like(self::STALE_PREFIX),
        ));
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('transient');
        }
    }

    /** @return list<Post>|null */
    private function decode(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        try {
            return array_values(array_map(static fn (array $d): Post => Post::fromArray($d), $raw));
        } catch (\Throwable) {
            return null;
        }
    }
}
