<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

class KeycloakTokenService
{
    private PendingRequest $http;
    private CacheRepository $cache;

    private string $baseUrl;
    private string $realm;
    private string $clientId;
    private ?string $clientSecret;

    public function __construct(HttpFactory $factory, CacheRepository $cache)
    {
        $this->cache = $cache;

        $this->baseUrl      = rtrim((string) config('services.keycloak.base_url'), '/');
        $this->realm        = (string) config('services.keycloak.realm');
        $this->clientId     = (string) config('services.keycloak.client_id');
        $this->clientSecret = config('services.keycloak.client_secret');

        $timeout = (int) config('http.timeout', 20);
        $retries = 2;
        $delayMs = 200;

        $this->http = $factory
            ->timeout($timeout)
            ->retry($retries, $delayMs);
    }

    public function getServiceToken(?string $scope = null): string
    {
        $cacheKey = $this->cacheKey($this->clientId, $scope);
        $cached   = $this->cache->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $tokenUrl = "{$this->baseUrl}/realms/{$this->realm}/protocol/openid-connect/token";
        $form = [
            'grant_type' => 'client_credentials',
            'client_id'  => $this->clientId,
        ];
        if (!empty($this->clientSecret)) {
            $form['client_secret'] = $this->clientSecret;
        }
        if (!empty($scope)) {
            $form['scope'] = $scope;
        }

        $resp = $this->http->asForm()->post($tokenUrl, $form)->throw();
        $accessToken = (string) $resp->json('access_token');
        $expiresIn   = (int) $resp->json('expires_in', 300);

        if ($accessToken === '') {
            throw new RuntimeException('Keycloak: access_token manquant dans la réponse.');
        }

        $ttl = max(60, $expiresIn - 30);
        $this->cache->put($cacheKey, $accessToken, $ttl);

        return $accessToken;
    }

    private function cacheKey(string $clientId, ?string $scope): string
    {
        $s = $scope ?: 'default';
        return "kc:svc_token:{$this->realm}:{$clientId}:{$s}";
    }
}
