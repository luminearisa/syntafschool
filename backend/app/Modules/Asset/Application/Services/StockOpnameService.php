<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Application\Support\AssetNumberGenerator;
use App\Modules\Asset\Domain\Enums\AssetStatus;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Domain\Enums\StockOpnameScanResult;
use App\Modules\Asset\Domain\Enums\StockOpnameStatus;
use App\Modules\Asset\Domain\Enums\VarianceDecision;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Domain\StateMachines\StockOpnameStateMachine;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetStockOpname;
use App\Modules\Asset\Models\AssetStockOpnameItem;
use App\Modules\Asset\Models\AssetStockOpnameVariance;
use App\Modules\Asset\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * Stok opname: penghitungan fisik yang jujur.
 *
 * Aturan yang membuat modul ini dapat dipercaya oleh auditor:
 *  - selisih TIDAK pernah langsung mengubah status aset — ia menjadi baris
 *    selisih dengan keputusan yang harus diambil;
 *  - penyesuaian hanya diterapkan setelah selisih disetujui;
 *  - barang tak dikenal (kode dipindai tetapi tidak ada di daftar harapan) tetap
 *    dicatat sebagai EXTRA/UNIDENTIFIED, bukan dibuang.
 *
 * Tanpa aturan itu, opname berubah menjadi alat untuk "merapikan" catatan.
 */
class StockOpnameService
{
    public function __construct(
        private readonly AssetNumberGenerator $numbers,
        private readonly AssetAuditService $audit,
        private readonly AssetNotificationService $notifications,
        private readonly AssetAccessService $access,
    ) {}

