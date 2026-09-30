<?php

namespace App\Modules\Clinic\Application\Support;

use Illuminate\Support\Facades\DB;

/**
 * Penomoran dokumen klinis.
 *
 * Nomor rekam medis bersifat **imun**: sekali diberikan tidak berubah meski
 * siswa pindah kelas, naik tingkat, atau tahun ajaran berganti. Nomor dokumen
 * lain (kunjungan, resep, rujukan) memakai urutan per hari/bulan agar mudah
 * dibaca dan tetap unik karena pembuatannya di dalam transaksi bercabang.
 */
class ClinicNumber
{
    /** Nomor rekam medis: MR-2026-000001 */
    public static function medicalRecord(int $sequence): string
    {
        return sprintf('MR-%s-%06d', now()->format('Y'), $sequence);
    }

    /** Nomor kunjungan: KV-20261030-0001 */
    public static function visit(int $sequence): string
    {
        return sprintf('KV-%s-%04d', now()->format('Ymd'), $sequence);
    }

    /** Nomor resep: RX-20261030-0001 */
    public static function prescription(int $sequence): string
    {
        return sprintf('RX-%s-%04d', now()->format('Ymd'), $sequence);
    }

    /** Nomor penyerahan obat: DO-20261030-0001 */
    public static function dispensing(int $sequence): string
    {
        return sprintf('DO-%s-%04d', now()->format('Ymd'), $sequence);
    }

    /** Nomor rujukan: RJ-20261030-0001 */
    public static function referral(int $sequence): string
    {
        return sprintf('RJ-%s-%04d', now()->format('Ymd'), $sequence);
    }

    /** Nomor insiden (gawat darurat & cedera): IN-20261030-0001 */
    public static function incident(int $sequence): string
    {
        return sprintf('IN-%s-%04d', now()->format('Ymd'), $sequence);
    }

    /** Nomor surat keterangan: SK-20261030-0001 */
    public static function certificate(int $sequence): string
    {
        return sprintf('SK-%s-%04d', now()->format('Ymd'), $sequence);
    }

    /** Nomor janji temu: AP-20261030-0001 */
    public static function appointment(int $sequence): string
    {
        return sprintf('AP-%s-%04d', now()->format('Ymd'), $sequence);
    }

    /** Nomor dokumen: DOC-20261030-0001 */
    public static function document(int $sequence): string
    {
        return sprintf('DOC-%s-%04d', now()->format('Ymd'), $sequence);
    }

    /**
     * Urutan berikutnya untuk sebuah tabel. Dipanggil di dalam transaksi yang
     * sama dengan penyimpanan barisnya sehingga celah nomor tidak terjadi pada
     * data yang benar-benar tersimpan.
     */
    public static function nextSequence(string $table, ?string $dateColumn = null, ?string $date = null): int
    {
        $query = DB::table($table);

        if ($dateColumn !== null && $date !== null) {
            $query->whereDate($dateColumn, $date);
        }

        return ((int) $query->count()) + 1;
    }

    /** Kode verifikasi publik untuk surat/dokumen: 12 karakter alfanumerik. */
    public static function verificationCode(): string
    {
        return strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
    }
}
