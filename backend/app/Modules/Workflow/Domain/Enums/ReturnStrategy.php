<?php

namespace App\Modules\Workflow\Domain\Enums;

/**
 * Perilaku alur setelah request dikembalikan lalu disubmit ulang (§34, §35).
 */
enum ReturnStrategy: string
{
    /** Lanjut dari step yang mengembalikan (approval sebelumnya tetap sah). */
    case RESUME_FROM_PREVIOUS_STEP = 'RESUME_FROM_PREVIOUS_STEP';
    /** Mulai ulang dari step pertama (semua keputusan lama diarsipkan). */
    case RESTART_WORKFLOW = 'RESTART_WORKFLOW';

    public function label(): string
    {
        return match ($this) {
            self::RESUME_FROM_PREVIOUS_STEP => 'Lanjut dari Step Pengembali',
            self::RESTART_WORKFLOW => 'Mulai Ulang dari Awal',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
