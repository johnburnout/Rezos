<?php

declare(strict_types=1);

namespace Rezos\Model;

/**
 * Profil public d'une Page Facebook ou d'un compte Instagram : lien « Nous suivre » et abonnés.
 */
final class Profile
{
    public function __construct(
        public readonly string $source,      // facebook | instagram
        public readonly string $accountId,   // ID de la Page Facebook connectée
        public readonly string $name,        // nom de la Page ou @compte
        public readonly string $url,
        public readonly ?int $followers = null,
    ) {
    }

    /** @return array{source: string, accountId: string, name: string, url: string, followers: int|null} */
    public function toArray(): array
    {
        return [
            'source'    => $this->source,
            'accountId' => $this->accountId,
            'name'      => $this->name,
            'url'       => $this->url,
            'followers' => $this->followers,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['source'],
            (string) $data['accountId'],
            (string) ($data['name'] ?? ''),
            (string) $data['url'],
            isset($data['followers']) ? (int) $data['followers'] : null,
        );
    }
}
