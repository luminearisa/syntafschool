<?php

namespace App\Services\Ppdb;

use App\Models\PpdbApplicant;
use App\Models\PpdbApplicantDocument;
use App\Models\PpdbInvoice;
use App\Models\PpdbParentAccount;
use App\Models\PpdbPeriod;
use App\Models\PpdbRegistration;
use App\Models\PpdbSelectionParticipant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard admin PPDB.
 *
 * Semua angka dihitung dengan SQL (group by + agregat) di atas SATU lapisan
 * filter yang sama dengan laporan — `PpdbReportService::applyFilters()` —
 * sehingga kartu dashboard dan baris laporan tidak pernah berbeda angka.
 * Tidak ada query per baris: tiap blok membayar beberapa query agregat,
 * berapa pun jumlah pendaftaran.
 *
 * Cakupan: `period_id` boleh dikosongkan. Bila kosong dan `all_periods` tidak
 * dikirim, dipakai periode non-arsip terbaru. `period` bernilai null bila
 * cakupannya semua periode.
 *
 * Semua nilai numerik selalu int/float (tidak pernah null); penyebut nol
 * menghasilkan 0.0 lewat `rate()`.
 */
class PpdbDashboardService
{
    /** Jumlah keranjang tren yang dikembalikan (mingguan/bulanan). */
    public const TREND_BUCKETS = 6;

    /**
     * Tahap corong. Ekspresinya kumulatif (siapa yang PERNAH melewati tahap
     * itu) dan memakai alias `r` milik `registrationQuery()`.
     *
     * @var list<array{key:string,label:string,basis:string,expr:string}>
     */
    public const FUNNEL_STEPS = [
        [
            'key' => 'draft',
            'label' => 'Dibuat',
            'basis' => 'semua registrasi pada cakupan',
            'expr' => '1 = 1',
        ],
        [
            'key' => 'submitted',
            'label' => 'Terkirim',
            'basis' => 'status bukan draft (sudah dikirim orang tua)',
            'expr' => "r.status <> 'draft'",
        ],
        [
            'key' => 'verified',
            'label' => 'Terverifikasi',
            'basis' => 'memiliki waktu verifikasi',
            'expr' => 'r.verified_at is not null',
        ],
        [
            'key' => 'selection',
            'label' => 'Masuk Seleksi',
            'basis' => 'status seleksi atau sesudahnya',
            'expr' => "r.status in ('selection', 'passed', 'failed', 'waitlisted', 're_registration', 'completed')",
        ],
        [
            'key' => 'passed',
            'label' => 'Lulus',
            'basis' => "status lulus/daftar ulang/selesai atau selection_result = 'passed'",
            'expr' => "r.status in ('passed', 're_registration', 'completed') or r.selection_result = 'passed'",
        ],
        [
            'key' => 'completed',
            'label' => 'Selesai',
            'basis' => 'status selesai (sudah menjadi siswa)',
            'expr' => "r.status = 'completed'",
        ],
    ];

    /**
     * Aksi tertunda. `filters` adalah param query yang sah untuk
     * `PpdbReportFilterRequest`, jadi satu item bisa dipakai sekaligus sebagai
     * deep-link daftar admin, laporan JSON, ekspor CSV, dan PDF.
     *
     * @var list<array<string, mixed>>
     */
    public const PENDING_ACTIONS = [
        [
            'key' => 'verify_registration',
            'label' => 'Pendaftaran menunggu verifikasi',
            'description' => 'Berkas pendaftaran yang sudah dikirim tetapi belum diputuskan panitia.',
            'scope' => 'registration',
            'filters' => ['status' => [PpdbRegistration::STATUS_SUBMITTED, PpdbRegistration::STATUS_UNDER_REVIEW]],
            'route_key' => 'ppdb.registrations.index',
            'report_type' => 'registration',
        ],
        [
            'key' => 'verify_payment',
            'label' => 'Pembayaran menunggu verifikasi',
            'description' => 'Pendaftaran dengan status pembayaran menunggu verifikasi bendahara.',
            'scope' => 'registration',
            'filters' => ['payment_status' => ['pending']],
            'route_key' => 'ppdb.invoices.index',
            'report_type' => 'payment',
        ],
        [
            'key' => 'review_document',
            'label' => 'Dokumen menunggu tinjau',
            'description' => 'Berkas aktif yang terunggah dan belum disetujui/ditolak.',
            'scope' => 'document',
            'filters' => ['review_status' => [
                PpdbApplicantDocument::STATUS_UPLOADED,
                PpdbApplicantDocument::STATUS_UNDER_REVIEW,
            ]],
            'route_key' => 'ppdb.documents.index',
            'report_type' => 'document',
        ],
        [
            'key' => 'score_selection',
            'label' => 'Peserta seleksi belum dinilai',
            'description' => 'Peserta tahap seleksi yang masih berstatus undangan atau hadir.',
            'scope' => 'selection',
            'filters' => ['participant_status' => [
                PpdbSelectionParticipant::STATUS_INVITED,
                PpdbSelectionParticipant::STATUS_ATTENDED,
            ]],
            'route_key' => 'ppdb.selection-stages.index',
            'report_type' => 'selection',
        ],
    ];

