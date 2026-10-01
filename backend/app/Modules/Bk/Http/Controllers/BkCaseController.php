<?php

namespace App\Modules\Bk\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Bk\Application\Services\BkAssignmentService;
use App\Modules\Bk\Application\Services\BkCaseService;
use App\Modules\Bk\Application\Services\BkTimelineService;
use App\Modules\Bk\Domain\Enums\AssignmentType;
use App\Modules\Bk\Domain\Enums\ClosureReason;
use App\Modules\Bk\Domain\Enums\EscalationLevel;
use App\Modules\Bk\Http\Requests\BkCaseRequest;
use App\Modules\Bk\Models\BkCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kasus BK: daftar, pembukaan langsung, perubahan, penugasan, penutupan,
 * pembukaan kembali, eskalasi, dan timeline.
 */
class BkCaseController extends BaseApiController
{
    public function __construct(
        private readonly BkCaseService $cases,
        private readonly BkAssignmentService $assignments,
        private readonly BkTimelineService $timeline,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $this->cases->paginate($request->user(), $request->only([
            'status', 'open', 'case_type', 'category', 'priority', 'severity',
            'counselor_id', 'student_id', 'tag', 'review_overdue', 'search',
        ]), (int) $request->integer('per_page', 20));

        return $this->successResponse($data, 'Daftar kasus.');
    }

    public function store(BkCaseRequest $request): JsonResponse
    {
        $this->authorize('create', BkCase::class);

        return $this->createdResponse($this->cases->create($request->user(), $request->validated()), 'Kasus dibuka.');
    }

    public function show(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        return $this->successResponse([
            'case' => $case->load(['student:id,full_name,nis', 'counselor:id,name', 'coordinator:id,name',
                'sourceReferral:id,referral_number,source_type', 'tags:id,name,color', 'participants.user:id,name', 'participants.guardian:id,full_name', 'participants.student:id,full_name']),
            'closure_blockers' => $this->cases->closureBlockers($case),
            'confidential_access' => $request->user()->can('viewConfidential', $case),
        ], 'Detail kasus.');
    }

    public function update(BkCaseRequest $request, BkCase $case): JsonResponse
    {
        $this->authorize('update', $case);

        return $this->successResponse($this->cases->update($request->user(), $case, $request->validated()), 'Kasus diperbarui.');
    }

    public function transition(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('update', $case);

        $validated = $request->validate([
            'status' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $status = \App\Modules\Bk\Domain\Enums\CaseStatus::from($validated['status']);

        return $this->successResponse($this->cases->transition($request->user(), $case, $status, $validated['note'] ?? null), 'Status kasus diperbarui.');
    }

    public function assign(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('assign', $case);

        $validated = $request->validate([
            'counselor_user_id' => ['required', 'integer', 'exists:users,id'],
            'assignment_type' => ['nullable', 'string'],
            'force' => ['nullable', 'boolean'],
        ]);

        $updated = $this->assignments->assign(
            $request->user(),
            $case,
            (int) $validated['counselor_user_id'],
            AssignmentType::from($validated['assignment_type'] ?? AssignmentType::PRIMARY->value),
            (bool) ($validated['force'] ?? false),
        );

        return $this->successResponse($updated, 'Penugasan konselor disimpan.');
    }

    public function endAssignment(Request $request, BkCase $case, int $userId): JsonResponse
    {
        $this->authorize('assign', $case);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:191']]);

        $this->assignments->endAssignment($request->user(), $case, $userId, $validated['reason']);

        return $this->successResponse(null, 'Penugasan dihentikan.');
    }

    public function decline(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:191']]);

        return $this->successResponse($this->assignments->declineAssignment($request->user(), $case, $validated['reason']), 'Penugasan dikembalikan ke koordinator.');
    }

    public function workload(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('bk.workload.view'), 403, 'Anda tidak berwenang melihat beban kerja konselor.');

        return $this->successResponse([
            'counselors' => $this->assignments->workload(),
            'candidates' => $this->assignments->suggestCandidates(),
        ], 'Beban kerja konselor.');
    }

    public function close(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('close', $case);

        $validated = $request->validate([
            'closure_reason' => ['required', 'string'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'force' => ['nullable', 'boolean'],
        ]);

        $closed = $this->cases->close(
            $request->user(),
            $case,
            ClosureReason::from($validated['closure_reason']),
            $validated['summary'] ?? null,
            (bool) ($validated['force'] ?? false),
        );

        return $this->successResponse($closed, 'Kasus ditutup.');
    }

    public function reopen(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('reopen', $case);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->successResponse($this->cases->reopen($request->user(), $case, $validated['reason']), 'Kasus dibuka kembali.');
    }

    public function review(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'next_review_date' => ['nullable', 'date'],
        ]);

        return $this->successResponse($this->cases->review($request->user(), $case, $validated['note'] ?? null, $validated['next_review_date'] ?? null), 'Tinjauan kasus tercatat.');
    }

    public function escalate(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('escalate', $case);

        $validated = $request->validate([
            'level' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->successResponse($this->cases->escalate($request->user(), $case, EscalationLevel::from($validated['level']), $validated['reason']), 'Kasus dieskalasi.');
    }

    public function timeline(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        return $this->successResponse(
            $this->timeline->forCase($case)->map(fn ($event): array => [
                'id' => $event->id,
                'event' => $event->event_type->value,
                'event_label' => $event->event_type->label(),
                'summary' => $event->summary,
                'actor' => $event->actor?->name,
                'actor_role' => $event->actor_role,
                'status_from' => $event->status_from,
                'status_to' => $event->status_to,
                'related_type' => $event->related_type,
                'related_id' => $event->related_id,
                'occurred_at' => $event->occurred_at?->toDateTimeString(),
            ])->values(),
            'Timeline kasus.',
        );
    }

    public function addParticipant(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('share', $case);

        $validated = $request->validate([
            'role' => ['required', 'string'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'guardian_id' => ['nullable', 'integer', 'exists:guardians,id'],
            'name' => ['nullable', 'string', 'max:120'],
            'contact' => ['nullable', 'string', 'max:80'],
            'can_view_case' => ['nullable', 'boolean'],
            'max_confidentiality' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->cases->addParticipant($request->user(), $case, $validated);

        return $this->createdResponse(null, 'Pihak ditambahkan pada kasus.');
    }

    public function removeParticipant(Request $request, BkCase $case, int $participantId): JsonResponse
    {
        $this->authorize('share', $case);

        $this->cases->removeParticipant($request->user(), $case, $participantId);

        return $this->successResponse(null, 'Akses pihak dicabut.');
    }

    public function syncTags(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('update', $case);

        $validated = $request->validate([
            'tags' => ['present', 'array'],
            'tags.*' => ['string'],
        ]);

        $this->cases->syncTags($request->user(), $case, $validated['tags']);

        return $this->successResponse($case->refresh()->load('tags'), 'Label kasus diperbarui.');
    }
}
