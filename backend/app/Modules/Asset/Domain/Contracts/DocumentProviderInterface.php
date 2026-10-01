<?php

namespace App\Modules\Asset\Domain\Contracts;

/**
 * Dokumen — penyimpanan berkas (faktur, garansi, manual, berita acara).
 *
 * Modul Asset hanya menyimpan rujukan. Bila penyedia dokumen belum terpasang,
 * binding bawaan menyimpan berkas di penyimpanan privat dan tetap mencatat
 * rujukannya, sehingga dokumen tidak pernah menjadi "tautan yang hilang".
 */
interface DocumentProviderInterface
{
    /** Simpan berkas; mengembalikan rujukan + URL bertanda tangan bila ada. */
    public function store(array $payload): array;

    /** URL sementara (bertanda tangan) untuk dokumen privat. */
    public function temporaryUrl(string $reference, int $minutes = 15): ?string;

    public function delete(string $reference): bool;

    public function exists(string $reference): bool;
}
