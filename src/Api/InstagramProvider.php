<?php

declare(strict_types=1);

namespace Rezos\Api;

use Rezos\Model\Post;
use Rezos\Settings;

/**
 * Médias du compte Instagram professionnel lié à la Page : GET /{ig-user-id}/media
 * Permissions : instagram_basic, pages_show_list (+ pages_read_engagement).
 */
final class InstagramProvider implements PostProviderInterface
{
    public function __construct(
        private readonly GraphClient $graph,
        private readonly Settings $settings,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->settings->isConnected() && $this->settings->get('ig_user_id') !== '';
    }

    public function fetch(int $limit): array
    {
        $data = $this->graph->get(
            $this->settings->get('ig_user_id') . '/media',
            [
                'fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count',
                'limit'  => min($limit, 100),
            ],
            (string) $this->settings->get('page_token'),
            (string) $this->settings->get('app_secret'),
        );

        $posts = [];
        foreach ($data['data'] ?? [] as $item) {
            $posts[] = $this->map($item);
        }

        return $posts;
    }

    /** @param array<string, mixed> $item */
    private function map(array $item): Post
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
        );
    }
}
