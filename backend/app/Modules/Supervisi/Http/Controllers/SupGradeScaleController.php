<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Modules\Supervisi\Application\Services\AuditLogger;
use App\Modules\Supervisi\Models\SupGradeScale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Skala nilai & ambang predikat (dapat disesuaikan tiap sekolah). */
class SupGradeScaleController extends SupController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return $this->successResponse(
            SupGradeScale::query()->orderByDesc('is_default')->orderBy('name')->get(),
            'Daftar skala nilai',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $scale = SupGradeScale::query()->create([
            ...$data,
            'is_system' => false,
            'is_default' => false,
        ]);

        $this->audit->log('grade_scale.created', $scale, null, $scale->toArray(), null, $this->actor($request));

        return $this->createdResponse($scale, 'Skala nilai dibuat');
    }

    public function update(Request $request, SupGradeScale $scale): JsonResponse
    {
        $data = $this->validated($request, partial: true);
        $before = $scale->toArray();

        if ($scale->is_system && isset($data['code'])) {
            $this->invalid('code', 'Kode skala bawaan tidak dapat diubah.');
        }

        $scale->fill($data)->save();
        $this->audit->log('grade_scale.updated', $scale, $before, $scale->toArray(), null, $this->actor($request));

        return $this->successResponse($scale, 'Skala nilai diperbarui');
    }

    /** Jadikan skala ini sebagai bawaan modul. */
    public function setDefault(SupGradeScale $scale): JsonResponse
    {
        SupGradeScale::query()->update(['is_default' => false]);
        $scale->forceFill(['is_default' => true])->save();

        return $this->successResponse($scale, 'Skala nilai dijadikan bawaan');
    }

    /** @return array<string,mixed> */
    protected function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$required, 'string', 'max:60', 'unique:sup_grade_scales,code'],
            'name' => [$required, 'string', 'max:120'],
            'scale_type' => ['nullable', 'string', 'in:PERCENT,RAW'],
            'description' => ['nullable', 'string'],
            'bands' => [$required, 'array', 'min:1'],
            'bands.*.min' => ['nullable', 'numeric'],
            'bands.*.max' => ['nullable', 'numeric'],
            'bands.*.label' => ['required', 'string', 'max:60'],
            'bands.*.tone' => ['nullable', 'string', 'in:GREEN,AMBER,RED,GRAY'],
            'bands.*.credit_percent' => ['nullable', 'numeric', 'min:0', 'max:200'],
        ]);
    }
}
