<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Injecte un tag de sensibilité ressource dans la requête
 * pour la chaîne ABAC/ZTA (consommé ensuite par le PDP).
 *
 * Usage route: ->middleware('resource.tag:FINANCIAL') ou :PII, INTERNAL, etc.
 * Override optionnel via header: X-Resource-Tag
 */
class ResourceTag
{
    public function handle(Request $request, Closure $next, ?string $tag = null): Response
    {
        $param   = $tag ?: 'GENERAL';
        $header  = $request->headers->get('X-Resource-Tag');
        $value   = strtoupper($header ?: $param);

        // Attributs simples
        $request->attributes->set('resource_tag', $value);

        // Espace "zt" partagé par les autres middlewares (PDP)
        $zt = (array) $request->attributes->get('zt', []);
        $zt['resource'] = array_merge($zt['resource'] ?? [], [
            'sensitivity' => $value,
        ]);
        $request->attributes->set('zt', $zt);

        return $next($request);
    }
}
