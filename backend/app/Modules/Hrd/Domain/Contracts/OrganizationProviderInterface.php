<?php

namespace App\Modules\Hrd\Domain\Contracts;

/**
 * Kontrak organisasi (§2, §65).
 *
 * Dipakai modul yang membutuhkan bagan organisasi tanpa boleh menyalinnya:
 * Keuangan (cost center & dimensi), Aset (lokasi & penanggung jawab), Workflow
 * (resolusi approver berdasarkan unit), dan LMS (kelas per unit).
 */
interface OrganizationProviderInterface
{
    /** @return array<string, mixed>|null */
    public function findById(int $organizationId): ?array;

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array;

    /**
     * Daftar seluruh unit (datar, dengan parent_id) untuk membangun pohon.
     *
     * @return list<array<string, mixed>>
     */
    public function all(bool $activeOnly = true): array;

    /**
     * Pohon organisasi mulai dari satu unit.
     *
     * @return array<string, mixed>|null
     */
    public function treeFrom(int $organizationId): ?array;

    /**
     * Id unit keturunan (termasuk dirinya sendiri) — dasar penegakan cakupan data.
     *
     * @return list<int>
     */
    public function descendantIds(int $organizationId, bool $includeSelf = true): array;

    /**
     * Pejabat yang memimpin sebuah unit, bila ada. Diutamakan pimpinan posisi,
     * lalu `manager_employee_id` unit.
     *
     * @return array<string, mixed>|null
     */
    public function headOf(int $organizationId): ?array;

    /** Apakah `$organizationId` berada di dalam (atau sama dengan) `$ancestorId`? */
    public function isWithin(int $organizationId, int $ancestorId): bool;
}
