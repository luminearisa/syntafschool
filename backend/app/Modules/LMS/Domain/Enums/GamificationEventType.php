<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Peristiwa yang menghasilkan XP/poin (§28). Bobot default dapat diatur admin
 * melalui pengaturan LMS; gamification selalu opsional.
 */
enum GamificationEventType: string
{
    case LESSON_COMPLETED = 'LESSON_COMPLETED';
    case ASSIGNMENT_COMPLETED = 'ASSIGNMENT_COMPLETED';
    case QUIZ_PASSED = 'QUIZ_PASSED';
    case COURSE_COMPLETED = 'COURSE_COMPLETED';
    case FORUM_CONTRIBUTION = 'FORUM_CONTRIBUTION';
    case LIVE_ATTENDED = 'LIVE_ATTENDED';
    case BADGE_AWARDED = 'BADGE_AWARDED';
    case STREAK_BONUS = 'STREAK_BONUS';

    public function label(): string
    {
        return match ($this) {
            self::LESSON_COMPLETED => 'Menyelesaikan materi',
            self::ASSIGNMENT_COMPLETED => 'Menyelesaikan tugas',
            self::QUIZ_PASSED => 'Lulus kuis',
            self::COURSE_COMPLETED => 'Menyelesaikan course',
            self::FORUM_CONTRIBUTION => 'Berkontribusi di forum',
            self::LIVE_ATTENDED => 'Mengikuti kelas langsung',
            self::BADGE_AWARDED => 'Mendapat badge',
            self::STREAK_BONUS => 'Bonus rentetan belajar',
        };
    }

    /** Bobot bawaan bila admin belum mengubah pengaturan. */
    public function defaultPoints(): int
    {
        return match ($this) {
            self::LESSON_COMPLETED => 10,
            self::ASSIGNMENT_COMPLETED => 20,
            self::QUIZ_PASSED => 25,
            self::COURSE_COMPLETED => 100,
            self::FORUM_CONTRIBUTION => 5,
            self::LIVE_ATTENDED => 15,
            self::BADGE_AWARDED => 10,
            self::STREAK_BONUS => 5,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
