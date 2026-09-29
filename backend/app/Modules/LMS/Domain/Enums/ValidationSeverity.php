<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Tingkat temuan validator (§17).
 *
 * `ERROR` menahan publikasi, `WARNING` hanya mengingatkan, `INFO` catatan
 * kualitas. Tidak ada validasi yang menghalangi peninjauan konten (§36).
 */
enum ValidationSeverity: string
{
    case ERROR = 'ERROR';
    case WARNING = 'WARNING';
    case INFO = 'INFO';

    public function label(): string
    {
        return match ($this) {
            self::ERROR => 'Harus diperbaiki',
            self::WARNING => 'Perlu diperhatikan',
            self::INFO => 'Catatan',
        };
    }

    public function blocksPublication(): bool
    {
        return $this === self::ERROR;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
