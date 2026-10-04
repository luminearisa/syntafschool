<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Hasil inspeksi mutu (§24). */
enum InspectionStatus: string
{
    use EnumOptions;

    case PENDING = 'PENDING';
    case PASSED = 'PASSED';
    case PARTIAL = 'PARTIAL';
    case FAILED = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu',
            self::PASSED => 'Lolos',
            self::PARTIAL => 'Lolos Sebagian',
            self::FAILED => 'Gagal',
        };
    }
}
