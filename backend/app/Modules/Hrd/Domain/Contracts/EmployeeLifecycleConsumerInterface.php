<?php

namespace App\Modules\Hrd\Domain\Contracts;

/**
 * Kontrak integrasi kecil yang dipakai HRD untuk memberi tahu modul lain bahwa
 * seorang pegawai berubah keadaan (§66).
 *
 * Kelasnya sengaja ringan: modul HRD tidak perlu tahu bagaimana LMS menyimpan
 * peserta kursus, Aset menyimpan pemegang barang, atau Aktivitas mencatat
 * pembina ekstrakurikuler. Ia hanya perlu memberi tahu — dan modul tujuan yang
 * memutuskan apa artinya.
 *
 * Bila modul tujuan belum terpasang, implementasi `Null*` dipakai dan niatnya
 * tetap tersimpan di `hr_integration_outbox` untuk dikerjakan kemudian.
 */
interface EmployeeLifecycleConsumerInterface
{
    /** Peristiwa yang diminati, mis. `employee.activated`, `employee.transferred`. */
    public function subscribesTo(): array;

    /**
     * Tangani peristiwa. Implementasi wajib idempoten: peristiwa yang sama dapat
     * dikirim ulang oleh antrean.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(string $event, array $payload): void;

    /** Nama modul untuk keperluan outbox & log. */
    public function module(): string;
}
