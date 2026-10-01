<?php

namespace App\Modules\Workflow\Domain\Contracts;

use App\Modules\Workflow\Domain\Support\ResolvedApprover;
use App\Modules\Workflow\Domain\Support\WorkflowContext;

/**
 * Menentukan **siapa** yang berhak menyetujui sebuah node (§22, §74).
 *
 * Engine tidak pernah menebak approver dari data bisnis. Ia menerima daftar
 * spesifikasi approver (`[{ "resolver": "role", "role": "FINANCE_MANAGER" }]`)
 * dan meminta resolver yang sesuai untuk menerjemahkannya menjadi pengguna.
 *
 * Modul ERP dapat menambahkan resolver sendiri (mis. "wali kelas", "musyrif
 * kamar", "pembimbing PKL") cukup dengan mendaftarkan implementasi kontrak ini
 * ke `WorkflowRegistrar` — tanpa menyentuh kode engine.
 */
interface ApproverResolverInterface
{
    /** Alias yang dipakai pada konfigurasi node, mis. `role`, `manager`. */
    public function alias(): string;

    /** Keterangan singkat untuk builder/admin. */
    public function description(): string;

    /**
     * Spesifikasi yang wajib ada pada konfigurasi (mis. `role` untuk resolver role).
     *
     * @return array<string, string> nama field => tipe (string|int|array|bool)
     */
    public function requiredSpec(): array;

    /**
     * Selesaikan approver untuk satu node.
     *
     * @param  array<string, mixed>  $spec  bagian konfigurasi resolver ini
     * @return list<ResolvedApprover> daftar approver (boleh kosong — engine
     *                                akan menerapkan kebijakan approver kosong)
     */
    public function resolve(WorkflowContext $context, array $spec = []): array;
}
