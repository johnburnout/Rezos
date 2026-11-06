<?php

declare(strict_types=1);

namespace Rezos\Api;

use Rezos\Model\Account;
use Rezos\Model\Post;

/**
 * Médias du compte Instagram professionnel lié à la Page : GET /{ig-user-id}/media
 * Permissions : instagram_basic, pages_show_list (+ pages_read_engagement).
 */
final class InstagramProvider implements PostProviderInterface
{
    public function __construct(private readonly GraphClient $graph)
    {
    }

    public function supports(Account $account): bool
    {
        return $account->hasInstagram();
    }

    public function fetch(Account $account, int $limit): array
    {
        $items = $this->graph->list(
            $account->igId . '/media',
            'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count',
            $limit,
            $account->token,
        );

        return array_map(fn (array $item): Post => $this->map($item, $account), $items);
    }

    /** @param array<string, mixed> $item */
    private function map(array $item, Account $account): Post
    {
        $type = (string) ($item['media_type'] ?? 'IMAGE');

        return new Post(
            source: 'instagram',
            id: (string) $item['id'],
            text: (string) ($item['caption'] ?? ''),
            // Pour une vidéo, media_url pointe vers le MP4 : on affiche la vignette.
            imageUrl: $type === 'VIDEO' ? ($item['thumbnail_url'] ?? null) : ($item['media_url'] ?? null),
            mediaType: match ($type) {
                'VIDEO'          => 'video',
                'CAROUSEL_ALBUM' => 'album',
                default          => 'image',
            },
            permalink: (string) ($item['permalink'] ?? ''),
            createdAt: new \DateTimeImmutable((string) $item['timestamp']),
            likes: isset($item['like_count']) ? (int) $item['like_count'] : null,
            comments: isset($item['comments_count']) ? (int) $item['comments_count'] : null,
            accountId: $account->id,
            accountName: '@' . $account->igUsername,
        );
    }
}
