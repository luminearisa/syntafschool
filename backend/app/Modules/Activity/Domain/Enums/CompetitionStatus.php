<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum CompetitionStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum CompetitionStatus: string
{
    case DRAFT = 'DRAFT';
    case REGISTRATION_OPEN = 'REGISTRATION_OPEN';
    case REGISTRATION_CLOSED = 'REGISTRATION_CLOSED';
    case ONGOING = 'ONGOING';
    case COMPLETED = 'COMPLETED';
    case PUBLISHED = 'PUBLISHED';
    case CANCELLED = 'CANCELLED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::REGISTRATION_OPEN => 'Pendaftaran Dibuka',
            self::REGISTRATION_CLOSED => 'Pendaftaran Ditutup',
            self::ONGOING => 'Berlangsung',
            self::COMPLETED => 'Selesai',
            self::PUBLISHED => 'Hasil Terbit',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isRegistrationOpen(): bool
    {
        return $this === self::REGISTRATION_OPEN;
    }
}
