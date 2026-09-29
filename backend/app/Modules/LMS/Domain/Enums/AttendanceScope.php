<?php

namespace App\Modules\LMS\Domain\Enums;

/**
 * Jenis kehadiran yang **tidak boleh dicampur** (§9):
 * akademik (SIAKAD), kehadiran belajar LMS, dan kehadiran kelas langsung.
 */
enum AttendanceScope: string
{
    case ACADEMIC = 'ACADEMIC';
    case LEARNING = 'LEARNING';
    case LIVE_SESSION = 'LIVE_SESSION';

    public function label(): string
    {
        return match ($this) {
            self::ACADEMIC => 'Kehadiran akademik',
            self::LEARNING => 'Kehadiran belajar LMS',
            self::LIVE_SESSION => 'Kehadiran kelas langsung',
        };
    }

    /** Nama tabel sumber — dikembalikan agar laporan menyebut asalnya. */
    public function sourceTable(): string
    {
        return match ($this) {
            self::ACADEMIC => 'student_attendances',
            self::LEARNING => 'lms_activity_completions',
            self::LIVE_SESSION => 'lms_live_session_attendance',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
