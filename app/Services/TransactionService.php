<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Enums\TransactionStatus;
use App\Enums\WalletStatus;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    public function __construct(private CeilingsClient $ceilings) {}

    /** Normalise un montant -> string décimal avec $scale décimales (par défaut 2) */
    private function dec(mixed $value, int $scale = 2): string
    {
        // number_format assure exactement $scale décimales et un point comme séparateur
        return number_format((float) $value, $scale, '.', '');
    }

    public function creditWallet(
        Wallet $wallet,
        float $amount,
        string $reference,
        string $description,
        string $period = 'daily'
    ): WalletTransaction {
        return DB::transaction(function () use ($wallet, $amount, $reference, $description, $period) {
            $existing = WalletTransaction::where('reference', $reference)->first();
            if ($existing) {
                return $existing;
            }

            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            if ($wallet->statut !== WalletStatus::ACTIF->value) {
                throw new \Exception('Portefeuille non actif');
            }

            if (config('wallet.enforce_credit_ceiling', false)) {
                $this->ceilings->checkOrFail($wallet->external_id, $amount, $period);
            }

            $tx = $wallet->transactions()->create([
                'type'        => 'credit',
                'montant'     => $amount,
                'devise'      => $wallet->devise,
                'description' => $description,
                'reference'   => $reference,
                'statut'      => TransactionStatus::COMPLETED->value,
            ]);

            $wallet->increment('solde', $amount);

            return $tx;
        });
    }

    public function debitWallet(
        Wallet $wallet,
        float $amount,
        string $reference,
        string $description,
        string $period = 'daily'
    ): WalletTransaction {
        return DB::transaction(function () use ($wallet, $amount, $reference, $description, $period) {
            $existing = WalletTransaction::where('reference', $reference)->first();
            if ($existing) {
                return $existing;
            }

            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            if ($wallet->statut !== WalletStatus::ACTIF->value) {
                throw new \Exception('Portefeuille non actif');
            }

            // ✅ bccomp exige des strings : on normalise à 2 décimales
            if (bccomp($this->dec($wallet->solde), $this->dec($amount), 2) < 0) {
                throw new \Exception('Solde insuffisant');
            }

            // Plafond de DÉBIT
            $this->ceilings->checkOrFail($wallet->external_id, $amount, $period);

            $tx = $wallet->transactions()->create([
                'type'        => 'debit',
                'montant'     => $amount,
                'devise'      => $wallet->devise,
                'description' => $description,
                'reference'   => $reference,
                'statut'      => TransactionStatus::COMPLETED->value,
            ]);

            $wallet->decrement('solde', $amount);

            return $tx;
        });
    }

    public function getStatement(
        Wallet $wallet,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $type = null,
        int $limit = 25,
        int $page = 1
    ) {
        $query = $wallet->transactions()->orderBy('created_at', 'desc');

        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }
        if ($type) {
            $query->where('type', $type);
        }

        return $query->paginate($limit, ['*'], 'page', $page);
    }
}
