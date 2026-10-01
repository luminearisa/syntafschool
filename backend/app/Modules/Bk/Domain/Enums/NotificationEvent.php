<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Peristiwa notifikasi modul BK (tanpa isi catatan rahasia).
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum NotificationEvent: string
{
    case NEW_REFERRAL = 'NEW_REFERRAL';
    case CASE_ASSIGNED = 'CASE_ASSIGNED';
    case APPOINTMENT_CREATED = 'APPOINTMENT_CREATED';
    case APPOINTMENT_CHANGED = 'APPOINTMENT_CHANGED';
    case APPOINTMENT_REMINDER = 'APPOINTMENT_REMINDER';
    case FOLLOW_UP_DUE = 'FOLLOW_UP_DUE';
    case FOLLOW_UP_OVERDUE = 'FOLLOW_UP_OVERDUE';
    case CONSENT_REQUIRED = 'CONSENT_REQUIRED';
    case ESCALATION = 'ESCALATION';
    case SLA_WARNING = 'SLA_WARNING';
    case SLA_BREACHED = 'SLA_BREACHED';
    case EXPORT_READY = 'EXPORT_READY';
    case RISK_INDICATOR_CREATED = 'RISK_INDICATOR_CREATED';
    case BK_REVIEW_REQUIRED = 'BK_REVIEW_REQUIRED';
    case CONSENT_EXPIRING = 'CONSENT_EXPIRING';
    case CONFERENCE_SCHEDULED = 'CONFERENCE_SCHEDULED';
    case CONFERENCE_ACTION_DUE = 'CONFERENCE_ACTION_DUE';
    case EXTERNAL_REFERRAL_UPDATED = 'EXTERNAL_REFERRAL_UPDATED';
    case DEVELOPMENT_REVIEW_DUE = 'DEVELOPMENT_REVIEW_DUE';
    case PROGRAM_SESSION_REMINDER = 'PROGRAM_SESSION_REMINDER';
    case FEEDBACK_RECEIVED = 'FEEDBACK_RECEIVED';
    case CASE_READY_TO_CLOSE = 'CASE_READY_TO_CLOSE';
    case RETENTION_DISPOSAL_REQUESTED = 'RETENTION_DISPOSAL_REQUESTED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::SLA_WARNING => 'Peringatan tenggat',
            self::SLA_BREACHED => 'Tenggat terlampaui',
            self::EXPORT_READY => 'Ekspor siap diunduh',
            self::RISK_INDICATOR_CREATED => 'Penanda dukungan baru',
            self::BK_REVIEW_REQUIRED => 'Tinjauan BK diperlukan',
            self::CONSENT_EXPIRING => 'Persetujuan akan berakhir',
            self::CONFERENCE_SCHEDULED => 'Konferensi kasus dijadwalkan',
            self::CONFERENCE_ACTION_DUE => 'Tindak lanjut konferensi jatuh tempo',
            self::EXTERNAL_REFERRAL_UPDATED => 'Perkembangan rujukan eksternal',
            self::DEVELOPMENT_REVIEW_DUE => 'Peninjauan pengembangan jatuh tempo',
            self::PROGRAM_SESSION_REMINDER => 'Pengingat sesi program',
            self::FEEDBACK_RECEIVED => 'Umpan balik diterima',
            self::CASE_READY_TO_CLOSE => 'Kasus siap ditutup',
            self::RETENTION_DISPOSAL_REQUESTED => 'Permintaan pemusnahan data',
            self::NEW_REFERRAL => 'Referral baru',
            self::CASE_ASSIGNED => 'Kasus ditugaskan',
            self::APPOINTMENT_CREATED => 'Janji temu dibuat',
            self::APPOINTMENT_CHANGED => 'Janji temu berubah',
            self::APPOINTMENT_REMINDER => 'Pengingat janji temu',
            self::FOLLOW_UP_DUE => 'Tindak lanjut jatuh tempo',
            self::FOLLOW_UP_OVERDUE => 'Tindak lanjut terlambat',
            self::CONSENT_REQUIRED => 'Persetujuan diperlukan',
            self::ESCALATION => 'Eskalasi kasus',
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
