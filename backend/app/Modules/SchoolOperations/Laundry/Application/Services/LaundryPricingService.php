<?php

namespace App\Modules\SchoolOperations\Laundry\Application\Services;

use App\Modules\SchoolOperations\Laundry\Domain\Support\LaundryMath;
use App\Modules\SchoolOperations\Laundry\Models\LaundryPricingRule;
use App\Modules\SchoolOperations\Laundry\Models\LaundryService;
use Illuminate\Validation\ValidationException;

/**
 * Kalkulasi harga laundry (§4, §11, §12, §54).
 *
 * Deterministik (Rule 7): input sama → output sama. Harga selalu diambil dari
 * pricing rule aktif pada tanggal order (Rule 8) dan hasilnya disnapshot ke
 * order sehingga perubahan harga tidak pernah mengubah order historis
 * (Rule 9/23).
 *
 * Aturan split free/paid:
 * - Jatah gratis dipakai TERLEBIH DAHULU (Rule 4), sebagian boleh (Rule 5):
 *   free = min(sisa jatah, permintaan); sisanya paid.
 * - Biaya tambahan (express/stain/dst) selalu menjadi porsi berbayar — jatah
 *   gratis menutup jasa cuci dasar, bukan layanan khusus.
 * - Quota PIECE menutup item termahal lebih dulu (menguntungkan santri),
 *   urutan tie-break stabil (harga desc, tipe item asc).
 */
