<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Tahapan impor course (§14):
 * UPLOAD → VALIDATE → PREVIEW → RESOLVE CONFLICT → IMPORT → VERIFY.
 */
enum ImportStatus: string
{
    case UPLOADED = 'UPLOADED';
    case VALIDATED = 'VALIDATED';
    case INVALID = 'INVALID';
    case IMPORTING = 'IMPORTING';
    case IMPORTED = 'IMPORTED';
    case FAILED = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::UPLOADED => 'Diunggah',
            self::VALIDATED => 'Siap diimpor',
            self::INVALID => 'Tidak valid',
            self::IMPORTING => 'Sedang diimpor',
            self::IMPORTED => 'Selesai',
            self::FAILED => 'Gagal',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::IMPORTED => 'green',
            self::VALIDATED => 'blue',
            self::UPLOADED, self::IMPORTING => 'amber',
            self::INVALID, self::FAILED => 'red',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
