<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Jenis pekerjaan yang dipantau SLA.
 *
 * Ambang waktunya tidak ada di sini: setiap jenis hanya menyediakan bawaan yang
 * dapat ditimpa kebijakan sekolah pada tabel bk_sla_policies.
 */
enum SlaSubjectType: string
{
    case REFERRAL = 'REFERRAL';
    case SCREENING = 'SCREENING';
    case ASSIGNMENT = 'ASSIGNMENT';
    case FOLLOW_UP = 'FOLLOW_UP';
    case CASE = 'CASE';
    case URGENT_CASE = 'URGENT_CASE';
    case EXTERNAL_REFERRAL = 'EXTERNAL_REFERRAL';
    case CONSENT = 'CONSENT';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::REFERRAL => 'Respon rujukan',
            self::SCREENING => 'Screening',
            self::ASSIGNMENT => 'Penugasan kasus',
            self::FOLLOW_UP => 'Tindak lanjut',
            self::CASE => 'Kasus',
            self::URGENT_CASE => 'Kasus mendesak',
            self::EXTERNAL_REFERRAL => 'Rujukan eksternal',
            self::CONSENT => 'Persetujuan',
        };
    }

    public function defaultHours(): int
    {
        return match ($this) {
            self::REFERRAL => 24,
            self::SCREENING => 72,
            self::ASSIGNMENT => 48,
            self::FOLLOW_UP => 336,
            self::CASE => 336,
            self::URGENT_CASE => 4,
            self::EXTERNAL_REFERRAL => 168,
            self::CONSENT => 72,
        };
    }

    /** Jenis yang naik ke manajemen bila tetap terlambat. */
    public function escalatesToManagement(): bool
    {
        return in_array($this, [self::URGENT_CASE, self::CASE, self::REFERRAL], true);
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
