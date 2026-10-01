<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Sumber referral \u2014 dari mana kekhawatiran datang.
 *
 * Nilai disimpan sebagai `string` UPPER_SNAKE_CASE di basis data, sehingga
 * menambah pilihan tidak memerlukan ALTER TABLE; keabsahannya ditegakkan aturan
 * validasi yang membaca enum ini.
 */
enum ReferralSource: string
{
    case STUDENT = 'STUDENT';
    case PARENT = 'PARENT';
    case TEACHER = 'TEACHER';
    case HOMEROOM = 'HOMEROOM';
    case BOARDING = 'BOARDING';
    case ACADEMIC = 'ACADEMIC';
    case DISCIPLINE = 'DISCIPLINE';
    case CLINIC = 'CLINIC';
    case STUDENT_ACTIVITY = 'STUDENT_ACTIVITY';
    case SELF_REFERRAL = 'SELF_REFERRAL';
    case SYSTEM = 'SYSTEM';
    case MANAGEMENT = 'MANAGEMENT';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::STUDENT => 'Siswa sendiri',
            self::PARENT => 'Orang tua/wali',
            self::TEACHER => 'Guru mata pelajaran',
            self::HOMEROOM => 'Wali kelas',
            self::BOARDING => 'Pengasuh asrama',
            self::ACADEMIC => 'Bagian akademik',
            self::DISCIPLINE => 'Bagian kedisiplinan',
            self::CLINIC => 'Klinik/UKS',
            self::STUDENT_ACTIVITY => 'Aktivitas siswa',
            self::SELF_REFERRAL => 'Permintaan sendiri',
            self::SYSTEM => 'Sistem (peringatan otomatis)',
            self::MANAGEMENT => 'Manajemen sekolah',
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
