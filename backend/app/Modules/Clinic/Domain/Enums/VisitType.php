<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum VisitType — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum VisitType: string
{
    case GENERAL = 'GENERAL';
    case EMERGENCY = 'EMERGENCY';
    case INJURY = 'INJURY';
    case ILLNESS = 'ILLNESS';
    case MEDICATION = 'MEDICATION';
    case FOLLOW_UP = 'FOLLOW_UP';
    case HEALTH_CHECK = 'HEALTH_CHECK';
    case VACCINATION = 'VACCINATION';
    case REFERRAL = 'REFERRAL';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::GENERAL => 'Umum',
            self::EMERGENCY => 'Gawat Darurat',
            self::INJURY => 'Cedera',
            self::ILLNESS => 'Penyakit',
            self::MEDICATION => 'Obat',
            self::FOLLOW_UP => 'Kontrol',
            self::HEALTH_CHECK => 'Pemeriksaan Kesehatan',
            self::VACCINATION => 'Imunisasi',
            self::REFERRAL => 'Rujukan',
            self::OTHER => 'Lainnya',
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
