<?php

namespace App\Modules\Hrd\Application\Services;

use App\Models\User;
use App\Modules\Hrd\Application\Support\HrdSettingsService;
use App\Modules\Hrd\Application\Support\IntegrationOutbox;
use App\Modules\Hrd\Models\HrContract;
use App\Modules\Hrd\Models\HrDataQualityIssue;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeCertification;
use App\Modules\Hrd\Models\HrEmployeeDocument;
use App\Modules\Hrd\Models\HrOffboardingProcess;
use App\Modules\Hrd\Models\HrOnboardingProcess;
use App\Modules\Hrd\Models\HrPosition;
use App\Modules\Hrd\Models\HrProbation;
use App\Modules\Hrd\Models\HrRequest;
use App\Modules\Hrd\Models\HrTransaction;

/**
 * Dasbor & pusat komando HR (§47, §74).
 *
 * Setiap angka di sini **dapat diklik menuju datanya** — bukan kartu hiasan.
 * Karena itu setiap blok mengembalikan `count` sekaligus `link` dan `filter`
 * yang siap dipakai layar daftar.
 *
 * Angka dihitung dari sumbernya masing-masing (pegawai, kontrak, sertifikasi,
 * transaksi), bukan dari tabel ringkasan yang bisa basi.
 */
class DashboardService
{
    public function __construct(
        private readonly DataQualityService $quality,
        private readonly IntegrationOutbox $outbox,
        private readonly HrdSettingsService $settings,
        private readonly HrdWorkflowService $workflow,
    ) {}

