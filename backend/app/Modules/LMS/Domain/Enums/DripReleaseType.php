<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Pola pelepasan konten bertahap / drip content (§4).
 */
enum DripReleaseType: string
{
    case IMMEDIATE = 'IMMEDIATE';
    case SPECIFIC_DATE = 'SPECIFIC_DATE';
    case DAYS_AFTER_ENROLLMENT = 'DAYS_AFTER_ENROLLMENT';
    case DAYS_AFTER_PREVIOUS_COMPLETION = 'DAYS_AFTER_PREVIOUS_COMPLETION';
    case ON_COMPLETION = 'ON_COMPLETION';

    public function label(): string
    {
        return match ($this) {
            self::IMMEDIATE => 'Langsung tersedia',
            self::SPECIFIC_DATE => 'Pada tanggal tertentu',
            self::DAYS_AFTER_ENROLLMENT => 'Beberapa hari setelah mendaftar',
            self::DAYS_AFTER_PREVIOUS_COMPLETION => 'Beberapa hari setelah materi sebelumnya selesai',
            self::ON_COMPLETION => 'Setelah materi sebelumnya selesai',
        };
    }

    public function isTimeBased(): bool
    {
        return in_array($this, [self::SPECIFIC_DATE, self::DAYS_AFTER_ENROLLMENT, self::DAYS_AFTER_PREVIOUS_COMPLETION], true);
    }

    public function isCompletionBased(): bool
    {
        return $this === self::ON_COMPLETION;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
