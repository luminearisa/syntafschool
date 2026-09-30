<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Modules\Supervisi\Models\SupActionItem;
use App\Modules\Supervisi\Models\SupActionPlan;
use App\Modules\Supervisi\Models\SupAppraisal;
use App\Modules\Supervisi\Models\SupCoachingSession;
use App\Modules\Supervisi\Models\SupDevelopment;
use App\Modules\Supervisi\Models\SupIndicator;
use App\Modules\Supervisi\Models\SupPipPlan;
use App\Modules\Supervisi\Models\SupProgram;
use App\Modules\Supervisi\Domain\Enums\IndicatorInputType;
use App\Modules\Supervisi\Models\SupVisit;
use App\Modules\Supervisi\Models\SupVisitAnswer;
use App\Modules\Supervisi\Models\SupVisitScore;
use App\Modules\Supervisi\Domain\Enums\ActionItemStatus;
use App\Modules\Supervisi\Domain\Enums\AppraisalStatus;
use Illuminate\Database\Eloquent\Builder;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Analitik supervisi (Fase 4).
 *
 * Satu tempat untuk seluruh angka yang dipakai laporan wajib Permendikbud
 * 15/2018 maupun pertanyaan pimpinan: sudahkah semua guru disupervisi, apakah
 * nilainya membaik antar periode, indikator mana yang lemah, apakah RTL
 * diselesaikan tepat waktu, dan apakah antar supervisor menilai dengan ukuran
 * yang sama.
 *
 * Seluruh metode menerima jendela waktu (`from`/`to`) sehingga hasilnya dapat
 * dibandingkan antar semester/tahun tanpa mengubah kode.
 */
class AnalyticsService
{
    /** Ambang indikator lemah: rata-rata persen di bawah ini dianggap perlu perbaikan. */
    public const WEAK_INDICATOR_PERCENT = 75.0;

    /** Nilai akhir di bawah ini wajib punya rencana perbaikan kinerja (PIP). */
    public const PIP_THRESHOLD = 76.0;

    /** @return array<string, mixed> */
    public function overview(CarbonInterface|string|null $from = null, CarbonInterface|string|null $to = null, ?DataScope $scope = null): array
    {
        [$from, $to] = $this->window($from, $to);

        $scope ??= DataScope::school();

        // Pegawai biasa: hanya datanya sendiri. Ringkasan agregat sekolah
        // ditambahkan bila sekolah mengizinkannya (blueprint §11.6).
        if ($scope->isSelf()) {
            return $this->personalOverview($from, $to, $scope);
        }

        return [
            'window' => $this->windowPayload($from, $to),
            'scope' => $scope->payload(),
            'coverage' => $this->coverage($from, $to),
            'visits' => $this->visitStats($from, $to),
            'scores' => $this->scoreStats($from, $to),
            'sections' => $this->sectionStats($from, $to),
            'weak_indicators' => $this->indicatorStats($from, $to, 10),
            'trend' => $this->trend($from, $to),
            'follow_up' => $this->followUpStats($from, $to),
            'appraisals' => $this->appraisalStats($from, $to),
            'growth' => $this->growthStats($from, $to),
            'supervisors' => $this->supervisorStats($from, $to),
        ];
    }

