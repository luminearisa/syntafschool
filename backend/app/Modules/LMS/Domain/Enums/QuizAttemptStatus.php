<?php

namespace App\Modules\LMS\Domain\Enums;

/** Status percobaan kuis (§25). */
enum QuizAttemptStatus: string
{
    case IN_PROGRESS = 'IN_PROGRESS';
    case SUBMITTED = 'SUBMITTED';
    case AUTO_GRADED = 'AUTO_GRADED';
    case MANUAL_GRADING = 'MANUAL_GRADING';
    case GRADED = 'GRADED';
    case ABANDONED = 'ABANDONED';
    case EXPIRED = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::IN_PROGRESS => 'Sedang dikerjakan',
            self::SUBMITTED => 'Terkirim',
            self::AUTO_GRADED => 'Dinilai otomatis',
            self::MANUAL_GRADING => 'Menunggu penilaian guru',
            self::GRADED => 'Selesai dinilai',
            self::ABANDONED => 'Ditinggalkan',
            self::EXPIRED => 'Waktu habis',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::IN_PROGRESS => 'blue',
            self::SUBMITTED, self::AUTO_GRADED => 'green',
            self::MANUAL_GRADING => 'amber',
            self::GRADED => 'green',
            self::ABANDONED, self::EXPIRED => 'red',
        };
    }

    public function isFinished(): bool
    {
        return $this !== self::IN_PROGRESS;
    }

    /** Sudah final (tidak dapat diubah lagi). */
    public function isFinal(): bool
    {
        return in_array($this, [self::AUTO_GRADED, self::GRADED, self::EXPIRED, self::ABANDONED], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
