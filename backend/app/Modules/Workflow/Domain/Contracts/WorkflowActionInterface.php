<?php

namespace App\Modules\Workflow\Domain\Contracts;

use App\Modules\Workflow\Domain\Support\WorkflowActionContext;

/**
 * Aksi otomatis yang dijalankan engine setelah sebuah kejadian (§43).
 *
 * Engine **tidak boleh** berisi logika bisnis modul. "Buat Purchase Order",
 * "Terbitkan tagihan", "Ubah status pengajuan" semuanya adalah aksi yang
 * didaftarkan modul pemiliknya lewat alias, lalu dirujuk definisi workflow.
 * Engine hanya tahu: kapan dijalankan, dengan konteks apa, dan apa yang
 * dicatat bila gagal.
 */
interface WorkflowActionInterface
{
    /** Alias pada konfigurasi node/definisi, mis. `business.create`. */
    public function alias(): string;

    public function description(): string;

    /**
     * Jalankan aksi. Implementasi **wajib** idempoten: engine dapat memanggil
     * ulang aksi yang sama setelah percobaan gagal atau pemulihan.
     *
     * @param  array<string, mixed>  $params  parameter dari definisi workflow
     * @return array<string, mixed> ringkasan hasil untuk jejak audit
     */
    public function execute(WorkflowActionContext $context, array $params = []): array;
}
