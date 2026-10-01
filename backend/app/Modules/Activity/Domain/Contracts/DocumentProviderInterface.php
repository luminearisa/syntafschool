<?php

namespace App\Modules\Activity\Domain\Contracts;

/**
 * Document Management — bukti prestasi, lampiran, berkas sertifikat (§66).
 */
interface DocumentProviderInterface
{
    public function store(string $path, array $meta = []): array;

    public function url(string $path): ?string;

    public function delete(string $path): bool;
}
