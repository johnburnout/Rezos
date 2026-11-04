<?php

declare(strict_types=1);

namespace Rezos\Api;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client minimal pour l'API Graph de Meta (Facebook + Instagram).
 */
final class GraphClient
{
    private const BASE_URL = 'https://graph.facebook.com/';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $version,
    ) {
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     *
     * @throws GraphException
     */
    public function get(string $path, array $query, string $accessToken, ?string $appSecret = null): array
    {
        $query['access_token'] = $accessToken;
        if ($appSecret !== null && $appSecret !== '') {
            // Recommandé par Meta : prouve que l'appel vient du serveur détenteur du secret.
            $query['appsecret_proof'] = hash_hmac('sha256', $accessToken, $appSecret);
        }

        return $this->request($this->version . '/' . ltrim($path, '/'), $query);
    }

    /**
     * Échange un token utilisateur court (1 h) contre un token long (~60 j).
     *
     * @throws GraphException
     */
    public function exchangeForLongLivedUserToken(string $appId, string $appSecret, string $shortToken): string
    {
        $data = $this->request($this->version . '/oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $appId,
            'client_secret'     => $appSecret,
            'fb_exchange_token' => $shortToken,
        ]);

        return (string) ($data['access_token'] ?? throw new GraphException('Réponse sans access_token.'));
    }

    /**
     * Liste les Pages gérées par l'utilisateur, avec leur token de Page
     * (sans expiration lorsqu'il provient d'un token utilisateur long)
     * et le compte Instagram professionnel lié.
     *
     * @return list<array{id: string, name: string, access_token: string, ig_id: ?string, ig_username: ?string}>
     *
     * @throws GraphException
     */
    public function listPages(string $userToken, string $appSecret): array
    {
        $pages = [];
        $query = [
            'fields' => 'id,name,access_token,instagram_business_account{id,username}',
            'limit'  => 100,
        ];

        // Pagination par curseur : on suit « after » tant que l'API annonce une page suivante.
        for ($i = 0; $i < 20; $i++) {
            $data = $this->get('me/accounts', $query, $userToken, $appSecret);

            foreach ($data['data'] ?? [] as $page) {
                $pages[] = [
                    'id'           => (string) $page['id'],
                    'name'         => (string) ($page['name'] ?? ''),
                    'access_token' => (string) ($page['access_token'] ?? ''),
                    'ig_id'        => isset($page['instagram_business_account']['id']) ? (string) $page['instagram_business_account']['id'] : null,
                    'ig_username'  => $page['instagram_business_account']['username'] ?? null,
                ];
            }

            $after = $data['paging']['cursors']['after'] ?? null;
            if (empty($data['paging']['next']) || !is_string($after) || $after === '') {
                break;
            }
            $query['after'] = $after;
        }

        return $pages;
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     *
     * @throws GraphException
     */
    private function request(string $path, array $query): array
    {
        try {
            $response = $this->http->request('GET', self::BASE_URL . $path, ['query' => $query]);
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new GraphException('Erreur réseau : ' . $e->getMessage(), 0, $e);
        }

        if (isset($data['error'])) {
            throw GraphException::fromPayload($data['error']);
        }

        return $data;
    }
}
