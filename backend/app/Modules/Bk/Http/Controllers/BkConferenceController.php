<?php

namespace App\Modules\Bk\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\User;
use App\Modules\Bk\Application\Services\BkAccessService;
use App\Modules\Bk\Application\Services\BkCaseConferenceService;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCaseConference;
use App\Modules\Bk\Models\BkCaseConferenceAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Konferensi kasus: penyelenggaraan, peserta, dan tindak lanjutnya.
 *
 * Politiknya sederhana: peserta konferensi tidak otomatis boleh membuka catatan
 * konseling. Yang mengatur itu `BkCaseConferenceService`, dan controller ini tidak
 * pernah mengirim `discussion`/`decision` kepada pengguna yang tidak berwenang
 * membaca konferensi kasus tersebut.
 */
class BkConferenceController extends Controller
{
    use ApiResponse;


    public function __construct(private readonly BkCaseConferenceService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BkCaseConference::class);

        return $this->successResponse($this->service->paginate($request->user(), $request->only([
            'case_id', 'status', 'upcoming', 'from', 'to',
        ]), (int) $request->input('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', BkCaseConference::class);

        $data = $request->validate([
            'case_id' => ['required', 'integer', 'exists:bk_cases,id'],
            'title' => ['nullable', 'string', 'max:160'],
            'purpose' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'mode' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:160'],
            'scheduled_start' => ['nullable', 'date'],
            'scheduled_end' => ['nullable', 'date', 'after_or_equal:scheduled_start'],
            'agenda' => ['nullable', 'array'],
            'confidentiality_level' => ['nullable', 'string'],
            'participants' => ['nullable', 'array'],
        ]);

        $case = BkCase::query()->findOrFail((int) $data['case_id']);
        $conference = $this->service->create($request->user(), $case, $data);

        return $this->createdResponse($conference, 'Konferensi kasus dibuat.');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $conference = BkCaseConference::query()->with(['case:id,case_number,student_id', 'student:id,full_name'])->findOrFail($id);
        $this->service->assertCanView($request->user(), $conference);

        $user = $request->user();

        return $this->successResponse([
            'conference' => $conference,
            'participants' => $this->service->participants($user, $conference),
            'actions' => $this->service->actions($user, $conference),
            // Batas kerahasiaan pribadi pemanggil, supaya layar tahu apa yang boleh dibuka.
            'confidentiality_ceiling' => $this->service->confidentialityCeiling($user, $conference)->value,
            'can_view_counseling' => $conference->case !== null
                && app(\App\Modules\Bk\Application\Services\BkAccessService::class)->canViewCounselingContent($user, $conference->case),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $conference = BkCaseConference::query()->findOrFail($id);
        $this->authorize('update', $conference);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'purpose' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:160'],
            'scheduled_start' => ['nullable', 'date'],
            'scheduled_end' => ['nullable', 'date'],
            'agenda' => ['nullable', 'array'],
            'discussion' => ['nullable', 'string'],
            'decision' => ['nullable', 'string'],
            'decision_outcome' => ['nullable', 'string'],
            'follow_up_required' => ['nullable', 'boolean'],
            'follow_up_summary' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ]);

        return $this->successResponse($this->service->update($request->user(), $conference, $data), 'Konferensi diperbarui.');
    }

    public function start(Request $request, int $id): JsonResponse
    {
        $conference = BkCaseConference::query()->findOrFail($id);
        $this->authorize('update', $conference);

        return $this->successResponse($this->service->start($request->user(), $conference), 'Konferensi dimulai.');
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $conference = BkCaseConference::query()->findOrFail($id);
        $this->authorize('update', $conference);

        $data = $request->validate([
            'decision' => ['required', 'string'],
            'decision_outcome' => ['nullable', 'string'],
            'discussion' => ['nullable', 'string'],
            'follow_up_required' => ['nullable', 'boolean'],
            'follow_up_summary' => ['nullable', 'string'],
        ]);

        return $this->successResponse($this->service->complete($request->user(), $conference, $data), 'Konferensi ditutup beserta keputusannya.');
    }

    public function addParticipant(Request $request, int $id): JsonResponse
    {
        $conference = BkCaseConference::query()->findOrFail($id);
        $this->authorize('update', $conference);

        $data = $request->validate([
            'participant_type' => ['required', 'string'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'guardian_id' => ['nullable', 'integer', 'exists:guardians,id'],
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'external_provider_id' => ['nullable', 'integer', 'exists:bk_referral_providers,id'],
            'external_name' => ['nullable', 'string', 'max:160'],
            'external_organization' => ['nullable', 'string', 'max:160'],
            'external_email' => ['nullable', 'email', 'max:160'],
            'role_label' => ['nullable', 'string', 'max:80'],
            'is_required' => ['nullable', 'boolean'],
        ]);

        $participant = $this->service->addParticipant($request->user(), $conference, $data);

        return $this->createdResponse($participant, 'Peserta ditambahkan.');
    }

    public function removeParticipant(Request $request, int $id, int $participantId): JsonResponse
    {
        $conference = BkCaseConference::query()->findOrFail($id);
        $this->authorize('update', $conference);

        $participant = $conference->participants()->findOrFail($participantId);
        $this->service->removeParticipant($request->user(), $conference, $participant);

        return $this->successResponse(null, 'Peserta dihapus dari konferensi.');
    }

    public function addAction(Request $request, int $id): JsonResponse
    {
        $conference = BkCaseConference::query()->findOrFail($id);
        $this->authorize('update', $conference);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'responsible_role' => ['nullable', 'string', 'max:32'],
            'due_date' => ['required', 'date'],
            'priority' => ['nullable', 'string'],
            'create_case_follow_up' => ['nullable', 'boolean'],
            'document_id' => ['nullable', 'integer', 'exists:bk_case_documents,id'],
        ]);

        $action = $this->service->createAction($request->user(), $conference, $data);

        return $this->createdResponse($action, 'Tindak lanjut konferensi dibuat.');
    }

    public function completeAction(Request $request, int $actionId): JsonResponse
    {
        $action = BkCaseConferenceAction::query()->findOrFail($actionId);
        $conference = $action->conference;
        abort_if($conference === null, 404);
        $this->authorize('update', $conference);

        $data = $request->validate(['outcome' => ['nullable', 'string']]);

        return $this->successResponse($this->service->completeAction($request->user(), $action, $data), 'Tindak lanjut konferensi selesai.');
    }
}
