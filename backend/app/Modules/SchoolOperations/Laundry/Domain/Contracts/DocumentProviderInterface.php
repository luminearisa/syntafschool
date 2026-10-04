<?php

namespace App\Modules\SchoolOperations\Laundry\Domain\Contracts;

/** Dokumen (§31). Penyimpanan berkas label/foto bila penyedia dokumen ada. */
interface DocumentProviderInterface
{
    /**
     * @param  array{kind:string, content:string, filename?:string, reference?:string}  $payload
     * @return array{stored:bool, path?:string|null, reason?:string}
     */
    public function store(array $payload): array;

    public function available(): bool;
}
