<?php

namespace App\Modules\UKS\Domain\Enums;

enum StockReason: string
{
    case PENGADAAN = 'PENGADAAN';
    case PEMAKAIAN = 'PEMAKAIAN';
    case PENYESUAIAN = 'PENYESUAIAN';
    case KEDALUWARSA = 'KEDALUWARSA';
    case RUSAK = 'RUSAK';

    public function label(): string
    {
        return match ($this) {
            self::PENGADAAN => 'Pengadaan',
            self::PEMAKAIAN => 'Pemakaian',
            self::PENYESUAIAN => 'Penyesuaian Stok',
            self::KEDALUWARSA => 'Kedaluwarsa',
            self::RUSAK => 'Rusak/Hilang',
        };
    }
}

