<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Asal sebuah grade item (§3). MANUAL tersedia agar guru tetap dapat menilai
 * hal yang tidak punya aktivitas LMS (mis. ujian tulis, praktik, sikap).
 */
enum GradeItemSource: string
{
    case ASSIGNMENT = 'ASSIGNMENT';
    case QUIZ = 'QUIZ';
    case FORUM = 'FORUM';
    case LESSON = 'LESSON';
    case MANUAL = 'MANUAL';
    case EXTERNAL = 'EXTERNAL';

    public function label(): string
    {
        return match ($this) {
            self::ASSIGNMENT => 'Tugas',
            self::QUIZ => 'Kuis',
            self::FORUM => 'Forum',
            self::LESSON => 'Lesson',
            self::MANUAL => 'Manual',
            self::EXTERNAL => 'Eksternal',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::ASSIGNMENT => 'brand',
            self::QUIZ => 'blue',
            self::FORUM => 'green',
            self::LESSON => 'amber',
            self::MANUAL => 'gray',
            self::EXTERNAL => 'red',
        };
    }

    /** Punya aktivitas LMS sebagai evidence? */
    public function hasEvidence(): bool
    {
        return $this !== self::MANUAL;
    }

    public function icon(): string
    {
        return match ($this) {
            self::ASSIGNMENT => 'clipboard',
            self::QUIZ => 'help',
            self::FORUM => 'message',
            self::LESSON => 'book',
            self::MANUAL => 'pencil',
            self::EXTERNAL => 'link',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
