<?php

namespace App\Modules\Workflow\Domain\Contracts;

/**
 * Direktori organisasi untuk resolusi approver berbasis jabatan (§25–§27).
 *
 * ERP ini menyimpan pegawai (`employees`), cost center Finance, dan unit kerja
 * sebagai data master — tetapi tidak memiliki bagan "siapa atasan siapa".
 * Kontrak ini memisahkan pertanyaan itu dari engine:
 *
 *  - implementasi default membaca `workflow_org_reports` / `workflow_org_assignments`
 *    (diisi admin lewat layar Direktori Workflow) dan memanfaatkan
 *    `finance_cost_centers.manager_id` bila ada;
 *  - sekolah yang sudah punya bagan organisasi HRD dapat mengikat implementasi
 *    lain (mis. pembaca modul Kepegawaian) tanpa mengubah engine.
 */
interface OrganizationalDirectoryInterface
{
    /** Atasan langsung seorang pengguna. */
    public function managerOf(?int $userId): ?int;

    /** Pengganti atasan (bila atasan sedang tidak ada). */
    public function backupManagerOf(?int $userId): ?int;

    /** Unit/departemen seorang pengguna. */
    public function departmentOf(?int $userId): ?string;

    /**
     * Pejabat pada sebuah unit/departemen.
     *
     * @return list<int> id pengguna
     */
    public function headsOfDepartment(string $department): array;

    /**
     * Pemegang sebuah jabatan (nama jabatan bebas, mis. "Kepala Sekolah").
     *
     * @return list<int>
     */
    public function holdersOfPosition(string $position): array;

    /**
     * Pengguna dengan cakupan tertentu (cost center / kampus / tim).
     *
     * @param  string  $scopeType  nilai `OrgScopeType`
     * @return list<int>
     */
    public function usersInScope(string $scopeType, string|int $scopeKey): array;

    /** Pemegang cakupan utama (mis. kepala unit, manajer cost center). */
    public function primaryHolder(string $scopeType, string|int $scopeKey): ?int;
}
