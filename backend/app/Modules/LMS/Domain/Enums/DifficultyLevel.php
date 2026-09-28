<?php

namespace App\Modules\LMS\Domain\Enums;

/** Tingkat kesulitan soal (§14) — dipakai statistik & pemilihan acak. */
enum DifficultyLevel: string
{
    case EASY = 'EASY';
    case MEDIUM = 'MEDIUM';
    case HARD = 'HARD';

    public function label(): string
    {
        return match ($this) {
            self::EASY => 'Mudah',
            self::MEDIUM => 'Sedang',
            self::HARD => 'Sulit',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
