<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum NotificationEvent — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum NotificationEvent: string
{
    case VISIT_CREATED = 'VISIT_CREATED';
    case EMERGENCY = 'EMERGENCY';
    case INJURY = 'INJURY';
    case REFERRAL = 'REFERRAL';
    case SENT_HOME = 'SENT_HOME';
    case MEDICATION = 'MEDICATION';
    case FOLLOW_UP = 'FOLLOW_UP';
    case CERTIFICATE_READY = 'CERTIFICATE_READY';
    case SCREENING = 'SCREENING';
    case OBSERVATION = 'OBSERVATION';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::VISIT_CREATED => 'Kunjungan Baru',
            self::EMERGENCY => 'Gawat Darurat',
            self::INJURY => 'Cedera',
            self::REFERRAL => 'Rujukan',
            self::SENT_HOME => 'Dipulangkan',
            self::MEDICATION => 'Obat',
            self::FOLLOW_UP => 'Tindak Lanjut',
            self::CERTIFICATE_READY => 'Surat Siap',
            self::SCREENING => 'Skrining',
            self::OBSERVATION => 'Observasi',
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
