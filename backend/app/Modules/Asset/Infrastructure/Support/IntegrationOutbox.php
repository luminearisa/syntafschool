<?php

namespace App\Modules\Asset\Infrastructure\Support;

use App\Modules\Asset\Models\AssetIntegrationOutbox;

/**
 * Outbox integrasi lintas modul.
 *
 * Sebab keberadaannya sederhana: modul Asset harus tetap dapat dipakai sekolah
 * yang belum memasang modul Keuangan/Persediaan/Pengadaan. Niat untuk mencatatkan
 * sesuatu ke modul lain disimpan lebih dulu (PENDING), lalu dikerjakan saat modul
 * tujuannya tersedia. Yang TIDAK boleh terjadi adalah niat itu hilang tanpa jejak
 * hanya karena modul tujuan belum ada.
 */
class IntegrationOutbox
{
    public function push(string $module, string $action, array $payload, ?string $subjectType = null, ?int $subjectId = null): AssetIntegrationOutbox
    {
        return AssetIntegrationOutbox::query()->create([
            'module' => $module,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => $payload,
            'status' => 'PENDING',
            'available_at' => now(),
        ]);
    }

    public function markDispatched(AssetIntegrationOutbox $entry, ?string $reference = null, array $extra = []): AssetIntegrationOutbox
    {
        $entry->forceFill([
            'status' => 'DISPATCHED',
            'dispatched_at' => now(),
            'payload' => array_merge((array) $entry->payload, $extra, $reference !== null ? ['reference' => $reference] : []),
        ])->save();

        return $entry;
    }

    public function markFailed(AssetIntegrationOutbox $entry, string $error): AssetIntegrationOutbox
    {
        $entry->forceFill([
            'status' => 'FAILED',
            'attempts' => (int) $entry->attempts + 1,
            'last_error' => $error,
        ])->save();

        return $entry;
    }
}
