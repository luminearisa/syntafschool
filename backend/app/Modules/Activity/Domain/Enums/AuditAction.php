<?php

namespace App\Modules\Activity\Domain\Enums;

/**
 * Enum AuditAction — nilai tersimpan sebagai `string` UPPER_SNAKE_CASE.
 *
 * Disimpan sebagai string di basis data supaya penambahan kasus baru tidak
 * menuntut ALTER TABLE; keabsahan nilai ditegakkan aturan validasi yang membaca
 * enum ini.
 */
enum AuditAction: string
{
    case PROGRAM_CREATED = 'PROGRAM_CREATED';
    case PROGRAM_UPDATED = 'PROGRAM_UPDATED';
    case PROGRAM_PUBLISHED = 'PROGRAM_PUBLISHED';
    case PROGRAM_ARCHIVED = 'PROGRAM_ARCHIVED';
    case REGISTRATION_SUBMITTED = 'REGISTRATION_SUBMITTED';
    case REGISTRATION_APPROVED = 'REGISTRATION_APPROVED';
    case REGISTRATION_REJECTED = 'REGISTRATION_REJECTED';
    case REGISTRATION_WAITLISTED = 'REGISTRATION_WAITLISTED';
    case WAITLIST_PROMOTED = 'WAITLIST_PROMOTED';
    case CONSENT_DECIDED = 'CONSENT_DECIDED';
    case MEMBERSHIP_CREATED = 'MEMBERSHIP_CREATED';
    case MEMBERSHIP_SUSPENDED = 'MEMBERSHIP_SUSPENDED';
    case MEMBERSHIP_REACTIVATED = 'MEMBERSHIP_REACTIVATED';
    case MEMBERSHIP_ROLE_CHANGED = 'MEMBERSHIP_ROLE_CHANGED';
    case MEMBERSHIP_WITHDRAWN = 'MEMBERSHIP_WITHDRAWN';
    case TRANSFER_APPROVED = 'TRANSFER_APPROVED';
    case SCHEDULE_CREATED = 'SCHEDULE_CREATED';
    case SCHEDULE_CHANGED = 'SCHEDULE_CHANGED';
    case SESSION_CREATED = 'SESSION_CREATED';
    case SESSION_CANCELLED = 'SESSION_CANCELLED';
    case ATTENDANCE_RECORDED = 'ATTENDANCE_RECORDED';
    case ASSESSMENT_SUBMITTED = 'ASSESSMENT_SUBMITTED';
    case ASSESSMENT_PUBLISHED = 'ASSESSMENT_PUBLISHED';
    case COMPLETION_VERIFIED = 'COMPLETION_VERIFIED';
    case COMPETITION_CREATED = 'COMPETITION_CREATED';
    case COMPETITION_SCORE_SUBMITTED = 'COMPETITION_SCORE_SUBMITTED';
    case COMPETITION_SCORE_LOCKED = 'COMPETITION_SCORE_LOCKED';
    case COMPETITION_SCORE_CORRECTED = 'COMPETITION_SCORE_CORRECTED';
    case RESULT_PUBLISHED = 'RESULT_PUBLISHED';
    case ACHIEVEMENT_CREATED = 'ACHIEVEMENT_CREATED';
    case ACHIEVEMENT_VERIFIED = 'ACHIEVEMENT_VERIFIED';
    case CERTIFICATE_ISSUED = 'CERTIFICATE_ISSUED';
    case CERTIFICATE_REVOKED = 'CERTIFICATE_REVOKED';
    case PORTFOLIO_GENERATED = 'PORTFOLIO_GENERATED';
    case PORTFOLIO_SHARED = 'PORTFOLIO_SHARED';
    case CHARGE_REQUESTED = 'CHARGE_REQUESTED';
    case EQUIPMENT_REQUESTED = 'EQUIPMENT_REQUESTED';
    case EQUIPMENT_ISSUED = 'EQUIPMENT_ISSUED';
    case FACILITY_REQUESTED = 'FACILITY_REQUESTED';
    case INTEGRATION_CALLED = 'INTEGRATION_CALLED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::PROGRAM_CREATED => 'Program Dibuat',
            self::PROGRAM_UPDATED => 'Program Diubah',
            self::PROGRAM_PUBLISHED => 'Program Diterbitkan',
            self::PROGRAM_ARCHIVED => 'Program Diarsipkan',
            self::REGISTRATION_SUBMITTED => 'Pendaftaran Diajukan',
            self::REGISTRATION_APPROVED => 'Pendaftaran Diterima',
            self::REGISTRATION_REJECTED => 'Pendaftaran Ditolak',
            self::REGISTRATION_WAITLISTED => 'Masuk Daftar Tunggu',
            self::WAITLIST_PROMOTED => 'Daftar Tunggu Dipromosikan',
            self::CONSENT_DECIDED => 'Persetujuan Diputuskan',
            self::MEMBERSHIP_CREATED => 'Keanggotaan Dibuat',
            self::MEMBERSHIP_SUSPENDED => 'Keanggotaan Ditangguhkan',
            self::MEMBERSHIP_REACTIVATED => 'Keanggotaan Diaktifkan',
            self::MEMBERSHIP_ROLE_CHANGED => 'Peran Anggota Diubah',
            self::MEMBERSHIP_WITHDRAWN => 'Keanggotaan Diakhiri',
            self::TRANSFER_APPROVED => 'Pindah Aktivitas Disetujui',
            self::SCHEDULE_CREATED => 'Jadwal Dibuat',
            self::SCHEDULE_CHANGED => 'Jadwal Diubah',
            self::SESSION_CREATED => 'Sesi Dibuat',
            self::SESSION_CANCELLED => 'Sesi Dibatalkan',
            self::ATTENDANCE_RECORDED => 'Kehadiran Dicatat',
            self::ASSESSMENT_SUBMITTED => 'Penilaian Dikirim',
            self::ASSESSMENT_PUBLISHED => 'Penilaian Diterbitkan',
            self::COMPLETION_VERIFIED => 'Penyelesaian Diverifikasi',
            self::COMPETITION_CREATED => 'Kompetisi Dibuat',
            self::COMPETITION_SCORE_SUBMITTED => 'Skor Dikirim',
            self::COMPETITION_SCORE_LOCKED => 'Skor Dikunci',
            self::COMPETITION_SCORE_CORRECTED => 'Skor Dikoreksi',
            self::RESULT_PUBLISHED => 'Hasil Diterbitkan',
            self::ACHIEVEMENT_CREATED => 'Prestasi Dicatat',
            self::ACHIEVEMENT_VERIFIED => 'Prestasi Diverifikasi',
            self::CERTIFICATE_ISSUED => 'Sertifikat Diterbitkan',
            self::CERTIFICATE_REVOKED => 'Sertifikat Dicabut',
            self::PORTFOLIO_GENERATED => 'Portofolio Dibuat',
            self::PORTFOLIO_SHARED => 'Portofolio Dibagikan',
            self::CHARGE_REQUESTED => 'Tagihan Diminta',
            self::EQUIPMENT_REQUESTED => 'Permintaan Peralatan',
            self::EQUIPMENT_ISSUED => 'Peralatan Diserahkan',
            self::FACILITY_REQUESTED => 'Peminjaman Fasilitas Diminta',
            self::INTEGRATION_CALLED => 'Integrasi Dipanggil',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
