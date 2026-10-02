<?php

namespace App\Modules\Hrd\Workforce\Services;

use App\Models\User;
use App\Modules\Hrd\Core\Support\HrdPhase2Events;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Workforce\Models\HrAttendanceRecord;
use App\Modules\Hrd\Workforce\Models\HrBusinessTrip;
use App\Modules\Hrd\Workforce\Models\HrBusinessTripParticipant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Perjalanan dinas dan penugasan resmi.
 *
 * Disetujui berarti presensi pada rentang tanggal tersebut berstatus dinas —
 * pegawai yang sedang bertugas tidak dihitung tidak hadir, dan payroll tidak
 * memotong hari itu.
 */
class BusinessTripService
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly HrdPhase2Events $events,
    ) {}

    /** @param array<string, mixed> $attributes @param list<int> $participantIds */
    public function create(HrEmployee|int $employee, array $attributes, User $actor, array $participantIds = []): HrBusinessTrip
    {
        $employeeId = $employee instanceof HrEmployee ? (int) $employee->getKey() : $employee;

        $start = Carbon::parse($attributes['start_date'] ?? now()->toDateString());
        $end = Carbon::parse($attributes['end_date'] ?? $start->toDateString());

        if ($end->lessThan($start)) {
            throw HrdValidationException::forField('end_date', 'Tanggal selesai tidak boleh sebelum tanggal mulai.', 'trip.date_invalid');
        }

        return DB::transaction(function () use ($employeeId, $attributes, $actor, $start, $end, $participantIds) {
            $trip = HrBusinessTrip::query()->create([
                'code' => sprintf('TRIP-%s-%04d', now()->format('Y'), HrBusinessTrip::query()->whereYear('created_at', now()->year)->count() + 1),
                'employee_id' => $employeeId,
                'trip_type' => strtoupper((string) ($attributes['trip_type'] ?? 'BUSINESS_TRIP')),
                'title' => $attributes['title'] ?? 'Perjalanan dinas',
                'destination' => $attributes['destination'] ?? null,
                'purpose' => $attributes['purpose'] ?? null,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'days' => (int) $start->diffInDays($end) + 1,
                'status' => 'DRAFT',
                'requested_by' => $actor->getKey(),
                'cost_estimate' => $attributes['cost_estimate'] ?? null,
                'fund_source' => $attributes['fund_source'] ?? null,
                'notes' => $attributes['notes'] ?? null,
            ]);

            foreach (array_unique(array_merge([$employeeId], $participantIds)) as $participantId) {
                HrBusinessTripParticipant::query()->create([
                    'trip_id' => $trip->getKey(),
                    'employee_id' => $participantId,
                    'role' => $participantId === $employeeId ? 'LEAD' : 'MEMBER',
                ]);
            }

            return $trip;
        });
    }

    public function submit(HrBusinessTrip $trip, User $actor): HrBusinessTrip
    {
        if ($trip->status !== 'DRAFT') {
            throw HrdValidationException::forField('status', 'Hanya draf perjalanan dinas yang dapat diajukan.', 'trip.status_invalid');
        }

        $trip->forceFill(['status' => 'SUBMITTED'])->save();

        $this->events->emit('WORKFORCE', 'trip.submitted', [
            'actor' => $actor,
            'employee_id' => $trip->employee_id,
            'subject_type' => HrBusinessTrip::class,
            'subject_id' => $trip->getKey(),
            'title' => 'Perjalanan dinas diajukan: '.$trip->title,
        ]);

        return $trip->refresh();
    }

    public function decide(HrBusinessTrip $trip, User $actor, bool $approved, ?string $notes = null): HrBusinessTrip
    {
        if ($trip->status !== 'SUBMITTED') {
            throw HrdValidationException::forField('status', 'Perjalanan dinas ini sudah diputuskan.', 'trip.decided');
        }

        return DB::transaction(function () use ($trip, $actor, $approved, $notes) {
            $trip->forceFill([
                'status' => $approved ? 'APPROVED' : 'CANCELLED',
                'approved_by' => $approved ? $actor->getKey() : null,
                'approved_at' => $approved ? now() : null,
                'notes' => $notes ?? $trip->notes,
            ])->save();

            if ($approved) {
                $this->syncAttendance($trip->refresh(), $actor);
            }

            $this->events->emit('WORKFORCE', $approved ? 'trip.approved' : 'trip.cancelled', [
                'actor' => $actor,
                'employee_id' => $trip->employee_id,
                'subject_type' => HrBusinessTrip::class,
                'subject_id' => $trip->getKey(),
                'title' => 'Perjalanan dinas '.($approved ? 'disetujui' : 'dibatalkan'),
                'reason' => $notes,
            ]);

            return $trip->refresh();
        });
    }

    public function complete(HrBusinessTrip $trip, User $actor, ?float $actualCost = null): HrBusinessTrip
    {
        $trip->forceFill([
            'status' => 'COMPLETED',
            'cost_actual' => $actualCost ?? $trip->cost_actual,
        ])->save();

        $this->events->emit('WORKFORCE', 'trip.completed', [
            'actor' => $actor,
            'employee_id' => $trip->employee_id,
            'subject_type' => HrBusinessTrip::class,
            'subject_id' => $trip->getKey(),
            'title' => 'Perjalanan dinas selesai: '.$trip->title,
            'payload' => ['cost_actual' => $actualCost],
        ]);

        return $trip->refresh();
    }

    /**
     * Menyelaraskan presensi dengan perjalanan dinas yang disetujui: hari dinas
     * menjadi BUSINESS_TRIP, kecuali hari itu sudah ada presensi nyata.
     */
    public function syncAttendance(HrBusinessTrip $trip, ?User $actor = null): int
    {
        $participants = HrBusinessTripParticipant::query()->where('trip_id', $trip->getKey())->pluck('employee_id');
        $updated = 0;

        foreach ($participants as $employeeId) {
            for ($date = Carbon::parse($trip->start_date); $date->lessThanOrEqualTo(Carbon::parse($trip->end_date)); $date->addDay()) {
                $record = HrAttendanceRecord::query()->firstOrNew([
                    'employee_id' => $employeeId,
                    'date' => $date->toDateString(),
                ]);

                if ($record->exists && $record->clock_in !== null) {
                    continue;
                }

                $record->fill([
                    'status' => 'BUSINESS_TRIP',
                    'note' => 'Perjalanan dinas '.$trip->code,
                ])->save();

                $updated++;
            }
        }

        return $updated;
    }
}
