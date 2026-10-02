<?php

namespace App\Modules\Hrd\Core\Domain\Contracts;

/**
 * Titik integrasi ke luar modul HRD: AI HR (asisten).
 *
 * Bila modul tujuan belum terpasang, implementasi bawaan mengembalikan jawaban
 * "belum tersedia" dan peristiwanya tetap tercatat di outbox — bukan membuat
 * modul HRD gagal. Pola ini sama dengan Phase 1 dan menjaga batas modul.
 */
interface AIHRProviderInterface
{
    /**
     * Kemampuan yang tersedia pada penyedia aktif.
     *
     * @return array<string, mixed>
     */
    public function capabilities(): array;
    /**
     * Menghasilkan saran/draf yang wajib ditinjau manusia.
     *
     * @return array<string, mixed>
     */
    public function suggest(string $capability, array $input, array $context): array;
    /**
     * Menyaring kolom sensitif sebelum dikirim ke penyedia.
     *
     * @return array<string, mixed>
     */
    public function redact(array $payload, array $allowedFields): array;
}
