<?php

namespace App\Modules\Clinic\Application\Services;

use App\Models\Guardian;
use App\Models\User;
use App\Modules\Clinic\Domain\Contracts\ParentProviderInterface;
use App\Modules\Clinic\Domain\Enums\StaffRole;
use App\Modules\Clinic\Models\ClinicPatient;
use App\Modules\Clinic\Models\ClinicStaff;
use App\Modules\Clinic\Models\ClinicVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Cakupan data klinis per peran — jantung privasi Modul Clinic.
 *
 * Aturan yang ditegakkan (semuanya **server-side**, tidak dapat dilewati klien):
 *
 * | Peran                          | Boleh melihat                                   |
 * |--------------------------------|-------------------------------------------------|
 * | Admin klinik / petugas medis   | seluruh pasien dalam cakupan kliniknya          |
 * | Kepala sekolah / admin sekolah | seluruh sekolah (baca & laporan)                |
 * | Guru / wali kelas              | **tidak otomatis** — hanya bila diberi izin     |
 * | Orang tua / wali               | hanya anak yang tertaut di `student_guardians`  |
 * | Siswa                          | hanya dirinya sendiri                           |
 *
 * Guru sengaja TIDAK mendapat akses ke rekam medis hanya karena ia mengajar
 * kelas itu: rekam medis bukan informasi operasional pengajaran. Operator UKS
 * tetap dapat melihat semua pasien melalui penugasan `clinic_staff`.
 */
class ClinicAccessService
{
    public function __construct(private readonly ParentProviderInterface $parents)
    {
    }

    /** Baris penugasan klinik aktif milik pengguna ini (bila ada). */
    public function staffFor(User $user): ?ClinicStaff
    {
        return ClinicStaff::query()
            ->with('clinic')
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', now()->toDateString()))
            ->first();
    }

    public function isClinicalStaff(User $user): bool
    {
        return $this->staffFor($user) !== null;
    }

    /** Peran klinis pengguna (DOCTOR, NURSE, UKS_OFFICER, dst) bila petugas. */
    public function role(User $user): ?StaffRole
    {
        $staff = $this->staffFor($user);

        return $staff === null ? null : StaffRole::from($staff->role);
    }

    /** Melihat seluruh pasien sekolah (bukan hanya lingkup sendiri). */
    public function canViewAll(User $user): bool
    {
        return $user->hasAnyPermission(['clinic.manage', 'clinic.record.view.all'])
            || $user->hasAnyRole(['super-admin', 'admin-sekolah', 'kepala-sekolah'])
            || $this->isClinicalStaff($user);
    }

    /** Boleh menulis catatan klinis (triase, tanda vital, asesmen, tindakan). */
    public function canRecordClinical(User $user): bool
    {
        if ($user->hasAnyRole(['super-admin', 'admin-sekolah'])) {
            return true;
        }

        $role = $this->role($user);

        return $role !== null && $role->canTriage();
    }

    /** Boleh menegakkan diagnosis (dokter/perawat sesuai kewenangan). */
    public function canDiagnose(User $user): bool
    {
        if ($user->hasAnyRole(['super-admin', 'admin-sekolah'])) {
            return true;
        }

        $role = $this->role($user);

        return $role !== null && $role->canDiagnose();
    }

    /** Boleh menerbitkan resep. */
    public function canPrescribe(User $user): bool
    {
        if ($user->hasAnyRole(['super-admin', 'admin-sekolah'])) {
            return true;
        }

        return $user->hasPermissionTo('clinic.prescription.create')
            && $this->isClinicalStaff($user);
    }

    /** Boleh menyerahkan obat (farmasi/perawat/dokter). */
    public function canDispense(User $user): bool
    {
        if ($user->hasAnyRole(['super-admin', 'admin-sekolah'])) {
            return true;
        }

        $role = $this->role($user);

        return $role !== null && $role->canDispense();
    }

    public function canProctor(User $user): bool
    {
        return $this->canRecordClinical($user);
    }

    /**
     * ID pasien yang boleh dilihat. `null` berarti seluruh sekolah.
     *
     * @return list<int>|null
     */
    public function visiblePatientIds(User $user): ?array
    {
        if ($this->canViewAll($user)) {
            return null;
        }

        $guardian = $this->guardianEntityFor($user);

        if ($guardian !== null) {
            $studentIds = $this->parents->studentIdsForGuardianUser($user->id);

            return $this->patientIdsForStudents($studentIds);
        }

        $studentId = $this->studentEntityIdFor($user);

        if ($studentId !== null) {
            return $this->patientIdsForStudents([$studentId]);
        }

        // Guru, pegawai non-klinik, dan akun tanpa entitas: tidak ada rekam
        // medis yang boleh dibaca. Izin `clinic.record.view` tetap diperlukan di
        // level route, tetapi cakupan barisnya kosong sampai diizinkan eksplisit.
        return [];
    }

    /** @param Builder<*> $query */
    public function scopePatients(Builder $query, User $user, string $column = 'patient_id'): Builder
    {
        $ids = $this->visiblePatientIds($user);

        if ($ids === null) {
            return $query;
        }

        return $query->whereIn($column, $ids === [] ? [-1] : $ids);
    }

    public function canSeePatient(User $user, int $patientId): bool
    {
        $ids = $this->visiblePatientIds($user);

        return $ids === null || in_array($patientId, $ids, true);
    }

