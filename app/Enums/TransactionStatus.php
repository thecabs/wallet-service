<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::PENDING => 'En attente',
            self::COMPLETED => 'Complétée',
            self::FAILED => 'Échouée',
            self::CANCELLED => 'Annulée',
        };
    }
}