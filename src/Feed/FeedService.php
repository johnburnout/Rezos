<?php

declare(strict_types=1);

namespace Rezos\Feed;

use Rezos\Api\GraphException;
use Rezos\Api\PostProviderInterface;
use Rezos\Cache\FeedCache;
use Rezos\Model\Account;
use Rezos\Model\Post;
use Rezos\Settings;

/**
 * Agrège les sources et les Pages demandées, trie par date et gère le cache.
 */
final class FeedService
{
    /** Nombre de posts récupérés par source et par Page (on filtre ensuite selon `limit`). */
    private const FETCH_SIZE = 30;

    /** @var list<string> */
    private array $lastErrors = [];

    /**
     * @param array<string, PostProviderInterface> $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly FeedCache $cache,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @param list<string>      $sources    ex. ['facebook', 'instagram']
     * @param list<string>|null $accountIds IDs de Pages ; null = toutes les Pages connectées
     * @return list<Post>
     */
    public function getPosts(array $sources, int $limit, ?string $type = null, ?array $accountIds = null): array
    {
        $this->lastErrors = [];
        $posts = [];

        foreach ($this->accounts($accountIds) as $account) {
            foreach (array_unique($sources) as $source) {
                $posts = [...$posts, ...$this->postsFor($source, $account)];
            }
        }

        if ($type !== null && $type !== '') {
            $posts = array_filter($posts, static fn (Post $p): bool => $p->mediaType === $type);
        }

        usort($posts, static fn (Post $a, Post $b): int => $b->createdAt <=> $a->createdAt);

        return array_slice(array_values($posts), 0, max(1, $limit));
    }

    /** @return list<string> */
    public function lastErrors(): array
    {
        return $this->lastErrors;
    }

    /** @return list<string> */
    public function availableSources(): array
    {
        return array_keys($this->providers);
    }

    public function supports(string $source, Account $account): bool
    {
        return isset($this->providers[$source]) && $this->providers[$source]->supports($account);
    }

    /**
     * @param list<string>|null $ids
     * @return list<Account>
     */
    private function accounts(?array $ids): array
    {
        $accounts = $this->settings->accounts();
        if ($ids === null) {
            return $accounts;
        }

        return array_values(array_filter($accounts, static fn (Account $a): bool => in_array($a->id, $ids, true)));
    }

    /** @return list<Post> */
    private function postsFor(string $source, Account $account): array
    {
        $provider = $this->providers[$source] ?? null;
        if ($provider === null || !$provider->supports($account)) {
            return [];
        }

        $key = $source . '_' . $account->id;
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $posts = $provider->fetch($account, self::FETCH_SIZE);
            $this->cache->set($key, $posts);

            return $posts;
        } catch (GraphException $e) {
            $label = sprintf('%s (%s)', $source, $account->name !== '' ? $account->name : $account->id);
            $this->lastErrors[] = $label . ' : ' . $e->getMessage();
            if (function_exists('error_log')) {
                error_log('[rezos] ' . $label . ' : ' . $e->getMessage());
            }

            // Mieux vaut un flux un peu ancien qu'un bloc vide.
            return $this->cache->getStale($key) ?? [];
        }
    }
}
