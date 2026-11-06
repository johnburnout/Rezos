<?php

declare(strict_types=1);

namespace Rezos\Model;

/**
 * Une Page Facebook connectée et, le cas échéant, le compte Instagram professionnel lié.
 */
final class Account
{
    public function __construct(
        public readonly string $id,          // ID de la Page Facebook
        public readonly string $name,
        public readonly string $token,       // token de Page (sans expiration)
        public readonly string $igId = '',
        public readonly string $igUsername = '',
    ) {
    }

    public function hasInstagram(): bool
    {
        return $this->igId !== '';
    }

    /** @return array{id: string, name: string, token: string, ig_id: string, ig_username: string} */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'token'       => $this->token,
            'ig_id'       => $this->igId,
            'ig_username' => $this->igUsername,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['name'] ?? ''),
            (string) ($data['token'] ?? ''),
            (string) ($data['ig_id'] ?? ''),
            (string) ($data['ig_username'] ?? ''),
        );
    }
}