    /**
     * Dasbor pribadi: kegiatan, nilai, RTL, penilaian, dan pengembangan **milik
     * satu pegawai**, baik sebagai yang disupervisi maupun yang menyupervisi.
     *
     * Nama rekan sejawat tidak pernah muncul di sini; ringkasan agregat sekolah
     * (sebaran nilai, indikator lemah) hanya disertakan bila sekolah
     * mengizinkan lewat pengaturan `peer_summary_enabled`.
     *
     * @return array<string, mixed>
     */
    public function personalOverview(CarbonInterface $from, CarbonInterface $to, DataScope $scope): array
    {
        $employeeId = $scope->employeeId;

        if ($employeeId === null) {
            return [
                'window' => $this->windowPayload($from, $to),
                'scope' => $scope->payload(),
                'coverage' => [
                    'total_employees' => 0, 'supervised' => 0, 'not_supervised' => 0, 'percent' => null,
                    'teacher_total' => 0, 'teacher_supervised' => 0, 'staff_total' => 0, 'staff_supervised' => 0,
                    'unsupervised' => [],
                ],
                'visits' => [
                    'total' => 0, 'finalized' => 0, 'finalized_on_time_percent' => null, 'avg_days_to_finalize' => null,
                    'by_status' => [], 'by_kind' => [], 'planned_target' => null, 'plan_realization_percent' => null,
                    'with_dispute' => 0, 'reopened' => 0,
                ],
                'scores' => ['count' => 0, 'average' => null, 'median' => null, 'lowest' => null, 'highest' => null, 'distribution' => []],
                'sections' => [],
                'weak_indicators' => [],
                'trend' => [],
                'follow_up' => [
                    'items_total' => 0, 'items_done' => 0, 'items_verified' => 0, 'items_overdue' => 0,
                    'completion_percent' => null, 'verified_percent' => null, 'on_time_percent' => null,
                    'avg_days_to_finish' => null, 'plans_by_status' => [],
                ],
                'appraisals' => [
                    'total' => 0, 'published' => 0, 'average' => null, 'needs_pip' => 0, 'needs_pip_rows' => [],
                    'pip_total' => 0, 'pip_active' => 0, 'pip_closed' => 0, 'pip_outcomes' => [],
                    'pip_acknowledged_percent' => null, 'by_predicate' => [],
                ],
                'growth' => [
                    'total_hours' => 0, 'entries' => 0, 'certified' => 0, 'cost' => 0,
                    'hours_by_kind' => [], 'hours_per_employee' => null,
                    'coaching_sessions' => 0, 'coaching_open' => 0, 'coaching_follow_up_due' => 0,
                ],
                'my_supervisors' => [],
                'message' => 'Akun ini belum tertaut ke data pegawai, sehingga tidak ada hasil supervisi yang dapat ditampilkan.',
            ];
        }

        // Kegiatan yang melibatkan saya (sebagai pelaksana maupun yang disupervisi).
        $mine = fn () => SupVisit::query()->where(function (Builder $query) use ($employeeId): void {
            $query->where('supervisor_employee_id', $employeeId)->orWhere('supervisee_employee_id', $employeeId);
        });

        $all = $mine()->whereBetween('created_at', [$from, $to]);
        $finalized = $mine()->whereNotNull('finalized_at')->whereBetween('finalized_at', [$from, $to]);

        $byStatus = (clone $all)->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status')->all();
        $byKind = (clone $all)->select('activity_kind', DB::raw('count(*) as total'))->groupBy('activity_kind')->pluck('total', 'activity_kind')->all();

        $asSupervisee = (clone $finalized)->where('supervisee_employee_id', $employeeId)->count();
        $finalizedCount = (clone $finalized)->count();
        $onTime = (clone $finalized)->whereColumn('finalized_at', '<=', 'observed_at')->count();

        $durations = (clone $finalized)->whereNotNull('observed_at')->get(['observed_at', 'finalized_at'])
            ->map(fn (SupVisit $visit) => $visit->finalized_at->diffInHours($visit->observed_at) / 24)
            ->filter(fn (float $days) => $days >= 0);

        $finalScores = (clone $finalized)->whereNotNull('final_score')->pluck('final_score')
            ->map(fn ($score) => (float) $score);

        $bands = [];
        foreach ($finalScores as $score) {
            $band = match (true) {
                $score >= 91 => 'SANGAT_BAIK',
                $score >= 76 => 'BAIK',
                $score >= 61 => 'CUKUP',
                default => 'PERLU_PERBAIKAN',
            };
            $bands[$band] ??= ['count' => 0, 'total' => 0.0];
            $bands[$band]['count']++;
            $bands[$band]['total'] += $score;
        }

        [$visitFrom, $visitTo] = [$from, $to];

        // RTL: butir yang menjadi tanggung jawab saya atau milik rencana saya.
        $items = SupActionItem::query()
            ->with('plan:id,title,supervisee_employee_id')
            ->where(function (Builder $query) use ($employeeId): void {
                $query->where('owner_employee_id', $employeeId)
                    ->orWhereHas('plan', fn (Builder $inner) => $inner->where('supervisee_employee_id', $employeeId));
            })
            ->get();

        $done = $items->whereIn('status', [ActionItemStatus::DONE, ActionItemStatus::VERIFIED]);

        // Penilaian kinerja milik saya.
        $appraisals = SupAppraisal::query()
            ->where('supervisee_employee_id', $employeeId)
            ->whereBetween('period_start', [$visitFrom->toDateString(), $visitTo->toDateString()])
            ->get();

        $published = $appraisals->where('status', AppraisalStatus::PUBLISHED);
        $scores = $published->pluck('final_score')->filter(fn ($score) => $score !== null)->map(fn ($score) => (float) $score);

        $pips = SupPipPlan::query()->where('supervisee_employee_id', $employeeId)->get();

        $developments = SupDevelopment::query()
            ->where('supervisee_employee_id', $employeeId)
            ->whereBetween('started_at', [$visitFrom->toDateString(), $visitTo->toDateString()])
            ->get();

        $coachings = SupCoachingSession::query()
            ->where(function (Builder $query) use ($employeeId): void {
                $query->where('supervisee_employee_id', $employeeId)->orWhere('supervisor_employee_id', $employeeId);
            })
            ->get();

        // Siapa yang menyupervisi saya (nama atasan sendiri bukan rahasia bagi
        // pegawai yang bersangkutan) — bukan peringkat rekan sejawat.
        $mySupervisors = SupVisit::query()
            ->where('supervisee_employee_id', $employeeId)
            ->whereNotNull('final_score')
            ->whereBetween('finalized_at', [$from, $to])
            ->select('supervisor_employee_id', DB::raw('count(*) as visits'), DB::raw('avg(final_score) as average_score'), DB::raw('min(final_score) as lowest_score'), DB::raw('max(final_score) as highest_score'))
            ->groupBy('supervisor_employee_id')
            ->get();

        $supervisorNames = $this->employeeNames($mySupervisors->pluck('supervisor_employee_id')->all());

        $payload = [
            'window' => $this->windowPayload($from, $to),
            'scope' => $scope->payload(),
            'coverage' => [
                'total_employees' => 1,
                'supervised' => $asSupervisee > 0 ? 1 : 0,
                'not_supervised' => $asSupervisee > 0 ? 0 : 1,
                'percent' => $asSupervisee > 0 ? 100.0 : 0.0,
                'teacher_total' => 0, 'teacher_supervised' => 0, 'staff_total' => 0, 'staff_supervised' => 0,
                'unsupervised' => [],
            ],
            'visits' => [
                'total' => array_sum($byStatus),
                'finalized' => $finalizedCount,
                'finalized_on_time_percent' => $finalizedCount > 0 ? round($onTime / $finalizedCount * 100, 2) : null,
                'avg_days_to_finalize' => $durations->isEmpty() ? null : round((float) $durations->avg(), 1),
                'by_status' => $byStatus,
                'by_kind' => $byKind,
                'planned_target' => null,
                'plan_realization_percent' => null,
                'with_dispute' => (clone $finalized)->whereHas('disputes')->count(),
                'reopened' => (clone $finalized)->where('revision', '>', 0)->count(),
            ],
            'scores' => [
                'count' => $finalScores->count(),
                'average' => $finalScores->isEmpty() ? null : round((float) $finalScores->avg(), 2),
                'median' => $finalScores->isEmpty() ? null : round((float) $finalScores->sort()->values()->get(intdiv($finalScores->count(), 2)), 2),
                'lowest' => $finalScores->isEmpty() ? null : round((float) $finalScores->min(), 2),
                'highest' => $finalScores->isEmpty() ? null : round((float) $finalScores->max(), 2),
                'distribution' => collect($bands)->map(fn (array $band) => [
                    'count' => $band['count'],
                    'average' => round($band['total'] / max($band['count'], 1), 2),
                ])->all(),
            ],
            'sections' => [],
            'weak_indicators' => [],
            'trend' => [],
            'follow_up' => [
                'items_total' => $items->count(),
                'items_done' => $done->count(),
                'items_verified' => $items->where('status', ActionItemStatus::VERIFIED)->count(),
                'items_overdue' => $items->filter(fn (SupActionItem $item) => $item->due_date !== null
                    && $item->due_date->isPast()
                    && ! in_array($item->status, [ActionItemStatus::DONE, ActionItemStatus::VERIFIED, ActionItemStatus::DROPPED], true))->count(),
                'completion_percent' => $items->count() > 0 ? round($done->count() / $items->count() * 100, 2) : null,
                'verified_percent' => $items->count() > 0 ? round($items->where('status', ActionItemStatus::VERIFIED)->count() / $items->count() * 100, 2) : null,
                'on_time_percent' => $done->isEmpty() ? null : round(
                    $done->filter(fn (SupActionItem $item) => $item->completed_at !== null && $item->due_date !== null
                        && $item->completed_at->lessThanOrEqualTo($item->due_date))->count() / $done->count() * 100,
                    2,
                ),
                'avg_days_to_finish' => $done->whereNotNull('completed_at')->isEmpty() ? null : round((float) $done->whereNotNull('completed_at')
                    ->map(fn (SupActionItem $item) => $item->completed_at->diffInHours($item->created_at) / 24)->avg(), 1),
                'plans_by_status' => SupActionPlan::query()
                    ->where(function (Builder $query) use ($employeeId): void {
                        $query->where('supervisee_employee_id', $employeeId)
                            ->orWhereHas('items', fn (Builder $inner) => $inner->where('owner_employee_id', $employeeId));
                    })
                    ->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status')->all(),
            ],
            'appraisals' => [
                'total' => $appraisals->count(),
                'published' => $published->count(),
                'average' => $scores->isEmpty() ? null : round((float) $scores->avg(), 2),
                'needs_pip' => $published->filter(fn (SupAppraisal $appraisal) => $appraisal->final_score !== null
                    && (float) $appraisal->final_score < 76
                    && ! $pips->contains(fn (SupPipPlan $plan) => (int) $plan->appraisal_id === (int) $appraisal->id))->count(),
                'needs_pip_rows' => $published
                    ->filter(fn (SupAppraisal $appraisal) => $appraisal->final_score !== null && (float) $appraisal->final_score < 76)
                    ->map(fn (SupAppraisal $appraisal) => [
                        'id' => $appraisal->id,
                        'appraisal_type' => (string) ($appraisal->appraisal_type?->value ?? ''),
                        'supervisee' => null, // milik pemakai sendiri
                        'position' => null,
                        'final_score' => $appraisal->final_score === null ? null : (float) $appraisal->final_score,
                        'grade_label' => $appraisal->grade_label,
                    ])->values()->all(),
                'pip_total' => $pips->count(),
                'pip_active' => $pips->whereIn('status', ['ACTIVE', 'ACKNOWLEDGED', 'IN_PROGRESS'])->count(),
                'pip_closed' => $pips->where('status', 'CLOSED')->count(),
                'pip_outcomes' => $pips->filter(fn (SupPipPlan $plan) => $plan->outcome !== null)->groupBy(fn (SupPipPlan $plan) => (string) $plan->outcome->value)->map->count()->all(),
                'pip_acknowledged_percent' => $pips->isEmpty() ? null : round($pips->whereNotNull('acknowledged_at')->count() / $pips->count() * 100, 2),
                'by_predicate' => [],
            ],
            'growth' => [
                'total_hours' => round((float) $developments->sum('hours'), 1),
                'entries' => $developments->count(),
                'certified' => $developments->whereNotNull('certificate_no')->count(),
                'cost' => round((float) $developments->sum('cost'), 2),
                'hours_by_kind' => $developments->groupBy(fn (SupDevelopment $development) => (string) ($development->kind?->value ?? 'LAINNYA'))
                    ->map(fn ($group) => ['count' => $group->count(), 'hours' => round((float) $group->sum('hours'), 1)])->all(),
                'hours_per_employee' => round((float) $developments->sum('hours'), 1),
                'coaching_sessions' => $coachings->count(),
                'coaching_open' => $coachings->where('status', '!=', 'CLOSED')->count(),
                'coaching_follow_up_due' => $coachings->filter(fn (SupCoachingSession $session) => $session->follow_up_at !== null
                    && $session->follow_up_at->isPast() && $session->status !== 'CLOSED')->count(),
            ],
            'supervisors' => $mySupervisors->map(fn ($row) => [
                'supervisor_employee_id' => (int) $row->supervisor_employee_id,
                'full_name' => $supervisorNames[(int) $row->supervisor_employee_id] ?? null,
                'visits' => (int) $row->visits,
                'average_score' => round((float) $row->average_score, 2),
                'lowest_score' => round((float) $row->lowest_score, 2),
                'highest_score' => round((float) $row->highest_score, 2),
                'deviation_from_school' => null,
                'leniency_index' => null,
                'needs_calibration' => false,
            ])->values()->all(),
        ];

        // Ringkasan agregat sekolah (tanpa nama siapa pun) — hanya bila sekolah
        // mengizinkannya.
        if ($scope->allowsPeerSummary()) {
            $payload['scores']['distribution'] = $this->scoreStats($from, $to)['distribution'];
            $payload['sections'] = $this->sectionStats($from, $to);
            $payload['weak_indicators'] = $this->indicatorStats($from, $to, 10);
        }

        return $payload;
    }