    /**
     * Ringkasan tenaga kerja (§47 Workforce).
     *
     * @return array<string, mixed>
     */
    public function workforce(?int $organizationId = null): array
    {
        $scope = fn ($query) => $query->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId));

        $total = $scope(HrEmployee::query())->whereNotIn('lifecycle_state', ['archived'])->count();
        $active = $scope(HrEmployee::query())->active()->count();
        $inactive = $scope(HrEmployee::query())->whereIn('lifecycle_state', ['inactive', 'archived'])->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'probation' => $scope(HrEmployee::query())->where('lifecycle_state', 'probation')->count(),
            'leave' => $scope(HrEmployee::query())->where('lifecycle_state', 'leave')->count(),
            'suspended' => $scope(HrEmployee::query())->where('lifecycle_state', 'suspended')->count(),
            'new_hires_30d' => $scope(HrEmployee::query())->where('hire_date', '>=', now()->subDays(30)->toDateString())->count(),
            'terminated_90d' => $scope(HrEmployee::query())->whereNotNull('termination_date')->where('termination_date', '>=', now()->subDays(90)->toDateString())->count(),
            'permanent' => $scope(HrEmployee::query())->where('employment_type', 'Permanent')->active()->count(),
            'contract_workers' => $scope(HrEmployee::query())->whereIn('employment_type', ['Fixed Term', 'Contract', 'Temporary', 'Project Based'])->active()->count(),
            'part_time' => $scope(HrEmployee::query())->where('employment_type', 'Part Time')->active()->count(),
            'interns' => $scope(HrEmployee::query())->whereIn('employment_type', ['Internship'])->orWhere('worker_type', 'Intern')->active()->count(),
        ];
    }

    /** Sebaran pegawai per dimensi organisasi (§47 Organization). */
    public function distribution(?int $organizationId = null, int $limit = 15): array
    {
        $grouped = fn (string $column) => HrEmployee::query()
            ->active()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->selectRaw("coalesce($column, 'Tanpa Data') as label, count(*) as aggregate")
            ->groupBy($column)
            ->orderByDesc('aggregate')
            ->limit($limit)
            ->pluck('aggregate', 'label')
            ->all();

        return [
            'by_organization' => $grouped('organization_id'),
            'by_department' => $grouped('department_id'),
            'by_position' => $grouped('position_id'),
            'by_location' => $grouped('location_id'),
            'by_worker_type' => $grouped('worker_type'),
            'by_employment_type' => $grouped('employment_type'),
            'by_job_family' => $this->byJobFamily($organizationId),
            'by_lifecycle_state' => $grouped('lifecycle_state'),
        ];
    }

    /**
     * Peringatan yang menuntut tindakan hari ini (§74 HR Command Center).
     *
     * @return array<string, mixed>
     */
    public function alerts(?int $organizationId = null): array
    {
        $contractWindows = $this->settings->reminderWindows('contract_days');
        $documentWindow = max([30, ...$this->settings->reminderWindows('document_days')]);
        $certificationWindow = max([30, ...$this->settings->reminderWindows('certification_days')]);
        $probationWindow = max([30, ...$this->settings->reminderWindows('probation_days')]);

        $contracts = HrContract::query()
            ->whereIn('status', ['active', 'expiring'])
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<=', now()->addDays($contractWindows === [] ? 90 : max($contractWindows))->toDateString())
            ->when($organizationId !== null, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->count();

        $documents = HrEmployeeDocument::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($documentWindow)->toDateString())
            ->when($organizationId !== null, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->count();

        $certifications = HrEmployeeCertification::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($certificationWindow)->toDateString())
            ->when($organizationId !== null, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->count();

        $probations = HrProbation::query()
            ->whereIn('status', ['pending', 'active', 'extended'])
            ->whereDate('end_date', '<=', now()->addDays($probationWindow)->toDateString())
            ->when($organizationId !== null, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->count();

        $pendingTransactions = HrTransaction::query()
            ->whereIn('status', ['draft', 'submitted', 'review'])
            ->when($organizationId !== null, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->count();

        $awaitingApply = HrTransaction::query()->where('status', 'approved')->whereNull('applied_at')->count();

        return [
            'contracts_expiring' => $this->alert('contracts_expiring', 'Kontrak akan berakhir', $contracts, '/hrd/contracts', ['status' => 'expiring']),
            'documents_expiring' => $this->alert('documents_expiring', 'Dokumen akan berakhir', $documents, '/hrd/documents', ['expiring' => true]),
            'certifications_expiring' => $this->alert('certifications_expiring', 'Sertifikasi akan berakhir', $certifications, '/hrd/certifications', ['expiring' => true]),
            'probations_ending' => $this->alert('probations_ending', 'Masa percobaan berakhir', $probations, '/hrd/employment/probation', []),
            'pending_transactions' => $this->alert('pending_transactions', 'Transaksi menunggu persetujuan', $pendingTransactions, '/hrd/transactions', ['pending' => true]),
            'transactions_awaiting_apply' => $this->alert('transactions_awaiting_apply', 'Transaksi siap diterapkan', $awaitingApply, '/hrd/transactions', ['awaiting_apply' => true]),
            'pending_requests' => $this->alert('pending_requests', 'Permintaan HR menunggu', HrRequest::query()->pending()->count(), '/hrd/requests/pending', ['pending' => true]),
            'requests_breaching_sla' => $this->alert('requests_breaching_sla', 'Permintaan melewati SLA', HrRequest::query()->breachingSla()->count(), '/hrd/requests/pending', ['breaching_sla' => true]),
            'onboarding_open' => $this->alert('onboarding_open', 'Onboarding berjalan', HrOnboardingProcess::query()->whereIn('status', ['pending', 'preboarding', 'in_progress'])->count(), '/hrd/onboarding', ['status' => 'open']),
            'offboarding_open' => $this->alert('offboarding_open', 'Offboarding berjalan', HrOffboardingProcess::query()->whereIn('status', ['draft', 'submitted', 'approved', 'notice_period', 'in_progress'])->count(), '/hrd/offboarding', ['status' => 'open']),
            'vacancies' => $this->alert('vacancies', 'Posisi kosong', HrPosition::query()->open()->withVacancy()->count(), '/hrd/positions', ['vacancy' => true]),
            'data_quality_critical' => $this->alert('data_quality_critical', 'Temuan kualitas data kritis', HrDataQualityIssue::query()->unresolved()->critical()->count(), '/hrd/data-quality', ['severity' => 'critical']),
        ];
    }

    /**
     * Dasbor lengkap untuk satu cakupan pembaca.
     *
     * @return array<string, mixed>
     */
    public function overview(?int $organizationId = null): array
    {
        return [
            'workforce' => $this->workforce($organizationId),
            'distribution' => $this->distribution($organizationId),
            'alerts' => $this->alerts($organizationId),
            'headcount' => app(PositionService::class)->headcountSummary($organizationId),
            'data_quality' => $this->quality->overview(),
            'health' => $this->health(),
        ];
    }

    /**
     * Kesehatan modul (§56, §74): hal-hal yang biasanya baru terlihat ketika sudah
     * terlambat — outbox menumpuk, handler efek hilang, engine workflow tidak siap.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $outbox = $this->outbox->status();

        $pending = 0;
        $failed = 0;

        foreach ($outbox as $module => $statuses) {
            $pending += (int) ($statuses['pending'] ?? 0);
            $failed += (int) ($statuses['failed'] ?? 0);
        }

        return [
            'status' => $failed > 0 ? 'degraded' : 'ok',
            'integration_outbox' => [
                'pending' => $pending,
                'failed' => $failed,
                'by_module' => $outbox,
                'registered_handlers' => $this->outbox->registeredActions(),
            ],
            'workflow_engine' => [
                'available' => $this->workflow->isEngineAvailable(),
            ],
            'employees' => [
                'active' => HrEmployee::query()->active()->count(),
                'archived' => HrEmployee::query()->archived()->count(),
                'without_assignment' => HrEmployee::query()->active()->whereDoesntHave('assignments', fn ($q) => $q->where('status', 'active'))->count(),
            ],
            'data_quality' => $this->quality->overview(),
        ];
    }

    /** @return array<string, int> */
    private function byJobFamily(?int $organizationId): array
    {
        $rows = HrEmployee::query()
            ->active()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereNotNull('position_id')
            ->join('hr_positions', 'hr_positions.id', '=', 'hr_employees.position_id')
            ->leftJoin('hr_jobs', 'hr_jobs.id', '=', 'hr_positions.job_id')
            ->selectRaw("coalesce(hr_jobs.job_family_code, 'Tanpa Data') as label, count(*) as aggregate")
            ->groupBy('label')
            ->orderByDesc('aggregate')
            ->pluck('aggregate', 'label')
            ->all();

        return array_map('intval', $rows);
    }

    /** @param array<string, mixed> $filter */
    private function alert(string $key, string $label, int $count, ?string $link, array $filter): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'severity' => $count === 0 ? 'info' : ($count > 10 ? 'critical' : 'warning'),
            'link' => $link,
            'filter' => $filter,
        ];
    }

    /** Dipakai laman "Profil saya" (§42): apa yang perlu dilengkapi pegawai ini. */
    public function selfServiceChecklist(User $user): array
    {
        $employee = app(HrdAccessService::class)->employeeForUser($user);

        if ($employee === null) {
            return ['employee_linked' => false, 'items' => []];
        }

        $items = [
            ['key' => 'profile', 'label' => 'Data pribadi lengkap', 'done' => $employee->person?->nik !== null && $employee->person?->birth_date !== null],
            ['key' => 'contact', 'label' => 'Kontak & alamat terisi', 'done' => $employee->contacts()->exists() || $employee->addresses()->exists()],
            ['key' => 'emergency', 'label' => 'Kontak darurat', 'done' => $employee->emergencyContacts()->exists()],
            ['key' => 'education', 'label' => 'Riwayat pendidikan', 'done' => $employee->educations()->exists()],
            ['key' => 'documents', 'label' => 'Dokumen wajib terunggah', 'done' => $employee->documents()->exists()],
            ['key' => 'contract', 'label' => 'Kontrak tercatat', 'done' => $employee->contracts()->exists()],
            ['key' => 'skills', 'label' => 'Keterampilan terisi', 'done' => $employee->skills()->exists()],
        ];

        $done = count(array_filter($items, fn (array $item) => $item['done']));

        return [
            'employee_linked' => true,
            'employee_id' => (int) $employee->getKey(),
            'completion' => (int) round($done / max(1, count($items)) * 100),
            'items' => $items,
        ];
    }
}
