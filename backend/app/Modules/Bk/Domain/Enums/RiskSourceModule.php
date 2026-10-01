<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Modul asal penanda.
 *
 * Dipakai untuk menelusuri kembali ke sumber kebenarannya: BK tidak menyalin data
 * akademik/presensi/kedisiplinan, hanya menunjuk dari mana penanda berasal.
 */
enum RiskSourceModule: string
{
    case ATTENDANCE = 'ATTENDANCE';
    case ACADEMIC = 'ACADEMIC';
    case DISCIPLINE = 'DISCIPLINE';
    case BOARDING = 'BOARDING';
    case ACTIVITY = 'ACTIVITY';
    case CLINIC = 'CLINIC';
    case TEACHER_REFERRAL = 'TEACHER_REFERRAL';
    case PARENT_REFERRAL = 'PARENT_REFERRAL';
    case SELF_REFERRAL = 'SELF_REFERRAL';
    case SYSTEM = 'SYSTEM';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::ATTENDANCE => 'Presensi',
            self::ACADEMIC => 'Akademik',
            self::DISCIPLINE => 'Kedisiplinan',
            self::BOARDING => 'Asrama',
            self::ACTIVITY => 'Aktivitas siswa',
            self::CLINIC => 'Klinik/UKS',
            self::TEACHER_REFERRAL => 'Rujukan guru',
            self::PARENT_REFERRAL => 'Rujukan orang tua',
            self::SELF_REFERRAL => 'Inisiatif siswa',
            self::SYSTEM => 'Sistem',
        };
    }

    /** Modul ini milik BK sendiri; selebihnya milik modul lain (source of truth). */
    public function isOwnedByBk(): bool
    {
        return in_array($this, [self::TEACHER_REFERRAL, self::PARENT_REFERRAL, self::SELF_REFERRAL, self::SYSTEM], true);
    }

    /** Modul yang menyimpan data klinis — isinya tidak boleh disalin ke BK. */
    public function isClinical(): bool
    {
        return $this === self::CLINIC;
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
