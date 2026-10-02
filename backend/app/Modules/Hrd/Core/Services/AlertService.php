<?php

namespace App\Modules\Hrd\Core\Services;

use App\Modules\Hrd\Core\Models\HrAlert;
use App\Modules\Hrd\Learning\Models\HrTrainingAssignment;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Payroll\Models\HrPayrollRun;
use App\Modules\Hrd\Performance\Models\HrPerformanceCycleParticipant;
use App\Modules\Hrd\Performance\Models\HrPerformanceGoal;
use App\Modules\Hrd\Recruitment\Models\HrCandidateApplication;
use App\Modules\Hrd\Recruitment\Models\HrJobOffer;
use App\Modules\Hrd\Workforce\Models\HrAttendanceRecord;
use App\Modules\Hrd\Workforce\Models\HrOvertimeRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Peringatan otomatis (§111).
 *
 * Semua peringatan memakai `dedupe_key`, sehingga pemindaian yang berjalan
 * berkali-kali (scheduler boleh terpanggil ulang) tidak pernah membuat
 * peringatan ganda untuk hal yang sama.
 */
class AlertService
{
    /** @param array<string, mixed> $data */
    public function raise(string $type, array $data): ?HrAlert
    {
        $dedupe = (string) ($data['dedupe_key'] ?? $type.':'.($data['subject_id'] ?? '').':'.($data['due_date'] ?? ''));

        $existing = HrAlert::query()->where('dedupe_key', $dedupe)->first();

        if ($existing !== null) {
            if (in_array($existing->status, ['RESOLVED', 'DISMISSED'], true)) {
                return $existing;
            }

            $existing->forceFill(['status' => 'OPEN'])->save();

            return $existing;
        }

        return HrAlert::query()->create([
            'dedupe_key' => $dedupe,
            'alert_type' => $type,
            'severity' => $data['severity'] ?? 'INFO',
            'title' => $data['title'] ?? $type,
            'message' => $data['message'] ?? null,
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'employee_id' => $data['employee_id'] ?? null,
            'organization_id' => $data['organization_id'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'status' => 'OPEN',
            'payload' => $data['payload'] ?? null,
            'triggered_at' => now(),
        ]);
    }

    public function acknowledge(int $alertId, int $userId, ?string $notes = null): ?HrAlert
    {
        $alert = HrAlert::query()->find($alertId);

        if ($alert === null) {
            return null;
        }

        $alert->forceFill([
            'status' => 'ACKNOWLEDGED',
            'acknowledged_by' => $userId,
            'acknowledged_at' => now(),
            'resolution_notes' => $notes,
        ])->save();

        return $alert->refresh();
    }

    public function open(?int $organizationId = null): array
    {
        return HrAlert::query()
            ->where('status', 'OPEN')
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->orderByRaw("case severity when 'CRITICAL' then 1 when 'WARNING' then 2 else 3 end")
            ->limit(200)
            ->get()
            ->groupBy('severity')
            ->map(fn ($items) => $items->count())
            ->all();
    }

    /**
     * Pemindaian menyeluruh — idempoten karena setiap peringatan ber-dedupe.
     *
     * @return array<string, int>
     */
    public function scanAll(): array
    {
        return [
            'contract_expiring' => $this->scanContractExpiring(),
            'certification_expiring' => $this->scanCertificationExpiring(),
            'training_overdue' => $this->scanTrainingOverdue(),
            'performance_review_due' => $this->scanReviewDue(),
            'goal_overdue' => $this->scanGoalOverdue(),
            'offer_expiring' => $this->scanOfferExpiring(),
            'candidate_waiting' => $this->scanCandidateWaiting(),
            'overtime_anomaly' => $this->scanOvertimeAnomaly(),
            'absence_anomaly' => $this->scanAbsenceAnomaly(),
            'payroll_anomaly' => $this->scanPayrollAnomaly(),
        ];
    }

    private function scanContractExpiring(): int
    {
        if (! Schema::hasTable('hr_contracts') || ! Schema::hasColumn('hr_contracts', 'end_date')) {
            return 0;
        }

        $days = (int) config('hrd_phase2.alerts.contract_expiring_days', 45);
        $raised = 0;

        $rows = DB::table('hr_contracts')
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->when(Schema::hasColumn('hr_contracts', 'status'), fn ($query) => $query->whereNotIn('status', ['ENDED', 'TERMINATED', 'CANCELLED']))
            ->select(['id', 'employee_id', 'end_date'])
            ->limit(500)
            ->get();

        foreach ($rows as $row) {
            $this->raise('CONTRACT_EXPIRING', [
                'dedupe_key' => 'contract-expiring:'.$row->id.':'.$row->end_date,
                'severity' => Carbon::parse($row->end_date)->diffInDays(now()) <= 14 ? 'CRITICAL' : 'WARNING',
                'title' => 'Kontrak berakhir '.$row->end_date,
                'employee_id' => $row->employee_id,
                'subject_type' => 'hr_contracts',
                'subject_id' => $row->id,
                'due_date' => $row->end_date,
            ]);
            $raised++;
        }

        return $raised;
    }

    private function scanCertificationExpiring(): int
    {
        $days = (int) config('hrd_phase2.alerts.certification_expiring_days', 60);

        if (! Schema::hasTable('hr_employee_certifications') || ! Schema::hasColumn('hr_employee_certifications', 'expires_at')) {
            return 0;
        }

        $raised = 0;

        $rows = DB::table('hr_employee_certifications')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->select(['id', 'employee_id', 'expires_at'])
            ->limit(500)
            ->get();

        foreach ($rows as $row) {
            $this->raise('CERTIFICATION_EXPIRING', [
                'dedupe_key' => 'cert-expiring:'.$row->id,
                'severity' => Carbon::parse($row->expires_at)->diffInDays(now()) <= 30 ? 'CRITICAL' : 'WARNING',
                'title' => 'Sertifikasi berakhir '.$row->expires_at,
                'employee_id' => $row->employee_id,
                'subject_type' => 'hr_employee_certifications',
                'subject_id' => $row->id,
                'due_date' => $row->expires_at,
            ]);
            $raised++;
        }

        return $raised;
    }

    private function scanTrainingOverdue(): int
    {
        $assignments = HrTrainingAssignment::query()
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->whereNotIn('status', ['COMPLETED', 'CANCELLED'])
            ->limit(500)
            ->get();

        foreach ($assignments as $assignment) {
            $this->raise('TRAINING_OVERDUE', [
                'dedupe_key' => 'training-overdue:'.$assignment->getKey(),
                'severity' => $assignment->is_mandatory ? 'CRITICAL' : 'WARNING',
                'title' => 'Pelatihan wajib terlewat',
                'employee_id' => $assignment->employee_id,
                'subject_type' => HrTrainingAssignment::class,
                'subject_id' => $assignment->getKey(),
                'due_date' => $assignment->due_date?->toDateString(),
            ]);
        }

        return $assignments->count();
    }

    private function scanReviewDue(): int
    {
        $days = (int) config('hrd_phase2.alerts.performance_review_due_days', 14);

        $participants = HrPerformanceCycleParticipant::query()
            ->where('status', '!=', 'COMPLETED')
            ->whereHas('cycle', fn ($query) => $query->whereNotNull('review_end')->whereBetween('review_end', [now()->toDateString(), now()->addDays($days)->toDateString()]))
            ->limit(500)
            ->get();

        foreach ($participants as $participant) {
            $this->raise('PERFORMANCE_REVIEW_DUE', [
                'dedupe_key' => 'review-due:'.$participant->getKey(),
                'severity' => 'WARNING',
                'title' => 'Tinjauan kinerja menunggu',
                'employee_id' => $participant->employee_id,
                'subject_type' => HrPerformanceCycleParticipant::class,
                'subject_id' => $participant->getKey(),
            ]);
        }

        return $participants->count();
    }

    private function scanGoalOverdue(): int
    {
        $goals = HrPerformanceGoal::query()
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->whereIn('status', ['ACTIVE', 'AT_RISK'])
            ->limit(500)
            ->get();

        foreach ($goals as $goal) {
            $this->raise('GOAL_OVERDUE', [
                'dedupe_key' => 'goal-overdue:'.$goal->getKey(),
                'severity' => 'WARNING',
                'title' => 'Sasaran melewati tenggat',
                'employee_id' => $goal->employee_id,
                'subject_type' => HrPerformanceGoal::class,
                'subject_id' => $goal->getKey(),
                'due_date' => $goal->due_date?->toDateString(),
            ]);
        }

        return $goals->count();
    }

    private function scanOfferExpiring(): int
    {
        $days = (int) config('hrd_phase2.alerts.offer_expiring_days', 3);

        $offers = HrJobOffer::query()
            ->whereIn('status', ['SENT', 'APPROVED'])
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)])
            ->limit(200)
            ->get();

        foreach ($offers as $offer) {
            $this->raise('OFFER_EXPIRING', [
                'dedupe_key' => 'offer-expiring:'.$offer->getKey(),
                'severity' => 'CRITICAL',
                'title' => 'Tawaran kerja segera kedaluwarsa',
                'subject_type' => HrJobOffer::class,
                'subject_id' => $offer->getKey(),
                'due_date' => $offer->expires_at?->toDateString(),
            ]);
        }

        return $offers->count();
    }

    private function scanCandidateWaiting(): int
    {
        $days = (int) config('hrd_phase2.alerts.candidate_waiting_days', 5);

        $applications = HrCandidateApplication::query()
            ->where('status', 'ACTIVE')
            ->where('stage_entered_at', '<=', now()->subDays($days))
            ->limit(300)
            ->get();

        foreach ($applications as $application) {
            $this->raise('CANDIDATE_WAITING', [
                'dedupe_key' => 'candidate-waiting:'.$application->getKey().':'.$application->stage_code,
                'severity' => 'WARNING',
                'title' => 'Kandidat menunggu di tahap '.$application->stage_code,
                'subject_type' => HrCandidateApplication::class,
                'subject_id' => $application->getKey(),
            ]);
        }

        return $applications->count();
    }

    private function scanOvertimeAnomaly(): int
    {
        $threshold = (int) config('hrd_phase2.alerts.overtime_anomaly_hours', 40) * 60;

        $rows = HrOvertimeRecord::query()
            ->where('status', 'VERIFIED')
            ->where('date', '>=', now()->startOfMonth()->toDateString())
            ->selectRaw('employee_id, sum(verified_minutes) as minutes')
            ->groupBy('employee_id')
            ->havingRaw('sum(verified_minutes) > ?', [$threshold])
            ->limit(200)
            ->get();

        foreach ($rows as $row) {
            $this->raise('OVERTIME_ANOMALY', [
                'dedupe_key' => 'overtime-anomaly:'.$row->employee_id.':'.now()->format('Y-m'),
                'severity' => 'WARNING',
                'title' => 'Lembur tidak wajar bulan ini',
                'employee_id' => $row->employee_id,
                'payload' => ['verified_minutes' => (int) $row->minutes],
            ]);
        }

        return $rows->count();
    }

    private function scanAbsenceAnomaly(): int
    {
        $threshold = (int) config('hrd_phase2.alerts.absence_anomaly_days', 3);

        $rows = HrAttendanceRecord::query()
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->where('status', 'ABSENT')
            ->selectRaw('employee_id, count(*) as days')
            ->groupBy('employee_id')
            ->havingRaw('count(*) > ?', [$threshold])
            ->limit(200)
            ->get();

        foreach ($rows as $row) {
            $this->raise('ABSENCE_ANOMALY', [
                'dedupe_key' => 'absence-anomaly:'.$row->employee_id.':'.now()->format('Y-m'),
                'severity' => 'WARNING',
                'title' => 'Ketidakhadiran tinggi',
                'employee_id' => $row->employee_id,
                'payload' => ['absent_days' => (int) $row->days],
            ]);
        }

        return $rows->count();
    }

    private function scanPayrollAnomaly(): int
    {
        $runs = HrPayrollRun::query()
            ->whereNotNull('variance_snapshot')
            ->whereIn('status', ['REVIEW', 'APPROVED'])
            ->limit(50)
            ->get();

        foreach ($runs as $run) {
            $snapshot = is_array($run->variance_snapshot) ? $run->variance_snapshot : [];
            $flagged = array_filter($snapshot, fn ($value) => ($value['flagged'] ?? false) === true);

            if ($flagged === []) {
                continue;
            }

            $this->raise('PAYROLL_ANOMALY', [
                'dedupe_key' => 'payroll-anomaly:'.$run->getKey(),
                'severity' => 'CRITICAL',
                'title' => 'Selisih payroll di luar ambang',
                'subject_type' => HrPayrollRun::class,
                'subject_id' => $run->getKey(),
                'payload' => ['variance' => $flagged],
            ]);
        }

        return $runs->count();
    }
}
