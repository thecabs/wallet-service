<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class PolicyDecision
{
    public function handle(Request $request, Closure $next)
    {
        $s = $request->attributes->get('zt.subject', []);
        $d = $request->attributes->get('zt.device', []);
        $c = $request->attributes->get('zt.context', []);
        $r = $request->attributes->get('zt.resource', []);
        $a = $request->attributes->get('zt.action', 'read');

        // RISK (MVP)
        $risk = 0;
        $hour = (int) ($c['hour'] ?? 12);
        if ($hour >= 20 || $hour < 6) $risk++;                  // heure atypique
        if ((int) ($c['failures_5m'] ?? 0) >= 5) $risk = 2;     // échecs répétés

        // Rôles normalisés
        $roles = collect(Arr::wrap($s['roles'] ?? []))
            ->map(fn($r) => strtolower((string)$r))
            ->all();

        $isAdmin = (bool) array_intersect($roles, [
            'admin','superadmin','bo_admin','bo_superadmin','role_admin','role_superadmin'
        ]);

        $isAgencyDirector = (bool) array_intersect($roles, [
            'directeur_agence','agency_director','role_agency_director'
        ]);

        // 1) Scoping agence (si owner_agency connu & user non-admin)
        if (!$isAdmin && !empty($r['owner_agency'])) {
            $userAgency = $s['agency_id'] ?? null;
            if ($userAgency === null) {
                return $this->deny('agency_missing', $risk);
            }
            // Directeur d'agence doit matcher l'agence
            if ($isAgencyDirector && $userAgency !== $r['owner_agency']) {
                return $this->deny('agency_scope_mismatch', $risk);
            }
            // Si pas directeur, pas propriétaire et pas admin → lecture OK (MVP wallet: pas de PII),
            // mais écriture interdite
            if ($a !== 'read' && $userAgency !== $r['owner_agency']) {
                return $this->deny('write_outside_agency', $risk);
            }
        }

        // 2) Device trust faible → écritures interdites
        if ((int) ($d['trust'] ?? 0) <= 1 && in_array($a, ['write','export','admin'], true)) {
            return $this->deny('device_trust_low', $risk);
        }

        // 3) Step-up pour PII à risque == 2 (pas applicable au wallet, laissé générique)
        if ($risk === 2 && ($r['sensitivity'] ?? '') === 'PII') {
            return response()->json(['error' => 'mfa_required', 'obligation' => 'mfa'], 403);
        }

        // 4) Risque élevé → actions sensibles interdites
        if ($risk >= 3 && in_array($a, ['write','export','admin'], true)) {
            return $this->deny('risk_high', $risk);
        }

        // Log décision
        Log::info('pdp.decision', [
            'allow'    => true,
            'reason'   => 'ok',
            'risk'     => $risk,
            'subject'  => ['sub' => $s['sub'] ?? null, 'roles' => $roles, 'agency_id' => $s['agency_id'] ?? null],
            'resource' => $r,
            'action'   => $a,
        ]);

        return $next($request);
    }

    private function deny(string $reason, int $risk)
    {
        Log::info('pdp.decision', ['allow' => false, 'reason' => $reason, 'risk' => $risk]);
        return response()->json(['error' => 'forbidden', 'reason' => $reason], 403);
    }
}