class LaundryPricingService
{
    /**
     * Hitung penawaran harga + split free/paid.
     *
     * @param  array{laundry_service_id:int, net_weight?:float|null, items?:list<array{item_type:string, quantity:int}>, extras?:list<string>, date?:string|null}  $input
     * @param  array{quota_type:string, remaining:float, max_free_weight_per_order?:float|null}|null  $entitlement
     * @return array<string, mixed>
     */
    public function quote(array $input, ?array $entitlement = null): array
    {
        $service = LaundryService::query()->find((int) ($input['laundry_service_id'] ?? 0));
        if (! $service || ! $service->is_active) {
            throw ValidationException::withMessages([
                'laundry_service_id' => 'Layanan laundry tidak ditemukan atau tidak aktif.',
            ]);
        }

        $date = $input['date'] ?? now()->toDateString();
        $netWeight = LaundryMath::quantity($input['net_weight'] ?? 0);
        $items = $this->normalizeItems($input['items'] ?? []);
        $extras = array_values(array_unique(array_map('strval', $input['extras'] ?? [])));

        $rules = $this->activeRules((int) $service->id, $date);

        $model = (string) $service->pricing_model;
        $lines = [];
        $baseGross = 0.0;
        $minimumApplied = false;
        $itemUnitPrices = [];

        if ($model === 'WEIGHT') {
            $rule = $this->pickRule($rules, fn (LaundryPricingRule $r) => $r->pricing_model === 'WEIGHT' && $r->fee_type === null);
            $unitPrice = LaundryMath::money($rule?->unit_price ?? 0);
            $minQty = $rule?->minimum_quantity !== null ? LaundryMath::quantity($rule->minimum_quantity) : 0.0;
            $billable = max($netWeight, $minQty);
            $baseGross = LaundryMath::money($billable * $unitPrice);

            if ($rule?->minimum_charge !== null && $baseGross < LaundryMath::money($rule->minimum_charge) && $netWeight > 0) {
                $baseGross = LaundryMath::money($rule->minimum_charge);
                $minimumApplied = true;
            }

            $lines[] = [
                'kind' => 'WEIGHT', 'rule_id' => $rule?->id, 'description' => "Cuci per kg ({$service->name})",
                'quantity' => $billable, 'unit_price' => $unitPrice, 'amount' => $baseGross,
            ];
        } elseif ($model === 'PIECE') {
            foreach ($items as $item) {
                $type = (string) $item['item_type'];
                $rule = $this->pickRule($rules, fn (LaundryPricingRule $r) => $r->pricing_model === 'PIECE'
                    && $r->fee_type === null && (string) $r->item_type === $type)
                    ?? $this->pickRule($rules, fn (LaundryPricingRule $r) => $r->pricing_model === 'PIECE' && $r->fee_type === null && $r->item_type === null);
                $unitPrice = LaundryMath::money($rule?->unit_price ?? 0);
                $amount = LaundryMath::money($unitPrice * (int) $item['quantity']);
                $itemUnitPrices[$type] = $unitPrice;
                $baseGross = LaundryMath::money($baseGross + $amount);
                $lines[] = [
                    'kind' => 'PIECE', 'rule_id' => $rule?->id, 'item_type' => $type,
                    'description' => 'Item '.$type, 'quantity' => (int) $item['quantity'],
                    'unit_price' => $unitPrice, 'amount' => $amount,
                ];
            }
        } else { // SERVICE — tarif flat per order.
            $rule = $this->pickRule($rules, fn (LaundryPricingRule $r) => $r->pricing_model === 'SERVICE' && $r->fee_type === null);
            $baseGross = LaundryMath::money($rule?->unit_price ?? 0);
            $lines[] = [
                'kind' => 'SERVICE', 'rule_id' => $rule?->id, 'description' => 'Tarif layanan '.$service->name,
                'quantity' => 1.0, 'unit_price' => $baseGross, 'amount' => $baseGross,
            ];
        }

        // Biaya tambahan (§4): fixed amount atau persen dari harga dasar.
        $extrasTotal = 0.0;
        foreach ($extras as $feeType) {
            $rule = $this->pickRule($rules, fn (LaundryPricingRule $r) => $r->fee_type === $feeType);
            $fee = $rule?->fee_amount !== null
                ? LaundryMath::money($rule->fee_amount)
                : LaundryMath::money($baseGross * ((float) ($rule?->fee_percent ?? 0)) / 100);
            $extrasTotal = LaundryMath::money($extrasTotal + $fee);
            $lines[] = [
                'kind' => 'FEE', 'rule_id' => $rule?->id, 'fee_type' => $feeType,
                'description' => 'Biaya tambahan '.$feeType, 'quantity' => 1.0,
                'unit_price' => $fee, 'amount' => $fee,
            ];
        }

        $totalGross = LaundryMath::money($baseGross + $extrasTotal);

        // ── Split free/paid (§11/§12) ─────────────────────────────────────
        $split = $this->splitFreePaid(
            quotaType: $entitlement['quota_type'] ?? null,
            remaining: LaundryMath::quantity($entitlement['remaining'] ?? 0),
            maxFreeWeightPerOrder: isset($entitlement['max_free_weight_per_order'])
                ? LaundryMath::quantity($entitlement['max_free_weight_per_order'])
                : null,
            model: $model,
            netWeight: $netWeight,
            items: $items,
            itemUnitPrices: $itemUnitPrices,
            rules: $rules,
            baseGross: $baseGross,
            extrasTotal: $extrasTotal,
            totalGross: $totalGross,
        );

        $snapshot = [
            'service_id' => (int) $service->id,
            'service_code' => (string) $service->code,
            'pricing_model' => $model,
            'priced_at' => $date,
            'currency' => 'IDR',
            'net_weight' => $netWeight,
            'items' => $items,
            'extras' => $extras,
            'item_unit_prices' => $itemUnitPrices,
            'lines' => $lines,
            'base_gross' => $baseGross,
            'extras_total' => $extrasTotal,
            'total_gross' => $totalGross,
            'minimum_applied' => $minimumApplied,
            'quota_type' => $split['quota_type'],
            'free_quantity' => $split['free_quantity'],
            'paid_quantity' => $split['paid_quantity'],
            'paid_amount' => $split['paid_amount'],
            'free_detail' => $split['free_detail'],
        ];

        return [
            'pricing_model' => $model,
            'currency' => 'IDR',
            'base_gross' => $baseGross,
            'extras_total' => $extrasTotal,
            'total_gross' => $totalGross,
            'minimum_applied' => $minimumApplied,
            'quota_type' => $split['quota_type'],
            'free_quantity' => $split['free_quantity'],
            'paid_quantity' => $split['paid_quantity'],
            'paid_amount' => $split['paid_amount'],
            'lines' => $lines,
            'item_unit_prices' => $itemUnitPrices,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * Split porsi gratis vs berbayar per quota type.
     *
     * @param  list<array{item_type:string, quantity:int}>  $items
     * @param  array<int, LaundryPricingRule>  $rules
     * @return array{quota_type:?string, free_quantity:float, paid_quantity:float, paid_amount:float, free_detail:array}
     */
    private function splitFreePaid(
        ?string $quotaType,
        float $remaining,
        ?float $maxFreeWeightPerOrder,
        string $model,
        float $netWeight,
        array $items,
        array $itemUnitPrices,
        array $rules,
        float $baseGross,
        float $extrasTotal,
        float $totalGross,
    ): array {
        $none = [
            'quota_type' => $quotaType, 'free_quantity' => 0.0,
            'paid_quantity' => $this->totalQuantity($model, $netWeight, $items),
            'paid_amount' => $totalGross, 'free_detail' => [],
        ];

        if ($quotaType === null || $remaining <= 0) {
            return $none;
        }

        switch ($quotaType) {
            case 'WEIGHT':
                $freeWeight = LaundryMath::quantity(min($remaining, $netWeight));
                $paidWeight = LaundryMath::quantity($netWeight - $freeWeight);

                if ($paidWeight <= 0) {
                    // Seluruh berat tertutup jatah; hanya biaya tambahan yang dibayar.
                    return [
                        'quota_type' => $quotaType, 'free_quantity' => $freeWeight,
                        'paid_quantity' => 0.0, 'paid_amount' => $extrasTotal,
                        'free_detail' => ['free_weight' => $freeWeight],
                    ];
                }

                $paidBase = $this->weightPrice($rules, $paidWeight);

                return [
                    'quota_type' => $quotaType, 'free_quantity' => $freeWeight,
                    'paid_quantity' => $paidWeight,
                    'paid_amount' => LaundryMath::money($paidBase + $extrasTotal),
                    'free_detail' => ['free_weight' => $freeWeight, 'paid_weight' => $paidWeight, 'paid_base' => $paidBase],
                ];

            case 'PIECE':
                // Ekspansi unit: item termahal ditutup jatah lebih dulu.
                $units = [];
                foreach ($items as $item) {
                    $price = LaundryMath::money($itemUnitPrices[$item['item_type']] ?? 0);
                    for ($i = 0; $i < (int) $item['quantity']; $i++) {
                        $units[] = ['item_type' => $item['item_type'], 'unit_price' => $price];
                    }
                }
                usort($units, fn ($a, $b) => [$b['unit_price'], $a['item_type']] <=> [$a['unit_price'], $b['item_type']]);

                $freeUnits = (int) min(floor($remaining), count($units));
                $paidAmount = 0.0;
                foreach (array_slice($units, $freeUnits) as $unit) {
                    $paidAmount = LaundryMath::money($paidAmount + $unit['unit_price']);
                }

                return [
                    'quota_type' => $quotaType,
                    'free_quantity' => (float) $freeUnits,
                    'paid_quantity' => (float) (count($units) - $freeUnits),
                    'paid_amount' => LaundryMath::money($paidAmount + $extrasTotal),
                    'free_detail' => ['free_pieces' => $freeUnits],
                ];

            case 'ORDER':
                if ($remaining < 1) {
                    return $none;
                }

                // Jatah order bisa dibatasi berat per order (policy).
                if ($maxFreeWeightPerOrder !== null && $netWeight > $maxFreeWeightPerOrder && $model === 'WEIGHT') {
                    $paidWeight = LaundryMath::quantity($netWeight - $maxFreeWeightPerOrder);

                    return [
                        'quota_type' => $quotaType, 'free_quantity' => 1.0,
                        'paid_quantity' => $paidWeight,
                        'paid_amount' => LaundryMath::money($this->weightPrice($rules, $paidWeight) + $extrasTotal),
                        'free_detail' => ['capped_free_weight' => $maxFreeWeightPerOrder, 'paid_weight' => $paidWeight],
                    ];
                }

                return [
                    'quota_type' => $quotaType, 'free_quantity' => 1.0, 'paid_quantity' => 0.0,
                    'paid_amount' => $extrasTotal,
                    'free_detail' => ['whole_order_free' => true],
                ];

            case 'AMOUNT':
                $freeValue = LaundryMath::money(min($remaining, $totalGross));

                return [
                    'quota_type' => $quotaType, 'free_quantity' => $freeValue,
                    'paid_quantity' => LaundryMath::money($totalGross - $freeValue),
                    'paid_amount' => LaundryMath::money($totalGross - $freeValue),
                    'free_detail' => ['free_value' => $freeValue],
                ];

            default:
                return $none;
        }
    }

    /** Harga porsi berat berbayar (minimum quantity/charge tetap berlaku). */
    private function weightPrice(array $rules, float $weight): float
    {
        $rule = $this->pickRule($rules, fn (LaundryPricingRule $r) => $r->pricing_model === 'WEIGHT' && $r->fee_type === null);
        $unitPrice = LaundryMath::money($rule?->unit_price ?? 0);
        $minQty = $rule?->minimum_quantity !== null ? LaundryMath::quantity($rule->minimum_quantity) : 0.0;
        $price = LaundryMath::money(max($weight, $minQty) * $unitPrice);

        if ($rule?->minimum_charge !== null && $price < LaundryMath::money($rule->minimum_charge)) {
            $price = LaundryMath::money($rule->minimum_charge);
        }

        return $price;
    }

    private function totalQuantity(string $model, float $netWeight, array $items): float
    {
        return match ($model) {
            'WEIGHT' => $netWeight,
            'PIECE' => (float) array_sum(array_map(fn ($i) => (int) $i['quantity'], $items)),
            default => 1.0,
        };
    }

    /**
     * Rule aktif utk satu layanan pada tanggal tertentu. Rule spesifik layanan
     * ditandai `specific` agar selalu menang atas rule generik.
     *
     * @return list<LaundryPricingRule>
     */
    private function activeRules(int $serviceId, string $date): array
    {
        $rules = LaundryPricingRule::query()
            ->where('is_active', true)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date))
            ->where(fn ($q) => $q->where('laundry_service_id', $serviceId)->orWhereNull('laundry_service_id'))
            ->orderByDesc('priority')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->all();

        return array_values(array_map(function (LaundryPricingRule $rule) use ($serviceId): LaundryPricingRule {
            $rule->setAttribute('specific', (int) $rule->laundry_service_id === $serviceId);

            return $rule;
        }, $rules));
    }

