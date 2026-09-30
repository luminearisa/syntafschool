<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum AuditAction — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum AuditAction: string
{
    case VIEW = 'VIEW';
    case CREATE = 'CREATE';
    case UPDATE = 'UPDATE';
    case DELETE = 'DELETE';
    case EXPORT = 'EXPORT';
    case PRINT = 'PRINT';
    case SHARE = 'SHARE';
    case DISPENSE = 'DISPENSE';
    case ADMINISTER = 'ADMINISTER';
    case AMEND = 'AMEND';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::VIEW => 'Melihat',
            self::CREATE => 'Membuat',
            self::UPDATE => 'Mengubah',
            self::DELETE => 'Menghapus',
            self::EXPORT => 'Ekspor',
            self::PRINT => 'Cetak',
            self::SHARE => 'Berbagi',
            self::DISPENSE => 'Menyerahkan Obat',
            self::ADMINISTER => 'Memberi Obat',
            self::AMEND => 'Koreksi',
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
