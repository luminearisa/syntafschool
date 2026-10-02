<?php

namespace App\Modules\Hrd\Leave\Services;

use App\Models\User;
use App\Modules\Hrd\Core\Domain\Contracts\LeaveProviderInterface;
use App\Modules\Hrd\Core\Support\HrdPhase2Events;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Leave\Models\HrLeaveApproval;
use App\Modules\Hrd\Leave\Models\HrLeaveBalance;
use App\Modules\Hrd\Leave\Models\HrLeaveRequest;
use App\Modules\Hrd\Leave\Models\HrLeaveRequestDay;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrLeaveType;
use App\Modules\Hrd\Workforce\Models\HrAttendanceRecord;
use App\Modules\Hrd\Workforce\Models\HrBusinessTrip;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Permintaan cuti dari pengajuan sampai pemotongan saldo.
 *
 * Dua hal yang membuat layanan ini berbeda dari formulir cuti biasa:
 * konflik diperiksa **sebelum** persetujuan (bukan setelah pegawai tidak masuk),
 * dan pemotongan saldo terjadi pada saat persetujuan — sehingga angka "sisa
 * cuti" yang dilihat pegawai selalu sama dengan angka yang dipakai payroll.
 */
class LeaveRequestService implements LeaveProviderInterface
{
    public function __construct(
        private readonly LeavePolicyService $policies,
        private readonly HolidayCalendarService $holidays,
        private readonly HrdPhase2Events $events,
    ) {}

    // ------------------------------------------------------------------ kontrak

    public function availableBalance(int $employeeId, string $leaveTypeCode, ?string $asOf = null): float
    {
        $type = HrLeaveType::query()->where('code', strtoupper($leaveTypeCode))->first();

        if ($type === null) {
            return 0.0;
        }

        return (float) $this->policies->balanceFor($employeeId, $type->getKey(), $this->policies->periodCode($asOf ?? now()))->available_days;
    }

    public function requestLeave(int $employeeId, array $payload, ?int $actorUserId = null): array
    {
        $employee = HrEmployee::query()->findOrFail($employeeId);
        $actor = $actorUserId !== null ? User::query()->find($actorUserId) : null;

        if ($actor === null) {
            throw HrdValidationException::forField('actor', 'Pelaku perubahan wajib diketahui.', 'leave.actor_required');
        }

        $request = $this->request($employee, $payload, $actor);

        return ['request_id' => $request->getKey(), 'code' => $request->code, 'status' => $request->status, 'days' => (float) $request->days];
    }

    public function approve(int $requestId, int $actorUserId, ?string $notes = null): array
    {
        $request = HrLeaveRequest::query()->findOrFail($requestId);
        $actor = User::query()->findOrFail($actorUserId);

        $decided = $this->decide($request, $actor, true, $notes);

        return ['request_id' => $decided->getKey(), 'status' => $decided->status, 'days' => (float) $decided->days];
    }

