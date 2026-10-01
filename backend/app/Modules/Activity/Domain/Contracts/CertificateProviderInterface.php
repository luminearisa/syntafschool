<?php

namespace App\Modules\Activity\Domain\Contracts;

/**
 * Penerbitan berkas sertifikat (§52). Nomor, kode verifikasi, dan riwayat
 * penerbitan tetap dicatat modul Activity; kontrak ini hanya menyiapkan berkas
 * dan menyimpannya melalui Document Management.
 */
interface CertificateProviderInterface
{
    /** Siapkan berkas sertifikat; kembalikan `['path' => ..., 'reference' => ...]`. */
    public function render(array $payload): array;

    public function revoke(string $path): bool;
}
