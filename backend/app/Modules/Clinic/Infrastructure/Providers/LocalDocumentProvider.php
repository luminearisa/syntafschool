<?php

namespace App\Modules\Clinic\Infrastructure\Providers;

use App\Modules\Clinic\Domain\Contracts\DocumentProviderInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Penyimpanan dokumen kesehatan pada disk privat `clinic`.
 *
 * Tidak ada URL publik: berkas hanya dapat dibaca lewat endpoint ber-otorisasi.
 * Nama berkas diacak sehingga tidak dapat ditebak dari nomor rekam medis.
 */
class LocalDocumentProvider implements DocumentProviderInterface
{
    private const ALLOWED_MIME = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const MAX_BYTES = 8 * 1024 * 1024; // 8 MB

    public function store(UploadedFile $file, int $patientId, string $type): array
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
            throw ValidationException::withMessages([
                'file' => 'Berkas harus berupa PDF atau gambar (JPG/PNG/WEBP).',
            ]);
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => 'Ukuran berkas maksimal 8 MB.',
            ]);
        }

        $disk = (string) config('clinic.document_disk', 'clinic');
        $path = $file->store("patients/{$patientId}/".strtolower($type), $disk);

        return [
            'path' => $path,
            'disk' => $disk,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
        ];
    }

    public function read(string $disk, string $path): string
    {
        if (! Storage::disk($disk)->exists($path)) {
            throw ValidationException::withMessages(['document' => 'Berkas dokumen tidak ditemukan pada penyimpanan.']);
        }

        return (string) Storage::disk($disk)->get($path);
    }

    public function exists(string $disk, string $path): bool
    {
        return Storage::disk($disk)->exists($path);
    }

    public function delete(string $disk, string $path): bool
    {
        return Storage::disk($disk)->delete($path);
    }
}
