<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Modules\Supervisi\Application\Services\AppraisalCalculator;
use App\Modules\Supervisi\Application\Services\AppraisalService;
use App\Modules\Supervisi\Domain\Enums\AppraisalStatus;
use App\Modules\Supervisi\Models\SupAppraisal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Penilaian kinerja: PKG, PKG BK, NKKS, tinjauan pegawai, kuadran SKP, 360°. */
class SupAppraisalController extends SupController
{
    public function __construct(
        private readonly AppraisalService $service,
        private readonly AppraisalCalculator $calculator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $rows = $this->service->list($request->only([
            'type', 'status', 'employee_id', 'year', 'search', 'limit',
        ]), $this->actor($request));

        return $this->successResponse([
            'rows' => $rows->map(fn (SupAppraisal $appraisal) => $this->service->presentRow($appraisal))->all(),
            'summary' => $this->summary($rows),
            'types' => $this->calculator->defaultInstruments(),
        ], 'Daftar penilaian kinerja');
    }

    /** Acuan rumus supaya sekolah dapat memeriksa angka yang dipakai sistem. */
    public function reference(): JsonResponse
    {
        return $this->successResponse([
            'instruments' => $this->calculator->defaultInstruments(),
            'working_days' => AppraisalCalculator::WORKING_DAYS,
            'attendance_formula' => 'NPK = Nilai PKG × (100% − (hari tanpa keterangan ÷ hari kerja) × 100%)',
            'predicates' => AppraisalCalculator::PREDICATES,
            'quadrant_matrix' => AppraisalCalculator::DEFAULT_MATRIX,
            'rating_thresholds' => AppraisalCalculator::DEFAULT_THRESHOLDS,
            'source_weights' => $this->service->sourceWeights(),
            'notes' => [
                'PKG guru mapel/kelas: Σ 14 kompetensi (1–4) → maks 56 → ×100/56.',
                'PKG guru BK: Σ 17 kompetensi → maks 68 → ×100/68.',
                'NKKS: Σ 6 aspek → maks 24 → ×100/24.',
                'Sebutan & angka kredit: 91–100 Amat Baik 125% · 76–90 Baik 100% · 61–75 Cukup 75% · 51–60 Sedang 50% · ≤50 Kurang 25%.',
            ],
        ], 'Acuan perhitungan penilaian kinerja');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supervisee_employee_id' => ['required', 'integer', 'exists:employees,id'],
            'appraisal_type' => ['required', 'in:PKG,PKG_BK,NKKS,STAFF_REVIEW,SKP_PERIODIK,PIP'],
            'period_type' => ['nullable', 'in:BULANAN,TRIWULAN,SEMESTER,TAHUNAN'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'academic_year_id' => ['nullable', 'integer'],
            'instrument_version_id' => ['nullable', 'integer', 'exists:sup_instrument_versions,id'],
            'assessor_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'reviewer_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $appraisal = $this->service->create($data, $this->actor($request));

        return $this->successResponse($this->service->detail($appraisal, $this->actor($request)), 'Dokumen penilaian dibuat', 201);
    }

    public function show(Request $request, SupAppraisal $appraisal): JsonResponse
    {
        return $this->successResponse($this->service->detail($appraisal, $this->actor($request)), 'Rincian penilaian kinerja');
    }

    public function update(Request $request, SupAppraisal $appraisal): JsonResponse
    {
        $data = $request->validate([
            'period_type' => ['nullable', 'in:BULANAN,TRIWULAN,SEMESTER,TAHUNAN'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
            'assessor_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'reviewer_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $updated = $this->service->update($appraisal, $data, $this->actor($request));

        return $this->successResponse($this->service->detail($updated, $this->actor($request)), 'Dokumen penilaian diperbarui');
    }

    public function saveScores(Request $request, SupAppraisal $appraisal): JsonResponse
    {
        $data = $request->validate([
            'scores' => ['required', 'array', 'min:1'],
            'scores.*.indicator_id' => ['required', 'integer'],
            'scores.*.option_id' => ['nullable', 'integer'],
            'scores.*.score' => ['nullable', 'numeric'],
            'scores.*.value_number' => ['nullable', 'numeric'],
            'scores.*.value_text' => ['nullable', 'string'],
            'scores.*.is_na' => ['nullable', 'boolean'],
            'scores.*.comment' => ['nullable', 'string'],
        ]);

        $updated = $this->service->saveScores($appraisal, $data['scores'], $this->actor($request));

        return $this->successResponse($this->service->detail($updated, $this->actor($request)), 'Skor tersimpan');
    }

    public function calculate(Request $request, SupAppraisal $appraisal): JsonResponse
    {
        $data = $request->validate([
            'attendance_absence_days' => ['nullable', 'integer', 'min:0', 'max:366'],
            'result_rating' => ['nullable', 'in:DI_ATAS_EKSPEKTASI,SESUAI_EKSPEKTASI,DI_BAWAH_EKSPEKTASI'],
            'behavior_rating' => ['nullable', 'in:DI_ATAS_EKSPEKTASI,SESUAI_EKSPEKTASI,DI_BAWAH_EKSPEKTASI'],
            'result_percent' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'behavior_percent' => ['nullable', 'numeric', 'min:0', 'max:200'],
        ]);

        $calculated = $this->service->calculate($appraisal, $this->actor($request), $data);

        return $this->successResponse($this->service->detail($calculated, $this->actor($request)), 'Nilai dihitung');
    }

    public function saveSources(Request $request, SupAppraisal $appraisal): JsonResponse
    {
        $data = $request->validate([
            'sources' => ['required', 'array', 'min:1'],
            'sources.*.source' => ['required', 'in:SELF,SUPERVISOR,PEER,SUBORDINATE,STUDENT,PARENT'],
            'sources.*.rater_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'sources.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'sources.*.score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sources.*.comment' => ['nullable', 'string'],
        ]);

        $updated = $this->service->saveSources($appraisal, $data['sources'], $this->actor($request));

        return $this->successResponse($this->service->detail($updated, $this->actor($request)), 'Penilaian multi-sumber tersimpan');
    }

    public function publish(Request $request, SupAppraisal $appraisal): JsonResponse
    {
        $published = $this->service->publish($appraisal, $this->actor($request));

        return $this->successResponse($this->service->detail($published, $this->actor($request)), 'Hasil penilaian diterbitkan');
    }

    public function reopen(Request $request, SupAppraisal $appraisal): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $reopened = $this->service->reopen($appraisal, $this->actor($request), $data['reason']);

        return $this->successResponse($this->service->detail($reopened, $this->actor($request)), 'Dokumen dibuka ulang untuk koreksi');
    }

    /** @param  Collection<int,SupAppraisal>  $rows */
    protected function summary(Collection $rows): array
    {
        $byType = [];
        foreach ($rows as $row) {
            $byType[$row->appraisal_type->value] = ($byType[$row->appraisal_type->value] ?? 0) + 1;
        }

        $scored = $rows->filter(fn (SupAppraisal $row) => $row->final_score !== null);

        return [
            'total' => $rows->count(),
            'draft' => $rows->where('status', AppraisalStatus::DRAFT)->count(),
            'published' => $rows->where('status', AppraisalStatus::PUBLISHED)->count(),
            'by_type' => $byType,
            'average_score' => $scored->isEmpty() ? null : round((float) $scored->avg('final_score'), 2),
            'needs_pip' => $rows->filter(fn (SupAppraisal $row) => in_array($row->final_predicate, ['BUTUH_PERBAIKAN', 'KURANG', 'SANGAT_KURANG'], true))->count(),
        ];
    }
}
