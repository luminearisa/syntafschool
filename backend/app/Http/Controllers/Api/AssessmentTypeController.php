<?php

namespace App\Http\Controllers\Api;

use App\Models\AssessmentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Jenis penilaian (A7 — konfigurasi).
 *
 * Jenis adalah data, bukan kode program: sekolah menambah "Kuis Bab", "Projek",
 * "Praktik" lewat sini, lalu bobotnya diatur di AssessmentWeightController.
 */
class AssessmentTypeController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = AssessmentType::query()->withCount('assessments');

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%"));
        }

        foreach (['category', 'is_active', 'is_required'] as $key) {
            $value = $request->query("filter.{$key}", $request->query($key));

            if ($value !== null && $value !== '') {
                $query->where($key, filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value);
            }
        }

        $query->orderBy('sort_order')->orderBy('name');

        if ($request->boolean('all')) {
            return $this->successResponse($query->get(), 'Jenis penilaian berhasil diambil');
        }

        return $this->successResponse(
            $query->paginate((int) $request->query('per_page', 15)),
            'Jenis penilaian berhasil diambil',
        );
    }

    public function show(AssessmentType $type): JsonResponse
    {
        return $this->successResponse(
            $type->loadCount('assessments'),
            'Detail jenis penilaian berhasil diambil',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $type = AssessmentType::create($data);

        return $this->createdResponse($type, 'Jenis penilaian berhasil dibuat');
    }

    public function update(Request $request, AssessmentType $type): JsonResponse
    {
        $type->update($this->validateData($request, $type));

        return $this->successResponse($type->refresh(), 'Jenis penilaian berhasil diperbarui');
    }

    /**
     * Jenis yang sudah dipakai jangan dihapus — nonaktifkan saja, supaya nilai
     * historis tetap bisa dibaca.
     */
    public function destroy(AssessmentType $type): JsonResponse
    {
        $usedBy = $type->assessments()->count();
        $weightedBy = $type->weights()->count();

        if ($usedBy > 0 || $weightedBy > 0) {
            throw ValidationException::withMessages([
                'id' => "Jenis ini masih dipakai {$usedBy} penilaian dan {$weightedBy} pengaturan bobot. Nonaktifkan saja.",
            ]);
        }

        $type->delete();

        return $this->successResponse(null, 'Jenis penilaian berhasil dihapus');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateData(Request $request, ?AssessmentType $type = null): array
    {
        return $request->validate([
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('assessment_types', 'code')->ignore($type?->id)->withoutTrashed(),
            ],
            'name' => ['required', 'string', 'max:100'],
            'category' => ['required', Rule::in(AssessmentType::CATEGORIES)],
            'is_required' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ], [], [
            'code' => 'kode',
            'name' => 'nama',
            'category' => 'kategori',
            'is_required' => 'wajib ada',
            'is_active' => 'aktif',
            'sort_order' => 'urutan',
        ]);
    }
}
