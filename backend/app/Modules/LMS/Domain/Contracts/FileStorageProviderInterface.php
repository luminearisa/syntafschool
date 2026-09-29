<?php

namespace App\Modules\LMS\Domain\Contracts;

use Illuminate\Http\UploadedFile;

/**
 * Penyimpanan berkas LMS (§28, §4).
 *
 * Seluruh berkas LMS melewati gerbang ini supaya visibilitas (PUBLIC, COURSE,
 * PRIVATE, SUBMISSION, TEACHER_ONLY, ADMIN_ONLY) selalu diterapkan dan path
 * penyimpanan pribadi tidak pernah dibocorkan ke klien.
 */
interface FileStorageProviderInterface
{
    public function key(): string;

    public function isAvailable(): bool;

    /** Nama disk yang dipakai (mis. `local`, `public`, `s3`). */
    public function disk(): string;

    /**
     * Simpan berkas.
     *
     * @param  string  $directory  mis. `lms/submissions/2026`
     * @param  string  $visibility  PUBLIC|COURSE|PRIVATE|SUBMISSION|TEACHER_ONLY|ADMIN_ONLY
     * @return array{path:string,disk:string,visibility:string,size:int,mime:string|null,checksum:string}
     */
    public function store(UploadedFile $file, string $directory, string $visibility = 'PRIVATE', ?string $name = null): array;

    /** Simpan isi mentah (mis. hasil ekspor laporan) tanpa unggahan HTTP. */
    public function put(string $directory, string $name, string $contents, string $visibility = 'PRIVATE'): array;

    public function exists(string $path): bool;

    public function delete(string $path): bool;

    /** Berapa lama berkas sementara disimpan sebelum boleh disapu (§39). */
    public function temporaryLifetimeHours(): int;
}