    /** Cakupan: berapa pegawai yang benar-benar disupervisi pada jendela waktu. */
    public function coverage(CarbonInterface $from, CarbonInterface $to): array
    {
        $positions = $this->superviseePool();

        $supervised = SupVisit::query()
            ->whereNotNull('finalized_at')
            ->whereBetween('finalized_at', [$from, $to])
            ->pluck('supervisee_employee_id')
            ->unique();

        $total = $positions->count();
        $done = $positions->whereIn('id', $supervised->all())->count();

        return [
            'total_employees' => $total,
            'supervised' => $done,
            'not_supervised' => max($total - $done, 0),
            'percent' => $total > 0 ? round($done / $total * 100, 2) : null,
            'teacher_total' => $positions->where('is_teacher', true)->count(),
            'teacher_supervised' => $positions->where('is_teacher', true)->whereIn('id', $supervised->all())->count(),
            'staff_total' => $positions->where('is_teacher', false)->count(),
            'staff_supervised' => $positions->where('is_teacher', false)->whereIn('id', $supervised->all())->count(),
            'unsupervised' => $positions->whereNotIn('id', $supervised->all())
                ->take(50)
                ->map(fn ($employee) => [
                    'id' => $employee->id,
                    'full_name' => $employee->full_name,
                    'position' => $employee->position,
                    'is_teacher' => (bool) $employee->is_teacher,
                ])->values()->all(),
        ];
    }

