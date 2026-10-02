<?php

namespace App\Modules\Hrd\Domain\Contracts;

/**
 * Titik masuk Workflow Engine ke data SDM (§2, §55, §66).
 *
 * Engine perlu tahu siapa atasan seorang pegawai dan siapa pemegang sebuah posisi
 * ketika menentukan approver. Sebelum modul HRD ada, engine menebak dari kolom
 * `employees.position`; sekarang engine bertanya ke sumber kebenarannya.
 *
 * Kontrak ini juga menjadi tempat HRD memulai persetujuan: HRD tidak membuat
 * mesin persetujuan sendiri, ia memakai engine yang sudah ada.
 */
interface HRWorkflowProviderInterface
{
    /** Id pengguna atasan langsung pegawai ini (untuk resolusi approver). */
    public function managerUserIdOf(int $employeeId): ?int;

    /** Id pengguna pemegang sebuah posisi pada satu tanggal. */
    public function positionHolderUserId(int $positionId, ?string $onDate = null): ?int;

    /** Id pengguna pejabat yang memimpin unit. */
    public function organizationHeadUserId(int $organizationId): ?int;

    /**
     * Mulai proses persetujuan untuk sebuah subjek SDM.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> informasi instans workflow
     */
    public function startApproval(string $definitionCode, string $subjectType, int $subjectId, array $payload = [], ?int $actorUserId = null): array;

    /** Status persetujuan terkini sebuah subjek, bila ada. */
    public function approvalStatus(string $subjectType, int $subjectId): ?string;
}
