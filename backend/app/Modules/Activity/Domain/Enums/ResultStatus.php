<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum ResultStatus — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum ResultStatus: string
{
    case DRAFT = 'DRAFT';
    case VERIFIED = 'VERIFIED';
    case PUBLISHED = 'PUBLISHED';
    case DISPUTED = 'DISPUTED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::VERIFIED => 'Terverifikasi',
            self::PUBLISHED => 'Terbit',
            self::DISPUTED => 'Sengketa',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