    /** Statistik kegiatan: dilaksanakan, dibatalkan, terlambat, menurut teknik. */
    public function visitStats(CarbonInterface $from, CarbonInterface $to): array
    {
        $visits = SupVisit::query()->whereBetween('created_at', [$from, $to]);

        $byStatus = (clone $visits)->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')->pluck('total', 'status')->all();

        $byKind = (clone $visits)->select('activity_kind', DB::raw('count(*) as total'))
            ->groupBy('activity_kind')->pluck('total', 'activity_kind')->all();

        $finalized = SupVisit::query()->whereNotNull('finalized_at')->whereBetween('finalized_at', [$from, $to])->count();

        $onTime = SupVisit::query()
            ->whereNotNull('finalized_at')
            ->whereBetween('finalized_at', [$from, $to])
            ->whereColumn('finalized_at', '<=', 'observed_at')
            ->count();

        $durations = SupVisit::query()
            ->whereNotNull('finalized_at')
            ->whereNotNull('observed_at')
            ->whereBetween('finalized_at', [$from, $to])
            ->get(['observed_at', 'finalized_at'])
            ->map(fn (SupVisit $visit) => $visit->finalized_at->diffInHours($visit->observed_at) / 24)
            ->filter(fn (float $days) => $days >= 0);

        $avgDays = $durations->isEmpty() ? null : round((float) $durations->avg(), 1);

        $planned = (int) SupProgram::query()
            ->whereBetween('starts_at', [$from->toDateString(), $to->toDateString()])
            ->with('targets')
            ->get()
            ->flatMap(fn (SupProgram $program) => $program->targets->pluck('planned_count'))
            ->filter()
            ->sum();

        return [
            'total' => array_sum($byStatus),
            'finalized' => $finalized,
            'finalized_on_time_percent' => $finalized > 0 ? round($onTime / $finalized * 100, 2) : null,
            'avg_days_to_finalize' => $avgDays,
            'by_status' => $byStatus,
            'by_kind' => $byKind,
            'planned_target' => $planned > 0 ? $planned : null,
            'plan_realization_percent' => $planned > 0 ? round($finalized / $planned * 100, 2) : null,
            'with_dispute' => SupVisit::query()->whereBetween('created_at', [$from, $to])->where('is_disputed', true)->count(),
            'reopened' => SupVisit::query()->whereBetween('created_at', [$from, $to])->where('revision', '>', 0)->count(),
        ];
    }

