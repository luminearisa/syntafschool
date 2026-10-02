<?php

namespace App\Modules\Hrd\Domain\Contracts;

/**
 * Kontrak departemen (§2, §65).
 *
 * Keuangan memakainya untuk dimensi biaya, Aset untuk penanggung jawab ruang,
 * dan Workflow untuk menemukan kepala departemen yang berhak menyetujui.
 */
interface DepartmentProviderInterface
{
    /** @return array<string, mixed>|null */
    public function findById(int $departmentId): ?array;

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array;

    /**
     * @return list<array<string, mixed>>
     */
    public function inOrganization(int $organizationId, bool $activeOnly = true): array;

    /**
     * Kepala departemen (pejabat posisi kepala, lalu `head_employee_id`).
     *
     * Dinamai eksplisit karena kontrak organisasi juga punya `headOf()` untuk
     * unit — dua hal berbeda yang tidak boleh tertukar oleh pemanggil.
     *
     * @return array<string, mixed>|null
     */
    public function headOfDepartment(int $departmentId): ?array;

    /**
     * Daftar id departemen beserta anak-anaknya.
     *
     * @return list<int>
     */
    public function withChildren(int $departmentId): array;
}
