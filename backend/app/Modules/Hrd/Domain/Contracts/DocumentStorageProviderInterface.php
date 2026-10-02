<?php

namespace App\Modules\Hrd\Domain\Contracts;

/**
 * Kontrak penyimpanan berkas (§31, §52).
 *
 * HRD memakai penyimpanan dokumen yang sudah ada di ERP (bila modulnya terpasang)
 * atau penyimpanan privat bawaannya. Yang tidak boleh terjadi: berkas pegawai
 * tersimpan di ruang publik tanpa kendali akses.
 */
interface DocumentStorageProviderInterface
{
    /**
     * Simpan berkas privat dan kembalikan jalur penyimpanannya.
     *
     * @param  \Illuminate\Http\UploadedFile|\Illuminate\Http\File  $file
     */
    public function store(mixed $file, string $directory, ?string $disk = null): string;

    /** Hapus berkas (mis. ketika versi dokumen dibatalkan). */
    public function delete(string $path, ?string $disk = null): bool;

    /** Apakah berkas benar-benar ada di penyimpanan? */
    public function exists(string $path, ?string $disk = null): bool;

    /**
     * Tautan sementara berbatas waktu untuk mengunduh berkas privat (§52).
     * Kembalikan null bila penyimpanan tidak mendukung tautan bertanda tangan.
     */
    public function temporaryUrl(string $path, int $minutes, ?string $disk = null): ?string;

    /** Nama disk yang dipakai modul HRD. */
    public function disk(): string;
}
