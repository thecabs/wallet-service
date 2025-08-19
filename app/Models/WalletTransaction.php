<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'wallet_id',
        'type',
        'montant',
        'devise',
        'description',
        'reference',
        'statut',
    ];

    protected $casts = [
        'montant' => 'decimal:2',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
