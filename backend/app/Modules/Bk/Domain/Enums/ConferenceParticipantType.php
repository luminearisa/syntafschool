<?php

namespace App\Modules\Bk\Domain\Enums;

/**
 * Peran peserta konferensi kasus.
 *
 * Peran ini menentukan kewenangan membaca di dalam konferensi. Peserta tidak
 * otomatis memperoleh akses catatan konseling kasusnya — kepala sekolah yang hadir
 * dalam konferensi tetap tidak membuka catatan konselor hanya karena ia hadir.
 */
enum ConferenceParticipantType: string
{
    case COUNSELOR = 'COUNSELOR';
    case BK_COORDINATOR = 'BK_COORDINATOR';
    case HOMEROOM_TEACHER = 'HOMEROOM_TEACHER';
    case TEACHER = 'TEACHER';
    case BOARDING_STAFF = 'BOARDING_STAFF';
    case CLINIC_STAFF = 'CLINIC_STAFF';
    case MANAGEMENT = 'MANAGEMENT';
    case PARENT = 'PARENT';
    case STUDENT = 'STUDENT';
    case EXTERNAL_PROVIDER = 'EXTERNAL_PROVIDER';
    case OTHER = 'OTHER';

    /** Label bahasa Indonesia untuk tampilan. */
    public function label(): string
    {
        return match ($this) {
            self::COUNSELOR => 'Konselor',
            self::BK_COORDINATOR => 'Koordinator BK',
            self::HOMEROOM_TEACHER => 'Wali kelas',
            self::TEACHER => 'Guru',
            self::BOARDING_STAFF => 'Pengasuh asrama',
            self::CLINIC_STAFF => 'Petugas klinik',
            self::MANAGEMENT => 'Manajemen',
            self::PARENT => 'Orang tua/wali',
            self::STUDENT => 'Siswa',
            self::EXTERNAL_PROVIDER => 'Pihak luar',
            self::OTHER => 'Lainnya',
        };
    }

    /** Jenis peserta yang berasal dari luar tim BK. */
    public function isExternal(): bool
    {
        return in_array($this, [self::PARENT, self::STUDENT, self::EXTERNAL_PROVIDER], true);
    }

    /** Kerahasiaan maksimum bawaan: tim BK boleh rahasia, pihak lain tidak. */
    public function defaultMaxConfidentiality(): ConfidentialityLevel
    {
        return match ($this) {
            self::COUNSELOR, self::BK_COORDINATOR => ConfidentialityLevel::HIGHLY_CONFIDENTIAL,
            // Manajemen hadir di rapat, tetapi kehadiran TIDAK memberi akses
            // catatan konseling: langit-langitnya setara staf lain. Bila memang
            // berhak (mis. kepala sekolah yang juga supervisor), haknya datang
            // dari `BkAccessService`, bukan dari jenis peserta.
            self::MANAGEMENT => ConfidentialityLevel::STAFF_ONLY,
            self::CLINIC_STAFF, self::BOARDING_STAFF, self::TEACHER, self::HOMEROOM_TEACHER => ConfidentialityLevel::STAFF_ONLY,
            self::PARENT => ConfidentialityLevel::RESTRICTED,
            self::STUDENT => ConfidentialityLevel::RESTRICTED,
            self::EXTERNAL_PROVIDER => ConfidentialityLevel::RESTRICTED,
            self::OTHER => ConfidentialityLevel::STAFF_ONLY,
        };
    }

    /** Kewenangan anggota tim BK (bukan sekadar peserta rapat). */
    public function isBkTeam(): bool
    {
        return in_array($this, [self::COUNSELOR, self::BK_COORDINATOR], true);
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
