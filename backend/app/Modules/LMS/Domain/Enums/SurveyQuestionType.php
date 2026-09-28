<?php

namespace App\Modules\LMS\Domain\Enums;

/** Tipe pertanyaan survei (§39). */
enum SurveyQuestionType: string
{
    case MULTIPLE_CHOICE = 'MULTIPLE_CHOICE';
    case RATING = 'RATING';
    case TEXT = 'TEXT';
    case YES_NO = 'YES_NO';

    public function label(): string
    {
        return match ($this) {
            self::MULTIPLE_CHOICE => 'Pilihan ganda',
            self::RATING => 'Skala penilaian',
            self::TEXT => 'Teks bebas',
            self::YES_NO => 'Ya / Tidak',
        };
    }

    public function needsOptions(): bool
    {
        return $this === self::MULTIPLE_CHOICE;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
