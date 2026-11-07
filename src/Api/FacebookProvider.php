<?php

declare(strict_types=1);

namespace Rezos\Api;

use Rezos\Model\Account;
use Rezos\Model\Post;
use Rezos\Model\Profile;

/**
 * Publications d'une Page Facebook : GET /{page-id}/posts
 * Permissions : pages_show_list, pages_read_engagement.
 */
final class FacebookProvider implements PostProviderInterface
{
    public function __construct(private readonly GraphClient $graph)
    {
    }

    public function supports(Account $account): bool
    {
        return true;
    }

    public function fetch(Account $account, int $limit): array
    {
        $items = $this->graph->list(
            $account->id . '/posts',
            'id,message,story,created_time,permalink_url,full_picture,'
                . 'attachments{media_type,type},'
                . 'reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0)',
            $limit,
            $account->token,
        );

        return array_map(fn (array $item): Post => $this->map($item, $account), $items);
    }

    public function profile(Account $account): Profile
    {
        $data = $this->graph->get($account->id, ['fields' => 'name,link,followers_count,fan_count'], $account->token);
        // followers_count : abonnés de la Page ; fan_count (« J'aime ») pour les anciennes Pages.
        $followers = $data['followers_count'] ?? $data['fan_count'] ?? null;

        return new Profile(
            source: 'facebook',
            accountId: $account->id,
            name: (string) ($data['name'] ?? $account->name),
            url: (string) ($data['link'] ?? 'https://www.facebook.com/' . $account->id),
            followers: $followers !== null ? (int) $followers : null,
        );
    }

    /** @param array<string, mixed> $item */
    private function map(array $item, Account $account): Post
    {
        $attachmentType = strtolower((string) ($item['attachments']['data'][0]['media_type'] ?? ''));
        $mediaType = match (true) {
            $attachmentType === 'video'                 => 'video',
            $attachmentType === 'album'                 => 'album',
            !empty($item['full_picture'])               => 'image',
            default                                     => 'text',
        };

        return new Post(
            source: 'facebook',
            id: (string) $item['id'],
            text: (string) ($item['message'] ?? $item['story'] ?? ''),
            imageUrl: $item['full_picture'] ?? null,
            mediaType: $mediaType,
            permalink: (string) ($item['permalink_url'] ?? 'https://www.facebook.com/' . $item['id']),
            createdAt: new \DateTimeImmutable((string) $item['created_time']),
            likes: isset($item['reactions']['summary']['total_count']) ? (int) $item['reactions']['summary']['total_count'] : null,
            comments: isset($item['comments']['summary']['total_count']) ? (int) $item['comments']['summary']['total_count'] : null,
            accountId: $account->id,
            accountName: $account->name,
        );
    }
}
