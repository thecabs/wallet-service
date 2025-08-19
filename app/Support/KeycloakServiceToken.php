<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KeycloakServiceToken
{
    public static function get(int $ttl = 250): ?string
    {
        return Cache::remember('wallet_s2s_token', $ttl, function () {
            $resp = Http::asForm()
                ->retry(2, 500)
                ->post(config('services.keycloak.token_url'), [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => config('services.keycloak.client_id'),
                    'client_secret' => config('services.keycloak.client_secret'),
                ]);

            if (!$resp->ok()) {
                Log::error('S2S token error', ['status' => $resp->status(), 'body' => $resp->body()]);
                return null;
            }
            return $resp->json('access_token');
        });
    }
}
