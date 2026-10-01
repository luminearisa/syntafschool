<?php

namespace App\Support\Accounting;

use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Aturan double-entry — fungsi murni, tanpa basis data.
 *
 * Dipisahkan dari engine supaya aturannya dapat diuji sendiri dan supaya tiap
 * pemanggil (jurnal manual, settlement, saldo awal) tidak menafsirkannya ulang
 * dengan caranya masing-masing. Kegagalan sebelumnya justru lahir dari selisih
 * tafsir itu: satu layanan memakai toleransi pembulatan, layanan lain tidak.
 *
 * Yang ditegakkan:
 *   - minimal 2 baris;
 *   - setiap baris: debet & kredit >= 0, tidak keduanya > 0, salah satu > 0;
 *   - Σ debet **tepat sama dengan** Σ kredit — dibandingkan sebagai string
 *     BCMath, bukan float, sehingga tidak ada toleransi diam-diam.
 */
final class JournalValidator
{
    /**
     * @param  array<int, array{account_id?: int, kode_coa?: string, debit?: mixed, credit?: mixed}>  $lines
     * @return array{total_debit: string, total_credit: string}
     *
     * @throws ValidationException
     */
    public static function validateBalanced(array $lines): array
    {
        if (count($lines) < 2) {
            throw ValidationException::withMessages([
                'lines' => ['Jurnal harus memiliki minimal 2 baris.'],
            ]);
        }

        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach (array_values($lines) as $index => $line) {
            $number = $index + 1;
            $debit = Money::of($line['debit'] ?? 0);
            $credit = Money::of($line['credit'] ?? 0);

            if (Money::isNegative($debit) || Money::isNegative($credit)) {
                throw ValidationException::withMessages([
                    'lines' => ["Baris {$number}: debet/kredit tidak boleh negatif."],
                ]);
            }

            if (Money::gtZero($debit) && Money::gtZero($credit)) {
                throw ValidationException::withMessages([
                    'lines' => ["Baris {$number}: satu baris tidak boleh memuat debet dan kredit sekaligus."],
                ]);
            }

            if (Money::isZero($debit) && Money::isZero($credit)) {
                throw ValidationException::withMessages([
                    'lines' => ["Baris {$number}: setiap baris harus memiliki debet atau kredit lebih dari nol."],
                ]);
            }

            $totalDebit = Money::add($totalDebit, $debit);
            $totalCredit = Money::add($totalCredit, $credit);
        }

        if (! Money::eq($totalDebit, $totalCredit)) {
            throw ValidationException::withMessages([
                'lines' => [
                    "Jurnal tidak seimbang: total debet ({$totalDebit}) tidak sama dengan total kredit ({$totalCredit}).",
                ],
            ]);
        }

        return ['total_debit' => $totalDebit, 'total_credit' => $totalCredit];
    }

    /**
     * Akun beban wajib membawa dimensi pusat biaya bila kebijakan menuntutnya.
     *
     * Dijalankan hanya untuk akun beban (akar kelompok 5) karena hanya di
     * sanalah realisasi anggaran diukur. Jurnal non-kas dikecualikan lewat
     * daftar modul pada konfigurasi, supaya penutupan buku dan depresiasi — yang
     * tidak punya pusat biaya tunggal — tidak ikut tertahan.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function validatePusatBiaya(array $lines, string $sumberModul): void
    {
        if (! (bool) config('finance.dimensions.require_cost_center_for_expense', false)) {
            return;
        }

        $dikecualikan = (array) config('finance.dimensions.cost_center_exempt_modules', []);

        if (in_array($sumberModul, $dikecualikan, true)) {
            return;
        }

        foreach (array_values($lines) as $index => $line) {
            $kode = (string) ($line['kode_coa'] ?? $line['account_code'] ?? '');

            if (! CoaHierarchy::isBeban($kode)) {
                continue;
            }

            if (empty($line['cost_center_id'])) {
                throw ValidationException::withMessages([
                    'lines' => ['Baris '.($index + 1).': pusat biaya wajib diisi untuk akun beban (menggerakkan realisasi anggaran).'],
                ]);
            }
        }
    }
}
