<?php

namespace App\Modules\SchoolOperations\Transportation\Application\Services;

use App\Modules\SchoolOperations\Transportation\Domain\Support\TransportContext;
use App\Modules\SchoolOperations\Transportation\Models\{ScheduleException, TransportationSchedule, TransportationRoute, Trip};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Jadwal (§29) & integrasi kalender sekolah (§53).
 *
 * Jadwal berulang + pengecualian (libur, ujian, pulang cepat/lambat, kegiatan)
 * menjadi sumber pembentukan trip harian. Tanggal libur tidak menghasilkan trip.
 */
class SchedulingService
{
    public function __construct(
        private readonly TransportContext $context,
        private readonly TripService $trips,
    ) {
    }

    public function createSchedule(array $payload): TransportationSchedule
    {
        $route = TransportationRoute::query()->findOrFail((int) $payload['route_id']);
        if (! $route->active) {
            throw ValidationException::withMessages(['route_id' => 'Rute tidak aktif sehingga tidak dapat dijadwalkan.']);
        }

        $schedule = TransportationSchedule::query()->create([
            'code' => $payload['code'] ?? ('SCH-'.str_pad((string) (TransportationSchedule::query()->count() + 1), 4, '0', STR_PAD_LEFT)),
            'name' => $payload['name'] ?? ($route->name.' '.strtoupper((string) ($payload['direction'] ?? 'PICKUP'))),
            'route_id' => $route->id,
            'service_id' => $payload['service_id'] ?? $route->service_id,
            'schedule_type' => strtoupper((string) ($payload['schedule_type'] ?? 'SCHOOL_DAYS')),
            'direction' => strtoupper((string) ($payload['direction'] ?? 'PICKUP')),
            'days_of_week' => $payload['days_of_week'] ?? [1, 2, 3, 4, 5],
            'valid_from' => $payload['valid_from'] ?? now()->toDateString(),
            'valid_until' => $payload['valid_until'] ?? null,
            'specific_dates' => $payload['specific_dates'] ?? null,
            'departure_time' => $payload['departure_time'] ?? '06:00',
            'expected_arrival_time' => $payload['expected_arrival_time'] ?? null,
            'lead_minutes' => (int) ($payload['lead_minutes'] ?? 30),
            'default_vehicle_id' => $payload['default_vehicle_id'] ?? null,
            'default_driver_profile_id' => $payload['default_driver_profile_id'] ?? null,
            'holiday_exception' => (bool) ($payload['holiday_exception'] ?? true),
            'active' => (bool) ($payload['active'] ?? true),
            'status' => 'ACTIVE',
            'notes' => $payload['notes'] ?? null,
        ]);

        $this->context->audit('TransportationScheduleCreated', $schedule, ['code' => $schedule->code]);

        return $schedule;
    }

    public function updateSchedule(TransportationSchedule $schedule, array $payload): TransportationSchedule
    {
        $schedule->fill(array_intersect_key($payload, array_flip([
            'name', 'service_id', 'schedule_type', 'direction', 'days_of_week', 'valid_from', 'valid_until',
            'specific_dates', 'departure_time', 'expected_arrival_time', 'lead_minutes', 'default_vehicle_id',
            'default_driver_profile_id', 'holiday_exception', 'active', 'status', 'notes',
        ])))->save();

        $this->context->audit('TransportationScheduleUpdated', $schedule, ['code' => $schedule->code]);

        return $schedule->refresh();
    }

    /** Pengecualian jadwal (§29/§53). */
    public function addException(array $payload): ScheduleException
    {
        $exception = ScheduleException::query()->create([
            'schedule_id' => $payload['schedule_id'] ?? null,
            'route_id' => $payload['route_id'] ?? null,
            'exception_date' => $payload['exception_date'],
            'exception_type' => strtoupper((string) $payload['exception_type']),
            'override_departure_time' => $payload['override_departure_time'] ?? null,
            'override_arrival_time' => $payload['override_arrival_time'] ?? null,
            'source' => strtoupper((string) ($payload['source'] ?? 'MANUAL')),
            'source_reference' => $payload['source_reference'] ?? null,
            'cancel_trips' => (bool) ($payload['cancel_trips'] ?? false),
            'notes' => $payload['notes'] ?? null,
            'created_by' => $this->context->actorId(),
        ]);

        $this->context->audit('ScheduleExceptionCreated', $exception, [
            'date' => $exception->exception_date, 'type' => $exception->exception_type,
        ]);

        return $exception;
    }

