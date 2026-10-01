<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Jenis peristiwa pada timeline kasus \u2014 append-only.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum CaseEventType: string
{
    case REFERRAL_CREATED = 'REFERRAL_CREATED';
    case REFERRAL_RECEIVED = 'REFERRAL_RECEIVED';
    case SCREENING_COMPLETED = 'SCREENING_COMPLETED';
    case CASE_OPENED = 'CASE_OPENED';
    case CASE_ASSIGNED = 'CASE_ASSIGNED';
    case CASE_REASSIGNED = 'CASE_REASSIGNED';
    case ASSESSMENT_SENT = 'ASSESSMENT_SENT';
    case ASSESSMENT_COMPLETED = 'ASSESSMENT_COMPLETED';
    case SESSION_SCHEDULED = 'SESSION_SCHEDULED';
    case SESSION_COMPLETED = 'SESSION_COMPLETED';
    case PLAN_CREATED = 'PLAN_CREATED';
    case GOAL_UPDATED = 'GOAL_UPDATED';
    case INTERVENTION_CREATED = 'INTERVENTION_CREATED';
    case INTERVENTION_UPDATED = 'INTERVENTION_UPDATED';
    case FOLLOW_UP_CREATED = 'FOLLOW_UP_CREATED';
    case FOLLOW_UP_COMPLETED = 'FOLLOW_UP_COMPLETED';
    case PARENT_MEETING_HELD = 'PARENT_MEETING_HELD';
    case DOCUMENT_ADDED = 'DOCUMENT_ADDED';
    case NOTE_ADDED = 'NOTE_ADDED';
    case STATUS_CHANGED = 'STATUS_CHANGED';
    case CASE_REVIEWED = 'CASE_REVIEWED';
    case CASE_RESOLVED = 'CASE_RESOLVED';
    case CASE_CLOSED = 'CASE_CLOSED';
    case CASE_REOPENED = 'CASE_REOPENED';
    case ESCALATED = 'ESCALATED';
    case ACCESS_LOGGED = 'ACCESS_LOGGED';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::REFERRAL_CREATED => 'Referral dibuat',
            self::REFERRAL_RECEIVED => 'Referral diterima',
            self::SCREENING_COMPLETED => 'Screening selesai',
            self::CASE_OPENED => 'Kasus dibuka',
            self::CASE_ASSIGNED => 'Konselor ditugaskan',
            self::CASE_REASSIGNED => 'Konselor diganti',
            self::ASSESSMENT_SENT => 'Asesmen dikirim',
            self::ASSESSMENT_COMPLETED => 'Asesmen selesai',
            self::SESSION_SCHEDULED => 'Sesi dijadwalkan',
            self::SESSION_COMPLETED => 'Sesi selesai',
            self::PLAN_CREATED => 'Rencana dibuat',
            self::GOAL_UPDATED => 'Sasaran diperbarui',
            self::INTERVENTION_CREATED => 'Intervensi dibuat',
            self::INTERVENTION_UPDATED => 'Intervensi diperbarui',
            self::FOLLOW_UP_CREATED => 'Tindak lanjut dibuat',
            self::FOLLOW_UP_COMPLETED => 'Tindak lanjut selesai',
            self::PARENT_MEETING_HELD => 'Pertemuan orang tua',
            self::DOCUMENT_ADDED => 'Dokumen ditambahkan',
            self::NOTE_ADDED => 'Catatan ditambahkan',
            self::STATUS_CHANGED => 'Status berubah',
            self::CASE_REVIEWED => 'Kasus ditinjau',
            self::CASE_RESOLVED => 'Kasus dituntaskan',
            self::CASE_CLOSED => 'Kasus ditutup',
            self::CASE_REOPENED => 'Kasus dibuka kembali',
            self::ESCALATED => 'Dieskalasi',
            self::ACCESS_LOGGED => 'Akses dicatat',
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
