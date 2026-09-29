<?php

namespace App\Modules\Supervisi\Domain\Enums;

/** Warna tingkat ketercapaian untuk tampilan. */
enum GradeTone: string
{
    case GREEN = 'GREEN';
    case AMBER = 'AMBER';
    case RED = 'RED';
    case GRAY = 'GRAY';

    public function label(): string
    {
        return match ($this) {
            self::GREEN => 'Baik',
            self::AMBER => 'Perlu perhatian',
            self::RED => 'Kurang',
            self::GRAY => 'Tidak dinilai',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
