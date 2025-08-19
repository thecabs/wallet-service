<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        \App\Http\Middleware\TrustProxies::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    ];

    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        'api' => [
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];

    protected $middlewareAliases = [
        'auth.basic'     => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'auth.session'   => \Illuminate\Session\Middleware\AuthenticateSession::class,
        'cache.headers'  => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can'            => \Illuminate\Auth\Middleware\Authorize::class,
        'guest'          => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'precognitive'   => \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
        'signed'         => \App\Http\Middleware\ValidateSignature::class,
        'throttle'       => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified'       => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,

        // ✅ ZTA / Sécurité
        'keycloak'         => \App\Http\Middleware\CheckJWTFromKeycloak::class,
        'check.role'       => \App\Http\Middleware\CheckKeycloakRole::class,
        'context.enricher' => \App\Http\Middleware\ContextEnricher::class,
        'resource.tag'     => \App\Http\Middleware\ResourceTag::class,
        'pdp'              => \App\Http\Middleware\PolicyDecision::class,
        'idempotency'      => \App\Http\Middleware\Idempotency::class,
    ];
}
