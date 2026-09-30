<?php

namespace App\Modules\UKS\Application\Services;

use App\Models\ClassStudent;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\User;
use App\Modules\UKS\Application\Support\UksContext;
use App\Modules\UKS\Models\UksIncident;
use App\Modules\UKS\Models\UksLetter;
use App\Modules\UKS\Models\UksOfficer;
use App\Modules\UKS\Models\UksScreening;
use App\Modules\UKS\Models\UksVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Cakupan data UKS per peran (privasi catatan kesehatan).
 *
 * - Admin sekolah / kepala sekolah / petugas UKS: seluruh siswa.
 * - Guru & wali kelas: hanya siswa di kelas yang diampu (wali kelas atau
 *   pengajar mata pelajaran) pada tahun ajaran aktif.
 * - Orang tua/wali: hanya anak yang tertaut di `student_guardians`.
 * - Siswa: hanya dirinya sendiri.
 *
 * Izin di route menentukan *layar* yang boleh dibuka; kelas ini menentukan
 * *baris* yang boleh dilihat. Keduanya wajib, sama seperti pola DataScope
 * pada modul Supervisi.
 */
class UksAccessService
{
    public function context(User $user): UksContext
    {
        return UksContext::for($user);
    }

    /** Petugas UKS aktif yang terhubung ke akun ini, bila ada. */
    public function officer(User $user): ?UksOfficer
    {
        $employee = $this->context($user)->employee;

        if ($employee === null) {
            return null;
        }

        return UksOfficer::query()
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->first();
    }

    public function isOfficer(User $user): bool
    {
        return $this->officer($user) !== null;
    }

    /** Melihat seluruh data sekolah (bukan hanya lingkup sendiri). */
    public function canViewAll(User $user): bool
    {
        return $user->hasAnyPermission([
            'uks.manage',
            'uks.visit.view.all',
        ]) || $user->hasRole(['super-admin', 'admin-sekolah', 'kepala-sekolah']);
    }

    /** Mengelola operasional UKS (menangani kunjungan, stok, pemeriksaan). */
    public function canOperate(User $user): bool
    {
        return $this->canViewAll($user)
            || $this->isOfficer($user)
            || $user->hasPermissionTo('uks.visit.manage');
    }

    /**
     * ID siswa yang boleh dilihat pengguna. `null` berarti seluruh sekolah.
     *
     * @return list<int>|null
     */
    public function visibleStudentIds(User $user): ?array
    {
        if ($this->canViewAll($user)) {
            return null;
        }

        $context = $this->context($user);

        if ($context->isStudent()) {
            return [$context->student->id];
        }

        if ($context->isGuardian()) {
            return DB::table('student_guardians')
                ->where('guardian_id', $context->guardian->id)
                ->pluck('student_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        if ($context->isEmployee()) {
            // Pegawai yang terdaftar sebagai petugas UKS melihat seluruh siswa,
            // apa pun peran ERP yang menempel pada akunnya.
            if ($this->isOfficer($user)) {
                return null;
            }

            return $this->studentIdsForTeacher($context->employee->id);
        }

        // Akun tanpa entitas (mis. auditor murni) tidak melihat baris apa pun
        // kecuali punya izin sekolah di atas.
        return [];
    }

    /** @param Builder<*> $query */
    public function scopeStudents(Builder $query, User $user, string $column = 'student_id'): Builder
    {
        $ids = $this->visibleStudentIds($user);

        return $ids === null ? $query : $query->whereIn($column, $ids === [] ? [-1] : $ids);
    }

    public function canSeeStudent(User $user, int $studentId): bool
    {
        $ids = $this->visibleStudentIds($user);

        return $ids === null || in_array($studentId, $ids, true);
    }

    public function assertCanSeeStudent(User $user, int $studentId): void
    {
        if (! $this->canSeeStudent($user, $studentId)) {
            throw new AccessDeniedHttpException('Anda tidak berwenang melihat data kesehatan siswa ini.');
        }
    }

    public function assertCanSeeVisit(User $user, UksVisit $visit): void
    {
        $this->assertCanSeeStudent($user, (int) $visit->student_id);
    }

    public function assertCanSeeScreening(User $user, UksScreening $screening): void
    {
        $this->assertCanSeeStudent($user, (int) $screening->student_id);
    }

    public function assertCanSeeIncident(User $user, UksIncident $incident): void
    {
        $this->assertCanSeeStudent($user, (int) $incident->student_id);
    }

    public function assertCanSeeLetter(User $user, UksLetter $letter): void
    {
        $this->assertCanSeeStudent($user, (int) $letter->student_id);
    }

    /**
     * Orang tua hanya boleh memberi persetujuan untuk anaknya sendiri.
     */
    public function assertCanConsent(User $user, UksVisit $visit): void
    {
        $context = $this->context($user);

        if ($this->canOperate($user)) {
            return;
        }

        if ($context->isGuardian() && $this->canSeeStudent($user, (int) $visit->student_id)) {
            return;
        }

        throw new AccessDeniedHttpException('Hanya orang tua/wali siswa atau petugas UKS yang dapat memberi persetujuan.');
    }

    /**
     * Siswa di kelas yang diampu guru: sebagai wali kelas atau pengajar.
     *
     * @return list<int>
     */
    private function studentIdsForTeacher(int $employeeId): array
    {
        $activeYearIds = DB::table('academic_years')->where('is_active', true)->pluck('id');

        $classIds = SchoolClass::query()
            ->where('homeroom_teacher_id', $employeeId)
            ->when($activeYearIds->isNotEmpty(), fn ($q) => $q->whereIn('academic_year_id', $activeYearIds))
            ->pluck('id');

        $taughtClassIds = ClassSubject::query()
            ->where('employee_id', $employeeId)
            ->when($activeYearIds->isNotEmpty(), fn ($q) => $q->whereIn('academic_year_id', $activeYearIds))
            ->pluck('school_class_id');

        $allClassIds = $classIds->merge($taughtClassIds)->unique()->values();

        if ($allClassIds->isEmpty()) {
            return [];
        }

        return ClassStudent::query()
            ->whereIn('school_class_id', $allClassIds)
            ->where('status', 'aktif')
            ->pluck('student_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
