<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum AttendanceSource — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum AttendanceSource: string
{
    case MANUAL = 'MANUAL';
    case QR = 'QR';
    case NFC = 'NFC';
    case RFID = 'RFID';
    case IMPORT = 'IMPORT';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::MANUAL => 'Manual',
            self::QR => 'QR',
            self::NFC => 'NFC',
            self::RFID => 'RFID',
            self::IMPORT => 'Impor',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
