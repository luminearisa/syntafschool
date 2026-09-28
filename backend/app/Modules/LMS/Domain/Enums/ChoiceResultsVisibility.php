<?php

namespace App\Modules\LMS\Domain\Enums;

/** Kapan hasil polling ditampilkan ke siswa (§38). */
enum ChoiceResultsVisibility: string
{
    case AFTER_RESPONSE = 'AFTER_RESPONSE';
    case AFTER_CLOSE = 'AFTER_CLOSE';
    case ALWAYS = 'ALWAYS';
    case NEVER = 'NEVER';

    public function label(): string
    {
        return match ($this) {
            self::AFTER_RESPONSE => 'Setelah menjawab',
            self::AFTER_CLOSE => 'Setelah ditutup',
            self::ALWAYS => 'Selalu',
            self::NEVER => 'Tidak pernah',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
