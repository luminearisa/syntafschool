<?php

namespace App\Modules\Bk\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Modules\Bk\Application\Services\BkCareerGuidanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bimbingan karier: profil, sesi, dan ringkasan yang menegaskan arah masih terbuka.
 */
class BkCareerController extends Controller
{
    use ApiResponse;


    public function __construct(private readonly BkCareerGuidanceService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('bk.career.view') || abort(403);

        return $this->successResponse($this->service->paginateProfiles($request->user(), $request->only([
            'exploration_status', 'student_id',
        ]), (int) $request->input('per_page', 25)));
    }

    public function upsert(Request $request): JsonResponse
    {
        $request->user()->can('bk.career.manage') || abort(403);

        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'aspiration' => ['nullable', 'string'],
            'interests' => ['nullable', 'array'],
            'strengths' => ['nullable', 'array'],
            'skills' => ['nullable', 'array'],
            'preferred_subjects' => ['nullable', 'array'],
            'assessment_inputs' => ['nullable', 'array'],
            'university_plan' => ['nullable', 'string'],
            'education_plan' => ['nullable', 'string'],
            'action_plan' => ['nullable', 'string'],
            'target_institution' => ['nullable', 'string', 'max:160'],
            'target_major' => ['nullable', 'string', 'max:160'],
            'alternative_options' => ['nullable', 'array'],
            'exploration_status' => ['nullable', 'string'],
            'guidance_status' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $profile = $this->service->upsertProfile($request->user(), (int) $data['student_id'], $data);

        return $this->successResponse([
            'profile' => $profile,
            'note' => 'Hasil asesmen tersimpan sebagai masukan; siswa tetap dapat mengubah arah kapan saja.',
        ], 'Profil karier disimpan.');
    }

    public function sessions(Request $request, int $studentId): JsonResponse
    {
        $request->user()->can('bk.career.view') || abort(403);

        return $this->successResponse([
            'sessions' => $this->service->sessions($request->user(), $studentId),
            'summary' => $this->service->summaryForStudent($studentId),
        ]);
    }

    public function storeSession(Request $request): JsonResponse
    {
        $request->user()->can('bk.career.manage') || abort(403);

        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'focus' => ['required', 'string'],
            'case_id' => ['nullable', 'integer', 'exists:bk_cases,id'],
            'session_at' => ['nullable', 'date'],
            'summary' => ['nullable', 'string'],
            'options_explored' => ['nullable', 'array'],
            'action_items' => ['nullable', 'array'],
            'next_steps' => ['nullable', 'string'],
            'next_session_date' => ['nullable', 'date'],
        ]);

        return $this->createdResponse($this->service->recordSession($request->user(), (int) $data['student_id'], $data), 'Sesi bimbingan karier dicatat.');
    }
}
