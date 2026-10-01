<?php

namespace App\Modules\Bk\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Modules\Bk\Application\Services\BkAccessService;
use App\Modules\Bk\Application\Services\BkDevelopmentPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rencana pengembangan siswa: sasaran, kegiatan, dan peninjauan kemajuan.
 *
 * Baseline wajib sebelum peninjauan pertama, dan nomor peninjauan dijaga berurutan
 * oleh layanan — supaya riwayat kemajuan tidak dapat ditulis mundur.
 */
class BkDevelopmentPlanController extends Controller
{
    use ApiResponse;


    public function __construct(private readonly BkDevelopmentPlanService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('bk.development.view') || abort(403);

        return $this->successResponse($this->service->paginate($request->user(), $request->only([
            'student_id', 'domain', 'status', 'review_due',
        ]), (int) $request->input('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can('bk.development.manage') || abort(403);

        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'domain' => ['required', 'string'],
            'title' => ['required', 'string', 'max:180'],
            'rationale' => ['nullable', 'string'],
            'baseline_summary' => ['nullable', 'string'],
            'case_id' => ['nullable', 'integer', 'exists:bk_cases,id'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'start_date' => ['nullable', 'date'],
            'target_end_date' => ['nullable', 'date'],
            'review_interval_days' => ['nullable', 'integer', 'min:1'],
            'goals' => ['nullable', 'array'],
            'goals.*.goal' => ['required_with:goals', 'string'],
            'goals.*.measurement' => ['required_with:goals', 'string'],
            'goals.*.baseline_value' => ['nullable', 'numeric'],
            'goals.*.target_value' => ['nullable', 'numeric'],
        ]);

        return $this->createdResponse($this->service->create($request->user(), $data), 'Rencana pengembangan dibuat.');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $request->user()->can('bk.development.view') || abort(403);

        $plan = \App\Modules\Bk\Models\BkDevelopmentPlan::query()
            ->with(['student:id,full_name,nis', 'goals', 'activities', 'reviews.reviewer:id,name', 'owner:id,name'])
            ->findOrFail($id);

        app(BkAccessService::class)->assertCanViewSupportProfile($request->user(), (int) $plan->student_id);

        return $this->successResponse($plan);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->user()->can('bk.development.manage') || abort(403);

        $plan = \App\Modules\Bk\Models\BkDevelopmentPlan::query()->findOrFail($id);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:180'],
            'rationale' => ['nullable', 'string'],
            'baseline_summary' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'target_end_date' => ['nullable', 'date'],
            'review_interval_days' => ['nullable', 'integer', 'min:1'],
            'next_review_date' => ['nullable', 'date'],
            'achievement_note' => ['nullable', 'string'],
        ]);

        return $this->successResponse($this->service->update($request->user(), $plan, $data), 'Rencana pengembangan diperbarui.');
    }

    public function addGoal(Request $request, int $id): JsonResponse
    {
        $request->user()->can('bk.development.manage') || abort(403);

        $plan = \App\Modules\Bk\Models\BkDevelopmentPlan::query()->findOrFail($id);

        $data = $request->validate([
            'goal' => ['required', 'string', 'max:200'],
            'measurement' => ['required', 'string', 'max:160'],
            'measurement_unit' => ['nullable', 'string', 'max:32'],
            'baseline_value' => ['nullable', 'numeric'],
            'target_value' => ['nullable', 'numeric'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'target_date' => ['nullable', 'date'],
            'weight' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->createdResponse($this->service->addGoal($request->user(), $plan, $data), 'Sasaran pengembangan ditambahkan.');
    }

    public function baseline(Request $request, int $goalId): JsonResponse
    {
        $request->user()->can('bk.development.manage') || abort(403);

        $goal = \App\Modules\Bk\Models\BkDevelopmentGoal::query()->findOrFail($goalId);

        $data = $request->validate([
            'progress_value' => ['nullable', 'numeric'],
            'reviewed_on' => ['nullable', 'date'],
            'progress_note' => ['nullable', 'string'],
        ]);

        return $this->createdResponse($this->service->recordBaseline($request->user(), $goal, $data), 'Baseline sasaran dicatat.');
    }

    public function review(Request $request, int $goalId): JsonResponse
    {
        $request->user()->can('bk.development.manage') || abort(403);

        $goal = \App\Modules\Bk\Models\BkDevelopmentGoal::query()->findOrFail($goalId);

        $data = $request->validate([
            'progress_value' => ['nullable', 'numeric'],
            'progress_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'status' => ['nullable', 'string'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'progress_note' => ['nullable', 'string'],
            'reviewed_on' => ['nullable', 'date'],
            'next_review_date' => ['nullable', 'date'],
        ]);

        return $this->createdResponse($this->service->review($request->user(), $goal, $data), 'Peninjauan kemajuan dicatat.');
    }

    public function addActivity(Request $request, int $id): JsonResponse
    {
        $request->user()->can('bk.development.manage') || abort(403);

        $plan = \App\Modules\Bk\Models\BkDevelopmentPlan::query()->findOrFail($id);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'goal_id' => ['nullable', 'integer', 'exists:bk_development_goals,id'],
            'activity_type' => ['nullable', 'string'],
            'scheduled_date' => ['nullable', 'date'],
            'facilitator_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        return $this->createdResponse($this->service->addActivity($request->user(), $plan, $data), 'Kegiatan pengembangan ditambahkan.');
    }

    public function completeActivity(Request $request, int $activityId): JsonResponse
    {
        $request->user()->can('bk.development.manage') || abort(403);

        $activity = \App\Modules\Bk\Models\BkDevelopmentActivity::query()->findOrFail($activityId);

        $data = $request->validate([
            'completed_date' => ['nullable', 'date'],
            'evidence' => ['nullable', 'string'],
            'document_id' => ['nullable', 'integer', 'exists:bk_case_documents,id'],
        ]);

        return $this->successResponse($this->service->completeActivity($request->user(), $activity, $data), 'Kegiatan pengembangan diselesaikan.');
    }
}
