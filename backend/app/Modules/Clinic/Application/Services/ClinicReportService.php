<?php

namespace App\Modules\Clinic\Application\Services;

use App\Models\User;
use App\Modules\Clinic\Domain\Contracts\InventoryProviderInterface;
use App\Modules\Clinic\Domain\Enums\AuditAction;
use App\Modules\Clinic\Models\ClinicDispensing;
use App\Modules\Clinic\Models\ClinicEmergency;
use App\Modules\Clinic\Models\ClinicReferral;
use App\Modules\Clinic\Models\ClinicVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard & pusat laporan klinik.
 *
 * Semua angka dihitung **di server dan di dalam cakupan akses** pengguna:
 * petugas medis melihat seluruh sekolah, orang tua hanya anaknya, siswa hanya
 * dirinya. Agregat yang berpotensi membuka data sensitif tidak pernah diberikan
 * ke peran yang tidak berwenang.
 *
 * Query dibuat hemat: hitungan memakai agregasi SQL, tren memakai satu query
 * berkelompok, dan daftar panjang selalu dibatasi — supaya dashboard tidak
 * melakukan pemindaian tabel penuh setiap kali dibuka.
 */
class ClinicReportService
{
    public function __construct(
        private readonly ClinicAccessService $access,
        private readonly InventoryProviderInterface $inventory,
    ) {
    }

    /**
     * Kartu ringkas untuk layar utama klinik.
     *
     * @return array<string, mixed>
     */
    public function dashboard(User $user): array
    {
        $today = now()->toDateString();
        $scope = fn (): Builder => $this->access->scopePatients(ClinicVisit::query(), $user);

        $visitsToday = $scope()->whereDate('visit_at', $today);
        $open = $scope()->whereNotIn('status', ['DISCHARGED', 'CANCELLED']);

        $queue = $open->clone()
            ->whereHas('queue', fn (Builder $q) => $q->whereIn('stage', ['WAITING', 'TRIAGE', 'WAITING_PROVIDER']))
            ->count();

        $pendingTriage = $scope()->where('status', 'REGISTERED')->count();

        $dashboard = [
            'date' => $today,
            'visits_today' => (clone $visitsToday)->count(),
            'visits_this_month' => $scope()->whereMonth('visit_at', now()->month)->whereYear('visit_at', now()->year)->count(),
            'queue_waiting' => $queue,
            'pending_triage' => $pendingTriage,
            'in_treatment' => $scope()->where('status', 'IN_PROGRESS')->count(),
            'in_observation' => $scope()->where('status', 'OBSERVATION')->count(),
            'emergencies_open' => $scope()->where('is_emergency', true)->whereNotIn('status', ['DISCHARGED', 'CANCELLED'])->count(),
            'referrals_active' => $this->access->scopePatients(ClinicReferral::query(), $user)
                ->whereNotIn('status', ['COMPLETED', 'CANCELLED'])
                ->count(),
            'follow_ups_due' => $this->access->scopePatients(\App\Modules\Clinic\Models\ClinicFollowUp::query(), $user)
                ->whereIn('status', ['PENDING', 'SCHEDULED', 'OVERDUE'])
                ->whereDate('due_date', '<=', now()->addDays(7)->toDateString())
                ->count(),
            'follow_ups_overdue' => $this->access->scopePatients(\App\Modules\Clinic\Models\ClinicFollowUp::query(), $user)
                ->whereIn('status', ['PENDING', 'SCHEDULED', 'OVERDUE'])
                ->whereDate('due_date', '<', $today)
                ->count(),
            'medications_dispensed_today' => $this->access->scopePatients(ClinicDispensing::query(), $user)
                ->whereDate('dispensed_at', $today)
                ->count(),
            'visits_by_day' => $this->trendByDay($user, 14),
            'visits_by_month' => $this->trendByMonth($user, 6),
            'top_complaints' => $this->topComplaints($user, 8),
            'top_diagnoses' => $this->topDiagnoses($user, 8),
            'queue_board' => $this->queueBoard($user),
        ];

        // Stok & kedaluwarsa hanya relevan (dan hanya boleh) untuk petugas klinik.
        if ($this->access->isClinicalStaff($user) || $user->hasAnyRole(['super-admin', 'admin-sekolah'])) {
            $dashboard['low_stock'] = $this->inventory->lowStockItems(10);
            $dashboard['expiring_medications'] = $this->inventory->expiringItems(
                (int) config('clinic.expiry_warning_days', 60),
                10,
            );
        } else {
            $dashboard['low_stock'] = [];
            $dashboard['expiring_medications'] = [];
        }

        return $dashboard;
    }

