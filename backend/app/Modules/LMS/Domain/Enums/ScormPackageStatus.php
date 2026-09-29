<?php

namespace App\Modules\LMS\Domain\Enums;

/** Siklus paket SCORM (§12: import → validate → extract → create activity). */
enum ScormPackageStatus: string
{
    case UPLOADED = 'UPLOADED';
    case VALIDATED = 'VALIDATED';
    case INVALID = 'INVALID';
    case IMPORTED = 'IMPORTED';
    case ARCHIVED = 'ARCHIVED';

    public function label(): string
    {
        return match ($this) {
            self::UPLOADED => 'Diunggah',
            self::VALIDATED => 'Tervalidasi',
            self::INVALID => 'Tidak valid',
            self::IMPORTED => 'Terpasang',
            self::ARCHIVED => 'Diarsipkan',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::IMPORTED => 'green',
            self::VALIDATED => 'blue',
            self::UPLOADED => 'amber',
            self::INVALID => 'red',
            self::ARCHIVED => 'gray',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
