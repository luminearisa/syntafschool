<?php

namespace App\Modules\LMS\Domain\Contracts;

/**
 * Sumber keikutsertaan akademik resmi (§5).
 *
 * Integrated → KRS/penempatan kelas SIAKAD (`class_students`, status
 * `aktif/pindah/keluar`). Standalone → LMS sendiri yang menjadi sumber resmi.
 *
 * Aturan penting: pembatalan di sumber akademik **tidak menghapus** data LMS —
 * pendaftaran hanya ditandai `SUSPENDED` sehingga progress, submission, nilai,
 * percobaan kuis, dan riwayat belajar tetap utuh (§5).
 */
interface EnrollmentProviderInterface
{
    public function key(): string;

    public function isAvailable(): bool;

    /**
     * Apakah sumber eksternal berwenang menentukan keikutsertaan?
     *
     * `true` (integrated) → sinkronisasi mengikuti sumber akademik.
     * `false` (standalone) → LMS tidak boleh menunggu sumber luar.
     */
    public function isAuthoritative(): bool;

    /**
     * Penempatan resmi (per kelas/periode) untuk sinkronisasi massal.
     *
     * @return list<array{student_id:int,class_id:int|null,academic_year_id:int|null,semester_id:int|null,status:string,source_id:string}>
     */
    public function officialPlacements(?int $academicYearId = null, ?int $classId = null): array;

    /**
     * Status akademik seorang siswa pada satu kelas/periode.
     *
     * @return array{status:string,source_id:string,academic_year_id:int|null,semester_id:int|null}|null
     *                                                                                                   `null` = tidak ada catatan resmi (jangan dianggap batal).
     */
    public function placementFor(int $studentId, ?int $classId = null, ?int $academicYearId = null): ?array;
}
