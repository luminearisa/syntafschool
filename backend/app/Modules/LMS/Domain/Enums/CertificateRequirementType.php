<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Syarat kelayakan sertifikat (§21). Beberapa syarat dapat digabung pada satu
 * course; semuanya harus terpenuhi sebelum sertifikat dapat diterbitkan.
 */
enum CertificateRequirementType: string
{
    case COMPLETION_PERCENTAGE = 'COMPLETION_PERCENTAGE';
    case MINIMUM_GRADE = 'MINIMUM_GRADE';
    case REQUIRED_ACTIVITIES = 'REQUIRED_ACTIVITIES';
    case REQUIRED_ASSESSMENT = 'REQUIRED_ASSESSMENT';
    case COMPETENCY = 'COMPETENCY';

    public function label(): string
    {
        return match ($this) {
            self::COMPLETION_PERCENTAGE => 'Persentase penyelesaian',
            self::MINIMUM_GRADE => 'Nilai minimum',
            self::REQUIRED_ACTIVITIES => 'Aktivitas wajib',
            self::REQUIRED_ASSESSMENT => 'Asesmen akhir wajib',
            self::COMPETENCY => 'Kompetensi tercapai',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
