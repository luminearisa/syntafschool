<?php

namespace App\Modules\SchoolOperations\Transportation\Application\Services;

use App\Modules\SchoolOperations\Transportation\Domain\Support\TransportContext;
use App\Modules\SchoolOperations\Transportation\Models\{
    TransportationAdjustment, TransportationCost, TransportationDailyClosing, TransportationIncident, Trip, TripManifest, TripPassenger
};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penutupan harian operasional (§83, §87 Rule 13).
 * Setelah ditutup, perubahan hanya lewat adjustment yang beralasan & diaudit.
 */
class DailyClosingService
{
    public function __construct(private readonly TransportContext $context)
    {
    }

    /** Ringkasan sebelum penutupan: trip yang belum selesai, insiden terbuka. */
    public function preflight(string $date, ?int $campusId = null): array
    {
        $trips = Trip::query()->whereDate('trip_date', $date)
            ->when($campusId, fn ($q, $campus) => $q->where('campus_id', $campus));

        $unfinished = (clone $trips)->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->count();
        $openIncidents = TransportationIncident::query()
            ->whereDate('occurred_at', $date)
            ->whereNotIn('status', ['CLOSED', 'RESOLVED'])
            ->count();

        return [
            'date' => $date,
            'campus_id' => $campusId,
            'trips_total' => (clone $trips)->count(),
            'trips_unfinished' => $unfinished,
            'open_incidents' => $openIncidents,
            'ready' => $unfinished === 0 && $openIncidents === 0,
            'warnings' => array_filter([
                $unfinished > 0 ? 'Masih ada '.$unfinished.' trip yang belum selesai.' : null,
                $openIncidents > 0 ? 'Masih ada '.$openIncidents.' insiden belum tuntas.' : null,
            ]),
        ];
    }

    public function close(string $date, ?int $campusId = null, ?int $actorId = null, bool $force = false): array
    {
        $preflight = $this->preflight($date, $campusId);
        if (! $preflight['ready'] && ! $force) {
            throw ValidationException::withMessages([
                'date' => 'Penutupan ditolak: '.implode(' ', $preflight['warnings']),
            ]);
        }

        $tripQuery = Trip::query()->whereDate('trip_date', $date)
            ->when($campusId, fn ($q, $campus) => $q->where('campus_id', $campus));

        $trips = (clone $tripQuery)->get();
        $tripIds = $trips->pluck('id')->all();

        $manifests = TripManifest::query()->whereIn('trip_id', $tripIds)->get();
        $costs = TransportationCost::query()->whereIn('trip_id', $tripIds)->get();
        $incidents = TransportationIncident::query()->whereIn('trip_id', $tripIds)->count();
        $passengers = TripPassenger::query()->whereIn('trip_id', $tripIds)->where('boarding_status', 'BOARDED')->count();
        $noShows = TripPassenger::query()->whereIn('trip_id', $tripIds)->where('boarding_status', 'NO_SHOW')->count();
        $distance = round((float) $trips->sum('distance_km'), 2);
        $fuelCost = round((float) TransportationCost::query()->whereIn('trip_id', $tripIds)->where('cost_category', 'FUEL')->sum('amount'), 2);
        $operationalCost = round((float) $costs->sum('amount'), 2);

        $completedTrips = $trips->whereIn('status', ['COMPLETED'])->count();
        $delayedTrips = $trips->where('delay_minutes', '>', 0)->count();
        $onTimeRate = $completedTrips > 0 ? round(($completedTrips - $delayedTrips) / $completedTrips * 100, 2) : 0.0;

        $closing = TransportationDailyClosing::query()->updateOrCreate(
            ['closing_date' => $date, 'campus_id' => $campusId],
            [
                'trips_planned' => $trips->count(),
                'trips_completed' => $completedTrips,
                'trips_cancelled' => $trips->where('status', 'CANCELLED')->count(),
                'passengers_transported' => $passengers,
                'no_shows' => $noShows,
                'incidents' => $incidents,
                'total_distance_km' => $distance,
                'total_fuel_cost' => $fuelCost,
                'total_operational_cost' => $operationalCost,
                'cost_per_passenger' => $passengers > 0 ? round($operationalCost / $passengers, 2) : 0,
                'on_time_rate' => $onTimeRate,
                'status' => 'CLOSED',
                'closed_by' => $actorId ?? $this->context->actorId(),
                'closed_at' => now(),
                'summary' => [
                    'preflight' => $preflight,
                    'manifests' => $manifests->count(),
                    'expected_passengers' => (int) $manifests->sum('expected_passengers'),
                    'no_show_rate' => $passengers > 0 ? round($noShows / max(1, $passengers + $noShows) * 100, 2) : 0,
                    'forced' => ! $preflight['ready'],
                ],
            ]
        );

        $this->context->publish('TransportationDailyClosingCompleted', $closing, [
            'date' => $date,
            'campus_id' => $campusId,
            'trips' => $closing->trips_planned,
            'passengers' => $closing->passengers_transported,
            'operational_cost' => $closing->total_operational_cost,
        ]);

        return [
            'closing_id' => $closing->id,
            'date' => $date,
            'status' => $closing->status,
            'summary' => $closing->only(['trips_planned', 'trips_completed', 'trips_cancelled', 'passengers_transported',
                'no_shows', 'incidents', 'total_distance_km', 'total_fuel_cost', 'total_operational_cost',
                'cost_per_passenger', 'on_time_rate']),
            'forced' => ! $preflight['ready'],
            'preflight' => $preflight,
        ];
    }

