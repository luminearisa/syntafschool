<?php

namespace App\Modules\Hrd\Workforce\Services;

use App\Models\User;
use App\Modules\Hrd\Core\Domain\Contracts\AttendanceProviderInterface;
use App\Modules\Hrd\Core\Support\HrdPhase2Events;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Workforce\Attendance\AttendanceProviderRegistry;
use App\Modules\Hrd\Workforce\Models\HrAttendanceCorrection;
use App\Modules\Hrd\Workforce\Models\HrAttendanceEvent;
use App\Modules\Hrd\Workforce\Models\HrAttendanceLocation;
use App\Modules\Hrd\Workforce\Models\HrAttendanceRecord;
use App\Modules\Hrd\Workforce\Models\HrBusinessTrip;
use App\Modules\Hrd\Workforce\Models\HrOvertimeRecord;
use App\Modules\Hrd\Workforce\Models\HrWorkforceImport;
use App\Modules\Hrd\Leave\Models\HrHoliday;
use App\Modules\Hrd\Leave\Models\HrHolidayCalendar;
use App\Modules\Hrd\Leave\Models\HrLeaveRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Presensi: satu jalur masuk, banyak sumber.
 *
 * Staff HR, web clock, aplikasi mobile, dan perangkat (QR, NFC, RFID,
 * sidik jari, wajah, API) semuanya berakhir di fungsi yang sama. Perangkat
 * hanya menyeragamkan muatan mentah; aturan keterlambatan, lintas tengah
 * malam, geofence, dan lembur dihitung sekali di sini — sehingga tidak ada
 * dua versi kebenaran tentang jam kerja seseorang.
 */
class AttendanceService implements AttendanceProviderInterface
{
    public function __construct(
        private readonly ScheduleService $schedules,
        private readonly AttendanceProviderRegistry $providers,
        private readonly HrdPhase2Events $events,
    ) {}

    // ------------------------------------------------------------------ kontrak

    public function recordClockIn(int $employeeId, array $payload): array
    {
        return $this->clockIn($employeeId, $payload, $payload['actor'] ?? null);
    }

    public function recordClockOut(int $employeeId, array $payload): array
    {
        return $this->clockOut($employeeId, $payload, $payload['actor'] ?? null);
    }