    public function assertCanSeePatient(User $user, int $patientId): void
    {
        if (! $this->canSeePatient($user, $patientId)) {
            throw new AccessDeniedHttpException('Anda tidak berwenang melihat rekam medis pasien ini.');
        }
    }

    public function assertCanSeeVisit(User $user, ClinicVisit $visit): void
    {
        $this->assertCanSeePatient($user, (int) $visit->patient_id);
    }

    public function assertCanSeePatientModel(User $user, ClinicPatient $patient): void
    {
        $this->assertCanSeePatient($user, (int) $patient->id);
    }

    /**
     * Dokumen kesehatan: hanya pemilik lingkup yang boleh mengunduh, dan hanya
     * bila dokumen memang ditandai dapat dilihat oleh peran tersebut.
     */
    public function assertCanAccessDocument(User $user, ClinicPatient $patient, string $visibility): void
    {
        $this->assertCanSeePatient($user, (int) $patient->id);

        $isClinical = $this->isClinicalStaff($user) || $this->canViewAll($user);
        $isGuardian = $this->guardianEntityFor($user) !== null;
        $isStudent = $this->studentEntityIdFor($user) !== null;

        $allowed = match ($visibility) {
            'CLINICAL' => $isClinical,
            'PARENT' => $isClinical || $isGuardian,
            'STAFF' => $isClinical || $user->hasAnyRole(['guru', 'pegawai']),
            'PUBLIC' => true,
            default => false,
        };

        if (! $allowed && ! ($isStudent && $visibility === 'PARENT')) {
            throw new AccessDeniedHttpException('Anda tidak berwenang membuka dokumen kesehatan ini.');
        }
    }

    /** Boleh mengekspor laporan kesehatan (agregat). */
    public function canExport(User $user): bool
    {
        return $user->hasPermissionTo('clinic.report.export');
    }

    /** Boleh melihat laporan tingkat pasien (bukan hanya agregat). */
    public function canSeePatientLevelReport(User $user): bool
    {
        return $this->canViewAll($user) && $user->hasPermissionTo('clinic.report.view');
    }

    /** Apakah pengguna hanya orang tua (tanpa hak klinis). */
    public function isParentOnly(User $user): bool
    {
        return ! $this->isClinicalStaff($user)
            && ! $this->canViewAll($user)
            && $this->guardianEntityFor($user) !== null;
    }

    public function isStudentOnly(User $user): bool
    {
        return ! $this->isClinicalStaff($user)
            && ! $this->canViewAll($user)
            && $this->studentEntityIdFor($user) !== null;
    }

    /** @return list<int> */
    public function childStudentIdsFor(User $user): array
    {
        return $this->parents->studentIdsForGuardianUser($user->id);
    }

    /** @param list<int> $studentIds @return list<int> */
    private function patientIdsForStudents(array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        return ClinicPatient::query()
            ->whereIn('student_id', $studentIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function guardianEntityFor(User $user): ?Guardian
    {
        if ($user->userable_type !== (new Guardian)->getMorphClass()) {
            return null;
        }

        return Guardian::query()->find($user->userable_id);
    }

    private function studentEntityIdFor(User $user): ?int
    {
        $studentClass = \App\Models\Student::class;

        if ($user->userable_type !== (new $studentClass)->getMorphClass()) {
            return null;
        }

        return $user->userable_id === null ? null : (int) $user->userable_id;
    }

    /**
     * Pasien yang boleh dilihat, lengkap dengan nama siswa dari master ERP.
     * Dipakai layar pencarian pasien agar tidak ada N+1 pada daftar.
     *
     * @return \Illuminate\Support\Collection<int, ClinicPatient>
     */
    public function visiblePatients(User $user, ?string $term = null, int $limit = 25)
    {
        $query = ClinicPatient::query()
            ->with(['student:id,nis,nisn,full_name,nick_name,birth_date,blood_type'])
            ->where('patient_type', 'STUDENT');

        $ids = $this->visiblePatientIds($user);
        if ($ids !== null) {
            $query->whereIn('id', $ids === [] ? [-1] : $ids);
        }

        if ($term !== null && trim($term) !== '') {
            $like = '%'.trim($term).'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('medical_record_number', 'like', $like)
                    ->orWhereHas('student', fn (Builder $s) => $s->where('full_name', 'like', $like)
                        ->orWhere('nis', 'like', $like)
                        ->orWhere('nisn', 'like', $like));
            });
        }

        return $query->orderByDesc('id')->limit($limit)->get();
    }

    /** Statistik cepat untuk pemilih pasien (berapa baris yang terlihat). */
    public function visiblePatientCount(User $user): int
    {
        $ids = $this->visiblePatientIds($user);

        return $ids === null
            ? ClinicPatient::query()->count()
            : ClinicPatient::query()->whereIn('id', $ids === [] ? [-1] : $ids)->count();
    }

    /** Daftar kelas yang boleh dipilih untuk skrining massal. */
    public function visibleClassIds(User $user): ?array
    {
        if ($this->canViewAll($user)) {
            return null;
        }

        $studentIds = $this->visiblePatientIds($user);

        if ($studentIds === null || $studentIds === []) {
            return [];
        }

        $patientStudentIds = ClinicPatient::query()->whereIn('id', $studentIds)->pluck('student_id')->filter();

        return DB::table('class_students')
            ->whereIn('student_id', $patientStudentIds)
            ->where('status', 'aktif')
            ->pluck('school_class_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
