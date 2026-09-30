<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum FollowUpStatus — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum FollowUpStatus: string
{
    case PENDING = 'PENDING';
    case SCHEDULED = 'SCHEDULED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case OVERDUE = 'OVERDUE';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu',
            self::SCHEDULED => 'Terjadwal',
            self::COMPLETED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
            self::OVERDUE => 'Terlambat',
        };
    }


    public function isOpen(): bool
    {
        return in_array($this, [self::PENDING, self::SCHEDULED, self::OVERDUE], true);
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
