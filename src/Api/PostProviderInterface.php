<?php

declare(strict_types=1);

namespace Rezos\Api;

use Rezos\Model\Account;
use Rezos\Model\Post;

interface PostProviderInterface
{
    /** Ce compte a-t-il des publications pour cette source ? */
    public function supports(Account $account): bool;

    /**
     * @return list<Post>
     *
     * @throws GraphException
     */
    public function fetch(Account $account, int $limit): array;
}
