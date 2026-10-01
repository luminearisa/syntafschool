<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum RequirementOperator — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum RequirementOperator: string
{
    case EQ = 'EQ';
    case NEQ = 'NEQ';
    case GT = 'GT';
    case GTE = 'GTE';
    case LT = 'LT';
    case LTE = 'LTE';
    case IN = 'IN';
    case NOT_IN = 'NOT_IN';
    case EXISTS = 'EXISTS';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::EQ => 'Sama Dengan',
            self::NEQ => 'Tidak Sama Dengan',
            self::GT => 'Lebih Dari',
            self::GTE => 'Minimal',
            self::LT => 'Kurang Dari',
            self::LTE => 'Maksimal',
            self::IN => 'Termasuk',
            self::NOT_IN => 'Tidak Termasuk',
            self::EXISTS => 'Harus Ada',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
