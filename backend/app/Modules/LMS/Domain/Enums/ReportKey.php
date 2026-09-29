<?php

namespace App\Modules\LMS\Domain\Enums;

/** Jenis laporan LMS (§22). */
enum ReportKey: string
{
    case COURSE = 'COURSE';
    case ENROLLMENT = 'ENROLLMENT';
    case COMPLETION = 'COMPLETION';
    case ASSIGNMENT = 'ASSIGNMENT';
    case QUIZ = 'QUIZ';
    case GRADE = 'GRADE';
    case ATTENDANCE = 'ATTENDANCE';
    case COMPETENCY = 'COMPETENCY';
    case OUTCOME = 'OUTCOME';
    case CERTIFICATE = 'CERTIFICATE';
    case BADGE = 'BADGE';
    case STUDENT_PROGRESS = 'STUDENT_PROGRESS';
    case TEACHER_ACTIVITY = 'TEACHER_ACTIVITY';
    case AT_RISK = 'AT_RISK';
    case ENGAGEMENT = 'ENGAGEMENT';

    public function label(): string
    {
        return match ($this) {
            self::COURSE => 'Laporan course',
            self::ENROLLMENT => 'Laporan pendaftaran',
            self::COMPLETION => 'Laporan ketuntasan',
            self::ASSIGNMENT => 'Laporan tugas',
            self::QUIZ => 'Laporan kuis',
            self::GRADE => 'Laporan nilai',
            self::ATTENDANCE => 'Laporan kehadiran',
            self::COMPETENCY => 'Laporan kompetensi',
            self::OUTCOME => 'Laporan capaian pembelajaran',
            self::CERTIFICATE => 'Laporan sertifikat',
            self::BADGE => 'Laporan badge',
            self::STUDENT_PROGRESS => 'Laporan progres siswa',
            self::TEACHER_ACTIVITY => 'Laporan aktivitas guru',
            self::AT_RISK => 'Laporan siswa berisiko',
            self::ENGAGEMENT => 'Laporan keterlibatan',
        };
    }

    /** Kolom yang diekspor (dipakai ekspor CSV/XLSX). */
    public function columns(): array
    {
        return match ($this) {
            self::COURSE => ['course', 'code', 'teacher', 'status', 'students', 'sections', 'activities', 'completion_rate'],
            self::ENROLLMENT => ['course', 'student', 'class', 'status', 'role', 'enrolled_at', 'progress_percent'],
            self::COMPLETION => ['course', 'student', 'progress_percent', 'completed_items', 'required_items', 'completed_at', 'status'],
            self::ASSIGNMENT => ['course', 'activity', 'student', 'submitted_at', 'status', 'late', 'grade'],
            self::QUIZ => ['course', 'quiz', 'student', 'attempts', 'best_score', 'passed', 'last_attempt_at'],
            self::GRADE => ['course', 'student', 'final_grade', 'letter', 'released_at', 'sync_status'],
            self::ATTENDANCE => ['course', 'student', 'live_sessions', 'attended', 'attendance_rate', 'academic_rate'],
            self::COMPETENCY => ['student', 'competency', 'course', 'status', 'evidence', 'assessed_at'],
            self::OUTCOME => ['course', 'outcome', 'student', 'achieved', 'score', 'assessed_at'],
            self::CERTIFICATE => ['number', 'student', 'course', 'issued_at', 'status', 'verified_count'],
            self::BADGE => ['badge', 'student', 'course', 'source', 'awarded_at', 'points'],
            self::STUDENT_PROGRESS => ['student', 'courses', 'average_progress', 'completed_courses', 'xp', 'streak', 'health'],
            self::TEACHER_ACTIVITY => ['teacher', 'courses', 'activities', 'graded', 'feedback_given', 'last_activity_at'],
            self::AT_RISK => ['student', 'course', 'status', 'reason', 'last_activity_at', 'overdue_count', 'progress_percent'],
            self::ENGAGEMENT => ['course', 'events', 'active_students', 'forum_posts', 'live_attendance', 'average_session_minutes'],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
