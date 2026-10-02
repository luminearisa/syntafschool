<?php

namespace App\Modules\SchoolOperations\Religious\Http\Controllers;

use App\Modules\SchoolOperations\Religious\Application\Services\{ReligiousActivityService, ReligiousLearningService};
use App\Modules\SchoolOperations\Religious\Models\ReligiousActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Kegiatan keagamaan, kajian, pendaftaran, peserta, presensi, dan penilaian (§32-§42, §91). */
class ActivityController extends Controller
{
    public function __construct(
        private readonly ReligiousActivityService $activities,
        private readonly ReligiousLearningService $learning,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('religious.activity.view') || abort(403);

        return response()->json(['data' => ReligiousActivity::query()
            ->when($request->input('activity_type'), fn ($q, $t) => $q->where('activity_type', strtoupper((string) $t)))
            ->when($request->integer('mosque_id'), fn ($q, $id) => $q->where('mosque_id', $id))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', strtoupper((string) $s)))
            ->orderByDesc('id')->limit((int) $request->integer('limit', 100))->get()
            ->map(fn (ReligiousActivity $a): array => $a->only([
                'id', 'code', 'title', 'activity_type', 'status', 'capacity',
                'registration_mode', 'target_population', 'starts_on', 'ends_on',
            ]))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can('religious.activity.manage') || abort(403);

        return response()->json(['data' => $this->activities->create($request->validate([
            'title' => ['required', 'string', 'max:200'],
            'activity_type' => ['required', 'string'],
            'description' => ['nullable', 'string'], 'category' => ['nullable', 'string'],
            'mosque_id' => ['nullable', 'integer'], 'campus_id' => ['nullable', 'integer'],
            'organization_id' => ['nullable', 'integer'], 'location' => ['nullable', 'string'],
            'area_id' => ['nullable', 'integer'],
            'instructor_personnel_id' => ['nullable', 'integer'],
            'instructor_name' => ['nullable', 'string'],
            'target_population' => ['nullable', 'string'], 'target_filter' => ['nullable', 'array'],
            'registration_mode' => ['nullable', 'string'], 'capacity' => ['nullable', 'integer', 'min:0'],
            'waitlist_enabled' => ['nullable', 'boolean'], 'requires_approval' => ['nullable', 'boolean'],
            'is_mandatory' => ['nullable', 'boolean'], 'recurring' => ['nullable', 'boolean'],
            'recurrence_rule' => ['nullable', 'array'], 'assessment_enabled' => ['nullable', 'boolean'],
            'certificate_enabled' => ['nullable', 'boolean'], 'lms_course_id' => ['nullable', 'integer'],
            'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]))], 201);
    }

    public function sessions(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.activity.view') || abort(403);

        return response()->json(['data' => \App\Modules\SchoolOperations\Religious\Models\ReligiousActivitySession::query()
            ->where('activity_id', $id)->orderBy('session_number')->get()]);
    }

    public function storeSession(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.activity.manage') || abort(403);

        return response()->json(['data' => $this->activities->addSession($id, $request->validate([
            'session_date' => ['required', 'date'], 'title' => ['nullable', 'string'],
            'start_time' => ['nullable'], 'end_time' => ['nullable'],
            'location' => ['nullable', 'string'], 'area_id' => ['nullable', 'integer'],
            'instructor_personnel_id' => ['nullable', 'integer'], 'mosque_id' => ['nullable', 'integer'],
            'topic' => ['nullable', 'string'], 'capacity' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ]))], 201);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.activity.manage') || abort(403);

        return response()->json(['data' => $this->activities->publish($id)]);
    }

    public function register(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.activity.register') || abort(403);

        return response()->json(['data' => $this->activities->register($id, $request->validate([
            'participant_type' => ['nullable', 'string'], 'student_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'], 'parent_id' => ['nullable', 'integer'],
            'external_name' => ['nullable', 'string'], 'external_contact' => ['nullable', 'string'],
            'session_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string'],
        ]))], 201);
    }

    public function approveRegistration(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.activity.manage') || abort(403);

        return response()->json(['data' => $this->activities->approveRegistration(
            $id, $request->boolean('approved', true), $request->input('reason')
        )]);
    }

    public function participants(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.activity.view') || abort(403);

        return response()->json(['data' => $this->activities->roster($id)]);
    }

    public function storeParticipant(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.activity.manage') || abort(403);

        return response()->json(['data' => $this->activities->addParticipant($id, $request->all())], 201);
    }

    public function recordAttendance(Request $request, int $participantId): JsonResponse
    {
        $request->user()->can('religious.activity.attend') || abort(403);

        return response()->json(['data' => $this->activities->recordAttendance($participantId, $request->all())], 201);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.activity.manage') || abort(403);

        return response()->json(['data' => $this->activities->complete($id, $request->all())]);
    }

    public function today(Request $request): JsonResponse
    {
        $request->user()->can('religious.activity.view') || abort(403);

        return response()->json(['data' => $this->activities->today(
            $request->integer('mosque_id') ?: null, $request->input('date')
        )]);
    }

    // ── Kajian & penilaian (§34, §35, §41) ─────────────────────

    public function studySessions(Request $request): JsonResponse
    {
        $request->user()->can('religious.activity.view') || abort(403);
        $resolved = new \Illuminate\Http\JsonResponse([]);
        $request->user()->can('religious.activity.view') || abort(403);

        return response()->json(['data' => \App\Modules\SchoolOperations\Religious\Models\ReligiousStudySession::query()
            ->when($request->input('from'), fn ($q, $d) => $q->whereDate('session_date', '>=', $d))
            ->when($request->input('to'), fn ($q, $d) => $q->whereDate('session_date', '<=', $d))
            ->orderByDesc('session_date')->limit((int) $request->integer('limit', 100))->get()]);
    }

    public function storeStudySession(Request $request): JsonResponse
    {
        $request->user()->can('religious.activity.manage') || abort(403);

        return response()->json(['data' => $this->learning->addStudySession($request->all())], 201);
    }

    public function references(Request $request): JsonResponse
    {
        $request->user()->can('religious.activity.view') || abort(403);

        return response()->json(['data' => $this->learning->references($request->only(['term', 'category', 'limit']))]);
    }

    public function storeReference(Request $request): JsonResponse
    {
        $request->user()->can('religious.activity.manage') || abort(403);

        return response()->json(['data' => $this->learning->addReference($request->validate([
            'title' => ['required', 'string'], 'reference_type' => ['nullable', 'string'],
            'author' => ['nullable', 'string'], 'publisher' => ['nullable', 'string'],
            'isbn' => ['nullable', 'string'], 'library_item_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string'], 'language' => ['nullable', 'string'],
            'edition' => ['nullable', 'string'], 'publication_year' => ['nullable', 'integer'],
            'summary' => ['nullable', 'string'], 'tags' => ['nullable', 'array'],
        ]))], 201);
    }

    public function assessmentTypes(Request $request): JsonResponse
    {
        $request->user()->can('religious.activity.view') || abort(403);

        return response()->json(['data' => $this->learning->assessmentTypes()]);
    }

    public function assess(Request $request): JsonResponse
    {
        $request->user()->can('religious.activity.assess') || abort(403);

        return response()->json(['data' => $this->learning->assess($request->validate([
            'assessment_type_code' => ['required', 'string'],
            'activity_id' => ['nullable', 'integer'], 'session_id' => ['nullable', 'integer'],
            'participant_id' => ['nullable', 'integer'], 'student_id' => ['nullable', 'integer'],
            'assessment_date' => ['nullable', 'date'], 'score' => ['nullable', 'numeric'],
            'max_score' => ['nullable', 'numeric'], 'grade_label' => ['nullable', 'string'],
            'level_label' => ['nullable', 'string'], 'components' => ['nullable', 'array'],
            'result' => ['nullable', 'string'], 'assessor_name' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]))], 201);
    }

    /** Ringkasan penilaian siswa (§73). */
    public function studentAssessments(Request $request, int $studentId): JsonResponse
    {
        $request->user()->can('religious.activity.view') || abort(403);

        return response()->json(['data' => $this->learning->studentSummary($studentId, $request->only(['from', 'to']))]);
    }
}
