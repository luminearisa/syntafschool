<?php

namespace App\Modules\Asset\Application\Support;

use App\Modules\Asset\Models\AssetSetting;
use App\Modules\Asset\Models\Asset;
use Illuminate\Support\Facades\DB;

/**
 * Penomoran dokumen modul Asset & Sarpras.
 *
 * Nomor urut diambil dari baris penghitung yang DIKUNCI (`lockForUpdate`) di
 * dalam transaksi, bukan dari `max(kode)+1`. Selisihnya penting: dua petugas yang
 * menyimpan pada saat yang sama akan mendapat nomor yang sama pada cara kedua —
 * dan kode aset yang kembar merusak label, stiker, dan rekonsiliasi.
 *
 * Pola kode dapat diubah sekolah lewat `config/asset.php` atau tabel
 * `asset_settings`, sehingga penomoran bukan aturan yang dipaku di kode.
 */
class AssetNumberGenerator
{
    public function next(string $name, array $context = []): string
    {
        $pattern = (string) (app(AssetSettingsService::class)->get("numbering.$name")
            ?? config("asset.numbering.$name", 'AST-{URUT}'));

        $scope = $this->scopeKey($name, $pattern, $context);

        return DB::transaction(function () use ($name, $pattern, $context, $scope): string {
            $sequence = $this->nextSequence($scope);

            return $this->render($pattern, $sequence, $context);
        });
    }

    /** Nomor urut berikutnya untuk satu lingkup penomoran. */
    private function nextSequence(string $scope): int
    {
        $key = 'sequence:'.$scope;

        $row = AssetSetting::query()->where('key', $key)->lockForUpdate()->first();

        if ($row === null) {
            // Baris pertama untuk lingkup ini: mulai dari 1.
            AssetSetting::query()->create([
                'key' => $key,
                'value' => ['value' => 1],
                'description' => 'Penghitung nomor dokumen modul Asset & Sarpras.',
            ]);

            return 1;
        }

        $value = (int) ($row->value['value'] ?? 0) + 1;
        $row->forceFill(['value' => ['value' => $value]])->save();

        return $value;
    }

    /** Lingkup penomoran: pola + tahun (dan kategori bila dipakai). */
    private function scopeKey(string $name, string $pattern, array $context): string
    {
        $parts = [$name, $pattern, (string) ($context['tahun'] ?? now()->year)];

        if (str_contains($pattern, '{KAT}')) {
            $parts[] = (string) ($context['kat'] ?? 'UMUM');
        }

        return implode(':', $parts);
    }

    private function render(string $pattern, int $sequence, array $context): string
    {
        return strtr($pattern, [
            '{KAT}' => strtoupper((string) ($context['kat'] ?? 'UMUM')),
            '{TAHUN}' => (string) ($context['tahun'] ?? now()->year),
            '{BULAN}' => str_pad((string) now()->month, 2, '0', STR_PAD_LEFT),
            '{URUT}' => str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            '{URUT4}' => str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
        ]);
    }

    /** Kode aset yang dijamin belum dipakai (pemeriksaan terakhir sebelum simpan). */
    public function assetCode(array $context = []): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = $this->next('asset', $context);

            if (! Asset::withoutSchoolScope()->where('asset_code', $code)->exists()) {
                return $code;
            }
        }

        return $this->next('asset', $context);
    }
}