    /** Rule pertama yang cocok: spesifik layanan dulu, lalu priority/efektif terbaru. */
    private function pickRule(array $rules, callable $filter): ?LaundryPricingRule
    {
        $matching = array_values(array_filter($rules, $filter));
        if ($matching === []) {
            return null;
        }

        usort($matching, function (LaundryPricingRule $a, LaundryPricingRule $b): int {
            return [(int) $b->getAttribute('specific'), (int) $b->priority, (string) $b->effective_from, (int) $b->id]
                <=> [(int) $a->getAttribute('specific'), (int) $a->priority, (string) $a->effective_from, (int) $a->id];
        });

        return $matching[0];
    }

    /** @return list<array{item_type:string, quantity:int}> */
    private function normalizeItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            $type = strtoupper(trim((string) ($item['item_type'] ?? 'OTHER')));
            $quantity = max(0, (int) ($item['quantity'] ?? 1));
            if ($quantity === 0) {
                continue;
            }
            $known = array_keys((array) config('laundry.item_types', []));
            if ($known !== [] && ! in_array($type, $known, true)) {
                $type = 'OTHER';
            }
            $normalized[] = ['item_type' => $type, 'quantity' => $quantity];
        }

        // Gabung baris tipe sama & urutkan stabil (deterministik).
        $grouped = [];
        foreach ($normalized as $item) {
            $grouped[$item['item_type']] = ($grouped[$item['item_type']] ?? 0) + $item['quantity'];
        }
        ksort($grouped);

        return array_values(array_map(
            fn (string $type, int $qty): array => ['item_type' => $type, 'quantity' => $qty],
            array_keys($grouped),
            $grouped
        ));
    }
}
