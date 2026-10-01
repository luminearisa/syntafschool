<?php

namespace App\Modules\Asset\Infrastructure\Providers;

use App\Modules\Asset\Domain\Contracts\DocumentProviderInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Penyimpanan dokumen bawaan: disk privat + URL sementara.
 *
 * Tidak ada dokumen aset yang disimpan di ruang publik: faktur, sertifikat, dan
 * berita acara penghapusan memuat informasi yang tidak boleh terbuka hanya karena
 * tautannya tersebar.
 */
class LocalDocumentProvider implements DocumentProviderInterface
{
    public function store(array $payload): array
    {
        $disk = (string) ($payload['disk'] ?? 'local');
        $path = (string) ($payload['path'] ?? 'asset-documents/'.now()->format('Y/m'));
        $name = (string) ($payload['filename'] ?? (Str::uuid().'.'.($payload['extension'] ?? 'bin')));

        $reference = trim($path, '/').'/'.$name;

        if (isset($payload['contents'])) {
            Storage::disk($disk)->put($reference, $payload['contents']);
        } elseif (isset($payload['file'])) {
            Storage::disk($disk)->putFileAs($path, $payload['file'], $name);
        }

        return [
            'status' => 'STORED',
            'reference' => $reference,
            'disk' => $disk,
            'size_bytes' => Storage::disk($disk)->exists($reference) ? Storage::disk($disk)->size($reference) : null,
            'mime_type' => Storage::disk($disk)->exists($reference) ? (Storage::disk($disk)->mimeType($reference) ?: null) : null,
        ];
    }

    public function temporaryUrl(string $reference, int $minutes = 15): ?string
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($reference)) {
            return null;
        }

        try {
            return $disk->temporaryUrl($reference, now()->addMinutes($minutes));
        } catch (\Throwable) {
            // Disk lokal tidak mendukung URL bertanda tangan; tautan internal tetap
            // memerlukan otentikasi pada rute pengunduhan.
            return url('/api/v1/assets/documents/'.urlencode(base64_encode($reference)));
        }
    }

    public function delete(string $reference): bool
    {
        return Storage::disk('local')->delete($reference);
    }

    public function exists(string $reference): bool
    {
        return Storage::disk('local')->exists($reference);
    }
}
