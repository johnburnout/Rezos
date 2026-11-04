<?php

declare(strict_types=1);

namespace Rezos\Api;

final class GraphException extends \RuntimeException
{
    /** @param array<string, mixed> $error */
    public static function fromPayload(array $error): self
    {
        $message = sprintf(
            '[%s %s] %s',
            $error['type'] ?? 'GraphError',
            $error['code'] ?? '?',
            $error['message'] ?? 'Erreur inconnue',
        );

        return new self($message, (int) ($error['code'] ?? 0));
    }

    /** Code 190 = token invalide ou expiré. */
    public function isAuthError(): bool
    {
        return $this->getCode() === 190;
    }
}
