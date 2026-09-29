<?php

namespace App\Modules\LMS\Domain\Contracts;

/**
 * Sumber konten interaktif (§13).
 *
 * Konten bawaan LMS (Phase 4) dilayani provider lokal; paket luar (H5P, dsb.)
 * dapat didaftarkan kemudian tanpa menyentuh `InteractiveContentService`.
 */
interface InteractiveContentProviderInterface
{
    public function key(): string;

    public function isAvailable(): bool;

    /** @return list<string> tipe konten yang didukung provider ini. */
    public function supportedTypes(): array;

    /** Apakah provider dapat mengimpor paket berkas (mis. `.h5p`)? */
    public function supportsPackages(): bool;

    /**
     * Validasi paket sebelum disimpan (§14: validate dulu, impor kemudian).
     *
     * @return array{valid:bool,type:string|null,title:string|null,version:string|null,errors:list<string>,warnings:list<string>,payload:array<string,mixed>}
     */
    public function validatePackage(string $absolutePath, ?string $originalName = null): array;

    /**
     * Terjemahkan paket menjadi struktur konten LMS.
     *
     * @param  array<string, mixed>  $package  hasil `validatePackage`
     * @return array{type:string,title:string,version:string,payload:array<string,mixed>,metadata:array<string,mixed>}
     */
    public function mapPackage(array $package): array;
}
