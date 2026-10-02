<?php

namespace App\Modules\Hrd\Workforce\Services;

use App\Models\User;
use App\Modules\Hrd\Core\Support\HrdPhase2Events;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Workforce\Models\HrAttendanceRecord;
use App\Modules\Hrd\Workforce\Models\HrOvertimeRecord;
use App\Modules\Hrd\Workforce\Models\HrOvertimeRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lembur: diminta, disetujui, **diverifikasi dari presensi**, baru dibayar.
 *
 * Aturan yang dijaga di sini adalah aturan yang paling sering dilanggar sistem
 * HR sederhana: lembur tidak pernah dibayar berdasarkan permintaan saja.
 * Pembayaran hanya muncul setelah jam kerja nyata melewati jam terjadwal.
 */
class OvertimeService
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly HrdPhase2Events $events,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function request(HrEmployee|int $employee, array $attributes, User $actor): HrOvertimeRequest
    {
        $employeeId = $employee instanceof HrEmployee ? (int) $employee->getKey() : $employee;

        $plannedStart = isset($attributes['planned_start']) ? Carbon::parse($attributes['planned_start']) : null;
        $plannedEnd = isset($attributes['planned_end']) ? Carbon::parse($attributes['planned_end']) : null;

        if ($plannedStart === null || $plannedEnd === null) {
            throw HrdValidationException::forField('planned_start', 'Jam mulai dan selesai lembur wajib diisi.', 'overtime.window_required');
        }

        if ($plannedEnd->lessThanOrEqualTo($plannedStart)) {
            $plannedEnd->addDay();
        }

        $plannedMinutes = (int) $plannedStart->diffInMinutes($plannedEnd);
        $minimum = (int) config('hrd_phase2.overtime.minimum_minutes', 30);

        if ($plannedMinutes < $minimum) {
            throw HrdValidationException::forField('planned_end', "Lembur minimal {$minimum} menit.", 'overtime.too_short');
        }

        $date = $plannedStart->toDateString();
        $maxHours = (int) config('hrd_phase2.overtime.max_hours_per_month', 60);

        $monthlyMinutes = (int) HrOvertimeRecord::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [Carbon::parse($date)->startOfMonth()->toDateString(), Carbon::parse($date)->endOfMonth()->toDateString()])
            ->whereIn('status', ['PENDING', 'VERIFIED'])
            ->sum('verified_minutes');

        if ($monthlyMinutes + $plannedMinutes > $maxHours * 60) {
            throw HrdValidationException::forField('planned_end', "Batas lembur bulanan {$maxHours} jam terlampaui.", 'overtime.monthly_limit');
        }

        $key = $attributes['idempotency_key'] ?? null;

        if ($key !== null) {
            $existing = HrOvertimeRequest::query()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $overtimeType = strtoupper((string) ($attributes['overtime_type'] ?? $this->typeFor($employeeId, $date)));

        $request = HrOvertimeRequest::query()->create([
            'code' => sprintf('OT-%s-%04d', now()->format('Y'), HrOvertimeRequest::query()->whereYear('created_at', now()->year)->count() + 1),
            'employee_id' => $employeeId,
            'date' => $date,
            'planned_start' => $plannedStart,
            'planned_end' => $plannedEnd,
            'planned_minutes' => $plannedMinutes,
            'overtime_type' => $overtimeType,
            'reason' => $attributes['reason'] ?? 'Pekerjaan tambahan',
            'status' => 'SUBMITTED',
            'requested_by' => $actor->getKey(),
            'evidence_path' => $attributes['evidence_path'] ?? null,
            'idempotency_key' => $key,
        ]);

        $this->events->emit('WORKFORCE', 'overtime.requested', [
            'actor' => $actor,
            'employee_id' => $employeeId,
            'subject_type' => HrOvertimeRequest::class,
            'subject_id' => $request->getKey(),
            'title' => 'Pengajuan lembur '.$request->date?->toDateString(),
            'payload' => ['planned_minutes' => $plannedMinutes, 'type' => $overtimeType],
        ]);

        return $request;
    }

    public function decide(HrOvertimeRequest $request, User $actor, bool $approved, ?string $notes = null): HrOvertimeRequest
    {
        if (! in_array($request->status, ['SUBMITTED', 'DRAFT'], true)) {
            throw HrdValidationException::forField('status', 'Pengajuan lembur ini sudah diputuskan.', 'overtime.decided');
        }

        $request->forceFill([
            'status' => $approved ? 'APPROVED' : 'REJECTED',
            'approved_by' => $actor->getKey(),
            'approved_at' => now(),
            'decision_notes' => $notes,
        ])->save();

        $this->events->emit('WORKFORCE', $approved ? 'overtime.approved' : 'overtime.rejected', [
            'actor' => $actor,
            'employee_id' => $request->employee_id,
            'subject_type' => HrOvertimeRequest::class,
            'subject_id' => $request->getKey(),
            'title' => 'Lembur '.($approved ? 'disetujui' : 'ditolak'),
            'reason' => $notes,
        ]);

        return $request->refresh();
    }

    /**
     * Verifikasi jam nyata dari presensi lalu hitung nilainya.
     *
     * Selisih yang lebih pendek daripada rencana dibayar sesuai kenyataan —
     * bukan sesuai pengajuan.
     *
     * @param array<string, mixed> $attributes
     */
    public function verifyActual(HrOvertimeRequest $request, User $actor, array $attributes = []): HrOvertimeRecord
    {
        if ($request->status !== 'APPROVED') {
            throw HrdValidationException::forField('status', 'Lembur harus disetujui sebelum diverifikasi.', 'overtime.not_approved');
        }

        $date = (string) $request->date?->toDateString();
        $record = HrAttendanceRecord::query()
            ->where('employee_id', $request->employee_id)
            ->where('date', $date)
            ->first();

        if ($record === null && (bool) config('hrd_phase2.overtime.require_actual_verification', true)) {
            throw HrdValidationException::forField('date', 'Presensi pada tanggal lembur belum ada, jam nyata belum dapat diverifikasi.', 'overtime.attendance_missing');
        }

        $attendanceOvertime = (int) ($record?->overtime_minutes ?? 0);
        $actualMinutes = (int) ($attributes['actual_minutes'] ?? min($attendanceOvertime, (int) $request->planned_minutes));

        $rounding = (int) config('hrd_phase2.overtime.rounding_minutes', 15);

        if ($rounding > 1) {
            $actualMinutes = (int) (floor($actualMinutes / $rounding) * $rounding);
        }

        $multiplier = $this->multiplierFor((string) $request->overtime_type);
        $rate = $attributes['rate_amount'] ?? null;

        return DB::transaction(function () use ($request, $actualMinutes, $multiplier, $rate, $record, $actor, $attributes, $date) {
            $overtime = HrOvertimeRecord::query()->updateOrCreate(
                [
                    'employee_id' => $request->employee_id,
                    'date' => $date,
                    'overtime_request_id' => $request->getKey(),
                ],
                [
                    'actual_start' => $attributes['actual_start'] ?? $request->planned_start,
                    'actual_end' => $attributes['actual_end'] ?? $request->planned_end,
                    'actual_minutes' => $actualMinutes,
                    'verified_minutes' => $actualMinutes,
                    'multiplier' => $multiplier,
                    'rate_amount' => $rate,
                    'amount' => $rate !== null ? round($actualMinutes / 60 * (float) $rate * $multiplier, 2) : null,
                    'status' => $actualMinutes > 0 ? 'VERIFIED' : 'REJECTED',
                    'verified_by' => $actor->getKey(),
                    'verified_at' => now(),
                    'attendance_record_id' => $record?->getKey(),
                    'notes' => $attributes['notes'] ?? null,
                ],
            );

            $request->forceFill(['status' => $actualMinutes > 0 ? 'COMPLETED' : 'REJECTED'])->save();

            $this->events->emit('WORKFORCE', 'overtime.verified', [
                'actor' => $actor,
                'employee_id' => $request->employee_id,
                'subject_type' => HrOvertimeRecord::class,
                'subject_id' => $overtime->getKey(),
                'title' => 'Lembur terverifikasi '.round($actualMinutes / 60, 2).' jam',
                'payload' => ['verified_minutes' => $actualMinutes, 'multiplier' => $multiplier],
            ]);

            return $overtime->refresh();
        });
    }

    /**
     * Ringkasan lembur siap dibayar untuk satu periode payroll.
     *
     * @return array<string, mixed>
     */
    public function payableFor(int $employeeId, string $from, string $to): array
    {
        $records = HrOvertimeRecord::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$from, $to])
            ->where('status', 'VERIFIED')
            ->get();

        return [
            'records' => $records->map->only(['date', 'verified_minutes', 'multiplier', 'amount'])->all(),
            'total_minutes' => (int) $records->sum('verified_minutes'),
            'total_hours' => round($records->sum('verified_minutes') / 60, 2),
            'amount' => $records->sum(fn (HrOvertimeRecord $record) => (float) $record->amount),
            'has_unpriced' => $records->contains(fn (HrOvertimeRecord $record) => $record->amount === null),
        ];
    }

    /** @return array<string, float> */
    public function multipliers(): array
    {
        return [
            'WORKDAY' => (float) config('hrd_phase2.overtime.multiplier_workday', 1.5),
            'REST_DAY' => (float) config('hrd_phase2.overtime.multiplier_rest_day', 2.0),
            'HOLIDAY' => (float) config('hrd_phase2.overtime.multiplier_holiday', 2.0),
        ];
    }

    private function multiplierFor(string $overtimeType): float
    {
        return $this->multipliers()[strtoupper($overtimeType)] ?? 1.5;
    }

    private function typeFor(int $employeeId, string $date): string
    {
        $context = $this->attendance->contextFor($employeeId, $date);

        if ($context['is_holiday']) {
            return 'HOLIDAY';
        }

        return $context['is_working'] ? 'WORKDAY' : 'REST_DAY';
    }
}
