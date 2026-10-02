<?php

namespace App\Modules\Hrd\Core\Infrastructure;

use App\Modules\Hrd\Core\Domain\Contracts\AIHRProviderInterface;

/**
 * Implementasi bawaan untuk AI HR (asisten) yang belum terpasang.
 *
 * Pola ini menjaga batas modul: HRD tetap berjalan penuh, peristiwanya tetap
 * tercatat, dan begitu modul tujuan tersedia cukup mengganti binding tanpa
 * mengubah satu pun layanan bisnis.
 */
class NullAIHRProvider implements AIHRProviderInterface
{
    /** @return array<string, mixed> */
    public function capabilities(): array
    {
        return ['status' => 'DISABLED', 'note' => 'Penyedia AI belum dikonfigurasi (hrd_phase2.ai.provider).'];
    }

    /** @return array<string, mixed> */
    public function suggest(string $capability, array $input, array $context): array
    {
        return ['status' => 'DISABLED', 'suggestion' => null, 'note' => 'AI tidak aktif; saran tetap wajib ditinjau manusia bila diaktifkan.'];
    }

    /** @return array<string, mixed> */
    public function redact(array $payload, array $allowedFields): array
    {
        $allowed = [];

        foreach ($payload as $key => $value) {
            if (! in_array($key, $allowedFields, true)) {
                $allowed[$key] = $value;
            }
        }

        return $allowed;
    }
}
