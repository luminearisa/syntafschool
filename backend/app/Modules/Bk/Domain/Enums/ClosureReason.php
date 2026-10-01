<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Alasan penutupan kasus.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum ClosureReason: string
{
    case GOAL_ACHIEVED = 'GOAL_ACHIEVED';
    case STABLE = 'STABLE';
    case TRANSFERRED = 'TRANSFERRED';
    case REFERRED = 'REFERRED';
    case STUDENT_LEFT = 'STUDENT_LEFT';
    case NO_FURTHER_ACTION = 'NO_FURTHER_ACTION';
    case DUPLICATE = 'DUPLICATE';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::GOAL_ACHIEVED => 'Sasaran tercapai',
            self::STABLE => 'Kondisi stabil',
            self::TRANSFERRED => 'Dipindahkan',
            self::REFERRED => 'Dirujuk',
            self::STUDENT_LEFT => 'Siswa pindah/lulus',
            self::NO_FURTHER_ACTION => 'Tidak perlu tindakan lanjut',
            self::DUPLICATE => 'Duplikat kasus lain',
            self::OTHER => 'Lain-lain',
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