    /** @return array<string, mixed> */
    public function leaveDaysInPeriod(int $employeeId, string $from, string $to): array
    {
        return HrLeaveRequest::query()
            ->with('leaveType')
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['APPROVED', 'TAKEN'])
            ->where(fn ($query) => $query->whereBetween('start_date', [$from, $to])->orWhereBetween('end_date', [$from, $to]))
            ->get()
            ->map(fn (HrLeaveRequest $request) => [
                'code' => $request->code,
                'leave_type' => $request->leaveType?->code,
                'start' => $request->start_date?->toDateString(),
                'end' => $request->end_date?->toDateString(),
                'days' => (float) $request->days,
                'paid' => (bool) ($request->balance_breakdown['paid'] ?? true),
            ])
            ->all();
    }

    public function unpaidLeaveDays(int $employeeId, string $from, string $to): float
    {
        return (float) HrLeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['APPROVED', 'TAKEN'])
            ->where(fn ($query) => $query->whereBetween('start_date', [$from, $to])->orWhereBetween('end_date', [$from, $to]))
            ->get()
            ->filter(fn (HrLeaveRequest $request) => (bool) ($request->balance_breakdown['paid'] ?? true) === false)
            ->sum('days');
    }

    // ------------------------------------------------------------------ pengajuan

    /**
     * @param array<string, mixed> $attributes
     */
    public function request(HrEmployee $employee, array $attributes, User $actor): HrLeaveRequest
    {
        $type = $this->resolveType($attributes);

        $start = Carbon::parse($attributes['start_date'] ?? now()->toDateString());
        $end = Carbon::parse($attributes['end_date'] ?? $start->toDateString());

        if ($end->lessThan($start)) {
            throw HrdValidationException::forField('end_date', 'Tanggal selesai tidak boleh sebelum tanggal mulai.', 'leave.date_invalid');
        }

        $resolved = $this->policies->resolvePolicy($employee, $type, $start->toDateString());

        if ($resolved['policy'] === null) {
            throw HrdValidationException::forField('leave_type', 'Belum ada kebijakan cuti yang berlaku untuk pegawai ini.', 'leave.policy_missing');
        }

        if (! $resolved['eligible']) {
            throw HrdValidationException::forField('leave_type', 'Pegawai belum memenuhi syarat jenis cuti ini: '.implode('; ', $resolved['reasons']), 'leave.not_eligible');
        }

        $policy = $resolved['policy'];
        $conflicts = $this->detectConflicts($employee, $start, $end, $type);
        $blocking = array_filter($conflicts, fn (array $conflict) => ($conflict['blocking'] ?? false) === true);

        if ($blocking !== []) {
            throw HrdValidationException::forField(
                'start_date',
                'Pengajuan bertentangan dengan: '.implode(', ', array_map(fn (array $c) => $c['message'], $blocking)),
                'leave.conflict',
            );
        }

        $effective = $this->holidays->effectiveDays($start->toDateString(), $end->toDateString(), $employee->organization_id);
        $days = (float) ($attributes['days'] ?? $effective['days']);

        if ($days <= 0) {
            throw HrdValidationException::forField('start_date', 'Rentang yang dipilih tidak memuat hari kerja.', 'leave.no_working_day');
        }

        $balance = $this->policies->balanceFor((int) $employee->getKey(), (int) $type->getKey(), $this->policies->periodCode($start));

        if ((bool) $policy->paid && (float) $balance->available_days < $days) {
            throw HrdValidationException::forField(
                'days',
                sprintf('Saldo cuti tidak cukup: tersedia %.2f hari, diminta %.2f hari.', (float) $balance->available_days, $days),
                'leave.balance_insufficient',
            );
        }

        if ((int) $policy->min_notice_days > 0 && now()->startOfDay()->diffInDays($start, false) < (int) $policy->min_notice_days && $start->isFuture()) {
            throw HrdValidationException::forField('start_date', "Pengajuan minimal {$policy->min_notice_days} hari sebelumnya.", 'leave.notice_too_short');
        }

        return DB::transaction(function () use ($employee, $type, $policy, $start, $end, $days, $attributes, $actor, $conflicts, $effective) {
            $request = HrLeaveRequest::query()->create([
                'code' => sprintf('LV-%s-%04d', now()->format('Y'), HrLeaveRequest::query()->whereYear('created_at', now()->year)->count() + 1),
                'employee_id' => $employee->getKey(),
                'leave_type_id' => $type->getKey(),
                'policy_id' => $policy->getKey(),
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'days' => $days,
                'half_day' => (bool) ($attributes['half_day'] ?? false),
                'reason' => $attributes['reason'] ?? null,
                'attachment_path' => $attributes['attachment_path'] ?? null,
                'status' => 'SUBMITTED',
                'submitted_at' => now(),
                'conflict_flags' => $conflicts === [] ? null : $conflicts,
                'balance_breakdown' => [
                    'available_before' => (float) $this->policies->balanceFor((int) $employee->getKey(), (int) $type->getKey(), $this->policies->periodCode($start))->available_days,
                    'requested' => $days,
                    'paid' => (bool) $policy->paid,
                ],
                'payroll_impact' => [
                    'paid_days' => (bool) $policy->paid ? $days : 0.0,
                    'unpaid_days' => (bool) $policy->paid ? 0.0 : $days,
                ],
                'requested_by' => $actor->getKey(),
                'delegate_employee_id' => $attributes['delegate_employee_id'] ?? null,
            ]);

            foreach ($effective['detail'] as $day) {
                HrLeaveRequestDay::query()->create([
                    'request_id' => $request->getKey(),
                    'date' => $day['date'],
                    'day_type' => $day['holiday'] ? 'HOLIDAY' : ($day['weekend'] ? 'WEEKEND' : 'FULL'),
                    'is_paid' => (bool) $policy->paid,
                    'counted' => (bool) $day['counted'],
                ]);
            }

            HrLeaveApproval::query()->create([
                'request_id' => $request->getKey(),
                'level' => 1,
                'decision' => 'PENDING',
            ]);

            $this->policies->applyPending((int) $employee->getKey(), (int) $type->getKey(), $days, $this->policies->periodCode($start));

            $this->events->emit('LEAVE', 'leave.requested', [
                'actor' => $actor,
                'employee_id' => $employee->getKey(),
                'subject_type' => HrLeaveRequest::class,
                'subject_id' => $request->getKey(),
                'title' => 'Pengajuan cuti '.$type->name.' '.round($days, 2).' hari',
                'payload' => ['start' => $request->start_date?->toDateString(), 'end' => $request->end_date?->toDateString(), 'days' => $days],
            ]);

            return $request->refresh();
        });
    }

    /**
     * Deteksi konflik (§32): cuti lain, presensi, hari libur, dinas, dan
     * beban tim.
     *
     * @return list<array<string, mixed>>
     */
    public function detectConflicts(HrEmployee $employee, Carbon $start, Carbon $end, ?HrLeaveType $type = null): array
    {
        $conflicts = [];

        $overlapping = HrLeaveRequest::query()
            ->where('employee_id', $employee->getKey())
            ->whereIn('status', ['SUBMITTED', 'APPROVED', 'TAKEN'])
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->get();

        foreach ($overlapping as $existing) {
            $conflicts[] = [
                'type' => 'EXISTING_LEAVE',
                'blocking' => true,
                'message' => 'sudah ada pengajuan cuti '.$existing->code.' pada rentang tersebut',
                'reference' => $existing->code,
            ];
        }

        $present = HrAttendanceRecord::query()
            ->where('employee_id', $employee->getKey())
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('clock_in')
            ->count();

        if ($present > 0) {
            $conflicts[] = [
                'type' => 'ATTENDANCE_PRESENT',
                'blocking' => true,
                'message' => "sudah ada presensi pada {$present} hari dalam rentang ini",
                'reference' => $present,
            ];
        }

        $trips = HrBusinessTrip::query()
            ->where('employee_id', $employee->getKey())
            ->whereIn('status', ['APPROVED', 'ONGOING'])
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->count();

        if ($trips > 0) {
            $conflicts[] = [
                'type' => 'BUSINESS_TRIP',
                'blocking' => true,
                'message' => 'ada penugasan perjalanan dinas pada rentang tersebut',
                'reference' => $trips,
            ];
        }

        $holidays = $this->holidays->inRange($start->toDateString(), $end->toDateString(), $employee->organization_id);

        if ($holidays !== []) {
            $conflicts[] = [
                'type' => 'HOLIDAY',
                'blocking' => false,
                'message' => count($holidays).' hari libur dalam rentang ini tidak dipotong saldo',
                'reference' => array_column($holidays, 'date'),
            ];
        }

        $coverage = $this->teamCoverage($employee, $start, $end);

        if ($coverage['exceeds_threshold']) {
            $conflicts[] = [
                'type' => 'TEAM_COVERAGE',
                'blocking' => false,
                'message' => sprintf('%.0f%% tim sudah cuti pada rentang ini', $coverage['percent']),
                'reference' => $coverage['percent'],
            ];
        }

        return $conflicts;
    }

    /**
     * Berapa persen tim yang sudah cuti pada rentang yang sama — peringatan
     * untuk peran kritis, bukan larangan otomatis.
     *
     * @return array<string, mixed>
     */
    public function teamCoverage(HrEmployee $employee, Carbon $start, Carbon $end): array
    {
        $teamSize = HrEmployee::query()
            ->where('lifecycle_state', 'active')
            ->where('department_id', $employee->department_id)
            ->count();

        if ($teamSize === 0) {
            return ['team_size' => 0, 'on_leave' => 0, 'percent' => 0.0, 'exceeds_threshold' => false];
        }

        $onLeave = HrLeaveRequest::query()
            ->where('employee_id', '!=', $employee->getKey())
            ->whereIn('status', ['SUBMITTED', 'APPROVED', 'TAKEN'])
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->whereHas('employee', fn ($query) => $query->where('department_id', $employee->department_id))
            ->distinct('employee_id')
            ->count('employee_id');

        $percent = round($onLeave / $teamSize * 100, 2);
        $threshold = (float) config('hrd_phase2.leave.max_concurrent_team_absence_percent', 40);

        return [
            'team_size' => $teamSize,
            'on_leave' => $onLeave,
            'percent' => $percent,
            'threshold' => $threshold,
            'exceeds_threshold' => $percent >= $threshold,
        ];
    }

    public function decide(HrLeaveRequest $request, User $actor, bool $approved, ?string $notes = null): HrLeaveRequest
    {
        if (! in_array($request->status, ['SUBMITTED', 'DRAFT'], true)) {
            throw HrdValidationException::forField('status', 'Pengajuan cuti ini sudah diputuskan.', 'leave.decided');
        }

        return DB::transaction(function () use ($request, $actor, $approved, $notes) {
            $request->forceFill([
                'status' => $approved ? 'APPROVED' : 'REJECTED',
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'decision_notes' => $notes,
            ])->save();

            HrLeaveApproval::query()
                ->where('request_id', $request->getKey())
                ->where('decision', 'PENDING')
                ->orderBy('level')
                ->first()
                ?->forceFill([
                    'approver_user_id' => $actor->getKey(),
                    'decision' => $approved ? 'APPROVED' : 'REJECTED',
                    'decided_at' => now(),
                    'notes' => $notes,
                ])->save();

            $employeeId = (int) $request->employee_id;
            $leaveTypeId = (int) $request->leave_type_id;
            $days = (float) $request->days;

            $periodCode = $this->policies->periodCode($request->start_date);

            if ($approved) {
                $this->policies->consume($employeeId, $leaveTypeId, $days, $periodCode);
                $this->syncAttendance($request->refresh(), $actor);
            } else {
                $this->policies->release($employeeId, $leaveTypeId, $days, $periodCode);
            }

            $this->events->emit('LEAVE', $approved ? 'leave.approved' : 'leave.rejected', [
                'actor' => $actor,
                'employee_id' => $employeeId,
                'subject_type' => HrLeaveRequest::class,
                'subject_id' => $request->getKey(),
                'title' => 'Cuti '.($approved ? 'disetujui' : 'ditolak').' '.round($days, 2).' hari',
                'reason' => $notes,
                'payload' => ['start' => $request->start_date?->toDateString(), 'end' => $request->end_date?->toDateString()],
            ]);

            return $request->refresh();
        });
    }

    public function cancel(HrLeaveRequest $request, User $actor, ?string $reason = null): HrLeaveRequest
    {
        if (in_array($request->status, ['CANCELLED', 'REJECTED'], true)) {
            return $request;
        }

        return DB::transaction(function () use ($request, $actor, $reason) {
            $wasApproved = $request->status === 'APPROVED';

            $periodCode = $this->policies->periodCode($request->start_date);

            if ($wasApproved) {
                $this->policies->release((int) $request->employee_id, (int) $request->leave_type_id, (float) $request->days, $periodCode);
                $this->revertAttendance($request->refresh());
            } else {
                $this->policies->release((int) $request->employee_id, (int) $request->leave_type_id, (float) $request->days, $periodCode);
            }

            $request->forceFill([
                'status' => 'CANCELLED',
                'decision_notes' => $reason ?? $request->decision_notes,
            ])->save();

            $this->events->emit('LEAVE', 'leave.cancelled', [
                'actor' => $actor,
                'employee_id' => $request->employee_id,
                'subject_type' => HrLeaveRequest::class,
                'subject_id' => $request->getKey(),
                'title' => 'Cuti dibatalkan',
                'reason' => $reason,
            ]);

            return $request->refresh();
        });
    }

    /** Cuti disetujui → kalender presensi (Attendance Integration §101). */
    public function syncAttendance(HrLeaveRequest $request, ?User $actor = null): int
    {
        $updated = 0;

        for ($date = Carbon::parse($request->start_date); $date->lessThanOrEqualTo(Carbon::parse($request->end_date)); $date->addDay()) {
            $record = HrAttendanceRecord::query()->firstOrNew([
                'employee_id' => $request->employee_id,
                'date' => $date->toDateString(),
            ]);

            if ($record->exists && $record->clock_in !== null) {
                continue;
            }

            $record->fill([
                'status' => 'LEAVE',
                'note' => 'Cuti '.$request->code,
            ])->save();

            $updated++;
        }

        $request->forceFill(['attendance_applied' => true])->save();

        return $updated;
    }

    private function revertAttendance(HrLeaveRequest $request): int
    {
        $reverted = HrAttendanceRecord::query()
            ->where('employee_id', $request->employee_id)
            ->whereBetween('date', [$request->start_date, $request->end_date])
            ->where('status', 'LEAVE')
            ->update(['status' => 'ABSENT', 'note' => 'Cuti dibatalkan '.$request->code]);

        $request->forceFill(['attendance_applied' => false])->save();

        return $reverted;
    }

    private function resolveType(array $attributes): HrLeaveType
    {
        $type = isset($attributes['leave_type_id'])
            ? HrLeaveType::query()->find($attributes['leave_type_id'])
            : HrLeaveType::query()->where('code', strtoupper((string) ($attributes['leave_type_code'] ?? '')))->first();

        if ($type === null) {
            throw HrdValidationException::forField('leave_type', 'Jenis cuti tidak ditemukan.', 'leave.type_missing');
        }

        return $type;
    }
}
