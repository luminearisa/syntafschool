<?php

namespace App\Modules\Hrd\Domain\Enums;

/**
 * Keadaan siklus hidup pegawai (§68).
 *
 * Ini adalah keadaan **mesin**, bukan label yang dilihat pengguna. Label ramah
 * sekolah ada di `hr_employees.employment_status` (kode referensi yang dapat
 * diubah). Pemisahan itu disengaja: sekolah boleh menyebut "Karyawan Kontrak"
 * atau "Guru Bantu", tetapi mesin tetap membutuhkan keadaan yang terbatas dan
 * transisi yang terverifikasi.
 *
 * ```
 * draft → preboarding → active ⇄ probation → confirmed → offboarding → inactive → archived
 *                          ↘ leave / suspended ↗
 * ```
 */
enum EmployeeLifecycleState: string
{
    case DRAFT = 'draft';
    case PREBOARDING = 'preboarding';
    case ACTIVE = 'active';
    case PROBATION = 'probation';
    case CONFIRMED = 'confirmed';
    case LEAVE = 'leave';
    case SUSPENDED = 'suspended';
    case OFFBOARDING = 'offboarding';
    case INACTIVE = 'inactive';
    case ARCHIVED = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::PREBOARDING => 'Pra-kerja',
            self::ACTIVE => 'Aktif',
            self::PROBATION => 'Percobaan',
            self::CONFIRMED => 'Diangkat Tetap',
            self::LEAVE => 'Cuti',
            self::SUSPENDED => 'Ditangguhkan',
            self::OFFBOARDING => 'Proses Keluar',
            self::INACTIVE => 'Tidak Aktif',
            self::ARCHIVED => 'Arsip',
        };
    }

    /** Keadaan yang berarti pegawai masih terhitung dalam angkatan kerja. */
    public function isWorking(): bool
    {
        return in_array($this, [self::ACTIVE, self::PROBATION, self::CONFIRMED, self::LEAVE, self::SUSPENDED], true);
    }

    /** Keadaan akhir: tidak ada transisi keluar dari sini kecuali rehire. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::INACTIVE, self::ARCHIVED], true);
    }

    public function allowsAssignment(): bool
    {
        return in_array($this, [self::PREBOARDING, self::ACTIVE, self::PROBATION, self::CONFIRMED, self::LEAVE, self::SUSPENDED], true);
    }
}
