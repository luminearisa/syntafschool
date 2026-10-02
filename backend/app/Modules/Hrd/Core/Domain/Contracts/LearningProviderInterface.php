<?php

namespace App\Modules\Hrd\Core\Domain\Contracts;

/**
 * Kontrak Pembelajaran — dipakai modul ERP lain dan layanan lain di dalam HRD.
 *
 * Pemanggil tidak boleh membaca tabel HRD secara langsung: ia bergantung pada
 * antarmuka ini, sehingga HRD tetap menjadi satu-satunya tempat data diubah dan
 * penggantian implementasi (termasuk untuk pengujian) tidak menyentuh pemanggil.
 */
interface LearningProviderInterface
{
    /**
     * Pelatihan wajib bagi pegawai.
     *
     * @return array<string, mixed>
     */
    public function requiredTraining(int $employeeId): array;

    /**
     * Status kepatuhan pelatihan wajib.
     *
     * @return array<string, mixed>
     */
    public function trainingCompliance(int $employeeId): array;

    /**
     * Mengajukan pelatihan.
     *
     * @return array<string, mixed>
     */
    public function requestTraining(int $employeeId, array $payload, ?int $actorUserId = null): array;

    /**
     * Menandai pelatihan selesai beserta asesmennya.
     *
     * @return array<string, mixed>
     */
    public function complete(int $assignmentId, array $payload, int $actorUserId): array;

    /**
     * Jam pelatihan pada rentang waktu.
     */
    public function trainingHours(int $employeeId, string $from, string $to): float;
}
