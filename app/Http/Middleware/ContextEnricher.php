<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ContextEnricher
{
    public function handle(Request $request, Closure $next)
    {
        // SUBJECT (déjà posé par CheckJWTFromKeycloak)
        $subject = [
            'sub'       => $request->attributes->get('external_id'),
            'roles'     => (array) $request->attributes->get('token_roles', []),
            'agency_id' => $request->attributes->get('agency_id'),
        ];

        // ==== DEVICE TRUST (calculé côté serveur) ====
        $trust = 1; // défaut: faible

        // 1) mTLS (reverse proxy/web server remplit ces vars)
        $sslVerify = $_SERVER['SSL_CLIENT_VERIFY'] ?? null;
        if ($sslVerify === 'SUCCESS') {
            $trust = 3;
        }

        // 2) IP autorisées (réseau bureau/VPN)
        if ($trust < 3) {
            $clientIp = $request->ip();
            $cidrs = array_filter(array_map('trim', explode(',', env('ADMIN_TRUSTED_CIDRS', ''))));
            foreach ($cidrs as $cidr) {
                if ($this->ipInCidr($clientIp, $cidr)) {
                    $trust = max($trust, 3); // réseau de confiance -> 3
                    break;
                }
            }
        }

        // 3) Attestation légère (HMAC) – optionnelle
        //   Le client envoie:
        //     X-Device-Id: <uuid>
        //     X-Device-Attest: base64url( HMAC_SHA256( device_id, DEVICE_ATTEST_SECRET ) )
        if ($trust < 3) {
            $devId = $request->header('X-Device-Id');
            $att   = $request->header('X-Device-Attest');
            $secret = env('DEVICE_ATTEST_SECRET'); // fixe en env (rotatable)
            if ($devId && $att && $secret) {
                $calc = rtrim(strtr(base64_encode(hash_hmac('sha256', $devId, $secret, true)), '+/', '-_'), '=');
                if (hash_equals($calc, $att)) {
                    $trust = max($trust, 2); // attestation soft -> 2
                }
            }
        }

        // 4) (DEV-ONLY) Accepter le header X-Device-Trust quand ALLOW_TRUST_HEADER=true
        if (env('ALLOW_TRUST_HEADER', false)) {
            $trust = max($trust, (int) $request->header('X-Device-Trust', 0));
        }

        $device = ['trust' => $trust];

        // ==== CONTEXT (MVP) ====
        $sub = $subject['sub'] ?: 'anon';
        $context = [
            'ip'          => $request->ip(),
            'hour'        => now()->setTimezone('Africa/Douala')->hour,
            'failures_5m' => (int) Cache::get("auth_fail_{$sub}", 0),
            'mfa_recent'  => $request->attributes->get('mfa_recent', false),
        ];

        // ==== RESOURCE / ACTION ====
        $resource = [
            'type'         => 'wallet',
            'id'           => null,
            'owner_agency' => null,
            'sensitivity'  => 'FINANCIAL',
        ];
        if ($wallet = $request->route('wallet')) {
            $resource['id']           = method_exists($wallet, 'getKey') ? $wallet->getKey() : (string) $wallet;
            $resource['owner_agency'] = $wallet->agency_id ?? null;
        }

        $action = strtoupper($request->method()) === 'GET' ? 'read' : 'write';

        // Pose dans la request
        $request->attributes->set('zt.subject',  $subject);
        $request->attributes->set('zt.device',   $device);
        $request->attributes->set('zt.context',  $context);
        $request->attributes->set('zt.resource', $resource);
        $request->attributes->set('zt.action',   $action);

        Log::info('context.enriched', [
            'device_trust' => $trust,
            'ip'           => $context['ip'],
            'action'       => $action,
        ]);

        return $next($request);
    }

    // Support IPv4 CIDR simple (ex: 192.168.1.0/24)
    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) return $ip === $cidr;
        [$subnet, $mask] = explode('/', $cidr, 2);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ||
            filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false; // (pour IPv6, ajouter une implémentation dédiée si besoin)
        }
        $mask = (int) $mask;
        $ipLong = ip2long($ip);
        $subLong = ip2long($subnet);
        $maskLong = -1 << (32 - $mask);
        return ($ipLong & $maskLong) === ($subLong & $maskLong);
    }
}
