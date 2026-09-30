<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum CampaignType — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum CampaignType: string
{
    case EDUCATION = 'EDUCATION';
    case CAMPAIGN = 'CAMPAIGN';
    case ANNOUNCEMENT = 'ANNOUNCEMENT';
    case POSTER = 'POSTER';
    case ARTICLE = 'ARTICLE';
    case WORKSHOP = 'WORKSHOP';
    case SEMINAR = 'SEMINAR';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::EDUCATION => 'Edukasi',
            self::CAMPAIGN => 'Kampanye',
            self::ANNOUNCEMENT => 'Pengumuman',
            self::POSTER => 'Poster',
            self::ARTICLE => 'Artikel',
            self::WORKSHOP => 'Lokakarya',
            self::SEMINAR => 'Seminar',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