    /** Nama bulan Indonesia untuk label tren. */
    private const MONTHS_ID = [
        1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des',
    ];

    /** Status yang dianggap sudah diterima (pemenuhan kuota). */
    private const ACCEPTED_SQL = "('passed', 're_registration', 'completed')";

    public function __construct(protected readonly PpdbReportService $reports)
    {
    }

    /**
     * Muat seluruh blok dashboard sekaligus.
     *
     * @param  array<string, mixed>  $rawFilters
     * @return array<string, mixed>
     */
    public function admin(array $rawFilters = []): array
    {
        [$filters, $period] = $this->resolveScope($rawFilters);

        return [
            'scope' => $period === null ? 'all' : 'period',
            'period' => $period,
            'statistics' => $this->statistics($filters),
            'funnel' => $this->funnel($filters),
            'financial' => $this->financial($filters),
            'distribution' => $this->distribution($filters),
            'pending_actions' => $this->pendingActions($filters),
            'trend' => $this->trend($filters),
            'filters' => $this->reports->filterPayload($filters, 'registration'),
            'generated_at' => now()->toIso8601String(),
            'updated_at' => $this->lastChange($filters),
        ];
    }

    // ==================================================================
    // CAKUPAN
    // ==================================================================

    /**
     * Filter mentah → filter ternormalisasi (+ periode default) dan muat
     * metadata periode cakupan.
     *
     * @param  array<string, mixed>  $raw
     * @return array{0: array<string, mixed>, 1: ?array<string, mixed>}
     */
    public function resolveScope(array $raw): array
    {
        $filters = $this->reports->normalize($raw);

        if ($filters['period_id'] === null && ! $filters['all_periods']) {
            $default = $this->defaultPeriodId();

            if ($default !== null) {
                $filters['period_id'] = $default;
            }
        }

        return [$filters, $this->periodPayload($filters['period_id'])];
    }

    /** Periode non-arsip terbaru; null bila belum ada periode sama sekali. */
    protected function defaultPeriodId(): ?int
    {
        $period = PpdbPeriod::query()
            ->where('status', '!=', PpdbPeriod::STATUS_ARCHIVED)
            ->latest('id')
            ->first();

        return $period === null ? null : (int) $period->id;
    }

    /**
     * @return ?array<string, mixed>
     */
    protected function periodPayload(?int $periodId): ?array
    {
        if ($periodId === null) {
            return null;
        }

        $period = DB::table('ppdb_periods')
            ->where('id', $periodId)
            ->whereNull('deleted_at')
            ->first([
                'id', 'code', 'name', 'slug', 'status',
                'registration_start', 'registration_end',
                'selection_announcement_date', 're_registration_start', 're_registration_end',
            ]);

        if ($period === null) {
            return null;
        }

        $programs = DB::table('ppdb_programs')
            ->where('period_id', $periodId)
            ->selectRaw('count(*) as programs, coalesce(sum(quota), 0) as quota')
            ->first();

        return [
            'id' => (int) $period->id,
            'code' => (string) $period->code,
            'name' => (string) $period->name,
            'slug' => (string) $period->slug,
            'status' => (string) $period->status,
            'registration_start' => $this->dateString($period->registration_start),
            'registration_end' => $this->dateString($period->registration_end),
            'selection_announcement_date' => $this->dateString($period->selection_announcement_date),
            're_registration_start' => $this->dateString($period->re_registration_start),
            're_registration_end' => $this->dateString($period->re_registration_end),
            'program_count' => (int) ($programs->programs ?? 0),
            'total_quota' => (int) ($programs->quota ?? 0),
            'wave_count' => (int) DB::table('ppdb_waves')->where('period_id', $periodId)->count(),
        ];
    }

