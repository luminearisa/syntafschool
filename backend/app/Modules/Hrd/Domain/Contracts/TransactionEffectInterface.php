<?php

namespace App\Modules\Hrd\Domain\Contracts;

use App\Models\User;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrTransaction;

/**
 * Efek sebuah transaksi SDM (§33, §34).
 *
 * Mesin transaksi hanya mengurus status, persetujuan, dan jejak. Perubahan
 * sebenarnya dikerjakan oleh handler efek yang terdaftar per jenis transaksi:
 * memindahkan penugasan, mengganti atasan, mengakhiri hubungan kerja.
 *
 * Inilah titik ekstensi yang diminta §82: menambah "Rotasi Antar Unit" pada fase
 * berikutnya berarti menambah satu kelas efek + satu baris di `hr_transaction_types`,
 * bukan menyunting mesin transaksi.
 *
 * Kontrak wajib:
 *  - **idempoten** — dipanggil di dalam transaksi basis data, tetapi harus aman
 *    bila dijalankan ulang setelah kegagalan;
 *  - **mengembalikan ringkasan** perubahan untuk disimpan (`after_snapshot`) dan
 *    ditampilkan pada layar persetujuan;
 *  - **tidak mengirim notifikasi sendiri** — mesin transaksi yang melakukannya,
 *    supaya urutan pemberitahuan seragam untuk semua jenis transaksi.
 */
interface TransactionEffectInterface
{
    /** Alias handler, dirujuk `hr_transaction_types.effect_handler`. */
    public function alias(): string;

    /** Apakah handler ini cocok untuk jenis transaksi tersebut? */
    public function supports(HrTransaction $transaction): bool;

    /**
     * Terapkan efek transaksi.
     *
     * @return array<string, mixed> ringkasan perubahan (disimpan ke `after_snapshot`)
     */
    public function apply(HrTransaction $transaction, HrEmployee $employee, User $actor): array;
}
