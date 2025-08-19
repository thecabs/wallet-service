<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WalletService
{
    /**
     * Crée (ou retrouve) un portefeuille pour un utilisateur.
     * - Idempotent via clé (redis lock) et via contrainte logique (external_id + devise).
     * - Laisse le Controller poser/forcer agency_id si besoin (rétro-compatible).
     */
    public function createWallet(
        string $externalId,
        ?string $localUserId = null,
        ?string $currency = 'XAF',
        ?string $agencyId = null,
        ?string $idempotencyKey = null
    ): Wallet {
        $devise = strtoupper((string) ($currency ?: 'XAF'));

        // 1) Verrou d’idempotence (si on a une clé explicite)
        $lock = null;
        if (!empty($idempotencyKey)) {
            $lockKey = "idem:wallet:create:{$externalId}:{$devise}:{$idempotencyKey}";
            $lock = Cache::lock($lockKey, 10); // 10s lock
            if (!$lock->get()) {
                // Une autre requête identique est en cours
                $existing = $this->findExisting($externalId, $devise);
                if ($existing) {
                    return $existing;
                }
                // Si rien, on répond conflit pour éviter les doublons très rapprochés
                abort(409, 'Request already in progress');
            }
        }

        try {
            // 2) Si existe déjà pour externalId+devise, on renvoie (idempotent)
            $existing = $this->findExisting($externalId, $devise);
            if ($existing) {
                return $existing;
            }

            // 3) Création transactionnelle
            return DB::transaction(function () use ($externalId, $localUserId, $devise, $agencyId) {
                /** @var Wallet $wallet */
                $wallet = new Wallet();

                // Champs usuels (le modèle est supposé existant, on reste tolérant)
                $wallet->id           = (string) Str::uuid();
                $wallet->external_id  = $externalId;
                if (!empty($agencyId)) {
                    $wallet->agency_id = $agencyId;
                }

                // certains schémas ont "devise", d'autres "currency"
                if ($wallet->getAttribute('devise') !== null || $wallet->isFillable('devise')) {
                    $wallet->devise = $devise;
                } elseif ($wallet->getAttribute('currency') !== null || $wallet->isFillable('currency')) {
                    $wallet->currency = $devise;
                }

                // champs de statut/solde selon ton modèle
                if ($wallet->getAttribute('statut') !== null || $wallet->isFillable('statut')) {
                    $wallet->statut = 'actif';
                } elseif ($wallet->getAttribute('status') !== null || $wallet->isFillable('status')) {
                    $wallet->status = 'actif';
                }

                if ($wallet->getAttribute('solde') !== null || $wallet->isFillable('solde')) {
                    $wallet->solde = $wallet->solde ?? 0;
                } elseif ($wallet->getAttribute('balance') !== null || $wallet->isFillable('balance')) {
                    $wallet->balance = $wallet->balance ?? 0;
                }

                // trace éventuelle
                if ($wallet->isFillable('created_by')) {
                    $wallet->created_by = request()->attributes->get('preferred_username')
                        ?? request()->attributes->get('external_id')
                        ?? $externalId;
                }
                if ($wallet->isFillable('created_via')) {
                    $wallet->created_via = 'api';
                }
                if ($wallet->isFillable('local_user_id') && $localUserId) {
                    $wallet->local_user_id = $localUserId;
                }

                $wallet->save();

                Log::channel('audit')->info('wallet.created.db', [
                    'wallet_id'   => $wallet->id,
                    'external_id' => $wallet->external_id,
                    'agency_id'   => $wallet->agency_id,
                    'devise'      => $devise,
                ]);

                return $wallet;
            });
        } finally {
            if ($lock) {
                optional($lock)->release();
            }
        }
    }

    /**
     * Mise en statut "inactif".
     */
    public function closeWallet(Wallet $wallet): void
    {
        DB::transaction(function () use ($wallet) {
            if ($wallet->getAttribute('statut') !== null || $wallet->isFillable('statut')) {
                $wallet->statut = 'inactif';
            } elseif ($wallet->getAttribute('status') !== null || $wallet->isFillable('status')) {
                $wallet->status = 'inactif';
            }
            $wallet->save();

            Log::channel('audit')->info('wallet.closed.db', [
                'wallet_id'   => $wallet->id,
                'external_id' => $wallet->external_id,
            ]);
        });
    }

    /**
     * Mise en statut "suspendu".
     */
    public function suspendWallet(Wallet $wallet): void
    {
        DB::transaction(function () use ($wallet) {
            if ($wallet->getAttribute('statut') !== null || $wallet->isFillable('statut')) {
                $wallet->statut = 'suspendu';
            } elseif ($wallet->getAttribute('status') !== null || $wallet->isFillable('status')) {
                $wallet->status = 'suspendu';
            }
            $wallet->save();

            Log::channel('audit')->info('wallet.suspended.db', [
                'wallet_id'   => $wallet->id,
                'external_id' => $wallet->external_id,
            ]);
        });
    }

    /**
     * Mise en statut "actif".
     */
    public function activateWallet(Wallet $wallet): void
    {
        DB::transaction(function () use ($wallet) {
            if ($wallet->getAttribute('statut') !== null || $wallet->isFillable('statut')) {
                $wallet->statut = 'actif';
            } elseif ($wallet->getAttribute('status') !== null || $wallet->isFillable('status')) {
                $wallet->status = 'actif';
            }
            $wallet->save();

            Log::channel('audit')->info('wallet.activated.db', [
                'wallet_id'   => $wallet->id,
                'external_id' => $wallet->external_id,
            ]);
        });
    }

    /**
     * Recherche idempotente (external_id + devise/currency).
     */
    private function findExisting(string $externalId, string $devise): ?Wallet
    {
        $q = Wallet::query()->where('external_id', $externalId);

        // On supporte deux schémas possibles
        $hasDevise   = SchemaHelper::columnExists($q, 'devise');
        $hasCurrency = SchemaHelper::columnExists($q, 'currency');

        if ($hasDevise) {
            $q->where('devise', $devise);
        } elseif ($hasCurrency) {
            $q->where('currency', $devise);
        }

        return $q->first();
    }
}

/**
 * Petit helper interne (évite d’ajouter une dépendance) pour tester la colonne.
 */
final class SchemaHelper
{
    public static function columnExists($eloquentQuery, string $column): bool
    {
        try {
            /** @var \Illuminate\Database\Eloquent\Builder $eloquentQuery */
            $model = $eloquentQuery->getModel();
            $table = $model->getTable();

            // cache léger en statique
            static $cache = [];
            $key = $table.':'.$column;
            if (array_key_exists($key, $cache)) {
                return $cache[$key];
            }

            $exists = \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
            $cache[$key] = $exists;
            return $exists;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
