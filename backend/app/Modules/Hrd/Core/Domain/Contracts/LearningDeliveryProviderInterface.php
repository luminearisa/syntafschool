<?php

namespace App\Modules\Hrd\Core\Domain\Contracts;

/**
 * Titik integrasi ke luar modul HRD: LMS.
 *
 * Bila modul tujuan belum terpasang, implementasi bawaan mengembalikan jawaban
 * "belum tersedia" dan peristiwanya tetap tercatat di outbox — bukan membuat
 * modul HRD gagal. Pola ini sama dengan Phase 1 dan menjaga batas modul.
 */
interface LearningDeliveryProviderInterface
{
    /**
     * Mendaftarkan pegawai pada konten LMS (LMS tetap sumber kebenaran konten).
     *
     * @return array<string, mixed>
     */
    public function enroll(int $employeeId, array $courseReference, array $context = []): array;
    /**
     * Status penyelesaian dari LMS.
     */
    public function completion(int $assignmentId): ?array;
    /**
     * Katalog kursus LMS untuk dipetakan ke katalog pelatihan HRD.
     *
     * @return array<string, mixed>
     */
    public function courseCatalog(array $filters = []): array;
}
