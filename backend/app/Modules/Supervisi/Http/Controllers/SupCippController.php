<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Modules\Supervisi\Application\Services\CippService;
use App\Modules\Supervisi\Models\SupProgram;
use App\Modules\Supervisi\Models\SupProgramEvaluation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Evaluasi program supervisi dengan model CIPP. */
class SupCippController extends SupController
{
    public function __construct(private readonly CippService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rows = $this->service->listing($request->only(['program_id', 'period']));

        return $this->successResponse([
            'rows' => $rows,
            'summary' => [
                'evaluations' => $rows->count(),
                'finalized' => $rows->whereNotNull('finalized_at')->count(),
                'average' => ($averages = $rows->pluck('average')->filter(fn ($value) => $value !== null))->isEmpty()
                    ? null
                    : round((float) $averages->avg(), 2),
            ],
            'dimensions' => CippService::DIMENSIONS,
        ], 'Daftar evaluasi program (CIPP)');
    }

    public function store(Request $request, SupProgram $program): JsonResponse
    {
        $evaluation = $this->service->create($program, $this->payload($request), $this->actor($request));

        return $this->successResponse($this->service->detail($evaluation), 'Evaluasi CIPP dibuat', 201);
    }

    public function show(SupProgramEvaluation $evaluation): JsonResponse
    {
        return $this->successResponse($this->service->detail($evaluation), 'Rincian evaluasi CIPP');
    }

    public function update(Request $request, SupProgramEvaluation $evaluation): JsonResponse
    {
        $evaluation = $this->service->update($evaluation, $this->payload($request));

        return $this->successResponse($this->service->detail($evaluation), 'Evaluasi CIPP diperbarui');
    }

    public function finalize(Request $request, SupProgramEvaluation $evaluation): JsonResponse
    {
        $evaluation = $this->service->finalize($evaluation, $this->actor($request));

        return $this->successResponse($this->service->detail($evaluation), 'Evaluasi CIPP difinalkan');
    }

    /** @return array<string, mixed> */
    protected function payload(Request $request): array
    {
        return $request->validate([
            'period' => ['nullable', 'string', 'max:60'],
            'context' => ['nullable', 'array'],
            'input' => ['nullable', 'array'],
            'process' => ['nullable', 'array'],
            'product' => ['nullable', 'array'],
            'stakeholders' => ['nullable', 'array'],
            'context_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'input_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'process_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'product_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'finding' => ['nullable', 'string'],
            'recommendation' => ['nullable', 'string'],
        ]);
    }
}
