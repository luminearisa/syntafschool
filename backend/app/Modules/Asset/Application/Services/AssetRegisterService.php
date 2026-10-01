<?php

namespace App\Modules\Asset\Application\Services;

use App\Models\User;
use App\Modules\Asset\Application\Support\AssetNumberGenerator;
use App\Modules\Asset\Application\Support\AssetSettingsService;
use App\Modules\Asset\Domain\Contracts\FinanceProviderInterface;
use App\Modules\Asset\Domain\Contracts\InventoryProviderInterface;
use App\Modules\Asset\Domain\Contracts\ProcurementProviderInterface;
use App\Modules\Asset\Domain\Enums\AssetCondition;
use App\Modules\Asset\Domain\Enums\AssetStatus;
use App\Modules\Asset\Domain\Enums\AuditAction;
use App\Modules\Asset\Domain\Enums\CapitalizationDecision;
use App\Modules\Asset\Domain\Exceptions\AssetDomainException;
use App\Modules\Asset\Domain\Exceptions\AssetValidationException;
use App\Modules\Asset\Domain\StateMachines\AssetStateMachine;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetCustodian;
use App\Modules\Asset\Models\AssetCustodianHistory;
use App\Modules\Asset\Models\AssetType;
use App\Modules\Asset\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Register aset: pengakuan, pendaftaran, penempatan, dan perubahan keadaannya.
 *
 * Dua hal yang dijaga ketat di sini:
 *  1. **Pengakuan sebelum pencatatan** — tidak semua barang yang dibeli adalah
 *     aset. Barang habis pakai masuk Persediaan, barang murah masuk Beban. Tanpa
 *     aturan ini, gudang penuh "aset" senilai seribu rupiah dan laporan aset
 *     kehilangan artinya.
 *  2. **Keuangan tidak ditulis dua kali** — perolehan dicatatkan lewat
 *     `FinanceProviderInterface`; bila modul Keuangan belum terpasang, niat itu
 *     masuk outbox (PENDING), bukan dicatat sendiri di sini.
 */
class AssetRegisterService
{
    public function __construct(
        private readonly AssetNumberGenerator $numbers,
        private readonly AssetSettingsService $settings,
        private readonly AssetCategoryService $categories,
        private readonly AssetAuditService $audit,
        private readonly FinanceProviderInterface $finance,
        private readonly InventoryProviderInterface $inventory,
        private readonly ProcurementProviderInterface $procurement,
    ) {}

    /**
     * Tentukan perlakuan sebuah barang: ASET, PERSEDIAAN, atau BEBAN.
     *
     * Dipakai sebelum pencatatan (dan diuji tersendiri) supaya keputusan ini
     * dapat dijelaskan kepada pemeriksa — bukan efek samping dari kode simpan.
     */
    public function recognize(?AssetCategory $category, ?AssetType $type, string $cost, bool $forceAsset = false): CapitalizationDecision
    {
        if ($forceAsset) {
            return CapitalizationDecision::ASSET;
        }

        $defaults = $this->categories->defaultsFor($type, $category);

        if ($defaults['is_consumable'] || ($category?->kind === CapitalizationDecision::INVENTORY->value)) {
            return CapitalizationDecision::INVENTORY;
        }

        $threshold = (string) $defaults['capitalization_threshold'];

        return $this->compareMoney($cost, $threshold) >= 0
            ? CapitalizationDecision::ASSET
            : CapitalizationDecision::EXPENSE;
    }

