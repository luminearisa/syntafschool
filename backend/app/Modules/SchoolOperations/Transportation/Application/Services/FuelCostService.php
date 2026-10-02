<?php

namespace App\Modules\SchoolOperations\Transportation\Application\Services;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\FinanceGatewayInterface;
use App\Modules\SchoolOperations\Transportation\Domain\Support\TransportContext;
use App\Modules\SchoolOperations\Transportation\Domain\TransportStatus;
use App\Modules\SchoolOperations\Transportation\Models\{TransportationCost, Trip, TripPassenger, VehicleFuelLog};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Biaya operasional transportasi (§58-§61, §80).
 * Tidak membuat ledger akuntansi: biaya dicatat, diagregasi, lalu diteruskan
 * ke modul Finance untuk penagihan/posting.
 */
class FuelCostService
{
    public function __construct(
        private readonly TransportContext $context,
        private readonly FinanceGatewayInterface $finance,
    ) {
    }

    public function recordCost(array $payload, ?string $idempotencyKey = null): array
    {
        $category = strtoupper((string) ($payload['cost_category'] ?? 'OTHER'));
        if (! in_array($category, TransportStatus::costCategories(), true)) {
            throw ValidationException::withMessages(['cost_category' => 'Kategori biaya tidak dikenal: '.$category]);
        }

        $result = $this->context->idempotent('FUEL_ENTRY', $idempotencyKey, function () use ($payload, $category, $idempotencyKey): array {
            $cost = TransportationCost::query()->create([
                'cost_number' => 'COST-'.now()->format('ymd').'-'.str_pad((string) (TransportationCost::query()->count() + 1), 4, '0', STR_PAD_LEFT),
                'cost_category' => $category,
                'trip_id' => $payload['trip_id'] ?? null,
                'vehicle_id' => $payload['vehicle_id'] ?? null,
                'driver_profile_id' => $payload['driver_profile_id'] ?? null,
                'route_id' => $payload['route_id'] ?? null,
                'cost_date' => $payload['cost_date'] ?? now()->toDateString(),
                'amount' => (float) ($payload['amount'] ?? 0),
                'quantity' => $payload['quantity'] ?? null,
                'unit' => $payload['unit'] ?? null,
                'source' => strtoupper((string) ($payload['source'] ?? 'MANUAL')),
                'source_reference' => $payload['source_reference'] ?? null,
                'payment_method' => $payload['payment_method'] ?? null,
                'receipt_number' => $payload['receipt_number'] ?? null,
                'vendor_id' => $payload['vendor_id'] ?? null,
                'billable' => (bool) ($payload['billable'] ?? false),
                'finance_status' => 'PENDING',
                'idempotency_key' => $idempotencyKey,
                'notes' => $payload['notes'] ?? null,
                'recorded_by' => $this->context->actorId(),
            ]);

            $posting = null;
            if (($payload['post_to_finance'] ?? false) || $cost->billable) {
                $posting = $this->finance->postCost([
                    'amount' => (float) $cost->amount,
                    'category' => $cost->cost_category,
                    'reference' => $cost->cost_number,
                    'date' => $cost->cost_date?->toDateString(),
                    'trip_id' => $cost->trip_id,
                    'vehicle_id' => $cost->vehicle_id,
                    'description' => 'Biaya transportasi '.$cost->cost_category.' ('.$cost->cost_number.')',
                ]);
                $cost->forceFill([
                    'finance_status' => ($posting['posted'] ?? false) ? 'POSTED' : 'PENDING',
                    'finance_reference' => $posting['reference'] ?? null,
                    'posted_at' => ($posting['posted'] ?? false) ? now() : null,
                ])->save();
            }

            $this->context->audit('TransportationCostRecorded', $cost, [
                'category' => $category, 'amount' => (float) $cost->amount,
            ]);

            return [
                'cost_id' => $cost->id,
                'cost_number' => $cost->cost_number,
                'amount' => (float) $cost->amount,
                'category' => $category,
                'finance_status' => $cost->finance_status,
                'finance_posting' => $posting,
            ];
        }, ['source' => strtoupper((string) ($payload['source'] ?? 'MANUAL'))]);

        return $result;
    }

    /** Biaya dari catatan BBM (otomatis saat pengisian dicatat). */
    public function costFromFuelLog(VehicleFuelLog $log): array
    {
        return $this->recordCost([
            'cost_category' => 'FUEL',
            'trip_id' => $log->trip_id,
            'vehicle_id' => $log->vehicle_id,
            'driver_profile_id' => $log->driver_profile_id,
            'cost_date' => $log->fuel_date?->toDateString(),
            'amount' => (float) $log->fuel_cost,
            'quantity' => (float) $log->fuel_quantity,
            'unit' => 'liter',
            'source' => 'FUEL_LOG',
            'source_reference' => 'fuel-log:'.$log->id,
            'receipt_number' => $log->receipt_number,
            'payment_method' => $log->payment_method,
        ], 'fuel-cost-'.$log->id);
    }

