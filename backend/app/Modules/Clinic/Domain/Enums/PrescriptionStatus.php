<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum PrescriptionStatus — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum PrescriptionStatus: string
{
    case DRAFT = 'DRAFT';
    case ISSUED = 'ISSUED';
    case PARTIALLY_DISPENSED = 'PARTIALLY_DISPENSED';
    case DISPENSED = 'DISPENSED';
    case CANCELLED = 'CANCELLED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::ISSUED => 'Diterbitkan',
            self::PARTIALLY_DISPENSED => 'Sebagian Diserahkan',
            self::DISPENSED => 'Sudah Diserahkan',
            self::CANCELLED => 'Dibatalkan',
        };
    }


    /** Resep yang masih bisa diserahkan obatnya. */
    public function isDispensable(): bool
    {
        return in_array($this, [self::ISSUED, self::PARTIALLY_DISPENSED], true);
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
