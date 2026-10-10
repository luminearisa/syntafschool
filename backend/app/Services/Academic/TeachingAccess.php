<?php

namespace App\Services\Academic;

use App\Models\Assessment;
use App\Models\Assignment;
use App\Models\ClassSubject;
use App\Models\Employee;
use App\Models\ReportCard;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\User;

/**
 * Batas kewenangan GURU atas kelas/mapel (presensi siswa, nilai, penilaian).
 *
 * Permission modul (`academic.attendance`, `academic.grade`) hanya menjawab
 * "boleh menilai/mencatat", bukan "kelas mana". Tanpa batas ini setiap guru
 * dapat mengubah presensi dan nilai kelas guru lain.
 *
 * Yang dibatasi hanya akun yang (a) tertaut ke pegawai, (b) berstatus guru,
 * dan (c) bukan pengelola akademik (`academic.promote`: admin, kepala sekolah).
 * Staf non-guru (mis. petugas absensi), pengelola, dan akun tanpa tautan
 * pegawai tetap seperti sebelumnya.
 */
class TeachingAccess
{
    /** ID pegawai-guru yang dibatasi, atau null bila akun ini tidak dibatasi. */
    public function restrictedTeacherId(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }

        $userable = $user->userable;

        if (! $userable instanceof Employee || ! $userable->is_teacher || $user->can('academic.promote')) {
            return null;
        }

        return (int) $userable->id;
    }

    public function mayRecordClass(?User $user, int $classId): bool
    {
        $teacherId = $this->restrictedTeacherId($user);

        return $teacherId === null || $this->teachesClass($teacherId, $classId);
    }

    /**
     * Daftar kelas yang boleh diakses guru, atau null bila akun tidak dibatasi.
     *
     * Dipakai untuk menyaring daftar (presensi/koreksi) agar guru hanya melihat
     * kelas yang diampu/walikelasinya, sementara pengelola melihat semuanya.
     *
     * @return list<int>|null
     */
    public function accessibleClassIds(?User $user): ?array
    {
        $teacherId = $this->restrictedTeacherId($user);

        if ($teacherId === null) {
            return null;
        }

        return SchoolClass::query()
            ->where('homeroom_teacher_id', $teacherId)
            ->pluck('id')
            ->merge(ClassSubject::query()->where('employee_id', $teacherId)->pluck('school_class_id'))
            ->merge(Schedule::query()->where('employee_id', $teacherId)->pluck('school_class_id'))
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function mayGrade(?User $user, Assessment $assessment): bool
    {
        $teacherId = $this->restrictedTeacherId($user);

        if ($teacherId === null || (int) $assessment->employee_id === $teacherId) {
            return true;
        }

        return $this->isHomeroom($teacherId, (int) $assessment->school_class_id)
            || ClassSubject::query()
                ->where('school_class_id', $assessment->school_class_id)
                ->where('subject_id', $assessment->subject_id)
                ->where('employee_id', $teacherId)
                ->exists();
    }

    /** Guru hanya mengelola/menilai tugas yang ia buat (`assignments.teacher_id`). */
    public function mayManageAssignment(?User $user, Assignment $assignment): bool
    {
        $teacherId = $this->restrictedTeacherId($user);

        return $teacherId === null || (int) $assignment->teacher_id === $teacherId;
    }

    /**
     * Guru hanya melihat/mengubah rapor kelas yang ia ampu atau walikelasinya.
     *
     * Tanpa batas ini, permission `report-card.update` membuat guru mana pun
     * dapat menyunting catatan rapor kelas guru lain.
     */
    public function mayManageReportCard(?User $user, ReportCard $reportCard): bool
    {
        $teacherId = $this->restrictedTeacherId($user);

        return $teacherId === null || $this->teachesClass($teacherId, (int) $reportCard->school_class_id);
    }

    /** Tugas baru atas nama guru lain hanya boleh dibuat pengelola. */
    public function mayCreateAssignmentFor(?User $user, int $teacherId): bool
    {
        $own = $this->restrictedTeacherId($user);

        return $own === null || $own === $teacherId;
    }

    private function teachesClass(int $teacherId, int $classId): bool
    {
        return $this->isHomeroom($teacherId, $classId)
            || ClassSubject::query()->where('school_class_id', $classId)->where('employee_id', $teacherId)->exists()
            || Schedule::query()->where('school_class_id', $classId)->where('employee_id', $teacherId)->exists();
    }

    private function isHomeroom(int $teacherId, int $classId): bool
    {
        return SchoolClass::query()->whereKey($classId)->where('homeroom_teacher_id', $teacherId)->exists();
    }
}
