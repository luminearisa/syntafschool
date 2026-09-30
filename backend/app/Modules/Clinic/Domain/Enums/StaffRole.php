<?php

namespace App\Modules\Clinic\Domain\Enums;

/**
 * Enum StaffRole — nilai tersimpan dalam format UPPER_SNAKE_CASE.
 *
 * Nilai disimpan sebagai `string` di basis data sehingga penambahan kasus baru
 * tidak memerlukan ALTER TABLE; keabsahan nilai ditegakkan oleh aturan validasi
 * yang membaca enum ini.
 */
enum StaffRole: string
{
    case CLINIC_ADMIN = 'CLINIC_ADMIN';
    case DOCTOR = 'DOCTOR';
    case NURSE = 'NURSE';
    case UKS_OFFICER = 'UKS_OFFICER';
    case PHARMACY_OFFICER = 'PHARMACY_OFFICER';
    case CLINIC_RECEPTIONIST = 'CLINIC_RECEPTIONIST';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::CLINIC_ADMIN => 'Admin Klinik',
            self::DOCTOR => 'Dokter',
            self::NURSE => 'Perawat',
            self::UKS_OFFICER => 'Petugas UKS',
            self::PHARMACY_OFFICER => 'Petugas Farmasi',
            self::CLINIC_RECEPTIONIST => 'Resepsionis Klinik',
        };
    }


    /** Boleh melakukan asesmen & diagnosis klinis. */
    public function canDiagnose(): bool
    {
        return in_array($this, [self::DOCTOR, self::NURSE], true);
    }

    /** Boleh menyerahkan obat. */
    public function canDispense(): bool
    {
        return in_array($this, [self::DOCTOR, self::NURSE, self::PHARMACY_OFFICER, self::CLINIC_ADMIN], true);
    }

    /** Boleh melakukan triase & tanda vital. */
    public function canTriage(): bool
    {
        return in_array($this, [self::DOCTOR, self::NURSE, self::UKS_OFFICER, self::CLINIC_ADMIN], true);
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
