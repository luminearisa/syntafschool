<?php

namespace App\Modules\LMS\Domain\Enums;

/** Jenis halaman lesson (§37): materi biasa atau halaman bercabang. */
enum LessonPageType: string
{
    case CONTENT = 'CONTENT';
    case QUESTION = 'QUESTION';

    public function label(): string
    {
        return match ($this) {
            self::CONTENT => 'Materi',
            self::QUESTION => 'Pertanyaan bercabang',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
