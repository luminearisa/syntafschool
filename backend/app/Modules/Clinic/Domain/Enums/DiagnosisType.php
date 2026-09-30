<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum DiagnosisType — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum DiagnosisType: string
{
    case PRIMARY = 'PRIMARY';
    case SECONDARY = 'SECONDARY';
    case SYMPTOM = 'SYMPTOM';
    case PROVISIONAL = 'PROVISIONAL';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PRIMARY => 'Diagnosis Utama',
            self::SECONDARY => 'Diagnosis Sekunder',
            self::SYMPTOM => 'Gejala',
            self::PROVISIONAL => 'Sementara',
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