    /** Statistik nilai kegiatan: rata-rata, sebaran predikat, tertinggi/terendah. */
    public function scoreStats(CarbonInterface $from, CarbonInterface $to): array
    {
        $query = SupVisit::query()
            ->whereNotNull('finalized_at')
            ->whereBetween('finalized_at', [$from, $to])
            ->whereNotNull('final_score');

        $distribution = (clone $query)->select('grade_band', DB::raw('count(*) as total'), DB::raw('avg(final_score) as rata'))
            ->groupBy('grade_band')->get()
            ->mapWithKeys(fn ($row) => [$row->grade_band ?? 'TANPA_PREDIKAT' => [
                'count' => (int) $row->total,
                'average' => round((float) $row->rata, 2),
            ]])->all();

        $average = (clone $query)->avg('final_score');
        $median = (clone $query)->pluck('final_score')->sort()->values();

        return [
            'count' => (clone $query)->count(),
            'average' => $average === null ? null : round((float) $average, 2),
            'median' => $median->isEmpty() ? null : round((float) $median->median(), 2),
            'lowest' => $median->isEmpty() ? null : round((float) $median->min(), 2),
            'highest' => $median->isEmpty() ? null : round((float) $median->max(), 2),
            'distribution' => $distribution,
        ];
    }

    /** Rata-rata per bagian instrumen (mis. perencanaan, pelaksanaan, penilaian). */
    public function sectionStats(CarbonInterface $from, CarbonInterface $to): array
    {
        return SupVisitScore::query()
            ->join('sup_visits', 'sup_visits.id', '=', 'sup_visit_scores.visit_id')
            ->join('sup_sections', 'sup_sections.id', '=', 'sup_visit_scores.section_id')
            ->whereNotNull('sup_visits.finalized_at')
            ->whereBetween('sup_visits.finalized_at', [$from, $to])
            ->select(
                'sup_sections.title as section',
                DB::raw('count(*) as visits'),
                DB::raw('avg(sup_visit_scores.percent) as rata'),
                DB::raw('min(sup_visit_scores.percent) as terendah'),
            )
            ->groupBy('sup_sections.title')
            ->orderBy('rata')
            ->get()
            ->map(fn ($row) => [
                'section' => $row->section,
                'visits' => (int) $row->visits,
                'average_percent' => round((float) $row->rata, 2),
                'lowest_percent' => round((float) $row->terendah, 2),
            ])->all();
    }

