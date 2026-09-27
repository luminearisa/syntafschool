<?php

namespace App\Services\Finance;

use App\Models\FinanceBilling;
use App\Models\FinanceInvoice;

/**
 * Satu pintu untuk menghitung sisa piutang sebuah tagihan (invariant §31):
 *
 *     outstanding = total − pembayaran teralokasi − write-off
 *
 * Kelas ini ada karena rumus yang sama dulu dituliskan di empat tempat (mesin
 * pembayaran, refund, write-off, dan recalculate billing) dan tidak semuanya
 * memakai rumus yang sama: tiga di antaranya menghitung `total − paid` saja.
 * Akibatnya nyata — setelah piutang di write-off, pembayaran berikutnya
 * menghidupkan kembali nominal yang sudah dihapus ke beban itu sebagai utang,
 * lalu sekolah menagih uang yang sudah dia hapus sendiri.
 *
 * Karena itu `remaining_amount` invoice & billing tidak pernah lagi ditulis
 * langsung: ia selalu hasil hitung dari kolom lain di sini. `paid_amount` hanya
 * bergerak lewat mesin pembayaran/alokasi, `written_off_amount` hanya lewat
 * write-off yang disetujui (§33) — keduanya memanggil class ini lewat
 * `applyPaidDelta()` dan `applyWriteOff()`.
 *
 * Satu aturan status untuk kedua dokumen (nilai enumnya identik): lunas dinilai
 * dari SISA, bukan dari `paid` — tagihan yang di-write-off penuh juga selesai
 * ditagih. Dokumen batal tidak pernah bergerak sendiri, dan sisa tidak pernah
 * negatif: kelebihan bayar adalah kredit di sisi payment, bukan piutang yang
 * berjalan mundur.
 */
class FinanceOutstandingRecalculator
{
    /** Toleransi pembulatan sen; di bawah ini sebuah nominal dianggap nol. */
    public const EPSILON = 0.0001;

    /** Naikkan atau turunkan pembayaran sebuah tagihan, lalu hitung ulang sisanya. */
    public function applyPaidDelta(FinanceInvoice|FinanceBilling $doc, float $delta): void
    {
        $this->write($doc, (float) $doc->paid_amount + $delta, (float) $this->writtenOff($doc));
    }

    /** Tambah write-off yang sudah disetujui (§33), lalu hitung ulang sisanya. */
    public function applyWriteOff(FinanceInvoice|FinanceBilling $doc, float $amount): void
    {
        $this->write($doc, (float) $doc->paid_amount, (float) $this->writtenOff($doc) + $amount);
    }

    /**
     * Hitung ulang dari kolom yang sudah tersimpan — dipakai setelah `total`
     * berubah (billing dihitung dari barisnya) dan oleh alat repair (§16).
     */
    public function recalculate(FinanceInvoice|FinanceBilling $doc): void
    {
        $this->write($doc, (float) $doc->paid_amount, (float) $this->writtenOff($doc));
    }

    private function write(FinanceInvoice|FinanceBilling $doc, float $paid, float $writtenOff): void
    {
        $total = round((float) $doc->total, 2);
        $paid = round(max(0, $paid), 2);
        $writtenOff = round(max(0, $writtenOff), 2);

        $doc->paid_amount = $paid;
        $doc->written_off_amount = $writtenOff;
        $doc->remaining_amount = round(max(0, $total - $paid - $writtenOff), 2);
        $doc->status = $this->statusFor((string) $doc->status, (float) $doc->remaining_amount, $paid);

        $doc->save();
    }

    private function writtenOff(FinanceInvoice|FinanceBilling $doc): float
    {
        return (float) ($doc->written_off_amount ?? 0);
    }

    private function statusFor(string $current, float $remaining, float $paid): string
    {
        if ($current === FinanceInvoice::STATUS_CANCELLED) {
            return $current;
        }

        if ($remaining <= self::EPSILON) {
            return FinanceInvoice::STATUS_PAID;
        }

        return $paid > self::EPSILON ? FinanceInvoice::STATUS_PARTIAL : FinanceInvoice::STATUS_ISSUED;
    }
}
