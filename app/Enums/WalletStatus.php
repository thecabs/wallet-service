<?php

namespace App\Enums;

enum WalletStatus: string
{
    case ACTIF = 'actif';
    case SUSPENDU = 'suspendu';
    case FERME = 'fermé';

    public function label(): string
    {
        return match($this) {
            self::ACTIF => 'Actif',
            self::SUSPENDU => 'Suspendu',
            self::FERME => 'Fermé',
        };
    }
}