    /**
     * Sinkronkan kalender akademik menjadi pengecualian jadwal (§53):
     * hari libur membatalkan trip, hari ujian/pulang cepat menggeser jam.
     */
    public function syncAcademicCalendar(?string $from = null, ?string $to = null): array
    {
        $from ??= now()->toDateString();
        $to ??= now()->addDays(60)->toDateString();

        if (! DB::getSchemaBuilder()->hasTable('academic_events')) {
            return ['synced' => 0, 'skipped' => true, 'reason' => 'Kalender akademik belum tersedia pada instalasi ini.'];
        }

        $events = DB::table('academic_events')
            ->where('is_active', true)
            ->whereBetween('start_date', [$from, $to])
            ->get();

        $synced = [];
        foreach ($events as $event) {
            $isHoliday = (bool) ($event->is_holiday ?? false);
            $existing = ScheduleException::query()
                ->whereDate('exception_date', $event->start_date)
                ->where('source', 'ACADEMIC_CALENDAR')
                ->where('source_reference', (string) $event->id)
                ->first();
            if ($existing) {
                continue;
            }

            $synced[] = $this->addException([
                'exception_date' => $event->start_date,
                'exception_type' => $isHoliday ? 'HOLIDAY' : 'EVENT',
                'source' => 'ACADEMIC_CALENDAR',
                'source_reference' => (string) $event->id,
                'cancel_trips' => $isHoliday,
                'notes' => $event->title ?? null,
            ]);
        }

        return [
            'synced' => count($synced),
            'events_considered' => $events->count(),
            'period' => [$from, $to],
        ];
    }

    /** Apakah jadwal beroperasi pada tanggal tertentu? */
    public function isOperationalDate(TransportationSchedule $schedule, string $date): array
    {
        $day = CarbonImmutable::parse($date);
        $exceptions = ScheduleException::query()
            ->whereDate('exception_date', $date)
            ->where(fn ($query) => $query->where('schedule_id', $schedule->id)->orWhereNull('schedule_id'))
            ->where(fn ($query) => $query->where('route_id', $schedule->route_id)->orWhereNull('route_id'))
            ->orderByDesc('schedule_id')
            ->get();

        foreach ($exceptions as $exception) {
            if ($exception->cancel_trips) {
                return [
                    'operational' => false,
                    'reason' => 'Pengecualian '.$exception->exception_type.' pada '.$date.' (trip dibatalkan).',
                    'exception_id' => $exception->id,
                    'override_departure' => $exception->override_departure_time,
                ];
            }
        }

        if ($schedule->valid_from && $day->lt(CarbonImmutable::parse($schedule->valid_from))) {
            return ['operational' => false, 'reason' => 'Di luar periode berlaku jadwal.', 'exception_id' => null];
        }
        if ($schedule->valid_until && $day->gt(CarbonImmutable::parse($schedule->valid_until))) {
            return ['operational' => false, 'reason' => 'Melewati periode berlaku jadwal.', 'exception_id' => null];
        }

        $specificDates = (array) ($schedule->specific_dates ?? []);
        if ($specificDates !== []) {
            if (! in_array($date, $specificDates, true)) {
                return ['operational' => false, 'reason' => 'Tanggal tidak termasuk daftar tanggal khusus jadwal.', 'exception_id' => null];
            }

            return ['operational' => true, 'reason' => 'Tanggal khusus jadwal.', 'exception_id' => null];
        }

        $days = array_map('intval', (array) ($schedule->days_of_week ?? []));
        if ($days !== [] && ! in_array($day->dayOfWeekIso, $days, true)) {
            return ['operational' => false, 'reason' => 'Hari tidak termasuk hari operasional jadwal.', 'exception_id' => null];
        }

        $override = $exceptions->firstWhere('cancel_trips', false);

        return [
            'operational' => true,
            'reason' => $override ? 'Pengecualian '.$override->exception_type.' mengubah jam operasional.' : 'Sesuai jadwal rutin.',
            'exception_id' => $override?->id,
            'override_departure' => $override?->override_departure_time,
            'override_arrival' => $override?->override_arrival_time,
        ];
    }

