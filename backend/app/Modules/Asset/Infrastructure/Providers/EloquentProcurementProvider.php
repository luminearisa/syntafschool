<?php

namespace App\Modules\Asset\Infrastructure\Providers;

use App\Modules\Asset\Domain\Contracts\ProcurementProviderInterface;
use App\Modules\Asset\Infrastructure\Support\IntegrationOutbox;
use Illuminate\Support\Facades\Schema;

/**
 * Jembatan ke modul Pengadaan.
 *
 * Modul Asset TIDAK membuat purchase order sendiri (itu akan menjadi transaksi
 * pengadaan kedua di ERP). Permintaan penggantian aset karena itu dititipkan:
 * bila modul Pengadaan tersedia, permintaan dibuat di sana; bila belum, ia masuk
 * outbox agar dapat diproses saat modulnya dipasang.
 */
class EloquentProcurementProvider implements ProcurementProviderInterface
{
    public function __construct(private readonly IntegrationOutbox $outbox) {}

    public function request(array $payload): array
    {
        $entry = $this->outbox->push('procurement', 'request', $payload, 'asset', (int) ($payload['asset_id'] ?? 0) ?: null);

        return [
            'status' => 'PENDING',
            'reference' => 'PR-'.$entry->id,
            'reason' => 'Permintaan pengadaan dicatat pada outbox integrasi (modul Pengadaan tidak dipanggil langsung dari sini).',
        ];
    }

    public function status(string $reference): ?array
    {
        if (! str_starts_with($reference, 'PR-')) {
            return null;
        }

        $entry = \App\Modules\Asset\Models\AssetIntegrationOutbox::query()->find((int) substr($reference, 3));

        return $entry === null ? null : [
            'reference' => $reference,
            'status' => $entry->status?->value ?? (string) $entry->status,
            'payload' => $entry->payload,
            'dispatched_at' => $entry->dispatched_at?->toDateTimeString(),
        ];
    }

    public function receipts(array $filters): array
    {
        // Penerimaan barang dibaca dari modul Pengadaan bila tersedia. Tabel
        // purchase order milik modul Keuangan BUKAN sumber kebenaran penerimaan,
        // jadi tidak dibaca di sini.
        return [];
    }

    public function markRecognized(string $reference, array $assetCodes): array
    {
        $updated = 0;

        if (Schema::hasTable('asset_integration_outbox')) {
            $entries = \App\Modules\Asset\Models\AssetIntegrationOutbox::query()
                ->where('module', 'procurement')
                ->where('status', 'PENDING')
                ->get();

            foreach ($entries as $entry) {
                if (($entry->payload['reference'] ?? null) === $reference || str_contains((string) $entry->payload['title'] ?? '', $reference)) {
                    $this->outbox->markDispatched($entry, $reference, ['asset_codes' => $assetCodes]);
                    $updated++;
                }
            }
        }

        return ['status' => 'OK', 'recognized' => $updated, 'asset_codes' => $assetCodes];
    }
}
