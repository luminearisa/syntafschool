<?php

namespace App\Modules\Procurement\Domain\Contracts;

/**
 * Document — pembuatan dokumen cetak (PR, RFQ, PO, GR, dsb.).
 *
 * Procurement menyusun datanya; rendering PDF/berkas diserahkan ke penyedia
 * dokumen sehingga tidak bergantung pada satu pustaka tertentu.
 */
interface DocumentProviderInterface
{
    /**
     * Hasilkan dokumen dari template.
     *
     * @param  array<string, mixed>  $payload  {type, data, format}
     * @return array{path: string, url: ?string, mime: string}
     */
    public function generate(array $payload): array;

    /**
     * Simpan lampiran yang diunggah (delivery note, invoice scan, dsb.).
     *
     * @param  array<string, mixed>  $payload
     * @return array{path: string, url: ?string}
     */
    public function store(array $payload): array;
}
