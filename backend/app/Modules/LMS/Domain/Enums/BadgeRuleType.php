<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Pemicu pemberian badge (§26).
 */
enum BadgeRuleType: string
{
    case COMPLETION = 'COMPLETION';
    case GRADE = 'GRADE';
    case ACTIVITY_COUNT = 'ACTIVITY_COUNT';
    case FORUM_PARTICIPATION = 'FORUM_PARTICIPATION';
    case COMPETENCY = 'COMPETENCY';
    case MANUAL = 'MANUAL';

    public function label(): string
    {
        return match ($this) {
            self::COMPLETION => 'Menyelesaikan course',
            self::GRADE => 'Mencapai nilai tertentu',
            self::ACTIVITY_COUNT => 'Jumlah aktivitas selesai',
            self::FORUM_PARTICIPATION => 'Partisipasi forum',
            self::COMPETENCY => 'Kompetensi tercapai',
            self::MANUAL => 'Diberikan manual oleh guru',
        };
    }

    public function isAutomatic(): bool
    {
        return $this !== self::MANUAL;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
