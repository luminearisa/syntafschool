<?php

namespace App\Services\Academic;

use App\Models\Assessment;
use App\Models\Assignment;
use App\Models\ClassSubject;
use App\Models\Employee;
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