    /** Buka kembali penutupan — wajib alasan, tercatat sebagai adjustment (§87 Rule 13). */
    public function reopen(int $closingId, string $reason, ?int $actorId = null): array
    {
        $closing = TransportationDailyClosing::query()->findOrFail($closingId);
        if ($closing->status !== 'CLOSED') {
            throw ValidationException::withMessages(['status' => 'Penutupan berstatus '.$closing->status.' tidak dapat dibuka ulang.']);
        }

        $closing->forceFill([
            'status' => 'REOPENED',
            'reopened_by' => $actorId ?? $this->context->actorId(),
            'reopened_at' => now(),
            'reopen_reason' => $reason,
        ])->save();

        $this->context->audit('DailyClosingReopened', $closing, ['reason' => $reason]);

        return ['closing_id' => $closing->id, 'status' => $closing->status, 'reason' => $reason];
    }

    /** Penyesuaian pasca-closing: koreksi boarding/biaya dengan alasan & audit. */
    public function adjust(array $payload): array
    {
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Penyesuaian wajib memiliki alasan (§87 Rule 13).']);
        }

        $type = strtoupper((string) $payload['adjustment_type']);
        $referenceId = (int) $payload['reference_id'];
        $before = null;
        $after = null;
        $effect = [];

        if ($type === 'BOARDING_CORRECTION') {
            $passenger = TripPassenger::query()->findOrFail($referenceId);
            $before = ['boarding_status' => $passenger->boarding_status];
            $passenger->forceFill([
                'boarding_status' => strtoupper((string) $payload['boarding_status']),
                'absence_reason' => $reason,
            ])->save();
            $after = ['boarding_status' => $passenger->boarding_status];
            $effect = ['passenger_id' => $passenger->id];
        } elseif ($type === 'COST_CORRECTION') {
            $cost = TransportationCost::query()->findOrFail($referenceId);
            $before = ['amount' => (float) $cost->amount];
            $cost->forceFill(['amount' => (float) $payload['amount'], 'notes' => $reason])->save();
            $after = ['amount' => (float) $cost->amount];
            $effect = ['cost_id' => $cost->id];
        } elseif ($type === 'CLOSING_REOPEN') {
            $effect = $this->reopen($referenceId, $reason, $payload['approved_by'] ?? null);
            $before = ['closing_status' => 'CLOSED'];
            $after = ['closing_status' => 'REOPENED'];
        } else {
            throw ValidationException::withMessages(['adjustment_type' => 'Jenis penyesuaian tidak dikenal: '.$type]);
        }

        $adjustment = TransportationAdjustment::query()->create([
            'reference_type' => strtolower((string) ($payload['reference_type'] ?? 'trip_passenger')),
            'reference_id' => $referenceId,
            'adjustment_type' => $type,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'requested_by' => $this->context->actorId(),
            'approved_by' => $payload['approved_by'] ?? null,
            'approved_at' => isset($payload['approved_by']) ? now() : null,
            'status' => 'APPLIED',
        ]);

        if (isset($payload['trip_id'])) {
            $manifest = TripManifest::query()->firstOrCreate(['trip_id' => (int) $payload['trip_id']]);
            app(ManifestService::class)->recalculate(Trip::query()->findOrFail((int) $payload['trip_id']));
        }

        $this->context->audit('TransportationAdjustmentApplied', $adjustment, ['type' => $type, 'reason' => $reason]);

        return ['adjustment_id' => $adjustment->id, 'type' => $type, 'effect' => $effect, 'before' => $before, 'after' => $after];
    }

    public function closings(array $filters = []): array
    {
        return TransportationDailyClosing::query()
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('closing_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('closing_date', '<=', $to))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', strtoupper($status)))
            ->orderByDesc('closing_date')
            ->limit((int) ($filters['limit'] ?? 60))
            ->get()
            ->map(fn (TransportationDailyClosing $closing): array => $closing->only([
                'id', 'closing_date', 'campus_id', 'trips_planned', 'trips_completed', 'passengers_transported',
                'no_shows', 'incidents', 'total_distance_km', 'total_operational_cost', 'cost_per_passenger',
                'on_time_rate', 'status', 'closed_at',
            ]))
            ->all();
    }
}
