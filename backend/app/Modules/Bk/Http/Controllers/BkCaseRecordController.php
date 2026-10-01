<?php

namespace App\Modules\Bk\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Bk\Application\Services\BkAssessmentService;
use App\Modules\Bk\Application\Services\BkDocumentService;
use App\Modules\Bk\Application\Services\BkFollowUpService;
use App\Modules\Bk\Application\Services\BkInterventionService;
use App\Modules\Bk\Application\Services\BkNoteService;
use App\Modules\Bk\Application\Services\BkParentMeetingService;
use App\Modules\Bk\Application\Services\BkPlanService;
use App\Modules\Bk\Application\Services\BkScreeningService;
use App\Modules\Bk\Application\Services\BkSessionService;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCounselingPlan;
use App\Modules\Bk\Models\BkCounselingSession;
use App\Modules\Bk\Models\BkFollowUp;
use App\Modules\Bk\Models\BkGoal;
use App\Modules\Bk\Models\BkIntervention;
use App\Modules\Bk\Models\BkCaseDocument;
use App\Modules\Bk\Models\BkCaseNote;
use App\Modules\Bk\Models\BkParentMeeting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rekaman di dalam satu kasus: screening, asesmen, sesi, rencana, intervensi,
 * tindak lanjut, pertemuan orang tua, dokumen, dan catatan.
 *
 * Seluruh endpoint memakai Policy + `BkAccessService`, sehingga guru/wali yang
 * mencoba membuka bagian konseling akan ditolak di sini — bukan hanya
 * disembunyikan di layar.
 */
class BkCaseRecordController extends BaseApiController
{
    public function __construct(
        private readonly BkAssessmentService $assessments,
        private readonly BkSessionService $sessions,
        private readonly BkPlanService $plans,
        private readonly BkInterventionService $interventions,
        private readonly BkFollowUpService $followUps,
        private readonly BkParentMeetingService $meetings,
        private readonly BkDocumentService $documents,
        private readonly BkNoteService $notes,
        private readonly BkScreeningService $screening,
    ) {
    }

    // --------------------------------------------------------------- screening

    public function screen(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);
        abort_unless($request->user()->hasPermissionTo('bk.screening.create'), 403, 'Anda tidak berwenang melakukan screening.');

        $validated = $request->validate([
            'presenting_concern' => ['required', 'string', 'max:4000'],
            'observation' => ['nullable', 'string', 'max:4000'],
            'student_perspective' => ['nullable', 'string', 'max:4000'],
            'teacher_observation' => ['nullable', 'string', 'max:4000'],
            'parent_observation' => ['nullable', 'string', 'max:4000'],
            'immediate_support_needed' => ['nullable', 'boolean'],
            'recommended_outcome' => ['required', 'string'],
            'recommendation_notes' => ['nullable', 'string', 'max:2000'],
            'follow_up_date' => ['nullable', 'date'],
        ]);

