<?php

namespace App\Modules\LMS\Domain\Enums;

/** Versi SCORM yang didukung arsitektur Phase 5 (§12). */
enum ScormVersion: string
{
    case SCORM_12 = 'SCORM_12';
    case SCORM_2004 = 'SCORM_2004';
    case UNKNOWN = 'UNKNOWN';

    public function label(): string
    {
        return match ($this) {
            self::SCORM_12 => 'SCORM 1.2',
            self::SCORM_2004 => 'SCORM 2004',
            self::UNKNOWN => 'Tidak dikenali',
        };
    }

    /** Elemen CMI yang dipakai untuk melacak (1.2 ↔ 2004 berbeda nama). */
    public function elements(): array
    {
        return match ($this) {
            self::SCORM_2004 => [
                'completion' => 'cmi.completion_status',
                'success' => 'cmi.success_status',
                'score' => 'cmi.score.raw',
                'score_min' => 'cmi.score.min',
                'score_max' => 'cmi.score.max',
                'score_scaled' => 'cmi.score.scaled',
                'time' => 'cmi.session_time',
                'total_time' => 'cmi.total_time',
                'location' => 'cmi.location',
                'suspend' => 'cmi.suspend_data',
                'entry' => 'cmi.entry',
            ],
            default => [
                'completion' => 'cmi.core.lesson_status',
                'success' => 'cmi.core.lesson_status',
                'score' => 'cmi.core.score.raw',
                'score_min' => 'cmi.core.score.min',
                'score_max' => 'cmi.core.score.max',
                'score_scaled' => null,
                'time' => 'cmi.core.session_time',
                'total_time' => 'cmi.core.total_time',
                'location' => 'cmi.core.lesson_location',
                'suspend' => 'cmi.suspend_data',
                'entry' => 'cmi.core.entry',
            ],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
