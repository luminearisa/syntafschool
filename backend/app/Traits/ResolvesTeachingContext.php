<?php

namespace App\Traits;

use App\Models\ClassSubject;
use App\Models\Employee;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Konteks mengajar bersama untuk Jurnal Mengajar & RPP (A11a).
 *
 * Menyediakan: penentuan guru dari akun pemanggil, penegakan agar guru hanya
 * mencatat atas namanya sendiri, dan pembacaan alokasi JP sebuah mapel di kelas.
 */
trait ResolvesTeachingContext
{
    /**
     * ID pegawai yang tertaut ke akun pemanggil, bila akun itu milik pegawai.
     */
    protected function linkedEmployeeId(?User $user): ?int
    {
        if (! $user) {
            return null;
        }

        $userable = $user->userable;

        return $userable instanceof Employee ? (int) $userable->id : null;
    }

    /**
     * Tentukan guru yang dipakai: dari input `teacher_id`, jika tidak ada dari
     * akun yang tertaut ke pegawai.
     */
    protected function resolveTeacherId(Request $request, ?int $fallback = null): ?int
    {
        $requested = $request->input('teacher_id');

        if ($requested !== null && $requested !== '') {
            return (int) $requested;
        }

        return $this->linkedEmployeeId($request->user()) ?? $fallback;
    }

    /**
     * Guru (akun yang tertaut ke pegawai) hanya boleh mencatat atas namanya
     * sendiri. Akun non-guru (mis. admin tanpa tautan pegawai) bebas memilih.
     */
    protected function assertTeacherOwnership(Request $request, int $teacherId): void
    {
        $linked = $this->linkedEmployeeId($request->user());

        if ($linked !== null && $linked !== $teacherId) {
            throw ValidationException::withMessages([
                'teacher_id' => 'Anda hanya boleh mencatat untuk diri sendiri, bukan atas nama guru lain.',
            ]);
        }
    }

    /**
     * Alokasi JP mapel di sebuah kelas: `class_subjects.weekly_hours`, jika
     * kosong jatuh ke `subjects.credit`. `null` berarti tidak ada batas.
     */
    protected function allocationFor(int $classId, int $subjectId): ?int
    {
        $weekly = ClassSubject::query()
            ->where('school_class_id', $classId)
            ->where('subject_id', $subjectId)
            ->value('weekly_hours');

        if ($weekly !== null) {
            return (int) $weekly;
        }

        $credit = Subject::query()->whereKey($subjectId)->value('credit');

        return $credit !== null ? (int) round((float) $credit) : null;
    }
}
