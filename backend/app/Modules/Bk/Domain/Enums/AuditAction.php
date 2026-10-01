<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Aksi yang dicatat pada jejak audit BK \u2014 append-only.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum AuditAction: string
{
    case VIEW = 'VIEW';
    case VIEW_CONFIDENTIAL = 'VIEW_CONFIDENTIAL';
    case CREATE = 'CREATE';
    case UPDATE = 'UPDATE';
    case DELETE = 'DELETE';
    case ASSIGN = 'ASSIGN';
    case REASSIGN = 'REASSIGN';
    case SCREEN = 'SCREEN';
    case SESSION_RECORD = 'SESSION_RECORD';
    case NOTE_ADD = 'NOTE_ADD';
    case EXPORT = 'EXPORT';
    case SHARE = 'SHARE';
    case CLOSE = 'CLOSE';
    case REOPEN = 'REOPEN';
    case ESCALATE = 'ESCALATE';
    case DOCUMENT_ADD = 'DOCUMENT_ADD';
    case DOCUMENT_VIEW = 'DOCUMENT_VIEW';
    case CONSENT_DECIDE = 'CONSENT_DECIDE';
    case REQUEST_SUBMIT = 'REQUEST_SUBMIT';
    case CASE_VIEW = 'CASE_VIEW';
    case CASE_CREATE = 'CASE_CREATE';
    case CASE_UPDATE = 'CASE_UPDATE';
    case CASE_ASSIGN = 'CASE_ASSIGN';
    case CASE_CLOSE = 'CASE_CLOSE';
    case CASE_REOPEN = 'CASE_REOPEN';
    case NOTE_VIEW = 'NOTE_VIEW';
    case NOTE_CREATE = 'NOTE_CREATE';
    case NOTE_UPDATE = 'NOTE_UPDATE';
    case SESSION_VIEW = 'SESSION_VIEW';
    case SESSION_CREATE = 'SESSION_CREATE';
    case ASSESSMENT_VIEW = 'ASSESSMENT_VIEW';
    case ASSESSMENT_CREATE = 'ASSESSMENT_CREATE';
    case DOCUMENT_DOWNLOAD = 'DOCUMENT_DOWNLOAD';
    case CONSENT_GRANT = 'CONSENT_GRANT';
    case CONSENT_REVOKE = 'CONSENT_REVOKE';
    case EXPORT_CREATE = 'EXPORT_CREATE';
    case REFERRAL_CREATE = 'REFERRAL_CREATE';
    case REFERRAL_VIEW = 'REFERRAL_VIEW';
    case CONFERENCE_VIEW = 'CONFERENCE_VIEW';
    case CONFERENCE_CREATE = 'CONFERENCE_CREATE';
    case EXTERNAL_REFERRAL_CREATE = 'EXTERNAL_REFERRAL_CREATE';
    case EXTERNAL_REFERRAL_VIEW = 'EXTERNAL_REFERRAL_VIEW';
    case RISK_VIEW = 'RISK_VIEW';
    case RISK_UPDATE = 'RISK_UPDATE';
    case DEVELOPMENT_VIEW = 'DEVELOPMENT_VIEW';
    case DEVELOPMENT_UPDATE = 'DEVELOPMENT_UPDATE';
    case CAREER_VIEW = 'CAREER_VIEW';
    case CAREER_UPDATE = 'CAREER_UPDATE';
    case PROGRAM_VIEW = 'PROGRAM_VIEW';
    case PROGRAM_UPDATE = 'PROGRAM_UPDATE';
    case FEEDBACK_SUBMIT = 'FEEDBACK_SUBMIT';
    case FEEDBACK_VIEW = 'FEEDBACK_VIEW';
    case SLA_ESCALATE = 'SLA_ESCALATE';
    case RETENTION_ARCHIVE = 'RETENTION_ARCHIVE';
    case RETENTION_DISPOSE = 'RETENTION_DISPOSE';
    case RETENTION_APPROVE = 'RETENTION_APPROVE';
    case SEARCH = 'SEARCH';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::VIEW => 'Melihat',
            self::VIEW_CONFIDENTIAL => 'Melihat data rahasia',
            self::CREATE => 'Membuat',
            self::UPDATE => 'Mengubah',
            self::DELETE => 'Menghapus',
            self::ASSIGN => 'Menugaskan',
            self::REASSIGN => 'Mengganti penugasan',
            self::SCREEN => 'Screening',
            self::SESSION_RECORD => 'Mencatat sesi',
            self::NOTE_ADD => 'Menambah catatan',
            self::EXPORT => 'Ekspor',
            self::SHARE => 'Berbagi',
            self::CLOSE => 'Menutup kasus',
            self::REOPEN => 'Membuka kembali',
            self::ESCALATE => 'Eskalasi',
            self::DOCUMENT_ADD => 'Menambah dokumen',
            self::DOCUMENT_VIEW => 'Melihat dokumen',
            self::CONSENT_DECIDE => 'Memutuskan persetujuan',
            self::REQUEST_SUBMIT => 'Mengajukan permintaan',
            self::CASE_VIEW => 'Melihat kasus',
            self::CASE_CREATE => 'Membuat kasus',
            self::CASE_UPDATE => 'Mengubah kasus',
            self::CASE_ASSIGN => 'Menugaskan kasus',
            self::CASE_CLOSE => 'Menutup kasus',
            self::CASE_REOPEN => 'Membuka kembali kasus',
            self::NOTE_VIEW => 'Melihat catatan',
            self::NOTE_CREATE => 'Membuat catatan',
            self::NOTE_UPDATE => 'Mengubah catatan',
            self::SESSION_VIEW => 'Melihat sesi',
            self::SESSION_CREATE => 'Membuat sesi',
            self::ASSESSMENT_VIEW => 'Melihat asesmen',
            self::ASSESSMENT_CREATE => 'Membuat asesmen',
            self::DOCUMENT_DOWNLOAD => 'Mengunduh dokumen',
            self::CONSENT_GRANT => 'Memberikan persetujuan',
            self::CONSENT_REVOKE => 'Mencabut persetujuan',
            self::EXPORT_CREATE => 'Membuat ekspor',
            self::REFERRAL_CREATE => 'Membuat rujukan',
            self::REFERRAL_VIEW => 'Melihat rujukan',
            self::CONFERENCE_VIEW => 'Melihat konferensi kasus',
            self::CONFERENCE_CREATE => 'Membuat konferensi kasus',
            self::EXTERNAL_REFERRAL_CREATE => 'Membuat rujukan eksternal',
            self::EXTERNAL_REFERRAL_VIEW => 'Melihat rujukan eksternal',
            self::RISK_VIEW => 'Melihat penanda risiko',
            self::RISK_UPDATE => 'Mengubah penanda risiko',
            self::DEVELOPMENT_VIEW => 'Melihat rencana pengembangan',
            self::DEVELOPMENT_UPDATE => 'Mengubah rencana pengembangan',
            self::CAREER_VIEW => 'Melihat profil karier',
            self::CAREER_UPDATE => 'Mengubah profil karier',
            self::PROGRAM_VIEW => 'Melihat program',
            self::PROGRAM_UPDATE => 'Mengubah program',
            self::FEEDBACK_SUBMIT => 'Mengirim umpan balik',
            self::FEEDBACK_VIEW => 'Melihat umpan balik',
            self::SLA_ESCALATE => 'Eskalasi SLA',
            self::RETENTION_ARCHIVE => 'Mengarsipkan data',
            self::RETENTION_DISPOSE => 'Memusnahkan data',
            self::RETENTION_APPROVE => 'Menyetujui pemusnahan',
            self::SEARCH => 'Pencarian',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