    /**
     * Indikator terlemah: rata-rata jawaban (dalam persen dari skor maksimum)
     * beserta jumlah jawaban NA dan sebarannya. Dipakai menyusun program
     * pembinaan berikutnya.
     *
     * @return list<array<string, mixed>>
     */
    public function indicatorStats(CarbonInterface $from, CarbonInterface $to, int $limit = 10, bool $weakestFirst = true): array
    {
        // Agregat dihitung dulu tanpa memaksakan kolom skor maksimum: batas
        // skor tiap indikator berasal dari opsi rubrik (SCALE), rentang
        // angka (NUMBER), atau bobot ya/tidak (BOOLEAN) — bukan satu kolom.
        $aggregates = SupVisitAnswer::query()
            ->join('sup_visits', 'sup_visits.id', '=', 'sup_visit_answers.visit_id')
            ->whereNotNull('sup_visits.finalized_at')
            ->whereBetween('sup_visits.finalized_at', [$from, $to])
            ->where('sup_visit_answers.is_na', false)
            ->whereNotNull('sup_visit_answers.score')
            ->select(
                'sup_visit_answers.indicator_id',
                DB::raw('avg(sup_visit_answers.score) as rata'),
                DB::raw('min(sup_visit_answers.score) as terendah'),
                DB::raw('count(*) as jawaban'),
            )
            ->groupBy('sup_visit_answers.indicator_id')
            ->get();

        if ($aggregates->isEmpty()) {
            return [];
        }

        $indicators = SupIndicator::query()
            ->with(['section:id,title', 'options:id,indicator_id,score'])
            ->whereIn('id', $aggregates->pluck('indicator_id')->all())
            ->get()
            ->keyBy('id');

        $stats = $aggregates->map(function ($row) use ($indicators) {
            $indicator = $indicators[$row->indicator_id] ?? null;
            $maximum = $indicator === null ? null : self::maxScoreOf($indicator);

            if ($indicator === null || $maximum === null || $maximum <= 0) {
                return null;
            }

            $percent = (float) $row->rata / $maximum * 100;

            return [
                'indicator_id' => (int) $row->indicator_id,
                'code' => $indicator->code,
                'statement' => $indicator->statement,
                'section' => $indicator->section?->title,
                'max_score' => $maximum,
                'answers' => (int) $row->jawaban,
                'average_score' => round((float) $row->rata, 2),
                'average_percent' => round($percent, 2),
                'lowest_score' => (float) $row->terendah,
                'is_weak' => $percent < self::WEAK_INDICATOR_PERCENT,
            ];
        })->filter()->values();

        $sorted = $weakestFirst
            ? $stats->sortBy('average_percent')
            : $stats->sortByDesc('average_percent');

        return $limit > 0 ? $sorted->take($limit)->values()->all() : $sorted->values()->all();
    }

    /**
     * Skor maksimum satu indikator menurut tipe inputnya.
     *
     * SCALE → opsi rubrik tertinggi, NUMBER → `score_max`, BOOLEAN → bobot
     * tertinggi di antara ya/tidak/sebagian.
     */
    public static function maxScoreOf(SupIndicator $indicator): ?float
    {
        $options = $indicator->relationLoaded('options')
            ? $indicator->options->pluck('score')
            : $indicator->options()->pluck('score');

        $candidates = match ($indicator->input_type) {
            IndicatorInputType::BOOLEAN => array_filter([
                $indicator->score_yes,
                $indicator->score_no,
                $indicator->score_partial,
            ], fn ($value) => $value !== null),
            IndicatorInputType::NUMBER => array_filter([$indicator->score_max, $indicator->score_min], fn ($value) => $value !== null),
            default => $options->filter(fn ($value) => $value !== null)->all(),
        };

        if ($candidates === []) {
            return null;
        }

        return round((float) max($candidates), 2);
    }

    /** Tren rata-rata nilai per bulan pada jendela waktu (untuk grafik). */
    public function trend(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = SupVisit::query()
            ->whereNotNull('finalized_at')
            ->whereBetween('finalized_at', [$from, $to])
            ->get(['finalized_at', 'final_score'])
            ->groupBy(fn (SupVisit $visit) => $visit->finalized_at->format('Y-m'));

        return $rows->sortKeys()->map(function (Collection $group, string $period) {
            $scored = $group->pluck('final_score')->filter(fn ($value) => $value !== null);

            return [
                'period' => $period,
                'visits' => $group->count(),
                'average' => $scored->isEmpty() ? null : round((float) $scored->avg(), 2),
            ];
        })->values()->all();
    }

