<?php

namespace App\Modules\LMS\Domain\Enums;

/** Cara siswa berpindah soal (§13). */
enum QuizNavigation: string
{
    case FREE = 'FREE';
    case SEQUENTIAL = 'SEQUENTIAL';

    public function label(): string
    {
        return match ($this) {
            self::FREE => 'Bebas',
            self::SEQUENTIAL => 'Berurutan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