        return $this->createdResponse($this->screening->record($request->user(), $case, $validated), 'Screening tersimpan.');
    }

    // --------------------------------------------------------------- asesmen

    public function assessments(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        $rows = $case->assessments()->with('template:id,name,target')->orderByDesc('assigned_at')->get()
            ->map(fn ($assessment): array => [
                'id' => $assessment->id,
                'assessment_number' => $assessment->assessment_number,
                'template' => $assessment->template?->name,
                'target' => $assessment->target->value,
                'status' => $assessment->status->value,
                'assigned_at' => $assessment->assigned_at?->toDateTimeString(),
                'due_date' => $assessment->due_date?->toDateString(),
                'total_score' => $assessment->total_score,
            ]);

        return $this->successResponse($rows, 'Asesmen kasus.');
    }

    public function storeAssessment(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);
        abort_unless($request->user()->hasPermissionTo('bk.assessment.assign'), 403, 'Anda tidak berwenang mengirim asesmen.');

        $validated = $request->validate([
            'template_id' => ['required', 'integer', 'exists:bk_assessment_templates,id'],
            'target' => ['nullable', 'string'],
            'respondent_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'respondent_name' => ['nullable', 'string', 'max:120'],
            'due_date' => ['nullable', 'date'],
        ]) + ['case_id' => $case->id];

        return $this->createdResponse($this->assessments->assign($request->user(), $validated), 'Asesmen dikirim.');
    }

    // --------------------------------------------------------------- sesi

    public function sessions(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        $canSeeCounseling = $request->user()->can('viewConfidential', $case);

        $rows = $case->sessions()->with('counselor:id,name')->orderByDesc('scheduled_at')->get()
            ->map(fn (BkCounselingSession $session): array => [
                'id' => $session->id,
                'session_number' => $session->session_number,
                'session_type' => $session->session_type->value,
                'mode' => $session->mode->value,
                'status' => $session->status->value,
                'scheduled_at' => $session->scheduled_at?->toDateTimeString(),
                'started_at' => $session->started_at?->toDateTimeString(),
                'ended_at' => $session->ended_at?->toDateTimeString(),
                'counselor' => $session->counselor?->name,
                'attendance_status' => $session->attendance_status->value,
                'objective' => $session->objective,
                'session_summary' => $session->session_summary,
                // Catatan konselor hanya dikirim ke pemegang akses konseling.
                'counselor_note' => $canSeeCounseling ? $session->counselor_note : null,
                'has_counselor_note' => $session->counselor_note !== null,
                'next_action' => $session->next_action,
            ]);

        return $this->successResponse($rows, 'Sesi konseling kasus.');
    }

    /**
     * Rincian satu sesi konseling.
     *
     * Endpoint ini sebelumnya tidak ada, sehingga permintaan terhadap sesi
     * menjawab 404 — "tidak ditemukan" — padahal sesinya ada dan yang terjadi
     * adalah penolakan akses. Perbedaan itu penting: 404 menyembunyikan sebab,
     * dan jejak audit tidak pernah tercatat. Di sini kasusnya diotorisasi lebih
     * dahulu (403 bila bukan haknya), baru catatan konselor disaring.
     */
    public function showSession(Request $request, \App\Modules\Bk\Models\BkCounselingSession $session): JsonResponse
    {
        $case = BkCase::query()->find($session->case_id);

        if ($case === null) {
            abort(404, 'Sesi tidak menunjuk kasus yang ada.');
        }

        // Penolakan di sini menyangkut ISI SESI, jadi dicatat menurut tingkat
        // kerahasiaan sesinya — bukan menurut kasusnya: sesi yang bersifat
        // konselor-saja harus meninggalkan jejak VIEW_CONFIDENTIAL, sekalipun
        // kasus induknya tidak. Tanpa itu, percobaan membuka catatan konseling
        // tidak terlihat sama sekali di jejak audit.
        if (! app(\App\Modules\Bk\Application\Services\BkAccessService::class)->canViewCase($request->user(), $case)) {
            $level = $session->confidentiality_level;
            $aksi = ($level instanceof \App\Modules\Bk\Domain\Enums\ConfidentialityLevel && $level->isCounselorOnly())
                ? 'VIEW_CONFIDENTIAL'
                : 'VIEW';

            app(\App\Modules\Bk\Application\Services\BkAuditService::class)
                ->logAccess($request->user(), $aksi, $session, false, 'Sesi konseling di luar cakupan.');

            abort(403, 'Anda tidak memiliki akses ke sesi konseling ini.');
        }

        $canSeeCounseling = $request->user()->can('viewConfidential', $case);

        return $this->successResponse([
            'id' => $session->id,
            'session_number' => $session->session_number,
            'session_type' => $session->session_type->value,
            'mode' => $session->mode->value,
            'status' => $session->status->value,
            'scheduled_at' => $session->scheduled_at?->toDateTimeString(),
            'started_at' => $session->started_at?->toDateTimeString(),
            'ended_at' => $session->ended_at?->toDateTimeString(),
            'counselor' => $session->counselor?->name,
            'attendance_status' => $session->attendance_status->value,
            'objective' => $session->objective,
            'session_summary' => $session->session_summary,
            'counselor_note' => $canSeeCounseling ? $session->counselor_note : null,
            'has_counselor_note' => $session->counselor_note !== null,
            'next_action' => $session->next_action,
        ], 'Rincian sesi konseling.');
    }

    public function storeSession(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        $validated = $request->validate([
            'counselor_id' => ['nullable', 'integer', 'exists:users,id'],
            'appointment_id' => ['nullable', 'integer', 'exists:bk_appointments,id'],
            'session_type' => ['nullable', 'string'],
            'mode' => ['nullable', 'string'],
            'scheduled_at' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:120'],
            'objective' => ['nullable', 'string', 'max:2000'],
            'confidentiality_level' => ['nullable', 'string'],
        ]);

        return $this->createdResponse($this->sessions->schedule($request->user(), $case, $validated), 'Sesi konseling dijadwalkan.');
    }

    // --------------------------------------------------------------- rencana

    public function plans(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        return $this->successResponse(
            $case->plans()->with(['goals' => fn ($q) => $q->orderBy('sort_order')])->orderByDesc('started_on')->get(),
            'Rencana konseling kasus.',
        );
    }

    public function storePlan(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);
        abort_unless($request->user()->hasPermissionTo('bk.plan.manage'), 403, 'Anda tidak berwenang menyusun rencana konseling.');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'problem_statement' => ['required', 'string', 'max:4000'],
            'started_on' => ['nullable', 'date'],
            'target_end_on' => ['nullable', 'date'],
            'goals' => ['nullable', 'array'],
            'goals.*.goal' => ['required_with:goals', 'string'],
            'goals.*.success_indicator' => ['required_with:goals', 'string'],
            'goals.*.objective' => ['nullable', 'string'],
            'goals.*.intervention_approach' => ['nullable', 'string'],
            'goals.*.responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'goals.*.timeline_end' => ['nullable', 'date'],
        ]);

        return $this->createdResponse($this->plans->create($request->user(), $case, $validated), 'Rencana konseling dibuat.');
    }

    public function updateGoal(Request $request, BkGoal $goal): JsonResponse
    {
        $this->authorize('view', $goal->case);

        $validated = $request->validate([
            'progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'status' => ['nullable', 'string'],
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'objective' => ['nullable', 'string', 'max:2000'],
            'timeline_end' => ['nullable', 'date'],
        ]);

        return $this->successResponse($this->plans->updateGoal($request->user(), $goal, $validated), 'Sasaran diperbarui.');
    }

    public function reviewPlan(Request $request, BkCounselingPlan $plan): JsonResponse
    {
        $this->authorize('view', $plan->case);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'string'],
        ]);

        return $this->successResponse($this->plans->review($request->user(), $plan, $validated['notes'] ?? null, $validated['status'] ?? null), 'Rencana ditinjau.');
    }

    // --------------------------------------------------------------- intervensi

    public function interventions(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        return $this->successResponse($case->interventions()->with('owner:id,name')->orderByDesc('start_date')->get(), 'Intervensi kasus.');
    }

    public function storeIntervention(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        $validated = $request->validate([
            'type' => ['required', 'string'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'plan_id' => ['nullable', 'integer', 'exists:bk_counseling_plans,id'],
            'goal_id' => ['nullable', 'integer', 'exists:bk_goals,id'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'owner_role' => ['nullable', 'string', 'max:40'],
            'owner_name' => ['nullable', 'string', 'max:120'],
            'start_date' => ['nullable', 'date'],
            'target_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'follow_up_required' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->createdResponse($this->interventions->create($request->user(), $case, $validated), 'Intervensi dibuat.');
    }

    public function updateIntervention(Request $request, BkIntervention $intervention): JsonResponse
    {
        $this->authorize('view', $intervention->case);

        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'title' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'owner_name' => ['nullable', 'string', 'max:120'],
            'target_date' => ['nullable', 'date'],
            'result' => ['nullable', 'string', 'max:4000'],
            'follow_up_required' => ['nullable', 'boolean'],
            'follow_up_due' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->successResponse($this->interventions->update($request->user(), $intervention, $validated), 'Intervensi diperbarui.');
    }

    // --------------------------------------------------------------- tindak lanjut

    public function followUps(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        return $this->successResponse($this->followUps->forCase($case), 'Tindak lanjut kasus.');
    }

    public function storeFollowUp(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'type' => ['nullable', 'string'],
            'description' => ['nullable', 'string', 'max:2000'],
            'intervention_id' => ['nullable', 'integer', 'exists:bk_interventions,id'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'due_date' => ['required', 'date'],
            'due_time' => ['nullable', 'date_format:H:i'],
        ]);

        return $this->createdResponse($this->followUps->create($request->user(), $case, $validated), 'Tindak lanjut dijadwalkan.');
    }

    // --------------------------------------------------------------- pertemuan orang tua

    public function meetings(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        return $this->successResponse($this->meetings->forCase($case), 'Pertemuan orang tua kasus.');
    }

    public function storeMeeting(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        $validated = $request->validate([
            'scheduled_at' => ['nullable', 'date'],
            'mode' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:120'],
            'agenda' => ['nullable', 'string', 'max:4000'],
            'counselor_id' => ['nullable', 'integer', 'exists:users,id'],
            'participants' => ['nullable', 'array'],
            'participants.*.role' => ['required_with:participants', 'string'],
            'participants.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
            'participants.*.guardian_id' => ['nullable', 'integer', 'exists:guardians,id'],
            'participants.*.name' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->createdResponse($this->meetings->request($request->user(), $case, $validated), 'Pertemuan orang tua dijadwalkan.');
    }

    public function recordMinutes(Request $request, BkParentMeeting $meeting): JsonResponse
    {
        $this->authorize('view', $meeting->case);

        $validated = $request->validate([
            'discussion' => ['nullable', 'string', 'max:8000'],
            'agreed_actions' => ['nullable', 'string', 'max:4000'],
            'started_at' => ['nullable', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'next_meeting_on' => ['nullable', 'date'],
            'status' => ['nullable', 'string'],
            'actions' => ['nullable', 'array'],
            'actions.*.description' => ['required_with:actions', 'string'],
            'actions.*.responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'actions.*.responsible_name' => ['nullable', 'string', 'max:120'],
            'actions.*.due_date' => ['nullable', 'date'],
        ]);

        return $this->successResponse($this->meetings->recordMinutes($request->user(), $meeting, $validated), 'Notulen pertemuan disimpan.');
    }

    public function convertMeetingAction(Request $request, BkParentMeeting $meeting, int $actionId): JsonResponse
    {
        $this->authorize('view', $meeting->case);

        $validated = $request->validate(['due_date' => ['nullable', 'date']]);

        return $this->createdResponse($this->meetings->convertAction($request->user(), $meeting, $actionId, $validated['due_date'] ?? null), 'Kesepakatan menjadi tindak lanjut.');
    }

    // --------------------------------------------------------------- dokumen

    public function documents(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        return $this->successResponse(
            array_map(fn (BkCaseDocument $document): array => [
                'id' => $document->id,
                'document_type' => $document->document_type->value,
                'title' => $document->title,
                'visibility' => $document->visibility->value,
                'is_sensitive' => $document->is_sensitive,
                'document_date' => $document->document_date?->toDateString(),
                'uploaded_by' => $document->uploadedBy?->name,
                'byte_size' => $document->byte_size,
            ], $this->documents->forCase($request->user(), $case)),
            'Dokumen kasus.',
        );
    }

    public function storeDocument(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);
        abort_unless($request->user()->hasPermissionTo('bk.document.upload'), 403, 'Anda tidak berwenang mengunggah dokumen.');

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png'],
            'document_type' => ['nullable', 'string'],
            'title' => ['nullable', 'string', 'max:160'],
            'visibility' => ['nullable', 'string'],
            'is_sensitive' => ['nullable', 'boolean'],
            'document_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->createdResponse(
            $this->documents->store($request->user(), $case, $request->file('file'), $validated),
            'Dokumen tersimpan.',
        );
    }

    public function downloadDocument(Request $request, BkCaseDocument $document): StreamedResponse
    {
        $this->authorize('view', $document->case);

        $contents = $this->documents->contents($request->user(), $document);

        return response()->streamDownload(
            fn () => print ($contents),
            $document->title,
            ['Content-Type' => $document->mime_type ?? 'application/octet-stream'],
        );
    }

    // --------------------------------------------------------------- catatan

    public function notes(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        return $this->successResponse(
            array_map(fn (BkCaseNote $note): array => [
                'id' => $note->id,
                'note_type' => $note->note_type->value,
                'body' => $note->body,
                'confidentiality_level' => $note->confidentiality_level->value,
                'is_sensitive' => $note->is_sensitive,
                'author' => $note->author?->name,
                'author_role' => $note->author_role,
                'occurred_at' => $note->occurred_at?->toDateTimeString(),
                'amended_from_id' => $note->amended_from_id,
            ], $this->notes->forCase($request->user(), $case)),
            'Catatan kasus.',
        );
    }

    public function storeNote(Request $request, BkCase $case): JsonResponse
    {
        $this->authorize('view', $case);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:8000'],
            'note_type' => ['nullable', 'string'],
            'session_id' => ['nullable', 'integer', 'exists:bk_counseling_sessions,id'],
            'is_sensitive' => ['nullable', 'boolean'],
            'confidentiality_level' => ['nullable', 'string'],
        ]);

        return $this->createdResponse($this->notes->create($request->user(), $case, $validated), 'Catatan ditambahkan.');
    }

    public function amendNote(Request $request, BkCaseNote $note): JsonResponse
    {
        $this->authorize('amend', $note);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:8000'],
        ]);

        return $this->createdResponse($this->notes->amend($request->user(), $note, $validated['reason'], $validated['body']), 'Catatan diperbaiki.');
    }
}