    /** Disiplin tindak lanjut: RTL tepat waktu, verifikasi, dan temuan berulang. */
    public function followUpStats(CarbonInterface $from, CarbonInterface $to): array
    {
        $items = SupActionItem::query()->whereBetween('created_at', [$from, $to]);
        $total = (clone $items)->count();
        $done = (clone $items)->whereIn('status', ['DONE', 'VERIFIED'])->count();
        $verified = (clone $items)->where('status', 'VERIFIED')->count();
        $overdue = (clone $items)->whereNotIn('status', ['DONE', 'VERIFIED', 'DROPPED'])
            ->whereNotNull('due_date')->whereDate('due_date', '<', now()->toDateString())->count();

        $onTime = (clone $items)->whereIn('status', ['DONE', 'VERIFIED'])
            ->whereNotNull('completed_at')->whereNotNull('due_date')
            ->whereColumn('completed_at', '<=', 'due_date')->count();

        $durations = (clone $items)->whereIn('status', ['DONE', 'VERIFIED'])
            ->whereNotNull('completed_at')
            ->get(['created_at', 'completed_at'])
            ->map(fn (SupActionItem $item) => $item->completed_at->diffInHours($item->created_at) / 24)
            ->filter(fn (float $days) => $days >= 0);

        $avgDays = $durations->isEmpty() ? null : round((float) $durations->avg(), 1);

        $plans = SupActionPlan::query()->whereBetween('created_at', [$from, $to])->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')->pluck('total', 'status')->all();

        return [
            'items_total' => $total,
            'items_done' => $done,
            'items_verified' => $verified,
            'items_overdue' => $overdue,
            'completion_percent' => $total > 0 ? round($done / $total * 100, 2) : null,
            'verified_percent' => $total > 0 ? round($verified / $total * 100, 2) : null,
            'on_time_percent' => $done > 0 ? round($onTime / $done * 100, 2) : null,
            'avg_days_to_finish' => $avgDays,
            'plans_by_status' => $plans,
        ];
    }

    /**
     * Rekap penilaian kinerja: predikat, angka kredit, dan kebutuhan PIP.
     *
     * `needs_pip` sengaja berupa *daftar* dokumen bernilai di bawah ambang yang
     * belum punya PIP — inilah yang dapat ditindaklanjuti kepala sekolah,
     * bukan sekadar angka.
     */
    public function appraisalStats(CarbonInterface $from, CarbonInterface $to): array
    {
        $query = SupAppraisal::query()->whereBetween('created_at', [$from, $to]);

        $byPredicate = (clone $query)->whereNotNull('final_score')
            ->select('grade_label', DB::raw('count(*) as total'), DB::raw('avg(final_score) as rata'))
            ->groupBy('grade_label')->get()
            ->mapWithKeys(fn ($row) => [$row->grade_label ?? 'TANPA_PREDIKAT' => [
                'count' => (int) $row->total,
                'average' => round((float) $row->rata, 2),
            ]])->all();

        $pip = SupPipPlan::query()->whereBetween('created_at', [$from, $to]);

        $belowThreshold = (clone $query)
            ->whereNotNull('final_score')
            ->where('final_score', '<', self::PIP_THRESHOLD)
            ->whereNotIn('id', SupPipPlan::query()->whereNotNull('appraisal_id')->select('appraisal_id'))
            ->with('supervisee:id,full_name,position')
            ->orderBy('final_score')
            ->get();

        $average = (clone $query)->whereNotNull('final_score')->avg('final_score');
        $pipTotal = (clone $pip)->count();

        return [
            'total' => (clone $query)->count(),
            'published' => (clone $query)->whereNotNull('published_at')->count(),
            'average' => $average === null ? null : round((float) $average, 2),
            'by_predicate' => $byPredicate,
            'pip_threshold' => self::PIP_THRESHOLD,
            'needs_pip' => $belowThreshold->count(),
            'needs_pip_rows' => $belowThreshold->take(50)->map(fn (SupAppraisal $appraisal) => [
                'id' => $appraisal->id,
                'appraisal_type' => (string) ($appraisal->appraisal_type?->value ?? ''),
                'supervisee' => $appraisal->supervisee?->full_name,
                'position' => $appraisal->supervisee?->position,
                'final_score' => $appraisal->final_score,
                'grade_label' => $appraisal->grade_label,
            ])->values()->all(),
            'pip_total' => $pipTotal,
            'pip_active' => (clone $pip)->where('status', 'ACTIVE')->count(),
            'pip_closed' => (clone $pip)->where('status', 'CLOSED')->count(),
            'pip_outcomes' => SupPipPlan::query()->whereBetween('created_at', [$from, $to])->whereNotNull('outcome')
                ->select('outcome', DB::raw('count(*) as total'))->groupBy('outcome')->pluck('total', 'outcome')->all(),
            'pip_acknowledged_percent' => $pipTotal > 0
                ? round((clone $pip)->whereNotNull('acknowledged_at')->count() / $pipTotal * 100, 2)
                : null,
        ];
    }

