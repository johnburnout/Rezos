<?php

declare(strict_types=1);

namespace Rezos\Model;

/**
 * Publication normalisée, quelle que soit sa source.
 */
final class Post
{
    public function __construct(
        public readonly string $source,          // 'facebook' | 'instagram'
        public readonly string $id,
        public readonly string $text,
        public readonly ?string $imageUrl,
        public readonly string $mediaType,       // 'image' | 'video' | 'album' | 'text'
        public readonly string $permalink,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?int $likes = null,
        public readonly ?int $comments = null,
        public readonly string $accountId = '',     // ID de la Page connectée
        public readonly string $accountName = '',   // nom de la Page ou @compte Instagram
    ) {
    }

    /** Extrait court pour l'affichage (le texte complet reste disponible). */
    public function excerpt(int $length = 140): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $this->text) ?? '');

        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length)) . '…' : $text;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source'    => $this->source,
            'id'        => $this->id,
            'text'      => $this->text,
            'imageUrl'  => $this->imageUrl,
            'mediaType' => $this->mediaType,
            'permalink' => $this->permalink,
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
            'likes'     => $this->likes,
            'comments'  => $this->comments,
            'accountId'   => $this->accountId,
            'accountName' => $this->accountName,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['source'],
            (string) $data['id'],
            (string) $data['text'],
            $data['imageUrl'] ?? null,
            (string) $data['mediaType'],
            (string) $data['permalink'],
            new \DateTimeImmutable((string) $data['createdAt']),
            $data['likes'] ?? null,
            $data['comments'] ?? null,
            (string) ($data['accountId'] ?? ''),
            (string) ($data['accountName'] ?? ''),
        );
    }
}
