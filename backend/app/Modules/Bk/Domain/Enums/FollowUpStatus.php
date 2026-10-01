<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Status tindak lanjut.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum FollowUpStatus: string
{
    case PENDING = 'PENDING';
    case COMPLETED = 'COMPLETED';
    case MISSED = 'MISSED';
    case RESCHEDULED = 'RESCHEDULED';
    case CANCELLED = 'CANCELLED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu',
            self::COMPLETED => 'Selesai',
            self::MISSED => 'Terlewat',
            self::RESCHEDULED => 'Dijadwalkan ulang',
            self::CANCELLED => 'Dibatalkan',
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

    /** Tindak lanjut yang masih menuntut perhatian (dasar widget \"terlambat\"). */
    /** Tindak lanjut yang sudah tuntas (selesai atau tidak relevan lagi). */
    public function isCompleted(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED], true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::PENDING, self::MISSED, self::RESCHEDULED], true);
    }
}
