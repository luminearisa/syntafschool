<?php

namespace App\Modules\Clinic\Domain\Contracts;

use Illuminate\Http\UploadedFile;

/**
 * Penyimpanan dokumen kesehatan ber-versi pada disk privat.
 *
 * Tidak ada URL publik yang dapat ditebak: berkas hanya dilayani endpoint
 * ber-otorisasi yang memeriksa peran dan kepemilikan lebih dahulu.
 */
interface DocumentProviderInterface
{
    /**
     * @return array{path: string, disk: string, original_name: string, mime_type: ?string, size_bytes: int}
     */
    public function store(UploadedFile $file, int $patientId, string $type): array;

    /** Isi berkas mentah untuk diunduh (sudah melewati pemeriksaan otorisasi pemanggil). */
    public function read(string $disk, string $path): string;

    public function exists(string $disk, string $path): bool;

    public function delete(string $disk, string $path): bool;
}
