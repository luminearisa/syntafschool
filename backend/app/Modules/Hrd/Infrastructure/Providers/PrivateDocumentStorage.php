<?php

namespace App\Modules\Hrd\Infrastructure\Providers;

use App\Modules\Hrd\Domain\Contracts\DocumentStorageProviderInterface;
use Illuminate\Support\Facades\Storage;

/**
 * Penyimpanan berkas privat (§31, §52).
 *
 * Disk default adalah disk privat modul (`hrd`), yang dapat diarahkan ke S3 oleh
 * sekolah tanpa mengubah kode. Berkas pegawai **tidak pernah** disajikan lewat
 * URL publik; tautan yang diberikan selalu bertanda tangan dan berbatas waktu.
 */
class PrivateDocumentStorage implements DocumentStorageProviderInterface
{
    public function __construct(private readonly ?string $configuredDisk = null) {}

    public function store(mixed $file, string $directory, ?string $disk = null): string
    {
        $disk = $disk ?? $this->disk();

        return Storage::disk($disk)->putFile($directory, $file, ['visibility' => 'private']);
    }

    public function delete(string $path, ?string $disk = null): bool
    {
        $disk = $disk ?? $this->disk();

        return Storage::disk($disk)->exists($path) && Storage::disk($disk)->delete($path);
    }

    public function exists(string $path, ?string $disk = null): bool
    {
        return Storage::disk($disk ?? $this->disk())->exists($path);
    }

    public function temporaryUrl(string $path, int $minutes, ?string $disk = null): ?string
    {
        $diskName = $disk ?? $this->disk();

        try {
            return Storage::disk($diskName)->temporaryUrl($path, now()->addMinutes($minutes));
        } catch (\Throwable) {
            // Disk lokal tanpa `serve`/temporaryUrl: berkas tetap dapat diunduh
            // lewat endpoint ber-otorisasi pada modul HRD.
            return null;
        }
    }

    public function disk(): string
    {
        return (string) ($this->configuredDisk
            ?? config('hrd.documents.disk', 'hrd'));
    }
}
