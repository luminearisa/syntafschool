<?php

namespace App\Modules\Asset\Domain\Contracts;

/**
 * Aset — apa yang modul Asset TAWARKAN kepada modul lain.
 *
 * Modul lain (mis. Kegiatan, Asrama) tidak menyentuh tabel `assets`; mereka
 * bertanya lewat kontrak ini, sehingga bentuk data aset dapat berubah tanpa
 * memecah pemakainya.
 */
interface AssetProviderInterface
{
    /** Ringkasan aman sebuah aset (tanpa data keuangan sensitif). */
    public function info(int $assetId): ?array;

    /** Pinjamkan aset untuk sebuah kegiatan/kebutuhan; mengembalikan data pinjaman. */
    public function lend(array $payload): array;

    /** Catat pengembalian berdasarkan nomor pinjaman atau id aset. */
    public function collect(array $payload): array;

    /** Aset yang bebas dipinjam pada rentang waktu tertentu. */
    public function available(array $filters): array;
}
