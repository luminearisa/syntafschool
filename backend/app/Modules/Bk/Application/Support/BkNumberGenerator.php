<?php

namespace App\Modules\Bk\Application\Support;

use Illuminate\Support\Facades\DB;

/**
 * Penomoran dokumen BK yang rapi dan aman-balapan.
 *
 * Nomor seperti `BK-C-2026-000001` dibuat dari tabel `document_sequences` di
 * dalam transaksi dengan kunci baris, sehingga dua permintaan bersamaan tidak
 * menghasilkan nomor kembar. Format disimpan di basis data agar sekolah dapat
 * mengubahnya tanpa deploy.
 */
class BkNumberGenerator
{
    public function next(string $code, string $fallbackPrefix): string
    {
        return DB::transaction(function () use ($code, $fallbackPrefix): string {
            $row = DB::table('document_sequences')->where('code', $code)->lockForUpdate()->first();

            if ($row === null) {
                DB::table('document_sequences')->insert([
                    'code' => $code,
                    'prefix' => $fallbackPrefix,
                    'format' => '{prefix}-{year}-{number}',
                    'last_number' => 1,
                    'reset_period' => 'year',
                    'last_reset_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $this->format($fallbackPrefix, 1);
            }

            $number = (int) $row->last_number + 1;

            DB::table('document_sequences')->where('id', $row->id)->update([
                'last_number' => $number,
                'updated_at' => now(),
            ]);

            return $this->format((string) ($row->prefix ?: $fallbackPrefix), $number);
        });
    }

    private function format(string $prefix, int $number): string
    {
        return sprintf('%s-%s-%06d', $prefix, date('Y'), $number);
    }
}
