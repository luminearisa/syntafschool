<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Peristiwa pemberitahuan LMS (§10).
 *
 * Satu enum untuk seluruh peristiwa supaya pengaturan kanal cukup diubah pada
 * `lms.notifications.events` tanpa menyentuh service.
 */
enum NotificationEvent: string
{
    case COURSE_ENROLLED = 'COURSE_ENROLLED';
    case COURSE_STARTED = 'COURSE_STARTED';
    case ACTIVITY_AVAILABLE = 'ACTIVITY_AVAILABLE';
    case ACTIVITY_DUE_SOON = 'ACTIVITY_DUE_SOON';
    case ACTIVITY_OVERDUE = 'ACTIVITY_OVERDUE';
    case ASSIGNMENT_SUBMITTED = 'ASSIGNMENT_SUBMITTED';
    case ASSIGNMENT_GRADED = 'ASSIGNMENT_GRADED';
    case QUIZ_AVAILABLE = 'QUIZ_AVAILABLE';
    case QUIZ_COMPLETED = 'QUIZ_COMPLETED';
    case GRADE_RELEASED = 'GRADE_RELEASED';
    case FORUM_REPLY = 'FORUM_REPLY';
    case LIVE_SESSION_UPCOMING = 'LIVE_SESSION_UPCOMING';
    case LIVE_SESSION_STARTED = 'LIVE_SESSION_STARTED';
    case COURSE_COMPLETED = 'COURSE_COMPLETED';
    case CERTIFICATE_ISSUED = 'CERTIFICATE_ISSUED';
    case BADGE_EARNED = 'BADGE_EARNED';
    case LEARNING_AT_RISK = 'LEARNING_AT_RISK';

    public function label(): string
    {
        return match ($this) {
            self::COURSE_ENROLLED => 'Pendaftaran course',
            self::COURSE_STARTED => 'Course dimulai',
            self::ACTIVITY_AVAILABLE => 'Aktivitas tersedia',
            self::ACTIVITY_DUE_SOON => 'Tenggat dekat',
            self::ACTIVITY_OVERDUE => 'Tenggat terlewat',
            self::ASSIGNMENT_SUBMITTED => 'Tugas dikumpulkan',
            self::ASSIGNMENT_GRADED => 'Tugas dinilai',
            self::QUIZ_AVAILABLE => 'Kuis tersedia',
            self::QUIZ_COMPLETED => 'Kuis selesai',
            self::GRADE_RELEASED => 'Nilai dirilis',
            self::FORUM_REPLY => 'Balasan forum',
            self::LIVE_SESSION_UPCOMING => 'Kelas langsung akan mulai',
            self::LIVE_SESSION_STARTED => 'Kelas langsung dimulai',
            self::COURSE_COMPLETED => 'Course selesai',
            self::CERTIFICATE_ISSUED => 'Sertifikat terbit',
            self::BADGE_EARNED => 'Badge diperoleh',
            self::LEARNING_AT_RISK => 'Perlu perhatian belajar',
        };
    }

    /** Kanal bawaan bila pengaturan tidak menentukan lain (§10). */
    public function defaultChannels(): array
    {
        return ['in_app'];
    }

    /** Penerima bawaan: siswa, guru, atau wali. */
    public function audience(): string
    {
        return match ($this) {
            self::ASSIGNMENT_SUBMITTED => 'teacher',
            self::LEARNING_AT_RISK, self::ACTIVITY_OVERDUE => 'guardian',
            default => 'student',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
