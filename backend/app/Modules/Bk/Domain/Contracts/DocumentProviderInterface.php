<?php

namespace App\Modules\Bk\Domain\Contracts;

/**
 * Penyimpanan berkas dokumen kasus (isi berkas), terpisah dari metadatanya.
 */
interface DocumentProviderInterface
{

    /**
     * Simpan berkas dokumen kasus.
     *
     * @param  array<string, mixed>  $meta
     * @return array{provider: string, reference: string, mime: ?string, size: ?int, checksum: ?string}
     */
    public function store(string $contents, string $filename, array $meta = []): array;

    /** Apakah berkas ini masih ada di penyimpanan. */
    public function exists(string $reference): bool;

    /** Unduh isi berkas (null bila tidak ada). */
    public function contents(string $reference): ?string;

    public function delete(string $reference): bool;
}