    /** @return array<string, mixed> */
    public function attendanceSummary(int $employeeId, string $from, string $to): array
    {
        $records = HrAttendanceRecord::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$from, $to])
            ->get();

        return [
            'from' => $from,
            'to' => $to,
            'days' => $records->count(),
            'present' => $records->whereIn('status', ['PRESENT', 'REMOTE'])->count(),
            'late' => $records->where('status', 'LATE')->count(),
            'late_minutes' => (int) $records->sum('late_minutes'),
            'absent' => $records->where('status', 'ABSENT')->count(),
            'leave' => $records->where('status', 'LEAVE')->count(),
            'business_trip' => $records->where('status', 'BUSINESS_TRIP')->count(),
            'holiday' => $records->where('status', 'HOLIDAY')->count(),
            'missing_clock' => $records->whereIn('status', ['MISSING_CLOCK_IN', 'MISSING_CLOCK_OUT'])->count(),
            'worked_hours' => round($records->sum('worked_minutes') / 60, 2),
            'overtime_hours' => round($records->sum('overtime_minutes') / 60, 2),
        ];
    }

    public function absenceDays(int $employeeId, string $from, string $to): float
    {
        return (float) HrAttendanceRecord::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$from, $to])
            ->where('status', 'ABSENT')
            ->count();
    }

    public function lateMinutes(int $employeeId, string $from, string $to): int
    {
        return (int) HrAttendanceRecord::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$from, $to])
            ->sum('late_minutes');
    }

    public function overtimeHours(int $employeeId, string $from, string $to): float
    {
        return round((float) HrOvertimeRecord::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$from, $to])
            ->where('status', 'VERIFIED')
            ->sum('verified_minutes') / 60, 2);
    }

    // ------------------------------------------------------------------ pencatatan

    /** @param array<string, mixed> $payload */
    public function clockIn(HrEmployee|int $employee, array $payload, ?User $actor = null): array
    {
        return $this->record($employee, 'CLOCK_IN', $payload, $actor);
    }

    /** @param array<string, mixed> $payload */
    public function clockOut(HrEmployee|int $employee, array $payload, ?User $actor = null): array
    {
        return $this->record($employee, 'CLOCK_OUT', $payload, $actor);
    }

    /**
     * Mencatat satu peristiwa presensi, idempoten bila `idempotency_key` diisi
     * (penting untuk sinkronisasi perangkat yang dapat mengirim ulang data).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function record(HrEmployee|int $employee, string $eventType, array $payload, ?User $actor = null): array
    {
        $employeeId = $employee instanceof HrEmployee ? (int) $employee->getKey() : $employee;
        $occurredAt = isset($payload['occurred_at']) ? Carbon::parse($payload['occurred_at']) : now();
        $provider = strtolower((string) ($payload['provider'] ?? config('hrd_phase2.attendance.default_provider', 'manual')));

        if (! in_array($provider, config('hrd_phase2.attendance.providers', []), true)) {
            throw HrdValidationException::forField('provider', "Penyedia presensi \"{$provider}\" tidak dikenal.", 'attendance.provider_unknown');
        }

        $key = $payload['idempotency_key'] ?? null;

        if ($key !== null) {
            $existing = HrAttendanceEvent::query()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                $record = HrAttendanceRecord::query()->find($existing->attendance_record_id);

                return ['event_id' => $existing->getKey(), 'record_id' => $existing->getKey() === null ? null : $record?->getKey(), 'duplicate' => true];
            }
        }

        return DB::transaction(function () use ($employeeId, $eventType, $payload, $actor, $occurredAt, $provider, $key) {
            $record = $this->dailyRecord($employeeId, $occurredAt->toDateString(), $actor);

            $this->assertGeofence($payload, $record);

            $event = HrAttendanceEvent::query()->create([
                'attendance_record_id' => $record->getKey(),
                'employee_id' => $employeeId,
                'event_type' => strtoupper($eventType),
                'occurred_at' => $occurredAt,
                'provider' => $provider,
                'device_id' => $payload['device_id'] ?? $record->device_id,
                'latitude' => $payload['latitude'] ?? null,
                'longitude' => $payload['longitude'] ?? null,
                'accuracy' => $payload['accuracy'] ?? null,
                'ip_address' => $payload['ip_address'] ?? null,
                'user_agent' => isset($payload['user_agent']) ? mb_substr((string) $payload['user_agent'], 0, 190) : null,
                'raw' => $payload['raw'] ?? null,
                'idempotency_key' => $key,
                'source_reference' => $payload['source_reference'] ?? null,
                'created_by' => $actor?->getKey(),
            ]);

            $record->forceFill([strtoupper($eventType) === 'CLOCK_IN' ? 'clock_in' : 'clock_out' => $occurredAt]);
            $record->forceFill([
                'clock_in_source' => strtoupper($eventType) === 'CLOCK_IN' ? $provider : $record->clock_in_source,
                'clock_out_source' => strtoupper($eventType) === 'CLOCK_OUT' ? $provider : $record->clock_out_source,
                'device_id' => $payload['device_id'] ?? $record->device_id,
                'location_id' => $payload['location_id'] ?? $record->location_id,
                'is_remote' => (bool) ($payload['is_remote'] ?? $record->is_remote),
            ])->save();

            $record = $this->recalculate($record->refresh());

            $this->events->emit('WORKFORCE', 'attendance.'.strtolower($eventType), [
                'actor' => $actor,
                'employee_id' => $employeeId,
                'subject_type' => HrAttendanceRecord::class,
                'subject_id' => $record->getKey(),
                'title' => (strtoupper($eventType) === 'CLOCK_IN' ? 'Masuk' : 'Keluar').' '.$occurredAt->format('H:i'),
                'payload' => ['status' => $record->status, 'late_minutes' => $record->late_minutes, 'provider' => $provider],
            ]);

            return [
                'event_id' => $event->getKey(),
                'record_id' => $record->getKey(),
                'status' => $record->status,
                'late_minutes' => (int) $record->late_minutes,
                'worked_minutes' => (int) $record->worked_minutes,
                'duplicate' => false,
            ];
        });
    }

    /** Menerima muatan mentah perangkat, menyeragamkan, lalu mencatat. */
    public function ingestFromDevice(string $providerCode, array $raw, ?User $actor = null): array
    {
        $provider = $this->providers->resolve($providerCode);
        $normalized = $provider->normalize($raw);

        $results = [];

        foreach ($normalized as $event) {
            $results[] = $this->record((int) $event['employee_id'], (string) $event['event_type'], array_merge($event, [
                'provider' => $providerCode,
                'actor' => $actor,
            ]), $actor);
        }

        return ['provider' => $providerCode, 'processed' => count($results), 'results' => $results];
    }

    /** Impor massal (berkas/dekstop perangkat) dengan jejak idempotensi. */
    public function importEvents(string $providerCode, array $rows, User $actor, ?string $sourceReference = null): HrWorkforceImport
    {
        $idempotencyKey = 'attendance-import:'.($sourceReference ?? md5(json_encode($rows)));

        $existing = HrWorkforceImport::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null && $existing->status === 'COMPLETED') {
            return $existing;
        }

        $import = $existing ?? HrWorkforceImport::query()->create([
            'code' => 'IMP-'.now()->format('Ymd-His'),
            'provider' => $providerCode,
            'kind' => 'ATTENDANCE_IMPORT',
            'source_reference' => $sourceReference,
            'idempotency_key' => $idempotencyKey,
            'total_rows' => count($rows),
            'status' => 'RUNNING',
            'started_at' => now(),
            'created_by' => $actor->getKey(),
        ]);

        $processed = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            try {
                $this->ingestFromDevice($providerCode, $row, $actor);
                $processed++;
            } catch (\Throwable $exception) {
                $failed++;
                $errors[] = ['row' => $index, 'error' => $exception->getMessage()];
            }
        }

        $import->forceFill([
            'total_rows' => count($rows),
            'processed_rows' => $processed,
            'failed_rows' => $failed,
            'errors' => $errors === [] ? null : $errors,
            'status' => $failed === 0 ? 'COMPLETED' : ($processed > 0 ? 'COMPLETED' : 'FAILED'),
            'finished_at' => now(),
        ])->save();

        return $import->refresh();
    }

    // ------------------------------------------------------------------ perhitungan harian

    public function dailyRecord(int $employeeId, string $date, ?User $actor = null): HrAttendanceRecord
    {
        $record = HrAttendanceRecord::query()->firstOrNew(['employee_id' => $employeeId, 'date' => $date]);

        if (! $record->exists) {
            $context = $this->contextFor($employeeId, $date);

            $record->fill([
                'schedule_id' => $context['schedule_id'],
                'shift_id' => $context['shift_id'],
                'status' => $context['is_holiday'] ? 'HOLIDAY' : ($context['on_leave'] ? 'LEAVE' : ($context['on_trip'] ? 'BUSINESS_TRIP' : 'ABSENT')),
                'is_holiday' => $context['is_holiday'],
                'created_by' => $actor?->getKey(),
            ])->save();
        }

        return $record;
    }

    public function recalculate(HrAttendanceRecord $record): HrAttendanceRecord
    {
        $context = $this->contextFor((int) $record->employee_id, (string) $record->date);
        $expectedStart = $context['start'];
        $expectedEnd = $context['end'];
        $grace = (int) config('hrd_phase2.attendance.late_grace_minutes', 10);
        $earlyGrace = (int) config('hrd_phase2.attendance.early_leave_grace_minutes', 5);

        $clockIn = $record->clock_in !== null ? Carbon::parse($record->clock_in) : null;
        $clockOut = $record->clock_out !== null ? Carbon::parse($record->clock_out) : null;
        $date = Carbon::parse((string) $record->date);

        $lateMinutes = 0;
        $earlyMinutes = 0;
        $workedMinutes = 0;
        $overtimeMinutes = 0;

        if ($clockIn !== null && $expectedStart !== null) {
            $threshold = $expectedStart->copy()->addMinutes($grace);
            $lateMinutes = $clockIn->greaterThan($threshold) ? (int) $threshold->diffInMinutes($clockIn) : 0;
        }

        if ($clockIn !== null && $clockOut !== null) {
            $end = $clockOut->lessThanOrEqualTo($clockIn) ? $clockOut->copy()->addDay() : $clockOut->copy();
            $workedMinutes = max(0, (int) $clockIn->diffInMinutes($end));

            if ($expectedEnd !== null) {
                $earlyThreshold = $expectedEnd->copy()->subMinutes($earlyGrace);
                $earlyMinutes = $clockOut->lessThan($earlyThreshold) ? (int) $clockOut->diffInMinutes($earlyThreshold) : 0;
            }

            if ($expectedStart !== null && $expectedEnd !== null) {
                $expectedMinutes = (int) $expectedStart->diffInMinutes($expectedEnd);
                $overtimeMinutes = max(0, $workedMinutes - $expectedMinutes);
            }
        }

        $maxMinutes = (int) config('hrd_phase2.attendance.max_shift_hours', 16) * 60;
        $overtimeMinutes = min($overtimeMinutes, max(0, $maxMinutes - max(0, $workedMinutes - $overtimeMinutes)));

        $status = $this->deriveStatus($record, $context, $clockIn, $clockOut, $lateMinutes, $earlyMinutes, $date);

        $record->forceFill([
            'schedule_id' => $context['schedule_id'] ?? $record->schedule_id,
            'shift_id' => $context['shift_id'] ?? $record->shift_id,
            'worked_minutes' => $workedMinutes,
            'late_minutes' => $lateMinutes,
            'early_leave_minutes' => $earlyMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'status' => $status,
            'is_holiday' => $context['is_holiday'],
        ])->save();

        return $record->refresh();
    }

    /** @return array<string, mixed> */
    public function contextFor(int $employeeId, string $date): array
    {
        $resolved = $this->schedules->resolveFor($employeeId, $date);
        $day = Carbon::parse($date);

        $onLeave = HrLeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'APPROVED')
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->exists();

        $onTrip = HrBusinessTrip::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['APPROVED', 'ONGOING'])
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->exists();

        return [
            'schedule_id' => $resolved['schedule']?->getKey(),
            'shift_id' => $resolved['shift']?->getKey(),
            'is_working' => $resolved['is_working'],
            'start' => $resolved['start'],
            'end' => $resolved['end'],
            'is_holiday' => $this->isHoliday($date, $employeeId),
            'on_leave' => $onLeave,
            'on_trip' => $onTrip,
            'is_weekend' => $day->isWeekend(),
        ];
    }

    // ------------------------------------------------------------------ koreksi

    /** @param array<string, mixed> $attributes */
    public function requestCorrection(HrEmployee $employee, array $attributes, User $actor): HrAttendanceCorrection
    {
        $correction = HrAttendanceCorrection::query()->create([
            'code' => 'COR-'.now()->format('Ymd-His'),
            'employee_id' => $employee->getKey(),
            'record_id' => $attributes['record_id'] ?? null,
            'date' => $attributes['date'] ?? now()->toDateString(),
            'correction_type' => strtoupper((string) ($attributes['correction_type'] ?? 'OTHER')),
            'requested_clock_in' => $attributes['requested_clock_in'] ?? null,
            'requested_clock_out' => $attributes['requested_clock_out'] ?? null,
            'reason' => $attributes['reason'] ?? 'Tanpa keterangan',
            'attachment_path' => $attributes['attachment_path'] ?? null,
            'status' => 'SUBMITTED',
            'workflow_instance_id' => $attributes['workflow_instance_id'] ?? null,
            'requested_by' => $actor->getKey(),
        ]);

        $this->events->emit('WORKFORCE', 'attendance.correction_requested', [
            'actor' => $actor,
            'employee_id' => $employee->getKey(),
            'subject_type' => HrAttendanceCorrection::class,
            'subject_id' => $correction->getKey(),
            'title' => 'Koreksi presensi '.$correction->date,
            'reason' => $correction->reason,
        ]);

        return $correction;
    }

    public function decideCorrection(HrAttendanceCorrection $correction, User $actor, bool $approved, ?string $notes = null): HrAttendanceCorrection
    {
        if ($correction->status !== 'SUBMITTED') {
            throw HrdValidationException::forField('status', 'Koreksi ini sudah diputuskan.', 'attendance.correction_decided');
        }

        return DB::transaction(function () use ($correction, $actor, $approved, $notes) {
            $correction->forceFill([
                'status' => $approved ? 'APPROVED' : 'REJECTED',
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'decision_notes' => $notes,
            ])->save();

            if ($approved) {
                $this->applyCorrection($correction->refresh(), $actor);
            }

            $this->events->emit('WORKFORCE', $approved ? 'attendance.correction_approved' : 'attendance.correction_rejected', [
                'actor' => $actor,
                'employee_id' => $correction->employee_id,
                'subject_type' => HrAttendanceCorrection::class,
                'subject_id' => $correction->getKey(),
                'title' => 'Koreksi presensi '.($approved ? 'disetujui' : 'ditolak'),
                'reason' => $notes,
            ]);

            return $correction->refresh();
        });
    }

    public function applyCorrection(HrAttendanceCorrection $correction, User $actor): HrAttendanceRecord
    {
        $record = $correction->record_id !== null
            ? HrAttendanceRecord::query()->find($correction->record_id)
            : null;

        $record = $record ?? $this->dailyRecord((int) $correction->employee_id, (string) $correction->date, $actor);

        if ($correction->requested_clock_in !== null) {
            $record->forceFill(['clock_in' => $correction->requested_clock_in, 'clock_in_source' => 'correction']);
        }

        if ($correction->requested_clock_out !== null) {
            $record->forceFill(['clock_out' => $correction->requested_clock_out, 'clock_out_source' => 'correction']);
        }

        $record->forceFill(['correction_id' => $correction->getKey()])->save();

        $record = $this->recalculate($record->refresh());

        $correction->forceFill(['applied_at' => now()])->save();

        return $record;
    }

    // ------------------------------------------------------------------ rekonsiliasi

    /**
     * Deteksi anomali presensi (§82) — dijalankan manual maupun oleh penjadwal.
     *
     * @return array<string, mixed>
     */
    public function reconcile(string $date): array
    {
        $records = HrAttendanceRecord::query()->where('date', $date)->get();
        $findings = [
            'missing_clock' => [],
            'duplicate_clock' => [],
            'impossible_duration' => [],
            'overnight_issue' => [],
            'invalid_location' => [],
            'unexpected_overtime' => [],
            'without_schedule' => [],
            'leave_but_present' => [],
            'absent_with_approved_leave' => [],
        ];

        $maxMinutes = (int) config('hrd_phase2.attendance.max_shift_hours', 16) * 60;

        foreach ($records as $record) {
            if (in_array($record->status, ['MISSING_CLOCK_IN', 'MISSING_CLOCK_OUT'], true)) {
                $findings['missing_clock'][] = $record->employee_id;
            }

            $eventCounts = HrAttendanceEvent::query()
                ->where('attendance_record_id', $record->getKey())
                ->selectRaw('event_type, count(*) as total')
                ->groupBy('event_type')
                ->pluck('total', 'event_type');

            foreach (['CLOCK_IN', 'CLOCK_OUT'] as $type) {
                if ((int) ($eventCounts[$type] ?? 0) > 1) {
                    $findings['duplicate_clock'][] = ['employee_id' => $record->employee_id, 'type' => $type];
                }
            }

            if ((int) $record->worked_minutes > $maxMinutes) {
                $findings['impossible_duration'][] = ['employee_id' => $record->employee_id, 'minutes' => $record->worked_minutes];
            }

            if ($record->clock_in !== null && $record->clock_out !== null && Carbon::parse($record->clock_out)->lessThan(Carbon::parse($record->clock_in))) {
                $shift = $record->shift_id !== null ? \App\Modules\Hrd\Workforce\Models\HrShift::query()->find($record->shift_id) : null;

                if ($shift === null || ! (bool) $shift->cross_midnight) {
                    $findings['overnight_issue'][] = $record->employee_id;
                }
            }

            $outside = HrAttendanceEvent::query()
                ->where('attendance_record_id', $record->getKey())
                ->whereNotNull('latitude')
                ->get()
                ->filter(fn (HrAttendanceEvent $event) => $this->outsideGeofence($event))
                ->count();

            if ($outside > 0) {
                $findings['invalid_location'][] = ['employee_id' => $record->employee_id, 'events' => $outside];
            }

            if ((int) $record->overtime_minutes > 0) {
                $approved = HrOvertimeRecord::query()
                    ->where('employee_id', $record->employee_id)
                    ->where('date', $record->date)
                    ->whereIn('status', ['PENDING', 'VERIFIED'])
                    ->exists();

                if (! $approved) {
                    $findings['unexpected_overtime'][] = ['employee_id' => $record->employee_id, 'minutes' => $record->overtime_minutes];
                }
            }

            if ($record->schedule_id === null && (int) $record->worked_minutes > 0) {
                $findings['without_schedule'][] = $record->employee_id;
            }

            $onLeave = HrLeaveRequest::query()
                ->where('employee_id', $record->employee_id)
                ->where('status', 'APPROVED')
                ->where('start_date', '<=', $record->date)
                ->where('end_date', '>=', $record->date)
                ->exists();

            if ($onLeave && ($record->clock_in !== null || $record->clock_out !== null)) {
                $findings['leave_but_present'][] = $record->employee_id;
            }

            if ($record->status === 'ABSENT' && $onLeave) {
                $findings['absent_with_approved_leave'][] = $record->employee_id;
            }
        }

        return [
            'date' => $date,
            'records' => $records->count(),
            'findings' => $findings,
            'totals' => array_map('count', $findings),
        ];
    }

    // ------------------------------------------------------------------ internal

    /** @param array<string, mixed> $context */
    private function deriveStatus(HrAttendanceRecord $record, array $context, ?Carbon $clockIn, ?Carbon $clockOut, int $lateMinutes, int $earlyMinutes, Carbon $date): string
    {
        if ($clockIn === null && $clockOut === null) {
            if ($context['is_holiday'] || ! $context['is_working']) {
                return 'HOLIDAY';
            }

            if ($context['on_leave']) {
                return 'LEAVE';
            }

            if ($context['on_trip']) {
                return 'BUSINESS_TRIP';
            }

            $grace = (int) config('hrd_phase2.attendance.late_grace_minutes', 10);

            if ($context['start'] !== null && now()->greaterThan($context['start']->copy()->addMinutes($grace))) {
                return 'MISSING_CLOCK_IN';
            }

            return 'ABSENT';
        }

        if ($clockIn !== null && $clockOut === null) {
            if ($context['end'] !== null && now()->greaterThan($context['end'])) {
                return 'MISSING_CLOCK_OUT';
            }

            if ((bool) $record->is_remote) {
                return 'REMOTE';
            }

            return $lateMinutes > 0 ? 'LATE' : 'PRESENT';
        }

        if ($lateMinutes > 0) {
            return 'LATE';
        }

        if ($earlyMinutes > 0) {
            return 'EARLY_LEAVE';
        }

        return (bool) $record->is_remote ? 'REMOTE' : 'PRESENT';
    }

    /** @param array<string, mixed> $payload */
    private function assertGeofence(array $payload, HrAttendanceRecord $record): void
    {
        $latitude = $payload['latitude'] ?? null;
        $longitude = $payload['longitude'] ?? null;

        if ($latitude === null || $longitude === null) {
            return;
        }

        $locationId = $payload['location_id'] ?? $record->location_id;

        if ($locationId === null || ! (bool) config('hrd_phase2.attendance.require_geofence', false)) {
            return;
        }

        $location = HrAttendanceLocation::query()->find($locationId);

        if ($location === null || ! (bool) $location->geofence_enabled || $location->latitude === null || $location->longitude === null) {
            return;
        }

        $distance = $this->distanceMeters((float) $location->latitude, (float) $location->longitude, (float) $latitude, (float) $longitude);

        if ($distance > (int) $location->radius_meters) {
            throw HrdValidationException::forField(
                'location',
                sprintf('Anda berada %.0f m dari %s (batas %d m).', $distance, $location->name, (int) $location->radius_meters),
                'attendance.outside_geofence',
            );
        }
    }

    private function outsideGeofence(HrAttendanceEvent $event): bool
    {
        if ($event->latitude === null || $event->longitude === null) {
            return false;
        }

        $record = HrAttendanceRecord::query()->find($event->attendance_record_id);
        $location = $record?->location_id !== null ? HrAttendanceLocation::query()->find($record->location_id) : null;

        if ($location === null || $location->latitude === null || $location->longitude === null) {
            return false;
        }

        return $this->distanceMeters((float) $location->latitude, (float) $location->longitude, (float) $event->latitude, (float) $event->longitude) > (int) $location->radius_meters;
    }

    private function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function isHoliday(string $date, int $employeeId): bool
    {
        $employee = HrEmployee::query()->find($employeeId);

        $calendarIds = HrHolidayCalendar::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('organization_id')->orWhere('organization_id', $employee?->organization_id))
            ->pluck('id');

        return HrHoliday::query()->whereIn('calendar_id', $calendarIds)->where('date', $date)->exists();
    }
}
