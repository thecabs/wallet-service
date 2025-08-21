<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class KeycloakTokenService
{
    public function clientCredentials(): string
    {
        $base  = rtrim((string) config('services.keycloak.base_url'), '/');
        $realm = trim((string) config('services.keycloak.realm'));
        $cid   = (string) config('services.keycloak.client_id');
        $sec   = (string) config('services.keycloak.client_secret');

        if ($base === '' || $realm === '') {
            throw new \RuntimeException('Keycloak misconfigured: base_url/realm is empty');
        }
        if ($cid === '' || $sec === '') {
            throw new \RuntimeException('Keycloak misconfigured: client_id/secret is empty');
        }

        $url = $base.'/realms/'.rawurlencode($realm).'/protocol/openid-connect/token';

        $resp = Http::asForm()->timeout(10)->post($url, [
            'grant_type'    => 'client_credentials',
            'client_id'     => $cid,
            'client_secret' => $sec,
        ])->throw();

        $token = $resp->json('access_token');
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Keycloak token missing in response');
        }
        return $token;
    }
}