    /** Ringkasan biaya per kategori/rentang (§60). */
    public function summary(?string $from = null, ?string $to = null, array $filters = []): array
    {
        $from ??= now()->startOfMonth()->toDateString();
        $to ??= now()->toDateString();

        $rows = TransportationCost::query()
            ->whereDate('cost_date', '>=', $from)->whereDate('cost_date', '<=', $to)
            ->when($filters['vehicle_id'] ?? null, fn ($q, $vehicleId) => $q->where('vehicle_id', $vehicleId))
            ->when($filters['route_id'] ?? null, fn ($q, $routeId) => $q->where('route_id', $routeId))
            ->when($filters['trip_id'] ?? null, fn ($q, $tripId) => $q->where('trip_id', $tripId))
            ->get();

        $byCategory = $rows->groupBy('cost_category')->map(fn ($items): array => [
            'entries' => $items->count(),
            'amount' => round((float) $items->sum('amount'), 2),
        ])->all();

        $trips = DB::table('trips')->whereDate('trip_date', '>=', $from)->whereDate('trip_date', '<=', $to)->count();
        $passengers = (int) DB::table('trip_passengers')
            ->join('trips', 'trips.id', '=', 'trip_passengers.trip_id')
            ->whereDate('trips.trip_date', '>=', $from)->whereDate('trips.trip_date', '<=', $to)
            ->where('trip_passengers.boarding_status', 'BOARDED')
            ->count();
        $distance = (float) DB::table('trips')->whereDate('trip_date', '>=', $from)->whereDate('trip_date', '<=', $to)->sum('distance_km');
        $total = round((float) $rows->sum('amount'), 2);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'total_cost' => $total,
            'by_category' => $byCategory,
            'entries' => $rows->count(),
            'trips' => $trips,
            'passengers_boarded' => $passengers,
            'total_distance_km' => round($distance, 2),
            'cost_per_trip' => $trips > 0 ? round($total / $trips, 2) : null,
            'cost_per_passenger' => $passengers > 0 ? round($total / $passengers, 2) : null,
            'cost_per_km' => $distance > 0 ? round($total / $distance, 2) : null,
            'unposted' => $rows->where('finance_status', 'PENDING')->count(),
        ];
    }

    public function tripCost(Trip $trip): array
    {
        $costs = TransportationCost::query()->where('trip_id', $trip->id)->get();
        $fuel = VehicleFuelLog::query()->where('trip_id', $trip->id)->get();
        $passengers = TripPassenger::query()->where('trip_id', $trip->id)->where('boarding_status', 'BOARDED')->count();
        $total = round((float) $costs->sum('amount'), 2);
        $distance = $trip->distance_km !== null ? (float) $trip->distance_km : null;

        return [
            'trip_id' => $trip->id,
            'trip_number' => $trip->trip_number,
            'total_cost' => $total,
            'by_category' => $costs->groupBy('cost_category')->map->sum('amount')->all(),
            'fuel' => [
                'entries' => $fuel->count(),
                'quantity' => round((float) $fuel->sum('fuel_quantity'), 2),
                'cost' => round((float) $fuel->sum('fuel_cost'), 2),
            ],
            'distance_km' => $distance,
            'passengers_boarded' => $passengers,
            'cost_per_passenger' => $passengers > 0 ? round($total / $passengers, 2) : null,
            'cost_per_km' => ($distance && $distance > 0) ? round($total / $distance, 2) : null,
            'fuel_per_km' => ($distance && $distance > 0) ? round((float) $fuel->sum('fuel_quantity') / $distance, 3) : null,
        ];
    }

    public function vehicleCost(int $vehicleId, ?string $from = null, ?string $to = null): array
    {
        $summary = $this->summary($from, $to, ['vehicle_id' => $vehicleId]);
        $trips = Trip::query()->where('vehicle_id', $vehicleId)
            ->when($from, fn ($q, $value) => $q->whereDate('trip_date', '>=', $value))
            ->when($to, fn ($q, $value) => $q->whereDate('trip_date', '<=', $value));

        return [
            'vehicle_id' => $vehicleId,
            'trips' => (clone $trips)->count(),
            'distance_km' => round((float) (clone $trips)->sum('distance_km'), 2),
            'downtime_trips' => (clone $trips)->where('status', 'CANCELLED')->count(),
            'incident_trips' => (clone $trips)->where('status', 'INCIDENT')->count(),
        ] + $summary;
    }

    /** Posting ulang biaya yang masih PENDING ke modul keuangan (§61). */
    public function postPendingCosts(?string $from = null, ?string $to = null): array
    {
        $costs = TransportationCost::query()
            ->where('finance_status', 'PENDING')
            ->when($from, fn ($q, $value) => $q->whereDate('cost_date', '>=', $value))
            ->when($to, fn ($q, $value) => $q->whereDate('cost_date', '<=', $value))
            ->limit(200)
            ->get();

        $posted = 0;
        $failed = 0;
        foreach ($costs as $cost) {
            $posting = $this->finance->postCost([
                'amount' => (float) $cost->amount,
                'category' => $cost->cost_category,
                'reference' => $cost->cost_number,
                'date' => $cost->cost_date?->toDateString(),
                'trip_id' => $cost->trip_id,
                'vehicle_id' => $cost->vehicle_id,
                'description' => 'Biaya transportasi '.$cost->cost_category.' ('.$cost->cost_number.')',
            ]);
            if ($posting['posted'] ?? false) {
                $cost->forceFill([
                    'finance_status' => 'POSTED',
                    'finance_reference' => $posting['reference'] ?? null,
                    'posted_at' => now(),
                ])->save();
                $posted++;
            } else {
                $failed++;
            }
        }

        return ['considered' => $costs->count(), 'posted' => $posted, 'still_pending' => $failed, 'finance_available' => $this->finance->available()];
    }
}
