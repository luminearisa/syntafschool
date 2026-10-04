<?php

namespace App\Modules\SchoolOperations\Laundry\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Laundry\Models\LaundryMachine;
use App\Modules\SchoolOperations\Laundry\Models\LaundryPricingRule;
use App\Modules\SchoolOperations\Laundry\Models\LaundryService;
use Illuminate\Validation\ValidationException;

/**
 * Master katalog: layanan (§3), aturan harga (§4), dan mesin (§34).
 *
 * Rule 23: aturan harga lama tidak dihapus/diubah saat harga naik — nonaktifkan
 * (`is_active=false`) atau tutup `effective_until`, lalu buat rule baru. Order
 * historis tetap memakai snapshotnya.
 */
class LaundryCatalogService
{
    public function __construct(private readonly LaundryAuditService $audit)
    {
    }

    // ── Layanan ────────────────────────────────────────────────────────────

    public function createService(array $data, ?User $actor = null): LaundryService
    {
        $service = LaundryService::create([
            'code' => strtoupper((string) $data['code']),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'service_type' => strtoupper((string) ($data['service_type'] ?? $data['code'])),
            'pricing_model' => $this->assertPricingModel((string) ($data['pricing_model'] ?? 'WEIGHT')),
            'unit' => $data['unit'] ?? 'KG',
            'processing_hours' => $data['processing_hours'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        $this->audit->record('SERVICE_CREATED', $service, $actor, [], $service->only(['code', 'name', 'pricing_model']));

        return $service;
    }

    public function updateService(LaundryService $service, array $data, ?User $actor = null): LaundryService
    {
        $before = $service->only(['name', 'pricing_model', 'unit', 'processing_hours', 'is_active']);

        if (isset($data['pricing_model'])) {
            $data['pricing_model'] = $this->assertPricingModel((string) $data['pricing_model']);
        }
        $service->update(collect($data)->only([
            'name', 'description', 'service_type', 'pricing_model', 'unit',
            'processing_hours', 'is_active', 'sort_order',
        ])->all());

        $this->audit->record('SERVICE_UPDATED', $service, $actor, $before, $service->only(array_keys($before)));

        return $service;
    }

    // ── Aturan harga ───────────────────────────────────────────────────────

    public function createPricingRule(array $data, ?User $actor = null): LaundryPricingRule
    {
        $model = $this->assertPricingModel((string) ($data['pricing_model'] ?? 'WEIGHT'));

        $rule = LaundryPricingRule::create([
            'laundry_service_id' => $data['laundry_service_id'] ?? null,
            'pricing_model' => $model,
            'item_type' => isset($data['item_type']) ? strtoupper((string) $data['item_type']) : null,
            'unit_price' => $data['unit_price'] ?? 0,
            'minimum_quantity' => $data['minimum_quantity'] ?? null,
            'minimum_charge' => $data['minimum_charge'] ?? null,
            'fee_type' => isset($data['fee_type']) ? strtoupper((string) $data['fee_type']) : null,
            'fee_amount' => $data['fee_amount'] ?? null,
            'fee_percent' => $data['fee_percent'] ?? null,
            'effective_from' => $data['effective_from'] ?? now()->toDateString(),
            'effective_until' => $data['effective_until'] ?? null,
            'priority' => (int) ($data['priority'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by' => $actor?->id,
        ]);

        $this->audit->record('PRICING_RULE_CREATED', $rule, $actor, [], $rule->only([
            'laundry_service_id', 'pricing_model', 'item_type', 'unit_price', 'fee_type', 'effective_from',
        ]));

        return $rule;
    }

    /** Nonaktifkan rule (bukan hapus) supaya histori harga terjaga (Rule 23). */
    public function deactivatePricingRule(LaundryPricingRule $rule, ?User $actor = null, ?string $reason = null): LaundryPricingRule
    {
        $rule->update(['is_active' => false]);
        $this->audit->record('PRICING_RULE_DEACTIVATED', $rule, $actor, ['is_active' => true], ['is_active' => false], $reason);

        return $rule;
    }

    // ── Mesin ──────────────────────────────────────────────────────────────

    public function createMachine(array $data, ?User $actor = null): LaundryMachine
    {
        $machine = LaundryMachine::create([
            'code' => strtoupper((string) $data['code']),
            'name' => $data['name'],
            'machine_type' => strtoupper((string) ($data['machine_type'] ?? 'WASHING')),
            'capacity_kg' => $data['capacity_kg'] ?? null,
            'location' => $data['location'] ?? null,
            'status' => $data['status'] ?? 'OPERATIONAL',
            'asset_id' => $data['asset_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->audit->record('MACHINE_CREATED', $machine, $actor, [], $machine->only(['code', 'machine_type']));

        return $machine;
    }

    public function updateMachine(LaundryMachine $machine, array $data, ?User $actor = null): LaundryMachine
    {
        $before = $machine->only(['name', 'status', 'capacity_kg', 'location', 'maintenance_state']);
        $machine->update(collect($data)->only([
            'name', 'machine_type', 'capacity_kg', 'location', 'status', 'asset_id', 'maintenance_state', 'notes',
        ])->all());

        $this->audit->record('MACHINE_UPDATED', $machine, $actor, $before, $machine->only(array_keys($before)));

        return $machine;
    }

    private function assertPricingModel(string $model): string
    {
        $model = strtoupper($model);
        if (! in_array($model, (array) config('laundry.pricing_models', ['WEIGHT', 'PIECE', 'SERVICE']), true)) {
            throw ValidationException::withMessages(['pricing_model' => 'Model harga harus WEIGHT, PIECE, atau SERVICE.']);
        }

        return $model;
    }
}
