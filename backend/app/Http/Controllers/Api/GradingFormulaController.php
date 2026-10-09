<?php

namespace App\Http\Controllers\Api;

use App\Models\GradingFormula;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Formula penilaian (A7 — konfigurasi).
 *
 * Tempat kebijakan penilaian disimpan: pembulatan berapa desimal dan bagaimana
 * nilai remedial menggantikan nilai awal. Formula boleh berversi — yang baru
 * dipakai mulai tahun ajaran tertentu, sementara penilaian lama tetap memakai
 * versi yang aktif saat itu.
 */
class GradingFormulaController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = GradingFormula::query()
            ->with('effectiveAcademicYear')
            ->when($request->boolean('only_active'), fn ($q) => $q->where('is_active', true))
            ->orderByDesc('version')
            ->orderBy('id');

        if ($request->boolean('all')) {
            return $this->successResponse($query->get(), 'Formula penilaian berhasil diambil');
        }

        return $this->successResponse(
            $query->paginate((int) $request->query('per_page', 15)),
            'Formula penilaian berhasil diambil',
        );
    }

    public function show(GradingFormula $formula): JsonResponse
    {
        return $this->successResponse(
            $formula->load('effectiveAcademicYear')->loadCount('assessments'),
            'Detail formula penilaian berhasil diambil',
        );
    }

    public function store(Request $request): JsonResponse
    {
        [$data, $settings] = $this->validateData($request);

        $formula = GradingFormula::create($data + ['settings' => $settings]);

        return $this->createdResponse($formula, 'Formula penilaian berhasil dibuat');
    }

    public function update(Request $request, GradingFormula $formula): JsonResponse
    {
        [$data, $settings] = $this->validateData($request, $formula);

        // Pertahankan pengaturan lama bila tidak dikirim, dan gabungkan bila
        // hanya sebagian yang diubah — supaya decimals/remedial tidak tereset.
        if ($settings !== null) {
            $data['settings'] = array_merge($formula->settings ?? [], $settings);
        }

        $formula->update($data);

        return $this->successResponse($formula->refresh(), 'Formula penilaian berhasil diperbarui');
    }

    public function destroy(GradingFormula $formula): JsonResponse
    {
        $used = $formula->assessments()->count();

        if ($used > 0) {
            throw ValidationException::withMessages([
                'id' => "Formula ini masih dipakai {$used} penilaian dan tidak bisa dihapus.",
            ]);
        }

        $formula->delete();

        return $this->successResponse(null, 'Formula penilaian berhasil dihapus');
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function validateData(Request $request, ?GradingFormula $formula = null): array
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('grading_formulas', 'code')->ignore($formula?->id)->withoutTrashed(),
            ],
            'name' => ['required', 'string', 'max:100'],
            'formula_type' => ['required', Rule::in(GradingFormula::TYPES)],
            'version' => ['nullable', 'integer', 'min:1'],
            'effective_academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'is_active' => ['boolean'],
            'settings' => ['nullable', 'array'],
            'settings.decimals' => ['nullable', 'integer', 'between:0,4'],
            'settings.remedial_rule' => ['nullable', Rule::in(GradingFormula::REMEDIAL_RULES)],
            'settings.remedial_cap' => [
                'nullable',
                'numeric',
                'between:0,100',
                'required_if:settings.remedial_rule,'.GradingFormula::REMEDIAL_CAP,
            ],
        ], [], [
            'code' => 'kode',
            'name' => 'nama',
            'formula_type' => 'jenis formula',
            'version' => 'versi',
            'effective_academic_year_id' => 'tahun ajaran berlaku',
            'settings.decimals' => 'jumlah desimal',
            'settings.remedial_rule' => 'aturan remedial',
            'settings.remedial_cap' => 'batas nilai remedial',
        ]);

        $settings = $data['settings'] ?? null;
        unset($data['settings']);

        return [$data, $settings];
    }
}