    /**
     * Papan antrean hari ini.
     *
     * @return list<array<string, mixed>>
     */
    public function queueBoard(User $user): array
    {
        $rows = $this->access->scopePatients(ClinicVisit::query(), $user)
            ->with(['patient.student:id,full_name,nis', 'triage:id,visit_id,priority,chief_complaint'])
            ->whereDate('visit_at', now()->toDateString())
            ->whereNotIn('status', ['DISCHARGED', 'CANCELLED'])
            ->orderByRaw("CASE priority WHEN 'EMERGENCY' THEN 0 WHEN 'URGENT' THEN 1 WHEN 'SEMI_URGENT' THEN 2 ELSE 3 END")
            ->orderBy('visit_at')
            ->limit(50)
            ->get();

        return $rows->map(fn (ClinicVisit $visit): array => [
            'visit_id' => $visit->id,
            'visit_number' => $visit->visit_number,
            'patient_name' => $visit->patient?->student?->full_name,
            'nis' => $visit->patient?->student?->nis,
            'priority' => $visit->priority,
            'priority_label' => $visit->priorityEnum()->label(),
            'status' => $visit->status,
            'status_label' => $visit->statusEnum()->label(),
            'stage' => $visit->queue?->stage,
            'queue_number' => $queueNumber = DB::table('clinic_queue_entries')->where('visit_id', $visit->id)->value('queue_number'),
            'waiting_minutes' => $visit->visit_at === null ? null : (int) $visit->visit_at->diffInMinutes(now()),
            'is_emergency' => (bool) $visit->is_emergency,
            'chief_complaint' => $visit->triage?->chief_complaint ?? $visit->chief_complaint,
        ])->all();
    }

