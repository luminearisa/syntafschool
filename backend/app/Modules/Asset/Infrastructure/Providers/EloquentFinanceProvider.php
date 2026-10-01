<?php

namespace App\Modules\Asset\Infrastructure\Providers;

use App\Modules\Asset\Domain\Contracts\FinanceProviderInterface;
use App\Modules\Asset\Infrastructure\Support\IntegrationOutbox;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Jembatan ke modul Keuangan.
 *
 * Bila modul Keuangan tersedia (model `FinanceAsset` + layanan akuntansinya),
 * perolehan, penyusutan, dan penghapusan benar-benar dicatat di sana. Bila belum,
 * niat itu masuk outbox sebagai PENDING — sehingga rekonsiliasi aset vs keuangan
 * tetap dapat dilakukan, bukan menjadi selisih yang tak dijelaskan.
 */
class EloquentFinanceProvider implements FinanceProviderInterface
{
    public function __construct(private readonly IntegrationOutbox $outbox) {}

    public function capitalize(array $payload): array
    {
        $financeAssetId = $this->upsertFinanceAsset($payload);

        if ($financeAssetId === null) {
            return $this->pending('capitalize', $payload);
        }

        return ['status' => 'DISPATCHED', 'finance_asset_id' => $financeAssetId, 'reference' => 'FA-'.$financeAssetId];
    }

    public function depreciate(int $assetId, string $period, array $context = []): array
    {
        $service = $this->accountingService('DepreciationService');

        if ($service !== null && method_exists($service, 'run')) {
            try {
                $service->run($period, $this->actor($context), $this->financeAssetId($assetId));

                return ['status' => 'DISPATCHED', 'reference' => 'DEP-'.$period];
            } catch (\Throwable $exception) {
                // Gagal di sisi Keuangan TIDAK boleh membatalkan penyusutan lokal
                // (data operasional) — niatnya dipindahkan ke outbox untuk dicoba lagi.
                return $this->pending('depreciate', ['asset_id' => $assetId, 'period' => $period] + $context, $exception->getMessage());
            }
        }

        return $this->pending('depreciate', ['asset_id' => $assetId, 'period' => $period] + $context);
    }

    public function dispose(array $payload): array
    {
        $service = $this->accountingService('AssetService');
        $financeAssetId = $this->financeAssetId((int) ($payload['asset_id'] ?? 0));

        if ($service !== null && $financeAssetId !== null && method_exists($service, 'dispose')) {
            try {
                $financeAsset = \App\Models\FinanceAsset::query()->find($financeAssetId);

                if ($financeAsset !== null) {
                    $transaction = $service->dispose($financeAsset, [
                        'tanggal' => $payload['effective_date'] ?? now()->toDateString(),
                        'hasil_pelepasan' => $payload['proceeds'] ?? '0.00',
                        'keterangan' => $payload['reason'] ?? null,
                    ], $this->actor($payload));

                    return ['status' => 'DISPATCHED', 'reference' => (string) ($transaction->id ?? '')];
                }
            } catch (\Throwable $exception) {
                return $this->pending('dispose', $payload, $exception->getMessage());
            }
        }

        return $this->pending('dispose', $payload);
    }

    public function revalue(array $payload): array
    {
        return $this->pending('revalue', $payload);
    }

    public function recordMaintenanceCost(array $payload): array
    {
        return $this->pending('maintenance_cost', $payload);
    }

    public function bookValue(int $assetId): ?string
    {
        $financeAssetId = $this->financeAssetId($assetId);

        if ($financeAssetId === null || ! class_exists(\App\Models\FinanceAsset::class)) {
            return null;
        }

        $value = \App\Models\FinanceAsset::query()->whereKey($financeAssetId)->value('nilai_buku');

        return $value === null ? null : (string) $value;
    }

    protected function upsertFinanceAsset(array $payload): ?int
    {
        if (! class_exists(\App\Models\FinanceAsset::class) || ! Schema::hasTable('finance_assets')) {
            return null;
        }

        $service = $this->accountingService('AssetService');

        if ($service !== null && method_exists($service, 'save')) {
            try {
                $asset = $service->save([
                    'kode_aset' => $payload['asset_code'] ?? ('AST-'.$payload['asset_id']),
                    'nama' => $payload['name'] ?? 'Aset',
                    'kategori' => (string) ($payload['category'] ?? 'UMUM'),
                    'tanggal_perolehan' => $payload['capitalization_date'] ?? now()->toDateString(),
                    'harga_perolehan' => $payload['acquisition_cost'] ?? '0.00',
                    'nilai_residu' => $payload['residual_value'] ?? '0.00',
                    'umur_bulan' => (int) ($payload['useful_life_months'] ?? 0),
                    'metode' => $payload['method'] ?? 'STRAIGHT_LINE',
                    'keterangan' => 'Dibuat dari modul Asset & Sarpras (aset #'.$payload['asset_id'].').',
                ], null, $this->actor($payload));

                return (int) ($asset->id ?? 0) ?: null;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    protected function financeAssetId(int $assetId): ?int
    {
        if ($assetId <= 0) {
            return null;
        }

        $value = \App\Modules\Asset\Models\Asset::query()->whereKey($assetId)->value('finance_asset_id');

        return $value === null ? null : (int) $value;
    }

    protected function accountingService(string $name): ?object
    {
        $class = 'App\\Services\\Finance\\Accounting\\'.$name;

        return class_exists($class) ? app($class) : null;
    }

    protected function actor(array $payload): User
    {
        $actorId = $payload['actor_id'] ?? null;

        return ($actorId !== null ? User::query()->find($actorId) : null) ?? $this->systemUser();
    }

    /** Pengguna sistem untuk pekerjaan terjadwal yang tidak dimulai seseorang. */
    protected function systemUser(): User
    {
        return User::query()->orderBy('id')->firstOrFail();
    }

    protected function pending(string $action, array $payload, ?string $error = null): array
    {
        $entry = $this->outbox->push('finance', $action, $payload, 'asset', (int) ($payload['asset_id'] ?? 0) ?: null);

        if ($error !== null) {
            $this->outbox->markFailed($entry, $error);
        }

        return [
            'status' => 'PENDING',
            'reference' => 'OUTBOX-'.$entry->id,
            'reason' => $error ?? 'Modul Keuangan belum tersedia; niat dicatat untuk dikerjakan kemudian.',
        ];
    }
}