    /** Pengembangan kompetensi & coaching: jam pelatihan dan kepatuhan tindak lanjut. */
    public function growthStats(CarbonInterface $from, CarbonInterface $to): array
    {
        $developments = SupDevelopment::query()->whereBetween('created_at', [$from, $to]);

        $hoursByKind = (clone $developments)->select('kind', DB::raw('sum(hours) as jam'), DB::raw('count(*) as jumlah'))
            ->groupBy('kind')->get()
            ->mapWithKeys(fn ($row) => [$row->kind => [
                'count' => (int) $row->jumlah,
                'hours' => round((float) $row->jam, 1),
            ]])->all();

        $sessions = SupCoachingSession::query()->whereBetween('created_at', [$from, $to]);

        return [
            'total_hours' => round((float) (clone $developments)->sum('hours'), 1),
            'entries' => (clone $developments)->count(),
            'certified' => (clone $developments)->whereNotNull('certificate_no')->count(),
            'cost' => round((float) (clone $developments)->sum('cost'), 2),
            'hours_by_kind' => $hoursByKind,
            'hours_per_employee' => ($count = (clone $developments)->distinct('supervisee_employee_id')->count('supervisee_employee_id')) > 0
                ? round((float) (clone $developments)->sum('hours') / $count, 1)
                : null,
            'coaching_sessions' => (clone $sessions)->count(),
            'coaching_open' => (clone $sessions)->where('status', 'OPEN')->count(),
            'coaching_follow_up_due' => (clone $sessions)->where('status', 'OPEN')
                ->whereNotNull('follow_up_at')->whereDate('follow_up_at', '<=', now()->toDateString())->count(),
        ];
    }

    /**
     * Kinerja tiap supervisor: jumlah kegiatan, rata-rata nilai yang diberikan,
     * dan indeks kelonggaran (selisih rata-rata nilainya dari rata-rata sekolah).
     * Indeks besar menandakan penilai terlalu longgar/keras sehingga perlu
     * dikalibrasi.
     */
    public function supervisorStats(CarbonInterface $from, CarbonInterface $to): array
    {
        $schoolAverage = SupVisit::query()->whereNotNull('finalized_at')->whereNotNull('final_score')
            ->whereBetween('finalized_at', [$from, $to])->avg('final_score');

        $rows = SupVisit::query()
            ->whereNotNull('finalized_at')->whereNotNull('final_score')
            ->whereBetween('finalized_at', [$from, $to])
            ->select(
                'supervisor_employee_id',
                DB::raw('count(*) as kegiatan'),
                DB::raw('avg(final_score) as rata'),
                DB::raw('min(final_score) as terendah'),
                DB::raw('max(final_score) as tertinggi'),
            )
            ->groupBy('supervisor_employee_id')
            ->orderBy('supervisor_employee_id')
            ->get();

        $names = $this->employeeNames($rows->pluck('supervisor_employee_id')->all());

        return $rows->map(function ($row) use ($schoolAverage, $names) {
            $average = round((float) $row->rata, 2);
            $deviation = $schoolAverage === null ? null : round($average - (float) $schoolAverage, 2);

            return [
                'supervisor_employee_id' => (int) $row->supervisor_employee_id,
                'full_name' => $names[$row->supervisor_employee_id] ?? null,
                'visits' => (int) $row->kegiatan,
                'average_score' => $average,
                'lowest_score' => round((float) $row->terendah, 2),
                'highest_score' => round((float) $row->tertinggi, 2),
                'deviation_from_school' => $deviation,
                'leniency_index' => $deviation === null ? null : round($deviation, 2),
                'needs_calibration' => $deviation !== null && abs($deviation) >= 10.0,
            ];
        })->all();
    }

    /** Daftar pegawai yang menjadi sasaran supervisi (guru + tendik aktif). */
    protected function superviseePool(): Collection
    {
        return DB::table('employees')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->select('id', 'full_name', 'position', 'is_teacher')
            ->orderBy('full_name')
            ->get();
    }

    /** @param list<int|string> $ids */
    protected function employeeNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('employees')->whereIn('id', $ids)->pluck('full_name', 'id')->all();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    protected function window(CarbonInterface|string|null $from, CarbonInterface|string|null $to): array
    {
        $start = $from === null ? now()->startOfYear() : Carbon::parse($from)->startOfDay();
        $end = $to === null ? now()->endOfYear() : Carbon::parse($to)->endOfDay();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end];
    }

    /** @return array<string, string> */
    protected function windowPayload(Carbon $from, Carbon $to): array
    {
        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'label' => $from->translatedFormat('d M Y').' – '.$to->translatedFormat('d M Y'),
        ];
    }

    /** Indikator pada instrumen versi tertentu (untuk kalibrasi). */
    public function indicatorsOfVersion(?int $instrumentVersionId): Collection
    {
        if ($instrumentVersionId === null) {
            return collect();
        }

        return SupIndicator::query()
            ->whereHas('section', fn ($query) => $query->where('instrument_version_id', $instrumentVersionId))
            ->with('section:id,title')
            ->orderBy('sort_order')
            ->get();
    }
}