    /** Bentuk trip untuk sebuah tanggal dari seluruh jadwal aktif. */
    public function generateTrips(string $date, array $filters = []): array
    {
        $schedules = TransportationSchedule::query()
            ->where('active', true)
            ->when($filters['route_id'] ?? null, fn ($q, $routeId) => $q->where('route_id', $routeId))
            ->when($filters['schedule_id'] ?? null, fn ($q, $scheduleId) => $q->where('id', $scheduleId))
            ->when($filters['direction'] ?? null, fn ($q, $direction) => $q->where('direction', strtoupper($direction)))
            ->when($filters['campus_id'] ?? null, fn ($q, $campus) => $q->whereHas('route', fn ($r) => $r->where('campus_id', $campus)))
            ->get();

        $created = [];
        $skipped = [];
        foreach ($schedules as $schedule) {
            $state = $this->isOperationalDate($schedule, $date);
            if (! $state['operational']) {
                $skipped[] = ['schedule_id' => $schedule->id, 'code' => $schedule->code, 'reason' => $state['reason']];
                continue;
            }

            $trip = $this->trips->createFromSchedule($schedule, $date, [
                'departure_time' => $state['override_departure'] ?? $schedule->departure_time,
                'arrival_time' => $state['override_arrival'] ?? $schedule->expected_arrival_time,
                'exception_id' => $state['exception_id'] ?? null,
            ]);

            $created[] = $trip->only(['id', 'trip_number', 'trip_date', 'direction', 'status', 'planned_departure']);
        }

        return [
            'date' => $date,
            'created' => $created,
            'created_count' => count($created),
            'skipped' => $skipped,
            'skipped_count' => count($skipped),
        ];
    }

    /** Kalender operasional: trip per tanggal pada rentang tertentu. */
    public function calendar(string $from, string $to, array $filters = []): array
    {
        $trips = Trip::query()
            ->whereDate('trip_date', '>=', $from)->whereDate('trip_date', '<=', $to)
            ->when($filters['route_id'] ?? null, fn ($q, $routeId) => $q->where('route_id', $routeId))
            ->when($filters['campus_id'] ?? null, fn ($q, $campus) => $q->where('campus_id', $campus))
            ->orderBy('trip_date')->orderBy('planned_departure')
            ->get();

        $grouped = $trips->groupBy(fn (Trip $trip): string => $trip->trip_date->toDateString());

        return [
            'from' => $from,
            'to' => $to,
            'days' => $grouped->map(fn ($items, $date): array => [
                'date' => $date,
                'trips' => $items->count(),
                'planned_passengers' => (int) $items->sum('planned_passengers'),
                'statuses' => $items->groupBy('status')->map->count()->all(),
                'items' => $items->map(fn (Trip $trip): array => [
                    'id' => $trip->id,
                    'trip_number' => $trip->trip_number,
                    'route_id' => $trip->route_id,
                    'direction' => $trip->direction,
                    'planned_departure' => $trip->planned_departure,
                    'status' => $trip->status,
                ])->values()->all(),
            ])->values()->all(),
            'total_trips' => $trips->count(),
            'exceptions' => ScheduleException::query()
                ->whereDate('exception_date', '>=', $from)->whereDate('exception_date', '<=', $to)
                ->orderBy('exception_date')
                ->get()
                ->map(fn (ScheduleException $exception): array => [
                    'date' => $exception->exception_date->toDateString(),
                    'type' => $exception->exception_type,
                    'source' => $exception->source,
                    'cancel_trips' => (bool) $exception->cancel_trips,
                    'notes' => $exception->notes,
                ])->all(),
        ];
    }
}
