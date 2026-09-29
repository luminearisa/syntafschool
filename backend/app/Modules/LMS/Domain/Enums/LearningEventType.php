<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Peristiwa belajar ringan untuk engagement & analytics (§38, §58–§60).
 *
 * Ini bukan platform analitik: hanya satu tabel peristiwa dengan agregasi
 * sederhana, sehingga dapat diganti penyimpanan lain tanpa mengubah pemanggil.
 */
enum LearningEventType: string
{
    case COURSE_VIEW = 'course_view';
    case ACTIVITY_VIEW = 'activity_view';
    case RESOURCE_DOWNLOAD = 'resource_download';
    case VIDEO_STARTED = 'video_started';
    case VIDEO_COMPLETED = 'video_completed';
    case ASSIGNMENT_STARTED = 'assignment_started';
    case ASSIGNMENT_SUBMITTED = 'assignment_submitted';
    case QUIZ_STARTED = 'quiz_started';
    case QUIZ_SUBMITTED = 'quiz_submitted';
    case FORUM_POST = 'forum_post';
    case FORUM_REPLY = 'forum_reply';
    case LIVE_SESSION_JOINED = 'live_session_joined';
    case INTERACTIVE_STARTED = 'interactive_started';
    case INTERACTIVE_COMPLETED = 'interactive_completed';
    case CERTIFICATE_ISSUED = 'certificate_issued';
    case BADGE_AWARDED = 'badge_awarded';
    case COURSE_COMPLETED = 'course_completed';
    case SCORM_STARTED = 'scorm_started';
    case SCORM_COMPLETED = 'scorm_completed';

    public function label(): string
    {
        return match ($this) {
            self::COURSE_VIEW => 'Membuka course',
            self::ACTIVITY_VIEW => 'Membuka materi',
            self::RESOURCE_DOWNLOAD => 'Mengunduh berkas',
            self::VIDEO_STARTED => 'Memulai video',
            self::VIDEO_COMPLETED => 'Menonton video selesai',
            self::ASSIGNMENT_STARTED => 'Memulai tugas',
            self::ASSIGNMENT_SUBMITTED => 'Mengumpulkan tugas',
            self::QUIZ_STARTED => 'Memulai kuis',
            self::QUIZ_SUBMITTED => 'Mengumpulkan kuis',
            self::FORUM_POST => 'Menulis di forum',
            self::FORUM_REPLY => 'Membalas forum',
            self::LIVE_SESSION_JOINED => 'Mengikuti kelas langsung',
            self::INTERACTIVE_STARTED => 'Memulai konten interaktif',
            self::INTERACTIVE_COMPLETED => 'Menyelesaikan konten interaktif',
            self::CERTIFICATE_ISSUED => 'Sertifikat terbit',
            self::BADGE_AWARDED => 'Badge diperoleh',
            self::COURSE_COMPLETED => 'Course selesai',
            self::SCORM_STARTED => 'Memulai paket SCORM',
            self::SCORM_COMPLETED => 'Menyelesaikan paket SCORM',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
