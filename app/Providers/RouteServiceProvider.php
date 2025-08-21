<?php
declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /**
         * 1) Throttle GLOBAL du group 'api'
         *    - Évite le cumul bloquant avec la valeur par défaut (souvent 60/min/IP)
         *    - Paramétrable via ENV ; plus permissif en local pour les tests
         */
        RateLimiter::for('api', function (Request $request) {
            $perMinute = app()->isLocal()
                ? (int) env('RATE_LIMIT_API_LOCAL', 600)
                : (int) env('RATE_LIMIT_API', 120);

            // clé = IP pour le global (classique)
            $key = $request->ip();

            // Réponse JSON standardisée (garde les headers Retry-After / X-RateLimit-*)
            return Limit::perMinute($perMinute)->by($key)->response(function ($request, array $headers) {
                return response()->json([
                    'error'      => 'rate_limited',
                    'message'    => 'Too Many Attempts.',
                    'retry_after'=> $headers['Retry-After'] ?? null,
                ], 429, $headers);
            });
        });

        /**
         * Helpers pour clés stables
         */
        $subjectKey = static function (Request $r): string {
            return (string) ($r->attributes->get('external_id') ?: $r->ip());
        };
        $routeKey = static function (Request $r): string {
            // Nom de route si dispo, sinon chemin
            return (string) ($r->route()?->getName() ?? $r->path());
        };
        $walletParam = static function (Request $r): ?string {
            $w = $r->route('wallet');
            return is_string($w) ? $w : (is_object($w) && method_exists($w, 'getAttribute') ? (string) $w->getAttribute('id') : null);
        };

        /**
         * 2) Throttle LECTURE (wallet-read)
         *    - Budget par (route + sujet) pour éviter qu’un GET consomme le quota d’un autre
         *    - Plus permissif en local
         */
        RateLimiter::for('wallet-read', function (Request $r) use ($subjectKey, $routeKey) {
            $limit = app()->isLocal()
                ? (int) env('RATE_LIMIT_WALLET_READ_LOCAL', 600)
                : (int) env('RATE_LIMIT_WALLET_READ', 60);

            $key = 'wr:' . $routeKey($r) . ':' . $subjectKey($r);

            return Limit::perMinute($limit)->by($key)->response(function ($request, array $headers) {
                return response()->json([
                    'error'      => 'rate_limited',
                    'message'    => 'Too Many Attempts (wallet-read).',
                    'retry_after'=> $headers['Retry-After'] ?? null,
                ], 429, $headers);
            });
        });

        /**
         * 3) Throttle ÉCRITURE (wallet-write)
         *    - Budget par sujet ; plus strict en prod
         */
        RateLimiter::for('wallet-write', function (Request $r) use ($subjectKey, $routeKey) {
            $limit = app()->isLocal()
                ? (int) env('RATE_LIMIT_WALLET_WRITE_LOCAL', 120)
                : (int) env('RATE_LIMIT_WALLET_WRITE', 5);

            $key = 'ww:' . $routeKey($r) . ':' . $subjectKey($r);

            return Limit::perMinute($limit)->by($key)->response(function ($request, array $headers) {
                return response()->json([
                    'error'      => 'rate_limited',
                    'message'    => 'Too Many Attempts (wallet-write).',
                    'retry_after'=> $headers['Retry-After'] ?? null,
                ], 429, $headers);
            });
        });

        /**
         * 4) Throttle TRANSACTIONS (wallet-tx)
         *    - Budget par (wallet_id + sujet) pour éviter qu’un même user sature toutes les TX
         */
        RateLimiter::for('wallet-tx', function (Request $r) use ($subjectKey, $walletParam, $routeKey) {
            $limit = app()->isLocal()
                ? (int) env('RATE_LIMIT_WALLET_TX_LOCAL', 240)
                : (int) env('RATE_LIMIT_WALLET_TX', 20);

            $w = $walletParam($r) ?: 'no-wallet';
            $key = 'wt:' . $routeKey($r) . ':' . $w . ':' . $subjectKey($r);

            return Limit::perMinute($limit)->by($key)->response(function ($request, array $headers) {
                return response()->json([
                    'error'      => 'rate_limited',
                    'message'    => 'Too Many Attempts (wallet-tx).',
                    'retry_after'=> $headers['Retry-After'] ?? null,
                ], 429, $headers);
            });
        });

        // Charge les routes API
        $this->routes(function () {
            Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));
        });
    }
}
