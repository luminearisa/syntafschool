<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Cara sebuah learning item dianggap tuntas (§22). Progress course dihitung
 * dari item bertipe selesai ini, bukan dari jumlah halaman yang dibuka.
 */
enum CompletionType: string
{
    case NONE = 'NONE';
    case MANUAL = 'MANUAL';
    case VIEW = 'VIEW';
    case PASS = 'PASS';
    case SUBMIT = 'SUBMIT';
    case COMPLETE_ACTIVITY = 'COMPLETE_ACTIVITY';

    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Tidak dihitung',
            self::MANUAL => 'Tandai manual oleh siswa',
            self::VIEW => 'Terbuka/terlihat',
            self::PASS => 'Lulus nilai minimum',
            self::SUBMIT => 'Mengumpulkan',
            self::COMPLETE_ACTIVITY => 'Menyelesaikan aktivitas',
        };
    }

    /** Diperhitungkan sebagai bagian progress course. */
    public function countsTowardProgress(): bool
    {
        return $this !== self::NONE;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
