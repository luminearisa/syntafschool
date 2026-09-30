<?php

namespace App\Modules\UKS\Application\Support;

use Illuminate\Support\Facades\DB;

/**
 * Penomoran dokumen UKS yang aman terhadap permintaan bersamaan.
 *
 * Memakai tabel `document_sequences` yang sudah ada (kolom `code` unik) dengan
 * kunci baris, sehingga dua petugas yang mencatat di saat yang sama tidak
 * pernah mendapat nomor yang sama. Format: `{prefix}/{tahun}/{nomor:5}`.
 */
final class UksNumber
{
    public static function next(string $code, string $prefix): string
    {
        return DB::transaction(function () use ($code, $prefix): string {
            $row = DB::table('document_sequences')->where('code', $code)->lockForUpdate()->first();

            if ($row === null) {
                DB::table('document_sequences')->insert([
                    'code' => $code,
                    'prefix' => $prefix,
                    'format' => '{PREFIX}/{YYYY}/{SEQ:5}',
                    'last_number' => 1,
                    'reset_period' => 'year',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $number = 1;
            } else {
                $number = ((int) $row->last_number) + 1;
                DB::table('document_sequences')->where('code', $code)->update([
                    'last_number' => $number,
                    'updated_at' => now(),
                ]);
            }

            return sprintf('%s/%s/%s', $prefix, now()->year, str_pad((string) $number, 5, '0', STR_PAD_LEFT));
        });
    }

    /** Kode verifikasi 8 karakter untuk surat (dipindai orang tua/pihak luar). */
    public static function verificationCode(): string
    {
        return strtoupper(substr(bin2hex(random_bytes(6)), 0, 8));
    }
}
