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

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
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
