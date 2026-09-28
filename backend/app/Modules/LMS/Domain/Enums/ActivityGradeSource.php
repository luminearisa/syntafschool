<?php

namespace App\Modules\LMS\Domain\Enums;

/** Asal catatan nilai aktivitas (§46–47) — dikonsumsi Gradebook Phase 3. */
enum ActivityGradeSource: string
{
    case ASSIGNMENT = 'ASSIGNMENT';
    case QUIZ = 'QUIZ';
    case FORUM = 'FORUM';
    case LESSON = 'LESSON';
    case CHOICE = 'CHOICE';
    case SURVEY = 'SURVEY';
    case MANUAL = 'MANUAL';

    public function label(): string
    {
        return match ($this) {
            self::ASSIGNMENT => 'Tugas',
            self::QUIZ => 'Kuis',
            self::FORUM => 'Forum',
            self::LESSON => 'Pelajaran',
            self::CHOICE => 'Pilihan',
            self::SURVEY => 'Survei',
            self::MANUAL => 'Manual',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
