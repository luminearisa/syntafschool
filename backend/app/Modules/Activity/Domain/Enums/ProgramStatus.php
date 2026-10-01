<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum ProgramStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum ProgramStatus: string
{
    case DRAFT = 'DRAFT';
    case PUBLISHED = 'PUBLISHED';
    case ACTIVE = 'ACTIVE';
    case CLOSED = 'CLOSED';
    case ARCHIVED = 'ARCHIVED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::PUBLISHED => 'Terbit',
            self::ACTIVE => 'Berjalan',
            self::CLOSED => 'Ditutup',
            self::ARCHIVED => 'Diarsipkan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