    /**
     * Buat sesi opname dan susun daftar aset yang DIHARAPKAN ada.
     *
     * @param array{campus_id?:int, building_id?:int, location_id?:int, category_id?:int, unit_id?:int} $scope
     */
    public function create(User $actor, array $scope, array $data = []): AssetStockOpname
    {
        return DB::transaction(function () use ($actor, $scope, $data): AssetStockOpname {
            $opname = AssetStockOpname::query()->create([
                'opname_number' => $this->numbers->next('stock_opname', ['tahun' => now()->year]),
                'title' => $data['title'] ?? 'Stok opname '.now()->toDateString(),
                'status' => StockOpnameStatus::PLANNED->value,
                'scope' => $scope,
                'method' => $data['method'] ?? 'MANUAL',
                'scheduled_for' => $data['scheduled_for'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
            ]);

            $assets = $this->expectedAssets($scope);
            $count = 0;

            foreach ($assets as $asset) {
                $opname->items()->create([
                    'asset_id' => $asset->id,
                    'expected_location_id' => $asset->location_id,
                    'expected_custodian_id' => $asset->custodian_id,
                    'scan_result' => StockOpnameScanResult::MISSING->value,
                    'is_variance' => false,
                ]);

                $count++;
            }

            $opname->forceFill(['expected_count' => $count])->save();

            $this->audit->record(AuditAction::STOCK_OPNAME_CREATED, $opname, $actor, [
                'status_after' => $opname->status,
                'reason' => 'Sesi stok opname dibuat.',
                'context' => ['scope' => $scope, 'expected_count' => $count],
            ]);

            return $opname->refresh();
        });
    }

    public function start(User $actor, AssetStockOpname $opname): AssetStockOpname
    {
        StockOpnameStateMachine::assert($this->statusValue($opname), StockOpnameStatus::IN_PROGRESS->value, 'stok opname');

        $opname->forceFill([
            'status' => StockOpnameStatus::IN_PROGRESS->value,
            'started_at' => now(),
            'started_by' => $actor->id,
        ])->save();

        $this->audit->record(AuditAction::STOCK_OPNAME_STARTED, $opname, $actor, [
            'status_after' => $opname->status,
            'reason' => 'Penghitungan dimulai.',
        ]);

        return $opname->refresh();
    }

    /**
     * Pindai satu kode: cocokkan dengan daftar harapan.
     *
     * Hasil pindai menyimpan juga KODE YANG DIPINDAI, sehingga barang tak dikenal
     * dapat ditelusuri kemudian — bukan sekadar dihitung sebagai "kelebihan".
     */
    public function scan(User $actor, AssetStockOpname $opname, string $code, array $data = []): AssetStockOpnameItem
    {
        if (! in_array($this->statusValue($opname), [StockOpnameStatus::IN_PROGRESS->value, StockOpnameStatus::COUNTING->value], true)) {
            throw new AssetDomainException('Penghitungan belum dimulai pada sesi opname ini.', 409);
        }

        $asset = Asset::query()
            ->where('asset_code', $code)
            ->orWhere('barcode', $code)
            ->orWhere('qr_token', $code)
            ->orWhere('asset_tag', $code)
            ->orWhere('serial_number', $code)
            ->first();

        return DB::transaction(function () use ($actor, $opname, $code, $data, $asset): AssetStockOpnameItem {
            $item = $asset !== null
                ? $opname->items()->where('asset_id', $asset->id)->lockForUpdate()->first()
                : null;

            $locationId = $data['location_id'] ?? $asset?->location_id;
            $result = $this->classify($asset, $item, $locationId, $data);

            if ($item === null) {
                // Barang di luar daftar harapan: dicatat sebagai baris baru, bukan diabaikan.
                $item = $opname->items()->create([
                    'asset_id' => $asset?->id,
                    'expected_location_id' => null,
                    'expected_custodian_id' => null,
                    'scanned_code' => $code,
                    'scanned_at' => now(),
                    'scanned_by' => $actor->id,
                    'scan_result' => $result->value,
                    'actual_location_id' => $locationId,
                    'actual_custodian_id' => $data['custodian_id'] ?? $asset?->custodian_id,
                    'condition_found' => $data['condition'] ?? $asset?->condition,
                    'is_variance' => $result->isVariance(),
                    'variance_decision' => $result->isVariance() ? VarianceDecision::PENDING->value : VarianceDecision::ACCEPTED->value,
                    'notes' => $data['notes'] ?? 'Tidak ada dalam daftar harapan.',
                ]);
            } else {
                $item->forceFill([
                    'scanned_code' => $code,
                    'scanned_at' => now(),
                    'scanned_by' => $actor->id,
                    'scan_result' => $result->value,
                    'actual_location_id' => $locationId,
                    'actual_custodian_id' => $data['custodian_id'] ?? $asset?->custodian_id,
                    'condition_found' => $data['condition'] ?? $item->condition_found,
                    'is_variance' => $result->isVariance(),
                    'variance_decision' => $result->isVariance() ? VarianceDecision::PENDING->value : VarianceDecision::ACCEPTED->value,
                    'notes' => $data['notes'] ?? $item->notes,
                ])->save();
            }

            if ($result->isVariance()) {
                $this->recordVariance($opname, $item, $result, $data);
            }

            $this->refreshCounters($opname);

            $this->audit->record(AuditAction::STOCK_OPNAME_SCANNED, $opname, $actor, [
                'reason' => 'Pindai '.$code.': '.$result->value,
                'context' => ['asset_id' => $asset?->id, 'result' => $result->value, 'location_id' => $locationId],
            ]);

            return $item->refresh();
        });
    }

    /** Pindah ke verifikasi: selisih wajib punya baris selisih. */
    public function moveToVerification(User $actor, AssetStockOpname $opname): AssetStockOpname
    {
        StockOpnameStateMachine::assert($this->statusValue($opname), StockOpnameStatus::VERIFICATION->value, 'stok opname');

        $this->markUnscannedAsMissing($opname);

        $opname->forceFill([
            'status' => StockOpnameStatus::VERIFICATION->value,
            'verified_by' => $actor->id,
            'verified_at' => now(),
        ])->save();

        return $opname->refresh();
    }

    /** Keputusan atas satu selisih (diterima/diperiksa/ditolak). */
    public function decideVariance(User $actor, AssetStockOpname $opname, AssetStockOpnameVariance $variance, VarianceDecision $decision, array $data = []): AssetStockOpnameVariance
    {
        if ((int) $variance->opname_id !== (int) $opname->id) {
            throw new AssetValidationException(['variance' => ['Selisih ini bukan milik sesi opname tersebut.']]);
        }

        $variance->forceFill([
            'decision' => $decision->value,
            'investigated_by' => $decision === VarianceDecision::INVESTIGATED ? $actor->id : $variance->investigated_by,
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'notes' => $data['notes'] ?? $variance->notes,
        ])->save();

        if ($variance->item_id !== null) {
            $item = AssetStockOpnameItem::query()->find($variance->item_id);

            if ($item !== null) {
                $item->forceFill(['variance_decision' => $decision->value])->save();
            }
        }

        $this->audit->record(AuditAction::VARIANCE_CREATED, $variance, $actor, [
            'reason' => $data['notes'] ?? 'Keputusan selisih: '.$decision->value,
            'context' => ['variance_type' => $variance->variance_type, 'decision' => $decision->value],
        ]);

        return $variance->refresh();
    }

    /**
     * Selesaikan opname: HANYA selisih yang disetujui diterapkan.
     *
     * Penyesuaian yang diterapkan dicatat pada baris selisih (`adjustment`),
     * sehingga "apa yang diubah sistem setelah opname" dapat diperiksa satu per
     * satu — bukan tersembunyi di dalam status aset.
     */
    public function complete(User $actor, AssetStockOpname $opname, array $data = []): AssetStockOpname
    {
        StockOpnameStateMachine::assert($this->statusValue($opname), StockOpnameStatus::APPROVAL->value, 'stok opname');

        return DB::transaction(function () use ($actor, $opname, $data): AssetStockOpname {
            $opname->forceFill([
                'status' => StockOpnameStatus::APPROVAL->value,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ])->save();

            $applied = [];

            foreach ($opname->variances()->whereIn('decision', [VarianceDecision::ACCEPTED->value, VarianceDecision::ADJUSTED->value, VarianceDecision::INVESTIGATED->value])->get() as $variance) {
                $item = $variance->item_id !== null ? AssetStockOpnameItem::query()->find($variance->item_id) : null;
                $asset = $variance->asset_id !== null ? Asset::query()->find($variance->asset_id) : null;

                if ($asset === null) {
                    continue;
                }

                $changes = $this->applyVariance($actor, $asset, $item, $variance);

                $variance->forceFill([
                    'decision' => VarianceDecision::ADJUSTED->value,
                    'adjustment' => $changes,
                ])->save();

                if ($item !== null) {
                    $item->forceFill([
                        'adjustment_applied_at' => now(),
                        'adjustment_notes' => json_encode($changes),
                    ])->save();
                }

                $applied[] = ['variance_id' => $variance->id, 'asset_id' => $asset->id, 'changes' => $changes];

                $this->audit->record(AuditAction::VARIANCE_ADJUSTED, $variance, $actor, [
                    'reason' => 'Penyesuaian setelah opname '.$opname->opname_number.'.',
                    'context' => $changes,
                ]);
            }

            $opname->forceFill([
                'status' => StockOpnameStatus::COMPLETED->value,
                'finished_at' => now(),
            ])->save();

            $this->refreshCounters($opname);

            $this->notifications->notify(
                [(int) $opname->started_by],
                ['ASSET_ADMIN', 'AUDITOR'],
                'STOCK_OPNAME_COMPLETED',
                'Stok opname selesai: '.$opname->opname_number,
                sprintf('%d aset diharapkan, %d ditemukan, %d selisih.', $opname->expected_count, $opname->found_count, $opname->variance_count),
                $opname,
                ['applied_adjustments' => count($applied)],
            );

            $this->audit->record(AuditAction::STOCK_OPNAME_COMPLETED, $opname, $actor, [
                'status_before' => StockOpnameStatus::APPROVAL->value,
                'status_after' => StockOpnameStatus::COMPLETED->value,
                'reason' => 'Opname selesai dan penyesuaian diterapkan.',
                'context' => ['applied' => count($applied)],
            ]);

            return $opname->refresh();
        });
    }

    /** Hasil opname per kategori untuk laporan. */
    public function summary(AssetStockOpname $opname): array
    {
        $items = $opname->items()->with('asset:id,asset_code,name,category_id,criticality')->get();

        return [
            'expected' => (int) $opname->expected_count,
            'found' => (int) $opname->found_count,
            'missing' => $opname->variances()->where('variance_type', StockOpnameScanResult::MISSING->value)->count(),
            'extra' => $opname->variances()->where('variance_type', StockOpnameScanResult::EXTRA->value)->count(),
            'wrong_location' => $opname->variances()->where('variance_type', StockOpnameScanResult::WRONG_LOCATION->value)->count(),
            'wrong_custodian' => $opname->variances()->where('variance_type', StockOpnameScanResult::WRONG_CUSTODIAN->value)->count(),
            'damaged' => $opname->variances()->where('variance_type', StockOpnameScanResult::DAMAGED->value)->count(),
            'unidentified' => $opname->variances()->where('variance_type', StockOpnameScanResult::UNIDENTIFIED->value)->count(),
            'pending_decisions' => $opname->variances()->where('decision', VarianceDecision::PENDING->value)->count(),
            'lines' => $items->count(),
        ];
    }

    /** Susun daftar harapan menurut lingkup opname. */
    protected function expectedAssets(array $scope)
    {
        $query = Asset::query()->whereNotIn('status', [AssetStatus::DISPOSED->value, AssetStatus::RETIRED->value]);

        if (isset($scope['location_id'])) {
            $location = Location::query()->find($scope['location_id']);

            if ($location !== null) {
                $query->whereIn('location_id', app(LocationService::class)->descendantIds($location));
            }
        } elseif (isset($scope['building_id'])) {
            $query->whereHas('location', fn ($q) => $q->where('building_id', $scope['building_id']));
        } elseif (isset($scope['campus_id'])) {
            $query->whereHas('location', fn ($q) => $q->where('campus_id', $scope['campus_id']));
        }

        if (isset($scope['category_id'])) {
            $query->where('category_id', $scope['category_id']);
        }

        if (isset($scope['unit_id'])) {
            $query->where('unit_id', $scope['unit_id']);
        }

        return $query->orderBy('asset_code')->get();
    }

    protected function classify(?Asset $asset, ?AssetStockOpnameItem $item, ?int $locationId, array $data): StockOpnameScanResult
    {
        if ($asset === null) {
            return StockOpnameScanResult::UNIDENTIFIED;
        }

        if ($item === null) {
            return StockOpnameScanResult::EXTRA;
        }

        if (($data['condition'] ?? null) !== null && in_array($data['condition'], ['DAMAGED', 'CRITICAL', 'UNUSABLE'], true)) {
            return StockOpnameScanResult::DAMAGED;
        }

        if ($locationId !== null && (int) $item->expected_location_id !== $locationId) {
            return StockOpnameScanResult::WRONG_LOCATION;
        }

        if (isset($data['custodian_id']) && (int) $item->expected_custodian_id !== (int) $data['custodian_id']) {
            return StockOpnameScanResult::WRONG_CUSTODIAN;
        }

        return StockOpnameScanResult::FOUND;
    }

    protected function recordVariance(AssetStockOpname $opname, AssetStockOpnameItem $item, StockOpnameScanResult $result, array $data): AssetStockOpnameVariance
    {
        return AssetStockOpnameVariance::query()->create([
            'opname_id' => $opname->id,
            'item_id' => $item->id,
            'asset_id' => $item->asset_id,
            'variance_type' => $result->value,
            'description' => $data['notes'] ?? 'Selisih terdeteksi saat penghitungan.',
            'decision' => VarianceDecision::PENDING->value,
        ]);
    }

    /** Terapkan penyesuaian yang disetujui (dan hanya itu). */
    protected function applyVariance(User $actor, Asset $asset, ?AssetStockOpnameItem $item, AssetStockOpnameVariance $variance): array
    {
        $changes = [];
        $type = $variance->variance_type instanceof StockOpnameScanResult
            ? $variance->variance_type->value
            : (string) $variance->variance_type;

        if ($type === StockOpnameScanResult::WRONG_LOCATION->value && $item?->actual_location_id !== null) {
            $changes['location_id'] = ['from' => $asset->location_id, 'to' => $item->actual_location_id];
            $asset->forceFill(['location_id' => $item->actual_location_id])->save();
        }

        if ($type === StockOpnameScanResult::WRONG_CUSTODIAN->value && $item?->actual_custodian_id !== null) {
            $changes['custodian_id'] = ['from' => $asset->custodian_id, 'to' => $item->actual_custodian_id];
            $asset->forceFill(['custodian_id' => $item->actual_custodian_id])->save();
        }

        if ($type === StockOpnameScanResult::DAMAGED->value && $item?->condition_found !== null) {
            $changes['condition'] = ['from' => $asset->condition?->value ?? (string) $asset->condition, 'to' => $item->condition_found];
            app(AssetRegisterService::class)->changeCondition(
                $actor, $asset,
                \App\Modules\Asset\Domain\Enums\AssetCondition::from((string) $item->condition_found),
                'Penyesuaian opname '.$variance->opname_id.'.',
            );
        }

        if ($type === StockOpnameScanResult::MISSING->value) {
            // Barang tidak ditemukan: dicatat sebagai investigasi, TIDAK langsung
            // dijadikan "hilang". Keputusan itu milik manusia (insiden terpisah).
            $changes['status'] = ['from' => $asset->status?->value ?? (string) $asset->status, 'to' => 'UNDER_INVESTIGATION'];
            $asset->forceFill(['status' => AssetStatus::UNDER_INVESTIGATION->value])->save();
        }

        return $changes;
    }

    protected function refreshCounters(AssetStockOpname $opname): void
    {
        $opname->forceFill([
            // "Ditemukan" = aset DARI DAFTAR HARAPAN yang benar-benar terlihat.
            // Barang tak dikenal (UNIDENTIFIED) dan kelebihan (EXTRA) dihitung
            // terpisah sebagai selisih — bukan sebagai temuan.
            'found_count' => $opname->items()
                ->whereNotNull('asset_id')
                ->whereIn('scan_result', [
                    StockOpnameScanResult::FOUND->value,
                    StockOpnameScanResult::WRONG_LOCATION->value,
                    StockOpnameScanResult::WRONG_CUSTODIAN->value,
                    StockOpnameScanResult::DAMAGED->value,
                ])
                ->count(),
            'variance_count' => $opname->variances()->count(),
        ])->save();
    }

    protected function statusValue(AssetStockOpname $opname): string
    {
        return $opname->status instanceof StockOpnameStatus ? $opname->status->value : (string) $opname->status;
    }

    /**
     * Aset yang diharapkan tetapi TIDAK PERNAH dipindai adalah selisih terpenting
     * dalam sebuah opname. Selisihnya dibentuk saat penghitungan ditutup, satu
     * kali per aset, supaya barang tidak "hilang" hanya karena tidak ada yang
     * memindainya — dan tetap harus diputuskan manusia sebelum statusnya berubah.
     */
    protected function markUnscannedAsMissing(AssetStockOpname $opname): void
    {
        $belumDihitung = $opname->items()
            ->where('scan_result', StockOpnameScanResult::MISSING->value)
            ->where('is_variance', false)
            ->get();

        foreach ($belumDihitung as $item) {
            $item->forceFill([
                'is_variance' => true,
                'variance_decision' => VarianceDecision::PENDING->value,
            ])->save();

            $this->recordVariance($opname, $item, StockOpnameScanResult::MISSING, [
                'notes' => 'Tidak ditemukan saat penghitungan ditutup.',
            ]);
        }
    }
}
