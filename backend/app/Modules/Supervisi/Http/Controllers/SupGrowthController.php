<?php

namespace App\Modules\Supervisi\Http\Controllers;

use App\Modules\Supervisi\Application\Services\CoachingService;
use App\Modules\Supervisi\Application\Services\DevelopmentService;
use App\Modules\Supervisi\Application\Services\PipService;
use App\Modules\Supervisi\Models\SupCoachingSession;
use App\Modules\Supervisi\Models\SupDevelopment;
use App\Modules\Supervisi\Models\SupPipItem;
use App\Modules\Supervisi\Models\SupPipPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pengembangan kompetensi, coaching, dan rencana perbaikan kinerja (PIP). */
class SupGrowthController extends SupController
{
    public function __construct(
        private readonly DevelopmentService $developments,
        private readonly CoachingService $coaching,
        private readonly PipService $pip,
    ) {}

    // ------------------------------------------------------------------
    // Pengembangan kompetensi (PKB)
    // ------------------------------------------------------------------

    public function developments(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->developments->list($request->only(['employee_id', 'kind', 'year', 'limit']), $this->actor($request)),
            'Pengembangan kompetensi',
        );
    }

    public function storeDevelopment(Request $request): JsonResponse
    {
        $data = $this->requestDevelopment($request);
        $development = $this->developments->record($data, $this->actor($request));

        return $this->successResponse([
            'row' => $this->developments->list(['employee_id' => $development->supervisee_employee_id], $this->actor($request))['rows'][0] ?? null,
        ], 'Pengembangan dicatat', 201);
    }

    public function updateDevelopment(Request $request, SupDevelopment $development): JsonResponse
    {
        $data = $this->requestDevelopment($request, false);
        $this->developments->update($development, $data, $this->actor($request));

        return $this->successResponse([], 'Pengembangan diperbarui');
    }

    public function destroyDevelopment(Request $request, SupDevelopment $development): JsonResponse
    {
        $this->developments->destroy($development, $this->actor($request));

        return $this->successResponse([], 'Pengembangan dihapus');
    }

    // ------------------------------------------------------------------
    // Coaching / mentoring
    // ------------------------------------------------------------------

    public function coachings(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->coaching->list($request->only(['employee_id', 'status', 'limit']), $this->actor($request)),
            'Sesi coaching',
        );
    }

    public function storeCoaching(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supervisee_employee_id' => ['required', 'integer', 'exists:employees,id'],
            'supervisor_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'visit_id' => ['nullable', 'integer', 'exists:sup_visits,id'],
            'session_at' => ['nullable', 'date'],
            'focus' => ['nullable', 'string', 'max:300'],
            'commitments' => ['nullable', 'array'],
            'commitments.*' => ['string', 'max:300'],
            'follow_up_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
        ]);

        $session = $this->coaching->create($data, $this->actor($request));

        return $this->successResponse(['id' => $session->id], 'Sesi coaching dibuat', 201);
    }

    public function updateCoaching(Request $request, SupCoachingSession $coaching): JsonResponse
    {
        $data = $request->validate([
            'session_at' => ['nullable', 'date'],
            'focus' => ['nullable', 'string', 'max:300'],
            'commitments' => ['nullable', 'array'],
            'follow_up_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
            'status' => ['nullable', 'in:OPEN,CLOSED,CANCELLED'],
        ]);

        $this->coaching->update($coaching, $data, $this->actor($request));

        return $this->successResponse([], 'Sesi coaching diperbarui');
    }

    public function closeCoaching(Request $request, SupCoachingSession $coaching): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $this->coaching->close($coaching, $data['note'], $this->actor($request));

        return $this->successResponse([], 'Sesi coaching ditutup');
    }

    // ------------------------------------------------------------------
    // PIP
    // ------------------------------------------------------------------

    public function pips(Request $request): JsonResponse
    {
        return $this->successResponse([
            'rows' => $this->pip->list($request->only(['status', 'employee_id', 'limit']), $this->actor($request)),
            'outcomes' => PipService::OUTCOMES,
        ], 'Rencana perbaikan kinerja');
    }

    public function storePip(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supervisee_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'appraisal_id' => ['nullable', 'integer', 'exists:sup_appraisals,id'],
            'reason' => ['nullable', 'string'],
            'expected_standard' => ['nullable', 'string'],
            'consequences_note' => ['nullable', 'string'],
            'support_provided' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'final_review_date' => ['nullable', 'date'],
            'review_cadence' => ['nullable', 'in:WEEKLY,BIWEEKLY,MONTHLY,OTHER'],
            'items' => ['nullable', 'array'],
            'items.*.statement' => ['required_with:items', 'string', 'max:500'],
            'items.*.measure' => ['nullable', 'string', 'max:200'],
            'items.*.target_value' => ['nullable', 'numeric'],
            'items.*.target_unit' => ['nullable', 'string', 'max:40'],
            'items.*.due_date' => ['nullable', 'date'],
        ]);

        $plan = $this->pip->create($data, $this->actor($request));

        return $this->successResponse($this->pip->detail($plan, $this->actor($request)), 'PIP dibuat', 201);
    }

    public function showPip(Request $request, SupPipPlan $pipPlan): JsonResponse
    {
        $this->pip->ensureVisible($pipPlan, $this->actor($request));

        return $this->successResponse($this->pip->detail($pipPlan, $this->actor($request)), 'Rincian PIP');
    }

    public function updatePip(Request $request, SupPipPlan $pipPlan): JsonResponse
    {
        $this->pip->ensureVisible($pipPlan, $this->actor($request));

        $data = $request->validate([
            'reason' => ['nullable', 'string'],
            'expected_standard' => ['nullable', 'string'],
            'consequences_note' => ['nullable', 'string'],
            'support_provided' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'final_review_date' => ['nullable', 'date'],
            'review_cadence' => ['nullable', 'in:WEEKLY,BIWEEKLY,MONTHLY,OTHER'],
            'status' => ['nullable', 'in:DRAFT,ACTIVE,CLOSED'],
        ]);

        $plan = $this->pip->update($pipPlan, $data, $this->actor($request));

        return $this->successResponse($this->pip->detail($plan, $this->actor($request)), 'PIP diperbarui');
    }

    public function storePipItem(Request $request, SupPipPlan $pipPlan): JsonResponse
    {
        $this->pip->ensureVisible($pipPlan, $this->actor($request));

        $data = $request->validate([
            'statement' => ['required', 'string', 'max:500'],
            'measure' => ['nullable', 'string', 'max:200'],
            'target_value' => ['nullable', 'numeric'],
            'target_unit' => ['nullable', 'string', 'max:40'],
            'due_date' => ['nullable', 'date'],
        ]);

        $this->pip->addItem($pipPlan, $data, $this->actor($request));

        return $this->successResponse($this->pip->detail($pipPlan->refresh(), $this->actor($request)), 'Sasaran PIP ditambahkan', 201);
    }

    public function updatePipItem(Request $request, SupPipItem $pipItem): JsonResponse
    {
        $data = $request->validate([
            'statement' => ['nullable', 'string', 'max:500'],
            'measure' => ['nullable', 'string', 'max:200'],
            'target_value' => ['nullable', 'numeric'],
            'target_unit' => ['nullable', 'string', 'max:40'],
            'due_date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:PENDING,IN_PROGRESS,DONE,DROPPED'],
            'result_note' => ['nullable', 'string'],
        ]);

        $item = $this->pip->updateItem($pipItem, $data, $this->actor($request));

        return $this->successResponse(
            $this->pip->detail($item->plan()->firstOrFail()->refresh(), $this->actor($request)),
            'Sasaran PIP diperbarui',
        );
    }

    public function reviewPip(Request $request, SupPipPlan $pipPlan): JsonResponse
    {
        $this->pip->ensureVisible($pipPlan, $this->actor($request));

        $data = $request->validate([
            'reviewed_at' => ['nullable', 'date'],
            'progress_note' => ['nullable', 'string'],
            'next_step' => ['nullable', 'string', 'max:300'],
            'status' => ['nullable', 'in:PENDING,DONE,EXTENDED'],
        ]);

        $this->pip->recordReview($pipPlan, $data, $this->actor($request));

        return $this->successResponse($this->pip->detail($pipPlan->refresh(), $this->actor($request)), 'Tinjauan PIP tersimpan', 201);
    }

    public function concludePip(Request $request, SupPipPlan $pipPlan): JsonResponse
    {
        $this->pip->ensureVisible($pipPlan, $this->actor($request));

        $data = $request->validate([
            'outcome' => ['required', 'in:IMPROVED,PARTIAL,NOT_IMPROVED,EXTENDED'],
            'note' => ['nullable', 'string'],
            'final_review_date' => ['nullable', 'date'],
        ]);

        $plan = $this->pip->conclude($pipPlan, $data, $this->actor($request));

        return $this->successResponse($this->pip->detail($plan, $this->actor($request)), 'Hasil PIP diputuskan');
    }

    public function acknowledgePip(Request $request, SupPipPlan $pipPlan): JsonResponse
    {
        $this->pip->ensureVisible($pipPlan, $this->actor($request));

        $plan = $this->pip->acknowledge($pipPlan, $this->actor($request));

        return $this->successResponse($this->pip->detail($plan, $this->actor($request)), 'PIP ditandai sudah dipahami');
    }

    /** @return array<string,mixed> */
    protected function requestDevelopment(Request $request, bool $creating = true): array
    {
        $rules = [
            'supervisee_employee_id' => [$creating ? 'required' : 'nullable', 'integer', 'exists:employees,id'],
            'kind' => ['nullable', 'string', 'max:30'],
            'title' => [$creating ? 'required' : 'nullable', 'string', 'max:200'],
            'provider' => ['nullable', 'string', 'max:150'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'started_at' => ['nullable', 'date'],
            'finished_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'certificate_no' => ['nullable', 'string', 'max:80'],
            'certificate_path' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:PLANNED,ONGOING,DONE,CANCELLED'],
            'linked_visit_id' => ['nullable', 'integer', 'exists:sup_visits,id'],
            'linked_action_item_id' => ['nullable', 'integer', 'exists:sup_action_items,id'],
        ];

        return array_filter($request->validate($rules), fn ($value) => $value !== null);
    }
}
