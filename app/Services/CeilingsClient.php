<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

class CeilingsClient
{
    private PendingRequest $http;
    private string $baseUrl;

    public function __construct(
        private KeycloakTokenService $kc,
        HttpFactory $factory,
    ) {
        $this->baseUrl = rtrim((string) config('services.user_ceiling.base_url'), '/');
        $timeout = (int) config('http.timeout', 20);
        $this->http = $factory->timeout($timeout)->retry(2, 200);
    }

    /**
     * Retourne true si l’opération est autorisée selon les plafonds.
     */
    public function check(string $externalId, float $amount, string $period = 'daily'): bool
    {
        if ($this->baseUrl === '') {
            throw new RuntimeException('CeilingsClient: services.user_ceiling.base_url manquant.');
        }

        $token = $this->kc->getServiceToken(); // client_credentials du wallet-service (service account)

        $resp = $this->http
            ->withToken($token)
            ->acceptJson()
            ->post($this->baseUrl . '/api/ceilings/check-limit', [
                'external_id' => $externalId,
                'amount'      => $amount,
                'period'      => $period,
            ]);

        if ($resp->failed()) {
            throw new RuntimeException('Ceiling service error: ' . $resp->status());
        }

        return (bool) $resp->json('allowed', false);
    }

    /**
     * Lève une exception si le plafond n’autorise pas l’opération.
     */
    public function checkOrFail(string $externalId, float $amount, string $period = 'daily'): void
    {
        if (!$this->check($externalId, $amount, $period)) {
            throw new RuntimeException("Transaction exceeds ceiling ($period).");
        }
    }
}
