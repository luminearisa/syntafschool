<?php

namespace App\Modules\SchoolOperations\Transportation\Application\Services;

use App\Modules\SchoolOperations\Transportation\Domain\Support\TransportContext;
use App\Modules\SchoolOperations\Transportation\Models\{
    TransportationAssignment, StudentTransportProfile, Trip, TripManifest, TripPassenger, TripStop
};
use Illuminate\Validation\ValidationException;

/**
 * Manifes penumpang (§33-§35): snapshot penumpang saat trip dibentuk,
 * status naik/turun, kapasitas, dan okupansi.
 *
 * Manifes adalah *snapshot* — perubahan penugasan setelah trip berjalan tidak
 * mengubah riwayat; koreksi dilakukan lewat adjustment yang diaudit (§13 aturan).
 */
class ManifestService
{
    public function __construct(private readonly TransportContext $context)
    {
    }

    /** Bentuk/segarkan manifes trip dari penugasan aktif. */
    public function generate(Trip $trip, array $options = []): TripManifest
    {
        $existing = TripManifest::query()->where('trip_id', $trip->id)->first();
        if ($existing && $existing->status !== 'DRAFT' && ! ($options['rebuild'] ?? false)) {
            return $existing;
        }

        $manifest = $existing ?? new TripManifest(['trip_id' => $trip->id]);
        $routeId = $trip->direction === 'DROPOFF'
            ? ($trip->route_id)
            : ($trip->route_id);

        $assignments = TransportationAssignment::query()
            ->where('status', 'ACTIVE')
            ->where('route_id', $routeId)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $trip->trip_date?->toDateString()))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $trip->trip_date?->toDateString()))
            ->when($trip->direction === 'PICKUP', fn ($q) => $q->whereNotNull('pickup_stop_id'))
            ->when($trip->direction === 'DROPOFF', fn ($q) => $q->whereNotNull('dropoff_stop_id'))
            ->get();

        $capacity = (int) optional($trip->vehicle)->student_capacity ?: (int) ($trip->planned_passengers ?: 0);
        $expected = $assignments->count();

        $manifest->fill([
            'route_version_id' => $trip->route_version_id,
            'expected_passengers' => $expected,
            'capacity' => $capacity,
            'occupancy_percent' => $capacity > 0 ? round($expected / $capacity * 100, 2) : 0.0,
            'status' => $options['finalize'] ?? false ? 'FINALIZED' : 'DRAFT',
            'generated_by' => $this->context->actorId(),
            'generated_at' => now(),
            'meta' => [
                'direction' => $trip->direction,
                'generated_for_date' => $trip->trip_date?->toDateString(),
                'assignment_count' => $expected,
            ],
        ])->save();

        $seat = 0;
        foreach ($assignments as $assignment) {
            $profile = StudentTransportProfile::query()->where('student_id', $assignment->student_id)->first();

            TripPassenger::query()->updateOrCreate(
                ['trip_id' => $trip->id, 'student_id' => $assignment->student_id],
                [
                    'manifest_id' => $manifest->id,
                    'employee_id' => null,
                    'assignment_id' => $assignment->id,
                    'pickup_stop_id' => $assignment->pickup_stop_id,
                    'dropoff_stop_id' => $assignment->dropoff_stop_id,
                    'seat_number' => ++$seat,
                    'passenger_type' => 'STUDENT',
                    'assignment_status' => 'ASSIGNED',
                    'boarding_status' => 'EXPECTED',
                    'dropoff_status' => 'EXPECTED',
                    'special_instruction' => $profile?->special_requirement_note,
                    'special_needs' => (bool) ($profile?->wheelchair_required || $profile?->escort_required || $profile?->special_requirement),
                ]
            );
        }

        $trip->forceFill(['planned_passengers' => $expected])->save();

        $this->recalculate($trip->refresh());
        $this->updateStopCounters($trip->refresh());
        $this->context->audit('TripManifestGenerated', $manifest, [
            'trip_number' => $trip->trip_number, 'expected' => $expected, 'capacity' => $capacity,
        ]);

        return $manifest->refresh();
    }

    /** Tambahkan penumpang non-siswa (staf, guru, tamu) ke manifes (§ staff/teacher transport). */
    public function addPassenger(Trip $trip, array $payload): TripPassenger
    {
        $manifest = TripManifest::query()->firstOrCreate(['trip_id' => $trip->id], ['capacity' => (int) optional($trip->vehicle)->student_capacity]);

        $passenger = TripPassenger::query()->create([
            'trip_id' => $trip->id,
            'manifest_id' => $manifest->id,
            'student_id' => $payload['student_id'] ?? null,
            'employee_id' => $payload['employee_id'] ?? null,
            'pickup_stop_id' => $payload['pickup_stop_id'] ?? null,
            'dropoff_stop_id' => $payload['dropoff_stop_id'] ?? null,
            'passenger_type' => strtoupper((string) ($payload['passenger_type'] ?? 'STAFF')),
            'boarding_status' => 'EXPECTED',
            'dropoff_status' => 'EXPECTED',
            'special_instruction' => $payload['special_instruction'] ?? null,
        ]);

        $this->recalculate($trip);

        return $passenger;
    }

    /** Perbarui status naik/turun dan hitung ulang agregat. */
    public function updateStatus(TripPassenger $passenger, string $field, string $status, array $meta = []): TripPassenger
    {
        $passenger->forceFill([
            $field => strtoupper($status),
        ] + $meta)->save();

        $trip = Trip::query()->findOrFail($passenger->trip_id);
        $this->recalculate($trip);
        $this->updateStopCounters($trip);

        return $passenger->refresh();
    }

    public function recalculate(Trip $trip): TripManifest
    {
        $manifest = TripManifest::query()->firstOrCreate(['trip_id' => $trip->id]);
        $passengers = TripPassenger::query()->where('trip_id', $trip->id)->get();
        $capacity = (int) ($manifest->capacity ?: optional($trip->vehicle)->student_capacity);

        $manifest->fill([
            'expected_passengers' => $passengers->count(),
            'boarded_passengers' => $passengers->where('boarding_status', 'BOARDED')->count(),
            'dropped_off_passengers' => $passengers->where('dropoff_status', 'DROPPED_OFF')->count(),
            'absent_passengers' => $passengers->where('boarding_status', 'ABSENT')->count(),
            'no_show_passengers' => $passengers->where('boarding_status', 'NO_SHOW')->count(),
            'capacity' => $capacity,
            'occupancy_percent' => $capacity > 0 ? round($passengers->count() / $capacity * 100, 2) : 0.0,
        ])->save();

        $trip->forceFill([
            'planned_passengers' => $passengers->count(),
            'boarded_count' => $manifest->boarded_passengers,
            'dropped_off_count' => $manifest->dropped_off_passengers,
            'no_show_count' => $manifest->no_show_passengers,
        ])->save();

        return $manifest->refresh();
    }

    /** Hitung ulang jumlah penumpang per halte trip (§41). */
    public function updateStopCounters(Trip $trip): void
    {
        foreach (TripStop::query()->where('trip_id', $trip->id)->get() as $stop) {
            // Penugasan siswa merujuk halte rute, sedangkan aksi pengemudi merujuk
            // halte trip — keduanya harus dihitung sebagai halte yang sama.
            $references = array_values(array_unique(array_filter([$stop->id, $stop->route_stop_id])));

            $boarded = TripPassenger::query()->where('trip_id', $trip->id)
                ->whereIn('boarded_stop_id', $references)
                ->whereIn('boarding_status', ['BOARDED', 'TRANSFERRED'])->count();
            $dropped = TripPassenger::query()->where('trip_id', $trip->id)
                ->whereIn('dropped_off_stop_id', $references)
                ->whereIn('dropoff_status', ['DROPPED_OFF', 'AUTHORIZED_PICKUP', 'TRANSFERRED'])->count();
            $noShow = TripPassenger::query()->where('trip_id', $trip->id)
                ->whereIn('pickup_stop_id', $references)
                ->where('boarding_status', 'NO_SHOW')->count();
            $planned = TripPassenger::query()->where('trip_id', $trip->id)
                ->where(fn ($q) => $q->whereIn('pickup_stop_id', $references)->orWhereIn('dropoff_stop_id', $references))
                ->count();

            $stop->forceFill([
                'planned_passengers' => $planned,
                'boarded_count' => $boarded,
                'dropped_off_count' => $dropped,
                'no_show_count' => $noShow,
            ])->save();
        }
    }

    public function manifestSnapshot(Trip $trip): array
    {
        $manifest = TripManifest::query()->where('trip_id', $trip->id)->first();
        $passengers = TripPassenger::query()->where('trip_id', $trip->id)->orderBy('seat_number')->get();

        return [
            'trip' => [
                'id' => $trip->id,
                'trip_number' => $trip->trip_number,
                'trip_date' => $trip->trip_date?->toDateString(),
                'direction' => $trip->direction,
                'status' => $trip->status,
                'route_id' => $trip->route_id,
                'vehicle_id' => $trip->vehicle_id,
                'driver_profile_id' => $trip->driver_profile_id,
            ],
            'manifest' => $manifest ? $manifest->only(['id', 'status', 'expected_passengers', 'boarded_passengers',
                'dropped_off_passengers', 'absent_passengers', 'no_show_passengers', 'capacity', 'occupancy_percent']) : null,
            'passengers' => $passengers->map(fn (TripPassenger $passenger): array => [
                'id' => $passenger->id,
                'student_id' => $passenger->student_id,
                'employee_id' => $passenger->employee_id,
                'passenger_type' => $passenger->passenger_type,
                'seat_number' => $passenger->seat_number,
                'pickup_stop_id' => $passenger->pickup_stop_id,
                'dropoff_stop_id' => $passenger->dropoff_stop_id,
                'boarding_status' => $passenger->boarding_status,
                'dropoff_status' => $passenger->dropoff_status,
                'boarded_at' => $passenger->boarded_at,
                'dropped_off_at' => $passenger->dropped_off_at,
                'special_instruction' => $passenger->special_instruction,
                'special_needs' => (bool) $passenger->special_needs,
            ])->all(),
            'stops' => TripStop::query()->where('trip_id', $trip->id)->orderBy('sequence')->get()->map(
                fn (TripStop $stop): array => [
                    'id' => $stop->id,
                    'sequence' => $stop->sequence,
                    'name' => $stop->name,
                    'status' => $stop->status,
                    'planned_arrival_at' => $stop->planned_arrival_at,
                    'actual_arrival_at' => $stop->actual_arrival_at,
                    'planned_passengers' => $stop->planned_passengers,
                    'boarded_count' => $stop->boarded_count,
                    'dropped_off_count' => $stop->dropped_off_count,
                    'no_show_count' => $stop->no_show_count,
                ]
            )->all(),
        ];
    }
}
