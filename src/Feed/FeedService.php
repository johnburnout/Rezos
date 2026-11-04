<?php

declare(strict_types=1);

namespace Rezos\Feed;

use Rezos\Api\GraphException;
use Rezos\Api\PostProviderInterface;
use Rezos\Cache\FeedCache;
use Rezos\Model\Post;

/**
 * Agrège les sources demandées, trie par date et gère le cache.
 */
final class FeedService
{
    /** Nombre de posts récupérés par source (on filtre ensuite selon `limit`). */
    private const FETCH_SIZE = 30;

    /** @var list<string> */
    private array $lastErrors = [];

    /**
     * @param array<string, PostProviderInterface> $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly FeedCache $cache,
    ) {
    }

    /**
     * @param list<string> $sources
     * @return list<Post>
     */
    public function getPosts(array $sources, int $limit, ?string $type = null): array
    {
        $this->lastErrors = [];
        $posts = [];

        foreach (array_unique($sources) as $source) {
            $posts = [...$posts, ...$this->postsFor($source)];
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

    /** @return list<Post> */
    private function postsFor(string $source): array
    {
        $provider = $this->providers[$source] ?? null;
        if ($provider === null || !$provider->isAvailable()) {
            return [];
        }

        $cached = $this->cache->get($source);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $posts = $provider->fetch(self::FETCH_SIZE);
            $this->cache->set($source, $posts);

            return $posts;
        } catch (GraphException $e) {
            $this->lastErrors[] = $source . ' : ' . $e->getMessage();
            if (function_exists('error_log')) {
                error_log('[rezos] ' . $source . ' : ' . $e->getMessage());
            }

            // Mieux vaut un flux un peu ancien qu'un bloc vide.
            return $this->cache->getStale($source) ?? [];
        }
    }
}
