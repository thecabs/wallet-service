<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckJWTFromKeycloak
{
    public function handle(Request $request, Closure $next): IlluminateResponse|JsonResponse
    {
        $token = $request->bearerToken();
        if (! $token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // 1) Tentative avec clé publique en .env (RS256)
        if ($rawKey = (config('keycloak.public_key') ?? env('KEYCLOAK_PUBLIC_KEY'))) {
            try {
                $pem     = $this->toPem($rawKey);
                $decoded = JWT::decode($token, new Key($pem, 'RS256'));
                $this->injectClaims($request, $decoded);
                return $next($request);
            } catch (\Throwable $e) {
                Log::warning('JWT decode with env public key failed: '.$e->getMessage());
            }
        }

        // 2) Fallback : JWKS (clé dynamique par kid)
        try {
            $jwksUrl = config('keycloak.jwks_url') ?? env('KEYCLOAK_JWKS_URL');
            if (! $jwksUrl) {
                $realmUrl = config('keycloak.url') ?? env('KEYCLOAK_URL');
                if ($realmUrl) {
                    $jwksUrl = rtrim($realmUrl, '/') . '/protocol/openid-connect/certs';
                }
            }
            if (! $jwksUrl) {
                return response()->json(['error' => 'Invalid token', 'message' => 'No public key or JWKS URL configured'], 401);
            }

            $jwks = Cache::remember('keycloak_jwks', 3600, function () use ($jwksUrl) {
                $resp = Http::get($jwksUrl);
                return $resp->ok() ? $resp->json() : null;
            });
            if (! $jwks || ! isset($jwks['keys'])) {
                return response()->json(['error' => 'Invalid token', 'message' => 'Cannot fetch JWKS'], 401);
            }

            // php-jwt v6 : parseKeySet => array<string, Key>
            $keys = JWK::parseKeySet($jwks);

            // Laisse la lib choisir la bonne clé via 'kid' si présent
            $decoded = JWT::decode($token, $keys);

            $this->injectClaims($request, $decoded);
            return $next($request);
        } catch (\Throwable $e) {
            Log::warning('JWKS validation failed: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid token', 'message' => $e->getMessage()], 401);
        }
    }

    private function injectClaims(Request $request, \stdClass $decoded): void
    {
        // external_id (sub)
        $externalId = $decoded->sub ?? null;

        // roles : realm_access + resource_access.<client>.roles
        $roles = [];
        if (isset($decoded->realm_access->roles) && is_array($decoded->realm_access->roles)) {
            $roles = array_merge($roles, $decoded->realm_access->roles);
        }
        if (isset($decoded->resource_access) && is_object($decoded->resource_access)) {
            foreach (get_object_vars($decoded->resource_access) as $client => $acc) {
                if (isset($acc->roles) && is_array($acc->roles)) {
                    $roles = array_merge($roles, $acc->roles);
                }
            }
        }
        // normalisation (lowercase + uniques)
        $roles = array_values(array_unique(array_map(fn($r) => strtolower((string)$r), $roles)));

        // agency_id (mapper Keycloak requis côté realm)
        $agencyId = $decoded->agency_id ?? null;

        // Métadonnées utiles (facultatives)
        $acr      = $decoded->acr ?? null;                       // ACR (ex: "mfa")
        $clientId = $decoded->azp ?? ($decoded->aud ?? null);    // client appli
        $username = $decoded->preferred_username ?? null;

        // Option ABAC futur : device trust via header (0..3)
        $deviceTrust = (int) $request->header('X-Device-Trust', 1);

        $request->attributes->add([
            'external_id'   => $externalId,
            'token_roles'   => $roles,
            'agency_id'     => $agencyId,
            'token_data'    => $decoded,
            'token_acr'     => $acr,
            'token_client'  => $clientId,
            'username'      => $username,
            'device_trust'  => $deviceTrust,
        ]);

        // Log minimal pour debug (pas de données sensibles)
        Log::info('auth.context', [
            'sub'       => $externalId,
            'roles'     => $roles,
            'agency_id' => $agencyId,
            'acr'       => $acr,
            'client'    => $clientId,
        ]);
    }

    private function toPem(string $rawKey): string
    {
        $rawKey = trim($rawKey);
        if (str_contains($rawKey, '-----BEGIN PUBLIC KEY-----')) {
            return preg_replace('/\r\n?/', "\n", $rawKey) . (str_ends_with($rawKey, "\n") ? '' : "\n");
        }
        $stripped = preg_replace('/\s+/', '', $rawKey);
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split($stripped, 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
