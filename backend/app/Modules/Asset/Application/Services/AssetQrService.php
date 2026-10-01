<?php

namespace App\Modules\Asset\Application\Services;

use App\Modules\Asset\Models\Asset;
use Illuminate\Support\Str;

/**
 * QR/barcode aset.
 *
 * Isi QR adalah TOKEN acak, bukan id aset: dengan begitu label yang sudah
 * tertempel bertahun-tahun tidak membocorkan apa pun bila dipindai orang luar,
 * dan token dapat dicetak ulang tanpa mengganti identitas aset. Pencarian lewat
 * token selalu mengembalikan data aman saja (tanpa angka keuangan).
 */
class AssetQrService
{
    public function __construct(private readonly AssetAccessService $access) {}

    public function ensureToken(Asset $asset): string
    {
        if ($asset->qr_token === null || $asset->qr_token === '') {
            $asset->forceFill(['qr_token' => (string) Str::uuid()])->save();
        }

        return (string) $asset->qr_token;
    }

    public function regenerateToken(Asset $asset): string
    {
        $asset->forceFill(['qr_token' => (string) Str::uuid()])->save();

        return (string) $asset->qr_token;
    }

    /**
     * Pencarian aset dari hasil pindai.
     *
     * Menerima kode aset, barcode, token QR, atau nomor seri — karena di lapangan
     * yang tersedia bisa salah satunya. Data yang dikembalikan mengikuti
     * `AssetAccessService::publicPayload` sehingga tidak pernah memuat nilai
     * keuangan.
     */
    public function lookup(string $code, bool $withDetail = false): ?array
    {
        $asset = Asset::query()
            ->with('location:id,name')
            ->where('asset_code', $code)
            ->orWhere('barcode', $code)
            ->orWhere('qr_token', $code)
            ->orWhere('asset_tag', $code)
            ->orWhere('serial_number', $code)
            ->first();

        if ($asset === null) {
            return null;
        }

        $payload = $this->access->publicPayload($asset);

        if ($withDetail) {
            $payload['can_request_maintenance'] = true;
        }

        return $payload;
    }

    /** Nilai yang dicetak pada label (teks QR + barcode manusiawi). */
    public function labelPayload(Asset $asset): array
    {
        $token = $this->ensureToken($asset);

        return [
            'asset_code' => $asset->asset_code,
            'name' => $asset->name,
            'qr_content' => $token,
            'barcode_content' => $asset->barcode,
            'tag_code' => $asset->asset_tag,
            'print_url' => url('/a/'.$token),
        ];
    }
}
