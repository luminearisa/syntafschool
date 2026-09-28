<?php

namespace App\Modules\LMS\Domain\Contracts;

use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsTeacher;

/**
 * Jembatan ke "course" versi SIAKAD (§6).
 *
 * Integrated : `subjects` (+ `class_subjects` untuk pengampu & rombel).
 * Standalone : LMS course itu sendiri adalah course akademik — implementasi
 *              standalone mengembalikan data ringkas dari tabel LMS, sehingga
 *              modul tidak menuntut keberadaan SIAKAD.
 */
interface AcademicCourseProviderInterface
{
    /** @return array<string, mixed>|null {id, code, name, credit, education_level_id} */
    public function find(int $academicCourseId): ?array;

    /**
     * Pasangan mapel ↔ kelas yang diampu seorang guru.
     *
     * @return list<array<string, mixed>>
     */
    public function forTeacher(LmsActor $actor): array;

    /**
     * Mapel yang diikuti seorang siswa (dari rombelnya) — dasar pembuatan
     * enrollment akademik tanpa meminta guru memasukkan siswa satu per satu.
     *
     * @return list<array<string, mixed>>
     */
    public function forStudent(int $studentId, ?int $academicYearId = null): array;

    /** Guru pengampu sebuah mapel di kelas tertentu. */
    public function teacherFor(int $classId, int $academicCourseId, ?int $academicYearId = null): ?LmsTeacher;

    /**
     * Ringkasan untuk halaman detail course LMS (kode & nama mapel ERP).
     *
     * @return array<string, mixed>|null
     */
    public function summary(?int $academicCourseId, ?int $classId, ?int $academicYearId): ?array;
}
