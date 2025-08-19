<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotence HTTP basée sur le header Idempotency-Key.
 *
 * - Si pas de header => passe.
 * - Si clé déjà vue et résultat en cache => rejoue la réponse précédente (X-Idempotent-Replay: 1).
 * - Si clé en cours (LOCK) => 409 conflict pour éviter les doublons concurrents.
 * - Sinon => lock, exécute l'action, met en cache le résultat pour la durée TTL.
 *
 * Usage route: ->middleware('idempotency:600')  // TTL 600s
 */
class Idempotency
{
    public function __construct(private CacheRepository $cache) {}

    public function handle(Request $request, Closure $next, int|string $ttlSeconds = 600): Response
    {
        $ttl = (int) $ttlSeconds;
        $key = $request->headers->get('Idempotency-Key');

        if (!$key) {
            /** @var Response $response */
            $response = $next($request);
            return $response;
        }

        $cacheKey = $this->cacheKey($key);

        // Déjà traité ?
        $entry = $this->cache->get($cacheKey);
        if (is_array($entry)) {
            return $this->replay($entry, $key);
        }

        // Tente un LOCK atomique (si existe déjà → conflit)
        if (!$this->cache->add($cacheKey, ['state' => 'LOCK'], $ttl)) {
            $r = response()->json([
                'success'           => false,
                'error'             => 'Idempotency key already in progress',
                'idempotent_replay' => true,
            ], 409);
            $r->headers->set('Idempotency-Key', $key);
            return $r;
        }

        // Exécute et stocke le résultat
        try {
            /** @var Response $response */
            $response = $next($request);

            $payload = [
                'state'        => 'RESULT',
                'status'       => $response->getStatusCode(),
                'content'      => $response->getContent(), // JSON string si JsonResponse
                'content_type' => $response->headers->get('Content-Type', 'application/json'),
            ];

            $this->cache->put($cacheKey, $payload, $ttl);

            // Ajouter l’en-tête sur la réponse renvoyée
            $response->headers->set('Idempotency-Key', $key);
            return $response;

        } catch (\Throwable $e) {
            // On mémorise un échec générique pour rejouer une erreur cohérente
            $this->cache->put($cacheKey, [
                'state'        => 'RESULT',
                'status'       => 500,
                'content'      => json_encode(['success' => false, 'error' => 'idempotent_failed']),
                'content_type' => 'application/json',
            ], $ttl);
            throw $e;
        }
    }

    private function replay(array $entry, string $key): Response
    {
        // Si LOCK encore présent -> conflit (requête concurrente)
        if (($entry['state'] ?? null) !== 'RESULT') {
            $r = response()->json([
                'success'           => false,
                'error'             => 'Idempotency key in progress',
                'idempotent_replay' => true,
            ], 409);
            $r->headers->set('Idempotency-Key', $key);
            return $r;
        }

        $status      = (int) ($entry['status'] ?? 200);
        $content     = (string) ($entry['content'] ?? '');
        $contentType = (string) ($entry['content_type'] ?? 'application/json');

        $r = response($content, $status);
        $r->headers->set('Content-Type', $contentType);
        $r->headers->set('Idempotency-Key', $key);
        $r->headers->set('X-Idempotent-Replay', '1');
        return $r;
    }

    private function cacheKey(string $key): string
    {
        // scope applicatif pour éviter collisions cross-MS
        $app = config('app.name', 'laravel');
        return sprintf('idem:%s:%s', strtolower($app), sha1($key));
    }
}
