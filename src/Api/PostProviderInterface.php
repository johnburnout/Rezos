<?php

declare(strict_types=1);

namespace Rezos\Api;

use Rezos\Model\Post;

interface PostProviderInterface
{
    public function isAvailable(): bool;

    /**
     * @return list<Post>
     *
     * @throws GraphException
     */
    public function fetch(int $limit): array;
}
