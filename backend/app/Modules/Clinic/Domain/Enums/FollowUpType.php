<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum FollowUpType — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum FollowUpType: string
{
    case REVIEW = 'REVIEW';
    case MEDICATION = 'MEDICATION';
    case WOUND_CARE = 'WOUND_CARE';
    case OBSERVATION = 'OBSERVATION';
    case RESULT_REVIEW = 'RESULT_REVIEW';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::REVIEW => 'Kontrol Ulang',
            self::MEDICATION => 'Obat',
            self::WOUND_CARE => 'Perawatan Luka',
            self::OBSERVATION => 'Observasi',
            self::RESULT_REVIEW => 'Baca Hasil',
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