    /**
     * Daftarkan satu aset.
     *
     * @param array<string, mixed> $data
     */
    public function register(User $actor, array $data): Asset
    {
        $category = isset($data['category_id']) ? AssetCategory::query()->find($data['category_id']) : null;
        $type = isset($data['asset_type_id']) ? AssetType::query()->find($data['asset_type_id']) : null;

        if ($category === null && $type === null) {
            throw new AssetValidationException(['category_id' => ['Kategori atau jenis aset wajib diisi.']]);
        }

        $cost = (string) ($data['acquisition_cost'] ?? '0');
        $decision = $this->recognize($category, $type, $cost, (bool) ($data['force_asset'] ?? false));

        if ($decision !== CapitalizationDecision::ASSET) {
            // Barang habis pakai TIDAK boleh otomatis menjadi aset: tempatnya di
            // Persediaan (atau Beban bila nilainya kecil). Pesannya menyebut
            // perlakuan yang benar supaya pengguna tahu harus ke mana.
            $this->handOffNonAsset($actor, $data, $category, $decision);

            throw new AssetValidationException([
                'capitalization_decision' => [sprintf(
                    'Barang ini diperlakukan sebagai %s, bukan aset. %s',
                    $decision->label(),
                    $decision === CapitalizationDecision::INVENTORY
                        ? 'Catat lewat penerimaan barang/persediaan.'
                        : 'Catat langsung sebagai beban; atau setujui sebagai aset bila memang tahan lama.',
                )],
            ], 'Barang tidak diakui sebagai aset.');
        }

        $defaults = $this->categories->defaultsFor($type, $category);

        $asset = DB::transaction(function () use ($actor, $data, $category, $type, $cost, $defaults, $decision): Asset {
            $code = $data['asset_code'] ?? $this->numbers->assetCode([
                'kat' => $category?->code ?? 'UMUM',
                'tahun' => $this->yearFrom($data['purchase_date'] ?? null),
            ]);

            $asset = Asset::query()->create([
                'category_id' => $category?->id,
                'asset_type_id' => $type?->id,
                'location_id' => $data['location_id'] ?? null,
                'unit_id' => $data['unit_id'] ?? null,
                'asset_code' => $code,
                'asset_number' => $data['asset_number'] ?? null,
                'name' => $data['name'],
                'serial_number' => $data['serial_number'] ?? null,
                'barcode' => $data['barcode'] ?? $this->barcodeFor($code),
                'qr_token' => (string) Str::uuid(),
                'manufacturer' => $data['manufacturer'] ?? null,
                'brand' => $data['brand'] ?? null,
                'model' => $data['model'] ?? null,
                'acquisition_source' => $data['acquisition_source'] ?? 'PURCHASE',
                'purchase_date' => $data['purchase_date'] ?? null,
                'received_date' => $data['received_date'] ?? null,
                'commission_date' => $data['commission_date'] ?? null,
                'capitalization_date' => $data['capitalization_date'] ?? ($data['received_date'] ?? null),
                'acquisition_cost' => $cost,
                'residual_value' => (string) ($data['residual_value'] ?? '0'),
                'current_book_value' => $cost,
                'useful_life_months' => $data['useful_life_months'] ?? $defaults['useful_life_months'],
                'depreciation_method' => $data['depreciation_method'] ?? $defaults['depreciation_method'],
                'capitalization_decision' => $decision->value,
                'capitalization_threshold' => (string) $defaults['capitalization_threshold'],
                'criticality' => $data['criticality'] ?? $defaults['criticality'],
                'status' => $data['status'] ?? AssetStatus::REGISTERED->value,
                'condition' => $data['condition'] ?? AssetCondition::NEW->value,
                'tag_status' => 'PENDING_TAGGING',
                'warranty_start' => $data['warranty_start'] ?? null,
                'warranty_end' => $data['warranty_end'] ?? null,
                'warranty_provider' => $data['warranty_provider'] ?? null,
                'warranty_number' => $data['warranty_number'] ?? null,
                'warranty_terms' => $data['warranty_terms'] ?? null,
                'procurement_reference' => $data['procurement_reference'] ?? null,
                'purchase_order_reference' => $data['purchase_order_reference'] ?? null,
                'invoice_reference' => $data['invoice_reference'] ?? null,
                'vendor_id' => $data['vendor_id'] ?? null,
                'received_by' => $data['received_by'] ?? $actor->id,
                'registered_at' => now(),
                'expected_life_end' => $this->expectedLifeEnd($data, $defaults['useful_life_months']),
                'description' => $data['description'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->writeIdentifiers($asset, $code);
            $this->writeSpecifications($asset, $type, $data);
            $this->writeAttributeValues($asset, $type, $data);

            if ($data['location_id'] ?? null) {
                $this->place($actor, $asset, (int) $data['location_id'], $data['custodian_id'] ?? null, $data['custodian_type'] ?? null);
                $asset->refresh();
            }

            $this->audit->record(AuditAction::ASSET_CREATED, $asset, $actor, [
                'after' => $asset->only(['asset_code', 'name', 'acquisition_cost', 'status', 'condition', 'location_id']),
                'status_after' => $asset->status instanceof AssetStatus ? $asset->status->value : (string) $asset->status,
                'reason' => 'Pendaftaran aset baru.',
                'context' => [
                    'capitalization_decision' => $decision->value,
                    'threshold' => (string) $defaults['capitalization_threshold'],
                ],
            ]);

            return $asset;
        });

        // Kapitalisasi ke Keuangan dilakukan setelah transaksi lokal selesai:
        // kegagalan modul lain tidak boleh membatalkan aset yang sudah terdaftar
        // secara fisik — niatnya tersimpan di outbox dan dapat dikerjakan ulang.
        $this->capitalize($actor, $asset);

        if (($data['procurement_reference'] ?? null) !== null) {
            $this->procurement->markRecognized((string) $data['procurement_reference'], [$asset->asset_code]);
        }

        return $asset->refresh();
    }

    /** Penerimaan barang dalam jumlah banyak (mis. 10 laptop dari satu PO). */
    public function receiveBulk(User $actor, array $payload): array
    {
        $quantity = max(1, (int) ($payload['quantity'] ?? 1));
        $serials = array_values(array_filter((array) ($payload['serial_numbers'] ?? []), fn ($s) => $s !== null && $s !== ''));
        $assets = [];

        foreach (range(1, $quantity) as $index) {
            $assets[] = $this->register($actor, array_merge($payload, [
                'serial_number' => $serials[$index - 1] ?? null,
                'name' => $quantity > 1
                    ? sprintf('%s #%d', (string) $payload['name'], $index)
                    : $payload['name'],
            ]));
        }

        return $assets;
    }

    /** Tempatkan aset: lokasi + pemegang, beserta riwayat pemegangnya. */
    public function place(User $actor, Asset $asset, int $locationId, int|string|null $custodianId = null, ?string $custodianType = null): Asset
    {
        $location = Location::query()->find($locationId);

        if ($location === null) {
            throw new AssetValidationException(['location_id' => ['Lokasi tidak ditemukan.']]);
        }

        return DB::transaction(function () use ($actor, $asset, $location, $custodianId, $custodianType): Asset {
            $before = ['location_id' => $asset->location_id, 'custodian_id' => $asset->custodian_id, 'status' => $asset->status];
            $target = AssetStatus::ASSIGNED;

            if ($asset->status instanceof AssetStatus && $asset->status->value !== $target->value && $asset->status->value !== AssetStatus::ACTIVE->value) {
                AssetStateMachine::assert($asset->status->value, AssetStatus::ACTIVE->value, 'aset');
            }

            $custodian = $this->resolveCustodian($custodianId, $custodianType ?? 'EMPLOYEE');

            $asset->forceFill([
                'location_id' => $location->id,
                'unit_id' => $asset->unit_id ?? $location->responsible_unit_id,
                'custodian_id' => $custodian?->user_id ?? ($custodian === null && $custodianType === null ? $asset->custodian_id : null),
                'custodian_type' => $custodian?->custodian_type ?? $custodianType ?? $asset->custodian_type,
                'custodian_label' => $custodian?->name ?? $asset->custodian_label,
                'status' => $custodian !== null ? AssetStatus::ASSIGNED->value : AssetStatus::ACTIVE->value,
                'version' => $asset->version + 1,
            ])->save();

            if ($custodian !== null) {
                $this->openCustody($actor, $asset, $custodian, $location->id, 'Penempatan aset.');
            }

            $this->audit->record(AuditAction::ASSET_ASSIGNED, $asset, $actor, [
                'before' => $before,
                'after' => ['location_id' => $asset->location_id, 'custodian_id' => $asset->custodian_id, 'status' => $asset->status],
                'status_before' => $before['status'] instanceof AssetStatus ? $before['status']->value : (string) $before['status'],
                'status_after' => $asset->status instanceof AssetStatus ? $asset->status->value : (string) $asset->status,
                'to' => $location->name,
                'reason' => 'Penempatan aset.',
            ]);

            return $asset->refresh();
        });
    }

    /**
     * Perubahan data aset dengan pengunci optimistis.
     *
     * Bila `version` yang dikirim tidak sama dengan yang tersimpan, artinya ada
     * yang mengubah aset ini lebih dulu — permintaan ditolak (409) alih-alih
     * saling menimpa. Inilah pengaman "dua orang mengubah aset yang sama".
     */
    public function update(User $actor, Asset $asset, array $data): Asset
    {
        if (isset($data['version']) && (int) $data['version'] !== (int) $asset->version) {
            throw new AssetDomainException(
                'Data aset ini sudah diubah orang lain (versi '.$asset->version.'). Muat ulang sebelum menyimpan.',
                409,
            );
        }

        $financial = ['acquisition_cost', 'residual_value', 'useful_life_months', 'depreciation_method', 'current_book_value', 'fair_value'];

        if (array_intersect_key($data, array_flip($financial)) !== [] && ! app(AssetAccessService::class)->canViewFinancials($actor)) {
            throw new AssetDomainException('Hanya pemegang izin keuangan yang boleh mengubah data nilai aset.', 403);
        }

        $tracked = ['name', 'serial_number', 'brand', 'model', 'manufacturer', 'category_id', 'asset_type_id', 'location_id',
            'unit_id', 'criticality', 'condition', 'description', 'notes', 'warranty_end', 'acquisition_cost', 'residual_value',
            'useful_life_months', 'depreciation_method', 'expected_life_end'];
        $before = $asset->only($tracked);

        $asset->fill(collect($data)->only($tracked)->all());
        $asset->forceFill(['version' => $asset->version + 1, 'updated_by' => $actor->id])->save();

        $this->audit->record(AuditAction::ASSET_UPDATED, $asset, $actor, [
            'before' => $before,
            'after' => $asset->only($tracked),
            'reason' => $data['reason'] ?? 'Perubahan data aset.',
        ]);

        return $asset->refresh();
    }

    /** Perubahan status — SELALU melewati peta transisi. */
    public function changeStatus(User $actor, Asset $asset, AssetStatus $to, ?string $reason = null, array $context = []): Asset
    {
        $from = $asset->status instanceof AssetStatus ? $asset->status : AssetStatus::from((string) $asset->status);

        AssetStateMachine::assert($from->value, $to->value, 'aset');

        return DB::transaction(function () use ($actor, $asset, $from, $to, $reason, $context): Asset {
            $asset->forceFill([
                'status' => $to->value,
                'version' => $asset->version + 1,
                'updated_by' => $actor->id,
            ])->save();

            if ($to === AssetStatus::RETIRED) {
                $asset->forceFill(['is_archived' => true, 'archived_at' => now()])->save();
            }

            $this->audit->record(AuditAction::ASSET_UPDATED, $asset, $actor, [
                'status_before' => $from->value,
                'status_after' => $to->value,
                'reason' => $reason,
                'context' => $context,
            ]);

            return $asset->refresh();
        });
    }

    /** Perubahan kondisi beserta riwayatnya; kondisi buruk memicu pemberitahuan. */
    public function changeCondition(User $actor, Asset $asset, AssetCondition $condition, ?string $reason = null): Asset
    {
        $from = $asset->condition instanceof AssetCondition ? $asset->condition : AssetCondition::from((string) $asset->condition);

        $asset->forceFill(['condition' => $condition->value, 'version' => $asset->version + 1, 'updated_by' => $actor->id])->save();

        $this->audit->record(AuditAction::ASSET_CONDITION_CHANGED, $asset, $actor, [
            'before' => ['condition' => $from->value],
            'after' => ['condition' => $condition->value],
            'reason' => $reason ?? 'Perubahan kondisi aset.',
        ]);

        if ($condition->needsRepair()) {
            app(AssetNotificationService::class)->notify(
                array_values(array_filter([$asset->custodian_id, $asset->location?->manager_user_id])),
                ['ASSET_OFFICER', 'MAINTENANCE_MANAGER'],
                'ASSET_CONDITION_BAD',
                'Aset perlu perhatian: '.$asset->name,
                sprintf('Kondisi tercatat %s (%s). Pertimbangkan permintaan pemeliharaan.', $condition->label(), $asset->asset_code),
                $asset,
            );
        }

        return $asset->refresh();
    }

    /** Ringkasan keuangan aset — hanya untuk pemegang izin keuangan. */
    public function financialSummary(User $actor, Asset $asset): array
    {
        app(AssetAccessService::class)->assertCanViewFinancials($actor);

        return [
            'acquisition_cost' => (string) $asset->acquisition_cost,
            'residual_value' => (string) $asset->residual_value,
            'accumulated_depreciation' => (string) $asset->accumulated_depreciation,
            'current_book_value' => (string) ($asset->current_book_value ?? $asset->acquisition_cost),
            'fair_value' => $asset->fair_value === null ? null : (string) $asset->fair_value,
            'useful_life_months' => $asset->useful_life_months,
            'depreciation_method' => $asset->depreciation_method,
            'capitalization_decision' => $asset->capitalization_decision?->value ?? (string) $asset->capitalization_decision,
            'finance_asset_id' => $asset->finance_asset_id,
            'finance_book_value' => $this->finance->bookValue((int) $asset->id),
        ];
    }

    /** Catatkan kapitalisasi ke Keuangan (dengan outbox bila belum tersedia). */
    protected function capitalize(User $actor, Asset $asset): void
    {
        $result = $this->finance->capitalize([
            'asset_id' => $asset->id,
            'asset_code' => $asset->asset_code,
            'name' => $asset->name,
            'category' => $asset->category?->name ?? $asset->category_id,
            'acquisition_cost' => (string) $asset->acquisition_cost,
            'residual_value' => (string) $asset->residual_value,
            'useful_life_months' => (int) ($asset->useful_life_months ?? 0),
            'method' => $asset->depreciation_method,
            'capitalization_date' => $asset->capitalization_date?->toDateString(),
            'unit_id' => $asset->unit_id,
            'actor_id' => $actor->id,
        ]);

        $asset->forceFill([
            'finance_asset_id' => $result['finance_asset_id'] ?? null,
        ])->save();
    }

    /** Barang yang bukan aset: dicatat ke Persediaan dan disebutkan alasannya. */
    protected function handOffNonAsset(User $actor, array $data, ?AssetCategory $category, CapitalizationDecision $decision): void
    {
        if ($decision !== CapitalizationDecision::INVENTORY) {
            return;
        }

        $this->inventory->recordConsumable([
            'name' => $data['name'],
            'serial_number' => $data['serial_number'] ?? null,
            'quantity' => $data['quantity'] ?? 1,
            'unit_cost' => (string) ($data['acquisition_cost'] ?? '0'),
            'received_at' => $data['received_date'] ?? now()->toDateString(),
            'reference' => $data['procurement_reference'] ?? null,
            'category' => $category?->name,
            'actor_id' => $actor->id,
        ]);
    }

    protected function resolveCustodian(int|string|null $custodianId, string $type): ?AssetCustodian
    {
        if ($custodianId === null) {
            return null;
        }

        $custodian = AssetCustodian::query()->find($custodianId);

        if ($custodian !== null) {
            return $custodian;
        }

        // Id yang dikirim dapat berupa id pengguna; carikan (atau buatkan) baris
        // pemegangnya supaya riwayat pemegang tetap satu bentuk.
        $user = is_numeric($custodianId) ? \App\Models\User::query()->find((int) $custodianId) : null;

        if ($user === null) {
            throw new AssetValidationException(['custodian_id' => ['Pemegang aset tidak ditemukan.']]);
        }

        return AssetCustodian::query()->firstOrCreate(
            ['user_id' => $user->id, 'custodian_type' => $type],
            ['name' => $user->name, 'is_active' => true],
        );
    }

    /** Buka baris riwayat pemegang (baris lama ditutup, tidak ditimpa). */
    public function openCustody(User $actor, Asset $asset, AssetCustodian $custodian, ?int $locationId, ?string $reason, ?int $transferId = null): AssetCustodianHistory
    {
        $asset->custodianHistory()->whereNull('released_at')->update([
            'released_at' => now(),
            'released_by' => $actor->id,
            'reason' => $reason ?? 'Diganti pemegang baru.',
        ]);

        return $asset->custodianHistory()->create([
            'custodian_id' => $custodian->id,
            'custodian_type' => $custodian->custodian_type,
            'custodian_label' => $custodian->name,
            'location_id' => $locationId,
            'assigned_at' => now(),
            'assigned_by' => $actor->id,
            'reason' => $reason,
            'transfer_id' => $transferId,
            'acknowledgement' => 'NONE',
        ]);
    }

    protected function writeIdentifiers(Asset $asset, string $code): void
    {
        foreach ([
            ['CODE', $code],
            ['BARCODE', (string) $asset->barcode],
            ['QR', (string) $asset->qr_token],
        ] as [$type, $value]) {
            if ($value === '') {
                continue;
            }

            $asset->identifiers()->create([
                'type' => $type,
                'value' => $value,
                'is_primary' => $type === 'CODE',
                'issued_at' => now(),
            ]);
        }

        $asset->tags()->create([
            'tag_code' => $this->numbers->next('tag'),
            'status' => 'PENDING_TAGGING',
        ]);
    }

    protected function writeSpecifications(Asset $asset, ?AssetType $type, array $data): void
    {
        foreach ((array) ($data['specifications'] ?? []) as $key => $value) {
            $asset->specifications()->create([
                'key' => is_string($key) ? $key : (string) ($value['key'] ?? 'SPEK'),
                'value' => is_array($value) ? (string) ($value['value'] ?? '') : (string) $value,
                'unit' => is_array($value) ? ($value['unit'] ?? null) : null,
            ]);
        }
    }

    protected function writeAttributeValues(Asset $asset, ?AssetType $type, array $data): void
    {
        if ($type === null || empty($data['attributes'])) {
            return;
        }

        $attributes = $type->attributes()->get()->keyBy('code');

        foreach ((array) $data['attributes'] as $code => $value) {
            $attribute = $attributes->get((string) $code);

            if ($attribute === null) {
                continue;
            }

            $asset->attributeValues()->create([
                'attribute_id' => $attribute->id,
                'value' => is_scalar($value) ? (string) $value : json_encode($value),
            ]);
        }
    }

    protected function barcodeFor(string $code): string
    {
        // Barcode dipakai pemindai 1D: hanya huruf/angka/dash, tanpa token rahasia.
        return preg_replace('/[^A-Za-z0-9\-]/', '', $code) ?: $code;
    }

    protected function yearFrom(?string $date): int
    {
        return $date === null ? (int) now()->year : (int) date('Y', strtotime($date));
    }

    protected function expectedLifeEnd(array $data, ?int $usefulLifeMonths): ?string
    {
        $start = $data['commission_date'] ?? $data['received_date'] ?? $data['purchase_date'] ?? null;

        if ($start === null || $usefulLifeMonths === null) {
            return null;
        }

        return date('Y-m-d', strtotime((string) $start.' +'.$usefulLifeMonths.' months'));
    }

    /** Perbandingan uang sebagai string (tanpa float). */
    protected function compareMoney(string $a, string $b): int
    {
        return bccomp($this->normalizeMoney($a), $this->normalizeMoney($b), 2);
    }

    protected function normalizeMoney(string $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : '0.00';
    }
}
