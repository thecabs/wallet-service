<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use HasFactory, HasUuids;

    // UUID PK
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        // 'user_id',            // ❌ pas de colonne en DB -> on retire
        'external_id',
        'agency_id',            // ✅ nouveau champ scoping d’agence
        'solde',
        'devise',
        'statut',
    ];

    protected $casts = [
        'solde' => 'decimal:2',
    ];

    // ❌ Relation User retirée (pas de table/clé en DB)
    // public function user(): BelongsTo { ... }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * NOTE: credit/debit ne modifient pas le solde ici -> géré par TransactionService
     * On garde la création de transaction avec statut COMPLETED (si ton enum correspond).
     */
    public function credit(float $amount, string $reference, ?string $description = null): WalletTransaction
    {
        return $this->transactions()->create([
            'type'        => 'credit',
            'montant'     => $amount,
            'devise'      => $this->devise,
            'description' => $description,
            'reference'   => $reference,
            'statut'      => \App\Enums\TransactionStatus::COMPLETED->value,
        ]);
    }

    public function debit(float $amount, string $reference, ?string $description = null): WalletTransaction
    {
        return $this->transactions()->create([
            'type'        => 'debit',
            'montant'     => $amount,
            'devise'      => $this->devise,
            'description' => $description,
            'reference'   => $reference,
            'statut'      => \App\Enums\TransactionStatus::COMPLETED->value,
        ]);
    }
}
