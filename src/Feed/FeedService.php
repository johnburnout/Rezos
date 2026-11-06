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

    /** Écart maximal entre une publication et sa rediffusion sur l'autre réseau. */
    private const CROSSPOST_WINDOW = 86400;

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

        $posts = self::withoutCrossPosts($posts);

        if ($type !== null && $type !== '') {
            $posts = array_filter($posts, static fn (Post $p): bool => $p->mediaType === $type);
        }

        usort($posts, static fn (Post $a, Post $b): int => $b->createdAt <=> $a->createdAt);

        return array_slice(array_values($posts), 0, max(1, $limit));
    }

    /**
     * Retire les rediffusions Facebook ↔ Instagram : même Page, même texte, autre source,
     * publiées à moins de CROSSPOST_WINDOW d'intervalle. L'API ne signale pas les rediffusions ;
     * on garde la plus ancienne (l'originale). Les publications sans texte ne sont jamais rapprochées.
     *
     * @param list<Post> $posts
     * @return list<Post>
     */
    private static function withoutCrossPosts(array $posts): array
    {
        // Du plus ancien au plus récent ; à égalité, Instagram d'abord (partage vers Facebook le plus courant).
        usort($posts, static fn (Post $a, Post $b): int => [$a->createdAt, $a->source !== 'instagram']
            <=> [$b->createdAt, $b->source !== 'instagram']);

        $kept = [];
        $originals = []; // empreinte → publications conservées
        foreach ($posts as $post) {
            $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $post->text) ?? ''));
            if ($text === '') {
                $kept[] = $post;
                continue;
            }

            $key = $post->accountId . "\0" . $text;
            foreach ($originals[$key] ?? [] as $original) {
                if ($original->source !== $post->source
                    && $post->createdAt->getTimestamp() - $original->createdAt->getTimestamp() <= self::CROSSPOST_WINDOW) {
                    continue 2;
                }
            }
            $originals[$key][] = $post;
            $kept[] = $post;
        }

        return $kept;
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
