<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Domain\Enums\AssetStatus;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Models\Asset;
use Illuminate\Support\Facades\DB;

/**
 * Operasi massal: menugaskan, memindahkan, melabeli, memeriksa, dan mengubah
 * status banyak aset sekaligus.
 *
 * Setiap operasi mengembalikan ringkasan hasil per aset — berhasil, dilewati,
 * beserta alasannya. Operasi massal yang hanya menjawab "berhasil" untuk 40 dari
 * 50 aset adalah operasi massal yang tidak dapat dipercaya.
 */
class AssetBulkService
{
    public function __construct(
        private readonly AssetRegisterService $register,
        private readonly AssetAuditService $audit,
        private readonly AssetAccessService $access,
    ) {}

    /** @param list<int> $assetIds */
    public function changeStatus(User $actor, array $assetIds, AssetStatus $status, ?string $reason = null): array
    {
        $this->access->assertCanManage($actor);

        return $this->each($assetIds, function (Asset $asset) use ($actor, $status, $reason): array {
            $this->register->changeStatus($actor, $asset, $status, $reason);

            return ['asset_code' => $asset->asset_code, 'status' => $status->value];
        });
    }

    /** @param list<int> $assetIds */
    public function assign(User $actor, array $assetIds, int $custodianId, ?int $locationId = null): array
    {
        $this->access->assertCanManage($actor);

        return $this->each($assetIds, function (Asset $asset) use ($actor, $custodianId, $locationId): array {
            $location = $locationId ?? $asset->location_id;

            if ($location === null) {
                throw new AssetDomainException('Aset belum punya lokasi; isi lokasi tujuan untuk penugasan massal.', 422);
            }

            $this->register->place($actor, $asset, (int) $location, $custodianId);

            return ['asset_code' => $asset->asset_code, 'custodian_id' => $custodianId];
        });
    }

    /** @param list<int> $assetIds */
    public function markPrinted(User $actor, array $assetIds): array
    {
        $this->access->assertCanManage($actor);

        $tags = app(AssetTagService::class)->markPrinted($actor, $assetIds);

        return ['printed' => count($tags), 'tags' => $tags];
    }

    /** @param list<int> $assetIds */
    public function inspect(User $actor, array $assetIds, string $inspectionType = 'ROUTINE'): array
    {
        return $this->each($assetIds, function (Asset $asset) use ($actor, $inspectionType): array {
            $inspection = app(InspectionService::class)->schedule($actor, [
                'asset_id' => $asset->id,
                'inspection_type' => $inspectionType,
                'scheduled_at' => now(),
            ]);

            return ['asset_code' => $asset->asset_code, 'inspection_number' => $inspection->inspection_number];
        });
    }

    /**
     * Jalankan operasi untuk setiap aset dengan ringkasan hasil.
     *
     * @param list<int> $assetIds
     * @return array{processed:int, succeeded:int, failed:int, results:list<array<string,mixed>>}
     */
    protected function each(array $assetIds, callable $callback): array
    {
        $results = [];
        $succeeded = 0;

        $assets = Asset::query()->whereIn('id', $assetIds)->get();

        foreach ($assets as $asset) {
            try {
                $results[] = ['asset_id' => $asset->id, 'ok' => true] + $callback($asset);
                $succeeded++;
            } catch (\Throwable $exception) {
                // Satu aset gagal tidak menggagalkan seluruh operasi, tetapi
                // kegagalannya WAJIB terlihat — bukan diabaikan diam-diam.
                $results[] = [
                    'asset_id' => $asset->id,
                    'asset_code' => $asset->asset_code,
                    'ok' => false,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        if ($succeeded > 0) {
            $this->audit->record(AuditAction::ASSET_UPDATED, $assets->first() ?? new Asset(), null, [
                'reason' => sprintf('Operasi massal pada %d aset (%d berhasil).', count($assetIds), $succeeded),
                'context' => ['requested' => count($assetIds), 'succeeded' => $succeeded, 'assets' => array_column($results, 'asset_code')],
            ]);
        }

        return [
            'processed' => $assets->count(),
            'succeeded' => $succeeded,
            'failed' => $assets->count() - $succeeded,
            'results' => $results,
        ];
    }
}
