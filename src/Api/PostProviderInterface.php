<?php

declare(strict_types=1);

namespace Rezos\Api;

use Rezos\Model\Account;
use Rezos\Model\Post;
use Rezos\Model\Profile;

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

    /**
     * Profil public (lien et nombre d'abonnés) pour la barre « Nous suivre ».
     *
     * @throws GraphException
     */
    public function profile(Account $account): Profile;
}