    /** Waktu perubahan terakhir di dalam cakupan (fallback: sekarang). */
    protected function lastChange(array $f): string
    {
        $last = $this->reports->registrationQuery($f)->max('r.updated_at');

        return $last === null
            ? now()->toIso8601String()
            : CarbonImmutable::parse((string) $last)->toIso8601String();
    }

    // ==================================================================
    // 1. STATISTIK
    // ==================================================================

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    protected function statistics(array $f): array
    {
        $totals = (clone $this->reports->registrationQuery($f))
            ->selectRaw('count(*) as registrations')
            ->selectRaw('count(distinct r.applicant_id) as applicants')
            ->selectRaw('count(distinct r.parent_account_id) as parent_accounts')
            ->selectRaw("sum(case when r.status <> 'draft' then 1 else 0 end) as submitted")
            ->selectRaw('count(distinct case when r.status in ('.$this->acceptedList().') then r.applicant_id end) as accepted')
            ->selectRaw("sum(case when r.status = 'completed' then 1 else 0 end) as completed")
            ->first();

        $documents = $this->documentStatistics($f);
        $invoices = $this->invoiceStatistics($f);

        return [
            'total_registrations' => (int) ($totals->registrations ?? 0),
            'submitted_registrations' => (int) ($totals->submitted ?? 0),
            'accepted_registrations' => (int) ($totals->accepted ?? 0),
            'completed_registrations' => (int) ($totals->completed ?? 0),
            'total_applicants' => (int) ($totals->applicants ?? 0),
            'total_parent_accounts' => (int) ($totals->parent_accounts ?? 0),
            'total_documents' => $documents['total_active'],
            'total_invoices' => $invoices['total'],
            'total_selection_participants' => (int) (clone $this->reports->selectionScope($f))->count(),
            'by_status' => $this->reports->completeMap(
                $this->reports->groupedRegistrationCount($f, 'r.status'),
                PpdbRegistration::STATUSES
            ),
            'by_payment_status' => $this->reports->completeMap(
                $this->reports->groupedRegistrationCount($f, 'r.payment_status'),
                PpdbRegistration::PAYMENT_STATUSES
            ),
            'by_document_status' => $this->reports->completeMap(
                $this->reports->groupedRegistrationCount($f, 'r.document_status'),
                PpdbRegistration::DOCUMENT_STATUSES
            ),
            'by_selection_result' => $this->selectionResultMap(
                $this->reports->groupedRegistrationCount($f, "coalesce(r.selection_result, 'none')")
            ),
            'applicants' => $this->applicantStatistics($f),
            'parent_accounts' => $this->parentAccountStatistics($f),
            'documents' => $documents,
            'invoices' => $invoices,
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    protected function applicantStatistics(array $f): array
    {
        $total = (int) (clone $this->reports->applicantScope($f))->count();

        $gender = (clone $this->reports->applicantScope($f))
            ->selectRaw("coalesce(gd.name, 'Tidak diketahui') as bucket, count(*) as total")
            ->groupBy('bucket')
            ->orderByDesc('total')
            ->get();

        return [
            'total' => $total,
            'by_status' => $this->reports->completeMap(
                $this->reports->groupedScopeCount(fn () => $this->reports->applicantScope($f), 'a.status'),
                PpdbApplicant::STATUSES
            ),
            'by_gender' => $this->shareList(
                $gender->mapWithKeys(static fn ($row) => [(string) $row->bucket => (int) $row->total])->all(),
                $total,
                'gender'
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    protected function parentAccountStatistics(array $f): array
    {
        $byStatus = $this->reports->groupedScopeCount(fn () => $this->reports->parentAccountScope($f), 'pa.account_status');

        $registered = (clone $this->reports->registrationQuery($f))
            ->selectRaw('count(distinct r.parent_account_id) as total')
            ->first();

        return [
            'total' => array_sum($byStatus),
            'with_registration' => (int) ($registered->total ?? 0),
            'by_status' => $this->reports->completeMap($byStatus, PpdbParentAccount::STATUSES),
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    protected function documentStatistics(array $f): array
    {
        $row = (clone $this->reports->documentUploadScope($f))
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when d.status in ('uploaded', 'under_review') then 1 else 0 end) as awaiting_review")
            ->selectRaw("sum(case when d.status = 'approved' then 1 else 0 end) as approved")
            ->selectRaw("sum(case when d.status = 'rejected' then 1 else 0 end) as rejected")
            ->first();

        return [
            'total_active' => (int) ($row->total ?? 0),
            'awaiting_review' => (int) ($row->awaiting_review ?? 0),
            'approved' => (int) ($row->approved ?? 0),
            'rejected' => (int) ($row->rejected ?? 0),
            'by_status' => $this->reports->completeMap(
                $this->reports->groupedScopeCount(fn () => $this->reports->documentUploadScope($f), 'd.status'),
                PpdbApplicantDocument::STATUSES
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    protected function invoiceStatistics(array $f): array
    {
        $row = (clone $this->reports->invoiceScope($f))
            ->selectRaw('count(*) as total')
            ->selectRaw('count(distinct i.registration_id) as registrations')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'registrations' => (int) ($row->registrations ?? 0),
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    protected function selectionResultMap(array $counts): array
    {
        $map = [];

        foreach (['passed', 'failed', 'waitlisted', 'none'] as $key) {
            $map[$key] = (int) ($counts[$key] ?? 0);
        }

        return $map;
    }

    // ==================================================================
    // 2. CORONG
    // ==================================================================

    /**
     * Satu query agregat untuk seluruh tahap corong.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    protected function funnel(array $f): array
    {
        $columns = [];

        foreach (self::FUNNEL_STEPS as $index => $step) {
            $columns[] = "sum(case when {$step['expr']} then 1 else 0 end) as step_{$index}";
        }

        $row = (clone $this->reports->registrationQuery($f))->selectRaw(implode(', ', $columns))->first();

        $start = (int) ($row->{'step_0'} ?? 0);
        $previous = null;
        $steps = [];

        foreach (self::FUNNEL_STEPS as $index => $step) {
            $count = (int) ($row->{"step_{$index}"} ?? 0);

            $steps[] = [
                'key' => $step['key'],
                'label' => $step['label'],
                'basis' => $step['basis'],
                'count' => $count,
                'drop_from_previous' => max(0, (int) ($previous ?? $count) - $count),
                'percent_of_start' => $this->rate($count, $start),
                'conversion_percent' => $previous === null ? 100.0 : $this->rate($count, $previous),
            ];

            $previous = $count;
        }

        return [
            'basis' => 'corong kumulatif: setiap tahap menghitung registrasi yang PERNAH mencapai tahap itu',
            'total' => $start,
            'overall_conversion_percent' => $this->rate(
                (int) ($row->{'step_'.(count(self::FUNNEL_STEPS) - 1)} ?? 0),
                $start
            ),
            'steps' => $steps,
        ];
    }

    // ==================================================================
    // 3. KEUANGAN
    // ==================================================================

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    protected function financial(array $f): array
    {
        $today = now()->toDateString();

        $totals = (clone $this->reports->invoiceScope($f))
            ->selectRaw('count(*) as invoices')
            ->selectRaw('coalesce(sum(i.amount), 0) as gross')
            ->selectRaw('coalesce(sum(i.discount_amount), 0) as discount')
            ->selectRaw('coalesce(sum(i.total_amount), 0) as billed')
            ->selectRaw("coalesce(sum(case when i.status not in ('cancelled', 'refunded') then i.total_amount else 0 end), 0) as net_billed")
            ->selectRaw('coalesce(sum(i.paid_amount), 0) as recorded_paid')
            ->selectRaw('coalesce(sum(case when i.total_amount > i.paid_amount then i.total_amount - i.paid_amount else 0 end), 0) as outstanding')
            ->selectRaw("sum(case when i.status = 'paid' then 1 else 0 end) as paid_invoices")
            ->selectRaw("sum(case when i.status in ('unpaid', 'pending') then 1 else 0 end) as open_invoices")
            ->selectRaw("sum(case when i.status in ('unpaid', 'pending', 'failed', 'expired') and i.due_date is not null and i.due_date < ? then 1 else 0 end) as overdue_invoices", [$today])
            ->first();

        $payments = (clone $this->reports->invoiceScope($f))
            ->join('ppdb_payments as p', 'p.invoice_id', '=', 'i.id')
            ->selectRaw('p.status as bucket, count(*) as total, coalesce(sum(p.amount), 0) as amount')
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $billed = round((float) ($totals->net_billed ?? 0), 2);
        $collected = round((float) ($payments->get('paid')->amount ?? 0), 2);

        return [
            'currency' => 'IDR',
            'invoice_count' => (int) ($totals->invoices ?? 0),
            'registration_count' => (int) (clone $this->reports->invoiceScope($f))
                ->selectRaw('count(distinct i.registration_id) as total')
                ->first()->total ?? 0,
            'gross_amount' => round((float) ($totals->gross ?? 0), 2),
            'discount_amount' => round((float) ($totals->discount ?? 0), 2),
            'billed_amount' => round((float) ($totals->billed ?? 0), 2),
            'net_billed_amount' => $billed,
            'collected_amount' => $collected,
            'outstanding_amount' => round((float) ($totals->outstanding ?? 0), 2),
            'awaiting_verification_amount' => round((float) ($payments->get('pending')->amount ?? 0), 2),
            'refunded_amount' => round((float) ($payments->get('refunded')->amount ?? 0), 2),
            'failed_amount' => round((float) ($payments->get('failed')->amount ?? 0), 2),
            'collection_percent' => $this->rate($collected, $billed),
            'average_invoice_amount' => $totals->invoices > 0
                ? round((float) ($totals->billed ?? 0) / (int) $totals->invoices, 2)
                : 0.0,
            'paid_invoice_count' => (int) ($totals->paid_invoices ?? 0),
            'open_invoice_count' => (int) ($totals->open_invoices ?? 0),
            'overdue_invoice_count' => (int) ($totals->overdue_invoices ?? 0),
            'by_invoice_status' => $this->invoiceStatusBreakdown($f),
            'by_payment_status' => $this->reports->completeMap(
                $payments->mapWithKeys(static fn ($row, $key) => [(string) $key => (int) $row->total])->all(),
                PpdbInvoice::STATUSES
            ),
            'by_channel' => $this->paymentSplit($f, 'p.method', false),
            'by_payment_method' => $this->paymentSplit($f, "coalesce(pm.name, 'Tidak diketahui')", true),
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, array{count:int, billed:float, collected:float, outstanding:float}>
     */
    protected function invoiceStatusBreakdown(array $f): array
    {
        $rows = (clone $this->reports->invoiceScope($f))
            ->selectRaw('i.status as bucket, count(*) as total')
            ->selectRaw('coalesce(sum(i.total_amount), 0) as billed')
            ->selectRaw('coalesce(sum(i.paid_amount), 0) as paid')
            ->selectRaw('coalesce(sum(case when i.total_amount > i.paid_amount then i.total_amount - i.paid_amount else 0 end), 0) as outstanding')
            ->groupBy('bucket')
            ->get();

        $breakdown = [];

        foreach (PpdbInvoice::STATUSES as $status) {
            $breakdown[$status] = [
                'count' => 0,
                'billed' => 0.0,
                'collected' => 0.0,
                'outstanding' => 0.0,
            ];
        }

        foreach ($rows as $row) {
            $breakdown[(string) $row->bucket] = [
                'count' => (int) $row->total,
                'billed' => round((float) $row->billed, 2),
                'collected' => round((float) $row->paid, 2),
                'outstanding' => round((float) $row->outstanding, 2),
            ];
        }

        return $breakdown;
    }

    /**
     * Rincian pembayaran LUNAS per kanal/metode, lengkap dengan porsinya.
     *
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    protected function paymentSplit(array $f, string $expression, bool $byMethod): array
    {
        $query = (clone $this->reports->invoiceScope($f))
            ->join('ppdb_payments as p', 'p.invoice_id', '=', 'i.id')
            ->where('p.status', 'paid');

        if ($byMethod) {
            $query->leftJoin('payment_methods as pm', 'pm.id', '=', 'p.payment_method_id');
        }

        $rows = $query->selectRaw("{$expression} as bucket, count(*) as total, coalesce(sum(p.amount), 0) as amount")
            ->groupBy('bucket')
            ->orderByDesc('total')
            ->get();

        $collected = (float) $rows->sum('amount');
        $count = (int) $rows->sum('total');

        return $rows->map(function ($row) use ($collected, $count, $byMethod): array {
            $value = (string) ($row->bucket ?? '');
            $label = $value === '' ? 'Tidak diketahui' : $value;

            if (! $byMethod) {
                $label = PpdbReportService::LABELS['channel'][$value] ?? $label;
            }

            return [
                'key' => $value,
                'label' => $label,
                'payments' => (int) $row->total,
                'amount' => round((float) $row->amount, 2),
                'share_percent' => $count > 0 ? round((int) $row->total / $count * 100, 2) : 0.0,
                'amount_percent' => $this->rate((float) $row->amount, $collected),
            ];
        })->values()->all();
    }

    // ==================================================================
    // 4. DISTRIBUSI
    // ==================================================================

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    protected function distribution(array $f): array
    {
        $programs = $this->programDistribution($f);
        $waves = $this->waveDistribution($f);

        $totalQuota = array_sum(array_column($programs, 'quota'));
        $totalAccepted = array_sum(array_column($programs, 'accepted'));

        return [
            'total_quota' => (int) $totalQuota,
            'total_accepted' => (int) $totalAccepted,
            'filled_percent' => $this->rate($totalAccepted, (int) $totalQuota),
            'by_program' => $programs,
            'by_wave' => $waves,
            'by_gender' => $this->genderDistribution($f),
        ];
    }

    /**
     * Satu query group by per program, digabung dengan daftar program agar
     * program yang belum pelamar tetap terlihat kuotanya.
     *
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    protected function programDistribution(array $f): array
    {
        $query = $this->reports->applyFilters(DB::table('ppdb_registrations as r'), $f, 'r');

        $counts = $query
            ->selectRaw('r.program_id as id, count(*) as applications')
            ->selectRaw('count(distinct r.applicant_id) as applicants')
            ->selectRaw("count(distinct case when r.status in (".$this->acceptedList().") then r.applicant_id end) as accepted")
            ->groupBy('r.program_id')
            ->get()
            ->keyBy('id');

        $total = (int) $counts->sum('applications');

        return $this->masterRows(
            DB::table('ppdb_programs')
                ->when($f['period_id'] !== null, static fn (BaseBuilder $q) => $q->where('period_id', $f['period_id']))
                // Program yang difilter eksplisit adalah satu-satunya yang boleh
                // muncul: baris program lain bernilai nol akan membuat total kuota
                // distribusi bertentangan dengan statistik cakupan.
                ->when($f['program_id'] !== null, static fn (BaseBuilder $q) => $q->where('id', $f['program_id']))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'code', 'name', 'quota', 'status']),
            $counts,
            ['applications' => 'applications', 'applicants' => 'applicants', 'accepted' => 'accepted'],
            function (object $program, ?object $row) use ($total): array {
                $accepted = (int) ($row->accepted ?? 0);
                $quota = $program->quota === null ? null : (int) $program->quota;

                return [
                    'quota' => $quota ?? 0,
                    'has_quota' => $quota !== null && $quota > 0,
                    'remaining_quota' => $quota === null ? 0 : max(0, $quota - $accepted),
                    'filled_percent' => $quota === null || $quota === 0
                        ? 0.0
                        : $this->rate($accepted, $quota),
                    'share_percent' => $this->rate((int) ($row->applications ?? 0), $total),
                ];
            }
        );
    }

    /**
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    protected function waveDistribution(array $f): array
    {
        $query = $this->reports->applyFilters(DB::table('ppdb_registrations as r'), $f, 'r');

        $counts = $query
            ->selectRaw('r.wave_id as id, count(*) as applications')
            ->selectRaw("count(distinct case when r.status in (".$this->acceptedList().") then r.id end) as accepted")
            ->groupBy('r.wave_id')
            ->get()
            ->keyBy('id');

        $total = (int) $counts->sum('applications');

        return $this->masterRows(
            DB::table('ppdb_waves')
                ->when($f['period_id'] !== null, static fn (BaseBuilder $q) => $q->where('period_id', $f['period_id']))
                ->when($f['wave_id'] !== null, static fn (BaseBuilder $q) => $q->where('id', $f['wave_id']))
                ->orderBy('priority')
                ->orderBy('id')
                ->get(['id', 'code', 'name', 'quota', 'status']),
            $counts,
            ['applications' => 'applications', 'accepted' => 'accepted'],
            function (object $wave, ?object $row) use ($total): array {
                $quota = $wave->quota === null ? null : (int) $wave->quota;

                return [
                    'quota' => $quota ?? 0,
                    'has_quota' => $quota !== null && $quota > 0,
                    'remaining_quota' => $quota === null ? 0 : max(0, $quota - (int) ($row->accepted ?? 0)),
                    'filled_percent' => $quota === null || $quota === 0
                        ? 0.0
                        : $this->rate((int) ($row->accepted ?? 0), $quota),
                    'share_percent' => $this->rate((int) ($row->applications ?? 0), $total),
                ];
            }
        );
    }

    /**
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    protected function genderDistribution(array $f): array
    {
        $total = (int) (clone $this->reports->applicantScope($f))->count();

        $rows = (clone $this->reports->applicantScope($f))
            ->selectRaw('a.gender_id as id, gd.name as label, count(*) as total')
            ->groupBy('a.gender_id', 'gd.name')
            ->orderByDesc('total')
            ->get();

        return $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'name' => (string) ($row->label ?? 'Tidak diketahui'),
            'applicants' => (int) $row->total,
            'share_percent' => $this->rate((int) $row->total, $total),
        ])->values()->all();
    }

    /**
     * Gabungkan daftar master (program/gelombang) dengan hasil group by.
     * Master hanya diambil kolom yang perlu dan jumlahnya belasan baris.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $masters
     * @param  \Illuminate\Support\Collection<string|int, object>  $counts
     * @param  array<string, string>  $fields  kunci hasil → kunci keluaran
     * @param  callable(object, ?object): array<string, mixed>  $extra
     * @return list<array<string, mixed>>
     */
    protected function masterRows($masters, $counts, array $fields, callable $extra): array
    {
        return $masters->map(static function (object $master) use ($counts, $fields, $extra): array {
            $row = $counts->get($master->id);

            $payload = [
                'id' => (int) $master->id,
                'code' => (string) $master->code,
                'name' => (string) $master->name,
                'status' => (string) $master->status,
            ];

            foreach ($fields as $source => $target) {
                $payload[$target] = (int) ($row->{$source} ?? 0);
            }

            return $payload + $extra($master, $row);
        })->values()->all();
    }

    /**
     * Daftar berlabel + porsinya dari map bucket → jumlah.
     *
     * @param  array<string, int>  $counts
     * @return list<array<string, mixed>>
     */
    protected function shareList(array $counts, int $total, string $labelKey): array
    {
        $rows = [];

        foreach ($counts as $label => $count) {
            $rows[] = [
                $labelKey => $label,
                'name' => $label,
                'total' => (int) $count,
                'share_percent' => $this->rate((int) $count, $total),
            ];
        }

        return $rows;
    }

    // ==================================================================
    // 5. AKSI TERTUNDA
    // ==================================================================

    /**
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    protected function pendingActions(array $f): array
    {
        return array_map(function (array $action) use ($f): array {
            $scoped = $this->overrideFilters($f, $action['filters']);

            return [
                'key' => $action['key'],
                'label' => $action['label'],
                'description' => $action['description'],
                'count' => $this->pendingCount($action['scope'], $scoped),
                'route_key' => $action['route_key'],
                'report_type' => $action['report_type'],
                'filters' => $this->activeFilterList($scoped, $action['filters']),
            ];
        }, self::PENDING_ACTIONS);
    }

    /**
     * @param  array<string, mixed>  $f
     */
    protected function pendingCount(string $scope, array $f): int
    {
        return match ($scope) {
            'document' => (int) $this->reports
                ->dateRange(
                    (clone $this->reports->documentUploadScope($f))
                        ->whereIn('d.status', $f['review_status']),
                    'coalesce(d.uploaded_at, d.created_at)',
                    $f
                )
                ->count(),
            'selection' => (int) (clone $this->reports->selectionScope($f))->count(),
            default => (int) (clone $this->reports->registrationQuery($f))->count(),
        };
    }

    /**
     * Pertahankan seluruh filter pemakai, tetapi persempit param status dengan
     * INTERSEKSI terhadap status aksi. Filter yang sudah bertabrakan menghasilkan
     * nol, bukan angka yang menyesatkan.
     *
     * @param  array<string, mixed>  $f
     * @param  array<string, list<string>>  $override
     * @return array<string, mixed>
     */
    protected function overrideFilters(array $f, array $override): array
    {
        $scoped = $f;

        foreach ($override as $param => $values) {
            $current = $f[$param] ?? [];
            $scoped[$param] = $current === [] ? $values : array_values(array_intersect($current, $values));
        }

        return $scoped;
    }

    /**
     * Param query siap pakai untuk deep-link.
     *
     * @param  array<string, mixed>  $f
     * @param  array<string, list<string>>  $override
     * @return array<string, mixed>
     */
    protected function activeFilterList(array $f, array $override): array
    {
        $params = array_values(array_unique([
            ...array_keys($override),
            'period_id', 'wave_id', 'program_id', 'date_from', 'date_to',
        ]));
        $payload = [];

        foreach ($params as $param) {
            $value = $f[$param] ?? null;

            if ($value === null || $value === [] || $value === '') {
                continue;
            }

            $payload[$param] = is_array($value) ? implode(',', $value) : $value;
        }

        return $payload;
    }

    // ==================================================================
    // 6. TREN
    // ==================================================================

    /**
     * Enam keranjang terakhir (mingguan/bulanan). Rentang waktu tren sengaja
     * mengabaikan `date_from`/`date_to` — tren selalu menampilkan periode
     * berjalan — sedangkan filter cakupan lainnya tetap berlaku.
     *
     * Keranjang dihitung dengan satu ekspresi CASE (ANSI, tanpa fungsi khusus
     * SQLite/MySQL) sehingga satu querygroup by cukup untuk semua bucket.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    protected function trend(array $f): array
    {
        $granularity = $f['granularity'] === 'weekly' ? 'weekly' : 'monthly';
        $buckets = $this->trendBuckets($granularity);

        $cases = '';
        foreach ($buckets as $bucket) {
            $cases .= " when r.created_at >= '{$bucket['start_sql']}' and r.created_at < '{$bucket['end_sql']}' then '{$bucket['key']}'";
        }

        $query = $this->reports
            ->applyFilters(DB::table('ppdb_registrations as r'), $f, 'r', false)
            ->where('r.created_at', '>=', $buckets[0]['start_sql'])
            ->where('r.created_at', '<', $buckets[count($buckets) - 1]['end_sql']);

        $rows = $query
            ->selectRaw("case{$cases} end as bucket, count(*) as total")
            ->selectRaw("sum(case when r.status <> 'draft' then 1 else 0 end) as submitted")
            ->selectRaw('sum(case when r.verified_at is not null then 1 else 0 end) as verified')
            ->selectRaw("sum(case when r.payment_status = 'paid' then 1 else 0 end) as paid")
            ->selectRaw("sum(case when r.status = 'completed' then 1 else 0 end) as completed")
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $points = array_map(static function (array $bucket) use ($rows): array {
            $row = $rows->get($bucket['key']);

            return [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'from' => $bucket['from'],
                'to' => $bucket['to'],
                'registrations' => (int) ($row->total ?? 0),
                'submitted' => (int) ($row->submitted ?? 0),
                'verified' => (int) ($row->verified ?? 0),
                'paid' => (int) ($row->paid ?? 0),
                'completed' => (int) ($row->completed ?? 0),
            ];
        }, $buckets);

        return [
            'granularity' => $granularity,
            'basis' => 'tanggal pendaftaran dibuat; rentang tren selalu '.$granularity.' '.self::TREND_BUCKETS.' keranjang terakhir',
            'bucket_count' => count($points),
            'total' => array_sum(array_column($points, 'registrations')),
            'points' => $points,
        ];
    }

    /**
     * Keranjang tren berurutan, berakhir pada keranjang berjalan.
     *
     * @return list<array<string, string>>
     */
    protected function trendBuckets(string $granularity): array
    {
        $now = CarbonImmutable::now();
        $start = $granularity === 'weekly'
            ? $now->startOfWeek()->subWeeks(self::TREND_BUCKETS - 1)
            : $now->startOfMonth()->subMonths(self::TREND_BUCKETS - 1);

        $buckets = [];

        for ($index = 0; $index < self::TREND_BUCKETS; $index++) {
            $from = $granularity === 'weekly' ? $start->addWeeks($index) : $start->addMonths($index);
            $to = $granularity === 'weekly' ? $from->addWeek() : $from->addMonthNoOverflow();

            $buckets[] = [
                'key' => $from->format($granularity === 'weekly' ? 'Y-m-d' : 'Y-m'),
                'label' => $this->bucketLabel($from, $to, $granularity),
                'from' => $from->toDateString(),
                'to' => $to->subSecond()->toDateString(),
                'start_sql' => $from->format('Y-m-d H:i:s'),
                'end_sql' => $to->format('Y-m-d H:i:s'),
            ];
        }

        return $buckets;
    }

    protected function bucketLabel(CarbonImmutable $from, CarbonImmutable $to, string $granularity): string
    {
        if ($granularity === 'monthly') {
            return self::MONTHS_ID[(int) $from->format('n')].' '.$from->format('Y');
        }

        return $from->format('d/m').' - '.$to->subSecond()->format('d/m');
    }

    // ==================================================================
    // PEMBANTU
    // ==================================================================

    /** Rasio persen dengan penjagaan bagi-nol. */
    protected function rate(int|float $part, int|float $total): float
    {
        if ((float) $total === 0.0) {
            return 0.0;
        }

        return round((float) $part / (float) $total * 100, 2);
    }

    protected function acceptedList(): string
    {
        return implode(', ', array_map(
            static fn (string $status) => "'".$status."'",
            PpdbReportService::ACCEPTED_STATUSES
        ));
    }

    protected function dateString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $timestamp = strtotime(trim((string) $value));

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
