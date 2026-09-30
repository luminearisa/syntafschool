<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum PatientType — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum PatientType: string
{
    case STUDENT = 'STUDENT';
    case TEACHER = 'TEACHER';
    case STAFF = 'STAFF';
    case BOARDING_STAFF = 'BOARDING_STAFF';
    case GUEST = 'GUEST';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::STUDENT => 'Siswa',
            self::TEACHER => 'Guru',
            self::STAFF => 'Pegawai',
            self::BOARDING_STAFF => 'Petugas Asrama',
            self::GUEST => 'Tamu',
            self::OTHER => 'Lainnya',
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
