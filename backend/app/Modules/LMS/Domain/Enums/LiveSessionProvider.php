<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Penyedia pertemuan daring (§13). Tidak ada provider yang di-hardcode:
 * setiap nilai dipetakan ke `LiveMeetingProviderInterface`.
 */
enum LiveSessionProvider: string
{
    case EXTERNAL = 'EXTERNAL';
    case ZOOM = 'ZOOM';
    case GOOGLE_MEET = 'GOOGLE_MEET';
    case CUSTOM = 'CUSTOM';

    public function label(): string
    {
        return match ($this) {
            self::EXTERNAL => 'Tautan eksternal',
            self::ZOOM => 'Zoom',
            self::GOOGLE_MEET => 'Google Meet',
            self::CUSTOM => 'Kustom',
        };
    }

    /** Provider bawaan hanya menyimpan tautan; tidak memanggil API pihak ketiga. */
    public function supportsApiIntegration(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
