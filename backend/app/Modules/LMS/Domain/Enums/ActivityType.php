<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Jenis aktivitas (§12). Phase 1 menyimpan konfigurasinya dan menautkannya ke
 * aktivitas ERP yang sudah ada bila tersedia (`assignment_id`); implementasi
 * penuh menyusul pada phase berikutnya.
 */
enum ActivityType: string
{
    case ASSIGNMENT = 'ASSIGNMENT';
    case QUIZ = 'QUIZ';
    case FORUM = 'FORUM';
    case LESSON = 'LESSON';
    case SURVEY = 'SURVEY';
    case CHOICE = 'CHOICE';
    case WORKSHOP = 'WORKSHOP';

    public function label(): string
    {
        return match ($this) {
            self::ASSIGNMENT => 'Tugas',
            self::QUIZ => 'Kuis',
            self::FORUM => 'Diskusi',
            self::LESSON => 'Pelajaran',
            self::SURVEY => 'Survei',
            self::CHOICE => 'Pilihan',
            self::WORKSHOP => 'Workshop',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::ASSIGNMENT => 'clipboard-list',
            self::QUIZ => 'help-circle',
            self::FORUM => 'messages-square',
            self::LESSON => 'book-marked',
            self::SURVEY => 'list-checks',
            self::CHOICE => 'toggle-left',
            self::WORKSHOP => 'users',
        };
    }

    /** Aktivitas yang punya nilai/umpan balik (§3, §27). */
    public function isGraded(): bool
    {
        return in_array($this, [self::ASSIGNMENT, self::QUIZ, self::WORKSHOP], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