    /**
     * Laporan kunjungan harian.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function daily(User $user, array $filters): array
    {
        $date = $filters['date'] ?? now()->toDateString();

        $query = $this->applyFilters($this->access->scopePatients(ClinicVisit::query(), $user), $filters)
            ->whereDate('visit_at', $date);

        $visits = $query->with(['patient.student:id,full_name,nis', 'triage:id,visit_id,priority', 'discharge:id,visit_id,status'])
            ->orderBy('visit_at')
            ->get();

        $byType = $visits->groupBy('visit_type')->map->count();
        $byStatus = $visits->groupBy('status')->map->count();
        $byPriority = $visits->groupBy('priority')->map->count();

        return [
            'date' => $date,
            'total' => $visits->count(),
            'by_visit_type' => $byType,
            'by_status' => $byStatus,
            'by_priority' => $byPriority,
            'referred' => $visits->where('status', 'REFERRED')->count(),
            'sent_home' => $visits->filter(fn (ClinicVisit $v): bool => (bool) ($v->discharge?->sent_home))->count(),
            'rows' => $visits->map(fn (ClinicVisit $visit): array => [
                'visit_number' => $visit->visit_number,
                'visit_at' => $visit->visit_at?->toIso8601String(),
                'patient_name' => $visit->patient?->student?->full_name,
                'nis' => $visit->patient?->student?->nis,
                'visit_type' => $visit->visit_type,
                'priority' => $visit->priority,
                'status' => $visit->status,
                'chief_complaint' => $visit->chief_complaint,
                'discharge_status' => $visit->discharge?->status,
            ])->all(),
        ];
    }

    /**
     * Ringkasan keluhan tersering.
     *
     * @return list<array<string, mixed>>
     */
    public function topComplaints(User $user, int $limit = 10, ?string $from = null, ?string $to = null): array
    {
        $from ??= now()->subDays(30)->toDateString();
        $to ??= now()->toDateString();

        return $this->access->scopePatients(ClinicVisit::query(), $user)
            ->whereBetween(DB::raw('date(visit_at)'), [$from, $to])
            ->whereNotNull('chief_complaint')
            ->select('chief_complaint', DB::raw('count(*) as total'))
            ->groupBy('chief_complaint')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => ['complaint' => $row->chief_complaint, 'total' => (int) $row->total])
            ->all();
    }

    /**
     * Ringkasan diagnosis tersering.
     *
     * Agregat diagnosis termasuk informasi klinis: hanya diberikan kepada
     * pengguna yang memang berhak melihat laporan tingkat pasien.
     *
     * @return list<array<string, mixed>>
     */
    public function topDiagnoses(User $user, int $limit = 10, ?string $from = null, ?string $to = null): array
    {
        if (! $this->access->canViewAll($user)) {
            return [];
        }

        $from ??= now()->subDays(90)->toDateString();
        $to ??= now()->toDateString();

        return DB::table('clinic_diagnoses')
            ->join('clinic_visits', 'clinic_visits.id', '=', 'clinic_diagnoses.visit_id')
            ->whereBetween(DB::raw('date(clinic_visits.visit_at)'), [$from, $to])
            ->select('clinic_diagnoses.diagnosis_name', DB::raw('count(*) as total'))
            ->groupBy('clinic_diagnoses.diagnosis_name')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => ['diagnosis' => $row->diagnosis_name, 'total' => (int) $row->total])
            ->all();
    }

    /**
     * Pemakaian obat (dari penyerahan yang benar-benar mengurangi stok).
     *
     * @return list<array<string, mixed>>
     */
    public function medicationUsage(User $user, ?string $from = null, ?string $to = null, int $limit = 50): array
    {
        $from ??= now()->subDays(30)->toDateString();
        $to ??= now()->toDateString();

        return DB::table('clinic_dispensing_items')
            ->join('clinic_dispensings', 'clinic_dispensings.id', '=', 'clinic_dispensing_items.dispensing_id')
            ->leftJoin('clinic_medications', 'clinic_medications.id', '=', 'clinic_dispensing_items.medication_id')
            ->whereBetween(DB::raw('date(clinic_dispensings.dispensed_at)'), [$from, $to])
            ->where('clinic_dispensing_items.inventory_result', 'SUCCESS')
            ->selectRaw("clinic_dispensing_items.medication_id, coalesce(clinic_medications.name, 'Tanpa master') as medication_name, sum(clinic_dispensing_items.quantity) as total_quantity, count(*) as times_dispensed")
            ->groupBy('clinic_dispensing_items.medication_id', 'clinic_medications.name')
            ->orderByDesc('times_dispensed')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'medication_id' => $row->medication_id,
                'medication_name' => $row->medication_name,
                'total_quantity' => (float) $row->total_quantity,
                'times_dispensed' => (int) $row->times_dispensed,
            ])->all();
    }

    /**
     * Laporan rujukan.
     *
     * @return array<string, mixed>
     */
    public function referrals(User $user, ?string $from = null, ?string $to = null): array
    {
        $from ??= now()->subDays(90)->toDateString();
        $to ??= now()->toDateString();

        $rows = $this->access->scopePatients(ClinicReferral::query(), $user)
            ->with(['patient.student:id,full_name,nis'])
            ->whereBetween(DB::raw('date(referral_date)'), [$from, $to])
            ->orderByDesc('referral_date')
            ->limit(500)
            ->get();

        return [
            'from' => $from,
            'to' => $to,
            'total' => $rows->count(),
            'by_status' => $rows->groupBy('status')->map->count(),
            'by_facility' => $rows->groupBy('facility')->map->count(),
            'rows' => $rows->map(fn (ClinicReferral $r): array => [
                'referral_number' => $r->referral_number,
                'referral_date' => $r->referral_date?->toIso8601String(),
                'patient_name' => $r->patient?->student?->full_name,
                'facility' => $r->facility,
                'urgency' => $r->urgency,
                'status' => $r->status,
            ])->all(),
        ];
    }

    /**
     * Laporan kegawatdaruratan.
     *
     * @return array<string, mixed>
     */
    public function emergencies(User $user, ?string $from = null, ?string $to = null): array
    {
        $from ??= now()->subDays(90)->toDateString();
        $to ??= now()->toDateString();

        $rows = ClinicEmergency::query()
            ->when(true, function (Builder $q) use ($user): void {
                $ids = $this->access->visiblePatientIds($user);
                if ($ids !== null) {
                    $q->whereIn('patient_id', $ids === [] ? [-1] : $ids);
                }
            })
            ->with(['patient.student:id,full_name,nis'])
            ->whereBetween(DB::raw('date(incident_at)'), [$from, $to])
            ->orderByDesc('incident_at')
            ->limit(500)
            ->get();

        return [
            'from' => $from,
            'to' => $to,
            'total' => $rows->count(),
            'by_severity' => $rows->groupBy('severity')->map->count(),
            'awaiting_parent_contact' => $rows->where('parent_contacted', false)->count(),
            'with_referral' => $rows->where('referral_required', true)->count(),
            'rows' => $rows->map(fn (ClinicEmergency $e): array => [
                'incident_number' => $e->incident_number,
                'incident_at' => $e->incident_at?->toIso8601String(),
                'patient_name' => $e->patient?->student?->full_name,
                'severity' => $e->severity,
                'status' => $e->status,
                'parent_contacted' => (bool) $e->parent_contacted,
            ])->all(),
        ];
    }

    /**
     * Laporan skrining & imunisasi.
     *
     * @return array<string, mixed>
     */
    public function screenings(User $user, ?int $programId = null): array
    {
        $ids = $this->access->visiblePatientIds($user);

        $query = DB::table('clinic_screenings')
            ->leftJoin('students', 'students.id', '=', 'clinic_screenings.student_id')
            ->when($ids !== null, fn ($q) => $q->whereIn('clinic_screenings.patient_id', $ids === [] ? [-1] : $ids))
            ->when($programId !== null, fn ($q) => $q->where('clinic_screenings.program_id', $programId));

        $rows = (clone $query)->select(
            'clinic_screenings.id',
            'clinic_screenings.type',
            'clinic_screenings.result',
            'clinic_screenings.bmi',
            'clinic_screenings.screened_at',
            'students.full_name as patient_name',
            'students.nis',
        )->orderByDesc('clinic_screenings.screened_at')->limit(500)->get();

        $vaccinations = DB::table('clinic_vaccinations')
            ->when($ids !== null, fn ($q) => $q->whereIn('patient_id', $ids === [] ? [-1] : $ids))
            ->select('vaccine', DB::raw('count(*) as total'))
            ->groupBy('vaccine')
            ->get()
            ->map(fn ($row): array => ['vaccine' => $row->vaccine, 'total' => (int) $row->total])
            ->all();

        return [
            'total' => $rows->count(),
            'by_result' => $rows->groupBy('result')->map->count(),
            'by_type' => $rows->groupBy('type')->map->count(),
            'rows' => $rows->map(fn ($r): array => (array) $r)->all(),
            'vaccinations' => $vaccinations,
        ];
    }

    /**
     * Laporan tindak lanjut.
     *
     * @return array<string, mixed>
     */
    public function followUps(User $user, ?string $status = null): array
    {
        $rows = $this->access->scopePatients(\App\Modules\Clinic\Models\ClinicFollowUp::query(), $user)
            ->with(['patient.student:id,full_name,nis'])
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->orderBy('due_date')
            ->limit(500)
            ->get();

        return [
            'total' => $rows->count(),
            'overdue' => $rows->filter(fn ($f): bool => $f->isOverdue())->count(),
            'by_status' => $rows->groupBy('status')->map->count(),
            'rows' => $rows->map(fn ($f): array => [
                'id' => $f->id,
                'due_date' => $f->due_date?->toDateString(),
                'patient_name' => $f->patient?->student?->full_name,
                'type' => $f->type,
                'status' => $f->status,
                'overdue' => $f->isOverdue(),
            ])->all(),
        ];
    }

    /**
     * Aktivitas petugas klinik (jumlah tindakan yang dicatat).
     *
     * @return list<array<string, mixed>>
     */
    public function staffActivity(User $user, ?string $from = null, ?string $to = null): array
    {
        if (! $this->access->canViewAll($user)) {
            return [];
        }

        $from ??= now()->subDays(30)->toDateString();
        $to ??= now()->toDateString();

        return DB::table('clinic_audit_logs')
            ->join('users', 'users.id', '=', 'clinic_audit_logs.actor_id')
            ->whereBetween(DB::raw('date(clinic_audit_logs.occurred_at)'), [$from, $to])
            ->select('users.name', 'clinic_audit_logs.action', DB::raw('count(*) as total'))
            ->groupBy('users.name', 'clinic_audit_logs.action')
            ->orderByDesc('total')
            ->limit(100)
            ->get()
            ->map(fn ($row): array => ['staff' => $row->name, 'action' => $row->action, 'total' => (int) $row->total])
            ->all();
    }

    /**
     * Laporan akses rekam medis (kepatuhan privasi).
     *
     * @return list<array<string, mixed>>
     */
    public function accessLog(User $user, ?int $patientId = null, int $limit = 200): array
    {
        if (! $this->access->canViewAll($user)) {
            return [];
        }

        return DB::table('clinic_audit_logs')
            ->leftJoin('users', 'users.id', '=', 'clinic_audit_logs.actor_id')
            ->when($patientId !== null, fn ($q) => $q->where('clinic_audit_logs.patient_id', $patientId))
            ->whereIn('clinic_audit_logs.action', ['VIEW', 'EXPORT', 'PRINT', 'SHARE'])
            ->select(
                'clinic_audit_logs.action',
                'clinic_audit_logs.patient_id',
                'clinic_audit_logs.access_reason',
                'clinic_audit_logs.occurred_at',
                'users.name as actor_name',
                'clinic_audit_logs.actor_role',
            )
            ->orderByDesc('clinic_audit_logs.occurred_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'action' => $row->action,
                'patient_id' => $row->patient_id,
                'actor' => $row->actor_name,
                'actor_role' => $row->actor_role,
                'reason' => $row->access_reason,
                'occurred_at' => $row->occurred_at,
            ])->all();
    }

    /**
     * Ekspor CSV generik untuk seluruh laporan di atas.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function toCsv(array $rows): string
    {
        if ($rows === []) {
            return "tidak ada data\n";
        }

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_keys((array) $rows[0]));

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn ($value): string => is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE),
                (array) $row,
            ));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** Catat bahwa laporan berisi data pasien telah diekspor. */
    public function auditExport(User $user, string $reportName, array $filters): void
    {
        app(ClinicAuditService::class)->record(
            AuditAction::EXPORT,
            null,
            null,
            $user,
            ['report' => $reportName, 'filters' => $filters],
            [],
            'Ekspor laporan klinik',
        );
    }

    /** @return list<array{date: string, total: int}> */
    private function trendByDay(User $user, int $days): array
    {
        $from = now()->subDays($days - 1)->toDateString();

        $rows = $this->access->scopePatients(ClinicVisit::query(), $user)
            ->whereDate('visit_at', '>=', $from)
            ->select(DB::raw('date(visit_at) as bucket'), DB::raw('count(*) as total'))
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        return collect(range($days - 1, 0))
            ->map(function (int $offset) use ($rows): array {
                $date = Carbon::today()->subDays($offset)->toDateString();

                return ['date' => $date, 'total' => (int) ($rows[$date] ?? 0)];
            })
            ->all();
    }

    /**
     * Tren kunjungan per bulan.
     *
     * Pengelompokan dilakukan per rentang bulan (bukan fungsi tanggal khusus
     * vendor) supaya tetap benar di SQLite, MySQL, maupun PostgreSQL: enam
     * query kecil pada kolom terindeks jauh lebih murah daripada satu query
     * yang tidak portabel.
     *
     * @return list<array{month: string, total: int}>
     */
    private function trendByMonth(User $user, int $months): array
    {
        return collect(range($months - 1, 0))
            ->map(function (int $offset) use ($user): array {
                $month = Carbon::today()->subMonths($offset);

                $total = $this->access->scopePatients(ClinicVisit::query(), $user)
                    ->whereBetween('visit_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
                    ->count();

                return ['month' => $month->format('Y-m'), 'total' => $total];
            })
            ->all();
    }

    /**
     * @param Builder<ClinicVisit> $query
     * @param array<string, mixed> $filters
     * @return Builder<ClinicVisit>
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(! empty($filters['clinic_id']), fn (Builder $q) => $q->where('clinic_id', $filters['clinic_id']))
            ->when(! empty($filters['visit_type']), fn (Builder $q) => $q->where('visit_type', $filters['visit_type']))
            ->when(! empty($filters['priority']), fn (Builder $q) => $q->where('priority', $filters['priority']))
            ->when(! empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['class_id']), fn (Builder $q) => $q->where('school_class_id', $filters['class_id']))
            ->when(! empty($filters['provider_id']), fn (Builder $q) => $q->where('attended_by', $filters['provider_id']))
            ->when(! empty($filters['from']), fn (Builder $q) => $q->whereDate('visit_at', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn (Builder $q) => $q->whereDate('visit_at', '<=', $filters['to']));
    }
}
