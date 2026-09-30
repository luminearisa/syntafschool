<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum HistoryCategory — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum HistoryCategory: string
{
    case PAST_ILLNESS = 'PAST_ILLNESS';
    case SURGERY = 'SURGERY';
    case HOSPITALIZATION = 'HOSPITALIZATION';
    case CHRONIC_CONDITION = 'CHRONIC_CONDITION';
    case MEDICATION_HISTORY = 'MEDICATION_HISTORY';
    case FAMILY_HISTORY = 'FAMILY_HISTORY';
    case RELEVANT_HISTORY = 'RELEVANT_HISTORY';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PAST_ILLNESS => 'Riwayat Penyakit',
            self::SURGERY => 'Operasi',
            self::HOSPITALIZATION => 'Rawat Inap',
            self::CHRONIC_CONDITION => 'Kondisi Kronis',
            self::MEDICATION_HISTORY => 'Riwayat Obat',
            self::FAMILY_HISTORY => 'Riwayat Keluarga',
            self::RELEVANT_HISTORY => 'Riwayat Relevan',
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
