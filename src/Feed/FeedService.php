<?php

declare(strict_types=1);

namespace Rezos\Feed;

use Rezos\Api\GraphException;
use Rezos\Api\PostProviderInterface;
use Rezos\Cache\FeedCache;
use Rezos\Model\Account;
use Rezos\Model\Post;
use Rezos\Model\Profile;
use Rezos\Settings;

/**
 * Agrège les sources et les Pages demandées, trie par date et gère le cache.
 */
final class FeedService
{
    /** Nombre de posts récupérés par source et par Page (on filtre ensuite selon `limit`). */
    private const FETCH_SIZE = 30;

    /** Écart maximal entre deux publications de même texte (rediffusion ou republication). */
    private const CROSSPOST_WINDOW = 86400;

    /** Écart maximal quand l'une des deux n'a pas de texte (Meta en perd souvent à la rediffusion). */
    private const CROSSPOST_UNTEXTED_WINDOW = 900;

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

        // Toutes les sources sont lues, même celles qui ne sont pas affichées : une publication
        // Facebook qui rediffuse celle du compte Instagram lié doit disparaître même si le flux
        // ne montre que Facebook (et inversement). Les lectures sont en cache.
        foreach ($this->accounts($accountIds) as $account) {
            foreach ($this->availableSources() as $source) {
                $posts = [...$posts, ...$this->postsFor($source, $account)];
            }
        }

        $wanted = array_unique($sources);
        $posts = array_filter(
            self::withoutDuplicates($posts),
            static fn (Post $p): bool => in_array($p->source, $wanted, true),
        );

        if ($type !== null && $type !== '') {
            $posts = array_filter($posts, static fn (Post $p): bool => $p->mediaType === $type);
        }

        usort($posts, static fn (Post $a, Post $b): int => $b->createdAt <=> $a->createdAt);

        return array_slice(array_values($posts), 0, max(1, $limit));
    }

    /**
     * Retire les rediffusions Facebook ↔ Instagram d'une même Page. L'API ne les signale pas ;
     * deux publications de sources différentes sont considérées comme l'originale et sa rediffusion si :
     *  - leurs textes sont identiques une fois réduits aux lettres et chiffres (Meta retire emojis
     *    et sauts de ligne au passage), à moins de CROSSPOST_WINDOW d'intervalle ;
     *  - ou l'une n'a pas de texte, à moins de CROSSPOST_UNTEXTED_WINDOW d'intervalle.
     * La plus ancienne (l'originale) est gardée.
     *
     * Retire aussi les republications sur une même source (même Page, même texte, à moins de
     * CROSSPOST_WINDOW) : on garde alors la plus récente, souvent publiée pour corriger la première.
     *
     * @param list<Post> $posts
     * @return list<Post>
     */
    private static function withoutDuplicates(array $posts): array
    {
        // Du plus ancien au plus récent ; à égalité, Instagram d'abord (partage vers Facebook le plus courant).
        usort($posts, static fn (Post $a, Post $b): int => [$a->createdAt, $a->source !== 'instagram']
            <=> [$b->createdAt, $b->source !== 'instagram']);

        $kept = [];
        foreach ($posts as $post) {
            foreach ($kept as $i => $original) {
                if (self::isCrossPost($original, $post)) {
                    continue 2;
                }
                if (self::isRepost($original, $post)) {
                    $kept[$i] = $post;
                    continue 2;
                }
            }
            $kept[] = $post;
        }

        return array_values($kept);
    }

    /** $post (le plus récent) republie-t-il $original sur la même source ? Seulement s'ils ont du texte. */
    private static function isRepost(Post $original, Post $post): bool
    {
        if ($original->source !== $post->source || $original->accountId !== $post->accountId) {
            return false;
        }
        $text = self::fingerprint($post->text);

        return $text !== ''
            && $text === self::fingerprint($original->text)
            && $post->createdAt->getTimestamp() - $original->createdAt->getTimestamp() <= self::CROSSPOST_WINDOW;
    }

    /** $post (le plus récent) est-il la rediffusion de $original ? */
    private static function isCrossPost(Post $original, Post $post): bool
    {
        if ($original->source === $post->source || $original->accountId !== $post->accountId) {
            return false;
        }

        $gap = $post->createdAt->getTimestamp() - $original->createdAt->getTimestamp();
        $a = self::fingerprint($original->text);
        $b = self::fingerprint($post->text);

        if ($a === '' || $b === '') {
            return $gap <= self::CROSSPOST_UNTEXTED_WINDOW;
        }

        return $a === $b && $gap <= self::CROSSPOST_WINDOW;
    }

    /** Texte réduit à ses lettres et chiffres, en minuscules. */
    private static function fingerprint(string $text): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? '');
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

    /**
     * Profils (lien, abonnés) de chaque source des Pages demandées, pour la barre « Nous suivre ».
     * Facebook d'abord, puis Instagram, Page par Page. En cache comme les flux.
     *
     * @param list<string>|null $accountIds
     * @return list<Profile>
     */
    public function profiles(?array $accountIds = null): array
    {
        $profiles = [];
        foreach ($this->accounts($accountIds) as $account) {
            foreach ($this->providers as $source => $provider) {
                if (!$provider->supports($account)) {
                    continue;
                }
                $data = $this->cached('profile_' . $source . '_' . $account->id, $source, $account,
                    static fn (): array => $provider->profile($account)->toArray());
                if ($data !== null) {
                    try {
                        $profiles[] = Profile::fromArray($data);
                    } catch (\Throwable) {
                        // Entrée de cache illisible : la barre se passe de ce profil.
                    }
                }
            }
        }

        return $profiles;
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
            $this->logError($source, $account, $e);

            // Mieux vaut un flux un peu ancien qu'un bloc vide.
            return $this->cache->getStale($key) ?? [];
        }
    }

    /**
     * Lecture en cache de données brutes, avec la dernière copie valide en secours.
     *
     * @param callable(): array<mixed> $fetch
     * @return array<mixed>|null
     */
    private function cached(string $key, string $source, Account $account, callable $fetch): ?array
    {
        $cached = $this->cache->getData($key);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $data = $fetch();
            $this->cache->setData($key, $data);

            return $data;
        } catch (GraphException $e) {
            $this->logError($source, $account, $e);

            return $this->cache->getData($key, stale: true);
        }
    }

    private function logError(string $source, Account $account, GraphException $e): void
    {
        $label = sprintf('%s (%s)', $source, $account->name !== '' ? $account->name : $account->id);
        $this->lastErrors[] = $label . ' : ' . $e->getMessage();
        if (function_exists('error_log')) {
            error_log('[rezos] ' . $label . ' : ' . $e->getMessage());
        }
    }
}
