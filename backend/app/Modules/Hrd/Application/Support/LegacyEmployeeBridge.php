<?php

namespace App\Modules\Hrd\Application\Support;

use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jembatan ke tabel `employees` lama (§87: jangan memaksa migrasi serentak).
 *
 * Modul akademik, presensi, jurnal mengajar, aset, dan koperasi masih menunjuk
 * `employees.id`. Daripada mengubah belasan modul sekaligus, HRD menuliskan
 * **proyeksi ringkas** ke tabel lama setiap kali data pegawai berubah:
 * nama, NIP, unit, status, aktif/tidak.
 *
 * Aturan kerjanya:
 *
 *  - HRD tetap satu-satunya tempat data diubah; tabel lama tidak pernah menjadi
 *    sumber, hanya cermin;
 *  - pemetaan status ke enum lama (`PNS|PPPK|Honorer|GTY`) dapat diubah lewat
 *    `config/hrd.php` karena enum itu milik modul lama, bukan milik HRD;
 *  - kegagalan memproyeksikan TIDAK menggagalkan transaksi HRD — perubahan yang
 *    sah tidak boleh dibatalkan karena tabel lama tidak ada (mis. instalasi baru).
 */
class LegacyEmployeeBridge
{
    public function enabled(): bool
    {
        return (bool) config('hrd.legacy_bridge.enabled', true) && Schema::hasTable('employees');
    }

    /** Proyeksikan keadaan pegawai ke tabel lama (buat bila belum tertaut). */
    public function project(HrEmployee $employee): ?int
    {
        if (! $this->enabled()) {
            return $employee->legacy_employee_id === null ? null : (int) $employee->legacy_employee_id;
        }

        $payload = $this->projectionFor($employee);

        try {
            $legacyId = $employee->legacy_employee_id;

            if ($legacyId !== null && ! DB::table('employees')->where('id', $legacyId)->exists()) {
                $legacyId = null;
            }

            if ($legacyId === null) {
                $legacyId = DB::table('employees')->insertGetId($payload + [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $employee->forceFill(['legacy_employee_id' => $legacyId])->saveQuietly();
            } else {
                DB::table('employees')->where('id', $legacyId)->update($payload + ['updated_at' => now()]);
            }

            return (int) $legacyId;
        } catch (\Throwable) {
            // Cermin gagal ≠ data HRD gagal. Keadaan ini terlihat pada layar
            // kesehatan modul sebagai temuan `hr.legacy_bridge.out_of_sync`.
            return $employee->legacy_employee_id === null ? null : (int) $employee->legacy_employee_id;
        }
    }

    /** Hapus cermin (dipakai ketika pegawai diarsipkan permanen). */
    public function detach(HrEmployee $employee): void
    {
        if (! $this->enabled() || $employee->legacy_employee_id === null) {
            return;
        }

        try {
            DB::table('employees')->where('id', $employee->legacy_employee_id)->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            // Diabaikan dengan sengaja; lihat catatan pada project().
        }
    }

    /**
     * Potongan data yang dicerminkan. Hanya kolom yang benar-benar dipakai modul
     * lama — salinan penuh akan menciptakan kembali duplikasi yang sedang dihapus.
     *
     * @return array<string, mixed>
     */
    public function projectionFor(HrEmployee $employee): array
    {
        $person = $employee->person;
        $organizationCode = $employee->organization_id === null
            ? null
            : HrOrganization::query()->whereKey($employee->organization_id)->value('code');

        return [
            'school_id' => $employee->organization_id === null
                ? null
                : HrOrganization::query()->whereKey($employee->organization_id)->value('school_id'),
            'nip' => $this->nipFor($employee),
            'full_name' => $person?->full_name ?? $employee->employee_number,
            'birth_place' => $person?->birth_place,
            'birth_date' => $person?->birth_date?->toDateString(),
            'address' => $person?->address,
            'phone' => $person?->mobile ?? $person?->phone,
            'email' => $person?->work_email ?? $person?->personal_email,
            'photo_path' => $person?->photo_path,
            'position' => $employee->position?->title,
            'employee_type' => $this->legacyWorkerType($employee),
            'employment_status' => $this->legacyEmploymentStatus($employee),
            'join_date' => $employee->hire_date?->toDateString(),
            'is_teacher' => $this->legacyIsTeacher($employee, $organizationCode),
            'is_active' => $employee->isActive(),
        ];
    }

    /** NIP diambil dari identitas `nip` bila ada, jika tidak dari nomor pegawai. */
    private function nipFor(HrEmployee $employee): ?string
    {
        $nip = $employee->identifiers()
            ->whereIn('type_code', ['nip', 'nuptk'])
            ->orderByDesc('is_primary')
            ->value('number');

        return $nip === null ? $employee->employee_number : (string) $nip;
    }

    /** Enum lama hanya mengenal guru/staf. */
    private function legacyWorkerType(HrEmployee $employee): string
    {
        $positionTitle = (string) ($employee->position?->title ?? '');
        $isTeacher = (bool) ($employee->position?->job?->is_teacher_role ?? false)
            || stripos($positionTitle, 'guru') !== false
            || stripos($positionTitle, 'ustadz') !== false
            || stripos($positionTitle, 'pengajar') !== false
            || stripos($positionTitle, 'musyrif') !== false;

        return $isTeacher ? 'guru' : 'staf';
    }

    private function legacyIsTeacher(HrEmployee $employee, ?string $organizationCode): bool
    {
        return $this->legacyWorkerType($employee) === 'guru';
    }

    /** Pemetaan ke enum lama; dapat diubah sekolah tanpa deploy ulang. */
    private function legacyEmploymentStatus(HrEmployee $employee): string
    {
        $map = (array) config('hrd.legacy_bridge.employment_status_map', []);
        $candidates = array_filter([
            $employee->employment_type,
            $employee->worker_type,
            (string) ($employee->position?->position_type ?? ''),
        ]);

        foreach ($candidates as $candidate) {
            if (isset($map[$candidate])) {
                return $map[$candidate];
            }
        }

        $lifecycleMap = match ($employee->lifecycle_state) {
            'probation', 'preboarding' => $map['Probation'] ?? null,
            default => null,
        };

        return $lifecycleMap ?? (string) config('hrd.legacy_bridge.default_employment_status', 'Honorer');
    }

    /** Berapa cermin yang tertinggal dari keadaan HRD — dipakai pemeriksa kesehatan. */
    public function outOfSyncCount(): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        return HrEmployee::query()
            ->whereNotNull('legacy_employee_id')
            ->whereDoesntHave('person')
            ->count();
    }
}
