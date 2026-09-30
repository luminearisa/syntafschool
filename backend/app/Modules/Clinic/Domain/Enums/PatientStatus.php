<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum PatientStatus — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum PatientStatus: string
{
    case ACTIVE = 'ACTIVE';
    case ARCHIVED = 'ARCHIVED';
    case RESTRICTED = 'RESTRICTED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Aktif',
            self::ARCHIVED => 'Diarsipkan',
            self::RESTRICTED => 'Dibatasi',
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
